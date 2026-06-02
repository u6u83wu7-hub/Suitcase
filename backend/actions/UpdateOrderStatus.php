<?php
// UpdateOrderStatus.php - 處理訂單狀態更新的 action
//版本1
if (!in_array($action, ['update_order_status', 'bulk_update_orders'], true)) {
    return;
}

$allowedStatuses = ['PENDING', 'PROCESSING', 'SHIPPED', 'DELIVERED', 'COMPLETED', 'CANCELLED'];
$newStatus = isset($_POST['status']) ? trim($_POST['status']) : '';

if (!in_array($newStatus, $allowedStatuses, true)) {
    echo "<script>alert('Invalid order status update.'); location.href='backend.php?page=orders';</script>";
    exit();
}

$orderIds = [];
if ($action === 'bulk_update_orders') {
    $rawIds = isset($_POST['order_ids']) && is_array($_POST['order_ids']) ? $_POST['order_ids'] : [];
    foreach ($rawIds as $rawId) {
        $id = intval($rawId);
        if ($id > 0) {
            $orderIds[] = $id;
        }
    }
} else {
    $singleId = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    if ($singleId > 0) {
        $orderIds[] = $singleId;
    }
}

if (empty($orderIds)) {
    echo "<script>alert('Please select at least one order.'); location.href='backend.php?page=orders';</script>";
    exit();
}

$shippingCarrier = trim($_POST['shipping_carrier'] ?? '');
$trackingNumberInput = trim($_POST['tracking_number'] ?? '');
$adminNotes = trim($_POST['admin_notes'] ?? '');

function buildTrackingNumber($carrier, $number) {
    if ($carrier !== '' && $number !== '') {
        return $carrier . ' | ' . $number;
    }
    if ($carrier !== '') {
        return $carrier;
    }
    return $number;
}

function ouAllowedNextStatuses($currentStatus) {
    $map = [
        'PENDING' => ['PROCESSING', 'CANCELLED'],
        'PROCESSING' => ['SHIPPED', 'CANCELLED'],
        'SHIPPED' => ['DELIVERED'],
        'DELIVERED' => ['COMPLETED'],
        'COMPLETED' => [],
        'CANCELLED' => ['PROCESSING'],
    ];

    return $map[$currentStatus] ?? [];
}

function ouCanMoveStatus($currentStatus, $newStatus) {
    if ($currentStatus === $newStatus) {
        return true;
    }

    return in_array($newStatus, ouAllowedNextStatuses($currentStatus), true);
}

function ouStatusLabel($status) {
    $labels = [
        'PENDING' => '待處理',
        'PROCESSING' => '處理中',
        'SHIPPED' => '已出貨',
        'DELIVERED' => '已送達',
        'COMPLETED' => '已完成',
        'CANCELLED' => '已取消',
    ];

    return $labels[$status] ?? $status;
}

$trackingNumber = buildTrackingNumber($shippingCarrier, $trackingNumberInput);
$orderColumnsForInventory = tableColumns($conn, 'orders');
$hasInventoryDeductedColumn = in_array('inventory_deducted', $orderColumnsForInventory, true);

function ouTableExists($conn, $tableName) {
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return ($res && $res->num_rows > 0);
}

function ouNotifyShipping($conn, $orderId) {
    $stmt = $conn->prepare("SELECT o.order_id, o.order_number, o.user_id, u.email, u.name
                            FROM orders o
                            LEFT JOIN users u ON u.user_id = o.user_id
                            WHERE o.order_id = ?
                            LIMIT 1");
    $stmt->bind_param("i", $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return;
    }

    $orderNo = $row['order_number'] !== null && $row['order_number'] !== '' ? $row['order_number'] : ('#' . $row['order_id']);
    $title = '訂單已出貨通知';
    $message = '您的商品已出貨。訂單編號：' . $orderNo . '。';

    if (ouTableExists($conn, 'user_notifications')) {
        $insert = $conn->prepare("INSERT INTO user_notifications (user_id, title, message) VALUES (?, ?, ?)");
        $insert->bind_param("iss", $row['user_id'], $title, $message);
        $insert->execute();
    }

    if (!empty($row['email'])) {
        $subject = 'All Pass 通知 - 您的商品已出貨';
        $headers = "Content-Type: text/plain; charset=UTF-8\r\n";
        @mail($row['email'], $subject, $message, $headers);
    }
}

$conn->begin_transaction();

try {
    $inventorySelect = $hasInventoryDeductedColumn ? ', inventory_deducted' : '';
    $orderSelect = $conn->prepare("SELECT status{$inventorySelect} FROM orders WHERE order_id = ? FOR UPDATE");
    $itemsSelect = $conn->prepare("SELECT variant_id, quantity FROM order_items WHERE order_id = ?");
    $restockStmt = $conn->prepare("UPDATE product_variants SET stock_available = stock_available + ? WHERE variant_id = ?");
    $deductStmt = $conn->prepare("UPDATE product_variants SET stock_available = stock_available - ? WHERE variant_id = ? AND stock_available >= ?");
    $updateStatusStmt = $conn->prepare("UPDATE orders SET status = ? WHERE order_id = ?");
    $updateDetailStmt = $conn->prepare("UPDATE orders SET status = ?, tracking_number = ?, admin_notes = ? WHERE order_id = ?");
    $markInventoryStmt = $hasInventoryDeductedColumn ? $conn->prepare("UPDATE orders SET inventory_deducted = ? WHERE order_id = ?") : null;

    foreach ($orderIds as $orderId) {
        $orderSelect->bind_param("i", $orderId);
        $orderSelect->execute();
        $current = $orderSelect->get_result()->fetch_assoc();

        if (!$current) {
            throw new Exception('Order not found.');
        }

        $currentStatus = $current['status'];
        $inventoryDeducted = $hasInventoryDeductedColumn ? ((int)$current['inventory_deducted'] === 1) : true;

        if (!ouCanMoveStatus($currentStatus, $newStatus)) {
            throw new Exception(
                '訂單 #' . $orderId . ' 無法從「' . ouStatusLabel($currentStatus) . '」改為「' . ouStatusLabel($newStatus) . '」。請依照訂單流程操作。'
            );
        }

        if ($currentStatus !== $newStatus) {
            if ($newStatus === 'CANCELLED' && $currentStatus !== 'CANCELLED' && (!$hasInventoryDeductedColumn || $inventoryDeducted)) {
                $itemsSelect->bind_param("i", $orderId);
                $itemsSelect->execute();
                $itemsResult = $itemsSelect->get_result();

                while ($item = $itemsResult->fetch_assoc()) {
                    $variantId = (int)$item['variant_id'];
                    $qty = (int)$item['quantity'];
                    if ($qty <= 0) {
                        continue;
                    }
                    $restockStmt->bind_param("ii", $qty, $variantId);
                    $restockStmt->execute();
                    if ($restockStmt->affected_rows !== 1) {
                        throw new Exception('Restock failed for variant #' . $variantId);
                    }
                }
                if ($markInventoryStmt) {
                    $deductedFlag = 0;
                    $markInventoryStmt->bind_param("ii", $deductedFlag, $orderId);
                    $markInventoryStmt->execute();
                }
            }

            if ($currentStatus === 'CANCELLED' && $newStatus !== 'CANCELLED' && (!$hasInventoryDeductedColumn || !$inventoryDeducted)) {
                $itemsSelect->bind_param("i", $orderId);
                $itemsSelect->execute();
                $itemsResult = $itemsSelect->get_result();

                while ($item = $itemsResult->fetch_assoc()) {
                    $variantId = (int)$item['variant_id'];
                    $qty = (int)$item['quantity'];
                    if ($qty <= 0) {
                        continue;
                    }
                    $deductStmt->bind_param("iii", $qty, $variantId, $qty);
                    $deductStmt->execute();
                    if ($deductStmt->affected_rows !== 1) {
                        throw new Exception('Stock is not enough to restore order #' . $orderId);
                    }
                }
                if ($markInventoryStmt) {
                    $deductedFlag = 1;
                    $markInventoryStmt->bind_param("ii", $deductedFlag, $orderId);
                    $markInventoryStmt->execute();
                }
            }
        }

        if ($action === 'bulk_update_orders') {
            $updateStatusStmt->bind_param("si", $newStatus, $orderId);
            if (!$updateStatusStmt->execute()) {
                throw new Exception('Order status update failed.');
            }
        } else {
            $updateDetailStmt->bind_param("sssi", $newStatus, $trackingNumber, $adminNotes, $orderId);
            if (!$updateDetailStmt->execute()) {
                throw new Exception('Order status update failed.');
            }
        }

        if ($newStatus === 'SHIPPED' && $currentStatus !== 'SHIPPED') {
            ouNotifyShipping($conn, $orderId);
        }
    }

    $conn->commit();

    if ($action === 'bulk_update_orders') {
        echo "<script>alert('Bulk order status updated.'); location.href='backend.php?page=orders';</script>";
    } else {
        $orderId = $orderIds[0];
        echo "<script>alert('Order status updated.'); location.href='backend.php?page=orders&order_id={$orderId}';</script>";
    }
} catch (Exception $e) {
    $conn->rollback();
    $msg = addslashes($e->getMessage());
    if ($action === 'bulk_update_orders') {
        echo "<script>alert('{$msg}'); location.href='backend.php?page=orders';</script>";
    } else {
        $orderId = $orderIds[0];
        echo "<script>alert('{$msg}'); location.href='backend.php?page=orders&order_id={$orderId}';</script>";
    }
}
exit();
?>

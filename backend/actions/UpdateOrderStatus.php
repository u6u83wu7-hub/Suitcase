<?php
if (!in_array($action, ['update_order_status', 'bulk_update_orders'], true)) {
    return;
}

$allowedStatuses = ['PENDING', 'PAID', 'SHIPPING', 'COMPLETED', 'CANCELLED'];
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

$trackingNumber = buildTrackingNumber($shippingCarrier, $trackingNumberInput);

$conn->begin_transaction();

try {
    $orderSelect = $conn->prepare("SELECT status FROM orders WHERE order_id = ? FOR UPDATE");
    $itemsSelect = $conn->prepare("SELECT variant_id, quantity FROM order_items WHERE order_id = ?");
    $restockStmt = $conn->prepare("UPDATE product_variants SET stock_available = stock_available + ? WHERE variant_id = ?");
    $deductStmt = $conn->prepare("UPDATE product_variants SET stock_available = stock_available - ? WHERE variant_id = ? AND stock_available >= ?");
    $updateStatusStmt = $conn->prepare("UPDATE orders SET status = ? WHERE order_id = ?");
    $updateDetailStmt = $conn->prepare("UPDATE orders SET status = ?, tracking_number = ?, admin_notes = ? WHERE order_id = ?");

    foreach ($orderIds as $orderId) {
        $orderSelect->bind_param("i", $orderId);
        $orderSelect->execute();
        $current = $orderSelect->get_result()->fetch_assoc();

        if (!$current) {
            throw new Exception('Order not found.');
        }

        $currentStatus = $current['status'];

        if ($currentStatus !== $newStatus) {
            if ($newStatus === 'CANCELLED' && $currentStatus !== 'CANCELLED') {
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
            }

            if ($currentStatus === 'CANCELLED' && $newStatus !== 'CANCELLED') {
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

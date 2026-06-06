<?php
if (($action ?? '') !== 'delete_order') {
    return;
}

$orderId = (int)($_POST['order_id'] ?? 0);
if ($orderId <= 0) {
    header('Location: backend.php?page=orders&error=' . urlencode('找不到要處理的訂單。'));
    exit();
}

$conn->begin_transaction();

try {
    $orderColumns = tableColumns($conn, 'orders');
    $hasInventoryDeducted = in_array('inventory_deducted', $orderColumns, true);
    $hasAdminNotes = in_array('admin_notes', $orderColumns, true);

    $inventorySelect = $hasInventoryDeducted ? ', inventory_deducted' : '';
    $orderStmt = $conn->prepare("SELECT status{$inventorySelect} FROM orders WHERE order_id = ? FOR UPDATE");
    if (!$orderStmt) {
        throw new RuntimeException('讀取訂單失敗。');
    }
    $orderStmt->bind_param('i', $orderId);
    $orderStmt->execute();
    $order = $orderStmt->get_result()->fetch_assoc();
    $orderStmt->close();

    if (!$order) {
        throw new RuntimeException('找不到要處理的訂單。');
    }

    $inventoryDeducted = $hasInventoryDeducted ? ((int)$order['inventory_deducted'] === 1) : true;

    if ($order['status'] !== 'CANCELLED' && $inventoryDeducted) {
        $itemsStmt = $conn->prepare('SELECT variant_id, quantity FROM order_items WHERE order_id = ?');
        $restockStmt = $conn->prepare('UPDATE product_variants SET stock_available = stock_available + ? WHERE variant_id = ?');
        if (!$itemsStmt || !$restockStmt) {
            throw new RuntimeException('建立庫存回補程序失敗。');
        }

        $itemsStmt->bind_param('i', $orderId);
        $itemsStmt->execute();
        $items = $itemsStmt->get_result();
        while ($item = $items->fetch_assoc()) {
            $variantId = (int)$item['variant_id'];
            $quantity = (int)$item['quantity'];
            if ($variantId <= 0 || $quantity <= 0) {
                continue;
            }
            $restockStmt->bind_param('ii', $quantity, $variantId);
            if (!$restockStmt->execute()) {
                throw new RuntimeException('庫存回補失敗。');
            }
        }
        $itemsStmt->close();
        $restockStmt->close();
    }

    $note = '後台於 ' . date('Y-m-d H:i:s') . ' 將此訂單封存為取消。';
    if ($hasAdminNotes && $hasInventoryDeducted) {
        $deducted = 0;
        $updateStmt = $conn->prepare("UPDATE orders SET status = 'CANCELLED', inventory_deducted = ?, admin_notes = CONCAT(COALESCE(admin_notes, ''), CASE WHEN COALESCE(admin_notes, '') = '' THEN '' ELSE '\n' END, ?) WHERE order_id = ?");
        $updateStmt->bind_param('isi', $deducted, $note, $orderId);
    } elseif ($hasAdminNotes) {
        $updateStmt = $conn->prepare("UPDATE orders SET status = 'CANCELLED', admin_notes = CONCAT(COALESCE(admin_notes, ''), CASE WHEN COALESCE(admin_notes, '') = '' THEN '' ELSE '\n' END, ?) WHERE order_id = ?");
        $updateStmt->bind_param('si', $note, $orderId);
    } elseif ($hasInventoryDeducted) {
        $deducted = 0;
        $updateStmt = $conn->prepare("UPDATE orders SET status = 'CANCELLED', inventory_deducted = ? WHERE order_id = ?");
        $updateStmt->bind_param('ii', $deducted, $orderId);
    } else {
        $updateStmt = $conn->prepare("UPDATE orders SET status = 'CANCELLED' WHERE order_id = ?");
        $updateStmt->bind_param('i', $orderId);
    }

    if (!$updateStmt || !$updateStmt->execute()) {
        throw new RuntimeException('更新訂單狀態失敗。');
    }
    $updateStmt->close();

    $conn->commit();
    header('Location: backend.php?page=orders&order_id=' . $orderId . '&success=1');
    exit();
} catch (Throwable $e) {
    $conn->rollback();
    header('Location: backend.php?page=orders&order_id=' . $orderId . '&error=' . urlencode($e->getMessage()));
    exit();
}
?>

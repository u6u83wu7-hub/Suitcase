<?php
// UpdateReturnRequest.php - approve, reject, or refund a return request.
if ($action !== 'update_return_request') {
    return;
}

$returnId = isset($_POST['return_id']) ? (int)$_POST['return_id'] : 0;
$orderId = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
$newStatus = isset($_POST['return_status']) ? trim((string)$_POST['return_status']) : '';
$adminNote = trim((string)($_POST['admin_note'] ?? ''));
$allowedStatuses = ['PENDING', 'APPROVED', 'REJECTED', 'REFUNDED'];

$redirect = 'backend.php?page=orders' . ($orderId > 0 ? '&order_id=' . $orderId : '');

if ($returnId <= 0 || $orderId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
    echo "<script>alert('Invalid return request update.'); location.href='{$redirect}';</script>";
    exit();
}

$tableRes = $conn->query("SHOW TABLES LIKE 'return_requests'");
if (!$tableRes || $tableRes->num_rows === 0) {
    echo "<script>alert('退貨申請資料表不存在。'); location.href='{$redirect}';</script>";
    exit();
}

$conn->begin_transaction();

try {
    $lockStmt = $conn->prepare('SELECT status FROM return_requests WHERE return_id = ? AND order_id = ? FOR UPDATE');
    if (!$lockStmt) {
        throw new RuntimeException('讀取退貨申請失敗。');
    }
    $lockStmt->bind_param('ii', $returnId, $orderId);
    $lockStmt->execute();
    $currentReturn = $lockStmt->get_result()->fetch_assoc();
    $lockStmt->close();

    if (!$currentReturn) {
        throw new RuntimeException('找不到退貨申請。');
    }

    $previousStatus = (string)$currentReturn['status'];

    $stmt = $conn->prepare(
        'UPDATE return_requests
         SET status = ?, admin_note = ?, updated_at = NOW()
         WHERE return_id = ? AND order_id = ?'
    );
    if (!$stmt) {
        throw new RuntimeException('準備更新退貨申請失敗。');
    }
    $stmt->bind_param('ssii', $newStatus, $adminNote, $returnId, $orderId);
    if (!$stmt->execute()) {
        throw new RuntimeException('退貨申請更新失敗。');
    }
    $stmt->close();

    if ($newStatus === 'REFUNDED' && $previousStatus !== 'REFUNDED') {
        $paymentTableRes = $conn->query("SHOW TABLES LIKE 'payment_transactions'");
        if ($paymentTableRes && $paymentTableRes->num_rows > 0) {
            $amount = 0.0;
            $amountStmt = $conn->prepare('SELECT total_amount FROM orders WHERE order_id = ? LIMIT 1');
            if ($amountStmt) {
                $amountStmt->bind_param('i', $orderId);
                $amountStmt->execute();
                $amountRow = $amountStmt->get_result()->fetch_assoc();
                $amount = $amountRow ? (float)$amountRow['total_amount'] : 0.0;
                $amountStmt->close();
            }

            $transactionNo = 'REF-' . $orderId . '-' . date('YmdHis');
            $failureReason = null;
            $paymentMethod = 'REFUND';
            $paymentStatus = 'REFUNDED';
            $insertStmt = $conn->prepare(
                'INSERT INTO payment_transactions (order_id, amount, payment_method, status, transaction_no, failure_reason, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())'
            );
            if (!$insertStmt) {
                throw new RuntimeException('準備退款紀錄失敗。');
            }
            $insertStmt->bind_param('idssss', $orderId, $amount, $paymentMethod, $paymentStatus, $transactionNo, $failureReason);
            if (!$insertStmt->execute()) {
                throw new RuntimeException('建立退款紀錄失敗。');
            }
            $insertStmt->close();
        }
    }

    $conn->commit();
    echo "<script>alert('退貨申請已更新。'); location.href='{$redirect}';</script>";
    exit();
} catch (Throwable $e) {
    $conn->rollback();
    $message = addslashes($e->getMessage());
    echo "<script>alert('{$message}'); location.href='{$redirect}';</script>";
    exit();
}
?>

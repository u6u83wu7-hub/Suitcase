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
    echo "<script>alert('Return request table does not exist.'); location.href='{$redirect}';</script>";
    exit();
}

$stmt = $conn->prepare(
    'UPDATE return_requests
     SET status = ?, admin_note = ?, updated_at = NOW()
     WHERE return_id = ? AND order_id = ?'
);

if (!$stmt) {
    echo "<script>alert('Unable to prepare return request update.'); location.href='{$redirect}';</script>";
    exit();
}

$stmt->bind_param('ssii', $newStatus, $adminNote, $returnId, $orderId);
if (!$stmt->execute()) {
    echo "<script>alert('Return request update failed.'); location.href='{$redirect}';</script>";
    exit();
}
$stmt->close();

if ($newStatus === 'REFUNDED') {
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
        $status = 'REFUNDED';
        $insertStmt = $conn->prepare(
            'INSERT INTO payment_transactions (order_id, amount, payment_method, status, transaction_no, failure_reason, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );
        if ($insertStmt) {
            $insertStmt->bind_param('idssss', $orderId, $amount, $paymentMethod, $status, $transactionNo, $failureReason);
            $insertStmt->execute();
            $insertStmt->close();
        }
    }
}

echo "<script>alert('Return request updated.'); location.href='{$redirect}';</script>";
exit();
?>

<?php
if ($action !== 'update_order_status') {
    return;
}

$orderId = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
$newStatus = isset($_POST['status']) ? trim($_POST['status']) : '';
$allowedStatuses = ['PENDING', 'PAID', 'SHIPPING', 'COMPLETED', 'CANCELLED'];

if ($orderId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
    echo "<script>alert('Invalid order status update.'); location.href='backend.php?page=orders';</script>";
    exit();
}

$stmt = $conn->prepare("UPDATE orders SET status = ? WHERE order_id = ?");
$stmt->bind_param("si", $newStatus, $orderId);

if ($stmt->execute()) {
    echo "<script>alert('Order status updated.'); location.href='backend.php?page=orders&order_id={$orderId}';</script>";
} else {
    echo "<script>alert('Order status update failed.'); location.href='backend.php?page=orders&order_id={$orderId}';</script>";
}
exit();
?>

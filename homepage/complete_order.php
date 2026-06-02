<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/security.php';

apConfigureErrorHandling();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$orderId = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
if ($orderId <= 0 || !apValidateCsrf()) {
    header('Location: profile.php#order-history');
    exit;
}

$conn = new mysqli('localhost', 'root', '', 'all_pass_db');
if ($conn->connect_error) {
    die('資料庫連線失敗: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');

$userId = intval($_SESSION['user_id']);
$stmt = $conn->prepare("UPDATE orders SET status = 'COMPLETED' WHERE order_id = ? AND user_id = ? AND status = 'DELIVERED'");
$stmt->bind_param('ii', $orderId, $userId);
$stmt->execute();

$conn->close();

header('Location: profile.php#order-history');
exit;
?>

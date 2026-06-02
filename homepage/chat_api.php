<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/includes/security.php';

apConfigureErrorHandling();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'not_logged_in']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'invalid_method']);
    exit;
}

if (!apValidateCsrf()) {
    echo json_encode(['success' => false, 'error' => 'invalid_csrf']);
    exit;
}

$action = isset($_POST['action']) ? trim($_POST['action']) : '';
$userId = intval($_SESSION['user_id']);
$conn = new mysqli('localhost', 'root', '', 'all_pass_db');
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'db_error']); exit;
}
$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+08:00'");

// 尋找該會員「唯一」的聊天室 (不分商品)
function chatEnsureTicket($conn, $userId) {
    $stmt = $conn->prepare("SELECT ticket_id FROM customer_tickets WHERE user_id = ? LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) return intval($row['ticket_id']);

    // 沒有就建一個新的
    $stmt = $conn->prepare("INSERT INTO customer_tickets (user_id, status) VALUES (?, 'OPEN')");
    $stmt->bind_param('i', $userId);
    if ($stmt->execute()) return $stmt->insert_id;
    return 0;
}

function chatFetchMessages($conn, $ticketId, $lastId = 0) {
    // 抓取訊息時，順便 JOIN 商品名稱，讓前台未來擴充可用
    $sql = "SELECT tm.message_id, tm.sender_type, tm.message_text, tm.created_at, tm.product_id, pr.name AS product_name 
            FROM ticket_messages tm 
            LEFT JOIN products pr ON tm.product_id = pr.product_id 
            WHERE tm.ticket_id = ? AND tm.message_id > ? 
            ORDER BY tm.message_id ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $ticketId, $lastId);
    $stmt->execute();
    $res = $stmt->get_result();
    $messages = [];
    while ($row = $res->fetch_assoc()) { $messages[] = $row; }
    return $messages;
}

$productId = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
$productIdToSave = $productId > 0 ? $productId : null;

if ($action === 'send_message') {
    $messageText = trim($_POST['message_text'] ?? '');
    if ($messageText === '') { echo json_encode(['success' => false]); exit; }

    $ticketId = chatEnsureTicket($conn, $userId);
    
    // 將 product_id 存入 ticket_messages 裡
    $stmt = $conn->prepare("INSERT INTO ticket_messages (ticket_id, sender_type, sender_id, product_id, message_text) VALUES (?, 'USER', ?, ?, ?)");
    $stmt->bind_param('iiis', $ticketId, $userId, $productIdToSave, $messageText);
    $stmt->execute();

    $conn->query("UPDATE customer_tickets SET status = 'OPEN', updated_at = NOW() WHERE ticket_id = {$ticketId}");
    
    echo json_encode(['success' => true, 'ticket_id' => $ticketId, 'messages' => chatFetchMessages($conn, $ticketId, $stmt->insert_id - 1)]);
    exit;
}

if ($action === 'load_messages' || $action === 'poll_messages') {
    $ticketId = chatEnsureTicket($conn, $userId);
    $lastId = isset($_POST['last_message_id']) ? intval($_POST['last_message_id']) : 0;
    $messages = chatFetchMessages($conn, $ticketId, $action === 'poll_messages' ? $lastId : 0);
    echo json_encode(['success' => true, 'ticket_id' => $ticketId, 'messages' => $messages]);
    exit;
}
?>

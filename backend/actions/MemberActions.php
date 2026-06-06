<?php
if (($action ?? '') !== 'update_member') {
    header('Location: backend.php?page=members&error=' . urlencode('無效的會員操作。'));
    exit();
}

function goMembers($message = '', $success = false)
{
    $params = ['page' => 'members'];
    if ($message !== '') {
        $params[$success ? 'success' : 'error'] = $message;
    }
    header('Location: backend.php?' . http_build_query($params));
    exit();
}

$userId = (int)($_POST['user_id'] ?? 0);
$name = trim((string)($_POST['name'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$membershipLevel = trim((string)($_POST['membership_level'] ?? '1'));
$pointsBalance = (int)($_POST['points_balance'] ?? 0);
$status = strtoupper(trim((string)($_POST['status'] ?? 'ACTIVE')));

$allowedLevels = ['1', '2', '3'];
$allowedStatuses = ['ACTIVE', 'SUSPENDED', 'INACTIVE'];

if ($userId <= 0) {
    goMembers('找不到要更新的會員。');
}
if ($name === '') {
    goMembers('會員姓名不可空白。');
}
if (!in_array($membershipLevel, $allowedLevels, true)) {
    goMembers('會員等級不正確。');
}
if ($pointsBalance < 0) {
    goMembers('點數不可小於 0。');
}
if (!in_array($status, $allowedStatuses, true)) {
    goMembers('會員狀態不正確。');
}

$stmt = $conn->prepare('UPDATE users SET name = ?, phone = ?, membership_level = ?, points_balance = ?, status = ? WHERE user_id = ?');
if (!$stmt) {
    goMembers('更新會員資料失敗。');
}

$stmt->bind_param('sssisi', $name, $phone, $membershipLevel, $pointsBalance, $status, $userId);
if (!$stmt->execute()) {
    $message = $stmt->error ?: '更新會員資料失敗。';
    $stmt->close();
    goMembers($message);
}

$affected = $stmt->affected_rows;
$stmt->close();

if ($affected < 0) {
    goMembers('更新會員資料失敗。');
}

goMembers('會員資料已更新。', true);
?>

<?php
// 建立或更新一組會員帳號，並把會員等級設為 2
$conn = new mysqli('localhost', 'root', '', 'all_pass_db');

if ($conn->connect_error) {
    die('連線失敗: ' . $conn->connect_error);
}

$conn->set_charset('utf8mb4');

// 你可以直接修改這三個值
$email = 'member@test.com';
$password = 'memberOne01';
$name = 'Member User';
$phone = '0912345678';
$membershipLevel = '2';
$status = 'ACTIVE';

$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

$checkStmt = $conn->prepare('SELECT user_id FROM users WHERE email = ? LIMIT 1');
if (!$checkStmt) {
    die('查詢準備失敗: ' . $conn->error);
}

$checkStmt->bind_param('s', $email);
$checkStmt->execute();
$checkStmt->bind_result($existingUserId);
$found = $checkStmt->fetch();
$checkStmt->close();

if ($found) {
    $updateStmt = $conn->prepare('UPDATE users SET password_hash = ?, name = ?, phone = ?, membership_level = ?, status = ? WHERE email = ?');
    if (!$updateStmt) {
        die('更新準備失敗: ' . $conn->error);
    }

    $updateStmt->bind_param('ssssss', $hashedPassword, $name, $phone, $membershipLevel, $status, $email);

    if ($updateStmt->execute()) {
        echo '<h3>✅ 會員帳號已更新成功！</h3>';
        echo '<b>帳號：</b>' . htmlspecialchars($email) . '<br>';
        echo '<b>明文密碼：</b>' . htmlspecialchars($password) . '<br>';
        echo '<b>會員等級：</b>' . htmlspecialchars($membershipLevel) . '<br>';
    } else {
        echo '更新失敗: ' . $updateStmt->error;
    }

    $updateStmt->close();
} else {
    $insertStmt = $conn->prepare('INSERT INTO users (email, password_hash, name, phone, membership_level, status) VALUES (?, ?, ?, ?, ?, ?)');
    if (!$insertStmt) {
        die('新增準備失敗: ' . $conn->error);
    }

    $insertStmt->bind_param('ssssss', $email, $hashedPassword, $name, $phone, $membershipLevel, $status);

    if ($insertStmt->execute()) {
        echo '<h3>✅ 會員帳號建立成功！</h3>';
        echo '<b>帳號：</b>' . htmlspecialchars($email) . '<br>';
        echo '<b>明文密碼：</b>' . htmlspecialchars($password) . '<br>';
        echo '<b>會員等級：</b>' . htmlspecialchars($membershipLevel) . '<br>';
    } else {
        echo '新增失敗: ' . $insertStmt->error;
    }

    $insertStmt->close();
}

$conn->close();
?>
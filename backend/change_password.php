<?php
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/../homepage/includes/security.php';

apConfigureErrorHandling();

$error_message = '';
$success_message = '';

function isValidPassword($password) {
    return preg_match('/^(?=.*[A-Za-z])(?=.*\d).{8,}$/', $password) === 1;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!apValidateCsrf()) {
        $error_message = '表單驗證失敗，請重新操作。';
    } else {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($newPassword !== $confirmPassword) {
        $error_message = '新密碼與確認密碼不一致。';
    } elseif (!isValidPassword($newPassword)) {
        $error_message = '新密碼需至少 8 碼且包含英文字母與數字。';
    } else {
        $conn = new mysqli("localhost", "root", "", "all_pass_db");
        if ($conn->connect_error) {
            error_log('Admin change password database connection failed: ' . $conn->connect_error);
            $error_message = '系統暫時無法連線，請稍後再試。';
        } else {
        $conn->set_charset("utf8mb4");

        $stmt = $conn->prepare("SELECT password_hash FROM admin_users WHERE admin_id = ?");
        $stmt->bind_param("i", $_SESSION['admin_id']);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            if (!password_verify($currentPassword, $row['password_hash'])) {
                $error_message = '目前密碼錯誤。';
            } elseif (password_verify($newPassword, $row['password_hash'])) {
                $error_message = '新密碼不可與舊密碼相同。';
            } else {
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $update = $conn->prepare("UPDATE admin_users SET password_hash = ? WHERE admin_id = ?");
                $update->bind_param("si", $newHash, $_SESSION['admin_id']);

                if ($update->execute()) {
                    $success_message = '密碼已更新。';
                } else {
                    $error_message = '更新密碼失敗，請稍後再試。';
                }
            }
        } else {
            $error_message = '找不到管理員資料。';
        }

        $conn->close();
        }
    }
    }
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>修改管理員密碼</title>
    <style>
        body { font-family: 'PingFang TC', sans-serif; background: #f5f5f5; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .card { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.12); width: 380px; }
        h2 { text-align: center; color: #333; margin-bottom: 24px; letter-spacing: 2px; }
        .form-group { margin-bottom: 18px; }
        label { display: block; margin-bottom: 8px; font-weight: bold; color: #666; }
        input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; }
        .btn { width: 100%; padding: 12px; background: #2c3e50; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 15px; margin-top: 6px; }
        .btn:hover { background: #1f2f3f; }
        .msg-error { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 6px; margin-bottom: 16px; text-align: center; font-size: 14px; }
        .msg-success { background: #d4edda; color: #155724; padding: 10px; border-radius: 6px; margin-bottom: 16px; text-align: center; font-size: 14px; }
        .footer-link { text-align: center; margin-top: 16px; font-size: 12px; }
        .footer-link a { color: #666; text-decoration: none; }
    </style>
</head>
<body>
    <div class="card">
        <h2>修改管理員密碼</h2>

        <?php if ($error_message): ?>
            <div class="msg-error"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <?php if ($success_message): ?>
            <div class="msg-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <form action="change_password.php" method="POST">
            <?php echo apCsrfField(); ?>
            <div class="form-group">
                <label>目前密碼</label>
                <input type="password" name="current_password" required>
            </div>
            <div class="form-group">
                <label>新密碼</label>
                <input type="password" name="new_password" placeholder="至少 8 碼，含英文字母與數字" required>
            </div>
            <div class="form-group">
                <label>確認新密碼</label>
                <input type="password" name="confirm_password" required>
            </div>
            <button type="submit" class="btn">更新密碼</button>
        </form>

        <div class="footer-link">
            <a href="backend.php?page=profile">回管理者資料</a>
        </div>
    </div>
</body>
</html>

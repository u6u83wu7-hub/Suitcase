<?php
session_start();
require_once __DIR__ . '/includes/security.php';

apConfigureErrorHandling();
date_default_timezone_set('Asia/Taipei');

$conn = new mysqli("localhost", "root", "", "all_pass_db");
if ($conn->connect_error) {
    die("資料庫連線失敗: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");
$conn->query("SET time_zone = '+08:00'");

function fpH($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function fpTableExists($conn, $tableName) {
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $res && $res->num_rows > 0;
}

function fpClientIp() {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'CLI'), 0, 45);
}

function fpRecordAttempt($conn, $scope, $identifier, $success) {
    if (!fpTableExists($conn, 'security_attempts')) {
        return;
    }
    $ip = fpClientIp();
    $stmt = $conn->prepare('INSERT INTO security_attempts (scope, identifier, ip_address, success) VALUES (?, ?, ?, ?)');
    if ($stmt) {
        $successInt = $success ? 1 : 0;
        $stmt->bind_param('sssi', $scope, $identifier, $ip, $successInt);
        $stmt->execute();
        $stmt->close();
    }
}

function fpTooManyAttempts($conn, $scope, $identifier, $limit, $minutes) {
    if (!fpTableExists($conn, 'security_attempts')) {
        return false;
    }
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS attempts
         FROM security_attempts
         WHERE scope = ? AND identifier = ? AND success = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ssi', $scope, $identifier, $minutes);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['attempts'] ?? 0) >= $limit;
}

function fpFindResetToken($conn, $token) {
    if ($token === '' || !fpTableExists($conn, 'password_reset_tokens')) {
        return null;
    }
    $hash = hash('sha256', $token);
    $stmt = $conn->prepare(
        "SELECT pr.reset_id, pr.user_id, pr.expires_at, pr.used_at, u.email
         FROM password_reset_tokens pr
         JOIN users u ON u.user_id = pr.user_id
         WHERE pr.token_hash = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row || !empty($row['used_at']) || strtotime($row['expires_at']) < time()) {
        return null;
    }
    return $row;
}

$token = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
$validToken = fpFindResetToken($conn, $token);
$step = $validToken ? 'reset' : 'request';
$errorMessage = '';
$successMessage = '';
$demoResetUrl = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!apValidateCsrf()) {
        $errorMessage = '表單驗證失敗，請重新送出。';
    } elseif (($_POST['action'] ?? '') === 'request_reset') {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $identifier = $email !== '' ? $email : fpClientIp();

        if (fpTooManyAttempts($conn, 'password_reset', $identifier, 5, 15)) {
            $errorMessage = '嘗試次數過多，請 15 分鐘後再試。';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            fpRecordAttempt($conn, 'password_reset', $identifier, false);
            $errorMessage = '請輸入正確的電子郵件格式。';
        } else {
            $stmt = $conn->prepare("SELECT user_id, status FROM users WHERE email = ? LIMIT 1");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $successMessage = '如果此 Email 已註冊，我們已建立一組限時重設連結。';
            if ($user && strtoupper((string)($user['status'] ?? 'ACTIVE')) === 'ACTIVE' && fpTableExists($conn, 'password_reset_tokens')) {
                $rawToken = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $rawToken);
                $expiresAt = date('Y-m-d H:i:s', time() + 30 * 60);
                $userId = (int)$user['user_id'];

                $conn->begin_transaction();
                try {
                    $invalidate = $conn->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL');
                    $invalidate->bind_param('i', $userId);
                    $invalidate->execute();
                    $invalidate->close();

                    $insert = $conn->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)');
                    $insert->bind_param('iss', $userId, $tokenHash, $expiresAt);
                    $insert->execute();
                    $insert->close();
                    $conn->commit();

                    $path = strtok($_SERVER['REQUEST_URI'] ?? '/Suitcase/homepage/forgot_password.php', '?');
                    $demoResetUrl = $path . '?token=' . urlencode($rawToken);
                    fpRecordAttempt($conn, 'password_reset', $identifier, true);
                } catch (Throwable $e) {
                    $conn->rollback();
                    fpRecordAttempt($conn, 'password_reset', $identifier, false);
                    $errorMessage = '建立重設連結失敗，請稍後再試。';
                    $successMessage = '';
                }
            } else {
                fpRecordAttempt($conn, 'password_reset', $identifier, false);
            }
        }
    } elseif (($_POST['action'] ?? '') === 'reset_password') {
        $postedToken = trim((string)($_POST['token'] ?? ''));
        $resetRow = fpFindResetToken($conn, $postedToken);
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');
        $step = 'reset';
        $token = $postedToken;

        if (!$resetRow) {
            $errorMessage = '重設連結無效或已過期，請重新申請。';
            $step = 'request';
        } elseif (!preg_match('/^(?=.*[A-Za-z])(?=.*\d).{8,}$/', $newPassword)) {
            $errorMessage = '新密碼至少 8 碼，且需包含英文字母與數字。';
        } elseif ($newPassword !== $confirmPassword) {
            $errorMessage = '兩次輸入的密碼不一致。';
        } else {
            $conn->begin_transaction();
            try {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $userId = (int)$resetRow['user_id'];
                $resetId = (int)$resetRow['reset_id'];

                $update = $conn->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?');
                $update->bind_param('si', $hash, $userId);
                $update->execute();
                $update->close();

                $mark = $conn->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE reset_id = ?');
                $mark->bind_param('i', $resetId);
                $mark->execute();
                $mark->close();

                $conn->commit();
                $step = 'done';
                $successMessage = '密碼已成功更新，請使用新密碼登入。';
            } catch (Throwable $e) {
                $conn->rollback();
                $errorMessage = '密碼更新失敗，請稍後再試。';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>忘記密碼 | All Pass</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'PingFang TC', 'Microsoft JhengHei', sans-serif; }
        body { background-color: #fcfcfc; color: #333; overflow-x: hidden; }
        a { text-decoration: none; color: inherit; }
        .top-bar { height: 3px; background-color: #1a1a1a; width: 100%; }
        header { background: #ffffff; padding: 20px 5%; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; }
        .header-logo { font-size: 24px; font-weight: 800; color: #2c3e50; letter-spacing: 3px; }
        .back-btn { color: #666; font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 6px; }
        .back-btn:hover { color: #db6b6b; }
        .back-btn::before { content: "<"; font-size: 16px; }
        .login-wrapper { min-height: calc(100vh - 120px); display: flex; align-items: center; justify-content: center; padding: 60px 20px; }
        .login-container { background: #ffffff; width: 100%; max-width: 460px; padding: 56px 46px; border: 1px solid #f0f0f0; box-shadow: 0 8px 40px rgba(0,0,0,0.05); }
        .logo-section { text-align: center; margin-bottom: 34px; }
        .logo { font-size: 32px; font-weight: 800; color: #2c3e50; letter-spacing: 4px; margin-bottom: 12px; }
        .logo-subtitle { font-size: 13px; color: #777; letter-spacing: 1px; font-weight: 500; line-height: 1.7; }
        .divider { height: 2px; background: linear-gradient(to right, #ffffff, #e0e0e0, #ffffff); margin: 30px 0; }
        .form-group { margin-bottom: 22px; }
        label { display: block; font-size: 13px; font-weight: 600; color: #2c3e50; margin-bottom: 10px; letter-spacing: 0.5px; }
        input[type="email"], input[type="password"] { width: 100%; padding: 13px 15px; border: 1px solid #e5e5e5; border-radius: 6px; font-size: 14px; background: #fafafa; color: #333; }
        input:focus { outline: none; border-color: #2c3e50; background: #ffffff; box-shadow: 0 0 0 2px rgba(44, 62, 80, 0.08); }
        .login-btn { width: 100%; padding: 13px; background: #2c3e50; color: white; border: none; border-radius: 6px; font-size: 15px; font-weight: 600; cursor: pointer; letter-spacing: 1px; margin-bottom: 18px; display: inline-block; text-align: center; }
        .login-btn:hover { background: #db6b6b; }
        .signup-section { text-align: center; padding-top: 20px; border-top: 1px solid #f5f5f5; }
        .signup-text { font-size: 13px; color: #666; }
        .signup-link { color: #db6b6b; font-weight: 600; }
        .message { padding: 12px 15px; border-radius: 6px; font-size: 13px; margin-bottom: 20px; line-height: 1.6; word-break: break-word; }
        .message.error { background: #fff5f5; color: #c33; border-left: 3px solid #c33; }
        .message.success { background: #f0fdf4; color: #15803d; border-left: 3px solid #16a34a; }
        .demo-link { display: block; margin-top: 10px; color: #b91c1c; font-weight: 700; text-decoration: underline; }
        @media (max-width: 576px) {
            header { flex-direction: column; gap: 15px; }
            .login-container { padding: 40px 30px; }
        }
    </style>
</head>
<body>
    <div class="top-bar"></div>
    <header>
        <div class="header-logo">All Pass</div>
        <a href="login.php" class="back-btn">回登入頁</a>
    </header>

    <div class="login-wrapper">
        <div class="login-container">
            <div class="logo-section">
                <div class="logo">All Pass</div>
                <div class="logo-subtitle">使用一次性限時連結重設密碼。</div>
            </div>

            <div class="divider"></div>

            <?php if ($errorMessage !== ''): ?>
                <div class="message error"><?php echo fpH($errorMessage); ?></div>
            <?php endif; ?>
            <?php if ($successMessage !== ''): ?>
                <div class="message success">
                    <?php echo fpH($successMessage); ?>
                    <?php if ($demoResetUrl !== ''): ?>
                        <a class="demo-link" href="<?php echo fpH($demoResetUrl); ?>">展示用重設連結</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($step === 'request'): ?>
                <form action="forgot_password.php" method="POST">
                    <?php echo apCsrfField(); ?>
                    <input type="hidden" name="action" value="request_reset">
                    <div class="form-group">
                        <label for="email">註冊 Email</label>
                        <input type="email" id="email" name="email" placeholder="example@email.com" required value="<?php echo fpH($_POST['email'] ?? ''); ?>">
                    </div>
                    <button type="submit" class="login-btn">建立重設連結</button>
                </form>
            <?php elseif ($step === 'reset'): ?>
                <form action="forgot_password.php?token=<?php echo fpH($token); ?>" method="POST">
                    <?php echo apCsrfField(); ?>
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="token" value="<?php echo fpH($token); ?>">
                    <div class="form-group">
                        <label for="new_password">新密碼</label>
                        <input type="password" id="new_password" name="new_password" placeholder="至少 8 碼，含英文與數字" required>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">確認新密碼</label>
                        <input type="password" id="confirm_password" name="confirm_password" placeholder="再次輸入新密碼" required>
                    </div>
                    <button type="submit" class="login-btn">確認重設密碼</button>
                </form>
            <?php else: ?>
                <a href="login.php" class="login-btn">立即登入</a>
            <?php endif; ?>

            <div class="signup-section">
                <p class="signup-text">想起密碼了嗎？ <a href="login.php" class="signup-link">返回登入</a></p>
            </div>
        </div>
    </div>
</body>
</html>
<?php $conn->close(); ?>

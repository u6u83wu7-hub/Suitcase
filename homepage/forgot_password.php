<?php
session_start();
require_once __DIR__ . '/includes/security.php';

apConfigureErrorHandling();

$step = 1; // 1: 驗證身分, 2: 填寫新密碼, 3: 完成
$error_message = "";
$success_message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!apValidateCsrf()) {
        $error_message = "表單驗證失敗，請重新送出。";
    } else {
        $conn = new mysqli("localhost", "root", "", "all_pass_db");
        if ($conn->connect_error) die("資料庫連線失敗: " . $conn->connect_error);
        $conn->set_charset("utf8mb4");

        // --- 步驟 1：驗證帳號與電話 ---
        if (isset($_POST['action']) && $_POST['action'] === 'verify') {
            $email = trim($_POST['email']);
            $phone = trim($_POST['phone']);

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error_message = "請輸入正確的電子郵件格式！";
            } else {
                $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND phone = ?");
                $stmt->bind_param("ss", $email, $phone);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows > 0) {
                    $step = 2; // 驗證成功，進入重設密碼步驟
                    $_SESSION['reset_email'] = $email; // 將帳號存入 session，避免被竄改
                } else {
                    $error_message = "找不到符合的帳號，或電話號碼輸入錯誤！";
                }
                $stmt->close();
            }
        } 
        // --- 步驟 2：更新密碼 ---
        elseif (isset($_POST['action']) && $_POST['action'] === 'reset') {
            $new_password = $_POST['new_password'];
            $confirm_password = $_POST['confirm_password'];

            if (!isset($_SESSION['reset_email'])) {
                $error_message = "驗證已失效，請重新操作。";
                $step = 1;
            } elseif (strlen($new_password) < 6) {
                $error_message = "密碼長度至少需要 6 個字元。";
                $step = 2;
            } elseif ($new_password !== $confirm_password) {
                $error_message = "兩次輸入的密碼不一致！";
                $step = 2;
            } else {
                $email = $_SESSION['reset_email'];
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

                $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
                $stmt->bind_param("ss", $hashed_password, $email);
                
                if ($stmt->execute()) {
                    $step = 3; // 更新成功，進入完成畫面
                    unset($_SESSION['reset_email']); // 清除 session 中的紀錄
                } else {
                    $error_message = "密碼更新失敗，請稍後再試。";
                    $step = 2;
                }
                $stmt->close();
            }
        }
        $conn->close();
    }
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>忘記密碼 | All Pass 行李箱專賣</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'PingFang TC', 'Microsoft JhengHei', sans-serif; }
        body { background-color: #fcfcfc; color: #333; overflow-x: hidden; }
        a { text-decoration: none; color: inherit; }
        .top-bar { height: 3px; background-color: #1a1a1a; width: 100%; }
        header { background: #ffffff; padding: 20px 5%; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; }
        .header-logo { font-size: 24px; font-weight: 800; color: #2c3e50; letter-spacing: 3px; }
        .header-nav { display: flex; gap: 20px; align-items: center; }
        .back-btn { color: #666; font-size: 14px; font-weight: 500; transition: color 0.3s; display: flex; align-items: center; gap: 6px; }
        .back-btn:hover { color: #db6b6b; }
        .back-btn::before { content: "←"; font-size: 16px; }
        .login-wrapper { min-height: calc(100vh - 120px); display: flex; align-items: center; justify-content: center; padding: 60px 20px; }
        .login-container { background: #ffffff; width: 100%; max-width: 440px; padding: 60px 50px; border: 1px solid #f0f0f0; box-shadow: 0 8px 40px rgba(0,0,0,0.05); animation: fadeIn 0.8s ease-out; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        .logo-section { text-align: center; margin-bottom: 50px; }
        .logo { font-size: 32px; font-weight: 800; color: #2c3e50; letter-spacing: 4px; margin-bottom: 12px; }
        .logo-subtitle { font-size: 13px; color: #999; letter-spacing: 1px; font-weight: 500; }
        .divider { height: 2px; background: linear-gradient(to right, #ffffff, #e0e0e0, #ffffff); margin: 40px 0; }
        .form-group { margin-bottom: 24px; }
        label { display: block; font-size: 13px; font-weight: 600; color: #2c3e50; margin-bottom: 10px; letter-spacing: 0.5px; }
        input[type="email"], input[type="password"], input[type="tel"] { width: 100%; padding: 13px 15px; border: 1px solid #e5e5e5; border-radius: 6px; font-size: 14px; transition: all 0.3s; background: #fafafa; color: #333; }
        input[type="email"]:focus, input[type="password"]:focus, input[type="tel"]:focus { outline: none; border-color: #2c3e50; background: #ffffff; box-shadow: 0 0 0 2px rgba(44, 62, 80, 0.08); }
        input::placeholder { color: #bbb; }
        .login-btn { width: 100%; padding: 13px; background: #2c3e50; color: white; border: none; border-radius: 6px; font-size: 15px; font-weight: 600; cursor: pointer; transition: all 0.3s; letter-spacing: 1px; margin-bottom: 20px; }
        .login-btn:hover { background: #db6b6b; transform: translateY(-1px); box-shadow: 0 8px 20px rgba(219, 107, 107, 0.2); }
        .login-btn:active { transform: translateY(0); }
        .signup-section { text-align: center; padding-top: 20px; border-top: 1px solid #f5f5f5; }
        .signup-text { font-size: 13px; color: #666; }
        .signup-link { color: #db6b6b; text-decoration: none; font-weight: 600; transition: color 0.3s; }
        .signup-link:hover { color: #2c3e50; }
        .error-message { background: #fff5f5; color: #c33; padding: 12px 15px; border-radius: 6px; font-size: 13px; margin-bottom: 20px; border-left: 3px solid #c33; display: none; line-height: 1.5; }
        .success-message { background: #f0fdf4; color: #15803d; padding: 12px 15px; border-radius: 6px; font-size: 13px; margin-bottom: 20px; border-left: 3px solid #16a34a; line-height: 1.5; }
        .error-message.show { display: block; }
        @media (max-width: 576px) {
            header { flex-direction: column; gap: 15px; }
            .login-container { padding: 40px 30px; }
            .logo { font-size: 26px; }
            .logo-section { margin-bottom: 35px; }
        }
    </style>
</head>
<body>

    <div class="top-bar"></div>

    <header>
        <div class="header-logo">All Pass</div>
        <nav class="header-nav">
            <a href="login.php" class="back-btn">回登入頁</a>
        </nav>
    </header>

    <div class="login-wrapper">
        <div class="login-container">
            <div class="logo-section">
                <div class="logo">All Pass</div>
                <div class="logo-subtitle">重設密碼</div>
            </div>

            <div class="divider"></div>

            <?php if(!empty($error_message)): ?>
                <div class="error-message show">
                    <?php echo $error_message; ?>
                </div>
            <?php endif; ?>

            <?php if ($step === 1): ?>
                <form action="forgot_password.php" method="POST">
                    <?php echo apCsrfField(); ?>
                    <input type="hidden" name="action" value="verify">
                    
                    <div class="form-group">
                        <label for="email">註冊的電子郵件 (帳號)</label>
                        <input type="email" id="email" name="email" placeholder="輸入你的電子郵件" required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                    </div>

                    <div class="form-group">
                        <label for="phone">註冊的手機號碼</label>
                        <input type="tel" id="phone" name="phone" placeholder="輸入當初註冊的手機號碼" required value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
                    </div>

                    <button type="submit" class="login-btn">驗證身分</button>
                </form>

            <?php elseif ($step === 2): ?>
                <div class="success-message">身分驗證成功！請輸入你的新密碼。</div>
                <form action="forgot_password.php" method="POST">
                    <?php echo apCsrfField(); ?>
                    <input type="hidden" name="action" value="reset">
                    
                    <div class="form-group">
                        <label for="new_password">新密碼</label>
                        <input type="password" id="new_password" name="new_password" placeholder="請輸入新密碼 (至少 6 個字元)" required>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">確認新密碼</label>
                        <input type="password" id="confirm_password" name="confirm_password" placeholder="請再次輸入新密碼" required>
                    </div>

                    <button type="submit" class="login-btn">確認重設密碼</button>
                </form>

            <?php elseif ($step === 3): ?>
                <div class="success-message">密碼已成功更新！</div>
                <a href="login.php">
                    <button type="button" class="login-btn">立即登入</button>
                </a>
            <?php endif; ?>

            <div class="signup-section">
                <p class="signup-text">想起來密碼了嗎？<a href="login.php" class="signup-link">返回登入</a></p>
            </div>
        </div>
    </div>

</body>
</html>
<?php
session_start();
require_once __DIR__ . '/includes/security.php';

apConfigureErrorHandling();

$error_message = "";
$success_message = "";

// 當使用者按下註冊按鈕 (送出 POST 表單)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!apValidateCsrf()) {
        $error_message = "表單驗證失敗，請重新送出。";
    } else {
    // 建立資料庫連線
    $conn = new mysqli("localhost", "root", "", "all_pass_db");
    
    if ($conn->connect_error) {
        die("資料庫連線失敗: " . $conn->connect_error);
    }
    $conn->set_charset("utf8mb4");

    // 取得使用者填寫的資料
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? ''); // 如果沒填會是空字串
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // 1. 檢查兩次密碼是否輸入一致
    if ($password !== $confirm_password) {
        $error_message = "兩次輸入的密碼不一致，請重新確認！";
    } elseif ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
        $error_message = "請確認姓名、電子信箱與密碼格式是否正確。";
    } else {
        // 2. 把密碼進行 Hash 加密 (超重要！保護客人隱私)
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // 3. 使用 prepared statement，避免註冊資料造成 SQL injection。
        $stmt = $conn->prepare("INSERT INTO users (name, email, password_hash, phone) VALUES (?, ?, ?, ?)");
        if (!$stmt) {
            $error_message = "系統發生錯誤，請稍後再試。";
        } else {
            $stmt->bind_param("ssss", $name, $email, $hashed_password, $phone);
        }

        // 4. 執行 SQL 並檢查結果。PHP 8 的 mysqli 預設會把 Duplicate entry 丟成例外。
        if ($stmt) {
            try {
                $stmt->execute();
                $success_message = "🎉 註冊成功！歡迎加入 All Pass，請前往登入。";
            } catch (mysqli_sql_exception $e) {
                if ((int) $e->getCode() === 1062) {
                    $error_message = "這個電子信箱已經被註冊過囉！請直接登入或換一個信箱。";
                } else {
                    error_log('Register failed: ' . $e->getMessage());
                    $error_message = "系統發生錯誤，請稍後再試。";
                }
            }
        }
        if ($stmt) {
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
    <title>註冊會員 | All Pass 行李箱專賣</title>
    <style>
        /* ======= 保留質感的共用 CSS ======= */
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'PingFang TC', 'Microsoft JhengHei', sans-serif; }
        body { background-color: #fcfcfc; color: #333; }
        a { text-decoration: none; color: inherit; }
        .top-bar { height: 3px; background-color: #1a1a1a; width: 100%; }
        header { background: #ffffff; padding: 20px 5%; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; }
        .header-logo { font-size: 24px; font-weight: 800; color: #2c3e50; letter-spacing: 3px; }
        .back-btn { color: #666; font-size: 14px; font-weight: 500; transition: color 0.3s; display: flex; align-items: center; gap: 6px; }
        .back-btn:hover { color: #db6b6b; }
        .back-btn::before { content: "←"; font-size: 16px; }
        
        /* 註冊表單專用樣式 */
        .register-wrapper { min-height: calc(100vh - 120px); display: flex; align-items: center; justify-content: center; padding: 40px 20px; }
        .register-container { background: #ffffff; width: 100%; max-width: 500px; padding: 50px; border: 1px solid #f0f0f0; box-shadow: 0 8px 40px rgba(0,0,0,0.05); }
        .logo-section { text-align: center; margin-bottom: 30px; }
        .logo { font-size: 32px; font-weight: 800; color: #2c3e50; letter-spacing: 4px; margin-bottom: 10px; }
        .logo-subtitle { font-size: 13px; color: #999; letter-spacing: 1px; font-weight: 500; }
        
        .form-row { display: flex; gap: 15px; margin-bottom: 20px; }
        .form-group { flex: 1; margin-bottom: 20px; }
        .form-row .form-group { margin-bottom: 0; }
        
        label { display: block; font-size: 13px; font-weight: 600; color: #2c3e50; margin-bottom: 8px; }
        input[type="text"], input[type="email"], input[type="password"], input[type="tel"] { 
            width: 100%; padding: 12px 15px; border: 1px solid #e5e5e5; border-radius: 6px; font-size: 14px; background: #fafafa; transition: 0.3s; 
        }
        input:focus { outline: none; border-color: #2c3e50; background: #ffffff; box-shadow: 0 0 0 2px rgba(44,62,80,0.08); }
        
        .submit-btn { width: 100%; padding: 14px; background: #2c3e50; color: white; border: none; border-radius: 6px; font-size: 15px; font-weight: 600; cursor: pointer; transition: 0.3s; margin-top: 10px; letter-spacing: 1px;}
        .submit-btn:hover { background: #db6b6b; transform: translateY(-1px); }
        
        .login-link-section { text-align: center; margin-top: 25px; font-size: 13px; color: #666; }
        .login-link { color: #db6b6b; font-weight: 600; transition: 0.3s; }
        .login-link:hover { color: #2c3e50; }

        /* 訊息提示框 */
        .msg-box { padding: 12px 15px; border-radius: 6px; font-size: 13px; margin-bottom: 20px; text-align: center; font-weight: 500;}
        .msg-error { background: #fff5f5; color: #c33; border: 1px solid #fcc; }
        .msg-success { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
    </style>
</head>
<body>

    <div class="top-bar"></div>

    <header>
        <div class="header-logo">All Pass</div>
        <nav class="header-nav">
            <a href="index.php" class="back-btn">回首頁</a>
        </nav>
    </header>

    <div class="register-wrapper">
        <div class="register-container">
            <div class="logo-section">
                <div class="logo">All Pass</div>
                <div class="logo-subtitle">建立新帳號，開啟你的旅程</div>
            </div>

            <?php if(!empty($error_message)): ?>
                <div class="msg-box msg-error"><?php echo $error_message; ?></div>
            <?php endif; ?>
            
            <?php if(!empty($success_message)): ?>
                <div class="msg-box msg-success"><?php echo $success_message; ?></div>
            <?php endif; ?>

            <form action="register.php" method="POST">
                <?php echo apCsrfField(); ?>
                <div class="form-row">
                    <div class="form-group">
                        <label for="name">真實姓名 <span style="color:red;">*</span></label>
                        <input type="text" id="name" name="name" placeholder="例如：王小明" required>
                    </div>
                    <div class="form-group">
                        <label for="phone">聯絡電話</label>
                        <input type="tel" id="phone" name="phone" placeholder="例如：0912345678">
                    </div>
                </div>

                <div class="form-group">
                    <label for="email">電子信箱 (作為登入帳號) <span style="color:red;">*</span></label>
                    <input type="email" id="email" name="email" placeholder="example@email.com" required>
                </div>

                <div class="form-group">
                    <label for="password">設定密碼 <span style="color:red;">*</span></label>
                    <input type="password" id="password" name="password" placeholder="請輸入至少 6 位數密碼" required minlength="6">
                </div>

                <div class="form-group">
                    <label for="confirm_password">確認密碼 <span style="color:red;">*</span></label>
                    <input type="password" id="confirm_password" name="confirm_password" placeholder="請再次輸入密碼" required minlength="6">
                </div>

                <button type="submit" class="submit-btn">立即註冊</button>
            </form>

            <div class="login-link-section">
                已經有帳號了？ <a href="login.php" class="login-link">點此登入</a>
            </div>
        </div>
    </div>

</body>
</html>

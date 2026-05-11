<?php
session_start();
$error_message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $conn = new mysqli("localhost", "root", "", "all_pass_db");
    if ($conn->connect_error) die("資料庫連線失敗: " . $conn->connect_error);

    $email = $_POST['email'];
    $password = $_POST['password'];

    // 只去一般消費者的 users 表格找
    $sql = "SELECT * FROM users WHERE email = '$email'";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        
        if (password_verify($password, $row['password_hash'])) {
            // 登入成功！存入 Session
            $_SESSION['user_id'] = $row['user_id'];
            $_SESSION['user_name'] = $row['name'];
            
            // 直接導向客人專屬的首頁
            header("Location: index.php"); 
            exit();
        } else {
            $error_message = "密碼錯誤，請再試一次！";
        }
    } else {
        $error_message = "找不到此帳號，請先前往註冊！";
    }
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登入 | All Pass 行李箱專賣</title>
    <style>
        /* ======= 保留你原本完美的 CSS ======= */
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
        input[type="email"], input[type="password"] { width: 100%; padding: 13px 15px; border: 1px solid #e5e5e5; border-radius: 6px; font-size: 14px; transition: all 0.3s; background: #fafafa; color: #333; }
        input[type="email"]:focus, input[type="password"]:focus { outline: none; border-color: #2c3e50; background: #ffffff; box-shadow: 0 0 0 2px rgba(44, 62, 80, 0.08); }
        input::placeholder { color: #bbb; }
        .remember-forgot { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; font-size: 13px; }
        .remember-forgot input[type="checkbox"] { margin-right: 6px; cursor: pointer; accent-color: #2c3e50; }
        .remember-forgot label { margin: 0; cursor: pointer; color: #666; font-weight: 400; }
        .forgot-link { color: #db6b6b; text-decoration: none; font-weight: 500; transition: color 0.3s; }
        .forgot-link:hover { color: #2c3e50; }
        .login-btn { width: 100%; padding: 13px; background: #2c3e50; color: white; border: none; border-radius: 6px; font-size: 15px; font-weight: 600; cursor: pointer; transition: all 0.3s; letter-spacing: 1px; margin-bottom: 20px; }
        .login-btn:hover { background: #db6b6b; transform: translateY(-1px); box-shadow: 0 8px 20px rgba(219, 107, 107, 0.2); }
        .login-btn:active { transform: translateY(0); }
        .signup-section { text-align: center; padding-top: 20px; border-top: 1px solid #f5f5f5; }
        .signup-text { font-size: 13px; color: #666; }
        .signup-link { color: #db6b6b; text-decoration: none; font-weight: 600; transition: color 0.3s; }
        .signup-link:hover { color: #2c3e50; }
        .error-message { background: #fff5f5; color: #c33; padding: 12px 15px; border-radius: 6px; font-size: 13px; margin-bottom: 20px; border-left: 3px solid #c33; display: none; line-height: 1.5; }
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
            <a href="index.php" class="back-btn">回首頁</a>
        </nav>
    </header>

    <div class="login-wrapper">
        <div class="login-container">
            <div class="logo-section">
                <div class="logo">All Pass</div>
                <div class="logo-subtitle">會員登入</div>
            </div>

            <div class="divider"></div>

            <?php if(!empty($error_message)): ?>
                <div class="error-message show">
                    <?php echo $error_message; ?>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST">
                <div class="form-group">
                    <label for="email">電子郵件 / 帳號</label>
                    <input type="email" id="email" name="email" placeholder="輸入你的電子郵件或帳號" required>
                </div>

                <div class="form-group">
                    <label for="password">密碼</label>
                    <input type="password" id="password" name="password" placeholder="輸入密碼" required>
                </div>

                <div class="remember-forgot">
                    <label><input type="checkbox" name="remember">記住我</label>
                    <a href="#" class="forgot-link">忘記密碼？</a>
                </div>

                <button type="submit" class="login-btn">登入</button>
            </form>

            <div class="signup-section">
                <p class="signup-text">還沒有帳戶？<a href="register.php" class="signup-link">立即註冊</a></p>
            </div>
        </div>
    </div>

</body>
</html>
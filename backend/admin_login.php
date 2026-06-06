<?php
session_start();
require_once __DIR__ . '/../homepage/includes/security.php';

apConfigureErrorHandling();

// 如果已經登入過了，直接跳轉到後台主頁
if (isset($_SESSION['admin_id'])) {
    header("Location: backend.php");
    exit();
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!apValidateCsrf()) {
        $error = "❌ 表單驗證失敗，請重新送出。";
    } else {
    $conn = new mysqli("localhost", "root", "", "all_pass_db");
    if ($conn->connect_error) {
        error_log('Admin login database connection failed: ' . $conn->connect_error);
        $error = "❌ 系統暫時無法連線資料庫，請稍後再試。";
    } else {
    $conn->set_charset("utf8mb4");
    
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    // 🛡️ 嚴格防護 A：限制長度，防止塞爆伺服器
    if (strlen($username) > 50 || strlen($password) > 255) {
        $error = "❌ 輸入長度異常，請勿惡意測試！";
    }
    // 🛡️ 嚴格防護 B：白名單驗證 (只允許英文、數字、底線)
    elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $error = "❌ 帳號格式錯誤，僅允許英數字！";
    }
    else {
        // 1. 查詢該管理員帳號 (防 SQL 注入的預備語句)
        $stmt = $conn->prepare("SELECT admin_id, role_id, password_hash, status FROM admin_users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($user = $result->fetch_assoc()) {
            // 2. 檢查帳號是否為 ACTIVE 狀態
            if ($user['status'] !== 'ACTIVE') {
                $error = "❌ 該帳號已被停用，請聯絡系統負責人。";
            } 
            // 3. 🌟 關鍵：比對明文密碼與資料庫密文
            else if (password_verify($password, $user['password_hash'])) {
                // 登入成功！發放通行證 (Session)
                $_SESSION['admin_id'] = $user['admin_id'];
                $_SESSION['admin_username'] = $username;
                $_SESSION['role_id'] = $user['role_id'];

                header("Location: backend.php");
                exit();
            } else {
                $error = "❌ 密碼錯誤！";
            }
        } else {
            $error = "❌ 找不到該管理員帳號！";
        }
        $stmt->close();
    }
    $conn->close();
    }
    }
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>All Pass 管理員登入</title>
    <style>
        body { font-family: 'PingFang TC', sans-serif; background: #2c3e50; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .login-box { background: white; padding: 40px; border-radius: 10px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); width: 350px; }
        h2 { text-align: center; color: #333; margin-bottom: 30px; letter-spacing: 2px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: bold; color: #666; }
        input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 5px; box-sizing: border-box; }
        .login-btn { width: 100%; padding: 12px; background: #db6b6b; color: white; border: none; border-radius: 5px; cursor: pointer; font-weight: bold; font-size: 16px; margin-top: 10px; }
        .login-btn:hover { background: #c05a5a; }
        .error-msg { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 5px; margin-bottom: 20px; text-align: center; font-size: 14px; }
        .footer-link { text-align: center; margin-top: 20px; font-size: 12px; color: #999; }
    </style>
</head>
<body>

    <div class="login-box">
        <h2>ADMIN LOGIN</h2>

        <?php if($error): ?>
            <div class="error-msg"><?php echo $error; ?></div>
        <?php endif; ?>

        <form action="admin_login.php" method="POST">
            <?php echo apCsrfField(); ?>
            <div class="form-group">
                <label>管理員帳號</label>
                <input type="text" name="username" placeholder="請輸入帳號" required>
            </div>
            <div class="form-group">
                <label>登入密碼</label>
                <input type="password" name="password" placeholder="請輸入密碼" required>
            </div>
            <button type="submit" class="login-btn">進入管理系統</button>
        </form>

        <div class="footer-link">
            &copy; 2026 All Pass Luggage Co. <br>
            <a href="index.php" style="color:#999; text-decoration:none;">回前台首頁</a>
        </div>
    </div>

</body>
</html>

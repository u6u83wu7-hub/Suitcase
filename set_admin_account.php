<?php
// set_admin_account.php(06/06)
// 1. 設定資料庫連線資訊
$conn = new mysqli("localhost", "root", "", "all_pass_db");

if ($conn->connect_error) {
    die("連線失敗: " . $conn->connect_error);
}

// 2. 設定你想要的帳號與明文密碼
$user = 'admin';
$pass = 'adminOne01'; // 這是你之後在網頁登入時要打的字

// 3. 🌟 關鍵：將密碼進行 Hash 加密
// 這會產生一串類似 $2y$10$px... 的 60 個字元長度亂碼
$hashed_password = password_hash($pass, PASSWORD_DEFAULT);

// 4. 依照規格書建立管理員 (假設 role_id = 1 是超級管理員)
$sql = "INSERT INTO admin_users (role_id, username, password_hash, status) 
        VALUES (1, '$user', '$hashed_password', 'ACTIVE')";

if ($conn->query($sql) === TRUE) {
    echo "<h3>✅ 管理員帳號建立成功！</h3>";
    echo "<b>帳號：</b>" . $user . "<br>";
    echo "<b>明文密碼：</b>" . $pass . "<br>";
    echo "<b>資料庫存儲的密文 (Hash)：</b><br><code style='background:#eee; padding:5px;'>" . $hashed_password . "</code><br><br>";
    echo "👉 現在你可以使用 admin 去登入後台了！";
} else {
    echo "錯誤: " . $conn->error;
}

$conn->close();
?>
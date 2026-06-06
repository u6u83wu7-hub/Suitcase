<?php
session_start();

// 只清除前台會員登入狀態，避免同一瀏覽器中的後台管理員 session 被一併登出。
unset($_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_email']);

if (session_status() === PHP_SESSION_ACTIVE) {
    session_regenerate_id(true);
}

// 將網頁自動導向回首頁
header("Location: index.php");
exit();
?>

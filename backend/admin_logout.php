<?php
session_start();

// 只清除後台管理員登入狀態，避免同一瀏覽器中的前台會員 session 被一併登出。
unset($_SESSION['admin_id'], $_SESSION['admin_username'], $_SESSION['role_id']);

if (session_status() === PHP_SESSION_ACTIVE) {
    session_regenerate_id(true);
}

header("Location: admin_login.php");
exit();
?>

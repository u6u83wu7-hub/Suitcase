<?php
session_start();

// 清空 session 並登出
$_SESSION = array();
session_destroy();

header("Location: admin_login.php");
exit();
?>

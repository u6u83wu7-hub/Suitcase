<?php
session_start();

// 清空所有的 Session 變數
$_SESSION = array();

// 徹底銷毀這個 Session
session_destroy();

// 將網頁自動導向回首頁
header("Location: index.php");
exit();
?>
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_id'])) {
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    $basePath = preg_replace('#/(actions|products)$#', '', $basePath);
    if (!headers_sent()) {
        header("Location: {$basePath}/admin_login.php");
    } else {
        echo '<p>請先登入管理員帳號。</p>';
        echo '<p><a href="' . htmlspecialchars($basePath . '/admin_login.php') . '">前往登入</a></p>';
    }
    exit();
}

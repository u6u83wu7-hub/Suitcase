<?php
require_once __DIR__ . '/auth_guard.php';
// Admin profile page (included in backend layout)
$admin_name = isset($_SESSION['admin_username']) ? $_SESSION['admin_username'] : '管理者';
?>
<h1>🔐 管理者資料</h1>
<p class="muted">帳號：<strong><?php echo htmlspecialchars($admin_name); ?></strong></p>
<p class="muted">角色：管理員</p>

<div style="margin-top:16px;">
    <a href="change_password.php"><button class="alt">修改密碼</button></a>
    <a href="admin_logout.php"><button class="alt">登出</button></a>
</div>

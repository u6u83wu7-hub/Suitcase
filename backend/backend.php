<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('Asia/Taipei');

require_once __DIR__ . '/auth_guard.php';


$conn = new mysqli("localhost", "root", "", "all_pass_db");
if ($conn->connect_error) {
    die("資料庫連線失敗: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");
$conn->query("SET time_zone = '+08:00'");

$admin_username = isset($_SESSION['admin_username']) ? $_SESSION['admin_username'] : '管理者';

$page = isset($_GET['page']) ? $_GET['page'] : 'products';

// White-list allowed pages to avoid path traversal
$allowed = [
    'dashboard', 'products', 'categories', 'orders', 'members', 'marketing', 'system', 'profile', 'edit_product'
];
if (!in_array($page, $allowed)) {
    $page = 'products';
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>All Pass 管理後台</title>
    <style>
        body { margin: 0; font-family: Arial, 'PingFang TC', 'Microsoft JhengHei', sans-serif; background: #f5f5f5; }
        .app { display: flex; min-height: 100vh; }
        .sidebar { width: 260px; background: #1a1a1a; color: #fff; padding: 24px; box-shadow: 2px 0 8px rgba(0,0,0,0.08); flex-shrink: 0;}
        .brand { font-size: 18px; font-weight: 800; color: #db6b6b; margin-bottom: 18px; }
        .admin-box { background: rgba(255,255,255,0.04); padding: 12px; border-radius: 8px; margin-bottom: 18px; }
        .admin-box .name { font-weight: 700; }
        .menu { margin-top: 10px; padding: 0; }
        .menu li { list-style: none; margin: 8px 0; }
        .menu a { color: #ddd; text-decoration: none; display: block; padding: 10px 12px; border-radius: 6px; }
        .menu a:hover, .menu a.active { background: rgba(219,107,107,0.12); color: #fff; }

        .main { flex: 1; padding: 28px; min-width: 0;}
        .card { background: #fff; padding: 22px; border-radius: 10px; box-shadow: 0 6px 18px rgba(0,0,0,0.04); }
        h1 { margin-top: 0; font-size: 20px; }
        .muted { color: #666; font-size: 14px; }

        /* simple form styles reused */
        input, select { width:100%; padding:10px; margin-top:8px; margin-bottom:14px; box-sizing:border-box; }
        button { padding:12px 16px; border:none; background:#2c3e50; color:#fff; border-radius:6px; cursor:pointer; }
        button.alt { background:#db6b6b; }
    </style>
</head>
<body>

<div class="app">
    <aside class="sidebar">
        <div class="brand">All Pass 管理系統</div>
        <div class="admin-box">
            <div class="name">您好，<?php echo htmlspecialchars($admin_username); ?></div>
            <div class="muted" style="margin-top:6px;">管理者介面</div>
            <div style="margin-top:10px;">
                <a href="admin_logout.php" style="color:#fff; font-size:13px;">登出</a>
            </div>
        </div>

        <ul class="menu">
            <li><a href="backend.php?page=dashboard" class="<?php echo $page=='dashboard' ? 'active' : ''; ?>">📊 營運儀表板</a></li>
            <li><a href="backend.php?page=products" class="<?php echo $page=='products' ? 'active' : ''; ?>">📦 商品管理</a></li>
            <li><a href="backend.php?page=categories" class="<?php echo $page=='categories' ? 'active' : ''; ?>">🏷️ 分類管理</a></li>
            <li><a href="backend.php?page=orders" class="<?php echo $page=='orders' ? 'active' : ''; ?>">🧾 訂單管理</a></li>
            <li><a href="backend.php?page=members" class="<?php echo $page=='members' ? 'active' : ''; ?>">👥 會員與客服管理</a></li>
            <li><a href="backend.php?page=marketing" class="<?php echo $page=='marketing' ? 'active' : ''; ?>">📢 行銷與內容管理</a></li>
            <li><a href="backend.php?page=system" class="<?php echo $page=='system' ? 'active' : ''; ?>">⚙️ 系統與權限管理</a></li>
            <li style="margin-top:12px;"><a href="backend.php?page=profile" class="<?php echo $page=='profile' ? 'active' : ''; ?>">🔐 管理者資料</a></li>
        </ul>
    </aside>

    <main class="main">
        <div class="card">
            <?php
            // include the page content from separate files inside the backend folder
            $include_file = __DIR__ . '/' . $page . '.php';
            if (file_exists($include_file)) {
                include $include_file;
            } else {
                echo '<h1>找不到頁面</h1><p class="muted">請確認您要訪問的頁面是否存在。</p>';
            }
            ?>
        </div>
    </main>
</div>

<?php $conn->close(); ?>

</body>
</html>
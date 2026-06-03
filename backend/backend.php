<?php
date_default_timezone_set('Asia/Taipei');

require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/../homepage/includes/security.php';

apConfigureErrorHandling();

$conn = new mysqli("localhost", "root", "", "all_pass_db");
if ($conn->connect_error) {
    die("資料庫連線失敗: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");
$conn->query("SET time_zone = '+08:00'");

$admin_username = isset($_SESSION['admin_username']) ? $_SESSION['admin_username'] : '管理者';
$page = isset($_GET['page']) ? $_GET['page'] : 'profile'; // 預設首頁為 dashboard

$allowed = [
    'dashboard', 'products', 'categories', 'orders', 'coupon', 'members', 'customer_service', 'marketing', 'system', 'profile', 'edit_product'
];
if (!in_array($page, $allowed)) {
    $page = 'profile';
}

// 撈取目前登入管理員的角色 ID (1 = 超級管理, 2 = 客服)
$admin_role_id = 2; 
if (isset($_SESSION['admin_username'])) {
    $stmt_role = $conn->prepare("SELECT role_id FROM admin_users WHERE username = ? LIMIT 1");
    $stmt_role->bind_param("s", $_SESSION['admin_username']);
    $stmt_role->execute();
    if ($r_row = $stmt_role->get_result()->fetch_assoc()) {
        $admin_role_id = intval($r_row['role_id']);
    }
    $stmt_role->close();
}

// 💡 核心修正 1：網址列強力攔截（白名單機制）
// 如果目前登入的不是超級管理員 (role_id != 1)，就限制他只能存取特定的客服相關頁面
if ($admin_role_id !== 1) {
    $cs_allowed_pages = ['customer_service', 'profile', 'coupon'];
    
    if (!in_array($page, $cs_allowed_pages, true)) {
        $page = 'profile'; // 只要企圖硬闖不對應的頁面，通通強制踢回儀表板！
    }
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>All Pass 管理後台</title>
    <style>
        :root {
            --primary: #db6b6b;
            --primary-dark: #b45353;
            --dark-bg: #1a1a1a;
            --card-bg: #fff;
        }
        body { margin: 0; font-family: 'PingFang TC', 'Microsoft JhengHei', sans-serif; background: #f5f7fb; color: #333; }
        .app { display: flex; min-height: 100vh; }
        
        .sidebar { 
            width: 260px; 
            background: var(--dark-bg); 
            color: #fff; 
            padding: 24px 16px; 
            box-shadow: 2px 0 8px rgba(0,0,0,0.05); 
            flex-shrink: 0;
            transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1), padding 0.3s;
            overflow: hidden;
            position: relative;
        }
        .sidebar.collapsed {
            width: 60px;
            padding: 24px 8px;
        }

        .brand-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; white-space: nowrap; }
        .brand { font-size: 18px; font-weight: 800; color: var(--primary); }
        .sidebar.collapsed .brand, .sidebar.collapsed .admin-box { display: none; }
        
        .toggle-btn {
            background: rgba(255,255,255,0.1);
            color: #fff;
            border: none;
            border-radius: 4px;
            padding: 6px 10px;
            cursor: pointer;
            font-size: 12px;
        }
        .toggle-btn:hover { background: var(--primary); }

        .admin-box { background: rgba(255,255,255,0.03); padding: 14px; border-radius: 8px; margin-bottom: 20px; }
        .admin-box .name { font-weight: 700; color: #fff; }
        
        .menu { margin-top: 10px; padding: 0; list-style: none; }
        .menu li { margin: 6px 0; position: relative; }
        .menu a { color: #cbd5e1; text-decoration: none; display: flex; align-items: center; padding: 12px 14px; border-radius: 8px; white-space: nowrap; transition: all 0.2s; }
        .menu a:hover, .menu a.active { background: rgba(219,107,107,0.15); color: #fff; font-weight: 700; }
        
        .menu a .text { transition: opacity 0.2s; }
        .sidebar.collapsed .menu a .text { opacity: 0; width: 0; pointer-events: none; display: inline-block; }
        .menu a::before { content: '📁'; margin-right: 12px; font-size: 14px; }
        .menu li:nth-child(1) a::before { content: '📊'; }
        .menu li:nth-child(2) a::before { content: '📦'; }
        .menu li:nth-child(3) a::before { content: '🏷️'; }
        .menu li:nth-child(4) a::before { content: '📜'; }
        .menu li:nth-child(5) a::before { content: '🎟️'; }
        .menu li:nth-child(6) a::before { content: '👥'; }
        .menu li:nth-child(7) a::before { content: '💬'; }
        .menu li:nth-child(8) a::before { content: '🎯'; }
        .menu li:nth-child(9) a::before { content: '⚙️'; }
        
        .main { flex: 1; padding: 32px; min-width: 0;}
        .card { background: var(--card-bg); padding: 28px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.02); }
        
        input, select { width:100%; padding:10px; margin-top:8px; margin-bottom:14px; box-sizing:border-box; border: 1px solid #ddd; border-radius:6px; }
        button { padding:12px 18px; border:none; background:#2c3e50; color:#fff; border-radius:6px; cursor:pointer; font-weight:700; }
        button.alt { background: var(--primary); }
        button:hover { opacity: 0.9; }
    </style>
</head>
<body>

<div class="app">
    <aside class="sidebar" id="appSidebar">
        <div class="brand-row">
            <div class="brand">All Pass 後台</div>
            <button class="toggle-btn" id="sidebarToggle" type="button" title="收合選單">☰</button>
        </div>
        <div class="admin-box">
            <div class="name">您好，<?php echo htmlspecialchars($admin_username); ?></div>
            <div class="muted" style="margin-top:4px; color:#94a3b8; font-size:14px;">管理者介面</div>
            <div style="margin-top:12px;">
                <a href="admin_logout.php" style="color: var(--primary); font-size:13px; text-decoration:none; font-weight:700;">🚪 安全登出</a>
            </div>
        </div>

        <ul class="menu">
         <?php if ($admin_role_id === 1): ?>
            <li><a href="backend.php?page=dashboard" class="<?php echo $page=='dashboard' ? 'active' : ''; ?>"><span class="text">營運儀表板</span></a></li>
         <?php endif; ?>
             
         <?php if ($admin_role_id === 1): ?>
            <li><a href="backend.php?page=products" class="<?php echo $page=='products' ? 'active' : ''; ?>"><span class="text">商品管理</span></a></li>
         <?php endif; ?>
             
            <?php if ($admin_role_id === 1): ?>
                <li><a href="backend.php?page=categories" class="<?php echo $page=='categories' ? 'active' : ''; ?>"><span class="text">分類管理</span></a></li>
            <?php endif; ?>
            <?php if ($admin_role_id === 1): ?>
            <li><a href="backend.php?page=orders" class="<?php echo $page=='orders' ? 'active' : ''; ?>"><span class="text">訂單管理</span></a></li>
            <?php endif; ?>

            <?php if ($admin_role_id === 1): ?>
            <li><a href="backend.php?page=coupon" class="<?php echo $page=='coupon' ? 'active' : ''; ?>"><span class="text">優惠卷管理</span></a></li>
            <?php endif; ?>
                
            <li><a href="backend.php?page=members" class="<?php echo $page=='members' ? 'active' : ''; ?>"><span class="text">會員管理</span></a></li>
            <li><a href="backend.php?page=customer_service" class="<?php echo $page=='customer_service' ? 'active' : ''; ?>"><span class="text">客服管理</span></a></li>
            
            <?php if ($admin_role_id === 1): ?>
                <li><a href="backend.php?page=marketing" class="<?php echo $page=='marketing' ? 'active' : ''; ?>"><span class="text">行銷內容管理</span></a></li>
            <?php endif; ?>
            
            <?php if ($admin_role_id === 1): ?>
                <li><a href="backend.php?page=system" class="<?php echo $page=='system' ? 'active' : ''; ?>"><span class="text">系統權限管理</span></a></li>
            <?php endif; ?>
            
            <li style="margin-top:20px;"><a href="backend.php?page=profile" class="<?php echo $page=='profile' ? 'active' : ''; ?>"><span class="text">管理者資料</span></a></li>
        </ul>
    </aside>

    <main class="main">
        <div class="card">
            <?php
            $include_file = __DIR__ . '/' . $page . '.php';
            if (file_exists($include_file)) {
                include $include_file;
            } else {
                echo '<h1>找不到頁面</h1><p class="muted" style="font-size:14px; color:#64748b;">請確認您要訪問的頁面是否存在。</p>';
            }
            ?>
        </div>
    </main>
</div>

<?php echo apCsrfFormScript(); ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('appSidebar');
    const toggleBtn = document.getElementById('sidebarToggle');
    
    if (localStorage.getItem('sidebar-collapsed') === 'true') {
        sidebar.classList.add('collapsed');
    }

    if (toggleBtn && sidebar) {
        toggleBtn.addEventListener('click', function() {
            const isCollapsed = sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebar-collapsed', isCollapsed);
        });
    }
});
</script>

<?php $conn->close(); ?>
</body>
</html>
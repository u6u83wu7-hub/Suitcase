<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($pageTitle)) {
    $pageTitle = 'All Pass 行李箱專賣 | Your All-Access Pass';
}

if (!isset($activeNav)) {
    $activeNav = '';
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'PingFang TC', 'Microsoft JhengHei', sans-serif; }
        body { background-color: #fcfcfc; color: #333; overflow-x: hidden; }
        a { text-decoration: none; color: inherit; }
        ul { list-style: none; }

        .top-bar { height: 3px; background-color: #db6b6b; width: 100%; position: fixed; top: 0; left: 0; z-index: 1001; }
        header { position: absolute; top: 3px; width: 100%; background: #1a1a1a; z-index: 1000; border-bottom: 1px solid #333; }
        .header-top { display: flex; justify-content: space-between; align-items: center; padding: 20px 5%; border-bottom: 1px solid #333; }
        .header-left { flex: 1; }
        .header-center { flex: 1; text-align: center; font-size: 32px; font-weight: 800; color: #ffffff; letter-spacing: 4px; display: block; }
        .header-center:hover { color: #db6b6b; }
        .header-right { flex: 1; display: flex; justify-content: flex-end; align-items: center; gap: 25px; }

        .search-box { display: flex; align-items: center; border-bottom: 1px solid #777; padding: 4px 0; }
        .search-box input { border: none; outline: none; width: 140px; font-size: 14px; background: transparent; color: #ffffff; transition: width 0.3s; }
        .search-box input::placeholder { color: #aaa; }
        .search-box input:focus { width: 180px; }
        .search-box button { background: none; border: none; cursor: pointer; font-size: 16px; color: #ffffff; }

        .icon-btn { font-size: 22px; cursor: pointer; position: relative; color: #ffffff; transition: color 0.3s; }
        .icon-btn:hover { color: #db6b6b; }
        .cart-badge { position: absolute; top: -5px; right: -8px; background: #db6b6b; color: white; font-size: 10px; font-weight: bold; padding: 2px 6px; border-radius: 50%; }

        .header-bottom { display: flex; justify-content: center; padding: 15px 0; background-color: #ffffff; border-bottom: 1px solid #e8e8e8; }
        .nav-links { display: flex; gap: 45px; align-items: center; }
        .dropdown { position: relative; display: inline-block; cursor: pointer; color: #333; font-size: 15px; font-weight: 500; letter-spacing: 1px; padding: 5px 0; transition: color 0.3s; }
        .dropdown::after { content: ' ▾'; font-size: 10px; color: #999; }
        .dropdown:hover { color: #db6b6b; }
        .icon-dropdown::after { content: ''; }
        .icon-dropdown .dropdown-content { right: 0; left: auto; transform: none; min-width: 130px; border-top: 2px solid #db6b6b; }
        .dropdown-content { display: none; position: absolute; top: 100%; left: 50%; transform: translateX(-50%); background-color: #fff; min-width: 160px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); border-top: 2px solid #db6b6b; padding: 10px 0; z-index: 1001; }
        .dropdown:hover .dropdown-content { display: block; }
        .dropdown-content a { color: #333; padding: 12px 20px; display: block; font-size: 14px; text-align: center; transition: background 0.2s, color 0.2s; }
        .dropdown-content a:hover { background-color: #f9f9f9; color: #db6b6b; }
        .nav-text-link { color: #333; font-size: 15px; font-weight: 500; letter-spacing: 1px; transition: color 0.3s; }
        .nav-text-link:hover { color: #db6b6b; }
        .nav-text-link.is-active { color: #db6b6b; }
        .sale-link { color: #e74c3c; font-weight: 600; }

        .hero { position: relative; height: 100vh; background: linear-gradient(to bottom, rgba(0, 0, 0, 0.4) 0%, rgba(0, 0, 0, 0.1) 100%), url('../img/輪播1.png') center/cover no-repeat; display: flex; align-items: center; justify-content: center; text-align: center; padding-top: 103px; }
        .hero-text { color: #fff; z-index: 10; text-shadow: 0 4px 15px rgba(0,0,0,0.3); }
        .hero-text h1 { font-size: 5rem; font-weight: 800; letter-spacing: 15px; margin-bottom: 15px; animation: fadeInDown 1.2s ease-out; }
        .hero-text p { font-size: 1.4rem; font-weight: 300; letter-spacing: 4px; text-transform: uppercase; opacity: 0.95; }
        @keyframes fadeInDown { from { opacity: 0; transform: translateY(-30px); } to { opacity: 1; transform: translateY(0); } }

        .trust-badges { display: flex; justify-content: center; gap: 80px; background: #fff; padding: 30px 5%; border-bottom: 1px solid #f0f0f0; }
        .badge { display: flex; align-items: center; gap: 10px; font-size: 15px; color: #666; font-weight: 500; }

        .page-hero { padding: 160px 5% 70px; background: linear-gradient(to bottom, rgba(26, 26, 26, 0.95), rgba(26, 26, 26, 0.78)), url('../img/輪播1.png') center/cover no-repeat; color: #fff; text-align: center; }
        .page-hero h1 { font-size: 4rem; letter-spacing: 10px; margin-bottom: 16px; }
        .page-hero p { font-size: 1.05rem; letter-spacing: 2px; color: rgba(255,255,255,0.88); }
        .hero-actions { margin-top: 28px; display: flex; justify-content: center; gap: 14px; }
        .hero-btn { display: inline-flex; align-items: center; justify-content: center; min-width: 140px; padding: 12px 20px; border: 1px solid rgba(255,255,255,0.7); color: #fff; background: rgba(255,255,255,0.08); border-radius: 999px; font-size: 14px; font-weight: 600; letter-spacing: 1px; transition: all 0.3s ease; }
        .hero-btn:hover { background: #db6b6b; border-color: #db6b6b; transform: translateY(-1px); }

        .section-container { padding: 80px 5%; max-width: 1300px; margin: 0 auto; }
        .section-title { font-size: 28px; font-weight: 700; margin-bottom: 50px; color: #222; text-align: center; letter-spacing: 3px; }
        .product-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 50px 30px; }
        @media (max-width: 992px) { .product-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 576px) { .product-grid { grid-template-columns: 1fr; } }

        .product-card { cursor: pointer; transition: transform 0.3s ease; }
        .product-img-wrapper { overflow: hidden; position: relative; margin-bottom: 20px; background-color: #f5f5f5; }
        .product-img { width: 100%; aspect-ratio: 1 / 1; object-fit: cover; transition: transform 0.6s cubic-bezier(0.25, 0.46, 0.45, 0.94); display: block; }
        .product-card:hover .product-img { transform: scale(1.08); }
        .product-info { text-align: center; }
        .product-title { font-size: 16px; margin-bottom: 10px; color: #333; font-weight: 500; line-height: 1.5; }
        .product-price { font-size: 17px; font-weight: 700; color: #2c3e50; }
        .empty-state { grid-column: 1 / -1; text-align: center; color: #999; padding: 40px 0; }

        .site-footer { border-top: 1px solid #ececec; background: #fff; padding: 24px 5%; color: #777; }
        .site-footer-inner { max-width: 1300px; margin: 0 auto; display: flex; justify-content: space-between; gap: 16px; align-items: center; flex-wrap: wrap; font-size: 13px; }
        .site-footer a { color: #db6b6b; font-weight: 600; }

        @media (max-width: 768px) {
            .header-top { flex-direction: column; gap: 14px; }
            .header-right { justify-content: center; flex-wrap: wrap; }
            .nav-links { gap: 18px; flex-wrap: wrap; justify-content: center; }
            .hero-text h1 { font-size: 3rem; letter-spacing: 8px; }
            .hero { height: 80vh; }
            .trust-badges { gap: 24px; flex-wrap: wrap; }
            .site-footer-inner { justify-content: center; text-align: center; }
        }
    </style>
</head>
<body>
    <div class="top-bar"></div>
    <header>
        <div class="header-top">
            <div class="header-left"></div>
            <a href="index.php" class="header-center" aria-label="回首頁">All Pass</a>
            <div class="header-right">
                <div class="search-box">
                    <input type="text" placeholder="Search...">
                    <button>🔍</button>
                </div>

                <?php if (isset($_SESSION['user_id'])): ?>
                    <div class="dropdown icon-dropdown icon-btn" style="padding-bottom: 10px; margin-bottom: -10px;">
                        👤
                        <div class="dropdown-content">
                            <div style="padding: 10px 15px; border-bottom: 1px solid #eee; text-align: center; color: #db6b6b; font-weight: bold; font-size: 14px;">
                                Hi, <?php echo htmlspecialchars($_SESSION['user_name']); ?>
                            </div>
                            <a href="profile.php">會員資料</a>
                            <a href="change_password.php">修改密碼</a>
                            <a href="logout.php">登出</a>
                        </div>
                    </div>
                <?php else: ?>
                    <a href="login.php" class="icon-btn" title="登入">👤</a>
                <?php endif; ?>

                <div class="icon-btn" title="購物車">🛒<span class="cart-badge">0</span></div>
            </div>
        </div>

        <div class="header-bottom">
            <nav class="nav-links">
                <a href="new_in.php" class="nav-text-link <?php echo $activeNav === 'new_in' ? 'is-active' : ''; ?>">NEW IN 新品</a>
                <div class="dropdown">款式材質
                    <div class="dropdown-content">
                        <a href="#">硬殼 (Hard Shell)</a>
                        <a href="#">軟殼 (Soft Shell)</a>
                    </div>
                </div>
                <div class="dropdown">尺寸挑選
                    <div class="dropdown-content">
                        <a href="#">登機箱 (20吋以下)</a>
                        <a href="#">中型箱 (24-26吋)</a>
                        <a href="#">大型箱 (28吋以上)</a>
                    </div>
                </div>
                <div class="dropdown">開合構造
                    <div class="dropdown-content">
                        <a href="#">輕量拉鍊款</a>
                        <a href="#">堅固鋁框款</a>
                        <a href="#">便利前開款</a>
                    </div>
                </div>
                <a href="#" class="nav-text-link sale-link">SALE 優惠專區</a>
            </nav>
        </div>
    </header>
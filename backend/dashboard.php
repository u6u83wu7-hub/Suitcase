<?php
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);
// dashboard.php - 營運儀表板數據核心與頂級 UI/UX 視覺化頁面
require_once __DIR__ . '/auth_guard.php';

// 建立局部連線（防範外部 include 變數未同步）
$conn = new mysqli("localhost", "root", "", "all_pass_db");
if ($conn->connect_error) {
    echo "<div style='color:red; padding:20px;'>資料庫連線失敗，無法載入儀表板數據。</div>";
    return;
}
$conn->set_charset("utf8mb4");

$adminRoleId = 2;
$currentSupplierId = null;
if (isset($_SESSION['admin_username'])) {
    $roleStmt = $conn->prepare("SELECT role_id, admin_id FROM admin_users WHERE username = ? LIMIT 1");
    $roleStmt->bind_param('s', $_SESSION['admin_username']);
    $roleStmt->execute();
    $roleRow = $roleStmt->get_result()->fetch_assoc();
    if ($roleRow) {
        $adminRoleId = intval($roleRow['role_id']);
        if ($adminRoleId === 3) {
            $adminId = intval($roleRow['admin_id']);
            $supplierStmt = $conn->prepare("SELECT supplier_id FROM suppliers WHERE admin_id = ? LIMIT 1");
            $supplierStmt->bind_param('i', $adminId);
            $supplierStmt->execute();
            if ($supplierRow = $supplierStmt->get_result()->fetch_assoc()) {
                $currentSupplierId = intval($supplierRow['supplier_id']);
            }
            $supplierStmt->close();
        }
    }
    $roleStmt->close();
}

$vendorProductWhereSql = '';
if ($adminRoleId === 3 && $currentSupplierId !== null) {
    $vendorProductWhereSql = ' AND p.supplier_id = ' . intval($currentSupplierId);
}

$vendorOrderWhereSql = '';
if ($adminRoleId === 3 && $currentSupplierId !== null) {
    $vendorOrderWhereSql = ' AND EXISTS (
        SELECT 1
        FROM order_items oi2
        JOIN product_variants pv2 ON pv2.variant_id = oi2.variant_id
        JOIN products p2 ON p2.product_id = pv2.product_id
        WHERE oi2.order_id = o.order_id
          AND p2.supplier_id = ' . intval($currentSupplierId) . '
    )';
}

// =================【 1. 後端關鍵數據即時統計 】=================

$total_sales = 0;
$order_count = 0;
$member_count = 0;
$low_stock_count = 0;
$pending_return_count = 0;
$aov = 0; // 客單價

// 💡 統計銷售額與總有效訂單
if ($adminRoleId === 3 && $currentSupplierId !== null) {
    $resOrders = $conn->query("SELECT SUM(oi.quantity * oi.locked_price) AS total, COUNT(DISTINCT o.order_id) AS cnt
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.order_id
        JOIN product_variants pv ON pv.variant_id = oi.variant_id
        JOIN products p ON p.product_id = pv.product_id
        WHERE o.status != 'CANCELLED' AND p.supplier_id = " . intval($currentSupplierId));
} else {
    $resOrders = $conn->query("SELECT SUM(total_amount) AS total, COUNT(order_id) AS cnt FROM orders WHERE status != 'CANCELLED'");
}
if ($resOrders && $row = $resOrders->fetch_assoc()) {
    $total_sales = floatval($row['total'] ?? 0);
    $order_count = intval($row['cnt'] ?? 0);
}

// 💡 計算客單價 (AOV = 總銷售額 / 總有效訂單)
if ($order_count > 0) {
    $aov = $total_sales / $order_count;
}

// 💡 統計活躍會員數
$resMembers = $conn->query("SELECT COUNT(user_id) AS cnt FROM users WHERE status = 'ACTIVE'");
if ($resMembers && $row = $resMembers->fetch_assoc()) {
    $member_count = intval($row['cnt'] ?? 0);
}

// 💡 統計庫存吃緊規格數 (庫存 <= 3 件)
$resLowStockCount = $conn->query("SELECT COUNT(pv.variant_id) AS cnt FROM product_variants pv JOIN products p ON pv.product_id = p.product_id WHERE pv.stock_available <= 3{$vendorProductWhereSql}");
if ($resLowStockCount && $row = $resLowStockCount->fetch_assoc()) {
    $low_stock_count = intval($row['cnt'] ?? 0);
}

// 統計待審核退貨，讓管理員一進後台就能看到需要處理的售後事件
$returnTableRes = $conn->query("SHOW TABLES LIKE 'return_requests'");
if ($returnTableRes && $returnTableRes->num_rows > 0) {
    $resPendingReturns = $conn->query("SELECT COUNT(DISTINCT rr.return_id) AS cnt
        FROM return_requests rr
        JOIN orders o ON o.order_id = rr.order_id
        WHERE rr.status = 'PENDING'{$vendorOrderWhereSql}");
    if ($resPendingReturns && $row = $resPendingReturns->fetch_assoc()) {
        $pending_return_count = intval($row['cnt'] ?? 0);
    }
}

// 💡 抓取庫存吃緊的詳細清單 (前 4 筆顯示在側欄快照)
$lowStockList = [];
$lowStockSql = "SELECT pv.sku_code, pv.color, pv.size_inches, pv.stock_available, p.name AS product_name 
                FROM product_variants pv 
                JOIN products p ON pv.product_id = p.product_id 
                WHERE pv.stock_available <= 3{$vendorProductWhereSql} 
                ORDER BY pv.stock_available ASC, p.product_id DESC LIMIT 4";
$resLowList = $conn->query($lowStockSql);
if ($resLowList) {
    while ($row = $resLowList->fetch_assoc()) {
        $lowStockList[] = $row;
    }
}

// 💡 建立商品銷售排行數據 (依據銷量 Qty 排序)
$rankingList = [];
$maxQty = 1; // 用來當作 CSS 進度條的 100% 基準值
$rankSql = "SELECT oi.product_name, SUM(oi.quantity) AS total_qty, SUM(oi.quantity * oi.locked_price) AS total_revenue
            FROM order_items oi
            JOIN orders o ON oi.order_id = o.order_id
            JOIN product_variants pv ON pv.variant_id = oi.variant_id
            JOIN products p ON p.product_id = pv.product_id
            WHERE o.status != 'CANCELLED'" . ($adminRoleId === 3 && $currentSupplierId !== null ? ' AND p.supplier_id = ' . intval($currentSupplierId) : '') . "
            GROUP BY oi.product_name
            ORDER BY total_qty DESC LIMIT 5";
$resRank = $conn->query($rankSql);
if ($resRank) {
    $isFirst = true;
    while ($row = $resRank->fetch_assoc()) {
        if ($isFirst) {
            $maxQty = intval($row['total_qty']) > 0 ? intval($row['total_qty']) : 1;
            $isFirst = false;
        }
        $rankingList[] = $row;
    }
}
?>

<style>
    .dash-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
    .dash-title-group h1 { margin: 0 0 4px 0; font-size: 24px; color: #1e293b; font-weight: 800; }
    
    /* 四核心卡片排版 */
    .dash-cards-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 20px; margin-bottom: 32px; }
    .dash-stat-card { 
        background: #fff; 
        border: 1px solid #e2e8f0; 
        border-radius: 12px; 
        padding: 24px; 
        display: flex; 
        flex-direction: column; 
        position: relative;
        text-decoration: none;
        color: inherit;
        transition: transform 0.2s, box-shadow 0.2s, border-color 0.2s;
    }
    /* 💡 針對「可跳轉」卡片增強滑鼠懸停反饋，提示使用者這是按鈕 */
    .dash-stat-card.clickable { cursor: pointer; }
    .dash-stat-card.clickable:hover { 
        transform: translateY(-3px); 
        box-shadow: 0 12px 20px rgba(0,0,0,0.04); 
        border-color: #db6b6b; 
    }
    .dash-stat-card .card-label { font-size: 13px; color: #64748b; font-weight: 700; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
    .dash-stat-card .card-value { font-size: 26px; font-weight: 800; color: #0f172a; line-height: 1.2; }
    .dash-stat-card .card-arrow { position: absolute; right: 20px; bottom: 20px; font-size: 12px; color: #94a3b8; opacity: 0; transition: opacity 0.2s, transform 0.2s; }
    .dash-stat-card.clickable:hover .card-arrow { opacity: 1; transform: translateX(3px); color: #db6b6b; }
    
    /* 危機警示卡特化樣式 */
    .dash-stat-card.danger-alert { border-left: 4px solid #ef4444; }
    .dash-stat-card.danger-alert.clickable:hover { border-color: #ef4444; box-shadow: 0 12px 20px rgba(239,68,68,0.06); }
    .dash-stat-card.danger-alert .card-value { color: #ef4444; }

    /* 下方雙欄複合式佈局 */
    .dash-layout-row { display: grid; grid-template-columns: 1.6fr 1fr; gap: 24px; align-items: start; }
    .dash-panel { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 24px; }
    .panel-title { font-size: 16px; font-weight: 800; color: #1e293b; margin: 0 0 20px 0; display: flex; align-items: center; justify-content: space-between; }
    
    /* 排行榜與 CSS 微圖表 */
    .rank-table { width: 100%; border-collapse: collapse; }
    .rank-table th { text-align: left; padding: 10px 12px; font-size: 13px; color: #64748b; border-bottom: 1px solid #edf2f7; }
    .rank-table td { padding: 14px 12px; font-size: 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    .rank-num { font-weight: 800; color: #94a3b8; width: 24px; }
    .rank-table tr:nth-child(1) .rank-num { color: #f59e0b; font-size: 16px; } /* 金牌色 */
    .rank-table tr:nth-child(2) .rank-num { color: #94a3b8; } /* 銀牌色 */
    .rank-table tr:nth-child(3) .rank-num { color: #b45309; } /* 銅牌色 */
    
    .prod-name-cell { font-weight: 700; color: #334155; margin-bottom: 6px; }
    
    /* 💡 核心視覺化：純 CSS 銷量比例圖表條 */
    .chart-bar-container { background: #f1f5f9; border-radius: 999px; height: 8px; width: 140px; overflow: hidden; display: inline-block; vertical-align: middle; margin-right: 8px; }
    .chart-bar-fill { background: linear-gradient(90deg, #fca5a5, #db6b6b); height: 100%; border-radius: 999px; }
    .chart-bar-text { font-size: 12px; font-weight: 800; color: #475569; display: inline-block; vertical-align: middle; }

    /* 庫存吃緊小快照清單 */
    .alert-stock-list { display: grid; gap: 12px; }
    .alert-stock-item { background: #fff5f5; border: 1px solid #fee2e2; border-radius: 8px; padding: 12px 14px; display: flex; justify-content: space-between; align-items: center; }
    .alert-stock-info { max-width: 75%; }
    .alert-stock-title { font-size: 13px; font-weight: 700; color: #991b1b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .alert-stock-desc { font-size: 11px; color: #dc2626; margin-top: 4px; font-weight: 600; }
    .alert-stock-badge { background: #ef4444; color: #fff; font-size: 11px; font-weight: 800; padding: 4px 10px; border-radius: 999px; white-space: nowrap; }
    
    .view-more-btn { font-size: 12px; color: #db6b6b; text-decoration: none; font-weight: 700; }
    .view-more-btn:hover { text-decoration: underline; }

    @media (max-width: 1024px) {
        .dash-cards-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .dash-layout-row { grid-template-columns: 1fr; }
    }
</style>

<div class="dash-header">
    <div class="dash-title-group">
        <h1>📊 營運儀表板</h1>
        <p class="muted">掌握全站關鍵商務指標、銷量排行與即時庫存危機監控。</p>
    </div>
</div>

<div class="dash-cards-grid">
    <div class="dash-stat-card">
        <span class="card-label">💰 商場總銷售額</span>
        <span class="card-value" style="color: #10b981;">NT$ <?php echo number_format($total_sales); ?></span>
    </div>

    <a href="backend.php?page=orders" class="dash-stat-card clickable">
        <span class="card-label">📜 有效訂單總量</span>
        <span class="card-value"><?php echo number_format($order_count); ?> 筆</span>
        <span class="card-arrow">查看訂單 ➔</span>
    </a>

    <a href="backend.php?page=orders&return_filter=PENDING" class="dash-stat-card clickable <?php echo $pending_return_count > 0 ? 'danger-alert' : ''; ?>">
        <span class="card-label">↩️ 待審核退貨</span>
        <span class="card-value"><?php echo number_format($pending_return_count); ?> 筆</span>
        <span class="card-arrow">前往審核 ➔</span>
    </a>

    <div class="dash-stat-card">
        <span class="card-label">📈 平均客單價 (AOV)</span>
        <span class="card-value">NT$ <?php echo number_format($aov); ?></span>
    </div>

    <a href="backend.php?page=members" class="dash-stat-card clickable">
        <span class="card-label">👥 活躍會員總數</span>
        <span class="card-value"><?php echo number_format($member_count); ?> 人</span>
        <span class="card-arrow">會員管理 ➔</span>
    </a>
</div>

<div class="dash-layout-row">
    <div class="dash-panel">
        <h2 class="panel-title">🏆 熱銷商品銷售排行 (Top 5) <span class="muted" style="font-weight:normal; font-size:12px;">依出貨件數排序</span></h2>
        <?php if (!empty($rankingList)): ?>
            <table class="rank-table">
                <thead>
                    <tr>
                        <th style="width: 40px;">名次</th>
                        <th>商品名稱</th>
                        <th style="width: 220px;">銷量比例圖表 (件數)</th>
                        <th style="width: 110px; text-align: right;">銷售累積總額</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $rankNum = 1;
                    foreach ($rankingList as $rank): 
                        // 計算目前銷量佔第一名的百分比，用來設定進度條寬度
                        $barPercent = round((intval($rank['total_qty']) / $maxQty) * 100);
                    ?>
                        <tr>
                            <td class="rank-num"><?php echo $rankNum++; ?></td>
                            <td>
                                <div class="prod-name-cell"><?php echo htmlspecialchars($rank['product_name']); ?></div>
                            </td>
                            <td>
                                <div class="chart-bar-container" title="第一名銷量對比率: <?php echo $barPercent; ?>%">
                                    <div class="chart-bar-fill" style="width: <?php echo $barPercent; ?>%;"></div>
                                </div>
                                <div class="chart-bar-text"><?php echo number_format($rank['total_qty']); ?> 件</div>
                            </td>
                            <td style="text-align: right; font-weight: 700; color: #1e293b;">
                                NT$ <?php echo number_format($rank['total_revenue']); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="color:#94a3b8; text-align:center; padding: 40px 0; margin:0;">目前商城尚無銷售紀錄，繼續加油！</p>
        <?php endif; ?>
    </div>

    <div class="dash-panel" style="background: #fff8f8; border-color: #fecaca;">
        <h2 class="panel-title" style="color: #991b1b;">
            ⚠️ 庫存危機快照 
            <a href="backend.php?page=products" class="view-more-btn" style="color: #dc2626;">進入商品管理處理 ➔</a>
        </h2>
        
        <div style="margin-bottom: 20px; background:#fff; padding:14px; border-radius:8px; border:1px solid #fee2e2;">
            <span style="font-size:12px; color:#7f1d1d; font-weight:700; display:block; margin-bottom:4px;">當前庫存量 ≤ 3 件的規格數</span>
            <span style="font-size:32px; font-weight:900; color:#ef4444;"><?php echo $low_stock_count; ?></span> <span style="font-size:14px; color:#7f1d1d; font-weight:700;">個項目亮紅燈</span>
        </div>

        <div class="alert-stock-list">
            <?php if (!empty($lowStockList)): ?>
                <?php foreach ($lowStockList as $stockItem): ?>
                    <div class="alert-stock-item">
                        <div class="alert-stock-info">
                            <div class="alert-stock-title" title="<?php echo htmlspecialchars($stockItem['product_name']); ?>">
                                <?php echo htmlspecialchars($stockItem['product_name']); ?>
                            </div>
                            <div class="alert-stock-desc">
                                規格：<?php echo htmlspecialchars(($stockItem['color'] ?? '通用色') . ' / ' . ($stockItem['size_inches'] ?? '通用尺寸')); ?>
                            </div>
                        </div>
                        <div class="alert-stock-badge">
                            剩 <?php echo intval($stockItem['stock_available']); ?> 件
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if ($low_stock_count > 4): ?>
                    <p style="text-align: center; margin: 8px 0 0 0; font-size:12px; color:#991b1b; font-weight:700;">
                        還有 <?php echo ($low_stock_count - 4); ?> 個規格在庫存邊緣，請盡速補貨...
                    </p>
                <?php endif; ?>
            <?php else: ?>
                <div style="text-align: center; color: #10b981; padding: 30px 0; font-weight: 700; font-size:14px;">
                    🎉 太棒了！目前全站商品庫存皆十分充足！
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php 
$conn->close(); 
?>

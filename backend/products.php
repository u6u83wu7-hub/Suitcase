<?php
// products.php - included by backend.php; assumes $conn and session already available

function pmTableColumns($conn, $tableName) {
    $cols = [];
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    $res = $conn->query("SHOW COLUMNS FROM `{$safeTable}`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $cols[] = $row['Field'];
        }
    }
    return $cols;
}

$perPage = 10;
$currentPage = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$offset = ($currentPage - 1) * $perPage;

$keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
$categoryFilter = isset($_GET['category_filter']) ? trim($_GET['category_filter']) : '';
$statusFilter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : '';
$featuredFilter = isset($_GET['featured_filter']) ? trim($_GET['featured_filter']) : '';

$categories = [];
$categorySql = "SELECT category_id, name FROM categories ORDER BY name ASC";
$categoryResult = $conn->query($categorySql);
if ($categoryResult) {
    while ($cat = $categoryResult->fetch_assoc()) {
        $categories[] = $cat;
    }
}

$conditions = [];
if ($keyword !== '') {
    $safeKeyword = $conn->real_escape_string($keyword);
    $conditions[] = "p.name LIKE '%{$safeKeyword}%'";
}
if ($categoryFilter !== '') {
    if ($categoryFilter === 'none') {
        $conditions[] = "p.primary_category_id IS NULL";
    } else {
        $conditions[] = "p.primary_category_id = " . intval($categoryFilter);
    }
}
if ($statusFilter !== '') {
    $safeStatus = $conn->real_escape_string($statusFilter);
    $conditions[] = "p.status = '{$safeStatus}'";
}
if ($featuredFilter !== '') {
    $conditions[] = "p.is_featured = " . intval($featuredFilter);
}

$whereClause = '';
if (!empty($conditions)) {
    $whereClause = 'WHERE ' . implode(' AND ', $conditions);
}

$productCols = pmTableColumns($conn, 'products');
$imageCols = pmTableColumns($conn, 'product_images');

$productOrderBy = in_array('created_at', $productCols, true)
    ? 'p.created_at DESC'
    : 'p.product_id DESC';

$imageOrderParts = [];
if (in_array('is_main', $imageCols, true)) {
    $imageOrderParts[] = 'pi.is_main DESC';
}
if (in_array('display_order', $imageCols, true)) {
    $imageOrderParts[] = 'pi.display_order ASC';
}
if (in_array('image_id', $imageCols, true)) {
    $imageOrderParts[] = 'pi.image_id ASC';
}
if (empty($imageOrderParts)) {
    $imageOrderParts[] = 'pi.product_id ASC';
}
$imageOrderBy = implode(', ', $imageOrderParts);

$countSql = "SELECT COUNT(*) AS total FROM products p {$whereClause}";
$countResult = $conn->query($countSql);
$totalProducts = ($countResult && $countResult->num_rows > 0) ? intval($countResult->fetch_assoc()['total']) : 0;
$totalPages = max(1, ceil($totalProducts / $perPage));

$productSql = "
    SELECT
        p.product_id,
        p.name,
        p.primary_category_id,
        p.status,
        p.is_featured,
        COUNT(v.product_id) AS sku_count,
        COALESCE(SUM(v.stock_available), 0) AS total_stock,
        MIN(v.price) AS min_price,
        MAX(v.price) AS max_price,
        (
            SELECT pi.image_url
            FROM product_images pi
            WHERE pi.product_id = p.product_id
            ORDER BY {$imageOrderBy}
            LIMIT 1
        ) AS main_image
    FROM products p
    LEFT JOIN product_variants v ON v.product_id = p.product_id
    {$whereClause}
    GROUP BY p.product_id
    ORDER BY {$productOrderBy}
    LIMIT {$offset}, {$perPage}
";
$productResult = $conn->query($productSql);
$productQueryError = $productResult ? '' : $conn->error;

function buildFilterQuery(array $overrides = []) {
    $base = [
        'page' => 'products',
        'keyword' => isset($_GET['keyword']) ? $_GET['keyword'] : '',
        'category_filter' => isset($_GET['category_filter']) ? $_GET['category_filter'] : '',
        'status_filter' => isset($_GET['status_filter']) ? $_GET['status_filter'] : '',
        'featured_filter' => isset($_GET['featured_filter']) ? $_GET['featured_filter'] : '',
        'p' => isset($_GET['p']) ? $_GET['p'] : 1,
    ];
    $merged = array_merge($base, $overrides);
    return 'backend.php?' . http_build_query($merged);
}
?>

<style>
    /* 全局與佈局 */
    .pm-wrap { display: grid; gap: 20px; color: #333; }
    .pm-head { display: flex; justify-content: space-between; align-items: end; gap: 12px; flex-wrap: wrap; }
    .pm-title { margin: 0; font-size: 24px; font-weight: bold; color: #1a1a1a; }
    .pm-sub { margin: 6px 0 0; color: #666; font-size: 14px; }

    /* 卡片與格線 */
    .pm-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.02); }
    .pm-grid { display: grid; grid-template-columns: repeat(12, 1fr); gap: 12px; align-items: end; }
    .pm-col-3 { grid-column: span 3; }
    .pm-col-2 { grid-column: span 2; }
    .pm-col-12 { grid-column: span 12; }

    /* 表單元件 */
    .pm-input, .pm-select, .pm-textarea {
        width: 100%;
        padding: 8px 12px;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        box-sizing: border-box;
        font-size: 14px;
        transition: border-color 0.2s;
    }
    .pm-input:focus, .pm-select:focus, .pm-textarea:focus { border-color: #3b82f6; outline: none; }
    .pm-textarea { min-height: 100px; resize: vertical; }
    label { font-size: 13px; font-weight: 600; color: #475569; margin-bottom: 6px; display: block; }

    /* 按鈕設計 */
    .pm-btn { border: none; border-radius: 6px; padding: 8px 16px; cursor: pointer; font-size: 14px; font-weight: 500; transition: all 0.2s; text-decoration: none; display: inline-block; text-align: center; }
    .pm-btn-main { background: #0f172a; color: #fff; }
    .pm-btn-main:hover { background: #334155; }
    .pm-btn-sub { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
    .pm-btn-sub:hover { background: #e2e8f0; color: #0f172a; }
    .pm-btn-danger { background: #fee2e2; color: #ef4444; border: 1px solid #fecaca; }
    .pm-btn-danger:hover { background: #fecaca; color: #b91c1c; }
    .pm-btn-edit { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
    .pm-btn-edit:hover { background: #dbeafe; color: #1d4ed8; }
    .pm-btn-sm { padding: 4px 10px; font-size: 12px; }

    /* 表格設計 (優化壓縮) */
    .pm-table-wrap { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 8px; margin-top: 8px; }
    .pm-table { width: 100%; border-collapse: collapse; min-width: 980px; font-size: 14px; }
    .pm-table th, .pm-table td { border-bottom: 1px solid #e2e8f0; padding: 12px 10px; text-align: left; vertical-align: middle; }
    .pm-table th { background: #f8fafc; font-weight: 600; color: #475569; white-space: nowrap; }
    .pm-table tbody tr { transition: background-color 0.2s; }
    .pm-table tbody tr:hover { background-color: #f8fafc; }
    .pm-link { color: #2563eb; text-decoration: none; font-weight: 600; }
    .pm-link:hover { text-decoration: underline; color: #1d4ed8; }

    /* 圖片與標籤 */
    .pm-thumb { width: 44px; height: 44px; border-radius: 6px; object-fit: cover; background: #f1f5f9; display: block; border: 1px solid #e2e8f0; }
    .pm-badge { font-size: 12px; border-radius: 99px; padding: 2px 8px; display: inline-block; font-weight: 500; }
    .pm-on { background: #dcfce7; color: #166534; }
    .pm-off { background: #f1f5f9; color: #64748b; }
    .pm-featured { background: #fef3c7; color: #b45309; }

    /* 其他 */
    .pm-actions { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
    .pm-pagination { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 16px; }
    .pm-page-link { text-decoration: none; border: 1px solid #e2e8f0; padding: 6px 12px; border-radius: 6px; color: #475569; font-size: 14px; transition: 0.2s; }
    .pm-page-link:hover { background: #f1f5f9; }
    .pm-page-current { background: #0f172a; color: #fff; border-color: #0f172a; }
    .pm-page-current:hover { background: #0f172a; }

    /* 頁籤 */
    .pm-tabs { display: flex; gap: 8px; }
    .pm-tab { padding: 8px 16px; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc; color: #475569; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; font-weight: 600; font-size: 14px; transition: all 0.2s; }
    .pm-tab:hover { background: #f1f5f9; }
    .pm-tab.active { background: #0f172a; color: #fff; border-color: #0f172a; }

    /* 新增商品區塊優化 */
    .pm-section-box { border: 1px solid #e2e8f0; background: #f8fafc; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
    .pm-section-title { font-size: 16px; font-weight: 600; color: #1e293b; margin: 0 0 12px 0; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; }
    .pm-sku-row { border: 1px solid #cbd5e1; border-radius: 8px; padding: 12px; margin-bottom: 10px; background: #fff; }

    /* 檔案上傳區 */
    .pm-file-input {
        display: block;
        width: 100%;
        min-height: 100px;
        padding: 16px;
        border: 2px dashed #cbd5e1;
        border-radius: 8px;
        background: #f8fafc;
        box-sizing: border-box;
        cursor: pointer;
        transition: all 0.2s;
        font-size: 14px;
        color: #475569;
    }
    .pm-file-input:hover { border-color: #3b82f6; background: #eff6ff; }
    .pm-file-input:focus { outline: none; border-color: #3b82f6; background: #eff6ff; }

    #tab-list, #tab-create { min-height: 560px; }

    @media (max-width: 960px) {
        .pm-col-3, .pm-col-2 { grid-column: span 12; }
    }
</style>

<div class="pm-wrap">
    <div class="pm-head">
        <div>
            <h1 class="pm-title">商品管理</h1>
            <p class="pm-sub">支援搜尋篩選、批次上下架、快速編輯與多圖上傳。</p>
        </div>
        <div class="pm-tabs">
            <button type="button" class="pm-tab active" data-tab="list">商品列表</button>
            <button type="button" class="pm-tab" data-tab="create">+ 新增商品</button>
        </div>
    </div>

    <!-- 商品列表 Tab -->
    <section class="pm-card" id="tab-list">
        <?php if ($productQueryError !== ''): ?>
            <div style="background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; padding:12px; border-radius:8px; margin-bottom:16px;">
                商品查詢失敗：<?php echo htmlspecialchars($productQueryError); ?>
            </div>
        <?php endif; ?>

        <!-- 篩選器 -->
        <form method="GET" action="backend.php" class="pm-grid" style="margin-bottom: 16px;">
            <input type="hidden" name="page" value="products">
            <div class="pm-col-3">
                <label>關鍵字搜尋</label>
                <input class="pm-input" name="keyword" value="<?php echo htmlspecialchars($keyword); ?>" placeholder="搜尋商品名稱...">
            </div>
            <div class="pm-col-3">
                <label>商品分類</label>
                <select class="pm-select" name="category_filter">
                    <option value="">全部分類</option>
                    <option value="none" <?php echo $categoryFilter === 'none' ? 'selected' : ''; ?>>不分類</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo intval($cat['category_id']); ?>" <?php echo $categoryFilter === (string)$cat['category_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="pm-col-2">
                <label>上架狀態</label>
                <select class="pm-select" name="status_filter">
                    <option value="">全部</option>
                    <option value="ON SHELF" <?php echo $statusFilter === 'ON SHELF' ? 'selected' : ''; ?>>上架中</option>
                    <option value="OFF SHELF" <?php echo $statusFilter === 'OFF SHELF' ? 'selected' : ''; ?>>已下架</option>
                </select>
            </div>
            <div class="pm-col-2">
                <label>首頁精選</label>
                <select class="pm-select" name="featured_filter">
                    <option value="">全部</option>
                    <option value="1" <?php echo $featuredFilter === '1' ? 'selected' : ''; ?>>精選商品</option>
                    <option value="0" <?php echo $featuredFilter === '0' ? 'selected' : ''; ?>>一般商品</option>
                </select>
            </div>
            <div class="pm-col-2" style="display:flex; gap:8px;">
                <button class="pm-btn pm-btn-main" type="submit">篩選</button>
                <a class="pm-btn pm-btn-sub" href="backend.php?page=products">重置</a>
            </div>
        </form>

        <!-- 列表與批次操作 -->
        <form method="POST" action="backend_action.php" id="bulkForm">
            <input type="hidden" name="action" value="bulk_update_products">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; margin-bottom:12px;">
                <div style="display:flex; gap:8px; align-items:center;">
                    <select class="pm-select" name="bulk_action" style="max-width:180px; padding:6px 10px;">
                        <option value="">選擇批次操作...</option>
                        <option value="set_on">批次上架</option>
                        <option value="set_off">批次下架</option>
                        <option value="set_featured">批次設為精選</option>
                        <option value="unset_featured">批次取消精選</option>
                    </select>
                    <button type="submit" class="pm-btn pm-btn-sub pm-btn-sm">套用</button>
                </div>
                <span style="font-size: 13px; color: #64748b;">共找到 <?php echo $totalProducts; ?> 項商品</span>
            </div>

            <div class="pm-table-wrap">
                <table class="pm-table">
                    <thead>
                        <tr>
                            <th style="width: 40px;"><input type="checkbox" id="checkAll"></th>
                            <th style="width: 60px;">圖片</th>
                            <th>商品名稱</th>
                            <th>分類</th>
                            <th>SKU</th>
                            <th>價格區間</th>
                            <th>總庫存</th>
                            <th>狀態</th>
                            <th>精選</th>
                            <th style="min-width: 180px;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($productResult && $productResult->num_rows > 0): ?>
                            <?php while ($row = $productResult->fetch_assoc()): ?>
                                <tr>
                                    <td><input type="checkbox" name="product_ids[]" value="<?php echo intval($row['product_id']); ?>" class="rowCheck"></td>
                                    <td>
                                        <?php if (!empty($row['main_image'])): ?>
                                            <img class="pm-thumb" src="../<?php echo htmlspecialchars($row['main_image']); ?>" alt="thumb">
                                        <?php else: ?>
                                            <span class="pm-thumb"></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <!-- 點擊商品名稱進入編輯 -->
                                        <a href="backend.php?page=edit_product&id=<?php echo intval($row['product_id']); ?>" class="pm-link">
                                            <?php echo htmlspecialchars($row['name']); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <?php
                                        if ($row['primary_category_id'] === null) {
                                            echo '<span style="color:#94a3b8;">不分類</span>';
                                        } else {
                                            $matched = '未命名分類';
                                            foreach ($categories as $cat) {
                                                if ((int)$cat['category_id'] === (int)$row['primary_category_id']) {
                                                    $matched = $cat['name'];
                                                    break;
                                                }
                                            }
                                            echo htmlspecialchars($matched);
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo intval($row['sku_count']); ?></td>
                                    <td>
                                        <?php if ($row['min_price'] === null): ?>
                                            -
                                        <?php elseif ((float)$row['min_price'] === (float)$row['max_price']): ?>
                                            $<?php echo number_format((float)$row['min_price']); ?>
                                        <?php else: ?>
                                            $<?php echo number_format((float)$row['min_price']); ?> ~ <?php echo number_format((float)$row['max_price']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span style="<?php echo (int)$row['total_stock'] === 0 ? 'color:#ef4444; font-weight:bold;' : ''; ?>">
                                            <?php echo number_format((int)$row['total_stock']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="pm-badge <?php echo $row['status'] === 'ON SHELF' ? 'pm-on' : 'pm-off'; ?>">
                                            <?php echo $row['status'] === 'ON SHELF' ? '上架中' : '已下架'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ((int)$row['is_featured'] === 1): ?>
                                            <span class="pm-badge pm-featured">精選</span>
                                        <?php else: ?>
                                            <span style="color:#cbd5e1;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="pm-actions">
                                            <!-- 編輯按鈕 -->
                                            <a href="backend.php?page=edit_product&id=<?php echo intval($row['product_id']); ?>" class="pm-btn pm-btn-edit pm-btn-sm">編輯</a>
                                            
                                            <!-- 狀態切換 -->
                                            <form method="POST" action="backend_action.php" style="display:inline;">
                                                <input type="hidden" name="action" value="toggle_product_status">
                                                <input type="hidden" name="product_id" value="<?php echo intval($row['product_id']); ?>">
                                                <input type="hidden" name="new_status" value="<?php echo $row['status'] === 'ON SHELF' ? 'OFF SHELF' : 'ON SHELF'; ?>">
                                                <button class="pm-btn pm-btn-sub pm-btn-sm" type="submit"><?php echo $row['status'] === 'ON SHELF' ? '設為下架' : '設為上架'; ?></button>
                                            </form>
                                            
                                            <!-- 刪除 -->
                                            <form method="POST" action="backend_action.php" style="display:inline;" onsubmit="return confirm('確定要刪除此商品嗎？此動作無法復原。');">
                                                <input type="hidden" name="action" value="delete_product">
                                                <input type="hidden" name="product_id" value="<?php echo intval($row['product_id']); ?>">
                                                <button class="pm-btn pm-btn-danger pm-btn-sm" type="submit">刪除</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="10" style="text-align:center; padding: 40px; color:#94a3b8;">目前沒有符合條件的商品</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>

        <?php if ($totalPages > 1): ?>
            <div class="pm-pagination">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <?php if ($i === $currentPage): ?>
                        <span class="pm-page-link pm-page-current"><?php echo $i; ?></span>
                    <?php else: ?>
                        <a class="pm-page-link" href="<?php echo htmlspecialchars(buildFilterQuery(['p' => $i])); ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- 新增商品 Tab -->
    <section class="pm-card" id="tab-create" style="display:none;">
        <form action="backend_action.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_product">

            <!-- 區塊 1：基本資訊 -->
            <div class="pm-section-box">
                <h3 class="pm-section-title">商品基本資訊</h3>
                <div class="pm-grid">
                    <div class="pm-col-3">
                        <label>商品名稱 <span style="color:#ef4444;">*</span></label>
                        <input class="pm-input" type="text" name="name" required placeholder="請輸入商品名稱">
                    </div>
                    <div class="pm-col-3">
                        <label>分類</label>
                        <select class="pm-select" name="category_id">
                            <option value="">不分類</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo intval($cat['category_id']); ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="pm-col-3" style="display:flex; align-items:center; padding-bottom:8px;">
                        <label style="margin:0; cursor:pointer; display:flex; align-items:center; gap:6px;">
                            <input type="checkbox" name="is_featured" value="1" style="width:16px; height:16px;">
                            設為首頁精選
                        </label>
                    </div>
                    <div class="pm-col-12">
                        <label>商品描述</label>
                        <textarea class="pm-textarea" name="description" placeholder="可填寫材質、特色、使用情境..."></textarea>
                    </div>
                    <div class="pm-col-12">
                        <label>保固與附加資訊</label>
                        <textarea class="pm-textarea" name="warranty_info" placeholder="例如：三年保固，破箱保修..." style="min-height: 60px;"></textarea>
                    </div>
                </div>
            </div>

            <!-- 區塊 2：SKU 管理 -->
            <div class="pm-section-box">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                    <h3 class="pm-section-title" style="border:none; padding:0; margin:0;">SKU 規格與價格配置</h3>
                    <button type="button" class="pm-btn pm-btn-sub pm-btn-sm" id="addSkuBtn">+ 新增一組規格</button>
                </div>
                
                <div id="skuRows">
                    <div class="pm-sku-row">
                        <div class="pm-grid">
                            <div class="pm-col-3">
                                <label>尺寸</label>
                                <select class="pm-select" name="size[]" required>
                                    <option value="">請選擇尺寸</option>
                                    <option value="20吋">20吋</option>
                                    <option value="24吋">24吋</option>
                                    <option value="28吋">28吋</option>
                                </select>
                            </div>
                            <div class="pm-col-3">
                                <label>顏色</label>
                                <input class="pm-input" type="text" name="color[]" placeholder="例如：消光黑">
                            </div>
                            <div class="pm-col-3">
                                <label>價格 (NT$) <span style="color:#ef4444;">*</span></label>
                                <input class="pm-input" type="number" name="price[]" min="0" step="1" required placeholder="0">
                            </div>
                            <div class="pm-col-3">
                                <label>庫存數量 <span style="color:#ef4444;">*</span></label>
                                <input class="pm-input" type="number" name="stock[]" min="0" step="1" required placeholder="0">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 區塊 3：圖片上傳 -->
            <div class="pm-section-box">
                <h3 class="pm-section-title">商品圖片</h3>
                <div class="pm-grid">
                    <div class="pm-col-12">
                        <label>上傳圖片（支援多選，第一張預設為主圖） <span style="color:#ef4444;">*</span></label>
                        <input class="pm-file-input" type="file" name="product_images[]" accept="image/*" multiple required>
                    </div>
                </div>
            </div>

            <div style="text-align: right; margin-top: 24px;">
                <button class="pm-btn pm-btn-main" type="submit" style="padding: 10px 32px; font-size: 16px;">確認建立商品</button>
            </div>
        </form>
    </section>
</div>

<script>
    (function () {
        // Tab 切換邏輯
        const tabs = document.querySelectorAll('.pm-tab');
        const tabList = document.getElementById('tab-list');
        const tabCreate = document.getElementById('tab-create');

        tabs.forEach(function (btn) {
            btn.addEventListener('click', function () {
                tabs.forEach(function (item) { item.classList.remove('active'); });
                btn.classList.add('active');
                if (btn.dataset.tab === 'create') {
                    tabList.style.display = 'none';
                    tabCreate.style.display = 'block';
                } else {
                    tabCreate.style.display = 'none';
                    tabList.style.display = 'block';
                }
            });
        });

        // 全選邏輯
        const checkAll = document.getElementById('checkAll');
        const rowChecks = document.querySelectorAll('.rowCheck');
        if (checkAll) {
            checkAll.addEventListener('change', function () {
                rowChecks.forEach(function (chk) { chk.checked = checkAll.checked; });
            });
        }

        // 動態新增 SKU 邏輯
        const addSkuBtn = document.getElementById('addSkuBtn');
        const skuRows = document.getElementById('skuRows');
        if (addSkuBtn && skuRows) {
            addSkuBtn.addEventListener('click', function () {
                const row = document.createElement('div');
                row.className = 'pm-sku-row';
                row.innerHTML =
                    '<div class="pm-grid">' +
                        '<div class="pm-col-3"><label>尺寸</label><select class="pm-select" name="size[]" required><option value="">請選擇尺寸</option><option value="20吋">20吋</option><option value="24吋">24吋</option><option value="28吋">28吋</option></select></div>' +
                        '<div class="pm-col-3"><label>顏色</label><input class="pm-input" type="text" name="color[]" placeholder="例如：消光黑"></div>' +
                        '<div class="pm-col-3"><label>價格 (NT$)</label><input class="pm-input" type="number" name="price[]" min="0" step="1" required placeholder="0"></div>' +
                        '<div class="pm-col-3"><label>庫存數量</label><input class="pm-input" type="number" name="stock[]" min="0" step="1" required placeholder="0"></div>' +
                    '</div>' +
                    '<div style="text-align:right; margin-top:10px;">' +
                        '<button type="button" class="pm-btn pm-btn-danger pm-btn-sm remove-sku">移除此規格</button>' +
                    '</div>';
                skuRows.appendChild(row);
            });

            skuRows.addEventListener('click', function (event) {
                if (!event.target.classList.contains('remove-sku')) {
                    return;
                }
                if (skuRows.querySelectorAll('.pm-sku-row').length <= 1) {
                    alert('至少必須保留一組 SKU 規格');
                    return;
                }
                event.target.closest('.pm-sku-row').remove();
            });
        }
    })();
</script>
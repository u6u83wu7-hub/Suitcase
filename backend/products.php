<?php
<<<<<<< HEAD
require_once __DIR__ . '/auth_guard.php';
// products.php - 商品管理主頁面（容器）
// $conn 由 backend.php 提供

=======
// products.php - 商品管理主頁面（容器）
// $conn 由 backend.php 提供                            

//告訴它資料表的名字（例如 products）
//它就會去資料庫把這個表裡面「有哪些欄位」查出來。
>>>>>>> e02cc20e6ff9c74d937a44a4212d3aadc43df848
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

// ===== 數據初始化 =====

<<<<<<< HEAD
// 分頁設定
=======
// 分頁設定，一頁只顯示 10 筆商品
>>>>>>> e02cc20e6ff9c74d937a44a4212d3aadc43df848
$perPage = 10;
$currentPage = isset($_GET['p']) ? max(1, intval($_GET['p'])) : 1;
$offset = ($currentPage - 1) * $perPage;

// 取得篩選條件
$keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
$categoryFilter = isset($_GET['category_filter']) ? trim($_GET['category_filter']) : '';
$statusFilter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : '';
$featuredFilter = isset($_GET['featured_filter']) ? trim($_GET['featured_filter']) : '';

// 取得分類列表
$categories = [];
$categorySql = "SELECT category_id, name FROM categories ORDER BY name ASC";
$categoryResult = $conn->query($categorySql);
if ($categoryResult) {
    while ($cat = $categoryResult->fetch_assoc()) {
        $categories[] = $cat;
    }
}

<<<<<<< HEAD
// 構建 WHERE 條件
=======
// 翻譯篩選條件，構建 WHERE 條件
>>>>>>> e02cc20e6ff9c74d937a44a4212d3aadc43df848
$conditions = [];
if ($keyword !== '') {
    $safeKeyword = $conn->real_escape_string($keyword);
    $conditions[] = "p.name LIKE '%{$safeKeyword}%'";
}
if ($categoryFilter !== '') {
    if ($categoryFilter === 'none') {
<<<<<<< HEAD
        $conditions[] = "NOT EXISTS (SELECT 1 FROM product_category_links pcl WHERE pcl.product_id = p.product_id)";
    } else {
        $conditions[] = "EXISTS (SELECT 1 FROM product_category_links pcl WHERE pcl.product_id = p.product_id AND pcl.category_id = " . intval($categoryFilter) . ")";
=======
        $conditions[] = "p.primary_category_id IS NULL";
    } else {
        $conditions[] = "p.primary_category_id = " . intval($categoryFilter);
>>>>>>> e02cc20e6ff9c74d937a44a4212d3aadc43df848
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

// 取得表字段
$productCols = pmTableColumns($conn, 'products');
$imageCols = pmTableColumns($conn, 'product_images');

// 排序設定
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

// 計算總數和分頁
$countSql = "SELECT COUNT(*) AS total FROM products p {$whereClause}";
$countResult = $conn->query($countSql);
$totalProducts = ($countResult && $countResult->num_rows > 0) ? intval($countResult->fetch_assoc()['total']) : 0;
$totalPages = max(1, ceil($totalProducts / $perPage));

<<<<<<< HEAD
// 查詢商品列表
=======
// 查詢商品列表、合併規格資料、找封面圖、套用條件與分頁
>>>>>>> e02cc20e6ff9c74d937a44a4212d3aadc43df848
$productSql = "
    SELECT
        p.product_id,
        p.name,
<<<<<<< HEAD
=======
        p.primary_category_id,
>>>>>>> e02cc20e6ff9c74d937a44a4212d3aadc43df848
        p.status,
        p.is_featured,
        COUNT(v.product_id) AS sku_count,
        COALESCE(SUM(v.stock_available), 0) AS total_stock,
<<<<<<< HEAD
        MIN(COALESCE(v.special_price, v.original_price)) AS min_price,
        MAX(COALESCE(v.special_price, v.original_price)) AS max_price,
        GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR ', ') AS category_names,
=======
        MIN(v.price) AS min_price,
        MAX(v.price) AS max_price,
>>>>>>> e02cc20e6ff9c74d937a44a4212d3aadc43df848
        (
            SELECT pi.image_url
            FROM product_images pi
            WHERE pi.product_id = p.product_id
            ORDER BY {$imageOrderBy}
            LIMIT 1
        ) AS main_image
    FROM products p
    LEFT JOIN product_variants v ON v.product_id = p.product_id
<<<<<<< HEAD
    LEFT JOIN product_category_links pcl ON pcl.product_id = p.product_id
    LEFT JOIN categories c ON c.category_id = pcl.category_id
=======
>>>>>>> e02cc20e6ff9c74d937a44a4212d3aadc43df848
    {$whereClause}
    GROUP BY p.product_id
    ORDER BY {$productOrderBy}
    LIMIT {$offset}, {$perPage}
";
$productResult = $conn->query($productSql);
$productQueryError = $productResult ? '' : $conn->error;

<<<<<<< HEAD
=======
//處理網址(記住你現在的搜尋條件，然後替換掉頁碼。)
>>>>>>> e02cc20e6ff9c74d937a44a4212d3aadc43df848
function buildFilterQuery(array $overrides = []) {
    $base = [
        'page' => 'products',
        'keyword' => isset($_GET['keyword']) ? $_GET['keyword'] : '',
        'category_filter' => isset($_GET['category_filter']) ? $_GET['category_filter'] : '',
        'status_filter' => isset($_GET['status_filter']) ? $_GET['status_filter'] : '',
        'featured_filter' => isset($_GET['featured_filter']) ? $_GET['featured_filter'] : '',
        'p' => isset($_GET['p']) ? $_GET['p'] : 1,
    ];
<<<<<<< HEAD

    // 💡 新增：如果切換分頁或點擊篩選時，自動清除編輯模式的參數，防止卡在編輯頁面
    if (isset($_GET['action']) && $_GET['action'] === 'edit') {
        unset($base['action'], $base['id']);
    }

=======
>>>>>>> e02cc20e6ff9c74d937a44a4212d3aadc43df848
    $merged = array_merge($base, $overrides);
    return 'backend.php?' . http_build_query($merged);
}

<<<<<<< HEAD
// 💡 核心邏輯：判斷目前是不是編輯模式 (網址包含 action=edit 且有商品 ID)
$isEditMode = (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id']) && intval($_GET['id']) > 0);

=======
//畫出了後台的標題、切換「商品列表」與「新增商品」的兩個按鈕。
>>>>>>> e02cc20e6ff9c74d937a44a4212d3aadc43df848
?>
<link rel="stylesheet" href="../css/products.css">

<div class="pm-wrap">
    <div class="pm-head">
        <div>
            <h1 class="pm-title">商品管理</h1>
            <p class="pm-sub">支援搜尋篩選、批次上下架、快速編輯與多圖上傳。</p>
        </div>
        <div class="pm-tabs">
<<<<<<< HEAD
            <?php if ($isEditMode): ?>
                <a href="backend.php?page=products" class="pm-tab active" style="text-decoration: none;">⬅️ 返回商品列表</a>
            <?php else: ?>
                <button type="button" class="pm-tab active" data-tab="list">商品列表</button>
                <button type="button" class="pm-tab" data-tab="create">+ 新增商品</button>
            <?php endif; ?>
        </div>
    </div>

    <?php 
    // 💡 智慧切換頁面內容
    if ($isEditMode) {
        // 進入編輯模式：載入剛剛新增的 edit_product.php
        require __DIR__ . '/products/edit_product.php'; 
    } else {
        // 正常模式：載入原本的列表與新增區塊
        require __DIR__ . '/products/list.php'; 
        require __DIR__ . '/products/create.php'; 
    }
    ?>
</div>

<script src="../js/products.js?v=<?php echo time(); ?>"></script>
=======
            <button type="button" class="pm-tab active" data-tab="list">商品列表</button>
            <button type="button" class="pm-tab" data-tab="create">+ 新增商品</button>
        </div>
    </div>

    <!-- 引入商品列表 -->
    <?php require __DIR__ . '/products/list.php'; ?>

    <!-- 引入新增商品 -->
    <?php require __DIR__ . '/products/create.php'; ?>
</div>

<script src="../js/products.js"></script>
>>>>>>> e02cc20e6ff9c74d937a44a4212d3aadc43df848

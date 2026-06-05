<?php
require_once __DIR__ . '/auth_guard.php';
// products.php - 商品管理主頁面（容器）

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
$supplierFilter = isset($_GET['supplier_filter']) ? trim($_GET['supplier_filter']) : '';
$statusFilter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : '';
$featuredFilter = isset($_GET['featured_filter']) ? trim($_GET['featured_filter']) : '';
$stockFilter = isset($_GET['stock_filter']) ? trim($_GET['stock_filter']) : '';
$lowStockThreshold = 5;

$productCols = pmTableColumns($conn, 'products');
$imageCols = pmTableColumns($conn, 'product_images');

$categories = [];
$categoryResult = $conn->query("SELECT category_id, name FROM categories ORDER BY name ASC");
if ($categoryResult) {
    while ($cat = $categoryResult->fetch_assoc()) {
        $categories[] = $cat;
    }
}

$suppliers = [];
$supplierResult = $conn->query("SELECT supplier_id, name FROM suppliers ORDER BY name ASC");
if ($supplierResult) {
    while ($supplier = $supplierResult->fetch_assoc()) {
        $suppliers[] = $supplier;
    }
}

$isVendorAccount = isset($admin_role_id) && intval($admin_role_id) === 3;
$vendorSupplierId = $isVendorAccount ? intval($current_supplier_id ?? 0) : 0;

$conditions = [];
if ($keyword !== '') {
    $safeKeyword = $conn->real_escape_string($keyword);
    $conditions[] = "p.name LIKE '%{$safeKeyword}%'";
}
if ($categoryFilter !== '') {
    if ($categoryFilter === 'none') {
        $conditions[] = "NOT EXISTS (SELECT 1 FROM product_category_links pcl WHERE pcl.product_id = p.product_id)";
    } else {
        $conditions[] = "EXISTS (SELECT 1 FROM product_category_links pcl WHERE pcl.product_id = p.product_id AND pcl.category_id = " . intval($categoryFilter) . ")";
    }
}
if ($supplierFilter !== '' && in_array('supplier_id', $productCols, true)) {
    if ($supplierFilter === 'none') {
        $conditions[] = 'p.supplier_id IS NULL';
    } else {
        $conditions[] = 'p.supplier_id = ' . intval($supplierFilter);
    }
}
if ($isVendorAccount && $vendorSupplierId > 0 && in_array('supplier_id', $productCols, true)) {
    $conditions[] = 'p.supplier_id = ' . $vendorSupplierId;
}
if ($statusFilter !== '') {
    $safeStatus = $conn->real_escape_string($statusFilter);
    $conditions[] = "p.status = '{$safeStatus}'";
}
if ($featuredFilter !== '') {
    $conditions[] = "p.is_featured = " . intval($featuredFilter);
}
if ($stockFilter === 'low') {
    $conditions[] = "EXISTS (
        SELECT 1
        FROM product_variants pv_stock
        WHERE pv_stock.product_id = p.product_id
          AND pv_stock.stock_available > 0
          AND pv_stock.stock_available <= {$lowStockThreshold}
    )";
} elseif ($stockFilter === 'out') {
    $conditions[] = "NOT EXISTS (
        SELECT 1
        FROM product_variants pv_stock
        WHERE pv_stock.product_id = p.product_id
          AND pv_stock.stock_available > 0
    )";
}

$whereClause = empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions);

$productOrderBy = in_array('created_at', $productCols, true) ? 'p.created_at DESC' : 'p.product_id DESC';

$imageOrderParts = [];
if (in_array('is_main', $imageCols, true)) {
    $imageOrderParts[] = 'pi.is_main DESC';
}
if (in_array('sort_order', $imageCols, true)) {
    $imageOrderParts[] = 'pi.sort_order ASC';
} elseif (in_array('display_order', $imageCols, true)) {
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
        MAX(s.name) AS supplier_name,
        p.status,
        p.is_featured,
        COUNT(v.variant_id) AS sku_count,
        COALESCE(SUM(v.stock_available), 0) AS total_stock,
        COALESCE(SUM(CASE WHEN v.stock_available = 0 THEN 1 ELSE 0 END), 0) AS out_sku_count,
        COALESCE(SUM(CASE WHEN v.stock_available > 0 AND v.stock_available <= {$lowStockThreshold} THEN 1 ELSE 0 END), 0) AS low_sku_count,
        COALESCE(MIN(v.stock_available), 0) AS min_stock,
        MIN(COALESCE(v.special_price, v.original_price)) AS min_price,
        MAX(COALESCE(v.special_price, v.original_price)) AS max_price,
        GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR ', ') AS category_names,
        (
            SELECT pi.image_url
            FROM product_images pi
            WHERE pi.product_id = p.product_id
            ORDER BY {$imageOrderBy}
            LIMIT 1
        ) AS main_image
    FROM products p
    LEFT JOIN suppliers s ON s.supplier_id = p.supplier_id
    LEFT JOIN product_variants v ON v.product_id = p.product_id
    LEFT JOIN product_category_links pcl ON pcl.product_id = p.product_id
    LEFT JOIN categories c ON c.category_id = pcl.category_id
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
        'keyword' => $_GET['keyword'] ?? '',
        'category_filter' => $_GET['category_filter'] ?? '',
        'supplier_filter' => $_GET['supplier_filter'] ?? '',
        'status_filter' => $_GET['status_filter'] ?? '',
        'featured_filter' => $_GET['featured_filter'] ?? '',
        'stock_filter' => $_GET['stock_filter'] ?? '',
        'p' => $_GET['p'] ?? 1,
    ];

    if (isset($_GET['action']) && $_GET['action'] === 'edit') {
        unset($base['action'], $base['id']);
    }

    return 'backend.php?' . http_build_query(array_merge($base, $overrides));
}

$isEditMode = (!$isVendorAccount && isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id']) && intval($_GET['id']) > 0);
?>
<link rel="stylesheet" href="../css/products.css">

<div class="pm-wrap">
    <div class="pm-head">
        <div>
            <h1 class="pm-title"><?php echo $isVendorAccount ? '商品瀏覽' : '商品管理'; ?></h1>
            <p class="pm-sub"></p>
        </div>
        <div class="pm-tabs">
            <?php if ($isEditMode): ?>
                <a href="backend.php?page=products" class="pm-tab active" style="text-decoration:none;">⬅️ 返回商品列表</a>
            <?php elseif ($isVendorAccount): ?>
                <button type="button" class="pm-tab active" data-tab="list">商品列表</button>
            <?php else: ?>
                <button type="button" class="pm-tab active" data-tab="list">商品列表</button>
                <button type="button" class="pm-tab" data-tab="create">+ 新增商品</button>
            <?php endif; ?>
        </div>
    </div>

    <?php
    if ($isEditMode) {
        require __DIR__ . '/products/edit_product.php';
    } else {
        require __DIR__ . '/products/list.php';
        if (!$isVendorAccount) {
            require __DIR__ . '/products/create.php';
        }
    }
    ?>
</div>

<script src="../js/products.js?v=<?php echo time(); ?>"></script>

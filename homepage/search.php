<?php
$pageTitle = '商品搜尋 | All Pass';
$activeNav = '';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Taipei');
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/storefront_helpers.php';
require_once __DIR__ . '/includes/promotion_price_sync.php';
require_once __DIR__ . '/includes/price_helper.php';

apConfigureErrorHandling();

$conn = new mysqli('localhost', 'root', '', 'all_pass_db');
if ($conn->connect_error) {
    die('資料庫連線失敗: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');
apSetDbTimeZone($conn);
apRunPromotionSync($conn);

function searchH($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function searchTableExists($conn, $tableName) {
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $res && $res->num_rows > 0;
}

$q = trim((string)($_GET['q'] ?? ''));
$categoryId = (int)($_GET['category_id'] ?? 0);
$minPrice = trim((string)($_GET['min_price'] ?? ''));
$maxPrice = trim((string)($_GET['max_price'] ?? ''));
$inStockOnly = isset($_GET['in_stock']) && (string)$_GET['in_stock'] === '1';
$sort = (string)($_GET['sort'] ?? 'newest');

$currentLevel = !empty($_SESSION['user_id']) ? apFetchUserMembershipLevel($conn, (int)$_SESSION['user_id']) : null;
$isMemberPriceEligible = apIsMemberPriceEligible($currentLevel);
$priceSql = apVariantPriceSql('pv', $isMemberPriceEligible);

$categories = sfFetchCategories($conn);
$products = [];

if (searchTableExists($conn, 'products') && searchTableExists($conn, 'product_variants')) {
    $conditions = ["p.status = 'ON SHELF'"];
    $types = '';
    $params = [];

    if ($q !== '') {
        $conditions[] = '(p.name LIKE ? OR p.description LIKE ? OR EXISTS (SELECT 1 FROM product_variants pvq WHERE pvq.product_id = p.product_id AND pvq.sku_code LIKE ?))';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= 'sss';
    }

    if ($categoryId > 0 && searchTableExists($conn, 'product_category_links')) {
        $conditions[] = 'EXISTS (SELECT 1 FROM product_category_links pcl WHERE pcl.product_id = p.product_id AND pcl.category_id = ?)';
        $params[] = $categoryId;
        $types .= 'i';
    }

    if ($inStockOnly) {
        $conditions[] = 'EXISTS (SELECT 1 FROM product_variants pvs WHERE pvs.product_id = p.product_id AND pvs.stock_available > 0)';
    }

    $minPriceValue = $minPrice !== '' ? max(0, (float)$minPrice) : null;
    $maxPriceValue = $maxPrice !== '' ? max(0, (float)$maxPrice) : null;
    if ($minPriceValue !== null) {
        $conditions[] = "EXISTS (SELECT 1 FROM product_variants pvp WHERE pvp.product_id = p.product_id AND " . apVariantPriceSql('pvp', $isMemberPriceEligible) . " >= ?)";
        $params[] = $minPriceValue;
        $types .= 'd';
    }
    if ($maxPriceValue !== null) {
        $conditions[] = "EXISTS (SELECT 1 FROM product_variants pvp WHERE pvp.product_id = p.product_id AND " . apVariantPriceSql('pvp', $isMemberPriceEligible) . " <= ?)";
        $params[] = $maxPriceValue;
        $types .= 'd';
    }

    $orderSql = 'p.product_id DESC';
    if ($sort === 'price_asc') {
        $orderSql = 'display_price ASC, p.product_id DESC';
    } elseif ($sort === 'price_desc') {
        $orderSql = 'display_price DESC, p.product_id DESC';
    } elseif ($sort === 'name_asc') {
        $orderSql = 'p.name ASC';
    }

    $whereSql = implode(' AND ', $conditions);
    $sql = "
        SELECT
            p.product_id,
            p.name,
            MIN({$priceSql}) AS display_price,
            COALESCE(SUM(pv.stock_available), 0) AS total_stock,
            COALESCE((
                SELECT pi.image_url
                FROM product_images pi
                WHERE pi.product_id = p.product_id
                ORDER BY pi.is_main DESC, pi.sort_order ASC, pi.image_id ASC
                LIMIT 1
            ), '') AS image_url
        FROM products p
        JOIN product_variants pv ON pv.product_id = p.product_id
        WHERE {$whereSql}
        GROUP BY p.product_id, p.name
        ORDER BY {$orderSql}
        LIMIT 80
    ";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if ($types !== '') {
            $bind = [$types];
            foreach ($params as $idx => $value) {
                $bind[] = &$params[$idx];
            }
            call_user_func_array([$stmt, 'bind_param'], $bind);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $products[] = $row;
        }
        $stmt->close();
    }
}

include 'header.php';
?>

<section class="page-hero">
    <h1>商品搜尋</h1>
    <p>依關鍵字、分類、價格與庫存快速找到適合的行李箱。</p>
</section>

<main class="section-container">
    <form method="GET" action="search.php" style="background:#fff; border:1px solid #eee; border-radius:12px; padding:18px; margin-bottom:28px; display:grid; grid-template-columns:2fr 1fr 1fr 1fr 1fr auto; gap:12px; align-items:end;">
        <div>
            <label style="display:block; font-size:13px; font-weight:700; margin-bottom:6px;">關鍵字</label>
            <input type="search" name="q" value="<?php echo searchH($q); ?>" placeholder="商品名稱、特色或 SKU" style="width:100%; height:42px; padding:0 12px; border:1px solid #ddd; border-radius:8px;">
        </div>
        <div>
            <label style="display:block; font-size:13px; font-weight:700; margin-bottom:6px;">分類</label>
            <select name="category_id" style="width:100%; height:42px; padding:0 12px; border:1px solid #ddd; border-radius:8px; background:#fff;">
                <option value="0">全部分類</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?php echo (int)$category['category_id']; ?>" <?php echo $categoryId === (int)$category['category_id'] ? 'selected' : ''; ?>><?php echo searchH($category['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="display:block; font-size:13px; font-weight:700; margin-bottom:6px;">最低價</label>
            <input type="number" name="min_price" min="0" value="<?php echo searchH($minPrice); ?>" style="width:100%; height:42px; padding:0 12px; border:1px solid #ddd; border-radius:8px;">
        </div>
        <div>
            <label style="display:block; font-size:13px; font-weight:700; margin-bottom:6px;">最高價</label>
            <input type="number" name="max_price" min="0" value="<?php echo searchH($maxPrice); ?>" style="width:100%; height:42px; padding:0 12px; border:1px solid #ddd; border-radius:8px;">
        </div>
        <div>
            <label style="display:block; font-size:13px; font-weight:700; margin-bottom:6px;">排序</label>
            <select name="sort" style="width:100%; height:42px; padding:0 12px; border:1px solid #ddd; border-radius:8px; background:#fff;">
                <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>最新</option>
                <option value="price_asc" <?php echo $sort === 'price_asc' ? 'selected' : ''; ?>>價格低到高</option>
                <option value="price_desc" <?php echo $sort === 'price_desc' ? 'selected' : ''; ?>>價格高到低</option>
                <option value="name_asc" <?php echo $sort === 'name_asc' ? 'selected' : ''; ?>>名稱 A-Z</option>
            </select>
        </div>
        <div style="display:flex; gap:10px; align-items:center;">
            <label style="display:flex; gap:6px; align-items:center; white-space:nowrap; font-size:13px;">
                <input type="checkbox" name="in_stock" value="1" <?php echo $inStockOnly ? 'checked' : ''; ?>> 有庫存
            </label>
            <button type="submit" style="height:42px; border:none; border-radius:999px; background:#db6b6b; color:#fff; font-weight:700; padding:0 18px;">搜尋</button>
        </div>
    </form>

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
        <h2 style="font-size:22px;">搜尋結果</h2>
        <span style="color:#777; font-size:14px;"><?php echo count($products); ?> 件商品</span>
    </div>

    <?php if (empty($products)): ?>
        <div style="background:#fff; border:1px solid #eee; border-radius:12px; padding:36px; text-align:center; color:#777;">找不到符合條件的商品。</div>
    <?php else: ?>
        <div class="product-grid">
            <?php foreach ($products as $product): ?>
                <?php $imageUrl = $product['image_url'] !== '' ? '../' . ltrim($product['image_url'], '/') : ''; ?>
                <a href="product_detail.php?id=<?php echo (int)$product['product_id']; ?>" class="product-card">
                    <div class="product-img-wrapper">
                        <?php if ($imageUrl !== ''): ?>
                            <img src="<?php echo searchH($imageUrl); ?>" alt="<?php echo searchH($product['name']); ?>" class="product-img">
                        <?php else: ?>
                            <div style="aspect-ratio:1/1; display:flex; align-items:center; justify-content:center; color:#999;">No Img</div>
                        <?php endif; ?>
                    </div>
                    <div class="product-info">
                        <h3 class="product-title"><?php echo searchH($product['name']); ?></h3>
                        <div class="product-price">NT$ <?php echo number_format((float)$product['display_price']); ?></div>
                        <div style="font-size:12px; color:<?php echo (int)$product['total_stock'] > 0 ? '#64748b' : '#b91c1c'; ?>; margin-top:6px;">
                            <?php echo (int)$product['total_stock'] > 0 ? '庫存 ' . (int)$product['total_stock'] : '售罄'; ?>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>

<?php include 'footer.php'; $conn->close(); ?>

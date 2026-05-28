<?php
// Temporary debug hardening: ensure fatal errors are captured even when host hides errors
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (defined('MYSQLI_REPORT_OFF') && function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

if (!function_exists('pdLog')) {
    function pdLog($message) {
        $logFile = __DIR__ . '/../backend/logs/product_detail_debug.log';
        @mkdir(dirname($logFile), 0777, true);
        @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n", FILE_APPEND);
    }
}

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        pdLog('FATAL: ' . $e['message'] . ' @ ' . $e['file'] . ':' . $e['line']);
        if (!headers_sent()) {
            http_response_code(500);
            echo '<h2 style="font-family:Arial,sans-serif;padding:20px;">商品頁發生錯誤</h2>';
            echo '<p style="font-family:Arial,sans-serif;padding:0 20px 20px;">錯誤已記錄，請查看 backend/logs/product_detail_debug.log</p>';
        }
    }
});

$pageTitle = '商品詳情 | All Pass';
$activeNav = '';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$cartNotice = '';
$cartNoticeType = 'success';
$currentUserMembershipLevel = 1;

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

$conn = new mysqli('localhost', 'root', '', 'all_pass_db');
if ($conn->connect_error) {
    die('資料庫連線失敗: ' . $conn->connect_error);
}

if (!empty($_SESSION['user_id'])) {
    $currentUserId = intval($_SESSION['user_id']);
    $membershipSql = 'SELECT membership_level FROM users WHERE user_id = ' . $currentUserId . ' LIMIT 1';
    $membershipRes = safeQuery($conn, $membershipSql, 'membershipLevel');
    if ($membershipRes && ($membershipRow = $membershipRes->fetch_assoc())) {
        $currentUserMembershipLevel = intval($membershipRow['membership_level'] ?? 1);
    }
}

function safeQuery($conn, $sql, $tag = '') {
    $res = $conn->query($sql);
    if ($res === false) {
        pdLog('SQL_FAIL ' . ($tag !== '' ? "[$tag] " : '') . $conn->error . ' | SQL=' . $sql);
    }
    return $res;
}

function tableExists($conn, $tableName) {
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    $res = safeQuery($conn, "SHOW TABLES LIKE '{$safe}'", 'tableExists');
    return ($res && $res->num_rows > 0);
}

function tableColumns($conn, $tableName) {
    $cols = [];
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    $res = safeQuery($conn, "SHOW COLUMNS FROM `{$safeTable}`", 'tableColumns');
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $cols[] = $row['Field'];
        }
    }
    return $cols;
}

function formatSizeLabel($size) {
    $value = trim((string)$size);
    if ($value === '') {
        return '';
    }

    if (preg_match('/\d+/', $value, $matches)) {
        $inches = intval($matches[0]);
        if ($inches <= 20) {
            return 'S';
        }
        if ($inches <= 23) {
            return 'M';
        }
        if ($inches <= 26) {
            return 'L';
        }
        return 'XL';
    }

    return strtoupper($value);
}

// 取得商品主檔
$productCols = tableColumns($conn, 'products');
$productSelect = [
    'product_id',
    'name',
    in_array('description', $productCols, true) ? 'description' : "'' AS description",
    in_array('warranty_months', $productCols, true) ? 'warranty_months' : 'NULL AS warranty_months',
    in_array('status', $productCols, true) ? 'status' : "'' AS status",
    in_array('is_featured', $productCols, true) ? 'is_featured' : '0 AS is_featured',
    in_array('created_at', $productCols, true) ? 'created_at' : 'NULL AS created_at',
];

$mainSql = 'SELECT ' . implode(', ', $productSelect) . ' FROM products WHERE product_id = ' . $id . ' LIMIT 1';
$prodRes = safeQuery($conn, $mainSql, 'mainProduct');
if (!$prodRes) {
    include 'header.php';
    echo '<div style="padding:120px 5%; text-align:center;"><h2>商品資料讀取失敗</h2><p>請稍後再試，或聯絡管理員。</p><p><a href="index.php">回首頁</a></p></div>';
    include 'footer.php';
    $conn->close();
    exit;
}

if (!$prodRes || $prodRes->num_rows === 0) {
    include 'header.php';
    echo '<div style="padding:120px 5%; text-align:center;"><h2>找不到此商品</h2><p>商品可能已下架或不存在。</p><p><a href="index.php">回首頁</a></p></div>';
    include 'footer.php';
    $conn->close();
    exit;
}
$product = $prodRes->fetch_assoc();

// 圖片
$imgs = [];
$imageCols = tableColumns($conn, 'product_images');
$imageOrder = [];
if (in_array('is_main', $imageCols, true)) {
    $imageOrder[] = 'is_main DESC';
}
if (in_array('sort_order', $imageCols, true)) {
    $imageOrder[] = 'sort_order ASC';
}
if (empty($imageOrder)) {
    $imageOrder[] = 'image_id ASC';
}
$imageSql = 'SELECT image_id, image_url, ' .
    (in_array('is_main', $imageCols, true) ? 'is_main' : '0 AS is_main') . ', ' .
    (in_array('sort_order', $imageCols, true) ? 'sort_order' : '0 AS sort_order') . ', ' .
    (in_array('color', $imageCols, true) ? 'color' : 'NULL AS color') . ', ' .
    (in_array('alt_text', $imageCols, true) ? 'alt_text' : 'NULL AS alt_text') .
    ' FROM product_images WHERE product_id = ' . $id . ' ORDER BY ' . implode(', ', $imageOrder);

$r = safeQuery($conn, $imageSql, 'images');
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $imgs[] = $row;
    }
}

$colorImageMap = [];
foreach ($imgs as $img) {
    $colorKey = isset($img['color']) ? trim((string)$img['color']) : '';
    if ($colorKey !== '' && !isset($colorImageMap[$colorKey])) {
        $colorImageMap[$colorKey] = $img;
    }
}

// 變體
$variants = [];
$variantCols = tableColumns($conn, 'product_variants');
$variantSql = 'SELECT variant_id, sku_code, ' .
    (in_array('color', $variantCols, true) ? 'color' : 'NULL AS color') . ', ' .
    (in_array('size_inches', $variantCols, true) ? 'size_inches' : 'NULL AS size_inches') . ', ' .
    (in_array('original_price', $variantCols, true) ? 'original_price' : '0 AS original_price') . ', ' .
    (in_array('special_price', $variantCols, true) ? 'special_price' : 'NULL AS special_price') . ', ' .
    (in_array('member_price', $variantCols, true) ? 'member_price' : '0 AS member_price') . ', ' .
    (in_array('stock_available', $variantCols, true) ? 'stock_available' : '0 AS stock_available') .
    ' FROM product_variants WHERE product_id = ' . $id;

$r = safeQuery($conn, $variantSql, 'variants');
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $variants[] = $row;
    }
}

$variantMap = [];
$defaultVariant = $variants[0] ?? null;
foreach ($variants as $v) {
    $variantId = intval($v['variant_id']);
    $variantColor = trim((string)($v['color'] ?? ''));
    $variantSize = trim((string)($v['size_inches'] ?? ''));
    $variantSizeLabel = formatSizeLabel($variantSize);
    $variantImage = null;
    if ($variantColor !== '' && isset($colorImageMap[$variantColor])) {
        $variantImage = $colorImageMap[$variantColor];
    } elseif (!empty($imgs)) {
        $variantImage = $imgs[0];
    }

    $variantMap[$variantId] = [
        'variant_id' => $variantId,
        'sku_code' => (string)($v['sku_code'] ?? ''),
        'size_inches' => $variantSize,
        'size_label' => $variantSizeLabel,
        'color' => $variantColor,
        'original_price' => isset($v['original_price']) ? floatval($v['original_price']) : 0,
        'special_price' => ($v['special_price'] !== null && $v['special_price'] !== '') ? floatval($v['special_price']) : null,
        'member_price' => isset($v['member_price']) ? floatval($v['member_price']) : null,
        'stock_available' => isset($v['stock_available']) ? intval($v['stock_available']) : 0,
        'image_url' => $variantImage ? ('../' . ltrim($variantImage['image_url'], '/')) : '',
        'alt_text' => $variantImage['alt_text'] ?? '',
    ];
}

$defaultVariantId = $defaultVariant ? intval($defaultVariant['variant_id']) : 0;
$defaultVariantData = ($defaultVariantId > 0 && isset($variantMap[$defaultVariantId])) ? $variantMap[$defaultVariantId] : null;
$defaultImageUrl = '';
if ($defaultVariantData && $defaultVariantData['image_url'] !== '') {
    $defaultImageUrl = $defaultVariantData['image_url'];
} elseif (!empty($imgs)) {
    $defaultImageUrl = '../' . ltrim($imgs[0]['image_url'], '/');
}

$defaultPrice = null;
if ($defaultVariantData) {
    $defaultPrice = ($defaultVariantData['special_price'] !== null && $defaultVariantData['special_price'] !== '')
        ? $defaultVariantData['special_price']
        : $defaultVariantData['original_price'];
}

$isMemberUser = ($currentUserMembershipLevel === 2);
$defaultMemberPrice = ($defaultVariantData && $defaultVariantData['member_price'] !== null && $defaultVariantData['member_price'] !== '')
    ? floatval($defaultVariantData['member_price'])
    : null;
$defaultDisplayPrice = $defaultVariantData
    ? ($isMemberUser && $defaultMemberPrice !== null ? $defaultMemberPrice : $defaultPrice)
    : null;

$variantPayload = array_values($variantMap);

$colorOptions = [];
$sizeOptions = [];
foreach ($variantMap as $variant) {
    $color = trim((string)($variant['color'] ?? ''));
    $size = trim((string)($variant['size_inches'] ?? ''));
    $sizeLabel = trim((string)($variant['size_label'] ?? ''));
    $stock = intval($variant['stock_available'] ?? 0);
    if ($color !== '') {
        if (!isset($colorOptions[$color])) {
            $colorOptions[$color] = [
                'label' => $color,
                'image_url' => $variant['image_url'] ?? '',
                'in_stock' => $stock > 0,
            ];
        } else {
            if ($stock > 0) {
                $colorOptions[$color]['in_stock'] = true;
            }
            if ($colorOptions[$color]['image_url'] === '' && ($variant['image_url'] ?? '') !== '') {
                $colorOptions[$color]['image_url'] = $variant['image_url'];
            }
        }
    }
    if ($sizeLabel !== '') {
        if (!isset($sizeOptions[$sizeLabel])) {
            $sizeOptions[$sizeLabel] = [
                'label' => $sizeLabel,
                'display' => $size,
                'in_stock' => $stock > 0,
            ];
        } elseif ($stock > 0) {
            $sizeOptions[$sizeLabel]['in_stock'] = true;
        }
    }
}

$defaultColor = $defaultVariantData['color'] ?? '';
$defaultSizeLabel = $defaultVariantData['size_label'] ?? '';
$defaultSizeDisplay = $defaultVariantData['size_inches'] ?? '';

$defaultOriginalPrice = $defaultVariantData ? floatval($defaultVariantData['original_price']) : null;
$defaultSpecialPrice = ($defaultVariantData && $defaultVariantData['special_price'] !== null && $defaultVariantData['special_price'] !== '')
    ? floatval($defaultVariantData['special_price'])
    : null;
$defaultMemberPrice = ($defaultVariantData && $defaultVariantData['member_price'] !== null && $defaultVariantData['member_price'] !== '')
    ? floatval($defaultVariantData['member_price'])
    : null;

$defaultHeadlinePrice = $defaultOriginalPrice;
$defaultHeadlineLabel = '售價';
if ($defaultOriginalPrice !== null) {
    if ($isMemberUser) {
        $candidates = [$defaultOriginalPrice];
        if ($defaultSpecialPrice !== null) {
            $candidates[] = $defaultSpecialPrice;
        }
        if ($defaultMemberPrice !== null) {
            $candidates[] = $defaultMemberPrice;
        }
        $defaultHeadlinePrice = min($candidates);
        if ($defaultMemberPrice !== null && abs($defaultHeadlinePrice - $defaultMemberPrice) < 0.0001) {
            $defaultHeadlineLabel = '會員價';
        } elseif ($defaultSpecialPrice !== null && abs($defaultHeadlinePrice - $defaultSpecialPrice) < 0.0001) {
            $defaultHeadlineLabel = '特價';
        }
    } elseif ($defaultSpecialPrice !== null && $defaultSpecialPrice < $defaultOriginalPrice) {
        $defaultHeadlinePrice = $defaultSpecialPrice;
        $defaultHeadlineLabel = '特價';
    }
}

// 加入購物車：寫入 cart_items（存在則累加數量）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_to_cart') {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }

    $userId = intval($_SESSION['user_id']);
    $quantity = isset($_POST['quantity']) ? max(1, intval($_POST['quantity'])) : 1;
    $variantId = isset($_POST['variant_id']) ? intval($_POST['variant_id']) : 0;

    // 若未選規格且有 SKU，預設第一個 SKU
    if ($variantId <= 0 && !empty($variants) && isset($variants[0]['variant_id'])) {
        $variantId = intval($variants[0]['variant_id']);
    }

    if (!tableExists($conn, 'cart_items')) {
        $cartNotice = '購物車資料表不存在，請先執行同步腳本。';
        $cartNoticeType = 'error';
    } else {
        $ok = false;
        $alreadyInCart = false;
        $cartErrorDetail = '';

        if ($variantId > 0) {
            $checkStmt = $conn->prepare('SELECT cart_item_id, quantity FROM cart_items WHERE user_id = ? AND product_id = ? AND variant_id = ? LIMIT 1');
            if ($checkStmt) {
                $checkStmt->bind_param('iii', $userId, $id, $variantId);
                $checkStmt->execute();
                $checkStmt->store_result();
                $checkStmt->bind_result($existsId, $existsQty);

                $hasExistingItem = $checkStmt->fetch();
                $checkStmt->free_result();

                if ($hasExistingItem) {
                    $alreadyInCart = true;
                    $newQty = intval($existsQty) + $quantity;
                    $upStmt = $conn->prepare('UPDATE cart_items SET quantity = ?, created_at = NOW() WHERE cart_item_id = ?');
                    if ($upStmt) {
                        $upStmt->bind_param('ii', $newQty, $existsId);
                        $ok = $upStmt->execute();
                        if (!$ok) {
                            $cartErrorDetail = 'UPDATE cart_items 失敗：' . $upStmt->error;
                        }
                        $upStmt->close();
                    } else {
                        $cartErrorDetail = 'UPDATE cart_items prepare 失敗：' . $conn->error;
                    }
                } else {
                    $insStmt = $conn->prepare('INSERT INTO cart_items (user_id, product_id, variant_id, quantity) VALUES (?, ?, ?, ?)');
                    if ($insStmt) {
                        $insStmt->bind_param('iiii', $userId, $id, $variantId, $quantity);
                        $ok = $insStmt->execute();
                        if (!$ok) {
                            $cartErrorDetail = 'INSERT cart_items 失敗：' . $insStmt->error;
                        }
                        $insStmt->close();
                    } else {
                        $cartErrorDetail = 'INSERT cart_items prepare 失敗：' . $conn->error;
                    }
                }
                $checkStmt->close();
            }
        } else {
            $checkStmt = $conn->prepare('SELECT cart_item_id, quantity FROM cart_items WHERE user_id = ? AND product_id = ? AND variant_id IS NULL LIMIT 1');
            if ($checkStmt) {
                $checkStmt->bind_param('ii', $userId, $id);
                $checkStmt->execute();
                $checkStmt->store_result();
                $checkStmt->bind_result($existsId, $existsQty);

                $hasExistingItem = $checkStmt->fetch();
                $checkStmt->free_result();

                if ($hasExistingItem) {
                    $alreadyInCart = true;
                    $newQty = intval($existsQty) + $quantity;
                    $upStmt = $conn->prepare('UPDATE cart_items SET quantity = ?, created_at = NOW() WHERE cart_item_id = ?');
                    if ($upStmt) {
                        $upStmt->bind_param('ii', $newQty, $existsId);
                        $ok = $upStmt->execute();
                        if (!$ok) {
                            $cartErrorDetail = 'UPDATE cart_items 失敗：' . $upStmt->error;
                        }
                        $upStmt->close();
                    } else {
                        $cartErrorDetail = 'UPDATE cart_items prepare 失敗：' . $conn->error;
                    }
                } else {
                    $insStmt = $conn->prepare('INSERT INTO cart_items (user_id, product_id, variant_id, quantity) VALUES (?, ?, NULL, ?)');
                    if ($insStmt) {
                        $insStmt->bind_param('iii', $userId, $id, $quantity);
                        $ok = $insStmt->execute();
                        if (!$ok) {
                            $cartErrorDetail = 'INSERT cart_items 失敗：' . $insStmt->error;
                        }
                        $insStmt->close();
                    } else {
                        $cartErrorDetail = 'INSERT cart_items prepare 失敗：' . $conn->error;
                    }
                }
                $checkStmt->close();
            }
        }

        if ($ok) {
            if ($alreadyInCart) {
                $cartNotice = '購物車已有該筆商品，已幫你更新數量，請前往購物車修改。';
            } else {
                $cartNotice = '已加入購物車';
            }
            $cartNoticeType = 'success';
        } else {
            $cartNotice = $cartErrorDetail !== '' ? $cartErrorDetail : '加入購物車失敗，請稍後再試。';
            $cartNoticeType = 'error';
            pdLog('ADD_CART_FAIL user=' . $userId . ', product=' . $id . ', variant=' . $variantId . ', err=' . $conn->error);
        }
    }
}

// 分類
$categories = [];
if (tableExists($conn, 'categories') && tableExists($conn, 'product_category_links')) {
    $catSql = 'SELECT c.category_id, c.name FROM categories c JOIN product_category_links pcl ON pcl.category_id = c.category_id WHERE pcl.product_id = ' . $id;
    $r = safeQuery($conn, $catSql, 'categories');
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $categories[] = $row;
        }
    }
}

include 'header.php';
?>

<link rel="stylesheet" href="../css/product_detail.css">
<main class="detail-page">
    <a href="index.php" class="back-link">回首頁</a>
    <section class="detail-wrap">
        <div class="detail-gallery">
            <?php if (!empty($imgs)): ?>
                <img id="mainImg" class="detail-main-image" src="<?php echo htmlspecialchars($defaultImageUrl !== '' ? $defaultImageUrl : '../' . ltrim($imgs[0]['image_url'], '/')); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                <div id="thumbList" class="detail-thumbs">
                    <?php foreach ($imgs as $im): ?>
                        <?php
                            $thumbSrc = '../' . ltrim($im['image_url'], '/');
                            $thumbColor = isset($im['color']) ? trim((string)$im['color']) : '';
                            $thumbMatchesDefault = ($defaultImageUrl !== '' && $thumbSrc === $defaultImageUrl);
                        ?>
                        <img
                            src="<?php echo htmlspecialchars($thumbSrc); ?>"
                            alt="<?php echo htmlspecialchars($im['alt_text'] ?? $product['name']); ?>"
                            data-image-url="<?php echo htmlspecialchars($thumbSrc); ?>"
                            data-color="<?php echo htmlspecialchars($thumbColor); ?>"
                            data-is-default="<?php echo $thumbMatchesDefault ? '1' : '0'; ?>"
                        >
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="detail-image-placeholder">暫無圖片</div>
            <?php endif; ?>
        </div>
        <div class="detail-info">
            <?php if (!empty($categories)): ?>
                <div class="detail-category"><?php echo htmlspecialchars($categories[0]['name']); ?></div>
            <?php else: ?>
                <div class="detail-category">All Pass</div>
            <?php endif; ?>
            <h1><?php echo htmlspecialchars($product['name']); ?></h1>

            <div class="price-stack">
                <div id="priceOriginalRow" class="price-line<?php echo ($defaultHeadlineLabel === '售價') ? ' is-hidden' : ''; ?>">
                    <span>原價</span>
                    <span id="priceOriginal"><?php echo $defaultOriginalPrice !== null ? 'NT$ ' . number_format($defaultOriginalPrice) : '--'; ?></span>
                </div>
                <div id="priceMemberRow" class="price-line<?php echo ($defaultHeadlineLabel === '會員價' || $defaultMemberPrice === null) ? ' is-hidden' : ''; ?>">
                    <span>會員價</span>
                    <span id="priceMember"><?php echo $defaultMemberPrice !== null ? 'NT$ ' . number_format($defaultMemberPrice) : '--'; ?></span>
                </div>
                <div id="priceSpecialRow" class="price-line<?php echo ($defaultHeadlineLabel === '特價' || $defaultSpecialPrice === null) ? ' is-hidden' : ''; ?>">
                    <span>特價</span>
                    <span id="priceSpecial"><?php echo $defaultSpecialPrice !== null ? 'NT$ ' . number_format($defaultSpecialPrice) : '--'; ?></span>
                </div>
            </div>
            <div class="price-main">
                <span id="priceLabel" class="price-tag"><?php echo htmlspecialchars($defaultHeadlineLabel); ?></span>
                <span id="headlinePrice" class="price-amount"><?php echo $defaultHeadlinePrice !== null ? 'NT$ ' . number_format($defaultHeadlinePrice) : '尚未設定'; ?></span>
            </div>
            <div id="priceHint" class="price-note">
                <?php if ($defaultVariantData): ?>
                    <?php if ($isMemberUser): ?>
                        <?php if ($defaultMemberPrice !== null && $defaultHeadlineLabel === '會員價'): ?>
                            您已享有專屬會員最優惠。
                        <?php elseif ($defaultMemberPrice !== null): ?>
                            會員價仍可使用：NT$ <?php echo number_format($defaultMemberPrice); ?>
                        <?php else: ?>
                            已顯示目前可用最優惠價格。
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if ($defaultSpecialPrice !== null && $defaultSpecialPrice < $defaultOriginalPrice): ?>
                            活動特惠價：NT$ <?php echo number_format($defaultSpecialPrice); ?>
                        <?php elseif ($defaultMemberPrice !== null): ?>
                            加入會員即可使用會員價：NT$ <?php echo number_format($defaultMemberPrice); ?>
                        <?php else: ?>
                            加入會員即可查看會員價。
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php if ($cartNotice !== ''): ?>
                <div class="detail-alert"><?php echo htmlspecialchars($cartNotice); ?></div>
            <?php endif; ?>

            <?php if (!empty($colorOptions)): ?>
                <div class="chip-group">
                    <span class="chip-label">顏色</span>
                    <div class="chip-row" id="colorOptions">
                        <?php foreach ($colorOptions as $color => $opt): ?>
                            <button
                                type="button"
                                class="chip color-chip<?php echo $opt['in_stock'] ? '' : ' is-disabled'; ?><?php echo ($defaultColor === $color) ? ' is-selected' : ''; ?>"
                                data-color-option="<?php echo htmlspecialchars($color); ?>"
                                data-image-url="<?php echo htmlspecialchars($opt['image_url']); ?>"
                                title="<?php echo htmlspecialchars($color); ?>"
                                <?php echo $opt['in_stock'] ? '' : 'disabled'; ?>
                            >
                                <span class="color-swatch" data-color="<?php echo htmlspecialchars($color); ?>"></span>
                                <span class="visually-hidden"><?php echo htmlspecialchars($color); ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($sizeOptions)): ?>
                <div class="chip-group">
                    <span class="chip-label">尺寸</span>
                    <div class="chip-row" id="sizeOptions">
                        <?php foreach ($sizeOptions as $sizeLabel => $opt): ?>
                            <button
                                type="button"
                                class="chip<?php echo $opt['in_stock'] ? '' : ' is-disabled'; ?><?php echo ($defaultSizeLabel === $sizeLabel) ? ' is-selected' : ''; ?>"
                                data-size-option="<?php echo htmlspecialchars($sizeLabel); ?>"
                                data-size-display="<?php echo htmlspecialchars($opt['display']); ?>"
                                title="<?php echo htmlspecialchars($opt['display']); ?>"
                                <?php echo $opt['in_stock'] ? '' : 'disabled'; ?>
                            >
                                <?php echo htmlspecialchars($opt['label']); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form method="post" id="cartForm" class="detail-actions">
                <input type="hidden" name="action" value="add_to_cart">
                <input type="hidden" name="variant_id" id="variantIdInput" value="<?php echo $defaultVariantId; ?>">
                <input type="number" name="quantity" id="quantityInput" min="1" value="1" class="qty-input">
                <button type="submit" class="detail-btn">加入購物車</button>
                <button type="button" class="ghost-btn favorite-btn" id="favoriteBtn" aria-pressed="false" aria-label="加入收藏">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M12 21s-6.7-4.35-9.33-8.05C-0.2 9.6 1.2 5.6 4.6 4.4c2.2-.8 4.5 0 6 1.8 1.5-1.8 3.8-2.6 6-1.8 3.4 1.2 4.8 5.2 1.93 8.55C18.7 16.65 12 21 12 21z" />
                    </svg>
                </button>
            </form>
            <div class="sticky-actions">
                <input type="number" id="quantityInputMobile" min="1" value="1" class="qty-input">
                <button type="submit" form="cartForm" class="detail-btn">加入購物車</button>
            </div>

            <div class="detail-info-box">
                <h3 style="margin:0 0 12px;">目前選擇的商品資訊</h3>
                <div style="display:grid; gap:8px; color:#444; line-height:1.7;">
                    <div>尺寸：<span id="selectedSize"><?php echo htmlspecialchars($defaultSizeLabel !== '' ? $defaultSizeLabel . ($defaultSizeDisplay !== '' ? ' (' . $defaultSizeDisplay . ')' : '') : '未設定'); ?></span></div>
                    <div>顏色：<span id="selectedColor"><?php echo htmlspecialchars($defaultVariantData['color'] ?? '未設定'); ?></span></div>
                    <div>單價：<span id="selectedPrice"><?php echo $defaultHeadlinePrice !== null ? 'NT$ ' . number_format($defaultHeadlinePrice) : '尚未設定'; ?></span></div>
                    <div>小計：<span id="selectedSubtotal"><?php echo $defaultHeadlinePrice !== null ? 'NT$ ' . number_format($defaultHeadlinePrice) : '尚未設定'; ?></span></div>
                    <div>庫存：<span id="selectedStock"><?php echo htmlspecialchars((string)($defaultVariantData['stock_available'] ?? '0')); ?></span></div>
                </div>
            </div>

            <?php if (!empty($product['description'])): ?>
                <section class="detail-copy">
                    <h2>商品描述</h2>
                    <p><?php echo nl2br(htmlspecialchars($product['description'])); ?></p>
                </section>
            <?php endif; ?>

            <?php if (!empty($product['warranty_months'])): ?>
                <section class="detail-copy">
                    <h2>保固資訊</h2>
                    <p><?php echo intval($product['warranty_months']); ?> 個月</p>
                </section>
            <?php endif; ?>
        </div>
    </section>
</main>

<div id="toast" class="toast"></div>

<script>
    const variantData = <?php echo json_encode($variantPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const isMemberUser = <?php echo $isMemberUser ? 'true' : 'false'; ?>;
    let globalSelectedColor = '<?php echo htmlspecialchars($defaultColor, ENT_QUOTES); ?>';
    let globalSelectedSize = '<?php echo htmlspecialchars($defaultSizeLabel, ENT_QUOTES); ?>';
</script>

<script src="../js/product_detail.js"></script>
<?php include 'footer.php'; $conn->close(); ?>
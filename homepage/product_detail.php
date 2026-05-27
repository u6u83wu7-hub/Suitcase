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
    $variantImage = null;
    if ($variantColor !== '' && isset($colorImageMap[$variantColor])) {
        $variantImage = $colorImageMap[$variantColor];
    } elseif (!empty($imgs)) {
        $variantImage = $imgs[0];
    }

    $variantMap[$variantId] = [
        'variant_id' => $variantId,
        'sku_code' => (string)($v['sku_code'] ?? ''),
        'size_inches' => (string)($v['size_inches'] ?? ''),
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

<section style="padding:120px 5%; max-width:1100px; margin:0 auto;">
    <a href="index.php" style="color:#555; display:inline-block; margin-bottom:18px;">⬅️ 回首頁</a>
    <div style="display:flex; gap:40px; align-items:flex-start;">
        <div style="flex:1; max-width:560px;">
            <?php if (!empty($imgs)): ?>
                <div style="border:1px solid #eee; padding:12px; background:#fff;">
                    <img id="mainImg" src="<?php echo htmlspecialchars($defaultImageUrl !== '' ? $defaultImageUrl : '../' . ltrim($imgs[0]['image_url'], '/')); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" style="width:100%; height:auto; object-fit:cover;">
                </div>
                <div id="thumbList" style="display:flex; gap:10px; margin-top:12px; flex-wrap:wrap;">
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
                            style="width:80px; height:80px; object-fit:cover; border:1px solid #ddd; cursor:pointer;"
                        >
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="border:1px solid #eee; padding:60px; text-align:center; color:#999;">暫無圖片</div>
            <?php endif; ?>
        </div>
        <div style="flex:1;">
            <h1 style="font-size:28px; margin-bottom:8px;"><?php echo htmlspecialchars($product['name']); ?></h1>
            <div style="color:#777; margin-bottom:12px;">狀態：<?php echo htmlspecialchars($product['status']); ?>　｜　上架時間：<?php echo htmlspecialchars($product['created_at']); ?></div>

            <div style="font-size:22px; font-weight:700; color:#222; margin-bottom:16px;">
                <?php
                if ($defaultDisplayPrice !== null) {
                    echo '<span id="headlinePrice">NT$ ' . number_format($defaultDisplayPrice) . '</span>';
                } else {
                    $displayPrice = null;
                    foreach ($variants as $v) {
                        $p = ($isMemberUser && $v['member_price'] !== null && $v['member_price'] !== '')
                            ? floatval($v['member_price'])
                            : (($v['special_price'] !== null && $v['special_price'] !== '') ? floatval($v['special_price']) : floatval($v['original_price']));
                        if ($displayPrice === null || $p < $displayPrice) $displayPrice = $p;
                    }
                    if ($displayPrice !== null) echo '<span id="headlinePrice">NT$ ' . number_format($displayPrice) . '</span>';
                    else echo '<span id="headlinePrice">價格：尚未設定</span>';
                }
                ?>
            </div>
            <div id="priceHint" style="margin-top:-6px; margin-bottom:14px; color:#777; font-size:14px; line-height:1.6;">
                <?php if ($defaultVariantData): ?>
                    <?php if ($isMemberUser): ?>
                        <?php if ($defaultMemberPrice !== null): ?>
                            您目前是會員，已顯示會員價。
                        <?php else: ?>
                            您目前是會員，已顯示目前可用價格。
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if ($defaultMemberPrice !== null): ?>
                            加入會員即可使用會員價：NT$ <?php echo number_format($defaultMemberPrice); ?>
                        <?php else: ?>
                            加入會員即可查看會員價。
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php if ($cartNotice !== ''): ?>
                <div style="margin-bottom:14px; padding:10px 12px; border-radius:8px; <?php echo $cartNoticeType === 'success' ? 'background:#ecfdf5;color:#166534;border:1px solid #86efac;' : 'background:#fef2f2;color:#991b1b;border:1px solid #fca5a5;'; ?>">
                    <?php echo htmlspecialchars($cartNotice); ?>
                </div>
            <?php endif; ?>

            <div style="display:flex; gap:12px; margin-bottom:16px; flex-wrap:wrap;">
                <button
                    type="button"
                    style="padding:10px 18px; border:1px solid #db6b6b; background:#fff; color:#db6b6b; border-radius:999px; font-weight:700; cursor:pointer;"
                    onclick="alert('已加入收藏');"
                >
                    收藏
                </button>
                <form method="post" id="cartForm" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin:0;">
                    <input type="hidden" name="action" value="add_to_cart">
                    <input type="hidden" name="variant_id" id="variantIdInput" value="<?php echo $defaultVariantId; ?>">

                    <?php if (!empty($variants)): ?>
                        <select id="variantSelect" style="height:40px; border:1px solid #ddd; border-radius:8px; padding:0 10px;">
                            <?php foreach ($variants as $v): ?>
                                <?php
                                    $optId = intval($v['variant_id']);
                                    $optPrice = ($v['special_price'] !== null && $v['special_price'] !== '') ? floatval($v['special_price']) : floatval($v['original_price']);
                                    $optColor = trim((string)($v['color'] ?? ''));
                                    $optSize = trim((string)($v['size_inches'] ?? ''));
                                ?>
                                <option
                                    value="<?php echo $optId; ?>"
                                    data-variant-id="<?php echo $optId; ?>"
                                    data-sku-code="<?php echo htmlspecialchars($v['sku_code']); ?>"
                                    data-size="<?php echo htmlspecialchars($optSize); ?>"
                                    data-color="<?php echo htmlspecialchars($optColor); ?>"
                                    data-price="<?php echo htmlspecialchars(number_format($optPrice, 2, '.', '')); ?>"
                                    data-stock="<?php echo intval($v['stock_available']); ?>"
                                    data-image-url="<?php echo htmlspecialchars($variantMap[$optId]['image_url'] ?? ''); ?>"
                                >
                                    <?php echo htmlspecialchars(($v['size_inches'] !== '' ? $v['size_inches'] : '尺寸未設定') . ' / ' . ($v['color'] !== '' ? $v['color'] : '顏色未設定')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>

                    <input type="number" name="quantity" id="quantityInput" min="1" value="1" style="width:76px; height:40px; border:1px solid #ddd; border-radius:8px; padding:0 10px;">
                    <button
                        type="submit"
                        style="padding:10px 18px; border:1px solid #db6b6b; background:#db6b6b; color:#fff; border-radius:999px; font-weight:700; cursor:pointer;"
                    >
                        加入購物車
                    </button>
                </form>
            </div>

            <?php if (!empty($categories)): ?>
                <div style="margin-bottom:12px;">分類：
                    <?php foreach ($categories as $c) echo '<span style="background:#f3f3f3;padding:6px 8px;border-radius:6px;margin-right:6px;">' . htmlspecialchars($c['name']) . '</span>'; ?>
                </div>
            <?php endif; ?>

            <div style="margin-bottom:18px; color:#444; line-height:1.8;"><?php echo nl2br(htmlspecialchars($product['description'])); ?></div>

            <?php if (!empty($variants)): ?>
                <h3 style="margin-top:10px;">SKU 列表</h3>
                <table style="width:100%; border-collapse:collapse; margin-top:8px;">
                    <thead>
                        <tr style="text-align:left; border-bottom:1px solid #eee; color:#666;"><th>SKU</th><th>尺寸</th><th>顏色</th><th>價格</th><th>庫存</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($variants as $v): ?>
                            <tr>
                                <td style="padding:8px 6px;"><?php echo htmlspecialchars($v['sku_code']); ?></td>
                                <td style="padding:8px 6px;"><?php echo htmlspecialchars($v['size_inches']); ?></td>
                                <td style="padding:8px 6px;"><?php echo htmlspecialchars($v['color']); ?></td>
                                <td style="padding:8px 6px;">
                                    <?php
                                        $listPrice = ($isMemberUser && $v['member_price'] !== null && $v['member_price'] !== '')
                                            ? floatval($v['member_price'])
                                            : (($v['special_price'] !== null && $v['special_price'] !== '') ? floatval($v['special_price']) : floatval($v['original_price']));
                                        echo 'NT$ ' . number_format($listPrice);
                                    ?>
                                </td>
                                <td style="padding:8px 6px;"><?php echo intval($v['stock_available']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <div style="margin-top:18px; padding:16px; border:1px solid #eee; border-radius:14px; background:#fafafa;">
                <h3 style="margin:0 0 12px;">目前選擇的商品資訊</h3>
                <div style="display:grid; gap:8px; color:#444; line-height:1.7;">
                    <div>SKU：<span id="selectedSku"><?php echo htmlspecialchars($defaultVariantData['sku_code'] ?? '未選擇'); ?></span></div>
                    <div>尺寸：<span id="selectedSize"><?php echo htmlspecialchars($defaultVariantData['size_inches'] ?? '未設定'); ?></span></div>
                    <div>顏色：<span id="selectedColor"><?php echo htmlspecialchars($defaultVariantData['color'] ?? '未設定'); ?></span></div>
                    <div>單價：<span id="selectedPrice"><?php echo $defaultPrice !== null ? 'NT$ ' . number_format($defaultPrice) : '尚未設定'; ?></span></div>
                    <div>小計：<span id="selectedSubtotal"><?php echo $defaultPrice !== null ? 'NT$ ' . number_format($defaultPrice) : '尚未設定'; ?></span></div>
                    <div>庫存：<span id="selectedStock"><?php echo htmlspecialchars((string)($defaultVariantData['stock_available'] ?? '0')); ?></span></div>
                </div>
            </div>

            <?php if (!empty($product['warranty_months'])): ?>
                <div style="margin-top:16px; color:#555;">保固：<?php echo intval($product['warranty_months']); ?> 個月</div>
            <?php endif; ?>

        </div>
    </div>
</section>

<script>
const variantData = <?php echo json_encode($variantPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const variantSelect = document.getElementById('variantSelect');
const variantIdInput = document.getElementById('variantIdInput');
const mainImg = document.getElementById('mainImg');
const selectedSku = document.getElementById('selectedSku');
const selectedSize = document.getElementById('selectedSize');
const selectedColor = document.getElementById('selectedColor');
const selectedPrice = document.getElementById('selectedPrice');
const selectedSubtotal = document.getElementById('selectedSubtotal');
const selectedStock = document.getElementById('selectedStock');
const headlinePrice = document.getElementById('headlinePrice');
const priceHint = document.getElementById('priceHint');
const thumbList = document.getElementById('thumbList');
const quantityInput = document.getElementById('quantityInput');
const isMemberUser = <?php echo $isMemberUser ? 'true' : 'false'; ?>;

function formatPrice(value) {
    const number = Number(value);
    if (Number.isNaN(number)) {
        return '尚未設定';
    }
    return 'NT$ ' + number.toLocaleString('zh-TW');
}

function findVariant(variantId) {
    const normalizedId = String(variantId || '');
    return variantData.find(item => String(item.variant_id) === normalizedId) || null;
}

function getVariantUnitPrice(variant) {
    if (!variant) {
        return null;
    }

    if (isMemberUser && variant.member_price !== null && variant.member_price !== '') {
        return Number(variant.member_price);
    }

    if (variant.special_price !== null && variant.special_price !== '') {
        return Number(variant.special_price);
    }

    return Number(variant.original_price);
}

function getVariantMemberPrice(variant) {
    if (!variant || variant.member_price === null || variant.member_price === '') {
        return null;
    }

    return Number(variant.member_price);
}

function updateSubtotal() {
    if (!selectedSubtotal) {
        return;
    }

    const variantId = variantIdInput ? variantIdInput.value : '';
    const variant = findVariant(variantId);
    const unitPrice = getVariantUnitPrice(variant);
    const quantity = quantityInput ? Math.max(1, parseInt(quantityInput.value || '1', 10) || 1) : 1;

    if (variant && unitPrice !== null && !Number.isNaN(unitPrice)) {
        selectedSubtotal.textContent = formatPrice(unitPrice * quantity);
    } else {
        selectedSubtotal.textContent = '尚未設定';
    }
}

function applyVariant(variantId, imageUrl) {
    const variant = findVariant(variantId);
    if (!variant) {
        return;
    }

    if (variantSelect) {
        variantSelect.value = String(variant.variant_id);
    }
    if (variantIdInput) {
        variantIdInput.value = String(variant.variant_id);
    }

    if (selectedSku) {
        selectedSku.textContent = variant.sku_code || '未設定';
    }
    if (selectedSize) {
        selectedSize.textContent = variant.size_inches || '未設定';
    }
    if (selectedColor) {
        selectedColor.textContent = variant.color || '未設定';
    }
    if (selectedPrice) {
        const priceValue = getVariantUnitPrice(variant);
        selectedPrice.textContent = priceValue !== null && !Number.isNaN(priceValue) ? formatPrice(priceValue) : '尚未設定';
    }
    if (headlinePrice) {
        const priceValue = getVariantUnitPrice(variant);
        headlinePrice.textContent = priceValue !== null && !Number.isNaN(priceValue) ? formatPrice(priceValue) : '價格：尚未設定';
    }
    if (priceHint) {
        const memberPrice = getVariantMemberPrice(variant);
        if (isMemberUser) {
            priceHint.textContent = memberPrice !== null && !Number.isNaN(memberPrice)
                ? '您目前是會員，已顯示會員價。'
                : '您目前是會員，已顯示目前可用價格。';
        } else {
            priceHint.textContent = memberPrice !== null && !Number.isNaN(memberPrice)
                ? '加入會員即可使用會員價：' + formatPrice(memberPrice)
                : '加入會員即可查看會員價。';
        }
    }
    if (selectedStock) {
        selectedStock.textContent = String(variant.stock_available ?? 0);
    }
    if (mainImg) {
        const nextImage = imageUrl || variant.image_url;
        if (nextImage) {
            mainImg.src = nextImage;
        }
    }

    updateSubtotal();

    if (thumbList) {
        const thumbs = thumbList.querySelectorAll('img[data-image-url]');
        thumbs.forEach((thumb) => {
            const isActive = thumb.dataset.imageUrl === (imageUrl || variant.image_url);
            thumb.style.outline = isActive ? '2px solid #db6b6b' : 'none';
            thumb.style.outlineOffset = isActive ? '2px' : '0';
        });
    }
}

if (variantSelect) {
    variantSelect.addEventListener('change', (event) => {
        const option = event.target.selectedOptions[0];
        const variantId = option ? option.value : event.target.value;
        const imageUrl = option ? option.dataset.imageUrl : '';
        applyVariant(variantId, imageUrl);
    });

    const selectedOption = variantSelect.selectedOptions[0];
    if (selectedOption) {
        applyVariant(selectedOption.value, selectedOption.dataset.imageUrl || '');
    }
}

if (quantityInput) {
    quantityInput.addEventListener('input', updateSubtotal);
    quantityInput.addEventListener('change', updateSubtotal);
}

if (thumbList) {
    thumbList.addEventListener('click', (event) => {
        const thumb = event.target.closest('img[data-image-url]');
        if (!thumb) {
            return;
        }

        if (mainImg) {
            mainImg.src = thumb.dataset.imageUrl;
        }

        const matchedVariant = variantData.find((item) => item.image_url && item.image_url === thumb.dataset.imageUrl);

        if (matchedVariant) {
            applyVariant(matchedVariant.variant_id, thumb.dataset.imageUrl);
        } else if (thumb.dataset.color) {
            const colorMatched = variantData.find((item) => item.color === thumb.dataset.color);
            if (colorMatched) {
                applyVariant(colorMatched.variant_id, thumb.dataset.imageUrl);
            }
        }
    });
}
</script>

<?php include 'footer.php'; $conn->close(); ?>

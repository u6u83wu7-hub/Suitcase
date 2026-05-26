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

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

$conn = new mysqli('localhost', 'root', '', 'all_pass_db');
if ($conn->connect_error) {
    die('資料庫連線失敗: ' . $conn->connect_error);
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

        if ($variantId > 0) {
            $checkStmt = $conn->prepare('SELECT cart_item_id, quantity FROM cart_items WHERE user_id = ? AND product_id = ? AND variant_id = ? LIMIT 1');
            if ($checkStmt) {
                $checkStmt->bind_param('iii', $userId, $id, $variantId);
                $checkStmt->execute();
                $checkStmt->bind_result($existsId, $existsQty);

                if ($checkStmt->fetch()) {
                    $newQty = intval($existsQty) + $quantity;
                    $upStmt = $conn->prepare('UPDATE cart_items SET quantity = ? WHERE cart_item_id = ?');
                    if ($upStmt) {
                        $upStmt->bind_param('ii', $newQty, $existsId);
                        $ok = $upStmt->execute();
                        $upStmt->close();
                    }
                } else {
                    $insStmt = $conn->prepare('INSERT INTO cart_items (user_id, product_id, variant_id, quantity) VALUES (?, ?, ?, ?)');
                    if ($insStmt) {
                        $insStmt->bind_param('iiii', $userId, $id, $variantId, $quantity);
                        $ok = $insStmt->execute();
                        $insStmt->close();
                    }
                }
                $checkStmt->close();
            }
        } else {
            $checkStmt = $conn->prepare('SELECT cart_item_id, quantity FROM cart_items WHERE user_id = ? AND product_id = ? AND variant_id IS NULL LIMIT 1');
            if ($checkStmt) {
                $checkStmt->bind_param('ii', $userId, $id);
                $checkStmt->execute();
                $checkStmt->bind_result($existsId, $existsQty);

                if ($checkStmt->fetch()) {
                    $newQty = intval($existsQty) + $quantity;
                    $upStmt = $conn->prepare('UPDATE cart_items SET quantity = ? WHERE cart_item_id = ?');
                    if ($upStmt) {
                        $upStmt->bind_param('ii', $newQty, $existsId);
                        $ok = $upStmt->execute();
                        $upStmt->close();
                    }
                } else {
                    $insStmt = $conn->prepare('INSERT INTO cart_items (user_id, product_id, quantity) VALUES (?, ?, ?)');
                    if ($insStmt) {
                        $insStmt->bind_param('iii', $userId, $id, $quantity);
                        $ok = $insStmt->execute();
                        $insStmt->close();
                    }
                }
                $checkStmt->close();
            }
        }

        if ($ok) {
            $cartNotice = '已加入購物車';
            $cartNoticeType = 'success';
        } else {
            $cartNotice = '加入購物車失敗，請稍後再試。';
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
                    <img id="mainImg" src="../<?php echo htmlspecialchars($imgs[0]['image_url']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" style="width:100%; height:auto; object-fit:cover;">
                </div>
                <div style="display:flex; gap:10px; margin-top:12px;">
                    <?php foreach ($imgs as $im): ?>
                        <img src="../<?php echo htmlspecialchars($im['image_url']); ?>" alt="" style="width:80px; height:80px; object-fit:cover; border:1px solid #ddd; cursor:pointer;" onclick="document.querySelector('#mainImg').src=this.src;">
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
                // 顯示最低價格
                $displayPrice = null;
                foreach ($variants as $v) {
                    $p = ($v['special_price'] !== null && $v['special_price'] !== '') ? floatval($v['special_price']) : floatval($v['original_price']);
                    if ($displayPrice === null || $p < $displayPrice) $displayPrice = $p;
                }
                if ($displayPrice !== null) echo 'NT$ ' . number_format($displayPrice);
                else echo '價格：尚未設定';
                ?>
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
                <form method="post" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin:0;">
                    <input type="hidden" name="action" value="add_to_cart">

                    <?php if (!empty($variants)): ?>
                        <select name="variant_id" style="height:40px; border:1px solid #ddd; border-radius:8px; padding:0 10px;">
                            <?php foreach ($variants as $v): ?>
                                <option value="<?php echo intval($v['variant_id']); ?>">
                                    <?php echo htmlspecialchars(($v['size_inches'] !== '' ? $v['size_inches'] : '尺寸未設定') . ' / ' . ($v['color'] !== '' ? $v['color'] : '顏色未設定')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>

                    <input type="number" name="quantity" min="1" value="1" style="width:76px; height:40px; border:1px solid #ddd; border-radius:8px; padding:0 10px;">
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
                                <td style="padding:8px 6px;"><?php echo 'NT$ ' . number_format(($v['special_price'] !== null && $v['special_price'] !== '') ? $v['special_price'] : $v['original_price']); ?></td>
                                <td style="padding:8px 6px;"><?php echo intval($v['stock_available']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if (!empty($product['warranty_months'])): ?>
                <div style="margin-top:16px; color:#555;">保固：<?php echo intval($product['warranty_months']); ?> 個月</div>
            <?php endif; ?>

        </div>
    </div>
</section>

<?php include 'footer.php'; $conn->close(); ?>

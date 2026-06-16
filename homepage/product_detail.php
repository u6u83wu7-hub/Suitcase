<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
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
            echo '<p style="font-family:Arial,sans-serif;padding:0 20px 20px;">請稍後再試，或回到首頁重新瀏覽商品。</p>';
        }
    }
});

$pageTitle = '商品詳情 | All Pass';
$activeNav = '';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Taipei');
require_once __DIR__ . '/includes/promotion_price_sync.php';
require_once __DIR__ . '/includes/storefront_helpers.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/price_helper.php';

apConfigureErrorHandling();

$cartNotice = '';
$cartNoticeType = 'success';
$currentUserMembershipLevel = null;

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

$conn = new mysqli('localhost', 'root', '', 'all_pass_db');
if ($conn->connect_error) {
    error_log('Product detail database connection failed: ' . $conn->connect_error);
    http_response_code(500);
    echo '系統暫時無法連線資料庫，請稍後再試。';
    exit;
}
$conn->set_charset('utf8mb4');
apRunPromotionSync($conn);

// 💡 處理前端 AJAX 發送的「加入/移除收藏愛心」請求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_favorite') {
    header('Content-Type: application/json');
    if (!apValidateCsrf()) {
        echo json_encode(['success' => false, 'error' => '表單驗證失敗，請重新操作']);
        exit;
    }
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'error' => '請先登入']);
        exit;
    }
    $userId = intval($_SESSION['user_id']);
    $favProductId = intval($_POST['product_id'] ?? 0);

    if ($favProductId > 0) {
        $checkFav = $conn->prepare("SELECT favorite_id FROM user_favorites WHERE user_id = ? AND product_id = ?");
        $checkFav->bind_param("ii", $userId, $favProductId);
        $checkFav->execute();
        $res = $checkFav->get_result();

        if ($res->num_rows > 0) {
            // 已存在則移除
            $delFav = $conn->prepare("DELETE FROM user_favorites WHERE user_id = ? AND product_id = ?");
            $delFav->bind_param("ii", $userId, $favProductId);
            $delFav->execute();
            echo json_encode(['success' => true, 'status' => 'removed']);
        } else {
            // 不存在則新增
            $addFav = $conn->prepare("INSERT INTO user_favorites (user_id, product_id) VALUES (?, ?)");
            $addFav->bind_param("ii", $userId, $favProductId);
            $addFav->execute();
            echo json_encode(['success' => true, 'status' => 'added']);
        }
        exit;
    }
    echo json_encode(['success' => false, 'error' => '商品無效']);
    exit;
}

if (!empty($_SESSION['user_id'])) {
    $currentUserMembershipLevel = apFetchUserMembershipLevel($conn, intval($_SESSION['user_id']));
}

function safeQuery($conn, $sql, $tag = '') {
    $res = $conn->query($sql);
    if ($res === false) {
        pdLog('SQL_FAIL ' . ($tag !== '' ? "[$tag] " : '') . $conn->error . ' | SQL=' . $sql);
    }
    return $res;
}

function tableExists($conn, $tableName) {
    $safe = preg_replace('/[^a-zA-70-9_]/', '', $tableName);
    $res = safeQuery($conn, "SHOW TABLES LIKE '{$safe}'", 'tableExists');
    return ($res && $res->num_rows > 0);
}

// 💡 修正了正規表示法小錯字
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
    in_array('warranty_info', $productCols, true) ? 'warranty_info' : "'' AS warranty_info",
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

// 💡 取得「初始收藏狀態」，以便渲染愛心顏色
$isFavorited = false;
if (!empty($_SESSION['user_id'])) {
    $favChk = $conn->prepare("SELECT favorite_id FROM user_favorites WHERE user_id = ? AND product_id = ?");
    $favChk->bind_param("ii", $_SESSION['user_id'], $id);
    $favChk->execute();
    if ($favChk->get_result()->num_rows > 0) {
        $isFavorited = true;
    }
    $favChk->close();
}

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
    (in_array('color_hex', $variantCols, true) ? 'color_hex' : 'NULL AS color_hex') . ', ' .
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
    
    // 👇 新增抓取並驗證色碼
    $variantColorHex = strtoupper(trim((string)($v['color_hex'] ?? '')));
    if (!preg_match('/^#[0-9A-F]{6}$/', $variantColorHex)) {
        $variantColorHex = function_exists('sfColorHex') ? (sfColorHex($variantColor) ?: '') : '';
    }

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
        'color_hex' => $variantColorHex, // 👇 新增這行存入陣列
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

$isMemberUser = apIsMemberPriceEligible($currentUserMembershipLevel);
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
                'hex' => $variant['color_hex'] ?? '', // 👇 新增存入 hex
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
            // 👇 確保有抓到色碼就補上
            if (($colorOptions[$color]['hex'] ?? '') === '' && ($variant['color_hex'] ?? '') !== '') {
                $colorOptions[$color]['hex'] = $variant['color_hex'];
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

$defaultPriceInfo = $defaultVariantData ? apResolveVariantPrice($defaultVariantData, $isMemberUser) : null;
$defaultHeadlinePrice = $defaultPriceInfo ? $defaultPriceInfo['final_price'] : $defaultOriginalPrice;
$defaultHeadlineLabel = $defaultPriceInfo ? $defaultPriceInfo['headline_label'] : '原價';

// 加入購物車：寫入 cart_items（存在則累加數量）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_to_cart') {
    if (!apValidateCsrf()) {
        $cartNotice = '表單驗證失敗，請重新操作。';
        $cartNoticeType = 'error';
    } else {
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

        $stockAvailable = null;
        if ($variantId > 0) {
            $stockStmt = $conn->prepare('SELECT stock_available FROM product_variants WHERE variant_id = ? AND product_id = ? LIMIT 1');
            if ($stockStmt) {
                $stockStmt->bind_param('ii', $variantId, $id);
                $stockStmt->execute();
                $stockRow = $stockStmt->get_result()->fetch_assoc();
                $stockAvailable = $stockRow ? intval($stockRow['stock_available']) : 0;
                $stockStmt->close();
            }
        }

        if (!tableExists($conn, 'cart_items')) {
            $cartNotice = '購物車資料表不存在，請先執行同步腳本。';
            $cartNoticeType = 'error';
        } elseif ($stockAvailable !== null && $quantity > $stockAvailable) {
            $cartNotice = '加入數量超過庫存，目前庫存 ' . $stockAvailable . ' 件。';
            $cartNoticeType = 'error';
        } else {
            $ok = false;
            $alreadyInCart = false;
            $cartErrorDetail = '';
            $targetCartItemId = 0;

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
                        
                        // 【修正】判斷是否為「直接下單」，若是則以選擇的數量為主（不累加）
                        if (isset($_POST['buy_now']) && $_POST['buy_now'] === '1') {
                            $newQty = $quantity;
                        } else {
                            $newQty = intval($existsQty) + $quantity;
                        }

                        if ($stockAvailable !== null && $newQty > $stockAvailable) {
                            $cartErrorDetail = '購物車已有此商品，累計數量會超過庫存，目前庫存 ' . $stockAvailable . ' 件。';
                        } else {
                            $upStmt = $conn->prepare('UPDATE cart_items SET quantity = ?, created_at = NOW() WHERE cart_item_id = ?');
                            if ($upStmt) {
                                $upStmt->bind_param('ii', $newQty, $existsId);
                                $ok = $upStmt->execute();
                                if ($ok) {
                                    $targetCartItemId = intval($existsId);
                                }
                                if (!$ok) {
                                    error_log('Add to cart update failed: ' . $upStmt->error);
                                    $cartErrorDetail = '加入購物車失敗，請稍後再試。';
                                }
                                $upStmt->close();
                            } else {
                                error_log('Add to cart update prepare failed: ' . $conn->error);
                                $cartErrorDetail = '加入購物車失敗，請稍後再試。';
                            }
                        }
                    } else {
                        $insStmt = $conn->prepare('INSERT INTO cart_items (user_id, product_id, variant_id, quantity) VALUES (?, ?, ?, ?)');
                        if ($insStmt) {
                            $insStmt->bind_param('iiii', $userId, $id, $variantId, $quantity);
                            $ok = $insStmt->execute();
                            if ($ok) {
                                $targetCartItemId = intval($conn->insert_id);
                            }
                            if (!$ok) {
                                error_log('Add to cart insert failed: ' . $insStmt->error);
                                $cartErrorDetail = '加入購物車失敗，請稍後再試。';
                            }
                            $insStmt->close();
                        } else {
                            error_log('Add to cart insert prepare failed: ' . $conn->error);
                            $cartErrorDetail = '加入購物車失敗，請稍後再試。';
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
                        
                        // 【修正】判斷是否為「直接下單」，若是則以選擇的數量為主（不累加）
                        if (isset($_POST['buy_now']) && $_POST['buy_now'] === '1') {
                            $newQty = $quantity;
                        } else {
                            $newQty = intval($existsQty) + $quantity;
                        }

                        $upStmt = $conn->prepare('UPDATE cart_items SET quantity = ?, created_at = NOW() WHERE cart_item_id = ?');
                        if ($upStmt) {
                            $upStmt->bind_param('ii', $newQty, $existsId);
                            $ok = $upStmt->execute();
                            if ($ok) {
                                $targetCartItemId = intval($existsId);
                            }
                            if (!$ok) {
                                error_log('Add to cart update without variant failed: ' . $upStmt->error);
                                $cartErrorDetail = '加入購物車失敗，請稍後再試。';
                            }
                            $upStmt->close();
                        } else {
                            error_log('Add to cart update without variant prepare failed: ' . $conn->error);
                            $cartErrorDetail = '加入購物車失敗，請稍後再試。';
                        }
                    } else {
                        $insStmt = $conn->prepare('INSERT INTO cart_items (user_id, product_id, variant_id, quantity) VALUES (?, ?, NULL, ?)');
                        if ($insStmt) {
                            $insStmt->bind_param('iii', $userId, $id, $quantity);
                            $ok = $insStmt->execute();
                            if ($ok) {
                                $targetCartItemId = intval($conn->insert_id);
                            }
                            if (!$ok) {
                                error_log('Add to cart insert without variant failed: ' . $insStmt->error);
                                $cartErrorDetail = '加入購物車失敗，請稍後再試。';
                            }
                            $insStmt->close();
                        } else {
                            error_log('Add to cart insert without variant prepare failed: ' . $conn->error);
                            $cartErrorDetail = '加入購物車失敗，請稍後再試。';
                        }
                    }
                    $checkStmt->close();
                }
            }

            if ($ok) {
                // 💡 如果是點擊「直接下單」，加入購物車成功後直接跳轉購物車頁面
                if (isset($_POST['buy_now']) && $_POST['buy_now'] === '1') {
                    header('Location: cart.php?buy_now_item=' . intval($targetCartItemId));
                    exit;
                }

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

$relatedProducts = [];
if (tableExists($conn, 'product_category_links')) {
    $categoryIds = array_map(function ($category) {
        return (int)$category['category_id'];
    }, $categories);

    $relatedImageOrder = [];
    if (in_array('is_main', $imageCols, true)) {
        $relatedImageOrder[] = 'pi.is_main DESC';
    }
    if (in_array('sort_order', $imageCols, true)) {
        $relatedImageOrder[] = 'pi.sort_order ASC';
    }
    if (empty($relatedImageOrder)) {
        $relatedImageOrder[] = 'pi.image_id ASC';
    }
    $relatedImageOrderSql = implode(', ', $relatedImageOrder);
    $relatedPriceSql = apVariantPriceSql('v', $isMemberUser);

    if (!empty($categoryIds)) {
        $categoryList = implode(',', array_map('intval', $categoryIds));
        $relatedSql = "
            SELECT
                p.product_id,
                p.name,
                p.is_featured,
                COUNT(DISTINCT pcl_match.category_id) AS match_count,
                COALESCE(SUM(v.stock_available), 0) AS total_stock,
                MIN({$relatedPriceSql}) AS price,
                (
                    SELECT pi.image_url
                    FROM product_images pi
                    WHERE pi.product_id = p.product_id
                    ORDER BY {$relatedImageOrderSql}
                    LIMIT 1
                ) AS image_url
            FROM products p
            INNER JOIN product_category_links pcl_match ON pcl_match.product_id = p.product_id
            LEFT JOIN product_variants v ON v.product_id = p.product_id
            WHERE p.product_id <> {$id}
              AND p.status = 'ON SHELF'
              AND pcl_match.category_id IN ({$categoryList})
            GROUP BY p.product_id
            HAVING total_stock > 0
            ORDER BY match_count DESC, p.is_featured DESC, total_stock DESC, p.created_at DESC
            LIMIT 4
        ";
        $relatedRes = safeQuery($conn, $relatedSql, 'relatedProductsByCategory');
        if ($relatedRes) {
            while ($row = $relatedRes->fetch_assoc()) {
                $relatedProducts[] = $row;
            }
        }
    }

    if (empty($relatedProducts)) {
        $fallbackSql = "
            SELECT
                p.product_id,
                p.name,
                p.is_featured,
                0 AS match_count,
                COALESCE(SUM(v.stock_available), 0) AS total_stock,
                MIN({$relatedPriceSql}) AS price,
                (
                    SELECT pi.image_url
                    FROM product_images pi
                    WHERE pi.product_id = p.product_id
                    ORDER BY {$relatedImageOrderSql}
                    LIMIT 1
                ) AS image_url
            FROM products p
            LEFT JOIN product_variants v ON v.product_id = p.product_id
            WHERE p.product_id <> {$id}
              AND p.status = 'ON SHELF'
            GROUP BY p.product_id
            HAVING total_stock > 0
            ORDER BY p.is_featured DESC, p.created_at DESC
            LIMIT 4
        ";
        $fallbackRes = safeQuery($conn, $fallbackSql, 'relatedProductsFallback');
        if ($fallbackRes) {
            while ($row = $fallbackRes->fetch_assoc()) {
                $relatedProducts[] = $row;
            }
        }
    }
}

$productFaqs = [];
if (tableExists($conn, 'product_qa')) {
    $faqSql = "SELECT question, answer, qa_type, product_id
               FROM product_qa
               WHERE qa_type = 'GENERAL'
                  OR product_id IS NULL
                  OR product_id = {$id}
               ORDER BY CASE WHEN product_id = {$id} THEN 0 ELSE 1 END, created_at DESC, qa_id DESC";
    $faqRes = safeQuery($conn, $faqSql, 'productFaqs');
    if ($faqRes) {
        while ($row = $faqRes->fetch_assoc()) {
            $productFaqs[] = $row;
        }
    }
}

$productReviews = [];
$reviewSummary = ['avg_rating' => 0, 'review_count' => 0];
if (tableExists($conn, 'product_reviews')) {
    $summaryStmt = $conn->prepare(
        'SELECT AVG(rating) AS avg_rating, COUNT(*) AS review_count
         FROM product_reviews
         WHERE product_id = ? AND is_visible = 1'
    );
    if ($summaryStmt) {
        $summaryStmt->bind_param('i', $id);
        $summaryStmt->execute();
        $summaryRow = $summaryStmt->get_result()->fetch_assoc();
        if ($summaryRow) {
            $reviewSummary['avg_rating'] = $summaryRow['avg_rating'] !== null ? (float)$summaryRow['avg_rating'] : 0;
            $reviewSummary['review_count'] = (int)$summaryRow['review_count'];
        }
        $summaryStmt->close();
    }

    $reviewStmt = $conn->prepare(
        'SELECT pr.rating, pr.comment, pr.created_at, u.name
         FROM product_reviews pr
         LEFT JOIN users u ON u.user_id = pr.user_id
         WHERE pr.product_id = ? AND pr.is_visible = 1
         ORDER BY pr.created_at DESC, pr.review_id DESC
         LIMIT 8'
    );
    if ($reviewStmt) {
        $reviewStmt->bind_param('i', $id);
        $reviewStmt->execute();
        $reviewRes = $reviewStmt->get_result();
        while ($row = $reviewRes->fetch_assoc()) {
            $productReviews[] = $row;
        }
        $reviewStmt->close();
    }
}

include 'header.php';
?>

<?php $productDetailCssVersion = @filemtime(__DIR__ . '/../css/product_detail.css') ?: time(); ?>
<link rel="stylesheet" href="../css/product_detail.css?v=<?php echo $productDetailCssVersion; ?>">
<main class="detail-page">
    <a href="index.php" class="back-link">回首頁</a>
    <section class="detail-wrap">
        
        <div class="detail-left-column">
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

            <?php if (!empty($productFaqs)): ?>
                <section class="detail-faq">
                    <div class="detail-section-heading">
                        <span>FAQ</span>
                        <h2>常見問題</h2>
                    </div>
                    <div class="faq-list">
                        <?php foreach ($productFaqs as $faq): ?>
                            <details class="faq-item">
                                <summary>
                                    <span><?php echo htmlspecialchars($faq['question']); ?></span>
                                    <small><?php echo intval($faq['product_id'] ?? 0) === $id ? '商品專屬' : '通用問題'; ?></small>
                                </summary>
                                <p><?php echo nl2br(htmlspecialchars($faq['answer'])); ?></p>
                            </details>
                        <?php endforeach; ?>
                    </div>
                </section>
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
                <div id="priceOriginalRow" class="price-line<?php echo ($defaultHeadlineLabel === '原價') ? ' is-hidden' : ''; ?>">
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
            class="chip color-chip color-text-chip<?php echo $opt['in_stock'] ? '' : ' is-disabled'; ?><?php echo ($defaultColor === $color) ? ' is-selected' : ''; ?>"
            data-color-option="<?php echo htmlspecialchars($color); ?>"
            data-color-hex="<?php echo htmlspecialchars($opt['hex'] ?? ''); ?>"
            data-image-url="<?php echo htmlspecialchars($opt['image_url']); ?>"
            title="<?php echo htmlspecialchars($color); ?>"
            <?php echo $opt['in_stock'] ? '' : 'disabled'; ?>
        >
            <?php if (!empty($opt['hex'])): ?>
                <span class="chip-color-dot" style="background:<?php echo htmlspecialchars($opt['hex']); ?>"></span>
            <?php endif; ?>
            <?php echo htmlspecialchars($color); ?>
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
                <?php echo apCsrfField(); ?>
                <input type="hidden" name="action" value="add_to_cart">
                <input type="hidden" name="variant_id" id="variantIdInput" value="<?php echo $defaultVariantId; ?>">
                <input type="number" name="quantity" id="quantityInput" min="1" value="1" class="qty-input">
                
                <button type="submit" class="detail-btn">加入購物車</button>
                <button type="submit" name="buy_now" value="1" class="detail-btn" style="background-color: var(--accent); border-color: var(--accent);">直接下單</button>
                
                <button type="button" class="ghost-btn favorite-btn <?php echo $isFavorited ? 'is-active' : ''; ?>" id="favoriteBtn" aria-pressed="<?php echo $isFavorited ? 'true' : 'false'; ?>" aria-label="加入收藏">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M12 21s-6.7-4.35-9.33-8.05C-0.2 9.6 1.2 5.6 4.6 4.4c2.2-.8 4.5 0 6 1.8 1.5-1.8 3.8-2.6 6-1.8 3.4 1.2 4.8 5.2 1.93 8.55C18.7 16.65 12 21 12 21z" />
                    </svg>
                </button>
            </form>
            
            <div class="sticky-actions">
                <input type="number" id="quantityInputMobile" min="1" value="1" class="qty-input">
                <button type="submit" form="cartForm" class="detail-btn" style="padding: 10px;">加入購物車</button>
                <button type="submit" form="cartForm" name="buy_now" value="1" class="detail-btn" style="padding: 10px; background-color: var(--accent); border-color: var(--accent);">直接下單</button>
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

            <?php if (!empty($product['warranty_info'])): ?>
                <section class="detail-copy">
                    <h2>售後與保固說明</h2>
                    <p><?php echo nl2br(htmlspecialchars($product['warranty_info'])); ?></p>
                </section>
            <?php endif; ?>

        </div>
    </section>

    <?php if (tableExists($conn, 'product_reviews')): ?>
        <section class="review-section" aria-label="商品評論">
            <div class="related-heading">
                <div>
                    <span>REVIEWS</span>
                    <h2>商品評論</h2>
                </div>
                <div class="review-summary">
                    <strong><?php echo $reviewSummary['review_count'] > 0 ? number_format($reviewSummary['avg_rating'], 1) : '--'; ?></strong>
                    <small><?php echo intval($reviewSummary['review_count']); ?> 則評論</small>
                </div>
            </div>

            <div class="review-list">
                <?php if (empty($productReviews)): ?>
                    <div class="review-empty">目前還沒有評論。</div>
                <?php else: ?>
                    <?php foreach ($productReviews as $review): ?>
                        <?php
                        $rating = max(1, min(5, (int)$review['rating']));
                        $maskedName = trim((string)($review['name'] ?? ''));
                        if ($maskedName === '') {
                            $maskedName = 'All Pass 會員';
                        } elseif (function_exists('mb_strlen') && mb_strlen($maskedName, 'UTF-8') > 1) {
                            $maskedName = mb_substr($maskedName, 0, 1, 'UTF-8') . str_repeat('*', max(1, mb_strlen($maskedName, 'UTF-8') - 1));
                        } elseif (strlen($maskedName) > 1) {
                            $maskedName = substr($maskedName, 0, 1) . str_repeat('*', max(1, strlen($maskedName) - 1));
                        }
                        ?>
                        <article class="review-card">
                            <div class="review-card-head">
                                <strong><?php echo htmlspecialchars($maskedName); ?></strong>
                                <span aria-label="<?php echo $rating; ?> stars"><?php echo str_repeat('★', $rating) . str_repeat('☆', 5 - $rating); ?></span>
                            </div>
                            <?php if (trim((string)$review['comment']) !== ''): ?>
                                <p><?php echo nl2br(htmlspecialchars($review['comment'])); ?></p>
                            <?php endif; ?>
                            <small><?php echo htmlspecialchars(substr((string)$review['created_at'], 0, 10)); ?></small>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!empty($relatedProducts)): ?>
        <section class="related-section" aria-label="相似行李箱推薦">
            <div class="related-heading">
                <div>
                    <span>RECOMMENDED</span>
                    <h2>相似行李箱推薦</h2>
                </div>
                <a href="new_in.php<?php echo !empty($categories) ? '?category_id=' . intval($categories[0]['category_id']) : ''; ?>">查看更多</a>
            </div>
            <div class="related-grid">
                <?php foreach ($relatedProducts as $related): ?>
                    <?php
                    $relatedImage = !empty($related['image_url']) ? '../' . ltrim($related['image_url'], '/') : '';
                    $relatedPrice = $related['price'] !== null ? (float)$related['price'] : 0;
                    ?>
                    <a class="related-product-card" href="product_detail.php?id=<?php echo intval($related['product_id']); ?>">
                        <div class="related-product-media">
                            <?php if ($relatedImage !== ''): ?>
                                <img class="related-product-image" src="<?php echo htmlspecialchars($relatedImage); ?>" alt="<?php echo htmlspecialchars($related['name']); ?>" loading="lazy">
                            <?php else: ?>
                                <div class="related-image-placeholder">No Img</div>
                            <?php endif; ?>
                        </div>
                        <div class="related-product-body">
       ㄉ                     <small><?php echo ((int)$related['match_count'] > 0) ? '同分類推薦' : '你可能也喜歡'; ?></small>
                            <strong><?php echo htmlspecialchars($related['name']); ?></strong>
                            <span>NT$ <?php echo number_format($relatedPrice); ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</main>

<div id="toast" class="toast"></div>

<script>
    const isLoggedIn = <?php echo !empty($_SESSION['user_id']) ? 'true' : 'false'; ?>;
    const currentProductId = <?php echo $id; ?>;
    const csrfToken = <?php echo json_encode(apCsrfToken(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const variantData = <?php echo json_encode($variantPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const isMemberUser = <?php echo $isMemberUser ? 'true' : 'false'; ?>;
    let globalSelectedColor = '<?php echo htmlspecialchars($defaultColor, ENT_QUOTES); ?>';
    let globalSelectedSize = '<?php echo htmlspecialchars($defaultSizeLabel, ENT_QUOTES); ?>';
</script>

<script src="../js/product_detail.js"></script>
<?php include 'footer.php'; $conn->close(); ?>
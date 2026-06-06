<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
$pageTitle = '結帳 | All Pass';
$activeNav = '';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Asia/Taipei');
require_once __DIR__ . '/includes/security.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = intval($_SESSION['user_id']);
$conn = new mysqli('localhost', 'root', '', 'all_pass_db');
if ($conn->connect_error) {
    error_log('Checkout database connection failed: ' . $conn->connect_error);
    http_response_code(500);
    echo '系統暫時無法連線資料庫，請稍後再試或聯繫客服。';
    exit;
}
$conn->set_charset('utf8mb4');
require_once __DIR__ . '/includes/storefront_helpers.php';
require_once __DIR__ . '/includes/promotion_price_sync.php';
require_once __DIR__ . '/includes/price_helper.php';

apRunPromotionSync($conn);
$currentUserMembershipLevel = apFetchUserMembershipLevel($conn, $userId);
$isMemberPriceEligible = apIsMemberPriceEligible($currentUserMembershipLevel);

function checkoutTableExists($conn, $tableName) {
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return ($res && $res->num_rows > 0);
}

function checkoutTableColumns($conn, $tableName) {
    $columns = [];
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    $res = $conn->query("SHOW COLUMNS FROM `{$safe}`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
    }
    return $columns;
}

function checkoutFetchRow($conn, $sql) {
    $res = $conn->query($sql);
    if (!$res) {
        return null;
    }
    $row = $res->fetch_assoc();
    $res->free();
    return $row ?: null;
}

function checkoutFetchRows($conn, $sql) {
    $rows = [];
    $res = $conn->query($sql);
    if (!$res) {
        return $rows;
    }
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $res->free();
    return $rows;
}

function checkoutGenerateOrderNumber($conn) {
    for ($i = 0; $i < 5; $i++) {
        $orderNumber = 'ORD' . date('YmdHis') . random_int(1000, 9999);
        $escaped = $conn->real_escape_string($orderNumber);
        $res = $conn->query("SELECT 1 FROM orders WHERE order_number = '{$escaped}' LIMIT 1");
        if (!$res || $res->num_rows === 0) {
            if ($res) {
                $res->free();
            }
            return $orderNumber;
        }
        $res->free();
    }

    return 'ORD' . date('YmdHis') . mt_rand(10000, 99999);
}

$userColumns = checkoutTableExists($conn, 'users') ? checkoutTableColumns($conn, 'users') : [];
$memberColumns = checkoutTableExists($conn, 'user_member_details') ? checkoutTableColumns($conn, 'user_member_details') : [];
$orderColumns = checkoutTableExists($conn, 'orders') ? checkoutTableColumns($conn, 'orders') : [];
$orderItemColumns = checkoutTableExists($conn, 'order_items') ? checkoutTableColumns($conn, 'order_items') : [];
$variantColumns = checkoutTableExists($conn, 'product_variants') ? checkoutTableColumns($conn, 'product_variants') : [];

$hasShippingNotesColumn = in_array('shipping_notes', $orderColumns, true);
$hasNoteColumn = in_array('note', $orderColumns, true);
$hasInventoryDeductedColumn = in_array('inventory_deducted', $orderColumns, true);
$hasVariantStockColumn = in_array('stock_available', $variantColumns, true);
$hasOrderItemProductIdColumn = in_array('product_id', $orderItemColumns, true);
$hasOrderItemVariantNameColumn = in_array('variant_name', $orderItemColumns, true);
$hasOrderItemUnitPriceColumn = in_array('unit_price', $orderItemColumns, true);
$hasOrderItemSubtotalAmountColumn = in_array('subtotal_amount', $orderItemColumns, true);

$user = [
    'name' => isset($_SESSION['user_name']) ? $_SESSION['user_name'] : '會員',
    'email' => isset($_SESSION['user_email']) ? $_SESSION['user_email'] : '',
    'phone' => ''
];

$userRow = checkoutFetchRow($conn, "SELECT name, email, phone FROM users WHERE user_id = {$userId} LIMIT 1");
if ($userRow) {
    $user['name'] = $userRow['name'] !== null ? $userRow['name'] : $user['name'];
    $user['email'] = $userRow['email'] !== null ? $userRow['email'] : $user['email'];
    $user['phone'] = $userRow['phone'] !== null ? $userRow['phone'] : '';
}

$memberDetail = [
    'full_address' => '',
    'address_note' => '',
    'cardholder_name' => '',
    'card_last4' => '',
    'card_brand' => '',
    'expiry_month' => '',
    'expiry_year' => ''
];

if (checkoutTableExists($conn, 'user_member_details')) {
    $detailRow = checkoutFetchRow($conn, "SELECT full_address, address_note, cardholder_name, card_last4, card_brand, expiry_month, expiry_year FROM user_member_details WHERE user_id = {$userId} LIMIT 1");
    if ($detailRow) {
        foreach ($memberDetail as $key => $value) {
            $memberDetail[$key] = $detailRow[$key] !== null ? $detailRow[$key] : '';
        }
    }
}

$selectedIds = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['selected']) && is_array($_POST['selected'])) {
    foreach ($_POST['selected'] as $selectedId) {
        $selectedIds[] = intval($selectedId);
    }
}

$postedQuantities = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quantities']) && is_array($_POST['quantities'])) {
    foreach ($_POST['quantities'] as $cartItemId => $quantity) {
        $postedQuantities[intval($cartItemId)] = max(1, intval($quantity));
    }
}

$items = [];
$totalAmount = 0;
if (checkoutTableExists($conn, 'cart_items') && !empty($selectedIds)) {
    $idList = implode(',', array_map('intval', $selectedIds));
    $imageOrder = 'pi.is_main DESC, pi.sort_order ASC, pi.image_id ASC';
    $fallbackPriceSql = apVariantPriceSql('pv', $isMemberPriceEligible);
    $sql = "
        SELECT
            ci.cart_item_id,
            ci.product_id,
            ci.variant_id,
            ci.quantity,
            p.name AS product_name,
            COALESCE(v.color, '') AS variant_color,
            COALESCE(v.size_inches, '') AS variant_size,
            COALESCE(v.sku_code, '') AS sku_code,
            COALESCE(v.original_price, 0) AS original_price,
            COALESCE(v.special_price, NULL) AS special_price,
            COALESCE(v.member_price, 0) AS member_price,
            COALESCE(v.stock_available, 0) AS stock_available,
            COALESCE((
                SELECT pi.image_url
                FROM product_images pi
                WHERE pi.product_id = p.product_id
                  AND ci.variant_id IS NOT NULL
                  AND v.color IS NOT NULL
                  AND v.color <> ''
                  AND pi.color = v.color
                ORDER BY {$imageOrder}
                LIMIT 1
            ), (
                SELECT pi.image_url
                FROM product_images pi
                WHERE pi.product_id = p.product_id
                ORDER BY {$imageOrder}
                LIMIT 1
            ), '') AS image_url,
            COALESCE((
                SELECT MIN({$fallbackPriceSql})
                FROM product_variants pv
                WHERE pv.product_id = p.product_id
            ), 0) AS fallback_price
        FROM cart_items ci
        LEFT JOIN products p ON p.product_id = ci.product_id
        LEFT JOIN product_variants v ON v.variant_id = ci.variant_id
        WHERE ci.user_id = {$userId} AND ci.cart_item_id IN ({$idList})
        ORDER BY ci.created_at DESC, ci.cart_item_id DESC
    ";
    $items = checkoutFetchRows($conn, $sql);
}

foreach ($items as &$item) {
    $cartItemId = intval($item['cart_item_id']);
    if (isset($postedQuantities[$cartItemId])) {
        $item['quantity'] = $postedQuantities[$cartItemId];
    }

    $priceInfo = apResolveVariantPrice($item, $isMemberPriceEligible);
    $displayPrice = floatval($priceInfo['final_price']);
    if ($displayPrice <= 0) {
        $displayPrice = floatval($item['fallback_price']);
    }
    $item['display_price'] = $displayPrice;
    $item['price_label'] = $priceInfo['headline_label'];
    $item['subtotal'] = $displayPrice * intval($item['quantity']);
    $totalAmount += $item['subtotal'];
}
unset($item);

$availableCoupons = [];
if (checkoutTableExists($conn, 'coupon_distributions') && checkoutTableExists($conn, 'coupons')) {
    $couponSql = "
        SELECT
            c.coupon_id,
            c.coupon_name,
            c.coupon_code,
            c.coupon_type,
            c.coupon_value,
            c.min_order_amount,
            c.start_at,
            c.end_at,
            SUM(cd.quantity) AS available_quantity,
            MAX(cd.created_at) AS received_at
        FROM coupon_distributions cd
        INNER JOIN coupons c ON c.coupon_id = cd.coupon_id
        WHERE cd.user_id = {$userId}
          AND c.is_active = 1
          AND (c.start_at IS NULL OR c.start_at <= NOW())
          AND (c.end_at IS NULL OR c.end_at >= NOW())
        GROUP BY c.coupon_id, c.coupon_name, c.coupon_code, c.coupon_type, c.coupon_value, c.min_order_amount, c.start_at, c.end_at
        ORDER BY received_at DESC, c.coupon_id DESC
    ";
    $availableCoupons = checkoutFetchRows($conn, $couponSql);
}

$errors = [];
$checkoutDone = false;
$orderNumber = '';

$defaultRecipientName = trim($user['name']);
$defaultRecipientPhone = trim($user['phone']);
$defaultShippingAddress = trim($memberDetail['full_address']);
$defaultAddressNote = trim($memberDetail['address_note']);
$defaultCardholderName = trim($memberDetail['cardholder_name'] !== '' ? $memberDetail['cardholder_name'] : $user['name']);
$defaultCardBrand = trim($memberDetail['card_brand']);
$defaultCardExpiryMonth = trim($memberDetail['expiry_month']);
$defaultCardExpiryYear = trim($memberDetail['expiry_year']);

$formRecipientName = $_POST['recipient_name'] ?? $defaultRecipientName;
$formRecipientPhone = $_POST['recipient_phone'] ?? $defaultRecipientPhone;
$formShippingAddress = $_POST['shipping_address'] ?? $defaultShippingAddress;
$formAddressNote = $_POST['address_note'] ?? $defaultAddressNote;
$formPaymentMethod = $_POST['payment_method'] ?? 'credit_card';
$formCardholderName = $_POST['cardholder_name'] ?? $defaultCardholderName;
$formCardBrand = $_POST['card_brand'] ?? $defaultCardBrand;
$formCardNumber = $_POST['card_number'] ?? '';
$formExpiryMonth = $_POST['expiry_month'] ?? $defaultCardExpiryMonth;
$formExpiryYear = $_POST['expiry_year'] ?? $defaultCardExpiryYear;
$formNote = $_POST['note'] ?? '';
$formCouponId = isset($_POST['coupon_id']) ? intval($_POST['coupon_id']) : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'place_order') {
    if (!apValidateCsrf()) {
        $errors[] = '表單驗證失敗，請重新操作。';
    }

    if (empty($items)) {
        $errors[] = '請先選擇購物車中的商品。';
    }

    $cardDigits = preg_replace('/\D+/', '', $formCardNumber);
    $formCardLast4 = $cardDigits !== '' ? substr($cardDigits, -4) : '';

    if (trim($formRecipientName) === '') {
        $errors[] = '請輸入收件人姓名。';
    }
    if (trim($formRecipientPhone) === '') {
        $errors[] = '請輸入收件人電話。';
    }
    if (trim($formShippingAddress) === '') {
        $errors[] = '請輸入收件地址。';
    }
    if (trim($formCardholderName) === '') {
        $errors[] = '請輸入持卡人姓名。';
    }
    if (trim($formCardBrand) === '') {
        $errors[] = '請選擇卡片品牌。';
    }
    if ($cardDigits === '' || strlen($cardDigits) < 12) {
        $errors[] = '請輸入完整信用卡號。';
    }
    if (trim($formExpiryMonth) === '') {
        $errors[] = '請輸入信用卡到期月。';
    }
    if (trim($formExpiryYear) === '') {
        $errors[] = '請輸入信用卡到期年。';
    }

    if (empty($errors)) {
        $shippingFee = 0.00;
        $appliedCouponId = null;
        $discountAmount = 0.00;
        $rewardPoints = 0;

        if ($formCouponId > 0) {
            $couponStmt = $conn->prepare("SELECT coupon_id, coupon_type, coupon_value, min_order_amount, is_active, start_at, end_at FROM coupons WHERE coupon_id = ? LIMIT 1");
            if ($couponStmt) {
                $couponStmt->bind_param('i', $formCouponId);
                $couponStmt->execute();
                $couponRes = $couponStmt->get_result();
                $couponRow = ($couponRes && $couponRes->num_rows > 0) ? $couponRes->fetch_assoc() : null;
                $couponStmt->close();

                if ($couponRow) {
                    $ownershipStmt = $conn->prepare("SELECT distribution_id FROM coupon_distributions WHERE coupon_id = ? AND user_id = ? LIMIT 1");
                    $couponOwned = false;
                    if ($ownershipStmt) {
                        $ownershipStmt->bind_param('ii', $formCouponId, $userId);
                        $ownershipStmt->execute();
                        $ownershipRes = $ownershipStmt->get_result();
                        $couponOwned = $ownershipRes && $ownershipRes->num_rows > 0;
                        $ownershipStmt->close();
                    }

                    $now = time();
                    $couponStart = !empty($couponRow['start_at']) ? strtotime($couponRow['start_at']) : false;
                    $couponEnd = !empty($couponRow['end_at']) ? strtotime($couponRow['end_at']) : false;
                    $couponValue = (float)($couponRow['coupon_value'] ?? 0);
                    $minOrderAmount = (float)($couponRow['min_order_amount'] ?? 0);

                    if ((int)$couponRow['is_active'] === 1 &&
                        $couponOwned &&
                        ($couponStart === false || $now >= $couponStart) &&
                        ($couponEnd === false || $now <= $couponEnd) &&
                        $totalAmount >= $minOrderAmount) {
                        if (($couponRow['coupon_type'] ?? '') === 'DISCOUNT') {
                            $discountAmount = round($totalAmount * $couponValue / 100, 2);
                        } elseif (($couponRow['coupon_type'] ?? '') === 'REDUCE') {
                            $discountAmount = round($couponValue, 2);
                        } elseif (($couponRow['coupon_type'] ?? '') === 'POINTS') {
                            $rewardPoints = max(0, (int)round($couponValue));
                        }
                        $appliedCouponId = (int)$couponRow['coupon_id'];
                    }
                }
            }
        }

        $discountAmount = min(max($discountAmount, 0.00), $totalAmount + $shippingFee);
        $grandTotal = max(round($totalAmount + $shippingFee - $discountAmount, 2), 0.00);
        $paymentMethod = 'credit_card';
        $formCardLast4 = substr($cardDigits, -4);

        $conn->begin_transaction();
        try {
            $orderNumber = checkoutGenerateOrderNumber($conn);
            $orderStatus = 'PENDING';
            $combinedNote = trim($formAddressNote . (($formAddressNote !== '' && $formNote !== '') ? ' | ' : '') . $formNote);

            $orderColumnsList = [
                'order_number',
                'user_id',
                'status',
                'subtotal_amount',
                'shipping_fee',
                'coupon_id',
                'discount_amount',
                'total_amount',
                'recipient_name',
                'recipient_phone',
                'shipping_address',
            ];
            $orderValues = [
                $orderNumber,
                $userId,
                $orderStatus,
                $totalAmount,
                $shippingFee,
                $appliedCouponId,
                $discountAmount,
                $grandTotal,
                $formRecipientName,
                $formRecipientPhone,
                $formShippingAddress,
            ];
            $orderTypes = 'sisddiddsss';

            if ($hasShippingNotesColumn) {
                $orderColumnsList[] = 'shipping_notes';
                $orderValues[] = $formAddressNote;
                $orderTypes .= 's';
            }

            $orderColumnsList[] = 'payment_method';
            $orderColumnsList[] = 'cardholder_name';
            $orderColumnsList[] = 'card_brand';
            $orderColumnsList[] = 'card_last4';
            $orderColumnsList[] = 'card_expiry_month';
            $orderColumnsList[] = 'card_expiry_year';
            $orderValues[] = $paymentMethod;
            $orderValues[] = $formCardholderName;
            $orderValues[] = $formCardBrand;
            $orderValues[] = $formCardLast4;
            $orderValues[] = $formExpiryMonth;
            $orderValues[] = $formExpiryYear;
            $orderTypes .= 'ssssss';

            if ($hasNoteColumn) {
                $orderColumnsList[] = 'note';
                $orderValues[] = $hasShippingNotesColumn ? $formNote : $combinedNote;
                $orderTypes .= 's';
            } elseif (!$hasShippingNotesColumn) {
                // If the legacy table has neither note column, we still keep the extra text locally.
            }

            $orderSql = 'INSERT INTO orders (' . implode(', ', $orderColumnsList) . ') VALUES (' . implode(', ', array_fill(0, count($orderColumnsList), '?')) . ')';
            $orderStmt = $conn->prepare($orderSql);
            if (!$orderStmt) {
                throw new RuntimeException('無法建立訂單語句。');
            }
            $bindValues = [$orderTypes];
            foreach ($orderValues as $index => $value) {
                $bindValues[] = &$orderValues[$index];
            }
            call_user_func_array([$orderStmt, 'bind_param'], $bindValues);
            if (!$orderStmt->execute()) {
                throw new RuntimeException('訂單寫入失敗。');
            }
            $orderId = intval($conn->insert_id);
            $orderStmt->close();

            if (checkoutTableExists($conn, 'payment_transactions')) {
                $paymentStatus = 'SUCCESS';
                $transactionNo = 'SIM-' . $orderNumber . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
                $paymentStmt = $conn->prepare('INSERT INTO payment_transactions (order_id, amount, payment_method, status, transaction_no) VALUES (?, ?, ?, ?, ?)');
                if (!$paymentStmt) {
                    throw new RuntimeException('建立付款交易紀錄失敗。');
                }
                $paymentStmt->bind_param('idsss', $orderId, $grandTotal, $paymentMethod, $paymentStatus, $transactionNo);
                if (!$paymentStmt->execute()) {
                    throw new RuntimeException('建立付款交易紀錄失敗。');
                }
                $paymentStmt->close();
            }

            $itemColumns = ['order_id'];
            $itemPlaceholders = ['?'];
            $itemTypes = 'i';

            if ($hasOrderItemProductIdColumn) {
                $itemColumns[] = 'product_id';
                $itemPlaceholders[] = '?';
                $itemTypes .= 'i';
            }

            $itemColumns[] = 'variant_id';
            $itemColumns[] = 'product_name';
            $itemColumns[] = 'sku_code';
            $itemColumns[] = 'color';
            $itemColumns[] = 'size_inches';
            $itemColumns[] = 'quantity';
            $itemColumns[] = 'locked_price';
            $itemPlaceholders[] = '?';
            $itemPlaceholders[] = '?';
            $itemPlaceholders[] = '?';
            $itemPlaceholders[] = '?';
            $itemPlaceholders[] = '?';
            $itemPlaceholders[] = '?';
            $itemPlaceholders[] = '?';
            $itemTypes .= 'issssid';

            if ($hasOrderItemVariantNameColumn) {
                $itemColumns[] = 'variant_name';
                $itemPlaceholders[] = '?';
                $itemTypes .= 's';
            }

            if ($hasOrderItemUnitPriceColumn) {
                $itemColumns[] = 'unit_price';
                $itemPlaceholders[] = '?';
                $itemTypes .= 'd';
            }

            if ($hasOrderItemSubtotalAmountColumn) {
                $itemColumns[] = 'subtotal_amount';
                $itemPlaceholders[] = '?';
                $itemTypes .= 'd';
            }

            $itemSql = 'INSERT INTO order_items (' . implode(', ', $itemColumns) . ') VALUES (' . implode(', ', $itemPlaceholders) . ')';
            $deductStockStmt = null;
            if ($hasVariantStockColumn) {
                $deductStockStmt = $conn->prepare("UPDATE product_variants SET stock_available = stock_available - ? WHERE variant_id = ? AND stock_available >= ?");
                if (!$deductStockStmt) {
                    throw new RuntimeException('建立庫存扣減程序失敗。');
                }
            }

            foreach ($items as $item) {
                $quantity = intval($item['quantity']);
                $unitPrice = floatval($item['display_price']);
                $skuCode = trim((string)$item['sku_code']);
                $variantColor = trim((string)$item['variant_color']);
                $variantSize = trim((string)$item['variant_size']);
                $variantName = trim(($variantSize !== '' ? $variantSize . '吋' : '') . (($variantColor !== '' && $variantSize !== '') ? ' / ' : '') . ($variantColor !== '' ? $variantColor : ''));
                $variantId = intval($item['variant_id']);
                $lockedPrice = $unitPrice;
                $productId = intval($item['product_id']);
                $subtotalAmount = $lockedPrice * $quantity;

                if ($deductStockStmt !== null) {
                    if ($variantId <= 0 || $quantity <= 0) {
                        throw new RuntimeException('商品規格或數量不正確，無法建立訂單。');
                    }
                    $deductStockStmt->bind_param('iii', $quantity, $variantId, $quantity);
                    if (!$deductStockStmt->execute() || $deductStockStmt->affected_rows !== 1) {
                        throw new RuntimeException('商品庫存不足，請返回購物車調整數量。');
                    }
                }

                $itemValues = [$orderId];
                if ($hasOrderItemProductIdColumn) {
                    $itemValues[] = $productId;
                }
                $itemValues[] = $variantId;
                $itemValues[] = $item['product_name'];
                $itemValues[] = $skuCode;
                $itemValues[] = $variantColor;
                $itemValues[] = $variantSize;
                $itemValues[] = $quantity;
                $itemValues[] = $lockedPrice;
                if ($hasOrderItemVariantNameColumn) {
                    $itemValues[] = $variantName;
                }
                if ($hasOrderItemUnitPriceColumn) {
                    $itemValues[] = $unitPrice;
                }
                if ($hasOrderItemSubtotalAmountColumn) {
                    $itemValues[] = $subtotalAmount;
                }

                $itemStmt = $conn->prepare($itemSql);
                if (!$itemStmt) {
                    throw new RuntimeException('無法建立訂單明細語句。');
                }

                $bindValues = [$itemTypes];
                foreach ($itemValues as $index => $value) {
                    $bindValues[] = &$itemValues[$index];
                }
                call_user_func_array([$itemStmt, 'bind_param'], $bindValues);

                if (!$itemStmt->execute()) {
                    throw new RuntimeException('訂單明細寫入失敗。');
                }
                $itemStmt->close();
            }

            if ($deductStockStmt !== null) {
                $deductStockStmt->close();
            }

            if ($hasInventoryDeductedColumn && $hasVariantStockColumn) {
                $inventoryFlag = 1;
                $markInventoryStmt = $conn->prepare("UPDATE orders SET inventory_deducted = ? WHERE order_id = ?");
                if (!$markInventoryStmt) {
                    throw new RuntimeException('更新訂單庫存狀態失敗。');
                }
                $markInventoryStmt->bind_param('ii', $inventoryFlag, $orderId);
                if (!$markInventoryStmt->execute()) {
                    throw new RuntimeException('更新訂單庫存狀態失敗。');
                }
                $markInventoryStmt->close();
            }

            if ($appliedCouponId !== null) {
                $distributionStmt = $conn->prepare(
                    "SELECT distribution_id, quantity
                     FROM coupon_distributions
                     WHERE coupon_id = ? AND user_id = ? AND quantity > 0
                     ORDER BY created_at DESC, distribution_id DESC
                     LIMIT 1
                     FOR UPDATE"
                );
                if (!$distributionStmt) {
                    throw new RuntimeException('無法更新優惠卷使用紀錄。');
                }

                $distributionStmt->bind_param('ii', $appliedCouponId, $userId);
                $distributionStmt->execute();
                $distributionRes = $distributionStmt->get_result();
                $distributionRow = ($distributionRes && $distributionRes->num_rows > 0) ? $distributionRes->fetch_assoc() : null;
                $distributionStmt->close();

                if (!$distributionRow) {
                    throw new RuntimeException('找不到可使用的優惠卷紀錄。');
                }

                $distributionId = intval($distributionRow['distribution_id']);
                $distributionQuantity = intval($distributionRow['quantity']);

                if ($distributionQuantity > 1) {
                    $updateDistributionStmt = $conn->prepare("UPDATE coupon_distributions SET quantity = quantity - 1 WHERE distribution_id = ?");
                    if (!$updateDistributionStmt) {
                        throw new RuntimeException('更新優惠卷數量失敗。');
                    }
                    $updateDistributionStmt->bind_param('i', $distributionId);
                    if (!$updateDistributionStmt->execute()) {
                        throw new RuntimeException('更新優惠卷數量失敗。');
                    }
                    $updateDistributionStmt->close();
                } else {
                    $deleteDistributionStmt = $conn->prepare("DELETE FROM coupon_distributions WHERE distribution_id = ?");
                    if (!$deleteDistributionStmt) {
                        throw new RuntimeException('刪除優惠卷紀錄失敗。');
                    }
                    $deleteDistributionStmt->bind_param('i', $distributionId);
                    if (!$deleteDistributionStmt->execute()) {
                        throw new RuntimeException('刪除優惠卷紀錄失敗。');
                    }
                    $deleteDistributionStmt->close();
                }
            }

            if ($rewardPoints > 0) {
                $pointStmt = $conn->prepare("UPDATE users SET points_balance = points_balance + ? WHERE user_id = ?");
                if (!$pointStmt) {
                    throw new RuntimeException('更新會員點數失敗。');
                }
                $pointStmt->bind_param('ii', $rewardPoints, $userId);
                if (!$pointStmt->execute()) {
                    throw new RuntimeException('更新會員點數失敗。');
                }
                $pointStmt->close();
            }

            $cartIds = array_map('intval', $selectedIds);
            if (!empty($cartIds)) {
                $cartIdList = implode(',', $cartIds);
                $conn->query("DELETE FROM cart_items WHERE user_id = {$userId} AND cart_item_id IN ({$cartIdList})");
            }

            $conn->commit();
            $checkoutDone = true;
            $conn->close();
            header('Location: order_success.php?order_number=' . urlencode($orderNumber));
            exit;
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('Checkout order creation failed for user_id ' . $userId . ': ' . $e->getMessage());
            $errors[] = '建立訂單失敗，請稍後再試。';
        }
    }
}

$memberFillData = [
    'recipient_name' => $user['name'],
    'recipient_phone' => $user['phone'],
    'shipping_address' => $memberDetail['full_address'],
    'address_note' => $memberDetail['address_note'],
    'cardholder_name' => $memberDetail['cardholder_name'] !== '' ? $memberDetail['cardholder_name'] : $user['name'],
    'card_brand' => $memberDetail['card_brand'],
    'expiry_month' => $memberDetail['expiry_month'],
    'expiry_year' => $memberDetail['expiry_year'],
];

$missingMemberFields = [];
if ($memberFillData['recipient_name'] === '') {
    $missingMemberFields[] = '姓名';
}
if ($memberFillData['recipient_phone'] === '') {
    $missingMemberFields[] = '電話';
}
if ($memberFillData['shipping_address'] === '') {
    $missingMemberFields[] = '地址';
}
if ($memberFillData['cardholder_name'] === '') {
    $missingMemberFields[] = '持卡人姓名';
}
if ($memberFillData['card_brand'] === '') {
    $missingMemberFields[] = '卡片品牌';
}
if ($memberFillData['expiry_month'] === '') {
    $missingMemberFields[] = '到期月';
}
if ($memberFillData['expiry_year'] === '') {
    $missingMemberFields[] = '到期年';
}

include 'header.php';
?>

<style>
    .coupon-option {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        padding: 14px 16px;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #fff;
        cursor: pointer;
        transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
    }
    .coupon-option.is-selected {
        border-color: #db6b6b;
        background: #fff7f7;
        box-shadow: 0 10px 24px rgba(219, 107, 107, 0.08);
    }
    .coupon-option input[type="radio"] {
        margin-top: 4px;
        transform: scale(1.05);
    }
    .coupon-option-main {
        flex: 1;
        min-width: 0;
    }
    .coupon-option-title {
        font-weight: 700;
        color: #111827;
        margin-bottom: 4px;
    }
    .coupon-option-sub {
        font-size: 13px;
        line-height: 1.6;
        color: #6b7280;
    }
    .coupon-option-meta {
        text-align: right;
        font-size: 13px;
        line-height: 1.6;
        color: #374151;
        white-space: nowrap;
    }
    .coupon-summary {
        margin-top: 14px;
        padding: 14px 16px;
        border-radius: 12px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
    }
    .coupon-summary-row {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 8px;
        font-size: 14px;
        color: #334155;
    }
    .coupon-summary-row:last-child {
        margin-bottom: 0;
    }
    .coupon-summary-total {
        font-size: 18px;
        font-weight: 800;
        color: #111827;
    }
</style>

<section style="padding:190px 5% 60px; max-width:1300px; margin:0 auto;">
    <a href="cart.php" style="color:#555; display:inline-block; margin-bottom:18px;">⬅️ 返回購物車</a>
    <h1 style="font-size:34px; margin-bottom:8px;">結帳</h1>
    <p style="color:#666; margin-bottom:22px;">確認勾選商品後，填入收件資訊與信用卡資料即可建立訂單。</p>

    <?php if (!empty($errors)): ?>
        <div style="margin-bottom:16px; padding:14px 16px; border-radius:12px; background:#fef2f2; color:#991b1b; border:1px solid #fca5a5; line-height:1.7;">
            <?php foreach ($errors as $error): ?>
                <div>• <?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (empty($items)): ?>
        <div style="background:#fff; border:1px solid #eee; border-radius:14px; padding:32px; text-align:center; color:#777;">
            你沒有選取任何購物車商品。
        </div>
    <?php else: ?>
        <form method="post" action="checkout.php" id="checkoutForm">
            <?php echo apCsrfField(); ?>
            <input type="hidden" name="action" value="place_order">

            <?php foreach ($items as $item): ?>
                <input type="hidden" name="selected[]" value="<?php echo intval($item['cart_item_id']); ?>">
                <input type="hidden" name="quantities[<?php echo intval($item['cart_item_id']); ?>]" value="<?php echo intval($item['quantity']); ?>">
            <?php endforeach; ?>

            <div style="display:grid; grid-template-columns:1.1fr 0.9fr; gap:18px; align-items:start;">
                <section style="background:#fff; border:1px solid #eee; border-radius:14px; overflow:auto;">
                    <div style="padding:18px 18px 0; font-size:20px; font-weight:700;">勾選商品</div>
                    <table style="width:100%; border-collapse:collapse; min-width:760px; margin-top:10px;">
                        <thead>
                            <tr style="background:#fafafa; border-bottom:1px solid #eee; text-align:left; color:#666; font-size:14px;">
                                <th style="padding:14px 12px; width:100px;">商品</th>
                                <th style="padding:14px 12px;">名稱 / 規格</th>
                                <th style="padding:14px 12px; width:140px;">單價</th>
                                <th style="padding:14px 12px; width:120px;">數量</th>
                                <th style="padding:14px 12px; width:140px;">小計</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <?php
                                $imageUrl = $item['image_url'] !== '' ? '../' . ltrim($item['image_url'], '/') : '';
                                $variantLabel = trim(($item['variant_size'] !== '' ? $item['variant_size'] . '吋' : '') . (($item['variant_color'] !== '' && $item['variant_size'] !== '') ? ' / ' : '') . ($item['variant_color'] !== '' ? $item['variant_color'] : ''));
                                ?>
                                <tr style="border-bottom:1px solid #f3f3f3; vertical-align:top;">
                                    <td style="padding:14px 12px;">
                                        <?php if ($imageUrl !== ''): ?>
                                            <img src="<?php echo htmlspecialchars($imageUrl); ?>" alt="<?php echo htmlspecialchars($item['product_name']); ?>" style="width:84px; height:84px; object-fit:cover; border-radius:10px; border:1px solid #eee;">
                                        <?php else: ?>
                                            <div style="width:84px; height:84px; border-radius:10px; border:1px solid #eee; background:#f7f7f7; display:flex; align-items:center; justify-content:center; color:#aaa; font-size:12px;">No Img</div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding:14px 12px;">
                                        <div style="font-weight:700; margin-bottom:6px; color:#222;"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                        <div style="font-size:13px; color:#777; line-height:1.7;">
                                            <?php if ($variantLabel !== ''): ?>
                                                <div>規格：<?php echo htmlspecialchars($variantLabel); ?></div>
                                            <?php endif; ?>
                                            <div>SKU：<?php echo htmlspecialchars($item['sku_code'] !== '' ? $item['sku_code'] : '-'); ?></div>
                                        </div>
                                    </td>
                                    <td style="padding:14px 12px; font-weight:700;">NT$ <?php echo number_format(floatval($item['display_price'])); ?></td>
                                    <td style="padding:14px 12px;">x<?php echo intval($item['quantity']); ?></td>
                                    <td style="padding:14px 12px; font-weight:700;">NT$ <?php echo number_format(floatval($item['subtotal'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div style="display:flex; justify-content:flex-end; padding:16px 18px 18px; font-size:18px; font-weight:700; color:#222;">
                        商品總額：NT$ <?php echo number_format($totalAmount); ?>
                    </div>
                </section>

                <section style="display:grid; gap:16px;">
                    <div style="background:#fff; border:1px solid #eee; border-radius:14px; padding:18px;">
                        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px; flex-wrap:wrap; margin-bottom:12px;">
                            <h2 style="font-size:20px; margin:0;">收件與付款資訊</h2>
                            <button type="button" id="fillMemberBtn" style="padding:10px 14px; border:none; border-radius:999px; background:#111; color:#fff; font-weight:700; cursor:pointer;">一鍵填入會員資訊</button>
                        </div>
                        <div id="fillNotice" style="display:none; margin-bottom:14px; padding:12px 14px; border-radius:10px; background:#f8fafc; color:#334155; border:1px solid #e2e8f0; line-height:1.7;"></div>

                        <div style="display:grid; gap:14px;">
                            <div>
                                <label style="display:block; font-weight:700; margin-bottom:6px;">收件人姓名</label>
                                <input type="text" name="recipient_name" id="recipient_name" value="<?php echo htmlspecialchars($formRecipientName); ?>" required style="width:100%; height:44px; padding:0 12px; border:1px solid #ddd; border-radius:10px;">
                            </div>
                            <div>
                                <label style="display:block; font-weight:700; margin-bottom:6px;">收件人電話</label>
                                <input type="text" name="recipient_phone" id="recipient_phone" value="<?php echo htmlspecialchars($formRecipientPhone); ?>" required style="width:100%; height:44px; padding:0 12px; border:1px solid #ddd; border-radius:10px;">
                            </div>
                            <div>
                                <label style="display:block; font-weight:700; margin-bottom:6px;">收件地址</label>
                                <input type="text" name="shipping_address" id="shipping_address" value="<?php echo htmlspecialchars($formShippingAddress); ?>" required style="width:100%; height:44px; padding:0 12px; border:1px solid #ddd; border-radius:10px;">
                            </div>
                            <div>
                                <label style="display:block; font-weight:700; margin-bottom:6px;">地址備註</label>
                                <input type="text" name="address_note" id="address_note" value="<?php echo htmlspecialchars($formAddressNote); ?>" style="width:100%; height:44px; padding:0 12px; border:1px solid #ddd; border-radius:10px;">
                            </div>
                            <div style="background:#fff; border:1px solid #eee; border-radius:14px; padding:18px;">
                                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px; flex-wrap:wrap; margin-bottom:12px;">
                                    <h3 style="font-size:18px; margin:0;">使用優惠卷</h3>
                                    <div style="font-size:13px; color:#6b7280;">選好後會即時顯示卷後價</div>
                                </div>

                                <?php if (!empty($availableCoupons)): ?>
                                    <div id="couponOptions" style="display:grid; gap:10px;">
                                        <label class="coupon-option <?php echo $formCouponId <= 0 ? 'is-selected' : ''; ?>">
                                            <input type="radio" name="coupon_id" value="" <?php echo $formCouponId <= 0 ? 'checked' : ''; ?>>
                                            <div class="coupon-option-main">
                                                <div class="coupon-option-title">不使用優惠卷</div>
                                                <div class="coupon-option-sub">保留原價結帳</div>
                                            </div>
                                        </label>

                                        <?php foreach ($availableCoupons as $coupon): ?>
                                            <?php
                                            $couponId = intval($coupon['coupon_id']);
                                            $couponType = $coupon['coupon_type'] ?? 'DISCOUNT';
                                            $couponValue = (float)($coupon['coupon_value'] ?? 0);
                                            $minOrderAmount = (float)($coupon['min_order_amount'] ?? 0);
                                            $availableQuantity = intval($coupon['available_quantity'] ?? 0);
                                            $valueLabel = $couponType === 'REDUCE'
                                                ? '折抵 NT$ ' . number_format($couponValue)
                                                : ($couponType === 'POINTS' ? number_format($couponValue) . ' 點' : rtrim(rtrim(number_format($couponValue, 2), '0'), '.') . '% 折扣');
                                            $couponTitle = trim(($coupon['coupon_name'] ?? '未命名優惠卷') . (!empty($coupon['coupon_code']) ? '（' . $coupon['coupon_code'] . '）' : ''));
                                            $selectedClass = $formCouponId === $couponId ? 'is-selected' : '';
                                        ?>
                                            <label class="coupon-option <?php echo $selectedClass; ?>">
                                                <input type="radio"
                                                       name="coupon_id"
                                                       value="<?php echo $couponId; ?>"
                                                       <?php echo $formCouponId === $couponId ? 'checked' : ''; ?>
                                                       data-coupon-type="<?php echo htmlspecialchars($couponType); ?>"
                                                       data-coupon-value="<?php echo htmlspecialchars((string)$couponValue); ?>"
                                                       data-min-order="<?php echo htmlspecialchars((string)$minOrderAmount); ?>">
                                                <div class="coupon-option-main">
                                                    <div class="coupon-option-title"><?php echo htmlspecialchars($couponTitle); ?></div>
                                                    <div class="coupon-option-sub"><?php echo htmlspecialchars($valueLabel); ?></div>
                                                </div>
                                                <div class="coupon-option-meta">
                                                    <div>可用 <?php echo number_format($availableQuantity); ?> 張</div>
                                                    <div>門檻 NT$ <?php echo number_format($minOrderAmount); ?></div>
                                                </div>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>

                                    <div class="coupon-summary">
                                        <div class="coupon-summary-row"><span>商品總額</span><strong id="couponBaseAmount">NT$ <?php echo number_format((float)$totalAmount); ?></strong></div>
                                        <div class="coupon-summary-row"><span>優惠折扣</span><strong id="couponDiscountAmount">NT$ 0</strong></div>
                                        <div class="coupon-summary-row coupon-summary-total"><span>卷後價</span><strong id="couponFinalAmount">NT$ <?php echo number_format((float)$totalAmount); ?></strong></div>
                                    </div>
                                <?php else: ?>
                                    <div style="padding:14px 16px; border-radius:12px; background:#f8fafc; border:1px solid #e2e8f0; color:#64748b; line-height:1.7;">
                                        目前沒有可用的優惠卷。
                                    </div>
                                    <div class="coupon-summary">
                                        <div class="coupon-summary-row"><span>商品總額</span><strong id="couponBaseAmount">NT$ <?php echo number_format((float)$totalAmount); ?></strong></div>
                                        <div class="coupon-summary-row"><span>優惠折扣</span><strong id="couponDiscountAmount">NT$ 0</strong></div>
                                        <div class="coupon-summary-row coupon-summary-total"><span>卷後價</span><strong id="couponFinalAmount">NT$ <?php echo number_format((float)$totalAmount); ?></strong></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <label style="display:block; font-weight:700; margin-bottom:6px;">付款方式</label>
                                <select name="payment_method" id="payment_method" style="width:100%; height:44px; padding:0 12px; border:1px solid #ddd; border-radius:10px; background:#fff;">
                                    <option value="credit_card" <?php echo $formPaymentMethod === 'credit_card' ? 'selected' : ''; ?>>信用卡</option>
                                </select>
                            </div>
                            <div>
                                <label style="display:block; font-weight:700; margin-bottom:6px;">持卡人姓名</label>
                                <input type="text" name="cardholder_name" id="cardholder_name" value="<?php echo htmlspecialchars($formCardholderName); ?>" required style="width:100%; height:44px; padding:0 12px; border:1px solid #ddd; border-radius:10px;">
                            </div>
                            <div>
                                <label style="display:block; font-weight:700; margin-bottom:6px;">信用卡品牌</label>
                                <select name="card_brand" id="card_brand" required style="width:100%; height:44px; padding:0 12px; border:1px solid #ddd; border-radius:10px; background:#fff;">
                                    <?php
                                    $brands = ['' => '請選擇', 'Visa' => 'Visa', 'MasterCard' => 'MasterCard', 'JCB' => 'JCB', 'American Express' => 'American Express'];
                                    foreach ($brands as $value => $label) {
                                        $selected = $formCardBrand === $value ? 'selected' : '';
                                        echo '<option value="' . htmlspecialchars($value) . '" ' . $selected . '>' . htmlspecialchars($label) . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                            <div>
                                <label style="display:block; font-weight:700; margin-bottom:6px;">信用卡號</label>
                                <input type="text" name="card_number" id="card_number" value="<?php echo htmlspecialchars($formCardNumber); ?>" required autocomplete="off" inputmode="numeric" placeholder="請輸入完整卡號" style="width:100%; height:44px; padding:0 12px; border:1px solid #ddd; border-radius:10px;">
                            </div>
                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                                <div>
                                    <label style="display:block; font-weight:700; margin-bottom:6px;">到期月</label>
                                    <input type="text" name="expiry_month" id="expiry_month" value="<?php echo htmlspecialchars($formExpiryMonth); ?>" required maxlength="2" placeholder="MM" style="width:100%; height:44px; padding:0 12px; border:1px solid #ddd; border-radius:10px;">
                                </div>
                                <div>
                                    <label style="display:block; font-weight:700; margin-bottom:6px;">到期年</label>
                                    <input type="text" name="expiry_year" id="expiry_year" value="<?php echo htmlspecialchars($formExpiryYear); ?>" required maxlength="4" placeholder="YYYY" style="width:100%; height:44px; padding:0 12px; border:1px solid #ddd; border-radius:10px;">
                                </div>
                            </div>
                            <div>
                                <label style="display:block; font-weight:700; margin-bottom:6px;">訂單備註</label>
                                <textarea name="note" rows="4" style="width:100%; padding:12px; border:1px solid #ddd; border-radius:10px; resize:vertical;"><?php echo htmlspecialchars($formNote); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div style="background:#fff; border:1px solid #eee; border-radius:14px; padding:18px;">
                        <h2 style="font-size:20px; margin-bottom:12px;">結帳確認</h2>
                        <div style="line-height:1.8; color:#444; font-size:14px; margin-bottom:12px;">
                            <div><strong>收件人：</strong><?php echo htmlspecialchars($formRecipientName); ?></div>
                            <div><strong>電話：</strong><?php echo htmlspecialchars($formRecipientPhone); ?></div>
                            <div><strong>地址：</strong><?php echo htmlspecialchars($formShippingAddress); ?></div>
                            <div><strong>卡片末 4 碼：</strong><?php echo htmlspecialchars($memberDetail['card_last4'] !== '' ? '****' . $memberDetail['card_last4'] : '尚未填入'); ?></div>
                        </div>
                        <div style="display:flex; gap:10px; flex-wrap:wrap; justify-content:flex-end;">
                            <a href="cart.php" style="display:inline-flex; align-items:center; justify-content:center; padding:12px 18px; border-radius:999px; background:#111; color:#fff; font-weight:700;">回購物車修改</a>
                            <button type="submit" style="padding:12px 18px; border:none; border-radius:999px; background:#db6b6b; color:#fff; font-weight:700; cursor:pointer;">確認結帳</button>
                        </div>
                    </div>
                </section>
            </div>
        </form>
    <?php endif; ?>
</section>

<script>
(function () {
    const subtotal = <?php echo json_encode((float)$totalAmount, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const baseAmountEl = document.getElementById('couponBaseAmount');
    const discountAmountEl = document.getElementById('couponDiscountAmount');
    const finalAmountEl = document.getElementById('couponFinalAmount');
    const couponCards = Array.from(document.querySelectorAll('.coupon-option'));
    const couponRadios = Array.from(document.querySelectorAll('input[name="coupon_id"]'));

    function formatAmount(value) {
        return 'NT$ ' + Math.max(0, Number(value) || 0).toLocaleString('zh-TW', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2
        });
    }

    function calculateDiscount(radio) {
        if (!radio || radio.value === '') {
            return 0;
        }

        const couponType = radio.dataset.couponType || '';
        const couponValue = parseFloat(radio.dataset.couponValue || '0');
        const minOrder = parseFloat(radio.dataset.minOrder || '0');

        if (!Number.isFinite(couponValue) || couponValue <= 0 || subtotal < minOrder) {
            return 0;
        }

        if (couponType === 'DISCOUNT') {
            return Math.max(0, Math.round((subtotal * couponValue / 100) * 100) / 100);
        }

        if (couponType === 'REDUCE') {
            return Math.max(0, Math.round(couponValue * 100) / 100);
        }

        return 0;
    }

    function syncCouponPreview() {
        const selectedRadio = couponRadios.find((radio) => radio.checked) || null;
        const discount = calculateDiscount(selectedRadio);
        const finalAmount = Math.max(0, Math.round((subtotal - discount) * 100) / 100);

        if (baseAmountEl) {
            baseAmountEl.textContent = formatAmount(subtotal);
        }
        if (discountAmountEl) {
            discountAmountEl.textContent = formatAmount(discount);
        }
        if (finalAmountEl) {
            finalAmountEl.textContent = formatAmount(finalAmount);
        }

        couponCards.forEach((card) => {
            const radio = card.querySelector('input[type="radio"]');
            card.classList.toggle('is-selected', !!radio && radio.checked);
        });
    }

    couponRadios.forEach((radio) => {
        radio.addEventListener('change', syncCouponPreview);
    });

    syncCouponPreview();
})();

(function () {
    const fillButton = document.getElementById('fillMemberBtn');
    const notice = document.getElementById('fillNotice');
    if (!fillButton || !notice) {
        return;
    }

    const memberData = <?php echo json_encode($memberFillData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const missingFields = <?php echo json_encode($missingMemberFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    function setValue(id, value) {
        const element = document.getElementById(id);
        if (element && value) {
            element.value = value;
        }
    }

    fillButton.addEventListener('click', function () {
        setValue('recipient_name', memberData.recipient_name);
        setValue('recipient_phone', memberData.recipient_phone);
        setValue('shipping_address', memberData.shipping_address);
        setValue('address_note', memberData.address_note);
        setValue('cardholder_name', memberData.cardholder_name);
        setValue('card_brand', memberData.card_brand);
        setValue('expiry_month', memberData.expiry_month);
        setValue('expiry_year', memberData.expiry_year);

        const notes = [];
        if (missingFields.length > 0) {
            notes.push('以下會員資料尚未完整：' + missingFields.join('、') + '。');
        }
        notes.push('信用卡號不會自動填入，請手動輸入完整卡號。');
        notice.innerHTML = notes.map(function (item) {
            return '<div>• ' + item + '</div>';
        }).join('');
        notice.style.display = 'block';
    });
})();
</script>

<?php include 'footer.php'; $conn->close(); ?>

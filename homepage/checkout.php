<?php
$pageTitle = '結帳 | All Pass';
$activeNav = '';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = intval($_SESSION['user_id']);
$conn = new mysqli('localhost', 'root', '', 'all_pass_db');
if ($conn->connect_error) {
    die('資料庫連線失敗: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');

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

function checkoutFetchRows($conn, $sql) {
    $rows = [];
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function checkoutFetchRow($conn, $sql) {
    $res = $conn->query($sql);
    if ($res && $res->num_rows > 0) {
        return $res->fetch_assoc();
    }
    return null;
}

function checkoutGenerateOrderNumber() {
    try {
        $suffix = strtoupper(bin2hex(random_bytes(2)));
    } catch (Throwable $e) {
        $suffix = strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 4));
    }
    return 'AP' . date('YmdHis') . $suffix;
}

function checkoutEnsureOrderColumns($conn) {
    if (!checkoutTableExists($conn, 'orders')) {
        return;
    }

    $checks = [
        'cardholder_name' => "ALTER TABLE `orders` ADD COLUMN `cardholder_name` VARCHAR(100) NULL AFTER `payment_method`",
        'card_expiry_month' => "ALTER TABLE `orders` ADD COLUMN `card_expiry_month` VARCHAR(2) NULL AFTER `card_last4`",
        'card_expiry_year' => "ALTER TABLE `orders` ADD COLUMN `card_expiry_year` VARCHAR(4) NULL AFTER `card_expiry_month`",
    ];

    foreach ($checks as $column => $sql) {
        $exists = false;
        $res = $conn->query("SHOW COLUMNS FROM `orders` LIKE '{$column}'");
        if ($res && $res->num_rows > 0) {
            $exists = true;
        }
        if (!$exists) {
            $conn->query($sql);
        }
    }
}

checkoutEnsureOrderColumns($conn);

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
            COALESCE((
                SELECT pi.image_url
                FROM product_images pi
                WHERE pi.product_id = p.product_id
                ORDER BY {$imageOrder}
                LIMIT 1
            ), '') AS image_url,
            COALESCE((
                SELECT MIN(COALESCE(pv.special_price, pv.original_price))
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

    $displayPrice = ($item['special_price'] !== null && $item['special_price'] !== '') ? floatval($item['special_price']) : floatval($item['original_price']);
    if ($displayPrice <= 0) {
        $displayPrice = floatval($item['fallback_price']);
    }
    $item['display_price'] = $displayPrice;
    $item['subtotal'] = $displayPrice * intval($item['quantity']);
    $totalAmount += $item['subtotal'];
}
unset($item);

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'place_order') {
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
        $grandTotal = $totalAmount + $shippingFee;
        $paymentMethod = 'credit_card';
        $formCardLast4 = substr($cardDigits, -4);

        $conn->begin_transaction();
        try {
            $orderNumber = checkoutGenerateOrderNumber();
            $orderStatus = 'PENDING';

            $orderSql = "INSERT INTO orders (
                order_number,
                user_id,
                status,
                subtotal_amount,
                shipping_fee,
                total_amount,
                recipient_name,
                recipient_phone,
                shipping_address,
                payment_method,
                cardholder_name,
                card_brand,
                card_last4,
                card_expiry_month,
                card_expiry_year,
                note
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $orderStmt = $conn->prepare($orderSql);
            if (!$orderStmt) {
                throw new RuntimeException('無法建立訂單語句。');
            }
            $orderStmt->bind_param(
                'sisdddssssssssss',
                $orderNumber,
                $userId,
                $orderStatus,
                $totalAmount,
                $shippingFee,
                $grandTotal,
                $formRecipientName,
                $formRecipientPhone,
                $formShippingAddress,
                $paymentMethod,
                $formCardholderName,
                $formCardBrand,
                $formCardLast4,
                $formExpiryMonth,
                $formExpiryYear,
                $formNote
            );
            if (!$orderStmt->execute()) {
                throw new RuntimeException('訂單寫入失敗。');
            }
            $orderId = intval($conn->insert_id);
            $orderStmt->close();

            $itemSqlWithVariant = "INSERT INTO order_items (
                order_id,
                product_id,
                variant_id,
                product_name,
                variant_name,
                quantity,
                unit_price,
                subtotal_amount
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $itemSqlWithoutVariant = "INSERT INTO order_items (
                order_id,
                product_id,
                product_name,
                variant_name,
                quantity,
                unit_price,
                subtotal_amount
            ) VALUES (?, ?, ?, ?, ?, ?, ?)";

            foreach ($items as $item) {
                $quantity = intval($item['quantity']);
                $unitPrice = floatval($item['display_price']);
                $subtotal = $unitPrice * $quantity;
                $variantName = trim(($item['variant_size'] !== '' ? $item['variant_size'] . '吋' : '') . (($item['variant_color'] !== '' && $item['variant_size'] !== '') ? ' / ' : '') . ($item['variant_color'] !== '' ? $item['variant_color'] : ''));
                $variantId = intval($item['variant_id']);

                if ($variantId > 0) {
                    $itemStmt = $conn->prepare($itemSqlWithVariant);
                    if (!$itemStmt) {
                        throw new RuntimeException('無法建立訂單明細語句。');
                    }
                    $productId = intval($item['product_id']);
                    $itemStmt->bind_param(
                        'iiissidd',
                        $orderId,
                        $productId,
                        $variantId,
                        $item['product_name'],
                        $variantName,
                        $quantity,
                        $unitPrice,
                        $subtotal
                    );
                } else {
                    $itemStmt = $conn->prepare($itemSqlWithoutVariant);
                    if (!$itemStmt) {
                        throw new RuntimeException('無法建立訂單明細語句。');
                    }
                    $productId = intval($item['product_id']);
                    $itemStmt->bind_param(
                        'iissidd',
                        $orderId,
                        $productId,
                        $item['product_name'],
                        $variantName,
                        $quantity,
                        $unitPrice,
                        $subtotal
                    );
                }

                if (!$itemStmt->execute()) {
                    throw new RuntimeException('訂單明細寫入失敗。');
                }
                $itemStmt->close();
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
            $errors[] = '建立訂單失敗，請稍後再試。';
            $errors[] = $e->getMessage();
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

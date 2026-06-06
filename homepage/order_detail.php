<?php
$pageTitle = '訂單明細 | All Pass';
$activeNav = '';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/security.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = intval($_SESSION['user_id']);
$orderNumber = isset($_GET['order_number']) ? trim($_GET['order_number']) : '';

$orderStatusLabels = [
    'PENDING' => '待處理',
    'PROCESSING' => '處理中',
    'SHIPPED' => '已出貨',
    'DELIVERED' => '已送達',
    'COMPLETED' => '已完成',
    'CANCELLED' => '已取消',
];

if ($orderNumber === '') {
    header('Location: profile.php#order-history');
    exit;
}

$conn = new mysqli('localhost', 'root', '', 'all_pass_db');
if ($conn->connect_error) {
    error_log('Order detail database connection failed: ' . $conn->connect_error);
    http_response_code(500);
    echo '系統暫時無法連線資料庫，請稍後再試。';
    exit;
}
$conn->set_charset('utf8mb4');

function odTableExists($conn, $tableName) {
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return ($res && $res->num_rows > 0);
}

function odFetchRow($conn, $sql) {
    $res = $conn->query($sql);
    if ($res && $res->num_rows > 0) {
        return $res->fetch_assoc();
    }
    return null;
}

function odFetchRows($conn, $sql) {
    $rows = [];
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function odTableColumns($conn, $tableName) {
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

$order = null;
$orderItems = [];
$returnNotice = '';
$returnNoticeType = 'success';
$returnRequest = null;

if (odTableExists($conn, 'orders')) {
    $safeOrderNumber = $conn->real_escape_string($orderNumber);
    $orderSql = "
        SELECT o.*, c.coupon_name, c.coupon_code
        FROM orders o
        LEFT JOIN coupons c ON c.coupon_id = o.coupon_id
        WHERE o.order_number = '{$safeOrderNumber}' AND o.user_id = {$userId}
        LIMIT 1
    ";
    $order = odFetchRow($conn, $orderSql);
}

if ($order && odTableExists($conn, 'order_items')) {
    $orderItems = odFetchRows($conn, "SELECT * FROM order_items WHERE order_id = " . intval($order['order_id']) . " ORDER BY order_item_id ASC");
}

$orderItemColumns = $orderItems ? odTableColumns($conn, 'order_items') : [];
$hasUnitPrice = in_array('unit_price', $orderItemColumns, true);
$hasSubtotalAmount = in_array('subtotal_amount', $orderItemColumns, true);
$hasLockedPrice = in_array('locked_price', $orderItemColumns, true);
$hasVariantName = in_array('variant_name', $orderItemColumns, true);

if ($order && odTableExists($conn, 'return_requests')) {
    $orderId = (int)$order['order_id'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_return') {
        if (!apValidateCsrf($_POST['csrf_token'] ?? null)) {
            $returnNotice = '表單驗證失敗，請重新操作。';
            $returnNoticeType = 'error';
        } elseif (!in_array((string)$order['status'], ['DELIVERED', 'COMPLETED'], true)) {
            $returnNotice = '只有已送達或已完成的訂單可以申請退貨。';
            $returnNoticeType = 'error';
        } else {
            $existingStmt = $conn->prepare("SELECT return_id FROM return_requests WHERE order_id = ? AND status IN ('PENDING', 'APPROVED') LIMIT 1");
            $existingReturnId = 0;
            if ($existingStmt) {
                $existingStmt->bind_param('i', $orderId);
                $existingStmt->execute();
                $existingRow = $existingStmt->get_result()->fetch_assoc();
                $existingReturnId = $existingRow ? (int)$existingRow['return_id'] : 0;
                $existingStmt->close();
            }

            if ($existingReturnId > 0) {
                $returnNotice = '此訂單已有處理中的退貨申請。';
                $returnNoticeType = 'error';
            } else {
                $reason = trim((string)($_POST['reason'] ?? ''));
                if ($reason === '') {
                    $returnNotice = '請填寫退貨原因。';
                    $returnNoticeType = 'error';
                } else {
                    $insertStmt = $conn->prepare(
                        "INSERT INTO return_requests (order_id, user_id, reason, status, created_at, updated_at)
                         VALUES (?, ?, ?, 'PENDING', NOW(), NOW())"
                    );
                    if ($insertStmt) {
                        $insertStmt->bind_param('iis', $orderId, $userId, $reason);
                        if ($insertStmt->execute()) {
                            $returnNotice = '退貨申請已送出，客服將於後台審核。';
                            $returnNoticeType = 'success';
                        } else {
                            $returnNotice = '退貨申請送出失敗，請稍後再試。';
                            $returnNoticeType = 'error';
                        }
                        $insertStmt->close();
                    }
                }
            }
        }
    }

    $returnStmt = $conn->prepare('SELECT * FROM return_requests WHERE order_id = ? ORDER BY created_at DESC, return_id DESC LIMIT 1');
    if ($returnStmt) {
        $returnStmt->bind_param('i', $orderId);
        $returnStmt->execute();
        $returnRequest = $returnStmt->get_result()->fetch_assoc();
        $returnStmt->close();
    }
}

include 'header.php';
?>

<section style="padding:190px 5% 60px; max-width:1100px; margin:0 auto;">
    <div style="display:flex; justify-content:space-between; align-items:flex-end; gap:16px; flex-wrap:wrap; margin-bottom:20px;">
        <div>
            <h1 style="font-size:34px; margin-bottom:8px;">訂單明細</h1>
            <p style="color:#666;">檢視這張訂單的收件資訊與商品內容。</p>
        </div>
        <a href="profile.php#order-history" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 16px; border-radius:999px; background:#111; color:#fff; font-weight:700;">回購買紀錄</a>
    </div>

    <?php if (!$order): ?>
        <div style="background:#fff; border:1px solid #eee; border-radius:14px; padding:32px; text-align:center; color:#777;">
            找不到對應的訂單資料。
        </div>
    <?php else: ?>
        <div style="display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:14px; margin-bottom:22px;">
            <div style="padding:16px; border-radius:12px; background:#fff; border:1px solid #eee;">
                <div style="font-size:13px; color:#666; margin-bottom:6px;">訂單編號</div>
                <div style="font-size:22px; font-weight:800; color:#222;"><?php echo htmlspecialchars($order['order_number']); ?></div>
            </div>
            <div style="padding:16px; border-radius:12px; background:#fff; border:1px solid #eee;">
                <div style="font-size:13px; color:#666; margin-bottom:6px;">訂單狀態</div>
                <div style="font-size:22px; font-weight:800; color:#222;"><?php echo htmlspecialchars($orderStatusLabels[$order['status']] ?? $order['status']); ?></div>
            </div>
            <div style="padding:16px; border-radius:12px; background:#fff; border:1px solid #eee;">
                <div style="font-size:13px; color:#666; margin-bottom:6px;">收件人</div>
                <div style="font-size:20px; font-weight:700; color:#222;"><?php echo htmlspecialchars($order['recipient_name']); ?></div>
            </div>
            <div style="padding:16px; border-radius:12px; background:#fff; border:1px solid #eee;">
                <div style="font-size:13px; color:#666; margin-bottom:6px;">應付總額</div>
                <div style="font-size:22px; font-weight:800; color:#222;">NT$ <?php echo number_format(floatval($order['total_amount'])); ?></div>
            </div>
            <div style="padding:16px; border-radius:12px; background:#fff; border:1px solid #eee;">
                <div style="font-size:13px; color:#666; margin-bottom:6px;">優惠卷使用</div>
                <div style="font-size:20px; font-weight:700; color:#222;">
                    <?php echo !empty($order['coupon_id']) ? htmlspecialchars(($order['coupon_name'] ?? ('#' . $order['coupon_id'])) . (!empty($order['coupon_code']) ? '（' . $order['coupon_code'] . '）' : '')) : '未使用'; ?>
                </div>
            </div>
        </div>

        <div style="background:#fff; border:1px solid #eee; border-radius:14px; padding:18px; margin-bottom:18px; line-height:1.9; color:#444;">
            <div><strong>收件電話：</strong><?php echo htmlspecialchars($order['recipient_phone']); ?></div>
            <div><strong>收件地址：</strong><?php echo htmlspecialchars($order['shipping_address']); ?></div>
            <div><strong>付款方式：</strong><?php echo htmlspecialchars($order['payment_method']); ?></div>
            <div><strong>卡片品牌：</strong><?php echo htmlspecialchars($order['card_brand']); ?></div>
            <div><strong>卡號末 4 碼：</strong><?php echo htmlspecialchars($order['card_last4'] !== '' ? '****' . $order['card_last4'] : '-'); ?></div>
            <div><strong>下單時間：</strong><?php echo htmlspecialchars($order['created_at']); ?></div>
        </div>

        <?php
        $discountAmount = isset($order['discount_amount']) ? (float)$order['discount_amount'] : 0.0;
        $subtotalAmount = isset($order['subtotal_amount']) ? (float)$order['subtotal_amount'] : 0.0;
        $shippingFee = isset($order['shipping_fee']) ? (float)$order['shipping_fee'] : 0.0;
        $discountedTotal = isset($order['total_amount']) ? (float)$order['total_amount'] : max(0, $subtotalAmount + $shippingFee - $discountAmount);
        ?>

        <div style="background:#fff; border:1px solid #eee; border-radius:14px; padding:18px; margin-bottom:18px; line-height:1.9; color:#444;">
            <div><strong>商品小計：</strong>NT$ <?php echo number_format($subtotalAmount); ?></div>
            <div><strong>運費：</strong>NT$ <?php echo number_format($shippingFee); ?></div>
            <div><strong>優惠折扣：</strong>NT$ <?php echo number_format($discountAmount); ?></div>
            <div><strong>優惠後總額：</strong>NT$ <?php echo number_format($discountedTotal); ?></div>
        </div>

        <?php if (odTableExists($conn, 'return_requests')): ?>
            <div style="background:#fff; border:1px solid #eee; border-radius:14px; padding:18px; margin-bottom:18px; line-height:1.8; color:#444;">
                <h2 style="font-size:20px; margin:0 0 12px; color:#222;">退貨申請</h2>
                <?php if ($returnNotice !== ''): ?>
                    <div style="padding:10px 12px; border-radius:8px; margin-bottom:12px; background:<?php echo $returnNoticeType === 'success' ? '#f0fdf4' : '#fef2f2'; ?>; color:<?php echo $returnNoticeType === 'success' ? '#166534' : '#991b1b'; ?>;">
                        <?php echo htmlspecialchars($returnNotice); ?>
                    </div>
                <?php endif; ?>

                <?php if ($returnRequest): ?>
                    <div style="display:grid; gap:8px;">
                        <div><strong>申請狀態：</strong><?php echo htmlspecialchars($returnRequest['status']); ?></div>
                        <div><strong>申請時間：</strong><?php echo htmlspecialchars($returnRequest['created_at']); ?></div>
                        <div><strong>退貨原因：</strong><?php echo nl2br(htmlspecialchars($returnRequest['reason'])); ?></div>
                        <?php if (!empty($returnRequest['admin_note'])): ?>
                            <div><strong>審核備註：</strong><?php echo nl2br(htmlspecialchars($returnRequest['admin_note'])); ?></div>
                        <?php endif; ?>
                    </div>
                <?php elseif (in_array((string)$order['status'], ['DELIVERED', 'COMPLETED'], true)): ?>
                    <form method="post" style="display:grid; gap:12px;">
                        <?php echo apCsrfField(); ?>
                        <input type="hidden" name="action" value="request_return">
                        <label style="display:grid; gap:6px; font-weight:700;">
                            退貨原因
                            <textarea name="reason" rows="4" maxlength="1000" required style="width:100%; border:1px solid #ddd; border-radius:8px; padding:10px; font:inherit;" placeholder="請描述退貨原因、商品狀況或需要客服協助的內容"></textarea>
                        </label>
                        <button type="submit" style="justify-self:start; border:0; border-radius:999px; padding:10px 18px; background:#111; color:#fff; font-weight:800; cursor:pointer;">送出退貨申請</button>
                    </form>
                <?php else: ?>
                    <p style="margin:0; color:#666;">訂單送達或完成後即可在這裡申請退貨。</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div style="overflow:auto; border:1px solid #eee; border-radius:14px;">
            <table style="width:100%; border-collapse:collapse; min-width:760px;">
                <thead>
                    <tr style="background:#fafafa; border-bottom:1px solid #eee; text-align:left; color:#666; font-size:14px;">
                        <th style="padding:14px 12px;">商品名稱</th>
                        <th style="padding:14px 12px;">規格</th>
                        <th style="padding:14px 12px; width:120px;">單價</th>
                        <th style="padding:14px 12px; width:120px;">數量</th>
                        <th style="padding:14px 12px; width:140px;">小計</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orderItems as $item): ?>
                        <?php
                        $displayUnitPrice = 0.0;
                        if ($hasUnitPrice && isset($item['unit_price']) && $item['unit_price'] !== null && $item['unit_price'] !== '') {
                            $displayUnitPrice = (float)$item['unit_price'];
                        } elseif ($hasLockedPrice && isset($item['locked_price']) && $item['locked_price'] !== null && $item['locked_price'] !== '') {
                            $displayUnitPrice = (float)$item['locked_price'];
                        }

                        $displaySubtotal = 0.0;
                        if ($hasSubtotalAmount && isset($item['subtotal_amount']) && $item['subtotal_amount'] !== null && $item['subtotal_amount'] !== '') {
                            $displaySubtotal = (float)$item['subtotal_amount'];
                        } else {
                            $displaySubtotal = $displayUnitPrice * (int)$item['quantity'];
                        }

                        $variantText = '';
                        if ($hasVariantName && isset($item['variant_name']) && trim((string)$item['variant_name']) !== '') {
                            $variantText = trim((string)$item['variant_name']);
                        } else {
                            $sizeText = trim((string)($item['size_inches'] ?? ''));
                            $colorText = trim((string)($item['color'] ?? ''));
                            $variantText = trim($sizeText . ($sizeText !== '' && $colorText !== '' ? ' / ' : '') . $colorText);
                        }
                        ?>
                        <tr style="border-bottom:1px solid #f3f3f3;">
                            <td style="padding:14px 12px;"><?php echo htmlspecialchars($item['product_name']); ?></td>
                            <td style="padding:14px 12px;"><?php echo htmlspecialchars($variantText !== '' ? $variantText : '-'); ?></td>
                            <td style="padding:14px 12px;">NT$ <?php echo number_format($displayUnitPrice); ?></td>
                            <td style="padding:14px 12px;"><?php echo intval($item['quantity']); ?></td>
                            <td style="padding:14px 12px; font-weight:700;">NT$ <?php echo number_format($displaySubtotal); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php include 'footer.php'; $conn->close(); ?>

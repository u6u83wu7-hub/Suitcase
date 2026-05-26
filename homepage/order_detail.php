<?php
$pageTitle = '訂單明細 | All Pass';
$activeNav = '';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = intval($_SESSION['user_id']);
$orderNumber = isset($_GET['order_number']) ? trim($_GET['order_number']) : '';

if ($orderNumber === '') {
    header('Location: profile.php#order-history');
    exit;
}

$conn = new mysqli('localhost', 'root', '', 'all_pass_db');
if ($conn->connect_error) {
    die('資料庫連線失敗: ' . $conn->connect_error);
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

$order = null;
$orderItems = [];

if (odTableExists($conn, 'orders')) {
    $safeOrderNumber = $conn->real_escape_string($orderNumber);
    $order = odFetchRow($conn, "SELECT * FROM orders WHERE order_number = '{$safeOrderNumber}' AND user_id = {$userId} LIMIT 1");
}

if ($order && odTableExists($conn, 'order_items')) {
    $orderItems = odFetchRows($conn, "SELECT * FROM order_items WHERE order_id = " . intval($order['order_id']) . " ORDER BY order_item_id ASC");
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
                <div style="font-size:22px; font-weight:800; color:#222;"><?php echo htmlspecialchars($order['status']); ?></div>
            </div>
            <div style="padding:16px; border-radius:12px; background:#fff; border:1px solid #eee;">
                <div style="font-size:13px; color:#666; margin-bottom:6px;">收件人</div>
                <div style="font-size:20px; font-weight:700; color:#222;"><?php echo htmlspecialchars($order['recipient_name']); ?></div>
            </div>
            <div style="padding:16px; border-radius:12px; background:#fff; border:1px solid #eee;">
                <div style="font-size:13px; color:#666; margin-bottom:6px;">應付總額</div>
                <div style="font-size:22px; font-weight:800; color:#222;">NT$ <?php echo number_format(floatval($order['total_amount'])); ?></div>
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
                        <tr style="border-bottom:1px solid #f3f3f3;">
                            <td style="padding:14px 12px;"><?php echo htmlspecialchars($item['product_name']); ?></td>
                            <td style="padding:14px 12px;"><?php echo htmlspecialchars($item['variant_name'] !== '' ? $item['variant_name'] : '-'); ?></td>
                            <td style="padding:14px 12px;">NT$ <?php echo number_format(floatval($item['unit_price'])); ?></td>
                            <td style="padding:14px 12px;"><?php echo intval($item['quantity']); ?></td>
                            <td style="padding:14px 12px; font-weight:700;">NT$ <?php echo number_format(floatval($item['subtotal_amount'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php include 'footer.php'; $conn->close(); ?>
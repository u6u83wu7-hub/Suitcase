<?php
$pageTitle = '訂單成立 | All Pass';
$activeNav = '';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$orderNumber = isset($_GET['order_number']) ? trim($_GET['order_number']) : '';
if ($orderNumber === '') {
    header('Location: profile.php');
    exit;
}

$conn = new mysqli('localhost', 'root', '', 'all_pass_db');
if ($conn->connect_error) {
    die('資料庫連線失敗: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');

function successTableExists($conn, $tableName) {
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return ($res && $res->num_rows > 0);
}

function successFetchRow($conn, $sql) {
    $res = $conn->query($sql);
    if ($res && $res->num_rows > 0) {
        return $res->fetch_assoc();
    }
    return null;
}

function successFetchRows($conn, $sql) {
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
if (successTableExists($conn, 'orders')) {
    $order = successFetchRow($conn, "SELECT * FROM orders WHERE order_number = '" . $conn->real_escape_string($orderNumber) . "' LIMIT 1");
}
if ($order && successTableExists($conn, 'order_items')) {
    $orderItems = successFetchRows($conn, "SELECT * FROM order_items WHERE order_id = " . intval($order['order_id']) . " ORDER BY order_item_id ASC");
}

include 'header.php';
?>

<section style="padding:190px 5% 60px; max-width:1100px; margin:0 auto;">
    <div style="background:#fff; border:1px solid #eee; border-radius:16px; padding:28px;">
        <h1 style="font-size:34px; margin-bottom:10px;">訂單已成立</h1>
        <p style="color:#666; margin-bottom:18px;">你的訂單已成功建立，請保留訂單編號以便後續查詢。</p>

        <?php if (!$order): ?>
            <div style="padding:16px; border-radius:12px; background:#fef2f2; color:#991b1b; border:1px solid #fca5a5;">
                找不到對應的訂單資料。
            </div>
        <?php else: ?>
            <div style="display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:14px; margin-bottom:22px;">
                <div style="padding:16px; border-radius:12px; background:#fafafa; border:1px solid #eee;">
                    <div style="font-size:13px; color:#666; margin-bottom:6px;">訂單編號</div>
                    <div style="font-size:22px; font-weight:800; color:#222;"><?php echo htmlspecialchars($order['order_number']); ?></div>
                </div>
                <div style="padding:16px; border-radius:12px; background:#fafafa; border:1px solid #eee;">
                    <div style="font-size:13px; color:#666; margin-bottom:6px;">訂單狀態</div>
                    <div style="font-size:22px; font-weight:800; color:#222;"><?php echo htmlspecialchars($order['status']); ?></div>
                </div>
                <div style="padding:16px; border-radius:12px; background:#fafafa; border:1px solid #eee;">
                    <div style="font-size:13px; color:#666; margin-bottom:6px;">應付總額</div>
                    <div style="font-size:22px; font-weight:800; color:#222;">NT$ <?php echo number_format(floatval($order['total_amount'])); ?></div>
                </div>
                <div style="padding:16px; border-radius:12px; background:#fafafa; border:1px solid #eee;">
                    <div style="font-size:13px; color:#666; margin-bottom:6px;">收件人</div>
                    <div style="font-size:22px; font-weight:800; color:#222;"><?php echo htmlspecialchars($order['recipient_name']); ?></div>
                </div>
            </div>

            <div style="overflow:auto; border:1px solid #eee; border-radius:14px; margin-bottom:18px;">
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

        <div style="display:flex; gap:10px; flex-wrap:wrap; justify-content:flex-end;">
            <a href="index.php" style="display:inline-flex; align-items:center; justify-content:center; padding:12px 18px; border-radius:999px; background:#111; color:#fff; font-weight:700;">回首頁</a>
            <?php if ($order): ?>
                <a href="order_detail.php?order_number=<?php echo urlencode($order['order_number']); ?>" style="display:inline-flex; align-items:center; justify-content:center; padding:12px 18px; border-radius:999px; background:#f3f4f6; color:#111; font-weight:700;">查看訂單明細</a>
            <?php endif; ?>
            <a href="profile.php#order-history" style="display:inline-flex; align-items:center; justify-content:center; padding:12px 18px; border-radius:999px; background:#db6b6b; color:#fff; font-weight:700;">回會員中心購買紀錄</a>
        </div>
    </div>
</section>

<?php include 'footer.php'; $conn->close(); ?>

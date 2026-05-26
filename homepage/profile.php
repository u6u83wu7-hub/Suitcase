<?php
$pageTitle = '會員中心 | All Pass';
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

function tableExists($conn, $tableName) {
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return ($res && $res->num_rows > 0);
}

function fetchAssocRows($conn, $sql) {
    $rows = [];
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

$user = [
    'name' => isset($_SESSION['user_name']) ? $_SESSION['user_name'] : '會員',
    'email' => isset($_SESSION['user_email']) ? $_SESSION['user_email'] : '',
    'phone' => '',
    'membership_level' => '',
    'points_balance' => 0,
    'created_at' => ''
];

if (tableExists($conn, 'users')) {
    $sql = "SELECT name, email, phone, membership_level, points_balance, created_at FROM users WHERE user_id = {$userId} LIMIT 1";
    $res = $conn->query($sql);
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $user['name'] = $row['name'] !== null ? $row['name'] : $user['name'];
        $user['email'] = $row['email'] !== null ? $row['email'] : $user['email'];
        $user['phone'] = $row['phone'] !== null ? $row['phone'] : '';
        $user['membership_level'] = $row['membership_level'] !== null ? $row['membership_level'] : '';
        $user['points_balance'] = $row['points_balance'] !== null ? intval($row['points_balance']) : 0;
        $user['created_at'] = $row['created_at'] !== null ? $row['created_at'] : '';
    }
}

// 購物車
$cartRows = [];
if (tableExists($conn, 'cart_items')) {
    $cartRows = fetchAssocRows(
        $conn,
        "SELECT cart_item_id, product_id, quantity, created_at FROM cart_items WHERE user_id = {$userId} ORDER BY created_at DESC"
    );
}

// 收藏
$favoriteRows = [];
if (tableExists($conn, 'user_favorites')) {
    $favoriteRows = fetchAssocRows(
        $conn,
        "SELECT favorite_id, product_id, created_at FROM user_favorites WHERE user_id = {$userId} ORDER BY created_at DESC"
    );
} elseif (tableExists($conn, 'favorites')) {
    $favoriteRows = fetchAssocRows(
        $conn,
        "SELECT favorite_id, product_id, created_at FROM favorites WHERE user_id = {$userId} ORDER BY created_at DESC"
    );
}

// 購買紀錄
$orderRows = [];
if (tableExists($conn, 'orders')) {
    $orderColsRes = $conn->query("SHOW COLUMNS FROM orders");
    $orderCols = [];
    if ($orderColsRes) {
        while ($col = $orderColsRes->fetch_assoc()) {
            $orderCols[] = $col['Field'];
        }
    }

    $orderIdCol = in_array('order_id', $orderCols, true) ? 'order_id' : (in_array('id', $orderCols, true) ? 'id' : 'NULL AS order_id');
    $orderNoCol = in_array('order_number', $orderCols, true) ? 'order_number' : "'' AS order_number";
    $statusCol = in_array('status', $orderCols, true) ? 'status' : "'' AS status";
    $totalCol = in_array('total_amount', $orderCols, true) ? 'total_amount' : (in_array('total', $orderCols, true) ? 'total' : '0 AS total_amount');
    $createdCol = in_array('created_at', $orderCols, true) ? 'created_at' : 'NULL AS created_at';
    $userCol = in_array('user_id', $orderCols, true) ? 'user_id' : null;

    if ($userCol !== null) {
        $orderSql = "SELECT {$orderIdCol}, {$orderNoCol}, {$statusCol}, {$totalCol}, {$createdCol} FROM orders WHERE {$userCol} = {$userId} ORDER BY created_at DESC";
        $orderRows = fetchAssocRows($conn, $orderSql);
    }
}

include 'header.php';
?>

<section style="padding:190px 5% 60px; max-width:1200px; margin:0 auto;">
    <h1 style="font-size:34px; margin-bottom:8px;">會員中心</h1>
    <p style="color:#666; margin-bottom:22px;">歡迎回來，<?php echo htmlspecialchars($user['name']); ?>。你可以在這裡查看帳號、購物車、收藏與購買紀錄。</p>

    <div style="display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:14px; margin-bottom:24px;">
        <div style="background:#fff; border:1px solid #eee; border-radius:12px; padding:14px;">
            <div style="font-size:13px; color:#666;">購物車項目</div>
            <div style="font-size:26px; font-weight:700;"><?php echo count($cartRows); ?></div>
        </div>
        <div style="background:#fff; border:1px solid #eee; border-radius:12px; padding:14px;">
            <div style="font-size:13px; color:#666;">收藏商品</div>
            <div style="font-size:26px; font-weight:700;"><?php echo count($favoriteRows); ?></div>
        </div>
        <div style="background:#fff; border:1px solid #eee; border-radius:12px; padding:14px;">
            <div style="font-size:13px; color:#666;">累積訂單</div>
            <div style="font-size:26px; font-weight:700;"><?php echo count($orderRows); ?></div>
        </div>
        <div style="background:#fff; border:1px solid #eee; border-radius:12px; padding:14px;">
            <div style="font-size:13px; color:#666;">會員點數</div>
            <div style="font-size:26px; font-weight:700;"><?php echo number_format($user['points_balance']); ?></div>
        </div>
    </div>

    <div style="display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:16px;">
        <section style="background:#fff; border:1px solid #eee; border-radius:12px; padding:18px;">
            <h2 style="font-size:20px; margin-bottom:12px;">會員帳號</h2>
            <div style="line-height:1.9; color:#444;">
                <div><strong>姓名：</strong><?php echo htmlspecialchars($user['name']); ?></div>
                <div><strong>Email：</strong><?php echo htmlspecialchars($user['email']); ?></div>
                <div><strong>電話：</strong><?php echo htmlspecialchars($user['phone']); ?></div>
                <div><strong>等級：</strong><?php echo htmlspecialchars($user['membership_level'] !== '' ? $user['membership_level'] : '一般會員'); ?></div>
                <div><strong>註冊時間：</strong><?php echo htmlspecialchars($user['created_at']); ?></div>
            </div>
        </section>

        <section style="background:#fff; border:1px solid #eee; border-radius:12px; padding:18px;">
            <h2 style="font-size:20px; margin-bottom:12px;">購物車</h2>
            <?php if (!empty($cartRows)): ?>
                <ul style="padding-left:16px; line-height:1.8; color:#444;">
                    <?php foreach ($cartRows as $item): ?>
                        <li>商品 ID #<?php echo intval($item['product_id']); ?>，數量 <?php echo intval($item['quantity']); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p style="color:#777;">目前購物車沒有商品。</p>
            <?php endif; ?>
        </section>

        <section style="background:#fff; border:1px solid #eee; border-radius:12px; padding:18px;">
            <h2 style="font-size:20px; margin-bottom:12px;">收藏</h2>
            <?php if (!empty($favoriteRows)): ?>
                <ul style="padding-left:16px; line-height:1.8; color:#444;">
                    <?php foreach ($favoriteRows as $item): ?>
                        <li>商品 ID #<?php echo intval($item['product_id']); ?>，收藏於 <?php echo htmlspecialchars($item['created_at']); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p style="color:#777;">目前尚無收藏商品。</p>
            <?php endif; ?>
        </section>

        <section style="background:#fff; border:1px solid #eee; border-radius:12px; padding:18px;">
            <h2 style="font-size:20px; margin-bottom:12px;">購買紀錄</h2>
            <?php if (!empty($orderRows)): ?>
                <div style="overflow:auto;">
                    <table style="width:100%; border-collapse:collapse; font-size:14px;">
                        <thead>
                            <tr style="text-align:left; border-bottom:1px solid #eee; color:#666;">
                                <th style="padding:8px 6px;">訂單編號</th>
                                <th style="padding:8px 6px;">狀態</th>
                                <th style="padding:8px 6px;">金額</th>
                                <th style="padding:8px 6px;">時間</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orderRows as $o): ?>
                                <tr style="border-bottom:1px solid #f3f3f3;">
                                    <td style="padding:8px 6px;"><?php echo htmlspecialchars($o['order_number'] !== '' ? $o['order_number'] : ('#' . $o['order_id'])); ?></td>
                                    <td style="padding:8px 6px;"><?php echo htmlspecialchars($o['status']); ?></td>
                                    <td style="padding:8px 6px;">NT$ <?php echo number_format(floatval($o['total_amount'])); ?></td>
                                    <td style="padding:8px 6px;"><?php echo htmlspecialchars($o['created_at']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p style="color:#777;">目前尚無購買紀錄。</p>
            <?php endif; ?>
        </section>
    </div>
</section>

<style>
@media (max-width: 992px) {
    section[style*='grid-template-columns:repeat(4'] {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }
    section[style*='grid-template-columns:repeat(2'] {
        grid-template-columns: 1fr !important;
    }
}
</style>

<?php include 'footer.php'; $conn->close(); ?>

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

require_once __DIR__ . '/includes/security.php';

$userId = intval($_SESSION['user_id']);

$orderStatusLabels = [
    'PENDING' => '待處理',
    'PROCESSING' => '處理中',
    'SHIPPED' => '已出貨',
    'DELIVERED' => '已送達',
    'COMPLETED' => '已完成',
    'CANCELLED' => '已取消',
];

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

function couponTypeText($type) {
    switch ($type) {
        case 'REDUCE':
            return '減價';
        case 'POINTS':
            return '點數回饋';
        default:
            return '折扣';
    }
}

function couponTargetText($type) {
    switch ($type) {
        case 'ALL':
            return '全體用戶';
        case 'USE CODE':
            return 'USE CODE';
        default:
            return '特定用戶';
    }
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

// 📊 修正版：收藏（改用 JOIN 順便撈出商品名稱）
$favoriteRows = [];
if (tableExists($conn, 'user_favorites')) {
    $favoriteRows = fetchAssocRows(
        $conn,
        "SELECT f.favorite_id, f.product_id, f.created_at, p.name AS product_name 
         FROM user_favorites f
         LEFT JOIN products p ON f.product_id = p.product_id
         WHERE f.user_id = {$userId} 
         ORDER BY f.created_at DESC"
    );
} elseif (tableExists($conn, 'favorites')) {
    $favoriteRows = fetchAssocRows(
        $conn,
        "SELECT f.favorite_id, f.product_id, f.created_at, p.name AS product_name 
         FROM favorites f
         LEFT JOIN products p ON f.product_id = p.product_id
         WHERE f.user_id = {$userId} 
         ORDER BY f.created_at DESC"
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

// 優惠卷持有與明細（來源：後台發送紀錄）
$couponRows = [];
$couponCount = 0;
$couponNotice = '';
if (tableExists($conn, 'coupon_distributions')) {
    $couponSql = "
        SELECT
            cd.distribution_id,
            cd.coupon_id,
            cd.user_id,
            cd.quantity,
            cd.target_type,
            cd.created_at,
            c.coupon_name,
            c.coupon_type,
            c.coupon_value,
            c.coupon_code,
            c.min_order_amount,
            c.usage_limit,
            c.used_count,
            c.is_active,
            c.start_at,
            c.end_at,
            u.name AS source_user_name,
            u.email AS source_user_email
        FROM coupon_distributions cd
        LEFT JOIN coupons c ON c.coupon_id = cd.coupon_id
        LEFT JOIN users u ON u.user_id = cd.user_id
        WHERE cd.user_id = {$userId}
        ORDER BY cd.created_at DESC, cd.distribution_id DESC
    ";
    $couponRows = fetchAssocRows($conn, $couponSql);
    foreach ($couponRows as $couponRow) {
        $couponCount += max(1, (int)($couponRow['quantity'] ?? 1));
    }
}

if (!empty($_GET['coupon_success'])) {
    $couponNotice = '<div style="padding:12px 14px; border-radius:10px; background:#ecfdf5; color:#047857; margin-bottom:16px;">優惠卷已成功加入您的會員資料。</div>';
} elseif (!empty($_GET['coupon_error'])) {
    $couponNotice = '<div style="padding:12px 14px; border-radius:10px; background:#fef2f2; color:#b91c1c; margin-bottom:16px;">' . htmlspecialchars($_GET['coupon_error']) . '</div>';
}

include 'header.php';
?>

<section style="padding:190px 5% 60px; max-width:1200px; margin:0 auto;">
    <h1 style="font-size:34px; margin-bottom:8px;">會員中心</h1>
    <p style="color:#666; margin-bottom:22px;">歡迎回來，<?php echo htmlspecialchars($user['name']); ?>。你可以在這裡查看帳號、購物車、收藏與購買紀錄。</p>

    <div style="display:grid; grid-template-columns:repeat(5, minmax(0,1fr)); gap:14px; margin-bottom:24px;">
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
        <div style="background:#fff; border:1px solid #eee; border-radius:12px; padding:14px;">
            <div style="font-size:13px; color:#666;">持有優惠卷</div>
            <div style="font-size:26px; font-weight:700;"><?php echo number_format($couponCount); ?></div>
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
            <div style="margin-top:14px;">
                <a href="member_detail.php" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 16px; border-radius:999px; background:#111; color:#fff; font-weight:700;">會員詳細資料</a>
            </div>
        </section>

        <section style="background:#fff; border:1px solid #eee; border-radius:12px; padding:18px; max-height:320px; overflow:auto;">
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
            <div style="margin-top:14px;">
                <a href="cart.php" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 16px; border-radius:999px; background:#db6b6b; color:#fff; font-weight:700;">前往購物車</a>
            </div>
        </section>

        <section style="background:#fff; border:1px solid #eee; border-radius:12px; padding:18px; max-height:320px; overflow:auto;">
    <h2 style="font-size:20px; margin-bottom:12px;">收藏</h2>
    <?php if (!empty($favoriteRows)): ?>
        <ul style="padding-left:16px; line-height:1.8; color:#444;">
            <?php foreach ($favoriteRows as $item): ?>
                <li style="margin-bottom: 8px;">
                    <a href="product_detail.php?id=<?php echo intval($item['product_id']); ?>" style="color:#db6b6b; font-weight:700; text-decoration:none; hover:text-decoration:underline;">
                        <?php echo htmlspecialchars($item['product_name'] ?? '未命名商品(ID:#' . $item['product_id'] . ')'); ?>
                    </a>
                    <span style="color:#888; font-size:12px; margin-left:8px;">
                        (收藏於 <?php echo date('Y-m-d H:i', strtotime($item['created_at'])); ?>)
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p style="color:#777;">目前尚無收藏商品。</p>
    <?php endif; ?>
</section>

        <section id="order-history" style="background:#fff; border:1px solid #eee; border-radius:12px; padding:18px; max-height:420px; overflow:auto;">
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
                                <th style="padding:8px 6px;">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orderRows as $o): ?>
                                <tr style="border-bottom:1px solid #f3f3f3;">
                                    <td style="padding:8px 6px;"><a href="order_detail.php?order_number=<?php echo urlencode($o['order_number'] !== '' ? $o['order_number'] : ('#' . $o['order_id'])); ?>" style="color:#db6b6b; font-weight:700; text-decoration:none;"><?php echo htmlspecialchars($o['order_number'] !== '' ? $o['order_number'] : ('#' . $o['order_id'])); ?></a></td>
                                    <td style="padding:8px 6px;"><?php echo htmlspecialchars($orderStatusLabels[$o['status']] ?? $o['status']); ?></td>
                                    <td style="padding:8px 6px;">NT$ <?php echo number_format(floatval($o['total_amount'])); ?></td>
                                    <td style="padding:8px 6px;"><?php echo htmlspecialchars($o['created_at']); ?></td>
                                    <td style="padding:8px 6px;">
                                        <?php if ($o['status'] === 'DELIVERED'): ?>
                                            <form method="POST" action="complete_order.php" style="margin:0;">
                                                <input type="hidden" name="order_id" value="<?php echo intval($o['order_id']); ?>">
                                                <button type="submit" style="padding:6px 10px; border-radius:999px; background:#111; color:#fff; font-weight:700; font-size:12px;">完成訂單</button>
                                            </form>
                                        <?php else: ?>
                                            <span style="color:#94a3b8; font-size:12px;">-</span>
                                        <?php endif; ?>
                                    </td>
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

    <?php echo $couponNotice; ?>

    <section style="background:#fff; border:1px solid #eee; border-radius:12px; padding:18px; margin-top:16px;">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:12px;">
            <h2 style="font-size:20px; margin:0;">優惠卷細節</h2>
            <button type="button" id="openRedeemCoupon" style="padding:10px 16px; border-radius:999px; background:#111; color:#fff; font-weight:700; border:none; cursor:pointer;">+ 新增優惠卷</button>
        </div>
        <?php if (!empty($couponRows)): ?>
            <div style="overflow:auto; max-height:320px;">
                <table style="width:100%; border-collapse:collapse; font-size:14px; min-width:700px;">
                    <thead>
                        <tr style="text-align:left; border-bottom:1px solid #eee; color:#666;">
                            <th style="padding:8px 6px;">優惠卷</th>
                            <th style="padding:8px 6px;">類型</th>
                            <th style="padding:8px 6px;">張數</th>
                            <th style="padding:8px 6px;">發送時間</th>
                            <th style="padding:8px 6px;">結束時間</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($couponRows as $coupon): ?>
                            <tr style="border-bottom:1px solid #f3f3f3;">
                                <td style="padding:8px 6px; font-weight:700;"><?php echo htmlspecialchars($coupon['coupon_name'] ?? '未命名優惠卷'); ?></td>
                                <td style="padding:8px 6px;"><?php echo htmlspecialchars(couponTypeText($coupon['coupon_type'] ?? 'DISCOUNT')); ?></td>
                                <td style="padding:8px 6px;"><?php echo number_format((int)($coupon['quantity'] ?? 1)); ?></td>
                                <td style="padding:8px 6px;"><?php echo htmlspecialchars($coupon['created_at'] ?? ''); ?></td>
                                <td style="padding:8px 6px;"><?php echo htmlspecialchars($coupon['end_at'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p style="color:#777;">目前尚未收到任何優惠卷。</p>
        <?php endif; ?>
    </section>
</section>

<div id="redeemCouponModal" style="position:fixed; inset:0; display:none; align-items:center; justify-content:center; background:rgba(15,23,42,.6); z-index:999; backdrop-filter:blur(2px);">
    <div style="background:#fff; width:min(520px, 95vw); border-radius:14px; padding:24px; box-shadow:0 20px 40px rgba(0,0,0,.12);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; padding-bottom:12px; border-bottom:1px solid #e5e7eb;">
            <h3 style="margin:0; font-size:18px;">輸入優惠卷代碼</h3>
            <button type="button" id="closeRedeemCouponModal" style="border:none; background:#f3f4f6; border-radius:999px; width:32px; height:32px; cursor:pointer;">✕</button>
        </div>
        <form action="../backend/backend_action.php" method="POST">
            <input type="hidden" name="action" value="redeem_coupon_code">
            <?php echo apCsrfField(); ?>
            <label for="redeem_coupon_code" style="display:block; font-size:14px; margin-bottom:8px; color:#444;">優惠卷代碼</label>
            <input type="text" id="redeem_coupon_code" name="coupon_code" required style="width:100%; padding:12px 14px; border:1px solid #d1d5db; border-radius:10px; box-sizing:border-box; margin-bottom:16px;" placeholder="請輸入優惠卷代碼">
            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" id="cancelRedeemCoupon" style="padding:10px 16px; border:none; border-radius:999px; background:#e5e7eb; cursor:pointer;">取消</button>
                <button type="submit" style="padding:10px 16px; border:none; border-radius:999px; background:#db6b6b; color:#fff; font-weight:700; cursor:pointer;">兌換優惠卷</button>
            </div>
        </form>
    </div>
</div>

<style>
@media (max-width: 992px) {
    section[style*='grid-template-columns:repeat(5'] {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }
    section[style*='grid-template-columns:repeat(2'] {
        grid-template-columns: 1fr !important;
    }
}
</style>

<script>
const redeemCouponModal = document.getElementById('redeemCouponModal');
const openRedeemCoupon = document.getElementById('openRedeemCoupon');
const closeRedeemCouponModal = document.getElementById('closeRedeemCouponModal');
const cancelRedeemCoupon = document.getElementById('cancelRedeemCoupon');

function hideRedeemCouponModal() {
    if (redeemCouponModal) {
        redeemCouponModal.style.display = 'none';
    }
}

if (openRedeemCoupon && redeemCouponModal) {
    openRedeemCoupon.addEventListener('click', () => {
        redeemCouponModal.style.display = 'flex';
    });
}

if (closeRedeemCouponModal) {
    closeRedeemCouponModal.addEventListener('click', hideRedeemCouponModal);
}

if (cancelRedeemCoupon) {
    cancelRedeemCoupon.addEventListener('click', hideRedeemCouponModal);
}

if (redeemCouponModal) {
    redeemCouponModal.addEventListener('click', (event) => {
        if (event.target === redeemCouponModal) {
            hideRedeemCouponModal();
        }
    });
}
</script>

<?php include 'footer.php'; $conn->close(); ?>

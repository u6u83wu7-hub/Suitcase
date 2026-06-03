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

// 💡 修正 1：確保 PHP 使用台灣時區
date_default_timezone_set('Asia/Taipei');

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

// 💡 修正 2：強制設定 MySQL 資料庫時區，否則 NOW() 會變成慢 8 小時的 UTC 時間！
$conn->query("SET time_zone = '+08:00'");

function tableExists($conn, $tableName) {
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return ($res && $res->num_rows > 0);
}

function fetchAssocRows($conn, $sql) {
    $rows = [];
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) $rows[] = $row;
    }
    return $rows;
}

function couponTypeText($type) {
    switch ($type) {
        case 'REDUCE': return '減價';
        case 'POINTS': return '點數回饋';
        default: return '折扣';
    }
}

$user = [
    'name' => isset($_SESSION['user_name']) ? $_SESSION['user_name'] : '會員',
    'email' => isset($_SESSION['user_email']) ? $_SESSION['user_email'] : '',
    'phone' => '', 'membership_level' => '', 'points_balance' => 0, 'created_at' => ''
];

if (tableExists($conn, 'users')) {
    $sql = "SELECT name, email, phone, membership_level, points_balance, created_at FROM users WHERE user_id = {$userId} LIMIT 1";
    $res = $conn->query($sql);
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $user = array_merge($user, $row);
    }
}

$userLevel = $user['membership_level'] !== '' ? $conn->real_escape_string($user['membership_level']) : '一般會員';

// ==========================================
// 💡 處理前端的 POST 請求 (領取與兌換優惠卷)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // 1. 處理點擊「領取公開卷」
    if ($action === 'claim_coupon') {
        $couponId = intval($_POST['coupon_id'] ?? 0);
        if ($couponId > 0) {
            $stmt = $conn->prepare("SELECT target_membership FROM coupons WHERE coupon_id = ? AND is_active = 1 AND (start_at IS NULL OR start_at <= NOW()) AND (end_at IS NULL OR end_at >= NOW()) AND (usage_limit IS NULL OR usage_limit = 0 OR used_count < usage_limit) AND (coupon_code IS NULL OR coupon_code = '')");
            $stmt->bind_param('i', $couponId);
            $stmt->execute();
            $validRes = $stmt->get_result();
            if ($validRes && $validRes->num_rows > 0) {
                $couponData = $validRes->fetch_assoc();
                if (!empty($couponData['target_membership']) && $couponData['target_membership'] !== $userLevel) {
                    header("Location: profile.php?coupon_error=" . urlencode("您的會員等級不符合此優惠卷的領取資格。"));
                    exit;
                }
                
                $check = $conn->query("SELECT distribution_id FROM coupon_distributions WHERE user_id = {$userId} AND coupon_id = {$couponId}");
                if ($check && $check->num_rows === 0) {
                    $conn->begin_transaction();
                    try {
                        $conn->query("INSERT INTO coupon_distributions (coupon_id, user_id, quantity, target_type, sent_by_admin_id) VALUES ({$couponId}, {$userId}, 1, 'SINGLE', 0)");
                        $conn->query("UPDATE coupons SET used_count = used_count + 1 WHERE coupon_id = {$couponId}");
                        $conn->commit();
                        header("Location: profile.php?coupon_success=" . urlencode("成功領取優惠卷！"));
                        exit;
                    } catch (Exception $e) {
                        $conn->rollback();
                        header("Location: profile.php?coupon_error=" . urlencode("領取失敗，請稍後再試。"));
                        exit;
                    }
                } else {
                    header("Location: profile.php?coupon_error=" . urlencode("您已經領取過此優惠卷了！"));
                    exit;
                }
            } else {
                header("Location: profile.php?coupon_error=" . urlencode("此優惠卷無效或已被領取完畢！"));
                exit;
            }
        }
    }

    // 2. 處理輸入「專屬代碼兌換」
    if ($action === 'redeem_coupon_code') {
        $redeemCode = trim($_POST['coupon_code'] ?? '');
        if ($redeemCode !== '') {
            $stmt = $conn->prepare("SELECT * FROM coupons WHERE coupon_code = ? LIMIT 1");
            $stmt->bind_param('s', $redeemCode);
            $stmt->execute();
            $couponRes = $stmt->get_result();
            
            if ($couponRes && $couponRes->num_rows > 0) {
                $coupon = $couponRes->fetch_assoc();
                $couponId = intval($coupon['coupon_id']);
                $now = time();
                $start = strtotime($coupon['start_at']);
                $end = strtotime($coupon['end_at']);
                
                if ((int)$coupon['is_active'] !== 1 || $now < $start || $now > $end) {
                    header("Location: profile.php?coupon_error=" . urlencode("此優惠碼無效或已過期。"));
                    exit;
                }
                if ((int)$coupon['usage_limit'] > 0 && (int)$coupon['used_count'] >= (int)$coupon['usage_limit']) {
                    header("Location: profile.php?coupon_error=" . urlencode("此優惠碼已被兌換完畢。"));
                    exit;
                }
                if (!empty($coupon['target_membership']) && $coupon['target_membership'] !== $userLevel) {
                    header("Location: profile.php?coupon_error=" . urlencode("您的會員等級不符合此專屬代碼的兌換資格。"));
                    exit;
                }

                $check = $conn->query("SELECT distribution_id FROM coupon_distributions WHERE user_id = {$userId} AND coupon_id = {$couponId}");
                if ($check && $check->num_rows === 0) {
                    $conn->begin_transaction();
                    try {
                        $conn->query("INSERT INTO coupon_distributions (coupon_id, user_id, quantity, target_type, sent_by_admin_id) VALUES ({$couponId}, {$userId}, 1, 'USE CODE', 0)");
                        $conn->query("INSERT INTO coupon_code_uses (coupon_id, user_id, coupon_code) VALUES ({$couponId}, {$userId}, '{$redeemCode}')");
                        $conn->query("UPDATE coupons SET used_count = used_count + 1 WHERE coupon_id = {$couponId}");
                        $conn->commit();
                        header("Location: profile.php?coupon_success=" . urlencode("成功兌換專屬優惠碼！"));
                        exit;
                    } catch (Exception $e) {
                        $conn->rollback();
                        header("Location: profile.php?coupon_error=" . urlencode("兌換發生錯誤。"));
                        exit;
                    }
                } else {
                    header("Location: profile.php?coupon_error=" . urlencode("您已經兌換過這個優惠碼了！"));
                    exit;
                }
            } else {
                header("Location: profile.php?coupon_error=" . urlencode("找不到此優惠碼！"));
                exit;
            }
        }
    }
}

$cartRows = [];
if (tableExists($conn, 'cart_items')) {
    $cartRows = fetchAssocRows(
        $conn,
        "SELECT c.cart_item_id, c.product_id, c.quantity, c.created_at, p.name AS product_name 
         FROM cart_items c 
         LEFT JOIN products p ON c.product_id = p.product_id 
         WHERE c.user_id = {$userId} 
         ORDER BY c.created_at DESC"
    );
}

$favoriteRows = [];
$favTable = tableExists($conn, 'user_favorites') ? 'user_favorites' : (tableExists($conn, 'favorites') ? 'favorites' : '');
if ($favTable) {
    $favoriteRows = fetchAssocRows(
        $conn,
        "SELECT f.favorite_id, f.product_id, f.created_at, p.name AS product_name 
         FROM {$favTable} f
         LEFT JOIN products p ON f.product_id = p.product_id
         WHERE f.user_id = {$userId} 
         ORDER BY f.created_at DESC"
    );
}

$orderRows = [];
if (tableExists($conn, 'orders')) {
    $orderRows = fetchAssocRows($conn, "SELECT order_id, order_number, status, total_amount, created_at FROM orders WHERE user_id = {$userId} ORDER BY created_at DESC");
}

// 💡 撈取「可領取」的公開優惠卷
$claimableCoupons = [];
$myCoupons = [];
$couponCount = 0;

if (tableExists($conn, 'coupons') && tableExists($conn, 'coupon_distributions')) {
    $claimableSql = "
    SELECT * FROM coupons 
    WHERE is_active = 1 
      AND (start_at IS NULL OR start_at <= NOW()) 
      AND (end_at IS NULL OR end_at >= NOW())
      AND (usage_limit IS NULL OR usage_limit = 0 OR used_count < usage_limit) /* 💡 修正這裡：加入 IS NULL 判斷 */
      AND (coupon_code IS NULL OR coupon_code = '') 
      AND (target_membership IS NULL OR target_membership = '' OR target_membership = '{$userLevel}')
      AND coupon_id NOT IN (
          SELECT coupon_id FROM coupon_distributions WHERE user_id = {$userId}
      )
    ORDER BY created_at DESC
";
    $claimableCoupons = fetchAssocRows($conn, $claimableSql);

    $myCouponSql = "
        SELECT cd.quantity, cd.created_at AS received_at, c.* FROM coupon_distributions cd
        JOIN coupons c ON cd.coupon_id = c.coupon_id
        WHERE cd.user_id = {$userId}
        ORDER BY cd.created_at DESC
    ";
    $myCoupons = fetchAssocRows($conn, $myCouponSql);
    foreach ($myCoupons as $c) {
        $couponCount += max(1, (int)($c['quantity'] ?? 1));
    }
}

$couponNotice = '';
if (!empty($_GET['coupon_success'])) {
    $couponNotice = '<div style="padding:12px 14px; border-radius:10px; background:#ecfdf5; color:#047857; margin-bottom:16px;">' . htmlspecialchars($_GET['coupon_success']) . '</div>';
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
                <a href="member_detail.php" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 16px; border-radius:999px; background:#111; color:#fff; font-weight:700; text-decoration:none;">會員詳細資料</a>
            </div>
        </section>

        <section style="background:#fff; border:1px solid #eee; border-radius:12px; padding:18px; max-height:320px; overflow:auto;">
            <h2 style="font-size:20px; margin-bottom:12px;">購物車</h2>
            <?php if (!empty($cartRows)): ?>
                <ul style="padding-left:16px; line-height:1.8; color:#444; margin:0;">
                    <?php foreach ($cartRows as $item): ?>
                        <li style="margin-bottom: 8px;">
                            <a href="product_detail.php?id=<?php echo intval($item['product_id']); ?>" style="color:#db6b6b; font-weight:700; text-decoration:none;">
                                <?php echo htmlspecialchars($item['product_name'] ?? '未命名商品(ID:#' . $item['product_id'] . ')'); ?>
                            </a>
                            <span style="color:#888; font-size:12px; margin-left:8px;">
                                (數量：<?php echo intval($item['quantity']); ?>)
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p style="color:#777;">目前購物車沒有商品。</p>
            <?php endif; ?>
            <div style="margin-top:14px;">
                <a href="cart.php" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 16px; border-radius:999px; background:#db6b6b; color:#fff; font-weight:700; text-decoration:none;">前往購物車</a>
            </div>
        </section>

        <section style="background:#fff; border:1px solid #eee; border-radius:12px; padding:18px; max-height:320px; overflow:auto;">
            <h2 style="font-size:20px; margin-bottom:12px;">收藏商品</h2>
            <?php if (!empty($favoriteRows)): ?>
                <ul style="padding-left:16px; line-height:1.8; color:#444; margin:0;">
                    <?php foreach ($favoriteRows as $item): ?>
                        <li style="margin-bottom: 8px;">
                            <a href="product_detail.php?id=<?php echo intval($item['product_id']); ?>" style="color:#db6b6b; font-weight:700; text-decoration:none;">
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

        <section id="order-history" style="background:#fff; border:1px solid #eee; border-radius:12px; padding:18px; max-height:320px; overflow:auto;">
            <h2 style="font-size:20px; margin-bottom:12px;">購買紀錄</h2>
            <?php if (!empty($orderRows)): ?>
                <div style="overflow:auto;">
                    <table style="width:100%; border-collapse:collapse; font-size:14px;">
                        <thead>
                            <tr style="text-align:left; border-bottom:1px solid #eee; color:#666;">
                                <th style="padding:8px 6px;">訂單編號</th>
                                <th style="padding:8px 6px;">狀態</th>
                                <th style="padding:8px 6px;">金額</th>
                                <th style="padding:8px 6px;">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orderRows as $o): ?>
                                <tr style="border-bottom:1px solid #f3f3f3;">
                                    <td style="padding:8px 6px;"><a href="order_detail.php?order_number=<?php echo urlencode($o['order_number'] !== '' ? $o['order_number'] : ('#' . $o['order_id'])); ?>" style="color:#db6b6b; font-weight:700; text-decoration:none;"><?php echo htmlspecialchars($o['order_number'] !== '' ? $o['order_number'] : ('#' . $o['order_id'])); ?></a></td>
                                    <td style="padding:8px 6px;"><?php echo htmlspecialchars($orderStatusLabels[$o['status']] ?? $o['status']); ?></td>
                                    <td style="padding:8px 6px;">NT$ <?php echo number_format(floatval($o['total_amount'])); ?></td>
                                    <td style="padding:8px 6px;">
                                        <?php if ($o['status'] === 'DELIVERED'): ?>
                                            <form method="POST" action="complete_order.php" style="margin:0;">
                                                <input type="hidden" name="order_id" value="<?php echo intval($o['order_id']); ?>">
                                                <button type="submit" style="padding:6px 10px; border-radius:999px; background:#111; color:#fff; font-weight:700; font-size:12px; border:none; cursor:pointer;">完成訂單</button>
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
        <h2 style="font-size:20px; margin-bottom:12px; color:#b91c1c;">🎁 可領取優惠卷</h2>
        <?php if (!empty($claimableCoupons)): ?>
            <div style="overflow:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:14px; min-width:600px;">
                    <thead>
                        <tr style="text-align:left; border-bottom:1px solid #eee; color:#666;">
                            <th style="padding:8px 6px;">優惠卷名稱</th>
                            <th style="padding:8px 6px;">優惠內容</th>
                            <th style="padding:8px 6px;">條件</th>
                            <th style="padding:8px 6px;">使用期限</th>
                            <th style="padding:8px 6px;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($claimableCoupons as $coupon): ?>
                            <tr style="border-bottom:1px solid #f3f3f3;">
                                <td style="padding:12px 6px; font-weight:700;"><?php echo htmlspecialchars($coupon['coupon_name']); ?></td>
                                <td style="padding:12px 6px; color:#db6b6b; font-weight:bold;">
                                    <?php echo htmlspecialchars(couponTypeText($coupon['coupon_type'])); ?> : 
                                    <?php echo number_format(floatval($coupon['coupon_value'])); ?>
                                </td>
                                <td style="padding:12px 6px; color:#64748b; font-size:12px;">
                                    滿 NT$<?php echo number_format(floatval($coupon['min_order_amount'])); ?><br>
                                    <?php echo !empty($coupon['target_membership']) ? '限'.htmlspecialchars($coupon['target_membership']) : '不限等級'; ?>
                                </td>
                                <td style="padding:12px 6px;"><?php echo htmlspecialchars($coupon['end_at'] ?? '無期限'); ?></td>
                                <td style="padding:12px 6px;">
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="action" value="claim_coupon">
                                        <input type="hidden" name="coupon_id" value="<?php echo intval($coupon['coupon_id']); ?>">
                                        <button type="submit" style="padding:6px 16px; border-radius:999px; background:#b91c1c; color:#fff; font-weight:700; border:none; cursor:pointer;">立即領取</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p style="color:#777;">目前沒有可以領取的優惠卷，請留意我們未來的活動！</p>
        <?php endif; ?>
    </section>

    <section style="background:#fff; border:1px solid #eee; border-radius:12px; padding:18px; margin-top:16px;">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:12px;">
            <h2 style="font-size:20px; margin:0;">🎟️ 我的優惠卷</h2>
            <button type="button" id="openRedeemCoupon" style="padding:8px 16px; border-radius:999px; background:#111; color:#fff; font-weight:700; border:none; cursor:pointer;">輸入優惠碼</button>
        </div>
        <?php if (!empty($myCoupons)): ?>
            <div style="overflow:auto; max-height:320px;">
                <table style="width:100%; border-collapse:collapse; font-size:14px; min-width:700px;">
                    <thead>
                        <tr style="text-align:left; border-bottom:1px solid #eee; color:#666;">
                            <th style="padding:8px 6px;">優惠卷</th>
                            <th style="padding:8px 6px;">類型</th>
                            <th style="padding:8px 6px;">領取時間</th>
                            <th style="padding:8px 6px;">結束時間</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($myCoupons as $coupon): ?>
                            <tr style="border-bottom:1px solid #f3f3f3;">
                                <td style="padding:12px 6px; font-weight:700;"><?php echo htmlspecialchars($coupon['coupon_name'] ?? '未命名優惠卷'); ?></td>
                                <td style="padding:12px 6px;"><?php echo htmlspecialchars(couponTypeText($coupon['coupon_type'] ?? 'DISCOUNT')); ?></td>
                                <td style="padding:12px 6px;"><?php echo htmlspecialchars($coupon['received_at'] ?? ''); ?></td>
                                <td style="padding:12px 6px; color:#b91c1c;"><?php echo htmlspecialchars($coupon['end_at'] ?? '無期限'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p style="color:#777;">您目前還沒有領取任何優惠卷。</p>
        <?php endif; ?>
    </section>
</section>

<div id="redeemCouponModal" style="position:fixed; inset:0; display:none; align-items:center; justify-content:center; background:rgba(15,23,42,.6); z-index:999; backdrop-filter:blur(2px);">
    <div style="background:#fff; width:min(520px, 95vw); border-radius:14px; padding:24px; box-shadow:0 20px 40px rgba(0,0,0,.12);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; padding-bottom:12px; border-bottom:1px solid #e5e7eb;">
            <h3 style="margin:0; font-size:18px;">輸入優惠卷代碼</h3>
            <button type="button" id="closeRedeemCouponModal" style="border:none; background:#f3f4f6; border-radius:999px; width:32px; height:32px; cursor:pointer;">✕</button>
        </div>
        <form action="profile.php" method="POST">
            <input type="hidden" name="action" value="redeem_coupon_code">
            <?php if(function_exists('apCsrfField')) echo apCsrfField(); ?>
            
            <label for="redeem_coupon_code" style="display:block; font-size:14px; margin-bottom:8px; color:#444;">優惠碼</label>
            <input type="text" id="redeem_coupon_code" name="coupon_code" required style="width:100%; padding:12px 14px; border:1px solid #d1d5db; border-radius:10px; box-sizing:border-box; margin-bottom:16px;" placeholder="請輸入活動專屬優惠碼">
            
            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" id="cancelRedeemCoupon" style="padding:10px 16px; border:none; border-radius:999px; background:#e5e7eb; cursor:pointer;">取消</button>
                <button type="submit" style="padding:10px 16px; border:none; border-radius:999px; background:#db6b6b; color:#fff; font-weight:700; cursor:pointer;">立即兌換</button>
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
    if (redeemCouponModal) redeemCouponModal.style.display = 'none';
}

if (openRedeemCoupon && redeemCouponModal) {
    openRedeemCoupon.addEventListener('click', () => { redeemCouponModal.style.display = 'flex'; });
}

if (closeRedeemCouponModal) closeRedeemCouponModal.addEventListener('click', hideRedeemCouponModal);
if (cancelRedeemCoupon) cancelRedeemCoupon.addEventListener('click', hideRedeemCouponModal);

if (redeemCouponModal) {
    redeemCouponModal.addEventListener('click', (event) => {
        if (event.target === redeemCouponModal) hideRedeemCouponModal();
    });
}
</script>

<?php include 'footer.php'; $conn->close(); ?>
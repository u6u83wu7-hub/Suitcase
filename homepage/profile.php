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
    error_log('Profile database connection failed: ' . $conn->connect_error);
    http_response_code(500);
    echo '系統暫時無法連線，請稍後再試。';
    exit;
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

function membershipLevelText($level) {
    $level = trim((string)$level);
    if ($level === '3') return 'VVIP';
    if ($level === '2') return 'VIP';
    if ($level === '1') return '一般會員';
    return $level;
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
// 💡 處理前端的 POST 請求 (領取、兌換優惠卷、送出評論與確認訂單)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    apRequireCsrf('profile.php?coupon_error=' . urlencode('表單驗證失敗，請重新操作。'));

    $couponRedirect = function ($type, $message) {
        header('Location: profile.php?' . http_build_query(['coupon_' . $type => $message]));
        exit;
    };

    if ($action === 'claim_coupon') {
        $couponId = (int)($_POST['coupon_id'] ?? 0);
        if ($couponId <= 0) {
            $couponRedirect('error', '找不到要領取的優惠卷。');
        }

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT * FROM coupons WHERE coupon_id = ? AND (coupon_code IS NULL OR coupon_code = '') FOR UPDATE");
            if (!$stmt) {
                throw new RuntimeException('讀取優惠卷失敗。');
            }
            $stmt->bind_param('i', $couponId);
            $stmt->execute();
            $validRes = $stmt->get_result();
            $coupon = ($validRes && $validRes->num_rows > 0) ? $validRes->fetch_assoc() : null;
            $stmt->close();

            if (!$coupon) {
                throw new RuntimeException('此優惠卷不存在或不可公開領取。');
            }

            $now = time();
            $start = !empty($coupon['start_at']) ? strtotime($coupon['start_at']) : false;
            $end = !empty($coupon['end_at']) ? strtotime($coupon['end_at']) : false;
            if ((int)$coupon['is_active'] !== 1 || ($start !== false && $now < $start) || ($end !== false && $now > $end)) {
                throw new RuntimeException('此優惠卷尚未啟用或已過期。');
            }
            if ((int)($coupon['usage_limit'] ?? 0) > 0 && (int)($coupon['used_count'] ?? 0) >= (int)$coupon['usage_limit']) {
                throw new RuntimeException('此優惠卷已被領取完畢。');
            }
            if (!empty($coupon['target_membership']) && $coupon['target_membership'] !== $userLevel) {
                throw new RuntimeException('您的會員等級不符合此優惠卷的領取資格。');
            }

            $checkStmt = $conn->prepare('SELECT distribution_id FROM coupon_distributions WHERE user_id = ? AND coupon_id = ? LIMIT 1');
            if (!$checkStmt) {
                throw new RuntimeException('檢查領取紀錄失敗。');
            }
            $checkStmt->bind_param('ii', $userId, $couponId);
            $checkStmt->execute();
            $checkRes = $checkStmt->get_result();
            $alreadyOwned = $checkRes && $checkRes->num_rows > 0;
            $checkStmt->close();
            if ($alreadyOwned) {
                throw new RuntimeException('您已經領取過此優惠卷了。');
            }

            $insertStmt = $conn->prepare("INSERT INTO coupon_distributions (coupon_id, user_id, quantity, target_type, sent_by_admin_id) VALUES (?, ?, 1, 'SINGLE', 0)");
            if (!$insertStmt) {
                throw new RuntimeException('建立領取紀錄失敗。');
            }
            $insertStmt->bind_param('ii', $couponId, $userId);
            if (!$insertStmt->execute()) {
                throw new RuntimeException('建立領取紀錄失敗。');
            }
            $insertStmt->close();

            $updateStmt = $conn->prepare('UPDATE coupons SET used_count = used_count + 1 WHERE coupon_id = ?');
            $updateStmt->bind_param('i', $couponId);
            if (!$updateStmt->execute()) {
                throw new RuntimeException('更新領取數失敗。');
            }
            $updateStmt->close();

            $conn->commit();
            $couponRedirect('success', '成功領取優惠卷！');
        } catch (Throwable $e) {
            $conn->rollback();
            $couponRedirect('error', $e->getMessage());
        }
    }

    if ($action === 'redeem_coupon_code') {
        $redeemCode = strtoupper(trim((string)($_POST['coupon_code'] ?? '')));
        $redeemCode = preg_replace('/\s+/', '', $redeemCode);
        if ($redeemCode === '') {
            $couponRedirect('error', '請輸入優惠碼。');
        }

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('SELECT * FROM coupons WHERE coupon_code = ? LIMIT 1 FOR UPDATE');
            if (!$stmt) {
                throw new RuntimeException('讀取優惠碼失敗。');
            }
            $stmt->bind_param('s', $redeemCode);
            $stmt->execute();
            $couponRes = $stmt->get_result();
            $coupon = ($couponRes && $couponRes->num_rows > 0) ? $couponRes->fetch_assoc() : null;
            $stmt->close();

            if (!$coupon) {
                throw new RuntimeException('找不到此優惠碼。');
            }

            $couponId = (int)$coupon['coupon_id'];
            $now = time();
            $start = !empty($coupon['start_at']) ? strtotime($coupon['start_at']) : false;
            $end = !empty($coupon['end_at']) ? strtotime($coupon['end_at']) : false;

            if ((int)$coupon['is_active'] !== 1 || ($start !== false && $now < $start) || ($end !== false && $now > $end)) {
                throw new RuntimeException('此優惠碼無效或已過期。');
            }
            if ((int)($coupon['usage_limit'] ?? 0) > 0 && (int)($coupon['used_count'] ?? 0) >= (int)$coupon['usage_limit']) {
                throw new RuntimeException('此優惠碼已被兌換完畢。');
            }
            if (!empty($coupon['target_membership']) && $coupon['target_membership'] !== $userLevel) {
                throw new RuntimeException('您的會員等級不符合此專屬代碼的兌換資格。');
            }

            $checkStmt = $conn->prepare('SELECT distribution_id FROM coupon_distributions WHERE user_id = ? AND coupon_id = ? LIMIT 1');
            if (!$checkStmt) {
                throw new RuntimeException('檢查兌換紀錄失敗。');
            }
            $checkStmt->bind_param('ii', $userId, $couponId);
            $checkStmt->execute();
            $checkRes = $checkStmt->get_result();
            $alreadyOwned = $checkRes && $checkRes->num_rows > 0;
            $checkStmt->close();
            if ($alreadyOwned) {
                throw new RuntimeException('您已經兌換過這個優惠碼了。');
            }

            $insertStmt = $conn->prepare("INSERT INTO coupon_distributions (coupon_id, user_id, quantity, target_type, sent_by_admin_id) VALUES (?, ?, 1, 'USE CODE', 0)");
            if (!$insertStmt) {
                throw new RuntimeException('建立兌換紀錄失敗。');
            }
            $insertStmt->bind_param('ii', $couponId, $userId);
            if (!$insertStmt->execute()) {
                throw new RuntimeException('建立兌換紀錄失敗。');
            }
            $insertStmt->close();

            $codeUseStmt = $conn->prepare('INSERT INTO coupon_code_uses (coupon_id, user_id, coupon_code) VALUES (?, ?, ?)');
            if (!$codeUseStmt) {
                throw new RuntimeException('建立優惠碼紀錄失敗。');
            }
            $codeUseStmt->bind_param('iis', $couponId, $userId, $redeemCode);
            if (!$codeUseStmt->execute()) {
                throw new RuntimeException('建立優惠碼紀錄失敗。');
            }
            $codeUseStmt->close();

            $updateStmt = $conn->prepare('UPDATE coupons SET used_count = used_count + 1 WHERE coupon_id = ?');
            $updateStmt->bind_param('i', $couponId);
            if (!$updateStmt->execute()) {
                throw new RuntimeException('更新兌換數失敗。');
            }
            $updateStmt->close();

            $conn->commit();
            $couponRedirect('success', '成功兌換專屬優惠碼！');
        } catch (Throwable $e) {
            $conn->rollback();
            $couponRedirect('error', $e->getMessage());
        }
    }

    if ($action === 'submit_profile_review') {
        $reviewOrderId = (int)($_POST['order_id'] ?? 0);
        $reviewProductId = (int)($_POST['product_id'] ?? 0);
        $rating = max(1, min(5, (int)($_POST['rating'] ?? 5)));
        $comment = trim((string)($_POST['comment'] ?? ''));
        $rewardPoints = 50; // 設定評論可獲得的紅利點數

        if ($reviewOrderId <= 0 || $reviewProductId <= 0) {
            $couponRedirect('error', '資料錯誤，請重新操作。');
        }

        $conn->begin_transaction();
        try {
            $checkStmt = $conn->prepare("
                SELECT o.order_id 
                FROM orders o
                JOIN order_items oi ON o.order_id = oi.order_id
                JOIN product_variants pv ON oi.variant_id = pv.variant_id
                LEFT JOIN product_reviews pr ON pr.order_id = o.order_id AND pr.product_id = pv.product_id
                WHERE o.user_id = ? AND o.order_id = ? AND pv.product_id = ? 
                  AND o.status IN ('DELIVERED', 'COMPLETED')
                  AND pr.review_id IS NULL
                LIMIT 1
            ");
            $checkStmt->bind_param('iii', $userId, $reviewOrderId, $reviewProductId);
            $checkStmt->execute();
            $isValid = $checkStmt->get_result()->num_rows > 0;
            $checkStmt->close();

            if (!$isValid) {
                throw new RuntimeException('找不到符合條件的待評論商品，或您已經評論過了。');
            }

            // 寫入評論
            $insertReview = $conn->prepare("INSERT INTO product_reviews (product_id, user_id, order_id, rating, comment, is_visible) VALUES (?, ?, ?, ?, ?, 1)");
            $insertReview->bind_param('iiiis', $reviewProductId, $userId, $reviewOrderId, $rating, $comment);
            if (!$insertReview->execute()) throw new RuntimeException('評論送出失敗。');
            $insertReview->close();

            // 發放紅利點數
            $updatePoints = $conn->prepare("UPDATE users SET points_balance = points_balance + ? WHERE user_id = ?");
            $updatePoints->bind_param('ii', $rewardPoints, $userId);
            if (!$updatePoints->execute()) throw new RuntimeException('點數發放失敗。');
            $updatePoints->close();

            $conn->commit();
            $couponRedirect('success', '評論已成功送出！獲得 ' . $rewardPoints . ' 點紅利。');
        } catch (Throwable $e) {
            $conn->rollback();
            $couponRedirect('error', $e->getMessage());
        }
    }

    // 💡 內聚整合：在這裡直接處理「確認完成訂單」
    if ($action === 'complete_order') {
        $orderId = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        if ($orderId <= 0) {
            $couponRedirect('error', '無效的訂單。');
        }

        $conn->begin_transaction();
        try {
            // 1. 驗證訂單歸屬與狀態是否為 DELIVERED
            $stmt = $conn->prepare("SELECT total_amount FROM orders WHERE order_id = ? AND user_id = ? AND status = 'DELIVERED' FOR UPDATE");
            $stmt->bind_param('ii', $orderId, $userId);
            $stmt->execute();
            $orderRes = $stmt->get_result();
            $order = $orderRes->fetch_assoc();
            $stmt->close();

            if (!$order) {
                throw new RuntimeException('找不到該訂單，或訂單狀態非待確認。');
            }

            // 2. 更新狀態為 COMPLETED
            $updateOrder = $conn->prepare("UPDATE orders SET status = 'COMPLETED' WHERE order_id = ?");
            $updateOrder->bind_param('i', $orderId);
            if (!$updateOrder->execute()) throw new RuntimeException('更新訂單狀態失敗。');
            $updateOrder->close();

            // 3. 計算 1% 消費回饋點數
            $rewardPoints = max(1, floor((float)$order['total_amount'] * 0.01));

            // 發放點數
            $updatePoints = $conn->prepare("UPDATE users SET points_balance = points_balance + ? WHERE user_id = ?");
            $updatePoints->bind_param('ii', $rewardPoints, $userId);
            if (!$updatePoints->execute()) throw new RuntimeException('點數回饋發放失敗。');
            $updatePoints->close();

            // 4. 寫入站內消息通知
            if (tableExists($conn, 'user_notifications')) {
                $title = "🎉 訂單完成與點數入帳通知";
                $msg = "您的訂單 (#" . $orderId . ") 已確認完成！感謝您的購買，系統已發放 " . $rewardPoints . " 點紅利點數至您的帳戶。別忘了前往下方填寫評價賺取額外紅利喔！";
                $notifyStmt = $conn->prepare("INSERT INTO user_notifications (user_id, title, message) VALUES (?, ?, ?)");
                $notifyStmt->bind_param('iss', $userId, $title, $msg);
                $notifyStmt->execute();
                $notifyStmt->close();
            }

            $conn->commit();
            $couponRedirect('success', "訂單已確認完成！恭喜獲得 {$rewardPoints} 點紅利回饋。");
        } catch (Throwable $e) {
            $conn->rollback();
            $couponRedirect('error', $e->getMessage());
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

// 💡 撈取「待評論」商品 (已送達或已完成，且無評論紀錄)
$pendingReviews = [];
if (tableExists($conn, 'product_reviews')) {
    $pendingSql = "
        SELECT 
            o.order_id, o.order_number, o.created_at AS order_date,
            p.product_id, p.name AS product_name,
            MIN(pi.image_url) AS image_url
        FROM orders o
        JOIN order_items oi ON o.order_id = oi.order_id
        JOIN product_variants pv ON oi.variant_id = pv.variant_id
        JOIN products p ON pv.product_id = p.product_id
        LEFT JOIN product_images pi ON p.product_id = pi.product_id AND pi.is_main = 1
        LEFT JOIN product_reviews pr ON pr.order_id = o.order_id AND pr.product_id = p.product_id
        WHERE o.user_id = {$userId} 
          AND o.status IN ('DELIVERED', 'COMPLETED') 
          AND pr.review_id IS NULL
        GROUP BY o.order_id, p.product_id
        ORDER BY o.created_at DESC
    ";
    $pendingReviews = fetchAssocRows($conn, $pendingSql);
}

// 💡 撈取「我的歷史評論」
$myReviews = [];
if (tableExists($conn, 'product_reviews')) {
    $myReviewsSql = "
        SELECT pr.rating, pr.comment, pr.created_at, p.product_id, p.name AS product_name
        FROM product_reviews pr
        JOIN products p ON pr.product_id = p.product_id
        WHERE pr.user_id = {$userId}
        ORDER BY pr.created_at DESC
    ";
    $myReviews = fetchAssocRows($conn, $myReviewsSql);
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
      AND (usage_limit IS NULL OR usage_limit = 0 OR used_count < usage_limit)
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
            <div style="display:flex; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:12px;">
                <h2 style="font-size:20px; margin:0;">購買紀錄</h2>
                <?php 
                // 檢查是否有尚未點擊「完成訂單」的項目 (狀態為 DELIVERED)
                $hasUncompletedOrder = false;
                if (!empty($orderRows)) {
                    foreach ($orderRows as $o) {
                        if ($o['status'] === 'DELIVERED') {
                            $hasUncompletedOrder = true;
                            break;
                        }
                    }
                }
                ?>
                <?php if ($hasUncompletedOrder): ?>
                    <span style="color:#b91c1c; font-size:14px; font-weight:700;">
                        ⚠️ 您有待確認的訂單，點選「完成訂單」即可獲得 1% 紅利點數！
                    </span>
                <?php endif; ?>
            </div>

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
                                            <form method="POST" action="profile.php" style="margin:0;">
                                                <?php if(function_exists('apCsrfField')) echo apCsrfField(); ?>
                                                <input type="hidden" name="action" value="complete_order">
                                                <input type="hidden" name="order_id" value="<?php echo intval($o['order_id']); ?>">
                                                <button type="submit" style="padding:6px 10px; border-radius:999px; background:#b91c1c; color:#fff; font-weight:700; font-size:12px; border:none; cursor:pointer;">完成訂單</button>
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
                                    <?php echo !empty($coupon['target_membership']) ? '限'.htmlspecialchars(membershipLevelText($coupon['target_membership'])) : '不限等級'; ?>
                                </td>
                                <td style="padding:12px 6pxStorage;"><?php echo htmlspecialchars($coupon['end_at'] ?? '無期限'); ?></td>
                                <td style="padding:12px 6px;">
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="action" value="claim_coupon">
                                        <input type="hidden" name="coupon_id" value="<?php echo intval($coupon['coupon_id']); ?>">
                                        <?php if(function_exists('apCsrfField')) echo apCsrfField(); ?>
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

    <section style="background:#fff; border:1px solid #eee; border-radius:12px; padding:18px; margin-top:16px;">
        <h2 style="font-size:20px; margin-bottom:16px; color:#111;">✍️ 商品評論 (寫評價賺點數！)</h2>
        
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(300px, 1fr)); gap:24px;">
            
            <div>
                <h3 style="font-size:16px; margin-bottom:12px; border-bottom:2px solid #db6b6b; padding-bottom:8px; display:inline-block;">待評論商品</h3>
                <?php if (!empty($pendingReviews)): ?>
                    <div style="display:flex; flex-direction:column; gap:16px;">
                        <?php foreach ($pendingReviews as $item): ?>
                            <div style="border:1px solid #e5e7eb; border-radius:8px; padding:12px; background:#fafafa;">
                                <div style="display:flex; align-items:center; gap:12px; margin-bottom:12px;">
                                    <?php if (!empty($item['image_url'])): ?>
                                        <img src="<?php echo htmlspecialchars('../' . ltrim($item['image_url'], '/')); ?>" style="width:50px; height:50px; object-fit:cover; border-radius:6px;">
                                    <?php else: ?>
                                        <div style="width:50px; height:50px; background:#ddd; border-radius:6px; display:flex; align-items:center; justify-content:center; font-size:10px;">無圖</div>
                                    <?php endif; ?>
                                    <div>
                                        <div style="font-weight:700; font-size:14px;"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                        <div style="font-size:12px; color:#666;">訂單: <?php echo htmlspecialchars($item['order_number'] ?: '#'.$item['order_id']); ?></div>
                                    </div>
                                </div>
                                
                                <form method="POST" style="display:flex; flex-direction:column; gap:8px;">
                                    <input type="hidden" name="action" value="submit_profile_review">
                                    <input type="hidden" name="order_id" value="<?php echo intval($item['order_id']); ?>">
                                    <input type="hidden" name="product_id" value="<?php echo intval($item['product_id']); ?>">
                                    <?php if(function_exists('apCsrfField')) echo apCsrfField(); ?>
                                    
                                    <div class="star-rating">
                                        <input type="radio" id="star5_<?php echo $item['order_id'].'_'.$item['product_id']; ?>" name="rating" value="5" checked /><label for="star5_<?php echo $item['order_id'].'_'.$item['product_id']; ?>" title="5星">★</label>
                                        <input type="radio" id="star4_<?php echo $item['order_id'].'_'.$item['product_id']; ?>" name="rating" value="4" /><label for="star4_<?php echo $item['order_id'].'_'.$item['product_id']; ?>" title="4星">★</label>
                                        <input type="radio" id="star3_<?php echo $item['order_id'].'_'.$item['product_id']; ?>" name="rating" value="3" /><label for="star3_<?php echo $item['order_id'].'_'.$item['product_id']; ?>" title="3星">★</label>
                                        <input type="radio" id="star2_<?php echo $item['order_id'].'_'.$item['product_id']; ?>" name="rating" value="2" /><label for="star2_<?php echo $item['order_id'].'_'.$item['product_id']; ?>" title="2星">★</label>
                                        <input type="radio" id="star1_<?php echo $item['order_id'].'_'.$item['product_id']; ?>" name="rating" value="1" /><label for="star1_<?php echo $item['order_id'].'_'.$item['product_id']; ?>" title="1星">★</label>
                                    </div>
                                    
                                    <textarea name="comment" rows="2" placeholder="分享您的使用心得以獲得點數..." style="width:100%; padding:8px; border:1px solid #ccc; border-radius:6px; resize:vertical;" required></textarea>
                                    
                                    <button type="submit" style="padding:8px; border:none; border-radius:6px; background:#db6b6b; color:#fff; font-weight:700; cursor:pointer;">送出評論 (+50點)</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p style="color:#777; font-size:14px;">目前沒有待評論的商品。快去購物完成訂單吧！</p>
                <?php endif; ?>
            </div>

            <div>
                <h3 style="font-size:16px; margin-bottom:12px; border-bottom:2px solid #111; padding-bottom:8px; display:inline-block;">我的評論紀錄</h3>
                <?php if (!empty($myReviews)): ?>
                    <div style="display:flex; flex-direction:column; gap:12px; max-height:500px; overflow-y:auto; padding-right:8px;">
                        <?php foreach ($myReviews as $review): ?>
                            <div style="border-bottom:1px solid #eee; padding-bottom:12px;">
                                <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
                                    <a href="product_detail.php?id=<?php echo intval($review['product_id']); ?>" style="font-weight:700; color:#111; text-decoration:none; font-size:14px;">
                                        <?php echo htmlspecialchars($review['product_name']); ?>
                                    </a>
                                    <span style="color:#f59e0b; font-size:14px;"><?php echo str_repeat('★', $review['rating']) . str_repeat('☆', 5 - $review['rating']); ?></span>
                                </div>
                                <p style="font-size:13px; color:#444; margin:0 0 6px 0; line-height:1.5;">
                                    <?php echo nl2br(htmlspecialchars($review['comment'])); ?>
                                </p>
                                <div style="font-size:11px; color:#999;"><?php echo htmlspecialchars(substr($review['created_at'], 0, 10)); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p style="color:#777; font-size:14px;">尚無評論紀錄。</p>
                <?php endif; ?>
            </div>
            
        </div>
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
/* 💡 新增：商品評價星星特效 CSS */
.star-rating {
    display: inline-flex;
    flex-direction: row-reverse;
    justify-content: flex-end;
}
.star-rating input {
    display: none;
}
.star-rating label {
    font-size: 24px;
    color: #d1d5db;
    cursor: pointer;
    padding: 0 2px;
    transition: color 0.2s;
}
.star-rating input:checked ~ label {
    color: #f59e0b;
}
.star-rating label:hover,
.star-rating label:hover ~ label {
    color: #fcd34d;
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
<?php
$pageTitle = '優惠商品 | All Pass';
$activeNav = 'promotions';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/storefront_helpers.php';
require_once __DIR__ . '/includes/promotion_price_sync.php';
require_once __DIR__ . '/includes/price_helper.php';

$conn = new mysqli('localhost', 'root', '', 'all_pass_db');
if ($conn->connect_error) {
    error_log('Promotion products database connection failed: ' . $conn->connect_error);
    http_response_code(500);
    echo '系統暫時無法連線，請稍後再試。';
    exit;
}
$conn->set_charset('utf8mb4');
$conn->query("SET time_zone = '+08:00'");
apRunPromotionSync($conn);

$currentUserMembershipLevel = !empty($_SESSION['user_id']) ? apFetchUserMembershipLevel($conn, intval($_SESSION['user_id'])) : null;
$isMemberPriceEligible = apIsMemberPriceEligible($currentUserMembershipLevel);

function ppH($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ppTableExists($conn, $tableName) {
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return ($res && $res->num_rows > 0);
}

function ppFetchRow($conn, $sql) {
    $res = $conn->query($sql);
    if ($res && $res->num_rows > 0) {
        return $res->fetch_assoc();
    }
    return null;
}

function ppFetchRows($conn, $sql) {
    $rows = [];
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function ppStatusLabel($startAt, $endAt) {
    $now = time();
    $startTime = strtotime($startAt);
    $endTime = strtotime($endAt);
    if ($now < $startTime) {
        return ['未開始', 'pp-off'];
    }
    if ($now > $endTime) {
        return ['已結束', 'pp-off'];
    }
    return ['進行中', 'pp-on'];
}

function ppDiscountPrice($basePrice, $discountType, $discountValue) {
    $basePrice = max(0, (float)$basePrice);
    $discountValue = (float)$discountValue;

    if ($discountType === 'PERCENT') {
        return max(0, $basePrice * (1 - ($discountValue / 100)));
    }

    return max(0, $basePrice - $discountValue);
}

function ppDateRange($startAt, $endAt) {
    return date('Y.m.d', strtotime($startAt)) . ' - ' . date('Y.m.d', strtotime($endAt));
}

function ppDiscountCopy($type, $value) {
    $value = (float)$value;
    if ($type === 'PERCENT') {
        return '現折 ' . rtrim(rtrim(number_format($value, 2), '0'), '.') . '%';
    }
    return '折抵 NT$ ' . number_format($value);
}

$promotionId = isset($_GET['promotion_id']) ? intval($_GET['promotion_id']) : 0;
$promotion = null;
$productRows = [];

if ($promotionId > 0 && ppTableExists($conn, 'promotions') && ppTableExists($conn, 'promotion_products')) {
    $promotion = ppFetchRow($conn, "SELECT id, name, promotion_image_url, description, discount_type, discount_value, start_at, end_at, is_active FROM promotions WHERE id = {$promotionId} AND is_active = 1 LIMIT 1");
    if ($promotion) {
        $imageOrderBy = sfProductImageOrder($conn, 'pi');
        $sql = "
            SELECT
                p.product_id,
                p.name AS product_name,
                COALESCE((
                    SELECT pi.image_url
                    FROM product_images pi
                    WHERE pi.product_id = p.product_id
                    ORDER BY {$imageOrderBy}
                    LIMIT 1
                ), '') AS image_url,
                COALESCE(MIN(COALESCE(v.original_price, 0)), 0) AS original_price,
                MIN(v.special_price) AS special_price,
                COALESCE(MIN(COALESCE(v.member_price, v.original_price, 0)), 0) AS member_price
            FROM promotion_products pp
            INNER JOIN products p ON p.product_id = pp.product_id
            LEFT JOIN product_variants v ON v.product_id = p.product_id
            WHERE pp.promotion_id = {$promotionId}
            GROUP BY p.product_id, p.name
            ORDER BY p.product_id DESC
        ";
        $productRows = ppFetchRows($conn, $sql);
    }
}

include 'header.php';
?>

<style>
    .pp-wrap { max-width: 1200px; margin: 0 auto; padding: 170px 5% 70px; }
    .pp-hero { margin-bottom: 24px; padding: 32px; border-radius: 8px; background: #111827; border: 1px solid #111827; }
    .pp-hero h1 { font-size: 34px; margin-bottom: 10px; color: #1f2937; }
    .pp-hero h1 { color: #fff; }
    .pp-hero p { color: rgba(255,255,255,0.82); line-height: 1.8; }
    .pp-list { display: grid; gap: 18px; }
    .pp-card { display: grid; grid-template-columns: 220px 1fr auto; gap: 18px; align-items: stretch; background: #fff; border: 1px solid #ececec; border-radius: 8px; overflow: hidden; box-shadow: 0 10px 25px rgba(15, 23, 42, 0.04); }
    .pp-image { min-height: 170px; background: #f5f5f5; }
    .pp-image img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .pp-body { padding: 22px 0; display: flex; flex-direction: column; justify-content: center; }
    .pp-name { font-size: 24px; font-weight: 800; color: #111827; margin-bottom: 10px; }
    .pp-meta { display: flex; flex-wrap: wrap; gap: 10px; color: #6b7280; font-size: 14px; }
    .pp-meta span { padding: 7px 12px; border-radius: 999px; background: #f8fafc; border: 1px solid #e5e7eb; }
    .pp-aside { padding: 22px 22px 22px 0; display: flex; flex-direction: column; justify-content: center; align-items: flex-end; gap: 12px; min-width: 180px; }
    .pp-price { font-size: 30px; font-weight: 900; color: #db6b6b; line-height: 1; }
    .pp-price small { display: block; font-size: 13px; color: #6b7280; font-weight: 600; margin-top: 8px; }
    .pp-badge { display: inline-flex; align-items: center; justify-content: center; padding: 6px 12px; border-radius: 999px; font-size: 12px; font-weight: 800; letter-spacing: .04em; }
    .pp-on { background: #dcfce7; color: #166534; }
    .pp-off { background: #fee2e2; color: #991b1b; }
    .pp-empty { padding: 36px; text-align: center; color: #6b7280; border: 1px dashed #ddd; border-radius: 8px; background: #fff; }
    @media (max-width: 900px) {
        .pp-card { grid-template-columns: 1fr; }
        .pp-aside { padding: 0 22px 22px; align-items: flex-start; }
        .pp-body { padding: 0 22px; }
        .pp-image { min-height: 220px; }
    }
</style>

<section class="pp-wrap">
    <?php if (!$promotion): ?>
        <div class="pp-hero">
            <h1>優惠商品</h1>
            <p>找不到對應的活動，或該活動目前未啟用。</p>
        </div>
        <div class="pp-empty">請先從活動專區點選正在進行的活動。</div>
    <?php else: ?>
        <?php [$statusText, $statusClass] = ppStatusLabel($promotion['start_at'], $promotion['end_at']); ?>
        <div class="pp-hero">
            <h1><?php echo ppH($promotion['name']); ?> - 優惠商品</h1>
            <p>以下列出此活動綁定的商品。價格已依活動折扣計算，按鈕可直接前往商品詳情頁。</p>
            <div style="margin-top:14px; display:flex; flex-wrap:wrap; gap:10px;">
                <span class="pp-badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                <span class="pp-badge pp-off"><?php echo ppH(ppDiscountCopy($promotion['discount_type'], $promotion['discount_value'])); ?></span>
                <span class="pp-badge" style="background:#f8fafc; color:#334155;">活動期間：<?php echo ppH(ppDateRange($promotion['start_at'], $promotion['end_at'])); ?></span>
            </div>
        </div>

        <?php if (empty($productRows)): ?>
            <div class="pp-empty">這個活動目前沒有綁定任何商品。</div>
        <?php else: ?>
            <div class="pp-list">
                <?php foreach ($productRows as $row): ?>
                    <?php
                    $originalPrice = (float)$row['original_price'];
                    $memberPrice = (float)$row['member_price'];
                    $priceInfo = apResolveVariantPrice([
                        'original_price' => $originalPrice,
                        'special_price' => $row['special_price'],
                        'member_price' => $memberPrice,
                    ], $isMemberPriceEligible);
                    $discountPrice = (float)$priceInfo['final_price'];
                    $imageUrl = !empty($row['image_url']) ? '../' . ltrim($row['image_url'], '/') : '';
                    $priceLabel = $priceInfo['headline_label'];
                    ?>
                    <article class="pp-card">
                        <div class="pp-image">
                            <?php if ($imageUrl !== ''): ?>
                                <img src="<?php echo ppH($imageUrl); ?>" alt="<?php echo ppH($row['product_name']); ?>">
                            <?php else: ?>
                                <div style="width:100%; height:100%; display:flex; align-items:center; justify-content:center; color:#9ca3af; font-weight:700;">No Image</div>
                            <?php endif; ?>
                        </div>
                        <div class="pp-body">
                            <div class="pp-name"><?php echo ppH($row['product_name']); ?></div>
                            <div class="pp-meta">
                                <span>原價起：NT$ <?php echo number_format($originalPrice); ?></span>
                                <?php if ($isMemberPriceEligible): ?>
                                    <span>會員價起：NT$ <?php echo number_format($memberPrice > 0 ? $memberPrice : $originalPrice); ?></span>
                                <?php endif; ?>
                                <span><?php echo ppH($priceLabel); ?>：NT$ <?php echo number_format($discountPrice); ?></span>
                            </div>
                        </div>
                        <div class="pp-aside">
                            <div class="pp-price">
                                NT$ <?php echo number_format($discountPrice); ?>
                                <small><?php echo ppH($priceLabel); ?></small>
                            </div>
                            <a href="product_detail.php?id=<?php echo intval($row['product_id']); ?>" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 16px; border-radius:999px; background:#111; color:#fff; font-weight:700;">查看商品詳情</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php include 'footer.php'; $conn->close(); ?>

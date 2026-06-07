<?php
$pageTitle = '活動專區 | All Pass';
$activeNav = 'promotions';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/storefront_helpers.php';

$conn = new mysqli('localhost', 'root', '', 'all_pass_db');
if ($conn->connect_error) {
    error_log('Promotions database connection failed: ' . $conn->connect_error);
    http_response_code(500);
    echo '系統暫時無法連線，請稍後再試。';
    exit;
}
$conn->set_charset('utf8mb4');

function promoH($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function promoStatusLabel($startAt, $endAt) {
    $now = time();
    $startTime = strtotime($startAt);
    $endTime = strtotime($endAt);
    if ($now < $startTime) {
        return ['未開始', 'ps-off'];
    }
    if ($now > $endTime) {
        return ['已結束', 'ps-off'];
    }
    return ['進行中', 'ps-on'];
}

function promoDateRange($startAt, $endAt) {
    return date('Y.m.d', strtotime($startAt)) . ' - ' . date('Y.m.d', strtotime($endAt));
}

function promoDiscountCopy($type, $value) {
    $value = (float)$value;
    if ($type === 'PERCENT') {
        return '現折 ' . rtrim(rtrim(number_format($value, 2), '0'), '.') . '%';
    }
    return '折抵 NT$ ' . number_format($value);
}

$promotions = [];
$bannerSelect = sfTableExists($conn, 'promotion_banners')
    ? "(SELECT pb.banner_image_url FROM promotion_banners pb WHERE pb.promotion_id = p.id ORDER BY pb.sort_order ASC LIMIT 1) AS banner_image_url"
    : "NULL AS banner_image_url";
$sql = "
    SELECT p.id, p.name, p.promotion_image_url, {$bannerSelect}, p.description, p.discount_type, p.discount_value, p.start_at, p.end_at, p.is_active
    FROM promotions p
    WHERE p.is_active = 1
    ORDER BY p.start_at DESC, p.id DESC
";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $promotions[] = $row;
    }
}

include 'header.php';
?>

<style>
    .promo-wrap { max-width: 1200px; margin: 0 auto; padding: 170px 5% 70px; }
    .promo-hero { margin-bottom: 24px; padding: 32px; border-radius: 8px; background: #111827; color: #fff; border: 1px solid #111827; }
    .promo-hero h1 { font-size: 34px; margin-bottom: 10px; color: #1f2937; }
    .promo-hero h1 { color: #fff; }
    .promo-hero p { color: rgba(255,255,255,0.82); line-height: 1.8; }
    .promo-list { display: grid; gap: 18px; }
    .promo-card { display: grid; grid-template-columns: 260px 1fr auto; gap: 18px; align-items: stretch; background: #fff; border: 1px solid #ececec; border-radius: 8px; overflow: hidden; box-shadow: 0 10px 25px rgba(15, 23, 42, 0.04); }
    .promo-image { min-height: 180px; background: #f5f5f5; }
    .promo-image img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .promo-body { padding: 22px 0; display: flex; flex-direction: column; justify-content: center; }
    .promo-name { font-size: 24px; font-weight: 800; color: #111827; margin-bottom: 10px; }
    .promo-desc { color: #4b5563; line-height: 1.8; margin-bottom: 14px; max-width: 760px; }
    .promo-meta { display: flex; flex-wrap: wrap; gap: 10px; color: #6b7280; font-size: 14px; }
    .promo-meta span { padding: 7px 12px; border-radius: 999px; background: #f8fafc; border: 1px solid #e5e7eb; }
    .promo-meta .promo-highlight { background: #fff7ed; color: #c2410c; border-color: #fed7aa; font-weight: 800; }
    .promo-aside { padding: 22px 22px 22px 0; display: flex; flex-direction: column; justify-content: center; align-items: flex-end; gap: 12px; min-width: 170px; }
    .promo-discount { font-size: 30px; font-weight: 900; color: #db6b6b; line-height: 1; }
    .promo-discount small { display: block; font-size: 13px; color: #6b7280; font-weight: 600; margin-top: 8px; }
    .promo-badge { display: inline-flex; align-items: center; justify-content: center; padding: 6px 12px; border-radius: 999px; font-size: 12px; font-weight: 800; letter-spacing: .04em; }
    .ps-on { background: #dcfce7; color: #166534; }
    .ps-off { background: #fee2e2; color: #991b1b; }
    .promo-empty { padding: 36px; text-align: center; color: #6b7280; border: 1px dashed #ddd; border-radius: 8px; background: #fff; }
    @media (max-width: 900px) {
        .promo-card { grid-template-columns: 1fr; }
        .promo-aside { padding: 0 22px 22px; align-items: flex-start; }
        .promo-body { padding: 0 22px; }
        .promo-image { min-height: 220px; }
    }
</style>

<section class="promo-wrap">
    <div class="promo-hero">
        <h1>SALE 優惠專區</h1>
        <p>精選活動與期間限定優惠，挑選適合下一段旅程的行李箱。</p>
    </div>

    <?php if (empty($promotions)): ?>
        <div class="promo-empty">目前沒有啟用中的活動。</div>
    <?php else: ?>
        <div class="promo-list">
            <?php foreach ($promotions as $promo): ?>
                <?php [$statusText, $statusClass] = promoStatusLabel($promo['start_at'], $promo['end_at']); ?>
                <?php
                $promoImage = '';
                if (!empty($promo['promotion_image_url']) && sfPublicFileExists($promo['promotion_image_url'])) {
                    $promoImage = $promo['promotion_image_url'];
                } elseif (!empty($promo['banner_image_url']) && sfPublicFileExists($promo['banner_image_url'])) {
                    $promoImage = $promo['banner_image_url'];
                }
                ?>
                <article class="promo-card">
                    <div class="promo-image">
                        <?php if (!empty($promoImage)): ?>
                            <img src="../<?php echo promoH($promoImage); ?>" alt="<?php echo promoH($promo['name']); ?>">
                        <?php else: ?>
                            <div style="width:100%; height:100%; display:flex; align-items:center; justify-content:center; color:#9ca3af; font-weight:700;">No Image</div>
                        <?php endif; ?>
                    </div>
                    <div class="promo-body">
                        <div class="promo-badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></div>
                        <div class="promo-name"><?php echo promoH($promo['name']); ?></div>
                        <div class="promo-desc"><?php echo promoH($promo['description'] !== '' ? $promo['description'] : '暫無活動說明'); ?></div>
                        <div class="promo-meta">
                            <span class="promo-highlight"><?php echo promoH(promoDiscountCopy($promo['discount_type'], $promo['discount_value'])); ?></span>
                            <span>活動期間：<?php echo promoH(promoDateRange($promo['start_at'], $promo['end_at'])); ?></span>
                        </div>
                    </div>
                    <div class="promo-aside">
                        <div class="promo-discount">
                            <?php echo promoH(promoDiscountCopy($promo['discount_type'], $promo['discount_value'])); ?>
                            <small>限時優惠</small>
                        </div>
                        <a href="promotion_products.php?promotion_id=<?php echo intval($promo['id']); ?>" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 16px; border-radius:999px; background:#111; color:#fff; font-weight:700;">查看優惠商品</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php include 'footer.php'; $conn->close(); ?>

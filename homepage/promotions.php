<?php
$pageTitle = '活動專區 | All Pass';
$activeNav = 'promotions';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$conn = new mysqli('localhost', 'root', '', 'all_pass_db');
if ($conn->connect_error) {
    die('資料庫連線失敗: ' . $conn->connect_error);
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

$promotions = [];
$sql = "
    SELECT id, name, promotion_image_url, description, discount_type, discount_value, start_at, end_at, is_active
    FROM promotions
    WHERE is_active = 1
    ORDER BY start_at DESC, id DESC
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
    .promo-hero { margin-bottom: 24px; padding: 28px; border-radius: 24px; background: linear-gradient(135deg, rgba(219,107,107,0.12), rgba(26,26,26,0.04)); border: 1px solid #eee; }
    .promo-hero h1 { font-size: 34px; margin-bottom: 10px; color: #1f2937; }
    .promo-hero p { color: #6b7280; line-height: 1.8; }
    .promo-list { display: grid; gap: 18px; }
    .promo-card { display: grid; grid-template-columns: 260px 1fr auto; gap: 18px; align-items: stretch; background: #fff; border: 1px solid #ececec; border-radius: 20px; overflow: hidden; box-shadow: 0 10px 25px rgba(15, 23, 42, 0.04); }
    .promo-image { min-height: 180px; background: #f5f5f5; }
    .promo-image img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .promo-body { padding: 22px 0; display: flex; flex-direction: column; justify-content: center; }
    .promo-name { font-size: 24px; font-weight: 800; color: #111827; margin-bottom: 10px; }
    .promo-desc { color: #4b5563; line-height: 1.8; margin-bottom: 14px; max-width: 760px; }
    .promo-meta { display: flex; flex-wrap: wrap; gap: 10px; color: #6b7280; font-size: 14px; }
    .promo-meta span { padding: 7px 12px; border-radius: 999px; background: #f8fafc; border: 1px solid #e5e7eb; }
    .promo-aside { padding: 22px 22px 22px 0; display: flex; flex-direction: column; justify-content: center; align-items: flex-end; gap: 12px; min-width: 170px; }
    .promo-discount { font-size: 30px; font-weight: 900; color: #db6b6b; line-height: 1; }
    .promo-discount small { display: block; font-size: 13px; color: #6b7280; font-weight: 600; margin-top: 8px; }
    .promo-badge { display: inline-flex; align-items: center; justify-content: center; padding: 6px 12px; border-radius: 999px; font-size: 12px; font-weight: 800; letter-spacing: .04em; }
    .ps-on { background: #dcfce7; color: #166534; }
    .ps-off { background: #fee2e2; color: #991b1b; }
    .promo-empty { padding: 36px; text-align: center; color: #6b7280; border: 1px dashed #ddd; border-radius: 18px; background: #fff; }
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
        <p>只顯示 <strong>啟用中</strong> 的活動。每個活動一行，方便你快速瀏覽正在進行的優惠內容。</p>
    </div>

    <?php if (empty($promotions)): ?>
        <div class="promo-empty">目前沒有啟用中的活動。</div>
    <?php else: ?>
        <div class="promo-list">
            <?php foreach ($promotions as $promo): ?>
                <?php [$statusText, $statusClass] = promoStatusLabel($promo['start_at'], $promo['end_at']); ?>
                <article class="promo-card">
                    <div class="promo-image">
                        <?php if (!empty($promo['promotion_image_url'])): ?>
                            <img src="../<?php echo promoH($promo['promotion_image_url']); ?>" alt="<?php echo promoH($promo['name']); ?>">
                        <?php else: ?>
                            <div style="width:100%; height:100%; display:flex; align-items:center; justify-content:center; color:#9ca3af; font-weight:700;">No Image</div>
                        <?php endif; ?>
                    </div>
                    <div class="promo-body">
                        <div class="promo-badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></div>
                        <div class="promo-name"><?php echo promoH($promo['name']); ?></div>
                        <div class="promo-desc"><?php echo promoH($promo['description'] !== '' ? $promo['description'] : '暫無活動說明'); ?></div>
                        <div class="promo-meta">
                            <span>開始：<?php echo promoH($promo['start_at']); ?></span>
                            <span>結束：<?php echo promoH($promo['end_at']); ?></span>
                            <span>類型：<?php echo promoH($promo['discount_type'] === 'PERCENT' ? '百分比折扣' : '固定折抵'); ?></span>
                        </div>
                    </div>
                    <div class="promo-aside">
                        <div class="promo-discount">
                            <?php if ($promo['discount_type'] === 'PERCENT'): ?>
                                <?php echo promoH(rtrim(rtrim(number_format((float)$promo['discount_value'], 2), '0'), '.')); ?>%
                            <?php else: ?>
                                NT$ <?php echo number_format((float)$promo['discount_value']); ?>
                            <?php endif; ?>
                            <small>活動優惠</small>
                        </div>
                        <a href="promotion_products.php?promotion_id=<?php echo intval($promo['id']); ?>" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 16px; border-radius:999px; background:#111; color:#fff; font-weight:700;">查看優惠商品</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php include 'footer.php'; $conn->close(); ?>
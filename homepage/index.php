<?php
$pageTitle = 'All Pass 行李箱專賣 | Your All-Access Pass';
$activeNav = '';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 💡 確保 PHP 使用台灣時區
function h($value) {
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
require_once __DIR__ . '/includes/storefront_helpers.php';
require_once __DIR__ . '/includes/price_helper.php';

$conn = new mysqli("localhost", "root", "", "all_pass_db");
if ($conn->connect_error) {
    error_log('Homepage database connection failed: ' . $conn->connect_error);
    http_response_code(500);
    echo '系統暫時無法連線，請稍後再試。';
    exit;
}
$conn->set_charset('utf8mb4');

// 💡 強制設定 MySQL 資料庫為台灣時區
$conn->query("SET time_zone = '+08:00'");

$currentUserMembershipLevel = !empty($_SESSION['user_id']) ? apFetchUserMembershipLevel($conn, intval($_SESSION['user_id'])) : null;
$isMemberPriceEligible = apIsMemberPriceEligible($currentUserMembershipLevel);

// 💡 判斷當前使用者是否為 VIP (或是未登入)
$isUserVip = in_array((string)$currentUserMembershipLevel, ['2', '3', 'VIP', 'VVIP'], true);

// 1. 抓取原本的行銷活動 Banner
$homepageBanners = sfFetchHomepageBanners($conn, 6);

// 2. 準備首頁跑馬燈陣列 (合併活動 Banner 與優惠券 Banner)
$marqueeItems = [];

// 將原本的行銷活動 Banner 放進陣列
if (!empty($homepageBanners)) {
    foreach ($homepageBanners as $b) {
        $marqueeItems[] = [
            'type' => 'promotion',
            'link' => 'promotion_products.php?promotion_id=' . $b['promotion_id'],
            'onclick' => '', // 活動不需要 onclick 攔截
            'name' => $b['name'],
            'image' => $b['banner_image_url'],
            'start' => $b['start_at'],
            'end' => $b['end_at'],
            'is_vip' => false
        ];
    }
}

// 3. 抓取「優惠卷」的 Banner
if ($conn->query("SHOW TABLES LIKE 'coupon_banners'")->num_rows > 0) {
    $couponSql = "
        SELECT cb.coupon_id, cb.banner_image_url, c.coupon_name, c.start_at, c.end_at, c.target_membership
        FROM coupon_banners cb
        JOIN coupons c ON c.coupon_id = cb.coupon_id
        WHERE cb.is_show_on_homepage = 1 AND c.is_active = 1
          AND (c.start_at IS NULL OR c.start_at <= NOW())
          AND (c.end_at IS NULL OR c.end_at >= NOW())
        ORDER BY cb.sort_order ASC
    ";
    $res = $conn->query($couponSql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $isVipCoupon = in_array((string)$row['target_membership'], ['2', '3', 'VIP', 'VVIP'], true);
            
            $link = 'profile.php';
            $onclick = '';

            // 💡 核心邏輯：如果是 VIP 專屬優惠卷，且目前使用者「不是 VIP」
            if ($isVipCoupon && !$isUserVip) {
                $link = 'javascript:void(0);'; // 取消預設的跳轉
                // 加上 JS Confirm 詢問視窗
                $onclick = " onclick=\"if(confirm('此為 VIP 專屬優惠卷！升級 VIP 即可領取 👑\\n\\n是否立即前往升級頁面？')) location.href='upgrade_vip.php';\" ";
            }

            $marqueeItems[] = [
                'type' => 'coupon',
                'link' => $link,
                'onclick' => $onclick,
                'name' => $row['coupon_name'],
                'image' => $row['banner_image_url'],
                'start' => $row['start_at'],
                'end' => $row['end_at'],
                'is_vip' => $isVipCoupon
            ];
        }
    }
}

include 'header.php';
?>

    <section class="hero">
        <div class="hero-text">
            <h1>ALL PASS</h1>
            <p>Pass through all the journey with you.</p>
        </div>
    </section>

    <section class="trust-badges">
        <div class="badge">🛡️ 原廠破箱保修</div>
        <div class="badge">🚚 全館滿 $3,000 免運</div>
        <div class="badge">💳 支援線上刷卡分期</div>
    </section>

    <?php if (!empty($marqueeItems)): ?>
        <section class="promo-marquee" aria-label="促銷活動">
            <div class="promo-marquee-track">
                <?php foreach (array_merge($marqueeItems, $marqueeItems) as $item): ?>
                    <a class="promo-marquee-item" href="<?php echo htmlspecialchars($item['link']); ?>" <?php echo $item['onclick']; ?>>
                        <?php if (!empty($item['image'])): ?>
                            <img src="../<?php echo htmlspecialchars(ltrim($item['image'], '/')); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>">
                        <?php endif; ?>
                        
                        <span>
                            <?php if ($item['is_vip']): ?>
                                <span style="background-color: #db6b6b; color: #fff; padding: 2px 6px; border-radius: 4px; font-size: 11px; margin-right: 4px; vertical-align: middle;">VIP專屬</span>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($item['name']); ?>
                        </span>
                        
                        <small>
                            <?php if (!empty($item['start']) && !empty($item['end'])): ?>
                                <?php echo htmlspecialchars(date('m/d', strtotime($item['start'])) . ' - ' . date('m/d', strtotime($item['end']))); ?>
                            <?php else: ?>
                                限時領取
                            <?php endif; ?>
                        </small>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="section-container">
        <h2 class="section-title">FEATURED ITEMS</h2>
        
        <div class="product-grid">
            
            <?php
                     $imageOrderBy = sfProductImageOrder($conn, 'pi2');
                     $priceSql = apVariantPriceSql('v', $isMemberPriceEligible);
                     $sql = "SELECT
                                                p.product_id,
                                                p.name,
                                                MIN({$priceSql}) AS price,
                                                COALESCE(pi_main.image_url,
                                                        (
                                                                SELECT pi2.image_url
                                                                FROM product_images pi2
                                                                WHERE pi2.product_id = p.product_id
                                                                ORDER BY {$imageOrderBy}
                                                                LIMIT 1
                                                        )
                                                ) AS image_url
                                        FROM products p
                                        LEFT JOIN product_variants v ON v.product_id = p.product_id
                                        LEFT JOIN product_images pi_main ON pi_main.product_id = p.product_id AND pi_main.is_main = 1
                                        WHERE p.is_featured = 1
                                            AND p.status = 'ON SHELF'
                                        GROUP BY p.product_id
                                        ORDER BY p.created_at DESC";

                        $result = $conn->query($sql);

            if ($result && $result->num_rows > 0) {
                while($row = $result->fetch_assoc()) {
                    echo '<div class="product-card" onclick="location.href=\'product_detail.php?id=' . $row['product_id'] . '\'">';
                    echo '  <div class="product-img-wrapper">';
                    echo '      <img src="../' . htmlspecialchars($row["image_url"]) . '" class="product-img" alt="商品圖片">';
                    echo '  </div>';
                    echo '  <div class="product-info">';
                    echo '      <div class="product-title">' . htmlspecialchars($row["name"]) . '</div>';
                    echo '      <div class="product-price">NT$ ' . number_format($row["price"]) . '</div>';
                    echo '  </div>';
                    echo '</div>';
                }
            } else {
                echo "<p style='grid-column: span 3; text-align: center; color: #999;'>目前尚無精選商品，敬請期待！</p>";
            }
            ?>

        </div>
    </section>

<?php include 'footer.php'; $conn->close(); ?>
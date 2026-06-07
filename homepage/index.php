<?php
$pageTitle = 'All Pass 行李箱專賣 | Your All-Access Pass';
$activeNav = '';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
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
$currentUserMembershipLevel = !empty($_SESSION['user_id']) ? apFetchUserMembershipLevel($conn, intval($_SESSION['user_id'])) : null;
$isMemberPriceEligible = apIsMemberPriceEligible($currentUserMembershipLevel);

$homepageBanners = sfFetchHomepageBanners($conn, 6);

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

    <?php if (!empty($homepageBanners)): ?>
        <section class="promo-marquee" aria-label="促銷活動">
            <div class="promo-marquee-track">
                <?php foreach (array_merge($homepageBanners, $homepageBanners) as $banner): ?>
                    <a class="promo-marquee-item" href="promotion_products.php?promotion_id=<?php echo intval($banner['promotion_id']); ?>">
                        <?php if (!empty($banner['banner_image_url'])): ?>
                            <img src="../<?php echo htmlspecialchars(ltrim($banner['banner_image_url'], '/')); ?>" alt="<?php echo htmlspecialchars($banner['name']); ?>">
                        <?php endif; ?>
                        <span><?php echo htmlspecialchars($banner['name']); ?></span>
                        <small><?php echo htmlspecialchars(date('m/d', strtotime($banner['start_at'])) . ' - ' . date('m/d', strtotime($banner['end_at']))); ?></small>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="section-container">
        <h2 class="section-title">FEATURED ITEMS</h2>
        
        <div class="product-grid">
            
            <?php
            /**
             * 🌟 專業連動查詢：
             * p = products (母檔) -> 抓商品名稱與精選狀態
             * v = product_variants (子檔) -> 抓價格
             * i = product_images (圖床) -> 抓標記為 is_main=1 的展示圖
             */
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
                    // 點擊卡片跳轉到商品詳情頁 (需帶上 id)
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

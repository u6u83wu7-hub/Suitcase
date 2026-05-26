<?php
$pageTitle = 'All Pass 行李箱專賣 | Your All-Access Pass';
$activeNav = '';

$conn = new mysqli("localhost", "root", "", "all_pass_db");
if ($conn->connect_error) {
    die("資料庫連線失敗: " . $conn->connect_error);
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
           $sql = "SELECT 
                        p.product_id, 
                        p.name, 
                        CASE
                            WHEN v.special_price IS NOT NULL AND v.special_price > 0 THEN v.special_price
                            WHEN v.member_price > 0 THEN v.member_price
                            ELSE v.original_price
                        END AS price,
                        i.image_url 
                    FROM products p
                    INNER JOIN product_variants v 
                        ON p.product_id = v.product_id
                    INNER JOIN product_images i 
                        ON p.product_id = i.product_id
                    WHERE p.is_featured = 1 
                      AND i.is_main = 1 
                      AND p.status = 'ON SHELF'
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

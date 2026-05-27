<?php
$pageTitle = 'NEW IN 新品 | All Pass 行李箱專賣';
$activeNav = 'new_in';

$conn = new mysqli("localhost", "root", "", "all_pass_db");
if ($conn->connect_error) {
    die("資料庫連線失敗: " . $conn->connect_error);
}

include 'header.php';
?>

    <section class="page-hero">
        <h1>NEW IN</h1>
        <p>最新上架商品，第一時間掌握新品動態。</p>
        <div class="hero-actions">
            <a href="index.php" class="hero-btn">回首頁</a>
        </div>
    </section>

    <section class="section-container">
        <h2 class="section-title">NEW ARRIVALS</h2>

        <div class="product-grid">
            <?php
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
                    WHERE i.is_main = 1 
                      AND p.status = 'ON SHELF'
                    ORDER BY p.created_at DESC
                    LIMIT 12";

            $result = $conn->query($sql);

            if ($result && $result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
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
                echo '<p class="empty-state">目前尚無新品，請稍後再回來看看。</p>';
            }
            ?>
        </div>
    </section>

<?php include 'footer.php'; $conn->close(); ?>

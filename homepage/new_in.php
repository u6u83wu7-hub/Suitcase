<?php
// Temporary: show PHP errors to help debug why page output is missing
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
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
                        MIN(COALESCE(v.special_price, v.original_price)) AS price,
                        (
                            SELECT pi.image_url
                            FROM product_images pi
                            WHERE pi.product_id = p.product_id
                            ORDER BY pi.is_main DESC, pi.sort_order ASC
                            LIMIT 1
                        ) AS image_url
                    FROM products p
                    LEFT JOIN product_variants v ON v.product_id = p.product_id
                    WHERE p.status = 'ON SHELF'
                    GROUP BY p.product_id
                    ORDER BY p.created_at DESC
                    LIMIT 12";

            $result = $conn->query($sql);

            // Debug log: record SQL and any mysqli error (avoid complex sampling)
            $debugLog = __DIR__ . '/../backend/logs/new_in_debug.log';
            @mkdir(dirname($debugLog), 0777, true);
            $dbg = [
                'time' => date('Y-m-d H:i:s'),
                'sql' => $sql,
                'mysqli_error' => $conn->error,
                'num_rows' => ($result ? $result->num_rows : 0),
            ];
            @file_put_contents($debugLog, json_encode($dbg, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

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
<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (defined('MYSQLI_REPORT_OFF') && function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

$pageTitle = 'NEW IN 新品 | All Pass 行李箱專賣';
$activeNav = 'new_in';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/storefront_helpers.php';
require_once __DIR__ . '/includes/price_helper.php';

$conn = new mysqli("localhost", "root", "", "all_pass_db");
if ($conn->connect_error) {
    error_log('New in database connection failed: ' . $conn->connect_error);
    http_response_code(500);
    echo '系統暫時無法連線，請稍後再試。';
    exit;
}
$conn->set_charset('utf8mb4');
$currentUserMembershipLevel = !empty($_SESSION['user_id']) ? apFetchUserMembershipLevel($conn, intval($_SESSION['user_id'])) : null;
$isMemberPriceEligible = apIsMemberPriceEligible($currentUserMembershipLevel);

$categoryId = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;
$categoryName = '';
if ($categoryId > 0 && sfTableExists($conn, 'categories')) {
    $catRes = $conn->query("SELECT name FROM categories WHERE category_id = {$categoryId} LIMIT 1");
    if ($catRes && ($catRow = $catRes->fetch_assoc())) {
        $categoryName = $catRow['name'];
        $pageTitle = $categoryName . ' | All Pass 行李箱專賣';
        $activeNav = 'category';
    }
}

include 'header.php';
?>

    <section class="page-hero">
        <h1><?php echo $categoryName !== '' ? htmlspecialchars($categoryName) : 'NEW IN'; ?></h1>
        <p><?php echo $categoryName !== '' ? '依照分類瀏覽適合你的行李箱。' : '最新上架商品，第一時間掌握新品動態。'; ?></p>
        <div class="hero-actions">
            <a href="index.php" class="hero-btn">回首頁</a>
            <?php if ($categoryName !== ''): ?>
                <a href="new_in.php" class="hero-btn">全部商品</a>
            <?php endif; ?>
        </div>
    </section>

    <section class="section-container">
        <h2 class="section-title"><?php echo $categoryName !== '' ? htmlspecialchars($categoryName) : 'NEW ARRIVALS'; ?></h2>

        <div class="product-grid">
            <?php
            $imageOrderBy = sfProductImageOrder($conn, 'pi');
            $priceSql = apVariantPriceSql('v', $isMemberPriceEligible);
            $categoryWhere = '';
            $categoryJoin = '';
            if ($categoryId > 0) {
                $categoryJoin = "INNER JOIN product_category_links pcl_filter ON pcl_filter.product_id = p.product_id";
                $categoryWhere = "AND pcl_filter.category_id = {$categoryId}";
            }

            $sql = "SELECT
                        p.product_id,
                        p.name,
                        MIN({$priceSql}) AS price,
                        (
                            SELECT pi.image_url
                            FROM product_images pi
                            WHERE pi.product_id = p.product_id
                            ORDER BY {$imageOrderBy}
                            LIMIT 1
                        ) AS image_url
                    FROM products p
                    {$categoryJoin}
                    LEFT JOIN product_variants v ON v.product_id = p.product_id
                    WHERE p.status = 'ON SHELF'
                    {$categoryWhere}
                    GROUP BY p.product_id
                    ORDER BY p.created_at DESC
                    LIMIT 12";

            $result = $conn->query($sql);
            if ($result === false) {
                error_log('[new_in] product query failed: ' . $conn->error);
            }

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
                echo '<p class="empty-state">' . ($categoryName !== '' ? '這個分類目前尚無上架商品。' : '目前尚無新品，請稍後再回來看看。') . '</p>';
            }
            ?>
        </div>
    </section>

<?php include 'footer.php'; $conn->close(); ?>

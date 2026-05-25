<?php
$pageTitle = 'Product Detail | All Pass';
$activeNav = '';

$conn = new mysqli("localhost", "root", "", "all_pass_db");
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

$productId = isset($_GET['id']) ? intval($_GET['id']) : 0;

$stmt = $conn->prepare("
    SELECT p.*, c.name AS category_name,
        (SELECT MIN(price) FROM product_variants WHERE product_id = p.product_id) AS min_price,
        (SELECT MAX(price) FROM product_variants WHERE product_id = p.product_id) AS max_price
    FROM products p
    LEFT JOIN categories c ON c.category_id = p.primary_category_id
    WHERE p.product_id = ? AND p.status = 'ON SHELF'
    LIMIT 1
");
$stmt->bind_param("i", $productId);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();

$variants = [];
$images = [];

if ($product) {
    $variantStmt = $conn->prepare("
        SELECT variant_id, sku_code, color, size_inches, capacity_liters, price, stock_available
        FROM product_variants
        WHERE product_id = ?
        ORDER BY price ASC, variant_id ASC
    ");
    $variantStmt->bind_param("i", $productId);
    $variantStmt->execute();
    $variantResult = $variantStmt->get_result();
    while ($row = $variantResult->fetch_assoc()) {
        $variants[] = $row;
    }

    $imageStmt = $conn->prepare("
        SELECT image_url, is_main, color, alt_text
        FROM product_images
        WHERE product_id = ?
        ORDER BY is_main DESC, sort_order ASC, image_id ASC
    ");
    $imageStmt->bind_param("i", $productId);
    $imageStmt->execute();
    $imageResult = $imageStmt->get_result();
    while ($row = $imageResult->fetch_assoc()) {
        $images[] = $row;
    }
}

include 'header.php';
?>

<main class="detail-page">
    <?php if (!$product): ?>
        <section class="detail-empty">
            <h1>Product not found</h1>
            <p>This item is unavailable or has been removed.</p>
            <a href="new_in.php" class="detail-btn">Back to products</a>
        </section>
    <?php else: ?>
        <?php
        $mainImage = !empty($images) ? $images[0]['image_url'] : '';
        $priceText = '';
        if ($product['min_price'] !== null) {
            if ((float)$product['min_price'] === (float)$product['max_price']) {
                $priceText = 'NT$ ' . number_format((float)$product['min_price']);
            } else {
                $priceText = 'NT$ ' . number_format((float)$product['min_price']) . ' - ' . number_format((float)$product['max_price']);
            }
        }
        ?>
        <section class="detail-wrap">
            <div class="detail-gallery">
                <?php if ($mainImage !== ''): ?>
                    <img class="detail-main-image" src="../<?php echo htmlspecialchars($mainImage); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                <?php else: ?>
                    <div class="detail-image-placeholder">No image</div>
                <?php endif; ?>

                <?php if (count($images) > 1): ?>
                    <div class="detail-thumbs">
                        <?php foreach ($images as $image): ?>
                            <img src="../<?php echo htmlspecialchars($image['image_url']); ?>" alt="<?php echo htmlspecialchars($image['alt_text'] ?: $product['name']); ?>">
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="detail-info">
                <div class="detail-category"><?php echo htmlspecialchars($product['category_name'] ?: 'All Pass'); ?></div>
                <h1><?php echo htmlspecialchars($product['name']); ?></h1>
                <div class="detail-price"><?php echo htmlspecialchars($priceText); ?></div>

                <?php if (!empty($_GET['error'])): ?>
                    <div class="detail-alert"><?php echo htmlspecialchars($_GET['error']); ?></div>
                <?php endif; ?>

                <form action="cart_action.php" method="POST" class="detail-form">
                    <input type="hidden" name="action" value="add_to_cart">
                    <input type="hidden" name="product_id" value="<?php echo intval($productId); ?>">

                    <label class="detail-label">Choose SKU</label>
                    <div class="variant-list">
                        <?php foreach ($variants as $idx => $variant): ?>
                            <?php $disabled = (int)$variant['stock_available'] <= 0; ?>
                            <label class="variant-option <?php echo $disabled ? 'is-disabled' : ''; ?>">
                                <input type="radio" name="variant_id" value="<?php echo intval($variant['variant_id']); ?>" <?php echo $idx === 0 && !$disabled ? 'checked' : ''; ?> <?php echo $disabled ? 'disabled' : ''; ?>>
                                <span>
                                    <?php echo htmlspecialchars(trim(($variant['size_inches'] ?: '-') . ' / ' . ($variant['color'] ?: '-'))); ?>
                                    <small>SKU <?php echo htmlspecialchars($variant['sku_code']); ?> | Stock <?php echo intval($variant['stock_available']); ?></small>
                                </span>
                                <strong>NT$ <?php echo number_format((float)$variant['price']); ?></strong>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="qty-row">
                        <label for="quantity">Quantity</label>
                        <input id="quantity" type="number" name="quantity" min="1" value="1">
                    </div>

                    <button type="submit" class="detail-btn" <?php echo empty($variants) ? 'disabled' : ''; ?>>Add to cart</button>
                    <a href="cart.php" class="detail-link">View cart</a>
                </form>

                <?php if (!empty($product['description'])): ?>
                    <section class="detail-copy">
                        <h2>Description</h2>
                        <p><?php echo nl2br(htmlspecialchars($product['description'])); ?></p>
                    </section>
                <?php endif; ?>

                <?php if (!empty($product['warranty_info'])): ?>
                    <section class="detail-copy">
                        <h2>Warranty</h2>
                        <p><?php echo nl2br(htmlspecialchars($product['warranty_info'])); ?></p>
                    </section>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>
</main>

<style>
    .detail-page { padding: 160px 5% 80px; max-width: 1280px; margin: 0 auto; }
    .detail-wrap { display: grid; grid-template-columns: minmax(320px, 1fr) minmax(320px, 0.85fr); gap: 56px; align-items: start; }
    .detail-main-image, .detail-image-placeholder { width: 100%; aspect-ratio: 1 / 1; object-fit: cover; background: #f3f4f6; display: flex; align-items: center; justify-content: center; color: #999; }
    .detail-thumbs { display: grid; grid-template-columns: repeat(auto-fill, minmax(80px, 1fr)); gap: 10px; margin-top: 12px; }
    .detail-thumbs img { width: 100%; aspect-ratio: 1 / 1; object-fit: cover; background: #f3f4f6; }
    .detail-category { color: #db6b6b; font-size: 13px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; margin-bottom: 12px; }
    .detail-info h1 { font-size: 34px; line-height: 1.25; margin: 0 0 14px; color: #202020; }
    .detail-price { font-size: 24px; font-weight: 800; color: #2c3e50; margin-bottom: 26px; }
    .detail-alert { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; padding: 12px 14px; margin-bottom: 18px; }
    .detail-form { border-top: 1px solid #eee; border-bottom: 1px solid #eee; padding: 22px 0; margin-bottom: 26px; }
    .detail-label { display: block; font-weight: 700; margin-bottom: 12px; }
    .variant-list { display: grid; gap: 10px; margin-bottom: 18px; }
    .variant-option { display: grid; grid-template-columns: 24px 1fr auto; gap: 12px; align-items: center; padding: 14px; border: 1px solid #e5e7eb; cursor: pointer; }
    .variant-option small { display: block; color: #777; margin-top: 4px; font-size: 12px; }
    .variant-option.is-disabled { opacity: 0.45; cursor: not-allowed; }
    .qty-row { display: grid; grid-template-columns: 120px 120px; gap: 12px; align-items: center; margin-bottom: 18px; }
    .qty-row input { padding: 11px; border: 1px solid #ddd; }
    .detail-btn { display: inline-flex; align-items: center; justify-content: center; min-width: 150px; padding: 13px 20px; background: #2c3e50; color: #fff; border: 0; cursor: pointer; font-weight: 700; }
    .detail-btn:hover { background: #db6b6b; }
    .detail-btn:disabled { opacity: 0.4; cursor: not-allowed; }
    .detail-link { margin-left: 14px; color: #db6b6b; font-weight: 700; }
    .detail-copy { margin-top: 24px; color: #555; line-height: 1.8; }
    .detail-copy h2 { font-size: 16px; color: #222; margin-bottom: 8px; }
    .detail-empty { text-align: center; padding: 80px 0; }
    @media (max-width: 840px) {
        .detail-wrap { grid-template-columns: 1fr; gap: 28px; }
        .detail-page { padding-top: 190px; }
    }
</style>

<?php include 'footer.php'; $conn->close(); ?>

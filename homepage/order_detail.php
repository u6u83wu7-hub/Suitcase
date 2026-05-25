<?php
$pageTitle = 'Order Detail | All Pass';
$activeNav = '';

$conn = new mysqli("localhost", "root", "", "all_pass_db");
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

include 'header.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userId = (int)$_SESSION['user_id'];
$orderId = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;

$stmt = $conn->prepare("
    SELECT *
    FROM orders
    WHERE order_id = ? AND user_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $orderId, $userId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

$items = [];
if ($order) {
    $itemStmt = $conn->prepare("
        SELECT oi.product_name, oi.sku_code, oi.color, oi.size_inches,
               oi.quantity, oi.locked_price, oi.variant_id,
               pv.product_id,
               (
                   SELECT image_url
                   FROM product_images
                   WHERE product_id = pv.product_id
                   ORDER BY is_main DESC, sort_order ASC, image_id ASC
                   LIMIT 1
               ) AS image_url
        FROM order_items oi
        LEFT JOIN product_variants pv ON pv.variant_id = oi.variant_id
        WHERE oi.order_id = ?
        ORDER BY oi.order_item_id ASC
    ");
    $itemStmt->bind_param("i", $orderId);
    $itemStmt->execute();
    $itemResult = $itemStmt->get_result();
    while ($row = $itemResult->fetch_assoc()) {
        $items[] = $row;
    }
}
?>

<main class="order-detail-page">
    <?php if (!$order): ?>
        <section class="od-empty">
            <h1>Order not found</h1>
            <p>This order does not exist or does not belong to your account.</p>
            <a href="my_orders.php" class="od-btn">Back to my orders</a>
        </section>
    <?php else: ?>
        <section class="od-head">
            <div>
                <a href="my_orders.php" class="od-back">Back to my orders</a>
                <h1>Order #<?php echo intval($order['order_id']); ?></h1>
                <p>Created at <?php echo htmlspecialchars($order['created_at']); ?></p>
            </div>
            <div class="od-status <?php echo htmlspecialchars($order['status']); ?>">
                <?php echo htmlspecialchars($order['status']); ?>
            </div>
        </section>

        <section class="od-layout">
            <div class="od-main">
                <section class="od-panel">
                    <h2>Items</h2>
                    <?php foreach ($items as $item): ?>
                        <article class="od-item">
                            <div class="od-img">
                                <?php if (!empty($item['image_url'])): ?>
                                    <img src="../<?php echo htmlspecialchars($item['image_url']); ?>" alt="<?php echo htmlspecialchars($item['product_name']); ?>">
                                <?php else: ?>
                                    <span>No image</span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <h3><?php echo htmlspecialchars($item['product_name']); ?></h3>
                                <p><?php echo htmlspecialchars(($item['size_inches'] ?: '-') . ' / ' . ($item['color'] ?: '-')); ?></p>
                                <p>SKU <?php echo htmlspecialchars($item['sku_code']); ?></p>
                            </div>
                            <div class="od-qty">x <?php echo intval($item['quantity']); ?></div>
                            <div class="od-price">
                                <strong>NT$ <?php echo number_format((float)$item['locked_price'] * (int)$item['quantity']); ?></strong>
                                <span>NT$ <?php echo number_format((float)$item['locked_price']); ?> each</span>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </section>
            </div>

            <aside class="od-side">
                <section class="od-panel">
                    <h2>Shipping</h2>
                    <dl class="od-kv">
                        <dt>Recipient</dt><dd><?php echo htmlspecialchars($order['recipient_name']); ?></dd>
                        <dt>Phone</dt><dd><?php echo htmlspecialchars($order['recipient_phone']); ?></dd>
                        <dt>Address</dt><dd><?php echo nl2br(htmlspecialchars($order['shipping_address'])); ?></dd>
                        <dt>Notes</dt><dd><?php echo htmlspecialchars($order['shipping_notes'] ?: '-'); ?></dd>
                    </dl>
                </section>

                <section class="od-panel">
                    <h2>Payment</h2>
                    <dl class="od-kv">
                        <dt>Method</dt><dd><?php echo htmlspecialchars($order['payment_method']); ?></dd>
                        <dt>Subtotal</dt><dd>NT$ <?php echo number_format((float)$order['subtotal_amount']); ?></dd>
                        <dt>Shipping</dt><dd>NT$ <?php echo number_format((float)$order['shipping_fee']); ?></dd>
                        <dt>Discount</dt><dd>NT$ <?php echo number_format((float)$order['discount_amount']); ?></dd>
                        <dt>Total</dt><dd class="od-total">NT$ <?php echo number_format((float)$order['total_amount']); ?></dd>
                    </dl>
                </section>
            </aside>
        </section>
    <?php endif; ?>
</main>

<style>
    .order-detail-page { padding: 160px 5% 80px; max-width: 1180px; margin: 0 auto; }
    .od-head { display: flex; justify-content: space-between; gap: 18px; align-items: flex-start; margin-bottom: 24px; }
    .od-back { color: #db6b6b; font-weight: 800; display: inline-flex; margin-bottom: 12px; }
    .od-head h1 { font-size: 34px; margin: 0 0 8px; }
    .od-head p { color: #666; }
    .od-status { display: inline-flex; padding: 8px 12px; border-radius: 999px; font-size: 13px; font-weight: 900; background: #e2e8f0; color: #334155; }
    .od-status.PAID { background: #dbeafe; color: #1d4ed8; }
    .od-status.SHIPPING { background: #fef3c7; color: #92400e; }
    .od-status.COMPLETED { background: #dcfce7; color: #166534; }
    .od-status.CANCELLED { background: #fee2e2; color: #991b1b; }
    .od-layout { display: grid; grid-template-columns: 1fr 340px; gap: 24px; align-items: start; }
    .od-panel { background: #fff; border: 1px solid #eee; padding: 22px; margin-bottom: 16px; }
    .od-panel h2 { margin: 0 0 18px; font-size: 18px; }
    .od-item { display: grid; grid-template-columns: 92px 1fr auto 150px; gap: 16px; align-items: center; padding: 14px 0; border-bottom: 1px solid #f1f5f9; }
    .od-item:last-child { border-bottom: 0; }
    .od-img { width: 92px; aspect-ratio: 1 / 1; background: #f3f4f6; display: flex; justify-content: center; align-items: center; color: #999; font-size: 12px; }
    .od-img img { width: 100%; height: 100%; object-fit: cover; }
    .od-item h3 { margin: 0 0 6px; font-size: 16px; }
    .od-item p { margin: 4px 0; color: #64748b; font-size: 13px; }
    .od-qty { font-weight: 800; color: #334155; }
    .od-price { text-align: right; }
    .od-price strong { display: block; color: #2c3e50; }
    .od-price span { display: block; color: #64748b; font-size: 12px; margin-top: 4px; }
    .od-kv { display: grid; grid-template-columns: 92px 1fr; gap: 10px 14px; font-size: 14px; }
    .od-kv dt { color: #64748b; }
    .od-kv dd { margin: 0; font-weight: 700; color: #1f2937; }
    .od-total { font-size: 18px; color: #2c3e50 !important; }
    .od-empty { text-align: center; background: #fff; border: 1px solid #eee; padding: 60px 20px; }
    .od-empty h1 { margin-bottom: 8px; }
    .od-empty p { color: #666; margin-bottom: 20px; }
    .od-btn { display: inline-flex; justify-content: center; padding: 11px 16px; background: #2c3e50; color: #fff; font-weight: 800; }
    .od-btn:hover { background: #db6b6b; }
    @media (max-width: 900px) {
        .order-detail-page { padding-top: 190px; }
        .od-layout { grid-template-columns: 1fr; }
        .od-item { grid-template-columns: 80px 1fr; }
        .od-qty, .od-price { grid-column: 2; text-align: left; }
    }
</style>

<?php include 'footer.php'; $conn->close(); ?>

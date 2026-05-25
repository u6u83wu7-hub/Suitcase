<?php
$pageTitle = 'Cart | All Pass';
$activeNav = '';

$conn = new mysqli("localhost", "root", "", "all_pass_db");
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

include 'header.php';

$items = [];
$subtotal = 0;

if (isset($_SESSION['user_id'])) {
    $userId = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("
        SELECT ci.cart_item_id, ci.quantity,
               p.product_id, p.name,
               pv.variant_id, pv.sku_code, pv.color, pv.size_inches, pv.price, pv.stock_available,
               (
                   SELECT image_url
                   FROM product_images
                   WHERE product_id = p.product_id
                   ORDER BY is_main DESC, sort_order ASC, image_id ASC
                   LIMIT 1
               ) AS image_url
        FROM carts c
        INNER JOIN cart_items ci ON ci.cart_id = c.cart_id
        INNER JOIN product_variants pv ON pv.variant_id = ci.variant_id
        INNER JOIN products p ON p.product_id = pv.product_id
        WHERE c.user_id = ?
        ORDER BY ci.created_at DESC
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['line_total'] = (float)$row['price'] * (int)$row['quantity'];
        $subtotal += $row['line_total'];
        $items[] = $row;
    }
}
?>

<main class="cart-page">
    <section class="cart-head">
        <h1>Shopping Cart</h1>
        <p>Review your selected luggage before checkout.</p>
    </section>

    <?php if (!isset($_SESSION['user_id'])): ?>
        <section class="cart-empty">
            <h2>Please login first</h2>
            <p>Members can save carts and place orders.</p>
            <a href="login.php" class="cart-btn">Login</a>
        </section>
    <?php elseif (empty($items)): ?>
        <section class="cart-empty">
            <h2>Your cart is empty</h2>
            <p>Add a product and come back here to checkout.</p>
            <a href="new_in.php" class="cart-btn">Browse products</a>
        </section>
    <?php else: ?>
        <?php if (!empty($_GET['msg'])): ?>
            <div class="cart-message"><?php echo htmlspecialchars($_GET['msg']); ?></div>
        <?php endif; ?>

        <section class="cart-layout">
            <div class="cart-items">
                <?php foreach ($items as $item): ?>
                    <article class="cart-item">
                        <a href="product_detail.php?id=<?php echo intval($item['product_id']); ?>" class="cart-img">
                            <?php if (!empty($item['image_url'])): ?>
                                <img src="../<?php echo htmlspecialchars($item['image_url']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>">
                            <?php else: ?>
                                <span>No image</span>
                            <?php endif; ?>
                        </a>
                        <div class="cart-info">
                            <h2><?php echo htmlspecialchars($item['name']); ?></h2>
                            <p><?php echo htmlspecialchars(($item['size_inches'] ?: '-') . ' / ' . ($item['color'] ?: '-')); ?></p>
                            <p>SKU <?php echo htmlspecialchars($item['sku_code']); ?> | Stock <?php echo intval($item['stock_available']); ?></p>
                            <strong>NT$ <?php echo number_format((float)$item['price']); ?></strong>
                        </div>
                        <div class="cart-actions">
                            <form action="cart_action.php" method="POST" class="qty-form">
                                <input type="hidden" name="action" value="update_quantity">
                                <input type="hidden" name="cart_item_id" value="<?php echo intval($item['cart_item_id']); ?>">
                                <input type="number" name="quantity" min="1" max="<?php echo intval($item['stock_available']); ?>" value="<?php echo intval($item['quantity']); ?>">
                                <button type="submit">Update</button>
                            </form>
                            <form action="cart_action.php" method="POST">
                                <input type="hidden" name="action" value="remove_item">
                                <input type="hidden" name="cart_item_id" value="<?php echo intval($item['cart_item_id']); ?>">
                                <button type="submit" class="cart-remove">Remove</button>
                            </form>
                        </div>
                        <div class="cart-line-total">NT$ <?php echo number_format($item['line_total']); ?></div>
                    </article>
                <?php endforeach; ?>
            </div>

            <aside class="cart-summary">
                <?php
                $shipping = $subtotal >= 3000 ? 0 : 120;
                $total = $subtotal + $shipping;
                ?>
                <h2>Summary</h2>
                <div><span>Subtotal</span><strong>NT$ <?php echo number_format($subtotal); ?></strong></div>
                <div><span>Shipping</span><strong>NT$ <?php echo number_format($shipping); ?></strong></div>
                <div class="summary-total"><span>Total</span><strong>NT$ <?php echo number_format($total); ?></strong></div>
                <a href="checkout.php" class="cart-btn">Checkout</a>
            </aside>
        </section>
    <?php endif; ?>
</main>

<style>
    .cart-page { padding: 160px 5% 80px; max-width: 1180px; margin: 0 auto; }
    .cart-head { margin-bottom: 28px; }
    .cart-head h1 { font-size: 34px; margin-bottom: 8px; }
    .cart-head p { color: #666; }
    .cart-message { background: #ecfdf5; border: 1px solid #bbf7d0; color: #166534; padding: 12px 14px; margin-bottom: 16px; }
    .cart-layout { display: grid; grid-template-columns: 1fr 320px; gap: 28px; align-items: start; }
    .cart-items { display: grid; gap: 14px; }
    .cart-item { display: grid; grid-template-columns: 120px 1fr 170px 130px; gap: 18px; align-items: center; background: #fff; border: 1px solid #eee; padding: 14px; }
    .cart-img { background: #f3f4f6; width: 120px; aspect-ratio: 1 / 1; display: flex; align-items: center; justify-content: center; color: #999; }
    .cart-img img { width: 100%; height: 100%; object-fit: cover; }
    .cart-info h2 { font-size: 17px; margin: 0 0 8px; }
    .cart-info p { margin: 4px 0; color: #777; font-size: 13px; }
    .cart-info strong, .cart-line-total { color: #2c3e50; font-weight: 800; }
    .cart-actions { display: grid; gap: 8px; }
    .qty-form { display: grid; grid-template-columns: 70px 1fr; gap: 8px; }
    .cart-actions input { padding: 9px; border: 1px solid #ddd; width: 100%; }
    .cart-actions button, .cart-btn { display: inline-flex; justify-content: center; align-items: center; padding: 10px 12px; border: 0; background: #2c3e50; color: #fff; cursor: pointer; font-weight: 700; text-align: center; }
    .cart-actions button:hover, .cart-btn:hover { background: #db6b6b; }
    .cart-remove { background: #9ca3af !important; }
    .cart-summary { background: #fff; border: 1px solid #eee; padding: 22px; position: sticky; top: 120px; }
    .cart-summary h2 { margin: 0 0 18px; }
    .cart-summary div { display: flex; justify-content: space-between; margin-bottom: 12px; color: #555; }
    .cart-summary .summary-total { border-top: 1px solid #eee; padding-top: 14px; margin-top: 14px; font-size: 18px; color: #111; }
    .cart-summary .cart-btn { width: 100%; margin-top: 10px; }
    .cart-empty { background: #fff; border: 1px solid #eee; text-align: center; padding: 60px 20px; }
    .cart-empty h2 { margin-bottom: 8px; }
    .cart-empty p { color: #666; margin-bottom: 20px; }
    @media (max-width: 900px) {
        .cart-layout { grid-template-columns: 1fr; }
        .cart-item { grid-template-columns: 92px 1fr; }
        .cart-actions, .cart-line-total { grid-column: 2; }
        .cart-img { width: 92px; }
    }
</style>

<?php include 'footer.php'; $conn->close(); ?>

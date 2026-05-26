<?php
$pageTitle = 'Checkout | All Pass';
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
$items = [];
$subtotal = 0;

$userStmt = $conn->prepare("SELECT name, phone FROM users WHERE user_id = ? LIMIT 1");
$userStmt->bind_param("i", $userId);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();

$stmt = $conn->prepare("
    SELECT ci.quantity, p.name, pv.color, pv.size_inches,
           CASE
               WHEN pv.special_price IS NOT NULL AND pv.special_price > 0 THEN pv.special_price
               WHEN pv.member_price > 0 THEN pv.member_price
               ELSE pv.original_price
           END AS price
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

$shipping = $subtotal >= 3000 ? 0 : 120;
$total = $subtotal + $shipping;
?>

<main class="checkout-page">
    <section class="checkout-head">
        <h1>Checkout</h1>
        <p>Confirm shipping details and create your order.</p>
    </section>

    <?php if (empty($items)): ?>
        <section class="checkout-empty">
            <h2>Your cart is empty</h2>
            <a href="cart.php" class="checkout-btn">Back to cart</a>
        </section>
    <?php else: ?>
        <?php if (!empty($_GET['error'])): ?>
            <div class="checkout-alert"><?php echo htmlspecialchars($_GET['error']); ?></div>
        <?php endif; ?>

        <section class="checkout-layout">
            <form action="place_order.php" method="POST" class="checkout-form">
                <h2>Shipping information</h2>
                <label>Recipient name</label>
                <input type="text" name="recipient_name" required value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>">

                <label>Phone</label>
                <input type="text" name="recipient_phone" required value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">

                <label>Address</label>
                <textarea name="shipping_address" required rows="4" placeholder="City, district, street, number"></textarea>

                <label>Notes</label>
                <input type="text" name="shipping_notes" placeholder="Optional delivery note">

                <label>Payment method</label>
                <select name="payment_method">
                    <option value="COD">Cash on delivery</option>
                    <option value="SIMULATED_CARD">Simulated card payment</option>
                </select>

                <button type="submit" class="checkout-btn">Place order</button>
            </form>

            <aside class="checkout-summary">
                <h2>Order summary</h2>
                <?php foreach ($items as $item): ?>
                    <div class="summary-item">
                        <span>
                            <?php echo htmlspecialchars($item['name']); ?>
                            <small><?php echo htmlspecialchars(($item['size_inches'] ?: '-') . ' / ' . ($item['color'] ?: '-')); ?> x <?php echo intval($item['quantity']); ?></small>
                        </span>
                        <strong>NT$ <?php echo number_format($item['line_total']); ?></strong>
                    </div>
                <?php endforeach; ?>
                <div class="summary-row"><span>Subtotal</span><strong>NT$ <?php echo number_format($subtotal); ?></strong></div>
                <div class="summary-row"><span>Shipping</span><strong>NT$ <?php echo number_format($shipping); ?></strong></div>
                <div class="summary-total"><span>Total</span><strong>NT$ <?php echo number_format($total); ?></strong></div>
            </aside>
        </section>
    <?php endif; ?>
</main>

<style>
    .checkout-page { padding: 160px 5% 80px; max-width: 1120px; margin: 0 auto; }
    .checkout-head { margin-bottom: 28px; }
    .checkout-head h1 { font-size: 34px; margin-bottom: 8px; }
    .checkout-head p { color: #666; }
    .checkout-alert { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; padding: 12px 14px; margin-bottom: 16px; }
    .checkout-layout { display: grid; grid-template-columns: 1fr 360px; gap: 28px; align-items: start; }
    .checkout-form, .checkout-summary, .checkout-empty { background: #fff; border: 1px solid #eee; padding: 24px; }
    .checkout-form h2, .checkout-summary h2 { margin-top: 0; }
    .checkout-form label { display: block; font-weight: 700; margin: 16px 0 8px; }
    .checkout-form input, .checkout-form textarea, .checkout-form select { width: 100%; padding: 12px; border: 1px solid #ddd; box-sizing: border-box; }
    .checkout-btn { display: inline-flex; justify-content: center; align-items: center; margin-top: 18px; padding: 12px 18px; border: 0; background: #2c3e50; color: #fff; font-weight: 700; cursor: pointer; }
    .checkout-btn:hover { background: #db6b6b; }
    .summary-item { display: flex; justify-content: space-between; gap: 16px; padding-bottom: 12px; margin-bottom: 12px; border-bottom: 1px solid #f1f1f1; }
    .summary-item small { display: block; color: #777; margin-top: 4px; }
    .summary-row, .summary-total { display: flex; justify-content: space-between; margin-top: 12px; }
    .summary-total { border-top: 1px solid #eee; padding-top: 14px; font-size: 18px; }
    .checkout-empty { text-align: center; }
    @media (max-width: 860px) { .checkout-layout { grid-template-columns: 1fr; } }
</style>

<?php include 'footer.php'; $conn->close(); ?>

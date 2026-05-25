<?php
$pageTitle = 'Order Created | All Pass';
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
    SELECT order_id, total_amount, status, created_at
    FROM orders
    WHERE order_id = ? AND user_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $orderId, $userId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
?>

<main class="success-page">
    <?php if (!$order): ?>
        <section class="success-card">
            <h1>Order not found</h1>
            <p>Please check your cart or contact the store.</p>
            <a href="cart.php" class="success-btn">Back to cart</a>
        </section>
    <?php else: ?>
        <section class="success-card">
            <div class="success-mark">OK</div>
            <h1>Order created</h1>
            <p>Your order has been saved. The store team can now see it in the backend.</p>
            <dl>
                <div><dt>Order ID</dt><dd>#<?php echo intval($order['order_id']); ?></dd></div>
                <div><dt>Status</dt><dd><?php echo htmlspecialchars($order['status']); ?></dd></div>
                <div><dt>Total</dt><dd>NT$ <?php echo number_format((float)$order['total_amount']); ?></dd></div>
                <div><dt>Created</dt><dd><?php echo htmlspecialchars($order['created_at']); ?></dd></div>
            </dl>
            <div class="success-actions">
                <a href="new_in.php" class="success-btn">Continue shopping</a>
                <a href="index.php" class="success-link">Back home</a>
            </div>
        </section>
    <?php endif; ?>
</main>

<style>
    .success-page { padding: 170px 5% 90px; max-width: 760px; margin: 0 auto; }
    .success-card { background: #fff; border: 1px solid #eee; padding: 42px; text-align: center; }
    .success-mark { width: 58px; height: 58px; margin: 0 auto 18px; border-radius: 50%; background: #ecfdf5; color: #166534; display: flex; align-items: center; justify-content: center; font-weight: 900; }
    .success-card h1 { font-size: 34px; margin-bottom: 10px; }
    .success-card p { color: #666; margin-bottom: 24px; }
    .success-card dl { display: grid; gap: 10px; margin: 0 auto 24px; max-width: 420px; text-align: left; }
    .success-card dl div { display: flex; justify-content: space-between; border-bottom: 1px solid #f1f1f1; padding-bottom: 10px; }
    .success-card dt { color: #777; }
    .success-card dd { margin: 0; font-weight: 800; color: #2c3e50; }
    .success-actions { display: flex; gap: 14px; justify-content: center; align-items: center; flex-wrap: wrap; }
    .success-btn { display: inline-flex; justify-content: center; align-items: center; padding: 12px 18px; background: #2c3e50; color: #fff; font-weight: 700; }
    .success-btn:hover { background: #db6b6b; }
    .success-link { color: #db6b6b; font-weight: 700; }
</style>

<?php include 'footer.php'; $conn->close(); ?>

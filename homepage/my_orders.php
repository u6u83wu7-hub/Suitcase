<?php
$pageTitle = 'My Orders | All Pass';
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
$orders = [];

$stmt = $conn->prepare("
    SELECT o.order_id, o.total_amount, o.status, o.created_at,
           o.payment_method, o.recipient_name,
           COUNT(oi.order_item_id) AS item_count
    FROM orders o
    LEFT JOIN order_items oi ON oi.order_id = o.order_id
    WHERE o.user_id = ?
    GROUP BY o.order_id
    ORDER BY o.created_at DESC
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $orders[] = $row;
}
?>

<main class="orders-page">
    <section class="orders-head">
        <h1>My Orders</h1>
        <p>Track your All Pass orders and review previous purchases.</p>
    </section>

    <?php if (empty($orders)): ?>
        <section class="orders-empty">
            <h2>No orders yet</h2>
            <p>Once you checkout, your orders will appear here.</p>
            <a href="new_in.php" class="orders-btn">Browse products</a>
        </section>
    <?php else: ?>
        <section class="orders-list">
            <?php foreach ($orders as $order): ?>
                <article class="order-card">
                    <div>
                        <div class="order-id">Order #<?php echo intval($order['order_id']); ?></div>
                        <div class="order-meta">
                            <?php echo htmlspecialchars($order['created_at']); ?> |
                            <?php echo intval($order['item_count']); ?> item(s) |
                            <?php echo htmlspecialchars($order['payment_method']); ?>
                        </div>
                    </div>
                    <div class="order-status <?php echo htmlspecialchars($order['status']); ?>">
                        <?php echo htmlspecialchars($order['status']); ?>
                    </div>
                    <div class="order-total">NT$ <?php echo number_format((float)$order['total_amount']); ?></div>
                    <a class="orders-btn order-link" href="order_detail.php?order_id=<?php echo intval($order['order_id']); ?>">View detail</a>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</main>

<style>
    .orders-page { padding: 160px 5% 80px; max-width: 1080px; margin: 0 auto; }
    .orders-head { margin-bottom: 28px; }
    .orders-head h1 { font-size: 34px; margin-bottom: 8px; }
    .orders-head p { color: #666; }
    .orders-list { display: grid; gap: 14px; }
    .order-card { display: grid; grid-template-columns: 1fr auto auto auto; gap: 18px; align-items: center; background: #fff; border: 1px solid #eee; padding: 18px; }
    .order-id { font-size: 18px; font-weight: 800; color: #1f2937; margin-bottom: 6px; }
    .order-meta { color: #64748b; font-size: 13px; }
    .order-total { font-weight: 900; color: #2c3e50; white-space: nowrap; }
    .order-status { display: inline-flex; justify-content: center; padding: 6px 10px; border-radius: 999px; font-size: 12px; font-weight: 900; background: #e2e8f0; color: #334155; }
    .order-status.PAID { background: #dbeafe; color: #1d4ed8; }
    .order-status.SHIPPING { background: #fef3c7; color: #92400e; }
    .order-status.COMPLETED { background: #dcfce7; color: #166534; }
    .order-status.CANCELLED { background: #fee2e2; color: #991b1b; }
    .orders-btn { display: inline-flex; justify-content: center; align-items: center; padding: 11px 16px; background: #2c3e50; color: #fff; font-weight: 800; border: 0; cursor: pointer; text-align: center; }
    .orders-btn:hover { background: #db6b6b; }
    .order-link { white-space: nowrap; }
    .orders-empty { text-align: center; background: #fff; border: 1px solid #eee; padding: 60px 20px; }
    .orders-empty h2 { margin-bottom: 8px; }
    .orders-empty p { color: #666; margin-bottom: 20px; }
    @media (max-width: 760px) {
        .orders-page { padding-top: 190px; }
        .order-card { grid-template-columns: 1fr; align-items: start; }
        .order-status, .order-total, .order-link { justify-self: start; }
    }
</style>

<?php include 'footer.php'; $conn->close(); ?>

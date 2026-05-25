<?php
$selectedOrderId = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
$allowedStatuses = ['PENDING', 'PAID', 'SHIPPING', 'COMPLETED', 'CANCELLED'];

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>

<style>
    .om-head { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; margin-bottom:18px; }
    .om-title { margin:0; font-size:22px; }
    .om-muted { color:#64748b; font-size:14px; margin:6px 0 0; }
    .om-grid { display:grid; grid-template-columns: minmax(360px, 1fr) minmax(360px, 0.9fr); gap:18px; align-items:start; }
    .om-panel { border:1px solid #e5e7eb; background:#fff; border-radius:8px; overflow:hidden; }
    .om-panel-head { padding:14px 16px; border-bottom:1px solid #e5e7eb; font-weight:800; background:#f8fafc; }
    .om-table { width:100%; border-collapse:collapse; font-size:14px; }
    .om-table th, .om-table td { padding:12px; border-bottom:1px solid #f1f5f9; text-align:left; vertical-align:top; }
    .om-table th { color:#475569; background:#f8fafc; font-size:12px; text-transform:uppercase; letter-spacing:.04em; }
    .om-link { color:#db6b6b; font-weight:800; text-decoration:none; }
    .om-badge { display:inline-flex; padding:4px 8px; border-radius:999px; font-size:12px; font-weight:800; background:#e2e8f0; color:#334155; }
    .om-badge.PAID { background:#dbeafe; color:#1d4ed8; }
    .om-badge.SHIPPING { background:#fef3c7; color:#92400e; }
    .om-badge.COMPLETED { background:#dcfce7; color:#166534; }
    .om-badge.CANCELLED { background:#fee2e2; color:#991b1b; }
    .om-detail { padding:16px; }
    .om-kv { display:grid; grid-template-columns:120px 1fr; gap:8px 14px; margin-bottom:18px; font-size:14px; }
    .om-kv dt { color:#64748b; }
    .om-kv dd { margin:0; font-weight:700; color:#1f2937; }
    .om-status-form { display:flex; gap:10px; align-items:center; margin:16px 0; }
    .om-status-form select { max-width:190px; margin:0; }
    .om-status-form button { margin:0; }
    .om-empty { padding:28px; text-align:center; color:#94a3b8; }
    @media (max-width: 980px) { .om-grid { grid-template-columns:1fr; } }
</style>

<div class="om-head">
    <div>
        <h1 class="om-title">Order Management</h1>
        <p class="om-muted">View checkout orders and update fulfillment status.</p>
    </div>
</div>

<?php
$orders = [];
$orderSql = "
    SELECT o.order_id, o.user_id, o.total_amount, o.status, o.created_at,
           o.recipient_name, u.email, u.name AS user_name,
           COUNT(oi.order_item_id) AS item_count
    FROM orders o
    LEFT JOIN users u ON u.user_id = o.user_id
    LEFT JOIN order_items oi ON oi.order_id = o.order_id
    GROUP BY o.order_id
    ORDER BY o.created_at DESC
    LIMIT 100
";
$orderResult = $conn->query($orderSql);
if ($orderResult) {
    while ($row = $orderResult->fetch_assoc()) {
        $orders[] = $row;
    }
}
?>

<div class="om-grid">
    <section class="om-panel">
        <div class="om-panel-head">Orders</div>
        <?php if (empty($orders)): ?>
            <div class="om-empty">No orders yet. Create an order from the frontend checkout flow.</div>
        <?php else: ?>
            <table class="om-table">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Customer</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td><a class="om-link" href="backend.php?page=orders&order_id=<?php echo intval($order['order_id']); ?>">#<?php echo intval($order['order_id']); ?></a></td>
                            <td>
                                <?php echo h($order['user_name'] ?: $order['recipient_name']); ?><br>
                                <span class="om-muted"><?php echo h($order['email'] ?: 'No email'); ?></span>
                            </td>
                            <td>NT$ <?php echo number_format((float)$order['total_amount']); ?><br><span class="om-muted"><?php echo intval($order['item_count']); ?> item(s)</span></td>
                            <td><span class="om-badge <?php echo h($order['status']); ?>"><?php echo h($order['status']); ?></span></td>
                            <td><?php echo h($order['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="om-panel">
        <div class="om-panel-head">Order Detail</div>
        <?php if ($selectedOrderId <= 0): ?>
            <div class="om-empty">Select an order to view details.</div>
        <?php else: ?>
            <?php
            $detailStmt = $conn->prepare("
                SELECT o.*, u.email, u.name AS user_name
                FROM orders o
                LEFT JOIN users u ON u.user_id = o.user_id
                WHERE o.order_id = ?
                LIMIT 1
            ");
            $detailStmt->bind_param("i", $selectedOrderId);
            $detailStmt->execute();
            $detail = $detailStmt->get_result()->fetch_assoc();

            $items = [];
            if ($detail) {
                $itemStmt = $conn->prepare("
                    SELECT product_name, sku_code, color, size_inches, quantity, locked_price
                    FROM order_items
                    WHERE order_id = ?
                    ORDER BY order_item_id ASC
                ");
                $itemStmt->bind_param("i", $selectedOrderId);
                $itemStmt->execute();
                $itemResult = $itemStmt->get_result();
                while ($row = $itemResult->fetch_assoc()) {
                    $items[] = $row;
                }
            }
            ?>

            <?php if (!$detail): ?>
                <div class="om-empty">Order not found.</div>
            <?php else: ?>
                <div class="om-detail">
                    <dl class="om-kv">
                        <dt>Order ID</dt><dd>#<?php echo intval($detail['order_id']); ?></dd>
                        <dt>Customer</dt><dd><?php echo h($detail['user_name'] ?: $detail['recipient_name']); ?></dd>
                        <dt>Email</dt><dd><?php echo h($detail['email'] ?: 'No email'); ?></dd>
                        <dt>Recipient</dt><dd><?php echo h($detail['recipient_name']); ?></dd>
                        <dt>Phone</dt><dd><?php echo h($detail['recipient_phone']); ?></dd>
                        <dt>Address</dt><dd><?php echo nl2br(h($detail['shipping_address'])); ?></dd>
                        <dt>Notes</dt><dd><?php echo h($detail['shipping_notes'] ?: '-'); ?></dd>
                        <dt>Payment</dt><dd><?php echo h($detail['payment_method']); ?></dd>
                        <dt>Status</dt><dd><span class="om-badge <?php echo h($detail['status']); ?>"><?php echo h($detail['status']); ?></span></dd>
                    </dl>

                    <form action="backend_action.php" method="POST" class="om-status-form">
                        <input type="hidden" name="action" value="update_order_status">
                        <input type="hidden" name="order_id" value="<?php echo intval($detail['order_id']); ?>">
                        <select name="status">
                            <?php foreach ($allowedStatuses as $status): ?>
                                <option value="<?php echo h($status); ?>" <?php echo $detail['status'] === $status ? 'selected' : ''; ?>><?php echo h($status); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit">Update status</button>
                    </form>

                    <table class="om-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>SKU</th>
                                <th>Qty</th>
                                <th>Price</th>
                                <th>Line</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td><?php echo h($item['product_name']); ?><br><span class="om-muted"><?php echo h(($item['size_inches'] ?: '-') . ' / ' . ($item['color'] ?: '-')); ?></span></td>
                                    <td><?php echo h($item['sku_code']); ?></td>
                                    <td><?php echo intval($item['quantity']); ?></td>
                                    <td>NT$ <?php echo number_format((float)$item['locked_price']); ?></td>
                                    <td>NT$ <?php echo number_format((float)$item['locked_price'] * (int)$item['quantity']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <dl class="om-kv" style="margin-top:18px;">
                        <dt>Subtotal</dt><dd>NT$ <?php echo number_format((float)$detail['subtotal_amount']); ?></dd>
                        <dt>Shipping</dt><dd>NT$ <?php echo number_format((float)$detail['shipping_fee']); ?></dd>
                        <dt>Discount</dt><dd>NT$ <?php echo number_format((float)$detail['discount_amount']); ?></dd>
                        <dt>Total</dt><dd>NT$ <?php echo number_format((float)$detail['total_amount']); ?></dd>
                    </dl>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>

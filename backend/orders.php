<?php
// orders.php - 訂單管理頁面
//版本1
require_once __DIR__ . '/auth_guard.php';

$selectedOrderId = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
$allowedStatuses = ['PENDING', 'PROCESSING', 'SHIPPED', 'DELIVERED', 'COMPLETED', 'CANCELLED'];

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function orderStatusLabel($status) {
    $labels = [
        'PENDING' => '待處理',
        'PROCESSING' => '處理中',
        'SHIPPED' => '已出貨',
        'DELIVERED' => '已送達',
        'COMPLETED' => '已完成',
        'CANCELLED' => '已取消',
    ];
    return $labels[$status] ?? $status;
}

function buildFilterQuery(array $overrides = []) {
    $base = [
        'page' => 'orders',
        'start_date' => isset($_GET['start_date']) ? $_GET['start_date'] : '',
        'end_date' => isset($_GET['end_date']) ? $_GET['end_date'] : '',
        'keyword' => isset($_GET['keyword']) ? $_GET['keyword'] : '',
        'status' => isset($_GET['status']) ? $_GET['status'] : '',
        'order_id' => isset($_GET['order_id']) ? $_GET['order_id'] : '',
    ];

    $merged = array_merge($base, $overrides);
    if (isset($merged['order_id']) && $merged['order_id'] === '') {
        unset($merged['order_id']);
    }

    return 'backend.php?' . http_build_query($merged);
}

function splitTrackingNumber($trackingNumber) {
    $trackingNumber = trim((string)$trackingNumber);
    if ($trackingNumber === '') {
        return ['', ''];
    }
    if (strpos($trackingNumber, ' | ') !== false) {
        $parts = explode(' | ', $trackingNumber, 2);
        return [trim($parts[0]), trim($parts[1])];
    }
    return ['', $trackingNumber];
}

$startDate = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$endDate = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';
$keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';

$conditions = [];
$params = [];
$types = '';

if ($startDate !== '') {
    $conditions[] = 'o.created_at >= ?';
    $params[] = $startDate . ' 00:00:00';
    $types .= 's';
}

if ($endDate !== '') {
    $conditions[] = 'o.created_at <= ?';
    $params[] = $endDate . ' 23:59:59';
    $types .= 's';
}

if ($keyword !== '') {
    $kw = '%' . $keyword . '%';
    $conditions[] = '(CAST(o.order_id AS CHAR) LIKE ? OR o.recipient_phone LIKE ?)';
    $params[] = $kw;
    $params[] = $kw;
    $types .= 'ss';
}

if ($statusFilter !== '' && in_array($statusFilter, $allowedStatuses, true)) {
    $conditions[] = 'o.status = ?';
    $params[] = $statusFilter;
    $types .= 's';
}

$whereClause = '';
if (!empty($conditions)) {
    $whereClause = 'WHERE ' . implode(' AND ', $conditions);
}

$orders = [];
$orderSql = "
    SELECT o.order_id, o.user_id, o.coupon_id, o.discount_amount, o.total_amount, o.status, o.created_at,
        o.recipient_name, o.recipient_phone, u.email, u.name AS user_name,
        c.coupon_name, c.coupon_code,
           COUNT(oi.order_item_id) AS item_count
    FROM orders o
    LEFT JOIN users u ON u.user_id = o.user_id
    LEFT JOIN coupons c ON c.coupon_id = o.coupon_id
    LEFT JOIN order_items oi ON oi.order_id = o.order_id
    {$whereClause}
    GROUP BY o.order_id
    ORDER BY o.created_at DESC
    LIMIT 200
";

if ($types !== '') {
    $stmt = $conn->prepare($orderSql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $orderResult = $stmt->get_result();
} else {
    $orderResult = $conn->query($orderSql);
}

if ($orderResult) {
    while ($row = $orderResult->fetch_assoc()) {
        $orders[] = $row;
    }
}
?>

<link rel="stylesheet" href="../css/products.css">

<style>
    .om-layout { display: grid; gap: 18px; }
    .om-table-actions { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
    .om-bulk-left { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .om-meta { color: #64748b; font-size: 13px; }
    .om-detail-grid { display: grid; grid-template-columns: 1.2fr 1fr; gap: 16px; }
    .om-section-title { margin: 0 0 12px; font-size: 16px; color: #0f172a; }
    .om-kv { display: grid; grid-template-columns: 110px 1fr; gap: 8px 14px; font-size: 14px; }
    .om-kv dt { color: #64748b; }
    .om-kv dd { margin: 0; font-weight: 600; color: #1f2937; }
    .pm-status-pending { background: #e2e8f0; color: #334155; }
    .pm-status-processing { background: #dbeafe; color: #1d4ed8; }
    .pm-status-shipped { background: #fef3c7; color: #92400e; }
    .pm-status-delivered { background: #ede9fe; color: #6d28d9; }
    .pm-status-completed { background: #dcfce7; color: #166534; }
    .pm-status-cancelled { background: #fee2e2; color: #991b1b; }
    .om-empty { padding: 22px 0; text-align: center; color: #94a3b8; }
    .om-detail-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px; }
    @media (max-width: 980px) {
        .om-detail-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="pm-wrap">
    <div class="pm-head">
        <div>
            <h1 class="pm-title">訂單管理</h1>
            <p class="pm-sub">集中管理訂單狀態、物流資訊與內部備註。</p>
        </div>
    </div>

    <section class="pm-card">
        <form method="GET" action="backend.php">
            <input type="hidden" name="page" value="orders">
            <div class="pm-grid">
                <div class="pm-col-3">
                    <label for="start_date">Start Date</label>
                    <input class="pm-input" type="date" id="start_date" name="start_date" value="<?php echo h($startDate); ?>">
                </div>
                <div class="pm-col-3">
                    <label for="end_date">End Date</label>
                    <input class="pm-input" type="date" id="end_date" name="end_date" value="<?php echo h($endDate); ?>">
                </div>
                <div class="pm-col-3">
                    <label for="keyword">訂單編號 / 手機</label>
                    <input class="pm-input" type="text" id="keyword" name="keyword" placeholder="例如：10023 或 0912" value="<?php echo h($keyword); ?>">
                </div>
                <div class="pm-col-3">
                    <label for="status">訂單狀態</label>
                    <select class="pm-select" id="status" name="status">
                        <option value="">全部狀態</option>
                        <?php foreach ($allowedStatuses as $status): ?>
                            <option value="<?php echo h($status); ?>" <?php echo $status === $statusFilter ? 'selected' : ''; ?>><?php echo h(orderStatusLabel($status)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="pm-col-12 om-detail-actions">
                    <button class="pm-btn pm-btn-main" type="submit">搜尋</button>
                    <a class="pm-btn pm-btn-sub" href="backend.php?page=orders">重置</a>
                </div>
            </div>
        </form>
    </section>

    <section class="pm-card">
        <div class="om-table-actions">
            <div class="om-bulk-left">
                <form method="POST" action="backend_action.php" id="bulkOrdersForm">
                    <?php echo apCsrfField(); ?>
                    <input type="hidden" name="action" value="bulk_update_orders">
                    <select class="pm-select" name="status" style="max-width:200px;">
                        <option value="">批次操作</option>
                        <option value="PROCESSING">批次改為處理中</option>
                        <option value="SHIPPED">批次出貨</option>
                        <option value="DELIVERED">批次改為已送達</option>
                        <option value="COMPLETED">批次完成</option>
                        <option value="CANCELLED">批次取消</option>
                    </select>
                    <button class="pm-btn pm-btn-sub pm-btn-sm" type="submit">套用</button>
                </form>
            </div>
            <div class="om-meta">共 <?php echo count($orders); ?> 筆 (最多顯示 200 筆)</div>
        </div>

        <?php if (empty($orders)): ?>
            <div class="om-empty">目前沒有符合條件的訂單。</div>
        <?php else: ?>
            <div class="pm-table-wrap">
                <table class="pm-table">
                    <thead>
                        <tr>
                            <th style="width:40px;"><input type="checkbox" id="ordersSelectAll"></th>
                            <th>訂單編號</th>
                            <th>顧客姓名 / 手機</th>
                            <th>總金額</th>
                            <th>商品件數</th>
                            <th>狀態</th>
                            <th>下單時間</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                            <?php
                            $statusClass = 'pm-status-pending';
                            if ($order['status'] === 'PROCESSING') { $statusClass = 'pm-status-processing'; }
                            if ($order['status'] === 'SHIPPED') { $statusClass = 'pm-status-shipped'; }
                            if ($order['status'] === 'DELIVERED') { $statusClass = 'pm-status-delivered'; }
                            if ($order['status'] === 'COMPLETED') { $statusClass = 'pm-status-completed'; }
                            if ($order['status'] === 'CANCELLED') { $statusClass = 'pm-status-cancelled'; }
                            ?>
                            <tr>
                                <td>
                                    <input type="checkbox" class="js-order-checkbox" name="order_ids[]" form="bulkOrdersForm" value="<?php echo intval($order['order_id']); ?>">
                                </td>
                                <td>#<?php echo intval($order['order_id']); ?></td>
                                <td>
                                    <div><?php echo h($order['user_name'] ?: $order['recipient_name']); ?></div>
                                    <div class="om-meta"><?php echo h($order['recipient_phone']); ?></div>
                                </td>
                                <td>NT$ <?php echo number_format((float)$order['total_amount']); ?></td>
                                <td><?php echo intval($order['item_count']); ?> 件</td>
                                <td><span class="pm-badge <?php echo h($statusClass); ?>"><?php echo h(orderStatusLabel($order['status'])); ?></span></td>
                                <td><?php echo h($order['created_at']); ?></td>
                                <td>
                                    <a class="pm-btn pm-btn-edit pm-btn-sm" href="<?php echo h(buildFilterQuery(['order_id' => intval($order['order_id'])])); ?>">查看詳情</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="pm-card">
        <h2 class="om-section-title">訂單詳細資訊</h2>
        <?php if ($selectedOrderId <= 0): ?>
            <div class="om-empty">請從上方列表點擊查看詳情。</div>
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
            $returnRequest = null;
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

                $returnTableRes = $conn->query("SHOW TABLES LIKE 'return_requests'");
                if ($returnTableRes && $returnTableRes->num_rows > 0) {
                    $returnStmt = $conn->prepare("
                        SELECT rr.*, u.name AS requester_name, u.email AS requester_email
                        FROM return_requests rr
                        LEFT JOIN users u ON u.user_id = rr.user_id
                        WHERE rr.order_id = ?
                        ORDER BY rr.created_at DESC, rr.return_id DESC
                        LIMIT 1
                    ");
                    if ($returnStmt) {
                        $returnStmt->bind_param("i", $selectedOrderId);
                        $returnStmt->execute();
                        $returnRequest = $returnStmt->get_result()->fetch_assoc();
                        $returnStmt->close();
                    }
                }
            }
            ?>

            <?php if (!$detail): ?>
                <div class="om-empty">訂單不存在。</div>
            <?php else: ?>
                <?php
                $discountAmount = isset($detail['discount_amount']) ? (float)$detail['discount_amount'] : max(0, (float)$detail['subtotal_amount'] + (float)$detail['shipping_fee'] - (float)$detail['total_amount']);
                $shippingNotes = '';
                if (isset($detail['shipping_notes']) && $detail['shipping_notes'] !== '') {
                    $shippingNotes = $detail['shipping_notes'];
                } elseif (isset($detail['note']) && $detail['note'] !== '') {
                    $shippingNotes = $detail['note'];
                }
                $trackingRaw = isset($detail['tracking_number']) ? $detail['tracking_number'] : '';
                list($shippingCarrier, $trackingNumber) = splitTrackingNumber($trackingRaw);
                ?>
                <div class="om-detail-grid">
                    <div>
                        <h3 class="om-section-title">訂單 / 付款</h3>
                        <dl class="om-kv">
                            <dt>訂單編號</dt><dd>#<?php echo intval($detail['order_id']); ?></dd>
                            <dt>客戶姓名</dt><dd><?php echo h($detail['user_name'] ?: $detail['recipient_name']); ?></dd>
                            <dt>聯絡信箱</dt><dd><?php echo h($detail['email'] ?: '-'); ?></dd>
                            <dt>優惠卷</dt><dd><?php echo !empty($detail['coupon_id']) ? h(($detail['coupon_name'] ?? ('#' . $detail['coupon_id'])) . (!empty($detail['coupon_code']) ? '（' . $detail['coupon_code'] . '）' : '')) : '-'; ?></dd>
                            <dt>付款方式</dt><dd><?php echo h($detail['payment_method']); ?></dd>
                            <dt>小計</dt><dd>NT$ <?php echo number_format((float)$detail['subtotal_amount']); ?></dd>
                            <dt>運費</dt><dd>NT$ <?php echo number_format((float)$detail['shipping_fee']); ?></dd>
                            <dt>折扣</dt><dd>NT$ <?php echo number_format($discountAmount); ?></dd>
                            <dt>總金額</dt><dd>NT$ <?php echo number_format((float)$detail['total_amount']); ?></dd>
                        </dl>
                    </div>
                    <div>
                        <h3 class="om-section-title">收件 / 物流</h3>
                        <dl class="om-kv">
                            <dt>收件人</dt><dd><?php echo h($detail['recipient_name']); ?></dd>
                            <dt>手機</dt><dd><?php echo h($detail['recipient_phone']); ?></dd>
                            <dt>地址</dt><dd><?php echo nl2br(h($detail['shipping_address'])); ?></dd>
                            <dt>備註</dt><dd><?php echo h($shippingNotes !== '' ? $shippingNotes : '-'); ?></dd>
                            <dt>下單時間</dt><dd><?php echo h($detail['created_at']); ?></dd>
                        </dl>
                    </div>
                </div>

                <div style="margin-top:16px;">
                    <h3 class="om-section-title">購買商品明細</h3>
                    <div class="pm-table-wrap">
                        <table class="pm-table">
                            <thead>
                                <tr>
                                    <th>商品</th>
                                    <th>SKU</th>
                                    <th>數量</th>
                                    <th>鎖定價格</th>
                                    <th>小計</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td>
                                            <div><?php echo h($item['product_name']); ?></div>
                                            <div class="om-meta"><?php echo h(($item['size_inches'] ?: '-') . ' / ' . ($item['color'] ?: '-')); ?></div>
                                        </td>
                                        <td><?php echo h($item['sku_code']); ?></td>
                                        <td><?php echo intval($item['quantity']); ?></td>
                                        <td>NT$ <?php echo number_format((float)$item['locked_price']); ?></td>
                                        <td>NT$ <?php echo number_format((float)$item['locked_price'] * (int)$item['quantity']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div style="margin-top:18px;">
                    <h3 class="om-section-title">狀態更新 / 物流資訊</h3>
                    <form action="backend_action.php" method="POST">
                        <?php echo apCsrfField(); ?>
                        <input type="hidden" name="action" value="update_order_status">
                        <input type="hidden" name="order_id" value="<?php echo intval($detail['order_id']); ?>">
                        <div class="pm-grid">
                            <div class="pm-col-3">
                                <label for="status_update">訂單狀態</label>
                                <select class="pm-select" id="status_update" name="status">
                                    <?php foreach ($allowedStatuses as $status): ?>
                                        <option value="<?php echo h($status); ?>" <?php echo $detail['status'] === $status ? 'selected' : ''; ?>><?php echo h(orderStatusLabel($status)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="pm-col-3">
                                <label for="shipping_carrier">物流公司</label>
                                <input class="pm-input" type="text" id="shipping_carrier" name="shipping_carrier" placeholder="例如：黑貓 / 郵局" value="<?php echo h($shippingCarrier); ?>">
                            </div>
                            <div class="pm-col-3">
                                <label for="tracking_number">物流追蹤單號</label>
                                <input class="pm-input" type="text" id="tracking_number" name="tracking_number" placeholder="輸入追蹤號碼" value="<?php echo h($trackingNumber); ?>">
                            </div>
                            <div class="pm-col-12">
                                <label for="admin_notes">內部備註 (僅管理員可見)</label>
                                <textarea class="pm-textarea" id="admin_notes" name="admin_notes" placeholder="紀錄客服處理、補件需求等..."><?php echo h($detail['admin_notes'] ?? ''); ?></textarea>
                            </div>
                            <div class="pm-col-12">
                                <button class="pm-btn pm-btn-main" type="submit">儲存更新</button>
                            </div>
                        </div>
                    </form>
                </div>

                <?php if ($returnRequest): ?>
                    <div style="margin-top:18px; padding:16px; border:1px solid #e2e8f0; border-radius:12px; background:#f8fafc;">
                        <h3 class="om-section-title">退貨申請審核</h3>
                        <dl class="om-kv">
                            <dt>申請人</dt><dd><?php echo h(($returnRequest['requester_name'] ?? '') ?: ($returnRequest['requester_email'] ?? '-')); ?></dd>
                            <dt>目前狀態</dt><dd><?php echo h($returnRequest['status']); ?></dd>
                            <dt>申請時間</dt><dd><?php echo h($returnRequest['created_at']); ?></dd>
                            <dt>退貨原因</dt><dd><?php echo nl2br(h($returnRequest['reason'])); ?></dd>
                            <?php if (!empty($returnRequest['admin_note'])): ?>
                                <dt>審核備註</dt><dd><?php echo nl2br(h($returnRequest['admin_note'])); ?></dd>
                            <?php endif; ?>
                        </dl>
                        <form action="backend_action.php" method="POST" style="margin-top:12px;">
                            <?php echo apCsrfField(); ?>
                            <input type="hidden" name="action" value="update_return_request">
                            <input type="hidden" name="return_id" value="<?php echo intval($returnRequest['return_id']); ?>">
                            <input type="hidden" name="order_id" value="<?php echo intval($detail['order_id']); ?>">
                            <div class="pm-grid">
                                <div class="pm-col-3">
                                    <label for="return_status">退貨狀態</label>
                                    <select class="pm-select" id="return_status" name="return_status">
                                        <?php foreach (['PENDING', 'APPROVED', 'REJECTED', 'REFUNDED'] as $returnStatus): ?>
                                            <option value="<?php echo h($returnStatus); ?>" <?php echo $returnRequest['status'] === $returnStatus ? 'selected' : ''; ?>><?php echo h($returnStatus); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="pm-col-9">
                                    <label for="return_admin_note">審核備註</label>
                                    <input class="pm-input" type="text" id="return_admin_note" name="admin_note" value="<?php echo h($returnRequest['admin_note'] ?? ''); ?>" placeholder="例如：同意退貨，請保留外箱並等待客服通知。">
                                </div>
                                <div class="pm-col-12">
                                    <button class="pm-btn pm-btn-main" type="submit">更新退貨狀態</button>
                                </div>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

                <div style="margin-top:18px; padding-top:18px; border-top:1px solid #e2e8f0;">
                    <h3 class="om-section-title">取消與封存</h3>
                    <form action="backend_action.php" method="POST" id="deleteOrderForm" style="display:flex; gap:10px; align-items:center;">
                        <?php echo apCsrfField(); ?>
                        <input type="hidden" name="action" value="delete_order">
                        <input type="hidden" name="order_id" value="<?php echo intval($detail['order_id']); ?>">
                        <label for="delete_older_days" style="margin:0;">取消備註保留天數：</label>
                        <input class="pm-input" type="number" id="delete_older_days" name="delete_older_days" min="0" value="0" style="max-width:80px; margin:0;">
                        <span style="font-size:12px; color:#64748b;">訂單資料會保留，只會改為取消並回補庫存。</span>
                        <button class="pm-btn pm-btn-danger" type="button" onclick="confirmDeleteOrder()">取消並封存訂單</button>
                    </form>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>

<script>
    const selectAll = document.getElementById('ordersSelectAll');
    const checkboxes = Array.from(document.querySelectorAll('.js-order-checkbox'));

    if (selectAll) {
        selectAll.addEventListener('change', () => {
            checkboxes.forEach(cb => { cb.checked = selectAll.checked; });
        });
    }

    checkboxes.forEach(cb => {
        cb.addEventListener('change', () => {
            if (!selectAll) {
                return;
            }
            selectAll.checked = checkboxes.length > 0 && checkboxes.every(item => item.checked);
        });
    });

    function confirmDeleteOrder() {
        const deleteOlderDays = parseInt(document.getElementById('delete_older_days').value) || 0;
        const msg = deleteOlderDays > 0
            ? '確定要取消並封存這筆訂單嗎？系統會保留訂單資料，並記錄你輸入的天數參考：' + deleteOlderDays + ' 天。'
            : '確定要取消並封存這筆訂單嗎？系統會保留訂單資料，並在需要時回補庫存。';

        if (confirm(msg)) {
            document.getElementById('deleteOrderForm').submit();
        }
    }
</script>

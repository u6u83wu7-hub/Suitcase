<?php
require_once __DIR__ . '/auth_guard.php';

if (intval($admin_role_id ?? 0) !== 1) {
    echo '<div style="padding:24px; background:#fff1f2; border:1px solid #fecaca; border-radius:12px; color:#991b1b; font-weight:700;">只有超級管理者可以查看此頁面。</div>';
    return;
}

$incompleteRecords = [];
$completeRecords = [];
$sql = "
    SELECT
        ss.supply_id,
        ss.supplier_id,
        ss.admin_id,
        ss.product_id,
        ss.variant_id,
        ss.supply_quantity,
        ss.is_supply_complete,
        ss.note,
        ss.created_at,
        s.name AS supplier_name,
        au.username AS admin_username,
        p.name AS product_name
        , pv.sku_code, pv.color, pv.size_inches
    FROM supplier_supplies ss
    LEFT JOIN suppliers s ON s.supplier_id = ss.supplier_id
    LEFT JOIN admin_users au ON au.admin_id = ss.admin_id
    LEFT JOIN products p ON p.product_id = ss.product_id
    LEFT JOIN product_variants pv ON pv.variant_id = ss.variant_id
    ORDER BY ss.created_at DESC, ss.supply_id DESC
";

$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        if (intval($row['is_supply_complete']) === 1) {
            $completeRecords[] = $row;
        } else {
            $incompleteRecords[] = $row;
        }
    }
}

$renderSupplyTable = function (array $rows, string $emptyMessage, bool $showAction = false) {
    if (empty($rows)) {
        echo '<div style="padding:20px; text-align:center; color:#64748b; background:#f8fafc; border:1px dashed #cbd5e1; border-radius:10px;">' . htmlspecialchars($emptyMessage) . '</div>';
        return;
    }
    ?>
    <div class="pm-table-wrap">
        <table class="pm-table">
            <thead>
                <tr>
                    <th>供應編號</th>
                    <th>廠商</th>
                    <th>建立者</th>
                    <th>商品 / 變體</th>
                    <th>數量</th>
                    <th>備註</th>
                    <th>建立時間</th>
                    <th>狀態</th>
                    <?php if ($showAction): ?><th>操作</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $record): ?>
                    <tr>
                        <td>#<?php echo intval($record['supply_id']); ?></td>
                        <td><?php echo htmlspecialchars($record['supplier_name'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($record['admin_username'] ?? ''); ?></td>
                        <td>
                            <?php echo htmlspecialchars($record['product_name'] ?? ''); ?>
                            <?php if (!empty($record['variant_id'])): ?>
                                <div style="font-size:13px; color:#64748b; margin-top:4px;">
                                    SKU #<?php echo intval($record['variant_id']); ?>
                                    <?php if (!empty($record['sku_code'])): ?> / <?php echo htmlspecialchars($record['sku_code']); ?><?php endif; ?>
                                    <?php if (!empty($record['color'])): ?> / <?php echo htmlspecialchars($record['color']); ?><?php endif; ?>
                                    <?php if (!empty($record['size_inches'])): ?> / <?php echo htmlspecialchars($record['size_inches']); ?><?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo intval($record['supply_quantity']); ?></td>
                        <td><?php echo htmlspecialchars($record['note'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($record['created_at']); ?></td>
                        <td>
                            <?php if (intval($record['is_supply_complete']) === 1): ?>
                                <span class="pm-badge pm-on">已完成</span>
                            <?php else: ?>
                                <span class="pm-badge pm-off">未完成</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($showAction): ?>
                            <td>
                                <?php if (intval($record['is_supply_complete']) === 0): ?>
                                    <form method="POST" action="backend_action.php" onsubmit="return confirm('確定要將此筆供應紀錄標記為已完成並增加庫存嗎？');" style="display:inline;">
                                        <input type="hidden" name="action" value="complete_supplier_supply">
                                        <input type="hidden" name="supply_id" value="<?php echo intval($record['supply_id']); ?>">
                                        <?php echo apCsrfField(); ?>
                                        <button class="pm-btn pm-btn-main pm-btn-sm" type="submit">確認補貨</button>
                                    </form>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
};
?>

<link rel="stylesheet" href="../css/products.css">

<div class="pm-wrap">
    <div class="pm-head">
        <div>
            <h1 class="pm-title">供應表單</h1>
            <p class="pm-sub">分開顯示未完成與已完成的供應紀錄</p>
        </div>
        <div style="display:flex; gap:8px; align-items:center;">
            <span class="pm-badge pm-off">未完成 <?php echo count($incompleteRecords); ?></span>
            <span class="pm-badge pm-on">已完成 <?php echo count($completeRecords); ?></span>
        </div>
    </div>

    <section class="pm-card" id="tab-supply-records">
        <div style="display:grid; gap:20px;">
            <div>
                <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:14px;">
                    <h2 class="pm-subtitle" style="margin:0;">未完成</h2>
                    <span style="font-size:13px; color:#64748b;">共 <?php echo count($incompleteRecords); ?> 筆</span>
                </div>
                <?php $renderSupplyTable($incompleteRecords, '目前沒有未完成的供應資料。', true); ?>
            </div>

            <div>
                <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:14px;">
                    <h2 class="pm-subtitle" style="margin:0;">已完成</h2>
                    <span style="font-size:13px; color:#64748b;">共 <?php echo count($completeRecords); ?> 筆</span>
                </div>
                <?php $renderSupplyTable($completeRecords, '目前沒有已完成的供應資料。', false); ?>
            </div>
        </div>
    </section>
</div>
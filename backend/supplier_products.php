<?php
require_once __DIR__ . '/auth_guard.php';

if (intval($admin_role_id ?? 0) !== 3) {
    echo '<div style="padding:24px; background:#fff1f2; border:1px solid #fecaca; border-radius:12px; color:#991b1b; font-weight:700;">只有廠商帳號可以查看此頁面。</div>';
    return;
}

$pageMessage = trim($_GET['message'] ?? '');
$supplierId = 0;
$supplierName = '';
$adminId = intval($_SESSION['admin_id'] ?? 0);

function supplyRequestStatusMeta($status) {
    $status = strtoupper((string)$status);
    $map = [
        'PENDING' => ['label' => '待供應', 'class' => 'pm-off', 'hint' => '管理員已提出需求，尚未有供應紀錄。'],
        'PARTIAL' => ['label' => '部分供應', 'class' => 'pm-featured', 'hint' => '已供應部分數量，仍有剩餘待補。'],
        'COMPLETED' => ['label' => '已完成', 'class' => 'pm-on', 'hint' => '供應數量已滿足需求。'],
        'CANCELLED' => ['label' => '已取消', 'class' => 'pm-off', 'hint' => '此供應請求已取消，不需再處理。'],
    ];
    return $map[$status] ?? ['label' => $status, 'class' => 'pm-off', 'hint' => '請確認此供應請求狀態。'];
}

$supplierStmt = $conn->prepare("SELECT supplier_id, name FROM suppliers WHERE admin_id = ? LIMIT 1");
$supplierStmt->bind_param('i', $adminId);
$supplierStmt->execute();
if ($supplierRow = $supplierStmt->get_result()->fetch_assoc()) {
    $supplierId = intval($supplierRow['supplier_id']);
    $supplierName = $supplierRow['name'];
}
$supplierStmt->close();

if ($supplierId <= 0) {
    echo '<div style="padding:24px; background:#fff1f2; border:1px solid #fecaca; border-radius:12px; color:#991b1b; font-weight:700;">找不到對應的廠商資料，請聯絡管理員。</div>';
    return;
}

$myProducts = [];
$productStmt = $conn->prepare("SELECT product_id, name FROM products WHERE supplier_id = ? ORDER BY name ASC");
$productStmt->bind_param('i', $supplierId);
$productStmt->execute();
$productResult = $productStmt->get_result();
while ($productRow = $productResult->fetch_assoc()) {
    $myProducts[] = $productRow;
}
$productStmt->close();

// 準備 variants map for vendor-side two-step selector
$variantsByProduct = [];
$productIds = array_map(function($p){ return intval($p['product_id']); }, $myProducts);
if (!empty($productIds)) {
    $ids = implode(',', $productIds);
    $vSql = "SELECT variant_id, product_id, sku_code, size_inches, color FROM product_variants WHERE product_id IN ({$ids}) ORDER BY variant_id";
    $vres = $conn->query($vSql);
    if ($vres) {
        while ($v = $vres->fetch_assoc()) {
            $pid = intval($v['product_id']);
            if (!isset($variantsByProduct[$pid])) $variantsByProduct[$pid] = [];
            $variantsByProduct[$pid][] = $v;
        }
    }
}

$supplyRecords = [];
 $recordStmt = $conn->prepare(" 
    SELECT ss.supply_id, ss.product_id, ss.variant_id, ss.supply_quantity, ss.note, ss.created_at, p.name AS product_name
    FROM supplier_supplies ss
    JOIN products p ON p.product_id = ss.product_id
    WHERE ss.supplier_id = ?
    ORDER BY ss.created_at DESC, ss.supply_id DESC
    LIMIT 12
");
$recordStmt->bind_param('i', $supplierId);
$recordStmt->execute();
$recordResult = $recordStmt->get_result();
while ($recordRow = $recordResult->fetch_assoc()) {
    $supplyRecords[] = $recordRow;
}
$recordStmt->close();

$supplyRequests = [];
$requestStmt = $conn->prepare("
    SELECT
        sr.request_id, sr.product_id, sr.variant_id, sr.requested_quantity, sr.note, sr.request_status, sr.created_at,
        p.name AS product_name,
        pv.sku_code, pv.color, pv.size_inches,
        COALESCE(SUM(ss.supply_quantity), 0) AS supplied_quantity,
        GREATEST(sr.requested_quantity - COALESCE(SUM(ss.supply_quantity), 0), 0) AS remaining_quantity
    FROM supply_requests sr
    JOIN products p ON p.product_id = sr.product_id
    LEFT JOIN product_variants pv ON pv.variant_id = sr.variant_id
    LEFT JOIN supplier_supplies ss ON ss.request_id = sr.request_id
    WHERE p.supplier_id = ?
    GROUP BY sr.request_id, sr.product_id, sr.variant_id, sr.requested_quantity, sr.note, sr.request_status, sr.created_at, p.name, pv.sku_code, pv.color, pv.size_inches
    ORDER BY sr.created_at DESC, sr.request_id DESC
");
$requestStmt->bind_param('i', $supplierId);
$requestStmt->execute();
$requestResult = $requestStmt->get_result();
while ($requestRow = $requestResult->fetch_assoc()) {
    $supplyRequests[] = $requestRow;
}
$requestStmt->close();

$pendingSupplyRequestCount = 0;
foreach ($supplyRequests as $requestRow) {
    $requestStatus = strtoupper((string)$requestRow['request_status']);
    $remainingQuantity = intval($requestRow['remaining_quantity'] ?? 0);
    if (!in_array($requestStatus, ['COMPLETED', 'CANCELLED'], true) && $remainingQuantity > 0) {
        $pendingSupplyRequestCount++;
    }
}
?>

<style>
    .vendor-supply-grid { display:grid; grid-template-columns: 1.1fr 0.9fr; gap:20px; align-items:start; }
    .vendor-request-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    @media (max-width: 820px) {
        .vendor-supply-grid { grid-template-columns:1fr; }
        .vendor-request-actions { align-items:stretch; flex-direction:column; }
        .vendor-request-actions .alt { width:100%; }
    }
</style>

<h1 style="font-size:24px; margin-top:0; margin-bottom:4px;">🏪 供應商品</h1>
<p class="muted">廠商：<?php echo htmlspecialchars($supplierName); ?>。此頁會把你送出的供應資料寫進供應紀錄表。</p>

<?php if ($pageMessage !== ''): ?>
    <div style="margin:16px 0; padding:12px 16px; border-radius:8px; background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; font-weight:700;">
        <?php echo htmlspecialchars($pageMessage); ?>
    </div>
<?php endif; ?>

<div class="vendor-supply-grid">
    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:20px;">
        <h2 style="margin-top:0; font-size:18px; color:#1e293b;">送出供應資料</h2>

        <?php if (empty($myProducts)): ?>
            <div style="padding:16px; background:#f8fafc; border:1px dashed #cbd5e1; border-radius:10px; color:#475569;">目前沒有綁定到你帳號的商品，請先由管理員將商品指派給你的廠商。</div>
        <?php else: ?>
            <form method="POST" action="backend_action.php">
                <input type="hidden" name="action" value="submit_supplier_supply">
                <?php echo apCsrfField(); ?>

                <label style="font-weight:700; color:#475569; font-size:13px;">選擇商品</label>
                <select id="product_select" name="product_id" required>
                    <option value="">請選擇商品</option>
                    <?php foreach ($myProducts as $product): ?>
                        <option value="<?php echo intval($product['product_id']); ?>"><?php echo htmlspecialchars($product['name']); ?></option>
                    <?php endforeach; ?>
                </select>

                <label style="font-weight:700; color:#475569; font-size:13px; margin-top:8px;">選擇 SKU（若有）</label>
                <select id="variant_select" name="variant_id" disabled>
                    <option value="">請先選擇商品以載入 SKU</option>
                </select>

                <label style="font-weight:700; color:#475569; font-size:13px;">供應數量</label>
                <input type="number" name="supply_quantity" min="1" step="1" required placeholder="請輸入數量">

                <label style="font-weight:700; color:#475569; font-size:13px;">備註（選填）</label>
                <textarea name="note" rows="4" placeholder="例如：已補貨、顏色到貨、請優先上架..." style="width:100%; padding:10px; box-sizing:border-box; border:1px solid #ddd; border-radius:6px;"></textarea>

                <button type="submit" class="alt" style="margin-top:10px;">送出供應資料</button>
            </form>
        <?php endif; ?>
    </div>

    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px;">
        <h2 style="margin-top:0; font-size:18px; color:#1e293b;">最近供應紀錄</h2>
        <?php if (empty($supplyRecords)): ?>
            <div style="padding:16px; color:#64748b; background:#fff; border-radius:10px; border:1px dashed #cbd5e1;">目前還沒有供應紀錄。</div>
        <?php else: ?>
            <div style="display:grid; gap:12px;">
                <?php foreach ($supplyRecords as $record): ?>
                    <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:14px;">
                        <div style="font-weight:800; color:#1e293b; margin-bottom:4px;">
                            <?php echo htmlspecialchars($record['product_name']); ?>
                            <?php if (!empty($record['variant_id'])): ?>
                                <span style="font-weight:600; color:#64748b; font-size:13px;"> / SKU #<?php echo intval($record['variant_id']); ?></span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:13px; color:#475569;">數量：<?php echo intval($record['supply_quantity']); ?></div>
                        <?php if (!empty($record['note'])): ?>
                            <div style="font-size:13px; color:#64748b; margin-top:4px;">備註：<?php echo htmlspecialchars($record['note']); ?></div>
                        <?php endif; ?>
                        <div style="font-size:12px; color:#94a3b8; margin-top:6px;"><?php echo htmlspecialchars($record['created_at']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div style="margin-top:20px; background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:14px;">
        <h2 style="margin:0; font-size:18px; color:#1e293b;">供應請求</h2>
        <span style="color:#64748b; font-size:13px;">尚有 <?php echo intval($pendingSupplyRequestCount); ?> 筆可供應請求；可供應的請求會顯示「供應」按鈕</span>
    </div>

    <?php if (empty($supplyRequests)): ?>
        <div style="padding:16px; color:#64748b; background:#f8fafc; border-radius:10px; border:1px dashed #cbd5e1;">目前沒有供應請求。</div>
    <?php else: ?>
        <div style="display:grid; gap:12px;">
            <?php foreach ($supplyRequests as $request): ?>
                <?php
                    $status = strtoupper((string)$request['request_status']);
                    $suppliedQuantity = intval($request['supplied_quantity'] ?? 0);
                    $remainingQuantity = intval($request['remaining_quantity'] ?? max(0, intval($request['requested_quantity']) - $suppliedQuantity));
                    $canSupply = !in_array($status, ['COMPLETED', 'CANCELLED'], true) && $remainingQuantity > 0;
                    $requestFormId = 'request-form-' . intval($request['request_id']);
                    $statusMeta = supplyRequestStatusMeta($status);
                ?>
                <div id="request-card-<?php echo intval($request['request_id']); ?>" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px;">
                    <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start; flex-wrap:wrap;">
                        <div>
                            <div style="font-weight:800; color:#1e293b;">
                                <?php echo htmlspecialchars($request['product_name']); ?>
                                <span style="font-size:13px; color:#64748b; font-weight:600;">/ SKU #<?php echo intval($request['variant_id']); ?></span>
                            </div>
                            <div style="font-size:13px; color:#475569; margin-top:4px;">
                                <?php if (!empty($request['sku_code'])): ?><?php echo htmlspecialchars($request['sku_code']); ?><?php endif; ?>
                                <?php if (!empty($request['color'])): ?> / <?php echo htmlspecialchars($request['color']); ?><?php endif; ?>
                                <?php if (!empty($request['size_inches'])): ?> / <?php echo htmlspecialchars($request['size_inches']); ?><?php endif; ?>
                            </div>
                            <div style="font-size:13px; color:#475569; margin-top:4px;">預期數量：<?php echo intval($request['requested_quantity']); ?></div>
                            <div style="font-size:13px; color:#475569; margin-top:4px;">已供應：<?php echo $suppliedQuantity; ?> / 剩餘：<?php echo $remainingQuantity; ?></div>
                            <?php if (!empty($request['note'])): ?>
                                <div style="font-size:13px; color:#64748b; margin-top:4px;">備註：<?php echo htmlspecialchars($request['note']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="vendor-request-actions">
                            <span id="request-badge-<?php echo intval($request['request_id']); ?>" class="pm-badge <?php echo htmlspecialchars($statusMeta['class']); ?>" title="<?php echo htmlspecialchars($statusMeta['hint']); ?>"><?php echo htmlspecialchars($statusMeta['label']); ?></span>
                            <?php if ($canSupply): ?>
                                <button id="supply-btn-<?php echo intval($request['request_id']); ?>" type="button" class="alt" style="padding:8px 14px;" onclick="toggleRequestForm('<?php echo $requestFormId; ?>')">供應</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($canSupply): ?>
                        <div id="<?php echo $requestFormId; ?>" style="display:none; margin-top:14px; padding-top:14px; border-top:1px dashed #cbd5e1;">
                            <form method="POST" action="backend_action.php" style="display:grid; gap:10px; max-width:520px;">
                                <input type="hidden" name="action" value="submit_supplier_supply">
                                <input type="hidden" name="request_id" value="<?php echo intval($request['request_id']); ?>">
                                <input type="hidden" name="product_id" value="<?php echo intval($request['product_id']); ?>">
                                <input type="hidden" name="variant_id" value="<?php echo intval($request['variant_id']); ?>">
                                <?php echo apCsrfField(); ?>

                                <div style="font-size:13px; color:#64748b;">此表單已預設商品與 SKU，廠商只能調整供應數量。</div>

                                <label style="font-weight:700; color:#475569; font-size:13px;">供應數量</label>
                                <input type="number" name="supply_quantity" min="1" max="<?php echo max(1, $remainingQuantity); ?>" step="1" required value="<?php echo max(1, $remainingQuantity); ?>" placeholder="請輸入數量">

                                <label style="font-weight:700; color:#475569; font-size:13px;">備註（選填）</label>
                                <textarea name="note" rows="3" placeholder="例如：分批供應、數量不足..." style="width:100%; padding:10px; box-sizing:border-box; border:1px solid #ddd; border-radius:6px;"><?php echo htmlspecialchars($request['note'] ?? ''); ?></textarea>

                                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                    <button type="submit" class="alt">送出供應</button>
                                    <button type="button" class="alt" style="background:#f1f5f9; color:#334155;" onclick="toggleRequestForm('<?php echo $requestFormId; ?>')">取消</button>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
// Variant data for products
const variantsByProduct = <?php echo json_encode($variantsByProduct, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP); ?>;

function buildVariantOptionLabel(v) {
    let parts = [];
    if (v.sku_code) parts.push(v.sku_code);
    if (v.color) parts.push(v.color);
    if (v.size_inches) parts.push(v.size_inches);
    return parts.length ? parts.join(' / ') : ('SKU #' + v.variant_id);
}

document.addEventListener('DOMContentLoaded', function () {
    const productSelect = document.getElementById('product_select');
    const variantSelect = document.getElementById('variant_select');

    productSelect.addEventListener('change', function () {
        const pid = parseInt(this.value) || 0;
        variantSelect.innerHTML = '';
        if (!pid || !variantsByProduct[pid] || variantsByProduct[pid].length === 0) {
            variantSelect.disabled = true;
            const opt = document.createElement('option');
            opt.value = '';
            opt.textContent = '此商品無 SKU，將以商品主檔為準';
            variantSelect.appendChild(opt);
            return;
        }
        variantSelect.disabled = false;
        const empty = document.createElement('option');

        
        empty.value = '';
        empty.textContent = '請選擇 SKU（可選）';
        variantSelect.appendChild(empty);
        variantsByProduct[pid].forEach(function (v) {
            const o = document.createElement('option');
            o.value = v.variant_id;
            o.textContent = buildVariantOptionLabel(v);
            variantSelect.appendChild(o);
        });
    });
});

// Toggle request form globally so inline onclick can call it
function toggleRequestForm(formId) {
    const form = document.getElementById(formId);
    if (!form) return;
    form.style.display = (form.style.display === 'none' || form.style.display === '') ? 'block' : 'none';
}

</script>

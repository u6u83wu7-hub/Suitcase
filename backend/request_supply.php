<?php
require_once __DIR__ . '/auth_guard.php';

if (intval($admin_role_id ?? 0) !== 1) {
    echo '<div style="padding:24px; background:#fff1f2; border:1px solid #fecaca; border-radius:12px; color:#991b1b; font-weight:700;">只有超級管理者可以查看此頁面。</div>';
    return;
}

$message = trim($_GET['message'] ?? '');
$selectedProductId = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;

$products = [];
$pRes = $conn->query("SELECT product_id, name FROM products ORDER BY name ASC");
if ($pRes) {
    while ($row = $pRes->fetch_assoc()) {
        $products[] = $row;
    }
}

$selectedProduct = null;
$variants = [];
if ($selectedProductId > 0) {
    foreach ($products as $product) {
        if (intval($product['product_id']) === $selectedProductId) {
            $selectedProduct = $product;
            break;
        }
    }
    $vStmt = $conn->prepare("SELECT variant_id, sku_code, color, size_inches, stock_available FROM product_variants WHERE product_id = ? ORDER BY variant_id ASC");
    $vStmt->bind_param('i', $selectedProductId);
    $vStmt->execute();
    $vRes = $vStmt->get_result();
    while ($row = $vRes->fetch_assoc()) {
        $variants[] = $row;
    }
    $vStmt->close();
}

$recentRequests = [];
$rSql = "
    SELECT sr.request_id, sr.product_id, sr.variant_id, sr.requested_quantity, sr.note, sr.request_status, sr.created_at,
           p.name AS product_name, pv.sku_code, pv.color, pv.size_inches
    FROM supply_requests sr
    LEFT JOIN products p ON p.product_id = sr.product_id
    LEFT JOIN product_variants pv ON pv.variant_id = sr.variant_id
    ORDER BY sr.created_at DESC, sr.request_id DESC
    LIMIT 12
";
$rRes = $conn->query($rSql);
if ($rRes) {
    while ($row = $rRes->fetch_assoc()) {
        $recentRequests[] = $row;
    }
}
?>

<link rel="stylesheet" href="../css/products.css?v=<?php echo @filemtime(__DIR__ . '/../css/products.css') ?: time(); ?>">

<div class="pm-wrap">
    <div class="pm-head">
        <div>
            <h1 class="pm-title">請求供貨</h1>
            <p class="pm-sub">從商品管理進來，指定商品的 SKU 與預期數量後送出請求供貨。</p>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div style="margin-bottom:16px; padding:12px 16px; border-radius:8px; background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; font-weight:700;">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <div style="display:grid; grid-template-columns: 1.1fr 0.9fr; gap:20px; align-items:start;">
        <div class="pm-card">
            <h2 class="pm-subtitle" style="margin-top:0;">建立請求</h2>

            <form method="POST" action="backend_action.php" class="pm-grid" style="max-width:100%;">
                <input type="hidden" name="action" value="submit_supply_request">
                <?php echo apCsrfField(); ?>

                <div class="pm-col-2">
                    <label>選擇商品</label>
                    <select class="pm-select" id="request_product_select" name="product_id" required>
                        <option value="">請選擇商品</option>
                        <?php foreach ($products as $product): ?>
                            <option value="<?php echo intval($product['product_id']); ?>" <?php echo $selectedProductId === intval($product['product_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($product['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="pm-col-2">
                    <label>選擇子商品 / SKU</label>
                    <select class="pm-select" id="request_variant_select" name="variant_id" required <?php echo $selectedProductId > 0 ? '' : 'disabled'; ?>>
                        <option value=""><?php echo $selectedProductId > 0 ? '請選擇 SKU' : '請先選擇商品'; ?></option>
                        <?php foreach ($variants as $variant): ?>
                            <option value="<?php echo intval($variant['variant_id']); ?>">
                                #<?php echo intval($variant['variant_id']); ?>
                                <?php echo htmlspecialchars(trim(($variant['sku_code'] ?? '') . ' ' . ($variant['color'] ?? '') . ' ' . ($variant['size_inches'] ?? ''))); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="pm-col-2">
                    <label>預期數量</label>
                    <input class="pm-input" type="number" name="requested_quantity" min="1" step="1" required placeholder="輸入預期數量">
                </div>

                <div class="pm-col-2">
                    <label>備註（選填）</label>
                    <textarea class="pm-input" name="note" rows="4" placeholder="例如：請先補貨、預計下週到貨..." style="resize:vertical;"></textarea>
                </div>

                <div class="pm-col-2" style="display:flex; gap:8px; align-items:center;">
                    <button class="pm-btn pm-btn-main" type="submit">送出請求供貨</button>
                    <a class="pm-btn pm-btn-sub" href="backend.php?page=products">返回商品管理</a>
                </div>
            </form>
        </div>

        <div class="pm-card">
            <h2 class="pm-subtitle" style="margin-top:0;">最近請求紀錄</h2>
            <?php if (empty($recentRequests)): ?>
                <div style="padding:16px; color:#64748b; background:#f8fafc; border-radius:10px; border:1px dashed #cbd5e1;">目前還沒有請求供貨資料。</div>
            <?php else: ?>
                <div style="display:grid; gap:12px;">
                    <?php foreach ($recentRequests as $record): ?>
                        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:14px;">
                            <div style="font-weight:800; color:#1e293b; margin-bottom:4px;"><?php echo htmlspecialchars($record['product_name'] ?? ''); ?></div>
                            <div style="font-size:13px; color:#475569;">
                                SKU #<?php echo intval($record['variant_id']); ?>
                                <?php if (!empty($record['sku_code'])): ?> / <?php echo htmlspecialchars($record['sku_code']); ?><?php endif; ?>
                            </div>
                            <div style="font-size:13px; color:#475569;">預期數量：<?php echo intval($record['requested_quantity']); ?></div>
                            <div style="margin-top:4px;"><span class="pm-badge pm-off"><?php echo htmlspecialchars($record['request_status']); ?></span></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const requestVariantsByProduct = <?php
    $variantMap = [];
    if (!empty($products)) {
        $ids = array_map(fn($p) => intval($p['product_id']), $products);
        $idSql = implode(',', $ids);
        $vres = $conn->query("SELECT variant_id, product_id, sku_code, color, size_inches FROM product_variants WHERE product_id IN ({$idSql}) ORDER BY variant_id ASC");
        if ($vres) {
            while ($row = $vres->fetch_assoc()) {
                $pid = intval($row['product_id']);
                if (!isset($variantMap[$pid])) $variantMap[$pid] = [];
                $variantMap[$pid][] = $row;
            }
        }
    }
    echo json_encode($variantMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>;

document.addEventListener('DOMContentLoaded', function () {
    const productSelect = document.getElementById('request_product_select');
    const variantSelect = document.getElementById('request_variant_select');

    function renderVariants(productId) {
        variantSelect.innerHTML = '';
        const variants = requestVariantsByProduct[productId] || [];
        if (!productId || variants.length === 0) {
            variantSelect.disabled = true;
            const opt = document.createElement('option');
            opt.value = '';
            opt.textContent = productId ? '此商品沒有 SKU' : '請先選擇商品';
            variantSelect.appendChild(opt);
            return;
        }
        variantSelect.disabled = false;
        const empty = document.createElement('option');
        empty.value = '';
        empty.textContent = '請選擇 SKU';
        variantSelect.appendChild(empty);
        variants.forEach(function (variant) {
            const option = document.createElement('option');
            option.value = variant.variant_id;
            option.textContent = '#' + variant.variant_id + ' ' + [variant.sku_code, variant.color, variant.size_inches].filter(Boolean).join(' / ');
            variantSelect.appendChild(option);
        });
    }

    productSelect.addEventListener('change', function () {
        renderVariants(parseInt(this.value, 10) || 0);
    });

    renderVariants(parseInt(productSelect.value, 10) || 0);
});
</script>

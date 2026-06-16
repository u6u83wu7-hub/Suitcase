<?php
require_once __DIR__ . '/../auth_guard.php';
// products/edit_product.php - 編輯商品頁面（置於子資料夾內）

$product_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$lowStockThreshold = isset($lowStockThreshold) ? (int)$lowStockThreshold : 5;
if ($product_id <= 0) {
    echo "<script>alert('無效的商品 ID'); location.href='backend.php?page=products';</script>";
    exit;
}

function epTableExists($conn, $tableName) {
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    $res = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
    return $res && $res->num_rows > 0;
}

function epSizeSortValue($size) {
    $size = trim((string)$size);
    if ($size === '') {
        return 999999;
    }
    if (preg_match('/\d+/', $size, $match)) {
        return (int)$match[0];
    }
    return 999999;
}

function epCompareVariantsBySize($a, $b) {
    $sizeA = epSizeSortValue($a['size_inches'] ?? '');
    $sizeB = epSizeSortValue($b['size_inches'] ?? '');
    if ($sizeA !== $sizeB) {
        return $sizeA <=> $sizeB;
    }
    $rawSizeCompare = strcmp((string)($a['size_inches'] ?? ''), (string)($b['size_inches'] ?? ''));
    if ($rawSizeCompare !== 0) {
        return $rawSizeCompare;
    }
    $colorCompare = strcmp((string)($a['color'] ?? ''), (string)($b['color'] ?? ''));
    if ($colorCompare !== 0) {
        return $colorCompare;
    }
    return (int)($a['variant_id'] ?? 0) <=> (int)($b['variant_id'] ?? 0);
}

// 1. 取得基本資料
$stmt = $conn->prepare("SELECT * FROM products WHERE product_id = ?");
$stmt->bind_param("i", $product_id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
if (!$product) {
    echo "<script>alert('找不到該商品'); location.href='backend.php?page=products';</script>";
    exit;
}

// 2. 取得所有分類
$categories = [];
$catRes = $conn->query("SELECT * FROM categories ORDER BY name ASC");
if ($catRes) while ($c = $catRes->fetch_assoc()) $categories[] = $c;

// 2.1 取得商品既有分類
$selectedCategoryIds = [];
$linkStmt = $conn->prepare("SELECT category_id FROM product_category_links WHERE product_id = ?");
$linkStmt->bind_param("i", $product_id);
$linkStmt->execute();
$linkRes = $linkStmt->get_result();
while ($link = $linkRes->fetch_assoc()) {
    $selectedCategoryIds[] = (int)$link['category_id'];
}

// 3. 取得所有 SKU 規格
$variants = [];
$vStmt = $conn->prepare("SELECT * FROM product_variants WHERE product_id = ?");
$vStmt->bind_param("i", $product_id);
$vStmt->execute();
$vRes = $vStmt->get_result();
while ($v = $vRes->fetch_assoc()) $variants[] = $v;
usort($variants, 'epCompareVariantsBySize');
if (empty($variants)) { 
    $variants[] = [
        'variant_id' => '',
        'size_inches' => '',
        'color' => '',
        'original_price' => 0,
        'special_price' => null,
        'member_price' => 0,
        'stock_available' => 0
    ];
}
$skuCount = count($variants);
$totalStock = 0;
$outSkuCount = 0;
$lowSkuCount = 0;
foreach ($variants as $variantForStock) {
    $stock = (int)($variantForStock['stock_available'] ?? 0);
    $totalStock += $stock;
    if ($stock === 0) {
        $outSkuCount++;
    } elseif ($stock <= $lowStockThreshold) {
        $lowSkuCount++;
    }
}

// 4. 取得舊圖片
$images = [];
$iStmt = $conn->prepare("SELECT * FROM product_images WHERE product_id = ? ORDER BY is_main DESC, image_id ASC");
$iStmt->bind_param("i", $product_id);
$iStmt->execute();
$iRes = $iStmt->get_result();
while ($i = $iRes->fetch_assoc()) $images[] = $i;

$inventoryLogs = [];
if (epTableExists($conn, 'inventory_adjustment_logs')) {
    $logStmt = $conn->prepare("
        SELECT l.*, au.username AS admin_username
        FROM inventory_adjustment_logs l
        LEFT JOIN admin_users au ON au.admin_id = l.admin_id
        WHERE l.product_id = ?
        ORDER BY l.created_at DESC, l.log_id DESC
        LIMIT 12
    ");
    if ($logStmt) {
        $logStmt->bind_param('i', $product_id);
        $logStmt->execute();
        $logRes = $logStmt->get_result();
        while ($log = $logRes->fetch_assoc()) {
            $inventoryLogs[] = $log;
        }
        $logStmt->close();
    }
}
?>

<section class="pm-card" id="tab-edit">
    <form action="backend_action.php" method="POST" enctype="multipart/form-data">
        <?php if (function_exists('apCsrfField')) echo apCsrfField(); ?>
        <input type="hidden" name="action" value="update_product">
        <input type="hidden" name="product_id" value="<?= $product_id ?>">

        <div class="pm-section-box">
            <h3 class="pm-section-title">基本資訊</h3>
            <div class="pm-grid">
                <div class="pm-col-3">
                    <label>商品名稱 <span style="color:#ef4444;">*</span></label>
                    <input class="pm-input" type="text" name="name" required value="<?= htmlspecialchars($product['name']) ?>">
                </div>
                
                <div class="pm-col-3">
                    <label>分類（可多選）</label>
                    <div class="category-dropdown" style="position:relative;">
                        <button type="button" class="pm-select category-toggle" style="text-align:left; width:100%;">選擇分類</button>
                        <div class="category-menu" style="position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid #e5e7eb; border-radius:6px; margin-top:6px; padding:10px; max-height:200px; overflow:auto; display:none; z-index:20;">
                            <?php foreach ($categories as $cat): ?>
                                <label style="font-weight: normal; cursor: pointer; display:flex; align-items:center; gap:6px; padding:6px 4px;">
                                    <input type="checkbox" name="category_ids[]" value="<?= intval($cat['category_id']) ?>" <?= in_array((int)$cat['category_id'], $selectedCategoryIds, true) ? 'checked' : '' ?>>
                                    <?= htmlspecialchars($cat['name']) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <input type="text" class="pm-input" name="new_category_name" style="margin-top:8px;" placeholder="新增分類名稱（選填）">
                </div>
                
                <div class="pm-col-3">
                    <label>廠商名稱（選填）</label>
                    <select class="pm-select" name="supplier_id">
                        <option value="">不指定廠商</option>
                        <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?= intval($supplier['supplier_id']) ?>" <?= (isset($product['supplier_id']) && $product['supplier_id'] == $supplier['supplier_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($supplier['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="pm-col-3" style="display:flex; align-items:center; padding-bottom:8px;">
                    <label style="margin:0; cursor:pointer; display:flex; align-items:center; gap:6px;">
                        <input type="checkbox" name="is_featured" value="1" <?= $product['is_featured'] ? 'checked' : '' ?> style="width:16px; height:16px;">
                        設為首頁精選
                    </label>
                </div>
                <div class="pm-col-12">
                    <label>商品描述</label>
                    <textarea class="pm-textarea" name="description"><?= htmlspecialchars($product['description'] ?? '') ?></textarea>
                </div>
                <div class="pm-col-12">
                    <label>保固與附加資訊</label>
                    <textarea class="pm-textarea" name="warranty_info"><?= htmlspecialchars($product['warranty_info'] ?? '') ?></textarea>
                </div>
            </div>
        </div>

        <div class="pm-section-box">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                <h3 class="pm-section-title" style="border:none; padding:0; margin:0;">SKU 規格與價格配置</h3>
                <button type="button" class="pm-btn pm-btn-sub pm-btn-sm" id="addSkuBtn">+ 新增一組規格</button>
            </div>
            <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; padding:12px 14px; border:1px solid #e2e8f0; border-radius:10px; background:#f8fafc; margin-bottom:14px;">
                <span style="font-weight:700; color:#0f172a;">庫存摘要</span>
                <span class="pm-badge" style="background:#e2e8f0; color:#334155;" title="每一組尺寸 / 顏色 / 價格 / 庫存組合是一個 SKU。">規格 <?php echo $skuCount; ?></span>
                <span class="pm-badge" style="background:#dcfce7; color:#166534;">總庫存 <?php echo number_format($totalStock); ?></span>
                <?php if ($lowSkuCount > 0): ?>
                    <span class="pm-badge" style="background:#fef3c7; color:#92400e;">低庫存 <?php echo $lowSkuCount; ?></span>
                <?php endif; ?>
                <?php if ($outSkuCount > 0): ?>
                    <span class="pm-badge" style="background:#fee2e2; color:#991b1b;">售罄 SKU <?php echo $outSkuCount; ?></span>
                <?php endif; ?>
                <span style="color:#64748b; font-size:13px;">低庫存門檻：<?php echo $lowStockThreshold; ?> 件以下。</span>
            </div>
            <div id="skuRows">
                <?php foreach ($variants as $v): ?>
                <?php
                $variantStock = (int)($v['stock_available'] ?? 0);
                $stockHint = '庫存正常';
                $stockHintStyle = 'color:#166534;';
                if ($variantStock === 0) {
                    $stockHint = '售罄，前台不可購買此數量';
                    $stockHintStyle = 'color:#991b1b;';
                } elseif ($variantStock <= $lowStockThreshold) {
                    $stockHint = '低庫存，建議補貨';
                    $stockHintStyle = 'color:#92400e;';
                }
                ?>
                <div class="pm-sku-row">
                    <input type="hidden" name="variant_id[]" value="<?= $v['variant_id'] ?>">
                    <div class="pm-grid">
                        <div class="pm-col-3">
                            <label>尺寸</label>
                            <input class="pm-input" type="text" name="size_inches[]" list="size-list" required value="<?= htmlspecialchars($v['size_inches'] ?? '') ?>">
                        </div>
                        <div class="pm-col-3">
                            <label>顏色</label>
                            <input class="pm-input sku-color-input" type="text" name="color[]" value="<?= htmlspecialchars($v['color'] ?? '') ?>">
                            <div class="sku-color-tools">
                                <input class="sku-color-picker" type="color" value="<?= preg_match('/^#[0-9A-F]{6}$/', isset($v['color_hex']) ? strtoupper(trim((string)$v['color_hex'])) : '') ? htmlspecialchars(strtoupper(trim((string)$v['color_hex']))) : '#111827' ?>" aria-label="選擇色票">
                                <input class="pm-input sku-color-hex-input" type="text" name="color_hex[]" value="<?= htmlspecialchars(isset($v['color_hex']) ? strtoupper(trim((string)$v['color_hex'])) : '') ?>" placeholder="#111827" maxlength="7" pattern="^#[0-9A-Fa-f]{6}$">
                            </div>
                        </div>
                        <div class="pm-col-3">
                            <label>原價 (NT$) <span style="color:#ef4444;">*</span></label>
                            <input class="pm-input" type="number" name="original_price[]" min="0" step="1" required value="<?= floatval($v['original_price'] ?? 0) ?>">
                        </div>
                        <div class="pm-col-3">
                            <label>特價 (NT$)</label>
                            <input class="pm-input" type="number" name="special_price[]" min="0" step="1" value="<?= $v['special_price'] === null ? '' : floatval($v['special_price']) ?>" placeholder="留空代表無特價">
                            <div class="sku-field-help">留空代表無特價；特價需大於 0 且低於原價。</div>
                        </div>
                        <div class="pm-col-3">
                            <label>VIP 價 (NT$) <span style="color:#ef4444;">*</span></label>
                            <input class="pm-input" type="number" name="member_price[]" min="0" step="1" required value="<?= floatval($v['member_price'] ?? 0) ?>">
                            <div class="sku-field-help">只有 VIP / VVIP 會員會套用此價格。</div>
                        </div>
                        <div class="pm-col-3">
                            <label>庫存數量 <span style="color:#ef4444;">*</span></label>
                            <input class="pm-input" type="number" name="stock[]" min="0" step="1" required value="<?= intval($v['stock_available']) ?>">
                            <div class="sku-stock-hint" style="margin-top:6px; font-size:12px; font-weight:700; <?php echo $stockHintStyle; ?>"><?php echo htmlspecialchars($stockHint); ?></div>
                        </div>
                    </div>
                    
                    <div style="text-align:right; margin-top:10px; display:flex; justify-content:flex-end; gap:8px;">
                        <button type="button" class="pm-btn pm-btn-sm copy-sku" style="background-color: #64748b; color: white; border: none;">複製此規格</button>
                        <button type="button" class="pm-btn pm-btn-danger pm-btn-sm remove-sku">移除此規格</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <datalist id="size-list">
                <option value="20吋"></option><option value="24吋"></option><option value="28吋"></option>
            </datalist>
        </div>

        <div class="pm-section-box">
            <h3 class="pm-section-title">現有圖片管理</h3>
            <div style="display:flex; gap:16px; flex-wrap:wrap; margin-bottom: 24px;">
                <?php foreach ($images as $img): ?>
                    <div style="border:1px solid #e2e8f0; padding:12px; border-radius:8px; width:180px; background:#fff;">
                        <img src="../<?= htmlspecialchars($img['image_url']) ?>" style="width:100%; height:140px; object-fit:cover; border-radius:6px; margin-bottom:8px;">
                        <label style="font-size:13px; display:flex; align-items:center; gap:6px; margin-bottom:8px; cursor:pointer;">
                            <input type="radio" name="existing_main_image" value="<?= $img['image_id'] ?>" <?= $img['is_main'] ? 'checked' : '' ?> style="margin:0;"> 
                            <strong>設為商品主圖</strong>
                        </label>
                        <label style="font-size:13px; display:flex; align-items:center; gap:6px; margin-bottom:8px; color:#ef4444; cursor:pointer;">
                            <input type="checkbox" name="delete_image_ids[]" value="<?= $img['image_id'] ?>" style="margin:0;"> 
                            刪除此圖片
                        </label>
                        <label style="font-size:12px; display:block; color:#475569;">
                            綁定顏色規格:
                            <select name="existing_image_color[<?= $img['image_id'] ?>]" class="pm-select existing-img-color-select" style="padding:6px; font-size:12px; margin-top:4px;" data-selected="<?= htmlspecialchars($img['color'] ?? '') ?>">
                                <option value="">無 (通用配圖)</option>
                            </select>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>

            <h3 class="pm-section-title">上傳新圖片</h3>
            <div class="pm-grid">
                <div class="pm-col-12">
                    <label>上傳額外的新圖片（選填）</label>
                    <input class="pm-file-input" type="file" name="product_images[]" id="product_images_input" accept="image/*" multiple>
                    <div id="image-preview-container" style="display:flex; gap:16px; flex-wrap:wrap; margin-top:16px;"></div>
                </div>
            </div>
        </div>

        <div class="pm-section-box">
            <h3 class="pm-section-title">近期庫存異動</h3>
            <?php if (!epTableExists($conn, 'inventory_adjustment_logs')): ?>
                <div style="padding:14px; border:1px solid #fde68a; background:#fffbeb; color:#92400e; border-radius:10px; line-height:1.7;">
                    尚未建立庫存異動紀錄表。請先執行 <code>db_setup_and_sync.php</code> 後再測試此功能。
                </div>
            <?php elseif (empty($inventoryLogs)): ?>
                <div style="padding:14px; border:1px solid #e2e8f0; background:#f8fafc; color:#64748b; border-radius:10px;">
                    目前尚無庫存異動紀錄。
                </div>
            <?php else: ?>
                <div class="pm-table-wrap">
                    <table class="pm-table">
                        <thead>
                            <tr>
                                <th>時間</th>
                                <th>SKU</th>
                                <th>規格</th>
                                <th>異動</th>
                                <th>操作</th>
                                <th>管理員</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inventoryLogs as $log): ?>
                                <?php
                                $delta = (int)$log['delta_quantity'];
                                $deltaStyle = $delta < 0 ? 'color:#991b1b;' : ($delta > 0 ? 'color:#166534;' : 'color:#64748b;');
                                $deltaText = ($delta > 0 ? '+' : '') . $delta;
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($log['created_at']); ?></td>
                                    <td><?php echo htmlspecialchars($log['sku_code'] ?: '-'); ?></td>
                                    <td><?php echo htmlspecialchars(trim(($log['size_inches'] ?: '-') . ' / ' . ($log['color'] ?: '-'))); ?></td>
                                    <td>
                                        <strong style="<?php echo $deltaStyle; ?>"><?php echo htmlspecialchars($deltaText); ?></strong>
                                        <div style="font-size:12px; color:#64748b;">
                                            <?php echo intval($log['old_stock']); ?> → <?php echo intval($log['new_stock']); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="pm-badge" style="background:#e2e8f0; color:#334155;"><?php echo htmlspecialchars($log['action_type']); ?></span>
                                        <?php if (!empty($log['note'])): ?>
                                            <div style="font-size:12px; color:#64748b; margin-top:4px;"><?php echo htmlspecialchars($log['note']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($log['admin_username'] ?: ('#' . (int)$log['admin_id'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div style="text-align: right; margin-top: 24px;">
            <button class="pm-btn pm-btn-main" type="submit" style="padding: 10px 32px; font-size: 16px;">確認儲存修改</button>
        </div>
    </form>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const dropdown = document.querySelector('.category-dropdown');
    if (!dropdown) return;
    const toggle = dropdown.querySelector('.category-toggle');
    const menu = dropdown.querySelector('.category-menu');

    toggle.addEventListener('click', function() {
        menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
    });

    document.addEventListener('click', function(event) {
        if (!dropdown.contains(event.target)) {
            menu.style.display = 'none';
        }
    });
});

// 針對現有圖片與新圖片的連動顏色下拉選單處理腳本
(function() {
    function getAvailableColors() {
        const colorInputs = document.querySelectorAll('.sku-color-input');
        const colors = new Set();
        colorInputs.forEach(input => {
            const val = input.value.trim();
            if(val !== '') colors.add(val);
        });
        return Array.from(colors);
    }
    function updateExistingImageColors() {
        const colors = getAvailableColors();
        const selects = document.querySelectorAll('.existing-img-color-select');
        selects.forEach(select => {
            const currentVal = select.value || select.dataset.selected;
            select.innerHTML = '<option value="">無 (通用配圖)</option>';
            colors.forEach(c => {
                const option = document.createElement('option');
                option.value = c;
                option.textContent = c;
                select.appendChild(option);
            });
            if(colors.includes(currentVal)) {
                select.value = currentVal;
                select.dataset.selected = currentVal;
            }
        });
    }
    
    // 延遲初始執行，確保資料載入完成
    setTimeout(updateExistingImageColors, 100);
    
    const skuRows = document.getElementById('skuRows');
    if(skuRows) {
        skuRows.addEventListener('input', function(e) {
            if(e.target.classList.contains('sku-color-input')) updateExistingImageColors();
        });
        skuRows.addEventListener('click', function(e) {
            if(e.target.classList.contains('remove-sku')) setTimeout(updateExistingImageColors, 150);
        });
    }
})();

// 庫存提示即時更新，協助管理員判斷是否售罄或需要補貨。
(function() {
    const lowStockThreshold = <?php echo (int)$lowStockThreshold; ?>;

    function ensureHint(input) {
        let hint = input.parentElement.querySelector('.sku-stock-hint');
        if (!hint) {
            hint = document.createElement('div');
            hint.className = 'sku-stock-hint';
            hint.style.marginTop = '6px';
            hint.style.fontSize = '12px';
            hint.style.fontWeight = '700';
            input.insertAdjacentElement('afterend', hint);
        }
        return hint;
    }

    function updateStockHint(input) {
        const value = Number.parseInt(input.value || '0', 10);
        const stock = Number.isNaN(value) || value < 0 ? 0 : value;
        const hint = ensureHint(input);
        if (stock === 0) {
            hint.textContent = '售罄，前台不可購買此數量';
            hint.style.color = '#991b1b';
        } else if (stock <= lowStockThreshold) {
            hint.textContent = '低庫存，建議補貨';
            hint.style.color = '#92400e';
        } else {
            hint.textContent = '庫存正常';
            hint.style.color = '#166534';
        }
    }

    document.addEventListener('input', function(event) {
        if (event.target.matches('input[name="stock[]"]')) {
            updateStockHint(event.target);
        }
    });

    document.querySelectorAll('input[name="stock[]"]').forEach(updateStockHint);
})();
</script>

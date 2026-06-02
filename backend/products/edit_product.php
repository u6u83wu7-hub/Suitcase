<?php
require_once __DIR__ . '/../auth_guard.php';
// products/edit_product.php - 編輯商品頁面（置於子資料夾內）

$product_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$lowStockThreshold = isset($lowStockThreshold) ? (int)$lowStockThreshold : 5;
if ($product_id <= 0) {
    echo "<script>alert('無效的商品 ID'); location.href='backend.php?page=products';</script>";
    exit;
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
?>

<section class="pm-card" id="tab-edit">
    <form action="backend_action.php" method="POST" enctype="multipart/form-data">
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
                <span class="pm-badge" style="background:#e2e8f0; color:#334155;">SKU <?php echo $skuCount; ?></span>
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
                        </div>
                        <div class="pm-col-3">
                            <label>原價 (NT$) <span style="color:#ef4444;">*</span></label>
                            <input class="pm-input" type="number" name="original_price[]" min="0" step="1" required value="<?= floatval($v['original_price'] ?? 0) ?>">
                        </div>
                        <div class="pm-col-3">
                            <label>特價 (NT$)</label>
                            <input class="pm-input" type="number" name="special_price[]" min="0" step="1" value="<?= $v['special_price'] === null ? '' : floatval($v['special_price']) ?>">
                        </div>
                        <div class="pm-col-3">
                            <label>會員價 (NT$) <span style="color:#ef4444;">*</span></label>
                            <input class="pm-input" type="number" name="member_price[]" min="0" step="1" required value="<?= floatval($v['member_price'] ?? 0) ?>">
                        </div>
                        <div class="pm-col-3">
                            <label>庫存數量 <span style="color:#ef4444;">*</span></label>
                            <input class="pm-input" type="number" name="stock[]" min="0" step="1" required value="<?= intval($v['stock_available']) ?>">
                            <div class="sku-stock-hint" style="margin-top:6px; font-size:12px; font-weight:700; <?php echo $stockHintStyle; ?>"><?php echo htmlspecialchars($stockHint); ?></div>
                        </div>
                    </div>
                    <div style="text-align:right; margin-top:10px;">
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

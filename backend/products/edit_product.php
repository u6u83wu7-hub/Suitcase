<?php
// products/edit_product.php - 編輯商品頁面（置於子資料夾內）

$product_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
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

// 3. 取得所有 SKU 規格
$variants = [];
$vStmt = $conn->prepare("SELECT * FROM product_variants WHERE product_id = ?");
$vStmt->bind_param("i", $product_id);
$vStmt->execute();
$vRes = $vStmt->get_result();
while ($v = $vRes->fetch_assoc()) $variants[] = $v;
if (empty($variants)) { 
    $variants[] = ['variant_id' => '', 'size_inches' => '', 'color' => '', 'price' => 0, 'stock_available' => 0];
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
                    <label>分類</label>
                    <select class="pm-select" name="category_id">
                        <option value="">不分類</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['category_id'] ?>" <?= $cat['category_id'] == $product['primary_category_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['name']) ?>
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
            <div id="skuRows">
                <?php foreach ($variants as $v): ?>
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
                            <label>價格 (NT$) <span style="color:#ef4444;">*</span></label>
                            <input class="pm-input" type="number" name="price[]" min="0" step="1" required value="<?= floatval($v['price']) ?>">
                        </div>
                        <div class="pm-col-3">
                            <label>庫存數量 <span style="color:#ef4444;">*</span></label>
                            <input class="pm-input" type="number" name="stock[]" min="0" step="1" required value="<?= intval($v['stock_available']) ?>">
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

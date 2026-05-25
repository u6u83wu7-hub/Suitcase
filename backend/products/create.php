<?php
require_once __DIR__ . '/../auth_guard.php';
// products/create.php - 新增商品表單（純展示層）
// 所有數據已由 products.php 準備好：$categories
?>

<section class="pm-card" id="tab-create" style="display:none;">
    <form action="backend_action.php" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="add_product">

        <div class="pm-section-box">
            <h3 class="pm-section-title">商品基本資訊</h3>
            <div class="pm-grid">
                <div class="pm-col-3">
                    <label>商品名稱 <span style="color:#ef4444;">*</span></label>
                    <input class="pm-input" type="text" name="name" required placeholder="請輸入商品名稱">
                </div>
                <div class="pm-col-3">
                    <label>分類</label>
                    <select class="pm-select" name="category_id" id="category_select" onchange="toggleNewCategory(this)">
                        <option value="">不分類</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo intval($cat['category_id']); ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endforeach; ?>
                        <option value="new" style="color:#2563eb; font-weight:bold;">+ 新增分類</option>
                    </select>
                    <input type="text" class="pm-input" name="new_category_name" id="new_category_name" style="display:none; margin-top:8px;" placeholder="輸入新分類名稱">
                </div>
                <div class="pm-col-3" style="display:flex; align-items:center; padding-bottom:8px;">
                    <label style="margin:0; cursor:pointer; display:flex; align-items:center; gap:6px;">
                        <input type="checkbox" name="is_featured" value="1" style="width:16px; height:16px;">
                        設為首頁精選
                    </label>
                </div>
                <div class="pm-col-12">
                    <label>商品描述</label>
                    <textarea class="pm-textarea" name="description" placeholder="可填寫材質、特色、使用情境..."></textarea>
                </div>
                <div class="pm-col-12">
                    <label>保固與附加資訊</label>
                    <textarea class="pm-textarea" name="warranty_info" placeholder="例如：三年保固，破箱保修..." style="min-height: 60px;"></textarea>
                </div>
            </div>
        </div>

        <div class="pm-section-box">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                <h3 class="pm-section-title" style="border:none; padding:0; margin:0;">SKU 規格與價格配置</h3>
                <button type="button" class="pm-btn pm-btn-sub pm-btn-sm" id="addSkuBtn">+ 新增一組規格</button>
            </div>
            
            <div id="skuRows">
                <div class="pm-sku-row">
                    <div class="pm-grid">
                        <div class="pm-col-3">
                            <label>尺寸</label>
                            <input class="pm-input" type="text" name="size_inches[]" list="size-list" required placeholder="請選擇或輸入尺寸">
                            <datalist id="size-list">
                                <option value="20吋"></option>
                                <option value="24吋"></option>
                                <option value="28吋"></option>
                            </datalist>
                        </div>
                        <div class="pm-col-3">
                            <label>顏色</label>
                            <input class="pm-input sku-color-input" type="text" name="color[]" placeholder="例如：消光黑">
                        </div>
                        <div class="pm-col-3">
                            <label>價格 (NT$) <span style="color:#ef4444;">*</span></label>
                            <input class="pm-input" type="number" name="price[]" min="0" step="1" required placeholder="0">
                        </div>
                        <div class="pm-col-3">
                            <label>庫存數量 <span style="color:#ef4444;">*</span></label>
                            <input class="pm-input" type="number" name="stock[]" min="0" step="1" required placeholder="0">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="pm-section-box">
            <h3 class="pm-section-title">商品圖片</h3>
            <div class="pm-grid">
                <div class="pm-col-12">
                    <label>上傳圖片（支援多選，可在下方指定主圖與顏色配圖） <span style="color:#ef4444;">*</span></label>
                    <input class="pm-file-input" type="file" name="product_images[]" id="product_images_input" accept="image/*" multiple required>
                    <div id="image-preview-container" style="display:flex; gap:16px; flex-wrap:wrap; margin-top:16px;"></div>
                </div>
            </div>
        </div>

        <div style="text-align: right; margin-top: 24px;">
            <button class="pm-btn pm-btn-main" type="submit" style="padding: 10px 32px; font-size: 16px;">確認建立商品</button>
        </div>
    </form>
</section>

<script>
// 獨立的分類選單切換邏輯
function toggleNewCategory(select) {
    const newCatInput = document.getElementById('new_category_name');
    if(select.value === 'new') {
        newCatInput.style.display = 'block';
        newCatInput.required = true;
    } else {
        newCatInput.style.display = 'none';
        newCatInput.required = false;
        newCatInput.value = '';
    }
}
</script>
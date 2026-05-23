// products.js - 商品管理模組的所有交互邏輯

document.addEventListener('DOMContentLoaded', function() {
    // --- 1. Tab 切換邏輯 ---
    const tabs = document.querySelectorAll('.pm-tab');
    const tabList = document.getElementById('tab-list');
    const tabCreate = document.getElementById('tab-create');

    tabs.forEach(function (btn) {
        btn.addEventListener('click', function () {
            tabs.forEach(function (item) { item.classList.remove('active'); });
            btn.classList.add('active');
            if (btn.dataset.tab === 'create') {
                tabList.style.display = 'none';
                tabCreate.style.display = 'block';
            } else {
                tabCreate.style.display = 'none';
                tabList.style.display = 'block';
            }
        });
    });

    // --- 2. 全選邏輯 ---
    const checkAll = document.getElementById('checkAll');
    const rowChecks = document.querySelectorAll('.rowCheck');
    if (checkAll) {
        checkAll.addEventListener('change', function () {
            rowChecks.forEach(function (chk) { chk.checked = checkAll.checked; });
        });
    }

    // --- 3. 動態新增 SKU 與連動圖片顏色邏輯 ---
    const addSkuBtn = document.getElementById('addSkuBtn');
    const skuRows = document.getElementById('skuRows');
    
    if (addSkuBtn && skuRows) {
        addSkuBtn.addEventListener('click', function () {
            const row = document.createElement('div');
            row.className = 'pm-sku-row';
            row.innerHTML =
                '<div class="pm-grid">' +
                    '<div class="pm-col-3"><label>尺寸</label><input class="pm-input" type="text" name="size_inches[]" list="size-list" required placeholder="請選擇或輸入尺寸"></div>' +
                    '<div class="pm-col-3"><label>顏色</label><input class="pm-input sku-color-input" type="text" name="color[]" placeholder="例如：消光黑"></div>' +
                    '<div class="pm-col-3"><label>價格 (NT$)</label><input class="pm-input" type="number" name="price[]" min="0" step="1" required placeholder="0"></div>' +
                    '<div class="pm-col-3"><label>庫存數量</label><input class="pm-input" type="number" name="stock[]" min="0" step="1" required placeholder="0"></div>' +
                '</div>' +
                '<div style="text-align:right; margin-top:10px;">' +
                    '<button type="button" class="pm-btn pm-btn-danger pm-btn-sm remove-sku">移除此規格</button>' +
                '</div>';
            skuRows.appendChild(row);
        });

        skuRows.addEventListener('click', function (event) {
            if (!event.target.classList.contains('remove-sku')) {
                return;
            }
            if (skuRows.querySelectorAll('.pm-sku-row').length <= 1) {
                alert('至少必須保留一組 SKU 規格');
                return;
            }
            event.target.closest('.pm-sku-row').remove();
            updateImageColorSelects(); // 移除規格時更新顏色選單
        });

        // 監聽顏色輸入，即時更新圖片顏色選單
        skuRows.addEventListener('input', function(event) {
            if(event.target.classList.contains('sku-color-input')) {
                updateImageColorSelects();
            }
        });
    }

    // --- 4. 圖片預覽與顏色選單生成 ---
    const fileInput = document.getElementById('product_images_input');
    const previewContainer = document.getElementById('image-preview-container');

    // 獲取目前 SKU 表單中所有不重複的顏色
    function getAvailableColors() {
        const colorInputs = document.querySelectorAll('.sku-color-input');
        const colors = new Set();
        colorInputs.forEach(input => {
            const val = input.value.trim();
            if(val !== '') colors.add(val);
        });
        return Array.from(colors);
    }

    // 更新圖片下方的顏色下拉選單
    function updateImageColorSelects() {
        if(!previewContainer) return;
        const colors = getAvailableColors();
        const selects = previewContainer.querySelectorAll('.img-color-select');
        
        selects.forEach(select => {
            const currentVal = select.value;
            select.innerHTML = '<option value="">無 (通用配圖)</option>';
            colors.forEach(c => {
                const option = document.createElement('option');
                option.value = c;
                option.textContent = c;
                select.appendChild(option);
            });
            // 嘗試保留原先選取的顏色
            if(colors.includes(currentVal)) {
                select.value = currentVal;
            }
        });
    }

    // 處理圖片選擇預覽
    if(fileInput && previewContainer) {
        fileInput.addEventListener('change', function(e) {
            previewContainer.innerHTML = ''; // 清空先前的預覽
            const files = e.target.files;
            const colors = getAvailableColors();
            
            if (files.length > 0) {
                console.log(`成功載入 ${files.length} 張圖片`); // 測試用，可在瀏覽器 F12 console 看到
            }

            Array.from(files).forEach((file, index) => {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const div = document.createElement('div');
                    div.style.border = '1px solid #e2e8f0';
                    div.style.padding = '12px';
                    div.style.borderRadius = '8px';
                    div.style.width = '180px';
                    div.style.background = '#fff';
                    div.style.boxShadow = '0 1px 3px rgba(0,0,0,0.05)';
                    
                    let colorOptions = '<option value="">無 (通用配圖)</option>';
                    colors.forEach(c => { colorOptions += `<option value="${c}">${c}</option>`; });

                    div.innerHTML = `
                        <img src="${e.target.result}" style="width:100%; height:140px; object-fit:cover; border-radius:6px; margin-bottom:12px; border:1px solid #e2e8f0;">
                        
                        <label style="font-size:13px; display:flex; align-items:center; gap:6px; margin-bottom:8px; cursor:pointer;">
                            <input type="radio" name="main_image_idx" value="${index}" ${index === 0 ? 'checked' : ''} style="margin:0;"> 
                            <strong>設為商品主圖</strong>
                        </label>
                        
                        <label style="font-size:12px; display:block; color:#475569;">
                            綁定顏色規格:
                            <select name="image_color_idx[${index}]" class="pm-select img-color-select" style="padding:6px; font-size:12px; margin-top:4px;">
                                ${colorOptions}
                            </select>
                        </label>
                    `;
                    previewContainer.appendChild(div);
                };
                reader.readAsDataURL(file);
            });
        });
    }
});
// products.js - 商品管理模組的所有交互邏輯

(function () {
    // Tab 切換邏輯
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

    // 全選邏輯
    const checkAll = document.getElementById('checkAll');
    const rowChecks = document.querySelectorAll('.rowCheck');
    if (checkAll) {
        checkAll.addEventListener('change', function () {
            rowChecks.forEach(function (chk) { chk.checked = checkAll.checked; });
        });
    }

    // 動態新增 SKU 邏輯
    const addSkuBtn = document.getElementById('addSkuBtn');
    const skuRows = document.getElementById('skuRows');
    if (addSkuBtn && skuRows) {
        addSkuBtn.addEventListener('click', function () {
            const row = document.createElement('div');
            row.className = 'pm-sku-row';
            row.innerHTML =
                '<div class="pm-grid">' +
                    '<div class="pm-col-3"><label>尺寸</label><select class="pm-select" name="size[]" required><option value="">請選擇尺寸</option><option value="20吋">20吋</option><option value="24吋">24吋</option><option value="28吋">28吋</option></select></div>' +
                    '<div class="pm-col-3"><label>顏色</label><input class="pm-input" type="text" name="color[]" placeholder="例如：消光黑"></div>' +
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
        });
    }
})();

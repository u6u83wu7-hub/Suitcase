<?php
require_once __DIR__ . '/auth_guard.php';
//categories.php
//版本1
// 1. 取得分類列表與數量
$categories = [];
$result = $conn->query(
    "SELECT c.category_id, c.name, COUNT(pcl.product_id) AS product_count
     FROM categories c
     LEFT JOIN product_category_links pcl ON pcl.category_id = c.category_id
     GROUP BY c.category_id
     ORDER BY c.name ASC"
);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }
}

// 2. 取得分類下的商品 (加入圖片撈取)
$categoryProducts = [];
$productResult = $conn->query(
    "SELECT pcl.category_id, p.product_id, p.name, p.status, 
            (SELECT image_url FROM product_images WHERE product_id = p.product_id ORDER BY is_main DESC, image_id ASC LIMIT 1) AS image_url
     FROM product_category_links pcl
     INNER JOIN products p ON p.product_id = pcl.product_id
     ORDER BY pcl.category_id ASC, p.name ASC"
);
if ($productResult) {
    while ($row = $productResult->fetch_assoc()) {
        $catId = (int)$row['category_id'];
        if (!isset($categoryProducts[$catId])) {
            $categoryProducts[$catId] = [];
        }
        $categoryProducts[$catId][] = [
            'product_id' => (int)$row['product_id'],
            'name' => $row['name'],
            'status' => $row['status'],
            'image_url' => $row['image_url'] ? '../' . $row['image_url'] : '' // 組合圖片路徑
        ];
    }
}

// 3. 取得「所有商品」清單 (準備給下拉選單新增用)
$allProducts = [];
$allRes = $conn->query("SELECT product_id, name FROM products ORDER BY name ASC");
if ($allRes) {
    while ($r = $allRes->fetch_assoc()) {
        $allProducts[] = $r;
    }
}
?>

<div class="pm-wrap">
    <div class="pm-head">
        <div>
            <h1 class="pm-title">🏷️ 分類管理</h1>
            <p class="pm-sub">新增、編輯分類，並可快速管理分類內的商品關聯。</p>
        </div>
    </div>

    <style>
    .category-grid { display: grid; grid-template-columns: 320px 1fr; gap: 24px; margin-top: 20px; }
    .category-card { background: #fff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.03); padding: 24px; border: 1px solid #f1f5f9; }
    .category-card h2 { margin: 0 0 16px; font-size: 16px; color:#1e293b; border-bottom: 2px solid #f1f5f9; padding-bottom: 12px;}
    .category-modal { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); display: none; align-items: center; justify-content: center; z-index: 999; backdrop-filter: blur(2px); }
    .category-modal .modal-panel { background: #fff; border-radius: 12px; padding: 24px; width: min(700px, 95vw); max-height: 85vh; display: flex; flex-direction: column; box-shadow: 0 20px 40px rgba(0,0,0,0.1); }
    .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; }
    .modal-title { font-size: 18px; font-weight: 700; color: #0f172a; }
    .modal-body { overflow-y: auto; flex: 1; padding-right: 8px;}
    .modal-list { margin: 0; padding: 0; list-style: none; }
    .modal-list li { display: flex; justify-content: space-between; align-items: center; padding: 12px; border-bottom: 1px solid #f1f5f9; transition: background 0.2s;}
    .modal-list li:hover { background: #f8fafc; }
    .prod-info { display: flex; align-items: center; gap: 12px; }
    .prod-thumb { width: 40px; height: 40px; border-radius: 6px; object-fit: cover; border: 1px solid #e2e8f0; background: #f8fafc; }
    .status-badge { display: inline-block; font-size: 12px; padding: 3px 8px; border-radius: 6px; margin-left: 8px; font-weight:500;}
    .status-on { background: #dcfce7; color: #166534; }
    .status-off { background: #fee2e2; color: #991b1b; }
    .add-to-cat-box { background: #f8fafc; padding: 16px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #e2e8f0; display:flex; gap:10px; align-items:flex-end;}
    </style>

    <div class="category-grid">
        <div>
            <div class="category-card" style="margin-bottom: 20px;">
                <h2>新增分類</h2>
                <form action="backend_action.php" method="POST">
                    <input type="hidden" name="action" value="add_category">
                    <label style="font-size:13px; font-weight:bold; color:#475569; display:block; margin-bottom:6px;">分類名稱</label>
                    <input type="text" class="pm-input" name="name" required placeholder="例如：20吋登機箱" style="margin-bottom:12px;">
                    <button type="submit" class="pm-btn pm-btn-main" style="width:100%;">新增分類</button>
                </form>
            </div>

            <div class="category-card">
                <h2>編輯分類</h2>
                <form action="backend_action.php" method="POST" id="editCategoryForm">
                    <input type="hidden" name="action" value="update_category">
                    <input type="hidden" name="category_id" id="editCategoryId" value="">
                    <label style="font-size:13px; font-weight:bold; color:#475569; display:block; margin-bottom:6px;">分類名稱</label>
                    <input type="text" class="pm-input" name="name" id="editCategoryName" placeholder="請先點擊右側的編輯按鈕" disabled style="margin-bottom:12px;">
                    <div style="display:flex; gap:8px;">
                        <button type="submit" class="pm-btn pm-btn-main" id="editCategorySave" disabled style="flex:1;">儲存</button>
                        <button type="button" class="pm-btn pm-btn-sub" id="editCategoryCancel" disabled>取消</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="category-card">
            <h2>現有分類列表</h2>
            <div class="pm-table-wrap">
                <table class="pm-table" style="width:100%;">
                    <thead>
                        <tr>
                            <th style="width:60px;">ID</th>
                            <th>分類名稱</th>
                            <th style="width:100px; text-align:center;">商品數量</th>
                            <th style="width:240px;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($categories)): ?>
                            <?php foreach ($categories as $cat): ?>
                                <tr>
                                    <td>#<?= intval($cat['category_id']); ?></td>
                                    <td style="font-weight:600; color:#334155;"><?= htmlspecialchars($cat['name']); ?></td>
                                    <td style="text-align:center;">
                                        <span style="background:#e2e8f0; padding:2px 8px; border-radius:12px; font-size:12px; color:#475569;">
                                            <?= intval($cat['product_count']); ?> 件
                                        </span>
                                    </td>
                                    <td>
                                        <div style="display:flex; gap:6px;">
                                            <button type="button" class="pm-btn pm-btn-sub pm-btn-sm js-view-products" 
                                                data-id="<?= $cat['category_id']; ?>" 
                                                data-name="<?= htmlspecialchars($cat['name']); ?>">
                                                查看商品
                                            </button>
                                            <button type="button" class="pm-btn pm-btn-edit pm-btn-sm js-edit-category" 
                                                data-id="<?= $cat['category_id']; ?>" 
                                                data-name="<?= htmlspecialchars($cat['name']); ?>">
                                                編輯
                                            </button>
                                            <form action="backend_action.php" method="POST" onsubmit="return confirm('確定要刪除此分類嗎？');" style="margin:0;">
                                                <input type="hidden" name="action" value="delete_category">
                                                <input type="hidden" name="category_id" value="<?= $cat['category_id']; ?>">
                                                <button type="submit" class="pm-btn pm-btn-danger pm-btn-sm">刪除</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" style="text-align:center; padding:30px; color:#94a3b8;">目前還沒有任何分類，請從左側新增。</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="category-modal" id="categoryModal">
    <div class="modal-panel">
        <div class="modal-header">
            <div class="modal-title" id="modalTitle">分類商品管理</div>
            <button type="button" class="pm-btn pm-btn-sub pm-btn-sm" id="modalClose">✕ 關閉</button>
        </div>
        
        <form action="backend_action.php" method="POST" class="add-to-cat-box">
            <input type="hidden" name="action" value="add_product_to_category">
            <input type="hidden" name="category_id" id="modalAddCategoryId" value="">
            <div style="flex:1;">
                <label style="font-size:13px; font-weight:bold; color:#475569; display:block; margin-bottom:4px;">➕ 將商品加入此分類</label>
                <select name="product_id" class="pm-select" required>
                    <option value="">-- 請選擇要加入的商品 --</option>
                    <?php foreach($allProducts as $p): ?>
                        <option value="<?= $p['product_id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="pm-btn pm-btn-main">加入分類</button>
        </form>

        <div class="modal-body">
            <ul class="modal-list" id="modalList">
                </ul>
        </div>
    </div>
</div>

<script>
// PHP 資料轉傳給 JS
const categoryProducts = <?= json_encode($categoryProducts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

document.addEventListener('DOMContentLoaded', function() {
    // 編輯分類邏輯
    const editId = document.getElementById('editCategoryId');
    const editName = document.getElementById('editCategoryName');
    const editSave = document.getElementById('editCategorySave');
    const editCancel = document.getElementById('editCategoryCancel');

    document.querySelectorAll('.js-edit-category').forEach(btn => {
        btn.addEventListener('click', () => {
            editId.value = btn.dataset.id;
            editName.value = btn.dataset.name;
            editName.disabled = false;
            editSave.disabled = false;
            editCancel.disabled = false;
            editName.focus();
        });
    });

    editCancel.addEventListener('click', () => {
        editId.value = '';
        editName.value = '';
        editName.disabled = true;
        editSave.disabled = true;
        editCancel.disabled = true;
    });

    // 彈出視窗邏輯
    const modal = document.getElementById('categoryModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalList = document.getElementById('modalList');
    const modalClose = document.getElementById('modalClose');
    const modalAddCatId = document.getElementById('modalAddCategoryId');

    function openModal(categoryId, categoryName) {
        modalTitle.innerHTML = `🏷️ 分類內容：<span style="color:#2563eb;">${categoryName}</span>`;
        modalAddCatId.value = categoryId; // 將分類ID塞入新增表單
        modalList.innerHTML = '';
        
        const items = categoryProducts[categoryId] || [];
        
        if (items.length === 0) {
            modalList.innerHTML = '<li style="justify-content:center; color:#94a3b8; padding:30px;">此分類尚無商品</li>';
        } else {
            items.forEach(item => {
                const li = document.createElement('li');
                const badgeClass = item.status === 'ON SHELF' ? 'status-badge status-on' : 'status-badge status-off';
                const statusText = item.status === 'ON SHELF' ? '上架中' : '已下架';
                const imgSrc = item.image_url ? `<img src="${item.image_url}" class="prod-thumb">` : `<div class="prod-thumb" style="display:flex;align-items:center;justify-content:center;color:#cbd5e1;font-size:10px;">無圖</div>`;
                
                li.innerHTML = `
                    <div class="prod-info">
                        ${imgSrc}
                        <div>
                            <div style="font-weight:600; color:#1e293b;">${item.name} <span class="${badgeClass}">${statusText}</span></div>
                            <div style="font-size:12px; color:#94a3b8;">商品 ID: #${item.product_id}</div>
                        </div>
                    </div>
                    <form action="backend_action.php" method="POST" onsubmit="return confirm('確定將此商品移出分類嗎？');" style="margin:0;">
                        <input type="hidden" name="action" value="remove_product_from_category">
                        <input type="hidden" name="category_id" value="${categoryId}">
                        <input type="hidden" name="product_id" value="${item.product_id}">
                        <button type="submit" class="pm-btn pm-btn-danger pm-btn-sm" style="background:#fff; color:#ef4444; border:1px solid #fca5a5;">移出此分類</button>
                    </form>
                `;
                modalList.appendChild(li);
            });
        }
        modal.style.display = 'flex';
    }

    document.querySelectorAll('.js-view-products').forEach(btn => {
        btn.addEventListener('click', () => {
            openModal(parseInt(btn.dataset.id, 10), btn.dataset.name);
        });
    });

    modalClose.addEventListener('click', () => modal.style.display = 'none');
    modal.addEventListener('click', (e) => { if (e.target === modal) modal.style.display = 'none'; });
});
</script>
<?php
require_once __DIR__ . '/../auth_guard.php';
// products/list.php - 商品列表頁面（純展示層）
// 所有數據已由 products.php 準備好：
// - $conn, $productResult, $categories, $totalProducts, $totalPages
// - $currentPage, $keyword, $categoryFilter, $statusFilter, $featuredFilter, $pmTableColumns
// - $buildFilterQuery() 函數
?>

<!-- 商品列表 Tab -->
<section class="pm-card" id="tab-list">
    <?php if ($productQueryError !== ''): ?>
        <div style="background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; padding:12px; border-radius:8px; margin-bottom:16px;">
            商品查詢失敗：<?php echo htmlspecialchars($productQueryError); ?>
        </div>
    <?php endif; ?>

    <!-- 篩選器 -->
    <form method="GET" action="backend.php" class="pm-grid" style="margin-bottom: 16px; max-width: 850px;">
        <input type="hidden" name="page" value="products">
        <div class="pm-col-3">
            <label>關鍵字搜尋</label>
            <input class="pm-input" name="keyword" value="<?php echo htmlspecialchars($keyword); ?>" placeholder="搜尋商品名稱...">
        </div>
        <div class="pm-col-3">
            <label>商品分類</label>
            <select class="pm-select" name="category_filter">
                <option value="">全部分類</option>
                <option value="none" <?php echo $categoryFilter === 'none' ? 'selected' : ''; ?>>不分類</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo intval($cat['category_id']); ?>" <?php echo $categoryFilter === (string)$cat['category_id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="pm-col-2">
            <label>上架狀態</label>
            <select class="pm-select" name="status_filter">
                <option value="">全部</option>
                <option value="ON SHELF" <?php echo $statusFilter === 'ON SHELF' ? 'selected' : ''; ?>>上架中</option>
                <option value="OFF SHELF" <?php echo $statusFilter === 'OFF SHELF' ? 'selected' : ''; ?>>已下架</option>
            </select>
        </div>
        <div class="pm-col-2">
            <label>首頁精選</label>
            <select class="pm-select" name="featured_filter">
                <option value="">全部</option>
                <option value="1" <?php echo $featuredFilter === '1' ? 'selected' : ''; ?>>精選商品</option>
                <option value="0" <?php echo $featuredFilter === '0' ? 'selected' : ''; ?>>一般商品</option>
            </select>
        </div>
        <div class="pm-col-2" style="display:flex; gap:8px;">
            <button class="pm-btn pm-btn-main" type="submit">篩選</button>
            <a class="pm-btn pm-btn-sub" href="backend.php?page=products">重置</a>
        </div>
    </form>

    <!-- 列表與批次操作 -->
    <form method="POST" action="backend_action.php" id="bulkForm">
        <input type="hidden" name="action" value="bulk_update_products">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; margin-bottom:12px;">
            <div style="display:flex; gap:8px; align-items:center;">
                <select class="pm-select" name="bulk_action" style="max-width:180px; padding:6px 10px;">
                    <option value="">選擇批次操作...</option>
                    <option value="set_on">批次上架</option>
                    <option value="set_off">批次下架</option>
                    <option value="set_featured">批次設為精選</option>
                    <option value="unset_featured">批次取消精選</option>
                </select>
                <button type="submit" class="pm-btn pm-btn-sub pm-btn-sm">套用</button>
            </div>
            <span style="font-size: 13px; color: #64748b;">共找到 <?php echo $totalProducts; ?> 項商品</span>
        </div>

        <div class="pm-table-wrap">
            <table class="pm-table">
                <thead>
                    <tr>
                        <th style="width: 40px;"><input type="checkbox" id="checkAll"></th>
                        <th style="width: 60px;">圖片</th>
                        <th>商品名稱</th>
                        <th>分類</th>
                        <th>SKU</th>
                        <th>價格區間</th>
                        <th>總庫存</th>
                        <th>狀態</th>
                        <th>精選</th>
                        <th style="min-width: 180px;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($productResult && $productResult->num_rows > 0): ?>
                        <?php while ($row = $productResult->fetch_assoc()): ?>
                            <tr>
                                <td><input type="checkbox" name="product_ids[]" value="<?php echo intval($row['product_id']); ?>" class="rowCheck"></td>
                                <td>
                                    <?php if (!empty($row['main_image'])): ?>
                                        <img class="pm-thumb" src="../<?php echo htmlspecialchars($row['main_image']); ?>" alt="thumb">
                                    <?php else: ?>
                                        <span class="pm-thumb"></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <!-- 點擊商品名稱進入編輯 -->
                                    <a href="backend.php?page=edit_product&id=<?php echo intval($row['product_id']); ?>" class="pm-link">
                                        <?php echo htmlspecialchars($row['name']); ?>
                                    </a>
                                </td>
                                <td>
                                    <?php
                                    if ($row['primary_category_id'] === null) {
                                        echo '<span style="color:#94a3b8;">不分類</span>';
                                    } else {
                                        $matched = '未命名分類';
                                        foreach ($categories as $cat) {
                                            if ((int)$cat['category_id'] === (int)$row['primary_category_id']) {
                                                $matched = $cat['name'];
                                                break;
                                            }
                                        }
                                        echo htmlspecialchars($matched);
                                    }
                                    ?>
                                </td>
                                <td><?php echo intval($row['sku_count']); ?></td>
                                <td>
                                    <?php if ($row['min_price'] === null): ?>
                                        -
                                    <?php elseif ((float)$row['min_price'] === (float)$row['max_price']): ?>
                                        $<?php echo number_format((float)$row['min_price']); ?>
                                    <?php else: ?>
                                        $<?php echo number_format((float)$row['min_price']); ?> ~ <?php echo number_format((float)$row['max_price']); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="<?php echo (int)$row['total_stock'] === 0 ? 'color:#ef4444; font-weight:bold;' : ''; ?>">
                                        <?php echo number_format((int)$row['total_stock']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="pm-badge <?php echo $row['status'] === 'ON SHELF' ? 'pm-on' : 'pm-off'; ?>">
                                        <?php echo $row['status'] === 'ON SHELF' ? '上架中' : '已下架'; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ((int)$row['is_featured'] === 1): ?>
                                        <span class="pm-badge pm-featured">精選</span>
                                    <?php else: ?>
                                        <span style="color:#cbd5e1;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="pm-actions">
                                        <!-- 編輯按鈕 -->
                                        <a href="backend.php?page=edit_product&id=<?php echo intval($row['product_id']); ?>" class="pm-btn pm-btn-edit pm-btn-sm">編輯</a>
                                        
                                        <!-- 狀態切換 -->
                                        <form method="POST" action="backend_action.php" style="display:inline;">
                                            <input type="hidden" name="action" value="toggle_product_status">
                                            <input type="hidden" name="product_id" value="<?php echo intval($row['product_id']); ?>">
                                            <input type="hidden" name="new_status" value="<?php echo $row['status'] === 'ON SHELF' ? 'OFF SHELF' : 'ON SHELF'; ?>">
                                            <button class="pm-btn pm-btn-sub pm-btn-sm" type="submit"><?php echo $row['status'] === 'ON SHELF' ? '設為下架' : '設為上架'; ?></button>
                                        </form>
                                        
                                        <!-- 刪除 -->
                                        <form method="POST" action="backend_action.php" style="display:inline;" onsubmit="return confirm('確定要刪除此商品嗎？此動作無法復原。');">
                                            <input type="hidden" name="action" value="delete_product">
                                            <input type="hidden" name="product_id" value="<?php echo intval($row['product_id']); ?>">
                                            <button class="pm-btn pm-btn-danger pm-btn-sm" type="submit">刪除</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="10" style="text-align:center; padding: 40px; color:#94a3b8;">目前沒有符合條件的商品</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>

    <?php if ($totalPages > 1): ?>
        <div class="pm-pagination">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <?php if ($i === $currentPage): ?>
                    <span class="pm-page-link pm-page-current"><?php echo $i; ?></span>
                <?php else: ?>
                    <a class="pm-page-link" href="<?php echo htmlspecialchars(buildFilterQuery(['p' => $i])); ?>"><?php echo $i; ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</section>

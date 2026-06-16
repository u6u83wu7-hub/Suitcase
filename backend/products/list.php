<?php
require_once __DIR__ . '/../auth_guard.php';
// products/list.php - 商品列表頁面（純展示層）
// 所有數據已由 products.php 準備好：
// - $conn, $productResult, $categories, $suppliers, $totalProducts, $totalPages
// - $currentPage, $keyword, $categoryFilter, $supplierFilter, $statusFilter, $featuredFilter, $pmTableColumns
// - $buildFilterQuery() 函數

$isVendorAccount = isset($admin_role_id) && intval($admin_role_id) === 3;
$vendorSupplierId = isset($vendorSupplierId) ? intval($vendorSupplierId) : 0;
$vendorSupplierName = '';
if ($isVendorAccount && $vendorSupplierId > 0) {
    foreach ($suppliers as $supplier) {
        if (intval($supplier['supplier_id']) === $vendorSupplierId) {
            $vendorSupplierName = $supplier['name'];
            break;
        }
    }
}
?>

<section class="pm-card" id="tab-list">
    <?php if ($productQueryError !== ''): ?>
        <div style="background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; padding:12px; border-radius:8px; margin-bottom:16px;">
            商品查詢失敗：<?php echo htmlspecialchars($productQueryError); ?>
        </div>
    <?php endif; ?>

    <form method="GET" action="backend.php" class="pm-grid" style="margin-bottom:16px; max-width:1000px;">
        <input type="hidden" name="page" value="products">
        <div class="pm-col-2">
            <label>關鍵字搜尋</label>
            <input class="pm-input" name="keyword" value="<?php echo htmlspecialchars($keyword); ?>" placeholder="搜尋商品名稱...">
        </div>
        <div class="pm-col-2">
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
            <label>廠商</label>
            <?php if ($isVendorAccount && $vendorSupplierId > 0): ?>
                <input type="hidden" name="supplier_filter" value="<?php echo $vendorSupplierId; ?>">
                <select class="pm-select" disabled>
                    <option value="<?php echo $vendorSupplierId; ?>" selected>
                        <?php echo htmlspecialchars($vendorSupplierName !== '' ? $vendorSupplierName : '我的廠商'); ?>
                    </option>
                </select>
            <?php else: ?>
                <select class="pm-select" name="supplier_filter">
                    <option value="">全部廠商</option>
                    <option value="none" <?php echo $supplierFilter === 'none' ? 'selected' : ''; ?>>未指定廠商</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?php echo intval($supplier['supplier_id']); ?>" <?php echo $supplierFilter === (string)$supplier['supplier_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($supplier['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
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
        <div class="pm-col-2">
            <label>庫存狀態</label>
            <select class="pm-select" name="stock_filter">
                <option value="">全部</option>
                <option value="low" <?php echo $stockFilter === 'low' ? 'selected' : ''; ?>>低庫存 SKU</option>
                <option value="out" <?php echo $stockFilter === 'out' ? 'selected' : ''; ?>>售罄商品</option>
            </select>
        </div>
        <div class="pm-col-2" style="display:flex; gap:8px;">
            <button class="pm-btn pm-btn-main" type="submit">篩選</button>
            <a class="pm-btn pm-btn-sub" href="backend.php?page=products">重置</a>
        </div>
    </form>

    <form method="POST" action="backend_action.php" id="bulkForm">
        <?php if (function_exists('apCsrfField')) echo apCsrfField(); ?>
        <input type="hidden" name="action" value="bulk_update_products">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; margin-bottom:12px;">
            <?php if (!$isVendorAccount): ?>
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
            <?php else: ?>
                <div></div>
            <?php endif; ?>
            <span style="font-size:13px; color:#64748b;">共找到 <?php echo $totalProducts; ?> 項商品</span>
        </div>

        <div class="pm-table-wrap">
            <table class="pm-table pm-product-table <?php echo $isVendorAccount ? 'pm-product-table-vendor' : ''; ?>">
                <thead>
                    <tr>
                        <?php if (!$isVendorAccount): ?>
                            <th style="width:40px;"><input type="checkbox" id="checkAll"></th>
                        <?php endif; ?>
                        <th style="width:60px;">圖片</th>
                        <th>商品名稱</th>
                        <th>分類</th>
                        <th>廠商</th>
                        <th title="每一組尺寸 / 顏色 / 價格 / 庫存組合是一個 SKU。">規格數</th>
                        <th>價格區間</th>
                        <th>總庫存</th>
                        <th>狀態</th>
                        <th>精選</th>
                        <th style="min-width:220px;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($productResult && $productResult->num_rows > 0): ?>
                        <?php while ($row = $productResult->fetch_assoc()): ?>
                            <?php
                            $totalStock = (int)$row['total_stock'];
                            $lowSkuCount = (int)$row['low_sku_count'];
                            $outSkuCount = (int)$row['out_sku_count'];
                            $skuCount = (int)$row['sku_count'];
                            $isLowStockAlert = ($totalStock > 0 && $totalStock <= $lowStockThreshold);
                            $lowStockButtonStyle = $isLowStockAlert
                                ? 'background:#dc2626; color:#fff; border-color:#dc2626;'
                                : 'background:#fff; color:#334155; border-color:#cbd5e1;';
                            ?>
                            <tr>
                                <?php if (!$isVendorAccount): ?>
                                    <td><input type="checkbox" name="product_ids[]" value="<?php echo intval($row['product_id']); ?>" class="rowCheck"></td>
                                <?php endif; ?>
                                <td class="pm-text-wrap">
                                    <?php if (!empty($row['main_image'])): ?>
                                        <img class="pm-thumb" src="../<?php echo htmlspecialchars($row['main_image']); ?>" alt="thumb">
                                    <?php else: ?>
                                        <span class="pm-thumb"></span>
                                    <?php endif; ?>
                                </td>
                                <td class="pm-text-wrap">
                                    <?php if ($isVendorAccount): ?>
                                        <span class="pm-link" style="cursor:default; text-decoration:none; color:inherit;">
                                            <?php echo htmlspecialchars($row['name']); ?>
                                        </span>
                                    <?php else: ?>
                                        <a href="backend.php?page=products&action=edit&id=<?php echo intval($row['product_id']); ?>" class="pm-link">
                                            <?php echo htmlspecialchars($row['name']); ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td class="pm-text-wrap">
                                    <?php if (empty($row['category_names'])): ?>
                                        <span style="color:#94a3b8;">不分類</span>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($row['category_names']); ?>
                                    <?php endif; ?>
                                </td>
                                <td class="pm-text-wrap">
                                    <?php if (!empty($row['supplier_name'])): ?>
                                        <?php echo htmlspecialchars($row['supplier_name']); ?>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;">未指定廠商</span>
                                    <?php endif; ?>
                                </td>
                                <td class="pm-nowrap">
                                    <strong><?php echo $skuCount; ?></strong>
                                    <div style="margin-top:4px; color:#94a3b8; font-size:12px;">SKU</div>
                                </td>
                                <td class="pm-nowrap">
                                    <?php if ($row['min_price'] === null): ?>
                                        -
                                    <?php elseif ((float)$row['min_price'] === (float)$row['max_price']): ?>
                                        $<?php echo number_format((float)$row['min_price']); ?>
                                    <?php else: ?>
                                        $<?php echo number_format((float)$row['min_price']); ?> ~ <?php echo number_format((float)$row['max_price']); ?>
                                    <?php endif; ?>
                                </td>
                                <td class="pm-nowrap">
                                    <span style="<?php echo $totalStock === 0 ? 'color:#ef4444; font-weight:bold;' : ''; ?>">
                                        <?php echo number_format($totalStock); ?>
                                    </span>
                                    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:6px;">
                                        <?php if ($skuCount === 0): ?>
                                            <span class="pm-badge pm-off">未建 SKU</span>
                                        <?php elseif ($totalStock === 0): ?>
                                            <span class="pm-badge pm-off">售罄</span>
                                        <?php endif; ?>
                                        <?php if ($lowSkuCount > 0): ?>
                                            <span class="pm-badge" style="background:#fef3c7; color:#92400e;">低庫存 <?php echo $lowSkuCount; ?></span>
                                        <?php endif; ?>
                                        <?php if ($outSkuCount > 0 && $totalStock > 0): ?>
                                            <span class="pm-badge" style="background:#fee2e2; color:#991b1b;">售罄 SKU <?php echo $outSkuCount; ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="pm-nowrap">
                                    <span class="pm-badge <?php echo $row['status'] === 'ON SHELF' ? 'pm-on' : 'pm-off'; ?>">
                                        <?php echo $row['status'] === 'ON SHELF' ? '上架中' : '已下架'; ?>
                                    </span>
                                </td>
                                <td class="pm-nowrap">
                                    <?php if ((int)$row['is_featured'] === 1): ?>
                                        <span class="pm-badge pm-featured">精選</span>
                                    <?php else: ?>
                                        <span style="color:#cbd5e1;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="pm-nowrap">
                                    <?php if ($isVendorAccount): ?>
                                        <span style="color:#94a3b8; font-size:13px;">僅供瀏覽</span>
                                    <?php else: ?>
                                        <div class="pm-actions">
                                            <?php if (intval($admin_role_id ?? 0) === 1): ?>
                                                <a href="backend.php?page=request_supply&product_id=<?php echo intval($row['product_id']); ?>" class="pm-btn pm-btn-main pm-btn-sm">請求供貨</a>
                                            <?php endif; ?>
                                            <a href="backend.php?page=products&action=edit&id=<?php echo intval($row['product_id']); ?>" class="pm-btn pm-btn-edit pm-btn-sm">編輯</a>
                                            <form method="POST" action="backend_action.php" style="display:inline;">
                                                <?php if (function_exists('apCsrfField')) echo apCsrfField(); ?>
                                                <input type="hidden" name="action" value="toggle_product_status">
                                                <input type="hidden" name="product_id" value="<?php echo intval($row['product_id']); ?>">
                                                <input type="hidden" name="new_status" value="<?php echo $row['status'] === 'ON SHELF' ? 'OFF SHELF' : 'ON SHELF'; ?>">
                                                <button class="pm-btn pm-btn-sub pm-btn-sm" type="submit"><?php echo $row['status'] === 'ON SHELF' ? '設為下架' : '設為上架'; ?></button>
                                            </form>
                                            <form method="POST" action="backend_action.php" style="display:inline;" onsubmit="return confirm('確定要刪除此商品嗎？此動作無法復原。');">
                                                <?php if (function_exists('apCsrfField')) echo apCsrfField(); ?>
                                                <input type="hidden" name="action" value="delete_product">
                                                <input type="hidden" name="product_id" value="<?php echo intval($row['product_id']); ?>">
                                                <button class="pm-btn pm-btn-danger pm-btn-sm" type="submit">刪除</button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="<?php echo $isVendorAccount ? '10' : '11'; ?>" style="text-align:center; padding:40px; color:#94a3b8;">目前沒有符合條件的商品</td>
                        </tr>
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

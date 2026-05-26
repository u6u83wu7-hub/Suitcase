<?php
require_once __DIR__ . '/../auth_guard.php';
// actions/BulkUpdateProducts.php - 批量更新商品

if ($action !== 'bulk_update_products') {
    return;
}

$ids = isset($_POST['product_ids']) && is_array($_POST['product_ids']) ? $_POST['product_ids'] : [];
$bulkAction = isset($_POST['bulk_action']) ? trim($_POST['bulk_action']) : '';

if (empty($ids) || $bulkAction === '') {
    goProducts('請選擇商品與批次動作');
}

$safeIds = [];
foreach ($ids as $id) {
    $safeIds[] = intval($id);
}
$safeIds = array_values(array_filter($safeIds, function ($v) { return $v > 0; }));

if (empty($safeIds)) {
    goProducts('未選到有效商品');
}

$idList = implode(',', $safeIds);
$mapSql = [
    'set_on' => "UPDATE products SET status = 'ON SHELF' WHERE product_id IN ({$idList})",
    'set_off' => "UPDATE products SET status = 'OFF SHELF' WHERE product_id IN ({$idList})",
    'set_featured' => "UPDATE products SET is_featured = 1 WHERE product_id IN ({$idList})",
    'unset_featured' => "UPDATE products SET is_featured = 0 WHERE product_id IN ({$idList})",
];

if (!isset($mapSql[$bulkAction])) {
    goProducts('不支援的批次操作');
}

if ($conn->query($mapSql[$bulkAction])) {
    goProducts('批次更新完成');
}

goProducts('批次更新失敗');

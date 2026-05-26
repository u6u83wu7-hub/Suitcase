<?php
require_once __DIR__ . '/../auth_guard.php';
// actions/ToggleFeatured.php - 切換商品精選狀態

if ($action !== 'toggle_featured') {
    return;
}

$productId = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
$newFeatured = isset($_POST['new_featured']) ? intval($_POST['new_featured']) : 0;

if ($productId <= 0 || !in_array($newFeatured, [0, 1], true)) {
    goProducts('無效的精選操作');
}

$stmt = $conn->prepare("UPDATE products SET is_featured = ? WHERE product_id = ?");
if (!$stmt) {
    goProducts('資料庫錯誤: ' . $conn->error);
}
$stmt->bind_param("ii", $newFeatured, $productId);

if ($stmt->execute()) {
    goProducts('精選狀態更新成功');
}

goProducts('精選狀態更新失敗');

<?php
// actions/ToggleProductStatus.php - 切换商品上下架狀態

if ($action !== 'toggle_product_status') {
    return;
}

$productId = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
$newStatus = isset($_POST['new_status']) ? trim($_POST['new_status']) : '';

if ($productId <= 0 || !in_array($newStatus, ['ON SHELF', 'OFF SHELF'], true)) {
    goProducts('無效的上架狀態操作');
}

$stmt = $conn->prepare("UPDATE products SET status = ? WHERE product_id = ?");
$stmt->bind_param("si", $newStatus, $productId);

if ($stmt->execute()) {
    goProducts('狀態更新成功');
}

goProducts('狀態更新失敗');

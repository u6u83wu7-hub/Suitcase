<?php
require_once __DIR__ . '/../auth_guard.php';
// actions/DeleteProduct.php - 删除商品及其相关数据

if ($action !== 'delete_product') {
    return;
}

$productId = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;

if ($productId <= 0) {
    goProducts('無效的商品編號');
}

$conn->begin_transaction();

try {
    // 获取所有商品图片
    $imgStmt = $conn->prepare("SELECT image_url FROM product_images WHERE product_id = ?");
    $imgStmt->bind_param("i", $productId);
    $imgStmt->execute();
    $imgResult = $imgStmt->get_result();
    
    // 删除物理文件
    while ($img = $imgResult->fetch_assoc()) {
        $filePath = __DIR__ . '/../' . $img['image_url'];
        if (is_file($filePath)) {
            unlink($filePath);
        }
    }

    // 删除产品图片数据库记录
    $d1 = $conn->prepare("DELETE FROM product_images WHERE product_id = ?");
    $d1->bind_param("i", $productId);
    $d1->execute();

    // 删除产品变体（SKU）
    $d2 = $conn->prepare("DELETE FROM product_variants WHERE product_id = ?");
    $d2->bind_param("i", $productId);
    $d2->execute();

    // 删除产品本身
    $d3 = $conn->prepare("DELETE FROM products WHERE product_id = ?");
    $d3->bind_param("i", $productId);
    $d3->execute();

    $conn->commit();
    goProducts('商品刪除成功');
} catch (Exception $e) {
    $conn->rollback();
    goProducts('刪除失敗: ' . $e->getMessage());
}

<?php
require_once __DIR__ . '/../auth_guard.php';

// 1. 新增分類
if ($action === 'add_category') {
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    if ($name === '') goCategories('請輸入分類名稱');

    $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
    $stmt->bind_param('s', $name);
    if ($stmt->execute()) goCategories('分類已新增');
    goCategories('分類新增失敗');
}

// 2. 更新分類名稱
if ($action === 'update_category') {
    $categoryId = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    if ($categoryId <= 0 || $name === '') goCategories('分類更新資料不完整');

    $stmt = $conn->prepare("UPDATE categories SET name = ? WHERE category_id = ?");
    $stmt->bind_param('si', $name, $categoryId);
    if ($stmt->execute()) goCategories('分類已更新');
    goCategories('分類更新失敗');
}

// 3. 刪除整個分類
if ($action === 'delete_category') {
    $categoryId = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;
    if ($categoryId <= 0) goCategories('分類刪除資料不完整');

    $conn->begin_transaction();
    try {
        // 先解除該分類下所有商品的綁定關係
        $delLinks = $conn->prepare("DELETE FROM product_category_links WHERE category_id = ?");
        $delLinks->bind_param('i', $categoryId);
        $delLinks->execute();

        // 刪除分類本身
        $stmt = $conn->prepare("DELETE FROM categories WHERE category_id = ?");
        $stmt->bind_param('i', $categoryId);
        $stmt->execute();

        $conn->commit();
        goCategories('分類已刪除');
    } catch (Exception $e) {
        $conn->rollback();
        goCategories('分類刪除失敗');
    }
}

// 4. 將商品新增到此分類 ( Modal 內的操作 )
if ($action === 'add_product_to_category') {
    $categoryId = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;
    $productId = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    
    if ($categoryId <= 0 || $productId <= 0) goCategories('請選擇要加入的商品');

    // INSERT IGNORE 避免重複加入報錯
    $stmt = $conn->prepare("INSERT IGNORE INTO product_category_links (category_id, product_id) VALUES (?, ?)");
    $stmt->bind_param('ii', $categoryId, $productId);
    if ($stmt->execute()) goCategories('商品已成功加入分類！');
    goCategories('加入失敗，可能是該商品已存在此分類中。');
}

// 5. 將商品移出此分類 ( Modal 內的操作 )
if ($action === 'remove_product_from_category') {
    $categoryId = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;
    $productId = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    
    if ($categoryId <= 0 || $productId <= 0) goCategories('操作資料不完整');

    $stmt = $conn->prepare("DELETE FROM product_category_links WHERE category_id = ? AND product_id = ?");
    $stmt->bind_param('ii', $categoryId, $productId);
    if ($stmt->execute()) goCategories('已將商品移出此分類！');
    goCategories('移出失敗');
}
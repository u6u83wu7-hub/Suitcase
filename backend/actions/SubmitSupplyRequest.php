<?php
if (($action ?? '') !== 'submit_supply_request') {
    return;
}

if (!isset($_SESSION['admin_id'], $_SESSION['admin_username'])) {
    goRequestSupply('請先登入。');
}

$adminId = intval($_SESSION['admin_id']);
$adminStmt = $conn->prepare("SELECT role_id FROM admin_users WHERE admin_id = ? LIMIT 1");
$adminStmt->bind_param('i', $adminId);
$adminStmt->execute();
$adminRow = $adminStmt->get_result()->fetch_assoc();
$adminStmt->close();

if (!$adminRow || intval($adminRow['role_id']) !== 1) {
    goRequestSupply('只有超級管理者可以建立請求供貨資料。', $productId);
}

$productId = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
$variantId = isset($_POST['variant_id']) ? intval($_POST['variant_id']) : 0;
$requestedQuantity = isset($_POST['requested_quantity']) ? intval($_POST['requested_quantity']) : 0;
$note = trim($_POST['note'] ?? '');

if ($productId <= 0 || $variantId <= 0 || $requestedQuantity <= 0) {
    goRequestSupply('請選擇商品、SKU並輸入正確的預期數量。', $productId);
}

$productStmt = $conn->prepare("SELECT p.product_id FROM products p WHERE p.product_id = ? LIMIT 1");
$productStmt->bind_param('i', $productId);
$productStmt->execute();
$productRow = $productStmt->get_result()->fetch_assoc();
$productStmt->close();

if (!$productRow) {
    goRequestSupply('找不到該商品。', $productId);
}

$variantStmt = $conn->prepare("SELECT pv.variant_id, pv.product_id FROM product_variants pv WHERE pv.variant_id = ? AND pv.product_id = ? LIMIT 1");
$variantStmt->bind_param('ii', $variantId, $productId);
$variantStmt->execute();
$variantRow = $variantStmt->get_result()->fetch_assoc();
$variantStmt->close();

if (!$variantRow) {
    goRequestSupply('所選 SKU 不屬於該商品。', $productId);
}

$insertStmt = $conn->prepare("INSERT INTO supply_requests (admin_id, product_id, variant_id, requested_quantity, note, request_status) VALUES (?, ?, ?, ?, ?, 'PENDING')");
$insertStmt->bind_param('iiiis', $adminId, $productId, $variantId, $requestedQuantity, $note);

if ($insertStmt->execute()) {
    $insertStmt->close();
    goRequestSupply('請求供貨資料已送出。', $productId);
}

$insertStmt->close();
goRequestSupply('請求供貨資料寫入失敗，請稍後再試。', $productId);

<?php
if (($action ?? '') !== 'submit_supplier_supply') {
    return;
}

if (!isset($_SESSION['admin_id'], $_SESSION['admin_username'])) {
    goSupplierProducts('請先登入。');
}

$adminId = intval($_SESSION['admin_id']);
$adminStmt = $conn->prepare("SELECT role_id FROM admin_users WHERE admin_id = ? LIMIT 1");
$adminStmt->bind_param('i', $adminId);
$adminStmt->execute();
$adminRow = $adminStmt->get_result()->fetch_assoc();
$adminStmt->close();

if (!$adminRow || intval($adminRow['role_id']) !== 3) {
    goSupplierProducts('只有廠商帳號可以送出供應資料。');
}

$supplierStmt = $conn->prepare("SELECT supplier_id, name FROM suppliers WHERE admin_id = ? LIMIT 1");
$supplierStmt->bind_param('i', $adminId);
$supplierStmt->execute();
$supplierRow = $supplierStmt->get_result()->fetch_assoc();
$supplierStmt->close();

if (!$supplierRow) {
    goSupplierProducts('找不到對應的廠商資料。');
}

$supplierId = intval($supplierRow['supplier_id']);
$productId = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
$variantId = isset($_POST['variant_id']) ? intval($_POST['variant_id']) : 0;
$supplyQuantity = isset($_POST['supply_quantity']) ? intval($_POST['supply_quantity']) : 0;
$note = trim($_POST['note'] ?? '');
$requestId = isset($_POST['request_id']) ? intval($_POST['request_id']) : 0;

if ($productId <= 0 || $supplyQuantity <= 0) {
    goSupplierProducts('請選擇商品並輸入正確的供應數量。');
}

$productStmt = $conn->prepare("SELECT product_id FROM products WHERE product_id = ? AND supplier_id = ? LIMIT 1");
$productStmt->bind_param('ii', $productId, $supplierId);
$productStmt->execute();
$productRow = $productStmt->get_result()->fetch_assoc();
$productStmt->close();

if (!$productRow) {
    goSupplierProducts('你只能對自己廠商的商品送出供應資料。');
}

if ($variantId > 0) {
    $varStmt = $conn->prepare("SELECT pv.variant_id FROM product_variants pv JOIN products p ON p.product_id = pv.product_id WHERE pv.variant_id = ? AND pv.product_id = ? AND p.supplier_id = ? LIMIT 1");
    $varStmt->bind_param('iii', $variantId, $productId, $supplierId);
    $varStmt->execute();
    $varRow = $varStmt->get_result()->fetch_assoc();
    $varStmt->close();
    if (!$varRow) {
        goSupplierProducts('所選 SKU 不存在或不屬於此商品/廠商。');
    }
}

$requestRow = null;
if ($requestId > 0) {
    $requestStmt = $conn->prepare("SELECT request_id, product_id, variant_id, requested_quantity, request_status FROM supply_requests WHERE request_id = ? LIMIT 1");
    $requestStmt->bind_param('i', $requestId);
    $requestStmt->execute();
    $requestRow = $requestStmt->get_result()->fetch_assoc();
    $requestStmt->close();

    if (!$requestRow) {
        goSupplierProducts('找不到對應的供應請求。');
    }
    if (intval($requestRow['product_id']) !== $productId || intval($requestRow['variant_id']) !== $variantId) {
        goSupplierProducts('供應請求與送出內容不一致。');
    }
    if (in_array(strtoupper((string)$requestRow['request_status']), ['COMPLETED', 'PARTIAL'], true)) {
        goSupplierProducts('此請求已完成，不能再次供應。');
    }
}

$conn->begin_transaction();
try {
    if ($variantId > 0) {
        $insertStmt = $conn->prepare("INSERT INTO supplier_supplies (supplier_id, admin_id, product_id, variant_id, supply_quantity, is_supply_complete, note) VALUES (?, ?, ?, ?, ?, 0, ?)");
        $insertStmt->bind_param('iiiiis', $supplierId, $adminId, $productId, $variantId, $supplyQuantity, $note);
    } else {
        $insertStmt = $conn->prepare("INSERT INTO supplier_supplies (supplier_id, admin_id, product_id, supply_quantity, is_supply_complete, note) VALUES (?, ?, ?, ?, 0, ?)");
        $insertStmt->bind_param('iiiis', $supplierId, $adminId, $productId, $supplyQuantity, $note);
    }

    if (!$insertStmt->execute()) {
        throw new Exception('供應資料寫入失敗');
    }
    $insertStmt->close();

    if ($requestRow) {
        $requestedQuantity = intval($requestRow['requested_quantity']);
        $newStatus = ($supplyQuantity >= $requestedQuantity) ? 'COMPLETED' : 'PARTIAL';
        $updateRequest = $conn->prepare("UPDATE supply_requests SET request_status = ? WHERE request_id = ?");
        $updateRequest->bind_param('si', $newStatus, $requestId);
        if (!$updateRequest->execute()) {
            throw new Exception('更新供應請求狀態失敗');
        }
        $updateRequest->close();
    }

    $conn->commit();
    if ($requestRow) {
        // redirect back and include completed_request_id so UI can update immediately
        $params = ['page' => 'supplier_products', 'message' => '已依請求送出供應資料，請求狀態已更新。', 'completed_request_id' => $requestId];
        header('Location: backend.php?' . http_build_query($params));
        exit();
    }
    goSupplierProducts('供應資料已送出並完成記錄。');
} catch (Exception $e) {
    $conn->rollback();
    goSupplierProducts($e->getMessage());
}
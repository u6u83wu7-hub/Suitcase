<?php
session_start();

// 管理員驗證
if (!isset($_SESSION['admin_id'])) {
    die("Access Denied");
}

// 資料庫連線
$conn = new mysqli("localhost", "root", "", "all_pass_db");

if ($conn->connect_error) {
    die("資料庫連線失敗：" . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: backend.php?page=products");
    exit();
}

function goProducts($message = '') {
    $safe = addslashes($message);
    echo "<script>" . ($message !== '' ? "alert('{$safe}');" : '') . "location.href='backend.php?page=products';</script>";
    exit();
}

function tableColumns($conn, $tableName) {
    $columns = [];
    $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    $res = $conn->query("SHOW COLUMNS FROM `{$tableName}`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
    }
    return $columns;
}

function boolPost($key) {
    return isset($_POST[$key]) && (string)$_POST[$key] === '1';
}

$action = isset($_POST['action']) ? trim($_POST['action']) : 'add_product';
$productColumns = tableColumns($conn, 'products');
$variantColumns = tableColumns($conn, 'product_variants');
$imageColumns = tableColumns($conn, 'product_images');

if ($action === 'add_product') {
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    if ($name === '') {
        goProducts('請輸入商品名稱');
    }

    $categoryId = (isset($_POST['category_id']) && $_POST['category_id'] !== '') ? intval($_POST['category_id']) : null;
    $isFeatured = boolPost('is_featured') ? 1 : 0;
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $warrantyInfo = isset($_POST['warranty_info']) ? trim($_POST['warranty_info']) : '';
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name)) . '-' . time();

    if (empty($_POST['price']) || empty($_POST['stock']) || !is_array($_POST['price']) || !is_array($_POST['stock'])) {
        goProducts('請至少建立一組 SKU 規格');
    }

    if (empty($_FILES['product_images']['name'][0])) {
        goProducts('請至少上傳一張圖片');
    }

    $uploadDir = __DIR__ . '/../img/products/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true)) {
        goProducts('建立圖片資料夾失敗');
    }

    $conn->begin_transaction();

    try {
        $insertCols = ['primary_category_id', 'name', 'slug', 'is_featured', 'status'];
        $insertVals = [$categoryId, $name, $slug, $isFeatured, 'ON SHELF'];
        $bindTypes = 'issis';

        if (in_array('description', $productColumns, true)) {
            $insertCols[] = 'description';
            $insertVals[] = $description;
            $bindTypes .= 's';
        }
        if (in_array('warranty_info', $productColumns, true)) {
            $insertCols[] = 'warranty_info';
            $insertVals[] = $warrantyInfo;
            $bindTypes .= 's';
        }

        $colSql = implode(', ', $insertCols);
        $qSql = implode(', ', array_fill(0, count($insertCols), '?'));
        $stmt = $conn->prepare("INSERT INTO products ({$colSql}) VALUES ({$qSql})");
        $stmt->bind_param($bindTypes, ...$insertVals);
        if (!$stmt->execute()) {
            throw new Exception('商品建立失敗');
        }
        $productId = $conn->insert_id;

        $sizes = isset($_POST['size']) && is_array($_POST['size']) ? $_POST['size'] : [];
        $colors = isset($_POST['color']) && is_array($_POST['color']) ? $_POST['color'] : [];
        $prices = $_POST['price'];
        $stocks = $_POST['stock'];

        $createdVariant = 0;
        for ($i = 0; $i < count($prices); $i++) {
            if ($prices[$i] === '' || $stocks[$i] === '') {
                continue;
            }

            $skuCode = 'AL-' . strtoupper(substr(md5($productId . '-' . $i . '-' . microtime(true)), 0, 10));
            $price = floatval($prices[$i]);
            $stock = intval($stocks[$i]);
            $size = isset($sizes[$i]) ? trim($sizes[$i]) : '';
            $color = isset($colors[$i]) ? trim($colors[$i]) : '';

            $vCols = ['product_id', 'sku_code', 'price', 'stock_available'];
            $vTypes = 'isdi';
            $vVals = [$productId, $skuCode, $price, $stock];

            if (in_array('size', $variantColumns, true)) {
                $vCols[] = 'size';
                $vTypes .= 's';
                $vVals[] = $size;
            }
            if (in_array('color', $variantColumns, true)) {
                $vCols[] = 'color';
                $vTypes .= 's';
                $vVals[] = $color;
            }

            $vColSql = implode(', ', $vCols);
            $vQSql = implode(', ', array_fill(0, count($vCols), '?'));
            $vStmt = $conn->prepare("INSERT INTO product_variants ({$vColSql}) VALUES ({$vQSql})");
            $vStmt->bind_param($vTypes, ...$vVals);
            if (!$vStmt->execute()) {
                throw new Exception('SKU 建立失敗');
            }
            $createdVariant++;
        }

        if ($createdVariant === 0) {
            throw new Exception('至少要有一組有效 SKU');
        }

        foreach ($_FILES['product_images']['tmp_name'] as $idx => $tmpName) {
            if ($_FILES['product_images']['error'][$idx] !== 0) {
                throw new Exception('圖片上傳失敗');
            }

            $origin = $_FILES['product_images']['name'][$idx];
            $ext = strtolower(pathinfo($origin, PATHINFO_EXTENSION));
            $allow = ['jpg', 'jpeg', 'png', 'webp'];
            if (!in_array($ext, $allow, true)) {
                throw new Exception('圖片格式僅支援 jpg/jpeg/png/webp');
            }

            $filename = $productId . '_' . $idx . '_' . time() . '.' . $ext;
            $targetPath = $uploadDir . $filename;
            if (!move_uploaded_file($tmpName, $targetPath)) {
                throw new Exception('圖片寫入失敗');
            }

            $imageUrl = 'img/products/' . $filename;
            $isMain = ($idx === 0) ? 1 : 0;
            $displayOrder = $idx;

            $iCols = ['product_id', 'image_url', 'is_main'];
            $iTypes = 'isi';
            $iVals = [$productId, $imageUrl, $isMain];
            if (in_array('display_order', $imageColumns, true)) {
                $iCols[] = 'display_order';
                $iTypes .= 'i';
                $iVals[] = $displayOrder;
            }

            $iColSql = implode(', ', $iCols);
            $iQSql = implode(', ', array_fill(0, count($iCols), '?'));
            $iStmt = $conn->prepare("INSERT INTO product_images ({$iColSql}) VALUES ({$iQSql})");
            $iStmt->bind_param($iTypes, ...$iVals);
            if (!$iStmt->execute()) {
                throw new Exception('商品圖片資料寫入失敗');
            }
        }

        $conn->commit();
        goProducts('商品新增成功');
    } catch (Exception $e) {
        $conn->rollback();
        goProducts('錯誤: ' . $e->getMessage());
    }
}

if ($action === 'toggle_product_status') {
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
}

if ($action === 'toggle_featured') {
    $productId = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $newFeatured = isset($_POST['new_featured']) ? intval($_POST['new_featured']) : 0;
    if ($productId <= 0 || !in_array($newFeatured, [0, 1], true)) {
        goProducts('無效的精選操作');
    }

    $stmt = $conn->prepare("UPDATE products SET is_featured = ? WHERE product_id = ?");
    $stmt->bind_param("ii", $newFeatured, $productId);
    if ($stmt->execute()) {
        goProducts('精選狀態更新成功');
    }
    goProducts('精選狀態更新失敗');
}

if ($action === 'bulk_update_products') {
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
}

if ($action === 'delete_product') {
    $productId = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    if ($productId <= 0) {
        goProducts('無效的商品編號');
    }

    $conn->begin_transaction();
    try {
        $imgStmt = $conn->prepare("SELECT image_url FROM product_images WHERE product_id = ?");
        $imgStmt->bind_param("i", $productId);
        $imgStmt->execute();
        $imgResult = $imgStmt->get_result();
        while ($img = $imgResult->fetch_assoc()) {
            $filePath = __DIR__ . '/../' . $img['image_url'];
            if (is_file($filePath)) {
                unlink($filePath);
            }
        }

        $d1 = $conn->prepare("DELETE FROM product_images WHERE product_id = ?");
        $d1->bind_param("i", $productId);
        $d1->execute();

        $d2 = $conn->prepare("DELETE FROM product_variants WHERE product_id = ?");
        $d2->bind_param("i", $productId);
        $d2->execute();

        $d3 = $conn->prepare("DELETE FROM products WHERE product_id = ?");
        $d3->bind_param("i", $productId);
        $d3->execute();

        $conn->commit();
        goProducts('商品刪除成功');
    } catch (Exception $e) {
        $conn->rollback();
        goProducts('刪除失敗: ' . $e->getMessage());
    }
}

goProducts('未知操作');
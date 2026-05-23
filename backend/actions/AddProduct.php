<?php
// actions/AddProduct.php - 添加新商品

if ($action !== 'add_product') {
    return;
}

$name = isset($_POST['name']) ? trim($_POST['name']) : '';
if ($name === '') {
    goProducts('請輸入商品名稱');
}

// 處理分類（檢查是否為動態新增）
$categoryId = null;
if (isset($_POST['category_id']) && $_POST['category_id'] === 'new' && !empty($_POST['new_category_name'])) {
    $newCatName = trim($_POST['new_category_name']);
    $stmtCat = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
    $stmtCat->bind_param("s", $newCatName);
    if ($stmtCat->execute()) {
        $categoryId = $conn->insert_id;
    }
} elseif (!empty($_POST['category_id'])) {
    $categoryId = intval($_POST['category_id']);
}

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

$uploadDir = __DIR__ . '/../../img/products/';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true)) {
    goProducts('建立圖片資料夾失敗');
}

$conn->begin_transaction();

try {
    // 寫入商品主檔
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

    // 處理 SKU
    // 👇 這裡已經幫你改成 $_POST['size_inches']
    $sizes = isset($_POST['size_inches']) && is_array($_POST['size_inches']) ? $_POST['size_inches'] : [];
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

        // 👇 這裡已經幫你改成 size_inches
        if (in_array('size_inches', $variantColumns, true)) {
            $vCols[] = 'size_inches';
            $vTypes .= 's';
            $vVals[] = $size;
        }
        // 將顏色寫入資料庫
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

    // 處理圖片上傳與顏色綁定
    $mainImageIdx = isset($_POST['main_image_idx']) ? intval($_POST['main_image_idx']) : 0;
    $imageColors = isset($_POST['image_color_idx']) ? $_POST['image_color_idx'] : [];

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
        // 依照前端選擇的 Index 設定主圖
        $isMain = ($idx === $mainImageIdx) ? 1 : 0; 
        $displayOrder = $idx;
        // 抓取綁定的顏色
        $imgColor = (isset($imageColors[$idx]) && $imageColors[$idx] !== '') ? trim($imageColors[$idx]) : null;

        $iCols = ['product_id', 'image_url', 'is_main'];
        $iTypes = 'isi';
        $iVals = [$productId, $imageUrl, $isMain];
        
        if (in_array('display_order', $imageColumns, true)) {
            $iCols[] = 'display_order';
            $iTypes .= 'i';
            $iVals[] = $displayOrder;
        }
        
        // 將圖片綁定顏色寫入資料庫
        if (in_array('color', $imageColumns, true) && $imgColor !== null) {
            $iCols[] = 'color';
            $iTypes .= 's';
            $iVals[] = $imgColor;
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
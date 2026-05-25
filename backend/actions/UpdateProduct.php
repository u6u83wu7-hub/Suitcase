<?php
require_once __DIR__ . '/../auth_guard.php';
// actions/UpdateProduct.php - 更新商品

if ($action !== 'update_product') {
    return;
}

$productId = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
if ($productId <= 0) {
    goProducts('無效的商品編號');
}

$name = isset($_POST['name']) ? trim($_POST['name']) : '';
if ($name === '') {
    goProducts('請輸入商品名稱');
}

// 👇 修改點 1：支援動態新增分類
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

$conn->begin_transaction();

try {
    // 更新商品主檔
    $setCols = ['primary_category_id = ?', 'name = ?', 'is_featured = ?'];
    $bindTypes = 'isi';
    $bindVals = [$categoryId, $name, $isFeatured];

    if (in_array('description', $productColumns, true)) {
        $setCols[] = 'description = ?';
        $bindTypes .= 's';
        $bindVals[] = $description;
    }
    if (in_array('warranty_info', $productColumns, true)) {
        $setCols[] = 'warranty_info = ?';
        $bindTypes .= 's';
        $bindVals[] = $warrantyInfo;
    }
    if (in_array('updated_at', $productColumns, true)) {
        $setCols[] = 'updated_at = NOW()';
    }

    $bindTypes .= 'i';
    $bindVals[] = $productId;
    $setSql = implode(', ', $setCols);
    $stmt = $conn->prepare("UPDATE products SET {$setSql} WHERE product_id = ?");
    $stmt->bind_param($bindTypes, ...$bindVals);
    if (!$stmt->execute()) {
        throw new Exception('商品更新失敗');
    }

    // 更新 / 新增 SKU
    $variantIds = isset($_POST['variant_id']) && is_array($_POST['variant_id']) ? $_POST['variant_id'] : [];
    $sizes = isset($_POST['size_inches']) && is_array($_POST['size_inches']) ? $_POST['size_inches'] : [];
    $colors = isset($_POST['color']) && is_array($_POST['color']) ? $_POST['color'] : [];
    $prices = isset($_POST['price']) && is_array($_POST['price']) ? $_POST['price'] : [];
    $stocks = isset($_POST['stock']) && is_array($_POST['stock']) ? $_POST['stock'] : [];

    $keepIds = [];
    $hasValidVariant = false;

    for ($i = 0; $i < count($prices); $i++) {
        if ($prices[$i] === '' || $stocks[$i] === '') {
            continue;
        }
        $hasValidVariant = true;
        $variantId = isset($variantIds[$i]) ? intval($variantIds[$i]) : 0;
        $price = floatval($prices[$i]);
        $stock = intval($stocks[$i]);
        $size = isset($sizes[$i]) ? trim($sizes[$i]) : '';
        $color = isset($colors[$i]) ? trim($colors[$i]) : '';

        if ($variantId > 0) {
            $vSet = ['price = ?', 'stock_available = ?'];
            $vTypes = 'di';
            $vVals = [$price, $stock];

            if (in_array('size_inches', $variantColumns, true)) {
                $vSet[] = 'size_inches = ?';
                $vTypes .= 's';
                $vVals[] = $size;
            }
            if (in_array('color', $variantColumns, true)) {
                $vSet[] = 'color = ?';
                $vTypes .= 's';
                $vVals[] = $color;
            }

            $vTypes .= 'ii';
            $vVals[] = $variantId;
            $vVals[] = $productId;

            $vSetSql = implode(', ', $vSet);
            $vStmt = $conn->prepare("UPDATE product_variants SET {$vSetSql} WHERE variant_id = ? AND product_id = ?");
            $vStmt->bind_param($vTypes, ...$vVals);
            if (!$vStmt->execute()) {
                throw new Exception('SKU 更新失敗');
            }
            $keepIds[] = $variantId;
        } else {
            $skuCode = 'AL-' . strtoupper(substr(md5($productId . '-' . $i . '-' . microtime(true)), 0, 10));
            $vCols = ['product_id', 'sku_code', 'price', 'stock_available'];
            $vTypes = 'isdi';
            $vVals = [$productId, $skuCode, $price, $stock];

            if (in_array('size_inches', $variantColumns, true)) {
                $vCols[] = 'size_inches';
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
            $keepIds[] = $conn->insert_id;
        }
    }

    if (!$hasValidVariant) {
        throw new Exception('至少要有一組有效 SKU');
    }

    if (!empty($keepIds)) {
        $safeIds = array_map('intval', $keepIds);
        $idList = implode(',', $safeIds);
        $conn->query("DELETE FROM product_variants WHERE product_id = {$productId} AND variant_id NOT IN ({$idList})");
    } else {
        $conn->query("DELETE FROM product_variants WHERE product_id = {$productId}");
    }

    // 👇 修改點 2：更新既有圖片顏色 (修復空值存入變成 0 的問題)
    if (in_array('color', $imageColumns, true) && isset($_POST['existing_image_color']) && is_array($_POST['existing_image_color'])) {
        foreach ($_POST['existing_image_color'] as $imgId => $colorVal) {
            $imgId = intval($imgId);
            if ($imgId <= 0) {
                continue;
            }
            $colorVal = trim($colorVal);
            // 如果沒選顏色，強制轉為 NULL 存入，避免資料庫誤存為 '0'
            if ($colorVal === '') {
                $colorVal = null;
            }
            
            $stmtColor = $conn->prepare('UPDATE product_images SET color = ? WHERE image_id = ? AND product_id = ?');
            $stmtColor->bind_param('sii', $colorVal, $imgId, $productId);
            $stmtColor->execute();
        }
    }

    // 刪除舊圖片
    if (isset($_POST['delete_image_ids']) && is_array($_POST['delete_image_ids'])) {
        $deleteIds = array_values(array_filter(array_map('intval', $_POST['delete_image_ids'])));
        if (!empty($deleteIds)) {
            $idList = implode(',', $deleteIds);
            $imgRes = $conn->query("SELECT image_id, image_url FROM product_images WHERE product_id = {$productId} AND image_id IN ({$idList})");
            if ($imgRes) {
                while ($img = $imgRes->fetch_assoc()) {
                    $filePath = __DIR__ . '/../../' . $img['image_url'];
                    if (is_file($filePath)) {
                        unlink($filePath);
                    }
                }
            }
            $conn->query("DELETE FROM product_images WHERE product_id = {$productId} AND image_id IN ({$idList})");
        }
    }

    // 上傳新圖片
    $mainImageIdx = isset($_POST['main_image_idx']) ? intval($_POST['main_image_idx']) : -1;
    $imageColors = isset($_POST['image_color_idx']) ? $_POST['image_color_idx'] : [];

    $uploadDir = __DIR__ . '/../../img/products/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true)) {
        throw new Exception('建立圖片資料夾失敗');
    }

    $mainNewImageId = 0;
    $hasUpload = isset($_FILES['product_images']) && isset($_FILES['product_images']['name']) && is_array($_FILES['product_images']['name']);

    $displayOrderStart = 0;
    if (in_array('display_order', $imageColumns, true)) {
        $maxRes = $conn->query("SELECT COALESCE(MAX(display_order), 0) AS max_order FROM product_images WHERE product_id = {$productId}");
        if ($maxRes) {
            $displayOrderStart = intval($maxRes->fetch_assoc()['max_order']) + 1;
        }
    }

    if ($hasUpload) {
        foreach ($_FILES['product_images']['tmp_name'] as $idx => $tmpName) {
            if (!isset($_FILES['product_images']['error'][$idx]) || $_FILES['product_images']['error'][$idx] === 4) {
                continue;
            }
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
            $isMain = ($mainImageIdx >= 0 && $idx === $mainImageIdx) ? 1 : 0;
            $imgColor = (isset($imageColors[$idx]) && $imageColors[$idx] !== '') ? trim($imageColors[$idx]) : null;

            $iCols = ['product_id', 'image_url', 'is_main'];
            $iTypes = 'isi';
            $iVals = [$productId, $imageUrl, $isMain];

            if (in_array('display_order', $imageColumns, true)) {
                $iCols[] = 'display_order';
                $iTypes .= 'i';
                $iVals[] = $displayOrderStart + $idx;
            }

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
            if ($isMain === 1) {
                $mainNewImageId = $conn->insert_id;
            }
        }
    }

    // 設定主圖
    $existingMainId = isset($_POST['existing_main_image']) ? intval($_POST['existing_main_image']) : 0;
    if ($mainNewImageId > 0) {
        $reset = $conn->prepare('UPDATE product_images SET is_main = 0 WHERE product_id = ?');
        $reset->bind_param('i', $productId);
        $reset->execute();

        $setMain = $conn->prepare('UPDATE product_images SET is_main = 1 WHERE image_id = ? AND product_id = ?');
        $setMain->bind_param('ii', $mainNewImageId, $productId);
        $setMain->execute();
    } elseif ($existingMainId > 0) {
        $reset = $conn->prepare('UPDATE product_images SET is_main = 0 WHERE product_id = ?');
        $reset->bind_param('i', $productId);
        $reset->execute();

        $setMain = $conn->prepare('UPDATE product_images SET is_main = 1 WHERE image_id = ? AND product_id = ?');
        $setMain->bind_param('ii', $existingMainId, $productId);
        $setMain->execute();
    }

    $conn->commit();
    goProducts('商品更新成功');
} catch (Exception $e) {
    $conn->rollback();
    goProducts('錯誤: ' . $e->getMessage());
}
?>
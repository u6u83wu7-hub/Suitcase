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

function upTableExists($conn, $tableName) {
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    $res = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
    return $res && $res->num_rows > 0;
}

function upLogInventoryChange($conn, $enabled, $productId, $variantId, array $snapshot, $oldStock, $newStock, $actionType, $adminId, $note) {
    if (!$enabled) {
        return;
    }

    if ($actionType === 'ADMIN_UPDATE' && (int)$oldStock === (int)$newStock) {
        return;
    }

    $skuCode = isset($snapshot['sku_code']) ? (string)$snapshot['sku_code'] : '';
    $size = isset($snapshot['size_inches']) ? (string)$snapshot['size_inches'] : '';
    $color = isset($snapshot['color']) ? (string)$snapshot['color'] : '';
    $oldStock = (int)$oldStock;
    $newStock = (int)$newStock;
    $delta = $newStock - $oldStock;
    $variantIdValue = $variantId > 0 ? $variantId : null;
    $adminIdValue = $adminId > 0 ? $adminId : null;

    $stmt = $conn->prepare("
        INSERT INTO inventory_adjustment_logs
            (product_id, variant_id, sku_code, size_inches, color, old_stock, new_stock, delta_quantity, action_type, admin_id, note)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        return;
    }

    $stmt->bind_param(
        'iisssiiisis',
        $productId,
        $variantIdValue,
        $skuCode,
        $size,
        $color,
        $oldStock,
        $newStock,
        $delta,
        $actionType,
        $adminIdValue,
        $note
    );
    $stmt->execute();
    $stmt->close();
}

// 分類（多選 + 動態新增）
$categoryIds = isset($_POST['category_ids']) && is_array($_POST['category_ids'])
    ? array_values(array_unique(array_map('intval', $_POST['category_ids'])))
    : [];

if (!empty($_POST['new_category_name'])) {
    $newCatName = trim($_POST['new_category_name']);
    if ($newCatName !== '') {
        $stmtCat = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
        $stmtCat->bind_param("s", $newCatName);
        if ($stmtCat->execute()) {
            $categoryIds[] = $conn->insert_id;
        }
    }
}

$isFeatured = boolPost('is_featured') ? 1 : 0;
$description = isset($_POST['description']) ? trim($_POST['description']) : '';
$warrantyInfo = isset($_POST['warranty_info']) ? trim($_POST['warranty_info']) : '';
// 💡 取得廠商 ID (如果未選擇則為 null)
$supplierId = isset($_POST['supplier_id']) && $_POST['supplier_id'] !== '' ? intval($_POST['supplier_id']) : null;

$conn->begin_transaction();

try {
    $inventoryLogEnabled = upTableExists($conn, 'inventory_adjustment_logs');
    $adminId = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : 0;
    $existingVariants = [];
    $existingVariantSelect = 'variant_id, sku_code, size_inches, color, stock_available';
    if (in_array('color_hex', $variantColumns, true)) {
        $existingVariantSelect .= ', color_hex';
    }
    $existingVariantRes = $conn->query("SELECT {$existingVariantSelect} FROM product_variants WHERE product_id = {$productId} FOR UPDATE");
    if ($existingVariantRes) {
        while ($existingVariant = $existingVariantRes->fetch_assoc()) {
            $existingVariants[(int)$existingVariant['variant_id']] = $existingVariant;
        }
    }

    // 更新商品主檔
    $setCols = ['name = ?', 'is_featured = ?'];
    $bindTypes = 'si';
    $bindVals = [$name, $isFeatured];

    // 💡 新增：將廠商 ID 寫入更新陣列
    if (in_array('supplier_id', $productColumns, true)) {
        $setCols[] = 'supplier_id = ?';
        $bindTypes .= 's'; // 使用 's' 允許寫入 null 值
        $bindVals[] = $supplierId;
    }

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
    $colorHexes = isset($_POST['color_hex']) && is_array($_POST['color_hex']) ? $_POST['color_hex'] : [];
    $originalPrices = isset($_POST['original_price']) && is_array($_POST['original_price']) ? $_POST['original_price'] : [];
    $specialPrices = isset($_POST['special_price']) && is_array($_POST['special_price']) ? $_POST['special_price'] : [];
    $memberPrices = isset($_POST['member_price']) && is_array($_POST['member_price']) ? $_POST['member_price'] : [];
    $stocks = isset($_POST['stock']) && is_array($_POST['stock']) ? $_POST['stock'] : [];

    $keepIds = [];
    $hasValidVariant = false;

    for ($i = 0; $i < count($originalPrices); $i++) {
        if ($originalPrices[$i] === '' || $memberPrices[$i] === '' || $stocks[$i] === '') {
            continue;
        }
        $hasValidVariant = true;
        $variantId = isset($variantIds[$i]) ? intval($variantIds[$i]) : 0;
        $originalPrice = floatval($originalPrices[$i]);
        $specialPrice = isset($specialPrices[$i]) && $specialPrices[$i] !== '' ? floatval($specialPrices[$i]) : null;
        $memberPrice = floatval($memberPrices[$i]);
        $stock = intval($stocks[$i]);
        $size = isset($sizes[$i]) ? trim($sizes[$i]) : '';
        $color = isset($colors[$i]) ? trim($colors[$i]) : '';
        $colorHex = isset($colorHexes[$i]) ? strtoupper(trim($colorHexes[$i])) : '';
        $colorHex = preg_match('/^#[0-9A-F]{6}$/', $colorHex) ? $colorHex : null;
        if ($color === '') {
            $colorHex = null;
        }
        if ($specialPrice !== null && ($specialPrice <= 0 || $specialPrice >= $originalPrice)) {
            throw new Exception('SKU 特價需大於 0 且低於原價；若無特價請留空。');
        }

        if ($variantId > 0) {
            $oldVariant = $existingVariants[$variantId] ?? null;
            $oldStock = $oldVariant ? (int)$oldVariant['stock_available'] : 0;
            $vSet = ['original_price = ?', 'special_price = ?', 'member_price = ?', 'stock_available = ?'];
            $vTypes = 'dddi';
            $vVals = [$originalPrice, $specialPrice, $memberPrice, $stock];

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
            if (in_array('color_hex', $variantColumns, true)) {
                $vSet[] = 'color_hex = ?';
                $vTypes .= 's';
                $vVals[] = $colorHex;
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
            $logSnapshot = [
                'sku_code' => $oldVariant['sku_code'] ?? '',
                'size_inches' => $size,
                'color' => $color,
            ];
            upLogInventoryChange($conn, $inventoryLogEnabled, $productId, $variantId, $logSnapshot, $oldStock, $stock, 'ADMIN_UPDATE', $adminId, '後台商品編輯更新庫存');
            $keepIds[] = $variantId;
        } else {
            $skuCode = 'AL-' . strtoupper(substr(md5($productId . '-' . $i . '-' . microtime(true)), 0, 10));
            $vCols = ['product_id', 'sku_code', 'original_price', 'special_price', 'member_price', 'stock_available'];
            $vTypes = 'isdddi';
            $vVals = [$productId, $skuCode, $originalPrice, $specialPrice, $memberPrice, $stock];

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
            if (in_array('color_hex', $variantColumns, true)) {
                $vCols[] = 'color_hex';
                $vTypes .= 's';
                $vVals[] = $colorHex;
            }

            $vColSql = implode(', ', $vCols);
            $vQSql = implode(', ', array_fill(0, count($vCols), '?'));
            $vStmt = $conn->prepare("INSERT INTO product_variants ({$vColSql}) VALUES ({$vQSql})");
            $vStmt->bind_param($vTypes, ...$vVals);
            if (!$vStmt->execute()) {
                throw new Exception('SKU 建立失敗');
            }
            $newVariantId = (int)$conn->insert_id;
            $logSnapshot = [
                'sku_code' => $skuCode,
                'size_inches' => $size,
                'color' => $color,
            ];
            upLogInventoryChange($conn, $inventoryLogEnabled, $productId, $newVariantId, $logSnapshot, 0, $stock, 'SKU_CREATE', $adminId, '後台新增 SKU');
            $keepIds[] = $newVariantId;
        }
    }

    if (!$hasValidVariant) {
        throw new Exception('至少要有一組有效 SKU');
    }

    if (!empty($keepIds)) {
        $safeIds = array_map('intval', $keepIds);
        $idList = implode(',', $safeIds);
        $deleteIds = array_diff(array_keys($existingVariants), $safeIds);
        foreach ($deleteIds as $deleteVariantId) {
            $deletedVariant = $existingVariants[(int)$deleteVariantId];
            upLogInventoryChange(
                $conn,
                $inventoryLogEnabled,
                $productId,
                (int)$deleteVariantId,
                $deletedVariant,
                (int)$deletedVariant['stock_available'],
                0,
                'SKU_DELETE',
                $adminId,
                '後台移除 SKU'
            );
        }
        $conn->query("DELETE FROM product_variants WHERE product_id = {$productId} AND variant_id NOT IN ({$idList})");
    } else {
        foreach ($existingVariants as $deleteVariantId => $deletedVariant) {
            upLogInventoryChange(
                $conn,
                $inventoryLogEnabled,
                $productId,
                (int)$deleteVariantId,
                $deletedVariant,
                (int)$deletedVariant['stock_available'],
                0,
                'SKU_DELETE',
                $adminId,
                '後台移除全部 SKU'
            );
        }
        $conn->query("DELETE FROM product_variants WHERE product_id = {$productId}");
    }

    $conn->query("DELETE FROM product_category_links WHERE product_id = {$productId}");
    if (!empty($categoryIds)) {
        $categoryIds = array_values(array_unique(array_filter(array_map('intval', $categoryIds))));
        $linkStmt = $conn->prepare("INSERT INTO product_category_links (product_id, category_id) VALUES (?, ?)");
        foreach ($categoryIds as $catId) {
            $linkStmt->bind_param('ii', $productId, $catId);
            if (!$linkStmt->execute()) {
                throw new Exception('分類關聯更新失敗');
            }
        }
    }

    // 👇 更新既有圖片顏色 (修復空值存入變成 0 的問題)
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

    $displayOrderColumn = in_array('sort_order', $imageColumns, true)
        ? 'sort_order'
        : (in_array('display_order', $imageColumns, true) ? 'display_order' : '');
    $displayOrderStart = 0;
    if ($displayOrderColumn !== '') {
        $maxRes = $conn->query("SELECT COALESCE(MAX({$displayOrderColumn}), 0) AS max_order FROM product_images WHERE product_id = {$productId}");
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

            if ($displayOrderColumn !== '') {
                $iCols[] = $displayOrderColumn;
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

<?php
require_once __DIR__ . '/../auth_guard.php';
// MarketingActions.php - 處理行銷活動相關的 action
//版本3 (已修正表單錯誤時保留輸入資料)

function parseDateTimeLocal($value) {
    if ($value === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $value);
    if (!$dt) {
        return null;
    }
    return $dt->format('Y-m-d H:i:s');
}

function parseDiscountValue($type, $valueRaw) {
    $value = is_numeric($valueRaw) ? (float)$valueRaw : null;
    if ($value === null) {
        return null;
    }
    if ($type === 'PERCENT') {
        if ($value <= 0 || $value > 100) {
            return null;
        }
    } elseif ($type === 'AMOUNT') {
        if ($value <= 0) {
            return null;
        }
    } else {
        return null;
    }
    return $value;
}

function uploadErrorMessage($errorCode) {
    switch ($errorCode) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return '上傳檔案超過大小限制';
        case UPLOAD_ERR_PARTIAL:
            return '圖片上傳不完整，請重新選擇';
        case UPLOAD_ERR_NO_FILE:
            return '請上傳活動圖片';
        case UPLOAD_ERR_NO_TMP_DIR:
            return '找不到暫存資料夾';
        case UPLOAD_ERR_CANT_WRITE:
            return '無法寫入檔案';
        case UPLOAD_ERR_EXTENSION:
            return '上傳被副檔名設定阻擋';
        default:
            return '圖片上傳失敗';
    }
}

function normalizeProductIds($productIds) {
    $normalized = [];
    foreach ($productIds as $pid) {
        $pid = intval($pid);
        if ($pid > 0) {
            $normalized[$pid] = true;
        }
    }
    return array_keys($normalized);
}

function findPromotionConflicts($conn, $promotionId, $productIds, $startAt, $endAt) {
    $productIds = normalizeProductIds($productIds);
    if (empty($productIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $sql = "
        SELECT
            pp.product_id,
            pr.name AS product_name,
            p.id AS promotion_id,
            p.name AS promotion_name,
            p.start_at,
            p.end_at
        FROM promotion_products pp
        INNER JOIN promotions p ON p.id = pp.promotion_id
        LEFT JOIN products pr ON pr.product_id = pp.product_id
        WHERE pp.product_id IN ({$placeholders})
          AND p.is_active = 1
          AND p.id <> ?
          AND NOT (p.end_at < ? OR p.start_at > ?)
        ORDER BY pp.product_id ASC, p.start_at DESC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $types = str_repeat('i', count($productIds)) . 'iss';
    $params = array_merge($productIds, [(int)$promotionId, $startAt, $endAt]);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $conflicts = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $conflicts[] = $row;
        }
    }
    return $conflicts;
}

function buildConflictMessage($conflicts) {
    if (empty($conflicts)) {
        return '';
    }

    $items = [];
    foreach (array_slice($conflicts, 0, 6) as $row) {
        $items[] = '#' . intval($row['product_id']) . ' ' . ($row['product_name'] ?? '未命名商品') . '（' . ($row['promotion_name'] ?? '其他活動') . '）';
    }

    $message = '以下商品已在其他啟用活動檔期內：' . implode('、', $items);
    if (count($conflicts) > 6) {
        $message .= '...';
    }
    return $message;
}

function savePromotionImage($promotionId, $fileInfo) {
    $extension = strtolower(pathinfo($fileInfo['name'], PATHINFO_EXTENSION));
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($extension, $allowedExt, true)) {
        throw new Exception('圖片格式僅支援 jpg, jpeg, png, webp');
    }

    $targetDir = __DIR__ . '/../../img/promotions';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    $fileName = 'promotion_' . $promotionId . '_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.' . $extension;
    $targetPath = $targetDir . '/' . $fileName;

    if (!move_uploaded_file($fileInfo['tmp_name'], $targetPath)) {
        throw new Exception('圖片上傳失敗');
    }

    return ['url' => 'img/promotions/' . $fileName, 'path' => $targetPath];
}

function applyPromotionDiscount($conn, $productIds, $discountType, $discountValue) {
    if (empty($productIds)) {
        return;
    }

    if ($discountType === 'PERCENT') {
        $stmt = $conn->prepare("UPDATE product_variants SET special_price = GREATEST(ROUND(member_price - (member_price * ? / 100), 2), 0) WHERE product_id = ?");
        foreach ($productIds as $pid) {
            $stmt->bind_param('di', $discountValue, $pid);
            $stmt->execute();
        }
        return;
    }

    $stmt = $conn->prepare("UPDATE product_variants SET special_price = GREATEST(ROUND(member_price - ?, 2), 0) WHERE product_id = ?");
    foreach ($productIds as $pid) {
        $stmt->bind_param('di', $discountValue, $pid);
        $stmt->execute();
    }
}

function clearPromotionPrices($conn, $productIds) {
    if (empty($productIds)) {
        return;
    }
    $stmt = $conn->prepare("UPDATE product_variants SET special_price = NULL WHERE product_id = ?");
    foreach ($productIds as $pid) {
        $stmt->bind_param('i', $pid);
        $stmt->execute();
    }
}

function getPromotionDiscount($conn, $promotionId) {
    $stmt = $conn->prepare("SELECT discount_type, discount_value FROM promotions WHERE id = ?");
    $stmt->bind_param('i', $promotionId);
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res || $res->num_rows === 0) {
        throw new Exception('找不到活動');
    }
    return $res->fetch_assoc();
}

function getPromotionSchedule($conn, $promotionId) {
    $stmt = $conn->prepare("SELECT start_at, end_at, is_active FROM promotions WHERE id = ?");
    $stmt->bind_param('i', $promotionId);
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res || $res->num_rows === 0) {
        throw new Exception('找不到活動');
    }
    return $res->fetch_assoc();
}

function syncPromotionProducts($conn, $promotionId, $productIds, $discountType, $discountValue) {
    $productIds = normalizeProductIds($productIds);

    $existing = [];
    $stmt = $conn->prepare("SELECT product_id FROM promotion_products WHERE promotion_id = ?");
    $stmt->bind_param('i', $promotionId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $existing[] = (int)$row['product_id'];
        }
    }

    $delStmt = $conn->prepare("DELETE FROM promotion_products WHERE promotion_id = ?");
    $delStmt->bind_param('i', $promotionId);
    $delStmt->execute();

    if (!empty($productIds)) {
        $insertStmt = $conn->prepare("INSERT INTO promotion_products (promotion_id, product_id) VALUES (?, ?)");
        foreach ($productIds as $pid) {
            $insertStmt->bind_param('ii', $promotionId, $pid);
            if (!$insertStmt->execute()) {
                throw new Exception('綁定商品失敗');
            }
        }
    }

    return;
}

if ($action === 'add_promotion') {
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $discountType = isset($_POST['discount_type']) ? trim($_POST['discount_type']) : '';
    $discountValueRaw = isset($_POST['discount_value']) ? trim($_POST['discount_value']) : '';
    $startAtRaw = isset($_POST['start_at']) ? trim($_POST['start_at']) : '';
    $endAtRaw = isset($_POST['end_at']) ? trim($_POST['end_at']) : '';
    $productIds = isset($_POST['product_ids']) && is_array($_POST['product_ids']) ? $_POST['product_ids'] : [];
    $isActive = boolPost('is_active') ? 1 : 0;

    // 將所有填寫的資料打包，如果發生錯誤可以帶回前端
    $errorParams = [
        'open' => 'create',
        'name' => $name,
        'description' => $description,
        'discount_type' => $discountType,
        'discount_value' => $discountValueRaw,
        'start_at' => $startAtRaw,
        'end_at' => $endAtRaw,
        'is_active' => $isActive,
        'product_ids' => implode(',', $productIds)
    ];

    if ($name === '') {
        goMarketing('請輸入活動名稱', $errorParams);
    }

    $startAt = parseDateTimeLocal($startAtRaw);
    $endAt = parseDateTimeLocal($endAtRaw);
    if ($startAt === null || $endAt === null) {
        goMarketing('活動時間格式不正確', $errorParams);
    }
    if (strtotime($endAt) <= strtotime($startAt)) {
        goMarketing('活動結束時間必須晚於開始時間', $errorParams);
    }

    $discountValue = parseDiscountValue($discountType, $discountValueRaw);
    if ($discountValue === null) {
        goMarketing('折扣規則不正確', $errorParams);
    }

    $productIds = normalizeProductIds($productIds);
    $conflicts = findPromotionConflicts($conn, 0, $productIds, $startAt, $endAt);
    if (!empty($conflicts)) {
        goMarketing(buildConflictMessage($conflicts), $errorParams);
    }

    if (!isset($_FILES['promotion_image'])) {
        goMarketing('請上傳活動圖片', $errorParams);
    }
    if ($_FILES['promotion_image']['error'] !== UPLOAD_ERR_OK) {
        goMarketing(uploadErrorMessage($_FILES['promotion_image']['error']), $errorParams);
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO promotions (name, promotion_image_url, description, discount_type, discount_value, start_at, end_at, is_active) VALUES (?, '', ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('sssdssi', $name, $description, $discountType, $discountValue, $startAt, $endAt, $isActive);
        if (!$stmt->execute()) {
            throw new Exception('新增活動失敗');
        }

        $promotionId = $conn->insert_id;
        $imageInfo = savePromotionImage($promotionId, $_FILES['promotion_image']);
        $updateStmt = $conn->prepare("UPDATE promotions SET promotion_image_url = ? WHERE id = ?");
        $updateStmt->bind_param('si', $imageInfo['url'], $promotionId);
        $updateStmt->execute();

        syncPromotionProducts($conn, $promotionId, $productIds, $discountType, $discountValue);
        $conn->commit();
        goMarketing('活動已新增');
    } catch (Exception $e) {
        $conn->rollback();
        if (isset($imageInfo['path']) && file_exists($imageInfo['path'])) {
            unlink($imageInfo['path']);
        }
        goMarketing($e->getMessage(), $errorParams);
    }
}

if ($action === 'update_promotion') {
    $promotionId = isset($_POST['promotion_id']) ? intval($_POST['promotion_id']) : 0;
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $discountType = isset($_POST['discount_type']) ? trim($_POST['discount_type']) : '';
    $discountValueRaw = isset($_POST['discount_value']) ? trim($_POST['discount_value']) : '';
    $startAtRaw = isset($_POST['start_at']) ? trim($_POST['start_at']) : '';
    $endAtRaw = isset($_POST['end_at']) ? trim($_POST['end_at']) : '';
    $productIds = isset($_POST['product_ids']) && is_array($_POST['product_ids']) ? $_POST['product_ids'] : [];
    $isActive = boolPost('is_active') ? 1 : 0;

    if ($promotionId <= 0) {
        goMarketing('活動資料不完整', 'edit');
    }

    // 將所有填寫的資料打包，針對 edit 模式帶入對應的 key
    $errorParams = [
        'open' => 'edit',
        'promotion_id' => $promotionId,
        'edit_name' => $name,
        'edit_description' => $description,
        'edit_discount_type' => $discountType,
        'edit_discount_value' => $discountValueRaw,
        'edit_start_at' => $startAtRaw,
        'edit_end_at' => $endAtRaw,
        'edit_is_active' => $isActive,
        'edit_product_ids' => implode(',', $productIds)
    ];

    if ($name === '') {
        goMarketing('請輸入活動名稱', $errorParams);
    }

    $startAt = parseDateTimeLocal($startAtRaw);
    $endAt = parseDateTimeLocal($endAtRaw);
    if ($startAt === null || $endAt === null) {
        goMarketing('活動時間格式不正確', $errorParams);
    }
    if (strtotime($endAt) <= strtotime($startAt)) {
        goMarketing('活動結束時間必須晚於開始時間', $errorParams);
    }

    $discountValue = parseDiscountValue($discountType, $discountValueRaw);
    if ($discountValue === null) {
        goMarketing('折扣規則不正確', $errorParams);
    }

    $productIds = normalizeProductIds($productIds);
    $conflicts = findPromotionConflicts($conn, $promotionId, $productIds, $startAt, $endAt);
    if (!empty($conflicts)) {
        goMarketing(buildConflictMessage($conflicts), $errorParams);
    }

    $conn->begin_transaction();
    try {
        $check = $conn->prepare("SELECT promotion_image_url FROM promotions WHERE id = ?");
        $check->bind_param('i', $promotionId);
        $check->execute();
        $checkResult = $check->get_result();
        if (!$checkResult || $checkResult->num_rows === 0) {
            throw new Exception('找不到活動');
        }
        $existing = $checkResult->fetch_assoc();
        $imageUrl = $existing['promotion_image_url'];

        if (isset($_FILES['promotion_image']) && $_FILES['promotion_image']['error'] === UPLOAD_ERR_OK) {
            $imageInfo = savePromotionImage($promotionId, $_FILES['promotion_image']);
            $imageUrl = $imageInfo['url'];
        } elseif (isset($_FILES['promotion_image']) && $_FILES['promotion_image']['error'] !== UPLOAD_ERR_NO_FILE) {
            throw new Exception(uploadErrorMessage($_FILES['promotion_image']['error']));
        }

        if ($imageUrl === '' || $imageUrl === null) {
            throw new Exception('活動圖片為必填');
        }

        $stmt = $conn->prepare("UPDATE promotions SET name = ?, promotion_image_url = ?, description = ?, discount_type = ?, discount_value = ?, start_at = ?, end_at = ?, is_active = ? WHERE id = ?");
        $stmt->bind_param('ssssdssii', $name, $imageUrl, $description, $discountType, $discountValue, $startAt, $endAt, $isActive, $promotionId);
        if (!$stmt->execute()) {
            throw new Exception('更新活動失敗');
        }

        syncPromotionProducts($conn, $promotionId, $productIds, $discountType, $discountValue);
        $conn->commit();
        goMarketing('活動已更新');
    } catch (Exception $e) {
        $conn->rollback();
        if (isset($imageInfo['path']) && file_exists($imageInfo['path'])) {
            unlink($imageInfo['path']);
        }
        goMarketing($e->getMessage(), $errorParams);
    }
}

if ($action === 'sync_promotion_products') {
    $promotionId = isset($_POST['promotion_id']) ? intval($_POST['promotion_id']) : 0;
    $productIds = isset($_POST['product_ids']) && is_array($_POST['product_ids']) ? $_POST['product_ids'] : [];

    if ($promotionId <= 0) {
        goMarketing('活動資料不完整', 'edit');
    }

    $productIds = normalizeProductIds($productIds);

    $conn->begin_transaction();
    try {
        $schedule = getPromotionSchedule($conn, $promotionId);
        $conflicts = findPromotionConflicts($conn, $promotionId, $productIds, $schedule['start_at'], $schedule['end_at']);
        if (!empty($conflicts)) {
            throw new Exception(buildConflictMessage($conflicts));
        }

        $discount = getPromotionDiscount($conn, $promotionId);
        syncPromotionProducts($conn, $promotionId, $productIds, $discount['discount_type'], (float)$discount['discount_value']);
        $conn->commit();
        goMarketing('活動商品已更新');
    } catch (Exception $e) {
        $conn->rollback();
        goMarketing($e->getMessage(), ['open' => 'edit', 'promotion_id' => $promotionId]);
    }
}

if ($action === 'upload_promotion_banner') {
    $promotionId = isset($_POST['promotion_id']) ? intval($_POST['promotion_id']) : 0;
    $isShowOnHomepage = boolPost('is_show_on_homepage') ? 1 : 0;
    $sortOrder = isset($_POST['sort_order']) ? intval($_POST['sort_order']) : 0;

    if ($promotionId <= 0) {
        goMarketing('活動資料不完整');
    }

    if (!isset($_FILES['banner_image']) || $_FILES['banner_image']['error'] !== UPLOAD_ERR_OK) {
        goMarketing('請選擇要上傳的圖片');
    }

    $fileInfo = $_FILES['banner_image'];
    $extension = strtolower(pathinfo($fileInfo['name'], PATHINFO_EXTENSION));
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($extension, $allowedExt, true)) {
        goMarketing('圖片格式僅支援 jpg, jpeg, png, webp');
    }

    $targetDir = __DIR__ . '/../../img/promotions';
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    $fileName = 'promotion_' . $promotionId . '_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.' . $extension;
    $targetPath = $targetDir . '/' . $fileName;

    if (!move_uploaded_file($fileInfo['tmp_name'], $targetPath)) {
        goMarketing('圖片上傳失敗');
    }

    $bannerUrl = 'img/promotions/' . $fileName;

    $conn->begin_transaction();
    try {
        $check = $conn->prepare("SELECT id FROM promotions WHERE id = ?");
        $check->bind_param('i', $promotionId);
        $check->execute();
        $checkResult = $check->get_result();
        if (!$checkResult || $checkResult->num_rows === 0) {
            throw new Exception('找不到活動');
        }

        $stmt = $conn->prepare("INSERT INTO promotion_banners (promotion_id, banner_image_url, is_show_on_homepage, sort_order) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('isii', $promotionId, $bannerUrl, $isShowOnHomepage, $sortOrder);
        if (!$stmt->execute()) {
            throw new Exception('Banner 儲存失敗');
        }

        $conn->commit();
        goMarketing('Banner 已上傳');
    } catch (Exception $e) {
        $conn->rollback();
        goMarketing($e->getMessage());
    }
}

if ($action === 'delete_promotion_banner') {
    $promotionId = isset($_POST['promotion_id']) ? intval($_POST['promotion_id']) : 0;
    $bannerUrl = isset($_POST['banner_image_url']) ? trim($_POST['banner_image_url']) : '';

    if ($promotionId <= 0 || $bannerUrl === '') {
        goMarketing('Banner 資料不完整');
    }

    $stmt = $conn->prepare("SELECT banner_image_url FROM promotion_banners WHERE promotion_id = ? AND banner_image_url = ? LIMIT 1");
    $stmt->bind_param('is', $promotionId, $bannerUrl);
    $stmt->execute();
    $result = $stmt->get_result();
    if (!$result || $result->num_rows === 0) {
        goMarketing('找不到要刪除的 Banner');
    }

    $conn->begin_transaction();
    try {
        $delete = $conn->prepare("DELETE FROM promotion_banners WHERE promotion_id = ? AND banner_image_url = ?");
        $delete->bind_param('is', $promotionId, $bannerUrl);
        if (!$delete->execute()) {
            throw new Exception('Banner 刪除失敗');
        }

        $conn->commit();

        $filePath = realpath(__DIR__ . '/../../' . ltrim($bannerUrl, '/'));
        $promotionDir = realpath(__DIR__ . '/../../img/promotions');
        if ($filePath && $promotionDir && strpos($filePath, $promotionDir) === 0 && is_file($filePath)) {
            @unlink($filePath);
        }

        goMarketing('Banner 已刪除');
    } catch (Exception $e) {
        $conn->rollback();
        goMarketing($e->getMessage());
    }
}

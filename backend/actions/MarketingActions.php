<?php
require_once __DIR__ . '/../auth_guard.php';
// MarketingActions.php - 處理行銷活動相關的 action
//版本1

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

if ($action === 'add_promotion') {
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $discountType = isset($_POST['discount_type']) ? trim($_POST['discount_type']) : '';
    $discountValueRaw = isset($_POST['discount_value']) ? trim($_POST['discount_value']) : '';
    $startAtRaw = isset($_POST['start_at']) ? trim($_POST['start_at']) : '';
    $endAtRaw = isset($_POST['end_at']) ? trim($_POST['end_at']) : '';
    $isActive = boolPost('is_active') ? 1 : 0;

    if ($name === '') {
        goMarketing('請輸入活動名稱');
    }

    $startAt = parseDateTimeLocal($startAtRaw);
    $endAt = parseDateTimeLocal($endAtRaw);
    if ($startAt === null || $endAt === null) {
        goMarketing('活動時間格式不正確');
    }
    if (strtotime($endAt) <= strtotime($startAt)) {
        goMarketing('活動結束時間必須晚於開始時間');
    }

    $discountValue = parseDiscountValue($discountType, $discountValueRaw);
    if ($discountValue === null) {
        goMarketing('折扣規則不正確');
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO promotions (name, description, discount_type, discount_value, start_at, end_at, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('sssddsi', $name, $description, $discountType, $discountValue, $startAt, $endAt, $isActive);
        if (!$stmt->execute()) {
            throw new Exception('新增活動失敗');
        }
        $conn->commit();
        goMarketing('活動已新增');
    } catch (Exception $e) {
        $conn->rollback();
        goMarketing($e->getMessage());
    }
}

if ($action === 'sync_promotion_products') {
    $promotionId = isset($_POST['promotion_id']) ? intval($_POST['promotion_id']) : 0;
    $productIds = isset($_POST['product_ids']) && is_array($_POST['product_ids']) ? $_POST['product_ids'] : [];

    if ($promotionId <= 0) {
        goMarketing('活動資料不完整');
    }

    $conn->begin_transaction();
    try {
        $check = $conn->prepare("SELECT id FROM promotions WHERE id = ?");
        $check->bind_param('i', $promotionId);
        $check->execute();
        $checkResult = $check->get_result();
        if (!$checkResult || $checkResult->num_rows === 0) {
            throw new Exception('找不到活動');
        }

        $delStmt = $conn->prepare("DELETE FROM promotion_products WHERE promotion_id = ?");
        $delStmt->bind_param('i', $promotionId);
        $delStmt->execute();

        if (!empty($productIds)) {
            $insertStmt = $conn->prepare("INSERT INTO promotion_products (promotion_id, product_id) VALUES (?, ?)");
            foreach ($productIds as $pid) {
                $pid = intval($pid);
                if ($pid <= 0) {
                    continue;
                }
                $insertStmt->bind_param('ii', $promotionId, $pid);
                if (!$insertStmt->execute()) {
                    throw new Exception('綁定商品失敗');
                }
            }
        }

        $conn->commit();
        goMarketing('活動商品已更新');
    } catch (Exception $e) {
        $conn->rollback();
        goMarketing($e->getMessage());
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

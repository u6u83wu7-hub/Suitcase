<?php
// 💡 修正這裡：把 upload_coupon_banner 和 delete_coupon_banner 加入合法白名單
    date_default_timezone_set('Asia/Taipei');

if (!in_array($action ?? '', ['add_coupon', 'edit_coupon', 'delete_coupon', 'send_coupon', 'upload_coupon_banner', 'delete_coupon_banner'], true)) {
    goCoupon('無效的優惠卷操作。');
}

// === 新增：處理優惠券跑馬燈 Banner 的上傳與刪除 ===
if ($action === 'upload_coupon_banner') {
    $couponId = intval($_POST['coupon_id'] ?? 0);
    $isShow = intval($_POST['is_show_on_homepage'] ?? 1);
    $sortOrder = intval($_POST['sort_order'] ?? 0);

    if ($couponId > 0 && isset($_FILES['banner_image']) && $_FILES['banner_image']['error'] === UPLOAD_ERR_OK) {
        // 💡 注意：因為這個檔案在 actions/ 資料夾內，所以上傳路徑要退回兩層去 img/
        $uploadDir = __DIR__ . '/../../img/promotions/';
        @mkdir($uploadDir, 0777, true);
        $ext = strtolower(pathinfo($_FILES['banner_image']['name'], PATHINFO_EXTENSION));
        $filename = 'coupon_banner_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
        $targetPath = $uploadDir . $filename;

        if (move_uploaded_file($_FILES['banner_image']['tmp_name'], $targetPath)) {
            $imageUrl = 'img/promotions/' . $filename;
            $stmt = $conn->prepare("INSERT INTO coupon_banners (coupon_id, banner_image_url, is_show_on_homepage, sort_order) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('isii', $couponId, $imageUrl, $isShow, $sortOrder);
            $stmt->execute();
            $stmt->close();
            
            header('Location: backend.php?page=coupon&success=1');
            exit;
        }
    }
    header('Location: backend.php?page=coupon&error=' . urlencode('跑馬燈 Banner 上傳失敗'));
    exit;
}

if ($action === 'delete_coupon_banner') {
    $couponId = intval($_POST['coupon_id'] ?? 0);
    $bannerUrl = $_POST['banner_image_url'] ?? '';
    if ($couponId > 0 && $bannerUrl !== '') {
        $stmt = $conn->prepare("DELETE FROM coupon_banners WHERE coupon_id = ? AND banner_image_url = ?");
        $stmt->bind_param('is', $couponId, $bannerUrl);
        $stmt->execute();
        $stmt->close();
        header('Location: backend.php?page=coupon&success=1');
        exit;
    }
    header('Location: backend.php?page=coupon&error=' . urlencode('刪除失敗'));
    exit;
}

function couponSuccess()
{
    header('Location: backend.php?page=coupon&success=1');
    exit();
}

function normalizeCouponCode($code)
{
    $code = strtoupper(trim((string)$code));
    return preg_replace('/\s+/', '', $code);
}

function parseCouponDate($value, $label)
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        goCoupon($label . '格式不正確。');
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function readCouponForm($conn, $currentCouponId = 0)
{
    $couponName = trim((string)($_POST['coupon_name'] ?? ''));
    $requireCode = isset($_POST['require_code']) ? 1 : 0;
    $couponCode = normalizeCouponCode($_POST['coupon_code'] ?? '');
    $couponType = strtoupper(trim((string)($_POST['coupon_type'] ?? 'DISCOUNT')));
    $couponValue = (float)($_POST['coupon_value'] ?? 0);
    $minOrderAmount = (float)($_POST['min_order_amount'] ?? 0);
    $targetMembership = trim((string)($_POST['target_membership'] ?? ''));
    $usageLimitRaw = trim((string)($_POST['usage_limit'] ?? ''));
    $startAt = parseCouponDate($_POST['start_at'] ?? '', '開始時間');
    $endAt = parseCouponDate($_POST['end_at'] ?? '', '結束時間');
    $isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;

    if ($couponName === '') {
        goCoupon('請輸入優惠卷名稱。');
    }
    if (!in_array($couponType, ['DISCOUNT', 'REDUCE', 'POINTS'], true)) {
        goCoupon('優惠卷類型不正確。');
    }
    if ($couponValue <= 0) {
        goCoupon('優惠卷數值必須大於 0。');
    }
    if ($couponType === 'DISCOUNT' && $couponValue > 100) {
        goCoupon('百分比折扣不可超過 100%。');
    }
    if ($minOrderAmount < 0) {
        goCoupon('最低消費金額不可小於 0。');
    }
    if ($usageLimitRaw !== '' && (!ctype_digit($usageLimitRaw) || (int)$usageLimitRaw < 0)) {
        goCoupon('發放上限必須是 0 或正整數。');
    }
    if ($startAt !== null && $endAt !== null && strtotime($startAt) > strtotime($endAt)) {
        goCoupon('開始時間不可晚於結束時間。');
    }
    if (!in_array($isActive, [0, 1], true)) {
        $isActive = 1;
    }

    $couponCodeValue = null;
    if ($requireCode === 1) {
        if ($couponCode === '') {
            goCoupon('請填寫專屬優惠碼。');
        }
        if (!preg_match('/^[A-Z0-9_-]{3,50}$/', $couponCode)) {
            goCoupon('優惠碼只能使用英文、數字、底線或連字號，長度需為 3 到 50 字。');
        }

        $duplicateStmt = $conn->prepare('SELECT coupon_id FROM coupons WHERE coupon_code = ? AND coupon_id <> ? LIMIT 1');
        if (!$duplicateStmt) {
            goCoupon('檢查優惠碼失敗，請稍後再試。');
        }
        $duplicateStmt->bind_param('si', $couponCode, $currentCouponId);
        $duplicateStmt->execute();
        $duplicateRes = $duplicateStmt->get_result();
        $hasDuplicate = $duplicateRes && $duplicateRes->num_rows > 0;
        $duplicateStmt->close();
        if ($hasDuplicate) {
            goCoupon('此優惠碼已存在，請改用其他代碼。');
        }

        $couponCodeValue = $couponCode;
    }

    $usageLimitValue = $usageLimitRaw === '' ? null : (int)$usageLimitRaw;
    $targetMembershipValue = $targetMembership === '' ? null : $targetMembership;

    return [
        'coupon_code' => $couponCodeValue,
        'coupon_name' => $couponName,
        'coupon_type' => $couponType,
        'coupon_value' => $couponValue,
        'min_order_amount' => $minOrderAmount,
        'target_membership' => $targetMembershipValue,
        'usage_limit' => $usageLimitValue,
        'start_at' => $startAt,
        'end_at' => $endAt,
        'is_active' => $isActive,
    ];
}

if (($action ?? '') === 'delete_coupon') {
    $couponId = (int)($_POST['coupon_id'] ?? 0);
    if ($couponId <= 0) {
        goCoupon('找不到要刪除的優惠卷。');
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('DELETE FROM coupon_code_uses WHERE coupon_id = ?');
        $stmt->bind_param('i', $couponId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare('DELETE FROM coupon_distributions WHERE coupon_id = ?');
        $stmt->bind_param('i', $couponId);
        $stmt->execute();
        $stmt->close();
        
        // 💡 順便刪除跑馬燈設定
        $stmt = $conn->prepare('DELETE FROM coupon_banners WHERE coupon_id = ?');
        $stmt->bind_param('i', $couponId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare('DELETE FROM coupons WHERE coupon_id = ?');
        $stmt->bind_param('i', $couponId);
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();

        if ($deleted <= 0) {
            throw new RuntimeException('找不到要刪除的優惠卷。');
        }

        $conn->commit();
        couponSuccess();
    } catch (Throwable $e) {
        $conn->rollback();
        goCoupon($e->getMessage());
    }
}

if (($action ?? '') === 'edit_coupon') {
    $couponId = (int)($_POST['coupon_id'] ?? 0);
    if ($couponId <= 0) {
        goCoupon('找不到要編輯的優惠卷。');
    }

    $data = readCouponForm($conn, $couponId);

    $currentStmt = $conn->prepare('SELECT used_count FROM coupons WHERE coupon_id = ? LIMIT 1');
    $currentStmt->bind_param('i', $couponId);
    $currentStmt->execute();
    $currentRes = $currentStmt->get_result();
    $currentCoupon = ($currentRes && $currentRes->num_rows > 0) ? $currentRes->fetch_assoc() : null;
    $currentStmt->close();
    if (!$currentCoupon) {
        goCoupon('找不到要編輯的優惠卷。');
    }
    if ($data['usage_limit'] !== null && $data['usage_limit'] > 0 && (int)$currentCoupon['used_count'] > $data['usage_limit']) {
        goCoupon('發放上限不可低於目前已發放數。');
    }

    $stmt = $conn->prepare('UPDATE coupons SET coupon_code = ?, coupon_name = ?, coupon_type = ?, coupon_value = ?, min_order_amount = ?, target_membership = ?, usage_limit = ?, start_at = ?, end_at = ?, is_active = ? WHERE coupon_id = ?');
    if (!$stmt) {
        goCoupon('更新優惠卷失敗，請稍後再試。');
    }
    $stmt->bind_param(
        'sssddsissii',
        $data['coupon_code'],
        $data['coupon_name'],
        $data['coupon_type'],
        $data['coupon_value'],
        $data['min_order_amount'],
        $data['target_membership'],
        $data['usage_limit'],
        $data['start_at'],
        $data['end_at'],
        $data['is_active'],
        $couponId
    );
    if (!$stmt->execute()) {
        $message = $stmt->error ?: '更新優惠卷失敗。';
        $stmt->close();
        goCoupon($message);
    }
    $stmt->close();
    couponSuccess();
}

if (($action ?? '') === 'send_coupon') {
    $couponId = (int)($_POST['coupon_id'] ?? 0);
    $targetUserId = (int)($_POST['target_user_id'] ?? 0);
    $quantity = (int)($_POST['quantity'] ?? 0);

    if ($couponId <= 0 || $targetUserId <= 0 || $quantity <= 0) {
        goCoupon('請填寫完整發送資訊。');
    }

    $adminId = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : 1;
    $targetType = 'SINGLE';

    $conn->begin_transaction();
    try {
        $couponStmt = $conn->prepare('SELECT coupon_id, target_membership, usage_limit, used_count, is_active, start_at, end_at FROM coupons WHERE coupon_id = ? FOR UPDATE');
        if (!$couponStmt) {
            throw new RuntimeException('讀取優惠卷失敗。');
        }
        $couponStmt->bind_param('i', $couponId);
        $couponStmt->execute();
        $couponRes = $couponStmt->get_result();
        $coupon = ($couponRes && $couponRes->num_rows > 0) ? $couponRes->fetch_assoc() : null;
        $couponStmt->close();
        if (!$coupon) {
            throw new RuntimeException('找不到要發送的優惠卷。');
        }

        $now = time();
        $start = !empty($coupon['start_at']) ? strtotime($coupon['start_at']) : false;
        $end = !empty($coupon['end_at']) ? strtotime($coupon['end_at']) : false;
        if ((int)$coupon['is_active'] !== 1 || ($start !== false && $now < $start) || ($end !== false && $now > $end)) {
            throw new RuntimeException('此優惠卷尚未啟用或已過期，無法補發。');
        }

        $userStmt = $conn->prepare('SELECT membership_level FROM users WHERE user_id = ? LIMIT 1');
        if (!$userStmt) {
            throw new RuntimeException('讀取會員資料失敗。');
        }
        $userStmt->bind_param('i', $targetUserId);
        $userStmt->execute();
        $userRes = $userStmt->get_result();
        $targetUser = ($userRes && $userRes->num_rows > 0) ? $userRes->fetch_assoc() : null;
        $userStmt->close();
        if (!$targetUser) {
            throw new RuntimeException('找不到要發送的會員。');
        }

        $requiredLevel = trim((string)($coupon['target_membership'] ?? ''));
        $userLevel = trim((string)($targetUser['membership_level'] ?? ''));
        if ($requiredLevel !== '' && $requiredLevel !== $userLevel) {
            throw new RuntimeException('此會員等級不符合優惠卷資格。');
        }

        $usageLimit = (int)($coupon['usage_limit'] ?? 0);
        $usedCount = (int)($coupon['used_count'] ?? 0);
        if ($usageLimit > 0 && $usedCount + $quantity > $usageLimit) {
            throw new RuntimeException('補發數量會超過優惠卷發放上限。');
        }

        $insertStmt = $conn->prepare('INSERT INTO coupon_distributions (coupon_id, user_id, quantity, target_type, sent_by_admin_id) VALUES (?, ?, ?, ?, ?)');
        if (!$insertStmt) {
            throw new RuntimeException('建立發送紀錄失敗。');
        }
        $insertStmt->bind_param('iiisi', $couponId, $targetUserId, $quantity, $targetType, $adminId);
        if (!$insertStmt->execute()) {
            throw new RuntimeException($insertStmt->error ?: '建立發送紀錄失敗。');
        }
        $insertStmt->close();

        $updateStmt = $conn->prepare('UPDATE coupons SET used_count = used_count + ? WHERE coupon_id = ?');
        if (!$updateStmt) {
            throw new RuntimeException('更新發放數失敗。');
        }
        $updateStmt->bind_param('ii', $quantity, $couponId);
        if (!$updateStmt->execute()) {
            throw new RuntimeException($updateStmt->error ?: '更新發放數失敗。');
        }
        $updateStmt->close();

        $conn->commit();
        couponSuccess();
    } catch (Throwable $e) {
        $conn->rollback();
        goCoupon($e->getMessage());
    }
}

if (($action ?? '') === 'add_coupon') {
    $data = readCouponForm($conn);

    $stmt = $conn->prepare('INSERT INTO coupons (coupon_code, coupon_name, coupon_type, coupon_value, min_order_amount, target_membership, usage_limit, start_at, end_at, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        goCoupon('新增優惠卷失敗，請稍後再試。');
    }
    $stmt->bind_param(
        'sssddsissi',
        $data['coupon_code'],
        $data['coupon_name'],
        $data['coupon_type'],
        $data['coupon_value'],
        $data['min_order_amount'],
        $data['target_membership'],
        $data['usage_limit'],
        $data['start_at'],
        $data['end_at'],
        $data['is_active']
    );
    if (!$stmt->execute()) {
        $message = $stmt->error ?: '新增優惠卷失敗。';
        $stmt->close();
        goCoupon($message);
    }
    $stmt->close();
    couponSuccess();
}
?>
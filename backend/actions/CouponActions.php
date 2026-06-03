<?php
if (!in_array($action ?? '', ['add_coupon', 'edit_coupon', 'delete_coupon', 'send_coupon'])) {
	goCoupon('未知優惠卷操作');
}

// 💡 1. 刪除優惠卷
if (($action ?? '') === 'delete_coupon') {
    $couponId = intval($_POST['coupon_id'] ?? 0);
    if ($couponId > 0) {
        $conn->query("DELETE FROM coupons WHERE coupon_id = {$couponId}");
    }
    header('Location: backend.php?page=coupon&success=1');
    exit();
}

// 💡 2. 編輯優惠卷
if (($action ?? '') === 'edit_coupon') {
    $couponId = intval($_POST['coupon_id'] ?? 0);
    $couponName = trim($_POST['coupon_name'] ?? '');
    
    $requireCode = isset($_POST['require_code']) ? intval($_POST['require_code']) : 0;
	$couponCode = trim($_POST['coupon_code'] ?? '');
    
	$couponType = trim($_POST['coupon_type'] ?? 'DISCOUNT');
	$couponValue = trim($_POST['coupon_value'] ?? '0');
	$minOrderAmount = trim($_POST['min_order_amount'] ?? '0');
    $targetMembership = trim($_POST['target_membership'] ?? ''); 
	$usageLimit = trim($_POST['usage_limit'] ?? '');
	$startAt = trim($_POST['start_at'] ?? '');
	$endAt = trim($_POST['end_at'] ?? '');
	$isActive = isset($_POST['is_active']) ? intval($_POST['is_active']) : 1;

	if ($couponName === '') goCoupon('請輸入優惠卷名稱');
    if ($requireCode === 1 && $couponCode === '') goCoupon('請填寫代碼內容');

	$couponCodeValue = ($requireCode === 1 && $couponCode !== '') ? $couponCode : null;
    $targetMembershipValue = $targetMembership === '' ? null : $targetMembership;
	$usageLimitValue = $usageLimit === '' ? null : intval($usageLimit);
	$startAtValue = date('Y-m-d H:i:s', strtotime($startAt));
	$endAtValue = date('Y-m-d H:i:s', strtotime($endAt));

    $stmt = $conn->prepare("UPDATE coupons SET coupon_code=?, coupon_name=?, coupon_type=?, coupon_value=?, min_order_amount=?, target_membership=?, usage_limit=?, start_at=?, end_at=?, is_active=? WHERE coupon_id=?");
    $stmt->bind_param('sssddssssii', $couponCodeValue, $couponName, $couponType, $couponValue, $minOrderAmount, $targetMembershipValue, $usageLimitValue, $startAtValue, $endAtValue, $isActive, $couponId);
    $stmt->execute();
    header('Location: backend.php?page=coupon&success=1');
    exit();
}

// 💡 3. 客服發放給特定會員 (單一發送)
if (($action ?? '') === 'send_coupon') {
	$couponId = intval($_POST['coupon_id'] ?? 0);
	$targetUserId = intval($_POST['target_user_id'] ?? 0);
	$quantity = intval($_POST['quantity'] ?? 0);

	if ($couponId <= 0 || $quantity <= 0 || $targetUserId <= 0) goCoupon('請填寫完整發送資訊');

    $adminId = isset($_SESSION['admin_id']) ? intval($_SESSION['admin_id']) : 1; 
	$targetType = 'SINGLE';

	$insertStmt = $conn->prepare("INSERT INTO coupon_distributions (coupon_id, user_id, quantity, target_type, sent_by_admin_id) VALUES (?, ?, ?, ?, ?)");
	
    $conn->begin_transaction();
	try {
        $insertStmt->bind_param('iiisi', $couponId, $targetUserId, $quantity, $targetType, $adminId);
        $insertStmt->execute();
		$conn->commit();
		header('Location: backend.php?page=coupon&success=1');
		exit();
	} catch (Exception $e) {
		$conn->rollback();
		goCoupon($e->getMessage());
	}
}

// 💡 4. 新增優惠卷
if (($action ?? '') === 'add_coupon') {
	$couponName = trim($_POST['coupon_name'] ?? '');
    $requireCode = isset($_POST['require_code']) ? intval($_POST['require_code']) : 0;
	$couponCode = trim($_POST['coupon_code'] ?? '');
	$couponType = trim($_POST['coupon_type'] ?? 'DISCOUNT');
	$couponValue = trim($_POST['coupon_value'] ?? '0');
	$minOrderAmount = trim($_POST['min_order_amount'] ?? '0');
    $targetMembership = trim($_POST['target_membership'] ?? ''); 
	$usageLimit = trim($_POST['usage_limit'] ?? '');
	$startAt = trim($_POST['start_at'] ?? '');
	$endAt = trim($_POST['end_at'] ?? '');
	$isActive = isset($_POST['is_active']) ? intval($_POST['is_active']) : 1;

	if ($couponName === '') goCoupon('請輸入優惠卷名稱');
    if ($requireCode === 1 && $couponCode === '') goCoupon('您已勾選啟用專屬優惠碼，請填寫代碼內容！');

	$couponCodeValue = ($requireCode === 1 && $couponCode !== '') ? $couponCode : null;
    $targetMembershipValue = $targetMembership === '' ? null : $targetMembership;
	$usageLimitValue = $usageLimit === '' ? null : intval($usageLimit);
	$startAtValue = date('Y-m-d H:i:s', strtotime($startAt));
	$endAtValue = date('Y-m-d H:i:s', strtotime($endAt));

	$stmt = $conn->prepare("INSERT INTO coupons (coupon_code, coupon_name, coupon_type, coupon_value, min_order_amount, target_membership, usage_limit, start_at, end_at, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
	$stmt->bind_param('sssddssssi', $couponCodeValue, $couponName, $couponType, $couponValue, $minOrderAmount, $targetMembershipValue, $usageLimitValue, $startAtValue, $endAtValue, $isActive);
	$stmt->execute();
    header('Location: backend.php?page=coupon&success=1');
    exit();
}
?>
<?php

require_once __DIR__ . '/../auth_guard.php';


if (($action ?? '') !== 'add_coupon' && ($action ?? '') !== 'send_coupon' && ($action ?? '') !== 'redeem_coupon_code') {
	goCoupon('未知優惠卷操作');
}

if (($action ?? '') === 'redeem_coupon_code') {
	$redeemCode = trim($_POST['coupon_code'] ?? '');
	$userId = intval($_SESSION['user_id'] ?? 0);

	if ($userId <= 0) {
		header('Location: ../homepage/profile.php?coupon_error=' . urlencode('請先登入'));
		exit();
	}
	if ($redeemCode === '') {
		header('Location: ../homepage/profile.php?coupon_error=' . urlencode('請輸入優惠卷代碼'));
		exit();
	}

	$couponStmt = $conn->prepare("SELECT coupon_id, is_active, start_at, end_at, usage_limit, used_count FROM coupons WHERE coupon_code = ? LIMIT 1");
	$couponStmt->bind_param('s', $redeemCode);
	$couponStmt->execute();
	$couponRes = $couponStmt->get_result();
	if (!$couponRes || $couponRes->num_rows === 0) {
		header('Location: ../homepage/profile.php?coupon_error=' . urlencode('找不到此優惠卷代碼'));
		exit();
	}
	$coupon = $couponRes->fetch_assoc();
	$couponId = intval($coupon['coupon_id']);

	if ((int)$coupon['is_active'] !== 1) {
		header('Location: ../homepage/profile.php?coupon_error=' . urlencode('此優惠卷目前未啟用'));
		exit();
	}
	$now = time();
	$startTime = !empty($coupon['start_at']) ? strtotime($coupon['start_at']) : false;
	$endTime = !empty($coupon['end_at']) ? strtotime($coupon['end_at']) : false;
	if (($startTime !== false && $now < $startTime) || ($endTime !== false && $now > $endTime)) {
		header('Location: ../homepage/profile.php?coupon_error=' . urlencode('此優惠卷不在有效期間內'));
		exit();
	}
	if ((int)$coupon['usage_limit'] > 0 && (int)$coupon['used_count'] >= (int)$coupon['usage_limit']) {
		header('Location: ../homepage/profile.php?coupon_error=' . urlencode('此優惠卷已達使用上限'));
		exit();
	}

	$checkStmt = $conn->prepare("SELECT coupon_code_use_id FROM coupon_code_uses WHERE coupon_id = ? AND user_id = ? LIMIT 1");
	$checkStmt->bind_param('ii', $couponId, $userId);
	$checkStmt->execute();
	$checkRes = $checkStmt->get_result();
	if ($checkRes && $checkRes->num_rows > 0) {
		header('Location: ../homepage/profile.php?coupon_error=' . urlencode('你已使用過這個優惠卷代碼'));
		exit();
	}

	$conn->begin_transaction();
	try {
		$useStmt = $conn->prepare("INSERT INTO coupon_code_uses (coupon_id, user_id, coupon_code) VALUES (?, ?, ?)");
		$useStmt->bind_param('iis', $couponId, $userId, $redeemCode);
		if (!$useStmt->execute()) {
			throw new Exception($useStmt->error ?: '優惠卷代碼新增失敗');
		}

		// 同步紀錄到 coupon_distributions，以便後台與前台能看到使用紀錄
		$distStmt = $conn->prepare("INSERT INTO coupon_distributions (coupon_id, user_id, quantity, target_type, sent_by_admin_id) VALUES (?, ?, ?, ?, ?)");
		if ($distStmt) {
			$qty = 1;
			$targetType = 'USE CODE';
			$adminId = null; // 前台兌換沒有管理員，保留空值方便後台辨識來源
			$distStmt->bind_param('iiisi', $couponId, $userId, $qty, $targetType, $adminId);
			if (!$distStmt->execute()) {
				throw new Exception($distStmt->error ?: '寫入發送紀錄失敗');
			}
			$distStmt->close();
		} else {
			throw new Exception('無法建立發送紀錄預備語句: ' . $conn->error);
		}

		$updateStmt = $conn->prepare("UPDATE coupons SET used_count = used_count + 1 WHERE coupon_id = ?");
		$updateStmt->bind_param('i', $couponId);
		if (!$updateStmt->execute()) {
			throw new Exception($updateStmt->error ?: '更新優惠卷使用次數失敗');
		}

		$conn->commit();
		header('Location: ../homepage/profile.php?coupon_success=1');
		exit();
	} catch (Exception $e) {
		$conn->rollback();
		header('Location: ../homepage/profile.php?coupon_error=' . urlencode($e->getMessage()));
		exit();
	}
}

if (($action ?? '') === 'send_coupon') {
	$couponId = intval($_POST['coupon_id'] ?? 0);
	$targetType = trim($_POST['target_type'] ?? 'SINGLE');
	$targetUserId = intval($_POST['target_user_id'] ?? 0);
	$quantity = intval($_POST['quantity'] ?? 0);

	if ($couponId <= 0) {
		goCoupon('請選擇優惠卷');
	}
	if ($quantity <= 0) {
		goCoupon('請輸入發送張數');
	}
	if (!in_array($targetType, ['SINGLE', 'ALL'], true)) {
		goCoupon('請選擇正確的發送對象');
	}

	$couponStmt = $conn->prepare("SELECT coupon_id FROM coupons WHERE coupon_id = ? LIMIT 1");
	$couponStmt->bind_param('i', $couponId);
	$couponStmt->execute();
	$couponRes = $couponStmt->get_result();
	if (!$couponRes || $couponRes->num_rows === 0) {
		goCoupon('找不到指定的優惠卷');
	}

	$userIds = [];
	if ($targetType === 'ALL') {
		$userResult = $conn->query("SELECT user_id FROM users ORDER BY user_id ASC");
		if ($userResult) {
			while ($userRow = $userResult->fetch_assoc()) {
				$userIds[] = (int)$userRow['user_id'];
			}
		}
		if (empty($userIds)) {
			goCoupon('找不到可發送的用戶');
		}
	} else {
		if ($targetUserId <= 0) {
			goCoupon('請選擇發送對象');
		}
		$userIds[] = $targetUserId;
	}

	$insertStmt = $conn->prepare("INSERT INTO coupon_distributions (coupon_id, user_id, quantity, target_type, sent_by_admin_id) VALUES (?, ?, ?, ?, ?)");
	if (!$insertStmt) {
		goCoupon('發送優惠卷失敗：' . $conn->error);
	}

	$adminId = isset($_SESSION['admin_username']) ? 0 : 0;
	$targetTypeValue = $targetType;

	$conn->begin_transaction();
	try {
		foreach ($userIds as $userId) {
			$insertStmt->bind_param('iiisi', $couponId, $userId, $quantity, $targetTypeValue, $adminId);
			if (!$insertStmt->execute()) {
				throw new Exception($insertStmt->error ?: '發送優惠卷失敗');
			}
		}
		$conn->commit();
		$insertStmt->close();
		header('Location: backend.php?page=coupon&success=1');
		exit();
	} catch (Exception $e) {
		$conn->rollback();
		$insertStmt->close();
		goCoupon($e->getMessage());
	}
}

$couponName = trim($_POST['coupon_name'] ?? '');
$couponCode = trim($_POST['coupon_code'] ?? '');
$couponType = trim($_POST['coupon_type'] ?? 'DISCOUNT');
$couponValue = trim($_POST['coupon_value'] ?? '0');
$minOrderAmount = trim($_POST['min_order_amount'] ?? '0');
$usageLimit = trim($_POST['usage_limit'] ?? '');
$startAt = trim($_POST['start_at'] ?? '');
$endAt = trim($_POST['end_at'] ?? '');
$isActive = isset($_POST['is_active']) ? intval($_POST['is_active']) : 1;

if ($couponName === '') {
	goCoupon('請輸入優惠卷名稱');
}
if (!in_array($couponType, ['DISCOUNT', 'REDUCE', 'POINTS'], true)) {
	goCoupon('請選擇優惠卷類型');
}
if ($startAt === '' || $endAt === '') {
	goCoupon('請設定優惠卷開始與結束日期');
}

$startTime = strtotime($startAt);
$endTime = strtotime($endAt);
if ($startTime === false || $endTime === false || $startTime >= $endTime) {
	goCoupon('優惠卷日期設定不正確');
}

$couponCodeValue = $couponCode === '' ? null : $couponCode;
$usageLimitValue = $usageLimit === '' ? null : intval($usageLimit);
$startAtValue = date('Y-m-d H:i:s', $startTime);
$endAtValue = date('Y-m-d H:i:s', $endTime);

$stmt = $conn->prepare("INSERT INTO coupons (coupon_code, coupon_name, coupon_type, coupon_value, min_order_amount, usage_limit, start_at, end_at, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
if (!$stmt) {
	goCoupon('新增優惠卷失敗：' . $conn->error);
}

$stmt->bind_param(
	'sssddsssi',
	$couponCodeValue,
	$couponName,
	$couponType,
	$couponValue,
	$minOrderAmount,
	$usageLimitValue,
	$startAtValue,
	$endAtValue,
	$isActive
);

if ($stmt->execute()) {
	$stmt->close();
	header('Location: backend.php?page=coupon&success=1');
	exit();
}

$error = $stmt->error ?: '新增優惠卷失敗';
$stmt->close();
goCoupon($error);
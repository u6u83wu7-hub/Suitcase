<?php

if (!function_exists('apIsLoggedInUser')) {
	function apIsLoggedInUser() {
		return session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id']);
	}
}

if (!function_exists('apResolveBasePrice')) {
	function apResolveBasePrice($originalPrice, $memberPrice, $isLoggedIn) {
		$originalPrice = (float)$originalPrice;
		$memberPrice = ($memberPrice !== null && $memberPrice !== '') ? (float)$memberPrice : null;
		if ($isLoggedIn && $memberPrice !== null && $memberPrice > 0) {
			return $memberPrice;
		}
		return $originalPrice;
	}
}

if (!function_exists('apFetchActivePromotionRule')) {
	function apFetchActivePromotionRule($conn, $productId) {
		if (!($conn instanceof mysqli) || (int)$productId <= 0) {
			return null;
		}

		$stmt = $conn->prepare("SELECT p.discount_type, p.discount_value FROM promotions p INNER JOIN promotion_products pp ON pp.promotion_id = p.id WHERE pp.product_id = ? AND p.is_active = 1 AND NOW() BETWEEN p.start_at AND p.end_at ORDER BY p.start_at DESC, p.id DESC LIMIT 1");
		if (!$stmt) {
			return null;
		}

		$productId = (int)$productId;
		$stmt->bind_param('i', $productId);
		$stmt->execute();
		$res = $stmt->get_result();
		if ($res && $res->num_rows > 0) {
			return $res->fetch_assoc();
		}
		return null;
	}
}

if (!function_exists('apApplyPromotionDiscount')) {
	function apApplyPromotionDiscount($basePrice, $promotionRule) {
		$basePrice = (float)$basePrice;
		if (empty($promotionRule)) {
			return $basePrice;
		}

		$discountType = $promotionRule['discount_type'] ?? '';
		$discountValue = isset($promotionRule['discount_value']) ? (float)$promotionRule['discount_value'] : 0;
		if ($discountValue <= 0) {
			return $basePrice;
		}

		if ($discountType === 'PERCENT') {
			return max(round($basePrice - ($basePrice * $discountValue / 100), 2), 0);
		}

		if ($discountType === 'AMOUNT') {
			return max(round($basePrice - $discountValue, 2), 0);
		}

		return $basePrice;
	}
}

if (!function_exists('apResolveDisplayPrice')) {
	function apResolveDisplayPrice($conn, $productId, $originalPrice, $memberPrice = null, $isLoggedIn = null) {
		if ($isLoggedIn === null) {
			$isLoggedIn = apIsLoggedInUser();
		}

		$basePrice = apResolveBasePrice($originalPrice, $memberPrice, $isLoggedIn);
		$promotionRule = apFetchActivePromotionRule($conn, $productId);
		$finalPrice = apApplyPromotionDiscount($basePrice, $promotionRule);

		return [
			'base_price' => $basePrice,
			'base_label' => $isLoggedIn ? '會員價' : '原價',
			'promotion_active' => !empty($promotionRule),
			'promotion_rule' => $promotionRule,
			'final_price' => $finalPrice,
			'final_label' => !empty($promotionRule) && abs($finalPrice - $basePrice) > 0.0001 ? '特價' : ($isLoggedIn ? '會員價' : '原價'),
		];
	}
}
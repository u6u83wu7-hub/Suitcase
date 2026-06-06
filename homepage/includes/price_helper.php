<?php

if (!function_exists('apPriceNumberOrNull')) {
    function apPriceNumberOrNull($value, $allowZero = false) {
        if ($value === null || $value === '') {
            return null;
        }

        $number = (float)$value;
        if ($number < 0) {
            return null;
        }
        if (!$allowZero && $number <= 0) {
            return null;
        }

        return $number;
    }
}

if (!function_exists('apIsMemberPriceEligible')) {
    function apIsMemberPriceEligible($membershipLevel) {
        if ($membershipLevel === null || $membershipLevel === '') {
            return false;
        }

        if (is_numeric($membershipLevel)) {
            return (int)$membershipLevel >= 2;
        }

        $normalized = strtolower(trim((string)$membershipLevel));
        return in_array($normalized, ['vip', 'premium', 'member', 'gold', 'silver'], true);
    }
}

if (!function_exists('apFetchUserMembershipLevel')) {
    function apFetchUserMembershipLevel($conn, $userId) {
        if (!($conn instanceof mysqli) || (int)$userId <= 0) {
            return null;
        }

        $stmt = $conn->prepare('SELECT membership_level FROM users WHERE user_id = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }

        $userId = (int)$userId;
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row['membership_level'] ?? null;
    }
}

if (!function_exists('apResolveVariantPrice')) {
    function apResolveVariantPrice(array $variant, $isMemberEligible = false) {
        $original = apPriceNumberOrNull($variant['original_price'] ?? null, true);
        $special = apPriceNumberOrNull($variant['special_price'] ?? null, true);
        $member = apPriceNumberOrNull($variant['member_price'] ?? null, false);

        if ($original === null) {
            $original = 0.0;
        }

        $candidates = [
            'original' => $original,
        ];

        if ($special !== null) {
            $candidates['special'] = $special;
        }

        if ($isMemberEligible && $member !== null) {
            $candidates['member'] = $member;
        }

        $selectedKey = 'original';
        $selectedPrice = $original;
        foreach ($candidates as $key => $price) {
            if ($price < $selectedPrice) {
                $selectedKey = $key;
                $selectedPrice = $price;
            }
        }

        $labels = [
            'original' => '原價',
            'special' => '特價',
            'member' => '會員價',
        ];

        return [
            'original_price' => $original,
            'special_price' => $special,
            'member_price' => $member,
            'final_price' => $selectedPrice,
            'headline_label' => $labels[$selectedKey],
            'price_type' => $selectedKey,
            'is_member_eligible' => (bool)$isMemberEligible,
        ];
    }
}

if (!function_exists('apVariantPriceSql')) {
    function apVariantPriceSql($alias = 'v', $isMemberEligible = false) {
        $safeAlias = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$alias);
        if ($safeAlias === '') {
            $safeAlias = 'v';
        }
        $prefix = $safeAlias . '.';
        $original = "COALESCE({$prefix}original_price, 0)";
        $special = "CASE WHEN {$prefix}special_price IS NOT NULL AND {$prefix}special_price >= 0 THEN {$prefix}special_price ELSE {$original} END";
        $member = $isMemberEligible
            ? "CASE WHEN {$prefix}member_price IS NOT NULL AND {$prefix}member_price > 0 THEN {$prefix}member_price ELSE {$original} END"
            : $original;

        return "LEAST({$original}, {$special}, {$member})";
    }
}

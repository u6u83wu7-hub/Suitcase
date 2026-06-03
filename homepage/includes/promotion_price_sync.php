<?php
if (!function_exists('apSetDbTimeZone')) {
    function apSetDbTimeZone($conn) {
        if ($conn instanceof mysqli) {
            $conn->query("SET time_zone = '+08:00'");
        }
    }
}

if (!function_exists('apTableExists')) {
    function apTableExists($conn, $tableName) {
        if (!($conn instanceof mysqli)) {
            return false;
        }
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$tableName);
        if ($safe === '') {
            return false;
        }
        $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
        return ($res && $res->num_rows > 0);
    }
}

if (!function_exists('apSyncPromotionPrices')) {
    function apSyncPromotionPrices($conn) {
        if (!($conn instanceof mysqli)) {
            return;
        }
        if (!apTableExists($conn, 'product_variants') || !apTableExists($conn, 'promotions') || !apTableExists($conn, 'promotion_products')) {
            return;
        }

        // 💡 修正：強制使用 original_price 作為折扣計算基準
        $sql = "
            UPDATE product_variants v
            LEFT JOIN (
                SELECT pp.product_id, p.discount_type, p.discount_value
                FROM promotions p
                INNER JOIN promotion_products pp ON pp.promotion_id = p.id
                WHERE p.is_active = 1
                  AND NOW() BETWEEN p.start_at AND p.end_at
            ) ap ON ap.product_id = v.product_id
            SET v.special_price = CASE
                WHEN ap.product_id IS NULL THEN NULL
                WHEN ap.discount_type = 'PERCENT' THEN GREATEST(ROUND(COALESCE(v.original_price, 0) - (COALESCE(v.original_price, 0) * ap.discount_value / 100), 2), 0)
                WHEN ap.discount_type = 'AMOUNT' THEN GREATEST(ROUND(COALESCE(v.original_price, 0) - ap.discount_value, 2), 0)
                ELSE NULL
            END
        ";
        if (!$conn->query($sql)) {
            error_log('[promotion_price_sync] sync failed: ' . $conn->error);
        }
    }
}

if (!function_exists('apRunPromotionSync')) {
    function apRunPromotionSync($conn) {
        if ($conn instanceof mysqli) {
            apSetDbTimeZone($conn);
            apSyncPromotionPrices($conn);
        }
    }
}
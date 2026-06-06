<?php

if (!function_exists('sfTableExists')) {
    function sfTableExists($conn, $tableName) {
        if (!$conn) {
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

if (!function_exists('sfTableColumns')) {
    function sfTableColumns($conn, $tableName) {
        $cols = [];
        if (!$conn) {
            return $cols;
        }
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$tableName);
        if ($safe === '') {
            return $cols;
        }
        $res = $conn->query("SHOW COLUMNS FROM `{$safe}`");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $cols[] = $row['Field'];
            }
        }
        return $cols;
    }
}

if (!function_exists('sfProductImageOrder')) {
    function sfProductImageOrder($conn, $alias = 'pi') {
        $prefix = $alias !== '' ? preg_replace('/[^a-zA-Z0-9_]/', '', $alias) . '.' : '';
        $cols = sfTableColumns($conn, 'product_images');
        $parts = [];

        if (in_array('is_main', $cols, true)) {
            $parts[] = $prefix . 'is_main DESC';
        }
        if (in_array('sort_order', $cols, true)) {
            $parts[] = $prefix . 'sort_order ASC';
        } elseif (in_array('display_order', $cols, true)) {
            $parts[] = $prefix . 'display_order ASC';
        }
        if (in_array('image_id', $cols, true)) {
            $parts[] = $prefix . 'image_id ASC';
        }

        return !empty($parts) ? implode(', ', $parts) : $prefix . 'product_id ASC';
    }
}

if (!function_exists('sfCategoryUrl')) {
    function sfCategoryUrl($categoryId) {
        return 'new_in.php?category_id=' . intval($categoryId);
    }
}

if (!function_exists('sfPublicFileExists')) {
    function sfPublicFileExists($relativePath) {
        $relativePath = ltrim((string)$relativePath, '/\\');
        if ($relativePath === '' || strpos($relativePath, '..') !== false) {
            return false;
        }

        return is_file(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath));
    }
}

if (!function_exists('sfFetchCategories')) {
    function sfFetchCategories($conn) {
        $categories = [];
        if (!sfTableExists($conn, 'categories')) {
            return $categories;
        }

        $cols = sfTableColumns($conn, 'categories');
        $parentSelect = in_array('parent_id', $cols, true) ? 'parent_id' : 'NULL AS parent_id';
        $sql = "SELECT category_id, name, {$parentSelect}
                FROM categories
                ORDER BY COALESCE(parent_id, 0) ASC, name ASC";
        $res = $conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $categories[] = $row;
            }
        }
        return $categories;
    }
}

if (!function_exists('sfFetchHomepageBanners')) {
    function sfFetchHomepageBanners($conn, $limit = 5) {
        $banners = [];
        if (!sfTableExists($conn, 'promotion_banners') || !sfTableExists($conn, 'promotions')) {
            return $banners;
        }

        $limit = max(1, intval($limit));
        $sql = "
            SELECT
                pb.promotion_id,
                pb.banner_image_url,
                p.promotion_image_url,
                p.name,
                p.description,
                p.start_at,
                p.end_at
            FROM promotion_banners pb
            INNER JOIN promotions p ON p.id = pb.promotion_id
            WHERE pb.is_show_on_homepage = 1
              AND p.is_active = 1
              AND NOW() BETWEEN p.start_at AND p.end_at
            ORDER BY pb.sort_order ASC, p.start_at DESC, pb.promotion_id DESC
            LIMIT {$limit}
        ";
        $res = $conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                if (!sfPublicFileExists($row['banner_image_url'])) {
                    $row['banner_image_url'] = sfPublicFileExists($row['promotion_image_url'])
                        ? $row['promotion_image_url']
                        : '';
                }
                $banners[] = $row;
            }
        }
        return $banners;
    }
}

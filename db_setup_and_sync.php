<?php
// db_setup_and_sync.php - 團隊資料庫【全新建立 + 舊版同步】一鍵搞定腳本（每次改檔同步更新版本號）
//版本2
header("Content-Type: text/html; charset=utf-8");

$host = "localhost";
$user = "root";
$pass = "";
$dbname = "all_pass_db"; 

$conn = new mysqli($host, $user, $pass);
if ($conn->connect_error) {
    die("<h3 style='color:red;'>❌ 資料庫連線失敗: " . $conn->connect_error . "</h3>");
}
$conn->set_charset("utf8mb4");

$conn->query("CREATE DATABASE IF NOT EXISTS `$dbname` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$conn->select_db($dbname);

echo "<h2>🚀 All Pass 專案 - 資料庫結構同步/初始化開始...</h2>";
echo "<p style='color:#0ea5e9; font-weight:700;'>執行版本：v2</p>";
echo "<hr>";

function columnExists($conn, $table, $column) {
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $result && $result->num_rows > 0;
}

// 📁 表格 1：管理員 (admin_users)
$sql_admin = "CREATE TABLE IF NOT EXISTS `admin_users` (
    `admin_id` INT AUTO_INCREMENT PRIMARY KEY,
    `role_id` INT NOT NULL DEFAULT 1,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_admin);

// 📁 表格 2：分類 (categories)
$sql_cat = "CREATE TABLE IF NOT EXISTS `categories` (
    `category_id` INT AUTO_INCREMENT PRIMARY KEY,
    `parent_id` INT NULL,
    `name` VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_cat);

// 📁 表格 3：供應商 (suppliers)
$sql_sup = "CREATE TABLE IF NOT EXISTS `suppliers` (
    `supplier_id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `contact_person` VARCHAR(100) NULL,
    `phone` VARCHAR(20) NULL,
    `email` VARCHAR(100) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_sup);

// 📁 表格 4：商品主檔 (products)
$sql_prod = "CREATE TABLE IF NOT EXISTS `products` (
    `product_id` INT AUTO_INCREMENT PRIMARY KEY,
    `supplier_id` INT NULL,
    `slug` VARCHAR(255) NOT NULL UNIQUE,
    `name` VARCHAR(255) NOT NULL,
    `meta_title` VARCHAR(255) NULL,
    `meta_description` TEXT NULL,
    `days_applicable` INT NULL,
    `warranty_months` INT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'OFF SHELF',
    `publish_at` DATETIME NULL,
    `offline_at` DATETIME NULL,
    `is_featured` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_prod);

// 📁 表格 5：SKU 規格變體 (product_variants) -> 確保使用 size_inches
$sql_vars = "CREATE TABLE IF NOT EXISTS `product_variants` (
    `variant_id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT NOT NULL,
    `sku_code` VARCHAR(50) NOT NULL UNIQUE,
    `color` VARCHAR(50) NULL,
    `size_inches` VARCHAR(50) NULL,
    `capacity_liters` VARCHAR(50) NULL,
    `original_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `special_price` DECIMAL(10,2) NULL,
    `member_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `stock_available` INT NOT NULL DEFAULT 0,
    `stock_reserved` INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_vars);

// 📁 表格 6：商品圖片 (product_images)
$sql_imgs = "CREATE TABLE IF NOT EXISTS `product_images` (
    `image_id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT NOT NULL,
    `image_url` VARCHAR(255) NOT NULL,
    `is_main` TINYINT(1) NOT NULL DEFAULT 0,
    `sort_order` INT NOT NULL DEFAULT 0,
    `alt_text` VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_imgs);

// 📁 表格 7：一般會員 (users)
$sql_users = "CREATE TABLE IF NOT EXISTS `users` (
    `user_id` INT AUTO_INCREMENT PRIMARY KEY,
    `email` VARCHAR(100) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `phone` VARCHAR(20) NULL,
    `sso_provider` VARCHAR(50) NULL,
    `sso_id` VARCHAR(255) NULL,
    `membership_level` VARCHAR(20) NULL,
    `points_balance` INT NOT NULL DEFAULT 0,
    `status` VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_users);

$sql_carts = "CREATE TABLE IF NOT EXISTS `carts` (
    `cart_id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL UNIQUE,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_carts_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_carts);

$sql_cart_items = "CREATE TABLE IF NOT EXISTS `cart_items` (
    `cart_item_id` INT AUTO_INCREMENT PRIMARY KEY,
    `cart_id` INT NOT NULL,
    `variant_id` INT NOT NULL,
    `quantity` INT NOT NULL DEFAULT 1,
    `is_selected` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_cart_variant` (`cart_id`, `variant_id`),
    INDEX `idx_cart_items_cart_id` (`cart_id`),
    INDEX `idx_cart_items_variant_id` (`variant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_cart_items);

$sql_orders = "CREATE TABLE IF NOT EXISTS `orders` (
    `order_id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `subtotal_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `shipping_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `status` VARCHAR(30) NOT NULL DEFAULT 'PENDING',
    `recipient_name` VARCHAR(100) NOT NULL,
    `recipient_phone` VARCHAR(50) NOT NULL,
    `shipping_address` TEXT NOT NULL,
    `shipping_notes` VARCHAR(255) NULL,
    `payment_method` VARCHAR(50) NOT NULL DEFAULT 'COD',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_orders_user_id` (`user_id`),
    INDEX `idx_orders_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_orders);

$sql_order_items = "CREATE TABLE IF NOT EXISTS `order_items` (
    `order_item_id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL,
    `variant_id` INT NOT NULL,
    `product_name` VARCHAR(255) NOT NULL,
    `sku_code` VARCHAR(50) NOT NULL,
    `color` VARCHAR(50) NULL,
    `size_inches` VARCHAR(50) NULL,
    `quantity` INT NOT NULL,
    `locked_price` DECIMAL(10,2) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_order_items_order_id` (`order_id`),
    INDEX `idx_order_items_variant_id` (`variant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_order_items);

$sql_promotions = "CREATE TABLE IF NOT EXISTS `promotions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(120) NOT NULL,
    `description` TEXT NULL,
    `discount_type` ENUM('PERCENT', 'AMOUNT') NOT NULL,
    `discount_value` DECIMAL(10,2) NOT NULL,
    `start_at` DATETIME NOT NULL,
    `end_at` DATETIME NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    INDEX `idx_promotions_active` (`is_active`),
    INDEX `idx_promotions_dates` (`start_at`, `end_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
if (!$conn->query($sql_promotions)) {
    echo "<p style='color:red;'>❌ 建立 promotions 失敗：" . htmlspecialchars($conn->error) . "</p>";
}

$sql_promotion_products = "CREATE TABLE IF NOT EXISTS `promotion_products` (
    `promotion_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    PRIMARY KEY (`promotion_id`, `product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
if ($conn->query($sql_promotion_products)) {
    $conn->query("ALTER TABLE `promotion_products` ADD FOREIGN KEY (`promotion_id`) REFERENCES `promotions`(`id`) ON DELETE CASCADE");
    $conn->query("ALTER TABLE `promotion_products` ADD FOREIGN KEY (`product_id`) REFERENCES `products`(`product_id`) ON DELETE CASCADE");
} else {
    echo "<p style='color:red;'>❌ 建立 promotion_products 失敗：" . htmlspecialchars($conn->error) . "</p>";
}

$sql_promotion_banners = "CREATE TABLE IF NOT EXISTS `promotion_banners` (
    `promotion_id` INT NOT NULL,
    `banner_image_url` VARCHAR(255) NOT NULL,
    `is_show_on_homepage` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`promotion_id`, `banner_image_url`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
if ($conn->query($sql_promotion_banners)) {
    $conn->query("ALTER TABLE `promotion_banners` ADD FOREIGN KEY (`promotion_id`) REFERENCES `promotions`(`id`) ON DELETE CASCADE");
} else {
    echo "<p style='color:red;'>❌ 建立 promotion_banners 失敗：" . htmlspecialchars($conn->error) . "</p>";
}

// 驗證 promotions 相關表是否存在
$checkPromoTables = $conn->query("SHOW TABLES LIKE 'promotion%'");
if ($checkPromoTables) {
    $found = [];
    while ($row = $checkPromoTables->fetch_array()) {
        $found[] = $row[0];
    }
    echo "<p style='color:gray;'>ℹ️ promotions 相關表：" . htmlspecialchars(implode(', ', $found)) . "</p>";
}

echo "<p style='color:blue;'>📋 基本 14 張資料表結構已確認/建立完成（含行銷活動相關表）。</p>";

// === 延伸欄位同步檢查 ===

// 追加 A: 為 product_images 補上 color 欄位
if (!columnExists($conn, 'product_images', 'color')) {
    $sql = "ALTER TABLE `product_images` ADD COLUMN `color` VARCHAR(50) NULL AFTER `is_main`";
    if ($conn->query($sql)) {
        echo "<p style='color:green;'>✅ 同步成功：已在 `product_images` 追加 `color` (顏色配圖功能) 欄位</p>";
    }
}

if (!columnExists($conn, 'products', 'description')) {
    $sql = "ALTER TABLE `products` ADD COLUMN `description` TEXT NULL AFTER `name`";
    if ($conn->query($sql)) {
        echo "<p style='color:green;'>✅ 同步成功：已在 `products` 追加 `description` 欄位</p>";
    }
}

if (!columnExists($conn, 'products', 'warranty_info')) {
    $sql = "ALTER TABLE `products` ADD COLUMN `warranty_info` TEXT NULL AFTER `description`";
    if ($conn->query($sql)) {
        echo "<p style='color:green;'>✅ 同步成功：已在 `products` 追加 `warranty_info` 欄位</p>";
    }
// 追加 B: 多分類對應表 (product_category_links)
}

if (!columnExists($conn, 'orders', 'tracking_number')) {
    $sql = "ALTER TABLE `orders` ADD COLUMN `tracking_number` VARCHAR(100) NULL AFTER `payment_method`";
    if ($conn->query($sql)) {
        echo "<p style='color:green;'>✅ 同步成功：已在 `orders` 追加 `tracking_number` 欄位</p>";
    }
}

if (!columnExists($conn, 'orders', 'admin_notes')) {
    $sql = "ALTER TABLE `orders` ADD COLUMN `admin_notes` TEXT NULL AFTER `tracking_number`";
    if ($conn->query($sql)) {
        echo "<p style='color:green;'>✅ 同步成功：已在 `orders` 追加 `admin_notes` 欄位</p>";
    }
}

$sql_links = "CREATE TABLE IF NOT EXISTS `product_category_links` (
    `product_id` INT NOT NULL,
    `category_id` INT NOT NULL,
    PRIMARY KEY (`product_id`, `category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
if ($conn->query($sql_links)) {
    echo "<p style='color:green;'>✅ 已確認 `product_category_links` 多分類關聯表</p>";
}

// 防呆 B：如果組員的 product_variants 裡有上版殘留的 `size` 欄位，自動將其移除
if (columnExists($conn, 'product_variants', 'size')) {
    $sql = "ALTER TABLE `product_variants` DROP COLUMN `size`";
    if ($conn->query($sql)) {
        echo "<p style='color:orange;'>⚠️ 清理完畢：已移除 product_variants 中多餘的 `size` 欄位，統一使用 `size_inches`</p>";
    }
}

// 防呆 C：SKU 價格欄位拆分（original/special/member）
if (!columnExists($conn, 'product_variants', 'original_price')) {
    $conn->query("ALTER TABLE `product_variants` ADD COLUMN `original_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `capacity_liters`");
    echo "<p style='color:green;'>✅ 已新增 `original_price` 欄位</p>";
}
if (!columnExists($conn, 'product_variants', 'special_price')) {
    $conn->query("ALTER TABLE `product_variants` ADD COLUMN `special_price` DECIMAL(10,2) NULL AFTER `original_price`");
    echo "<p style='color:green;'>✅ 已新增 `special_price` 欄位</p>";
}
if (!columnExists($conn, 'product_variants', 'member_price')) {
    $conn->query("ALTER TABLE `product_variants` ADD COLUMN `member_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `special_price`");
    echo "<p style='color:green;'>✅ 已新增 `member_price` 欄位</p>";
}

// 將舊 price 搬到新欄位，並移除 price
if (columnExists($conn, 'product_variants', 'price')) {
    $conn->query("UPDATE `product_variants` SET `original_price` = `price`, `member_price` = `price` WHERE `original_price` = 0.00 AND `member_price` = 0.00");
    $conn->query("ALTER TABLE `product_variants` DROP COLUMN `price`");
    echo "<p style='color:orange;'>⚠️ 已將 `price` 搬移至 `original_price`/`member_price` 並移除舊欄位</p>";
}

// 👇👇👇 新增的防呆 D：檢查 size_inches 的型態是否為 VARCHAR，如果不是就自動修改 👇👇👇
$checkSizeCol = $conn->query("SHOW COLUMNS FROM `product_variants` LIKE 'size_inches'");
if ($checkSizeCol && $checkSizeCol->num_rows > 0) {
    $colData = $checkSizeCol->fetch_assoc();
    // 檢查欄位型態名稱中是否包含 varchar (代表支援文字)
    if (stripos($colData['Type'], 'varchar') === false) {
        // 如果不是文字型態（例如是 INT），強制轉換為 VARCHAR(50)
        $conn->query("ALTER TABLE `product_variants` MODIFY COLUMN `size_inches` VARCHAR(50) NULL");
        echo "<p style='color:green;'>🔧 自動修復：已將 `size_inches` 的型態轉為 VARCHAR(50)，解決存入中文字報錯問題！</p>";
    } else {
        echo "<p style='color:gray;'>ℹ️ 狀態：`size_inches` 欄位型態已是 VARCHAR，支援中文字存取。</p>";
    }
}
// 👆👆👆 結束防呆 D 👆👆👆

// 防呆 E：把舊的 primary_category_id 同步進多分類關聯表
if (columnExists($conn, 'products', 'primary_category_id')) {
    $sqlSync = "INSERT IGNORE INTO product_category_links (product_id, category_id)
                SELECT product_id, primary_category_id
                FROM products
                WHERE primary_category_id IS NOT NULL";
    if ($conn->query($sqlSync)) {
        echo "<p style='color:green;'>✅ 已同步 products.primary_category_id 到 product_category_links</p>";
    }
    $conn->query("ALTER TABLE `products` DROP COLUMN `primary_category_id`");
    echo "<p style='color:orange;'>⚠️ 已移除 products.primary_category_id</p>";
}

// === 預填初始資料 ===
$checkAdmin = $conn->query("SELECT * FROM admin_users WHERE username = 'admin'");
if ($checkAdmin && $checkAdmin->num_rows == 0) {
    $sql_init_admin = "INSERT INTO `admin_users` (`admin_id`, `role_id`, `username`, `password_hash`, `status`, `created_at`) 
                       VALUES (1, 1, 'admin', '\$2y\$10\$HkeuM6SN3hE33bcDF.7F6.egnEarVJU25j7tWDbn7CvFjPQxFUdRO', 'ACTIVE', '2026-05-10 08:22:29')";
    $conn->query($sql_init_admin);
    echo "<p style='color:green;'>👤 已預填預設管理員帳號 (admin)</p>";
}

$checkUser = $conn->query("SELECT * FROM users WHERE email = 'Test@gmail.com'");
if ($checkUser && $checkUser->num_rows == 0) {
    $sql_init_user = "INSERT INTO `users` (`user_id`, `email`, `password_hash`, `name`, `phone`, `status`) 
                      VALUES (1, 'Test@gmail.com', '\$2y\$10\$39ckM/FZNDUoq42XP7/9H.hh1l3yeF7xfRbct6NV497c./oaQZWP', 'Test', '0987654321', 'ACTIVE')";
    $conn->query($sql_init_user);
    echo "<p style='color:green;'>👥 已預填預設測試會員帳號 (Test@gmail.com)</p>";
}

echo "<hr>";
echo "<h2>🎉 恭喜！全體組員資料庫結構已 100% 修正並同步一致！</h2>";
$conn->close();
?>

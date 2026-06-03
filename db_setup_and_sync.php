<?php
// db_setup_and_sync.php - 團隊資料庫【全新建立 + 舊版同步】一鍵搞定腳本
// 版本 4.2 (加入優惠券系統)
header("Content-Type: text/html; charset=utf-8");

mysqli_report(MYSQLI_REPORT_OFF);

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
echo "<p style='color:#0ea5e9; font-weight:700;'>執行版本：v4.2</p>";
echo "<hr>";

function columnExists($conn, $table, $column) {
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return $result && $result->num_rows > 0;
}

function tableExists($conn, $table) {
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $result = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
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

// 📁 表格 5：SKU 規格變體 (product_variants)
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

// 📁 表格 5.1：庫存異動紀錄
$sql_inventory_logs = "CREATE TABLE IF NOT EXISTS `inventory_adjustment_logs` (
    `log_id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT NOT NULL,
    `variant_id` INT NULL,
    `sku_code` VARCHAR(50) NULL,
    `size_inches` VARCHAR(50) NULL,
    `color` VARCHAR(50) NULL,
    `old_stock` INT NOT NULL DEFAULT 0,
    `new_stock` INT NOT NULL DEFAULT 0,
    `delta_quantity` INT NOT NULL DEFAULT 0,
    `action_type` VARCHAR(30) NOT NULL DEFAULT 'ADMIN_UPDATE',
    `admin_id` INT NULL,
    `note` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_inventory_logs_product_id` (`product_id`),
    INDEX `idx_inventory_logs_variant_id` (`variant_id`),
    INDEX `idx_inventory_logs_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_inventory_logs);

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

// 📁 表格 8：會員收藏 (user_favorites)
$sql_favorites = "CREATE TABLE IF NOT EXISTS `user_favorites` (
    `favorite_id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_user_product` (`user_id`, `product_id`),
    INDEX `idx_favorites_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_favorites);

$sql_carts = "CREATE TABLE IF NOT EXISTS `carts` (
    `cart_id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL UNIQUE,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_carts_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_carts);

$sql_cart_items = "CREATE TABLE IF NOT EXISTS `cart_items` (
    `cart_item_id` INT AUTO_INCREMENT PRIMARY KEY,
    `cart_id` INT NULL,
    `user_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `variant_id` INT NULL,
    `quantity` INT NOT NULL DEFAULT 1,
    `is_selected` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_cart_user_product_variant` (`user_id`, `product_id`, `variant_id`),
    UNIQUE KEY `uq_cart_variant` (`cart_id`, `variant_id`),
    INDEX `idx_cart_items_cart_id` (`cart_id`),
    INDEX `idx_cart_items_user_id` (`user_id`),
    INDEX `idx_cart_items_product_id` (`product_id`),
    INDEX `idx_cart_items_variant_id` (`variant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_cart_items);

$sql_orders = "CREATE TABLE IF NOT EXISTS `orders` (
    `order_id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_number` VARCHAR(50) NULL,
    `user_id` INT NOT NULL,
    `subtotal_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `shipping_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `status` ENUM('PENDING','PROCESSING','SHIPPED','DELIVERED','COMPLETED','CANCELLED') NOT NULL DEFAULT 'PENDING',
    `recipient_name` VARCHAR(100) NOT NULL,
    `recipient_phone` VARCHAR(50) NOT NULL,
    `shipping_address` TEXT NOT NULL,
    `shipping_notes` VARCHAR(255) NULL,
    `payment_method` VARCHAR(50) NOT NULL DEFAULT 'COD',
    `cardholder_name` VARCHAR(100) NULL,
    `card_brand` VARCHAR(30) NULL,
    `card_last4` VARCHAR(4) NULL,
    `card_expiry_month` VARCHAR(2) NULL,
    `card_expiry_year` VARCHAR(4) NULL,
    `inventory_deducted` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_orders_user_id` (`user_id`),
    INDEX `idx_orders_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_orders);

// =====================================
// 📁 表格 9：優惠券系統全套建立 (包含升級版)
// =====================================
$sql_coupons = "CREATE TABLE IF NOT EXISTS `coupons` (
    `coupon_id` INT AUTO_INCREMENT PRIMARY KEY,
    `coupon_name` VARCHAR(100) NOT NULL,
    `coupon_code` VARCHAR(50) NULL UNIQUE,
    `coupon_type` ENUM('DISCOUNT', 'REDUCE', 'POINTS') NOT NULL DEFAULT 'DISCOUNT',
    `coupon_value` DECIMAL(10,2) NOT NULL,
    `min_order_amount` DECIMAL(10,2) NULL DEFAULT 0,
    `target_membership` VARCHAR(50) NULL,
    `usage_limit` INT NULL DEFAULT 0,
    `used_count` INT NOT NULL DEFAULT 0,
    `start_at` DATETIME NULL,
    `end_at` DATETIME NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_coupons);

// 💡 動態補足 target_membership 欄位 (防呆)
if (!columnExists($conn, 'coupons', 'target_membership')) {
    $conn->query("ALTER TABLE `coupons` ADD COLUMN `target_membership` VARCHAR(50) NULL AFTER `min_order_amount`");
}

$sql_distributions = "CREATE TABLE IF NOT EXISTS `coupon_distributions` (
    `distribution_id` INT AUTO_INCREMENT PRIMARY KEY,
    `coupon_id` INT NOT NULL,
    `target_type` VARCHAR(20) NOT NULL DEFAULT 'SINGLE',
    `user_id` INT NULL,
    `sent_by_admin_id` INT NULL,
    `quantity` INT NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_distributions);

$sql_uses = "CREATE TABLE IF NOT EXISTS `coupon_code_uses` (
    `coupon_code_use_id` INT AUTO_INCREMENT PRIMARY KEY,
    `coupon_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `coupon_code` VARCHAR(50) NULL,
    `used_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_uses);

if (!columnExists($conn, 'orders', 'coupon_id')) {
    $conn->query("ALTER TABLE `orders` ADD COLUMN `coupon_id` INT NULL AFTER `user_id`");
}


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

$sql_customer_tickets = "CREATE TABLE IF NOT EXISTS `customer_tickets` (
    `ticket_id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `product_id` INT NULL,
    `status` ENUM('OPEN','ANSWERED','CLOSED') NOT NULL DEFAULT 'OPEN',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_customer_tickets_user_id` (`user_id`),
    INDEX `idx_customer_tickets_status` (`status`),
    INDEX `idx_customer_tickets_product_id` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_customer_tickets);

$sql_ticket_messages = "CREATE TABLE IF NOT EXISTS `ticket_messages` (
    `message_id` INT AUTO_INCREMENT PRIMARY KEY,
    `ticket_id` INT NOT NULL,
    `sender_type` ENUM('USER','ADMIN') NOT NULL,
    `sender_id` INT NOT NULL,
    `product_id` INT NULL,
    `message_text` TEXT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ticket_messages_ticket_id` (`ticket_id`),
    INDEX `idx_ticket_messages_product_id` (`product_id`),
    INDEX `idx_ticket_messages_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_ticket_messages);

echo "<p style='color:blue;'>📋 優惠券系統、基本資料表與相關防呆擴充已建立完成。</p>";

// === 預填初始資料 ===
$checkAdmin = $conn->query("SELECT * FROM admin_users WHERE username = 'admin'");
if ($checkAdmin && $checkAdmin->num_rows == 0) {
    $sql_init_admin = "INSERT INTO `admin_users` (`admin_id`, `role_id`, `username`, `password_hash`, `status`, `created_at`) 
                       VALUES (1, 1, 'admin', '\$2y\$10\$HkeuM6SN3hE33bcDF.7F6.egnEarVJU25j7tWDbn7CvFjPQxFUdRO', 'ACTIVE', '2026-05-10 08:22:29')";
    $conn->query($sql_init_admin);
    echo "<p style='color:green;'>👤 已預填預設管理員帳號 (admin)</p>";
}

echo "<hr>";
echo "<h2>🎉 恭喜！全體組員資料庫結構已 100% 修正並同步一致！</h2>";
$conn->close();
?>
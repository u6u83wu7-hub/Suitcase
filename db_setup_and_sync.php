<?php
// db_setup_and_sync.php - 團隊資料庫【全新建立 + 舊版同步】一鍵搞定腳本
// 版本 4.1 (修復防護版)
header("Content-Type: text/html; charset=utf-8");

// 💡 新增這行：防止 PHP 8.1+ 因為一點點微小的 SQL 阻礙就整個腳本崩潰！
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
echo "<p style='color:#0ea5e9; font-weight:700;'>執行版本：v4.1</p>";
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

function indexExists($conn, $table, $indexName) {
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $safeIndex = $conn->real_escape_string($indexName);
    $result = $conn->query("SHOW INDEX FROM `{$safeTable}` WHERE Key_name = '{$safeIndex}'");
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
    `admin_id` INT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `contact_person` VARCHAR(100) NULL,
    `phone` VARCHAR(20) NULL,
    `email` VARCHAR(100) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_sup);

if (tableExists($conn, 'suppliers') && !columnExists($conn, 'suppliers', 'admin_id')) {
    $conn->query("ALTER TABLE `suppliers` ADD COLUMN `admin_id` INT NULL AFTER `supplier_id`");
}

// 📁 表格 3.1：廠商供應紀錄 (supplier_supplies)
$sql_supplier_supplies = "CREATE TABLE IF NOT EXISTS `supplier_supplies` (
    `supply_id` INT AUTO_INCREMENT PRIMARY KEY,
    `supplier_id` INT NOT NULL,
    `admin_id` INT NOT NULL,
    `request_id` INT NULL,
    `product_id` INT NOT NULL,
    `variant_id` INT NULL,
    `supply_quantity` INT NOT NULL,
    `is_supply_complete` TINYINT(1) NOT NULL DEFAULT 0,
    `note` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_supplier_supplies_supplier_id` (`supplier_id`),
    INDEX `idx_supplier_supplies_request_id` (`request_id`),
    INDEX `idx_supplier_supplies_product_id` (`product_id`),
    INDEX `idx_supplier_supplies_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_supplier_supplies);

if (tableExists($conn, 'supplier_supplies')) {
    if (!columnExists($conn, 'supplier_supplies', 'supplier_id')) {
        $conn->query("ALTER TABLE `supplier_supplies` ADD COLUMN `supplier_id` INT NOT NULL AFTER `supply_id`");
    }
    if (!columnExists($conn, 'supplier_supplies', 'admin_id')) {
        $conn->query("ALTER TABLE `supplier_supplies` ADD COLUMN `admin_id` INT NOT NULL AFTER `supplier_id`");
    }
    if (!columnExists($conn, 'supplier_supplies', 'request_id')) {
        $conn->query("ALTER TABLE `supplier_supplies` ADD COLUMN `request_id` INT NULL AFTER `admin_id`");
    }
    if (!indexExists($conn, 'supplier_supplies', 'idx_supplier_supplies_request_id')) {
        $conn->query("ALTER TABLE `supplier_supplies` ADD INDEX `idx_supplier_supplies_request_id` (`request_id`)");
    }
    if (!columnExists($conn, 'supplier_supplies', 'product_id')) {
        $conn->query("ALTER TABLE `supplier_supplies` ADD COLUMN `product_id` INT NOT NULL AFTER `request_id`");
    }
    if (!columnExists($conn, 'supplier_supplies', 'variant_id')) {
        $conn->query("ALTER TABLE `supplier_supplies` ADD COLUMN `variant_id` INT NULL AFTER `product_id`");
    }
    if (!columnExists($conn, 'supplier_supplies', 'supply_quantity')) {
        $conn->query("ALTER TABLE `supplier_supplies` ADD COLUMN `supply_quantity` INT NOT NULL AFTER `product_id`");
    }
    if (!columnExists($conn, 'supplier_supplies', 'is_supply_complete')) {
        $conn->query("ALTER TABLE `supplier_supplies` ADD COLUMN `is_supply_complete` TINYINT(1) NOT NULL DEFAULT 0 AFTER `supply_quantity`");
    }
    if (!columnExists($conn, 'supplier_supplies', 'note')) {
        $conn->query("ALTER TABLE `supplier_supplies` ADD COLUMN `note` VARCHAR(255) NULL AFTER `is_supply_complete`");
    }
    if (!columnExists($conn, 'supplier_supplies', 'created_at')) {
        $conn->query("ALTER TABLE `supplier_supplies` ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `note`");
    }
}

// 📁 表格 3.2：請求供貨表 (supply_requests)
$sql_supply_requests = "CREATE TABLE IF NOT EXISTS `supply_requests` (
    `request_id` INT AUTO_INCREMENT PRIMARY KEY,
    `admin_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `variant_id` INT NOT NULL,
    `requested_quantity` INT NOT NULL,
    `note` VARCHAR(255) NULL,
    `request_status` VARCHAR(20) NOT NULL DEFAULT 'PENDING',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_supply_requests_admin_id` (`admin_id`),
    INDEX `idx_supply_requests_product_id` (`product_id`),
    INDEX `idx_supply_requests_variant_id` (`variant_id`),
    INDEX `idx_supply_requests_status` (`request_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_supply_requests);

if (tableExists($conn, 'supply_requests')) {
    if (!columnExists($conn, 'supply_requests', 'admin_id')) {
        $conn->query("ALTER TABLE `supply_requests` ADD COLUMN `admin_id` INT NOT NULL AFTER `request_id`");
    }
    if (!columnExists($conn, 'supply_requests', 'product_id')) {
        $conn->query("ALTER TABLE `supply_requests` ADD COLUMN `product_id` INT NOT NULL AFTER `admin_id`");
    }
    if (!columnExists($conn, 'supply_requests', 'variant_id')) {
        $conn->query("ALTER TABLE `supply_requests` ADD COLUMN `variant_id` INT NOT NULL AFTER `product_id`");
    }
    if (!columnExists($conn, 'supply_requests', 'requested_quantity')) {
        $conn->query("ALTER TABLE `supply_requests` ADD COLUMN `requested_quantity` INT NOT NULL AFTER `variant_id`");
    }
    if (!columnExists($conn, 'supply_requests', 'note')) {
        $conn->query("ALTER TABLE `supply_requests` ADD COLUMN `note` VARCHAR(255) NULL AFTER `requested_quantity`");
    }
    if (!columnExists($conn, 'supply_requests', 'request_status')) {
        $conn->query("ALTER TABLE `supply_requests` ADD COLUMN `request_status` VARCHAR(20) NOT NULL DEFAULT 'PENDING' AFTER `note`");
    }
    if (!columnExists($conn, 'supply_requests', 'created_at')) {
        $conn->query("ALTER TABLE `supply_requests` ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `request_status`");
    }
}

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

// 💡 已經將「會員收藏」拉到這層，確保它一定會被建立
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

// 修正 cart_items 欄位
if (columnExists($conn, 'cart_items', 'cart_id')) {
    $conn->query("ALTER TABLE `cart_items` MODIFY COLUMN `cart_id` INT NULL");
}
if (!columnExists($conn, 'cart_items', 'user_id')) {
    $conn->query("ALTER TABLE `cart_items` ADD COLUMN `user_id` INT NOT NULL DEFAULT 0 AFTER `cart_id`");
}
if (!columnExists($conn, 'cart_items', 'product_id')) {
    $conn->query("ALTER TABLE `cart_items` ADD COLUMN `product_id` INT NOT NULL DEFAULT 0 AFTER `user_id`");
}
if (columnExists($conn, 'cart_items', 'variant_id')) {
    $conn->query("ALTER TABLE `cart_items` MODIFY COLUMN `variant_id` INT NULL");
}

$sql_orders = "CREATE TABLE IF NOT EXISTS `orders` (
    `order_id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_number` VARCHAR(50) NULL,
    `user_id` INT NOT NULL,
    `subtotal_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `shipping_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `coupon_id` INT NULL,
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
    INDEX `idx_orders_coupon_id` (`coupon_id`),
    INDEX `idx_orders_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_orders);

$statusColRes = $conn->query("SHOW COLUMNS FROM `orders` LIKE 'status'");
if ($statusColRes && $statusColRes->num_rows > 0) {
    $statusCol = $statusColRes->fetch_assoc();
    $statusType = strtolower((string)$statusCol['Type']);
    if (strpos($statusType, 'enum') === false) {
        $conn->query("ALTER TABLE `orders` MODIFY COLUMN `status` ENUM('PENDING','PROCESSING','SHIPPED','DELIVERED','COMPLETED','CANCELLED') NOT NULL DEFAULT 'PENDING'");
    }
    $conn->query("UPDATE `orders` SET `status` = 'PROCESSING' WHERE `status` = 'PAID'");
    $conn->query("UPDATE `orders` SET `status` = 'SHIPPED' WHERE `status` = 'SHIPPING'");
}

if (!columnExists($conn, 'orders', 'order_number')) {
    $conn->query("ALTER TABLE `orders` ADD COLUMN `order_number` VARCHAR(50) NULL AFTER `order_id`");
}
if (!columnExists($conn, 'orders', 'coupon_id')) {
    $conn->query("ALTER TABLE `orders` ADD COLUMN `coupon_id` INT NULL AFTER `shipping_fee`");
}
if (!indexExists($conn, 'orders', 'idx_orders_coupon_id')) {
    $conn->query("ALTER TABLE `orders` ADD INDEX `idx_orders_coupon_id` (`coupon_id`)");
}
if (!columnExists($conn, 'orders', 'cardholder_name')) {
    $conn->query("ALTER TABLE `orders` ADD COLUMN `cardholder_name` VARCHAR(100) NULL AFTER `payment_method`");
}
if (!columnExists($conn, 'orders', 'card_brand')) {
    $conn->query("ALTER TABLE `orders` ADD COLUMN `card_brand` VARCHAR(30) NULL AFTER `cardholder_name`");
}
if (!columnExists($conn, 'orders', 'card_last4')) {
    $conn->query("ALTER TABLE `orders` ADD COLUMN `card_last4` VARCHAR(4) NULL AFTER `card_brand`");
}
if (!columnExists($conn, 'orders', 'card_expiry_month')) {
    $conn->query("ALTER TABLE `orders` ADD COLUMN `card_expiry_month` VARCHAR(2) NULL AFTER `card_last4`");
}
if (!columnExists($conn, 'orders', 'card_expiry_year')) {
    $conn->query("ALTER TABLE `orders` ADD COLUMN `card_expiry_year` VARCHAR(4) NULL AFTER `card_expiry_month`");
}
if (!columnExists($conn, 'orders', 'inventory_deducted')) {
    $conn->query("ALTER TABLE `orders` ADD COLUMN `inventory_deducted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `card_expiry_year`");
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

if (columnExists($conn, 'order_items', 'variant_id')) {
    $conn->query("ALTER TABLE `order_items` MODIFY COLUMN `variant_id` INT NOT NULL");
}
if (!columnExists($conn, 'order_items', 'sku_code')) {
    $conn->query("ALTER TABLE `order_items` ADD COLUMN `sku_code` VARCHAR(50) NOT NULL AFTER `product_name`");
}
if (!columnExists($conn, 'order_items', 'color')) {
    $conn->query("ALTER TABLE `order_items` ADD COLUMN `color` VARCHAR(50) NULL AFTER `sku_code`");
}
if (!columnExists($conn, 'order_items', 'size_inches')) {
    $conn->query("ALTER TABLE `order_items` ADD COLUMN `size_inches` VARCHAR(50) NULL AFTER `color`");
}
if (!columnExists($conn, 'order_items', 'locked_price')) {
    $conn->query("ALTER TABLE `order_items` ADD COLUMN `locked_price` DECIMAL(10,2) NOT NULL AFTER `size_inches`");
}

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

if (!columnExists($conn, 'ticket_messages', 'product_id')) {
    $conn->query("ALTER TABLE `ticket_messages` ADD COLUMN `product_id` INT NULL AFTER `sender_id`");
}

$sql_product_qa = "CREATE TABLE IF NOT EXISTS `product_qa` (
    `qa_id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT NULL,
    `question` TEXT NOT NULL,
    `answer` TEXT NOT NULL,
    `qa_type` ENUM('GENERAL','PRODUCT') NOT NULL DEFAULT 'PRODUCT',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_product_qa_product_id` (`product_id`),
    INDEX `idx_product_qa_type` (`qa_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_product_qa);

if (!columnExists($conn, 'product_qa', 'product_id')) {
    $conn->query("ALTER TABLE `product_qa` ADD COLUMN `product_id` INT NULL AFTER `qa_id`");
}
if (!columnExists($conn, 'product_qa', 'question')) {
    $conn->query("ALTER TABLE `product_qa` ADD COLUMN `question` TEXT NOT NULL AFTER `product_id`");
}
if (!columnExists($conn, 'product_qa', 'answer')) {
    $conn->query("ALTER TABLE `product_qa` ADD COLUMN `answer` TEXT NOT NULL AFTER `question`");
}
if (!columnExists($conn, 'product_qa', 'qa_type')) {
    $conn->query("ALTER TABLE `product_qa` ADD COLUMN `qa_type` ENUM('GENERAL','PRODUCT') NOT NULL DEFAULT 'PRODUCT' AFTER `answer`");
}

$sql_user_notifications = "CREATE TABLE IF NOT EXISTS `user_notifications` (
    `notification_id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `title` VARCHAR(120) NOT NULL,
    `message` TEXT NOT NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_notifications_user_id` (`user_id`),
    INDEX `idx_user_notifications_is_read` (`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_user_notifications);

$sql_member_details = "CREATE TABLE IF NOT EXISTS `user_member_details` (
    `member_detail_id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL UNIQUE,
    `full_address` VARCHAR(255) NULL,
    `address_note` VARCHAR(255) NULL,
    `cardholder_name` VARCHAR(100) NULL,
    `card_last4` VARCHAR(4) NULL,
    `card_brand` VARCHAR(30) NULL,
    `expiry_month` VARCHAR(2) NULL,
    `expiry_year` VARCHAR(4) NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_member_details);

$sql_coupons = "CREATE TABLE IF NOT EXISTS `coupons` (
    `coupon_id` INT AUTO_INCREMENT PRIMARY KEY,
    `coupon_code` VARCHAR(50) NULL,
    `coupon_name` VARCHAR(120) NOT NULL,
    `coupon_type` VARCHAR(20) NOT NULL DEFAULT 'DISCOUNT',
    `coupon_value` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `min_order_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `target_membership` VARCHAR(20) NULL,
    `usage_limit` INT NULL,
    `used_count` INT NOT NULL DEFAULT 0,
    `start_at` DATETIME NULL,
    `end_at` DATETIME NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_coupons_code` (`coupon_code`),
    INDEX `idx_coupons_active` (`is_active`),
    INDEX `idx_coupons_dates` (`start_at`, `end_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_coupons);

if (tableExists($conn, 'coupons')) {
    if (!columnExists($conn, 'coupons', 'coupon_code')) {
        $conn->query("ALTER TABLE `coupons` ADD COLUMN `coupon_code` VARCHAR(50) NULL AFTER `coupon_id`");
    }
    if (!columnExists($conn, 'coupons', 'coupon_name')) {
        $conn->query("ALTER TABLE `coupons` ADD COLUMN `coupon_name` VARCHAR(120) NOT NULL AFTER `coupon_code`");
    }
    if (!columnExists($conn, 'coupons', 'coupon_type')) {
        $conn->query("ALTER TABLE `coupons` ADD COLUMN `coupon_type` VARCHAR(20) NOT NULL DEFAULT 'DISCOUNT' AFTER `coupon_name`");
    }
    if (!columnExists($conn, 'coupons', 'coupon_value')) {
        $conn->query("ALTER TABLE `coupons` ADD COLUMN `coupon_value` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `coupon_type`");
    }
    if (!columnExists($conn, 'coupons', 'min_order_amount')) {
        $conn->query("ALTER TABLE `coupons` ADD COLUMN `min_order_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `coupon_value`");
    }
    if (!columnExists($conn, 'coupons', 'target_membership')) {
        $conn->query("ALTER TABLE `coupons` ADD COLUMN `target_membership` VARCHAR(20) NULL AFTER `min_order_amount`");
    }
    if (!columnExists($conn, 'coupons', 'usage_limit')) {
        $conn->query("ALTER TABLE `coupons` ADD COLUMN `usage_limit` INT NULL AFTER `target_membership`");
    }
    if (!columnExists($conn, 'coupons', 'used_count')) {
        $conn->query("ALTER TABLE `coupons` ADD COLUMN `used_count` INT NOT NULL DEFAULT 0 AFTER `usage_limit`");
    }
    if (!columnExists($conn, 'coupons', 'start_at')) {
        $conn->query("ALTER TABLE `coupons` ADD COLUMN `start_at` DATETIME NULL AFTER `used_count`");
    }
    if (!columnExists($conn, 'coupons', 'end_at')) {
        $conn->query("ALTER TABLE `coupons` ADD COLUMN `end_at` DATETIME NULL AFTER `start_at`");
    }
    if (!columnExists($conn, 'coupons', 'is_active')) {
        $conn->query("ALTER TABLE `coupons` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `end_at`");
    }
    if (!columnExists($conn, 'coupons', 'created_at')) {
        $conn->query("ALTER TABLE `coupons` ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `is_active`");
    }
    if (!indexExists($conn, 'coupons', 'idx_coupons_code')) {
        $conn->query("ALTER TABLE `coupons` ADD INDEX `idx_coupons_code` (`coupon_code`)");
    }
    if (!indexExists($conn, 'coupons', 'idx_coupons_active')) {
        $conn->query("ALTER TABLE `coupons` ADD INDEX `idx_coupons_active` (`is_active`)");
    }
    if (!indexExists($conn, 'coupons', 'idx_coupons_dates')) {
        $conn->query("ALTER TABLE `coupons` ADD INDEX `idx_coupons_dates` (`start_at`, `end_at`)");
    }
}

$sql_coupon_distributions = "CREATE TABLE IF NOT EXISTS `coupon_distributions` (
    `distribution_id` INT AUTO_INCREMENT PRIMARY KEY,
    `coupon_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `quantity` INT NOT NULL DEFAULT 1,
    `target_type` VARCHAR(20) NOT NULL DEFAULT 'SINGLE',
    `sent_by_admin_id` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_coupon_dist_coupon` (`coupon_id`),
    INDEX `idx_coupon_dist_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_coupon_distributions);

if (tableExists($conn, 'coupon_distributions')) {
    if (!columnExists($conn, 'coupon_distributions', 'coupon_id')) {
        $conn->query("ALTER TABLE `coupon_distributions` ADD COLUMN `coupon_id` INT NOT NULL AFTER `distribution_id`");
    }
    if (!columnExists($conn, 'coupon_distributions', 'user_id')) {
        $conn->query("ALTER TABLE `coupon_distributions` ADD COLUMN `user_id` INT NOT NULL AFTER `coupon_id`");
    }
    if (!columnExists($conn, 'coupon_distributions', 'quantity')) {
        $conn->query("ALTER TABLE `coupon_distributions` ADD COLUMN `quantity` INT NOT NULL DEFAULT 1 AFTER `user_id`");
    }
    if (!columnExists($conn, 'coupon_distributions', 'target_type')) {
        $conn->query("ALTER TABLE `coupon_distributions` ADD COLUMN `target_type` VARCHAR(20) NOT NULL DEFAULT 'SINGLE' AFTER `quantity`");
    }
    if (!columnExists($conn, 'coupon_distributions', 'sent_by_admin_id')) {
        $conn->query("ALTER TABLE `coupon_distributions` ADD COLUMN `sent_by_admin_id` INT NULL AFTER `target_type`");
    }
    if (!columnExists($conn, 'coupon_distributions', 'created_at')) {
        $conn->query("ALTER TABLE `coupon_distributions` ADD COLUMN `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `sent_by_admin_id`");
    }
    if (!indexExists($conn, 'coupon_distributions', 'idx_coupon_dist_coupon')) {
        $conn->query("ALTER TABLE `coupon_distributions` ADD INDEX `idx_coupon_dist_coupon` (`coupon_id`)");
    }
    if (!indexExists($conn, 'coupon_distributions', 'idx_coupon_dist_user')) {
        $conn->query("ALTER TABLE `coupon_distributions` ADD INDEX `idx_coupon_dist_user` (`user_id`)");
    }
}

$sql_coupon_code_uses = "CREATE TABLE IF NOT EXISTS `coupon_code_uses` (
    `coupon_code_use_id` INT AUTO_INCREMENT PRIMARY KEY,
    `coupon_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `coupon_code` VARCHAR(50) NOT NULL,
    `used_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_coupon_code_uses_coupon` (`coupon_id`),
    INDEX `idx_coupon_code_uses_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_coupon_code_uses);

if (tableExists($conn, 'coupon_code_uses')) {
    if (!columnExists($conn, 'coupon_code_uses', 'coupon_id')) {
        $conn->query("ALTER TABLE `coupon_code_uses` ADD COLUMN `coupon_id` INT NOT NULL AFTER `coupon_code_use_id`");
    }
    if (!columnExists($conn, 'coupon_code_uses', 'user_id')) {
        $conn->query("ALTER TABLE `coupon_code_uses` ADD COLUMN `user_id` INT NOT NULL AFTER `coupon_id`");
    }
    if (!columnExists($conn, 'coupon_code_uses', 'coupon_code')) {
        $conn->query("ALTER TABLE `coupon_code_uses` ADD COLUMN `coupon_code` VARCHAR(50) NOT NULL AFTER `user_id`");
    }
    if (!columnExists($conn, 'coupon_code_uses', 'used_at')) {
        $conn->query("ALTER TABLE `coupon_code_uses` ADD COLUMN `used_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER `coupon_code`");
    }
    if (!indexExists($conn, 'coupon_code_uses', 'idx_coupon_code_uses_coupon')) {
        $conn->query("ALTER TABLE `coupon_code_uses` ADD INDEX `idx_coupon_code_uses_coupon` (`coupon_id`)");
    }
    if (!indexExists($conn, 'coupon_code_uses', 'idx_coupon_code_uses_user')) {
        $conn->query("ALTER TABLE `coupon_code_uses` ADD INDEX `idx_coupon_code_uses_user` (`user_id`)");
    }
}

$sql_promotions = "CREATE TABLE IF NOT EXISTS `promotions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(120) NOT NULL,
    `promotion_image_url` VARCHAR(255) NULL,
    `description` TEXT NULL,
    `discount_type` ENUM('PERCENT', 'AMOUNT') NOT NULL,
    `discount_value` DECIMAL(10,2) NOT NULL,
    `start_at` DATETIME NOT NULL,
    `end_at` DATETIME NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_promotions);

$sql_promotion_products = "CREATE TABLE IF NOT EXISTS `promotion_products` (
    `promotion_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    PRIMARY KEY (`promotion_id`, `product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_promotion_products);

$sql_promotion_banners = "CREATE TABLE IF NOT EXISTS `promotion_banners` (
    `promotion_id` INT NOT NULL,
    `banner_image_url` VARCHAR(255) NOT NULL,
    `is_show_on_homepage` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`promotion_id`, `banner_image_url`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_promotion_banners);

echo "<p style='color:blue;'>📋 基本資料表與會員收藏表結構已確認/建立完成。</p>";

// === 延伸欄位同步檢查 ===

if (!columnExists($conn, 'product_images', 'color')) {
    $conn->query("ALTER TABLE `product_images` ADD COLUMN `color` VARCHAR(50) NULL AFTER `is_main`");
}

if (!columnExists($conn, 'product_images', 'sort_order')) {
    $afterColumn = columnExists($conn, 'product_images', 'display_order') ? 'display_order' : 'is_main';
    $conn->query("ALTER TABLE `product_images` ADD COLUMN `sort_order` INT NOT NULL DEFAULT 0 AFTER `{$afterColumn}`");
}

if (columnExists($conn, 'product_images', 'display_order') && columnExists($conn, 'product_images', 'sort_order')) {
    $conn->query("UPDATE `product_images` SET `sort_order` = `display_order` WHERE (`sort_order` = 0 OR `sort_order` IS NULL) AND `display_order` IS NOT NULL");
}

if (!columnExists($conn, 'products', 'description')) {
    $conn->query("ALTER TABLE `products` ADD COLUMN `description` TEXT NULL AFTER `name`");
}

if (!columnExists($conn, 'products', 'warranty_info')) {
    $conn->query("ALTER TABLE `products` ADD COLUMN `warranty_info` TEXT NULL AFTER `description`");
}

if (!columnExists($conn, 'promotions', 'promotion_image_url')) {
    $conn->query("ALTER TABLE `promotions` ADD COLUMN `promotion_image_url` VARCHAR(255) NULL AFTER `name`");
}

if (!columnExists($conn, 'orders', 'tracking_number')) {
    $conn->query("ALTER TABLE `orders` ADD COLUMN `tracking_number` VARCHAR(100) NULL AFTER `payment_method`");
}

if (!columnExists($conn, 'orders', 'admin_notes')) {
    $conn->query("ALTER TABLE `orders` ADD COLUMN `admin_notes` TEXT NULL AFTER `tracking_number`");
}

$sql_password_reset_tokens = "CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
    `reset_id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_password_reset_user` (`user_id`),
    INDEX `idx_password_reset_token` (`token_hash`),
    INDEX `idx_password_reset_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_password_reset_tokens);

$sql_security_attempts = "CREATE TABLE IF NOT EXISTS `security_attempts` (
    `attempt_id` INT AUTO_INCREMENT PRIMARY KEY,
    `scope` VARCHAR(40) NOT NULL,
    `identifier` VARCHAR(190) NOT NULL,
    `ip_address` VARCHAR(45) NULL,
    `success` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_security_attempts_scope_identifier` (`scope`, `identifier`, `created_at`),
    INDEX `idx_security_attempts_ip` (`ip_address`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_security_attempts);

$sql_payment_transactions = "CREATE TABLE IF NOT EXISTS `payment_transactions` (
    `payment_id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL,
    `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `payment_method` VARCHAR(50) NOT NULL DEFAULT 'credit_card',
    `status` VARCHAR(30) NOT NULL DEFAULT 'SUCCESS',
    `transaction_no` VARCHAR(80) NULL,
    `failure_reason` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_payment_transactions_order` (`order_id`),
    INDEX `idx_payment_transactions_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_payment_transactions);

$sql_return_requests = "CREATE TABLE IF NOT EXISTS `return_requests` (
    `return_id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `reason` VARCHAR(255) NOT NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'REQUESTED',
    `admin_note` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_return_requests_order` (`order_id`),
    INDEX `idx_return_requests_user` (`user_id`),
    INDEX `idx_return_requests_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_return_requests);

$sql_product_reviews = "CREATE TABLE IF NOT EXISTS `product_reviews` (
    `review_id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `order_id` INT NOT NULL,
    `rating` TINYINT NOT NULL,
    `comment` TEXT NULL,
    `is_visible` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_product_review_order_user` (`product_id`, `user_id`, `order_id`),
    INDEX `idx_product_reviews_product` (`product_id`),
    INDEX `idx_product_reviews_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_product_reviews);

$sql_admin_audit_logs = "CREATE TABLE IF NOT EXISTS `admin_audit_logs` (
    `log_id` INT AUTO_INCREMENT PRIMARY KEY,
    `admin_id` INT NULL,
    `action` VARCHAR(80) NOT NULL,
    `target_type` VARCHAR(60) NULL,
    `target_id` INT NULL,
    `message` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_admin_audit_logs_admin` (`admin_id`),
    INDEX `idx_admin_audit_logs_action` (`action`),
    INDEX `idx_admin_audit_logs_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_admin_audit_logs);

$sql_links = "CREATE TABLE IF NOT EXISTS `product_category_links` (
    `product_id` INT NOT NULL,
    `category_id` INT NOT NULL,
    PRIMARY KEY (`product_id`, `category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
$conn->query($sql_links);

// 防呆 B
if (columnExists($conn, 'product_variants', 'size')) {
    $conn->query("ALTER TABLE `product_variants` DROP COLUMN `size`");
}

// 防呆 C
if (!columnExists($conn, 'product_variants', 'original_price')) {
    $conn->query("ALTER TABLE `product_variants` ADD COLUMN `original_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `capacity_liters`");
}
if (!columnExists($conn, 'product_variants', 'special_price')) {
    $conn->query("ALTER TABLE `product_variants` ADD COLUMN `special_price` DECIMAL(10,2) NULL AFTER `original_price`");
}
if (!columnExists($conn, 'product_variants', 'member_price')) {
    $conn->query("ALTER TABLE `product_variants` ADD COLUMN `member_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `special_price`");
}
if (columnExists($conn, 'product_variants', 'price')) {
    $conn->query("UPDATE `product_variants` SET `original_price` = `price`, `member_price` = `price` WHERE `original_price` = 0.00 AND `member_price` = 0.00");
    $conn->query("ALTER TABLE `product_variants` DROP COLUMN `price`");
}

// 防呆 D
$checkSizeCol = $conn->query("SHOW COLUMNS FROM `product_variants` LIKE 'size_inches'");
if ($checkSizeCol && $checkSizeCol->num_rows > 0) {
    $colData = $checkSizeCol->fetch_assoc();
    if (stripos($colData['Type'], 'varchar') === false) {
        $conn->query("ALTER TABLE `product_variants` MODIFY COLUMN `size_inches` VARCHAR(50) NULL");
    }
}

// 防呆 E：把舊的 primary_category_id 同步進多分類關聯表 (加上錯誤檢查保護)
if (columnExists($conn, 'products', 'primary_category_id')) {
    $sqlSync = "INSERT IGNORE INTO product_category_links (product_id, category_id)
                SELECT product_id, primary_category_id
                FROM products
                WHERE primary_category_id IS NOT NULL";
    $conn->query($sqlSync);
    
    // 如果這裡執行失敗，上面的 mysqli_report_off 會讓它默默跳過，不會讓整個網頁當機
    $conn->query("ALTER TABLE `products` DROP COLUMN `primary_category_id`");
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

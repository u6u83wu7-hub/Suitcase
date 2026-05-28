<?php
// backend_action.php - 统一路由器
// 分發請求到各个 action 處理器
//版本4
require_once __DIR__ . '/auth_guard.php';

// 資料庫連線
$conn = new mysqli("localhost", "root", "", "all_pass_db");

if ($conn->connect_error) {
    die("資料庫連線失敗：" . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: backend.php?page=products");
    exit();
}

// 辅助函数
function goProducts($message = '') {
    $safe = addslashes($message);
    echo "<script>" . ($message !== '' ? "alert('{$safe}');" : '') . "location.href='backend.php?page=products';</script>";
    exit();
}

function goCategories($message = '') {
    $safe = addslashes($message);
    echo "<script>" . ($message !== '' ? "alert('{$safe}');" : '') . "location.href='backend.php?page=categories';</script>";
    exit();
}

function goMarketing($message = '', $extraParams = []) {
    $params = ['page' => 'marketing'];
    if ($message !== '') {
        $params['error'] = $message;
    }
    if (is_string($extraParams) && $extraParams !== '') {
        $params['open'] = $extraParams;
    } elseif (is_array($extraParams) && !empty($extraParams)) {
        $params = array_merge($params, $extraParams);
    }
    header('Location: backend.php?' . http_build_query($params));
    exit();
}

function tableColumns($conn, $tableName) {
    $columns = [];
    $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    $res = $conn->query("SHOW COLUMNS FROM `{$tableName}`");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $columns[] = $row['Field'];
        }
    }
    return $columns;
}

function boolPost($key) {
    return isset($_POST[$key]) && (string)$_POST[$key] === '1';
}

// 获取 action 和必要的列信息
$action = isset($_POST['action']) ? trim($_POST['action']) : 'unknown';
$productColumns = tableColumns($conn, 'products');
$variantColumns = tableColumns($conn, 'product_variants');
$imageColumns = tableColumns($conn, 'product_images');

// 路由表：action => 处理文件
$actions = [
    'add_product' => 'actions/AddProduct.php',
    'update_product' => 'actions/UpdateProduct.php',
    'toggle_product_status' => 'actions/ToggleProductStatus.php',
    'toggle_featured' => 'actions/ToggleFeatured.php',
    'bulk_update_products' => 'actions/BulkUpdateProducts.php',
    'delete_product' => 'actions/DeleteProduct.php',
    'update_order_status' => 'actions/UpdateOrderStatus.php',
    'bulk_update_orders' => 'actions/UpdateOrderStatus.php',
    'add_promotion' => 'actions/MarketingActions.php',
    'update_promotion' => 'actions/MarketingActions.php',
    'sync_promotion_products' => 'actions/MarketingActions.php',
    'upload_promotion_banner' => 'actions/MarketingActions.php',
    'add_category' => 'actions/CategoryActions.php',
    'update_category' => 'actions/CategoryActions.php',
    'delete_category' => 'actions/CategoryActions.php',
    'add_product_to_category' => 'actions/CategoryActions.php',      // 👈 新增這行
    'remove_product_from_category' => 'actions/CategoryActions.php', // 👈 新增這行
];

// 分发请求
if (isset($actions[$action])) {
    $actionFile = __DIR__ . '/' . $actions[$action];
    if (file_exists($actionFile)) {
        require $actionFile;
    } else {
        goProducts('操作處理器不存在');
    }
} else {
    goProducts('未知操作');
}
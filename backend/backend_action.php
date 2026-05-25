<?php
// backend_action.php - 统一路由器
// 分發請求到各个 action 處理器

session_start();

// 管理員驗證
if (!isset($_SESSION['admin_id'])) {
    die("Access Denied");
}

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

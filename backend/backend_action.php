<?php
// backend_action.php - 统一路由器
// 分發請求到各个 action 處理器
//版本4
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/../homepage/includes/security.php';

date_default_timezone_set('Asia/Taipei');
apConfigureErrorHandling();

// 資料庫連線
$conn = new mysqli("localhost", "root", "", "all_pass_db");

if ($conn->connect_error) {
    error_log('Backend action database connection failed: ' . $conn->connect_error);
    http_response_code(500);
    echo "系統暫時無法連線資料庫，請稍後再試或聯繫管理員。";
    exit();
}

$conn->set_charset("utf8mb4");
$conn->query("SET time_zone = '+08:00'");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: backend.php?page=products");
    exit();
}

$requestedAction = isset($_POST['action']) ? trim($_POST['action']) : 'unknown';
// 設定針對特定 action 的 CSRF 返回頁
$csrfReturnPage = 'backend.php?page=products';
if ($requestedAction === 'submit_supplier_supply') {
    $csrfReturnPage = 'backend.php?page=supplier_products';
} elseif ($requestedAction === 'complete_supplier_supply') {
    $csrfReturnPage = 'backend.php?page=supplier_supplies';
} elseif ($requestedAction === 'submit_supply_request') {
    $csrfReturnPage = 'backend.php?page=request_supply';
} elseif (in_array($requestedAction, ['add_coupon', 'edit_coupon', 'delete_coupon', 'send_coupon'], true)) {
    $csrfReturnPage = 'backend.php?page=coupon';
} elseif (in_array($requestedAction, ['delete_order', 'update_return_request'], true)) {
    $csrfReturnPage = 'backend.php?page=orders';
} elseif ($requestedAction === 'update_member') {
    $csrfReturnPage = 'backend.php?page=members';
}

apRequireCsrf($csrfReturnPage);

$auditTableResult = $conn->query("SHOW TABLES LIKE 'admin_audit_logs'");
if ($auditTableResult && $auditTableResult->num_rows > 0) {
    $adminId = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;
    $targetId = null;
    foreach (['order_id', 'product_id', 'coupon_id', 'user_id', 'category_id', 'request_id', 'supply_id', 'ticket_id'] as $targetKey) {
        if (isset($_POST[$targetKey]) && is_scalar($_POST[$targetKey]) && (int)$_POST[$targetKey] > 0) {
            $targetId = (int)$_POST[$targetKey];
            break;
        }
    }
    $targetType = explode('_', $requestedAction)[0] ?: 'backend';
    $message = '後台操作已通過 CSRF 驗證並送往處理器。';
    $auditStmt = $conn->prepare('INSERT INTO admin_audit_logs (admin_id, action, target_type, target_id, message) VALUES (?, ?, ?, ?, ?)');
    if ($auditStmt) {
        $auditStmt->bind_param('issis', $adminId, $requestedAction, $targetType, $targetId, $message);
        $auditStmt->execute();
        $auditStmt->close();
    }
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

function goCoupon($message = '') {
    $params = ['page' => 'coupon'];
    if ($message !== '') {
        $params['error'] = $message;
    }
    header('Location: backend.php?' . http_build_query($params));
    exit();
}

function goSupplierProducts($message = '') {
    $params = ['page' => 'supplier_products'];
    if ($message !== '') {
        $params['message'] = $message;
    }
    header('Location: backend.php?' . http_build_query($params));
    exit();
}

function goRequestSupply($message = '', $productId = 0) {
    $params = ['page' => 'request_supply'];
    if ($message !== '') {
        $params['message'] = $message;
    }
    if ($productId > 0) {
        $params['product_id'] = $productId;
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

function backendTableExists($conn, $tableName) {
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $res && $res->num_rows > 0;
}

function backendFetchOrderCoupon($conn, $orderId) {
    if (!backendTableExists($conn, 'coupon_distributions')) {
        return null;
    }

    $stmt = $conn->prepare('SELECT user_id, coupon_id FROM orders WHERE order_id = ? AND coupon_id IS NOT NULL AND coupon_id > 0 LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('讀取訂單優惠券資料失敗。');
    }
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$order) {
        return null;
    }

    return [
        'user_id' => (int)$order['user_id'],
        'coupon_id' => (int)$order['coupon_id'],
    ];
}

function backendRestoreOrderCouponUsage($conn, $orderId) {
    $coupon = backendFetchOrderCoupon($conn, $orderId);
    if (!$coupon) {
        return;
    }

    $select = $conn->prepare('SELECT distribution_id FROM coupon_distributions WHERE coupon_id = ? AND user_id = ? LIMIT 1 FOR UPDATE');
    if (!$select) {
        throw new RuntimeException('鎖定會員優惠券資料失敗。');
    }
    $select->bind_param('ii', $coupon['coupon_id'], $coupon['user_id']);
    $select->execute();
    $distribution = $select->get_result()->fetch_assoc();
    $select->close();

    if ($distribution) {
        $distributionId = (int)$distribution['distribution_id'];
        $update = $conn->prepare('UPDATE coupon_distributions SET quantity = quantity + 1 WHERE distribution_id = ?');
        if (!$update) {
            throw new RuntimeException('回補會員優惠券失敗。');
        }
        $update->bind_param('i', $distributionId);
        $update->execute();
        $update->close();
        return;
    }

    $targetType = 'SINGLE';
    $quantity = 1;
    $insert = $conn->prepare('INSERT INTO coupon_distributions (coupon_id, user_id, quantity, target_type) VALUES (?, ?, ?, ?)');
    if (!$insert) {
        throw new RuntimeException('重建會員優惠券資料失敗。');
    }
    $insert->bind_param('iiis', $coupon['coupon_id'], $coupon['user_id'], $quantity, $targetType);
    $insert->execute();
    $insert->close();
}

function backendDeductOrderCouponUsage($conn, $orderId) {
    $coupon = backendFetchOrderCoupon($conn, $orderId);
    if (!$coupon) {
        return;
    }

    $select = $conn->prepare('SELECT distribution_id, quantity FROM coupon_distributions WHERE coupon_id = ? AND user_id = ? AND quantity > 0 ORDER BY distribution_id ASC LIMIT 1 FOR UPDATE');
    if (!$select) {
        throw new RuntimeException('鎖定會員優惠券資料失敗。');
    }
    $select->bind_param('ii', $coupon['coupon_id'], $coupon['user_id']);
    $select->execute();
    $distribution = $select->get_result()->fetch_assoc();
    $select->close();

    if (!$distribution) {
        throw new RuntimeException('會員優惠券數量不足，無法恢復此訂單。');
    }

    $distributionId = (int)$distribution['distribution_id'];
    $quantity = (int)$distribution['quantity'];
    if ($quantity > 1) {
        $update = $conn->prepare('UPDATE coupon_distributions SET quantity = quantity - 1 WHERE distribution_id = ?');
        if (!$update) {
            throw new RuntimeException('扣回會員優惠券失敗。');
        }
        $update->bind_param('i', $distributionId);
        $update->execute();
        $update->close();
    } else {
        $delete = $conn->prepare('DELETE FROM coupon_distributions WHERE distribution_id = ?');
        if (!$delete) {
            throw new RuntimeException('使用會員優惠券失敗。');
        }
        $delete->bind_param('i', $distributionId);
        $delete->execute();
        $delete->close();
    }
}

function boolPost($key) {
    return isset($_POST[$key]) && (string)$_POST[$key] === '1';
}

// 获取 action 和必要的列信息
$action = $requestedAction;
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
    'delete_order' => 'actions/DeleteOrder.php',
    'update_return_request' => 'actions/UpdateReturnRequest.php',
    'add_promotion' => 'actions/MarketingActions.php',
    'update_promotion' => 'actions/MarketingActions.php',
    'sync_promotion_products' => 'actions/MarketingActions.php',
    'upload_promotion_banner' => 'actions/MarketingActions.php',
    'delete_promotion_banner' => 'actions/MarketingActions.php',
    'delete_promotion' => 'actions/MarketingActions.php',
    'add_coupon' => 'actions/CouponActions.php',
    'edit_coupon' => 'actions/CouponActions.php',
    'delete_coupon' => 'actions/CouponActions.php',
    'send_coupon' => 'actions/CouponActions.php',
    'update_member' => 'actions/MemberActions.php',
    'add_category' => 'actions/CategoryActions.php',
    'update_category' => 'actions/CategoryActions.php',
    'delete_category' => 'actions/CategoryActions.php',
    'add_product_to_category' => 'actions/CategoryActions.php',      // 👈 新增這行
    'remove_product_from_category' => 'actions/CategoryActions.php', // 👈 新增這行
    'reply_ticket_message' => 'actions/CustomerServiceActions.php',
    'add_product_qa' => 'actions/CustomerServiceActions.php',
    'submit_supplier_supply' => 'actions/SubmitSupplierSupply.php',
    'complete_supplier_supply' => 'actions/CompleteSupplierSupply.php',
    'submit_supply_request' => 'actions/SubmitSupplyRequest.php',
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

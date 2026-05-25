<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$conn = new mysqli("localhost", "root", "", "all_pass_db");
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

function redirectCart($message = '') {
    $url = 'cart.php';
    if ($message !== '') {
        $url .= '?msg=' . urlencode($message);
    }
    header("Location: {$url}");
    exit();
}

function redirectProduct($productId, $message) {
    header("Location: product_detail.php?id=" . intval($productId) . "&error=" . urlencode($message));
    exit();
}

function getCartId($conn, $userId) {
    $stmt = $conn->prepare("SELECT cart_id FROM carts WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) {
        return (int)$row['cart_id'];
    }

    $insert = $conn->prepare("INSERT INTO carts (user_id) VALUES (?)");
    $insert->bind_param("i", $userId);
    $insert->execute();
    return (int)$conn->insert_id;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectCart();
}

$userId = (int)$_SESSION['user_id'];
$action = isset($_POST['action']) ? $_POST['action'] : '';

if ($action === 'add_to_cart') {
    $productId = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $variantId = isset($_POST['variant_id']) ? intval($_POST['variant_id']) : 0;
    $quantity = isset($_POST['quantity']) ? max(1, intval($_POST['quantity'])) : 1;

    $stockStmt = $conn->prepare("SELECT stock_available FROM product_variants WHERE variant_id = ? LIMIT 1");
    $stockStmt->bind_param("i", $variantId);
    $stockStmt->execute();
    $stockRow = $stockStmt->get_result()->fetch_assoc();
    if (!$stockRow) {
        redirectProduct($productId, 'Selected SKU does not exist.');
    }
    if ((int)$stockRow['stock_available'] < $quantity) {
        redirectProduct($productId, 'Not enough stock for this SKU.');
    }

    $cartId = getCartId($conn, $userId);

    $existingStmt = $conn->prepare("SELECT quantity FROM cart_items WHERE cart_id = ? AND variant_id = ? LIMIT 1");
    $existingStmt->bind_param("ii", $cartId, $variantId);
    $existingStmt->execute();
    $existing = $existingStmt->get_result()->fetch_assoc();

    if ($existing) {
        $newQty = (int)$existing['quantity'] + $quantity;
        if ((int)$stockRow['stock_available'] < $newQty) {
            redirectProduct($productId, 'Cart quantity would exceed current stock.');
        }
        $update = $conn->prepare("UPDATE cart_items SET quantity = ? WHERE cart_id = ? AND variant_id = ?");
        $update->bind_param("iii", $newQty, $cartId, $variantId);
        $update->execute();
    } else {
        $insert = $conn->prepare("INSERT INTO cart_items (cart_id, variant_id, quantity) VALUES (?, ?, ?)");
        $insert->bind_param("iii", $cartId, $variantId, $quantity);
        $insert->execute();
    }

    redirectCart('Item added to cart.');
}

if ($action === 'update_quantity') {
    $cartId = getCartId($conn, $userId);
    $itemId = isset($_POST['cart_item_id']) ? intval($_POST['cart_item_id']) : 0;
    $quantity = isset($_POST['quantity']) ? max(1, intval($_POST['quantity'])) : 1;

    $stmt = $conn->prepare("
        SELECT ci.cart_item_id, pv.stock_available
        FROM cart_items ci
        INNER JOIN product_variants pv ON pv.variant_id = ci.variant_id
        WHERE ci.cart_item_id = ? AND ci.cart_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $itemId, $cartId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        redirectCart('Cart item not found.');
    }
    if ((int)$row['stock_available'] < $quantity) {
        redirectCart('Quantity exceeds current stock.');
    }

    $update = $conn->prepare("UPDATE cart_items SET quantity = ? WHERE cart_item_id = ? AND cart_id = ?");
    $update->bind_param("iii", $quantity, $itemId, $cartId);
    $update->execute();
    redirectCart('Cart updated.');
}

if ($action === 'remove_item') {
    $cartId = getCartId($conn, $userId);
    $itemId = isset($_POST['cart_item_id']) ? intval($_POST['cart_item_id']) : 0;
    $delete = $conn->prepare("DELETE FROM cart_items WHERE cart_item_id = ? AND cart_id = ?");
    $delete->bind_param("ii", $itemId, $cartId);
    $delete->execute();
    redirectCart('Item removed.');
}

redirectCart('Unknown cart action.');
?>

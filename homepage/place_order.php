<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: checkout.php");
    exit();
}

$conn = new mysqli("localhost", "root", "", "all_pass_db");
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

$userId = (int)$_SESSION['user_id'];
$recipientName = trim($_POST['recipient_name'] ?? '');
$recipientPhone = trim($_POST['recipient_phone'] ?? '');
$shippingAddress = trim($_POST['shipping_address'] ?? '');
$shippingNotes = trim($_POST['shipping_notes'] ?? '');
$paymentMethod = trim($_POST['payment_method'] ?? 'COD');

function failCheckout($message) {
    header("Location: checkout.php?error=" . urlencode($message));
    exit();
}

if ($recipientName === '' || $recipientPhone === '' || $shippingAddress === '') {
    failCheckout('Please complete recipient name, phone, and address.');
}

$allowedPayments = ['COD', 'SIMULATED_CARD'];
if (!in_array($paymentMethod, $allowedPayments, true)) {
    $paymentMethod = 'COD';
}

$conn->begin_transaction();

try {
    $cartStmt = $conn->prepare("SELECT cart_id FROM carts WHERE user_id = ? LIMIT 1");
    $cartStmt->bind_param("i", $userId);
    $cartStmt->execute();
    $cart = $cartStmt->get_result()->fetch_assoc();
    if (!$cart) {
        throw new Exception('Cart is empty.');
    }
    $cartId = (int)$cart['cart_id'];

    $itemStmt = $conn->prepare("
        SELECT ci.cart_item_id, ci.quantity,
               p.name AS product_name,
               pv.variant_id, pv.sku_code, pv.color, pv.size_inches, pv.price, pv.stock_available
        FROM cart_items ci
        INNER JOIN product_variants pv ON pv.variant_id = ci.variant_id
        INNER JOIN products p ON p.product_id = pv.product_id
        WHERE ci.cart_id = ?
        ORDER BY ci.cart_item_id ASC
        FOR UPDATE
    ");
    $itemStmt->bind_param("i", $cartId);
    $itemStmt->execute();
    $result = $itemStmt->get_result();

    $items = [];
    $subtotal = 0;
    while ($row = $result->fetch_assoc()) {
        $qty = (int)$row['quantity'];
        if ($qty <= 0) {
            throw new Exception('Invalid cart quantity.');
        }
        if ((int)$row['stock_available'] < $qty) {
            throw new Exception($row['product_name'] . ' has insufficient stock.');
        }
        $row['line_total'] = (float)$row['price'] * $qty;
        $subtotal += $row['line_total'];
        $items[] = $row;
    }

    if (empty($items)) {
        throw new Exception('Cart is empty.');
    }

    $shippingFee = $subtotal >= 3000 ? 0 : 120;
    $discountAmount = 0;
    $totalAmount = $subtotal + $shippingFee - $discountAmount;
    $status = 'PENDING';

    $orderStmt = $conn->prepare("
        INSERT INTO orders
            (user_id, subtotal_amount, shipping_fee, discount_amount, total_amount, status,
             recipient_name, recipient_phone, shipping_address, shipping_notes, payment_method)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $orderStmt->bind_param(
        "iddddssssss",
        $userId,
        $subtotal,
        $shippingFee,
        $discountAmount,
        $totalAmount,
        $status,
        $recipientName,
        $recipientPhone,
        $shippingAddress,
        $shippingNotes,
        $paymentMethod
    );
    if (!$orderStmt->execute()) {
        throw new Exception('Failed to create order.');
    }
    $orderId = (int)$conn->insert_id;

    $orderItemStmt = $conn->prepare("
        INSERT INTO order_items
            (order_id, variant_id, product_name, sku_code, color, size_inches, quantity, locked_price)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stockStmt = $conn->prepare("
        UPDATE product_variants
        SET stock_available = stock_available - ?
        WHERE variant_id = ? AND stock_available >= ?
    ");

    foreach ($items as $item) {
        $variantId = (int)$item['variant_id'];
        $qty = (int)$item['quantity'];
        $price = (float)$item['price'];
        $productName = $item['product_name'];
        $skuCode = $item['sku_code'];
        $color = $item['color'];
        $size = $item['size_inches'];

        $orderItemStmt->bind_param(
            "iissssid",
            $orderId,
            $variantId,
            $productName,
            $skuCode,
            $color,
            $size,
            $qty,
            $price
        );
        if (!$orderItemStmt->execute()) {
            throw new Exception('Failed to create order item.');
        }

        $stockStmt->bind_param("iii", $qty, $variantId, $qty);
        if (!$stockStmt->execute() || $stockStmt->affected_rows !== 1) {
            throw new Exception($productName . ' stock update failed.');
        }
    }

    $clearStmt = $conn->prepare("DELETE FROM cart_items WHERE cart_id = ?");
    $clearStmt->bind_param("i", $cartId);
    $clearStmt->execute();

    $conn->commit();
    header("Location: order_success.php?order_id=" . $orderId);
    exit();
} catch (Exception $e) {
    $conn->rollback();
    failCheckout($e->getMessage());
}
?>

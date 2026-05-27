<?php
$pageTitle = '購物車 | All Pass';
$activeNav = '';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = intval($_SESSION['user_id']);
$conn = new mysqli('localhost', 'root', '', 'all_pass_db');
if ($conn->connect_error) {
    die('資料庫連線失敗: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');

function cartTableExists($conn, $tableName) {
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return ($res && $res->num_rows > 0);
}

function cartFetchRows($conn, $sql) {
    $rows = [];
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

if (cartTableExists($conn, 'cart_items')) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_item_id'])) {
        $deleteId = intval($_POST['delete_item_id']);
        $stmt = $conn->prepare('DELETE FROM cart_items WHERE cart_item_id = ? AND user_id = ?');
        if ($stmt) {
            $stmt->bind_param('ii', $deleteId, $userId);
            $stmt->execute();
            $stmt->close();
        }
        header('Location: cart.php?notice=deleted');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_cart') {
        $quantities = isset($_POST['quantities']) && is_array($_POST['quantities']) ? $_POST['quantities'] : [];
        $stmt = $conn->prepare('UPDATE cart_items SET quantity = ? WHERE cart_item_id = ? AND user_id = ?');
        $deleteStmt = $conn->prepare('DELETE FROM cart_items WHERE cart_item_id = ? AND user_id = ?');
        if ($stmt && $deleteStmt) {
            foreach ($quantities as $cartItemId => $qtyValue) {
                $cartItemId = intval($cartItemId);
                $quantity = intval($qtyValue);
                if ($quantity <= 0) {
                    $deleteStmt->bind_param('ii', $cartItemId, $userId);
                    $deleteStmt->execute();
                } else {
                    $stmt->bind_param('iii', $quantity, $cartItemId, $userId);
                    $stmt->execute();
                }
            }
        }
        if ($stmt) {
            $stmt->close();
        }
        if ($deleteStmt) {
            $deleteStmt->close();
        }
        header('Location: cart.php?notice=updated');
        exit;
    }
}

$items = [];
if (cartTableExists($conn, 'cart_items')) {
    $imageOrder = 'pi.is_main DESC, pi.sort_order ASC, pi.image_id ASC';
    $sql = "
        SELECT
            ci.cart_item_id,
            ci.product_id,
            ci.variant_id,
            ci.quantity,
            ci.created_at,
            p.name AS product_name,
            p.status,
            COALESCE(v.color, '') AS variant_color,
            COALESCE(v.size_inches, '') AS variant_size,
            COALESCE(v.sku_code, '') AS sku_code,
            COALESCE(v.original_price, 0) AS original_price,
            COALESCE(v.special_price, NULL) AS special_price,
            COALESCE(v.member_price, 0) AS member_price,
            COALESCE(
                (
                    SELECT pi.image_url
                    FROM product_images pi
                    WHERE pi.product_id = p.product_id
                      AND ci.variant_id IS NOT NULL
                      AND v.color IS NOT NULL
                      AND v.color <> ''
                      AND pi.color = v.color
                    ORDER BY {$imageOrder}
                    LIMIT 1
                ),
                (
                    SELECT pi.image_url
                    FROM product_images pi
                    WHERE pi.product_id = p.product_id
                    ORDER BY {$imageOrder}
                    LIMIT 1
                ),
                ''
            ) AS image_url,
            COALESCE((
                SELECT MIN(COALESCE(pv.special_price, pv.original_price))
                FROM product_variants pv
                WHERE pv.product_id = p.product_id
            ), 0) AS fallback_price
        FROM cart_items ci
        LEFT JOIN products p ON p.product_id = ci.product_id
        LEFT JOIN product_variants v ON v.variant_id = ci.variant_id
        WHERE ci.user_id = {$userId}
        ORDER BY ci.created_at DESC, ci.cart_item_id DESC
    ";
    $items = cartFetchRows($conn, $sql);
}

$notice = isset($_GET['notice']) ? $_GET['notice'] : '';
$selectedIds = [];
$totalAmount = 0;
foreach ($items as &$item) {
    $price = ($item['special_price'] !== null && $item['special_price'] !== '')
        ? floatval($item['special_price'])
        : floatval($item['original_price']);
    if ($price <= 0) {
        $price = floatval($item['fallback_price']);
    }
    $item['display_price'] = $price;
}
unset($item);
include 'header.php';
?>

<section style="padding:190px 5% 60px; max-width:1200px; margin:0 auto;">
    <div style="display:flex; justify-content:space-between; align-items:flex-end; gap:16px; flex-wrap:wrap; margin-bottom:20px;">
        <div>
            <h1 style="font-size:34px; margin-bottom:8px;">購物車</h1>
            <p style="color:#666;">可勾選商品、調整數量、刪除項目，然後前往結帳。</p>
        </div>
        <a href="index.php" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 16px; border-radius:999px; background:#111; color:#fff; font-weight:700;">繼續選購</a>
    </div>

    <?php if ($notice === 'deleted'): ?>
        <div style="margin-bottom:16px; padding:12px 14px; border-radius:10px; background:#fef2f2; color:#991b1b; border:1px solid #fca5a5;">已刪除該筆購物車資料。</div>
    <?php elseif ($notice === 'updated'): ?>
        <div style="margin-bottom:16px; padding:12px 14px; border-radius:10px; background:#ecfdf5; color:#166534; border:1px solid #86efac;">購物車已更新。</div>
    <?php endif; ?>

    <?php if (!cartTableExists($conn, 'cart_items')): ?>
        <div style="background:#fff; border:1px solid #eee; border-radius:14px; padding:32px; text-align:center; color:#777;">目前資料庫尚未建立 `cart_items`，請先執行同步腳本。</div>
    <?php elseif (empty($items)): ?>
        <div style="background:#fff; border:1px solid #eee; border-radius:14px; padding:32px; text-align:center; color:#777;">
            <p style="margin-bottom:14px;">你的購物車目前是空的。</p>
            <a href="new_in.php" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 16px; border-radius:999px; background:#db6b6b; color:#fff; font-weight:700;">去逛新品</a>
        </div>
    <?php else: ?>
        <form method="post" action="cart.php" id="cartForm">
            <div style="overflow:auto; background:#fff; border:1px solid #eee; border-radius:14px;">
                <table style="width:100%; border-collapse:collapse; min-width:920px;">
                    <thead>
                        <tr style="background:#fafafa; border-bottom:1px solid #eee; text-align:left; color:#666; font-size:14px;">
                            <th style="padding:14px 12px; width:56px;"></th>
                            <th style="padding:14px 12px; width:100px;">商品</th>
                            <th style="padding:14px 12px;">名稱 / 規格</th>
                            <th style="padding:14px 12px; width:120px;">單價</th>
                            <th style="padding:14px 12px; width:140px;">數量</th>
                            <th style="padding:14px 12px; width:140px;">小計</th>
                            <th style="padding:14px 12px; width:120px;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <?php
                            $displayPrice = floatval($item['display_price']);
                            $subtotal = $displayPrice * intval($item['quantity']);
                            $totalAmount += $subtotal;
                            $imageUrl = $item['image_url'] !== '' ? '../' . ltrim($item['image_url'], '/') : '';
                            $variantLabel = trim(($item['variant_size'] !== '' ? $item['variant_size'] . '吋' : '') . (($item['variant_color'] !== '' && $item['variant_size'] !== '') ? ' / ' : '') . ($item['variant_color'] !== '' ? $item['variant_color'] : ''));
                            ?>
                            <tr style="border-bottom:1px solid #f3f3f3; vertical-align:top;" data-cart-row data-cart-item-id="<?php echo intval($item['cart_item_id']); ?>">
                                <td style="padding:14px 12px; text-align:center;">
                                    <input type="checkbox" name="selected[]" value="<?php echo intval($item['cart_item_id']); ?>" style="width:18px; height:18px;" data-cart-checkbox>
                                </td>
                                <td style="padding:14px 12px;">
                                    <?php if ($imageUrl !== ''): ?>
                                        <img src="<?php echo htmlspecialchars($imageUrl); ?>" alt="<?php echo htmlspecialchars($item['product_name']); ?>" style="width:84px; height:84px; object-fit:cover; border-radius:10px; border:1px solid #eee;">
                                    <?php else: ?>
                                        <div style="width:84px; height:84px; border-radius:10px; border:1px solid #eee; background:#f7f7f7; display:flex; align-items:center; justify-content:center; color:#aaa; font-size:12px;">No Img</div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:14px 12px;">
                                    <div style="font-weight:700; margin-bottom:6px; color:#222;"><?php echo htmlspecialchars($item['product_name']); ?></div>
                                    <div style="font-size:13px; color:#777; line-height:1.7;">
                                        <?php if ($variantLabel !== ''): ?>
                                            <div>規格：<?php echo htmlspecialchars($variantLabel); ?></div>
                                        <?php endif; ?>
                                        <div>SKU：<?php echo htmlspecialchars($item['sku_code'] !== '' ? $item['sku_code'] : '-'); ?></div>
                                        <div>加入時間：<?php echo htmlspecialchars($item['created_at']); ?></div>
                                    </div>
                                </td>
                                <td style="padding:14px 12px; font-weight:700;">NT$ <?php echo number_format($displayPrice); ?></td>
                                <td style="padding:14px 12px;">
                                    <input type="number" name="quantities[<?php echo intval($item['cart_item_id']); ?>]" value="<?php echo intval($item['quantity']); ?>" min="1" style="width:100px; height:40px; border:1px solid #ddd; border-radius:8px; padding:0 10px;" data-cart-qty data-unit-price="<?php echo htmlspecialchars((string)$displayPrice); ?>">
                                </td>
                                <td style="padding:14px 12px; font-weight:700;">NT$ <span data-cart-subtotal><?php echo number_format($subtotal); ?></span></td>
                                <td style="padding:14px 12px;">
                                    <button type="submit" name="delete_item_id" value="<?php echo intval($item['cart_item_id']); ?>" formaction="cart.php" formmethod="post" style="padding:8px 12px; border:none; border-radius:8px; background:#f3f4f6; color:#991b1b; font-weight:700; cursor:pointer;">刪除</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; margin-top:18px;">
                <div style="font-size:18px; font-weight:700; color:#222;">已勾選商品總額：NT$ <span id="selectedTotal"><?php echo number_format($totalAmount); ?></span></div>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <button type="submit" name="action" value="update_cart" style="padding:12px 18px; border:none; border-radius:999px; background:#111; color:#fff; font-weight:700; cursor:pointer;">更新購物車</button>
                    <button type="submit" name="action" value="checkout" formaction="checkout.php" formmethod="post" style="padding:12px 18px; border:none; border-radius:999px; background:#db6b6b; color:#fff; font-weight:700; cursor:pointer;">前往結帳</button>
                </div>
            </div>
        </form>
    <?php endif; ?>
</section>

<script>
(function () {
    const form = document.getElementById('cartForm');
    if (!form) return;

    const rows = Array.from(form.querySelectorAll('[data-cart-row]'));
    const selectedTotalEl = document.getElementById('selectedTotal');

    function formatMoney(value) {
        return new Intl.NumberFormat('zh-TW').format(value);
    }

    function recalc() {
        let selectedTotal = 0;

        rows.forEach((row) => {
            const checkbox = row.querySelector('[data-cart-checkbox]');
            const qtyInput = row.querySelector('[data-cart-qty]');
            const subtotalEl = row.querySelector('[data-cart-subtotal]');
            const unitPrice = parseFloat(qtyInput ? qtyInput.dataset.unitPrice : '0') || 0;
            const quantity = Math.max(1, parseInt(qtyInput ? qtyInput.value : '1', 10) || 1);
            const subtotal = unitPrice * quantity;

            if (subtotalEl) {
                subtotalEl.textContent = formatMoney(subtotal);
            }

            if (checkbox && checkbox.checked) {
                selectedTotal += subtotal;
            }
        });

        if (selectedTotalEl) {
            selectedTotalEl.textContent = formatMoney(selectedTotal);
        }
    }

    rows.forEach((row) => {
        const checkbox = row.querySelector('[data-cart-checkbox]');
        const qtyInput = row.querySelector('[data-cart-qty]');

        if (checkbox) {
            checkbox.addEventListener('change', recalc);
        }
        if (qtyInput) {
            qtyInput.addEventListener('input', recalc);
            qtyInput.addEventListener('change', recalc);
        }
    });

    recalc();
})();
</script>

<?php include 'footer.php'; $conn->close(); ?>

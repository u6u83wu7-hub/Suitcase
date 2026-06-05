<?php
// actions/CompleteSupplierSupply.php - 管理者確認補貨，增加庫存並標記供應單為完成
if ($action !== 'complete_supplier_supply') {
    return;
}

if (!isset($_SESSION['admin_id'], $_SESSION['admin_username'])) {
    echo "<script>alert('請先登入管理者帳號'); location.href='backend.php?page=supplier_supplies';</script>";
    exit();
}

$adminId = intval($_SESSION['admin_id']);
$roleStmt = $conn->prepare("SELECT role_id FROM admin_users WHERE admin_id = ? LIMIT 1");
$roleStmt->bind_param('i', $adminId);
$roleStmt->execute();
$roleRow = $roleStmt->get_result()->fetch_assoc();
$roleStmt->close();

if (!$roleRow || intval($roleRow['role_id']) !== 1) {
    echo "<script>alert('只有超級管理者可以執行此操作'); location.href='backend.php?page=supplier_supplies';</script>";
    exit();
}

$supplyId = isset($_POST['supply_id']) ? intval($_POST['supply_id']) : 0;
if ($supplyId <= 0) {
    echo "<script>alert('無效的供應編號'); location.href='backend.php?page=supplier_supplies';</script>";
    exit();
}

$conn->begin_transaction();
try {
    // 鎖定供應紀錄
    $sel = $conn->prepare("SELECT supply_id, product_id, variant_id, supply_quantity, is_supply_complete FROM supplier_supplies WHERE supply_id = ? FOR UPDATE");
    $sel->bind_param('i', $supplyId);
    $sel->execute();
    $srow = $sel->get_result()->fetch_assoc();
    $sel->close();

    if (!$srow) {
        throw new Exception('找不到該供應紀錄');
    }
    if (intval($srow['is_supply_complete']) === 1) {
        throw new Exception('該供應紀錄已標記為完成');
    }

    $productId = intval($srow['product_id']);
    $qty = intval($srow['supply_quantity']);
    if ($productId <= 0 || $qty <= 0) {
        throw new Exception('供應資料不完整');
    }

    // 若供應紀錄指定了 variant_id，則直接對該 variant 補貨
    $specifiedVariantId = isset($srow['variant_id']) ? intval($srow['variant_id']) : 0;
    if ($specifiedVariantId > 0) {
        $varStmt = $conn->prepare("SELECT variant_id, stock_available FROM product_variants WHERE variant_id = ? FOR UPDATE");
        $varStmt->bind_param('i', $specifiedVariantId);
        $varStmt->execute();
        $vrow = $varStmt->get_result()->fetch_assoc();
        $varStmt->close();

        if (!$vrow) {
            throw new Exception('指定的 SKU 找不到');
        }

        $variantId = intval($vrow['variant_id']);
        $oldStock = intval($vrow['stock_available']);
        $restock = $conn->prepare("UPDATE product_variants SET stock_available = stock_available + ? WHERE variant_id = ?");
        $restock->bind_param('ii', $qty, $variantId);
        if (!$restock->execute()) {
            throw new Exception('增加 SKU 庫存失敗');
        }
        $restock->close();

        $hasLogs = $conn->query("SHOW TABLES LIKE 'inventory_adjustment_logs'")->num_rows > 0;
        if ($hasLogs) {
            $log = $conn->prepare("INSERT INTO inventory_adjustment_logs (product_id, variant_id, old_stock, new_stock, delta_quantity, action_type, admin_id, note) VALUES (?, ?, ?, ?, ?, 'SUPPLIER_RESTOCK', ?, ?)");
            $newStock = $oldStock + $qty;
            $note = '供應單 #' . $supplyId . ' 補貨 (variant)';
            $log->bind_param('iiiiiss', $productId, $variantId, $oldStock, $newStock, $qty, $adminId, $note);
            $log->execute();
            $log->close();
        }
    } else {
        // 若未指定 variant，退回到原先策略：找商品下第一個 variant 補貨，若沒有則更新 products.stock（若存在）
        $varStmt = $conn->prepare("SELECT variant_id, stock_available FROM product_variants WHERE product_id = ? ORDER BY variant_id LIMIT 1 FOR UPDATE");
        $varStmt->bind_param('i', $productId);
        $varStmt->execute();
        $vrow = $varStmt->get_result()->fetch_assoc();
        $varStmt->close();

        if ($vrow) {
            $variantId = intval($vrow['variant_id']);
            $oldStock = intval($vrow['stock_available']);
            $restock = $conn->prepare("UPDATE product_variants SET stock_available = stock_available + ? WHERE variant_id = ?");
            $restock->bind_param('ii', $qty, $variantId);
            if (!$restock->execute()) {
                throw new Exception('增加 SKU 庫存失敗');
            }
            $restock->close();

            $hasLogs = $conn->query("SHOW TABLES LIKE 'inventory_adjustment_logs'")->num_rows > 0;
            if ($hasLogs) {
                $log = $conn->prepare("INSERT INTO inventory_adjustment_logs (product_id, variant_id, old_stock, new_stock, delta_quantity, action_type, admin_id, note) VALUES (?, ?, ?, ?, ?, 'SUPPLIER_RESTOCK', ?, ?)");
                $newStock = $oldStock + $qty;
                $note = '供應單 #' . $supplyId . ' 補貨';
                $log->bind_param('iiiiiss', $productId, $variantId, $oldStock, $newStock, $qty, $adminId, $note);
                $log->execute();
                $log->close();
            }
        } else {
            $hasStockCol = $conn->query("SHOW COLUMNS FROM `products` LIKE 'stock' ")->num_rows > 0;
            if ($hasStockCol) {
                $upd = $conn->prepare("UPDATE products SET stock = COALESCE(stock, 0) + ? WHERE product_id = ?");
                $upd->bind_param('ii', $qty, $productId);
                if (!$upd->execute()) {
                    throw new Exception('更新商品庫存失敗');
                }
                $upd->close();
            }
        }
    }

    // 標記供應紀錄為已完成
    $m = $conn->prepare("UPDATE supplier_supplies SET is_supply_complete = 1 WHERE supply_id = ?");
    $m->bind_param('i', $supplyId);
    if (!$m->execute()) {
        throw new Exception('標記供應紀錄失敗');
    }
    $m->close();

    $conn->commit();
    echo "<script>alert('補貨完成並已更新庫存'); location.href='backend.php?page=supplier_supplies';</script>";
    exit();
} catch (Exception $e) {
    $conn->rollback();
    $msg = addslashes($e->getMessage());
    echo "<script>alert('{$msg}'); location.href='backend.php?page=supplier_supplies';</script>";
    exit();
}

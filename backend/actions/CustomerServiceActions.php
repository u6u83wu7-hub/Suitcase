<?php
// CustomerServiceActions.php - 客服管理動作

if (!in_array($action, ['reply_ticket_message', 'add_product_qa', 'update_product_qa', 'toggle_product_qa'], true)) {
    return;
}

function csRedirect($url, $message = '') {
    if ($message !== '') {
        $safe = addslashes($message);
        echo "<script>alert('{$safe}'); location.href='{$url}';</script>";
    } else {
        header('Location: ' . $url);
    }
    exit();
}

$returnTo = isset($_POST['return_to']) ? trim($_POST['return_to']) : 'backend.php?page=customer_service';

if ($action === 'reply_ticket_message') {
    $ticketId = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
    $messageText = trim($_POST['message_text'] ?? '');
    $productIdRaw = isset($_POST['product_id']) ? trim($_POST['product_id']) : '';
    $productId = $productIdRaw === '' ? null : intval($productIdRaw);

    if ($ticketId <= 0 || $messageText === '') {
        csRedirect($returnTo, '請輸入回覆內容。');
    }

    $adminId = isset($_SESSION['admin_id']) ? intval($_SESSION['admin_id']) : 0;
    if ($adminId <= 0) {
        csRedirect('admin_login.php', '請先登入管理員帳號。');
    }

    $conn->begin_transaction();
    try {
        if ($productId === null) {
            $insert = $conn->prepare("INSERT INTO ticket_messages (ticket_id, sender_type, sender_id, product_id, message_text) VALUES (?, 'ADMIN', ?, NULL, ?)");
            $insert->bind_param('iis', $ticketId, $adminId, $messageText);
        } else {
            $insert = $conn->prepare("INSERT INTO ticket_messages (ticket_id, sender_type, sender_id, product_id, message_text) VALUES (?, 'ADMIN', ?, ?, ?)");
            $insert->bind_param('iiis', $ticketId, $adminId, $productId, $messageText);
        }
        if (!$insert->execute()) {
            throw new Exception('新增訊息失敗');
        }

        $update = $conn->prepare("UPDATE customer_tickets SET status = 'ANSWERED', updated_at = NOW() WHERE ticket_id = ?");
        $update->bind_param('i', $ticketId);
        $update->execute();

        $conn->commit();
        csRedirect($returnTo, '已送出回覆。');
    } catch (Exception $e) {
        $conn->rollback();
        csRedirect($returnTo, '送出失敗: ' . $e->getMessage());
    }
}

if ($action === 'add_product_qa' || $action === 'update_product_qa') {
    $qaId = isset($_POST['qa_id']) ? intval($_POST['qa_id']) : 0;
    $question = trim($_POST['question'] ?? '');
    $answer = trim($_POST['answer'] ?? '');
    $qaType = trim($_POST['qa_type'] ?? 'PRODUCT');
    $productIdRaw = isset($_POST['product_id']) ? trim($_POST['product_id']) : '';
    $productId = $productIdRaw === '' ? null : intval($productIdRaw);

    if ($question === '' || $answer === '') {
        csRedirect($returnTo, '請填入完整的 FAQ 內容。');
    }

    if (!in_array($qaType, ['GENERAL', 'PRODUCT'], true)) {
        $qaType = 'PRODUCT';
    }

    if ($qaType === 'GENERAL') {
        $productId = null;
    }

    if ($qaType === 'PRODUCT' && $productId === null) {
        csRedirect($returnTo, '商品專屬 FAQ 請選擇商品，或改為通用 FAQ。');
    }

    if ($action === 'update_product_qa') {
        if ($qaId <= 0) {
            csRedirect($returnTo, '找不到要更新的 FAQ。');
        }

        if ($productId === null) {
            $stmt = $conn->prepare("UPDATE product_qa SET product_id = NULL, question = ?, answer = ?, qa_type = ?, updated_at = NOW() WHERE qa_id = ?");
            $stmt->bind_param('sssi', $question, $answer, $qaType, $qaId);
        } else {
            $stmt = $conn->prepare("UPDATE product_qa SET product_id = ?, question = ?, answer = ?, qa_type = ?, updated_at = NOW() WHERE qa_id = ?");
            $stmt->bind_param('isssi', $productId, $question, $answer, $qaType, $qaId);
        }

        if ($stmt && $stmt->execute()) {
            csRedirect($returnTo, 'FAQ 已更新。');
        }

        csRedirect($returnTo, '更新 FAQ 失敗。');
    }

    if ($productId === null) {
        $stmt = $conn->prepare("INSERT INTO product_qa (product_id, question, answer, qa_type, is_active) VALUES (NULL, ?, ?, ?, 1)");
        $stmt->bind_param('sss', $question, $answer, $qaType);
    } else {
        $stmt = $conn->prepare("INSERT INTO product_qa (product_id, question, answer, qa_type, is_active) VALUES (?, ?, ?, ?, 1)");
        $stmt->bind_param('isss', $productId, $question, $answer, $qaType);
    }

    if ($stmt->execute()) {
        csRedirect($returnTo, '已新增 FAQ。');
    }

    csRedirect($returnTo, '新增 FAQ 失敗。');
}
if ($action === 'toggle_product_qa') {
    $qaId = isset($_POST['qa_id']) ? intval($_POST['qa_id']) : 0;
    $newStatus = isset($_POST['new_status']) ? intval($_POST['new_status']) : 0;
    $newStatus = $newStatus === 1 ? 1 : 0;

    if ($qaId <= 0) {
        csRedirect($returnTo, '找不到要更新的 FAQ。');
    }

    $stmt = $conn->prepare("UPDATE product_qa SET is_active = ?, updated_at = NOW() WHERE qa_id = ?");
    if ($stmt) {
        $stmt->bind_param('ii', $newStatus, $qaId);
        if ($stmt->execute()) {
            csRedirect($returnTo, $newStatus === 1 ? 'FAQ 已啟用。' : 'FAQ 已停用。');
        }
    }

    csRedirect($returnTo, '更新 FAQ 狀態失敗。');
}
?>

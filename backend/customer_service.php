<?php
// customer_service.php - 客服管理中心 (依人分類版)

$selectedTicketId = isset($_GET['ticket_id']) ? intval($_GET['ticket_id']) : 0;

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function csTableExists($conn, $tableName) {
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $res && $res->num_rows > 0;
}

function csColumnExists($conn, $tableName, $columnName) {
    $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    $safeColumn = $conn->real_escape_string($columnName);
    $res = $conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    return $res && $res->num_rows > 0;
}

$allProducts = [];
$prodRes = $conn->query("SELECT product_id, name FROM products ORDER BY name ASC");
if ($prodRes) {
    while ($p = $prodRes->fetch_assoc()) { $allProducts[] = $p; }
}

$tickets = [];
// 抓取該對話中「最後一次」被標註的商品名稱，顯示在左側列表
$ticketsSql = "
    SELECT t.ticket_id, t.user_id, t.status, t.updated_at,
           u.name AS user_name, u.email AS user_email,
           (SELECT message_text FROM ticket_messages tm WHERE tm.ticket_id = t.ticket_id ORDER BY tm.message_id DESC LIMIT 1) AS last_message,
           (SELECT pr.name FROM ticket_messages tm2 LEFT JOIN products pr ON tm2.product_id = pr.product_id WHERE tm2.ticket_id = t.ticket_id AND tm2.product_id IS NOT NULL ORDER BY tm2.message_id DESC LIMIT 1) AS latest_product_name
    FROM customer_tickets t
    LEFT JOIN users u ON u.user_id = t.user_id
    ORDER BY t.updated_at DESC
";
$ticketsRes = $conn->query($ticketsSql);
if ($ticketsRes) {
    while ($row = $ticketsRes->fetch_assoc()) { $tickets[] = $row; }
}

if ($selectedTicketId <= 0 && !empty($tickets)) {
    $selectedTicketId = intval($tickets[0]['ticket_id']);
}

$selectedTicket = null;
$messages = [];
$wasOpen = false;
if ($selectedTicketId > 0) {
    $ticketStmt = $conn->prepare("SELECT t.*, u.name AS user_name FROM customer_tickets t LEFT JOIN users u ON u.user_id = t.user_id WHERE t.ticket_id = ? LIMIT 1");
    $ticketStmt->bind_param('i', $selectedTicketId);
    $ticketStmt->execute();
    $selectedTicket = $ticketStmt->get_result()->fetch_assoc();
    $wasOpen = $selectedTicket && $selectedTicket['status'] === 'OPEN';

    if ($selectedTicket) {
        $msgStmt = $conn->prepare("
            SELECT tm.*, pr.name AS product_name 
            FROM ticket_messages tm 
            LEFT JOIN products pr ON tm.product_id = pr.product_id 
            WHERE tm.ticket_id = ? 
            ORDER BY tm.message_id ASC
        ");
        $msgStmt->bind_param('i', $selectedTicketId);
        $msgStmt->execute();
        $msgRes = $msgStmt->get_result();
        while ($row = $msgRes->fetch_assoc()) { $messages[] = $row; }
    }
}

$lastUserMessageId = 0;
$currentProductId = '';
$currentProductName = '';
foreach ($messages as $msg) {
    if ($msg['sender_type'] === 'USER') {
        $lastUserMessageId = (int)$msg['message_id'];
    }
    if (!empty($msg['product_id'])) {
        $currentProductId = (string)$msg['product_id'];
        $currentProductName = (string)($msg['product_name'] ?? '');
    }
}

$defaultReplyLabel = '一般問題';
if ($currentProductId !== '') {
    $defaultReplyLabel = '商品：' . ($currentProductName !== '' ? $currentProductName : ('#' . $currentProductId));
}

$nextAdminReply = [];
$messageCount = count($messages);
for ($i = 0; $i < $messageCount; $i++) {
    if ($messages[$i]['sender_type'] !== 'USER') continue;
    $replyText = '';
    for ($j = $i + 1; $j < $messageCount; $j++) {
        if ($messages[$j]['sender_type'] === 'ADMIN') { $replyText = $messages[$j]['message_text']; break; }
    }
    $nextAdminReply[(int)$messages[$i]['message_id']] = $replyText;
}

$productFaqs = [];
if (csTableExists($conn, 'product_qa')) {
    $faqActiveSql = csColumnExists($conn, 'product_qa', 'is_active') ? 'pq.is_active' : '1 AS is_active';
    $faqRes = $conn->query("
        SELECT pq.qa_id, pq.product_id, pq.question, pq.answer, pq.qa_type, {$faqActiveSql}, pq.created_at, pq.updated_at, p.name AS product_name
        FROM product_qa pq
        LEFT JOIN products p ON p.product_id = pq.product_id
        ORDER BY pq.updated_at DESC, pq.qa_id DESC
        LIMIT 80
    ");
    if ($faqRes) {
        while ($row = $faqRes->fetch_assoc()) { $productFaqs[] = $row; }
    }
}
?>

<style>
    :root { --cs-bg: #f8fafc; --cs-border: #e2e8f0; --cs-brand: #db6b6b; --cs-dark: #0f172a; --cs-text-main: #1e293b; --cs-text-muted: #64748b; }
    .cs-container { display: grid; grid-template-columns: 340px 1fr; gap: 24px; height: calc(100vh - 120px); min-height: 600px; margin-top: 16px; }
    .cs-sidebar { background: #fff; border: 1px solid var(--cs-border); border-radius: 16px; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02); }
    .cs-sidebar-list { flex: 1; overflow-y: auto; padding: 12px; }
    .cs-ticket { display: block; padding: 16px; border-radius: 12px; margin-bottom: 8px; text-decoration: none; border: 1px solid transparent; border-left: 4px solid transparent; transition: all 0.2s; }
    .cs-ticket:hover { background: var(--cs-bg); border-color: var(--cs-border); border-left-color: var(--cs-border); }
    
    .cs-ticket.unread { background: #fff5f5; border-color: #fecaca; }
    .cs-ticket.active { background: #f1f5f9; border-color: #cbd5e1; border-left: 4px solid var(--cs-dark); }
    .cs-ticket.unread.active { background: #fff5f5; border-color: #fecaca; border-left: 4px solid var(--cs-brand); }
    
    .cs-ticket-name { font-size: 16px; font-weight: 800; color: var(--cs-dark); margin-bottom: 4px; display:flex; justify-content:space-between; align-items:center; gap: 8px; }
    .cs-ticket-meta { font-size: 13px; color: var(--cs-text-muted); margin-bottom: 8px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cs-ticket-preview { font-size: 13px; color: var(--cs-text-muted); display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; margin-bottom: 10px; line-height: 1.5; }
    .cs-unread-dot { width: 8px; height: 8px; border-radius: 999px; background: #ef4444; display: inline-block; box-shadow: 0 0 0 4px rgba(239,68,68,0.15); }
    
    .cs-badge { display: inline-flex; align-items: center; justify-content: center; padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 800; letter-spacing: 0.05em; }
    .cs-badge.open { background: #ef4444; color: #fff; animation: pulse 2s infinite; }
    .cs-badge.answered { background: #e2e8f0; color: #64748b; }
    
    @keyframes pulse {
        0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
        70% { box-shadow: 0 0 0 6px rgba(239, 68, 68, 0); }
        100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
    }

    .cs-main { background: #fff; border: 1px solid var(--cs-border); border-radius: 16px; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02); }
    .cs-main-header { padding: 20px 24px; border-bottom: 1px solid var(--cs-border); display: flex; justify-content: space-between; align-items: center; background: #fff; }
    .cs-main-header h2 { margin: 0 0 6px 0; font-size: 20px; color: var(--cs-dark); }
    .cs-chat-area { flex: 1; background: var(--cs-bg); padding: 24px 24px 12px; overflow-y: auto; display: flex; flex-direction: column; gap: 16px; }
    
    /* 對話列排版 */
    .msg-row { display: flex; gap: 12px; width: 100%; align-items: flex-end; }
    .msg-row.user { flex-direction: row; }
    .msg-row.admin { flex-direction: row-reverse; }
    
    /* 氣泡本體與商品標籤包裝 */
    .msg-content-wrapper { display: flex; flex-direction: column; max-width: 620px; gap: 6px; }
    .msg-row.user .msg-content-wrapper { align-items: flex-start; }
    .msg-row.admin .msg-content-wrapper { align-items: flex-end; }
    
    .msg-bubble { padding: 12px 16px; border-radius: 18px; font-size: 15px; line-height: 1.6; box-shadow: 0 4px 12px rgba(15,23,42,0.06); }
    .msg-row.user .msg-bubble { background: #e5e7eb; color: var(--cs-text-main); border-bottom-left-radius: 6px; }
    .msg-row.admin .msg-bubble { background: var(--cs-brand); color: #fff; border-bottom-right-radius: 6px; }
    .msg-row.is-new .msg-bubble { outline: 2px solid rgba(219,107,107,0.25); }
    
    /* 💡 商品標籤完美復刻前台樣式 */
    .msg-product-link { font-size: 11px; color: var(--cs-brand); text-decoration: none; font-weight: 700; background: #fff; padding: 4px 10px; border-radius: 999px; border: 1px solid #fecaca; display: inline-flex; align-items: center; transition: all 0.2s; }
    .msg-product-link:hover { background: #fef2f2; }
    
    /* 旁邊的時間與按鈕 */
    .msg-aside { display: flex; flex-direction: column; gap: 6px; }
    .msg-row.user .msg-aside { align-items: flex-start; }
    .msg-row.admin .msg-aside { align-items: flex-end; }
    .msg-time { font-size: 11px; color: #94a3b8; }
    .btn-actions-group { display: flex; gap: 6px; flex-wrap: wrap; }
    .btn-action { background: #fff; border: 1px solid var(--cs-border); color: var(--cs-text-muted); font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 999px; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; }
    .btn-action:hover { border-color: var(--cs-brand); color: var(--cs-brand); box-shadow: 0 2px 6px rgba(219,107,107,0.12); }
    
    .cs-input-area { padding: 18px 24px; background: #fff; border-top: 1px solid var(--cs-border); display: flex; gap: 12px; align-items: flex-end; position: sticky; bottom: 0; z-index: 5; }
    .cs-reply-meta { display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--cs-text-muted); margin-bottom: 6px; }
    .cs-reply-tag { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; border: 1px solid #fecaca; background: #fff; color: #b91c1c; font-weight: 700; }
    .cs-textarea-wrapper { flex: 1; border: 1px solid var(--cs-border); border-radius: 12px; padding: 12px 16px; background: #fff; transition: border-color 0.2s; }
    .cs-textarea-wrapper:focus-within { border-color: var(--cs-dark); }
    .cs-textarea-wrapper textarea { width: 100%; border: none; outline: none; resize: none; font-size: 15px; font-family: inherit; line-height: 1.5; min-height: 24px; max-height: 120px; overflow-y: auto; padding: 0; background: transparent; }
    .btn-submit-reply { background: var(--cs-dark); color: #fff; border: none; padding: 0 32px; height: 50px; border-radius: 12px; font-size: 15px; font-weight: 800; cursor: pointer; transition: background 0.2s; white-space: nowrap; }
    .btn-submit-reply:hover { background: #1e293b; }
    .cs-modal-mask { display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); align-items: center; justify-content: center; z-index: 2000; backdrop-filter: blur(2px); }
    .cs-modal { background: #fff; border-radius: 16px; width: min(600px, 90vw); padding: 32px; box-shadow: 0 20px 40px rgba(0,0,0,0.15); }
    .cs-modal h3 { margin: 0 0 24px; font-size: 22px; color: var(--cs-dark); border-bottom: 2px solid #f1f5f9; padding-bottom: 12px; }
    .cs-form-group { margin-bottom: 20px; }
    .cs-form-group label { display: block; font-size: 14px; font-weight: 700; color: #475569; margin-bottom: 8px; }
    .cs-form-input, .cs-form-select { width: 100%; border: 1px solid var(--cs-border); border-radius: 10px; padding: 12px; font-size: 15px; font-family: inherit; background: #fff; box-sizing: border-box; }
    .cs-form-input:focus, .cs-form-select:focus { outline: none; border-color: var(--cs-brand); box-shadow: 0 0 0 3px rgba(219,107,107,0.1); }
    .cs-modal-actions { margin-top: 32px; display: flex; justify-content: flex-end; gap: 12px; }
    .cs-faq-admin { margin-top: 20px; background: #fff; border: 1px solid var(--cs-border); border-radius: 16px; padding: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02); }
    .cs-faq-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
    .cs-faq-head h2 { margin: 0 0 6px; color: var(--cs-dark); font-size: 20px; }
    .cs-faq-table-wrap { overflow-x: auto; border: 1px solid var(--cs-border); border-radius: 12px; }
    .cs-faq-table { width: 100%; min-width: 880px; border-collapse: collapse; font-size: 13px; }
    .cs-faq-table th, .cs-faq-table td { padding: 12px; border-bottom: 1px solid #f1f5f9; text-align: left; vertical-align: top; }
    .cs-faq-table th { background: #f8fafc; color: #64748b; white-space: nowrap; }
    .cs-faq-question { max-width: 260px; font-weight: 800; color: var(--cs-dark); line-height: 1.5; }
    .cs-faq-answer { max-width: 340px; color: #475569; line-height: 1.6; }
    .cs-faq-actions { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
    .cs-faq-actions form { margin: 0; }
    .cs-faq-empty { padding: 24px; text-align: center; color: var(--cs-text-muted); background: #f8fafc; border-radius: 12px; }
    @media (max-width: 820px) {
        .cs-container {
            grid-template-columns: 1fr;
            height: auto;
            min-height: 0;
        }
        .cs-sidebar { max-height: 320px; }
        .cs-main { min-height: 560px; }
        .cs-main-header,
        .cs-input-area {
            align-items: stretch;
            flex-direction: column;
        }
        .btn-submit-reply { width: 100%; }
        .msg-content-wrapper { max-width: 100%; min-width: 0; }
        .msg-row,
        .msg-row.admin {
            align-items: stretch;
            flex-direction: column;
        }
        .msg-row.admin .msg-content-wrapper,
        .msg-row.admin .msg-aside {
            align-items: flex-start;
        }
        .cs-modal {
            width: min(92vw, 600px);
            max-height: 88vh;
            overflow: auto;
            padding: 22px;
        }
        .cs-faq-admin { padding: 16px; }
    }
</style>

<div>
    <h1 style="margin: 0 0 8px 0;">客服管理中心</h1>
    <p class="muted" style="margin: 0;">集中管理客戶工單、回覆訊息與 FAQ 轉換。</p>
</div>

<div class="cs-container">
    <aside class="cs-sidebar">
        <div class="cs-sidebar-list">
            <?php if (empty($tickets)): ?>
                <div style="padding: 24px; text-align: center; color: var(--cs-text-muted);">目前沒有對話。</div>
            <?php else: ?>
                <?php foreach ($tickets as $ticket): ?>
                    <?php
                    $isUnread = $ticket['status'] === 'OPEN';
                    $isActive = intval($ticket['ticket_id']) === $selectedTicketId;
                    
                    $ticketClass = 'cs-ticket';
                    if ($isUnread) $ticketClass .= ' unread';
                    if ($isActive) $ticketClass .= ' active';
                    
                    $statusClass = $isUnread ? 'open' : 'answered';
                    $statusText = $isUnread ? '🔴 新訊息' : '已回覆';
                    
                    $sidebarUserName = !empty($ticket['user_name']) ? $ticket['user_name'] : ('會員 #' . intval($ticket['user_id']));
                    $productDisplay = !empty($ticket['latest_product_name']) ? h($ticket['latest_product_name']) : '一般問題';
                    ?>
                    <a href="backend.php?page=customer_service&ticket_id=<?php echo intval($ticket['ticket_id']); ?>" class="<?php echo $ticketClass; ?>">
                        <div class="cs-ticket-name">
                            <?php echo h($sidebarUserName); ?>
                            <?php if ($isUnread): ?>
                                <span class="cs-unread-dot" title="未讀"></span>
                            <?php endif; ?>
                            <span class="cs-badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                        </div>
                        <div class="cs-ticket-meta">工單 #<?php echo intval($ticket['ticket_id']); ?> · 📍 <?php echo $productDisplay; ?></div>
                        <div class="cs-ticket-preview"><?php echo h($ticket['last_message'] ?: '尚無訊息'); ?></div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </aside>

    <section class="cs-main">
        <?php if (!$selectedTicket): ?>
            <div style="flex: 1; display: flex; align-items: center; justify-content: center; color: var(--cs-text-muted);">請從左側選擇一位會員開始對話。</div>
        <?php else: ?>
            <div class="cs-main-header">
                <div>
                    <?php $chatUserName = !empty($selectedTicket['user_name']) ? $selectedTicket['user_name'] : ('會員 #' . $selectedTicket['user_id']); ?>
                    <h2>與 <?php echo h($chatUserName); ?> 對話中</h2>
                    <div class="cs-ticket-meta" style="margin:0; color:#64748b;">工單編號 #<?php echo intval($selectedTicket['ticket_id']); ?></div>
                </div>
                <span class="cs-badge <?php echo $selectedTicket['status'] === 'OPEN' ? 'open' : 'answered'; ?>" style="font-size: 13px; padding: 6px 14px;">
                    <?php echo $selectedTicket['status'] === 'OPEN' ? '待處理 (OPEN)' : '已回覆 (ANSWERED)'; ?>
                </span>
            </div>

            <div class="cs-chat-area" id="csMessages">
                <?php if (empty($messages)): ?>
                    <div style="text-align: center; color: var(--cs-text-muted); padding: 40px;">尚無對話內容。</div>
                <?php else: ?>
                    <?php foreach ($messages as $msg): ?>
                        <?php
                        $isUser = $msg['sender_type'] === 'USER';
                        $faqAnswer = $isUser ? ($nextAdminReply[(int)$msg['message_id']] ?? '') : '';
                        $msgClass = $isUser ? 'user' : 'admin';
                        $msgProductId = !empty($msg['product_id']) ? (int)$msg['product_id'] : 0;
                        $msgProductLabel = $msgProductId > 0 ? ($msg['product_name'] ?: '#' . $msgProductId) : '一般問題';
                        $isNew = $wasOpen && $isUser && ((int)$msg['message_id'] === $lastUserMessageId);
                        ?>
                        <div class="msg-row <?php echo $msgClass; ?> <?php echo $isNew ? 'is-new' : ''; ?>">
                            <div class="msg-content-wrapper">
                                <div class="msg-bubble"><?php echo nl2br(h($msg['message_text'])); ?></div>
                                <!-- 💡 乾淨的商品標籤排版，完全比照前台 -->
                                <?php if ($isUser && $msgProductId > 0): ?>
                                    <!-- ⚠️ 如果你的前台商品頁不是 product_detail.php，請改掉這裡的 href -->
                                    <a href="../homepage/product_detail.php?id=<?php echo $msgProductId; ?>" target="_blank" class="msg-product-link" title="開新分頁查看商品">
                                        📍 相關商品：<?php echo h($msgProductLabel); ?> ↗
                                    </a>
                                <?php endif; ?>
                            </div>

                            <div class="msg-aside">
                                <span class="msg-time"><?php echo date('Y-m-d H:i', strtotime($msg['created_at'])); ?></span>
                                <?php if ($isUser): ?>
                                    <div class="btn-actions-group">
                                        <button type="button" class="btn-action js-quote-btn" data-text="<?php echo h($msg['message_text']); ?>" data-product-id="<?php echo h($msgProductId); ?>" data-product-name="<?php echo h($msgProductLabel); ?>" title="回覆此問題">回覆</button>
                                        <button type="button" class="btn-action js-faq-btn" data-question="<?php echo h($msg['message_text']); ?>" data-answer="<?php echo h($faqAnswer); ?>" data-product-id="<?php echo h($msgProductId); ?>" title="收錄至商品 FAQ">FAQ</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <form class="cs-input-area" method="POST" action="backend_action.php">
                <?php echo apCsrfField(); ?>
                <input type="hidden" name="action" value="reply_ticket_message">
                <input type="hidden" name="ticket_id" value="<?php echo intval($selectedTicket['ticket_id']); ?>">
                <input type="hidden" name="return_to" value="backend.php?page=customer_service&ticket_id=<?php echo intval($selectedTicket['ticket_id']); ?>">
                <input type="hidden" name="product_id" id="replyProductId" value="<?php echo h($currentProductId); ?>">
                
                <div class="cs-textarea-wrapper">
                    <div class="cs-reply-meta">
                        <span>本次回覆：</span>
                        <span class="cs-reply-tag" id="replyProductLabel"><?php echo h($defaultReplyLabel); ?></span>
                    </div>
                    <textarea name="message_text" id="replyInput" placeholder="輸入客服回覆內容... (按 Enter 即可送出)" required rows="1"></textarea>
                </div>
                <button type="submit" class="btn-submit-reply">送出</button>
            </form>
        <?php endif; ?>
    </section>
</div>

<section class="cs-faq-admin">
    <div class="cs-faq-head">
        <div>
            <h2>FAQ 管理</h2>
            <p class="muted" style="margin:0;">可編輯客服收錄的商品 FAQ，打錯可停用或重新啟用。</p>
        </div>
        <button type="button" class="pm-btn pm-btn-main js-faq-create">新增 FAQ</button>
    </div>
    <?php if (empty($productFaqs)): ?>
        <div class="cs-faq-empty">目前尚未建立 FAQ。</div>
    <?php else: ?>
        <div class="cs-faq-table-wrap">
            <table class="cs-faq-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>類型 / 商品</th>
                        <th>問題</th>
                        <th>答案</th>
                        <th>狀態</th>
                        <th>更新時間</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($productFaqs as $faq): ?>
                        <?php
                        $faqProductId = (int)($faq['product_id'] ?? 0);
                        $faqType = (string)($faq['qa_type'] ?? 'PRODUCT');
                        $faqActive = (int)($faq['is_active'] ?? 1) === 1;
                        ?>
                        <tr>
                            <td>#<?php echo (int)$faq['qa_id']; ?></td>
                            <td>
                                <span class="cs-badge <?php echo $faqType === 'GENERAL' ? 'answered' : 'open'; ?>"><?php echo $faqType === 'GENERAL' ? '通用' : '商品'; ?></span>
                                <div style="margin-top:6px; color:#64748b;"><?php echo $faqProductId > 0 ? h($faq['product_name'] ?: ('#' . $faqProductId)) : '不綁定商品'; ?></div>
                            </td>
                            <td class="cs-faq-question"><?php echo nl2br(h($faq['question'])); ?></td>
                            <td class="cs-faq-answer"><?php echo nl2br(h($faq['answer'])); ?></td>
                            <td><span class="cs-badge <?php echo $faqActive ? 'answered' : 'open'; ?>"><?php echo $faqActive ? '啟用' : '停用'; ?></span></td>
                            <td><?php echo h(substr((string)($faq['updated_at'] ?? $faq['created_at'] ?? ''), 0, 16)); ?></td>
                            <td>
                                <div class="cs-faq-actions">
                                    <button type="button"
                                            class="btn-action js-faq-edit"
                                            data-qa-id="<?php echo (int)$faq['qa_id']; ?>"
                                            data-question="<?php echo h($faq['question']); ?>"
                                            data-answer="<?php echo h($faq['answer']); ?>"
                                            data-type="<?php echo h($faqType); ?>"
                                            data-product-id="<?php echo $faqProductId > 0 ? $faqProductId : ''; ?>">編輯</button>
                                    <form method="POST" action="backend_action.php">
                                        <?php echo apCsrfField(); ?>
                                        <input type="hidden" name="action" value="toggle_product_qa">
                                        <input type="hidden" name="qa_id" value="<?php echo (int)$faq['qa_id']; ?>">
                                        <input type="hidden" name="new_status" value="<?php echo $faqActive ? '0' : '1'; ?>">
                                        <input type="hidden" name="return_to" value="<?php echo h('backend.php?page=customer_service&ticket_id=' . intval($selectedTicketId)); ?>">
                                        <button type="submit" class="btn-action"><?php echo $faqActive ? '停用' : '啟用'; ?></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<div class="cs-modal-mask" id="csFaqModal">
    <div class="cs-modal">
        <h3>新增常見問題</h3>
        <form method="POST" action="backend_action.php">
            <?php echo apCsrfField(); ?>
            <input type="hidden" name="action" value="add_product_qa" id="csFaqAction">
            <input type="hidden" name="qa_id" value="" id="csFaqId">
            <input type="hidden" name="return_to" value="<?php echo h('backend.php?page=customer_service&ticket_id=' . intval($selectedTicketId)); ?>">

            <div class="cs-form-group">
                <label for="csFaqQuestion">問題</label>
                <textarea class="cs-form-input" name="question" id="csFaqQuestion" rows="3" required></textarea>
            </div>
            <div class="cs-form-group">
                <label for="csFaqAnswer">回答</label>
                <textarea class="cs-form-input" name="answer" id="csFaqAnswer" rows="4" required></textarea>
            </div>
            <div class="cs-form-group">
                <label for="csFaqType">FAQ 類型</label>
                <select class="cs-form-select" name="qa_type" id="csFaqType">
                    <option value="PRODUCT">商品專屬</option>
                    <option value="GENERAL">通用問題</option>
                </select>
            </div>
            <div class="cs-form-group">
                <label for="csFaqProduct">關聯商品</label>
                <select class="cs-form-select" name="product_id" id="csFaqProduct">
                    <option value="">一般問題 (不指定商品)</option>
                    <?php foreach ($allProducts as $prod): ?>
                        <option value="<?php echo intval($prod['product_id']); ?>"><?php echo h($prod['name']); ?> (ID: <?php echo intval($prod['product_id']); ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="cs-modal-actions">
                <button type="button" class="pm-btn pm-btn-sub" id="csFaqCancel" style="padding: 10px 20px; border-radius: 8px; border: 1px solid var(--cs-border); background: #fff; cursor: pointer;">取消</button>
                <button type="submit" class="pm-btn pm-btn-main" style="padding: 10px 20px; border-radius: 8px; border: none; background: var(--cs-brand); color: #fff; cursor: pointer; font-weight: bold;">送出</button>
            </div>
        </form>
    </div>
</div>

<script>
    const faqModal = document.getElementById('csFaqModal');
    const faqQuestion = document.getElementById('csFaqQuestion');
    const faqAnswer = document.getElementById('csFaqAnswer');
    const faqType = document.getElementById('csFaqType');
    const faqProduct = document.getElementById('csFaqProduct');
    const faqAction = document.getElementById('csFaqAction');
    const faqId = document.getElementById('csFaqId');
    const faqCancel = document.getElementById('csFaqCancel');
    const replyInput = document.getElementById('replyInput');
    const replyProductId = document.getElementById('replyProductId');
    const replyProductLabel = document.getElementById('replyProductLabel');

    function setReplyProduct(productId, productName) {
        if (!replyProductId || !replyProductLabel) {
            return;
        }
        const cleanId = String(productId || '').trim();
        const cleanName = String(productName || '').trim();
        replyProductId.value = cleanId;
        if (cleanId) {
            replyProductLabel.textContent = '商品：' + (cleanName !== '' ? cleanName : ('#' + cleanId));
        } else {
            replyProductLabel.textContent = '一般問題';
        }
    }

    function openFaqModal({ qaId = '', question = '', answer = '', productId = '', qaType = '' }) {
        if (!faqModal) {
            return;
        }
        if (faqAction) {
            faqAction.value = qaId ? 'update_product_qa' : 'add_product_qa';
        }
        if (faqId) {
            faqId.value = qaId || '';
        }
        faqQuestion.value = question;
        faqAnswer.value = answer;
        faqProduct.value = productId || '';
        faqType.value = qaType || (productId ? 'PRODUCT' : 'GENERAL');
        faqProduct.disabled = faqType.value === 'GENERAL';
        faqModal.style.display = 'flex';
    }

    document.querySelectorAll('.js-faq-create').forEach(btn => {
        btn.addEventListener('click', () => {
            openFaqModal({});
        });
    });

    document.querySelectorAll('.js-faq-edit').forEach(btn => {
        btn.addEventListener('click', () => {
            openFaqModal({
                qaId: btn.dataset.qaId || '',
                question: btn.dataset.question || '',
                answer: btn.dataset.answer || '',
                productId: btn.dataset.productId || '',
                qaType: btn.dataset.type || ''
            });
        });
    });

    document.querySelectorAll('.js-faq-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            openFaqModal({
                question: btn.dataset.question || '',
                answer: btn.dataset.answer || '',
                productId: btn.dataset.productId || ''
            });
        });
    });

    document.querySelectorAll('.js-quote-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            if (!replyInput) {
                return;
            }
            const quote = (btn.dataset.text || '').trim();
            if (!quote) {
                return;
            }
            setReplyProduct(btn.dataset.productId || '', btn.dataset.productName || '');
            const prefix = '回覆：' + quote;
            const current = replyInput.value.trim();
            replyInput.value = current ? (current + "\n" + prefix + "\n") : (prefix + "\n");
            replyInput.focus();
        });
    });

    if (faqType) {
        faqType.addEventListener('change', () => {
            if (!faqProduct) {
                return;
            }
            const isGeneral = faqType.value === 'GENERAL';
            faqProduct.disabled = isGeneral;
            if (isGeneral) {
                faqProduct.value = '';
            }
        });
    }

    if (faqCancel) {
        faqCancel.addEventListener('click', () => {
            faqModal.style.display = 'none';
        });
    }

    if (faqModal) {
        faqModal.addEventListener('click', event => {
            if (event.target === faqModal) {
                faqModal.style.display = 'none';
            }
        });
    }

    const messagesBox = document.getElementById('csMessages');
    function scrollToBottom() {
        if (messagesBox) {
            messagesBox.scrollTop = messagesBox.scrollHeight;
        }
    }

    window.addEventListener('load', scrollToBottom);

    if (replyInput) {
        replyInput.addEventListener('keydown', event => {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                replyInput.closest('form').submit();
            }
        });
    }
</script>

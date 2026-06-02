<footer class="site-footer">
        <div class="site-footer-inner">
            <p>© <?php echo date('Y'); ?> All Pass 行李箱專賣</p>
            <a href="index.php">回首頁</a>
        </div>
    </footer>

    <div class="chat-widget" id="chatWidget">
        <button class="chat-toggle" id="chatToggle" type="button" title="聯絡客服">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path>
            </svg>
        </button>
        <div class="chat-panel" id="chatPanel">
            <div class="chat-header">
                <span>線上客服</span>
                <button class="chat-close" id="chatClose" type="button">×</button>
            </div>
            <div class="chat-messages" id="chatMessages"></div>
            <div class="chat-input">
                <textarea id="chatInput" placeholder="輸入訊息... (按 Enter 送出)" rows="2"></textarea>
                <button id="chatSend" type="button">送出</button>
            </div>
            <div class="chat-hint" id="chatHint">請先登入才能使用客服。</div>
        </div>
    </div>

    <style>
        .chat-widget { position: fixed; right: 20px; bottom: 20px; z-index: 1200; font-family: 'PingFang TC', 'Microsoft JhengHei', sans-serif; }
        
        /* 💡 圓形懸浮按鈕 (FAB) 樣式 */
        .chat-toggle { position: relative; background: #111; color: #fff; border: none; border-radius: 50%; width: 60px; height: 60px; display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 10px 24px rgba(0,0,0,0.15); transition: transform 0.2s; }
        .chat-toggle:hover { transform: scale(1.05); }
        .chat-toggle.has-unread::after { content: ''; position: absolute; top: 2px; right: 2px; width: 14px; height: 14px; background: #ef4444; border: 2px solid #fff; border-radius: 50%; }
        
        .chat-panel { position: absolute; right: 0; bottom: 76px; width: 320px; background: #fff; border-radius: 16px; box-shadow: 0 12px 30px rgba(0,0,0,0.18); display: none; flex-direction: column; overflow: hidden; }
        .chat-panel.is-open { display: flex; }
        .chat-header { background: #111; color: #fff; padding: 12px 14px; display: flex; align-items: center; justify-content: space-between; font-weight: 700; }
        .chat-close { background: transparent; border: none; color: #fff; font-size: 18px; cursor: pointer; }
        .chat-messages { padding: 14px; background: #f8fafc; max-height: 360px; overflow-y: auto; display: flex; flex-direction: column; gap: 14px; }
        
        .chat-msg-wrap { display: flex; flex-direction: column; max-width: 85%; }
        .chat-msg-wrap.user { align-self: flex-end; align-items: flex-end; }
        .chat-msg-wrap.admin { align-self: flex-start; align-items: flex-start; }
        .chat-msg { padding: 10px 12px; border-radius: 12px; font-size: 14px; line-height: 1.5; white-space: pre-wrap; box-shadow: 0 1px 2px rgba(0,0,0,0.02); }
        .chat-msg-wrap.user .chat-msg { background: #111; color: #fff; border-bottom-right-radius: 2px; }
        .chat-msg-wrap.admin .chat-msg { background: #fff; border: 1px solid #e5e7eb; color: #111; border-bottom-left-radius: 2px; }
        
        /* 💡 前台商品小標籤 */
        .chat-msg-product { font-size: 11px; color: #db6b6b; font-weight: 700; margin-top: 6px; background: #fff; padding: 3px 8px; border-radius: 999px; border: 1px solid #fecaca; text-decoration: none; display: inline-block; transition: background 0.2s; }
        .chat-msg-product.general { color: #64748b; border-color: #e2e8f0; cursor: default; }
        .chat-msg-product:not(.general):hover { background: #fef2f2; }

        .chat-input { display: flex; gap: 8px; padding: 12px; border-top: 1px solid #eee; background: #fff; }
        .chat-input textarea { flex: 1; resize: none; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 10px; font-size: 14px; font-family: inherit; }
        .chat-input textarea:focus { outline: none; border-color: #db6b6b; }
        .chat-input button { background: #db6b6b; color: #fff; border: none; border-radius: 8px; padding: 8px 14px; font-weight: 700; cursor: pointer; transition: background 0.2s; }
        .chat-input button:hover { background: #c25959; }
        .chat-hint { padding: 8px 12px 12px; font-size: 12px; color: #c2410c; display: none; text-align: center; }
        .chat-panel.is-locked .chat-input { opacity: 0.5; pointer-events: none; }
        .chat-panel.is-locked .chat-hint { display: block; }
        @media (max-width: 640px) { .chat-panel { width: min(92vw, 320px); } }
    </style>

    <?php 
    if(file_exists(__DIR__ . '/includes/security.php')) {
        require_once __DIR__ . '/includes/security.php'; 
        if(function_exists('apCsrfFormScript')) echo apCsrfFormScript(); 
    }
    ?>

    <script>
        (function () {
            const isLoggedIn = <?php echo isset($_SESSION['user_id']) ? 'true' : 'false'; ?>;
            const csrfToken = '<?php echo function_exists("apCsrfToken") ? apCsrfToken() : ""; ?>';
            const widget = document.getElementById('chatWidget');
            const toggleBtn = document.getElementById('chatToggle');
            const panel = document.getElementById('chatPanel');
            const closeBtn = document.getElementById('chatClose');
            const messagesBox = document.getElementById('chatMessages');
            const input = document.getElementById('chatInput');
            const sendBtn = document.getElementById('chatSend');

            let ticketId = 0;
            let lastMessageId = 0;
            let isFirstLoad = true;

            const urlParams = new URLSearchParams(window.location.search);
            const productId = parseInt(urlParams.get('id') || '0', 10) || 0;

            function setOpen(open) {
                if (!panel) return;
                panel.classList.toggle('is-open', open);
                if (open) {
                    toggleBtn.classList.remove('has-unread');
                    scrollToBottom();
                }
            }

            function scrollToBottom() {
                if (messagesBox) messagesBox.scrollTop = messagesBox.scrollHeight;
            }

            function appendMessage(msg) {
                const wrap = document.createElement('div');
                wrap.className = 'chat-msg-wrap ' + (msg.sender_type === 'ADMIN' ? 'admin' : 'user');
                
                const div = document.createElement('div');
                div.className = 'chat-msg';
                div.textContent = msg.message_text;
                wrap.appendChild(div);

                if (msg.sender_type === 'USER') {
                    const prodTag = document.createElement(msg.product_id > 0 ? 'a' : 'span');
                    if (msg.product_id > 0 && msg.product_name) {
                        prodTag.className = 'chat-msg-product';
                        prodTag.href = 'product_detail.php?id=' + msg.product_id;
                        prodTag.target = '_blank';
                        prodTag.textContent = '📍 ' + msg.product_name + ' ↗';
                    } else {
                        prodTag.className = 'chat-msg-product general';
                        prodTag.textContent = '📍 一般問題';
                    }
                    wrap.appendChild(prodTag);
                }

                messagesBox.appendChild(wrap);
                scrollToBottom();
                lastMessageId = Math.max(lastMessageId, parseInt(msg.message_id, 10) || 0);
            }

            function sendRequest(action, data) {
                const body = new URLSearchParams(Object.assign({ action, csrf_token: csrfToken }, data));
                // 💡 完美修復點：退回上一層，進入 backend 資料夾找 API！
                return fetch('chat_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                }).then(res => res.json());
            }

            function backgroundSync() {
                if (isFirstLoad) {
                    sendRequest('load_messages', {}).then(data => {
                        if (!data.success) return;
                        ticketId = data.ticket_id || 0;
                        messagesBox.innerHTML = '';
                        (data.messages || []).forEach(appendMessage);
                        isFirstLoad = false;
                    });
                } else if (ticketId > 0) {
                    sendRequest('poll_messages', { ticket_id: ticketId, last_message_id: lastMessageId })
                        .then(data => {
                            if (!data.success) return;
                            let hasNewAdminMsg = false;
                            (data.messages || []).forEach(msg => {
                                appendMessage(msg);
                                if (msg.sender_type === 'ADMIN') hasNewAdminMsg = true;
                            });
                            
                            if (hasNewAdminMsg && !panel.classList.contains('is-open')) {
                                toggleBtn.classList.add('has-unread');
                            }
                        });
                }
            }

            function sendMessage() {
                const text = (input.value || '').trim();
                if (!text) return;
                sendBtn.disabled = true;
                
                sendRequest('send_message', { message_text: text, product_id: productId })
                    .then(data => {
                        if (data.success) {
                            ticketId = data.ticket_id || ticketId;
                            if (isFirstLoad) isFirstLoad = false;
                            (data.messages || []).forEach(appendMessage);
                            input.value = '';
                        }
                    })
                    .finally(() => { sendBtn.disabled = false; input.focus(); });
            }

            if (!isLoggedIn && panel) panel.classList.add('is-locked');
            else if (isLoggedIn) {
                backgroundSync();
                setInterval(backgroundSync, 5000);
            }

            if (toggleBtn) toggleBtn.addEventListener('click', () => setOpen(!panel.classList.contains('is-open')));
            if (closeBtn) closeBtn.addEventListener('click', () => setOpen(false));
            if (sendBtn) sendBtn.addEventListener('click', sendMessage);
            if (input) {
                input.addEventListener('keydown', event => {
                    if (event.key === 'Enter' && !event.shiftKey) {
                        event.preventDefault();
                        sendMessage();
                    }
                });
            }
        })();
    </script>
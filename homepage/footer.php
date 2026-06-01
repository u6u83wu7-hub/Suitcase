    <footer class="site-footer">
        <div class="site-footer-inner">
            <p>© <?php echo date('Y'); ?> All Pass 行李箱專賣</p>
            <a href="index.php">回首頁</a>
        </div>
    </footer>

    <div class="chat-widget" id="chatWidget">
        <button class="chat-toggle" id="chatToggle" type="button">客服</button>
        <div class="chat-panel" id="chatPanel">
            <div class="chat-header">
                <span>線上客服</span>
                <button class="chat-close" id="chatClose" type="button">×</button>
            </div>
            <div class="chat-messages" id="chatMessages"></div>
            <div class="chat-input">
                <textarea id="chatInput" placeholder="輸入訊息..." rows="2"></textarea>
                <button id="chatSend" type="button">送出</button>
            </div>
            <div class="chat-hint" id="chatHint">請先登入才能使用客服。</div>
        </div>
    </div>

    <style>
        .chat-widget { position: fixed; right: 20px; bottom: 20px; z-index: 1200; font-family: 'PingFang TC', 'Microsoft JhengHei', sans-serif; }
        .chat-toggle { background: #111; color: #fff; border: none; border-radius: 999px; padding: 12px 18px; font-weight: 700; cursor: pointer; box-shadow: 0 10px 24px rgba(0,0,0,0.15); }
        .chat-panel { position: absolute; right: 0; bottom: 60px; width: 320px; background: #fff; border-radius: 16px; box-shadow: 0 12px 30px rgba(0,0,0,0.18); display: none; flex-direction: column; overflow: hidden; }
        .chat-panel.is-open { display: flex; }
        .chat-header { background: #111; color: #fff; padding: 12px 14px; display: flex; align-items: center; justify-content: space-between; font-weight: 700; }
        .chat-close { background: transparent; border: none; color: #fff; font-size: 18px; cursor: pointer; }
        .chat-messages { padding: 12px; background: #f8fafc; max-height: 320px; overflow-y: auto; display: flex; flex-direction: column; gap: 10px; }
        .chat-msg { padding: 8px 10px; border-radius: 10px; font-size: 14px; line-height: 1.5; white-space: pre-wrap; }
        .chat-msg.user { background: #111; color: #fff; align-self: flex-end; }
        .chat-msg.admin { background: #fff; border: 1px solid #e5e7eb; color: #111; align-self: flex-start; }
        .chat-input { display: flex; gap: 8px; padding: 10px; border-top: 1px solid #eee; background: #fff; }
        .chat-input textarea { flex: 1; resize: none; border: 1px solid #e2e8f0; border-radius: 8px; padding: 6px 8px; font-size: 14px; }
        .chat-input button { background: #db6b6b; color: #fff; border: none; border-radius: 8px; padding: 6px 12px; font-weight: 700; cursor: pointer; }
        .chat-hint { padding: 8px 12px 12px; font-size: 12px; color: #c2410c; display: none; }
        .chat-panel.is-locked .chat-input { opacity: 0.5; pointer-events: none; }
        .chat-panel.is-locked .chat-hint { display: block; }
        @media (max-width: 640px) {
            .chat-panel { width: min(92vw, 320px); }
        }
    </style>

    <script>
        (function () {
            const isLoggedIn = <?php echo isset($_SESSION['user_id']) ? 'true' : 'false'; ?>;
            const widget = document.getElementById('chatWidget');
            const toggleBtn = document.getElementById('chatToggle');
            const panel = document.getElementById('chatPanel');
            const closeBtn = document.getElementById('chatClose');
            const messagesBox = document.getElementById('chatMessages');
            const input = document.getElementById('chatInput');
            const sendBtn = document.getElementById('chatSend');

            let ticketId = 0;
            let lastMessageId = 0;
            let pollTimer = null;

            const urlParams = new URLSearchParams(window.location.search);
            const productId = parseInt(urlParams.get('id') || '0', 10) || 0;

            function setOpen(open) {
                if (!panel) {
                    return;
                }
                panel.classList.toggle('is-open', open);
                if (open && isLoggedIn) {
                    loadMessages();
                    if (!pollTimer) {
                        pollTimer = setInterval(pollMessages, 5000);
                    }
                }
                if (!open && pollTimer) {
                    clearInterval(pollTimer);
                    pollTimer = null;
                }
            }

            function appendMessage(msg) {
                const div = document.createElement('div');
                div.className = 'chat-msg ' + (msg.sender_type === 'ADMIN' ? 'admin' : 'user');
                div.textContent = msg.message_text;
                messagesBox.appendChild(div);
                messagesBox.scrollTop = messagesBox.scrollHeight;
                lastMessageId = Math.max(lastMessageId, parseInt(msg.message_id, 10) || 0);
            }

            function sendRequest(action, data) {
                const body = new URLSearchParams(Object.assign({ action }, data));
                return fetch('chat_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                }).then(res => res.json());
            }

            function loadMessages() {
                messagesBox.innerHTML = '';
                lastMessageId = 0;
                sendRequest('load_messages', { product_id: productId })
                    .then(data => {
                        if (!data.success) {
                            return;
                        }
                        ticketId = data.ticket_id || 0;
                        (data.messages || []).forEach(appendMessage);
                    })
                    .catch(() => {});
            }

            function pollMessages() {
                if (!ticketId) {
                    return;
                }
                sendRequest('poll_messages', { ticket_id: ticketId, last_message_id: lastMessageId })
                    .then(data => {
                        if (!data.success) {
                            return;
                        }
                        (data.messages || []).forEach(appendMessage);
                    })
                    .catch(() => {});
            }

            function sendMessage() {
                const text = (input.value || '').trim();
                if (!text) {
                    return;
                }
                sendBtn.disabled = true;
                sendRequest('send_message', { message_text: text, product_id: productId })
                    .then(data => {
                        if (data.success) {
                            ticketId = data.ticket_id || ticketId;
                            (data.messages || []).forEach(appendMessage);
                            input.value = '';
                        }
                    })
                    .finally(() => { sendBtn.disabled = false; });
            }

            if (!isLoggedIn && panel) {
                panel.classList.add('is-locked');
            }

            if (toggleBtn) {
                toggleBtn.addEventListener('click', () => setOpen(true));
            }
            if (closeBtn) {
                closeBtn.addEventListener('click', () => setOpen(false));
            }
            if (sendBtn) {
                sendBtn.addEventListener('click', sendMessage);
            }
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
</body>
</html>
<?php
// system.php - 系統與權限管理獨立內頁
require_once __DIR__ . '/auth_guard.php';

// 1. 安全防護：強制檢查目前登入的管理者是不是 Super Admin (role_id = 1)
$current_admin = $_SESSION['admin_username'];
$stmt_check = $conn->prepare("SELECT role_id FROM admin_users WHERE username = ? LIMIT 1");
$stmt_check->bind_param("s", $current_admin);
$stmt_check->execute();
$current_role = $stmt_check->get_result()->fetch_assoc()['role_id'] ?? 2;
$stmt_check->close();

if (intval($current_role) !== 1) {
    echo '<div style="padding:40px; text-align:center; background:#fff1f2; border:1px solid #fecaca; border-radius:12px; color:#991b1b;">';
    echo '<h2>⚠️ 存取權限不足</h2>';
    echo '<p>您目前的主機帳號群組屬於「客服專員」，無權查閱或更動系統安全性設定。</p>';
    echo '<p><a href="backend.php?page=dashboard" style="color:#db6b6b; font-weight:700; text-decoration:none;">返回儀表板</a></p>';
    echo '</div>';
    return; // 阻斷後續所有 HTML 渲染
}

$msg = ''; $msg_type = 'success';

function sysWriteAdminAudit($conn, $action, $targetType, $targetId, $message) {
    $auditTableResult = $conn->query("SHOW TABLES LIKE 'admin_audit_logs'");
    if (!$auditTableResult || $auditTableResult->num_rows <= 0) {
        return;
    }

    $adminId = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;
    $targetId = $targetId !== null ? (int)$targetId : null;
    $stmt = $conn->prepare('INSERT INTO admin_audit_logs (admin_id, action, target_type, target_id, message) VALUES (?, ?, ?, ?, ?)');
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('issis', $adminId, $action, $targetType, $targetId, $message);
    $stmt->execute();
    $stmt->close();
}

// 2. 處理後端 POST 表單提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['system_action'])) {
    if (!apValidateCsrf()) {
        $msg = '安全憑證失效，請重新操作。'; $msg_type = 'error';
    } else {
        $action = $_POST['system_action'];
        
        // 新增管理者
        if ($action === 'create_admin') {
            $new_user = trim($_POST['username'] ?? '');
            $new_pass = $_POST['password'] ?? '';
            $new_role = intval($_POST['role_id'] ?? 2);
            $supplier_name = trim($_POST['supplier_name'] ?? '');
            $contact_person = trim($_POST['contact_person'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');

            if ($new_user === '' || $new_pass === '') {
                $msg = '帳號或密碼欄位不可留白！'; $msg_type = 'error';
            } elseif ($new_role === 3 && $supplier_name === '') {
                $msg = '選擇廠商角色時，請輸入廠商名稱！'; $msg_type = 'error';
            } else {
                $conn->begin_transaction();
                try {
                    // 檢查帳號是否重複
                    $stmt = $conn->prepare("SELECT admin_id FROM admin_users WHERE username = ? LIMIT 1");
                    $stmt->bind_param("s", $new_user);
                    $stmt->execute();
                    if ($stmt->get_result()->num_rows > 0) {
                        throw new Exception('建立失敗：此管理員帳號已存在！');
                    }
                    $stmt->close();

                    // 💡 安全核心：使用 BCRYPT 雜湊法對新密碼加密
                    $hash = password_hash($new_pass, PASSWORD_BCRYPT);
                    $ins = $conn->prepare("INSERT INTO admin_users (role_id, username, password_hash, status) VALUES (?, ?, ?, 'ACTIVE')");
                    $ins->bind_param("iss", $new_role, $new_user, $hash);
                    if (!$ins->execute()) {
                        throw new Exception('資料庫寫入異常。');
                    }
                    $adminId = $conn->insert_id;
                    $ins->close();

                    if ($new_role === 3) {
                        $supplierStmt = $conn->prepare("INSERT INTO suppliers (admin_id, name, contact_person, phone, email) VALUES (?, ?, ?, ?, ?)");
                        $supplierStmt->bind_param("issss", $adminId, $supplier_name, $contact_person, $phone, $email);
                        if (!$supplierStmt->execute()) {
                            throw new Exception('廠商資料寫入失敗。');
                        }
                        $supplierStmt->close();
                    }

                    $conn->commit();
                    sysWriteAdminAudit($conn, 'create_admin', 'admin_user', $adminId, '建立後台管理員帳號');
                    $msg = $new_role === 3
                        ? '成功建立廠商帳號並寫入廠商資料：' . htmlspecialchars($new_user)
                        : '成功建立全新管理員帳號：' . htmlspecialchars($new_user);
                } catch (Exception $e) {
                    $conn->rollback();
                    $msg = $e->getMessage();
                    $msg_type = 'error';
                }
            }
        }
        
        // 修改管理者狀態與權限
        if ($action === 'update_admin') {
            $target_id = intval($_POST['admin_id']);
            $target_role = intval($_POST['role_id']);
            $target_status = $_POST['status'] === 'ACTIVE' ? 'ACTIVE' : 'DISABLED';

            // 防呆：不允許自己把自己停權，或者自己把自己的權限降級
            if ($target_id === intval($_SESSION['admin_id'] ?? 0)) {
                $msg = '安全保護：您無法在登入狀態下變更或停權您自己的權限！'; $msg_type = 'error';
            } else {
                $up = $conn->prepare("UPDATE admin_users SET role_id = ?, status = ? WHERE admin_id = ?");
                $up->bind_param("isi", $target_role, $target_status, $target_id);
                if ($up->execute()) {
                    $msg = '管理員權限設定更新成功！';
                    sysWriteAdminAudit($conn, 'update_admin', 'admin_user', $target_id, '更新後台管理員權限或狀態');
                }
                $up->close();
            }
        }
    }
}

// 3. 撈取目前所有的管理員清單
$admins = [];
$res = $conn->query("SELECT admin_id, role_id, username, status, created_at FROM admin_users ORDER BY admin_id ASC");
if ($res) {
    while ($row = $res->fetch_assoc()) { $admins[] = $row; }
}

function sysTableExists($conn, $tableName) {
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $res && $res->num_rows > 0;
}

function sysFetchRows($conn, $sql) {
    $rows = [];
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    return $rows;
}

$lowStockRows = sysFetchRows($conn, "
    SELECT p.product_id, p.name AS product_name, pv.variant_id, pv.sku_code, pv.color, pv.size_inches, pv.stock_available
    FROM product_variants pv
    JOIN products p ON p.product_id = pv.product_id
    WHERE pv.stock_available <= 5
    ORDER BY pv.stock_available ASC, p.product_id DESC
    LIMIT 20
");

$inventoryLogRows = sysTableExists($conn, 'inventory_adjustment_logs')
    ? sysFetchRows($conn, "
        SELECT l.*, p.name AS product_name, au.username AS admin_username
        FROM inventory_adjustment_logs l
        LEFT JOIN products p ON p.product_id = l.product_id
        LEFT JOIN admin_users au ON au.admin_id = l.admin_id
        ORDER BY l.created_at DESC, l.log_id DESC
        LIMIT 12
    ")
    : [];

$securityAttemptRows = sysTableExists($conn, 'security_attempts')
    ? sysFetchRows($conn, "
        SELECT scope, identifier, ip_address, success, created_at
        FROM security_attempts
        ORDER BY created_at DESC, attempt_id DESC
        LIMIT 12
    ")
    : [];

$auditRows = sysTableExists($conn, 'admin_audit_logs')
    ? sysFetchRows($conn, "
        SELECT al.*, au.username AS admin_username
        FROM admin_audit_logs al
        LEFT JOIN admin_users au ON au.admin_id = al.admin_id
        ORDER BY al.created_at DESC, al.log_id DESC
        LIMIT 12
    ")
    : [];

$pointRows = sysFetchRows($conn, "
    SELECT user_id, name, email, points_balance
    FROM users
    WHERE points_balance > 0
    ORDER BY points_balance DESC, user_id DESC
    LIMIT 12
");
?>

<style>
    .sys-layout { display: grid; grid-template-columns: 2fr 1fr; gap: 24px; align-items: start; margin-top: 20px; }
    .sys-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px; }
    .sys-title { font-size: 16px; font-weight: 800; color: #1e293b; margin: 0 0 16px 0; }
    
    /* 提示泡泡 */
    .sys-alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-weight: 700; font-size: 14px; }
    .sys-alert.success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
    .sys-alert.error { background: #fff1f2; color: #991b1b; border: 1px solid #fecaca; }

    /* 表格設計 */
    .sys-table { width: 100%; border-collapse: collapse; text-align: left; font-size: 14px; }
    .sys-table th { padding: 12px; color: #64748b; border-bottom: 1px solid #edf2f7; background: #f8fafc; }
    .sys-table td { padding: 14px 12px; border-bottom: 1px solid #f1f5f9; }
    
    .badge { padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 800; }
    .badge.super { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    .badge.cs { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
    .badge.active { background: #ecfdf5; color: #065f46; }
    .badge.disabled { background: #f1f5f9; color: #64748b; text-decoration: line-through; }

    .inline-form { display: flex; align-items: center; gap: 8px; margin: 0; }
    .inline-form select { width: auto; padding: 6px 10px; margin: 0; font-size: 13px; }
    .inline-btn { padding: 6px 12px; background: #334155; color: #fff; font-size: 12px; border-radius: 4px; }
    .inline-btn:hover { background: #111827; }
    .ops-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; margin-top: 24px; }
    .ops-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px; overflow: hidden; }
    .ops-card h2 { margin: 0 0 12px; font-size: 16px; color: #1e293b; }
    .ops-table-wrap { overflow-x: auto; }
    .ops-table { width: 100%; min-width: 620px; border-collapse: collapse; font-size: 13px; }
    .ops-table th { text-align: left; color: #64748b; background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 9px 10px; }
    .ops-table td { border-bottom: 1px solid #f1f5f9; padding: 10px; vertical-align: top; color: #334155; }
    .ops-empty { padding: 18px; border-radius: 10px; background: #f8fafc; color: #64748b; font-size: 13px; }
    .ops-badge { display: inline-flex; align-items: center; padding: 3px 8px; border-radius: 999px; font-size: 11px; font-weight: 800; background: #eef2ff; color: #3730a3; }
    .ops-badge.bad { background: #fef2f2; color: #991b1b; }
    .ops-badge.good { background: #ecfdf5; color: #047857; }
    @media (max-width: 1000px) {
        .sys-layout, .ops-grid { grid-template-columns: 1fr; }
    }
</style>

<h1 style="font-size:24px; margin-top:0; margin-bottom:4px;">⚙️ 系統與權限管理</h1>
<p class="muted">超級安全控管中心：可在此新增、指派管理者角色、或對離職員工進行帳號停權鎖定。</p>

<?php if ($msg !== ''): ?>
    <div class="sys-alert <?php echo $msg_type; ?>"><?php echo $msg; ?></div>
<?php endif; ?>

<div class="sys-layout">
    <div class="sys-card">
        <h2 class="sys-title">📋 系統現存管理員清單</h2>
        <table class="sys-table">
            <thead>
                <tr>
                    <th style="width:60px;">ID</th>
                    <th>管理員帳號</th>
                    <th>目前角色群組</th>
                    <th>安全狀態</th>
                    <th>即時權限變更調整</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($admins as $ad): ?>
                    <tr>
                        <td>#<?php echo $ad['admin_id']; ?></td>
                        <td style="font-weight:700; color:#1e293b;">
                            <?php echo htmlspecialchars($ad['username']); ?>
                            <?php if(intval($ad['admin_id']) === intval($_SESSION['admin_id'] ?? 0)) echo ' <small style="color:#94a3b8;">(您自己)</small>'; ?>
                        </td>
                        <td>
                            <?php if (intval($ad['role_id']) === 1): ?>
                                <span class="badge super">👑 超級管理者</span>
                            <?php elseif (intval($ad['role_id']) === 3): ?>
                                <span class="badge cs">🏪 廠商</span>
                            <?php else: ?>
                                <span class="badge cs">💬 客服專員</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($ad['status'] === 'ACTIVE'): ?>
                                <span class="badge active">● 啟用中</span>
                            <?php else: ?>
                                <span class="badge disabled">○ 已停權</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="POST" class="inline-form">
                                <input type="hidden" name="system_action" value="update_admin">
                                <input type="hidden" name="admin_id" value="<?php echo $ad['admin_id']; ?>">
                                
                                <select name="role_id">
                                    <option value="1" <?php echo intval($ad['role_id'])==1 ? 'selected':''; ?>>超級管理者</option>
                                    <option value="2" <?php echo intval($ad['role_id'])==2 ? 'selected':''; ?>>客服專員</option>
                                    <option value="3" <?php echo intval($ad['role_id'])==3 ? 'selected':''; ?>>廠商</option>
                                </select>

                                <select name="status">
                                    <option value="ACTIVE" <?php echo $ad['status']=='ACTIVE' ? 'selected':''; ?>>保持啟用</option>
                                    <option value="DISABLED" <?php echo $ad['status']=='DISABLED' ? 'selected':''; ?>>停權凍結</option>
                                </select>

                                <button type="submit" class="inline-btn">儲存</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="sys-card" style="background: #f8fafc;">
        <h2 class="sys-title">➕ 建立新後台帳號</h2>
        <form method="POST" style="margin:0;">
            <input type="hidden" name="system_action" value="create_admin">
            
            <label style="font-size:13px; font-weight:700; color:#475569;">管理員登入帳號 (Username)</label>
            <input type="text" name="username" placeholder="例如：service_peter" required autocomplete="off">

            <label style="font-size:13px; font-weight:700; color:#475569;">預設初始密碼 (Password)</label>
            <input type="password" name="password" placeholder="輸入強固密碼組合" required>

            <label style="font-size:13px; font-weight:700; color:#475569;">初始指派權限群組</label>
            <select name="role_id" id="createAdminRoleId">
                <option value="2" selected>💬 客服專員 (受限角色)</option>
                <option value="3">🏪 廠商 (受限角色)</option>
                <option value="1">👑 超級管理者 (完整控制)</option>
            </select>

            <div id="supplierFields" style="display:none; margin-top:12px; padding:12px; border:1px dashed #cbd5e1; border-radius:10px; background:#fff;">
                <div style="font-size:13px; font-weight:800; color:#334155; margin-bottom:8px;">廠商資訊（選擇廠商角色時填寫）</div>
                <label style="font-size:13px; font-weight:700; color:#475569;">廠商名稱</label>
                <input type="text" name="supplier_name" placeholder="例如：宏遠旅行箱有限公司" autocomplete="off">

                <label style="font-size:13px; font-weight:700; color:#475569;">聯絡人</label>
                <input type="text" name="contact_person" placeholder="例如：王小姐" autocomplete="off">

                <label style="font-size:13px; font-weight:700; color:#475569;">電話</label>
                <input type="text" name="phone" placeholder="例如：02-1234-5678" autocomplete="off">

                <label style="font-size:13px; font-weight:700; color:#475569;">Email</label>
                <input type="email" name="email" placeholder="example@company.com" autocomplete="off">
            </div>

            <button type="submit" class="alt" style="width:100%; margin-top:8px;">確認配置並發送帳號</button>
        </form>
    </div>
</div>

<div class="ops-grid">
    <section class="ops-card">
        <h2>低庫存 SKU 監控</h2>
        <?php if (!empty($lowStockRows)): ?>
            <div class="ops-table-wrap">
                <table class="ops-table">
                    <thead><tr><th>商品 / SKU</th><th>規格</th><th>可售庫存</th></tr></thead>
                    <tbody>
                        <?php foreach ($lowStockRows as $row): ?>
                            <tr>
                                <td><strong>#<?php echo (int)$row['product_id']; ?> <?php echo htmlspecialchars($row['product_name']); ?></strong><br><span style="color:#64748b;"><?php echo htmlspecialchars($row['sku_code'] ?: '-'); ?></span></td>
                                <td><?php echo htmlspecialchars(trim(($row['size_inches'] ?: '-') . ' / ' . ($row['color'] ?: '-'))); ?></td>
                                <td><span class="ops-badge <?php echo (int)$row['stock_available'] <= 0 ? 'bad' : ''; ?>"><?php echo (int)$row['stock_available']; ?> 件</span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="ops-empty">目前沒有低庫存 SKU。</div>
        <?php endif; ?>
    </section>

    <section class="ops-card">
        <h2>近期庫存異動</h2>
        <?php if (!empty($inventoryLogRows)): ?>
            <div class="ops-table-wrap">
                <table class="ops-table">
                    <thead><tr><th>時間</th><th>商品 / SKU</th><th>異動</th><th>操作</th></tr></thead>
                    <tbody>
                        <?php foreach ($inventoryLogRows as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['created_at']); ?></td>
                                <td><strong><?php echo htmlspecialchars($row['product_name'] ?: ('#' . $row['product_id'])); ?></strong><br><span style="color:#64748b;"><?php echo htmlspecialchars($row['sku_code'] ?: '-'); ?></span></td>
                                <td><?php echo (int)$row['old_stock']; ?> -> <?php echo (int)$row['new_stock']; ?> <span class="ops-badge <?php echo (int)$row['delta_quantity'] < 0 ? 'bad' : 'good'; ?>"><?php echo (int)$row['delta_quantity']; ?></span></td>
                                <td><?php echo htmlspecialchars($row['action_type']); ?><br><span style="color:#64748b;"><?php echo htmlspecialchars($row['admin_username'] ?: '-'); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="ops-empty">目前沒有庫存異動紀錄。</div>
        <?php endif; ?>
    </section>

    <section class="ops-card">
        <h2>登入 / 密碼重設嘗試</h2>
        <?php if (!empty($securityAttemptRows)): ?>
            <div class="ops-table-wrap">
                <table class="ops-table">
                    <thead><tr><th>時間</th><th>範圍</th><th>識別值</th><th>結果</th></tr></thead>
                    <tbody>
                        <?php foreach ($securityAttemptRows as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['created_at']); ?></td>
                                <td><?php echo htmlspecialchars($row['scope']); ?></td>
                                <td><?php echo htmlspecialchars($row['identifier']); ?><br><span style="color:#64748b;"><?php echo htmlspecialchars($row['ip_address'] ?: '-'); ?></span></td>
                                <td><span class="ops-badge <?php echo (int)$row['success'] === 1 ? 'good' : 'bad'; ?>"><?php echo (int)$row['success'] === 1 ? '成功' : '失敗'; ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="ops-empty">目前沒有安全嘗試紀錄。</div>
        <?php endif; ?>
    </section>

    <section class="ops-card">
        <h2>管理員操作審計 Log</h2>
        <?php if (!empty($auditRows)): ?>
            <div class="ops-table-wrap">
                <table class="ops-table">
                    <thead><tr><th>時間</th><th>管理員</th><th>操作</th><th>目標</th></tr></thead>
                    <tbody>
                        <?php foreach ($auditRows as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['created_at']); ?></td>
                                <td><?php echo htmlspecialchars($row['admin_username'] ?: ('#' . $row['admin_id'])); ?></td>
                                <td><strong><?php echo htmlspecialchars($row['action']); ?></strong><br><span style="color:#64748b;"><?php echo htmlspecialchars($row['message'] ?: '-'); ?></span></td>
                                <td><?php echo htmlspecialchars($row['target_type'] ?: '-'); ?> <?php echo $row['target_id'] !== null ? '#' . (int)$row['target_id'] : ''; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="ops-empty">目前沒有管理員操作紀錄。新的後台操作會開始寫入這裡。</div>
        <?php endif; ?>
    </section>

    <section class="ops-card">
        <h2>會員點數餘額快照</h2>
        <?php if (!empty($pointRows)): ?>
            <div class="ops-table-wrap">
                <table class="ops-table">
                    <thead><tr><th>會員</th><th>Email</th><th>點數</th></tr></thead>
                    <tbody>
                        <?php foreach ($pointRows as $row): ?>
                            <tr>
                                <td>#<?php echo (int)$row['user_id']; ?> <?php echo htmlspecialchars($row['name']); ?></td>
                                <td><?php echo htmlspecialchars($row['email']); ?></td>
                                <td><span class="ops-badge good"><?php echo number_format((int)$row['points_balance']); ?> 點</span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="ops-empty">目前沒有會員持有點數。</div>
        <?php endif; ?>
    </section>
</div>

<script>
(function () {
    const roleSelect = document.getElementById('createAdminRoleId');
    const supplierFields = document.getElementById('supplierFields');
    if (!roleSelect || !supplierFields) {
        return;
    }

    function toggleSupplierFields() {
        supplierFields.style.display = roleSelect.value === '3' ? 'block' : 'none';
    }

    roleSelect.addEventListener('change', toggleSupplierFields);
    toggleSupplierFields();
})();
</script>

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

            if ($new_user === '' || $new_pass === '') {
                $msg = '帳號或密碼欄位不可留白！'; $msg_type = 'error';
            } else {
                // 檢查帳號是否重複
                $stmt = $conn->prepare("SELECT admin_id FROM admin_users WHERE username = ? LIMIT 1");
                $stmt->bind_param("s", $new_user); $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $msg = '建立失敗：此管理員帳號已存在！'; $msg_type = 'error';
                } else {
                    // 💡 安全核心：使用 BCRYPT 雜湊法對新密碼加密
                    $hash = password_hash($new_pass, PASSWORD_BCRYPT);
                    $ins = $conn->prepare("INSERT INTO admin_users (role_id, username, password_hash, status) VALUES (?, ?, ?, 'ACTIVE')");
                    $ins->bind_param("iss", $new_role, $new_user, $hash);
                    if ($ins->execute()) {
                        $msg = '成功建立全新管理員帳號：' . htmlspecialchars($new_user);
                    } else {
                        $msg = '資料庫寫入異常。'; $msg_type = 'error';
                    }
                    $ins->close();
                }
                $stmt->close();
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
                if ($up->execute()) { $msg = '管理員權限設定更新成功！'; }
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
            <select name="role_id">
                <option value="2" selected>💬 客服專員 (受限角色)</option>
                <option value="1">👑 超級管理者 (完整控制)</option>
            </select>

            <button type="submit" class="alt" style="width:100%; margin-top:8px;">確認配置並發送帳號</button>
        </form>
    </div>
</div>
<?php
require_once __DIR__ . '/auth_guard.php';

if (!function_exists('h')) {
    function h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function memberLevelLabel($level)
{
    $level = trim((string)$level);
    if ($level === '3') {
        return 'VVIP';
    }
    if ($level === '2') {
        return 'VIP';
    }
    return '一般會員';
}

function memberStatusLabel($status)
{
    switch (strtoupper((string)$status)) {
        case 'SUSPENDED': return '停權';
        case 'INACTIVE': return '停用';
        default: return '啟用';
    }
}

$keyword = trim((string)($_GET['q'] ?? ''));
$memberRows = [];
$stats = [
    'total_members' => 0,
    'vip_members' => 0,
    'active_members' => 0,
    'total_points' => 0,
];

$statsResult = $conn->query("
    SELECT
        COUNT(*) AS total_members,
        SUM(CASE WHEN membership_level IN ('2', '3') THEN 1 ELSE 0 END) AS vip_members,
        SUM(CASE WHEN status = 'ACTIVE' THEN 1 ELSE 0 END) AS active_members,
        COALESCE(SUM(points_balance), 0) AS total_points
    FROM users
");
if ($statsResult && ($row = $statsResult->fetch_assoc())) {
    $stats = array_merge($stats, $row);
}

$memberSql = "
    SELECT
        u.user_id,
        u.email,
        u.name,
        u.phone,
        COALESCE(NULLIF(u.membership_level, ''), '1') AS membership_level,
        COALESCE(u.points_balance, 0) AS points_balance,
        u.status,
        u.created_at,
        (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.user_id) AS order_count,
        (SELECT COALESCE(SUM(o.total_amount), 0) FROM orders o WHERE o.user_id = u.user_id AND o.status <> 'CANCELLED') AS lifetime_spend,
        (SELECT MAX(o.created_at) FROM orders o WHERE o.user_id = u.user_id) AS last_order_at,
        (SELECT COALESCE(SUM(cd.quantity), 0) FROM coupon_distributions cd WHERE cd.user_id = u.user_id) AS coupon_count
    FROM users u
";

if ($keyword !== '') {
    $memberSql .= " WHERE u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? ";
}
$memberSql .= " ORDER BY u.created_at DESC, u.user_id DESC LIMIT 200";

$stmt = $conn->prepare($memberSql);
if ($stmt) {
    if ($keyword !== '') {
        $like = '%' . $keyword . '%';
        $stmt->bind_param('sss', $like, $like, $like);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $memberRows[] = $row;
        }
    }
    $stmt->close();
}

$noticeHtml = '';
if (!empty($_GET['success'])) {
    $noticeHtml = '<div class="member-notice success">' . h($_GET['success']) . '</div>';
} elseif (!empty($_GET['error'])) {
    $noticeHtml = '<div class="member-notice error">' . h($_GET['error']) . '</div>';
}
?>

<link rel="stylesheet" href="../css/products.css">

<style>
.member-page { display: flex; flex-direction: column; gap: 18px; }
.member-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
.member-title { margin: 0; font-size: 24px; color: #0f172a; }
.member-subtitle { margin: 6px 0 0; color: #64748b; font-size: 14px; }
.member-search { display: flex; gap: 8px; align-items: center; min-width: min(420px, 100%); }
.member-search input { margin: 0; }
.member-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
.member-stat { border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px; background: #fff; }
.member-stat span { display: block; color: #64748b; font-size: 13px; }
.member-stat strong { display: block; margin-top: 6px; color: #0f172a; font-size: 22px; }
.member-table-wrap { overflow-x: auto; border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; }
.member-table { width: 100%; min-width: 1180px; border-collapse: collapse; }
.member-table th, .member-table td { padding: 12px 10px; border-bottom: 1px solid #eef2f7; text-align: left; vertical-align: middle; font-size: 13px; }
.member-table th { color: #475569; background: #f8fafc; font-weight: 700; }
.member-table tr:last-child td { border-bottom: 0; }
.member-table input, .member-table select { margin: 0; padding: 8px 10px; font-size: 13px; }
.member-inline { display: grid; grid-template-columns: 130px 110px 92px 86px 96px 76px; gap: 8px; align-items: center; }
.member-email { color: #64748b; font-size: 12px; margin-top: 4px; }
.member-badge { display: inline-flex; align-items: center; border-radius: 999px; padding: 4px 9px; font-size: 12px; font-weight: 700; background: #eef2ff; color: #3730a3; }
.member-badge.basic { background: #f1f5f9; color: #475569; }
.member-badge.status { background: #ecfdf5; color: #047857; }
.member-badge.status.bad { background: #fef2f2; color: #b91c1c; }
.member-notice { padding: 12px 14px; border-radius: 8px; font-size: 14px; }
.member-notice.success { background: #ecfdf5; color: #047857; }
.member-notice.error { background: #fef2f2; color: #b91c1c; }
@media (max-width: 900px) {
    .member-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .member-search { width: 100%; }
}
</style>

<div class="member-page">
    <div class="member-head">
        <div>
            <h1 class="member-title">會員管理</h1>
            <p class="member-subtitle">管理會員等級、點數與帳號狀態；VIP/VVIP 才會套用商品會員價。</p>
        </div>
        <form class="member-search" method="GET" action="backend.php">
            <input type="hidden" name="page" value="members">
            <input class="pm-input" type="search" name="q" value="<?php echo h($keyword); ?>" placeholder="搜尋姓名、Email、電話">
            <button class="pm-btn pm-btn-main" type="submit">搜尋</button>
        </form>
    </div>

    <?php echo $noticeHtml; ?>

    <div class="member-stats">
        <div class="member-stat"><span>總會員</span><strong><?php echo number_format((int)$stats['total_members']); ?></strong></div>
        <div class="member-stat"><span>VIP / VVIP</span><strong><?php echo number_format((int)$stats['vip_members']); ?></strong></div>
        <div class="member-stat"><span>啟用中</span><strong><?php echo number_format((int)$stats['active_members']); ?></strong></div>
        <div class="member-stat"><span>總點數</span><strong><?php echo number_format((int)$stats['total_points']); ?></strong></div>
    </div>

    <div class="member-table-wrap">
        <table class="member-table">
            <thead>
                <tr>
                    <th style="width:210px;">會員</th>
                    <th style="width:120px;">目前等級</th>
                    <th style="width:95px;">狀態</th>
                    <th style="width:95px;">點數</th>
                    <th style="width:120px;">訂單</th>
                    <th style="width:120px;">累積消費</th>
                    <th style="width:140px;">最後訂單</th>
                    <th style="width:620px;">快速更新</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($memberRows)): ?>
                    <?php foreach ($memberRows as $member): ?>
                        <?php
                            $level = (string)($member['membership_level'] ?? '1');
                            $status = strtoupper((string)($member['status'] ?? 'ACTIVE'));
                            $isVip = in_array($level, ['2', '3'], true);
                            $isActive = $status === 'ACTIVE';
                        ?>
                        <tr>
                            <td>
                                <div style="font-weight:700; color:#0f172a;">#<?php echo (int)$member['user_id']; ?> <?php echo h($member['name']); ?></div>
                                <div class="member-email"><?php echo h($member['email']); ?></div>
                                <div class="member-email"><?php echo h($member['phone'] ?: '-'); ?></div>
                            </td>
                            <td><span class="member-badge <?php echo $isVip ? '' : 'basic'; ?>"><?php echo h(memberLevelLabel($level)); ?></span></td>
                            <td><span class="member-badge status <?php echo $isActive ? '' : 'bad'; ?>"><?php echo h(memberStatusLabel($status)); ?></span></td>
                            <td><?php echo number_format((int)$member['points_balance']); ?></td>
                            <td><?php echo number_format((int)$member['order_count']); ?> 筆<br><span style="color:#64748b;"><?php echo number_format((int)$member['coupon_count']); ?> 張券</span></td>
                            <td>NT$ <?php echo number_format((float)$member['lifetime_spend']); ?></td>
                            <td><?php echo h($member['last_order_at'] ?: '-'); ?></td>
                            <td>
                                <form class="member-inline" action="backend_action.php" method="POST">
                                    <input type="hidden" name="action" value="update_member">
                                    <input type="hidden" name="user_id" value="<?php echo (int)$member['user_id']; ?>">
                                    <?php if (function_exists('apCsrfField')) echo apCsrfField(); ?>
                                    <input class="pm-input" type="text" name="name" value="<?php echo h($member['name']); ?>" required>
                                    <input class="pm-input" type="text" name="phone" value="<?php echo h($member['phone']); ?>" placeholder="電話">
                                    <select class="pm-select" name="membership_level">
                                        <option value="1" <?php echo $level === '1' ? 'selected' : ''; ?>>一般會員</option>
                                        <option value="2" <?php echo $level === '2' ? 'selected' : ''; ?>>VIP</option>
                                        <option value="3" <?php echo $level === '3' ? 'selected' : ''; ?>>VVIP</option>
                                    </select>
                                    <input class="pm-input" type="number" name="points_balance" min="0" value="<?php echo (int)$member['points_balance']; ?>">
                                    <select class="pm-select" name="status">
                                        <option value="ACTIVE" <?php echo $status === 'ACTIVE' ? 'selected' : ''; ?>>啟用</option>
                                        <option value="SUSPENDED" <?php echo $status === 'SUSPENDED' ? 'selected' : ''; ?>>停權</option>
                                        <option value="INACTIVE" <?php echo $status === 'INACTIVE' ? 'selected' : ''; ?>>停用</option>
                                    </select>
                                    <button class="pm-btn pm-btn-main pm-btn-sm" type="submit">儲存</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="8" style="text-align:center; padding:28px; color:#94a3b8;">找不到符合條件的會員。</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

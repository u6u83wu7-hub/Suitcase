<?php
// manage_feedback.php - 評價管理 (純評論版)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Asia/Taipei');

// 資料庫連線 (如果 backend.php 已經連線過，這裡其實可以省略，但為了保險保留)
if (!isset($conn) || $conn->connect_error) {
    $conn = new mysqli('localhost', 'root', '', 'all_pass_db');
    if (!$conn->connect_error) {
        $conn->set_charset('utf8mb4');
    }
}

// 避免跟其他頁面的 h() 函數衝突，改名為 h_feedback
if (!function_exists('h_feedback')) {
    function h_feedback($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$noticeHtml = '';

// ==========================================
// 💡 處理 POST 請求 (隱藏/刪除)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'toggle_review_visibility') {
        $reviewId = (int)$_POST['review_id'];
        $currentVisibility = (int)$_POST['current_visibility'];
        $newVisibility = $currentVisibility === 1 ? 0 : 1;
        
        $stmt = $conn->prepare("UPDATE product_reviews SET is_visible = ? WHERE review_id = ?");
        $stmt->bind_param('ii', $newVisibility, $reviewId);
        $stmt->execute();
        $stmt->close();
        $noticeHtml = '<div style="padding:12px; background:#ecfdf5; color:#047857; border-radius:8px; margin-bottom:16px; border:1px solid #a7f3d0;">狀態已更新。</div>';
    }

    if ($action === 'delete_review') {
        $reviewId = (int)$_POST['review_id'];
        $stmt = $conn->prepare("DELETE FROM product_reviews WHERE review_id = ?");
        $stmt->bind_param('i', $reviewId);
        $stmt->execute();
        $stmt->close();
        $noticeHtml = '<div style="padding:12px; background:#ecfdf5; color:#047857; border-radius:8px; margin-bottom:16px; border:1px solid #a7f3d0;">評論已永久刪除。</div>';
    }
}

// ==========================================
// 💡 撈取資料
// ==========================================
$reviews = [];
$reviewSql = "
    SELECT pr.*, p.name AS product_name, u.name AS user_name, u.email AS user_email 
    FROM product_reviews pr
    LEFT JOIN products p ON pr.product_id = p.product_id
    LEFT JOIN users u ON pr.user_id = u.user_id
    ORDER BY pr.created_at DESC
";
$res = $conn->query($reviewSql);
if ($res) {
    while ($row = $res->fetch_assoc()) $reviews[] = $row;
}
?>

<link rel="stylesheet" href="../css/products.css">
<style>
    .star-rating { color: #f59e0b; letter-spacing: 2px; }
    .table-wrap { overflow-x: auto; background: #fff; border-radius: 8px; border: 1px solid #e2e8f0; }
    .pm-table { width: 100%; border-collapse: collapse; text-align: left; }
    .pm-table th { background: #f8fafc; font-weight: 600; padding: 12px; color: #475569; border-bottom: 1px solid #e2e8f0; }
    .pm-table td { padding: 12px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
    .btn-sm { padding: 6px 12px; font-size: 12px; border-radius: 6px; cursor: pointer; border: none; color: #fff; font-weight: bold; }
    .btn-warn { background: #f59e0b; }
    .btn-danger { background: #ef4444; }
    .pm-badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; }
</style>

<div class="pm-wrap" style="max-width: 1200px; margin: 0 auto;">
    <div class="pm-head" style="margin-bottom: 20px;">
        <h1 class="pm-title" style="margin:0; font-size:24px; color:#0f172a;">💬 評價管理</h1>
    </div>

    <?php echo $noticeHtml; ?>

    <section class="pm-card" style="background: white; padding: 20px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 30px;">
        <h2 style="font-size:18px; margin:0 0 16px; color:#1e293b;">商品評論清單</h2>
        <div class="table-wrap">
            <table class="pm-table">
                <thead>
                    <tr>
                        <th style="width:60px;">ID</th>
                        <th style="width:200px;">商品 / 訂單</th>
                        <th style="width:150px;">會員資訊</th>
                        <th style="width:100px;">評分</th>
                        <th>評論內容</th>
                        <th style="width:80px;">狀態</th>
                        <th style="width:140px;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reviews)): ?>
                        <tr><td colspan="7" style="text-align:center; padding: 20px; color:#94a3b8;">目前沒有任何商品評論。</td></tr>
                    <?php else: ?>
                        <?php foreach ($reviews as $r): ?>
                            <tr>
                                <td>#<?php echo $r['review_id']; ?></td>
                                <td>
                                    <div style="font-weight:bold; color:#2563eb; font-size:14px;"><?php echo h_feedback($r['product_name']); ?></div>
                                    <div style="font-size:12px; color:#64748b; margin-top:4px;">訂單 #<?php echo $r['order_id']; ?></div>
                                </td>
                                <td>
                                    <div style="font-weight:bold; font-size:14px;"><?php echo h_feedback($r['user_name']); ?></div>
                                    <div style="font-size:12px; color:#64748b; margin-top:2px;"><?php echo h_feedback($r['user_email']); ?></div>
                                </td>
                                <td class="star-rating"><?php echo str_repeat('★', $r['rating']) . str_repeat('☆', 5 - $r['rating']); ?></td>
                                <td style="font-size:14px; color:#334155; line-height: 1.5;"><?php echo nl2br(h_feedback($r['comment'])); ?></td>
                                <td>
                                    <?php if ($r['is_visible'] == 1): ?>
                                        <span class="pm-badge" style="background:#dcfce7; color:#166534;">顯示中</span>
                                    <?php else: ?>
                                        <span class="pm-badge" style="background:#fee2e2; color:#991b1b;">已隱藏</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display:flex; gap:8px;">
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="action" value="toggle_review_visibility">
                                            <input type="hidden" name="review_id" value="<?php echo $r['review_id']; ?>">
                                            <input type="hidden" name="current_visibility" value="<?php echo $r['is_visible']; ?>">
                                            <button type="submit" class="btn-sm btn-warn">
                                                <?php echo $r['is_visible'] == 1 ? '隱藏' : '顯示'; ?>
                                            </button>
                                        </form>
                                        <form method="POST" style="margin:0;" onsubmit="return confirm('確定要永久刪除這則評論嗎？');">
                                            <input type="hidden" name="action" value="delete_review">
                                            <input type="hidden" name="review_id" value="<?php echo $r['review_id']; ?>">
                                            <button type="submit" class="btn-sm btn-danger">刪除</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
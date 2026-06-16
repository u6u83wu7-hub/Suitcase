<?php
$pageTitle = '升級 VIP | All Pass';
$activeNav = '';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/security.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = intval($_SESSION['user_id']);
$conn = new mysqli('localhost', 'root', '', 'all_pass_db');
if ($conn->connect_error) {
    die('連線失敗');
}
$conn->set_charset('utf8mb4');

$notice = '';

// 處理升級表單提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upgrade') {
    if (!apValidateCsrf()) {
        $notice = '表單驗證失敗，請重新操作。';
    } else {
        // 模擬付款成功，將會員等級更新為 '2' (VIP)
        $stmt = $conn->prepare("UPDATE users SET membership_level = '2' WHERE user_id = ?");
        $stmt->bind_param('i', $userId);
        
        if ($stmt->execute()) {
            $_SESSION['membership_level'] = '2'; // 更新 Session
            header('Location: profile.php?coupon_success=' . urlencode('恭喜！您已成功升級為終身 VIP，即刻享有專屬優惠價。'));
            exit;
        } else {
            $notice = '升級失敗，請稍後再試。';
        }
        $stmt->close();
    }
}

include 'header.php';
?>

<style>
    /* 沿用你的網站配色與樣式邏輯 */
    .vip-page-wrap {
        padding: 160px 5% 80px;
        max-width: 800px;
        margin: 0 auto;
    }
    .vip-header {
        text-align: center;
        margin-bottom: 40px;
    }
    .vip-header h1 {
        font-size: 32px;
        line-height: 1.2;
        margin: 0 0 14px;
        color: var(--ink, #202020);
    }
    .vip-header p {
        color: var(--muted, #6f6f6f);
        font-size: 15px;
        line-height: 1.6;
        margin: 0;
    }
    .vip-card {
        background: #fff;
        border: 1px solid var(--line, #e5e7eb);
        padding: 48px;
        max-width: 480px;
        margin: 0 auto;
    }
    .vip-category {
        color: var(--accent, #db6b6b);
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 1px;
        text-transform: uppercase;
        margin-bottom: 16px;
        text-align: center;
    }
    .vip-price {
        font-size: 42px;
        font-weight: 800;
        color: var(--ink, #202020);
        text-align: center;
        margin-bottom: 32px;
        display: flex;
        align-items: baseline;
        justify-content: center;
        gap: 8px;
    }
    .vip-price span {
        font-size: 14px;
        color: var(--muted, #6f6f6f);
        font-weight: normal;
    }
    .vip-features {
        border-top: 1px solid var(--line, #e5e7eb);
        padding-top: 32px;
        margin-bottom: 32px;
        display: grid;
        gap: 16px;
    }
    .vip-feature-item {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        color: var(--ink, #202020);
        font-size: 15px;
        line-height: 1.6;
    }
    .vip-feature-icon {
        color: var(--accent, #db6b6b);
        flex-shrink: 0;
        margin-top: 2px;
    }
    .vip-feature-icon svg {
        width: 18px;
        height: 18px;
        fill: currentColor;
    }
    .vip-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        padding: 16px 20px;
        background: var(--accent, #db6b6b);
        color: #fff;
        border: 0;
        cursor: pointer;
        font-weight: 700;
        font-size: 16px;
        transition: background 0.2s ease;
    }
    .vip-btn:hover {
        background: var(--accent-dark, #b45353);
    }
    .vip-note {
        text-align: center;
        font-size: 13px;
        color: var(--muted, #6f6f6f);
        margin-top: 16px;
    }
    .vip-alert {
        background: #fef2f2;
        color: #b91c1c;
        border: 1px solid #fecaca;
        padding: 12px 14px;
        margin-bottom: 24px;
        text-align: center;
        font-size: 14px;
    }

    @media (max-width: 600px) {
        .vip-page-wrap { padding-top: 120px; }
        .vip-card { padding: 32px 24px; border-left: none; border-right: none; }
    }
</style>

<main class="vip-page-wrap">
    
    <div class="vip-header">
        <h1></h1>
        <h1>升級 All Pass VIP</h1>
        <p>一次性支付，為你未來的每一趟旅程省下更多預算。</p>
    </div>

    <?php if ($notice): ?>
        <div class="vip-alert">
            <?php echo htmlspecialchars($notice); ?>
        </div>
    <?php endif; ?>

    <div class="vip-card">
        <div class="vip-category">LIFETIME MEMBERSHIP</div>
        <div class="vip-price">
            NT$ 999 <span>/ 終身有效</span>
        </div>
        
        <div class="vip-features">
            <div class="vip-feature-item">
                <div class="vip-feature-icon">
                    <svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                </div>
                <div>全站行李箱享專屬 <strong>VIP 會員折扣價</strong></div>
            </div>
            <div class="vip-feature-item">
                <div class="vip-feature-icon">
                    <svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                </div>
                <div>不定期發放 <strong>專屬免運 / 滿額折價卷</strong></div>
            </div>
            <div class="vip-feature-item">
                <div class="vip-feature-icon">
                    <svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                </div>
                <div>商品評論與活動紅利點數 <strong>加碼回饋</strong></div>
            </div>
            <div class="vip-feature-item">
                <div class="vip-feature-icon">
                    <svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                </div>
                <div>優先享有新款行李箱早鳥預購資格</div>
            </div>
        </div>

        <form method="POST">
            <?php echo apCsrfField(); ?>
            <input type="hidden" name="action" value="upgrade">
            <button type="submit" class="vip-btn">
                確認升級並付款
            </button>
        </form>
    </div>

</main>

<?php include 'footer.php'; $conn->close(); ?>
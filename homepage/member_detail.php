<?php
$pageTitle = '會員詳細資料 | All Pass';
$activeNav = '';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = intval($_SESSION['user_id']);
$conn = new mysqli('localhost', 'root', '', 'all_pass_db');
if ($conn->connect_error) {
    die('資料庫連線失敗: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');

function memberTableExists($conn, $tableName) {
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return ($res && $res->num_rows > 0);
}

function memberFetchRow($conn, $sql) {
    $res = $conn->query($sql);
    if ($res && $res->num_rows > 0) {
        return $res->fetch_assoc();
    }
    return null;
}

if (!memberTableExists($conn, 'user_member_details')) {
    include 'header.php';
    echo '<section style="padding:190px 5% 60px; max-width:1000px; margin:0 auto;">';
    echo '<div style="background:#fff; border:1px solid #eee; border-radius:14px; padding:28px; text-align:center;">';
    echo '<h1 style="margin-bottom:10px;">會員詳細資料頁尚未完成初始化</h1>';
    echo '<p style="color:#666; margin-bottom:16px;">請先執行 db_setup_and_sync.php 建立 `user_member_details` 資料表。</p>';
    echo '<a href="profile.php" style="display:inline-flex; padding:10px 16px; border-radius:999px; background:#db6b6b; color:#fff; font-weight:700;">返回會員中心</a>';
    echo '</div></section>';
    include 'footer.php';
    $conn->close();
    exit;
}

$user = [
    'name' => isset($_SESSION['user_name']) ? $_SESSION['user_name'] : '會員',
    'email' => isset($_SESSION['user_email']) ? $_SESSION['user_email'] : '',
    'phone' => ''
];

$userRes = memberFetchRow($conn, "SELECT name, email, phone FROM users WHERE user_id = {$userId} LIMIT 1");
if ($userRes) {
    $user['name'] = $userRes['name'] !== null ? $userRes['name'] : $user['name'];
    $user['email'] = $userRes['email'] !== null ? $userRes['email'] : $user['email'];
    $user['phone'] = $userRes['phone'] !== null ? $userRes['phone'] : '';
}

$detail = [
    'full_address' => '',
    'address_note' => '',
    'cardholder_name' => '',
    'card_last4' => '',
    'card_brand' => '',
    'expiry_month' => '',
    'expiry_year' => ''
];

$detailRow = memberFetchRow($conn, "SELECT full_address, address_note, cardholder_name, card_last4, card_brand, expiry_month, expiry_year FROM user_member_details WHERE user_id = {$userId} LIMIT 1");
if ($detailRow) {
    foreach ($detail as $key => $value) {
        $detail[$key] = $detailRow[$key] !== null ? $detailRow[$key] : '';
    }
}

$notice = '';
$noticeType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullAddress = isset($_POST['full_address']) ? trim($_POST['full_address']) : '';
    $addressNote = isset($_POST['address_note']) ? trim($_POST['address_note']) : '';
    $cardholderName = isset($_POST['cardholder_name']) ? trim($_POST['cardholder_name']) : '';
    $cardBrand = isset($_POST['card_brand']) ? trim($_POST['card_brand']) : '';
    $expiryMonth = isset($_POST['expiry_month']) ? trim($_POST['expiry_month']) : '';
    $expiryYear = isset($_POST['expiry_year']) ? trim($_POST['expiry_year']) : '';
    $cardNumber = isset($_POST['card_number']) ? preg_replace('/\D+/', '', $_POST['card_number']) : '';
    $cardLast4 = $cardNumber !== '' ? substr($cardNumber, -4) : '';

    if ($fullAddress === '' || $cardholderName === '' || $cardNumber === '' || $expiryMonth === '' || $expiryYear === '') {
        $notice = '請把地址、持卡人、卡號與到期日填完整。';
        $noticeType = 'error';
    } else {
        $stmt = $conn->prepare('INSERT INTO user_member_details (user_id, full_address, address_note, cardholder_name, card_last4, card_brand, expiry_month, expiry_year) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE full_address = VALUES(full_address), address_note = VALUES(address_note), cardholder_name = VALUES(cardholder_name), card_last4 = VALUES(card_last4), card_brand = VALUES(card_brand), expiry_month = VALUES(expiry_month), expiry_year = VALUES(expiry_year)');
        if ($stmt) {
            $stmt->bind_param('isssssss', $userId, $fullAddress, $addressNote, $cardholderName, $cardLast4, $cardBrand, $expiryMonth, $expiryYear);
            if ($stmt->execute()) {
                $notice = '會員詳細資料已更新。';
                $detail['full_address'] = $fullAddress;
                $detail['address_note'] = $addressNote;
                $detail['cardholder_name'] = $cardholderName;
                $detail['card_last4'] = $cardLast4;
                $detail['card_brand'] = $cardBrand;
                $detail['expiry_month'] = $expiryMonth;
                $detail['expiry_year'] = $expiryYear;
            } else {
                $notice = '資料更新失敗，請稍後再試。';
                $noticeType = 'error';
            }
            $stmt->close();
        } else {
            $notice = '無法建立資料更新語句。';
            $noticeType = 'error';
        }
    }
}

include 'header.php';
?>

<section style="padding:190px 5% 60px; max-width:1100px; margin:0 auto;">
    <div style="display:flex; justify-content:space-between; align-items:flex-end; gap:16px; flex-wrap:wrap; margin-bottom:20px;">
        <div>
            <h1 style="font-size:34px; margin-bottom:8px;">會員詳細資料</h1>
            <p style="color:#666;">你可以在這裡填寫收件地址與付款資訊。系統只會儲存卡號末 4 碼，不會保存安全碼。</p>
        </div>
        <a href="profile.php" style="display:inline-flex; align-items:center; justify-content:center; padding:10px 16px; border-radius:999px; background:#111; color:#fff; font-weight:700;">返回會員中心</a>
    </div>

    <?php if ($notice !== ''): ?>
        <div style="margin-bottom:16px; padding:12px 14px; border-radius:10px; <?php echo $noticeType === 'success' ? 'background:#ecfdf5;color:#166534;border:1px solid #86efac;' : 'background:#fef2f2;color:#991b1b;border:1px solid #fca5a5;'; ?>"><?php echo htmlspecialchars($notice); ?></div>
    <?php endif; ?>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
        <section style="background:#fff; border:1px solid #eee; border-radius:14px; padding:20px;">
            <h2 style="font-size:20px; margin-bottom:14px;">會員資訊</h2>
            <div style="line-height:1.9; color:#444;">
                <div><strong>姓名：</strong><?php echo htmlspecialchars($user['name']); ?></div>
                <div><strong>Email：</strong><?php echo htmlspecialchars($user['email']); ?></div>
                <div><strong>電話：</strong><?php echo htmlspecialchars($user['phone']); ?></div>
            </div>

            <div style="margin-top:18px; padding:14px; border-radius:12px; background:#fafafa; border:1px solid #eee; color:#666; font-size:14px; line-height:1.7;">
                提醒：信用卡資訊僅保存卡號末 4 碼與到期日，避免在系統中儲存完整卡號與安全碼。
            </div>
        </section>

        <section style="background:#fff; border:1px solid #eee; border-radius:14px; padding:20px;">
            <h2 style="font-size:20px; margin-bottom:14px;">已儲存資料</h2>
            <div style="line-height:1.9; color:#444;">
                <div><strong>收件地址：</strong><?php echo htmlspecialchars($detail['full_address'] !== '' ? $detail['full_address'] : '尚未填寫'); ?></div>
                <div><strong>地址備註：</strong><?php echo htmlspecialchars($detail['address_note'] !== '' ? $detail['address_note'] : '無'); ?></div>
                <div><strong>持卡人：</strong><?php echo htmlspecialchars($detail['cardholder_name'] !== '' ? $detail['cardholder_name'] : '尚未填寫'); ?></div>
                <div><strong>卡片品牌：</strong><?php echo htmlspecialchars($detail['card_brand'] !== '' ? $detail['card_brand'] : '尚未填寫'); ?></div>
                <div><strong>卡號末 4 碼：</strong><?php echo htmlspecialchars($detail['card_last4'] !== '' ? '**** **** **** ' . $detail['card_last4'] : '尚未填寫'); ?></div>
                <div><strong>到期日：</strong><?php echo htmlspecialchars($detail['expiry_month'] !== '' ? $detail['expiry_month'] . '/' . $detail['expiry_year'] : '尚未填寫'); ?></div>
            </div>
        </section>
    </div>

    <form method="post" style="margin-top:16px; background:#fff; border:1px solid #eee; border-radius:14px; padding:20px;">
        <h2 style="font-size:20px; margin-bottom:14px;">編輯收件地址與信用卡資訊</h2>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
            <div style="grid-column:1 / -1;">
                <label style="display:block; font-weight:700; margin-bottom:6px;">收件地址</label>
                <input type="text" name="full_address" value="<?php echo htmlspecialchars($detail['full_address']); ?>" placeholder="例如：台北市大安區忠孝東路四段 123 號 5 樓" style="width:100%; height:44px; padding:0 12px; border:1px solid #ddd; border-radius:10px;">
            </div>
            <div style="grid-column:1 / -1;">
                <label style="display:block; font-weight:700; margin-bottom:6px;">地址備註</label>
                <input type="text" name="address_note" value="<?php echo htmlspecialchars($detail['address_note']); ?>" placeholder="例如：請先按門鈴 / 管委會代收" style="width:100%; height:44px; padding:0 12px; border:1px solid #ddd; border-radius:10px;">
            </div>
            <div>
                <label style="display:block; font-weight:700; margin-bottom:6px;">持卡人姓名</label>
                <input type="text" name="cardholder_name" value="<?php echo htmlspecialchars($detail['cardholder_name']); ?>" placeholder="Cardholder Name" style="width:100%; height:44px; padding:0 12px; border:1px solid #ddd; border-radius:10px;">
            </div>
            <div>
                <label style="display:block; font-weight:700; margin-bottom:6px;">卡片品牌</label>
                <select name="card_brand" style="width:100%; height:44px; padding:0 12px; border:1px solid #ddd; border-radius:10px; background:#fff;">
                    <?php
                    $brands = ['' => '請選擇', 'Visa' => 'Visa', 'MasterCard' => 'MasterCard', 'JCB' => 'JCB', 'American Express' => 'American Express'];
                    foreach ($brands as $value => $label) {
                        $selected = $detail['card_brand'] === $value ? 'selected' : '';
                        echo '<option value="' . htmlspecialchars($value) . '" ' . $selected . '>' . htmlspecialchars($label) . '</option>';
                    }
                    ?>
                </select>
            </div>
            <div>
                <label style="display:block; font-weight:700; margin-bottom:6px;">卡號</label>
                <input type="text" name="card_number" inputmode="numeric" autocomplete="off" placeholder="請輸入卡號，系統只會保留末 4 碼" style="width:100%; height:44px; padding:0 12px; border:1px solid #ddd; border-radius:10px;">
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                <div>
                    <label style="display:block; font-weight:700; margin-bottom:6px;">到期月</label>
                    <input type="text" name="expiry_month" maxlength="2" value="<?php echo htmlspecialchars($detail['expiry_month']); ?>" placeholder="MM" style="width:100%; height:44px; padding:0 12px; border:1px solid #ddd; border-radius:10px;">
                </div>
                <div>
                    <label style="display:block; font-weight:700; margin-bottom:6px;">到期年</label>
                    <input type="text" name="expiry_year" maxlength="4" value="<?php echo htmlspecialchars($detail['expiry_year']); ?>" placeholder="YYYY" style="width:100%; height:44px; padding:0 12px; border:1px solid #ddd; border-radius:10px;">
                </div>
            </div>
        </div>

        <div style="margin-top:18px; display:flex; justify-content:flex-end; gap:10px; flex-wrap:wrap;">
            <a href="profile.php" style="display:inline-flex; align-items:center; justify-content:center; padding:12px 18px; border-radius:999px; background:#f3f4f6; color:#111; font-weight:700;">取消</a>
            <button type="submit" style="padding:12px 18px; border:none; border-radius:999px; background:#db6b6b; color:#fff; font-weight:700; cursor:pointer;">儲存資料</button>
        </div>
    </form>
</section>

<style>
@media (max-width: 992px) {
    section div[style*='grid-template-columns:1fr 1fr'] {
        grid-template-columns: 1fr !important;
    }
}
</style>

<?php include 'footer.php'; $conn->close(); ?>

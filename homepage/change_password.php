<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$pageTitle = '修改密碼 | All Pass 行李箱專賣';
$activeNav = '';

$error_message = '';
$success_message = '';

function isValidPassword($password) {
    return preg_match('/^(?=.*[A-Za-z])(?=.*\d).{8,}$/', $password) === 1;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($newPassword !== $confirmPassword) {
        $error_message = '新密碼與確認密碼不一致。';
    } elseif (!isValidPassword($newPassword)) {
        $error_message = '新密碼需至少 8 碼且包含英文字母與數字。';
    } else {
        $conn = new mysqli("localhost", "root", "", "all_pass_db");
        if ($conn->connect_error) {
            die("資料庫連線失敗: " . $conn->connect_error);
        }
        $conn->set_charset("utf8mb4");

        $stmt = $conn->prepare("SELECT password_hash FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            if (!password_verify($currentPassword, $row['password_hash'])) {
                $error_message = '目前密碼錯誤。';
            } elseif (password_verify($newPassword, $row['password_hash'])) {
                $error_message = '新密碼不可與舊密碼相同。';
            } else {
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $update = $conn->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
                $update->bind_param("si", $newHash, $_SESSION['user_id']);

                if ($update->execute()) {
                    $success_message = '密碼已更新。請使用新密碼登入。';
                } else {
                    $error_message = '更新密碼失敗，請稍後再試。';
                }
            }
        } else {
            $error_message = '找不到使用者資料。';
        }

        $conn->close();
    }
}
?>

<?php include 'header.php'; ?>

<div class="page-hero">
    <h1>修改密碼</h1>
    <p>為你的帳號設定更安全的密碼</p>
</div>

<section class="section-container" style="max-width: 600px;">
    <div style="background:#fff; padding:40px; border:1px solid #eee; box-shadow:0 8px 30px rgba(0,0,0,0.05);">
        <?php if (!empty($error_message)): ?>
            <div style="background:#fff5f5; color:#c33; padding:12px 15px; border-radius:6px; margin-bottom:20px; border-left:3px solid #c33;">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($success_message)): ?>
            <div style="background:#f0fff4; color:#2f855a; padding:12px 15px; border-radius:6px; margin-bottom:20px; border-left:3px solid #2f855a;">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <form action="change_password.php" method="POST">
            <div style="margin-bottom:18px;">
                <label style="display:block; margin-bottom:8px; font-weight:600; color:#333;">目前密碼</label>
                <input type="password" name="current_password" required style="width:100%; padding:12px; border:1px solid #ddd; border-radius:6px;">
            </div>
            <div style="margin-bottom:18px;">
                <label style="display:block; margin-bottom:8px; font-weight:600; color:#333;">新密碼</label>
                <input type="password" name="new_password" required placeholder="至少 8 碼，含英文字母與數字" style="width:100%; padding:12px; border:1px solid #ddd; border-radius:6px;">
            </div>
            <div style="margin-bottom:24px;">
                <label style="display:block; margin-bottom:8px; font-weight:600; color:#333;">確認新密碼</label>
                <input type="password" name="confirm_password" required style="width:100%; padding:12px; border:1px solid #ddd; border-radius:6px;">
            </div>
            <button type="submit" style="width:100%; padding:12px; background:#2c3e50; color:#fff; border:none; border-radius:6px; font-weight:600; cursor:pointer;">更新密碼</button>
        </form>
    </div>
</section>

<?php include 'footer.php'; ?>

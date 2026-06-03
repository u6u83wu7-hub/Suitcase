<?php
function h($value) {
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function couponTypeLabel($type) {
	switch ($type) {
		case 'REDUCE': return ['減價', 'pm-on'];
		case 'POINTS': return ['點數回饋', 'pm-badge'];
		default: return ['折扣', 'pm-off'];
	}
}

function couponTargetLabel($type, $adminId = 0) {
	switch ($type) {
		case 'USE CODE': return '代碼兌換';
		case 'SINGLE': return (int)$adminId > 0 ? '客服後台發放' : '前台主動領取';
		default: return '一般發放';
	}
}

$couponUseRows = [];
$couponUseResult = $conn->query("SELECT cu.coupon_code_use_id, cu.coupon_id, cu.user_id, cu.coupon_code, cu.used_at, c.coupon_name, c.coupon_type, u.name AS user_name, u.email AS user_email FROM coupon_code_uses cu LEFT JOIN coupons c ON c.coupon_id = cu.coupon_id LEFT JOIN users u ON u.user_id = cu.user_id ORDER BY cu.used_at DESC, cu.coupon_code_use_id DESC");
if ($couponUseResult) {
	while ($row = $couponUseResult->fetch_assoc()) $couponUseRows[] = $row;
}

function couponUsageLabel($limit, $used) {
	return (int)$limit <= 0 ? '無限制' : (string)(int)$limit;
}

$couponRows = [];
$couponResult = $conn->query("SELECT * FROM coupons ORDER BY created_at DESC, coupon_id DESC");
if ($couponResult) {
	while ($row = $couponResult->fetch_assoc()) $couponRows[] = $row;
}

$userRows = [];
$userResult = $conn->query("SELECT user_id, name, email FROM users ORDER BY user_id ASC");
if ($userResult) {
	while ($row = $userResult->fetch_assoc()) $userRows[] = $row;
}

$distributionRows = [];
$distributionSql = "
	SELECT cd.*, c.coupon_name, c.coupon_code, u.name AS user_name, u.email AS user_email
	FROM coupon_distributions cd
	LEFT JOIN coupons c ON c.coupon_id = cd.coupon_id
	LEFT JOIN users u ON u.user_id = cd.user_id
	ORDER BY cd.created_at DESC, cd.distribution_id DESC
";
$distributionResult = $conn->query($distributionSql);
if ($distributionResult) {
	while ($row = $distributionResult->fetch_assoc()) $distributionRows[] = $row;
}

$noticeHtml = '';
if (!empty($_GET['success'])) {
	$noticeHtml = '<div style="padding:12px 14px; border-radius:10px; background:#ecfdf5; color:#047857; margin-bottom:16px;">操作已成功完成。</div>';
} elseif (!empty($_GET['error'])) {
	$noticeHtml = '<div style="padding:12px 14px; border-radius:10px; background:#fef2f2; color:#b91c1c; margin-bottom:16px;">' . h($_GET['error']) . '</div>';
}
?>

<link rel="stylesheet" href="../css/products.css">

<div class="pm-wrap">
	<div class="pm-head">
		<div><h1 class="pm-title">🎫 優惠卷管理</h1></div>
		<button class="pm-btn pm-btn-main" type="button" id="openCreateCoupon">+ 新增優惠卷</button>
	</div>

	<?php echo $noticeHtml; ?>

	<section class="pm-card">
		<h3 class="pm-section-title">優惠卷清單</h3>
		<div class="pm-table-wrap">
			<table class="pm-table">
				<thead>
					<tr>
						<th style="width:50px;">ID</th>
						<th>優惠卷名稱</th>
						<th style="width:120px;">優惠碼</th>
						<th style="width:100px;">類型/數值</th>
						<th style="width:160px;">限制門檻</th>
						<th style="width:160px;">活動期間</th>
						<th style="width:70px;">啟用</th>
						<th style="width:80px;">上限/已用</th>
						<th style="width:220px;">操作</th>
					</tr>
				</thead>
				<tbody>
					<?php if (!empty($couponRows)): ?>
						<?php foreach ($couponRows as $coupon): ?>
							<?php [$typeText, $typeClass] = couponTypeLabel($coupon['coupon_type'] ?? 'DISCOUNT'); ?>
							<tr>
								<td>#<?php echo intval($coupon['coupon_id']); ?></td>
								<td>
									<div style="font-weight:600; color:#0f172a;"><?php echo h($coupon['coupon_name']); ?></div>
									<div style="font-size:12px; color:#64748b; margin-top:4px;">供前台領取或輸入使用</div>
								</td>
								<td>
									<?php if (!empty($coupon['coupon_code'])): ?>
										<span style="font-weight:700; color:#b91c1c;"><?php echo h($coupon['coupon_code']); ?></span>
									<?php else: ?>
										<span style="color:#94a3b8;">無 (公開牆領取)</span>
									<?php endif; ?>
								</td>
								<td>
                                    <span class="pm-badge <?php echo $typeClass; ?>" style="margin-bottom:4px;"><?php echo $typeText; ?></span><br>
									<span style="font-weight:700;">
                                        <?php if (($coupon['coupon_type'] ?? 'DISCOUNT') === 'REDUCE') echo 'NT$ ' . number_format((float)$coupon['coupon_value']);
                                        elseif (($coupon['coupon_type'] ?? 'DISCOUNT') === 'POINTS') echo number_format((float)$coupon['coupon_value']) . ' 點';
                                        else echo rtrim(rtrim(number_format((float)$coupon['coupon_value'], 2), '0'), '.') . '%'; ?>
                                    </span>
								</td>
								<td>
                                    <div style="font-weight:700; color:#0f172a;">滿 NT$ <?php echo number_format((float)($coupon['min_order_amount'] ?? 0)); ?></div>
                                    <div style="font-size:12px; color:#64748b; margin-top:4px;"><?php echo !empty($coupon['target_membership']) ? '限「'.h($coupon['target_membership']).'」領取' : '不限會員等級'; ?></div>
                                </td>
								<td style="font-size:13px; color:#444;">
									<div>起：<?php echo h($coupon['start_at'] ?? ''); ?></div>
									<div>迄：<?php echo h($coupon['end_at'] ?? ''); ?></div>
								</td>
								<td>
									<?php if ((int)($coupon['is_active'] ?? 0) === 1): ?>
										<span class="pm-badge pm-on">啟用</span>
									<?php else: ?>
										<span class="pm-badge pm-off">停用</span>
									<?php endif; ?>
								</td>
								<td><?php echo h(couponUsageLabel($coupon['usage_limit'] ?? 0, $coupon['used_count'] ?? 0)); ?> / <?php echo (int)($coupon['used_count'] ?? 0); ?></td>
								<td>
                                    <div style="display:flex; gap:6px; flex-wrap:wrap;">
                                        <button class="pm-btn pm-btn-sub pm-btn-sm js-send-coupon" type="button" data-id="<?php echo intval($coupon['coupon_id']); ?>" data-name="<?php echo h($coupon['coupon_name']); ?>" data-code="<?php echo h($coupon['coupon_code'] ?? ''); ?>">補發</button>
                                        
                                        <button class="pm-btn pm-btn-main pm-btn-sm js-edit-coupon" type="button" data-coupon="<?php echo htmlspecialchars(json_encode($coupon), ENT_QUOTES, 'UTF-8'); ?>">編輯</button>
                                        
                                        <form action="backend_action.php" method="POST" style="margin:0;" onsubmit="return confirm('警告：確定要永久刪除這張優惠卷嗎？');">
                                            <input type="hidden" name="action" value="delete_coupon">
                                            <input type="hidden" name="coupon_id" value="<?php echo intval($coupon['coupon_id']); ?>">
                                            <button class="pm-btn pm-btn-danger pm-btn-sm" type="submit">刪除</button>
                                        </form>
                                    </div>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php else: ?>
						<tr><td colspan="9" style="text-align:center; padding:24px; color:#94a3b8;">目前尚無優惠卷資料。</td></tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	</section>
    
    </div>

<style>
.coupon-modal { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); display: none; align-items: center; justify-content: center; z-index: 999; backdrop-filter: blur(2px); }
.coupon-modal .modal-panel { background: #fff; border-radius: 12px; padding: 24px; width: min(760px, 95vw); max-height: 85vh; display: flex; flex-direction: column; box-shadow: 0 20px 40px rgba(0,0,0,0.1); }
.modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; }
.modal-title { font-size: 18px; font-weight: 700; color: #0f172a; }
.modal-body { overflow-y: auto; flex: 1; padding-right: 8px; }
</style>

<div class="coupon-modal" id="couponModal">
	<div class="modal-panel">
		<div class="modal-header">
			<div class="modal-title" id="couponModalTitle">新增優惠卷</div>
			<button type="button" class="pm-btn pm-btn-sub pm-btn-sm" id="closeCouponModal">✕ 關閉</button>
		</div>
		<form action="backend_action.php" method="POST" class="modal-body" id="couponForm">
			<input type="hidden" name="action" id="couponFormAction" value="add_coupon">
            <input type="hidden" name="coupon_id" id="edit_coupon_id" value="">
            
			<div class="pm-grid">
				<div class="pm-col-12" style="grid-column: span 12;">
					<label for="coupon_name">優惠卷名稱</label>
					<input class="pm-input" type="text" id="coupon_name" name="coupon_name" required placeholder="例如：新會員折扣卷">
				</div>

				<div class="pm-col-12" style="grid-column: span 12; margin-bottom: 8px;">
					<label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
						<input type="checkbox" id="require_code" name="require_code" value="1" style="width:auto; margin:0;">
						<span style="font-weight:normal; color:#475569;">啟用專屬優惠碼 (勾選後才需輸入代碼，未勾選代表前台公開領取)</span>
					</label>
				</div>
				<div class="pm-col-12" style="grid-column: span 12; display: none;" id="couponCodeWrap">
					<label for="coupon_code">專屬優惠碼設定</label>
					<input class="pm-input" type="text" id="coupon_code" name="coupon_code" placeholder="例如：VIP2026 (請勿包含空白與特殊符號)">
				</div>

				<div class="pm-col-4" style="grid-column: span 4;">
					<label for="coupon_type">優惠卷類型</label>
					<select class="pm-select" id="coupon_type" name="coupon_type" required>
						<option value="DISCOUNT">折扣</option>
						<option value="REDUCE">減價</option>
						<option value="POINTS">點數回饋</option>
					</select>
				</div>
				<div class="pm-col-4" style="grid-column: span 4;">
					<label for="coupon_value" id="coupon_value_label">數值</label>
					<input class="pm-input" type="number" step="0.01" id="coupon_value" name="coupon_value" required placeholder="例如：15">
				</div>
				<div class="pm-col-4" style="grid-column: span 4;">
					<label for="usage_limit">總發放數量 (留白=無限制)</label>
					<input class="pm-input" type="number" id="usage_limit" name="usage_limit" placeholder="例如：100">
				</div>

				<div class="pm-col-6" style="grid-column: span 6;">
					<label for="target_membership">領取資格 (會員等級限制)</label>
					<input class="pm-input" type="text" id="target_membership" name="target_membership" placeholder="例如：VIP (留白代表全體可領)">
				</div>
				<div class="pm-col-6" style="grid-column: span 6;">
					<label for="min_order_amount">結帳滿額限制</label>
					<input class="pm-input" type="number" step="0.01" id="min_order_amount" name="min_order_amount" value="0" placeholder="例如：1000">
				</div>

				<div class="pm-col-4" style="grid-column: span 4;">
					<label for="start_at">開始日期</label>
					<input class="pm-input" type="datetime-local" id="start_at" name="start_at" required>
				</div>
				<div class="pm-col-4" style="grid-column: span 4;">
					<label for="end_at">結束日期</label>
					<input class="pm-input" type="datetime-local" id="end_at" name="end_at" required>
				</div>
				<div class="pm-col-4" style="grid-column: span 4;">
					<label for="is_active">啟用狀態</label>
					<select class="pm-select" id="is_active" name="is_active">
						<option value="1">啟用</option>
						<option value="0">停用</option>
					</select>
				</div>
				<div class="pm-col-12" style="display:flex; gap:10px; justify-content:flex-end; margin-top:6px;">
					<button class="pm-btn pm-btn-main" type="submit" id="couponSubmitBtn">建立優惠卷</button>
				</div>
			</div>
		</form>
	</div>
</div>

<div class="coupon-modal" id="sendCouponModal">
	<div class="modal-panel">
		<div class="modal-header">
			<div class="modal-title">客服手動補發優惠卷</div>
			<button type="button" class="pm-btn pm-btn-sub pm-btn-sm" id="closeSendCouponModal">✕ 關閉</button>
		</div>
		<form action="backend_action.php" method="POST" class="modal-body">
			<input type="hidden" name="action" value="send_coupon">
			<input type="hidden" name="coupon_id" id="send_coupon_id" value="">
			<div class="pm-grid">
				<div class="pm-col-12">
					<label>優惠卷名稱</label>
					<input class="pm-input" type="text" id="send_coupon_name" readonly>
				</div>
				<div class="pm-col-6" style="grid-column: span 6;">
					<label for="target_user_id">補發給哪位會員？</label>
					<select class="pm-select" id="target_user_id" name="target_user_id" required>
						<option value="">請選擇指定會員</option>
						<?php foreach ($userRows as $user): ?>
							<option value="<?php echo intval($user['user_id']); ?>">#<?php echo intval($user['user_id']); ?> <?php echo h($user['name'] . ' / ' . $user['email']); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="pm-col-6" style="grid-column: span 6;">
					<label for="quantity">發放張數</label>
					<input class="pm-input" type="number" id="quantity" name="quantity" min="1" value="1" required>
				</div>
				<div class="pm-col-12" style="display:flex; gap:10px; justify-content:flex-end; margin-top:6px;">
					<button class="pm-btn pm-btn-main" type="submit">立即發放</button>
				</div>
			</div>
		</form>
	</div>
</div>

<script>
const couponModal = document.getElementById('couponModal');
const couponFormAction = document.getElementById('couponFormAction');
const couponModalTitle = document.getElementById('couponModalTitle');
const couponSubmitBtn = document.getElementById('couponSubmitBtn');

const openCreateCoupon = document.getElementById('openCreateCoupon');
const closeCouponModal = document.getElementById('closeCouponModal');

const requireCodeCheckbox = document.getElementById('require_code');
const couponCodeWrap = document.getElementById('couponCodeWrap');
const couponCodeInput = document.getElementById('coupon_code');

if (requireCodeCheckbox && couponCodeWrap && couponCodeInput) {
    requireCodeCheckbox.addEventListener('change', function() {
        if (this.checked) {
            couponCodeWrap.style.display = 'block';
            couponCodeInput.required = true;
        } else {
            couponCodeWrap.style.display = 'none';
            couponCodeInput.required = false;
            couponCodeInput.value = ''; 
        }
    });
}

// 💡 點擊「新增優惠券」按鈕時清空表單
if (openCreateCoupon) {
	openCreateCoupon.addEventListener('click', () => {
        document.getElementById('couponForm').reset();
        couponFormAction.value = 'add_coupon';
        couponModalTitle.textContent = '新增優惠卷';
        couponSubmitBtn.textContent = '建立優惠卷';
        requireCodeCheckbox.checked = false;
        requireCodeCheckbox.dispatchEvent(new Event('change'));
		couponModal.style.display = 'flex';
	});
}

// 💡 點擊「編輯」按鈕時載入資料
document.querySelectorAll('.js-edit-coupon').forEach((button) => {
    button.addEventListener('click', () => {
        const data = JSON.parse(button.dataset.coupon);
        
        document.getElementById('edit_coupon_id').value = data.coupon_id;
        document.getElementById('coupon_name').value = data.coupon_name;
        document.getElementById('coupon_type').value = data.coupon_type;
        document.getElementById('coupon_value').value = data.coupon_value;
        document.getElementById('min_order_amount').value = data.min_order_amount;
        document.getElementById('target_membership').value = data.target_membership || '';
        document.getElementById('usage_limit').value = data.usage_limit == 0 ? '' : data.usage_limit;
        document.getElementById('is_active').value = data.is_active;
        
        // 處理 datetime-local 格式 (將空白轉 T)
        if(data.start_at) document.getElementById('start_at').value = data.start_at.replace(' ', 'T');
        if(data.end_at) document.getElementById('end_at').value = data.end_at.replace(' ', 'T');

        if (data.coupon_code) {
            requireCodeCheckbox.checked = true;
            couponCodeInput.value = data.coupon_code;
        } else {
            requireCodeCheckbox.checked = false;
            couponCodeInput.value = '';
        }
        requireCodeCheckbox.dispatchEvent(new Event('change'));

        couponFormAction.value = 'edit_coupon';
        couponModalTitle.textContent = '編輯優惠卷';
        couponSubmitBtn.textContent = '儲存變更';
        
        couponModal.style.display = 'flex';
    });
});

if (closeCouponModal) closeCouponModal.addEventListener('click', () => { couponModal.style.display = 'none'; });

// 處理手動發放
const sendCouponModal = document.getElementById('sendCouponModal');
const closeSendCouponModal = document.getElementById('closeSendCouponModal');
document.querySelectorAll('.js-send-coupon').forEach((button) => {
	button.addEventListener('click', () => {
		document.getElementById('send_coupon_id').value = button.dataset.id || '';
		document.getElementById('send_coupon_name').value = button.dataset.name + (button.dataset.code ? '（' + button.dataset.code + '）' : '');
		sendCouponModal.style.display = 'flex';
	});
});
if (closeSendCouponModal) closeSendCouponModal.addEventListener('click', () => { sendCouponModal.style.display = 'none'; });

window.addEventListener('click', (event) => {
	if (event.target === couponModal) couponModal.style.display = 'none';
	if (event.target === sendCouponModal) sendCouponModal.style.display = 'none';
});
</script>
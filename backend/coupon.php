<?php
function h($value) {
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function couponTypeLabel($type) {
	switch ($type) {
		case 'REDUCE':
			return ['減價', 'pm-on'];
		case 'POINTS':
			return ['點數回饋', 'pm-badge'];
		default:
			return ['折扣', 'pm-off'];
	}
}

function couponTargetLabel($type) {
	switch ($type) {
		case 'ALL':
			return '全體用戶';
		case 'USE CODE':
			return 'USE CODE';
		default:
			return '特定用戶';
	}
}

$couponUseRows = [];
$couponUseResult = $conn->query("SELECT cu.coupon_code_use_id, cu.coupon_id, cu.user_id, cu.coupon_code, cu.used_at, c.coupon_name, c.coupon_type, u.name AS user_name, u.email AS user_email FROM coupon_code_uses cu LEFT JOIN coupons c ON c.coupon_id = cu.coupon_id LEFT JOIN users u ON u.user_id = cu.user_id ORDER BY cu.used_at DESC, cu.coupon_code_use_id DESC");
if ($couponUseResult) {
	while ($row = $couponUseResult->fetch_assoc()) {
		$couponUseRows[] = $row;
	}
}

function couponUsageLabel($limit, $used) {
	$limit = (int)$limit;
	$used = (int)$used;
	if ($limit <= 0) {
		return '無限制';
	}
	return (string)$limit;
}

function couponUsedLabel($used) {
	$used = (int)$used;
	return (string)$used;
}

$couponRows = [];
$couponResult = $conn->query("SELECT * FROM coupons ORDER BY created_at DESC, coupon_id DESC");
if ($couponResult) {
	while ($row = $couponResult->fetch_assoc()) {
		$couponRows[] = $row;
	}
}

$userRows = [];
$userResult = $conn->query("SELECT user_id, name, email FROM users ORDER BY user_id ASC");
if ($userResult) {
	while ($row = $userResult->fetch_assoc()) {
		$userRows[] = $row;
	}
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
	while ($row = $distributionResult->fetch_assoc()) {
		$distributionRows[] = $row;
	}
}

$noticeHtml = '';
if (!empty($_GET['success'])) {
	$noticeHtml = '<div style="padding:12px 14px; border-radius:10px; background:#ecfdf5; color:#047857; margin-bottom:16px;">優惠卷已建立完成。</div>';
} elseif (!empty($_GET['error'])) {
	$noticeHtml = '<div style="padding:12px 14px; border-radius:10px; background:#fef2f2; color:#b91c1c; margin-bottom:16px;">' . h($_GET['error']) . '</div>';
}
?>

<link rel="stylesheet" href="../css/products.css">

<div class="pm-wrap">
	<div class="pm-head">
		<div>
			<h1 class="pm-title">🎫 優惠卷管理</h1>
		</div>
		<button class="pm-btn pm-btn-main" type="button" id="openCreateCoupon">+ 新增優惠卷</button>
	</div>

	<?php echo $noticeHtml; ?>

	<section class="pm-card">
		<h3 class="pm-section-title">優惠卷清單</h3>
		<div class="pm-table-wrap">
			<table class="pm-table">
				<thead>
					<tr>
						<th style="width:90px;">ID</th>
						<th>優惠卷名稱</th>
						<th style="width:160px;">優惠碼</th>
						<th style="width:120px;">類型</th>
						<th style="width:140px;">數值</th>
						<th style="width:160px;">使用門檻</th>
						<th style="width:180px;">期間</th>
						<th style="width:90px;">啟用</th>
						<th style="width:120px;">使用上限</th>
						<th style="width:120px;">已使用次數</th>
						<th style="width:120px;">發送</th>
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
									<div style="font-size:12px; color:#64748b; margin-top:4px;">可讓管理者後續於前台輸入優惠碼使用</div>
								</td>
								<td>
									<?php if (!empty($coupon['coupon_code'])): ?>
										<?php echo h($coupon['coupon_code']); ?>
									<?php else: ?>
										<span style="color:#94a3b8;">未設定</span>
									<?php endif; ?>
								</td>
								<td><span class="pm-badge <?php echo $typeClass; ?>"><?php echo $typeText; ?></span></td>
								<td>
									<?php if (($coupon['coupon_type'] ?? 'DISCOUNT') === 'REDUCE'): ?>
										NT$ <?php echo number_format((float)$coupon['coupon_value']); ?>
									<?php elseif (($coupon['coupon_type'] ?? 'DISCOUNT') === 'POINTS'): ?>
										<?php echo number_format((float)$coupon['coupon_value']); ?> 點
									<?php else: ?>
										<?php echo rtrim(rtrim(number_format((float)$coupon['coupon_value'], 2), '0'), '.'); ?>%
									<?php endif; ?>
								</td>
								<td>滿 NT$ <?php echo number_format((float)($coupon['min_order_amount'] ?? 0)); ?></td>
								<td>
									<div><?php echo h($coupon['start_at'] ?? ''); ?></div>
									<div><?php echo h($coupon['end_at'] ?? ''); ?></div>
								</td>
								<td>
									<?php if ((int)($coupon['is_active'] ?? 0) === 1): ?>
										<span class="pm-badge pm-on">啟用</span>
									<?php else: ?>
										<span class="pm-badge pm-off">停用</span>
									<?php endif; ?>
								</td>
								<td>
									<?php echo h(couponUsageLabel($coupon['usage_limit'] ?? 0, $coupon['used_count'] ?? 0)); ?>
								</td>
								<td>
									<?php echo h(couponUsedLabel($coupon['used_count'] ?? 0)); ?>
								</td>
								<td>
									<button class="pm-btn pm-btn-sub pm-btn-sm js-send-coupon" type="button"
										data-id="<?php echo intval($coupon['coupon_id']); ?>"
										data-name="<?php echo h($coupon['coupon_name']); ?>"
										data-code="<?php echo h($coupon['coupon_code'] ?? ''); ?>">
										發送給用戶
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php else: ?>
						<tr>
							<td colspan="11" style="text-align:center; padding:24px; color:#94a3b8;">目前尚無優惠卷資料。</td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	</section>

	<section class="pm-card">
		<h3 class="pm-section-title">優惠卷發送紀錄</h3>
		<div class="pm-table-wrap">
			<table class="pm-table">
				<thead>
					<tr>
						<th style="width:90px;">ID</th>
						<th>優惠卷</th>
						<th>對象</th>
						<th style="width:110px;">張數</th>
						<th style="width:120px;">類型</th>
						<th style="width:180px;">時間</th>
					</tr>
				</thead>
				<tbody>
					<?php if (!empty($distributionRows)): ?>
						<?php foreach ($distributionRows as $row): ?>
							<tr>
								<td>#<?php echo intval($row['distribution_id']); ?></td>
								<td><?php echo h($row['coupon_name'] ?? ''); ?><?php echo !empty($row['coupon_code']) ? '（' . h($row['coupon_code']) . '）' : ''; ?></td>
								<td><?php echo h(($row['user_name'] ?? '') !== '' ? $row['user_name'] : ($row['user_email'] ?? '')); ?></td>
								<td><?php echo intval($row['quantity'] ?? 0); ?></td>
									<td><?php echo h(couponTargetLabel($row['target_type'] ?? 'SINGLE')); ?></td>
								<td><?php echo h($row['created_at'] ?? ''); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php else: ?>
						<tr>
							<td colspan="6" style="text-align:center; padding:24px; color:#94a3b8;">目前尚無發送紀錄。</td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	</section>

	<section class="pm-card">
		<h3 class="pm-section-title">前台優惠卷兌換紀錄</h3>
		<div class="pm-table-wrap">
			<table class="pm-table">
				<thead>
					<tr>
						<th style="width:90px;">ID</th>
						<th>優惠卷</th>
						<th>用戶</th>
						<th style="width:150px;">代碼</th>
						<th style="width:180px;">兌換時間</th>
					</tr>
				</thead>
				<tbody>
					<?php if (!empty($couponUseRows)): ?>
						<?php foreach ($couponUseRows as $row): ?>
							<tr>
								<td>#<?php echo intval($row['coupon_code_use_id']); ?></td>
								<td><?php echo h($row['coupon_name'] ?? ''); ?></td>
								<td><?php echo h(($row['user_name'] ?? '') !== '' ? $row['user_name'] : ($row['user_email'] ?? '')); ?></td>
								<td><?php echo h($row['coupon_code'] ?? ''); ?></td>
								<td><?php echo h($row['used_at'] ?? ''); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php else: ?>
						<tr>
							<td colspan="5" style="text-align:center; padding:24px; color:#94a3b8;">目前尚無前台兌換紀錄。</td>
						</tr>
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
			<div class="modal-title">新增優惠卷</div>
			<button type="button" class="pm-btn pm-btn-sub pm-btn-sm" id="closeCouponModal">✕ 關閉</button>
		</div>
		<form action="backend_action.php" method="POST" class="modal-body">
			<input type="hidden" name="action" value="add_coupon">
			<div class="pm-grid">
				<div class="pm-col-6" style="grid-column: span 6;">
					<label for="coupon_name">優惠卷名稱</label>
					<input class="pm-input" type="text" id="coupon_name" name="coupon_name" required placeholder="例如：新會員折扣卷">
				</div>
				<div class="pm-col-6" style="grid-column: span 6;">
					<label for="coupon_code">優惠碼（可留白）</label>
					<input class="pm-input" type="text" id="coupon_code" name="coupon_code" placeholder="例如：WELCOME2026">
				</div>
				<div class="pm-col-6" style="grid-column: span 6;">
					<label for="coupon_type">優惠卷類型</label>
					<select class="pm-select" id="coupon_type" name="coupon_type" required>
						<option value="DISCOUNT">折扣</option>
						<option value="REDUCE">減價</option>
						<option value="POINTS">點數回饋</option>
					</select>
				</div>
				<div class="pm-col-6" style="grid-column: span 6;">
					<label for="coupon_value" id="coupon_value_label">數值</label>
					<input class="pm-input" type="number" step="0.01" id="coupon_value" name="coupon_value" required placeholder="例如：15">
				</div>
				<div class="pm-col-6" style="grid-column: span 6;">
					<label for="min_order_amount">使用門檻</label>
					<input class="pm-input" type="number" step="0.01" id="min_order_amount" name="min_order_amount" value="0" placeholder="例如：1000">
				</div>
				<div class="pm-col-6" style="grid-column: span 6;">
					<label for="usage_limit">可使用次數（可留白）</label>
					<input class="pm-input" type="number" id="usage_limit" name="usage_limit" placeholder="留白代表不限制">
				</div>
				<div class="pm-col-6" style="grid-column: span 6;">
					<label for="start_at">開始日期</label>
					<input class="pm-input" type="datetime-local" id="start_at" name="start_at" required>
				</div>
				<div class="pm-col-6" style="grid-column: span 6;">
					<label for="end_at">結束日期</label>
					<input class="pm-input" type="datetime-local" id="end_at" name="end_at" required>
				</div>
				<div class="pm-col-6" style="grid-column: span 6;">
					<label for="is_active">啟用狀態</label>
					<select class="pm-select" id="is_active" name="is_active">
						<option value="1">啟用</option>
						<option value="0">停用</option>
					</select>
				</div>
				<div class="pm-col-12" style="display:flex; gap:10px; justify-content:flex-end; margin-top:6px;">
					<button class="pm-btn pm-btn-main" type="submit">建立優惠卷</button>
				</div>
			</div>
		</form>
	</div>
</div>

<div class="coupon-modal" id="sendCouponModal">
	<div class="modal-panel">
		<div class="modal-header">
			<div class="modal-title" id="sendCouponTitle">發送優惠卷</div>
			<button type="button" class="pm-btn pm-btn-sub pm-btn-sm" id="closeSendCouponModal">✕ 關閉</button>
		</div>
		<form action="backend_action.php" method="POST" class="modal-body">
			<input type="hidden" name="action" value="send_coupon">
			<input type="hidden" name="coupon_id" id="send_coupon_id" value="">
			<div class="pm-grid">
				<div class="pm-col-6" style="grid-column: span 6;">
					<label>優惠卷名稱</label>
					<input class="pm-input" type="text" id="send_coupon_name" readonly>
				</div>
				<div class="pm-col-6" style="grid-column: span 6;">
					<label for="target_type">發送對象</label>
					<select class="pm-select" id="target_type" name="target_type" required>
						<option value="SINGLE">特定用戶</option>
						<option value="ALL">全體用戶</option>
					</select>
				</div>
				<div class="pm-col-6" style="grid-column: span 6;" id="targetUserWrap">
					<label for="target_user_id">選擇用戶</label>
					<select class="pm-select" id="target_user_id" name="target_user_id">
						<option value="">請選擇用戶</option>
						<?php foreach ($userRows as $user): ?>
							<option value="<?php echo intval($user['user_id']); ?>">#<?php echo intval($user['user_id']); ?> <?php echo h($user['name'] . ' / ' . $user['email']); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="pm-col-6" style="grid-column: span 6;">
					<label for="quantity">優惠卷張數</label>
					<input class="pm-input" type="number" id="quantity" name="quantity" min="1" value="1" required>
				</div>
				<div class="pm-col-12" style="display:flex; gap:10px; justify-content:flex-end; margin-top:6px;">
					<button class="pm-btn pm-btn-main" type="submit">發送優惠卷</button>
				</div>
			</div>
		</form>
	</div>
</div>

<script>
const couponModal = document.getElementById('couponModal');
const openCreateCoupon = document.getElementById('openCreateCoupon');
const closeCouponModal = document.getElementById('closeCouponModal');
const sendCouponModal = document.getElementById('sendCouponModal');
const closeSendCouponModal = document.getElementById('closeSendCouponModal');
const targetType = document.getElementById('target_type');
const targetUserWrap = document.getElementById('targetUserWrap');
const sendCouponId = document.getElementById('send_coupon_id');
const sendCouponName = document.getElementById('send_coupon_name');
const couponType = document.getElementById('coupon_type');
const couponValue = document.getElementById('coupon_value');
const couponValueLabel = document.getElementById('coupon_value_label');

function updateCouponValueHint() {
	if (!couponType || !couponValue || !couponValueLabel) {
		return;
	}
	if (couponType.value === 'DISCOUNT') {
		couponValueLabel.textContent = '數值（折扣）';
		couponValue.placeholder = '例如：15，代表 15% 折扣';
		couponValue.step = '0.01';
	} else if (couponType.value === 'REDUCE') {
		couponValueLabel.textContent = '數值（減價）';
		couponValue.placeholder = '例如：200，代表折抵 200 元';
		couponValue.step = '0.01';
	} else {
		couponValueLabel.textContent = '數值（點數回饋）';
		couponValue.placeholder = '例如：50，代表回饋 50 點';
		couponValue.step = '1';
	}
}

if (openCreateCoupon) {
	openCreateCoupon.addEventListener('click', () => {
		couponModal.style.display = 'flex';
	});
}

if (closeCouponModal) {
	closeCouponModal.addEventListener('click', () => {
		couponModal.style.display = 'none';
	});
}

document.querySelectorAll('.js-send-coupon').forEach((button) => {
	button.addEventListener('click', () => {
		sendCouponId.value = button.dataset.id || '';
		sendCouponName.value = button.dataset.name + (button.dataset.code ? '（' + button.dataset.code + '）' : '');
		if (targetType) {
			targetType.value = 'SINGLE';
		}
		if (targetUserWrap) {
			targetUserWrap.style.display = 'block';
		}
		sendCouponModal.style.display = 'flex';
	});
});

if (closeSendCouponModal) {
	closeSendCouponModal.addEventListener('click', () => {
		sendCouponModal.style.display = 'none';
	});
}

if (targetType) {
	targetType.addEventListener('change', () => {
		if (targetUserWrap) {
			targetUserWrap.style.display = targetType.value === 'ALL' ? 'none' : 'block';
			targetUserWrap.querySelector('select') && (targetUserWrap.querySelector('select').required = targetType.value !== 'ALL');
		}
		const targetUserSelect = document.getElementById('target_user_id');
		if (targetUserSelect) {
			targetUserSelect.required = targetType.value !== 'ALL';
			if (targetType.value === 'ALL') {
				targetUserSelect.value = '';
			}
		}
	});
	const targetUserSelect = document.getElementById('target_user_id');
	if (targetUserSelect) {
		targetUserSelect.required = targetType.value !== 'ALL';
	}
}

if (couponType) {
	couponType.addEventListener('change', updateCouponValueHint);
	updateCouponValueHint();
}

window.addEventListener('click', (event) => {
	if (event.target === couponModal) {
		couponModal.style.display = 'none';
	}
	if (event.target === sendCouponModal) {
		sendCouponModal.style.display = 'none';
	}
});
</script>
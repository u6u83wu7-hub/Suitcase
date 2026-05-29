<?php echo "現在伺服器顯示的時間是: " . date('Y-m-d H:i:s'); ?>

<?php
require_once __DIR__ . '/auth_guard.php';
require_once __DIR__ . '/../homepage/includes/promotion_price_sync.php';
apRunPromotionSync($conn);
// marketing.php - 行銷活動管理主頁面
//版本3
function h($value) {
	return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function promotionStatusLabel($startAt, $endAt) {
	$now = time();
	$startTime = strtotime($startAt);
	$endTime = strtotime($endAt);
	if ($now < $startTime) {
		return ['未開始', 'pm-off'];
	}
	if ($now > $endTime) {
		return ['已結束', 'pm-off'];
	}
	return ['進行中', 'pm-on'];
}

$keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
$statusFilter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : '';
$activeFilter = isset($_GET['active_filter']) ? trim($_GET['active_filter']) : '';

$conditions = [];
if ($keyword !== '') {
	$safeKeyword = $conn->real_escape_string($keyword);
	$conditions[] = "p.name LIKE '%{$safeKeyword}%'";
}
if ($statusFilter === 'upcoming') {
	$conditions[] = "NOW() < p.start_at";
} elseif ($statusFilter === 'ongoing') {
	$conditions[] = "NOW() BETWEEN p.start_at AND p.end_at";
} elseif ($statusFilter === 'ended') {
	$conditions[] = "NOW() > p.end_at";
}
if ($activeFilter !== '') {
	$conditions[] = "p.is_active = " . intval($activeFilter);
}

$whereClause = '';
if (!empty($conditions)) {
	$whereClause = 'WHERE ' . implode(' AND ', $conditions);
}

$promotions = [];
$promotionSql = "
	SELECT
		p.*, 
		(SELECT COUNT(*) FROM promotion_products pp WHERE pp.promotion_id = p.id) AS product_count,
		(SELECT COUNT(*) FROM promotion_banners pb WHERE pb.promotion_id = p.id) AS banner_count,
		(SELECT pb.banner_image_url FROM promotion_banners pb WHERE pb.promotion_id = p.id ORDER BY pb.sort_order ASC LIMIT 1) AS banner_image
	FROM promotions p
	{$whereClause}
	ORDER BY p.start_at DESC, p.id DESC
";
$promotionResult = $conn->query($promotionSql);
if ($promotionResult) {
	while ($row = $promotionResult->fetch_assoc()) {
		$promotions[] = $row;
	}
}

$allProducts = [];
$productResult = $conn->query("SELECT product_id, name FROM products ORDER BY name ASC");
if ($productResult) {
	while ($row = $productResult->fetch_assoc()) {
		$allProducts[] = $row;
	}
}

$promotionProducts = [];
$ppResult = $conn->query("SELECT promotion_id, product_id FROM promotion_products");
if ($ppResult) {
	while ($row = $ppResult->fetch_assoc()) {
		$pid = (int)$row['promotion_id'];
		if (!isset($promotionProducts[$pid])) {
			$promotionProducts[$pid] = [];
		}
		$promotionProducts[$pid][] = (int)$row['product_id'];
	}
}

$productUsage = [];
foreach ($promotionProducts as $promotionId => $productIds) {
	foreach ($productIds as $productId) {
		if (!isset($productUsage[$productId])) {
			$productUsage[$productId] = [];
		}
		$productUsage[$productId][] = (int)$promotionId;
	}
}

$bannerMap = [];
$bannerResult = $conn->query("SELECT promotion_id, banner_image_url FROM promotion_banners ORDER BY sort_order ASC");
if ($bannerResult) {
	while ($row = $bannerResult->fetch_assoc()) {
		$pid = (int)$row['promotion_id'];
		if (!isset($bannerMap[$pid])) {
			$bannerMap[$pid] = [];
		}
		$bannerMap[$pid][] = $row['banner_image_url'];
	}
}

$activeProducts = [];
$activeSql = "
	SELECT p.id AS promotion_id, p.name AS promotion_name, pr.product_id, pr.name AS product_name
	FROM promotions p
	INNER JOIN promotion_products pp ON pp.promotion_id = p.id
	INNER JOIN products pr ON pr.product_id = pp.product_id
	WHERE p.is_active = 1
	  AND NOW() BETWEEN p.start_at AND p.end_at
	ORDER BY p.start_at DESC, p.id DESC, pr.name ASC
";
$activeResult = $conn->query($activeSql);
if ($activeResult) {
	while ($row = $activeResult->fetch_assoc()) {
		$activeProducts[] = $row;
	}
}
?>

<link rel="stylesheet" href="../css/products.css">

<div class="pm-wrap">
	<div class="pm-head">
		<div>
			<h1 class="pm-title">📢 行銷活動管理</h1>
		</div>
		<button class="pm-btn pm-btn-main" type="button" id="openCreatePromotion">+ 新增活動</button>
	</div>

	<section class="pm-card">
		<h3 class="pm-section-title">活動篩選</h3>
		<form class="pm-grid" action="backend.php" method="GET">
			<input type="hidden" name="page" value="marketing">
			<div class="pm-col-3">
				<label for="keyword">關鍵字</label>
				<input class="pm-input" type="text" id="keyword" name="keyword" placeholder="活動名稱" value="<?php echo h($keyword); ?>">
			</div>
			<div class="pm-col-3">
				<label for="status_filter">活動狀態</label>
				<select class="pm-select" id="status_filter" name="status_filter">
					<option value="">全部</option>
					<option value="upcoming" <?php echo $statusFilter === 'upcoming' ? 'selected' : ''; ?>>未開始</option>
					<option value="ongoing" <?php echo $statusFilter === 'ongoing' ? 'selected' : ''; ?>>進行中</option>
					<option value="ended" <?php echo $statusFilter === 'ended' ? 'selected' : ''; ?>>已結束</option>
				</select>
			</div>
			<div class="pm-col-3">
				<label for="active_filter">啟用狀態</label>
				<select class="pm-select" id="active_filter" name="active_filter">
					<option value="">全部</option>
					<option value="1" <?php echo $activeFilter === '1' ? 'selected' : ''; ?>>啟用</option>
					<option value="0" <?php echo $activeFilter === '0' ? 'selected' : ''; ?>>停用</option>
				</select>
			</div>
			<div class="pm-col-3" style="display:flex; gap:8px; align-items:flex-end;">
				<button class="pm-btn pm-btn-main" type="submit">套用篩選</button>
				<a class="pm-btn pm-btn-sub" href="backend.php?page=marketing">清除</a>
			</div>
		</form>
	</section>

	<section class="pm-card">
		<h3 class="pm-section-title">活動清單</h3>
		<div class="pm-table-wrap">
			<table class="pm-table">
				<thead>
					<tr>
						<th style="width:80px;">ID</th>
						<th>活動名稱</th>
						<th style="width:120px;">活動圖片</th>
						<th style="width:160px;">折扣規則</th>
						<th style="width:220px;">活動期間</th>
						<th style="width:120px;">狀態</th>
						<th style="width:90px;">啟用</th>
						<th style="width:90px;">商品數</th>
						<th style="width:90px;">Banner</th>
						<th style="width:200px;">操作</th>
					</tr>
				</thead>
				<tbody>
					<?php if (!empty($promotions)): ?>
						<?php foreach ($promotions as $promo): ?>
							<?php [$statusText, $statusClass] = promotionStatusLabel($promo['start_at'], $promo['end_at']); ?>
							<tr>
								<td>#<?php echo intval($promo['id']); ?></td>
								<td>
									<div style="font-weight:600; color:#0f172a;"><?php echo h($promo['name']); ?></div>
									<div style="font-size:12px; color:#64748b; margin-top:4px;"><?php echo h($promo['description']); ?></div>
								</td>
								<td>
									<?php if (!empty($promo['promotion_image_url'])): ?>
										<img src="../<?php echo h($promo['promotion_image_url']); ?>" alt="活動圖片" style="width:90px; height:54px; object-fit:cover; border-radius:6px; border:1px solid #e2e8f0;">
									<?php else: ?>
										<span style="color:#94a3b8;">尚未上傳</span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ($promo['discount_type'] === 'PERCENT'): ?>
										<?php echo h(rtrim(rtrim(number_format((float)$promo['discount_value'], 2), '0'), '.')); ?>% OFF
									<?php else: ?>
										折抵 NT$ <?php echo number_format((float)$promo['discount_value']); ?>
									<?php endif; ?>
								</td>
								<td>
									<div><?php echo h($promo['start_at']); ?></div>
									<div><?php echo h($promo['end_at']); ?></div>
								</td>
								<td><span class="pm-badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></span></td>
								<td>
									<?php if ((int)$promo['is_active'] === 1): ?>
										<span class="pm-badge pm-on">啟用</span>
									<?php else: ?>
										<span class="pm-badge pm-off">停用</span>
									<?php endif; ?>
								</td>
								<td><?php echo intval($promo['product_count']); ?></td>
								<td><?php echo intval($promo['banner_count']); ?></td>
								<td>
									<div class="pm-actions">
										<button class="pm-btn pm-btn-edit pm-btn-sm js-edit-promotion" type="button"
											data-id="<?php echo intval($promo['id']); ?>"
											data-name="<?php echo h($promo['name']); ?>"
											data-description="<?php echo h($promo['description']); ?>"
											data-discount-type="<?php echo h($promo['discount_type']); ?>"
											data-discount-value="<?php echo h($promo['discount_value']); ?>"
											data-start-at="<?php echo h(date('Y-m-d\TH:i', strtotime($promo['start_at']))); ?>"
											data-end-at="<?php echo h(date('Y-m-d\TH:i', strtotime($promo['end_at']))); ?>"
											data-is-active="<?php echo intval($promo['is_active']); ?>"
											data-image-url="<?php echo h($promo['promotion_image_url']); ?>">
											編輯
										</button>
										<button class="pm-btn pm-btn-sub pm-btn-sm js-bind-products" type="button" data-id="<?php echo intval($promo['id']); ?>" data-name="<?php echo h($promo['name']); ?>">商品綁定</button>
										<button class="pm-btn pm-btn-edit pm-btn-sm js-upload-banner" type="button" data-id="<?php echo intval($promo['id']); ?>" data-name="<?php echo h($promo['name']); ?>">Banner</button>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php else: ?>
						<tr>
							<td colspan="10" style="text-align:center; padding:24px; color:#94a3b8;">目前沒有符合條件的活動。</td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	</section>

	<section class="pm-card">
		<h3 class="pm-section-title">目前進行中活動商品</h3>
		<div class="pm-table-wrap">
			<table class="pm-table" style="min-width:600px;">
				<thead>
					<tr>
						<th style="width:100px;">活動 ID</th>
						<th>活動名稱</th>
						<th style="width:120px;">商品 ID</th>
						<th>商品名稱</th>
					</tr>
				</thead>
				<tbody>
					<?php if (!empty($activeProducts)): ?>
						<?php foreach ($activeProducts as $row): ?>
							<tr>
								<td>#<?php echo intval($row['promotion_id']); ?></td>
								<td><?php echo h($row['promotion_name']); ?></td>
								<td>#<?php echo intval($row['product_id']); ?></td>
								<td><?php echo h($row['product_name']); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php else: ?>
						<tr>
							<td colspan="4" style="text-align:center; padding:20px; color:#94a3b8;">目前沒有進行中的活動商品。</td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	</section>
</div>

<style>
.marketing-modal { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); display: none; align-items: center; justify-content: center; z-index: 999; backdrop-filter: blur(2px); }
.marketing-modal .modal-panel { background: #fff; border-radius: 12px; padding: 24px; width: min(760px, 95vw); max-height: 85vh; display: flex; flex-direction: column; box-shadow: 0 20px 40px rgba(0,0,0,0.1); }
.modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; }
.modal-title { font-size: 18px; font-weight: 700; color: #0f172a; }
.modal-body { overflow-y: auto; flex: 1; padding-right: 8px; }
.product-toolbar { display: flex; gap: 12px; align-items: center; justify-content: space-between; margin: 8px 0 10px; }
.product-search { flex: 1; display: flex; }
.product-search input { width: 100%; padding: 8px 10px; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc; color: #0f172a; }
.product-meta { display: flex; gap: 8px; font-size: 12px; }
.product-count { padding: 6px 10px; border-radius: 999px; background: #f1f5f9; color: #475569; font-weight: 600; }
.product-count.is-selected { background: #dbeafe; color: #1d4ed8; }
.product-list { max-height: 320px; overflow: auto; border: 1px solid #e2e8f0; border-radius: 10px; padding: 8px; background: #f8fafc; }
.product-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; }
.product-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 12px; display: flex; gap: 10px; align-items: center; transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease; }
.product-card:hover { border-color: #cbd5f5; box-shadow: 0 6px 14px rgba(15, 23, 42, 0.08); transform: translateY(-1px); }
.product-card input { width: 18px; height: 18px; accent-color: #2563eb; }
.product-card .product-info { display: flex; flex-direction: column; gap: 4px; }
.product-card .product-title { font-weight: 700; color: #0f172a; font-size: 14px; }
.product-card .product-sub { font-size: 12px; color: #64748b; }
.product-card.is-selected { border-color: #2563eb; box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.15); }
.product-empty { grid-column: 1 / -1; padding: 18px; text-align: center; color: #94a3b8; }
.banner-preview { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px; }
.banner-preview img { width: 160px; height: 60px; object-fit: cover; border-radius: 6px; border: 1px solid #e2e8f0; }
</style>

<div class="marketing-modal" id="promotionModal">
	<div class="modal-panel">
		<div class="modal-header">
			<div class="modal-title">新增行銷活動</div>
			<button type="button" class="pm-btn pm-btn-sub pm-btn-sm" id="closePromotionModal">✕ 關閉</button>
		</div>
		<form action="backend_action.php" method="POST" enctype="multipart/form-data" class="modal-body">
			<input type="hidden" name="action" value="add_promotion">
			<div class="pm-grid">
				<div class="pm-col-6" style="grid-column: span 6;">
					<label for="promotion_name">活動名稱</label>
					<input class="pm-input" type="text" id="promotion_name" name="name" required placeholder="例如：雙 11 全館折扣">
				</div>
				<div class="pm-col-6" style="grid-column: span 6;">
					<label for="promotion_discount_type">折扣類型</label>
					<select class="pm-select" id="promotion_discount_type" name="discount_type" required>
						<option value="PERCENT">百分比折扣</option>
						<option value="AMOUNT">折抵金額</option>
					</select>
				</div>
				<div class="pm-col-6" style="grid-column: span 6;">
					<label for="promotion_discount_value" id="promotion_discount_label">折扣數值</label>
					<input class="pm-input" type="number" step="0.01" id="promotion_discount_value" name="discount_value" required placeholder="例如：15 或 200">
				</div>
				<div class="pm-col-12">
					<label for="promotion_image">活動圖片（必填）</label>
					<input class="pm-file-input" type="file" id="promotion_image" name="promotion_image" accept="image/*" required>
					<div style="font-size:12px; color:#64748b; margin-top:6px;">活動圖片會作為活動主圖，即使不放首頁輪播也必須上傳。</div>
				</div>
				<div class="pm-col-6" style="grid-column: span 6;">
					<label for="promotion_active">啟用活動</label>
					<select class="pm-select" id="promotion_active" name="is_active">
						<option value="1">啟用</option>
						<option value="0">停用</option>
					</select>
				</div>
				<div class="pm-col-6" style="grid-column: span 6;">
					<label for="promotion_start_at">開始時間</label>
					<input class="pm-input" type="datetime-local" id="promotion_start_at" name="start_at" required>
				</div>
				<div class="pm-col-6" style="grid-column: span 6;">
					<label for="promotion_end_at">結束時間</label>
					<input class="pm-input" type="datetime-local" id="promotion_end_at" name="end_at" required>
				</div>
				<div class="pm-col-12">
					<label for="promotion_description">活動描述</label>
					<textarea class="pm-textarea" id="promotion_description" name="description" placeholder="簡單描述活動重點"></textarea>
				</div>
				<div class="pm-col-12">
					<label>選擇活動商品</label>
					<div class="product-toolbar">
						<div class="product-search">
							<input type="text" id="createProductSearch" placeholder="搜尋商品名稱或 ID">
						</div>
						<div class="product-meta">
							<span class="product-count" id="createAvailableCount">可選 0</span>
							<span class="product-count is-selected" id="createSelectedCount">已選 0</span>
						</div>
					</div>
					<div class="product-list">
						<div class="product-grid" id="createProductsList"></div>
					</div>
				</div>
				<div class="pm-col-12" style="display:flex; gap:10px; justify-content:flex-end;">
					<button class="pm-btn pm-btn-main" type="submit">建立活動</button>
				</div>
			</div>
		</form>
	</div>
</div>

<div class="marketing-modal" id="editPromotionModal">
	<div class="modal-panel">
		<div class="modal-header">
			<div class="modal-title" id="editPromotionTitle">編輯行銷活動</div>
			<button type="button" class="pm-btn pm-btn-sub pm-btn-sm" id="closeEditPromotionModal">✕ 關閉</button>
		</div>
		<form action="backend_action.php" method="POST" enctype="multipart/form-data" class="modal-body">
			<input type="hidden" name="action" value="update_promotion">
			<input type="hidden" name="promotion_id" id="edit_promotion_id" value="">
			<div class="pm-grid">
				<div class="pm-col-6" style="grid-column: span 6;">
					<label for="edit_promotion_name">活動名稱</label>
					<input class="pm-input" type="text" id="edit_promotion_name" name="name" required>
				</div>
				<div class="pm-col-6" style="grid-column: span 6;">
					<label for="edit_promotion_discount_type">折扣類型</label>
					<select class="pm-select" id="edit_promotion_discount_type" name="discount_type" required>
						<option value="PERCENT">百分比折扣</option>
						<option value="AMOUNT">折抵金額</option>
					</select>
				</div>
				<div class="pm-col-6" style="grid-column: span 6;">
					<label for="edit_promotion_discount_value" id="edit_promotion_discount_label">折扣數值</label>
					<input class="pm-input" type="number" step="0.01" id="edit_promotion_discount_value" name="discount_value" required>
				</div>
				<div class="pm-col-6" style="grid-column: span 6;">
					<label for="edit_promotion_active">啟用活動</label>
					<select class="pm-select" id="edit_promotion_active" name="is_active">
						<option value="1">啟用</option>
						<option value="0">停用</option>
					</select>
				</div>
				<div class="pm-col-6" style="grid-column: span 6;">
					<label for="edit_promotion_start_at">開始時間</label>
					<input class="pm-input" type="datetime-local" id="edit_promotion_start_at" name="start_at" required>
				</div>
				<div class="pm-col-6" style="grid-column: span 6;">
					<label for="edit_promotion_end_at">結束時間</label>
					<input class="pm-input" type="datetime-local" id="edit_promotion_end_at" name="end_at" required>
				</div>
				<div class="pm-col-12">
					<label for="edit_promotion_description">活動描述</label>
					<textarea class="pm-textarea" id="edit_promotion_description" name="description" placeholder="簡單描述活動重點"></textarea>
				</div>
				<div class="pm-col-12">
					<label for="edit_promotion_image">更新活動圖片（未選擇則沿用舊圖）</label>
					<input class="pm-file-input" type="file" id="edit_promotion_image" name="promotion_image" accept="image/*">
					<div id="editImagePreview" style="margin-top:10px;"></div>
				</div>
				<div class="pm-col-12">
					<label>選擇活動商品</label>
					<div class="product-toolbar">
						<div class="product-search">
							<input type="text" id="editProductSearch" placeholder="搜尋商品名稱或 ID">
						</div>
						<div class="product-meta">
							<span class="product-count" id="editAvailableCount">可選 0</span>
							<span class="product-count is-selected" id="editSelectedCount">已選 0</span>
						</div>
					</div>
					<div class="product-list">
						<div class="product-grid" id="editProductsList"></div>
					</div>
				</div>
				<div class="pm-col-12" style="display:flex; gap:10px; justify-content:flex-end;">
					<button class="pm-btn pm-btn-main" type="submit">儲存活動</button>
				</div>
			</div>
		</form>
	</div>
</div>

<div class="marketing-modal" id="bindProductsModal">
	<div class="modal-panel">
		<div class="modal-header">
			<div class="modal-title" id="bindProductsTitle">活動商品綁定</div>
			<button type="button" class="pm-btn pm-btn-sub pm-btn-sm" id="closeBindProductsModal">✕ 關閉</button>
		</div>
		<form action="backend_action.php" method="POST" class="modal-body" id="bindProductsForm">
			<input type="hidden" name="action" value="sync_promotion_products">
			<input type="hidden" name="promotion_id" id="bindPromotionId" value="">
			<div class="product-toolbar">
				<div class="product-search">
					<input type="text" id="bindProductSearch" placeholder="搜尋商品名稱或 ID">
				</div>
				<div class="product-meta">
					<span class="product-count" id="bindAvailableCount">可選 0</span>
					<span class="product-count is-selected" id="bindSelectedCount">已選 0</span>
				</div>
			</div>
			<div class="product-list">
				<div class="product-grid" id="bindProductsList"></div>
			</div>
			<div style="margin-top:16px; display:flex; gap:10px; justify-content:flex-end;">
				<button class="pm-btn pm-btn-main" type="submit">儲存商品綁定</button>
			</div>
		</form>
	</div>
</div>

<div class="marketing-modal" id="bannerModal">
	<div class="modal-panel">
		<div class="modal-header">
			<div class="modal-title" id="bannerTitle">Banner 版位設定</div>
			<button type="button" class="pm-btn pm-btn-sub pm-btn-sm" id="closeBannerModal">✕ 關閉</button>
		</div>
		<form action="backend_action.php" method="POST" enctype="multipart/form-data" class="modal-body">
			<input type="hidden" name="action" value="upload_promotion_banner">
			<input type="hidden" name="promotion_id" id="bannerPromotionId" value="">
			<div class="pm-grid">
				<div class="pm-col-12">
					<label for="banner_image">上傳 Banner 圖片</label>
					<input class="pm-file-input" type="file" id="banner_image" name="banner_image" accept="image/*" required>
				</div>
				<div class="pm-col-6" style="grid-column: span 6;">
					<label for="is_show_on_homepage">首頁顯示</label>
					<select class="pm-select" id="is_show_on_homepage" name="is_show_on_homepage">
						<option value="1">是</option>
						<option value="0">否</option>
					</select>
				</div>
				<div class="pm-col-6" style="grid-column: span 6;">
					<label for="sort_order">排序</label>
					<input class="pm-input" type="number" id="sort_order" name="sort_order" value="0">
				</div>
				<div class="pm-col-12">
					<div class="banner-preview" id="bannerPreview"></div>
				</div>
				<div class="pm-col-12" style="display:flex; gap:10px; justify-content:flex-end;">
					<button class="pm-btn pm-btn-main" type="submit">上傳 Banner</button>
				</div>
			</div>
		</form>
	</div>
</div>

<script>
const promotionProducts = <?php echo json_encode($promotionProducts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const allProducts = <?php echo json_encode($allProducts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const bannerMap = <?php echo json_encode($bannerMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const productUsage = <?php echo json_encode($productUsage, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

const promotionModal = document.getElementById('promotionModal');
const editPromotionModal = document.getElementById('editPromotionModal');
const bindProductsModal = document.getElementById('bindProductsModal');
const bannerModal = document.getElementById('bannerModal');

const createProductsList = document.getElementById('createProductsList');
const editProductsList = document.getElementById('editProductsList');
const bindProductsList = document.getElementById('bindProductsList');

const createProductSearch = document.getElementById('createProductSearch');
const editProductSearch = document.getElementById('editProductSearch');
const bindProductSearch = document.getElementById('bindProductSearch');

const createSelectedCount = document.getElementById('createSelectedCount');
const editSelectedCount = document.getElementById('editSelectedCount');
const bindSelectedCount = document.getElementById('bindSelectedCount');

const createAvailableCount = document.getElementById('createAvailableCount');
const editAvailableCount = document.getElementById('editAvailableCount');
const bindAvailableCount = document.getElementById('bindAvailableCount');

const productToPromotions = {};
Object.keys(productUsage || {}).forEach(productId => {
	productToPromotions[productId] = (productUsage[productId] || []).map(Number);
});

document.getElementById('openCreatePromotion').addEventListener('click', () => {
	renderCreateProducts(new Set());
	promotionModal.style.display = 'flex';
});

document.getElementById('closePromotionModal').addEventListener('click', () => {
	promotionModal.style.display = 'none';
});

document.getElementById('closeEditPromotionModal').addEventListener('click', () => {
	editPromotionModal.style.display = 'none';
});

document.getElementById('closeBindProductsModal').addEventListener('click', () => {
	bindProductsModal.style.display = 'none';
});

document.getElementById('closeBannerModal').addEventListener('click', () => {
	bannerModal.style.display = 'none';
});

document.querySelectorAll('.js-bind-products').forEach(btn => {
	btn.addEventListener('click', () => {
		const promotionId = btn.dataset.id;
		const promotionName = btn.dataset.name || '';
		document.getElementById('bindPromotionId').value = promotionId;
		document.getElementById('bindProductsTitle').textContent = '活動商品綁定 - ' + promotionName;
		renderBindProducts(promotionId, new Set(promotionProducts[promotionId] || []));
		bindProductsModal.style.display = 'flex';
	});
});

document.querySelectorAll('.js-upload-banner').forEach(btn => {
	btn.addEventListener('click', () => {
		const promotionId = btn.dataset.id;
		const promotionName = btn.dataset.name || '';
		document.getElementById('bannerPromotionId').value = promotionId;
		document.getElementById('bannerTitle').textContent = 'Banner 版位設定 - ' + promotionName;
		renderBannerPreview(promotionId);
		bannerModal.style.display = 'flex';
	});
});

document.querySelectorAll('.js-edit-promotion').forEach(btn => {
	btn.addEventListener('click', () => {
		const promotionId = btn.dataset.id;
		const promotionName = btn.dataset.name || '';
		document.getElementById('editPromotionTitle').textContent = '編輯行銷活動 - ' + promotionName;
		document.getElementById('edit_promotion_id').value = promotionId;
		document.getElementById('edit_promotion_name').value = promotionName;
		document.getElementById('edit_promotion_description').value = btn.dataset.description || '';
		document.getElementById('edit_promotion_discount_type').value = btn.dataset.discountType || 'PERCENT';
		document.getElementById('edit_promotion_discount_value').value = btn.dataset.discountValue || '';
		document.getElementById('edit_promotion_start_at').value = btn.dataset.startAt || '';
		document.getElementById('edit_promotion_end_at').value = btn.dataset.endAt || '';
		document.getElementById('edit_promotion_active').value = btn.dataset.isActive || '1';

		const selected = new Set(promotionProducts[promotionId] || []);
		renderEditProducts(promotionId, selected);
		updateDiscountMeta(document.getElementById('edit_promotion_discount_type'), document.getElementById('edit_promotion_discount_value'), document.getElementById('edit_promotion_discount_label'));

		const preview = document.getElementById('editImagePreview');
		preview.innerHTML = '';
		if (btn.dataset.imageUrl) {
			const img = document.createElement('img');
			img.src = '../' + btn.dataset.imageUrl;
			img.alt = '活動圖片';
			img.style.width = '160px';
			img.style.height = '90px';
			img.style.objectFit = 'cover';
			img.style.borderRadius = '8px';
			img.style.border = '1px solid #e2e8f0';
			preview.appendChild(img);
		}

		editPromotionModal.style.display = 'flex';
	});
});

if (createProductSearch) {
	createProductSearch.addEventListener('input', () => {
		renderCreateProducts(getSelectedIds(createProductsList));
	});
}

if (editProductSearch) {
	editProductSearch.addEventListener('input', () => {
		const promotionId = editProductsList ? editProductsList.dataset.promotionId : '';
		renderEditProducts(promotionId, getSelectedIds(editProductsList));
	});
}

if (bindProductSearch) {
	bindProductSearch.addEventListener('input', () => {
		const promotionId = bindProductsList ? bindProductsList.dataset.promotionId : '';
		renderBindProducts(promotionId, getSelectedIds(bindProductsList));
	});
}

function getAvailableProductSet(promotionId) {
	const available = new Set();
	const targetId = promotionId ? parseInt(promotionId, 10) : null;

	allProducts.forEach(product => {
		const pid = parseInt(product.product_id, 10);
		const promos = productToPromotions[pid] || [];
		if (promos.length === 0 || (targetId !== null && promos.includes(targetId))) {
			available.add(pid);
		}
	});

	return available;
}

function getSelectedIds(container) {
	if (!container) {
		return new Set();
	}
	const selected = new Set();
	container.querySelectorAll('input[type="checkbox"]:checked').forEach(cb => {
		selected.add(parseInt(cb.value, 10));
	});
	return selected;
}

function updateSelectedCount(container, selectedCountEl) {
	if (!selectedCountEl || !container) {
		return;
	}
	const count = container.querySelectorAll('input[type="checkbox"]:checked').length;
	selectedCountEl.textContent = '已選 ' + count;
}

function renderCreateProducts(selected) {
	const availableSet = getAvailableProductSet('');
	renderProductCheckboxesTo(createProductsList, selected, {
		availableSet,
		query: createProductSearch ? createProductSearch.value : '',
		selectedCountEl: createSelectedCount,
		availableCountEl: createAvailableCount,
	});
}

function renderEditProducts(promotionId, selected) {
	if (editProductsList) {
		editProductsList.dataset.promotionId = promotionId || '';
	}
	const availableSet = getAvailableProductSet(promotionId);
	renderProductCheckboxesTo(editProductsList, selected, {
		availableSet,
		query: editProductSearch ? editProductSearch.value : '',
		selectedCountEl: editSelectedCount,
		availableCountEl: editAvailableCount,
	});
}

function renderBindProducts(promotionId, selected) {
	if (bindProductsList) {
		bindProductsList.dataset.promotionId = promotionId || '';
	}
	const availableSet = getAvailableProductSet(promotionId);
	renderProductCheckboxesTo(bindProductsList, selected, {
		availableSet,
		query: bindProductSearch ? bindProductSearch.value : '',
		selectedCountEl: bindSelectedCount,
		availableCountEl: bindAvailableCount,
	});
}

function renderProductCheckboxesTo(container, selected, options = {}) {
	if (!container) {
		return;
	}
	container.innerHTML = '';

	const availableSet = options.availableSet || null;
	const query = (options.query || '').trim().toLowerCase();
	if (options.availableCountEl) {
		const availableCount = availableSet ? availableSet.size : allProducts.length;
		options.availableCountEl.textContent = '可選 ' + availableCount;
	}

	if (allProducts.length === 0) {
		container.innerHTML = '<div class="product-empty">目前沒有商品可綁定。</div>';
		updateSelectedCount(container, options.selectedCountEl);
		return;
	}

	let matched = 0;
	allProducts.forEach(product => {
		const pid = parseInt(product.product_id, 10);
		if (availableSet && !availableSet.has(pid)) {
			return;
		}
		const name = product.name || '';
		const haystack = (pid + ' ' + name).toLowerCase();
		if (query !== '' && !haystack.includes(query)) {
			return;
		}
		matched += 1;

		const wrapper = document.createElement('label');
		wrapper.className = 'product-card';
		const checkbox = document.createElement('input');
		checkbox.type = 'checkbox';
		checkbox.name = 'product_ids[]';
		checkbox.value = product.product_id;
		checkbox.checked = selected.has(pid);

		const info = document.createElement('div');
		info.className = 'product-info';
		info.innerHTML = '<div class="product-title">#' + product.product_id + ' ' + name + '</div>' +
			'<div class="product-sub">ID ' + product.product_id + '</div>';

		if (checkbox.checked) {
			wrapper.classList.add('is-selected');
		}

		checkbox.addEventListener('change', () => {
			wrapper.classList.toggle('is-selected', checkbox.checked);
			updateSelectedCount(container, options.selectedCountEl);
		});

		wrapper.appendChild(checkbox);
		wrapper.appendChild(info);
		container.appendChild(wrapper);
	});

	if (matched === 0) {
		container.innerHTML = '<div class="product-empty">沒有符合條件的商品。</div>';
	}

	updateSelectedCount(container, options.selectedCountEl);
}

function renderProductCheckboxes(promotionId) {
	renderBindProducts(promotionId, new Set(promotionProducts[promotionId] || []));
}

function updateDiscountMeta(selectEl, inputEl, labelEl) {
	if (!selectEl || !inputEl || !labelEl) {
		return;
	}
	if (selectEl.value === 'PERCENT') {
		labelEl.textContent = '折扣數值（百分比%）';
		inputEl.placeholder = '例如：15';
		inputEl.step = '0.01';
		return;
	}
	labelEl.textContent = '折扣數值（折抵金額 NT$）';
	inputEl.placeholder = '例如：200';
	inputEl.step = '1';
}

function renderBannerPreview(promotionId) {
	const preview = document.getElementById('bannerPreview');
	preview.innerHTML = '';
	const banners = bannerMap[promotionId] || [];
	if (banners.length === 0) {
		preview.innerHTML = '<div style="color:#94a3b8;">目前尚未設定 Banner。</div>';
		return;
	}
	banners.forEach(url => {
		const img = document.createElement('img');
		img.src = '../' + url;
		img.alt = 'Banner';
		preview.appendChild(img);
	});
}

window.addEventListener('click', (event) => {
	if (event.target === promotionModal) promotionModal.style.display = 'none';
	if (event.target === editPromotionModal) editPromotionModal.style.display = 'none';
	if (event.target === bindProductsModal) bindProductsModal.style.display = 'none';
	if (event.target === bannerModal) bannerModal.style.display = 'none';
});

const promotionDiscountType = document.getElementById('promotion_discount_type');
const promotionDiscountValue = document.getElementById('promotion_discount_value');
const promotionDiscountLabel = document.getElementById('promotion_discount_label');
if (promotionDiscountType && promotionDiscountValue && promotionDiscountLabel) {
	updateDiscountMeta(promotionDiscountType, promotionDiscountValue, promotionDiscountLabel);
	promotionDiscountType.addEventListener('change', () => {
		updateDiscountMeta(promotionDiscountType, promotionDiscountValue, promotionDiscountLabel);
	});
}

const editDiscountType = document.getElementById('edit_promotion_discount_type');
const editDiscountValue = document.getElementById('edit_promotion_discount_value');
const editDiscountLabel = document.getElementById('edit_promotion_discount_label');
if (editDiscountType && editDiscountValue && editDiscountLabel) {
	editDiscountType.addEventListener('change', () => {
		updateDiscountMeta(editDiscountType, editDiscountValue, editDiscountLabel);
	});
}
</script>
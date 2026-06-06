# CURRENT_HANDOFF

## 1. Purpose

這份 handoff 是給下一個接手此專案的 Codex 對話閱讀，用來延續 All Pass 行李箱電商期末專案的開發與測試工作。
目前專案目標不是單點修 bug，而是把系統補到接近期末滿分、並盡量符合可上架電商的完整度。
前一輪已針對 merge 後的安全、購物、訂單、優惠券、評論、退貨與資料庫同步做過一批修正。
本文件只保留會影響後續判斷的接力資訊，不包含完整聊天流水帳。
下一個對話應先閱讀本文件、回報理解，再依使用者確認的目標開始工作。
遇到 `uncertain` 標記的內容時，不應自行猜測為既定需求，應先詢問使用者或查檔案確認。
使用者偏好繁體中文、具體測試流程、可直接拿去 commit 的訊息，以及有證據的判斷。

## 2. Current Main Goal

* confirmed: 建立一個完整的行李箱電商期末專案，功能要接近已上架電商，尤其要注意邊界測試與刁鑽測資。
* confirmed: 目前採用會員分級價方案 B，只有 VIP/VVIP 類型會員享會員價，一般註冊會員仍看原價或特價。
* confirmed: 目前優先補齊購物、會員、安全、優惠券、訂單、退貨、評論、搜尋、後台流程。
* confirmed: 使用者需要可手測的流程設計與可 commit 的描述訊息。
* confirmed: `docs/CURRENT_HANDOFF.md` 是給使用者本機接力使用，不應被推上 git。
* uncertain: 是否要在下一輪立刻修改程式碼，把 `orders.coupon_id` 的 migration 補進 `db_setup_and_sync.php`。本輪因使用者限制「不要修改任何程式碼」，只做了本機資料庫欄位修復。
* uncertain: 後台自動登出是否只由前台登出共用 session 造成，還是也有 PHP session lifetime 或瀏覽器 cookie 設定問題。

## 3. User Preferences and Working Style

* 回答語言: confirmed，使用繁體中文。
* 詳略程度: confirmed，使用者需要完整測試步驟、清楚排序、能直接照做。
* 品質要求: confirmed，希望功能接近可上架電商，不只展示表面，要注意邊界測試、庫存、權限、安全、流程閉環。
* 工作流程: confirmed，偏好先討論/規劃，再按推薦順序實作，最後給測試流程與 commit 訊息。
* 輸出偏好: confirmed，不喜歡普通聊天摘要；需要 handoff context、有 confirmed/uncertain、有檔案證據。
* Git 偏好: confirmed，使用者會用 Fork app 推測試分支，常需要 commit message。
* 文件偏好: confirmed，handoff 文件應為本機接力文件，不要推上 git。
* 風險偏好: confirmed，對安全與資料一致性要求高，不希望硬刪訂單資料，偏好取消/封存。

## 4. Key Decisions Made

* decision: 價格規則採會員分級價方案 B。
  reason: 一般註冊會員是下單基本門檻，不應全部會員都自動享會員價；會員價應作為 VIP/VVIP 權益。
  status: confirmed
  evidence: 對話中使用者明確表示「我選擇做方案 B：會員分級價」；相關程式參考 `homepage/includes/price_helper.php`。

* decision: 供應請求 PARTIAL 狀態矛盾已列為早期修正項。
  reason: 廠商供應流程若狀態互相矛盾，會影響庫存與後台驗收。
  status: confirmed
  evidence: 對話中已將「修廠商供應請求的 PARTIAL 狀態矛盾」列為前 3 個優先實作步驟；相關檔案參考 `backend/actions/CompleteSupplierSupply.php`、`backend/actions/SubmitSupplierSupply.php`、`backend/supplier_supplies.php`。

* decision: 訂單不硬刪，改為取消/封存並視情況回補庫存。
  reason: 硬刪會破壞報表、付款紀錄、庫存追蹤與稽核。
  status: confirmed
  evidence: 對話中已明確採用取消/封存方向；目前 action 參考 `backend/actions/DeleteOrder.php`。

* decision: 忘記密碼改為一次性 token 流程，期末展示可顯示測試連結。
  reason: email + phone 直接重設不符合可上架標準。
  status: confirmed
  evidence: `homepage/forgot_password.php`、`db_setup_and_sync.php` 中的 `password_reset_tokens`。

* decision: 收藏 AJAX 與後台訂單表單要有 CSRF。
  reason: 收藏會改資料，後台訂單狀態也會改資料，需防跨站請求偽造。
  status: confirmed
  evidence: `homepage/product_detail.php`、`js/product_detail.js`、`backend/orders.php`、`backend/backend_action.php`。

* decision: 結帳先做模擬付款紀錄，不串真實金流。
  reason: 期末專案展示需要流程完整，但不需要真實支付服務。
  status: confirmed
  evidence: `homepage/checkout.php`、`db_setup_and_sync.php` 中的 `payment_transactions`。

* decision: 商品評論限制為已送達或已完成訂單會員才可評論。
  reason: 避免未購買者刷評論，接近上架電商常見規則。
  status: confirmed
  evidence: `homepage/product_detail.php`、`db_setup_and_sync.php` 中的 `product_reviews`。

* decision: 退貨申請需由會員端送出，後台可審核狀態。
  reason: 補齊上架電商的售後流程。
  status: confirmed
  evidence: `homepage/order_detail.php`、`backend/orders.php`、`backend/actions/UpdateReturnRequest.php`、`db_setup_and_sync.php` 中的 `return_requests`。

* decision: `docs/CURRENT_HANDOFF.md` 只作本機接力用途，不應推上 git。
  reason: 使用者明確要求「不要推上git只有我使用」。
  status: confirmed
  evidence: 本次使用者要求。

## 5. Important Context

* confirmed: 目前工作區位於 `C:\Users\ianfu\Desktop\Suitcase`。
* confirmed: 專案是 PHP + MySQL + XAMPP 類型，資料庫名為 `all_pass_db`。
* confirmed: PHP CLI 可用路徑為 `C:\xampp\php\php.exe`，單純 `php` 不一定在 PATH。
* confirmed: 前一輪全專案 PHP lint 通過，當時結果為 68 個 PHP 檔無語法錯誤。
* confirmed: 前一輪已執行 `C:\xampp\php\php.exe db_setup_and_sync.php` 並成功建立多個新表。
* confirmed: 本輪結帳錯誤 `Unknown column 'coupon_id' in 'field list'` 已確認是本機 `orders` 表缺 `coupon_id`。
* confirmed: 本輪已用非破壞性 SQL 在本機資料庫新增 `orders.coupon_id` 與索引 `idx_orders_coupon_id`。
* uncertain: 因本輪使用者限制不要修改程式碼，`db_setup_and_sync.php` 尚未加入 `orders.coupon_id` 的 migration；其他環境重新同步時可能仍缺欄位。
* confirmed: 後台登入保護依賴 `backend/auth_guard.php`，登入檔為 `backend/admin_login.php`。
* uncertain: 後台看似自動登出，可能原因是前台 `homepage/logout.php` 與後台 `backend/admin_logout.php` 都使用同一個 PHP session 並 `session_destroy()`，同瀏覽器同 session 下前台登出可能連後台一起清掉。
* confirmed: 使用者不希望這份 handoff 被推上 git；目前文件位於 repo 內，若使用者 commit 時需避免 staging 此檔。

## 6. Current State

### Done

* confirmed: `database.php` 已恢復後台登入保護與安全錯誤處理。
* confirmed: 直接下單流程已改為導向購物車並帶 `buy_now_item`，讓該商品預設勾選。
* confirmed: 收藏 AJAX 已補 CSRF token。
* confirmed: 購物車更新數量已補伺服器端庫存檢查。
* confirmed: 會員登入已檢查 `users.status = ACTIVE`，並加入登入嘗試限制。
* confirmed: 忘記密碼已改為一次性 token、期限、使用後失效。
* confirmed: 後台訂單刪除已改為取消訂單/回補庫存方向。
* confirmed: 結帳成功會建立模擬付款交易紀錄。
* confirmed: Header 搜尋框已接 `homepage/search.php`。
* confirmed: 商品評論與評論顯示已接到商品詳情頁。
* confirmed: 會員端退貨申請與後台退貨審核已接上。
* confirmed: 新增資料表包括 `password_reset_tokens`、`security_attempts`、`payment_transactions`、`return_requests`、`product_reviews`、`admin_audit_logs`。
* confirmed: 本機資料庫已補 `orders.coupon_id` 與 `idx_orders_coupon_id`。
* confirmed: 本文件 `docs/CURRENT_HANDOFF.md` 已建立。

### In Progress

* confirmed: 使用者正在進行結帳、後台、會員、安全等手測。
* confirmed: 使用者正在準備 commit 到測試分支。
* uncertain: 後台自動登出問題尚未完整重現，只完成可能原因初步分析。

### Not Started

* confirmed: 低庫存後台清單尚未完整做成營運頁。
* confirmed: 點數來源明細頁尚未完整補齊。
* confirmed: 優惠券使用紀錄完整報表尚未完成。
* confirmed: 管理員審計 log 後台查詢頁尚未完成。
* uncertain: 是否要建立完整 E2E 自動化測試腳本尚未確認。

### Blocked / Unknown

* uncertain: 內建 Browser runtime 曾被本機 sandbox 中斷，無法完成截圖級視覺驗證。
* uncertain: 後台自動登出是否只與共用 session 有關，需使用者描述重現步驟或允許後續修改 session 設計。
* uncertain: `docs/CURRENT_HANDOFF.md` 位於 repo 內但不應推上 git；目前未修改 `.git/info/exclude` 或 `.gitignore`。

## 7. Important Files and Paths

* path: `database.php`
  purpose: 後台/資料庫檢視入口。
  why it matters: 曾被 merge 改成公開顯示錯誤並移除登入保護，已列為 P0 安全風險。
  status: confirmed

* path: `homepage/checkout.php`
  purpose: 結帳、建立訂單、套用優惠券、扣庫存、付款紀錄。
  why it matters: 使用 `orders.coupon_id`、`orders.discount_amount`、`payment_transactions`，本輪結帳錯誤源於資料庫缺 `coupon_id`。
  status: confirmed

* path: `db_setup_and_sync.php`
  purpose: 初始化與同步資料庫 schema。
  why it matters: 已建立多個新表，但本輪尚未修改程式碼補 `orders.coupon_id` migration。
  status: confirmed / uncertain

* path: `homepage/cart.php`
  purpose: 購物車、直接下單預設勾選、更新數量。
  why it matters: 伺服器端庫存檢查與 `buy_now_item` 流程都會影響結帳。
  status: confirmed

* path: `homepage/product_detail.php`
  purpose: 商品詳情、加入購物車、直接下單、收藏、評論。
  why it matters: 涉及價格顯示、CSRF、評論資格、使用者購物入口。
  status: confirmed

* path: `js/product_detail.js`
  purpose: 商品詳情頁前端互動與收藏 AJAX。
  why it matters: 已補 `csrf_token` 傳送。
  status: confirmed

* path: `homepage/forgot_password.php`
  purpose: 忘記密碼與重設密碼流程。
  why it matters: 已改為 token 模式，展示模式會顯示測試連結。
  status: confirmed

* path: `homepage/login.php`
  purpose: 會員登入。
  why it matters: 已加入會員狀態檢查與登入嘗試限制。
  status: confirmed

* path: `backend/orders.php`
  purpose: 後台訂單列表、訂單詳情、狀態更新、退貨審核、取消訂單表單。
  why it matters: 會用 `orders.coupon_id`，也含 CSRF 表單與退貨審核入口。
  status: confirmed

* path: `backend/backend_action.php`
  purpose: 後台 POST action 路由與 CSRF 驗證。
  why it matters: 新增/接入 `delete_order`、`update_return_request` 等 action。
  status: confirmed

* path: `backend/actions/DeleteOrder.php`
  purpose: 訂單取消與庫存回補。
  why it matters: 取代硬刪訂單。
  status: confirmed

* path: `backend/actions/UpdateReturnRequest.php`
  purpose: 後台退貨審核、退款狀態與退款交易紀錄。
  why it matters: 補齊售後流程。
  status: confirmed

* path: `homepage/order_detail.php`
  purpose: 會員訂單詳情與退貨申請。
  why it matters: 只有已送達/已完成訂單可申請退貨。
  status: confirmed

* path: `homepage/search.php`
  purpose: 商品搜尋與篩選結果頁。
  why it matters: Header 搜尋框已導向此頁。
  status: confirmed

* path: `homepage/logout.php`
  purpose: 前台會員登出。
  why it matters: 使用 `session_destroy()`，可能在同瀏覽器共用 session 時清掉後台登入。
  status: confirmed / uncertain

* path: `backend/admin_logout.php`
  purpose: 後台管理員登出。
  why it matters: 同樣使用 `session_destroy()`。
  status: confirmed

* path: `backend/auth_guard.php`
  purpose: 後台登入保護。
  why it matters: 若 `$_SESSION['admin_id']` 不存在會導回 `admin_login.php`。
  status: confirmed

* path: `docs/CURRENT_HANDOFF.md`
  purpose: 本機接力文件。
  why it matters: 使用者要求不要推上 git。
  status: confirmed

## 8. Reusable Workflows

* when to use: 修改 PHP 後做語法檢查。
  input: 修改後的 PHP 檔案或全專案。
  steps: 使用 `C:\xampp\php\php.exe -l path\to\file.php`；全專案可遞迴跑所有 `*.php`。
  output: `No syntax errors detected` 或錯誤位置。
  quality checklist: 確認使用 XAMPP PHP 路徑；不要假設 `php` 已在 PATH；全專案檢查要排除 `.git`。

* when to use: 修改資料庫 schema 後確認欄位或表是否存在。
  input: 資料庫名、資料表、欄位或索引。
  steps: 用 `C:\xampp\php\php.exe` 執行簡短 mysqli 查詢，例如 `SHOW COLUMNS FROM orders LIKE 'coupon_id'`。
  output: 欄位/索引存在或缺失。
  quality checklist: 查詢前確認 MySQL 已啟動；避免破壞性 SQL；必要時先備份。

* when to use: 修結帳或訂單流程後。
  input: 登入會員、購物車商品、可用庫存、可用優惠券。
  steps: 商品詳情加入購物車或直接下單；購物車勾選；進結帳；套優惠券；送出訂單；查訂單與庫存；查 `payment_transactions`。
  output: 訂單建立、庫存扣除、付款紀錄建立、優惠券數量/使用紀錄正確。
  quality checklist: 測超庫存、未勾選商品、無優惠券、有優惠券、庫存不足 rollback、重複送出。

* when to use: 測會員安全。
  input: ACTIVE、SUSPENDED、INACTIVE 會員帳號。
  steps: 分別登入；測忘記密碼；測 token 過期/重複使用；測多次失敗。
  output: ACTIVE 可登入，停用/停權不可登入，reset token 一次性生效。
  quality checklist: 不洩漏帳號是否存在；失敗次數限制應生效；token 不應可重複使用。

* when to use: 測後台登入/登出問題。
  input: 同一瀏覽器前台會員 session、後台管理員 session。
  steps: 同瀏覽器登入前台與後台；前台登出；刷新後台；再用不同瀏覽器或無痕視窗分開測。
  output: 判斷是否因共用 PHP session 導致後台被清掉。
  quality checklist: 記錄重現步驟、瀏覽器、是否同網域同 session cookie。

* when to use: 做接力文件。
  input: 對話中已確認的決策、已修改檔案、未確定事項。
  steps: 分 confirmed/uncertain；只保留會影響後續判斷的資訊；每個重要判斷附檔案證據。
  output: `docs/CURRENT_HANDOFF.md`。
  quality checklist: 不寫流水帳；不編造；目前狀態和下一步分開；避免把本機私有文件加入 commit。

## 9. Failure Patterns / Things to Avoid

* 不要把 handoff 寫成普通聊天摘要。
* 不要沒有證據就下結論，尤其是安全、庫存、金流、session 問題。
* 不要把本機 DB 已修復誤判為所有環境永久修復；若程式同步腳本未改，其他人仍可能缺欄位。
* 不要硬刪訂單資料，應優先取消/封存並維持報表與庫存紀錄。
* 不要忽略 CSRF，尤其是收藏、訂單、退貨、會員、優惠券等會改資料的操作。
* 不要只做前端限制；庫存、評論資格、會員狀態必須伺服器端驗證。
* 不要把草稿或本機接力文件誤加入正式 commit。
* 不要在使用者明確限制「不要修改程式碼」時改程式碼。
* 不要把前台/後台共用 session 的風險當成使用者錯覺，需用重現流程確認。
* 不要在未確認前串真實金流或寄信服務。

## 10. Next Recommended Actions

* priority: P0
  action: 讓使用者重新測一次結帳。
  why: 本輪已在本機 DB 補 `orders.coupon_id`，需確認原錯誤消失並能建立訂單。
  expected output: 成功建立訂單，或取得新的錯誤訊息截圖/文字。

* priority: P0
  action: 下一輪若允許修改程式碼，將 `orders.coupon_id` migration 補進 `db_setup_and_sync.php`。
  why: 目前只修了本機資料庫，其他環境或重建資料庫仍可能缺欄位。
  expected output: 同步腳本可自動補 `orders.coupon_id` 與索引。

* priority: P1
  action: 重現後台自動登出問題。
  why: 初步判斷可能是前台/後台共用 PHP session，需確認使用者實際操作路徑。
  expected output: 明確重現條件，並決定是否改為前後台分離 session key 或避免前台登出清空 admin session。

* priority: P1
  action: 跑完整手測流程：登入、搜尋、直接下單、購物車、結帳、付款紀錄、評論、退貨、後台審核。
  why: 前一輪功能補很多，需確認整體流程沒有新斷點。
  expected output: 測試通過清單與 bug list。

* priority: P1
  action: 檢查優惠券使用紀錄與限量券扣抵流程。
  why: 結帳與 `coupon_id` 修復直接影響優惠券流程。
  expected output: 確認公開領券、優惠碼、限量券、VIP 券、結帳使用都正確。

* priority: P2
  action: 補後台營運報表：低庫存、點數紀錄、優惠券使用紀錄、管理員審計 log。
  why: 這些是接近上架電商與期末加分的營運能力。
  expected output: 後台營運管理頁或整合區塊。

* priority: P2
  action: 建立展示用測試資料與展示腳本。
  why: 期末專案需要穩定 demo，避免現場臨時造資料。
  expected output: demo 帳號、商品、優惠券、訂單、退貨、評論資料與展示順序。

## 11. Open Questions for the User

* 後台自動登出是在你前台會員登出後發生，還是沒有任何登出操作也會發生？需要確認是否為共用 session 問題。
* 你是同一個瀏覽器同時開前台與後台，還是分不同瀏覽器/無痕？需要確認 cookie/session 範圍。
* 是否允許下一輪修改 `db_setup_and_sync.php`，把 `orders.coupon_id` migration 永久補進同步腳本？目前本輪因限制沒有改程式碼。
* `docs/CURRENT_HANDOFF.md` 要不要加入本機 git exclude？需要確認是否允許修改 `.git/info/exclude` 這種本機 git 設定。
* 接下來要優先修後台登出，還是先跑完整手測清單？兩者都重要，但優先順序會影響工作安排。
* 是否要把目前修改正式 commit，還是先再修一輪測試 bug？需要確認分支策略。
* 是否需要產出期末展示腳本與測試資料 SQL？這能提高 demo 穩定性，但會多一份輔助文件或資料腳本。

## 12. Prompt for Next Conversation

請先閱讀 `docs/CURRENT_HANDOFF.md`，這是一份 All Pass 電商期末專案的接力 handoff context。
閱讀後請先用繁體中文回報你理解到的目前狀態、已確認目標、uncertain 事項與你建議的下一步，不要立刻修改程式碼。
請先確認我的當前目標後再開始工作。
如果遇到 handoff 裡標記為 `uncertain` 的內容，請先問我或查專案檔案，不要自行猜測成事實。
接下來工作時，請保持「先確認現況、再規劃、再實作、最後驗證與回報」的流程，並附上檔案路徑作為證據。

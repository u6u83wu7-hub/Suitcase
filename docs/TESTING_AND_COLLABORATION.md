# All Pass 測試與協作說明

## 本機準備

1. 開啟 XAMPP 的 Apache 與 MySQL。
2. 建議用 Apache Alias 讓 `http://localhost/Suitcase` 直接指向 `C:\Users\ianfu\Desktop\Suitcase`。
3. 若已設定 Alias，之後只修改桌面專案，不要再改 `C:\xampp\htdocs\Suitcase` 的舊副本。
4. 執行 `http://localhost/Suitcase/db_setup_and_sync.php`，確保資料表與欄位同步完成。
5. 使用管理員帳號登入後台，再開啟 `database.php` 查看資料庫；未登入管理員會被導回後台登入頁。

## 快速網址

- 前台首頁：`http://localhost/Suitcase/homepage/index.php`
- 會員註冊：`http://localhost/Suitcase/homepage/register.php`
- 會員登入：`http://localhost/Suitcase/homepage/login.php`
- 購物車：`http://localhost/Suitcase/homepage/cart.php`
- 後台登入：`http://localhost/Suitcase/backend/admin_login.php`
- 後台訂單：`http://localhost/Suitcase/backend/backend.php?page=orders`
- 後台商品：`http://localhost/Suitcase/backend/backend.php?page=products`
- 資料庫檢視：`http://localhost/Suitcase/database.php`

## 主要測試流程

### 1. 會員與安全

1. 到 `homepage/register.php` 註冊新會員。
2. 嘗試使用已註冊 email 重複註冊，應顯示信箱已存在，不應出現 Fatal error 或 HTTP 500。
3. 登入會員，確認可進入會員中心。
4. 未登入管理員時開啟 `database.php`，應被導回 `backend/admin_login.php`。

### 2. 商品與購物車

1. 後台確認至少一個上架商品，且至少一個 SKU 有庫存。
2. 前台進入商品詳情頁，選擇規格並加入購物車。
3. 嘗試加入超過 SKU 庫存的數量，應被阻擋並顯示庫存不足。
4. 到購物車修改數量，若數量超過庫存，畫面應顯示警告。

### 2.1 後台商品庫存

1. 進入 `backend.php?page=products`。
2. 商品列表的總庫存欄應顯示售罄、低庫存或售罄 SKU 提示。
3. 使用庫存狀態篩選：
   - `低庫存 SKU` 應只顯示至少一個 SKU 庫存為 1 到 5 的商品。
   - `售罄商品` 應只顯示沒有任何可售庫存的商品。
4. 點擊商品進入編輯頁，SKU 區塊應顯示庫存摘要。
5. 修改 SKU 庫存數字時，提示應即時變成售罄、低庫存或庫存正常。
6. 儲存後回到列表，總庫存與庫存狀態提示應同步更新。
7. 本輪新增 `inventory_adjustment_logs` 資料表；測試庫存異動紀錄前，請先執行 `db_setup_and_sync.php`。
8. 商品編輯頁的「近期庫存異動」應顯示 SKU、舊庫存、新庫存、異動量、操作類型、管理員與時間。
9. 新增 SKU、修改 SKU 庫存、移除 SKU 時，應各自留下紀錄。

### 3. 結帳與庫存

1. 記錄商品 SKU 原始庫存。
2. 從購物車勾選商品進入結帳。
3. 結帳頁若數量超過庫存，送出按鈕應停用。
4. 填寫收件與信用卡資料後送出訂單。
5. 訂單成立後，確認：
   - `orders` 新增訂單。
   - `order_items` 有商品快照。
   - 對應 SKU 的 `stock_available` 已扣除。
   - `orders.inventory_deducted` 應為 `1`。
6. 快速連點或重新整理送出頁時，不應產生庫存負數或超賣。

### 4. 後台訂單管理

1. 進入 `backend.php?page=orders`。
2. 點選訂單詳情，確認狀態流程顯示目前狀態與允許下一步。
3. 從待處理改為處理中，應成功。
4. 從處理中改為已出貨，應可儲存物流公司與追蹤單號。
5. 從已出貨改為已送達，再改為已完成，應成功。
6. 嘗試跳過流程，例如待處理直接改為已完成，應被後端阻擋。
7. 將待處理或處理中訂單改為已取消，應補回庫存，`inventory_deducted` 應變為 `0`。
8. 從已取消恢復處理時，應重新檢查並扣庫存；若庫存不足，應阻擋恢復。
9. 已完成訂單是流程終點，只能更新物流與內部備註，不應再改狀態。

### 5. 批次訂單操作

1. 勾選多筆訂單並套用批次狀態。
2. 系統會逐筆檢查狀態流程；若其中一筆不合法，整批更新應被阻擋。
3. 不要混選不同流程階段的訂單做批次出貨或完成，除非你確定每筆都符合下一步。

### 6. 活動與首頁 Banner

1. 後台建立活動並綁定商品。
2. 上傳首頁跑馬燈 Banner。
3. 前台首頁應顯示活動跑馬燈。
4. 刪除 Banner 後，首頁不應再顯示該 Banner。

### 7. 相似商品推薦

1. 進入任一商品詳情頁，例如 `homepage/product_detail.php?id=商品ID`。
2. 若該商品有分類，右側資訊區應顯示「相似行李箱推薦」。
3. 推薦商品應優先來自相同分類，且只顯示上架、有庫存的商品。
4. 點擊推薦卡片後，應進入該商品詳情頁。
5. 若同分類沒有可售商品，系統會退回顯示其他上架且有庫存的商品。

## Git / Fork 協作注意

1. Commit 前先確認目前分支是團隊指定分支，例如 `integration/cart-order-into-homepage`。
2. `AGENTS.md` 是本機提醒文件，不要加入 Git；`.gitignore` 已排除它。
3. 不要 commit XAMPP runtime log、上傳圖片資料夾、暫存檔。
4. Commit 前至少執行：

```powershell
C:\xampp\php\php.exe -l homepage\checkout.php
C:\xampp\php\php.exe -l backend\orders.php
C:\xampp\php\php.exe -l backend\backend_action.php
C:\xampp\php\php.exe -l backend\actions\UpdateOrderStatus.php
```

5. 推送前手動測一次「加入購物車 -> 結帳 -> 後台改狀態 -> 庫存變化」。

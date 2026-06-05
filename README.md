hi


我目前只改後台的部分，新增商品時會包含廠商名稱，後台管理員可新增廠商帳號
後台管理員可發出供應請求，廠商可以提交供應表單，後台可以根據該供應表單新增商聘對應數量
廠商的營運統計那邊我覺得怪怪的還沒修好AI就沒預算了


現在價格還是亂的我現在是直接讓優惠卷在訂單提交時去覆蓋原本訂出來的價格，之後改價格的部分應該沒太大影響
我把products.php 跟 backend_action.php 拆成小塊了
products.php 的 js拆去 js/products.js, css 拆去 css/products.css
然後 products.php 的功能拆去 backend/products/create 跟 backend/products/list
    products.php ：初始化、SQL查詢、引入 create, list
    create：產品列表展示 HTML
    list：新增商品表單 HTML
backend_action.php 把功能拆進 backend/actions 裡面
backend_action 就負責分發請求到各个 action 處理器，不然照以前的方法我覺得加上其他功能後backend_action.php會變爆炸多東西很亂

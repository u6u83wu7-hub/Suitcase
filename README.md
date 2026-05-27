hi
我把products.php 跟 backend_action.php 拆成小塊了
products.php 的 js拆去 js/products.js, css 拆去 css/products.css
然後 products.php 的功能拆去 backend/products/create 跟 backend/products/list
    products.php ：初始化、SQL查詢、引入 create, list
    create：產品列表展示 HTML
    list：新增商品表單 HTML
backend_action.php 把功能拆進 backend/actions 裡面
backend_action 就負責分發請求到各个 action 處理器，不然照以前的方法我覺得加上其他功能後backend_action.php會變爆炸多東西很亂
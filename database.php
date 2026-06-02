<?php
// 本頁只供已登入管理員在本機檢視資料庫，請勿公開到正式環境。
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/homepage/includes/security.php';

apConfigureErrorHandling();
if (!isset($_SESSION['admin_id'])) {
    header('Location: backend/admin_login.php');
    exit;
}
mysqli_report(MYSQLI_REPORT_OFF); 

date_default_timezone_set('Asia/Taipei');

// 建立資料庫連線
$conn = new mysqli("localhost", "root", "", "all_pass_db");

if ($conn->connect_error) {
    die("資料庫連線失敗: " . $conn->connect_error);
}
$conn->query("SET time_zone = '+08:00'");
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>All Pass 自動撈資料庫</title>
    <style>
        body { font-family: 'PingFang TC', 'Microsoft JhengHei', sans-serif; background-color: #f4f7f6; color: #333; padding: 40px; }
        .dashboard-header { text-align: center; margin-bottom: 40px; }
        .dashboard-header h1 { color: #2c3e50; letter-spacing: 2px; }
        .table-container { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 40px; overflow-x: auto; }
        .table-title { font-size: 20px; font-weight: bold; color: #db6b6b; margin-bottom: 15px; border-left: 4px solid #db6b6b; padding-left: 10px; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; white-space: nowrap; }
        th, td { border: 1px solid #eee; padding: 12px 15px; text-align: left; }
        th { background-color: #2c3e50; color: white; font-weight: 500; }
        tr:nth-child(even) { background-color: #f9f9f9; }
        tr:hover { background-color: #f1f1f1; }
        .empty-msg { text-align: center; color: #999; font-style: italic; }
    </style>
</head>
<body>

    <div class="dashboard-header">
        <h1>🚀 All Pass 自動撈資料庫</h1>
        <p>系統狀態：連線正常 | 全表自動抓取中</p>
    </div>

    <?php
    // 【魔法第一步】去問資料庫：「你現在有幾個表格？」
    $tables_result = $conn->query("SHOW TABLES");

    if ($tables_result && $tables_result->num_rows > 0) {
        
        // 開始外層迴圈：一個一個表格抓出來處理
        while ($table_row = $tables_result->fetch_array()) {
            $table_name = $table_row[0]; // 取得表格名稱 (例如: users, products)
            
            echo '<div class="table-container">';
            echo '<div class="table-title">📁 表格名稱：' . $table_name . '</div>';
            echo '<table>';

            // 【魔法第二步】去問這個表格：「你有哪幾個欄位 (Columns)？」
            $columns_result = $conn->query("SHOW COLUMNS FROM `$table_name`");
            $columns = [];
            
            echo '<tr>';
            if ($columns_result) {
                while ($col = $columns_result->fetch_assoc()) {
                    $columns[] = $col['Field']; // 把欄位名稱存進陣列，等一下抓資料要用
                    echo '<th>' . $col['Field'] . '</th>'; // 印出表頭 <th>
                }
            }
            echo '</tr>';

            // 【魔法第三步】把這個表格的所有資料抓出來
            // 加上 LIMIT 100 是為了保護伺服器，避免資料幾萬筆時網頁當掉
            $data_result = $conn->query("SELECT * FROM `$table_name` ORDER BY 1 DESC LIMIT 100");

            if ($data_result && $data_result->num_rows > 0) {
                // 內層迴圈：一筆一筆印出資料
                while ($data_row = $data_result->fetch_assoc()) {
                    echo '<tr>';
                    // 根據剛剛抓到的欄位名稱，把資料對應填入 <td>
                    foreach ($columns as $col_name) {
                        // htmlspecialchars 是為了防止 XSS 攻擊，保護你的網頁
                        echo '<td>' . htmlspecialchars((string)$data_row[$col_name]) . '</td>';
                    }
                    echo '</tr>';
                }
            } else {
                echo "<tr><td colspan='" . count($columns) . "' class='empty-msg'>這個表格目前沒有任何資料</td></tr>";
            }

            echo '</table>';
            echo '</div>';
        }
    } else {
        echo "<h2 style='text-align:center; color:#e74c3c;'>你的資料庫目前空空如也，一個表格都沒有喔！</h2>";
    }

    $conn->close();
    ?>

</body>
</html>

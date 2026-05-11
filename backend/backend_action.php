<?php
session_start();

// 管理員驗證
if (!isset($_SESSION['admin_id'])) {
    die("Access Denied");
}

// 資料庫連線
$conn = new mysqli("localhost", "root", "", "all_pass_db");

if ($conn->connect_error) {
    die("資料庫連線失敗：" . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

// 表單送出
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $name = trim($_POST['name']);
    $price = floatval($_POST['price']);
    $stock = intval($_POST['stock']);

    $is_featured = isset($_POST['is_featured']) ? 1 : 0;

    $category_id = !empty($_POST['category_id'])
        ? intval($_POST['category_id'])
        : null;

    // slug
    $slug = strtolower(str_replace(' ', '-', $name)) . "-" . time();

    // 圖片資料夾
    $upload_dir = __DIR__ . "/img/products/";

    // 若資料夾不存在就建立
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    // 檢查是否有圖片
    if (empty($_FILES['product_images']['name'][0])) {
        die("請至少上傳一張圖片");
    }

    // 啟動交易
    $conn->begin_transaction();

    try {

        // =========================
        // products
        // =========================
        $stmt1 = $conn->prepare("
            INSERT INTO products
            (
                primary_category_id,
                name,
                slug,
                is_featured,
                status
            )
            VALUES (?, ?, ?, ?, 'ON SHELF')
        ");

        $stmt1->bind_param(
            "issi",
            $category_id,
            $name,
            $slug,
            $is_featured
        );

        if (!$stmt1->execute()) {
            throw new Exception("products 新增失敗");
        }

        $product_id = $conn->insert_id;

        // =========================
        // variants
        // =========================
        $sku_code = "AL-" . strtoupper(uniqid());

        $stmt2 = $conn->prepare("
            INSERT INTO product_variants
            (
                product_id,
                sku_code,
                price,
                stock_available
            )
            VALUES (?, ?, ?, ?)
        ");

        $stmt2->bind_param(
            "isdi",
            $product_id,
            $sku_code,
            $price,
            $stock
        );

        if (!$stmt2->execute()) {
            throw new Exception("variant 新增失敗");
        }

        // =========================
        // images
        // =========================
        foreach ($_FILES['product_images']['tmp_name'] as $key => $tmp_name) {

            if ($_FILES['product_images']['error'][$key] != 0) {
                throw new Exception("圖片上傳錯誤");
            }

            $original_name =
                $_FILES['product_images']['name'][$key];

            $file_ext = strtolower(
                pathinfo($original_name, PATHINFO_EXTENSION)
            );

            // 允許格式
            $allow = ['jpg', 'jpeg', 'png', 'webp'];

            if (!in_array($file_ext, $allow)) {
                throw new Exception("不支援的圖片格式");
            }

            // 新檔名
            $new_filename =
                $product_id . "_" .
                $key . "_" .
                time() . "." .
                $file_ext;

            $target_path = $upload_dir . $new_filename;

$db_image_path = "img/products/" . $new_filename;

            // 搬移圖片
            if (!move_uploaded_file($tmp_name, $target_path)) {

                echo "<pre>";
                print_r($_FILES);
                echo "</pre>";

                die("目標路徑：" . $target_path);
            }

            // 第一張主圖
            $is_main = ($key == 0) ? 1 : 0;

            // 寫入 product_images
            $stmt3 = $conn->prepare("
                INSERT INTO product_images
                (
                    product_id,
                    image_url,
                    is_main
                )
                VALUES (?, ?, ?)
            ");

            $stmt3->bind_param(
                "isi",
                $product_id,
                $db_image_path,
                $is_main
            );

            if (!$stmt3->execute()) {
                throw new Exception("圖片資料寫入失敗");
            }
        }

        // 成功
        $conn->commit();

        echo "
        <script>
            alert('商品新增成功');
            location.href='backend.php';
        </script>
        ";

    } catch (Exception $e) {

        // rollback
        $conn->rollback();

        echo "錯誤：" . $e->getMessage();
    }
}

$conn->close();
?>
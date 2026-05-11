<?php
// products.php - included by backend.php; assumes $conn and session already available

// 取得分類
$sql = "SELECT category_id, name FROM categories";
$result = $conn->query($sql);
?>

<h1>📦 商品管理</h1>
<p class="muted">新增商品到商城；已存在的商品請由列表管理。</p>

<form action="backend_action.php" method="POST" enctype="multipart/form-data">

    <label>商品名稱</label>
    <input type="text" name="name" required>

    <label>分類</label>
    <select name="category_id">
        <option value="">不分類</option>
        <?php
        if ($result) {
            while($row = $result->fetch_assoc()){
                echo '<option value="' . $row['category_id'] . '">' . htmlspecialchars($row['name']) . '</option>';
            }
        }
        ?>
    </select>

    <label>價格</label>
    <input type="number" name="price" required>

    <label>庫存</label>
    <input type="number" name="stock" required>

    <label>商品圖片</label>
    <input type="file" name="product_images[]" multiple required>

    <label><input type="checkbox" name="is_featured" value="1" checked> 首頁精選商品</label>

    <br><br>
    <button type="submit">發布商品</button>

</form>

<?php
include 'db_connect.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$message = "";

// ✅ เพิ่ม หรือ แก้ไข หมวดหมู่
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $category_name = mysqli_real_escape_string($conn, $_POST['category_name']);
    $description = mysqli_real_escape_string($conn, $_POST['description']);

    if (isset($_POST['category_id']) && !empty($_POST['category_id'])) {
        // ✅ อัพเดตหมวดหมู่
        $category_id = intval($_POST['category_id']);
        $sql = "UPDATE tb_categories SET name='$category_name', description='$description' WHERE id=$category_id";
        if (mysqli_query($conn, $sql)) {
            $message = "Category updated successfully!";
        } else {
            $message = "Error updating category: " . mysqli_error($conn);
        }
    } else {
        // ✅ เพิ่มหมวดหมู่ใหม่
        $sql = "INSERT INTO tb_categories (name, description) VALUES ('$category_name', '$description')";
        if (mysqli_query($conn, $sql)) {
            $message = "Category added successfully!";
        } else {
            $message = "Error adding category: " . mysqli_error($conn);
        }
    }
}

// ✅ ลบหมวดหมู่
if (isset($_GET['delete'])) {
    $delete_id = intval($_GET['delete']);
    $sql_delete = "DELETE FROM tb_categories WHERE id = $delete_id";
    if (mysqli_query($conn, $sql_delete)) {
        $message = "Category deleted successfully!";
    } else {
        $message = "Error deleting category: " . mysqli_error($conn);
    }
}

// ✅ ดึงข้อมูลหมวดหมู่ทั้งหมด
$sql_categories = "SELECT * FROM tb_categories ORDER BY name ASC";
$result_categories = mysqli_query($conn, $sql_categories);

mysqli_close($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Categories</title>
    <link rel="stylesheet" href="css/enterprise-ui.css">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen">
    <?php $active_nav = ''; include __DIR__ . '/navbar_kpi.php'; ?>
    <main class="enterprise-shell">
    <?php
    kpi_page_header(
        'จัดการหมวดหมู่',
        'รวบรวมการเพิ่ม แก้ไข และลบหมวดหมู่ตัวชี้วัดไว้ในหน้าจอเดียว พร้อมแถบเมนูแบบเดียวกับส่วนอื่นของระบบ',
        array(
            array('label' => 'หน้าแรก', 'href' => 'index.php'),
            array('label' => 'หมวดหมู่', 'href' => '')
        ),
        kpi_enterprise_action_link('index.php', 'กลับหน้าแรก', 'secondary')
    );
    ?>

    <div class="max-w-4xl mx-auto enterprise-panel p-6 sm:p-8">
        <div class="flex justify-between items-center">
            <h1 class="text-2xl font-bold text-gray-800 mb-4">Manage Catagory</h1>
            <a href="index.php" class="px-4 py-2 bg-blue-500 text-white rounded">🏠 Home</a>
        </div>

        <?php if (!empty($message)) : ?>
            <p class="text-green-500"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>

        <!-- ✅ ฟอร์มเพิ่ม/แก้ไขหมวดหมู่ -->
        <form method="POST" class="space-y-4">
            <input type="hidden" name="category_id" id="category_id">
            
            <label class="block font-semibold text-gray-700">Category Name</label>
            <input type="text" name="category_name" id="category_name" required class="w-full p-2 border rounded">

            <label class="block font-semibold text-gray-700">Description</label>
            <textarea name="description" id="description" class="w-full p-2 border rounded"></textarea>

            <button type="submit" class="px-4 py-2 bg-blue-500 text-white rounded">Save</button>
        </form>

        <!-- ✅ ตารางแสดงหมวดหมู่ -->
        <div class="mt-6">
            <h2 class="text-xl font-bold text-gray-800">Category List</h2>
            <table class="w-full mt-2 border">
                <thead>
                    <tr class="bg-gray-200">
                        <th class="p-2 border">Name</th>
                        <th class="p-2 border">Description</th>
                        <th class="p-2 border">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = mysqli_fetch_assoc($result_categories)) : ?>
                        <tr>
                            <td class="p-2 border"><?php echo htmlspecialchars($row['name']); ?></td>
                            <td class="p-2 border"><?php echo htmlspecialchars($row['description']); ?></td>
                            <td class="p-2 border text-center">
                                <button class="px-3 py-1 bg-yellow-500 text-white rounded edit-btn" 
                                        data-id="<?php echo $row['id']; ?>" 
                                        data-name="<?php echo htmlspecialchars($row['name']); ?>" 
                                        data-description="<?php echo htmlspecialchars($row['description']); ?>">
                                    Edit
                                </button>
                                <a href="category.php?delete=<?php echo $row['id']; ?>" 
                                   onclick="return confirm('Are you sure?');"
                                   class="px-3 py-1 bg-red-500 text-white rounded">
                                    Delete
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        document.addEventListener("DOMContentLoaded", function () {
            let editButtons = document.querySelectorAll(".edit-btn");

            editButtons.forEach(button => {
                button.addEventListener("click", function () {
                    document.getElementById("category_id").value = this.getAttribute("data-id");
                    document.getElementById("category_name").value = this.getAttribute("data-name");
                    document.getElementById("description").value = this.getAttribute("data-description");
                });
            });
        });
    </script>

    </main>
</body>
</html>

<?php
include 'db_connect.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
$message = "";

// ตรวจสอบการส่งข้อมูล
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_kpi'])) {
    $kpi_name = mysqli_real_escape_string($conn, $_POST['kpi_name']);
    $description = mysqli_real_escape_string($conn, $_POST['description']);
    $category = mysqli_real_escape_string($conn, $_POST['category']);
    $target_value = floatval($_POST['target_value']);
    $actual_value = floatval($_POST['actual_value']);
    $unit = mysqli_real_escape_string($conn, $_POST['unit']);
    $operation = mysqli_real_escape_string($conn, $_POST['operation']);
    $strategic_issue = mysqli_real_escape_string($conn, $_POST['strategic_issue']);
    $mission = mysqli_real_escape_string($conn, $_POST['mission']);
    $fiscal_year = intval($_POST['fiscal_year']);
    $quarter = mysqli_real_escape_string($conn, $_POST['quarter']);
    $responsible_person = mysqli_real_escape_string($conn, $_POST['responsible_person']);
    $action_plan = mysqli_real_escape_string($conn, $_POST['action_plan']);
    $root_cause = mysqli_real_escape_string($conn, $_POST['root_cause']);
    $suggestions = mysqli_real_escape_string($conn, $_POST['suggestions']);
    
    $variance = $target_value - $actual_value;
    $status = ($actual_value >= $target_value) ? 'Success' : 'Fail';
    
    $sql_insert = "INSERT INTO tb_kpis (
        kpi_name, description, category, target_value, actual_value, variance, status,
        unit, operation, strategic_issue, mission, fiscal_year, quarter,
        responsible_person, action_plan, root_cause, suggestions, last_update
    ) VALUES (
        '$kpi_name', '$description', '$category', '$target_value', '$actual_value', '$variance', '$status',
        '$unit', '$operation', '$strategic_issue', '$mission', '$fiscal_year', '$quarter',
        '$responsible_person', '$action_plan', '$root_cause', '$suggestions', NOW()
    )";
    
    if (mysqli_query($conn, $sql_insert)) {
        header("Location: dashboard.php?message=KPI Added Successfully");
        exit();
    } else {
        $message = "Error adding KPI: " . mysqli_error($conn);
    }
}
mysqli_close($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add KPI</title>
    <link rel="stylesheet" href="css/enterprise-ui.css">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen">
    <?php $active_nav = 'template'; include __DIR__ . '/navbar_kpi.php'; ?>
    <main class="enterprise-shell">
        <?php
        kpi_page_header(
            'เพิ่มตัวชี้วัด',
            'จัดเก็บตัวชี้วัดพื้นฐานเข้าสู่ระบบด้วยหน้าฟอร์มที่อยู่ใน visual language เดียวกับหน้าโปรไฟล์และหน้าจัดการอื่นของโครงการ',
            array(
                array('label' => 'หน้าแรก', 'href' => 'index.php'),
                array('label' => 'เพิ่มตัวชี้วัด', 'href' => '')
            ),
            kpi_enterprise_action_link('kpi_template_manage.php', 'กลับหน้าตัวชี้วัด', 'secondary')
        );
        ?>
    <div class="max-w-4xl mx-auto enterprise-panel p-6 sm:p-8">
         <div class="flex justify-between items-center">
            <h1 class="text-2xl font-bold text-gray-800 mb-4">Add New KPI</h1>
            <a href="index.php" class="px-4 py-2 bg-blue-500 text-white rounded">🏠 Home</a>
        </div>
        <?php if (!empty($message)) : ?>
            <p class="text-red-500"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>
        
        <form method="POST" class="space-y-4">
            <label class="block font-semibold text-gray-700">KPI Name</label>
            <input type="text" name="kpi_name" class="w-full p-2 border rounded">
            
            <label class="block font-semibold text-gray-700">Description</label>
            <textarea name="description" class="w-full p-2 border rounded"></textarea>
            
            <label class="block font-semibold text-gray-700">Category</label>
            <input type="text" name="category" class="w-full p-2 border rounded">
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block font-semibold text-gray-700">Fiscal Year</label>
                    <input type="number" name="fiscal_year" class="w-full p-2 border rounded">
                </div>
                <div>
                    <label class="block font-semibold text-gray-700">Quarter</label>
                    <select name="quarter" class="w-full p-2 border rounded">
                        <option value="Q1">Q1</option>
                        <option value="Q2">Q2</option>
                        <option value="Q3">Q3</option>
                        <option value="Q4">Q4</option>
                    </select>
                </div>
            </div>
            
            <label class="block font-semibold text-gray-700">Strategic Issue</label>
            <input type="text" name="strategic_issue" class="w-full p-2 border rounded">
            
            <label class="block font-semibold text-gray-700">Mission</label>
            <input type="text" name="mission" class="w-full p-2 border rounded">
            
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block font-semibold text-gray-700">Operation</label>
                    <select name="operation" class="w-full p-2 border rounded">
                        <option value="=">=</option>
                        <option value=">">></option>
                        <option value="<"><</option>
                        <option value=">=">>=</option>
                        <option value="<="><=</option>
                    </select>
                </div>
                <div>
                    <label class="block font-semibold text-gray-700">Target Value</label>
                    <input type="number" step="0.01" name="target_value" class="w-full p-2 border rounded">
                </div>
                <div>
                    <label class="block font-semibold text-gray-700">Unit</label>
                    <input type="text" name="unit" class="w-full p-2 border rounded">
                </div>
            </div>
            
            <label class="block font-semibold text-gray-700">Actual Value</label>
            <input type="number" step="0.01" name="actual_value" class="w-full p-2 border rounded">
            
            <label class="block font-semibold text-gray-700">Responsible Person</label>
            <input type="text" name="responsible_person" class="w-full p-2 border rounded">
            
            <label class="block font-semibold text-gray-700">Action Plan</label>
            <textarea name="action_plan" class="w-full p-2 border rounded"></textarea>
            
            <label class="block font-semibold text-gray-700">Root Cause</label>
            <textarea name="root_cause" class="w-full p-2 border rounded"></textarea>
            
            <label class="block font-semibold text-gray-700">Suggestions</label>
            <textarea name="suggestions" class="w-full p-2 border rounded"></textarea>
            
            <button type="submit" name="save_kpi" class="px-4 py-2 bg-blue-500 text-white rounded">Save KPI</button>
        </form>
    </div>
    </main>
</body>
</html>

<?php
include 'db_connect.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
$message = "";

// Strategic Issues
$sql_issues = "SELECT * FROM tb_strategic_issues ORDER BY name ASC";
$result_issues = mysqli_query($conn, $sql_issues);
if (!$result_issues) die("Error fetching strategic issues: " . mysqli_error($conn));
$strategic_issues_list = [];
while ($issue = mysqli_fetch_assoc($result_issues)) {
    $strategic_issues_list[$issue['name']] = $issue['name'];
}

// Missions
$sql_missions = "SELECT * FROM tb_missions ORDER BY name ASC";
$result_missions = mysqli_query($conn, $sql_missions);
if (!$result_missions) die("Error fetching missions: " . mysqli_error($conn));
$missions_list = [];
while ($mission = mysqli_fetch_assoc($result_missions)) {
    $missions_list[$mission['id']] = [
        'name' => $mission['name'],
        'strategic_issue' => $mission['strategic_issue']
    ];
}

// Fiscal Years
$sql_fiscal_years = "SELECT * FROM tb_fiscal_years ORDER BY year ASC";
$result_fiscal_years = mysqli_query($conn, $sql_fiscal_years);
if (!$result_fiscal_years) die("Error fetching fiscal years: " . mysqli_error($conn));
$fiscal_years_list = [];
while ($row = mysqli_fetch_assoc($result_fiscal_years)) {
    $fiscal_years_list[$row['year']] = $row['year'];
}

// Categories
$sql_categories = "SELECT id, name FROM tb_categories ORDER BY name ASC";
$result_categories = mysqli_query($conn, $sql_categories);
if (!$result_categories) die("Error fetching categories: " . mysqli_error($conn));
$categories_list = [];
while ($row = mysqli_fetch_assoc($result_categories)) {
    if (isset($row['id'], $row['name'])) {
        $categories_list[$row['id']] = $row['name'];
    }
}

if (empty($categories_list)) echo "<p class='text-red-500'>⚠ ไม่มีหมวดหมู่ในระบบ กรุณาเพิ่มข้อมูลหมวดหมู่</p>";

// Load KPI data for editing
$kpi_data = [
    'kpi_name' => '', 'description' => '', 'category_id' => '',
    'target_value' => '', 'actual_value' => '', 'unit' => '',
    'operation' => '', 'strategic_issue' => '', 'mission' => '',
    'fiscal_year' => date('Y') + 543, 'responsible_person' => '',
    'action_plan' => '', 'root_cause' => '', 'suggestions' => '',
    'quarter1' => '', 'quarter2' => '', 'quarter3' => '', 'quarter4' => ''
];

if ($edit_id) {
    $sql = "SELECT * FROM tb_kpis WHERE id = $edit_id";
    $result = mysqli_query($conn, $sql);
    if ($result && mysqli_num_rows($result) > 0) {
        $kpi_data = mysqli_fetch_assoc($result);
        foreach ([1, 2, 3, 4] as $q) {
            if (!isset($kpi_data["quarter$q"])) $kpi_data["quarter$q"] = '';
        }
    } else {
        $message = "KPI not found.";
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $kpi_name = mysqli_real_escape_string($conn, $_POST['kpi_name']);
    $description = mysqli_real_escape_string($conn, $_POST['description']);
    $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : NULL;
    $target_value = floatval($_POST['target_value']);
    $quarter1 = isset($_POST['quarter1']) ? floatval($_POST['quarter1']) : 0;
    $quarter2 = isset($_POST['quarter2']) ? floatval($_POST['quarter2']) : 0;
    $quarter3 = isset($_POST['quarter3']) ? floatval($_POST['quarter3']) : 0;
    $quarter4 = isset($_POST['quarter4']) ? floatval($_POST['quarter4']) : 0;
    $actual_value = $quarter1 + $quarter2 + $quarter3 + $quarter4;
    $unit = mysqli_real_escape_string($conn, $_POST['unit']);
    $operation = mysqli_real_escape_string($conn, $_POST['operation']);
    $strategic_issue = mysqli_real_escape_string($conn, $_POST['strategic_issue']);
    $mission = mysqli_real_escape_string($conn, $_POST['mission']);
    $fiscal_year = mysqli_real_escape_string($conn, $_POST['fiscal_year']);
    $responsible_person = mysqli_real_escape_string($conn, $_POST['responsible_person']);
    $action_plan = mysqli_real_escape_string($conn, $_POST['action_plan']);
    $root_cause = mysqli_real_escape_string($conn, $_POST['root_cause']);
    $suggestions = mysqli_real_escape_string($conn, $_POST['suggestions']);

    $variance = $target_value - $actual_value;
    $status = ($actual_value >= $target_value) ? 'Success' : 'Fail';

    if ($edit_id) {
        $sql_update = "UPDATE tb_kpis SET 
            kpi_name='$kpi_name', description='$description', category_id='$category_id',
            target_value='$target_value', actual_value='$actual_value', variance='$variance',
            status='$status', unit='$unit', operation='$operation', strategic_issue='$strategic_issue',
            mission='$mission', fiscal_year='$fiscal_year',
            quarter1='$quarter1', quarter2='$quarter2', quarter3='$quarter3', quarter4='$quarter4',
            responsible_person='$responsible_person', action_plan='$action_plan',
            root_cause='$root_cause', suggestions='$suggestions'
            WHERE id=$edit_id";

        if (mysqli_query($conn, $sql_update)) {
            echo "<script>window.location.href='dashboard.php?message=KPI Updated Successfully';</script>";
            exit();
        } else {
            $message = "Error updating KPI: " . mysqli_error($conn);
        }
    } else {
        $sql_insert = "INSERT INTO tb_kpis 
            (kpi_name, description, category_id, target_value, actual_value, variance, status,
            unit, operation, strategic_issue, mission, fiscal_year,
            quarter1, quarter2, quarter3, quarter4,
            responsible_person, action_plan, root_cause, suggestions) 
            VALUES 
            ('$kpi_name', '$description', '$category_id', '$target_value', '$actual_value', '$variance', '$status',
            '$unit', '$operation', '$strategic_issue', '$mission', '$fiscal_year',
            '$quarter1', '$quarter2', '$quarter3', '$quarter4',
            '$responsible_person', '$action_plan', '$root_cause','$suggestions')";

        if (mysqli_query($conn, $sql_insert)) {
            echo "<script>window.location.href='dashboard.php?message=KPI Added Successfully';</script>";
            exit();
        } else {
            $message = "Error inserting KPI: " . mysqli_error($conn);
        }
    }
}

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit KPI</title>
    <link rel="stylesheet" href="css/enterprise-ui.css">
    <script src="https://cdn.tailwindcss.com"></script>
	
	<script>
document.addEventListener("DOMContentLoaded", function () {
    let strategicDropdown = document.querySelector('select[name="strategic_issue"]');
    let missionDropdown = document.querySelector('select[name="mission"]');

    function filterMissions() {
        let selectedStrategicIssue = strategicDropdown.value;
        let missionOptions = missionDropdown.querySelectorAll("option");

        missionOptions.forEach(option => {
            let missionStrategicIssue = option.getAttribute("data-strategic-issue");
            if (!missionStrategicIssue || missionStrategicIssue === selectedStrategicIssue || option.value === "") {
                option.style.display = "block";
            } else {
                option.style.display = "none";
            }
        });
    }

    strategicDropdown.addEventListener("change", filterMissions);
    filterMissions();
});
</script>

</head>
<body class="min-h-screen">
    <?php $active_nav = 'template'; include __DIR__ . '/navbar_kpi.php'; ?>
    <main class="enterprise-shell">
    <?php
    kpi_page_header(
        $edit_id ? 'แก้ไข KPI รายไตรมาส' : 'เพิ่ม KPI รายไตรมาส',
        'หน้าจอเวอร์ชันเดิมสำหรับจัดการ KPI รายไตรมาส ได้รับการย้ายมาอยู่ใต้แถบเมนู enterprise เพื่อให้ใช้งานต่อเนื่องกับหน้าอื่นของระบบ',
        array(
            array('label' => 'หน้าแรก', 'href' => 'index.php'),
            array('label' => 'KPI รายไตรมาส', 'href' => '')
        ),
        kpi_enterprise_action_link('dashboard.php', 'กลับแดชบอร์ด', 'secondary')
    );
    ?>

    <div class="max-w-4xl mx-auto enterprise-panel p-6 sm:p-8">
        <h1 class="text-2xl font-bold text-gray-800 mb-4">เพิ่ม/แก้ไข KPI</h1>

        <?php if (!empty($message)) : ?>
            <p class="text-red-500"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>

        <?php if ($kpi_data) : ?>
            <form method="POST" class="space-y-4">
				<input type="hidden" name="id" value="<?php echo htmlspecialchars($kpi_data['id']); ?>">

				<label class="block font-semibold text-gray-700">KPI Name (ตัวชี้วัด)</label>
				<input type="text" name="kpi_name" value="<?php echo htmlspecialchars($kpi_data['kpi_name']); ?>" class="w-full p-2 border rounded">

				<label class="block font-semibold text-gray-700">Description (รายละเอียดตัวชี้วัด)</label>
				<textarea name="description" class="w-full p-2 border rounded"><?php echo htmlspecialchars($kpi_data['description']); ?></textarea>

				<label class="block font-semibold text-gray-700">Category (หมวดหมู่)</label>
				<select name="category_id" class="w-full p-2 border rounded">
					<option value="">-- เลือกหมวดหมู่ --</option>
					<?php foreach ($categories_list as $id => $category_name): ?>
						<option value="<?php echo $id; ?>" 
							<?php echo (isset($kpi_data['category_id']) && $kpi_data['category_id'] == $id) ? 'selected' : ''; ?>>
							<?php echo htmlspecialchars($category_name); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<!-- ปีงบประมาณ + ไตรมาส (อยู่ในบรรทัดเดียวกัน) -->
				<div class="grid grid-cols-2 gap-4">
					<div>
						<label class="block font-semibold text-gray-700">Fiscal Year (ปีงบประมาณ)</label>
							<select name="fiscal_year" class="w-full p-2 border rounded">
								<option value="">-- เลือกปีงบประมาณ --</option>
								<?php foreach ($fiscal_years_list as $year): ?>
									<option value="<?php echo $year; ?>" <?php echo ($kpi_data['fiscal_year'] == $year) ? 'selected' : ''; ?>>
										<?php echo $year; ?>
									</option>
								<?php endforeach; ?>
							</select>
					</div>
				</div>
				<div class="grid grid-cols-2 gap-4">
					<div class="grid grid-cols-4 gap-4">
						<?php foreach ([1, 2, 3, 4] as $q): ?>
						<div>
							<label class="block font-semibold text-gray-700">ไตรมาส <?php echo $q; ?> (Q<?php echo $q; ?>)</label>
							<input type="number" step="0.01" name="quarter<?php echo $q; ?>" 
								value="<?php echo isset($kpi_data["quarter$q"]) ? htmlspecialchars($kpi_data["quarter$q"]) : ''; ?>" 
								class="w-full p-2 border rounded">
						</div>
						<?php endforeach; ?>
					</div>

				</div>

				<label class="block font-semibold text-gray-700">Strategic Issue (ประเด็นยุทธศาสตร์)</label>
				<select name="strategic_issue" class="w-full p-2 border rounded">
					<option value="">-- เลือกประเด็นยุทธศาสตร์ --</option>
					<?php foreach ($strategic_issues_list as $name => $display_name): ?>
						<option value="<?php echo $name; ?>" <?php echo ($kpi_data['strategic_issue'] == $name) ? 'selected' : ''; ?>>
							<?php echo htmlspecialchars($display_name); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<label class="block font-semibold text-gray-700">Mission (พันธกิจ)</label>
				<select name="mission" id="missionDropdown" class="w-full p-2 border rounded">
					<option value="">-- เลือกพันธกิจ --</option>
					<?php foreach ($missions_list as $id => $mission): ?>
						<option value="<?php echo htmlspecialchars($mission['name']); ?>" 
							data-strategic-issue="<?php echo htmlspecialchars($mission['strategic_issue']); ?>" 
							<?php echo ($kpi_data['mission'] == $mission['name']) ? 'selected' : ''; ?>>
							<?php echo htmlspecialchars($mission['name']); ?>
						</option>
					<?php endforeach; ?>
				</select>
				
				<!-- Target Value + Unit + Operation + Actual Value (อยู่ในบรรทัดเดียวกัน) -->
				<div class="grid grid-cols-3 gap-4">
					<div>
						<label class="block font-semibold text-gray-700">Operation (เงื่อนไขเปรียบเทียบ)</label>
						<select name="operation" class="w-full p-2 border rounded">
							<?php foreach (["=", ">", "<", ">=", "<="] as $op) : ?>
								<option value="<?php echo $op; ?>" <?php echo ($kpi_data['operation'] == $op) ? 'selected' : ''; ?>><?php echo $op; ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div>
						<label class="block font-semibold text-gray-700">Target Value (เป้าหมาย)</label>
						<input type="number" step="0.01" name="target_value" value="<?php echo htmlspecialchars($kpi_data['target_value']); ?>" class="w-full p-2 border rounded">
					</div>
					<div>
						<label class="block font-semibold text-gray-700">Unit (หน่วยวัด)</label>
						<input type="text" name="unit" value="<?php echo htmlspecialchars($kpi_data['unit']); ?>" class="w-full p-2 border rounded">
					</div>
				</div>
				
				<label class="block font-semibold text-gray-700">Actual Value (ค่าจริง)</label>
				<input type="number" step="0.01" name="actual_value" value="<?php echo htmlspecialchars($kpi_data['actual_value']); ?>" class="w-full p-2 border rounded">
				
				<label class="block font-semibold text-gray-700">Responsible Person (ผู้รับผิดชอบ)</label>
				<input type="text" name="responsible_person" value="<?php echo htmlspecialchars($kpi_data['responsible_person']); ?>" class="w-full p-2 border rounded">

				<label class="block font-semibold text-gray-700">Action Plan (แผนปฏิบัติการ)</label>
				<textarea name="action_plan" class="w-full p-2 border rounded"><?php echo htmlspecialchars($kpi_data['action_plan']); ?></textarea>

				<label class="block font-semibold text-gray-700">Root Cause (สาเหตุที่แท้จริง)</label>
				<textarea name="root_cause" class="w-full p-2 border rounded"><?php echo htmlspecialchars($kpi_data['root_cause']); ?></textarea>

				<label class="block font-semibold text-gray-700">Suggestions (ข้อเสนอแนะ)</label>
				<textarea name="suggestions" class="w-full p-2 border rounded"><?php echo htmlspecialchars($kpi_data['suggestions']); ?></textarea>

				<div class="flex gap-4">
					<button type="submit" name="update_kpi" class="px-4 py-2 bg-blue-500 text-white rounded">Save</button>
					<a href="dashboard.php" class="px-4 py-2 bg-gray-500 text-white rounded">Cancel</a>
				</div>
			</form>

        <?php else: ?>
            <p class="text-red-500">Invalid KPI ID.</p>
        <?php endif; ?>
    </div>
    </main>
</body>
</html>

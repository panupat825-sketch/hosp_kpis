<?php
// เชื่อมต่อฐานข้อมูล
include 'db_connect.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// ✅ ดึงข้อมูลจาก `tb_strategic_issues`
$sql_issues = "SELECT * FROM tb_strategic_issues ORDER BY name ASC";
$result_issues = mysqli_query($conn, $sql_issues);
$strategic_issues_list = [];
if ($result_issues) {
    while ($issue = mysqli_fetch_assoc($result_issues)) {
        $strategic_issues_list[$issue['name']] = $issue['name']; // ✅ ใช้ `name` แทน `id`
    }
} else {
    die("Error fetching strategic issues: " . mysqli_error($conn));
}

// ✅ ดึงพันธกิจทั้งหมด
$sql_missions = "SELECT * FROM tb_missions ORDER BY name ASC";
$result_missions = mysqli_query($conn, $sql_missions);
$missions_list = [];
if ($result_missions) {
    while ($mission = mysqli_fetch_assoc($result_missions)) {
        $missions_list[$mission['name']] = $mission['name'];
    }
} else {
    die("Error fetching missions: " . mysqli_error($conn));
}

// ✅ ดึงปีงบประมาณ
$sql_fiscal_years = "SELECT DISTINCT fiscal_year FROM tb_kpis ORDER BY fiscal_year DESC";
$result_fiscal_years = mysqli_query($conn, $sql_fiscal_years);
$fiscal_years_list = [];
if ($result_fiscal_years) {
    while ($year = mysqli_fetch_assoc($result_fiscal_years)) {
        $fiscal_years_list[$year['fiscal_year']] = $year['fiscal_year'];
    }
} else {
    die("Error fetching fiscal years: " . mysqli_error($conn));
}

// ✅ รับค่าฟิลเตอร์
$filter_strategic_issue = isset($_GET['strategic_issue']) ? mysqli_real_escape_string($conn, $_GET['strategic_issue']) : '';
$filter_mission = isset($_GET['mission']) ? mysqli_real_escape_string($conn, $_GET['mission']) : '';
$filter_fiscal_year = isset($_GET['fiscal_year']) ? mysqli_real_escape_string($conn, $_GET['fiscal_year']) : '';

// ✅ กำหนดเงื่อนไขตัวกรอง
$where_clause = "WHERE 1=1";
if (!empty($filter_strategic_issue)) {
    $where_clause .= " AND kpi.strategic_issue = '$filter_strategic_issue'";
}
if (!empty($filter_mission)) {
    $where_clause .= " AND kpi.mission = '$filter_mission'";
}
if (!empty($filter_fiscal_year)) {
    $where_clause .= " AND kpi.fiscal_year = '$filter_fiscal_year'";
}

// ✅ ดึงข้อมูล KPI พร้อม JOIN กับ Strategic Issues และ Missions
$sql = "
    SELECT kpi.*, si.name AS strategic_issue_name, m.name AS mission_name
    FROM tb_kpis AS kpi
    LEFT JOIN tb_strategic_issues AS si ON kpi.strategic_issue = si.name
    LEFT JOIN tb_missions AS m ON kpi.mission = m.name
    $where_clause 
    ORDER BY kpi.fiscal_year DESC, kpi.quarter ASC, kpi.strategic_issue ASC, kpi.mission ASC";

$result = mysqli_query($conn, $sql);
if (!$result) {
    die("Error fetching KPI data: " . mysqli_error($conn));
}

// ✅ จัดกลุ่มข้อมูล KPI ตาม Strategic Issue และ Mission
$strategic_issues = [];
while ($row = mysqli_fetch_assoc($result)) {
    $strategic_issues[$row['strategic_issue']]['name'] = $row['strategic_issue'];
    $strategic_issues[$row['strategic_issue']]['missions'][$row['mission']]['name'] = $row['mission'];
}

// ✅ ตรวจสอบการลบข้อมูล KPI
if (isset($_GET['delete'])) {
    $delete_id = intval($_GET['delete']);
    $sql_delete = "DELETE FROM tb_kpis WHERE id = ?";
    
    if ($stmt = mysqli_prepare($conn, $sql_delete)) {
        mysqli_stmt_bind_param($stmt, "i", $delete_id);
        if (mysqli_stmt_execute($stmt)) {
            echo "<script>window.location.href='dashboard.php?message=KPI Deleted Successfully';</script>";
            exit();
        } else {
            echo "<script>alert('Error deleting KPI: " . mysqli_error($conn) . "');</script>";
        }
        mysqli_stmt_close($stmt);
    }
}



// รับค่าช่วงเวลา
$selected_month = isset($_GET['month']) ? $_GET['month'] : '';
$where_clause = $selected_month ? "WHERE month = '$selected_month'" : "";

// ดึงข้อมูล KPI จากฐานข้อมูลโดยใช้ค่าตัวกรองที่แก้ไขแล้ว
$sql = "SELECT * FROM tb_kpis $where_clause ORDER BY fiscal_year, quarter, strategic_issue, mission";
$result = mysqli_query($conn, $sql);

if (!$result) {
    die("Error in SQL query: " . mysqli_error($conn));
}

$strategic_issues = [];
$kpi_labels = [];
$kpi_values = [];

while ($row = mysqli_fetch_assoc($result)) {
    switch ($row['operation']) {
        case '<':
        case '<=':
            $status_class = ($row['actual_value'] <= $row['target_value']) ? "bg-green-200 border-l-4 border-green-600" : "bg-red-200 border-l-4 border-red-600";
            break;
        case '>':
        case '>=':
            $status_class = ($row['actual_value'] >= $row['target_value']) ? "bg-green-200 border-l-4 border-green-600" : "bg-red-200 border-l-4 border-red-600";
            break;
        case '=':
            $status_class = ($row['actual_value'] == $row['target_value']) ? "bg-green-200 border-l-4 border-green-600" : "bg-red-200 border-l-4 border-red-600";
            break;
        default:
            $status_class = "bg-gray-200 border-l-4 border-gray-500";
    }

    if ($row['operation'] == '<=' || $row['operation'] == '<') {
        $progress = ($row['actual_value'] > 0) ? ($row['target_value'] / $row['actual_value']) * 100 : 0;
    } else {
        $progress = ($row['target_value'] > 0) ? ($row['actual_value'] / $row['target_value']) * 100 : 0;
    }

    $kpi_labels[] = $row['kpi_name'];
    $kpi_values[] = $row['actual_value'];

    $strategic_issues[$row['strategic_issue']]['name'] = $row['strategic_issue'];
    $strategic_issues[$row['strategic_issue']]['missions'][$row['mission']]['name'] = $row['mission'];
    $strategic_issues[$row['strategic_issue']]['missions'][$row['mission']]['fiscal_years'][$row['fiscal_year']]['quarters'][$row['quarter']]['kpis'][] = [
        'id' => $row['id'],
        'name' => $row['kpi_name'],
        'description' => $row['description'],
        'category' => $row['category'],
        'target' => $row['target_value'],
        'actual' => $row['actual_value'],
        'variance' => $row['variance'],
        'status' => $row['status'],
        'unit' => $row['unit'],
        'operation' => $row['operation'],
        'progress' => $progress,
        'status_class' => $status_class,
        'strategic_goal' => $row['strategic_goal'],
        'department_id' => $row['department_id'],
        'responsible_person' => $row['responsible_person'],
        'action_plan' => $row['action_plan'],
        'root_cause' => $row['root_cause'],
        'suggestions' => $row['suggestions'],
        'alert_threshold' => $row['alert_threshold'],
        'last_update' => $row['last_update']
    ];
}

mysqli_close($conn);
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hospital KPI Dashboard</title>
	
    <link rel="stylesheet" href="css/enterprise-ui.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="min-h-screen">
    <?php $active_nav = 'dashboard_quarter'; include __DIR__ . '/navbar_kpi.php'; ?>
    <main class="enterprise-shell">
    <?php
    kpi_page_header(
        'แดชบอร์ดตัวชี้วัดแบบเดิม',
        'โครงสร้างข้อมูลเดิมยังใช้งานได้เหมือนเดิม แต่เปลี่ยนแถบเมนูและเปลือกหน้าให้สอดคล้องกับ enterprise workspace ชุดใหม่',
        array(
            array('label' => 'หน้าแรก', 'href' => 'index.php'),
            array('label' => 'แดชบอร์ดแบบเดิม', 'href' => '')
        ),
        kpi_enterprise_action_link('kpi_manage.php', 'เพิ่ม KPI', 'primary')
    );
    ?>

    <div class="max-w-6xl mx-auto enterprise-panel p-6 sm:p-8">
   
        
        <div class="flex justify-between items-center mb-4">
			<h1 class="text-2xl font-bold text-gray-800">Sirattana Hospital KPI Dashboard</h1>
			<div class="flex gap-2">
				<a href="kpi_manage.php" class="px-4 py-2 bg-green-500 text-white rounded hover:bg-green-600 transition">
					➕ Add KPI
				</a>
				<a href="index.php" class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600 transition">
					🏠 Home
				</a>
			</div>
		</div>
    
		<!-- Form Filter -->
        <form method="GET" class="mb-4 flex gap-4">
            <select name="strategic_issue" class="p-2 border rounded">
                <option value="">เลือกประเด็นยุทธศาสตร์</option>
                <?php foreach ($strategic_issues_list as $issue) : ?>
                    <option value="<?php echo htmlspecialchars($issue); ?>" <?php echo ($filter_strategic_issue == $issue) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($issue); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="mission" class="p-2 border rounded">
                <option value="">เลือกพันธกิจ</option>
                <?php foreach ($missions_list as $mission) : ?>
                    <option value="<?php echo htmlspecialchars($mission); ?>" <?php echo ($filter_mission == $mission) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($mission); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="fiscal_year" class="p-2 border rounded">
                <option value="">เลือกปีงบประมาณ</option>
                <?php foreach ($fiscal_years_list as $year) : ?>
                    <option value="<?php echo $year; ?>" <?php echo ($filter_fiscal_year == $year) ? 'selected' : ''; ?>>
                        <?php echo $year; ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="px-4 py-2 bg-green-500 text-white rounded">🔍 Filter</button>
        </form>


				<!-- แสดง KPI -->
				
		<!-- Container ครอบทั้งหมด -->
<div class="bg-white p-6 rounded-lg shadow-md mb-8">
    <?php foreach ($strategic_issues as $strategic_issue): ?>
        <div class="border-t-4 border-blue-400 mt-10 pt-6">
            <div class="bg-blue-100 p-4 rounded-lg mb-6">
                <h2 class="text-xl font-bold text-blue-800">ประเด็นยุทธศาสตร์: <?php echo htmlspecialchars($strategic_issue['name']); ?></h2>
            </div>

            <?php foreach ($strategic_issue['missions'] as $mission): ?>
                <div class="bg-yellow-100 p-4 rounded-lg mb-4">
                    <h3 class="text-lg font-semibold text-yellow-800">พันธกิจ: <?php echo htmlspecialchars($mission['name']); ?></h3>
                </div>

                <?php foreach ($mission['fiscal_years'] as $year => $year_data): ?>
                    <div class="bg-gray-200 p-3 rounded-lg mb-3">
                        <h4 class="text-md font-semibold text-gray-700">ปีงบประมาณ: <?php echo htmlspecialchars($year); ?></h4>
                    </div>
                    
                    <?php foreach ($year_data['quarters'] as $quarter => $quarter_data): ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <?php foreach ($quarter_data['kpis'] as $kpi): ?>
                                <div class="p-4 <?php echo $kpi['status_class']; ?> rounded-lg">
                                    <h2 class="text-lg font-semibold text-blue-600"><?php echo htmlspecialchars($kpi['name']); ?></h2>
                                    <p class="text-gray-600"><strong>Description:</strong> <?php echo htmlspecialchars($kpi['description']); ?></p>
                                    <p class="text-gray-600"><strong>Category:</strong> <?php echo htmlspecialchars($kpi['category']); ?></p>
                                    <p class="text-gray-600"><strong>Target:</strong> <?php echo htmlspecialchars($kpi['operation']) . " " . htmlspecialchars($kpi['target']) . " " . htmlspecialchars($kpi['unit']); ?></p>
                                    <p class="text-gray-600"><strong>Actual:</strong> <?php echo htmlspecialchars($kpi['actual']); ?> <?php echo htmlspecialchars($kpi['unit']); ?></p>
                                    <p class="text-gray-600"><strong>Variance:</strong> <?php echo htmlspecialchars($kpi['variance']); ?></p>
                                    <p class="text-gray-600"><strong>Responsible:</strong> <?php echo htmlspecialchars($kpi['responsible_person']); ?></p>
                                    <p class="text-gray-600"><strong>Action Plan:</strong> <?php echo htmlspecialchars($kpi['action_plan']); ?></p>

                                    <div class="w-full bg-gray-300 rounded-full h-4 mt-2 overflow-hidden">
                                        <div class="bg-blue-500 h-4 rounded-full" style="width: <?php echo min(100, $kpi['progress']); ?>%;"></div>
                                    </div>

                                    <p class="text-gray-800 font-semibold mt-2"><?php echo number_format($kpi['progress'], 2); ?>%</p>

                                    <!-- ปุ่ม Edit และ Delete -->
                                    <div class="mt-2 flex gap-2">
                                        <a href="kpi_manage.php?edit=<?php echo $kpi['id']; ?>" class="px-3 py-1 bg-yellow-500 text-white rounded">Edit</a>
                                        <a href="dashboard.php?delete=<?php echo $kpi['id']; ?>" onclick="return confirm('คุณแน่ใจหรือไม่ที่จะลบ KPI นี้?');" class="px-3 py-1 bg-red-500 text-white rounded">Delete</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</div>

    </div>
    </main>
</body>
</html>

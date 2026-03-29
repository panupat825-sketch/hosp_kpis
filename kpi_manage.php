<?php
// kpi_manage.php (วิธี A) — มี Dropdown Unit + ปุ่ม Home
include 'db_connect.php';
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

$edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
$message = "";

/* ---------- MASTER: Strategic Issues (ต้องมี category_id) ---------- */
$strategic_issues_list = array();
$q = mysqli_query($conn, "SELECT id, name, category_id FROM tb_strategic_issues ORDER BY name ASC");
if(!$q) die("Error fetching strategic issues: ".mysqli_error($conn));
while($r = mysqli_fetch_assoc($q)) {
  $strategic_issues_list[] = array(
    'id' => (int)$r['id'],
    'name' => $r['name'],
    'category_id' => isset($r['category_id']) ? (int)$r['category_id'] : null
  );
}

/* ---------- MASTER: Missions (อ้างชื่อ strategic_issue เดิม) ---------- */
$missions_list = array();
$q = mysqli_query($conn, "SELECT id, name, strategic_issue FROM tb_missions ORDER BY name ASC");
if(!$q) die("Error fetching missions: ".mysqli_error($conn));
while($r = mysqli_fetch_assoc($q)) { $missions_list[$r['id']] = $r; }

/* ---------- MASTER: Fiscal Years ---------- */
$fiscal_years_list = array();
$q = mysqli_query($conn, "SELECT year FROM tb_fiscal_years ORDER BY year ASC");
if(!$q) die("Error fetching fiscal years: ".mysqli_error($conn));
while($r = mysqli_fetch_assoc($q)) { $fiscal_years_list[] = $r['year']; }

/* ---------- MASTER: Categories ---------- */
$categories_list = array();
$q = mysqli_query($conn, "SELECT id,name FROM tb_categories ORDER BY name ASC");
if(!$q) die("Error fetching categories: ".mysqli_error($conn));
while($r = mysqli_fetch_assoc($q)) { $categories_list[$r['id']] = $r['name']; }

/* ---------- ค่าเริ่มต้นของแบบฟอร์ม ---------- */
$kpi_data = array(
  'id' => 0,
  'kpi_name' => '',
  'description' => '',
  'category_id' => null,
  'target_value' => '',
  'actual_value' => '',
  'unit' => '',
  'operation' => '=',
  'strategic_issue' => '',
  'mission' => '',
  'fiscal_year' => date('Y') + 543,
  'quarter' => 'Q1',
  'quarter1' => 0, 'quarter2' => 0, 'quarter3' => 0, 'quarter4' => 0,
  'responsible_person' => '',
  'action_plan' => '',
  'root_cause' => '',
  'suggestions' => ''
);

/* ---------- โหลด KPI เดิม (ถ้ามี) ---------- */
if ($edit_id) {
  $res = mysqli_query($conn, "SELECT * FROM tb_kpis WHERE id = ".$edit_id);
  if (!$res) die("Error fetching KPI data: ".mysqli_error($conn));
  if (mysqli_num_rows($res) > 0) {
    $kpi_data = mysqli_fetch_assoc($res);
    $kpi_data['quarter'] = 'Q1';
    if (!empty($kpi_data['quarter2'])) $kpi_data['quarter'] = 'Q2';
    if (!empty($kpi_data['quarter3'])) $kpi_data['quarter'] = 'Q3';
    if (!empty($kpi_data['quarter4'])) $kpi_data['quarter'] = 'Q4';
  } else {
    $message = "KPI not found.";
  }
}

/* ---------- ฟังก์ชันคำนวณสถานะ ---------- */
function calc_status($op, $t, $a){
  if ($t === null || $a === null || $t === '' || $a === '' || !is_numeric($t) || !is_numeric($a)) return 'Warning';
  $t = (float)$t; $a = (float)$a; $op = trim($op);
  if ($op=='<'  || $op=='&lt;')  return ($a <  $t) ? 'Success':'Fail';
  if ($op=='<=' || $op=='&lt;=') return ($a <= $t) ? 'Success':'Fail';
  if ($op=='>'  || $op=='&gt;')  return ($a >  $t) ? 'Success':'Fail';
  if ($op=='>=' || $op=='&gt;=') return ($a >= $t) ? 'Success':'Fail';
  if ($op=='='  || $op=='==')    return ($a == $t) ? 'Success':'Fail';
  return 'Warning';
}

/* ---------- บันทึก (เพิ่ม/แก้ไข) ---------- */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
  $kpi_name = trim(isset($_POST['kpi_name']) ? $_POST['kpi_name'] : '');
  $description = trim(isset($_POST['description']) ? $_POST['description'] : '');
  $category_id = (isset($_POST['category_id']) && $_POST['category_id']!=='') ? intval($_POST['category_id']) : null;
  $target_value = (isset($_POST['target_value']) && $_POST['target_value']!=='') ? (float)$_POST['target_value'] : null;
  $actual_value = (isset($_POST['actual_value']) && $_POST['actual_value']!=='') ? (float)$_POST['actual_value'] : null;
  $unit = trim(isset($_POST['unit']) ? $_POST['unit'] : ''); // ← จะถูกซิงก์จาก dropdown JS
  $operation = trim(isset($_POST['operation']) ? $_POST['operation'] : '=');
  $strategic_issue = trim(isset($_POST['strategic_issue']) ? $_POST['strategic_issue'] : '');
  $mission = trim(isset($_POST['mission']) ? $_POST['mission'] : '');
  $fiscal_year = trim(isset($_POST['fiscal_year']) ? $_POST['fiscal_year'] : '');
  $quarter_sel = isset($_POST['quarter']) ? $_POST['quarter'] : 'Q1';
  $responsible_person = trim(isset($_POST['responsible_person']) ? $_POST['responsible_person'] : '');
  $action_plan = trim(isset($_POST['action_plan']) ? $_POST['action_plan'] : '');
  $root_cause = trim(isset($_POST['root_cause']) ? $_POST['root_cause'] : '');
  $suggestions = trim(isset($_POST['suggestions']) ? $_POST['suggestions'] : '');

  $q1=$q2=$q3=$q4=0;
  if ($quarter_sel==='Q1') $q1=1; elseif ($quarter_sel==='Q2') $q2=1; elseif ($quarter_sel==='Q3') $q3=1; elseif ($quarter_sel==='Q4') $q4=1;

  $variance = (is_numeric($target_value) && is_numeric($actual_value)) ? ($target_value - $actual_value) : null;
  $status = calc_status($operation, $target_value, $actual_value);

  // ตรวจ strategic_issue ต้องอยู่ใน category ที่เลือก (ถ้าระบุ)
  $cat_ok = true;
  if ($category_id !== null && $strategic_issue !== '') {
    $sql_check = "SELECT 1 FROM tb_strategic_issues WHERE name = ? AND category_id = ?";
    if ($stc = mysqli_prepare($conn, $sql_check)) {
      mysqli_stmt_bind_param($stc, "si", $strategic_issue, $category_id);
      mysqli_stmt_execute($stc);
      $rs = mysqli_stmt_get_result($stc);
      $cat_ok = ($rs && mysqli_num_rows($rs) > 0);
      mysqli_stmt_close($stc);
    }
  }
  if (!$cat_ok) { $message = "❌ Strategic Issue ที่เลือกไม่ได้อยู่ใน Category นี้ กรุณาเลือกใหม่"; }

  if ($id > 0) {
    $sql = "UPDATE tb_kpis SET
              kpi_name=?, description=?, category_id=?,
              target_value=?, actual_value=?, variance=?, status=?,
              unit=?, operation=?, strategic_issue=?, mission=?,
              fiscal_year=?, quarter1=?, quarter2=?, quarter3=?, quarter4=?,
              responsible_person=?, action_plan=?, root_cause=?, suggestions=?
            WHERE id=?";
    if ($st = mysqli_prepare($conn, $sql)) {
      mysqli_stmt_bind_param(
        $st,
        "ssidddsssssiiiiissssi",
        $kpi_name, $description, $category_id,
        $target_value, $actual_value, $variance, $status,
        $unit, $operation, $strategic_issue, $mission,
        $fiscal_year, $q1, $q2, $q3, $q4,
        $responsible_person, $action_plan, $root_cause, $suggestions,
        $id
      );
      if (mysqli_stmt_execute($st)) { echo "<script>location.href='dashboard.php?message=KPI Updated Successfully';</script>"; exit(); }
      else { $message = "Error updating KPI: ".mysqli_error($conn); }
      mysqli_stmt_close($st);
    } else { $message = "Prepare failed: ".mysqli_error($conn); }
  } else {
    $sql = "INSERT INTO tb_kpis
            (kpi_name,description,category_id,
             target_value,actual_value,variance,status,
             unit,operation,strategic_issue,mission,
             fiscal_year,quarter1,quarter2,quarter3,quarter4,
             responsible_person,action_plan,root_cause,suggestions)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    if ($st = mysqli_prepare($conn, $sql)) {
      mysqli_stmt_bind_param(
        $st,
        "ssidddsssssiiiiissss",
        $kpi_name, $description, $category_id,
        $target_value, $actual_value, $variance, $status,
        $unit, $operation, $strategic_issue, $mission,
        $fiscal_year, $q1, $q2, $q3, $q4,
        $responsible_person, $action_plan, $root_cause, $suggestions
      );
      if (mysqli_stmt_execute($st)) { echo "<script>location.href='dashboard.php?message=KPI Added Successfully';</script>"; exit(); }
      else { $message = "Error inserting KPI: ".mysqli_error($conn); }
      mysqli_stmt_close($st);
    } else { $message = "Prepare failed: ".mysqli_error($conn); }
  }
}

/* ---------- หน่วยวัดที่ใช้บ่อย (สำหรับ Dropdown) ---------- */
$unit_options = array('%','ครั้ง','คน','ราย','เรื่อง','บาท','วัน','ชั่วโมง','นาที','คะแนน','เตียง','เคส','ครั้ง/เดือน','ครั้ง/ไตรมาส','ครั้ง/ปี','ต่อ 1,000 วันนอน','ต่อ 100 เคส');
$current_unit = isset($kpi_data['unit']) ? trim($kpi_data['unit']) : '';
$unit_in_list = in_array($current_unit, $unit_options, true);
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit KPI</title>
  <link rel="stylesheet" href="css/enterprise-ui.css">
  <script src="https://cdn.tailwindcss.com"></script>

  <script>
  document.addEventListener("DOMContentLoaded", function () {
    var categorySelect  = document.querySelector('select[name="category_id"]');
    var strategicSelect = document.getElementById('strategicIssueSelect');
    var missionSelect   = document.querySelector('select[name="mission"]');

    // --- ฟิลเตอร์ Strategic Issue ตาม Category ---
    function filterStrategicByCategory() {
      var catId = categorySelect ? categorySelect.value : '';
      var visible = 0;
      [].forEach.call(strategicSelect.options, function(opt){
        var optCat = opt.getAttribute('data-category-id');
        if (!optCat || opt.value === '') { opt.style.display='block'; return; }
        var show = (catId === '' || optCat === catId);
        opt.style.display = show ? 'block' : 'none';
        if (show && opt.value !== '') visible++;
      });
      if (visible === 0) {
        [].forEach.call(strategicSelect.options, function(opt){ if (opt.value !== '') opt.style.display='block'; });
      }
      var sel = strategicSelect.selectedOptions[0];
      if (sel && sel.style.display === 'none') strategicSelect.value = '';
      filterMissionsByStrategic();
    }

    // --- ฟิลเตอร์ Mission ตาม Strategic Issue ---
    function filterMissionsByStrategic() {
      var si = strategicSelect.value;
      [].forEach.call(missionSelect.options, function(opt){
        var optSI = opt.getAttribute('data-strategic-issue');
        if (!optSI || opt.value === '' || optSI === si) opt.style.display = 'block';
        else opt.style.display = 'none';
      });
      var sel = missionSelect.selectedOptions[0];
      if (sel && sel.style.display === 'none') missionSelect.value = '';
    }

    if (categorySelect) categorySelect.addEventListener('change', filterStrategicByCategory);
    strategicSelect.addEventListener('change', filterMissionsByStrategic);
    filterStrategicByCategory();

    // --- จัดการ Unit: dropdown + อื่นๆ (กำหนดเอง) ---
    var unitSelect   = document.getElementById('unitSelect');
    var unitCustom   = document.getElementById('unitCustom');
    var unitHidden   = document.getElementById('unitHidden');
    function syncUnit() {
      if (unitSelect.value === '__OTHER__') {
        unitCustom.style.display = 'block';
        unitHidden.value = unitCustom.value;
      } else {
        unitCustom.style.display = 'none';
        unitHidden.value = unitSelect.value;
      }
    }
    unitSelect.addEventListener('change', syncUnit);
    unitCustom.addEventListener('input', function(){ unitHidden.value = unitCustom.value; });
    syncUnit();
  });
  </script>
</head>
<body class="min-h-screen">
  <?php $active_nav = 'template'; include __DIR__ . '/navbar_kpi.php'; ?>
  <main class="enterprise-shell">
  <?php
  kpi_page_header(
    $edit_id ? 'แก้ไขตัวชี้วัด' : 'เพิ่มตัวชี้วัด',
    'หน้าฟอร์มแบบละเอียดสำหรับบริหาร KPI ทั้งหมวดหมู่ หน่วยวัด ประเด็นยุทธศาสตร์ และข้อมูลรับผิดชอบ โดยใช้แถบเมนูแบบเดียวกับหน้าโปรไฟล์',
    array(
      array('label' => 'หน้าแรก', 'href' => 'index.php'),
      array('label' => 'จัดการตัวชี้วัด', 'href' => '')
    ),
    kpi_enterprise_action_link('dashboard.php', 'กลับแดชบอร์ด', 'secondary')
  );
  ?>
  <div class="max-w-4xl mx-auto enterprise-panel p-6 sm:p-8">
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold text-gray-800">เพิ่ม/แก้ไข KPI</h1>
      <a href="index.php" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 transition">🏠 Home</a>
    </div>

    <?php if (!empty($message)) : ?>
      <p class="text-red-600 mb-4"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <form method="POST" class="space-y-4">
      <input type="hidden" name="id" value="<?php echo htmlspecialchars(isset($kpi_data['id'])?$kpi_data['id']:0, ENT_QUOTES, 'UTF-8'); ?>">

      <label class="block font-semibold text-gray-700">KPI Name (ตัวชี้วัด)</label>
      <input type="text" name="kpi_name" value="<?php echo htmlspecialchars((string)$kpi_data['kpi_name'], ENT_QUOTES, 'UTF-8'); ?>" class="w-full p-2 border rounded" required>

      <label class="block font-semibold text-gray-700">Description (รายละเอียดตัวชี้วัด)</label>
      <textarea name="description" class="w-full p-2 border rounded"><?php echo htmlspecialchars((string)$kpi_data['description'], ENT_QUOTES, 'UTF-8'); ?></textarea>

      <label class="block font-semibold text-gray-700">Category (หมวดหมู่)</label>
      <select name="category_id" class="w-full p-2 border rounded">
        <option value="">-- เลือกหมวดหมู่ --</option>
        <?php foreach ($categories_list as $cid => $cname): ?>
          <option value="<?php echo $cid; ?>" <?php echo (isset($kpi_data['category_id']) && $kpi_data['category_id']==$cid)?'selected':''; ?>>
            <?php echo htmlspecialchars($cname, ENT_QUOTES, 'UTF-8'); ?>
          </option>
        <?php endforeach; ?>
      </select>

      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="block font-semibold text-gray-700">Fiscal Year (ปีงบประมาณ)</label>
          <select name="fiscal_year" class="w-full p-2 border rounded" required>
            <option value="">-- เลือกปีงบประมาณ --</option>
            <?php foreach ($fiscal_years_list as $year): ?>
              <option value="<?php echo htmlspecialchars($year, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($kpi_data['fiscal_year']==$year?'selected':''); ?>>
                <?php echo htmlspecialchars($year, ENT_QUOTES, 'UTF-8'); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block font-semibold text-gray-700">Quarter (ไตรมาส)</label>
          <select name="quarter" class="w-full p-2 border rounded">
            <?php foreach (array("Q1","Q2","Q3","Q4") as $q): ?>
              <option value="<?php echo $q; ?>" <?php echo ($kpi_data['quarter']===$q?'selected':''); ?>><?php echo $q; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <label class="block font-semibold text-gray-700">Strategic Issue (ประเด็นยุทธศาสตร์)</label>
      <select name="strategic_issue" class="w-full p-2 border rounded" id="strategicIssueSelect">
        <option value="">-- เลือกประเด็นยุทธศาสตร์ --</option>
        <?php foreach ($strategic_issues_list as $issue): ?>
          <?php
            $attr = '';
            if (!is_null($issue['category_id']) && $issue['category_id']!=='') {
              $attr = ' data-category-id="'.(int)$issue['category_id'].'"';
            }
          ?>
          <option value="<?php echo htmlspecialchars($issue['name'], ENT_QUOTES, 'UTF-8'); ?>"<?php echo $attr; ?>
            <?php echo ($kpi_data['strategic_issue'] === $issue['name']) ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($issue['name'], ENT_QUOTES, 'UTF-8'); ?>
          </option>
        <?php endforeach; ?>
      </select>

      <label class="block font-semibold text-gray-700">Mission (พันธกิจ)</label>
      <select name="mission" id="missionDropdown" class="w-full p-2 border rounded">
        <option value="">-- เลือกพันธกิจ --</option>
        <?php foreach ($missions_list as $id => $mission): ?>
          <option value="<?php echo htmlspecialchars($mission['name'], ENT_QUOTES, 'UTF-8'); ?>"
                  data-strategic-issue="<?php echo htmlspecialchars($mission['strategic_issue'], ENT_QUOTES, 'UTF-8'); ?>"
                  <?php echo ($kpi_data['mission'] === $mission['name']) ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($mission['name'], ENT_QUOTES, 'UTF-8'); ?>
          </option>
        <?php endforeach; ?>
      </select>

      <div class="grid grid-cols-3 gap-4">
        <div>
          <label class="block font-semibold text-gray-700">Operation (เงื่อนไขเปรียบเทียบ)</label>
          <select name="operation" class="w-full p-2 border rounded">
            <?php foreach (array("=","<",">","<=",">=") as $op): ?>
              <option value="<?php echo $op; ?>" <?php echo ($kpi_data['operation']===$op?'selected':''); ?>><?php echo $op; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="block font-semibold text-gray-700">Target Value (เป้าหมาย)</label>
          <input type="number" step="0.01" name="target_value" value="<?php echo htmlspecialchars((string)$kpi_data['target_value'], ENT_QUOTES, 'UTF-8'); ?>" class="w-full p-2 border rounded">
        </div>
        <div>
          <label class="block font-semibold text-gray-700">Unit (หน่วยวัด)</label>
          <?php
            // ตัดสินใจ default ของ dropdown
            $defaultUnitValue = $unit_in_list ? $current_unit : '__OTHER__';
          ?>
          <select id="unitSelect" class="w-full p-2 border rounded">
            <option value="">-- เลือกหน่วย --</option>
            <?php foreach ($unit_options as $u): ?>
              <option value="<?php echo htmlspecialchars($u, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($defaultUnitValue===$u?'selected':''); ?>>
                <?php echo htmlspecialchars($u, ENT_QUOTES, 'UTF-8'); ?>
              </option>
            <?php endforeach; ?>
            <option value="__OTHER__" <?php echo ($defaultUnitValue==='__OTHER__'?'selected':''); ?>>อื่นๆ (กำหนดเอง)</option>
          </select>
          <input type="text" id="unitCustom" placeholder="กรอกหน่วยวัดเอง"
                 class="w-full p-2 border rounded mt-2" style="display: <?php echo $unit_in_list?'none':'block'; ?>"
                 value="<?php echo $unit_in_list ? '' : htmlspecialchars($current_unit, ENT_QUOTES, 'UTF-8'); ?>">
          <!-- hidden ที่ถูกส่งไปกับฟอร์ม -->
          <input type="hidden" name="unit" id="unitHidden" value="<?php echo htmlspecialchars($current_unit, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
      </div>

      <label class="block font-semibold text-gray-700">Actual Value (ค่าจริง)</label>
      <input type="number" step="0.01" name="actual_value" value="<?php echo htmlspecialchars((string)$kpi_data['actual_value'], ENT_QUOTES, 'UTF-8'); ?>" class="w-full p-2 border rounded">

      <label class="block font-semibold text-gray-700">Responsible Person (ผู้รับผิดชอบ)</label>
      <input type="text" name="responsible_person" value="<?php echo htmlspecialchars((string)$kpi_data['responsible_person'], ENT_QUOTES, 'UTF-8'); ?>" class="w-full p-2 border rounded">

      <label class="block font-semibold text-gray-700">Action Plan (แผนปฏิบัติการ)</label>
      <textarea name="action_plan" class="w-full p-2 border rounded"><?php echo htmlspecialchars((string)$kpi_data['action_plan'], ENT_QUOTES, 'UTF-8'); ?></textarea>

      <label class="block font-semibold text-gray-700">Root Cause (สาเหตุที่แท้จริง)</label>
      <textarea name="root_cause" class="w-full p-2 border rounded"><?php echo htmlspecialchars((string)$kpi_data['root_cause'], ENT_QUOTES, 'UTF-8'); ?></textarea>

      <label class="block font-semibold text-gray-700">Suggestions (ข้อเสนอแนะ)</label>
      <textarea name="suggestions" class="w-full p-2 border rounded"><?php echo htmlspecialchars((string)$kpi_data['suggestions'], ENT_QUOTES, 'UTF-8'); ?></textarea>

      <div class="flex gap-4">
        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded">Save</button>
        <a href="dashboard.php" class="px-4 py-2 bg-gray-500 text-white rounded">Cancel</a>
      </div>
    </form>
  </div>
  </main>
</body>
</html>

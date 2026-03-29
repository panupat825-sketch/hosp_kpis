<?php
// kpi_instance_manage.php
require_once __DIR__ . '/db_connect.php';
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }
require_once __DIR__.'/auth.php';
require_login();
require_role(array('admin', 'manager', 'staff'));
$u = current_user();

/* ---------------- Helpers ---------------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function calc_status($op, $t, $a){
  if ($t === '' || $a === '' || !is_numeric($t) || !is_numeric($a)) return 'Warning';
  $t = (float)$t; $a = (float)$a; $op = trim($op);
  if ($op=='<'  || $op=='&lt;')  return ($a <  $t) ? 'Success':'Fail';
  if ($op=='<=' || $op=='&lt;=') return ($a <= $t) ? 'Success':'Fail';
  if ($op=='>'  || $op=='&gt;')  return ($a >  $t) ? 'Success':'Fail';
  if ($op=='>=' || $op=='&gt;=') return ($a >= $t) ? 'Success':'Fail';
  if ($op=='='  || $op=='==')    return ($a == $t) ? 'Success':'Fail';
  return 'Warning';
}
function log_app_error($message, $context){
  error_log('[hosp_kpis] ' . $message . ' | ' . json_encode($context));
}
function normalize_fiscal_year($raw){
  $raw = trim((string)$raw);
  if ($raw === '' || !preg_match('/^\d{4}$/', $raw)) return false;
  $year = (int)$raw;
  if ($year >= 2000 && $year <= 2200) $year += 543;
  if ($year < 2500 || $year > 2800) return false;
  return (string)$year;
}
function detect_current_fiscal_year_be(){
  $year_ce = (int)date('Y');
  if ((int)date('n') >= 10) $year_ce++;
  return (string)($year_ce + 543);
}
function normalize_decimal_input($raw){
  $raw = trim((string)$raw);
  if ($raw === '') return '';
  if (!is_numeric($raw)) return false;
  return (float)$raw;
}

/* ---------------- modal or full page ---------------- */
$is_modal = (isset($_GET['modal']) && $_GET['modal'] == '1');

/* ---------------- Load master: KPI templates (รวมชื่อ Dept / Team) ---------------- */
$templates = array();

$sql_tpl = "
  SELECT
    t.id,
    t.kpi_name,
    t.description,
    t.strategic_issue,
    t.mission,
    t.category_id,
    t.agg_type,
    t.strategy_id,
    t.department_id,
    t.workgroup_id,
    t.responsible_user_id,
    COALESCE(c.name, 'N/A') AS category_name,
    COALESCE(s.name, '—')   AS strategy_name,
    GROUP_CONCAT(DISTINCT d.department_name ORDER BY d.department_name SEPARATOR ', ') AS dept_names,
    GROUP_CONCAT(DISTINCT tm.name_th       ORDER BY tm.name_th       SEPARATOR ', ') AS team_names
  FROM tb_kpi_templates t
  LEFT JOIN tb_categories  c  ON c.id = t.category_id
  LEFT JOIN tb_strategies  s  ON s.id = t.strategy_id
  LEFT JOIN tb_departments d  ON FIND_IN_SET(d.id, t.department_id)
  LEFT JOIN tb_teams       tm ON FIND_IN_SET(tm.id, t.workgroup_id)
  GROUP BY
    t.id, t.kpi_name, t.description, t.strategic_issue, t.mission,
    t.category_id, t.agg_type, t.strategy_id,
    t.department_id, t.workgroup_id, t.responsible_user_id,
    c.name, s.name
  ORDER BY t.kpi_name ASC
";
if ($res = mysqli_query($conn, $sql_tpl)) {
  while ($r = mysqli_fetch_assoc($res)) {
    if (!isset($r['agg_type']) || $r['agg_type'] === '') {
      $r['agg_type'] = 'AVG';
    }
    $templates[] = $r;
  }
  mysqli_free_result($res);
}

/* ---------------- Load master: Users / Departments / Teams ---------------- */
$users = array();
$uq = mysqli_query($conn, "SELECT id, fullname FROM tb_users ORDER BY fullname ASC");
if ($uq) {
  while ($r = mysqli_fetch_assoc($uq)) {
    $users[] = $r;
  }
  mysqli_free_result($uq);
}

$departments = array();
$dq = mysqli_query($conn, "SELECT id, department_name FROM tb_departments ORDER BY department_name ASC");
if ($dq) {
  while ($r = mysqli_fetch_assoc($dq)) {
    $departments[] = $r;
  }
  mysqli_free_result($dq);
}

$teams = array();
$tq = mysqli_query($conn, "SELECT id, name_th FROM tb_teams ORDER BY name_th ASC");
if ($tq) {
  while ($r = mysqli_fetch_assoc($tq)) {
    $teams[] = $r;
  }
  mysqli_free_result($tq);
}

/* ---------------- Defaults & load for edit ---------------- */
$instance_id = 0;
if (isset($_GET['edit_instance']))      $instance_id = (int)$_GET['edit_instance'];
elseif (isset($_GET['edit']))           $instance_id = (int)$_GET['edit'];

$message = '';
$instance = array(
  'id'=>0,'template_id'=>'','fiscal_year'=>'','quarter'=>'Q1',
  'quarter1'=>0,'quarter2'=>0,'quarter3'=>0,'quarter4'=>0,
  'operation'=>'=','target_value'=>'','unit'=>'','actual_value'=>'',
  'variance'=>'','status'=>'Warning','responsible_person'=>'',
  'department_id'=>'','workgroup_id'=>'',
  'action_plan'=>'','root_cause'=>'','suggestions'=>''
);

$is_modal = (isset($_GET['modal']) && $_GET['modal'] === '1');

/* --- ค่าที่ส่งมาจาก dashboard ตอนกดเพิ่มไตรมาส --- */
$from_tpl     = isset($_GET['template_id']) ? (int)$_GET['template_id'] : 0;
$from_year    = isset($_GET['fiscal_year']) ? trim($_GET['fiscal_year']) : '';
$from_quarter = isset($_GET['quarter']) ? strtoupper(trim($_GET['quarter'])) : '';
$normalized_from_year = normalize_fiscal_year($from_year);

/* ---------- กรณีเพิ่มใหม่ (ไม่มี instance_id) ตั้งค่าเริ่มต้นจาก dashboard ---------- */
if ($instance_id <= 0) {

  if ($from_tpl > 0) {
    $instance['template_id'] = $from_tpl;
  }
  if ($normalized_from_year !== false) {
    $instance['fiscal_year'] = $normalized_from_year;
  } elseif ($from_year === '') {
    $instance['fiscal_year'] = detect_current_fiscal_year_be();
  }
  if (in_array($from_quarter, array('Q1','Q2','Q3','Q4'), true)) {
    $instance['quarter']  = $from_quarter;
    $instance['quarter1'] = $instance['quarter2'] = $instance['quarter3'] = $instance['quarter4'] = 0;
    if     ($from_quarter === 'Q1') $instance['quarter1'] = 1;
    elseif ($from_quarter === 'Q2') $instance['quarter2'] = 1;
    elseif ($from_quarter === 'Q3') $instance['quarter3'] = 1;
    elseif ($from_quarter === 'Q4') $instance['quarter4'] = 1;
  }

  // ถ้ากำลังเพิ่ม Q2–Q4 ให้ลองดึง operation/target/unit จาก Q1
  if ($from_tpl > 0 && $normalized_from_year !== false && in_array($from_quarter, array('Q2','Q3','Q4'), true)) {
    $sql_base = "
      SELECT operation, target_value, unit
      FROM tb_kpi_instances
      WHERE template_id = ? AND fiscal_year = ? AND quarter1 = 1
      LIMIT 1
    ";
    if ($stb = mysqli_prepare($conn, $sql_base)) {
      mysqli_stmt_bind_param($stb, "is", $from_tpl, $normalized_from_year);
      mysqli_stmt_execute($stb);
      mysqli_stmt_bind_result($stb, $b_op, $b_t, $b_unit);
      if (mysqli_stmt_fetch($stb)) {
        if ($instance['operation'] === '=') {
          $instance['operation'] = $b_op;
        }
        if ($instance['target_value'] === '' || $instance['target_value'] == 0) {
          $instance['target_value'] = $b_t;
        }
        if ($instance['unit'] === '') {
          $instance['unit'] = $b_unit;
        }
      }
      mysqli_stmt_close($stb);
    }
  }
}

/* ---------- โหลด instance กรณีแก้ไข ---------- */
if ($instance_id > 0) {
  if ($st = mysqli_prepare($conn, "SELECT * FROM tb_kpi_instances WHERE id=? LIMIT 1")) {
    mysqli_stmt_bind_param($st, "i", $instance_id);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    if ($res && mysqli_num_rows($res)>0) {
      $instance = mysqli_fetch_assoc($res);
      mysqli_free_result($res);
      $instance['quarter'] = 'Q1';
      if (!empty($instance['quarter2'])) $instance['quarter']='Q2';
      if (!empty($instance['quarter3'])) $instance['quarter']='Q3';
      if (!empty($instance['quarter4'])) $instance['quarter']='Q4';
    } else {
      $message='Instance not found.';
    }
    mysqli_stmt_close($st);
  } else {
    log_app_error('KPI instance load prepare failed', array('instance_id' => $instance_id, 'db_error' => mysqli_error($conn)));
    $message = 'Unable to load this KPI record right now.';
  }
}

/* ---------------- Save (Insert/Update) ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_post_csrf();

  $is_modal_request = $is_modal || (isset($_POST['modal']) && $_POST['modal'] === '1');

  $id              = isset($_POST['id']) ? (int)$_POST['id'] : 0;
  $template_id     = isset($_POST['template_id']) ? (int)$_POST['template_id'] : 0;
  $fiscal_year_raw = isset($_POST['fiscal_year']) ? trim($_POST['fiscal_year']) : '';
  $fiscal_year     = normalize_fiscal_year($fiscal_year_raw);
  $quarter_sel     = isset($_POST['quarter']) ? strtoupper(trim($_POST['quarter'])) : 'Q1';
  $operation       = isset($_POST['operation']) ? trim($_POST['operation']) : '=';
  $target_value    = normalize_decimal_input(isset($_POST['target_value']) ? $_POST['target_value'] : '');
  $unit            = isset($_POST['unit']) ? trim($_POST['unit']) : '';
  $actual_value    = normalize_decimal_input(isset($_POST['actual_value']) ? $_POST['actual_value'] : '');

  // ผู้รับผิดชอบ (เลือกชื่อ + พิมพ์เองได้)
  $responsible   = isset($_POST['responsible_person']) ? trim($_POST['responsible_person']) : '';

  // หน่วยงาน / ทีม ที่รับผิดชอบ (เก็บเป็น CSV id)
  $dept_ids = isset($_POST['department_ids']) && is_array($_POST['department_ids'])
                ? $_POST['department_ids'] : array();
  $team_ids = isset($_POST['team_ids']) && is_array($_POST['team_ids'])
                ? $_POST['team_ids'] : array();

  $dept_ids = array_map('intval', $dept_ids);
  $team_ids = array_map('intval', $team_ids);

  $department_csv = implode(',', $dept_ids);
  $team_csv       = implode(',', $team_ids);

  $action_plan   = isset($_POST['action_plan']) ? trim($_POST['action_plan']) : '';
  $root_cause    = isset($_POST['root_cause']) ? trim($_POST['root_cause']) : '';
  $suggestions   = isset($_POST['suggestions']) ? trim($_POST['suggestions']) : '';

  $q1=$q2=$q3=$q4=0;
  if     ($quarter_sel==='Q1') $q1=1;
  elseif ($quarter_sel==='Q2') $q2=1;
  elseif ($quarter_sel==='Q3') $q3=1;
  elseif ($quarter_sel==='Q4') $q4=1;

  if ($template_id <= 0) {
    $message = 'Please select a KPI.';
  } elseif ($fiscal_year === false) {
    $message = 'Please enter a valid fiscal year (BE 2500-2800 or CE 2000-2200).';
  } elseif (!in_array($quarter_sel, array('Q1','Q2','Q3','Q4'), true)) {
    $message = 'Please select a valid quarter.';
  } elseif ($target_value === false) {
    $message = 'Target value must be a valid number.';
  } elseif ($actual_value === false) {
    $message = 'Actual value must be a valid number.';
  }

  $variance = (is_numeric($target_value) && is_numeric($actual_value))
      ? (float)$target_value - (float)$actual_value : null;
  $status   = calc_status($operation, $target_value, $actual_value);

  $tv = ($target_value === '' ? null : (string)$target_value);
  $av = ($actual_value === '' ? null : (string)$actual_value);
  $vv = ($variance     === null ? null : (string)$variance);

  if ($message === '' && $id > 0) {
    // UPDATE
    $sql = "UPDATE tb_kpi_instances SET
              template_id=?, fiscal_year=?,
              quarter1=?, quarter2=?, quarter3=?, quarter4=?,
              operation=?, target_value=?, unit=?, actual_value=?, variance=?, status=?,
              responsible_person=?, department_id=?, workgroup_id=?, action_plan=?, root_cause=?, suggestions=?
            WHERE id=?";
    if ($st = mysqli_prepare($conn, $sql)) {
      mysqli_stmt_bind_param(
        $st,
        "isiiiissssssssssssi",
        $template_id, $fiscal_year,
        $q1, $q2, $q3, $q4,
        $operation, $tv, $unit, $av, $vv, $status,
        $responsible, $department_csv, $team_csv, $action_plan, $root_cause, $suggestions,
        $id
      );
      if (mysqli_stmt_execute($st)) {
        mysqli_stmt_close($st);
        if ($is_modal_request) {
          echo "<script>
                  if (window.top && window.top !== window) {
                    window.top.location.reload();
                  } else {
                    window.location.href = 'dashboard.php?message=Instance Updated';
                  }
                </script>";
        } else {
          echo "<script>location.href='dashboard.php?message=Instance Updated';</script>";
        }
        exit();
      } else {
        log_app_error('KPI instance update failed', array('instance_id' => $id, 'template_id' => $template_id, 'db_error' => mysqli_error($conn)));
        $message = 'Unable to save this KPI record right now. Please try again.';
      }
      mysqli_stmt_close($st);
    } else {
      log_app_error('KPI instance update prepare failed', array('instance_id' => $id, 'template_id' => $template_id, 'db_error' => mysqli_error($conn)));
      $message = 'Unable to save this KPI record right now. Please try again.';
    }
  } elseif ($message === '') {
    // INSERT
    $sql = "INSERT INTO tb_kpi_instances
            (template_id, fiscal_year,
             quarter1, quarter2, quarter3, quarter4,
             operation, target_value, unit, actual_value, variance, status,
             responsible_person, department_id, workgroup_id, action_plan, root_cause, suggestions)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
    if ($st = mysqli_prepare($conn, $sql)) {
      mysqli_stmt_bind_param(
        $st,
        "isiiiissssssssssss",
        $template_id, $fiscal_year,
        $q1, $q2, $q3, $q4,
        $operation, $tv, $unit, $av, $vv, $status,
        $responsible, $department_csv, $team_csv, $action_plan, $root_cause, $suggestions
      );
      if (mysqli_stmt_execute($st)) {
        mysqli_stmt_close($st);
        if ($is_modal_request) {
          echo "<script>
                  if (window.top && window.top !== window) {
                    window.top.location.reload();
                  } else {
                    window.location.href = 'dashboard.php?message=Instance Added';
                  }
                </script>";
        } else {
          echo "<script>location.href='dashboard.php?message=Instance Added';</script>";
        }
        exit();
      } else {
        log_app_error('KPI instance insert failed', array('template_id' => $template_id, 'db_error' => mysqli_error($conn)));
        $message = 'Unable to save this KPI record right now. Please try again.';
      }
      mysqli_stmt_close($st);
    } else {
      log_app_error('KPI instance insert prepare failed', array('template_id' => $template_id, 'db_error' => mysqli_error($conn)));
      $message = 'Unable to save this KPI record right now. Please try again.';
    }
  }

  // keep values on error
  $instance = array(
    'id'=>$id,'template_id'=>$template_id,'fiscal_year'=>($fiscal_year === false ? $fiscal_year_raw : $fiscal_year),'quarter'=>$quarter_sel,
    'quarter1'=>$q1,'quarter2'=>$q2,'quarter3'=>$q3,'quarter4'=>$q4,
    'operation'=>$operation,'target_value'=>$target_value,'unit'=>$unit,'actual_value'=>$actual_value,
    'variance'=>$variance,'status'=>$status,'responsible_person'=>$responsible,
    'department_id'=>$department_csv,'workgroup_id'=>$team_csv,
    'action_plan'=>$action_plan,'root_cause'=>$root_cause,'suggestions'=>$suggestions
  );
}

/* ---------------- selected template for summary card ---------------- */
$selected_tpl = null;
if (!empty($instance['template_id'])) {
  foreach ($templates as $t) {
    if ((int)$t['id'] === (int)$instance['template_id']) { $selected_tpl = $t; break; }
  }
}

$tplDeptText = $selected_tpl && !empty($selected_tpl['dept_names']) ? $selected_tpl['dept_names'] : '';
$tplTeamText = $selected_tpl && !empty($selected_tpl['team_names']) ? $selected_tpl['team_names'] : '';

/* สำหรับติ๊ก checkbox ตอนโหลดฟอร์ม */
$instDeptIds = array();
$instTeamIds = array();
if (!empty($instance['department_id'])) {
  foreach (explode(',', $instance['department_id']) as $id) {
    $id = (int)trim($id);
    if ($id > 0) $instDeptIds[] = $id;
  }
}
if (!empty($instance['workgroup_id'])) {
  foreach (explode(',', $instance['workgroup_id']) as $id) {
    $id = (int)trim($id);
    if ($id > 0) $instTeamIds[] = $id;
  }
}

$unit_options = array('%','ครั้ง','ราย','คน/วัน','นาที','ชั่วโมง','วัน','ครั้ง/1,000 patient-days','อัตราส่วน','คะแนน','บาท','เตียงวัน');
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo $instance_id>0 ? 'แก้ไขบันทึกผลตัวชี้วัด (KPI Instance)' : 'บันทึกผลตัวชี้วัดใหม่ (KPI Instance)'; ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="<?php echo $is_modal ? 'bg-white' : 'bg-slate-100 min-h-screen'; ?>">

<?php
  if (!$is_modal) {
    $active_nav = 'instance';
    include __DIR__ . '/navbar_kpi.php';
  }
?>

<div class="<?php echo $is_modal ? 'w-full p-4' : 'w-full bg-white/95 p-6 mt-4 rounded-3xl shadow-xl shadow-slate-200/70 border border-slate-200'; ?>">
  <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-5 pb-4 border-b border-slate-200">
    <div>
      <h1 class="text-xl md:text-2xl font-semibold text-gray-900">
        <?php echo $instance_id>0 ? 'แก้ไขบันทึกผลตัวชี้วัด (KPI Instance)' : 'บันทึกผลตัวชี้วัดใหม่ (KPI Instance)'; ?>
      </h1>
      <p class="text-sm text-gray-500 mt-1">
        แบบฟอร์มสำหรับบันทึกผลการดำเนินงานตามตัวชี้วัดของปีงบประมาณและไตรมาสที่กำหนด
      </p>
    </div>

    <?php if (!$is_modal): ?>
      <div class="flex flex-wrap gap-2">
        <a href="dashboard.php" class="px-4 py-2 bg-blue-700 text-white rounded-xl hover:bg-blue-800 text-sm shadow-sm shadow-blue-200">
          กลับแดชบอร์ด
        </a>
        <a href="kpi_table.php" class="px-4 py-2 bg-slate-800 text-white rounded-xl hover:bg-slate-900 text-sm shadow-sm shadow-slate-200">
          ตารางข้อมูลตัวชี้วัด
        </a>
      </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($message)): ?>
    <div class="mb-5 p-4 bg-red-50 border border-red-300 text-red-700 rounded-2xl text-sm shadow-sm shadow-red-100">
      <?php echo h($message); ?>
    </div>
  <?php endif; ?>

  <form method="post" class="space-y-5">
    <input type="hidden" name="id" value="<?php echo (int)$instance_id; ?>">
    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
    <?php if ($is_modal): ?>
      <input type="hidden" name="modal" value="1">
    <?php endif; ?>

    <!-- Template Search -->
    <label class="block font-semibold text-gray-800">เลือกแม่แบบตัวชี้วัด (Template)</label>
    <input id="tplSearch" type="text" class="w-full p-3 border border-slate-300 rounded-2xl mb-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200"
           placeholder="พิมพ์ชื่อ KPI / ประเด็นยุทธศาสตร์ / พันธกิจ / กลยุทธ์ / หมวดหมู่ เพื่อค้นหา..."
           value="<?php echo $selected_tpl ? h($selected_tpl['kpi_name']) : ''; ?>">

    <div id="tplResults" class="border border-slate-200 rounded-2xl hidden bg-white shadow-xl shadow-slate-200/70 max-h-64 overflow-y-auto text-sm"></div>

    <select id="template_id" name="template_id" class="hidden">
      <option value="">-- เลือกแม่แบบ --</option>
      <?php foreach($templates as $t):
        $search = strtolower(
          $t['kpi_name'].' '.$t['strategy_name'].' '.$t['mission'].' '.$t['strategic_issue'].' '.
          $t['category_name'].' '.$t['agg_type'].' '.$t['description'].' '.$t['dept_names'].' '.$t['team_names']
        );
      ?>
        <option
          value="<?php echo (int)$t['id']; ?>"
          <?php echo ((int)$instance['template_id']===(int)$t['id'])?'selected':''; ?>
          data-search="<?php echo h($search); ?>"
          data-kpi="<?php echo h($t['kpi_name']); ?>"
          data-issue="<?php echo h($t['strategic_issue']); ?>"
          data-mission="<?php echo h($t['mission']); ?>"
          data-strategy="<?php echo h($t['strategy_name']); ?>"
          data-cat="<?php echo h($t['category_name']); ?>"
          data-agg="<?php echo h($t['agg_type']); ?>"
          data-desc="<?php echo h($t['description']); ?>"
          data-depts="<?php echo h($t['dept_names']); ?>"
          data-teams="<?php echo h($t['team_names']); ?>"
        >
          <?php
            echo h($t['kpi_name']).' — '.
                 h($t['strategic_issue']).' › '.
                 h($t['mission']).' › '.
                 h($t['strategy_name']).' › '.
                 h($t['category_name']);
          ?>
        </option>
      <?php endforeach; ?>
    </select>

    <!-- Summary Card -->
    <div id="tplCard" class="mt-3 rounded-xl border-2 border-blue-600 bg-blue-50 p-4 shadow-sm <?php echo $selected_tpl?'':'hidden'; ?>">
      <div class="flex items-center gap-2 text-blue-800">
        <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-600 text-white text-sm font-bold">KPI</span>
        <h2 id="cardKpi" class="text-xl md:text-2xl font-extrabold tracking-tight">
          <?php echo $selected_tpl ? h($selected_tpl['kpi_name']) : ''; ?>
        </h2>
      </div>
      <ul class="mt-3 flex flex-wrap gap-2">
        <li class="px-3 py-1 rounded-full bg-white border border-blue-300 text-blue-700 text-xs md:text-sm font-semibold">
          Strategic: <span id="cardIssue"><?php echo $selected_tpl ? h($selected_tpl['strategic_issue']) : ''; ?></span>
        </li>
        <li class="px-3 py-1 rounded-full bg-white border border-indigo-300 text-indigo-700 text-xs md:text-sm font-semibold">
          Mission: <span id="cardMission"><?php echo $selected_tpl ? h($selected_tpl['mission']) : ''; ?></span>
        </li>
        <li class="px-3 py-1 rounded-full bg-white border border-pink-300 text-pink-700 text-xs md:text-sm font-semibold">
          Strategy: <span id="cardStrategy"><?php echo $selected_tpl ? h($selected_tpl['strategy_name']) : ''; ?></span>
        </li>
        <li class="px-3 py-1 rounded-full bg-white border border-emerald-300 text-emerald-700 text-xs md:text-sm font-semibold">
          Category: <span id="cardCat"><?php echo $selected_tpl ? h($selected_tpl['category_name']) : 'N/A'; ?></span>
        </li>
        <li class="px-3 py-1 rounded-full bg-white border border-orange-300 text-orange-700 text-xs md:text-sm font-semibold">
          Mode: <span id="cardAgg">
            <?php
              if ($selected_tpl) {
                $agg = isset($selected_tpl['agg_type']) ? $selected_tpl['agg_type'] : 'AVG';
                echo ($agg==='SUM') ? 'สะสมทั้งปี (SUM)' : 'เฉลี่ยรายไตรมาส (AVG)';
              }
            ?>
          </span>
        </li>
        <li class="px-3 py-1 rounded-full bg-white border border-sky-300 text-sky-700 text-xs md:text-sm font-semibold">
          Dept: <span id="cardDept"><?php echo h($tplDeptText); ?></span>
        </li>
        <li class="px-3 py-1 rounded-full bg-white border border-lime-300 text-lime-700 text-xs md:text-sm font-semibold">
          Team: <span id="cardTeam"><?php echo h($tplTeamText); ?></span>
        </li>
      </ul>
      <div class="mt-4">
        <div class="text-xs uppercase tracking-wider text-gray-500 font-semibold">Description</div>
        <p id="cardDesc" class="mt-1 text-base leading-relaxed font-medium bg-white/70 rounded-lg p-3 border border-gray-200">
          <?php echo $selected_tpl ? h($selected_tpl['description']) : '—'; ?>
        </p>
      </div>
    </div>

    <!-- Year / Quarter -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2">
      <div>
        <label class="block font-semibold text-gray-800">ปีงบประมาณ (Fiscal Year)</label>
        <input type="text" name="fiscal_year" class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200"
               value="<?php echo h($instance['fiscal_year']); ?>" placeholder="เช่น 2568 หรือ 2025"
               pattern="[0-9]{4}" inputmode="numeric"
               title="กรอกปี 4 หลัก เช่น 2568 หรือ 2025" required>
      </div>
      <div>
        <label class="block font-semibold text-gray-800">ไตรมาส (Quarter)</label>
        <select name="quarter" class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200">
          <?php foreach (array('Q1','Q2','Q3','Q4') as $q): ?>
            <option value="<?php echo $q; ?>" <?php echo ($instance['quarter']===$q?'selected':''); ?>><?php echo $q; ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <!-- Target / Unit -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div>
        <label class="block font-semibold text-gray-800">เงื่อนไข (Operation)</label>
        <select name="operation" class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200">
          <?php foreach (array('=','<','>','<=','>=') as $op): ?>
            <option value="<?php echo $op; ?>" <?php echo ($instance['operation']===$op?'selected':''); ?>><?php echo $op; ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block font-semibold text-gray-800">ค่าเป้าหมาย (Target Value)</label>
        <input type="number" step="0.01" name="target_value" class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200"
               value="<?php echo h($instance['target_value']); ?>">
      </div>
      <div>
        <label class="block font-semibold text-gray-800">หน่วยวัด (Unit)</label>
        <input list="unitList" type="text" name="unit" class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200"
               value="<?php echo h($instance['unit']); ?>" placeholder="เช่น %, นาที, ครั้ง">
        <datalist id="unitList">
          <?php foreach($unit_options as $uopt): ?>
            <option value="<?php echo h($uopt); ?>"></option>
          <?php endforeach; ?>
        </datalist>
      </div>
    </div>

    <!-- Actual -->
    <div>
      <label class="block font-semibold text-gray-800">ค่าจริง (Actual Value)</label>
      <input type="number" step="0.01" name="actual_value" class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200"
             value="<?php echo h($instance['actual_value']); ?>">
    </div>

    <!-- Responsible: เลือกชื่อ + ติ๊กหน่วยงาน/ทีม -->
    <div>
      <label class="block font-semibold text-gray-800">ผู้รับผิดชอบ (Responsible Person)</label>

      <!-- auto-suggest จาก tb_users -->
      <div class="mt-1">
        <input list="userList"
               name="responsible_person"
               class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200"
               value="<?php echo h($instance['responsible_person']); ?>"
               placeholder="เลือกชื่อจากรายชื่อ หรือพิมพ์เองได้">
        <datalist id="userList">
          <?php foreach ($users as $ur): ?>
            <option value="<?php echo h($ur['fullname']); ?>"></option>
          <?php endforeach; ?>
        </datalist>
        <p class="text-xs text-gray-500 mt-1">
          ถ้าชื่อไม่อยู่ในรายการ สามารถพิมพ์ชื่อ–นามสกุลเองได้
        </p>
      </div>

      <!-- ป้ายอ้างอิงจากแม่แบบ -->
      <div class="mt-2 flex flex-wrap gap-2 text-xs md:text-sm">
        <?php if ($tplDeptText): ?>
          <span class="inline-flex items-center px-3 py-1 rounded-full bg-sky-50 border border-sky-300 text-sky-800">
            หน่วยงานตามแม่แบบ: <?php echo h($tplDeptText); ?>
          </span>
        <?php endif; ?>
        <?php if ($tplTeamText): ?>
          <span class="inline-flex items-center px-3 py-1 rounded-full bg-lime-50 border border-lime-300 text-lime-800">
            ทีมตามแม่แบบ: <?php echo h($tplTeamText); ?>
          </span>
        <?php endif; ?>
      </div>

      <!-- ติ๊กหน่วยงาน / ทีม -->
      <div class="mt-3 border rounded-md bg-slate-50 p-3">
        <p class="text-xs text-gray-500 mb-2">
          เลือกหน่วยงานและทีมที่รับผิดชอบตัวชี้วัดสำหรับปีงบประมาณและไตรมาสนี้ (เลือกได้หลายรายการ)
        </p>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <div class="font-semibold text-sm mb-1">หน่วยงาน (Department)</div>
            <div class="max-h-40 overflow-y-auto pr-1">
              <?php foreach ($departments as $d): ?>
                <?php $did = (int)$d['id']; ?>
                <label class="flex items-center gap-2 text-xs mb-1">
                  <input type="checkbox" name="department_ids[]"
                         value="<?php echo $did; ?>"
                         <?php echo in_array($did, $instDeptIds, true) ? 'checked' : ''; ?>>
                  <span><?php echo h($d['department_name']); ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div>
            <div class="font-semibold text-sm mb-1">ทีม / คณะทำงาน (Team / Workgroup)</div>
            <div class="max-h-40 overflow-y-auto pr-1">
              <?php foreach ($teams as $tm): ?>
                <?php $tid = (int)$tm['id']; ?>
                <label class="flex items-center gap-2 text-xs mb-1">
                  <input type="checkbox" name="team_ids[]"
                         value="<?php echo $tid; ?>"
                         <?php echo in_array($tid, $instTeamIds, true) ? 'checked' : ''; ?>>
                  <span><?php echo h($tm['name_th']); ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <p class="text-xs text-gray-500 mt-2">
          * สามารถกำหนดผู้รับผิดชอบและหน่วยงานแตกต่างกันในแต่ละปี/ไตรมาสได้ โดยจะไม่กระทบแม่แบบ
        </p>
      </div>
    </div>

    <!-- Action plan / Root cause / Suggestions -->
    <div>
      <label class="block font-semibold text-gray-800">แผนปฏิบัติการ (Action Plan)</label>
      <textarea name="action_plan" class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200" rows="3"><?php echo h($instance['action_plan']); ?></textarea>
    </div>

    <div>
      <label class="block font-semibold text-gray-800">สาเหตุที่แท้จริง (Root Cause)</label>
      <textarea name="root_cause" class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200" rows="3"><?php echo h($instance['root_cause']); ?></textarea>
    </div>

    <div>
      <label class="block font-semibold text-gray-800">ข้อเสนอแนะ / แนวทางปรับปรุง (Suggestions)</label>
      <textarea name="suggestions" class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200" rows="3"><?php echo h($instance['suggestions']); ?></textarea>
    </div>

    <div class="flex gap-3 pt-2">
      <button type="submit" class="px-4 py-2 bg-emerald-600 text-white rounded-xl hover:bg-emerald-700 text-sm shadow-sm shadow-emerald-100">
        บันทึกผลตัวชี้วัด
      </button>

      <?php if (!$is_modal): ?>
        <a href="dashboard.php" class="px-4 py-2 bg-slate-700 text-white rounded-xl hover:bg-slate-800 text-sm shadow-sm shadow-slate-200">
          ยกเลิก / กลับแดชบอร์ด
        </a>
      <?php endif; ?>
    </div>
  </form>
</div>

<script>
(function(){
  var searchInput = document.getElementById('tplSearch');
  var resultsBox  = document.getElementById('tplResults');
  var sel         = document.getElementById('template_id');

  var card   = document.getElementById('tplCard'),
      elK    = document.getElementById('cardKpi'),
      elI    = document.getElementById('cardIssue'),
      elM    = document.getElementById('cardMission'),
      elS    = document.getElementById('cardStrategy'),
      elC    = document.getElementById('cardCat'),
      elA    = document.getElementById('cardAgg'),
      elD    = document.getElementById('cardDesc'),
      elDept = document.getElementById('cardDept'),
      elTeam = document.getElementById('cardTeam');

  var TPL = [];
  (function buildIndex(){
    for (var i=0;i<sel.options.length;i++){
      var o = sel.options[i];
      if (!o.value) continue;
      TPL.push({
        id: o.value,
        label: o.text,
        search: (o.getAttribute('data-search')||''),
        kpi: o.getAttribute('data-kpi')||'',
        issue: o.getAttribute('data-issue')||'',
        mission: o.getAttribute('data-mission')||'',
        strategy: o.getAttribute('data-strategy')||'',
        cat: o.getAttribute('data-cat')||'',
        agg: o.getAttribute('data-agg')||'',
        desc: o.getAttribute('data-desc')||'',
        depts: o.getAttribute('data-depts')||'',
        teams: o.getAttribute('data-teams')||''
      });
    }
  })();

  function aggText(agg){
    if (agg === 'SUM') return 'สะสมทั้งปี (SUM)';
    if (agg === 'AVG') return 'เฉลี่ยรายไตรมาส (AVG)';
    return agg || '';
  }

  function updateCardFromOption(opt){
    if (!opt){
      if (card) card.classList.add('hidden');
      return;
    }
    if (elK)    elK.textContent    = opt.getAttribute('data-kpi')||'';
    if (elI)    elI.textContent    = opt.getAttribute('data-issue')||'';
    if (elM)    elM.textContent    = opt.getAttribute('data-mission')||'';
    if (elS)    elS.textContent    = opt.getAttribute('data-strategy')||'';
    if (elC)    elC.textContent    = opt.getAttribute('data-cat')||'';
    if (elD)    elD.textContent    = opt.getAttribute('data-desc')||'';
    if (elA)    elA.textContent    = aggText(opt.getAttribute('data-agg')||'');

    var depts = opt.getAttribute('data-depts')||'';
    var teams = opt.getAttribute('data-teams')||'';
    if (elDept) elDept.textContent = depts;
    if (elTeam) elTeam.textContent = teams;

    if (card) card.classList.remove('hidden');
  }

  function findById(id){
    for (var i=0;i<TPL.length;i++) if (TPL[i].id==id) return TPL[i];
    return null;
  }

  function selectTemplateById(id){
    for (var i=0;i<sel.options.length;i++){
      if (sel.options[i].value==id){
        sel.selectedIndex=i;
        updateCardFromOption(sel.options[i]);
        break;
      }
    }
  }

  function escapeHtml(s){
    return String(s).replace(/[&<>"']/g,function(m){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":"&#39;"}[m]);
    });
  }

  function renderResults(list){
    if (!resultsBox) return;
    if (!list || !list.length){
      resultsBox.innerHTML = '<div class="p-3 text-gray-500">ไม่พบแม่แบบที่ตรงกับคำค้น</div>';
      resultsBox.classList.remove('hidden');
      return;
    }
    var html='';
    for (var i=0;i<list.length;i++){
      var r=list[i];
      html += '<button type="button" data-id="'+r.id+'" class="w-full text-left p-3 hover:bg-gray-100 border-b">'+
                '<div class="font-medium text-gray-900">'+escapeHtml(r.kpi||r.label)+'</div>'+
                '<div class="text-sm text-gray-600">'+
                  escapeHtml(r.issue)+' › '+escapeHtml(r.mission)+
                  (r.strategy ? ' › '+escapeHtml(r.strategy) : '')+
                  ' › '+escapeHtml(r.cat) +
                '</div>'+
                (r.depts ? '<div class="text-xs text-sky-700 mt-0.5">Dept: '+escapeHtml(r.depts)+'</div>' : '')+
                (r.teams ? '<div class="text-xs text-lime-700 mt-0.5">Team: '+escapeHtml(r.teams)+'</div>' : '')+
                '<div class="text-xs text-orange-700 mt-0.5">'+escapeHtml(aggText(r.agg))+'</div>'+
                (r.desc?'<div class="text-xs text-gray-500 mt-1">'+escapeHtml(r.desc)+'</div>':'')+
              '</button>';
    }
    resultsBox.innerHTML = html;
    resultsBox.classList.remove('hidden');

    var btns = resultsBox.querySelectorAll('button[data-id]');
    for (var j=0;j<btns.length;j++){
      btns[j].addEventListener('click', function(){
        var id=this.getAttribute('data-id');
        selectTemplateById(id);
        var f=findById(id); if (f && searchInput){ searchInput.value=f.kpi||f.label; }
        resultsBox.classList.add('hidden');
      });
    }
  }

  function filter(){
    if (!searchInput || !resultsBox) return;
    var q=(searchInput.value||'').toLowerCase().trim();
    if (q===''){
      if (sel.value){ updateCardFromOption(sel.options[sel.selectedIndex]); }
      resultsBox.classList.add('hidden');
      return;
    }
    var out=[];
    for (var i=0;i<TPL.length;i++){
      if (TPL[i].search.indexOf(q)!==-1) out.push(TPL[i]);
    }
    renderResults(out.slice(0,20));
  }

  if (searchInput){
    searchInput.addEventListener('keydown', function(e){
      if (e.key==='Enter'){ e.preventDefault(); filter(); }
    });
    searchInput.addEventListener('input', filter);
  }

  document.addEventListener('keydown', function(e){
    var k=(e.key||'').toLowerCase();
    if ((e.ctrlKey||e.metaKey)&&k==='k' && searchInput){
      e.preventDefault(); searchInput.focus(); searchInput.select();
    }
  });

  if (sel && sel.value){ updateCardFromOption(sel.options[sel.selectedIndex]); }
})();
</script>
</body>
</html>

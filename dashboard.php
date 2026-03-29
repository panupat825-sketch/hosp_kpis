<?php
// dashboard.php — Group by Strategic → Mission(เป้าประสงค์) → FiscalYear → KPI
// และรวมการ์ด KPI ต่อ “ปีงบประมาณ” ให้แสดง Q1–Q4 ในการ์ดเดียว
include 'db_connect.php';
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
require_once __DIR__.'/auth.php';
require_login();
$u = current_user();

/* path สำหรับไฟล์ template KPI (ต้องตรงกับที่ใช้ใน kpi_template_manage.php) */
$upload_tpl_base = 'uploads_kpi_templates';

/* ====== โหลด Master สำหรับตัวกรอง ====== */
/* ตัดหมวดหมู่ (tb_categories) ออกแล้ว เหลือเฉพาะ ประเด็นยุทธศาสตร์ + เป้าประสงค์ + ปีงบ */
$strategic_issues_list = array();
if ($rs = mysqli_query($conn, "SELECT DISTINCT name FROM tb_strategic_issues ORDER BY name ASC")) {
  while ($r = mysqli_fetch_assoc($rs)) $strategic_issues_list[$r['name']] = $r['name'];
  mysqli_free_result($rs);
}
$missions_list = array();
if ($rs = mysqli_query($conn, "SELECT DISTINCT name FROM tb_missions ORDER BY name ASC")) {
  while ($r = mysqli_fetch_assoc($rs)) $missions_list[$r['name']] = $r['name'];
  mysqli_free_result($rs);
}
$categories_list = array();
if ($rs = mysqli_query($conn, "SELECT id, name FROM tb_categories ORDER BY name ASC")) {
  while ($r = mysqli_fetch_assoc($rs)) $categories_list[(int)$r['id']] = $r['name'];
  mysqli_free_result($rs);
}
$fiscal_years_list = array();
if ($rs = mysqli_query($conn, "SELECT DISTINCT fiscal_year FROM tb_kpi_instances ORDER BY fiscal_year DESC")) {
  while ($r = mysqli_fetch_assoc($rs)) $fiscal_years_list[$r['fiscal_year']] = $r['fiscal_year'];
  mysqli_free_result($rs);
}

/* ====== map หน่วยงาน / ทีม / user สำหรับแสดงชื่อ ====== */
$dept_map = array();
if ($rs = mysqli_query($conn, "SELECT id, department_name FROM tb_departments")) {
  while ($r = mysqli_fetch_assoc($rs)) {
    $dept_map[(int)$r['id']] = $r['department_name'];
  }
  mysqli_free_result($rs);
}
$team_map = array();
if ($rs = mysqli_query($conn, "SELECT id, name_th FROM tb_teams")) {
  while ($r = mysqli_fetch_assoc($rs)) {
    $team_map[(int)$r['id']] = $r['name_th'];
  }
  mysqli_free_result($rs);
}
$user_map = array();
if ($rs = mysqli_query($conn, "SELECT id, fullname FROM tb_users")) {
  while ($r = mysqli_fetch_assoc($rs)) {
    $user_map[(int)$r['id']] = $r['fullname'];
  }
  mysqli_free_result($rs);
}

/* หาค่า min/max ปีงบ (ใช้ตอนสร้างลิงก์ 5 ปีไปหน้ารายปี) */
$min_fiscal_year = null;
$max_fiscal_year = null;
foreach ($fiscal_years_list as $fy) {
  if ($min_fiscal_year === null || $fy < $min_fiscal_year) $min_fiscal_year = $fy;
  if ($max_fiscal_year === null || $fy > $max_fiscal_year) $max_fiscal_year = $fy;
}

function log_app_error($message, $context){
  error_log('[hosp_kpis] ' . $message . ' | ' . json_encode($context));
}
function normalize_fiscal_year($raw){
  $raw = trim((string)$raw);
  if ($raw === '') return '';
  if (!preg_match('/^\d{4}$/', $raw)) return false;
  $year = (int)$raw;
  if ($year >= 2000 && $year <= 2200) $year += 543;
  if ($year < 2500 || $year > 2800) return false;
  return (string)$year;
}

/* ====== รับตัวกรอง ====== */
$get = function($k){ return isset($_GET[$k]) ? trim($_GET[$k]) : ''; };
$page_error = '';
$filter_strategic   = $get('strategic_issue');
$filter_mission     = $get('mission');       // เป้าประสงค์
$filter_fiscal_year = normalize_fiscal_year($get('fiscal_year'));
$filter_quarter     = $get('quarter');       // ยังรองรับจาก query string ได้ แต่ไม่มีช่องให้เลือกแล้ว
$filter_template_id = $get('template_id');   // ใช้เวลาลิงก์มาจากหน้า yearly
$filter_keyword     = $get('search_text');   // คำค้น KPI / description / strategic / mission / strategy
$filter_category_id = $get('category_id');
$filter_department_id = $get('department_id');

if ($get('fiscal_year') !== '' && $filter_fiscal_year === false) {
  $page_error = 'Fiscal year filter must be a valid BE or CE year.';
  $filter_fiscal_year = '';
}
$filter_category_id = ctype_digit($filter_category_id) ? (int)$filter_category_id : 0;
$filter_department_id = ctype_digit($filter_department_id) ? (int)$filter_department_id : 0;

/* ====== WHERE จากตัวกรอง ====== */
$where_parts = array();
$where_types = '';
$where_params = array();
if ($filter_strategic   !== '') {
  $where_parts[] = "t.strategic_issue = ?";
  $where_types .= 's';
  $where_params[] = $filter_strategic;
}
if ($filter_mission     !== '') {
  $where_parts[] = "t.mission = ?";
  $where_types .= 's';
  $where_params[] = $filter_mission;
}
if ($filter_fiscal_year !== '') {
  $where_parts[] = "i.fiscal_year = ?";
  $where_types .= 's';
  $where_params[] = $filter_fiscal_year;
}
if ($filter_quarter     !== '') {
  $map = array('Q1'=>'i.quarter1=1','Q2'=>'i.quarter2=1','Q3'=>'i.quarter3=1','Q4'=>'i.quarter4=1');
  if (isset($map[$filter_quarter])) $where_parts[] = $map[$filter_quarter];
}
if ($filter_template_id !== '' && ctype_digit($filter_template_id)) {
  $where_parts[] = "t.id = ?";
  $where_types .= 'i';
  $where_params[] = (int)$filter_template_id;
}
if ($filter_category_id > 0) {
  $where_parts[] = "t.category_id = ?";
  $where_types .= 'i';
  $where_params[] = $filter_category_id;
}
if ($filter_department_id > 0) {
  $where_parts[] = "(
    (COALESCE(i.department_id, '') <> '' AND FIND_IN_SET(?, i.department_id))
    OR
    (COALESCE(i.department_id, '') = '' AND FIND_IN_SET(?, t.department_id))
  )";
  $where_types .= 'ii';
  $where_params[] = $filter_department_id;
  $where_params[] = $filter_department_id;
}

/* คำค้นแบบพิมพ์หา KPI / Description / Strategic / Mission / Strategy */
if ($filter_keyword !== '') {
  $where_parts[] = "(
      t.kpi_name        LIKE ?
   OR t.description     LIKE ?
   OR t.strategic_issue LIKE ?
   OR t.mission         LIKE ?
   OR s.name            LIKE ?
  )";
  $like = '%'.$filter_keyword.'%';
  $where_types .= 'sssss';
  $where_params[] = $like;
  $where_params[] = $like;
  $where_params[] = $like;
  $where_params[] = $like;
  $where_params[] = $like;
}
$where = "WHERE 1=1";
if (!empty($where_parts)) {
  $where .= " AND " . implode(" AND ", $where_parts);
}

/* ====== ลบ instance (ถ้าต้องการ) ====== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_instance') {
  require_role('admin');
  require_post_csrf();
  $iid = isset($_POST['delete_instance_id']) ? (int)$_POST['delete_instance_id'] : 0;
  if ($iid > 0 && $st = mysqli_prepare($conn,"DELETE FROM tb_kpi_instances WHERE id=?")) {
    mysqli_stmt_bind_param($st,"i",$iid);
    if (mysqli_stmt_execute($st)) {
      echo "<script>location.href='dashboard.php?message=Instance Deleted';</script>"; exit();
    } else {
      log_app_error('Dashboard delete instance failed', array('instance_id' => $iid, 'db_error' => mysqli_error($conn)));
      $page_error = 'Unable to delete this KPI record right now.';
    }
    mysqli_stmt_close($st);
  } elseif ($iid > 0) {
    log_app_error('Dashboard delete instance prepare failed', array('instance_id' => $iid, 'db_error' => mysqli_error($conn)));
    $page_error = 'Unable to delete this KPI record right now.';
  }
}

/* ====== Query หลัก (ดึง agg_type + strategy_name + responsible config จาก template มาด้วย) ====== */
$sql = "
SELECT
  i.id,
  i.fiscal_year,
  i.target_value,
  i.actual_value,
  i.variance,
  i.status,
  i.unit,
  i.operation,
  i.responsible_person,
  i.department_id,
  i.workgroup_id,
  i.action_plan,
  i.root_cause,
  i.suggestions,
  i.last_update,
  i.quarter1,
  i.quarter2,
  i.quarter3,
  i.quarter4,
  t.id AS template_id,
  t.kpi_name,
  t.description,
  t.strategic_issue,
  t.mission,
  t.template_file,
  t.agg_type,
  t.department_id        AS tpl_department_id,
  t.workgroup_id         AS tpl_workgroup_id,
  t.responsible_user_id  AS tpl_responsible_user_id,
  s.name AS strategy_name,
  CASE WHEN i.quarter1=1 THEN 'Q1'
       WHEN i.quarter2=1 THEN 'Q2'
       WHEN i.quarter3=1 THEN 'Q3'
       WHEN i.quarter4=1 THEN 'Q4' ELSE NULL END AS quarter,
  CASE WHEN i.quarter1=1 THEN 1
       WHEN i.quarter2=1 THEN 2
       WHEN i.quarter3=1 THEN 3
       WHEN i.quarter4=1 THEN 4 ELSE 0 END AS quarter_sort
FROM tb_kpi_instances i
JOIN tb_kpi_templates t ON t.id = i.template_id
LEFT JOIN tb_strategies s ON s.id = t.strategy_id
{$where}
ORDER BY i.fiscal_year DESC,
         t.strategic_issue ASC,
         t.mission ASC,
         t.kpi_name ASC,
         quarter_sort ASC
";
$result = false;
$query_started = perf_now();
if ($st = mysqli_prepare($conn, $sql)) {
  if (!db_bind_params($st, $where_types, $where_params)) {
    log_app_error('Quarter dashboard bind failed', array('db_error' => mysqli_error($conn)));
    $page_error = 'Unable to load KPI data right now. Please adjust the filter or try again.';
  } elseif (mysqli_stmt_execute($st)) {
    $result = mysqli_stmt_get_result($st);
    perf_log_if_slow('dashboard.main_query', $query_started, array(
      'filters' => array(
        'fiscal_year' => $filter_fiscal_year,
        'template_id' => $filter_template_id,
        'category_id' => $filter_category_id,
        'department_id' => $filter_department_id
      )
    ));
    if ($result === false) {
      log_app_error('Quarter dashboard result fetch failed', array('db_error' => mysqli_error($conn)));
      $page_error = 'Unable to load KPI data right now. Please adjust the filter or try again.';
    }
  } else {
    log_app_error('Quarter dashboard execute failed', array('db_error' => mysqli_error($conn)));
    $page_error = 'Unable to load KPI data right now. Please adjust the filter or try again.';
  }
  mysqli_stmt_close($st);
} else {
  log_app_error('Quarter dashboard query failed', array(
    'db_error' => mysqli_error($conn),
    'filters' => array(
      'fiscal_year' => $filter_fiscal_year,
      'strategic_issue' => $filter_strategic,
      'mission' => $filter_mission,
      'category_id' => $filter_category_id,
      'department_id' => $filter_department_id,
      'template_id' => $filter_template_id
    )
  ));
  $page_error = 'Unable to load KPI data right now. Please adjust the filter or try again.';
}

/* ====== Helpers ====== */
function compute_status_class($op,$t,$a){
  $class = "bg-gray-200 border-l-4 border-gray-500";
  if ($t === null || $a === null || $t === '' || $a === '') return $class;
  if (!is_numeric($t) || !is_numeric($a)) return $class;

  $t = (float)$t;
  $a = (float)$a;
  $op = trim($op);

  if ($op==='<' || $op==='<=') {
    return ($a <= $t)
      ? "bg-green-100 border-l-4 border-green-600"
      : "bg-red-100 border-l-4 border-red-600";
  }
  if ($op==='>' || $op==='>=') {
    return ($a >= $t)
      ? "bg-green-100 border-l-4 border-green-600"
      : "bg-red-100 border-l-4 border-red-600";
  }
  if ($op==='=') {
    return ($a == $t)
      ? "bg-green-100 border-l-4 border-green-600"
      : "bg-red-100 border-l-4 border-red-600";
  }
  return $class;
}
function h($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }

/* helper: แปลง CSV id → array id */
function parse_ids_csv($csv){
  $ids = array();
  $csv = (string)$csv;
  if ($csv === '') return $ids;
  $parts = explode(',', $csv);
  foreach ($parts as $p){
    $p = trim($p);
    if ($p === '') continue;
    $id = (int)$p;
    if ($id > 0) $ids[$id] = true;
  }
  return array_keys($ids);
}

/* anchor KPI (ใช้ร่วมกับหน้า yearly) */
function make_kpi_anchor($si, $mi, $kpi){
  return 'kpi_anchor_' . md5($si.'|'.$mi.'|'.$kpi);
}

/* anchor สำหรับการ์ด KPI รายปีบนหน้านี้ */
function make_quarter_anchor($si, $mi, $kpi, $fy){
  return 'kpiq_' . md5($si.'|'.$mi.'|'.$kpi.'|'.$fy);
}

/* สำหรับตัดคำว่า ต่อปี / /ปี ออกจากหน่วย (ใช้กับ agg_type = SUM) */
function normalize_sum_unit($unit){
  $u = trim((string)$unit);
  if ($u === '') return $u;
  $patterns = array('ต่อปี','/ปี');
  foreach ($patterns as $p){
    $u = str_replace($p,'',$u);
  }
  return trim($u);
}

/* ====== สร้างโครงรวม KPI ต่อปี ====== */
$data = array();
if ($result) while ($row = mysqli_fetch_assoc($result)) {
  $si    = (string)$row['strategic_issue'];
  $mi    = (string)$row['mission'];
  $fy    = (string)$row['fiscal_year'];
  $qt    = (string)$row['quarter'];     // 'Q1'..'Q4' หรือ NULL
  $kname = (string)$row['kpi_name'];

  // progress รายไตรมาส (รองรับ KPI ยิ่งน้อยยิ่งดี)
  $op = trim((string)$row['operation']);
  $t  = (float)$row['target_value'];
  $a  = (float)$row['actual_value'];

  if ($op === '<=' || $op === '<') {
    if ($a <= 0) {
      $progress = 100;
    } elseif ($t > 0) {
      $progress = ($t / $a) * 100;
    } else {
      $progress = 0;
    }
  } else {
    if ($t > 0) {
      $progress = ($a / $t) * 100;
    } else {
      $progress = 0;
    }
  }
  if ($progress < 0)   $progress = 0;
  if ($progress > 999) $progress = 999;

  if (!isset($data[$si])) {
    $data[$si] = array('name'=>$si, 'missions'=>array());
  }
  if (!isset($data[$si]['missions'][$mi])) {
    $data[$si]['missions'][$mi] = array('name'=>$mi, 'fiscal_years'=>array());
  }
  if (!isset($data[$si]['missions'][$mi]['fiscal_years'][$fy])) {
    $data[$si]['missions'][$mi]['fiscal_years'][$fy] = array('kpis'=>array());
  }
  if (!isset($data[$si]['missions'][$mi]['fiscal_years'][$fy]['kpis'][$kname])) {
    $data[$si]['missions'][$mi]['fiscal_years'][$fy]['kpis'][$kname] = array(
      'template' => array(
        'description'          => $row['description'],
        'strategy_name'        => $row['strategy_name'],
        'template_file'        => $row['template_file'],
        'template_id'          => (int)$row['template_id'],
        'agg_type'             => isset($row['agg_type']) ? $row['agg_type'] : 'AVG',
        'department_ids'       => $row['tpl_department_id'],
        'workgroup_ids'        => $row['tpl_workgroup_id'],
        'responsible_user_id'  => isset($row['tpl_responsible_user_id']) ? (int)$row['tpl_responsible_user_id'] : 0
      ),
      'quarters' => array()
    );
  }
  if (!$qt) continue;

  $data[$si]['missions'][$mi]['fiscal_years'][$fy]['kpis'][$kname]['quarters'][$qt] = array(
    'instance_id'       => (int)$row['id'],
    'target'            => $row['target_value'],
    'actual'            => $row['actual_value'],
    'variance'          => $row['variance'],
    'status'            => $row['status'],
    'unit'              => $row['unit'],
    'operation'         => $row['operation'],
    'progress'          => $progress,
    'responsible_person'=> $row['responsible_person'],
    'action_plan'       => $row['action_plan'],
    'root_cause'        => $row['root_cause'],
    'suggestions'       => $row['suggestions'],
    'last_update'       => isset($row['last_update']) ? $row['last_update'] : '',
    'base_status_class' => compute_status_class(
      $row['operation'],
      $row['target_value'],
      $row['actual_value']
    ),
    // instance-level responsible (CSV id ของหน่วยงาน/ทีม)
    'department_ids'    => $row['department_id'],
    'workgroup_ids'     => $row['workgroup_id']
  );
}
if ($result) mysqli_free_result($result);
mysqli_close($conn);

/* ====== เตรียม config ของกราฟ Chart.js ====== */
$charts = array();
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Hospital KPI Dashboard</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

  <style>
    .donut-gauge {
      --value: 0;
      --color: #22c55e;
      --thickness: 12px;
      position: relative;
      width: 90px;
      aspect-ratio: 1 / 1;
      border-radius: 999px;
      display: flex;
      align-items: center;
      justify-content: center;
      background:
        conic-gradient(
          var(--color) calc(var(--value) * 1%),
          #e5e7eb 0
        );
    }
    .donut-gauge::before {
      content: "";
      position: absolute;
      inset: calc(var(--thickness));
      border-radius: inherit;
      background: #ffffff;
    }
    .donut-center-text {
      position: relative;
      z-index: 1;
      font-size: 0.8rem;
      font-weight: 700;
      color: #374151;
      text-align: center;
      line-height: 1.1;
    }
    .kpi-anchor {
      scroll-margin-top: 120px;
    }
  </style>
</head>
<body class="bg-slate-100">

<?php
  $active_nav = 'dashboard_quarter';
  include __DIR__ . '/navbar_kpi.php';
  $header_actions = ''
    . '<a href="dashboard_yearly.php" class="inline-flex items-center justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-300">ดูมุมมองรายปี</a>'
    . '<a href="kpi_table.php" class="inline-flex items-center justify-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-200">ตาราง KPI</a>';
  kpi_page_header(
    'แดชบอร์ด KPI รายไตรมาส',
    'ติดตามผลตัวชี้วัดรายไตรมาส พร้อมตัวกรองที่คงค่าเดิมและการ์ด KPI ที่อ่านค่าเป้าหมาย เทียบค่าจริง และเปอร์เซ็นต์ได้ชัดเจน',
    array(
      array('label' => 'หน้าแรก', 'href' => 'index.php'),
      array('label' => 'แดชบอร์ดรายไตรมาส', 'href' => '')
    ),
    $header_actions
  );
?>

<div class="w-full px-4 sm:px-6 lg:px-8">
<div class="bg-white/95 p-5 sm:p-6 rounded-3xl shadow-xl shadow-slate-200/70 border border-slate-200">

  <!-- ตัวกรอง -->
  <form method="get" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-10 gap-3 mb-6 rounded-3xl border border-slate-200 bg-slate-50/90 p-4 sm:p-5 shadow-inner shadow-slate-100">
    <div class="flex flex-col md:col-span-2 xl:col-span-2">
      <label for="filter_fiscal_year" class="text-xs font-medium text-slate-700 mb-1">ปีงบประมาณ</label>
      <select id="filter_fiscal_year" name="fiscal_year" class="p-2.5 border border-slate-300 rounded-lg bg-white focus:outline-none focus:ring-2 focus:ring-sky-200">
        <option value="">ทั้งหมด</option>
        <?php foreach($fiscal_years_list as $y): ?>
          <option value="<?php echo h($y); ?>" <?php echo ($y===$filter_fiscal_year?'selected':''); ?>>
            <?php echo h($y); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="flex flex-col">
      <label for="filter_strategic_issue" class="text-xs font-medium text-slate-700 mb-1">ประเด็นยุทธศาสตร์</label>
      <select id="filter_strategic_issue" name="strategic_issue" class="p-2.5 border border-slate-300 rounded-lg bg-white focus:outline-none focus:ring-2 focus:ring-sky-200">
        <option value="">ทั้งหมด</option>
        <?php foreach($strategic_issues_list as $si): ?>
          <option value="<?php echo h($si); ?>" <?php echo ($si===$filter_strategic?'selected':''); ?>>
            <?php echo h($si); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="flex flex-col">
      <label for="filter_mission" class="text-xs font-medium text-slate-700 mb-1">เป้าประสงค์</label>
      <select id="filter_mission" name="mission" class="p-2.5 border border-slate-300 rounded-lg bg-white focus:outline-none focus:ring-2 focus:ring-sky-200">
        <option value="">ทั้งหมด</option>
        <?php foreach($missions_list as $mi): ?>
          <option value="<?php echo h($mi); ?>" <?php echo ($mi===$filter_mission?'selected':''); ?>>
            <?php echo h($mi); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="flex flex-col">
      <label for="filter_category_id" class="text-xs font-medium text-slate-700 mb-1">หมวดหมู่</label>
      <select id="filter_category_id" name="category_id" class="p-2.5 border border-slate-300 rounded-lg bg-white focus:outline-none focus:ring-2 focus:ring-sky-200">
        <option value="">ทั้งหมด</option>
        <?php foreach($categories_list as $cid => $cname): ?>
          <option value="<?php echo (int)$cid; ?>" <?php echo ($cid===$filter_category_id?'selected':''); ?>>
            <?php echo h($cname); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="flex flex-col">
      <label for="filter_department_id" class="text-xs font-medium text-slate-700 mb-1">หน่วยงาน</label>
      <select id="filter_department_id" name="department_id" class="p-2.5 border border-slate-300 rounded-lg bg-white focus:outline-none focus:ring-2 focus:ring-sky-200">
        <option value="">ทั้งหมด</option>
        <?php foreach($dept_map as $did => $dname): ?>
          <option value="<?php echo (int)$did; ?>" <?php echo ($did===$filter_department_id?'selected':''); ?>>
            <?php echo h($dname); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="flex flex-col">
      <label for="filter_search_text" class="text-xs font-medium text-slate-700 mb-1">คำค้น </label>
      <input
        type="text"
        id="filter_search_text"
        name="search_text"
        class="p-2.5 border border-slate-300 rounded-lg bg-white focus:outline-none focus:ring-2 focus:ring-sky-200"
        placeholder="พิมพ์คำค้นเพื่อกรอง KPI"
        value="<?php echo h($filter_keyword); ?>"
      />
    </div>

    <div class="flex flex-col gap-2 md:col-span-2 xl:col-span-2 md:items-stretch">
      <label class="text-xs text-gray-600 mb-1 invisible md:visible"> </label>
      <div class="flex flex-col 2xl:flex-row gap-2 w-full">
        <button type="submit"
                class="px-4 py-2.5 bg-slate-900 text-white rounded-lg whitespace-nowrap w-full focus:outline-none focus:ring-2 focus:ring-slate-300">
          กรองข้อมูล
        </button>
        <a href="dashboard.php"
           class="px-4 py-2.5 bg-white border border-slate-300 text-slate-700 rounded-lg whitespace-nowrap hover:bg-slate-100 w-full text-center focus:outline-none focus:ring-2 focus:ring-slate-200">
          ล้างตัวกรอง
        </a>
      </div>
    </div>
  </form>

  <?php if ($page_error !== ''): ?>
    <div role="alert" class="mb-4 p-3 rounded-xl border border-amber-300 bg-amber-50 text-amber-800 text-sm">
      <?php echo h($page_error); ?>
    </div>
  <?php endif; ?>

  <?php if (empty($data)): ?>
    <div class="p-8 text-center text-slate-600 rounded-2xl border border-dashed border-slate-300 bg-slate-50">
      <div class="text-base font-medium text-slate-800 mb-1">ไม่พบข้อมูลตามตัวกรอง</div>
      <div class="text-sm">ลองปรับปีงบประมาณ หน่วยงาน หรือคำค้น แล้วค้นหาใหม่</div>
    </div>
  <?php else: ?>

    <?php foreach ($data as $si_name => $si_pack): ?>
      <div class="border-t-4 border-gray-300 mt-10 pt-6">
        <!-- การ์ดประเด็นยุทธศาสตร์ -->
        <div class="bg-black p-4 rounded-lg mb-6">
          <h2 class="text-xl font-bold text-white">
            ประเด็นยุทธศาสตร์: <?php echo h($si_pack['name']); ?>
          </h2>
        </div>

        <?php foreach ($si_pack['missions'] as $mi_name => $mission_pack): ?>
          <!-- การ์ดเป้าประสงค์ -->
          <div class="bg-yellow-100 p-3 rounded mb-4">
            <h3 class="text-lg font-semibold text-yellow-800">
              เป้าประสงค์: <?php echo h($mission_pack['name']); ?>
            </h3>
          </div>

          <?php foreach ($mission_pack['fiscal_years'] as $year => $ydata): ?>
            <div class="bg-gray-200 p-3 rounded mb-4">
              <h4 class="text-md font-semibold text-gray-700">
                ปีงบประมาณ: <?php echo h($year); ?>
              </h4>
            </div>

            <?php
              $kpis_of_year = $ydata['kpis'];
              if (is_array($kpis_of_year)) {
                ksort($kpis_of_year, SORT_NATURAL | SORT_FLAG_CASE);
              }
            ?>

            <?php if (empty($kpis_of_year)): ?>
              <div class="p-4 text-gray-600">ยังไม่มี KPI ในปีนี้</div>
            <?php else: ?>
              <?php foreach ($kpis_of_year as $kpi_name => $pack): ?>
                <?php
                  $tpl           = $pack['template'];
                  $strategy_name = $tpl['strategy_name'] ? $tpl['strategy_name'] : '—';
                  $qs            = isset($pack['quarters']) ? $pack['quarters'] : array();
                  $order         = array('Q1','Q2','Q3','Q4');

                  // แปลง template-level responsible → ชื่อ
                  $tpl_dep_names  = array();
                  $tpl_team_names = array();
                  if (!empty($tpl['department_ids'])) {
                    $ids = parse_ids_csv($tpl['department_ids']);
                    foreach ($ids as $idv) {
                      if (isset($dept_map[$idv])) $tpl_dep_names[] = $dept_map[$idv];
                    }
                  }
                  if (!empty($tpl['workgroup_ids'])) {
                    $ids = parse_ids_csv($tpl['workgroup_ids']);
                    foreach ($ids as $idv) {
                      if (isset($team_map[$idv])) $tpl_team_names[] = $team_map[$idv];
                    }
                  }
                  $tpl_resp_user_name = '';
                  if (!empty($tpl['responsible_user_id']) &&
                      isset($user_map[(int)$tpl['responsible_user_id']])) {
                    $tpl_resp_user_name = $user_map[(int)$tpl['responsible_user_id']];
                  }

                  $agg_type = isset($tpl['agg_type']) ? strtoupper($tpl['agg_type']) : 'AVG';
                  if ($agg_type !== 'SUM') $agg_type = 'AVG';

                  $sumT = 0.0;
                  $sumA = 0.0;
                  $qCount = 0;
                  $opFirst = null;
                  $unit_raw = '';
                  $perQTarget = array();
                  $perQActual = array();

                  foreach ($order as $qk) {
                    if (!isset($qs[$qk])) continue;
                    $r = $qs[$qk];
                    if (is_numeric($r['target'])) {
                      $t = (float)$r['target'];
                      $sumT += $t;
                      $perQTarget[$qk] = $t;
                    }
                    if (is_numeric($r['actual'])) {
                      $a = (float)$r['actual'];
                      $sumA += $a;
                      $perQActual[$qk] = $a;
                    }
                    $qCount++;
                    if ($opFirst === null && !empty($r['operation'])) $opFirst = $r['operation'];
                    if ($unit_raw === '' && !empty($r['unit'])) $unit_raw = $r['unit'];
                  }

                  if ($agg_type === 'SUM') {
                    $base_unit     = normalize_sum_unit($unit_raw);
                    $unit_display  = $base_unit ? ($base_unit . ' (สะสม)') : '(สะสม)';
                  } else {
                    $unit_display  = $unit_raw;
                  }

                  $labels        = array();
                  $seriesT       = array();
                  $seriesA       = array();
                  $badge_percent = 0;
                  $diffVal       = 0;
                  $diffAbs       = 0;
                  $diffText      = '';
                  $diffClass     = 'bg-red-100 text-red-700 border-red-300';

                  $yearTarget = 0.0;
                  if (!empty($perQTarget)) {
                    $yearTarget = $sumT;
                  }

                  // ตัวแปรกลางที่ใช้คิด % บรรลุผล
                  $cmpTarget = 0.0;
                  $cmpActual = 0.0;

                  if ($agg_type === 'SUM') {
                    /* -------- mode SUM: ใช้เป้า annual + ค่าจริงสะสม -------- */
                    $yearActual = $sumA;
                    $cmpTarget  = $yearTarget;
                    $cmpActual  = $yearActual;

                    $diffVal  = $cmpActual - $cmpTarget;
                    $diffAbs  = abs($diffVal);
                    $diffSign = $diffVal > 0 ? '+' : ($diffVal < 0 ? '−' : '');
                    $diffText = $diffSign . number_format($diffAbs, 2);

                    if ($opFirst === '>' || $opFirst === '>=') {
                      if ($cmpActual >= $cmpTarget) {
                        $diffClass = 'bg-emerald-100 text-emerald-700 border-emerald-300';
                      }
                    } elseif ($opFirst === '<' || $opFirst === '<=') {
                      if ($cmpActual <= $cmpTarget) {
                        $diffClass = 'bg-emerald-100 text-emerald-700 border-emerald-300';
                      }
                    } else {
                      if (abs($diffVal) < 0.000001) {
                        $diffClass = 'bg-emerald-100 text-emerald-700 border-emerald-300';
                      }
                    }

                    // สร้าง series กราฟแบบสะสม
                    $actualCum = array();
                    $running = 0.0;
                    foreach ($order as $qk) {
                      $labels[] = $qk;
                      $seriesT[] = $yearTarget > 0 ? $yearTarget : null;
                      if (isset($perQActual[$qk])) {
                        $running += $perQActual[$qk];
                        $actualCum[$qk] = $running;
                        $seriesA[] = $running;
                      } else {
                        if ($running > 0) {
                          $actualCum[$qk] = $running;
                          $seriesA[] = $running;
                        } else {
                          $actualCum[$qk] = 0;
                          $seriesA[] = null;
                        }
                      }
                    }
                  } else {
                    /* -------- mode AVG: ใช้ค่าเฉลี่ยต่อไตรมาส -------- */
                    $avgT = ($qCount > 0) ? $sumT / $qCount : 0;
                    $avgA = ($qCount > 0) ? $sumA / $qCount : 0;

                    $cmpTarget = $avgT;
                    $cmpActual = $avgA;

                    $diffVal  = $avgA - $avgT;
                    $diffAbs  = abs($diffVal);
                    $diffSign = $diffVal > 0 ? '+' : ($diffVal < 0 ? '−' : '');
                    $diffText = $diffSign . number_format($diffAbs, 2);

                    if ($opFirst === '>' || $opFirst === '>=') {
                      if ($avgA >= $avgT) $diffClass = 'bg-emerald-100 text-emerald-700 border-emerald-300';
                    } elseif ($opFirst === '<' || $opFirst === '<=') {
                      if ($avgA <= $avgT) $diffClass = 'bg-emerald-100 text-emerald-700 border-emerald-300';
                    } else {
                      if (abs($diffVal) < 0.000001) $diffClass = 'bg-emerald-100 text-emerald-700 border-emerald-300';
                    }

                    $actualCum = array();
                    foreach ($order as $qk) {
                      $labels[]  = $qk;
                      $seriesT[] = isset($perQTarget[$qk]) ? $perQTarget[$qk] : null;
                      $seriesA[] = isset($perQActual[$qk]) ? $perQActual[$qk] : null;
                      $actualCum[$qk] = isset($perQActual[$qk]) ? $perQActual[$qk] : 0;
                    }
                  }

                  // คำนวณ % บรรลุผลของเกจจากชุดเปรียบเทียบเดียวกับตัวหนังสือ
                  if ($opFirst === '<' || $opFirst === '<=') {
                    if ($cmpActual <= 0) {
                      $badge_percent = 100;
                    } elseif ($cmpTarget > 0) {
                      $badge_percent = ($cmpTarget / $cmpActual) * 100;
                    } else {
                      $badge_percent = 0;
                    }
                  } else {
                    $badge_percent = ($cmpTarget > 0)
                      ? ($cmpActual / $cmpTarget) * 100
                      : 0;
                  }
                  $badge_percent = max(0, min(999, round($badge_percent)));

                  $gaugePercent = (float)max(0, min(100, $badge_percent));
                  $ringColor = '#dc2626';
                  if ($badge_percent >= 100) {
                    $ringColor = '#16a34a';
                  } elseif ($badge_percent >= 80) {
                    $ringColor = '#facc15';
                  }

                  // คำนวณช่วงแกน Y และตรวจว่ามีข้อมูล numeric จริงหรือไม่
                  $vals = array();
                  foreach ($seriesT as $v) if (is_numeric($v)) $vals[] = (float)$v;
                  foreach ($seriesA as $v) if (is_numeric($v)) $vals[] = (float)$v;

                  $ymin = 0; $ymax = 0;
                  $hasNumeric = !empty($vals);

                  if ($hasNumeric) {
                    $minv = min($vals);
                    $maxv = max($vals);
                    if ($maxv == $minv) {
                      $ymin = $minv - 5;
                      $ymax = $maxv + 5;
                    } else {
                      $range   = $maxv - $minv;
                      $padding = $range * 0.25;
                      if ($padding < 1) $padding = 1;
                      $ymin = $minv - $padding;
                      $ymax = $maxv + $padding;
                    }
                    if ($ymin < 0) $ymin = 0;
                  }

                  $chart_id = '';
                  if ($hasNumeric && !empty($labels)) {
                    $chart_id = 'chart_'.md5($year.'_'.$kpi_name.'_'.uniqid('',true));
                    $charts[] = array(
                      'id'     => $chart_id,
                      'labels' => $labels,
                      't'      => $seriesT,
                      'a'      => $seriesA,
                      'ymin'   => $ymin,
                      'ymax'   => $ymax
                    );
                  }

                  $tpl_file   = isset($tpl['template_file']) ? trim($tpl['template_file']) : '';
                  $templateId = isset($tpl['template_id']) ? (int)$tpl['template_id'] : 0;

                  // anchor การ์ดปีนี้
                  $quarter_anchor_id = make_quarter_anchor($si_name, $mi_name, $kpi_name, $year);

                  // ===== ลิงก์ไปหน้ารายปี (ช่วง 5 ปีรอบปีนี้) =====
                  $centerYear = (int)$year;
                  $yf = $centerYear - 2;
                  $yt = $centerYear + 2;
                  if ($min_fiscal_year !== null && $max_fiscal_year !== null) {
                    if ($yf < $min_fiscal_year) {
                      $yf = $min_fiscal_year;
                      $yt = min($yf + 4, $max_fiscal_year);
                    }
                    if ($yt > $max_fiscal_year) {
                      $yt = $max_fiscal_year;
                      $yf = max($yt - 4, $min_fiscal_year);
                    }
                  }
                  $yearly_link = 'dashboard_yearly.php'
                    . '?year_from='      . urlencode($yf)
                    . '&year_to='        . urlencode($yt)
                    . '&strategic_issue='. urlencode($si_name)
                    . '&mission='        . urlencode($mi_name)
                    . '&template_id='    . urlencode($templateId)
                    . '#' . rawurlencode(make_kpi_anchor($si_name, $mi_name, $kpi_name));
                ?>

                <div id="<?php echo h($quarter_anchor_id); ?>"
                     class="kpi-anchor mb-6 p-4 bg-white rounded-lg shadow border">

                  <!-- หัวข้อ KPI + ปุ่มไปหน้ารายปี + ปุ่มเปิดเทมเพลต -->
                  <div class="flex items-start justify-between gap-3 mb-1">
                    <h2 class="text-lg font-semibold text-blue-600">
                      <?php echo h($kpi_name); ?>
                    </h2>
                    <div class="flex items-center gap-2">
                      <a href="<?php echo h($yearly_link); ?>"
                         class="px-3 py-1 text-xs md:text-sm rounded bg-emerald-600 hover:bg-emerald-700 text-white whitespace-nowrap"
                         title="ดูสรุป KPI รายปี (ช่วงประมาณ 5 ปีรอบปี <?php echo h($year); ?>)">
                        📊 สรุปแบบรายปี
                      </a>
                      <?php if ($tpl_file !== ''): ?>
                        <button type="button"
                                class="px-3 py-1 text-xs md:text-sm rounded bg-indigo-600 hover:bg-indigo-700 text-white whitespace-nowrap"
                                data-kpi="<?php echo h($kpi_name); ?>"
                                data-desc="<?php echo h($tpl['description']); ?>"
                                data-file="<?php echo h($tpl_file); ?>"
                                onclick="openTplModal(this)">
                          📎 เปิดเทมเพลต
                        </button>
                      <?php endif; ?>
                    </div>
                  </div>

                  <!-- การ์ดป้าย Strategic / เป้าประสงค์ / กลยุทธ์ -->
                  <div class="mt-2 flex flex-wrap gap-2 text-xs md:text-sm">
                    <span class="inline-flex items-center px-3 py-1 rounded-full bg-slate-100 text-slate-800 border border-slate-300">
                      Strategic: <?php echo h($si_name); ?>
                    </span>
                    <span class="inline-flex items-center px-3 py-1 rounded-full bg-amber-100 text-amber-900 border border-amber-300">
                      เป้าประสงค์: <?php echo h($mi_name); ?>
                    </span>
                    <span class="inline-flex items-center px-3 py-1 rounded-full bg-pink-100 text-pink-900 border border-pink-300">
                      Strategy: <?php echo h($strategy_name); ?>
                    </span>
                  </div>

                  <p class="text-gray-600 mt-2">
                    <strong>Description:</strong> <?php echo h($tpl['description']); ?>
                  </p>

                  <!-- แสดงผู้รับผิดชอบจาก Template -->
                  <div class="mt-2 text-xs md:text-sm text-gray-700 space-y-1">
                    <?php if ($tpl_resp_user_name !== ''): ?>
                      <div><strong>ผู้รับผิดชอบหลัก (Template):</strong> <?php echo h($tpl_resp_user_name); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($tpl_dep_names)): ?>
                      <div><strong>หน่วยงาน:</strong> <?php echo h(implode(', ', $tpl_dep_names)); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($tpl_team_names)): ?>
                      <div><strong>ทีม / กลุ่มงาน:</strong> <?php echo h(implode(', ', $tpl_team_names)); ?></div>
                    <?php endif; ?>
                  </div>

                  <div class="mt-4 grid grid-cols-1 lg:grid-cols-3 gap-4">
                    <!-- ซ้าย 2 คอลัมน์: รายไตรมาส -->
                    <div class="lg:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-4">
                      <?php
                        $quarterIndexMap = array('Q1'=>1,'Q2'=>2,'Q3'=>3,'Q4'=>4);
                      ?>
                      <?php foreach ($order as $qq): ?>
                        <?php if (isset($qs[$qq])): $row = $qs[$qq]; ?>
                          <?php
                            if ($agg_type === 'SUM') {
                              $u_quarter = normalize_sum_unit($row['unit']);
                              $u_quarter = $u_quarter ? ($u_quarter.' (สะสม)') : '(สะสม)';
                            } else {
                              $u_quarter = $row['unit'];
                            }

                            $cardClass = $row['base_status_class'];
                            if ($agg_type === 'SUM' && $yearTarget > 0) {
                              $idx = isset($quarterIndexMap[$qq]) ? $quarterIndexMap[$qq] : 1;
                              $targetThreshold = $yearTarget * ($idx / 4.0);
                              $actualCumN = isset($actualCum[$qq]) ? $actualCum[$qq] : 0;
                              $cardClass = compute_status_class($opFirst, $targetThreshold, $actualCumN);
                            }
                            $barWidth = (int)min(100,max(0,$row['progress']));
                          ?>
                          <div class="p-3 <?php echo $cardClass; ?> rounded">
                            <div class="font-semibold text-gray-800 mb-1">ไตรมาส: <?php echo $qq; ?></div>

                            <p class="text-gray-700">
                              <strong>เป้าหมาย:</strong>
                              <?php echo h(($row['operation'] ? $row['operation'].' ' : '').$row['target']); ?>
                              <?php echo ' '.h($u_quarter); ?>
                            </p>
                            <p class="text-gray-700">
                              <strong>ค่าจริง:</strong>
                              <?php echo h($row['actual']); ?>
                              <?php echo ' '.h($u_quarter); ?>
                            </p>

                            <div class="w-full bg-gray-300 rounded-full h-3 mt-2 overflow-hidden relative">
                              <div class="bg-blue-500 h-3 rounded-full"
                                   style="width: <?php echo $barWidth; ?>%;"></div>
                            </div>
                            <div class="text-xs text-gray-600 mt-1">
                              Progress: <?php echo number_format((float)$row['progress'],2); ?>% |
                              Variance: <?php echo h($row['variance']); ?>
                            </div>

                            <div class="text-xs text-gray-600 mt-1">
                              ผู้รับผิดชอบ (ปีนี้/ไตรมาสนี้): <?php echo h($row['responsible_person']); ?>
                            </div>
                            <div class="text-xs text-gray-600">
                              Action Plan: <?php echo h($row['action_plan']); ?>
                            </div>

                            <div class="mt-2 flex gap-2">
                              <button type="button"
                                      class="px-3 py-1 bg-yellow-600 text-white rounded"
                                      data-instance-id="<?php echo (int)$row['instance_id']; ?>"
                                      onclick="openEditInstanceModal(this)">
                                Edit
                              </button>

                              <?php if (has_role('admin')): ?>
                                <form method="post" class="inline" onsubmit="return confirm('ยืนยันลบ KPI instance นี้?');">
                                  <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                  <input type="hidden" name="action" value="delete_instance">
                                  <input type="hidden" name="delete_instance_id" value="<?php echo (int)$row['instance_id']; ?>">
                                  <button type="submit" class="px-3 py-1 bg-red-600 text-white rounded">
                                    ลบ
                                  </button>
                                </form>
                              <?php endif; ?>
                            </div>
                          </div>
                        <?php else: ?>
                          <!-- การ์ดว่าง: เพิ่มข้อมูลไตรมาสนี้ -->
                          <div class="p-3 bg-gray-50 border rounded cursor-pointer hover:bg-blue-50"
                               data-template-id="<?php echo $templateId; ?>"
                               data-fiscal-year="<?php echo h($year); ?>"
                               data-quarter="<?php echo $qq; ?>"
                               onclick="openAddInstanceModal(this)">
                            <div class="font-semibold text-gray-700 mb-1">
                              ไตรมาส: <?php echo $qq; ?>
                            </div>
                            <div class="text-sm text-gray-400">
                              ยังไม่มีข้อมูล<br>
                              <span class="text-blue-600 underline">
                                คลิกเพื่อเพิ่มผลตัวชี้วัดไตรมาสนี้
                              </span>
                            </div>
                          </div>
                        <?php endif; ?>
                      <?php endforeach; ?>
                    </div>

                    <!-- ขวา: donut + กราฟ -->
                    <div class="lg:col-span-1">
                      <div class="border border-slate-200 rounded-2xl px-3 py-3 flex flex-col gap-2 h-full bg-white shadow-sm shadow-slate-200/70">
                        <div class="flex flex-wrap gap-3 items-start">
                          <div class="donut-gauge"
                               style="--value: <?php echo isset($gaugePercent)?(float)$gaugePercent:0; ?>;
                                      --color: <?php echo isset($ringColor)?$ringColor:'#22c55e'; ?>;">
                            <div class="donut-center-text">
                              <?php echo isset($gaugePercent)?(int)$gaugePercent:0; ?>%
                            </div>
                          </div>

                          <div class="text-xs text-gray-700 flex-1 min-w-[170px]">
                            <div class="font-semibold mb-1">
                              <?php if ($agg_type === 'SUM'): ?>
                                เปรียบเทียบเป้า : ค่าจริง (สะสมถึงปัจจุบัน)
                              <?php else: ?>
                                เปรียบเทียบเป้า : ค่าจริง (ค่าเฉลี่ยต่อไตรมาส)
                              <?php endif; ?>
                            </div>

                            <div class="flex flex-col gap-1">
                              <?php if ($agg_type === 'SUM'): ?>
                                <div class="flex items-center gap-2">
                                  <span class="inline-block w-2.5 h-2.5 rounded-full bg-sky-500"></span>
                                  <span>
                                    เป้าหมายทั้งปี:
                                    <?php
                                      echo h($opFirst ? $opFirst.' ' : '');
                                      echo number_format($yearTarget,2).' '.h($unit_display?:'');
                                    ?>
                                  </span>
                                </div>
                                <div class="flex items-center gap-2">
                                  <span class="inline-block w-2.5 h-2.5 rounded-full bg-rose-500"></span>
                                  <span>
                                    ผลสะสมถึงปัจจุบัน:
                                    <?php echo number_format(isset($cmpActual)?$cmpActual:0,2).' '.h($unit_display?:''); ?>
                                  </span>
                                </div>
                              <?php else: ?>
                                <div class="flex items-center gap-2">
                                  <span class="inline-block w-2.5 h-2.5 rounded-full bg-sky-500"></span>
                                  <span>
                                    เป้าเฉลี่ย:
                                    <?php
                                      echo h($opFirst ? $opFirst.' ' : '');
                                      echo number_format(isset($cmpTarget)?$cmpTarget:0,2).' '.h($unit_display?:'');
                                    ?>
                                  </span>
                                </div>
                                <div class="flex items-center gap-2">
                                  <span class="inline-block w-2.5 h-2.5 rounded-full bg-rose-500"></span>
                                  <span>
                                    ค่าจริงเฉลี่ย:
                                    <?php echo number_format(isset($cmpActual)?$cmpActual:0,2).' '.h($unit_display?:''); ?>
                                  </span>
                                </div>
                              <?php endif; ?>
                            </div>

                            <div class="mt-2">
                              <span class="text-[10px] mr-1">ส่วนต่าง</span>
                              <span class="inline-flex items-center px-2 py-0.5 rounded-full border
                                           text-[11px] font-semibold <?php echo $diffClass; ?>">
                                <?php echo $diffText.' '.h($unit_display?:''); ?>
                              </span>
                            </div>
                          </div>
                        </div>

                        <div class="flex justify-between text-[11px] text-gray-600 mt-1">
                          <div>หน่วย: <?php echo h($unit_display?:'—'); ?></div>
                          <div>Q1–Q4: Target vs Actual</div>
                        </div>

                        <div class="mt-1 h-40">
                          <?php if ($chart_id): ?>
                            <canvas id="<?php echo h($chart_id); ?>" class="w-full h-full"></canvas>
                          <?php else: ?>
                            <div class="w-full h-full flex items-center justify-center text-[11px] text-gray-400 border border-dashed rounded">
                              ยังไม่มีข้อมูลเพียงพอสำหรับสร้างกราฟ
                            </div>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>

          <?php endforeach; // ปี ?>
        <?php endforeach; // เป้าประสงค์ ?>
      </div>
    <?php endforeach; // ประเด็นยุทธศาสตร์ ?>
  <?php endif; ?>
</div>

<!-- Modal แสดงเทมเพลต KPI -->
<div id="tplModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50">
  <div class="bg-white rounded-lg shadow-xl
              w-full max-w-6xl
              mx-2 md:mx-6
              max-h-[100vh]
              flex flex-col"
       onclick="event.stopPropagation();">

    <div class="flex items-center justify-between px-4 py-3 border-b">
      <div>
        <h3 id="tplTitle" class="text-lg font-semibold text-gray-800">Template</h3>
        <p class="text-xs text-gray-500">เอกสารเทมเพลตตัวชี้วัด</p>
      </div>
      <button type="button"
              class="px-3 py-1 text-sm rounded bg-gray-200 hover:bg-gray-300"
              onclick="closeTplModal()">✕ ปิด</button>
    </div>
    <div class="px-4 py-3 space-y-3 overflow-y-auto">
      <div>
        <div class="text-xs font-semibold text-gray-500 mb-1">คำอธิบาย KPI</div>
        <p id="tplDesc" class="text-sm text-gray-700">—</p>
      </div>

      <div>
        <div class="text-xs font-semibold text-gray-500 mb-1">
          พรีวิวเอกสาร (รองรับไฟล์ PDF/รูปภาพ)
        </div>
        <iframe id="tplFrame" src=""
                class="w-full h-96 md:h-[30rem] border rounded hidden"></iframe>

        <p class="text-xs text-gray-400 mt-1">
          ถ้าไม่แสดงพรีวิว สามารถกดปุ่มด้านล่างเพื่อเปิดไฟล์เต็มในแท็บใหม่ได้
        </p>
      </div>

      <div class="pt-2 border-t">
        <a id="tplLink" href="#" target="_blank"
           class="inline-flex items-center px-3 py-1.5 rounded bg-indigo-600 hover:bg-indigo-700 text-white text-sm">
          📂 เปิดไฟล์เทมเพลตในแท็บใหม่
        </a>
      </div>
    </div>
  </div>
</div>

<!-- Modal แก้ไข/เพิ่ม KPI Instance -->
<div id="editInstanceModal"
     class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50">
  <div class="bg-white rounded-lg shadow-xl
              w-full max-w-5xl
              mx-2 md:mx-6
              max-h-[100vh]
              flex flex-col"
       onclick="event.stopPropagation();">

    <div class="flex items-center justify-between px-4 py-3 border-b">
      <div>
        <h3 class="text-lg font-semibold text-gray-800">
          ✏️ แก้ไข/เพิ่มผลตัวชี้วัด (KPI Instance)
        </h3>
        <p class="text-xs text-gray-500">
          แบบฟอร์มนี้คือหน้า kpi_instance_manage.php ในมุมมอง modal
        </p>
      </div>
      <button type="button"
              class="px-3 py-1 text-sm rounded bg-gray-200 hover:bg-gray-300"
              onclick="closeEditInstanceModal()">✕ ปิด</button>
    </div>

    <div class="flex-1 overflow-y-auto">
      <!-- ใช้ iframe โหลดฟอร์ม kpi_instance_manage.php -->
      <iframe id="editInstanceFrame"
              src=""
              class="w-full h-[80vh] border-0"></iframe>
    </div>
  </div>
</div>

<!-- สร้างกราฟทั้งหมด + script modal -->
<script>
  /* ========= Chart.js: กราฟ Q1–Q4 ของแต่ละ KPI ========= */
  (function () {
    var configs = <?php echo json_encode($charts); ?> || [];
    if (!Array.isArray(configs)) return;

    configs.forEach(function (c) {
      if (!c || !c.id) return;

      var canvas = document.getElementById(c.id);
      if (!canvas) return;

      var ctx  = canvas.getContext('2d');
      var yMin = (typeof c.ymin === 'number') ? c.ymin : 0;
      var yMax = (typeof c.ymax === 'number') ? c.ymax : undefined;

      new Chart(ctx, {
        type: 'line',
        data: {
          labels: c.labels || [],
          datasets: [
            {
              label: 'Target',
              data:  c.t || [],
              borderColor: '#0f766e',
              backgroundColor: 'rgba(15, 118, 110, 0.12)',
              borderWidth: 2,
              pointRadius: 4,
              pointHoverRadius: 5,
              pointBackgroundColor: '#0f766e',
              tension: 0.2
            },
            {
              label: 'Actual',
              data:  c.a || [],
              borderColor: '#2563eb',
              backgroundColor: 'rgba(37, 99, 235, 0.14)',
              borderWidth: 2,
              pointRadius: 4,
              pointHoverRadius: 5,
              pointBackgroundColor: '#2563eb',
              tension: 0.2
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              position: 'bottom',
              labels: {
                boxWidth: 12,
                usePointStyle: true,
                color: '#334155'
              }
            }
          },
          scales: {
            x: {
              ticks: { color: '#64748b' },
              grid: { display: false }
            },
            y: {
              suggestedMin: yMin,
              suggestedMax: yMax,
              ticks: { maxTicksLimit: 6, color: '#64748b' },
              grid: { color: 'rgba(148, 163, 184, 0.18)' }
            }
          }
        }
      });
    });
  })();

  /* ========= Modal: เปิดไฟล์เทมเพลต KPI ========= */
  var TPL_BASE = <?php echo json_encode(rtrim($upload_tpl_base,'/').'/'); ?>;

  function openTplModal(btn) {
    var modal = document.getElementById('tplModal');
    if (!modal) return;

    var kpi  = btn.getAttribute('data-kpi')  || '';
    var desc = btn.getAttribute('data-desc') || '';
    var file = btn.getAttribute('data-file') || '';

    var titleEl = document.getElementById('tplTitle');
    var descEl  = document.getElementById('tplDesc');
    var linkEl  = document.getElementById('tplLink');
    var frameEl = document.getElementById('tplFrame');

    titleEl.textContent = kpi || 'Template';
    descEl.textContent  = desc || '—';

    var url = TPL_BASE + file;
    linkEl.href = url;

    var ext = (file.split('.').pop() || '').toLowerCase();
    var canEmbed = ['pdf','jpg','jpeg','png','gif','webp'].indexOf(ext) !== -1;

    if (canEmbed) {
      frameEl.src = url;
      frameEl.classList.remove('hidden');
    } else {
      frameEl.src = '';
      frameEl.classList.add('hidden');
    }

    modal.classList.remove('hidden');
    modal.classList.add('flex');
  }

  function closeTplModal() {
    var modal = document.getElementById('tplModal');
    if (!modal) return;

    var frameEl = document.getElementById('tplFrame');
    if (frameEl) frameEl.src = '';

    modal.classList.add('hidden');
    modal.classList.remove('flex');
  }

  // คลิกพื้นหลังเพื่อปิด tplModal
  (function () {
    var modal = document.getElementById('tplModal');
    if (!modal) return;

    modal.addEventListener('click', function (e) {
      if (e.target === modal) {
        closeTplModal();
      }
    });
  })();

  /* ========= Modal: แก้ไข / เพิ่ม KPI Instance (ผ่าน iframe) ========= */

  function openEditInstanceModal(btn) {
    var id    = btn.getAttribute('data-instance-id');
    var modal = document.getElementById('editInstanceModal');
    var frame = document.getElementById('editInstanceFrame');

    if (!id || !modal || !frame) return;

    var url = 'kpi_instance_manage.php?edit=' + encodeURIComponent(id) + '&modal=1';
    frame.src = url;

    modal.classList.remove('hidden');
    modal.classList.add('flex');
  }

  function openAddInstanceModal(btn) {
    if (!confirm('คุณต้องการเพิ่มข้อมูลตัวชี้วัดในไตรมาสนี้หรือไม่?')) {
      return;
    }

    var tplId = btn.getAttribute('data-template-id');
    var fy    = btn.getAttribute('data-fiscal-year');
    var q     = btn.getAttribute('data-quarter');

    var modal = document.getElementById('editInstanceModal');
    var frame = document.getElementById('editInstanceFrame');

    if (!tplId || !fy || !q || !modal || !frame) return;

    var url = 'kpi_instance_manage.php'
              + '?template_id=' + encodeURIComponent(tplId)
              + '&fiscal_year=' + encodeURIComponent(fy)
              + '&quarter='     + encodeURIComponent(q)
              + '&modal=1';

    frame.src = url;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
  }

  function closeEditInstanceModal() {
    var modal = document.getElementById('editInstanceModal');
    var frame = document.getElementById('editInstanceFrame');
    if (!modal) return;

    if (frame) frame.src = '';
    modal.classList.add('hidden');
    modal.classList.remove('flex');
  }

  function handleInstanceSaved() {
    closeEditInstanceModal();
    window.location.reload();
  }
  window.handleInstanceSaved = handleInstanceSaved;

  // คลิกพื้นหลังเพื่อปิด editInstanceModal
  (function () {
    var modal = document.getElementById('editInstanceModal');
    if (!modal) return;

    modal.addEventListener('click', function (e) {
      if (e.target === modal) {
        closeEditInstanceModal();
      }
    });
  })();
</script>

</body>
</html>

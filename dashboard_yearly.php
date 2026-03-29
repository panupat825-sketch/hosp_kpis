<?php
// dashboard_yearly.php — สรุป KPI แบบรายปี (รองรับช่วงหลายปี + template_id + anchor)
include 'db_connect.php';
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
require_once __DIR__ . '/auth.php';
require_login();
$u = current_user();

/* ====== Master สำหรับตัวกรอง ====== */
/* ไม่มีการใช้หมวดหมู่ (Category) แล้ว */

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
$dept_map = array();
if ($rs = mysqli_query($conn, "SELECT id, department_name FROM tb_departments ORDER BY department_name ASC")) {
  while ($r = mysqli_fetch_assoc($rs)) $dept_map[(int)$r['id']] = $r['department_name'];
  mysqli_free_result($rs);
}
$fiscal_years_list = array();
if ($rs = mysqli_query($conn, "SELECT DISTINCT fiscal_year FROM tb_kpi_instances ORDER BY fiscal_year DESC")) {
  while ($r = mysqli_fetch_assoc($rs)) $fiscal_years_list[(int)$r['fiscal_year']] = (int)$r['fiscal_year'];
  mysqli_free_result($rs);
}

/* หาค่าน้อยสุด–มากสุดของปีงบ */
$min_fiscal_year = null;
$max_fiscal_year = null;
foreach ($fiscal_years_list as $fy) {
  if ($min_fiscal_year === null || $fy < $min_fiscal_year) $min_fiscal_year = $fy;
  if ($max_fiscal_year === null || $fy > $max_fiscal_year) $max_fiscal_year = $fy;
}

/* ====== Helper ====== */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function log_app_error($message, $context){
  error_log('[hosp_kpis] ' . $message . ' | ' . json_encode($context));
}
function normalize_fiscal_year($raw){
  $raw = trim((string)$raw);
  if ($raw === '') return 0;
  if (!preg_match('/^\d{4}$/', $raw)) return false;
  $year = (int)$raw;
  if ($year >= 2000 && $year <= 2200) $year += 543;
  if ($year < 2500 || $year > 2800) return false;
  return $year;
}

function make_kpi_anchor($si, $mi, $kpi){
  return 'kpi_anchor_' . md5($si.'|'.$mi.'|'.$kpi);
}

/* สำหรับตัดคำว่า ต่อปี / /ปี ออกจากหน่วย (ใช้กับ agg_type = SUM) */
function normalize_sum_unit($unit){
  $u = trim((string)$unit);
  if ($u === '') return $u;
  $patterns = array('ต่อปี','/ปี');
  foreach ($patterns as $p){
    $u = str_replace($p, '', $u);
  }
  return trim($u);
}

function compute_status_class($op,$t,$a){
  $class = "bg-gray-100 border-l-4 border-gray-400";
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

/**
 * สรุปค่ารายปีสำหรับ KPI หนึ่งตัวในปีหนึ่ง
 */
function summarize_year($agg_type_raw, $quarters){
  $order = array('Q1','Q2','Q3','Q4');

  $agg_type = strtoupper((string)$agg_type_raw);
  if ($agg_type !== 'SUM') $agg_type = 'AVG';

  $sumT = 0.0;
  $sumA = 0.0;
  $qCount = 0;
  $opFirst = null;
  $unit_raw = '';
  $perQTarget = array();
  $perQActual = array();

  foreach ($order as $qk) {
    if (!isset($quarters[$qk])) continue;
    $r = $quarters[$qk];
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
    $base_unit    = normalize_sum_unit($unit_raw);
    $unit_display = $base_unit ? ($base_unit.' (สะสม)') : '(สะสม)';
  } else {
    $unit_display = $unit_raw;
  }

  $badge_percent = 0;
  $diffVal  = 0;
  $diffAbs  = 0;
  $diffText = '';
  $diffClass = 'bg-red-100 text-red-700 border-red-300';

  if ($agg_type === 'SUM') {
    $yearTarget = !empty($perQTarget) ? $sumT : 0.0;
    $yearActual = $sumA;

    if ($opFirst === '<' || $opFirst === '<=') {
        if ($yearActual <= 0) {
            $badge_percent = 100;
        } elseif ($yearTarget > 0) {
            $badge_percent = ($yearTarget / $yearActual) * 100;
        } else {
            $badge_percent = 0;
        }
    } else {
        $badge_percent = ($yearTarget > 0)
            ? ($yearActual / $yearTarget) * 100
            : 0;
    }
    $badge_percent = max(0, min(999, round($badge_percent)));

    $diffVal  = $yearActual - $yearTarget;
    $diffAbs  = abs($diffVal);
    $diffSign = $diffVal > 0 ? '+' : ($diffVal < 0 ? '−' : '');
    $diffText = $diffSign . number_format($diffAbs, 2);

    if ($opFirst === '>' || $opFirst === '>=') {
      if ($yearActual >= $yearTarget) $diffClass = 'bg-emerald-100 text-emerald-700 border-emerald-300';
    } elseif ($opFirst === '<' || $opFirst === '<=') {
      if ($yearActual <= $yearTarget) $diffClass = 'bg-emerald-100 text-emerald-700 border-emerald-300';
    } else {
      if (abs($diffVal) < 0.000001) $diffClass = 'bg-emerald-100 text-emerald-700 border-emerald-300';
    }

    $gaugePercent = (float)max(0, min(100, $badge_percent));
    $ringColor = '#dc2626';
    if ($badge_percent >= 100) {
      $ringColor = '#16a34a';
    } elseif ($badge_percent >= 80) {
      $ringColor = '#facc15';
    }

    return array(
      'is_sum'        => true,
      'unit_display'  => $unit_display,
      'opFirst'       => $opFirst,
      'yearTarget'    => $yearTarget,
      'yearActual'    => $yearActual,
      'avgT'          => 0,
      'avgA'          => 0,
      'badge_percent' => $badge_percent,
      'gaugePercent'  => $gaugePercent,
      'ringColor'     => $ringColor,
      'diffText'      => $diffText,
      'diffClass'     => $diffClass
    );
  } else {
    $avgT = ($qCount > 0) ? $sumT / $qCount : 0;
    $avgA = ($qCount > 0) ? $sumA / $qCount : 0;

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

    if ($opFirst === '<' || $opFirst === '<=') {
        if ($avgA <= 0) {
            $badge_percent = 100;
        } elseif ($avgT > 0) {
            $badge_percent = ($avgT / $avgA) * 100;
        } else {
            $badge_percent = 0;
        }
    } else {
        $badge_percent = ($avgT > 0)
            ? ($avgA / $avgT) * 100
            : 0;
    }
    $badge_percent = max(0, min(999, round($badge_percent)));
    $gaugePercent  = (float)max(0, min(100, $badge_percent));
    $ringColor = '#dc2626';
    if ($badge_percent >= 100) {
      $ringColor = '#16a34a';
    } elseif ($badge_percent >= 80) {
      $ringColor = '#facc15';
    }

    return array(
      'is_sum'        => false,
      'unit_display'  => $unit_display,
      'opFirst'       => $opFirst,
      'yearTarget'    => 0,
      'yearActual'    => 0,
      'avgT'          => $avgT,
      'avgA'          => $avgA,
      'badge_percent' => $badge_percent,
      'gaugePercent'  => $gaugePercent,
      'ringColor'     => $ringColor,
      'diffText'      => $diffText,
      'diffClass'     => $diffClass
    );
  }
}

/* ====== รับตัวกรองจาก GET ====== */
$get = function($k){ return isset($_GET[$k]) ? trim($_GET[$k]) : ''; };
$page_error = '';

$filter_strategic   = $get('strategic_issue');
$filter_mission     = $get('mission');
$filter_template_id = $get('template_id');  // ยังใช้สำหรับ anchor จากหน้าอื่น
$filter_keyword     = $get('search_text');   // คำค้น
$filter_category_id = $get('category_id');
$filter_department_id = $get('department_id');

/* ปีงบประมาณช่วงจาก–ถึง */
$year_from_input = $get('year_from');
$year_to_input   = $get('year_to');
$year_from = normalize_fiscal_year($year_from_input);
$year_to   = normalize_fiscal_year($year_to_input);

if (($year_from_input !== '' && $year_from === false) || ($year_to_input !== '' && $year_to === false)) {
  $page_error = 'Fiscal year range must be valid BE or CE years.';
  $year_from = 0;
  $year_to = 0;
}
$filter_category_id = ctype_digit($filter_category_id) ? (int)$filter_category_id : 0;
$filter_department_id = ctype_digit($filter_department_id) ? (int)$filter_department_id : 0;

/* ถ้าไม่ส่งมา ให้ default เป็นช่วง 5 ปีล่าสุดเท่าที่มีข้อมูล */
if ($min_fiscal_year !== null && $max_fiscal_year !== null) {
  if ($year_to <= 0)   $year_to = $max_fiscal_year;
  if ($year_from <= 0) $year_from = max($min_fiscal_year, $year_to - 4);
  if ($year_from < $min_fiscal_year) $year_from = $min_fiscal_year;
  if ($year_to > $max_fiscal_year)   $year_to   = $max_fiscal_year;
  if ($year_from > $year_to) {
    $tmp = $year_from;
    $year_from = $year_to;
    $year_to = $tmp;
  }
}

/* ====== WHERE ====== */
$where_parts = array();
$where_types = '';
$where_params = array();
if ($year_from > 0 && $year_to > 0) {
  $where_parts[] = "i.fiscal_year BETWEEN ? AND ?";
  $where_types .= 'ii';
  $where_params[] = (int)$year_from;
  $where_params[] = (int)$year_to;
}
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

/* คำค้น (ชื่อ KPI / description / strategic / mission / strategy name) */
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

/* ====== Query หลัก (ดึงแต่ละ quarter) ====== */
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
  i.department_id,
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
  t.agg_type,
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
ORDER BY t.strategic_issue ASC,
         t.mission ASC,
         t.kpi_name ASC,
         i.fiscal_year ASC,
         quarter_sort ASC
";
$result = false;
$query_started = perf_now();
if ($st = mysqli_prepare($conn, $sql)) {
  if (!db_bind_params($st, $where_types, $where_params)) {
    log_app_error('Yearly dashboard bind failed', array('db_error' => mysqli_error($conn)));
    $page_error = 'Unable to load KPI data right now. Please adjust the filter or try again.';
  } elseif (mysqli_stmt_execute($st)) {
    $result = mysqli_stmt_get_result($st);
    perf_log_if_slow('dashboard_yearly.main_query', $query_started, array(
      'filters' => array(
        'year_from' => $year_from,
        'year_to' => $year_to,
        'template_id' => $filter_template_id,
        'category_id' => $filter_category_id,
        'department_id' => $filter_department_id
      )
    ));
    if ($result === false) {
      log_app_error('Yearly dashboard result fetch failed', array('db_error' => mysqli_error($conn)));
      $page_error = 'Unable to load KPI data right now. Please adjust the filter or try again.';
    }
  } else {
    log_app_error('Yearly dashboard execute failed', array('db_error' => mysqli_error($conn)));
    $page_error = 'Unable to load KPI data right now. Please adjust the filter or try again.';
  }
  mysqli_stmt_close($st);
} else {
  log_app_error('Yearly dashboard query failed', array(
    'db_error' => mysqli_error($conn),
    'filters' => array(
      'year_from' => $year_from,
      'year_to' => $year_to,
      'strategic_issue' => $filter_strategic,
      'mission' => $filter_mission,
      'category_id' => $filter_category_id,
      'department_id' => $filter_department_id,
      'template_id' => $filter_template_id
    )
  ));
  $page_error = 'Unable to load KPI data right now. Please adjust the filter or try again.';
}

/* -------- สร้างโครง data -------- */
$data = array();
if ($result) while ($row = mysqli_fetch_assoc($result)) {
  $si    = (string)$row['strategic_issue'];
  $mi    = (string)$row['mission'];
  $fy    = (string)$row['fiscal_year'];
  $qt    = (string)$row['quarter'];     // 'Q1'..'Q4' หรือ NULL
  $kname = (string)$row['kpi_name'];

  if (!isset($data[$si])) {
    $data[$si] = array('name'=>$si, 'missions'=>array());
  }
  if (!isset($data[$si]['missions'][$mi])) {
    $data[$si]['missions'][$mi] = array('name'=>$mi, 'kpis'=>array());
  }
  if (!isset($data[$si]['missions'][$mi]['kpis'][$kname])) {
    $data[$si]['missions'][$mi]['kpis'][$kname] = array(
      'template' => array(
        'description'    => $row['description'],
        'strategy_name'  => ($row['strategy_name'] ? $row['strategy_name'] : 'N/A'),
        'template_id'    => (int)$row['template_id'],
        'agg_type'       => isset($row['agg_type']) ? $row['agg_type'] : 'AVG'
      ),
      'years' => array()
    );
  }

  if (!$qt) continue;

  if (!isset($data[$si]['missions'][$mi]['kpis'][$kname]['years'][$fy])) {
    $data[$si]['missions'][$mi]['kpis'][$kname]['years'][$fy] = array(
      'quarters' => array()
    );
  }

  $data[$si]['missions'][$mi]['kpis'][$kname]['years'][$fy]['quarters'][$qt] = array(
    'instance_id' => (int)$row['id'],
    'target'      => $row['target_value'],
    'actual'      => $row['actual_value'],
    'variance'    => $row['variance'],
    'status'      => $row['status'],
    'unit'        => $row['unit'],
    'operation'   => $row['operation'],
    'progress'    => 0, // ยังไม่ใช้ในหน้านี้
    'last_update' => isset($row['last_update']) ? $row['last_update'] : ''
  );
}
if ($result) mysqli_free_result($result);
mysqli_close($conn);

/* เตรียม config chart รายปีต่อ KPI */
$charts = array();
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Hospital KPI Dashboard - Yearly View</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <style>
    .donut-gauge {
      --value: 0;
      --color: #22c55e;
      --thickness: 12px;
      position: relative;
      width: 110px;
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
      font-size: 0.9rem;
      font-weight: 700;
      color: #374151;
      text-align: center;
      line-height: 1.1;
    }
    .kpi-anchor {
      scroll-margin-top: 120px; /* ให้เลื่อนแล้วไม่โดน navbar บัง */
    }
  </style>
</head>
<body class="bg-slate-100">
<?php
  $active_nav = 'dashboard_yearly';
  include __DIR__ . '/navbar_kpi.php';
  $header_actions = ''
    . '<a href="dashboard.php" class="inline-flex items-center justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-300">ดูมุมมองรายไตรมาส</a>'
    . '<a href="kpi_table.php" class="inline-flex items-center justify-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-slate-200">ตาราง KPI</a>';
  kpi_page_header(
    'แดชบอร์ด KPI รายปี',
    'สรุปแนวโน้ม KPI หลายปีด้วยตัวกรองที่ชัดเจน กราฟอ่านง่าย และการคงค่าสถานะตัวกรองขณะเปลี่ยนมุมมอง',
    array(
      array('label' => 'หน้าแรก', 'href' => 'index.php'),
      array('label' => 'แดชบอร์ดรายปี', 'href' => '')
    ),
    $header_actions
  );
?>

<div class="w-full px-4 sm:px-6 lg:px-8">
<div class="bg-white/95 p-5 sm:p-6 rounded-3xl shadow-xl shadow-slate-200/70 border border-slate-200">

  <!-- ตัวกรอง -->
  <form method="get" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-10 gap-3 mb-6 rounded-3xl border border-slate-200 bg-slate-50/90 p-4 sm:p-5 shadow-inner shadow-slate-100">
    <div class="flex flex-col">
      <label for="year_from" class="text-xs font-medium text-slate-700 mb-1">ปีงบประมาณจาก</label>
      <select id="year_from" name="year_from" class="p-2.5 border border-slate-300 rounded-lg bg-white focus:outline-none focus:ring-2 focus:ring-sky-200">
        <?php
          $years_sorted = $fiscal_years_list;
          sort($years_sorted);
          foreach ($years_sorted as $y):
        ?>
          <option value="<?php echo (int)$y; ?>" <?php echo ($y == $year_from ? 'selected' : ''); ?>>
            <?php echo h($y); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="flex flex-col">
      <label for="year_to" class="text-xs font-medium text-slate-700 mb-1">ปีงบประมาณถึง</label>
      <select id="year_to" name="year_to" class="p-2.5 border border-slate-300 rounded-lg bg-white focus:outline-none focus:ring-2 focus:ring-sky-200">
        <?php
          rsort($years_sorted);
          foreach ($years_sorted as $y):
        ?>
          <option value="<?php echo (int)$y; ?>" <?php echo ($y == $year_to ? 'selected' : ''); ?>>
            <?php echo h($y); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="flex flex-col">
      <label for="yearly_strategic_issue" class="text-xs font-medium text-slate-700 mb-1">ประเด็นยุทธศาสตร์</label>
      <select id="yearly_strategic_issue" name="strategic_issue" class="p-2.5 border border-slate-300 rounded-lg bg-white focus:outline-none focus:ring-2 focus:ring-sky-200">
        <option value="">ทั้งหมด</option>
        <?php foreach($strategic_issues_list as $si): ?>
          <option value="<?php echo h($si); ?>" <?php echo ($si===$filter_strategic?'selected':''); ?>>
            <?php echo h($si); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="flex flex-col">
      <label for="yearly_mission" class="text-xs font-medium text-slate-700 mb-1">เป้าประสงค์</label>
      <select id="yearly_mission" name="mission" class="p-2.5 border border-slate-300 rounded-lg bg-white focus:outline-none focus:ring-2 focus:ring-sky-200">
        <option value="">ทั้งหมด</option>
        <?php foreach($missions_list as $mi): ?>
          <option value="<?php echo h($mi); ?>" <?php echo ($mi===$filter_mission?'selected':''); ?>>
            <?php echo h($mi); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="flex flex-col">
      <label for="yearly_category" class="text-xs font-medium text-slate-700 mb-1">หมวดหมู่</label>
      <select id="yearly_category" name="category_id" class="p-2.5 border border-slate-300 rounded-lg bg-white focus:outline-none focus:ring-2 focus:ring-sky-200">
        <option value="">ทั้งหมด</option>
        <?php foreach($categories_list as $cid => $cname): ?>
          <option value="<?php echo (int)$cid; ?>" <?php echo ($cid===$filter_category_id?'selected':''); ?>>
            <?php echo h($cname); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="flex flex-col">
      <label for="yearly_department" class="text-xs font-medium text-slate-700 mb-1">หน่วยงาน</label>
      <select id="yearly_department" name="department_id" class="p-2.5 border border-slate-300 rounded-lg bg-white focus:outline-none focus:ring-2 focus:ring-sky-200">
        <option value="">ทั้งหมด</option>
        <?php foreach($dept_map as $did => $dname): ?>
          <option value="<?php echo (int)$did; ?>" <?php echo ($did===$filter_department_id?'selected':''); ?>>
            <?php echo h($dname); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="flex flex-col md:col-span-2 xl:col-span-2">
      <label for="yearly_search" class="text-xs font-medium text-slate-700 mb-1">คำค้น (KPI / คำอธิบาย / กลยุทธ์)</label>
      <input
        type="text"
        id="yearly_search"
        name="search_text"
        class="p-2.5 border border-slate-300 rounded-lg bg-white focus:outline-none focus:ring-2 focus:ring-sky-200"
        placeholder="พิมพ์คำค้นเพื่อกรอง KPI"
        value="<?php echo h($filter_keyword); ?>"
      />
    </div>

    <div class="flex flex-col gap-2 md:col-span-2 xl:col-span-2 md:items-stretch">
      <label class="text-xs text-gray-600 mb-1 invisible md:visible"> </label>
      <div class="flex flex-col 2xl:flex-row gap-2 w-full">
        <button class="px-4 py-2.5 bg-slate-900 text-white rounded-lg w-full focus:outline-none focus:ring-2 focus:ring-slate-300">
          กรองข้อมูล
        </button>
        <a href="dashboard_yearly.php"
           class="px-4 py-2.5 bg-white border border-slate-300 text-slate-800 rounded-lg w-full text-center focus:outline-none focus:ring-2 focus:ring-slate-200">
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
      <div class="text-sm">ลองปรับช่วงปีงบประมาณ หมวดหมู่ หรือหน่วยงาน แล้วค้นหาใหม่</div>
    </div>
  <?php else: ?>

    <?php foreach ($data as $si_name => $si_pack): ?>
      <div class="border-t-4 border-gray-300 mt-10 pt-6">
        <div class="bg-black p-4 rounded-lg mb-6">
          <h2 class="text-xl font-bold text-white">
            ประเด็นยุทธศาสตร์: <?php echo h($si_pack['name']); ?>
          </h2>
        </div>

        <?php foreach ($si_pack['missions'] as $mi_name => $mission_pack): ?>
          <div class="bg-yellow-100 p-3 rounded mb-4">
            <h3 class="text-lg font-semibold text-yellow-800">
              เป้าประสงค์: <?php echo h($mission_pack['name']); ?>
            </h3>
          </div>

          <?php
            $kpis = $mission_pack['kpis'];
            if (is_array($kpis)) {
              ksort($kpis, SORT_NATURAL | SORT_FLAG_CASE);
            }
          ?>

          <?php if (empty($kpis)): ?>
            <div class="p-4 text-gray-600">ไม่พบ KPI สำหรับช่วงปีนี้</div>
          <?php else: ?>

            <?php foreach ($kpis as $kpi_name => $kpi_pack): ?>
              <?php
                $tpl   = $kpi_pack['template'];
                $years = $kpi_pack['years'];

                // แสดงเฉพาะปีที่อยู่ในช่วง year_from–year_to และมีข้อมูลจริง
                $years_filtered = array();
                foreach ($years as $fy => $yd) {
                  $fy_int = (int)$fy;
                  if ($fy_int >= $year_from && $fy_int <= $year_to) {
                    $years_filtered[$fy_int] = $yd;
                  }
                }
                if (empty($years_filtered)) continue;
                ksort($years_filtered, SORT_NUMERIC);

                $anchor_id = make_kpi_anchor($si_name, $mi_name, $kpi_name);

                // -------- summary รายปี + เตรียมกราฟ --------
                $per_year_summary = array();
                $labels_years     = array();
                $seriesT          = array();
                $seriesA          = array();
                $unit_display_any = '';
                foreach ($years_filtered as $fy_int => $year_pack) {
                  $summary = summarize_year(
                    isset($tpl['agg_type']) ? $tpl['agg_type'] : 'AVG',
                    isset($year_pack['quarters']) ? $year_pack['quarters'] : array()
                  );
                  $per_year_summary[$fy_int] = $summary;

                  $labels_years[] = (string)$fy_int;
                  if ($summary['is_sum']) {
                    $t = $summary['yearTarget'];
                    $a = $summary['yearActual'];
                  } else {
                    $t = $summary['avgT'];
                    $a = $summary['avgA'];
                  }
                  $seriesT[] = $t;
                  $seriesA[] = $a;
                  if ($unit_display_any === '' && $summary['unit_display'] !== '') {
                    $unit_display_any = $summary['unit_display'];
                  }
                }

                // -------- config กราฟรายปี --------
                $vals = array();
                foreach ($seriesT as $v) if (is_numeric($v)) $vals[] = (float)$v;
                foreach ($seriesA as $v) if (is_numeric($v)) $vals[] = (float)$v;
                $ymin = 0; $ymax = 0;
                if (!empty($vals)) {
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

                $chart_id = 'chart_year_'.md5($si_name.'|'.$mi_name.'|'.$kpi_name.'|'.$year_from.'_'.$year_to.'_'.uniqid('',true));
                $charts[] = array(
                  'id'     => $chart_id,
                  'labels' => $labels_years,
                  't'      => $seriesT,
                  'a'      => $seriesA,
                  'ymin'   => $ymin,
                  'ymax'   => $ymax
                );

                /* ====== สรุปภาพรวมหลายปี (สำหรับเกจด้านขวา) ======
                   - ถ้า agg_type = SUM  : ใช้ผลรวมเป้าหมาย/ค่าจริงทุกปี
                   - ถ้า agg_type = AVG  : ใช้ค่าเฉลี่ยของค่าเฉลี่ยรายปีทุกปี
                */
                $year_keys = array_keys($per_year_summary);
                sort($year_keys, SORT_NUMERIC);

                $agg_type_multi_raw = isset($tpl['agg_type']) ? strtoupper($tpl['agg_type']) : 'AVG';
                if ($agg_type_multi_raw !== 'SUM') $agg_type_multi_raw = 'AVG';
                $is_sum_multi = ($agg_type_multi_raw === 'SUM');

                $op_multi   = null;
                $unit_multi = $unit_display_any;
                $badge_multi = 0;
                $gauge_multi = 0;
                $ringColor   = '#dc2626';
                $diffText_multi  = '';
                $diffClass_multi = 'bg-red-100 text-red-700 border-red-300';
                $t_multi = 0.0;
                $a_multi = 0.0;
                $text_head_multi   = '';
                $label_compare_multi = '';

                if (!empty($year_keys)) {
                  $first_summary = $per_year_summary[$year_keys[0]];
                  $op_multi = $first_summary['opFirst'];
                  if ($unit_multi === '' && $first_summary['unit_display'] !== '') {
                    $unit_multi = $first_summary['unit_display'];
                  }

                  if ($is_sum_multi) {
                    $sumT_multi = 0.0;
                    $sumA_multi = 0.0;
                    foreach ($year_keys as $yk) {
                      $sumT_multi += (float)$per_year_summary[$yk]['yearTarget'];
                      $sumA_multi += (float)$per_year_summary[$yk]['yearActual'];
                    }
                    $t_multi = $sumT_multi;
                    $a_multi = $sumA_multi;

                    if ($op_multi === '<' || $op_multi === '<=') {
                      if ($a_multi <= 0) {
                        $badge_multi = 100;
                      } elseif ($t_multi > 0) {
                        $badge_multi = ($t_multi / $a_multi) * 100;
                      } else {
                        $badge_multi = 0;
                      }
                    } else {
                      $badge_multi = ($t_multi > 0) ? ($a_multi / $t_multi) * 100 : 0;
                    }
                    $badge_multi = max(0, min(999, round($badge_multi)));
                    $gauge_multi = max(0, min(100, (float)$badge_multi));

                    $diffVal_multi  = $a_multi - $t_multi;
                    $diffAbs_multi  = abs($diffVal_multi);
                    $diffSign_multi = $diffVal_multi > 0 ? '+' : ($diffVal_multi < 0 ? '−' : '');
                    $diffText_multi = $diffSign_multi . number_format($diffAbs_multi, 2);

                    if ($op_multi === '>' || $op_multi === '>=') {
                      if ($a_multi >= $t_multi) $diffClass_multi = 'bg-emerald-100 text-emerald-700 border-emerald-300';
                    } elseif ($op_multi === '<' || $op_multi === '<=') {
                      if ($a_multi <= $t_multi) $diffClass_multi = 'bg-emerald-100 text-emerald-700 border-emerald-300';
                    } else {
                      if (abs($diffVal_multi) < 0.000001) $diffClass_multi = 'bg-emerald-100 text-emerald-700 border-emerald-300';
                    }

                    if ($badge_multi >= 100)      $ringColor = '#16a34a';
                    elseif ($badge_multi >= 80)   $ringColor = '#facc15';

                    $text_head_multi     = 'ช่วงปี ' . $year_from . '–' . $year_to . ' (ค่ารวมสะสมทุกปี)';
                    $label_compare_multi = 'เปรียบเทียบเป้า : ค่าจริง (สะสมรวมทุกปี)';
                  } else {
                    $sumAvgT_multi = 0.0;
                    $sumAvgA_multi = 0.0;
                    foreach ($year_keys as $yk) {
                      $sumAvgT_multi += (float)$per_year_summary[$yk]['avgT'];
                      $sumAvgA_multi += (float)$per_year_summary[$yk]['avgA'];
                    }
                    $countYears = count($year_keys);
                    $t_multi = $countYears > 0 ? $sumAvgT_multi / $countYears : 0;
                    $a_multi = $countYears > 0 ? $sumAvgA_multi / $countYears : 0;

                    $diffVal_multi  = $a_multi - $t_multi;
                    $diffAbs_multi  = abs($diffVal_multi);
                    $diffSign_multi = $diffVal_multi > 0 ? '+' : ($diffVal_multi < 0 ? '−' : '');
                    $diffText_multi = $diffSign_multi . number_format($diffAbs_multi, 2);

                    if ($op_multi === '>' || $op_multi === '>=') {
                      if ($a_multi >= $t_multi) $diffClass_multi = 'bg-emerald-100 text-emerald-700 border-emerald-300';
                    } elseif ($op_multi === '<' || $op_multi === '<=') {
                      if ($a_multi <= $t_multi) $diffClass_multi = 'bg-emerald-100 text-emerald-700 border-emerald-300';
                    } else {
                      if (abs($diffVal_multi) < 0.000001) $diffClass_multi = 'bg-emerald-100 text-emerald-700 border-emerald-300';
                    }

                    if ($op_multi === '<' || $op_multi === '<=') {
                      if ($a_multi <= 0) {
                        $badge_multi = 100;
                      } elseif ($t_multi > 0) {
                        $badge_multi = ($t_multi / $a_multi) * 100;
                      } else {
                        $badge_multi = 0;
                      }
                    } else {
                      $badge_multi = ($t_multi > 0) ? ($a_multi / $t_multi) * 100 : 0;
                    }
                    $badge_multi = max(0, min(999, round($badge_multi)));
                    $gauge_multi = max(0, min(100, (float)$badge_multi));

                    if ($badge_multi >= 100)      $ringColor = '#16a34a';
                    elseif ($badge_multi >= 80)   $ringColor = '#facc15';

                    $text_head_multi     = 'ช่วงปี ' . $year_from . '–' . $year_to . ' (ค่าเฉลี่ยทุกปี)';
                    $label_compare_multi = 'เปรียบเทียบเป้า : ค่าจริง (ค่าเฉลี่ยทุกปี)';
                  }
                }
              ?>

              <div id="<?php echo h($anchor_id); ?>" class="kpi-anchor mb-8">
                <div class="flex items-start justify-between gap-3 mb-3">
                  <div>
                    <h4 class="text-lg font-semibold text-blue-700">
                      KPI: <?php echo h($kpi_name); ?>
                    </h4>
                    <p class="text-gray-700 text-sm">
                      <strong>Description:</strong> <?php echo h($tpl['description']); ?>
                    </p>
                    <?php if (!empty($tpl['strategy_name']) && $tpl['strategy_name'] !== 'N/A'): ?>
                      <div class="mt-1 flex flex-wrap gap-1">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-blue-50 text-blue-700 border border-blue-200">
                          กลยุทธ์: <?php echo h($tpl['strategy_name']); ?>
                        </span>
                      </div>
                    <?php endif; ?>
                  </div>
                  <div class="text-xs text-gray-500 text-right">
                    ช่วงปีที่แสดง:
                    <?php echo h($year_from); ?> – <?php echo h($year_to); ?><br>
                    (ถ้าปีใดไม่มีข้อมูล จะไม่แสดงการ์ดของปีนั้น)
                  </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                  <!-- การ์ดปีงบประมาณ (ซ้าย 2 คอลัมน์) -->
                  <div class="lg:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach ($years_filtered as $fy_int => $year_pack): ?>
                      <?php
                        $summary = $per_year_summary[$fy_int];
                        $is_sum        = $summary['is_sum'];
                        $unit_display  = $summary['unit_display'];
                        $opFirst       = $summary['opFirst'];
                        $yearTarget    = $summary['yearTarget'];
                        $yearActual    = $summary['yearActual'];
                        $avgT          = $summary['avgT'];
                        $avgA          = $summary['avgA'];
                        $badge_percent = $summary['badge_percent'];

                        if ($is_sum) {
                          $t_for_status = $yearTarget;
                          $a_for_status = $yearActual;
                          $text_target  = 'เป้าหมายทั้งปี: '
                                          . h($opFirst ? $opFirst.' ' : '')
                                          . number_format($yearTarget,2)
                                          . ' ' . h($unit_display ?: '');
                          $text_actual  = 'ค่ารวมสะสมทั้งปี: '
                                          . number_format($yearActual,2)
                                          . ' ' . h($unit_display ?: '');
                        } else {
                          $t_for_status = $avgT;
                          $a_for_status = $avgA;
                          $text_target  = 'เป้าเฉลี่ยต่อไตรมาส: '
                                          . h($opFirst ? $opFirst.' ' : '')
                                          . number_format($avgT,2)
                                          . ' ' . h($unit_display ?: '');
                          $text_actual  = 'ค่าจริงเฉลี่ยต่อไตรมาส: '
                                          . number_format($avgA,2)
                                          . ' ' . h($unit_display ?: '');
                        }

                        $cardClass = compute_status_class($opFirst, $t_for_status, $a_for_status);

                        // anchor ฝั่ง dashboard.php
                        $quarter_anchor = 'kpiq_' . md5($si_name.'|'.$mi_name.'|'.$kpi_name.'|'.$fy_int);

                        // ลิงก์ไปหน้าไตรมาสของปีนี้ + แสดงเฉพาะ KPI นี้
                        $q_link = 'dashboard.php'
                                  . '?fiscal_year='     . urlencode($fy_int)
                                  . '&strategic_issue=' . urlencode($si_name)
                                  . '&mission='         . urlencode($mi_name)
                                  . '&template_id='     . urlencode($tpl['template_id'])
                                  . '#' . rawurlencode($quarter_anchor);
                      ?>
                      <a href="<?php echo h($q_link); ?>" class="block hover:shadow-md transition">
                        <div class="rounded-lg shadow border <?php echo $cardClass; ?> p-4">
                          <div class="font-semibold text-gray-800 mb-1">
                            ปีงบประมาณ: <?php echo h($fy_int); ?>
                          </div>
                          <p class="text-xs md:text-sm text-gray-700">
                            <?php echo $text_target; ?>
                          </p>
                          <p class="text-xs md:text-sm text-gray-700">
                            <?php echo $text_actual; ?>
                          </p>

                          <div class="mt-3 bg-gray-200 rounded-full h-3 overflow-hidden">
                            <div class="bg-blue-500 h-3 rounded-full"
                                 style="width: <?php echo (int)min(100,max(0,$badge_percent)); ?>%;"></div>
                          </div>
                          <div class="mt-1 text-xs text-gray-600">
                            Progress: <?php echo number_format($badge_percent,2); ?>%
                          </div>

                          <div class="mt-2 text-[11px] text-right text-blue-700">
                            คลิกเพื่อดูรายละเอียดรายไตรมาสปีนี้
                          </div>
                        </div>
                      </a>
                    <?php endforeach; ?>
                  </div>

                  <!-- สรุปภาพรวมช่วงหลายปี + เกจ + กราฟ (ขวา 1 คอลัมน์) -->
                  <div class="lg:col-span-1">
                    <div class="border border-slate-200 rounded-2xl px-3 py-3 flex flex-col gap-2 h-full bg-white shadow-sm shadow-slate-200/70">
                      <div class="text-sm font-semibold text-gray-800 mb-1">
                        <?php echo h($text_head_multi); ?>
                      </div>

                      <div class="flex flex-wrap gap-3 items-start">
                        <div class="donut-gauge"
                             style="--value: <?php echo (float)$gauge_multi; ?>;
                                    --color: <?php echo $ringColor; ?>;">
                          <div class="donut-center-text">
                            <?php echo (int)$badge_multi; ?>%
                          </div>
                        </div>

                        <div class="flex-1 min-w-[170px] text-xs text-gray-700">
                          <div class="font-semibold mb-1">
                            <?php echo h($label_compare_multi); ?>
                          </div>
                          <?php if ($is_sum_multi): ?>
                            <div class="flex items-center gap-2">
                              <span class="inline-block w-2.5 h-2.5 rounded-full bg-sky-500"></span>
                              <span>
                                เป้าหมายรวมทุกปี:
                                <?php
                                  echo h($op_multi ? $op_multi.' ' : '');
                                  echo number_format($t_multi,2).' '.h($unit_multi ?: '');
                                ?>
                              </span>
                            </div>
                            <div class="flex items-center gap-2">
                              <span class="inline-block w-2.5 h-2.5 rounded-full bg-rose-500"></span>
                              <span>
                                ค่ารวมสะสมทุกปี:
                                <?php echo number_format($a_multi,2).' '.h($unit_multi ?: ''); ?>
                              </span>
                            </div>
                          <?php else: ?>
                            <div class="flex items-center gap-2">
                              <span class="inline-block w-2.5 h-2.5 rounded-full bg-sky-500"></span>
                              <span>
                                เป้าหมายเฉลี่ยทุกปี:
                                <?php
                                  echo h($op_multi ? $op_multi.' ' : '');
                                  echo number_format($t_multi,2).' '.h($unit_multi ?: '');
                                ?>
                              </span>
                            </div>
                            <div class="flex items-center gap-2">
                              <span class="inline-block w-2.5 h-2.5 rounded-full bg-rose-500"></span>
                              <span>
                                ค่าจริงเฉลี่ยทุกปี:
                                <?php echo number_format($a_multi,2).' '.h($unit_multi ?: ''); ?>
                              </span>
                            </div>
                          <?php endif; ?>

                          <div class="mt-2">
                            <span class="text-[10px] mr-1">ส่วนต่าง</span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full border
                                         text-[11px] font-semibold <?php echo $diffClass_multi; ?>">
                              <?php echo $diffText_multi.' '.h($unit_multi ?: ''); ?>
                            </span>
                          </div>
                        </div>
                      </div>

                      <div class="flex justify-between text-[11px] text-gray-600 mt-1">
                        <div>หน่วย: <?php echo h($unit_display_any ?: ($unit_multi ?: '—')); ?></div>
                        <div>เปรียบเทียบ Actual vs Target รายปี</div>
                      </div>

                      <div class="mt-1 h-40">
                        <canvas id="<?php echo h($chart_id); ?>" class="w-full h-full"></canvas>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

            <?php endforeach; // KPI ?>
          <?php endif; ?>

        <?php endforeach; // mission ?>
      </div>
    <?php endforeach; // strategic ?>
  <?php endif; ?>
</div>
</div>

<script>
  // สร้างกราฟรายปีสำหรับแต่ละ KPI
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
</script>

</body>
</html>

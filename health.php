<?php
require_once __DIR__ . '/auth.php';
apply_session_cookie_settings();
session_start();
require_once __DIR__ . '/db_connect.php';
require_login();
require_role('admin');

$active_nav = '';

function h($s){
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function detect_current_fiscal_year_be(){
    $year_ce = (int)date('Y');
    $month = (int)date('n');
    if ($month >= 10) {
        $year_ce++;
    }
    return (string)($year_ce + 543);
}

$checks = array();
$overall_ok = true;

$db_ok = false;
$db_latency_ms = null;
$db_error = '';
$db_started = microtime(true);
if ($st = mysqli_prepare($conn, 'SELECT 1')) {
    if (mysqli_stmt_execute($st)) {
        $db_ok = true;
        $db_latency_ms = round((microtime(true) - $db_started) * 1000, 2);
    } else {
        $db_error = 'Query failed';
    }
    mysqli_stmt_close($st);
} else {
    $db_error = 'Prepare failed';
}
$checks[] = array(
    'label' => 'Database Connectivity',
    'ok' => $db_ok,
    'detail' => $db_ok ? ('Connected (' . $db_latency_ms . ' ms)') : $db_error
);
if (!$db_ok) $overall_ok = false;

$timezone_name = date_default_timezone_get();
$timezone_ok = ($timezone_name !== '' && $timezone_name !== false);
$checks[] = array(
    'label' => 'Timezone',
    'ok' => $timezone_ok,
    'detail' => $timezone_ok ? $timezone_name : 'Timezone not configured'
);
if (!$timezone_ok) $overall_ok = false;

$fy_rule_text = 'Fiscal year rolls over on October 1 and stores Buddhist Era (BE) values.';
$fy_today = detect_current_fiscal_year_be();
$checks[] = array(
    'label' => 'Fiscal Year Rule',
    'ok' => true,
    'detail' => $fy_rule_text . ' Current FY: ' . $fy_today
);

$template_count = 0;
$instance_count = 0;
if ($db_ok) {
    if ($rs = mysqli_query($conn, 'SELECT COUNT(*) AS c FROM tb_kpi_templates')) {
        $row = mysqli_fetch_assoc($rs);
        $template_count = isset($row['c']) ? (int)$row['c'] : 0;
        mysqli_free_result($rs);
    }
    if ($rs = mysqli_query($conn, 'SELECT COUNT(*) AS c FROM tb_kpi_instances')) {
        $row = mysqli_fetch_assoc($rs);
        $instance_count = isset($row['c']) ? (int)$row['c'] : 0;
        mysqli_free_result($rs);
    }
}

if (!$overall_ok) {
    http_response_code(503);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>System Health | hosp_kpis</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 min-h-screen">
<?php
  include __DIR__ . '/navbar_kpi.php';
  $header_actions = '<a href="index.php" class="inline-flex items-center justify-center rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-300">กลับหน้าแรก</a>';
  kpi_page_header(
    'System Health',
    'ตรวจสอบความพร้อมเบื้องต้นก่อนปล่อยใช้งาน โดยไม่แสดงค่า config ที่เป็นความลับ',
    array(
      array('label' => 'หน้าแรก', 'href' => 'index.php'),
      array('label' => 'System Health', 'href' => '')
    ),
    $header_actions
  );
?>

<div class="w-full px-4 pb-8 sm:px-6 lg:px-8">
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 bg-white rounded-2xl border border-slate-200 shadow-sm p-5 sm:p-6">
      <div class="flex items-center justify-between gap-3 mb-4">
        <h2 class="text-lg font-semibold text-slate-900">Health Checks</h2>
        <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold <?php echo $overall_ok ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-800'; ?>">
          <?php echo $overall_ok ? 'READY' : 'ATTENTION'; ?>
        </span>
      </div>
      <div class="space-y-3">
        <?php foreach ($checks as $check): ?>
          <div class="rounded-xl border px-4 py-3 <?php echo $check['ok'] ? 'border-emerald-200 bg-emerald-50' : 'border-red-200 bg-red-50'; ?>">
            <div class="flex items-center justify-between gap-3">
              <div class="font-medium text-slate-900"><?php echo h($check['label']); ?></div>
              <div class="text-xs font-semibold <?php echo $check['ok'] ? 'text-emerald-700' : 'text-red-700'; ?>">
                <?php echo $check['ok'] ? 'OK' : 'FAIL'; ?>
              </div>
            </div>
            <div class="mt-1 text-sm text-slate-600"><?php echo h($check['detail']); ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-5 sm:p-6">
      <h2 class="text-lg font-semibold text-slate-900 mb-4">Runtime Summary</h2>
      <dl class="space-y-3 text-sm">
        <div>
          <dt class="text-slate-500">Server Time</dt>
          <dd class="font-medium text-slate-900"><?php echo h(date('Y-m-d H:i:s')); ?></dd>
        </div>
        <div>
          <dt class="text-slate-500">Timezone</dt>
          <dd class="font-medium text-slate-900"><?php echo h($timezone_name); ?></dd>
        </div>
        <div>
          <dt class="text-slate-500">Current FY (BE)</dt>
          <dd class="font-medium text-slate-900"><?php echo h($fy_today); ?></dd>
        </div>
        <div>
          <dt class="text-slate-500">KPI Templates</dt>
          <dd class="font-medium text-slate-900"><?php echo number_format($template_count); ?></dd>
        </div>
        <div>
          <dt class="text-slate-500">KPI Instances</dt>
          <dd class="font-medium text-slate-900"><?php echo number_format($instance_count); ?></dd>
        </div>
        <div>
          <dt class="text-slate-500">Slow Query Logging</dt>
          <dd class="font-medium text-slate-900"><?php echo perf_logging_enabled() ? 'Enabled' : 'Disabled'; ?></dd>
        </div>
      </dl>
    </div>
  </div>
</div>

</body>
</html>

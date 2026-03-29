<?php
// kpi_table.php — list KPI Instances (PHP 5.6)
include 'db_connect.php';
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
require_once __DIR__.'/auth.php';
require_login();
$u = current_user(); // ใช้ $u['fullname'], $u['role'] ได้
/* ---------- Flash helpers ---------- */
function flash_get($k){ if(empty($_SESSION['_flash'][$k]))return null; $v=$_SESSION['_flash'][$k]; unset($_SESSION['_flash'][$k]); return $v; }
function flash_set($k,$v){ $_SESSION['_flash'][$k]=$v; }
function safe($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
function isnum($v){ if($v===null || $v==='') return false; return is_numeric($v); }
function nfmt($n){ return rtrim(rtrim(number_format((float)$n,4,'.',''), '0'), '.'); }

$csrf = csrf_token();

/* ---------- DELETE (POST) ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='delete') {
  if (!has_role(array('admin', 'manager'))) {
    flash_set('error','Forbidden');
    header('Location: kpi_table.php'); exit();
  }
  if (!hash_equals($csrf, isset($_POST['csrf_token'])?$_POST['csrf_token']:'')) {
    flash_set('error','Invalid CSRF token');
    header('Location: kpi_table.php'); exit();
  }
  $id = isset($_POST['id']) ? intval($_POST['id']) : 0; // instance id
  if ($id>0) {
    if ($stmt = mysqli_prepare($conn, "DELETE FROM tb_kpi_instances WHERE id=?")) {
      mysqli_stmt_bind_param($stmt, "i", $id);
      if (mysqli_stmt_execute($stmt)) flash_set('success','ลบ KPI instance สำเร็จ');
      else flash_set('error','ลบไม่สำเร็จ: '.mysqli_error($conn));
      mysqli_stmt_close($stmt);
    } else {
      flash_set('error','เตรียมคำสั่งลบไม่สำเร็จ');
    }
  }
  header('Location: kpi_table.php'); exit();
}

/* ---------- Filters & Pagination ---------- */
$fy   = isset($_GET['fy'])  ? trim($_GET['fy'])  : '';
$qtxt = isset($_GET['q'])   ? trim($_GET['q'])   : '';
$pp   = isset($_GET['per_page']) ? max(5,min(100,intval($_GET['per_page']))) : 20;
$page = isset($_GET['page'])? max(1,intval($_GET['page'])) : 1;
$off  = ($page-1)*$pp;

$where=[]; $types=''; $params=[];
if ($fy!==''){ $where[]="i.fiscal_year=?"; $types.='i'; $params[]=$fy; }
if ($qtxt!==''){
  $where[]="(t.kpi_name LIKE ? OR t.strategic_issue LIKE ? OR t.mission LIKE ? OR i.responsible_person LIKE ? OR t.description LIKE ?)";
  $like='%'.$qtxt.'%'; for($i=0;$i<5;$i++) $params[]=$like; $types.='sssss';
}
$W = $where ? 'WHERE '.implode(' AND ',$where) : '';

/* ---------- Count ---------- */
$total=0;
$sqlCount = "SELECT COUNT(*) 
             FROM tb_kpi_instances i 
             JOIN tb_kpi_templates t ON t.id=i.template_id
             $W";
if ($stmt = mysqli_prepare($conn, $sqlCount)) {
  $count_started = perf_now();
  if (db_bind_params($stmt, $types, $params) && mysqli_stmt_execute($stmt)) {
    mysqli_stmt_bind_result($stmt, $total);
    mysqli_stmt_fetch($stmt);
    perf_log_if_slow('kpi_table.count', $count_started, array('fy' => $fy, 'q' => $qtxt));
  }
  mysqli_stmt_close($stmt);
}

/* ---------- Distinct fiscal years (filter) ---------- */
$years=[];
$rs = mysqli_query($conn,"SELECT DISTINCT fiscal_year FROM tb_kpi_instances ORDER BY fiscal_year DESC");
if ($rs){
  while($r=mysqli_fetch_assoc($rs)){
    if($r['fiscal_year']!==null && $r['fiscal_year']!=='') $years[]=$r['fiscal_year'];
  }
  mysqli_free_result($rs);
}

/* ---------- Fetch rows (paginated) ---------- */
$sql = "SELECT
          i.id AS instance_id,
          i.fiscal_year,
          i.quarter1,i.quarter2,i.quarter3,i.quarter4,
          i.operation,i.target_value,i.actual_value,i.variance,i.status,
          i.unit,i.responsible_person,i.action_plan,i.root_cause,i.suggestions,
          i.last_update,
          t.kpi_name,t.strategic_issue,t.mission,t.description
        FROM tb_kpi_instances i
        JOIN tb_kpi_templates t ON t.id=i.template_id
        $W
        ORDER BY i.fiscal_year DESC,
                 (CASE WHEN i.quarter1=1 THEN 1 WHEN i.quarter2=1 THEN 2 WHEN i.quarter3=1 THEN 3 WHEN i.quarter4=1 THEN 4 ELSE 0 END),
                 t.strategic_issue, t.mission, t.kpi_name
        LIMIT ? OFFSET ?";
$params2=$params; $types2=$types.'ii'; $params2[]=$pp; $params2[]=$off;

$rows=[];
if ($stmt = mysqli_prepare($conn,$sql)){
  $list_started = perf_now();
  db_bind_params($stmt, $types2, $params2);
  mysqli_stmt_execute($stmt);
  perf_log_if_slow('kpi_table.list', $list_started, array('fy' => $fy, 'q' => $qtxt, 'page' => $page, 'per_page' => $pp));

  if (function_exists('mysqli_stmt_get_result')) {
    $res = mysqli_stmt_get_result($stmt);
    if ($res){
      while($row=mysqli_fetch_assoc($res)) $rows[]=$row;
      mysqli_free_result($res);
    }
  } else {
    mysqli_stmt_store_result($stmt);
    $cols = array(
      'instance_id','fiscal_year','quarter1','quarter2','quarter3','quarter4',
      'operation','target_value','actual_value','variance','status',
      'unit','responsible_person','action_plan','root_cause','suggestions',
      'last_update',
      'kpi_name','strategic_issue','mission','description'
    );
    $binds = array(); $refs = array();
    foreach ($cols as $c){ $binds[$c] = null; $refs[] = &$binds[$c]; }
    call_user_func_array(array($stmt,'bind_result'), $refs);
    while (mysqli_stmt_fetch($stmt)) {
      $row=array(); foreach($cols as $c){ $row[$c]=$binds[$c]; } $rows[]=$row;
    }
  }
  mysqli_stmt_close($stmt);
}

/* ---------- Helpers ---------- */
function qbadges($r){
  $qs=[]; if(!empty($r['quarter1']))$qs[]='Q1'; if(!empty($r['quarter2']))$qs[]='Q2';
  if(!empty($r['quarter3']))$qs[]='Q3'; if(!empty($r['quarter4']))$qs[]='Q4'; return $qs;
}
function kpi_status_calc($op,$t,$a){
  if(!isnum($t)||!isnum($a)) return array('label'=>'N/A','ok'=>null);
  $t=(float)$t; $a=(float)$a; $op=trim((string)$op);
  if($op=='<'  || $op=='&lt;')  return array('label'=>$a<$t  ?'Success':'Fail','ok'=>$a<$t);
  if($op=='<=' || $op=='&lt;=') return array('label'=>$a<=$t ?'Success':'Fail','ok'=>$a<=$t);
  if($op=='>'  || $op=='&gt;')  return array('label'=>$a>$t  ?'Success':'Fail','ok'=>$a>$t);
  if($op=='>=' || $op=='&gt;=') return array('label'=>$a>=$t ?'Success':'Fail','ok'=>$a>=$t);
  if($op=='='  || $op=='==')    return array('label'=>$a==$t ?'Success':'Fail','ok'=>$a==$t);
  return array('label'=>'Unknown','ok'=>null);
}
function qbuild($arr){
  $b=$_GET; foreach($arr as $k=>$v){ $b[$k]=$v; }
  foreach(['fy','q','per_page'] as $k){ if(isset($b[$k]) && $b[$k]==='') unset($b[$k]); }
  return 'kpi_table.php?'.http_build_query($b);
}

$pages = max(1, ceil($total / $pp));
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ตารางข้อมูลตัวชี้วัด KPI | โรงพยาบาลศรีรัตนะ</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">

<?php
  // ใช้ navbar กลางเดียวกับหน้า dashboard
  $active_nav = 'kpi_instances'; // หรือชื่อ key ที่กำหนดใน navbar_kpi.php
  $active_nav = 'table';
  include __DIR__.'/navbar_kpi.php';
  $header_actions = '<a href="kpi_instance_manage.php" class="inline-flex items-center justify-center rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-300">เพิ่มหรือแก้ไขผล KPI</a>';
  kpi_page_header(
    'ตารางข้อมูล KPI',
    'ค้นหา ตรวจสอบ และจัดการข้อมูล KPI ด้วยตารางที่อ่านง่ายขึ้นบนทั้งเดสก์ท็อปและมือถือ',
    array(
      array('label' => 'หน้าแรก', 'href' => 'index.php'),
      array('label' => 'ตาราง KPI', 'href' => '')
    ),
    $header_actions
  );
?>

  <!-- MAIN CONTENT -->
  <div class="w-full px-4 sm:px-6 lg:px-8">
  <div class="bg-white p-5 sm:p-6 rounded-2xl shadow-lg border border-slate-200">

    <!-- Legacy Header -->
    <div class="hidden">
      <div>
        <h1 class="text-xl md:text-2xl font-semibold text-gray-900">
          ตารางข้อมูลตัวชี้วัด (KPI Instances)
        </h1>
        <p class="text-sm text-gray-500 mt-1">
          แสดงรายการบันทึกผลตัวชี้วัดทั้งหมด สามารถค้นหาและจัดการข้อมูลทีละรายการได้
        </p>
      </div>
      <div class="flex gap-2">
        <a href="kpi_instance_manage.php"
           class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-sm">
          ➕ เพิ่ม/แก้ไขผลตัวชี้วัด
        </a>
      </div>
    </div>

    <?php if($m=flash_get('success')): ?>
      <div role="status" class="mb-4 p-3 rounded-xl border border-emerald-300 bg-emerald-50 text-emerald-800 text-sm">
        <?php echo safe($m); ?>
      </div>
    <?php endif; ?>
    <?php if($m=flash_get('error')): ?>
      <div role="alert" class="mb-4 p-3 rounded-xl border border-red-300 bg-red-50 text-red-800 text-sm">
        <?php echo safe($m); ?>
      </div>
    <?php endif; ?>

    <!-- Filters -->
    <form class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-8 gap-3 mb-4 rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:p-5" method="get">
      <div>
        <label class="block text-sm text-gray-600 mb-1">ปีงบประมาณ</label>
        <select name="fy" class="w-full border border-slate-300 rounded-lg p-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-sky-200">
          <option value="">-- ทั้งหมด --</option>
          <?php foreach($years as $y): ?>
            <option value="<?php echo safe($y); ?>" <?php echo ($fy!=='' && (string)$fy===(string)$y)?'selected':''; ?>>
              <?php echo safe($y); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="md:col-span-2 xl:col-span-4">
        <label class="block text-sm text-gray-600 mb-1">คำค้น</label>
        <input type="text" name="q" value="<?php echo safe($qtxt); ?>"
               class="w-full border border-slate-300 rounded-lg p-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-sky-200"
               placeholder="ชื่อ KPI / ประเด็นยุทธศาสตร์ / พันธกิจ / ผู้รับผิดชอบ">
      </div>
      <div class="flex items-end gap-2 md:col-span-2 xl:col-span-3">
        <select name="per_page" class="border border-slate-300 rounded-lg p-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-sky-200">
          <?php foreach([10,20,50,100] as $opt): ?>
            <option value="<?php echo $opt; ?>" <?php echo $pp==$opt?'selected':''; ?>>
              <?php echo $opt; ?>/หน้า
            </option>
          <?php endforeach; ?>
        </select>
        <button class="px-4 py-2 bg-gray-800 text-white rounded text-sm">ค้นหา</button>
        <a class="px-4 py-2.5 bg-white border border-slate-300 text-slate-800 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-slate-200" href="kpi_table.php">
          ล้างตัวกรอง
        </a>
      </div>
    </form>

    <div class="text-sm text-slate-600 mb-3">
      พบทั้งหมด <?php echo number_format($total); ?> รายการ • หน้า <?php echo $page; ?>/<?php echo $pages; ?>
    </div>

    <?php if (empty($rows)): ?>
      <p class="mt-4 text-red-600 text-sm">ไม่พบข้อมูล KPI</p>
    <?php else: ?>
      <div class="mt-2 overflow-x-auto rounded-2xl border border-slate-200 bg-white">
        <table class="min-w-[1400px] w-full border-collapse text-sm">
          <thead>
            <tr class="bg-slate-100 text-slate-700">
              <th class="border p-2 text-center">#</th>
              <th class="border p-2 text-center">ปีงบ</th>
              <th class="border p-2 text-center">Quarter</th>
              <th class="border p-2">ประเด็นยุทธศาสตร์</th>
              <th class="border p-2">พันธกิจ</th>
              <th class="border p-2">KPI Name</th>
              <th class="border p-2 text-center">Operation</th>
              <th class="border p-2 text-center">เป้าหมาย</th>
              <th class="border p-2 text-center">ค่าจริง</th>
              <th class="border p-2 text-center">แปรปรวน</th>
              <th class="border p-2">ผู้รับผิดชอบ</th>
              <th class="border p-2">Action Plan</th>
              <th class="border p-2 text-center">Status</th>
              <th class="border p-2 text-center">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($rows as $i=>$k): ?>
            <?php
              $t = isnum($k['target_value']) ? (float)$k['target_value'] : null;
              $a = isnum($k['actual_value']) ? (float)$k['actual_value'] : null;
              $var = isnum($k['variance']) ? (float)$k['variance'] : ( (isnum($t)&&isnum($a)) ? ($t-$a) : null );
              $st = !empty($k['status']) ? array('label'=>$k['status'], 'ok'=>($k['status']==='Success')) : array('label'=>'N/A','ok'=>null);
              if ($st['label']==='N/A') {
                $st = kpi_status_calc($k['operation'],$t,$a);
              }
              $rowc = ($st['ok']===true)?'bg-emerald-50':(($st['ok']===false)?'bg-red-50':'bg-white');
              $qs = qbadges($k);
              $unit = (string)$k['unit'];
            ?>
            <tr class="<?php echo $rowc; ?> text-gray-800 align-top">
              <td class="border p-2 text-center"><?php echo ($off+$i+1); ?></td>
              <td class="border p-2 text-center"><?php echo safe($k['fiscal_year']); ?></td>
              <td class="border p-2 whitespace-nowrap">
                <?php if(empty($qs)): ?>
                  <span class="px-2 py-0.5 text-xs rounded bg-gray-100 text-gray-700">—</span>
                <?php else: foreach($qs as $qq): ?>
                  <span class="px-2 py-0.5 mr-1 text-xs rounded bg-sky-100 text-sky-800"><?php echo $qq; ?></span>
                <?php endforeach; endif; ?>
              </td>
              <td class="border p-2"><?php echo safe($k['strategic_issue']); ?></td>
              <td class="border p-2"><?php echo safe($k['mission']); ?></td>
              <td class="border p-2 font-semibold"><?php echo safe($k['kpi_name']); ?></td>
              <td class="border p-2 text-center whitespace-nowrap"><?php echo safe($k['operation']); ?></td>
              <td class="border p-2 text-center whitespace-nowrap">
                <?php echo isnum($t)? safe(nfmt($t)) : '—'; ?><?php echo $unit!==''?' '.safe($unit):''; ?>
              </td>
              <td class="border p-2 text-center whitespace-nowrap">
                <?php echo isnum($a)? safe(nfmt($a)) : '—'; ?><?php echo $unit!==''?' '.safe($unit):''; ?>
              </td>
              <td class="border p-2 text-center">
                <?php echo isnum($var)? safe(nfmt($var)):'—'; ?>
              </td>
              <td class="border p-2"><?php echo safe($k['responsible_person']); ?></td>
              <td class="border p-2"><?php echo safe($k['action_plan']); ?></td>
              <td class="border p-2 text-center whitespace-nowrap">
                <?php if ($st['ok']===true): ?>
                  <span class="inline-block px-2 py-1 text-xs rounded bg-emerald-100 text-emerald-800">
                    ✅ <?php echo safe($st['label']); ?>
                  </span>
                <?php elseif ($st['ok']===false): ?>
                  <span class="inline-block px-2 py-1 text-xs rounded bg-red-100 text-red-800">
                    ❌ <?php echo safe($st['label']); ?>
                  </span>
                <?php else: ?>
                  <span class="inline-block px-2 py-1 text-xs rounded bg-gray-100 text-gray-700">
                    ⚠️ <?php echo safe($st['label']); ?>
                  </span>
                <?php endif; ?>
              </td>
              <td class="border p-2">
                <div class="flex justify-center gap-2">
                  <a href="kpi_instance_manage.php?edit=<?php echo intval($k['instance_id']); ?>"
                     class="px-3 py-1 rounded bg-yellow-500 hover:bg-yellow-600 text-white text-xs">
                    Edit
                  </a>
                  <?php if (has_role(array('admin', 'manager'))): ?>
                    <form method="post" onsubmit="return confirm('ยืนยันการลบ KPI นี้?');">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="csrf_token" value="<?php echo safe($csrf); ?>">
                      <input type="hidden" name="id" value="<?php echo intval($k['instance_id']); ?>">
                      <button class="px-3 py-1 rounded bg-red-600 hover:bg-red-700 text-white text-xs" type="submit">
                        Delete
                      </button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div class="mt-4 flex flex-wrap items-center gap-2 text-sm">
        <?php if($page>1): ?>
          <a class="px-3 py-1 rounded border bg-white hover:bg-gray-50" href="<?php echo safe(qbuild(['page'=>1])); ?>">« หน้าแรก</a>
          <a class="px-3 py-1 rounded border bg-white hover:bg-gray-50" href="<?php echo safe(qbuild(['page'=>$page-1])); ?>">‹ ก่อนหน้า</a>
        <?php endif; ?>
        <span class="px-3 py-1">หน้า <?php echo $page; ?> / <?php echo $pages; ?></span>
        <?php if($page<$pages): ?>
          <a class="px-3 py-1 rounded border bg-white hover:bg-gray-50" href="<?php echo safe(qbuild(['page'=>$page+1])); ?>">ถัดไป ›</a>
          <a class="px-3 py-1 rounded border bg-white hover:bg-gray-50" href="<?php echo safe(qbuild(['page'=>$pages])); ?>">สุดท้าย »</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
  </div>

</body>
</html>

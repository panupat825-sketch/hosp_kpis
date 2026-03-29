<?php
// index.php
session_start();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/auth.php';
if (!isset($_SESSION['user_id']) && isset($_SESSION['app_user_id'])) {
    $_SESSION['user_id'] = $_SESSION['app_user_id'];
    $_SESSION['fullname'] = isset($_SESSION['name_th']) && $_SESSION['name_th'] !== '' ? $_SESSION['name_th'] : (isset($_SESSION['name_eng']) ? $_SESSION['name_eng'] : 'Guest');
    $_SESSION['username'] = isset($_SESSION['provider_id']) ? $_SESSION['provider_id'] : '';
    $_SESSION['role'] = isset($_SESSION['roles'][0]) ? $_SESSION['roles'][0] : 'user';
    $_SESSION['position'] = isset($_SESSION['position_name']) ? $_SESSION['position_name'] : '';
    $_SESSION['department'] = isset($_SESSION['hcode']) ? $_SESSION['hcode'] : '';
}
if (!isset($_SESSION['user_id'])) {
    header('Location: login-oauth-v2.php');
    exit();
}
require_login();

$u = current_user(); // ใช้ $u['fullname'], $u['role'] ได้

// --------- สรุปตัวเลขไว้เป็น badge ----------
$cnt_tpl = 0;
$cnt_ins = 0;

$res = mysqli_query($conn, "SELECT COUNT(*) AS c FROM tb_kpi_templates");
if ($res) {
    $r = mysqli_fetch_assoc($res);
    $cnt_tpl = (int)$r['c'];
    mysqli_free_result($res);
}
$res = mysqli_query($conn, "SELECT COUNT(*) AS c FROM tb_kpi_instances");
if ($res) {
    $r = mysqli_fetch_assoc($res);
    $cnt_ins = (int)$r['c'];
    mysqli_free_result($res);
}

// --------- ข้อมูลผู้ใช้จาก session ----------
$is_login = isset($_SESSION['user_id']);
$fullname = $is_login && isset($_SESSION['fullname']) ? $_SESSION['fullname'] : 'Guest';
$role     = isset($u['role']) ? $u['role'] : 'guest';

function h($s){
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ระบบบริหารตัวชี้วัด KPI โรงพยาบาลศรีรัตนะ</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="css/enterprise-ui.css">
</head>
<body class="p-4 md:p-6">

  <div class="enterprise-panel enterprise-glow w-full p-6 md:p-8 rounded-3xl">
    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6 border-b border-slate-200 pb-5">
      <div>
        <h1 class="text-2xl md:text-3xl font-extrabold text-gray-900 tracking-wide">
          ระบบบริหารตัวชี้วัด <span class="text-blue-700">KPI</span> โรงพยาบาลศรีรัตนะ
        </h1>
        <p class="mt-1 text-sm text-gray-500">
          เครื่องมือสำหรับบริหารจัดการตัวชี้วัดเชิงยุทธศาสตร์ การบันทึกผล การติดตามความก้าวหน้า
          และการเชื่อมโยงกับกลยุทธ์/เป้าประสงค์ขององค์กร
        </p>
      </div>

      <div class="flex flex-col items-end gap-2">
        <div class="flex items-center gap-2 text-sm text-gray-600">
          <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-blue-50 text-blue-700 font-semibold">
            👤
          </span>
          <div class="text-right">
            <div class="font-semibold text-gray-800">
              <?php echo h($fullname); ?>
            </div>
            <div class="text-xs text-gray-500">
              บทบาท: <?php echo h($role); ?>
            </div>
          </div>
        </div>
        <div class="flex gap-2">
          <?php if ($role === 'admin'): ?>
            <a href="health.php"
               class="px-3 py-1.5 text-sm rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50">
              System Health
            </a>
          <?php endif; ?>
          <a href="profile.php"
             class="px-3 py-1.5 text-sm rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50">
            ข้อมูลผู้ใช้
          </a>
          <a href="logout.php"
             class="px-3 py-1.5 text-sm rounded-lg bg-red-600 text-white hover:bg-red-700">
            ออกจากระบบ
          </a>
        </div>
      </div>
    </div>

    <!-- Summary cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
      <div class="p-5 rounded-2xl bg-gradient-to-br from-blue-50 to-cyan-50 border border-blue-200 flex items-center justify-between shadow-sm shadow-blue-100">
        <div>
          <div class="text-xs uppercase tracking-wide text-blue-700 font-semibold">
            แบบฟอร์มตัวชี้วัด (KPI Template)
          </div>
          <div class="text-3xl font-bold text-blue-900 mt-1">
            <?php echo (int)$cnt_tpl; ?>
          </div>
          <div class="text-xs text-gray-500 mt-1">
            จำนวนแบบฟอร์มตัวชี้วัดที่กำหนดไว้ในระบบ
          </div>
        </div>
        <a href="kpi_template_manage.php"
           class="px-3 py-2 text-sm bg-blue-700 text-white rounded-lg hover:bg-blue-800">
          จัดการแบบฟอร์ม
        </a>
      </div>

      <div class="p-5 rounded-2xl bg-gradient-to-br from-emerald-50 to-teal-50 border border-emerald-200 flex items-center justify-between shadow-sm shadow-emerald-100">
        <div>
          <div class="text-xs uppercase tracking-wide text-emerald-700 font-semibold">
            รายการบันทึกผล (KPI Instance)
          </div>
          <div class="text-3xl font-bold text-emerald-900 mt-1">
            <?php echo (int)$cnt_ins; ?>
          </div>
          <div class="text-xs text-gray-500 mt-1">
            จำนวนรายการบันทึกผลตัวชี้วัดที่ดำเนินการแล้ว
          </div>
        </div>
        <a href="kpi_instance_manage.php"
           class="px-3 py-2 text-sm bg-emerald-600 text-white rounded-lg hover:bg-emerald-700">
          บันทึกผลตัวชี้วัด
        </a>
      </div>
    </div>

    <!-- Main sections -->
    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">

      <!-- SECTION: รายงานและแดชบอร์ด -->
      <div class="space-y-3">
        <div class="flex items-center justify-between mb-2">
          <h2 class="text-base font-semibold text-gray-800">
            รายงานและการติดตามผลตัวชี้วัด
          </h2>
        </div>

        <a href="dashboard.php"
           class="p-5 bg-gradient-to-r from-blue-700 to-cyan-600 text-white font-semibold rounded-2xl shadow-xl shadow-blue-200 hover:from-blue-800 hover:to-cyan-700 transition flex items-center justify-between">
          <div class="flex items-center gap-3">
            <span class="text-2xl">📊</span>
            <div>
              <div>แดชบอร์ดตัวชี้วัด (รายไตรมาส)</div>
              <div class="text-xs text-blue-100 mt-1">
                แสดงผลตัวชี้วัดตามปีงบประมาณและไตรมาส พร้อมรายละเอียดรายตัวชี้วัด
              </div>
            </div>
          </div>
          <span class="text-2xl">→</span>
        </a>

        <a href="dashboard_yearly.php"
           class="p-5 bg-gradient-to-r from-slate-900 to-sky-700 text-white font-semibold rounded-2xl shadow-xl shadow-sky-200 hover:from-slate-950 hover:to-sky-800 transition flex items-center justify-between">
          <div class="flex items-center gap-3">
            <span class="text-2xl">📈</span>
            <div>
              <div>แดชบอร์ดเปรียบเทียบผลรายปี</div>
              <div class="text-xs text-sky-100 mt-1">
                เปรียบเทียบผลตัวชี้วัดหลายปีงบประมาณในมุมมองเชิงยุทธศาสตร์ / กลยุทธ์ / เป้าประสงค์
              </div>
            </div>
          </div>
          <span class="text-2xl">→</span>
        </a>

        <a href="kpi_table.php"
           class="p-5 bg-white border border-slate-200 text-gray-800 font-semibold rounded-2xl shadow-sm shadow-slate-200 hover:bg-gray-50 transition flex items-center justify-between">
          <div class="flex items-center gap-3">
            <span class="text-2xl">📋</span>
            <div>
              <div>ตารางข้อมูลตัวชี้วัด</div>
              <div class="text-xs text-gray-500 mt-1">
                ตารางสรุปรายการบันทึกผลตัวชี้วัดทั้งหมดในระบบ
              </div>
            </div>
          </div>
          <span class="text-xl text-gray-400">→</span>
        </a>
      </div>

      <!-- SECTION: จัดการข้อมูลตัวชี้วัด + Master -->
      <div class="space-y-4">

        <div class="p-5 bg-white rounded-2xl border border-slate-200 shadow-sm shadow-slate-200">
          <div class="font-semibold text-gray-800 mb-3">
            การกำหนดและบันทึกข้อมูลตัวชี้วัด
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <a href="kpi_template_manage.php"
               class="px-3 py-2 bg-indigo-600 text-white rounded-lg text-sm font-semibold hover:bg-indigo-700 text-center">
              🧩 จัดการแบบฟอร์ม KPI
            </a>
            <a href="kpi_instance_manage.php"
               class="px-3 py-2 bg-emerald-600 text-white rounded-lg text-sm font-semibold hover:bg-emerald-700 text-center">
              📝 บันทึก/แก้ไขผลตัวชี้วัด
            </a>
          </div>
        </div>

        <div class="p-5 bg-slate-50 rounded-2xl border border-slate-200 shadow-sm shadow-slate-200/70">
          <div class="font-semibold text-gray-800 mb-3">
            ⚙️ ข้อมูลอ้างอิง (Master Data)
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <a href="strategic_issues.php"
               class="px-3 py-2 bg-yellow-500 text-white rounded-lg hover:bg-yellow-600 text-sm text-center">
              ประเด็นยุทธศาสตร์
            </a>
            <a href="missions.php"
               class="px-3 py-2 bg-indigo-500 text-white rounded-lg hover:bg-indigo-600 text-sm text-center">
              เป้าประสงค์
            </a>
            <a href="strategies.php"
               class="px-3 py-2 bg-fuchsia-500 text-white rounded-lg hover:bg-fuchsia-600 text-sm text-center">
              กลยุทธ์
            </a>
            <a href="teams.php"
               class="px-3 py-2 bg-orange-500 text-white rounded-lg hover:bg-orange-600 text-sm text-center">
              ทีม / ทีมข้ามสายงาน
            </a>
            <a href="fiscal_years.php"
               class="px-3 py-2 bg-teal-500 text-white rounded-lg hover:bg-teal-600 text-sm text-center">
              ปีงบประมาณ
            </a>
            <a href="workgroups.php"
               class="px-3 py-2 bg-lime-600 text-white rounded-lg hover:bg-lime-700 text-sm text-center">
              กลุ่มงาน
            </a>
            <a href="departments.php"
               class="px-3 py-2 bg-lime-600 text-white rounded-lg hover:bg-lime-700 text-sm text-center">
              แผนก
            </a>
          </div>
        </div>

        <?php if ($role === 'admin'): ?>
        <div class="p-5 bg-red-50 rounded-2xl border border-red-200 shadow-sm shadow-red-100">
          <div class="font-semibold text-red-800 mb-3">
            👥 จัดการผู้ใช้และสิทธิ์ (เฉพาะผู้ดูแลระบบ)
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <a href="users.php"
               class="px-3 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 text-sm text-center">
              ผู้ใช้งานระบบ
            </a>
            <a href="roles.php"
               class="px-3 py-2 bg-rose-500 text-white rounded-lg hover:bg-rose-600 text-sm text-center">
              บทบาท / สิทธิ์การใช้งาน
            </a>
          </div>
        </div>
        <?php endif; ?>

      </div>
    </div>

    <!-- Footer -->
    <footer class="mt-8 pt-4 border-t text-gray-500 text-xs tracking-wide text-center">
      © 2025 ระบบบริหารตัวชี้วัด KPI โรงพยาบาลศรีรัตนะ
    </footer>
  </div>

</body>
</html>

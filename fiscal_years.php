<?php
include 'db_connect.php';
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
require_once __DIR__.'/auth.php';
require_login();
$u = current_user(); // ใช้ $u['fullname'], $u['role'] ได้

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$message = "";

/* ========== จัดการ POST: เพิ่มปีงบประมาณ ========== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['add_fiscal_year'])) {
    $year = isset($_POST['year']) ? (int)$_POST['year'] : 0;

    if ($year > 0) {
        // แนะนำให้มี UNIQUE(year) ใน tb_fiscal_years
        if ($st = mysqli_prepare($conn, "INSERT IGNORE INTO tb_fiscal_years (year) VALUES (?)")) {
            mysqli_stmt_bind_param($st, "i", $year);
            if (mysqli_stmt_execute($st)) {
                if (mysqli_stmt_affected_rows($st) > 0) {
                    $msg = "เพิ่มปีงบประมาณ $year เรียบร้อยแล้ว";
                } else {
                    $msg = "ปีงบประมาณ $year มีอยู่ในระบบแล้ว";
                }
            } else {
                $msg = "ไม่สามารถเพิ่มปีงบประมาณได้: " . mysqli_error($conn);
            }
            mysqli_stmt_close($st);
        } else {
            $msg = "ไม่สามารถเตรียมคำสั่งเพิ่มปีงบประมาณได้: " . mysqli_error($conn);
        }
    } else {
        $msg = "ค่าปีงบประมาณไม่ถูกต้อง";
    }

    header("Location: fiscal_years.php?msg=" . urlencode($msg));
    exit();
}

/* ========== ลบปีงบประมาณ (ผ่าน GET) ========== */
if (isset($_GET['delete_year'])) {
    $del_year = (int)$_GET['delete_year'];
    if ($del_year > 0) {
        if ($st = mysqli_prepare($conn, "DELETE FROM tb_fiscal_years WHERE year = ?")) {
            mysqli_stmt_bind_param($st, "i", $del_year);
            if (mysqli_stmt_execute($st)) {
                if (mysqli_stmt_affected_rows($st) > 0) {
                    $msg = "ลบปีงบประมาณ $del_year เรียบร้อยแล้ว";
                } else {
                    $msg = "ไม่พบปีงบประมาณ $del_year ในระบบ";
                }
            } else {
                $msg = "ไม่สามารถลบปีงบประมาณได้: " . mysqli_error($conn);
            }
            mysqli_stmt_close($st);
        } else {
            $msg = "ไม่สามารถเตรียมคำสั่งลบปีงบประมาณได้: " . mysqli_error($conn);
        }

        header("Location: fiscal_years.php?msg=" . urlencode($msg));
        exit();
    }
}

/* ========== รับข้อความจาก redirect (ถ้ามี) ========== */
if (isset($_GET['msg'])) {
    $message = trim($_GET['msg']);
}

/* ========== ดึงรายการปีทั้งหมด ========== */
$sql_fiscal_years = "SELECT year FROM tb_fiscal_years ORDER BY year ASC";
$result_fiscal_years = mysqli_query($conn, $sql_fiscal_years);
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>จัดการปีงบประมาณ (Fiscal Years) | ระบบบริหารตัวชี้วัด KPI โรงพยาบาลศรีรัตนะ</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 min-h-screen">

  <?php
    // Navbar มาตรฐาน (เหมือนทุกหน้า KPI)
    $active_nav = 'fiscal_years';
    include __DIR__ . '/navbar_kpi.php';
  ?>

  <!-- MAIN CONTENT -->
  <div class="w-full bg-white/95 p-6 rounded-3xl shadow-xl shadow-slate-200/70 border border-slate-200">

    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-5 border-b border-slate-200 pb-4">
      <div>
        <h1 class="text-xl md:text-2xl font-semibold text-gray-900">
          🗓 จัดการปีงบประมาณ (Fiscal Years)
        </h1>
        <p class="text-sm text-gray-500 mt-1">
          ปีงบประมาณใช้เป็นข้อมูลอ้างอิงสำหรับการบันทึกผลตัวชี้วัด (KPI Instances)
          และการจัดทำรายงาน/แดชบอร์ดตามปีงบประมาณของโรงพยาบาล
        </p>
      </div>
    </div>

    <!-- Message -->
    <?php if ($message !== ''): ?>
      <div class="mb-5 px-4 py-3 rounded-2xl border text-sm shadow-sm
                  <?php echo (strpos($message,'ไม่สามารถ')!==false || strpos($message,'Error')!==false)
                             ? 'bg-red-50 border-red-300 text-red-700'
                             : 'bg-emerald-50 border-emerald-300 text-emerald-800'; ?>">
        <?php echo h($message); ?>
      </div>
    <?php endif; ?>

    <!-- ฟอร์มเพิ่มปีงบประมาณ -->
    <div class="mb-6 p-5 rounded-2xl border border-slate-200 bg-slate-50/80 shadow-inner shadow-slate-100">
      <h2 class="text-lg font-semibold text-gray-800 mb-3">➕ เพิ่มปีงบประมาณ</h2>
      <form method="POST" class="flex flex-col md:flex-row gap-3 items-stretch md:items-center">
        <div class="flex-1">
          <label class="block text-sm text-gray-700 mb-1">ปีงบประมาณ (พ.ศ.)</label>
          <input
            type="number"
            name="year"
            placeholder="เช่น 2568"
            class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200"
            required
          >
        </div>
        <div class="pt-5 md:pt-0">
          <button
            type="submit"
            name="add_fiscal_year"
            class="px-4 py-2 bg-blue-700 text-white rounded-xl hover:bg-blue-800 text-sm shadow-sm shadow-blue-200">
            บันทึกปีงบประมาณ
          </button>
        </div>
      </form>
      <p class="mt-2 text-xs text-gray-500">
        * แนะนำให้ตั้งค่า UNIQUE(year) ในตาราง <code>tb_fiscal_years</code> เพื่อป้องกันข้อมูลซ้ำ
      </p>
    </div>

    <!-- ตารางปีงบประมาณ -->
    <div>
      <h2 class="text-lg font-semibold text-gray-800 mb-3">รายการปีงบประมาณทั้งหมด</h2>

      <div class="overflow-x-auto rounded-2xl border border-slate-200 shadow-sm shadow-slate-200/70">
        <table class="min-w-full border-collapse text-sm">
          <thead class="bg-slate-100 text-gray-700">
            <tr>
              <th class="border border-gray-300 px-3 py-2 w-16 text-left">ลำดับ</th>
              <th class="border border-gray-300 px-3 py-2 text-center">ปีงบประมาณ (พ.ศ.)</th>
              <th class="border border-gray-300 px-3 py-2 w-40 text-center">จัดการ</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($result_fiscal_years && mysqli_num_rows($result_fiscal_years) > 0): ?>
              <?php $i=1; while ($row = mysqli_fetch_assoc($result_fiscal_years)): ?>
                <?php $yr = (int)$row['year']; ?>
                <tr class="hover:bg-gray-50">
                  <td class="border border-gray-300 px-3 py-2"><?php echo $i++; ?></td>
                  <td class="border border-gray-300 px-3 py-2 text-center font-semibold text-gray-900">
                    <?php echo $yr; ?>
                  </td>
                  <td class="border border-gray-300 px-3 py-2 text-center">
                    <a href="fiscal_years.php?delete_year=<?php echo $yr; ?>"
                       onclick="return confirm('ยืนยันลบปีงบประมาณ <?php echo $yr; ?> ออกจากระบบหรือไม่?');"
                       class="px-3 py-1 bg-red-600 text-white rounded-lg hover:bg-red-700 text-xs">
                      🗑 ลบ
                    </a>
                  </td>
                </tr>
              <?php endwhile; ?>
            <?php else: ?>
              <tr>
                <td colspan="3" class="border border-gray-300 px-3 py-3 text-center text-gray-600">
                  ยังไม่มีปีงบประมาณในระบบ
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>

</body>
</html>
<?php mysqli_close($conn); ?>

<?php
// workgroups.php — จัดการกลุ่มงาน / หน่วยงาน (ใช้ตาราง tb_workgroups)
include 'db_connect.php';
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
require_once __DIR__.'/auth.php';
require_login();
$u = current_user(); // ใช้ $u['fullname'], $u['role'] ได้

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$message  = '';
$edit_id  = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

/* ---------- ค่าเริ่มต้นฟอร์ม ---------- */
$form = array(
    'id'         => 0,
    'group_code' => '',
    'group_name' => '',
    'is_active'  => 1,
    'sort_order' => 0,
);

/* ---------- โหลดข้อมูลเดิมถ้าเป็นโหมดแก้ไข (GET edit=) ---------- */
if ($edit_id > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $sql = "SELECT * FROM tb_workgroups WHERE id = ".$edit_id." LIMIT 1";
    if ($res = mysqli_query($conn, $sql)) {
        if (mysqli_num_rows($res) > 0) {
            $form = mysqli_fetch_assoc($res);
            // เผื่อค่า NULL
            if (!isset($form['group_code'])) $form['group_code'] = '';
            if (!isset($form['sort_order'])) $form['sort_order'] = 0;
        } else {
            $message = "ไม่พบกลุ่มงาน/หน่วยงานที่ต้องการแก้ไข";
        }
        mysqli_free_result($res);
    } else {
        $message = "Query error: ".mysqli_error($conn);
    }
}

/* ---------- บันทึกข้อมูล (เพิ่ม/แก้ไข) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id         = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $group_code = trim(isset($_POST['group_code']) ? $_POST['group_code'] : '');
    $group_name = trim(isset($_POST['group_name']) ? $_POST['group_name'] : '');
    $is_active  = isset($_POST['is_active']) ? 1 : 0;
    $sort_order = isset($_POST['sort_order']) && $_POST['sort_order'] !== '' ? (int)$_POST['sort_order'] : 0;

    if ($group_name === '') {
        $message = "กรุณากรอกชื่อกลุ่มงาน / หน่วยงาน";
        // คืนค่าฟอร์ม
        $form['id']         = $id;
        $form['group_code'] = $group_code;
        $form['group_name'] = $group_name;
        $form['is_active']  = $is_active;
        $form['sort_order'] = $sort_order;
    } else {
        if ($id > 0) {
            // UPDATE
            $sql = "UPDATE tb_workgroups
                       SET group_code = ?, group_name = ?, is_active = ?, sort_order = ?
                     WHERE id = ?";
            if ($st = mysqli_prepare($conn, $sql)) {
                mysqli_stmt_bind_param($st, "ssiii",
                    $group_code, $group_name, $is_active, $sort_order, $id
                );
                if (mysqli_stmt_execute($st)) {
                    mysqli_stmt_close($st);
                    header("Location: workgroups.php?message=อัปเดตกลุ่มงานเรียบร้อยแล้ว");
                    exit();
                } else {
                    $message = "ไม่สามารถอัปเดตข้อมูลได้: ".mysqli_error($conn);
                    mysqli_stmt_close($st);
                    $form['id']         = $id;
                    $form['group_code'] = $group_code;
                    $form['group_name'] = $group_name;
                    $form['is_active']  = $is_active;
                    $form['sort_order'] = $sort_order;
                }
            } else {
                $message = "Prepare failed: ".mysqli_error($conn);
            }
        } else {
            // INSERT
            $sql = "INSERT INTO tb_workgroups (group_code, group_name, is_active, sort_order)
                    VALUES (?, ?, ?, ?)";
            if ($st = mysqli_prepare($conn, $sql)) {
                mysqli_stmt_bind_param($st, "ssii",
                    $group_code, $group_name, $is_active, $sort_order
                );
                if (mysqli_stmt_execute($st)) {
                    mysqli_stmt_close($st);
                    header("Location: workgroups.php?message=เพิ่มกลุ่มงานใหม่เรียบร้อยแล้ว");
                    exit();
                } else {
                    $message = "ไม่สามารถบันทึกข้อมูลได้: ".mysqli_error($conn);
                    mysqli_stmt_close($st);
                    $form['group_code'] = $group_code;
                    $form['group_name'] = $group_name;
                    $form['is_active']  = $is_active;
                    $form['sort_order'] = $sort_order;
                }
            } else {
                $message = "Prepare failed: ".mysqli_error($conn);
            }
        }
    }
}

/* ---------- ลบข้อมูล ---------- */
if (isset($_GET['delete'])) {
    $del = (int)$_GET['delete'];
    if ($del > 0) {
        $sql = "DELETE FROM tb_workgroups WHERE id = ?";
        if ($st = mysqli_prepare($conn, $sql)) {
            mysqli_stmt_bind_param($st, "i", $del);
            if (mysqli_stmt_execute($st)) {
                mysqli_stmt_close($st);
                header("Location: workgroups.php?message=ลบกลุ่มงานเรียบร้อยแล้ว");
                exit();
            } else {
                $message = "ไม่สามารถลบข้อมูลได้: ".mysqli_error($conn);
                mysqli_stmt_close($st);
            }
        } else {
            $message = "Prepare failed: ".mysqli_error($conn);
        }
    }
}

/* ---------- ดึงรายการกลุ่มงานทั้งหมด ---------- */
$rows = array();
$sql_list = "SELECT * FROM tb_workgroups ORDER BY sort_order ASC, group_name ASC";
if ($res = mysqli_query($conn, $sql_list)) {
    while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    mysqli_free_result($res);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>จัดการกลุ่มงาน / หน่วยงาน | ระบบ KPI โรงพยาบาลศรีรัตนะ</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 min-h-screen">

  <?php
    // Navbar มาตรฐานของ KPI
    $active_nav = 'workgroups';
    include __DIR__ . '/navbar_kpi.php';
  ?>

  <!-- MAIN CONTENT -->
  <div class="w-full bg-white/95 p-6 rounded-3xl shadow-xl shadow-slate-200/70 border border-slate-200 mt-4">

    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-5 border-b border-slate-200 pb-4">
      <div>
        <h1 class="text-xl md:text-2xl font-semibold text-gray-900">
          🏢 จัดการกลุ่มงาน / หน่วยงาน (Workgroups / Departments)
        </h1>
        <p class="text-sm text-gray-500 mt-1">
          ใช้กำหนดรายชื่อกลุ่มงานหรือแผนก เพื่อผูกกับตัวชี้วัด (KPI) ในการบันทึกผลและรายงาน
        </p>
      </div>
    </div>

    <!-- System / Action message -->
    <?php if (!empty($message)): ?>
      <div class="mb-5 px-4 py-3 rounded-2xl border text-sm shadow-sm
                  <?php echo (strpos($message,'ไม่สามารถ')!==false || strpos($message,'failed')!==false)
                             ? 'bg-red-50 border-red-300 text-red-700'
                             : 'bg-emerald-50 border-emerald-300 text-emerald-800'; ?>">
        <?php echo h($message); ?>
      </div>
    <?php endif; ?>

    <?php if (isset($_GET['message']) && $_GET['message']!==''): ?>
      <div class="mb-5 px-4 py-3 rounded-2xl border text-sm bg-emerald-50 border-emerald-300 text-emerald-800 shadow-sm shadow-emerald-100">
        <?php echo h($_GET['message']); ?>
      </div>
    <?php endif; ?>

    <!-- ฟอร์มเพิ่ม/แก้ไข กลุ่มงาน -->
    <div class="mb-6 p-5 rounded-2xl border border-slate-200 bg-slate-50/80 shadow-inner shadow-slate-100">
      <h2 class="text-lg font-semibold text-gray-800 mb-3">
        <?php echo ($form['id']>0) ? 'แก้ไขกลุ่มงาน / หน่วยงาน' : 'เพิ่มกลุ่มงาน / หน่วยงานใหม่'; ?>
      </h2>

      <form method="post" class="space-y-4">
        <input type="hidden" name="id" value="<?php echo (int)$form['id']; ?>">

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <label class="block text-sm font-semibold text-gray-800 mb-1">
              รหัสกลุ่มงาน (Group Code)
            </label>
            <input
              type="text"
              name="group_code"
              class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200"
              value="<?php echo h($form['group_code']); ?>"
              placeholder="เช่น 01, 02, 03">
            <p class="text-xs text-gray-500 mt-1">
              * ไม่บังคับ แต่ช่วยให้จัดเรียง/อ้างอิงในระบบอื่นได้ง่าย
            </p>
          </div>

          <div class="md:col-span-2">
            <label class="block text-sm font-semibold text-gray-800 mb-1">
              ชื่อกลุ่มงาน / หน่วยงาน (Group Name)
            </label>
            <input
              type="text"
              name="group_name"
              class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200"
              value="<?php echo h($form['group_name']); ?>"
              placeholder="เช่น กลุ่มงานการแพทย์, กลุ่มงานการพยาบาล"
              required>
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <label class="block text-sm font-semibold text-gray-800 mb-1">
              ลำดับการแสดงผล (Sort Order)
            </label>
            <input
              type="number"
              name="sort_order"
              class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200"
              value="<?php echo (int)$form['sort_order']; ?>"
              placeholder="เช่น 1, 2, 3">
            <p class="text-xs text-gray-500 mt-1">
              * ค่าน้อยแสดงก่อน ใช้กำหนดลำดับกลุ่มงานบนรายงานหรือเมนู
            </p>
          </div>

          <div class="flex items-center md:col-span-2">
            <label class="inline-flex items-center">
              <input
                type="checkbox"
                name="is_active"
                class="mr-2"
                <?php echo ((int)$form['is_active']===1 ? 'checked' : ''); ?>>
              <span class="text-sm font-semibold text-gray-800">
                ใช้งานอยู่ (Active)
              </span>
            </label>
            <span class="ml-3 text-xs text-gray-500">
              ยกเลิกการใช้งานหากไม่ต้องการให้เลือกในหน้าบันทึก KPI
            </span>
          </div>
        </div>

        <div class="flex flex-wrap gap-3 pt-2">
          <button
            type="submit"
            class="px-4 py-2 bg-blue-700 text-white rounded-xl hover:bg-blue-800 text-sm shadow-sm shadow-blue-200">
            บันทึกกลุ่มงาน
          </button>

          <?php if ($form['id']>0): ?>
            <a href="workgroups.php"
               class="px-4 py-2 bg-slate-700 text-white rounded-xl hover:bg-slate-800 text-sm shadow-sm shadow-slate-200">
              ยกเลิกการแก้ไข
            </a>
          <?php else: ?>
            <button type="reset"
                    class="px-4 py-2 bg-slate-700 text-white rounded-xl hover:bg-slate-800 text-sm shadow-sm shadow-slate-200">
              ล้างฟอร์ม
            </button>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <!-- ตารางรายการกลุ่มงาน -->
    <div>
      <h2 class="text-lg font-semibold text-gray-800 mb-3">
        รายการกลุ่มงาน / หน่วยงานทั้งหมด
      </h2>

      <div class="overflow-x-auto rounded-2xl border border-slate-200 shadow-sm shadow-slate-200/70">
        <table class="min-w-full border-collapse text-sm">
          <thead class="bg-slate-100 text-gray-700">
            <tr>
              <th class="border border-gray-300 px-3 py-2 w-16 text-left">ลำดับ</th>
              <th class="border border-gray-300 px-3 py-2 w-24 text-left">รหัส</th>
              <th class="border border-gray-300 px-3 py-2">ชื่อกลุ่มงาน / หน่วยงาน</th>
              <th class="border border-gray-300 px-3 py-2 w-24 text-center">สถานะ</th>
              <th class="border border-gray-300 px-3 py-2 w-24 text-center">ลำดับ</th>
              <th class="border border-gray-300 px-3 py-2 w-32 text-center">จัดการ</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr>
                <td colspan="6" class="border border-gray-300 px-3 py-3 text-center text-gray-600">
                  ยังไม่มีกลุ่มงาน / หน่วยงานในระบบ
                </td>
              </tr>
            <?php else: $i=1; foreach($rows as $r): ?>
              <tr class="hover:bg-gray-50">
                <td class="border border-gray-300 px-3 py-2">
                  <?php echo $i++; ?>
                </td>
                <td class="border border-gray-300 px-3 py-2 text-gray-800">
                  <?php echo h($r['group_code']); ?>
                </td>
                <td class="border border-gray-300 px-3 py-2 font-semibold text-gray-900">
                  <?php echo h($r['group_name']); ?>
                </td>
                <td class="border border-gray-300 px-3 py-2 text-center">
                  <?php if ((int)$r['is_active']===1): ?>
                    <span class="inline-flex px-2 py-0.5 text-xs rounded-full bg-emerald-100 text-emerald-700 border border-emerald-300">
                      ใช้งาน
                    </span>
                  <?php else: ?>
                    <span class="inline-flex px-2 py-0.5 text-xs rounded-full bg-gray-200 text-gray-700 border border-gray-300">
                      ปิดใช้งาน
                    </span>
                  <?php endif; ?>
                </td>
                <td class="border border-gray-300 px-3 py-2 text-center">
                  <?php echo (int)$r['sort_order']; ?>
                </td>
                <td class="border border-gray-300 px-3 py-2 text-center whitespace-nowrap">
                  <a href="workgroups.php?edit=<?php echo (int)$r['id']; ?>"
                     class="inline-block px-2.5 py-1 bg-amber-500 text-white rounded-lg text-xs hover:bg-amber-600">
                    Edit
                  </a>
                  <a href="workgroups.php?delete=<?php echo (int)$r['id']; ?>"
                     onclick="return confirm('ยืนยันลบกลุ่มงาน/หน่วยงานนี้หรือไม่?');"
                     class="inline-block px-2.5 py-1 bg-red-600 text-white rounded-lg text-xs hover:bg-red-700 ml-1">
                    Delete
                  </a>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</body>
</html>
<?php mysqli_close($conn); ?>

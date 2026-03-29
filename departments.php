<?php
// departments.php — จัดการแผนก (tb_departments) ผูกกับ tb_workgroups

include 'db_connect.php';
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
require_once __DIR__ . '/auth.php';
require_login();
$u = current_user(); // ใช้ $u['fullname'], $u['role'] ได้

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$message     = '';
$edit_id     = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$filter_wg   = isset($_GET['workgroup_id']) ? (int)$_GET['workgroup_id'] : 0;

/* ---------- โหลดรายการกลุ่มงานจาก tb_workgroups ---------- */
$workgroups = array();
$sql_wg = "
  SELECT id, group_code, group_name
  FROM tb_workgroups
  WHERE is_active = 1
  ORDER BY sort_order ASC, group_code ASC, group_name ASC
";
if ($res = mysqli_query($conn, $sql_wg)) {
    while ($r = mysqli_fetch_assoc($res)) {
        $workgroups[(int)$r['id']] = $r;
    }
    mysqli_free_result($res);
}

/* ---------- ค่าเริ่มต้นฟอร์ม ---------- */
$form = array(
    'id'             => 0,
    'workgroup_id'   => 0,
    'department_name'=> '',
    'description'    => ''
);

/* ---------- โหลดข้อมูลเดิม (โหมดแก้ไข) ---------- */
if ($edit_id > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $sql = "SELECT * FROM tb_departments WHERE id = ".$edit_id." LIMIT 1";
    if ($res = mysqli_query($conn, $sql)) {
        if (mysqli_num_rows($res) > 0) {
            $form = mysqli_fetch_assoc($res);
            if (!isset($form['workgroup_id'])) $form['workgroup_id'] = 0;
            if (!isset($form['description']))  $form['description']  = '';
        } else {
            $message = "ไม่พบแผนกที่ต้องการแก้ไข";
        }
        mysqli_free_result($res);
    } else {
        $message = "Query error: ".mysqli_error($conn);
    }
}

/* ---------- บันทึกข้อมูล (เพิ่ม / แก้ไข) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id             = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $workgroup_id   = isset($_POST['workgroup_id']) ? (int)$_POST['workgroup_id'] : 0;
    $department_name= trim(isset($_POST['department_name']) ? $_POST['department_name'] : '');
    $description    = trim(isset($_POST['description']) ? $_POST['description'] : '');

    if ($workgroup_id <= 0) {
        $message = "กรุณาเลือกกลุ่มงานที่แผนกนี้สังกัด";
    } elseif ($department_name === '') {
        $message = "กรุณากรอกชื่อแผนก (department_name)";
    }

    if ($message !== '') {
        // keep form
        $form['id']             = $id;
        $form['workgroup_id']   = $workgroup_id;
        $form['department_name']= $department_name;
        $form['description']    = $description;
    } else {
        if ($id > 0) {
            // UPDATE tb_departments
            $sql = "
              UPDATE tb_departments
                 SET workgroup_id = ?, department_name = ?, description = ?
               WHERE id = ?
            ";
            if ($st = mysqli_prepare($conn, $sql)) {
                mysqli_stmt_bind_param($st, "issi",
                    $workgroup_id, $department_name, $description, $id
                );
                if (mysqli_stmt_execute($st)) {
                    mysqli_stmt_close($st);
                    header("Location: departments.php?message=อัปเดตแผนกเรียบร้อยแล้ว&workgroup_id=".$workgroup_id);
                    exit();
                } else {
                    $message = "ไม่สามารถอัปเดตข้อมูลได้: ".mysqli_error($conn);
                    mysqli_stmt_close($st);
                }
            } else {
                $message = "Prepare failed: ".mysqli_error($conn);
            }
        } else {
            // INSERT tb_departments
            $sql = "
              INSERT INTO tb_departments (workgroup_id, department_name, description)
              VALUES (?,?,?)
            ";
            if ($st = mysqli_prepare($conn, $sql)) {
                mysqli_stmt_bind_param($st, "iss",
                    $workgroup_id, $department_name, $description
                );
                if (mysqli_stmt_execute($st)) {
                    mysqli_stmt_close($st);
                    header("Location: departments.php?message=เพิ่มแผนกใหม่เรียบร้อยแล้ว&workgroup_id=".$workgroup_id);
                    exit();
                } else {
                    $message = "ไม่สามารถบันทึกข้อมูลได้: ".mysqli_error($conn);
                    mysqli_stmt_close($st);
                }
            } else {
                $message = "Prepare failed: ".mysqli_error($conn);
            }
        }

        // ถ้ามี error ให้คืนค่าฟอร์ม
        if ($message !== '') {
            $form['id']             = $id;
            $form['workgroup_id']   = $workgroup_id;
            $form['department_name']= $department_name;
            $form['description']    = $description;
        }
    }
}

/* ---------- ลบข้อมูล ---------- */
if (isset($_GET['delete'])) {
    $del = (int)$_GET['delete'];
    if ($del > 0) {
        $sql = "DELETE FROM tb_departments WHERE id = ?";
        if ($st = mysqli_prepare($conn, $sql)) {
            mysqli_stmt_bind_param($st, "i", $del);
            if (mysqli_stmt_execute($st)) {
                mysqli_stmt_close($st);
                header("Location: departments.php?message=ลบแผนกเรียบร้อยแล้ว&workgroup_id=".$filter_wg);
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

/* ---------- ดึงรายการแผนกทั้งหมด (JOIN tb_workgroups) ---------- */
$rows = array();
$sql_list = "
  SELECT d.*, wg.group_code, wg.group_name
  FROM tb_departments d
  LEFT JOIN tb_workgroups wg ON wg.id = d.workgroup_id
";
if ($filter_wg > 0) {
    $sql_list .= " WHERE d.workgroup_id = ".$filter_wg." ";
}
$sql_list .= "
  ORDER BY wg.sort_order ASC, wg.group_code ASC,
           d.department_name ASC
";

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
  <title>จัดการแผนก (Departments) | ระบบ KPI โรงพยาบาลศรีรัตนะ</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 min-h-screen">

  <?php
    $active_nav = 'departments';
    include __DIR__ . '/navbar_kpi.php';
  ?>

  <div class="w-full bg-white/95 p-6 rounded-3xl shadow-xl shadow-slate-200/70 border border-slate-200 mt-4">

    <!-- Header + Filter -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-5 border-b border-slate-200 pb-4">
      <div>
        <h1 class="text-xl md:text-2xl font-semibold text-gray-900">
          🧩 จัดการแผนก (Departments)
        </h1>
        <p class="text-sm text-gray-500 mt-1">
          เลือกกลุ่มงาน (tb_workgroups) แล้วกำหนดแผนกย่อย (tb_departments) ให้สอดคล้องกับโครงสร้างหน่วยงาน
        </p>
      </div>

      <form method="get" class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50/80 p-2 shadow-inner shadow-slate-100">
        <select name="workgroup_id" class="p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200">
          <option value="0">กลุ่มงาน (ทั้งหมด)</option>
          <?php foreach($workgroups as $wgid=>$wg): ?>
            <option value="<?php echo (int)$wgid; ?>" <?php echo ($filter_wg === $wgid ? 'selected' : ''); ?>>
              <?php
                $code = $wg['group_code'] !== '' ? $wg['group_code'].' - ' : '';
                echo h($code.$wg['group_name']);
              ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button class="px-3 py-2 bg-slate-800 text-white rounded-xl text-sm shadow-sm shadow-slate-200">
          กรอง
        </button>
      </form>
    </div>

    <!-- Messages -->
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

    <!-- Form -->
    <div class="mb-6 p-5 rounded-2xl border border-slate-200 bg-slate-50/80 shadow-inner shadow-slate-100">
      <h2 class="text-lg font-semibold text-gray-800 mb-3">
        <?php echo ($form['id'] > 0) ? 'แก้ไขแผนก' : 'เพิ่มแผนกใหม่'; ?>
      </h2>

      <form method="post" class="space-y-4">
        <input type="hidden" name="id" value="<?php echo (int)$form['id']; ?>">

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-semibold text-gray-800 mb-1">
              กลุ่มงานที่สังกัด (workgroup_id)
            </label>
            <select name="workgroup_id" class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200" required>
              <option value="0">-- เลือกกลุ่มงาน --</option>
              <?php foreach($workgroups as $wgid=>$wg): ?>
                <option value="<?php echo (int)$wgid; ?>"
                  <?php echo ((int)$form['workgroup_id'] === $wgid ? 'selected' : ''); ?>>
                  <?php
                    $code = $wg['group_code'] !== '' ? $wg['group_code'].' - ' : '';
                    echo h($code.$wg['group_name']);
                  ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="block text-sm font-semibold text-gray-800 mb-1">
              ชื่อแผนก (department_name)
            </label>
            <input
              type="text"
              name="department_name"
              class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200"
              value="<?php echo h($form['department_name']); ?>"
              placeholder="เช่น งานเทคนิคการแพทย์, งานผู้ป่วยนอก"
              required>
          </div>
        </div>

        <div>
          <label class="block text-sm font-semibold text-gray-800 mb-1">
            รายละเอียด / หมายเหตุ (description)
          </label>
          <textarea
            name="description"
            rows="3"
            class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200"
            placeholder="เช่น ใช้เพื่อระบุขอบเขตงาน หรือใช้ในการจัดกลุ่มตัวชี้วัด"><?php
              echo h($form['description']);
          ?></textarea>
        </div>

        <div class="flex flex-wrap gap-3 pt-2">
          <button
            type="submit"
            class="px-4 py-2 bg-blue-700 text-white rounded-xl hover:bg-blue-800 text-sm shadow-sm shadow-blue-200">
            บันทึกแผนก
          </button>

          <?php if ($form['id'] > 0): ?>
            <a href="departments.php"
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

    <!-- Table -->
    <div>
      <h2 class="text-lg font-semibold text-gray-800 mb-3">
        รายการแผนกทั้งหมด (tb_departments)
      </h2>

      <div class="overflow-x-auto rounded-2xl border border-slate-200 shadow-sm shadow-slate-200/70">
        <table class="min-w-full border-collapse text-sm">
          <thead class="bg-slate-100 text-gray-700">
            <tr>
              <th class="border border-gray-300 px-3 py-2 w-12 text-left">#</th>
              <th class="border border-gray-300 px-3 py-2">กลุ่มงาน</th>
              <th class="border border-gray-300 px-3 py-2">ชื่อแผนก (department_name)</th>
              <th class="border border-gray-300 px-3 py-2 w-1/3">รายละเอียด</th>
              <th class="border border-gray-300 px-3 py-2 w-32 text-center">จัดการ</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr>
                <td colspan="5" class="border border-gray-300 px-3 py-3 text-center text-gray-600">
                  ยังไม่มีแผนกในระบบ
                </td>
              </tr>
            <?php else: $i=1; foreach($rows as $r): ?>
              <tr class="hover:bg-gray-50">
                <td class="border border-gray-300 px-3 py-2"><?php echo $i++; ?></td>
                <td class="border border-gray-300 px-3 py-2">
                  <?php
                    $code = $r['group_code'] !== '' ? $r['group_code'].' - ' : '';
                    echo h($code.$r['group_name']);
                  ?>
                </td>
                <td class="border border-gray-300 px-3 py-2 font-semibold text-gray-900">
                  <?php echo h($r['department_name']); ?>
                </td>
                <td class="border border-gray-300 px-3 py-2 text-gray-700">
                  <?php echo nl2br(h($r['description'])); ?>
                </td>
                <td class="border border-gray-300 px-3 py-2 text-center whitespace-nowrap">
                  <a href="departments.php?edit=<?php echo (int)$r['id']; ?>"
                     class="inline-block px-2.5 py-1 bg-amber-500 text-white rounded-lg text-xs hover:bg-amber-600">
                    Edit
                  </a>
                  <a href="departments.php?delete=<?php echo (int)$r['id']; ?>&workgroup_id=<?php echo (int)$filter_wg; ?>"
                     onclick="return confirm('ยืนยันลบแผนกนี้หรือไม่?');"
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

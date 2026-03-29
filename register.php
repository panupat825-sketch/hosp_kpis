<?php
// register.php — PHP 5.6 compatible (self-register = staff)
// ดึงตำแหน่งจาก tb_positions และแผนกจาก tb_departments
// เมื่อเลือกแผนก จะดึงกลุ่มงานจาก tb_workgroups (group_name) เติมให้เอง

session_start();
require_once __DIR__ . '/db_connect.php';

/* ---------------- CSRF token ---------------- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

/* ---------------- helpers ---------------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function post($k){ return isset($_POST[$k]) ? trim($_POST[$k]) : ''; }

/* ---------------- โหลด master: ตำแหน่ง, แผนก+กลุ่มงาน ---------------- */
$positions   = array();
$pos_index   = array(); // map id => row
$departments = array();
$dept_index  = array();

/*  ตำแหน่งที่ active
    ปรับ ORDER BY ให้เรียงตามชื่อ position_name ตามลำดับอักษร
*/
$sqlPos = "SELECT id, position_code, position_name 
           FROM tb_positions 
           WHERE is_active = 1 
           ORDER BY position_name ASC";
if ($res = mysqli_query($conn, $sqlPos)) {
    while ($r = mysqli_fetch_assoc($res)) {
        $positions[] = $r;
        $pos_index[(int)$r['id']] = $r;
    }
    mysqli_free_result($res);
}

/*
  tb_departments      : id, workgroup_id, department_name, description
  tb_workgroups       : id, group_code, group_name, is_active, sort_order
  ต้องการให้เวลาผู้ใช้เลือก department แล้วเอา group_name ไปเติมใน division
*/
$sqlDept = "
    SELECT d.id,
           d.department_name,
           wg.group_name AS workgroup_name
    FROM tb_departments d
    LEFT JOIN tb_workgroups wg ON d.workgroup_id = wg.id
    ORDER BY d.department_name
";
if ($res = mysqli_query($conn, $sqlDept)) {
    while ($r = mysqli_fetch_assoc($res)) {
        $departments[] = $r;
        $dept_index[(int)$r['id']] = $r;
    }
    mysqli_free_result($res);
}

/* ---------------- defaults ---------------- */
$message = "";
$old = array(
    'fullname'      => '',
    'username'      => '',
    'position_id'   => '',
    'department_id' => '',
    'division'      => ''
);

/* ---------------- handle submit ---------------- */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // CSRF
    $posted_token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if (!hash_equals($csrf, $posted_token)) {
        $message = "❌ Invalid request (CSRF).";
    } else {
        // รับค่า
        $fullname      = post('fullname');
        $username      = post('username');
        $password      = isset($_POST['password'])  ? (string)$_POST['password']  : '';
        $password2     = isset($_POST['password2']) ? (string)$_POST['password2'] : '';
        $position_id   = (int)post('position_id');
        $department_id = (int)post('department_id');
        $division      = post('division');   // จะถูก auto-fill จาก JS หรือ fallback จาก PHP

        // role เริ่มต้น: staff
        $role = 'staff';

        // เก็บค่าเดิมเพื่อเติมกลับฟอร์มกรณี error
        $old = array(
            'fullname'      => $fullname,
            'username'      => $username,
            'position_id'   => $position_id,
            'department_id' => $department_id,
            'division'      => $division
        );

        // ถ้าช่อง division ยังว่าง แต่ department มี group_name ให้ใช้ group_name เป็น default
        if ($division === '' && $department_id > 0 && isset($dept_index[$department_id])) {
            if (!empty($dept_index[$department_id]['workgroup_name'])) {
                $division = $dept_index[$department_id]['workgroup_name'];
                $old['division'] = $division;
            }
        }

        // validate ขั้นต้น
        $errors = array();
        if ($fullname === '')    $errors[] = "กรุณากรอกชื่อ-สกุล";
        if ($username === '')    $errors[] = "กรุณากรอก Username";
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,30}$/', $username)) {
            $errors[] = "Username ต้องเป็น a-z,0-9,_ . - ความยาว 3–30 ตัว";
        }
        if (strlen($password) < 6) {
            $errors[] = "Password ต้องยาวอย่างน้อย 6 ตัวอักษร";
        }
        if ($password !== $password2) {
            $errors[] = "ยืนยันรหัสผ่านไม่ตรงกัน";
        }
        if ($position_id <= 0 || !isset($pos_index[$position_id])) {
            $errors[] = "กรุณาเลือกตำแหน่งจากรายการ";
        }
        if ($department_id <= 0 || !isset($dept_index[$department_id])) {
            $errors[] = "กรุณาเลือกหน่วยงาน/แผนกจากรายการ";
        }
        if ($division === '') {
            $errors[] = "กรุณาระบุข้อมูลกลุ่มงาน / กอง / ฝ่าย (ระบบจะดึงให้อัตโนมัติจากหน่วยงาน หากมีการตั้งค่า)";
        }

        // ตรวจ username ซ้ำ
        if (empty($errors)) {
            if ($st = mysqli_prepare($conn, "SELECT COUNT(*) FROM tb_users WHERE username=?")) {
                mysqli_stmt_bind_param($st, "s", $username);
                mysqli_stmt_execute($st);
                mysqli_stmt_bind_result($st, $cnt);
                mysqli_stmt_fetch($st);
                mysqli_stmt_close($st);

                if ($cnt > 0) {
                    $errors[] = "❌ Username นี้มีอยู่แล้ว";
                }
            } else {
                $errors[] = "ไม่สามารถตรวจสอบ username ได้: ".mysqli_error($conn);
            }
        }

        if (empty($errors)) {
            // เตรียมชื่อที่จะเก็บลง tb_users (เก็บเป็นข้อความเหมือนเดิม)
            $position_name   = $pos_index[$position_id]['position_name'];
            $department_name = $dept_index[$department_id]['department_name'];

            // แฮชรหัสผ่าน
            $hash = password_hash($password, PASSWORD_BCRYPT);

            // บันทึกลง tb_users
            $sql = "INSERT INTO tb_users
                        (username, password, fullname, position, department, division, role, created_at)
                    VALUES (?,?,?,?,?,?,?,NOW())";

            if ($st = mysqli_prepare($conn, $sql)) {
                mysqli_stmt_bind_param(
                    $st,
                    "sssssss",
                    $username,
                    $hash,
                    $fullname,
                    $position_name,
                    $department_name,
                    $division,
                    $role
                );
                if (mysqli_stmt_execute($st)) {
                    $message = "✅ ลงทะเบียนสำเร็จ! ไปที่ ".
                               "<a href='login.php' class='text-blue-600 underline'>เข้าสู่ระบบ</a>";
                    // เคลียร์ค่าเดิมออกจากฟอร์ม
                    $old = array(
                        'fullname'      => '',
                        'username'      => '',
                        'position_id'   => '',
                        'department_id' => '',
                        'division'      => ''
                    );
                } else {
                    $message = "❌ เกิดข้อผิดพลาดในการบันทึก: ".h(mysqli_error($conn));
                }
                mysqli_stmt_close($st);
            } else {
                $message = "❌ เตรียมคำสั่งไม่สำเร็จ: ".h(mysqli_error($conn));
            }
        } else {
            // รวม error แสดงในกล่องเดียว
            $safe_errors = array();
            foreach ($errors as $e) { $safe_errors[] = h($e); }
            $message = '❌ พบข้อผิดพลาด:<br>• '.implode('<br>• ', $safe_errors);
        }
    }
}

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <title>ลงทะเบียนผู้ใช้ระบบ KPI</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-gradient-to-br from-slate-100 via-sky-50 to-slate-200 flex items-center justify-center p-4">
  <div class="w-full max-w-xl rounded-3xl border border-slate-200 bg-white/95 p-8 shadow-2xl shadow-slate-300/50">
    <h2 class="text-2xl font-extrabold text-center mb-1">🔐 ลงทะเบียนผู้ใช้</h2>
    <p class="text-center text-xs text-gray-500 mb-6">
      สิทธิ์เริ่มต้น: ผู้ใช้งานทั่วไป (staff) &ndash; ผู้ดูแลระบบสามารถปรับสิทธิ์ภายหลังได้
    </p>

    <?php if (!empty($message)): ?>
      <div class="mb-5 p-4 rounded-2xl border text-sm shadow-sm
        <?php echo (strpos($message,'✅')!==false)
                  ? 'bg-emerald-50 border-emerald-300 text-emerald-800'
                  : 'bg-red-50 border-red-300 text-red-800'; ?>">
        <?php echo $message; ?>
      </div>
    <?php endif; ?>

    <form action="register.php" method="POST" class="space-y-4 text-sm">
      <input type="hidden" name="csrf_token" value="<?php echo h($csrf); ?>">

      <input type="text" name="fullname" placeholder="ชื่อ-สกุล"
             value="<?php echo h($old['fullname']); ?>"
             required class="w-full rounded-xl border border-slate-300 px-3 py-2.5 shadow-sm focus:border-sky-400 focus:outline-none focus:ring-4 focus:ring-sky-100">

      <input type="text" name="username" placeholder="Username"
             value="<?php echo h($old['username']); ?>"
             required class="w-full rounded-xl border border-slate-300 px-3 py-2.5 shadow-sm focus:border-sky-400 focus:outline-none focus:ring-4 focus:ring-sky-100">

      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <input type="password" name="password" placeholder="Password (≥ 6 ตัว)"
               required class="w-full rounded-xl border border-slate-300 px-3 py-2.5 shadow-sm focus:border-sky-400 focus:outline-none focus:ring-4 focus:ring-sky-100">
        <input type="password" name="password2" placeholder="ยืนยัน Password"
               required class="w-full rounded-xl border border-slate-300 px-3 py-2.5 shadow-sm focus:border-sky-400 focus:outline-none focus:ring-4 focus:ring-sky-100">
      </div>

      <!-- ตำแหน่งจาก tb_positions (ไม่แสดงรหัสหน้าแล้ว) -->
      <div>
        <label class="block text-xs font-semibold text-gray-700 mb-1">
          ตำแหน่ง (Position)
        </label>
        <select name="position_id" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 shadow-sm focus:border-sky-400 focus:outline-none focus:ring-4 focus:ring-sky-100" required>
          <option value="">-- เลือกตำแหน่ง --</option>
          <?php foreach ($positions as $p): ?>
            <?php
              $pid  = (int)$p['id'];
              $text = $p['position_name']; // ไม่แสดง position_code แล้ว
            ?>
            <option value="<?php echo $pid; ?>"
              <?php echo ($old['position_id'] !== '' && (int)$old['position_id'] === $pid) ? 'selected' : ''; ?>>
              <?php echo h($text); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- หน่วยงาน/แผนกจาก tb_departments + group_name จาก tb_workgroups -->
      <div>
        <label class="block text-xs font-semibold text-gray-700 mb-1">
          หน่วยงาน / แผนก (Department)
        </label>
        <select name="department_id" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 shadow-sm focus:border-sky-400 focus:outline-none focus:ring-4 focus:ring-sky-100" required id="departmentSelect">
          <option value="">-- เลือกหน่วยงาน/แผนก --</option>
          <?php foreach ($departments as $d): ?>
            <?php
              $did           = (int)$d['id'];
              $wg_name       = isset($d['workgroup_name']) ? $d['workgroup_name'] : '';
              $selected_dept = ($old['department_id'] !== '' && (int)$old['department_id'] === $did);
            ?>
            <option value="<?php echo $did; ?>"
                    data-division="<?php echo h($wg_name); ?>"
              <?php echo $selected_dept ? 'selected' : ''; ?>>
              <?php echo h($d['department_name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <p class="text-[11px] text-gray-400 mt-1">
          เมื่อเลือกหน่วยงาน ระบบจะเติมกลุ่มงาน/กอง/ฝ่ายให้อัตโนมัติ 
        </p>
      </div>

      <div>
        <label class="block text-xs font-semibold text-gray-700 mb-1">
          กลุ่มงาน / กอง / ฝ่าย (Division / Workgroup)
        </label>
        <input type="text" name="division" id="divisionInput"
               placeholder="กลุ่มงาน / กอง / ฝ่าย"
               value="<?php echo h($old['division']); ?>"
               required class="w-full rounded-xl border border-slate-300 px-3 py-2.5 shadow-sm focus:border-sky-400 focus:outline-none focus:ring-4 focus:ring-sky-100">
      </div>

      <button type="submit"
        class="w-full rounded-xl bg-blue-700 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-800">
        ลงทะเบียน
      </button>
    </form>

    <p class="text-center mt-4 text-xs md:text-sm">
      มีบัญชีแล้ว?
      <a href="login.php" class="text-blue-600 underline">เข้าสู่ระบบ</a>
    </p>
  </div>

  <!-- JS: auto-fill กลุ่มงานจากหน่วยงาน -->
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      var deptSelect    = document.getElementById('departmentSelect');
      var divisionInput = document.getElementById('divisionInput');
      if (!deptSelect || !divisionInput) return;

      function updateDivisionFromDept() {
        var opt = deptSelect.options[deptSelect.selectedIndex];
        if (!opt) return;
        var div = opt.getAttribute('data-division') || '';
        if (div !== '') {
          divisionInput.value = div;
        }
      }

      deptSelect.addEventListener('change', updateDivisionFromDept);

      // กรณีมีค่า department เดิมตอนโหลดหน้า ให้ sync ครั้งแรก
      updateDivisionFromDept();
    });
  </script>
</body>
</html>

<?php
// users.php — จัดการผู้ใช้งานระบบ
require_once __DIR__ . '/db_connect.php';
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }
require_once __DIR__ . '/auth.php';
require_login();
require_role('admin');

$u = current_user();

// จำกัดสิทธิ์เฉพาะ admin
if (empty($u['role']) || $u['role'] !== 'admin') {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Forbidden</title></head><body>';
    echo '<div style="margin: 50px auto; max-width: 600px; font-family: sans-serif;">';
    echo '<h1>403 Forbidden</h1><p>อนุญาตเฉพาะผู้ดูแลระบบ (admin) เท่านั้น</p>';
    echo '</div></body></html>';
    exit();
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function add_error(&$errors, $public_message, $log_message){
    error_log('[hosp_kpis] roles.php | ' . $log_message);
    $errors[] = $public_message;
}

$message = '';
$errors  = array();

/* ------------- Handle POST actions ------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'create') {
        $username   = trim(isset($_POST['username']) ? $_POST['username'] : '');
        $fullname   = trim(isset($_POST['fullname']) ? $_POST['fullname'] : '');
        $position   = trim(isset($_POST['position']) ? $_POST['position'] : '');
        $department = trim(isset($_POST['department']) ? $_POST['department'] : '');
        $division   = trim(isset($_POST['division']) ? $_POST['division'] : '');
        $role       = trim(isset($_POST['role']) ? $_POST['role'] : 'staff');
        $password   = isset($_POST['password']) && $_POST['password'] !== '' ? $_POST['password'] : '123456';

        if ($username === '' || $fullname === '') {
            $errors[] = 'กรุณากรอก Username และชื่อ-สกุล';
        } else {
            // เช็ค username ซ้ำ
            $chk = mysqli_query($conn, "SELECT id FROM tb_users WHERE username='".mysqli_real_escape_string($conn,$username)."' LIMIT 1");
            if ($chk && mysqli_num_rows($chk)>0) {
                $errors[] = 'Username นี้ถูกใช้แล้ว';
                mysqli_free_result($chk);
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $sql  = "INSERT INTO tb_users (username, fullname, position, department, division, password, role)
                         VALUES (?,?,?,?,?,?,?)";
                if ($st = mysqli_prepare($conn, $sql)) {
                    mysqli_stmt_bind_param($st, "sssssss",
                        $username, $fullname, $position, $department, $division, $hash, $role
                    );
                    if (mysqli_stmt_execute($st)) {
                        $message = 'เพิ่มผู้ใช้งานใหม่เรียบร้อย (รหัสผ่านเริ่มต้น: '.$password.')';
                    } else {
                        $errors[] = 'ไม่สามารถเพิ่มผู้ใช้งานได้: '.mysqli_error($conn);
                    }
                    mysqli_stmt_close($st);
                } else {
                    $errors[] = 'Prepare failed: '.mysqli_error($conn);
                }
            }
        }

    } elseif ($action === 'update') {
        $id         = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $fullname   = trim(isset($_POST['fullname']) ? $_POST['fullname'] : '');
        $position   = trim(isset($_POST['position']) ? $_POST['position'] : '');
        $department = trim(isset($_POST['department']) ? $_POST['department'] : '');
        $division   = trim(isset($_POST['division']) ? $_POST['division'] : '');
        $role       = trim(isset($_POST['role']) ? $_POST['role'] : 'staff');

        if ($id <= 0) {
            $errors[] = 'ไม่พบรหัสผู้ใช้';
        } else {
            $sql = "UPDATE tb_users
                       SET fullname=?, position=?, department=?, division=?, role=?
                     WHERE id=?";
            if ($st = mysqli_prepare($conn, $sql)) {
                mysqli_stmt_bind_param($st, "sssssi",
                    $fullname, $position, $department, $division, $role, $id
                );
                if (mysqli_stmt_execute($st)) {
                    $message = 'บันทึกข้อมูลผู้ใช้งานเรียบร้อย';
                } else {
                    $errors[] = 'ไม่สามารถบันทึกข้อมูลได้: '.mysqli_error($conn);
                }
                mysqli_stmt_close($st);
            } else {
                $errors[] = 'Prepare failed: '.mysqli_error($conn);
            }
        }

    } elseif ($action === 'reset_password') {
        $id       = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $new_pass = '123456';
        if ($id <= 0) {
            $errors[] = 'ไม่พบรหัสผู้ใช้';
        } else {
            $hash = password_hash($new_pass, PASSWORD_BCRYPT);
            $sql  = "UPDATE tb_users SET password=? WHERE id=?";
            if ($st = mysqli_prepare($conn, $sql)) {
                mysqli_stmt_bind_param($st, "si", $hash, $id);
                if (mysqli_stmt_execute($st)) {
                    $message = 'รีเซ็ตรหัสผ่านเป็นค่าเริ่มต้น (123456) เรียบร้อย';
                } else {
                    $errors[] = 'ไม่สามารถรีเซ็ตรหัสผ่านได้: '.mysqli_error($conn);
                }
                mysqli_stmt_close($st);
            } else {
                $errors[] = 'Prepare failed: '.mysqli_error($conn);
            }
        }

    } elseif ($action === 'delete') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id <= 0) {
            $errors[] = 'ไม่พบรหัสผู้ใช้';
        } elseif ($id == $u['id']) {
            $errors[] = 'ไม่สามารถลบผู้ใช้งานที่กำลังล็อกอินอยู่เองได้';
        } else {
            $sql = "DELETE FROM tb_users WHERE id=?";
            if ($st = mysqli_prepare($conn, $sql)) {
                mysqli_stmt_bind_param($st, "i", $id);
                if (mysqli_stmt_execute($st)) {
                    $message = 'ลบผู้ใช้งานเรียบร้อย';
                } else {
                    $errors[] = 'ไม่สามารถลบผู้ใช้งานได้: '.mysqli_error($conn);
                }
                mysqli_stmt_close($st);
            } else {
                $errors[] = 'Prepare failed: '.mysqli_error($conn);
            }
        }
    }
}

/* ------------- Load users list ------------- */
$users = array();
$res = mysqli_query($conn, "SELECT * FROM tb_users ORDER BY id ASC");
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $users[] = $row;
    }
    mysqli_free_result($res);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <title>จัดการผู้ใช้งานระบบ</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 min-h-screen">
<?php
  $active_nav = 'settings';
  include __DIR__ . '/navbar_kpi.php';
?>
  <div class="w-full bg-white/95 p-6 rounded-3xl shadow-xl shadow-slate-200/70 border border-slate-200 mt-4 mb-6">
    <div class="flex flex-col gap-3 border-b border-slate-200 pb-4 mb-5 md:flex-row md:items-center md:justify-between">
      <h1 class="text-2xl font-bold text-gray-800">จัดการผู้ใช้งานระบบ</h1>
      <div class="flex flex-wrap items-center gap-2">
        <a href="roles.php"
           class="inline-flex items-center rounded-xl bg-rose-500 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-rose-600">
          บทบาท / สิทธิการใช้งาน
        </a>
        <a href="dashboard.php"
           class="inline-flex items-center rounded-xl bg-slate-800 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-900">
          กลับหน้า Dashboard
        </a>
      </div>
    </div>

    <?php if ($message): ?>
      <div class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 shadow-sm">
        <?php echo h($message); ?>
      </div>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="mb-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 shadow-sm space-y-1">
        <?php foreach ($errors as $e): ?>
          <div>• <?php echo h($e); ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- ฟอร์มเพิ่มผู้ใช้งาน -->
    <div class="mb-6 rounded-2xl border border-slate-200 bg-slate-50/80 p-5 shadow-inner">
      <h2 class="text-lg font-semibold mb-3 text-gray-800">เพิ่มผู้ใช้งานใหม่</h2>
      <form method="post" class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
        <div>
          <label class="block mb-1 font-medium">Username</label>
          <input type="text" name="username" required
                 class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-sky-400 focus:outline-none focus:ring-4 focus:ring-sky-100">
        </div>
        <div class="md:col-span-2">
          <label class="block mb-1 font-medium">ชื่อ-สกุล</label>
          <input type="text" name="fullname" required
                 class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-sky-400 focus:outline-none focus:ring-4 focus:ring-sky-100">
        </div>
        <div>
          <label class="block mb-1 font-medium">ตำแหน่ง</label>
          <input type="text" name="position" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-sky-400 focus:outline-none focus:ring-4 focus:ring-sky-100">
        </div>
        <div>
          <label class="block mb-1 font-medium">งาน/ฝ่าย</label>
          <input type="text" name="department" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-sky-400 focus:outline-none focus:ring-4 focus:ring-sky-100">
        </div>
        <div>
          <label class="block mb-1 font-medium">กลุ่มงาน/กอง</label>
          <input type="text" name="division" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-sky-400 focus:outline-none focus:ring-4 focus:ring-sky-100">
        </div>
        <div>
          <label class="block mb-1 font-medium">บทบาท (Role)</label>
          <select name="role" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-sky-400 focus:outline-none focus:ring-4 focus:ring-sky-100">
            <option value="staff">staff</option>
            <option value="manager">manager</option>
            <option value="admin">admin</option>
          </select>
        </div>
        <div>
          <label class="block mb-1 font-medium">รหัสผ่านเริ่มต้น</label>
          <input type="text" name="password" placeholder="เว้นว่าง = 123456"
                 class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-sky-400 focus:outline-none focus:ring-4 focus:ring-sky-100">
        </div>
        <div class="md:col-span-3 flex justify-end mt-2">
          <button type="submit"
                  class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700">
            บันทึกผู้ใช้ใหม่
          </button>
        </div>
      </form>
    </div>

    <!-- ตารางรายชื่อผู้ใช้ -->
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
      <div class="border-b border-slate-200 px-4 py-4">
      <h2 class="text-lg font-semibold mb-0 text-gray-800">รายชื่อผู้ใช้งาน</h2>
      </div>
      <div class="overflow-x-auto">
        <table class="min-w-full border-collapse text-sm">
          <thead>
            <tr class="bg-slate-100 text-gray-700">
              <th class="px-2 py-2 text-left">ID</th>
              <th class="px-2 py-2 text-left">Username</th>
              <th class="px-2 py-2 text-left">ชื่อ-สกุล</th>
              <th class="px-2 py-2 text-left">ตำแหน่ง</th>
              <th class="px-2 py-2 text-left">งาน/ฝ่าย</th>
              <th class="px-2 py-2 text-left">กลุ่มงาน</th>
              <th class="px-2 py-2 text-left">Role</th>
              <th class="px-2 py-2 text-center">จัดการ</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
          <?php foreach ($users as $row): ?>
            <tr>
              <td class="px-2 py-2 text-gray-500"><?php echo (int)$row['id']; ?></td>
              <td class="px-2 py-2 font-mono text-xs"><?php echo h($row['username']); ?></td>
              <td class="px-2 py-2">
                <form method="post" class="space-y-1 md:space-y-0 md:flex md:flex-wrap md:items-center">
                  <input type="hidden" name="action" value="update">
                  <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                  <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                  <input type="text" name="fullname"
                         class="border border-slate-300 rounded-xl px-3 py-2 w-full md:w-48 mb-1 md:mb-0 text-sm shadow-sm"
                         value="<?php echo h($row['fullname']); ?>">
              </td>
              <td class="px-2 py-2">
                  <input type="text" name="position"
                         class="border border-slate-300 rounded-xl px-3 py-2 w-full md:w-40 text-sm shadow-sm"
                         value="<?php echo h($row['position']); ?>">
              </td>
              <td class="px-2 py-2">
                  <input type="text" name="department"
                         class="border border-slate-300 rounded-xl px-3 py-2 w-full md:w-40 text-sm shadow-sm"
                         value="<?php echo h($row['department']); ?>">
              </td>
              <td class="px-2 py-2">
                  <input type="text" name="division"
                         class="border border-slate-300 rounded-xl px-3 py-2 w-full md:w-40 text-sm shadow-sm"
                         value="<?php echo h($row['division']); ?>">
              </td>
              <td class="px-2 py-2">
                  <select name="role" class="border border-slate-300 rounded-xl px-3 py-2 w-full md:w-28 text-sm shadow-sm">
                    <?php foreach (array('staff','manager','admin') as $r): ?>
                      <option value="<?php echo $r; ?>" <?php echo ($row['role']===$r?'selected':''); ?>>
                        <?php echo $r; ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
              </td>
              <td class="px-2 py-2 text-center align-top">
                  <div class="flex flex-col gap-1 items-stretch">
                    <button type="submit"
                            class="rounded-lg bg-blue-600 px-2.5 py-1.5 text-white text-xs font-medium transition hover:bg-blue-700">
                      บันทึก
                    </button>
                </form>
                    <form method="post" onsubmit="return confirm('ยืนยันรีเซ็ตรหัสผ่านเป็น 123456 ?');">
                      <input type="hidden" name="action" value="reset_password">
                      <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                      <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                      <button type="submit"
                              class="w-full rounded-lg bg-amber-500 px-2.5 py-1.5 text-white text-xs font-medium transition hover:bg-amber-600">
                        Reset PW
                      </button>
                    </form>
                    <?php if ($row['id'] != $u['id']): ?>
                    <form method="post" onsubmit="return confirm('ยืนยันลบผู้ใช้งานรายนี้?');">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                      <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                      <button type="submit"
                              class="w-full rounded-lg bg-red-600 px-2.5 py-1.5 text-white text-xs font-medium transition hover:bg-red-700">
                        ลบ
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
      <p class="mt-3 text-xs text-gray-500">
        * รหัสผ่านเริ่มต้น/รีเซ็ต ใช้ค่า <code>123456</code> แนะนำให้ผู้ใช้เปลี่ยนรหัสผ่านภายหลังเข้าสู่ระบบ
      </p>
    </div>
  </div>
</body>
</html>

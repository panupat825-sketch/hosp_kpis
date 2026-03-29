<?php
// users.php — จัดการบัญชีผู้ใช้ระบบ KPI (tb_users) + ทีม + หน่วยงาน
include 'db_connect.php';
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
require_once __DIR__ . '/auth.php';
require_login();

$u = current_user(); // ใช้ตรวจ role / department ฯลฯ

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/* ---------------- helper บันทึกทีมของผู้ใช้ลง tb_user_team_map ---------------- */
if (!function_exists('save_user_teams')) {
    function save_user_teams($conn, $user_id, $team_ids) {
        $user_id = (int)$user_id;
        if ($user_id <= 0) return;

        // ลบ mapping เดิม
        @mysqli_query($conn, "DELETE FROM tb_user_team_map WHERE user_id={$user_id}");

        if (!is_array($team_ids) || empty($team_ids)) {
            return;
        }
        $values = array();
        foreach ($team_ids as $tid) {
            $tid = (int)$tid;
            if ($tid <= 0) continue;
            $values[] = "({$user_id}, {$tid}, 0, NOW())";
        }
        if (!empty($values)) {
            $sql = "INSERT INTO tb_user_team_map (user_id, team_id, is_leader, created_at)
                    VALUES ".implode(',', $values);
            @mysqli_query($conn, $sql);
        }
    }
}

/* ---------------- สิทธิ์ role ---------------- */
$is_admin = isset($u['role']) && $u['role'] === 'admin';

$roles = array(
    'admin'   => 'ผู้ดูแลระบบ (admin)',
    'manager' => 'หัวหน้ากลุ่มงาน / หัวหน้าทีม (manager)',
    'staff'   => 'ผู้ใช้งานทั่วไป (staff)'
);

/* ---------------- ตัวแปรแสดงผล ---------------- */
$message = '';
$message_type = 'info'; // success / error / info

/* ---------------- โหลด master: ทีม ---------------- */
$team_list = array();
$sql_teams = "
    SELECT id, name_th, name_en, team_group
    FROM tb_teams
    WHERE is_active = 1
    ORDER BY team_group, name_th
";
if ($rs_t = mysqli_query($conn, $sql_teams)) {
    while ($r = mysqli_fetch_assoc($rs_t)) {
        $team_list[] = $r;
    }
    mysqli_free_result($rs_t);
}

/* ---------------- โหลด master: หน่วยงาน + กอง/ฝ่าย จาก tb_departments / tb_workgroups ---------------- */
// dept_div_map ใช้ map 'ชื่อหน่วยงาน' => 'ชื่อกอง/ฝ่าย (กลุ่มงาน)'
$dept_div_map            = array();
$department_suggestions  = array();
$division_suggestions    = array();

// 1) ดึงจากตาราง master
$sql_dept = "
    SELECT d.department_name AS dept, w.group_name AS division
    FROM tb_departments d
    LEFT JOIN tb_workgroups w ON w.id = d.workgroup_id   -- ถ้าคอลัมน์ชื่อไม่ใช่ name_th ปรับตรงนี้
    ORDER BY d.department_name ASC
";
if ($rs = mysqli_query($conn, $sql_dept)) {
    while ($r = mysqli_fetch_assoc($rs)) {
        $dept = trim((string)$r['dept']);
        $div  = trim((string)$r['division']);
        if ($dept === '') continue;

        if (!isset($dept_div_map[$dept])) {
            $dept_div_map[$dept] = $div;
            $department_suggestions[] = $dept;
        }
        if ($div !== '' && !in_array($div, $division_suggestions, true)) {
            $division_suggestions[] = $div;
        }
    }
    mysqli_free_result($rs);
}

// 2) เผื่อมีหน่วยงาน/กองฝ่ายที่เคยพิมพ์เองใน tb_users แต่ไม่มีใน master → เติมเข้ามาด้วย
$sql_users_dept = "
    SELECT DISTINCT department, division
    FROM tb_users
    WHERE department <> ''
    ORDER BY department, division
";
if ($rs = mysqli_query($conn, $sql_users_dept)) {
    while ($r = mysqli_fetch_assoc($rs)) {
        $dept = trim((string)$r['department']);
        $div  = trim((string)$r['division']);
        if ($dept === '') continue;

        if (!isset($dept_div_map[$dept])) {
            $dept_div_map[$dept] = $div;
            $department_suggestions[] = $dept;
        }
        if ($div !== '' && !in_array($div, $division_suggestions, true)) {
            $division_suggestions[] = $div;
        }
    }
    mysqli_free_result($rs);
}

/* team ที่ user ที่กำลังแก้ไขสังกัด */
$current_team_ids = array();

/* ---------------- รับค่า GET ---------------- */
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$search  = isset($_GET['search']) ? trim($_GET['search']) : '';

/* ค่าเริ่มต้นของฟอร์ม */
$form = array(
    'id'         => 0,
    'username'   => '',
    'fullname'   => '',
    'position'   => '',
    'department' => '',
    'division'   => '',
    'role'       => 'staff'
);

/* ---------------- โหลดข้อมูลเดิมกรณีแก้ไข ---------------- */
if ($edit_id > 0) {
    $sql = "SELECT * FROM tb_users WHERE id=".$edit_id." LIMIT 1";
    if ($rs = mysqli_query($conn, $sql)) {
        if ($row = mysqli_fetch_assoc($rs)) {
            $form['id']         = (int)$row['id'];
            $form['username']   = $row['username'];
            $form['fullname']   = $row['fullname'];
            $form['position']   = $row['position'];
            $form['department'] = $row['department'];
            $form['division']   = $row['division'];
            $form['role']       = $row['role'] ? $row['role'] : 'staff';

            // โหลด team ของ user นี้
            $rs2 = mysqli_query($conn,
                "SELECT team_id FROM tb_user_team_map WHERE user_id=".(int)$form['id']
            );
            if ($rs2) {
                while ($r2 = mysqli_fetch_assoc($rs2)) {
                    $current_team_ids[(int)$r2['team_id']] = true;
                }
                mysqli_free_result($rs2);
            }
        } else {
            $message = 'ไม่พบบัญชีผู้ใช้ที่ต้องการแก้ไข';
            $message_type = 'error';
        }
        mysqli_free_result($rs);
    } else {
        $message = 'โหลดข้อมูลผู้ใช้ไม่สำเร็จ: '.mysqli_error($conn);
        $message_type = 'error';
    }
}

/* ---------------- POST: เพิ่ม / แก้ไข ผู้ใช้ ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_user'])) {
    if (!$is_admin) {
        $message = 'คุณไม่มีสิทธิ์จัดการผู้ใช้ ต้องเป็น admin เท่านั้น';
        $message_type = 'error';
    } else {
        $id         = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $username   = trim(isset($_POST['username']) ? $_POST['username'] : '');
        $fullname   = trim(isset($_POST['fullname']) ? $_POST['fullname'] : '');
        $position   = trim(isset($_POST['position']) ? $_POST['position'] : '');
        $department = trim(isset($_POST['department']) ? $_POST['department'] : '');
        $division   = trim(isset($_POST['division']) ? $_POST['division'] : '');
        $role_post  = trim(isset($_POST['role']) ? $_POST['role'] : 'staff');
        $password   = isset($_POST['password']) ? $_POST['password'] : '';
        $password2  = isset($_POST['password_confirm']) ? $_POST['password_confirm'] : '';

        // team ที่ถูกติ๊ก
        $team_ids = array();
        if (isset($_POST['team_ids']) && is_array($_POST['team_ids'])) {
            foreach ($_POST['team_ids'] as $tid) {
                $tid = (int)$tid;
                if ($tid > 0) $team_ids[] = $tid;
            }
        }
        $current_team_ids = array();
        foreach ($team_ids as $tid) {
            $current_team_ids[$tid] = true;
        }

        // เช็ค role
        if (!isset($roles[$role_post])) {
            $role_post = 'staff';
        }

        // เก็บค่าลง $form เพื่อใช้ render modal กลับ
        $form['id']         = $id;
        $form['username']   = $username;
        $form['fullname']   = $fullname;
        $form['position']   = $position;
        $form['department'] = $department;
        $form['division']   = $division;
        $form['role']       = $role_post;

        // validate ขั้นต้น
        if ($username === '' || $fullname === '') {
            $message = 'กรุณากรอก Username และชื่อ–สกุล';
            $message_type = 'error';
        } elseif ($id <= 0 && $password === '') {
            $message = 'กรณีเพิ่มผู้ใช้ใหม่ ต้องกำหนดรหัสผ่าน';
            $message_type = 'error';
        } elseif ($password !== '' && $password !== $password2) {
            $message = 'รหัสผ่านและยืนยันรหัสผ่านไม่ตรงกัน';
            $message_type = 'error';
        } else {
            // ตรวจ username ซ้ำ
            $username_esc = mysqli_real_escape_string($conn, $username);
            if ($id > 0) {
                $sql = "SELECT id FROM tb_users
                        WHERE username='{$username_esc}' AND id <> {$id}
                        LIMIT 1";
            } else {
                $sql = "SELECT id FROM tb_users
                        WHERE username='{$username_esc}'
                        LIMIT 1";
            }
            $rs = mysqli_query($conn, $sql);
            if ($rs && mysqli_num_rows($rs) > 0) {
                $message = 'Username นี้มีอยู่แล้วในระบบ';
                $message_type = 'error';
                mysqli_free_result($rs);
            } else {
                if ($rs) mysqli_free_result($rs);

                // hash password ถ้ามี
                $pwd_hash = '';
                if ($password !== '') {
                    $pwd_hash = password_hash($password, PASSWORD_DEFAULT);
                }

                if ($id > 0) {
                    // UPDATE
                    if ($password !== '') {
                        $sql = "UPDATE tb_users
                                SET username=?, fullname=?, position=?, department=?, division=?, role=?, password=?
                                WHERE id=?";
                        $st = mysqli_prepare($conn, $sql);
                        if ($st) {
                            mysqli_stmt_bind_param(
                                $st,
                                "sssssssi",
                                $username,
                                $fullname,
                                $position,
                                $department,
                                $division,
                                $role_post,
                                $pwd_hash,
                                $id
                            );
                        }
                    } else {
                        $sql = "UPDATE tb_users
                                SET username=?, fullname=?, position=?, department=?, division=?, role=?
                                WHERE id=?";
                        $st = mysqli_prepare($conn, $sql);
                        if ($st) {
                            mysqli_stmt_bind_param(
                                $st,
                                "ssssssi",
                                $username,
                                $fullname,
                                $position,
                                $department,
                                $division,
                                $role_post,
                                $id
                            );
                        }
                    }

                    $ok = false;
                    if ($st && mysqli_stmt_execute($st)) {
                        $ok = true;
                    }
                    if ($st) mysqli_stmt_close($st);

                    if ($ok) {
                        save_user_teams($conn, $id, $team_ids);
                        header('Location: users.php?msg=updated');
                        exit();
                    } else {
                        $message = 'บันทึกการแก้ไขไม่สำเร็จ: '.mysqli_error($conn);
                        $message_type = 'error';
                    }
                } else {
                    // INSERT
                    $sql = "INSERT INTO tb_users
                            (username, fullname, position, department, division, password, role, created_at)
                            VALUES (?,?,?,?,?,?,?,NOW())";
                    $st = mysqli_prepare($conn, $sql);
                    if ($st) {
                        mysqli_stmt_bind_param(
                            $st,
                            "sssssss",
                            $username,
                            $fullname,
                            $position,
                            $department,
                            $division,
                            $pwd_hash,
                            $role_post
                        );
                    }

                    $ok = false;
                    $new_id = 0;
                    if ($st && mysqli_stmt_execute($st)) {
                        $ok = true;
                        $new_id = mysqli_insert_id($conn);
                    }
                    if ($st) mysqli_stmt_close($st);

                    if ($ok) {
                        save_user_teams($conn, $new_id, $team_ids);
                        header('Location: users.php?msg=created');
                        exit();
                    } else {
                        $message = 'เพิ่มผู้ใช้ใหม่ไม่สำเร็จ: '.mysqli_error($conn);
                        $message_type = 'error';
                    }
                }
            }
        }
    }
}

/* ---------------- ลบผู้ใช้ ---------------- */
if (isset($_GET['delete']) && $is_admin) {
    $del_id = (int)$_GET['delete'];
    if ($del_id > 0) {
        if (isset($u['id']) && (int)$u['id'] === $del_id) {
            $message = 'ไม่อนุญาตให้ลบบัญชีของตัวเอง';
            $message_type = 'error';
        } else {
            $st = mysqli_prepare($conn, "DELETE FROM tb_users WHERE id=?");
            if ($st) {
                mysqli_stmt_bind_param($st, "i", $del_id);
                if (mysqli_stmt_execute($st)) {
                    mysqli_stmt_close($st);
                    header('Location: users.php?msg=deleted');
                    exit();
                } else {
                    $message = 'ลบผู้ใช้ไม่สำเร็จ: '.mysqli_error($conn);
                    $message_type = 'error';
                    mysqli_stmt_close($st);
                }
            } else {
                $message = 'เตรียมคำสั่งลบไม่สำเร็จ: '.mysqli_error($conn);
                $message_type = 'error';
            }
        }
    }
}

/* ---------------- โหลดรายการผู้ใช้ทั้งหมด + รวมชื่อทีม ---------------- */
$users = array();

$sql_list = "
SELECT
  u.*,
  GROUP_CONCAT(DISTINCT t.name_th ORDER BY t.name_th SEPARATOR ', ') AS team_names
FROM tb_users u
LEFT JOIN tb_user_team_map ut ON ut.user_id = u.id
LEFT JOIN tb_teams t         ON t.id = ut.team_id
WHERE 1=1
";

if ($search !== '') {
    $kw = mysqli_real_escape_string($conn, $search);
    $sql_list .= " AND (
        u.username   LIKE '%{$kw}%' OR
        u.fullname   LIKE '%{$kw}%' OR
        u.department LIKE '%{$kw}%' OR
        u.division   LIKE '%{$kw}%' OR
        t.name_th    LIKE '%{$kw}%' OR
        t.name_en    LIKE '%{$kw}%'
    )";
}

$sql_list .= "
GROUP BY u.id
ORDER BY u.created_at DESC, u.id DESC
";

if ($rs = mysqli_query($conn, $sql_list)) {
    while ($row = mysqli_fetch_assoc($rs)) {
        $users[] = $row;
    }
    mysqli_free_result($rs);
} else {
    $message = ($message ? $message."\n" : '').'โหลดรายการผู้ใช้ไม่สำเร็จ: '.mysqli_error($conn);
    $message_type = 'error';
}

/* ---------------- msg จาก redirect ---------------- */
if (isset($_GET['msg']) && $message === '') {
    if ($_GET['msg'] === 'created') {
        $message = 'เพิ่มผู้ใช้ใหม่เรียบร้อยแล้ว';
        $message_type = 'success';
    } elseif ($_GET['msg'] === 'updated') {
        $message = 'บันทึกการแก้ไขผู้ใช้เรียบร้อยแล้ว';
        $message_type = 'success';
    } elseif ($_GET['msg'] === 'deleted') {
        $message = 'ลบผู้ใช้เรียบร้อยแล้ว';
        $message_type = 'success';
    }
}

/* ให้ JS รู้ว่าต้องเปิด modal อัตโนมัติไหม */
$should_open_modal = $is_admin && (
    $form['id'] > 0 ||
    ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_user']) && $message_type === 'error')
);
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>จัดการผู้ใช้ระบบ KPI | โรงพยาบาลศรีรัตนะ</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 min-h-screen">
<?php
  $active_nav = 'users';
  include __DIR__.'/navbar_kpi.php';
?>

<div class="w-full bg-white/95 p-6 rounded-3xl shadow-xl shadow-slate-200/70 border border-slate-200 mt-4">
  <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-5 border-b border-slate-200 pb-4">
    <div>
      <h1 class="text-xl md:text-2xl font-semibold text-gray-900">
        จัดการผู้ใช้ระบบ KPI
      </h1>
      <p class="text-sm text-gray-500 mt-1">
        กำหนดบัญชีผู้ใช้ สิทธิ์การใช้งาน ข้อมูลตำแหน่ง/หน่วยงาน และเชื่อมโยงผู้ใช้กับทีม
        (เช่น PCT, RM, ENV, ทีมคุณภาพ ฯลฯ)
      </p>
    </div>
    <div class="text-right text-sm text-gray-600">
      <div>บทบาทของคุณ: <span class="font-semibold"><?php echo h(isset($u['role']) ? $u['role'] : '-'); ?></span></div>
      <?php if (!$is_admin): ?>
        <div class="text-xs text-red-500 mt-1">* เฉพาะ admin เท่านั้นที่สามารถเพิ่ม/แก้ไข/ลบ ผู้ใช้ได้</div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($message !== ''): ?>
    <?php
      $color = 'blue';
      if ($message_type === 'success') $color = 'emerald';
      elseif ($message_type === 'error') $color = 'red';
    ?>
    <div class="mb-5 p-4 rounded-2xl border border-<?php echo $color; ?>-300 bg-<?php echo $color; ?>-50 text-<?php echo $color; ?>-800 text-sm whitespace-pre-line shadow-sm">
      <?php echo h($message); ?>
    </div>
  <?php endif; ?>

  <!-- แถบบน: ปุ่มเพิ่ม + ค้นหา -->
  <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-4">
    <h2 class="text-lg font-semibold text-gray-800">รายการผู้ใช้ทั้งหมด</h2>

    <div class="flex flex-col md:flex-row gap-2 md:items-center">
      <?php if ($is_admin): ?>
        <button type="button"
                onclick="openCreateUserModal();"
                class="px-4 py-2 bg-blue-700 hover:bg-blue-800 text-white text-sm rounded-xl shadow-sm shadow-blue-200">
          + เพิ่มผู้ใช้ใหม่
        </button>
      <?php endif; ?>

      <form method="get" class="flex flex-col sm:flex-row gap-2 rounded-2xl border border-slate-200 bg-slate-50/80 p-2 shadow-inner shadow-slate-100">
        <input type="text" name="search"
               value="<?php echo h($search); ?>"
               placeholder="ค้นหา username / ชื่อ / หน่วยงาน / ทีม"
               class="p-2.5 border border-slate-300 rounded-xl text-sm w-48 md:w-64 shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200">
        <button class="px-3 py-2 bg-slate-800 text-white rounded-xl text-sm shadow-sm shadow-slate-200">
          ค้นหา
        </button>
        <?php if ($search !== ''): ?>
          <a href="users.php" class="px-3 py-2 bg-white border border-slate-300 text-gray-800 rounded-xl text-sm">
            ล้าง
          </a>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <!-- ตารางผู้ใช้ -->
  <div class="overflow-x-auto rounded-2xl border border-slate-200 shadow-sm shadow-slate-200/70">
    <table class="min-w-full text-sm border-collapse">
      <thead class="bg-slate-100">
        <tr>
          <th class="border px-2 py-1 text-left">#</th>
          <th class="border px-2 py-1 text-left">Username</th>
          <th class="border px-2 py-1 text-left">ชื่อ–สกุล</th>
          <th class="border px-2 py-1 text-left">ตำแหน่ง</th>
          <th class="border px-2 py-1 text-left">หน่วยงาน</th>
          <th class="border px-2 py-1 text-left">กอง/ฝ่าย</th>
          <th class="border px-2 py-1 text-left">ทีมที่สังกัด</th>
          <th class="border px-2 py-1 text-left">Role</th>
          <th class="border px-2 py-1 text-left">สร้างเมื่อ</th>
          <th class="border px-2 py-1 text-left">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($users)): ?>
          <tr>
            <td colspan="10" class="text-center text-gray-500 py-3">
              ยังไม่มีข้อมูลผู้ใช้ หรือไม่พบตามเงื่อนไขค้นหา
            </td>
          </tr>
        <?php else: ?>
          <?php $i = 1; foreach ($users as $row): ?>
            <tr class="hover:bg-gray-50">
              <td class="border px-2 py-1"><?php echo $i++; ?></td>
              <td class="border px-2 py-1"><?php echo h($row['username']); ?></td>
              <td class="border px-2 py-1"><?php echo h($row['fullname']); ?></td>
              <td class="border px-2 py-1"><?php echo h($row['position']); ?></td>
              <td class="border px-2 py-1"><?php echo h($row['department']); ?></td>
              <td class="border px-2 py-1"><?php echo h($row['division']); ?></td>
              <td class="border px-2 py-1 text-xs">
                <?php
                  $teams = trim((string)$row['team_names']);
                  echo $teams !== '' ? h($teams) : '<span class="text-gray-400">-</span>';
                ?>
              </td>
              <td class="border px-2 py-1">
                <?php
                  $rk = $row['role'];
                  echo h(isset($roles[$rk]) ? $roles[$rk] : $rk);
                ?>
              </td>
              <td class="border px-2 py-1 text-xs text-gray-600">
                <?php echo h($row['created_at']); ?>
              </td>
              <td class="border px-2 py-1 whitespace-nowrap">
                <?php if ($is_admin): ?>
                  <a href="users.php?edit=<?php echo (int)$row['id']; ?>"
                     class="inline-block px-2.5 py-1 bg-amber-500 hover:bg-amber-600 text-white rounded-lg text-xs">
                    Edit
                  </a>
                  <?php if (!isset($u['id']) || (int)$u['id'] !== (int)$row['id']): ?>
                    <a href="users.php?delete=<?php echo (int)$row['id']; ?>"
                       onclick="return confirm('ยืนยันการลบบัญชีผู้ใช้นี้หรือไม่?');"
                       class="inline-block px-2.5 py-1 bg-red-600 hover:bg-red-700 text-white rounded-lg text-xs ml-1">
                      Delete
                    </a>
                  <?php endif; ?>
                <?php endif; ?>
                <a href="teams.php?filter_user_id=<?php echo (int)$row['id']; ?>"
                   class="inline-block px-2.5 py-1 bg-sky-600 hover:bg-sky-700 text-white rounded-lg text-xs ml-1">
                  ทีม
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal ฟอร์มผู้ใช้ -->
<div id="userModal"
     class="fixed inset-0 bg-slate-950/55 hidden items-center justify-center z-50 backdrop-blur-sm">
  <div class="bg-white rounded-3xl shadow-2xl shadow-slate-900/20 border border-slate-200 w-full mx-2 max-h-[90vh] flex flex-col
              max-w-lg md:max-w-3xl lg:max-w-5xl">
    <div class="flex items-center justify-between px-4 py-3 border-b border-slate-200">
      <h2 id="userModalTitle" class="text-lg font-semibold">
        <?php echo ($form['id'] > 0) ? 'แก้ไขข้อมูลผู้ใช้' : 'เพิ่มผู้ใช้ใหม่'; ?>
      </h2>
      <button type="button"
              onclick="closeUserModal();"
              class="text-gray-500 hover:text-gray-800 text-xl leading-none">
        &times;
      </button>
    </div>

    <?php if (!$is_admin): ?>
      <div class="p-4 text-sm text-red-600">
        เฉพาะ admin เท่านั้นที่สามารถเพิ่ม/แก้ไขผู้ใช้ได้
      </div>
    <?php else: ?>
      <form method="post" class="p-4 space-y-4 overflow-y-auto" id="userForm">
        <input type="hidden" name="id" id="user_id"
               value="<?php echo (int)$form['id']; ?>">

        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Username (ใช้เข้าสู่ระบบ)</label>
          <input type="text" name="username" id="user_username"
                 value="<?php echo h($form['username']); ?>"
                 class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200" required>
        </div>
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">ชื่อ–สกุล</label>
          <input type="text" name="fullname" id="user_fullname"
                 value="<?php echo h($form['fullname']); ?>"
                 class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200" required>
        </div>
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">ตำแหน่ง (Position)</label>
          <input type="text" name="position" id="user_position"
                 value="<?php echo h($form['position']); ?>"
                 class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200">
        </div>

        <!-- หน่วยงาน: ช่อง + checkbox -->
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">
            หน่วยงาน/กลุ่มงานหลัก (Department)
          </label>
          <input type="text" name="department" id="user_department"
                 value="<?php echo h($form['department']); ?>"
                 class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200"
                 placeholder="จะถูกเติมอัตโนมัติจากหน่วยงานที่ติ๊กด้านล่าง">
          <?php if (!empty($department_suggestions)): ?>
            <div class="mt-1 max-h-32 overflow-y-auto border rounded p-2 bg-gray-50 text-xs">
              <?php foreach ($department_suggestions as $dept): ?>
                <?php
                  $checked = (strpos($form['department'], $dept) !== false) ? 'checked' : '';
                ?>
                <label class="inline-flex items-center mr-2 mb-1">
                  <input type="checkbox"
                         class="mr-1 dept-checkbox"
                         name="departments[]"
                         value="<?php echo h($dept); ?>"
                         <?php echo $checked; ?>>
                  <span><?php echo h($dept); ?></span>
                </label>
              <?php endforeach; ?>
            </div>
            <p class="text-[11px] text-gray-400 mt-1">
              เลือกได้มากกว่า 1 หน่วยงาน หรือพิมพ์เองในช่องด้านบน
            </p>
          <?php endif; ?>
        </div>

        <!-- กอง/ฝ่าย: แสดงปุ่มตามหน่วยงานที่เลือก -->
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">กอง/ฝ่าย (Division)</label>
          <input type="text" name="division" id="user_division"
                 value="<?php echo h($form['division']); ?>"
                 class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200">
          <div id="divisionChips" class="mt-1 flex flex-wrap gap-1 text-xs">
            <!-- เติมโดย JS -->
          </div>
          <p class="text-[11px] text-gray-400 mt-1">
            หากเลือกหน่วยงานแล้ว ระบบจะแสดงเฉพาะกอง/ฝ่ายที่ตรงกับหน่วยงานนั้น
          </p>
        </div>

        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">สิทธิ์การใช้งาน (Role)</label>
          <select name="role" id="user_role" class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200">
            <?php foreach ($roles as $rk => $label): ?>
              <option value="<?php echo h($rk); ?>" <?php echo ($form['role'] === $rk ? 'selected' : ''); ?>>
                <?php echo h($label); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- ทีมที่สังกัด: checkbox -->
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">ทีมที่สังกัด</label>
          <div class="max-h-40 overflow-y-auto border border-slate-200 rounded-2xl p-2 bg-slate-50 text-xs shadow-inner shadow-slate-100">
            <?php if (empty($team_list)): ?>
              <div class="text-gray-400">ยังไม่มีการกำหนดทีมในระบบ</div>
            <?php else: ?>
              <?php
                $current_group = '';
                $group_labels = array(
                    'QUALITY'   => 'กลุ่มทีมคุณภาพ / บริหาร',
                    'CLINICAL'  => 'ทีมคลินิก / PCT',
                    'SUPPORT'   => 'ทีมสนับสนุน',
                    'MANAGEMENT'=> 'บริหารจัดการ',
                    'OTHER'     => 'อื่น ๆ'
                );
              ?>
              <?php foreach ($team_list as $t): ?>
                <?php
                  $tg = (string)$t['team_group'];
                  if ($tg !== $current_group) {
                      if ($current_group !== '') {
                          echo '<hr class="my-1 border-gray-200">';
                      }
                      $current_group = $tg;
                      $label = isset($group_labels[$tg]) ? $group_labels[$tg] : $tg;
                      echo '<div class="mt-1 mb-1 text-[11px] font-semibold text-gray-500">'.h($label).'</div>';
                  }
                  $tid = (int)$t['id'];
                  $checked = isset($current_team_ids[$tid]) ? 'checked' : '';
                ?>
                <label class="inline-flex items-center mr-3 mb-1">
                  <input type="checkbox"
                         class="mr-1"
                         name="team_ids[]"
                         value="<?php echo $tid; ?>"
                         <?php echo $checked; ?>>
                  <span><?php echo h($t['name_th']); ?></span>
                </label>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <p class="text-[11px] text-gray-400 mt-1">
            เลือกได้มากกว่าหนึ่งทีม เช่น PCT, RM, ENV, ทีมคุณภาพ ฯลฯ
          </p>
        </div>

        <div class="border-t pt-3 mt-2">
          <label class="block text-sm font-semibold text-gray-700 mb-1">
            รหัสผ่าน
            <?php if ($form['id'] > 0): ?>
              <span class="text-xs text-gray-500">(เว้นว่างหากไม่ต้องการเปลี่ยน)</span>
            <?php endif; ?>
          </label>
          <input type="password" name="password" id="user_password"
                 class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200">
        </div>
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">ยืนยันรหัสผ่าน</label>
          <input type="password" name="password_confirm" id="user_password_confirm"
                 class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200">
        </div>

        <div class="flex gap-2 pt-2 justify-end">
          <button type="button"
                  onclick="closeUserModal();"
                  class="px-4 py-2 bg-slate-200 hover:bg-slate-300 text-gray-800 rounded-xl text-sm">
            ยกเลิก
          </button>
          <button type="submit" name="save_user"
                  class="px-4 py-2 bg-blue-700 hover:bg-blue-800 text-white rounded-xl text-sm shadow-sm shadow-blue-200">
            บันทึกผู้ใช้
          </button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<script>
  // mapping PHP -> JS
  var DEPT_DIV_MAP   = <?php echo json_encode($dept_div_map, JSON_UNESCAPED_UNICODE); ?>;
  var ALL_DIVISIONS  = <?php echo json_encode($division_suggestions, JSON_UNESCAPED_UNICODE); ?>;

  function openUserModal() {
    var modal = document.getElementById('userModal');
    if (!modal) return;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
  }

  function closeUserModal() {
    var modal = document.getElementById('userModal');
    if (!modal) return;
    modal.classList.add('hidden');
    modal.classList.remove('flex');

    if (window.history && window.history.replaceState) {
      var params = new URLSearchParams(window.location.search);
      params.delete('edit');
      params.delete('msg');
      var qs = params.toString();
      var url = window.location.pathname + (qs ? '?' + qs : '');
      window.history.replaceState({}, '', url);
    }
  }

  function openCreateUserModal() {
    var f = document.getElementById('userForm');
    if (!f) return;
    f.reset();

    document.getElementById('user_id').value = '0';
    document.getElementById('user_username').value   = '';
    document.getElementById('user_fullname').value   = '';
    document.getElementById('user_position').value   = '';
    document.getElementById('user_department').value = '';
    document.getElementById('user_division').value   = '';

    var roleSel = document.getElementById('user_role');
    if (roleSel) {
      for (var i = 0; i < roleSel.options.length; i++) {
        if (roleSel.options[i].value === 'staff') {
          roleSel.selectedIndex = i;
          break;
        }
      }
    }

    // clear dept checkboxes
    var deptBoxes = document.querySelectorAll('.dept-checkbox');
    for (var j = 0; j < deptBoxes.length; j++) {
      deptBoxes[j].checked = false;
    }

    // clear team checkboxes
    var teamBoxes = document.querySelectorAll('#userForm input[type="checkbox"][name="team_ids[]"]');
    for (var k = 0; k < teamBoxes.length; k++) {
      teamBoxes[k].checked = false;
    }

    document.getElementById('userModalTitle').textContent = 'เพิ่มผู้ใช้ใหม่';
    updateDeptAndDivisionFromCheckbox();
    openUserModal();
  }

  function setDivision(val) {
    var el = document.getElementById('user_division');
    if (el) el.value = val;
  }

  function updateDivisionChips(selectedDepts) {
    var container = document.getElementById('divisionChips');
    if (!container) return;

    var divSet = new Set();

    if (selectedDepts.length === 0) {
      (ALL_DIVISIONS || []).forEach(function(d) {
        if (d) divSet.add(d);
      });
    } else {
      selectedDepts.forEach(function(dept) {
        var divName = DEPT_DIV_MAP[dept];
        if (divName) divSet.add(divName);
      });
      if (divSet.size === 0) {
        (ALL_DIVISIONS || []).forEach(function(d) {
          if (d) divSet.add(d);
        });
      }
    }

    container.innerHTML = '';
    divSet.forEach(function(divName) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'px-2 py-1 text-xs border rounded bg-gray-50 hover:bg-gray-200 mb-1 mr-1';
      btn.textContent = divName;
      btn.setAttribute('data-value', divName);
      btn.onclick = function() { setDivision(divName); };
      container.appendChild(btn);
    });

    // ถ้ามี division เดียว ออโต้ใส่ให้เลย
    if (divSet.size === 1) {
      var only = divSet.values().next().value;
      setDivision(only);
    }
  }

  function updateDeptAndDivisionFromCheckbox() {
    var boxes = document.querySelectorAll('.dept-checkbox');
    var selected = [];
    for (var i = 0; i < boxes.length; i++) {
      if (boxes[i].checked) {
        selected.push(boxes[i].value);
      }
    }
    var deptInput = document.getElementById('user_department');
    if (deptInput) {
      deptInput.value = selected.join(', ');
    }
    updateDivisionChips(selected);
  }

  document.addEventListener('DOMContentLoaded', function () {
    var boxes = document.querySelectorAll('.dept-checkbox');
    for (var i = 0; i < boxes.length; i++) {
      boxes[i].addEventListener('change', updateDeptAndDivisionFromCheckbox);
    }

    // sync ครั้งแรก จากค่าใน $form
    updateDeptAndDivisionFromCheckbox();

    // auto open modal กรณี edit / validate fail
    <?php if ($should_open_modal): ?>
    var title = document.getElementById('userModalTitle');
    if (title && <?php echo (int)$form['id']; ?> > 0) {
      title.textContent = 'แก้ไขข้อมูลผู้ใช้';
    } else if (title) {
      title.textContent = 'เพิ่มผู้ใช้ใหม่';
    }
    openUserModal();
    <?php endif; ?>

    // ก่อน submit sync หน่วยงานจาก checkbox อีกครั้ง
    var f = document.getElementById('userForm');
    if (f) {
      f.addEventListener('submit', function() {
        updateDeptAndDivisionFromCheckbox();
      });
    }
  });
</script>

</body>
</html>

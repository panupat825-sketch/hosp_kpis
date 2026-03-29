<?php
// teams.php  — จัดการทีม (tb_teams) + สมาชิกทีม (tb_user_team_map)
// PHP 5.6 + mysqli

include 'db_connect.php';
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
require_once __DIR__ . '/auth.php';
require_login();
require_role(array('admin', 'manager'));
$u = current_user();

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('team_action_token_valid')) {
    function team_action_token_valid() {
        $expected = csrf_token();
        $actual = isset($_REQUEST['csrf_token']) ? (string)$_REQUEST['csrf_token'] : '';
        return hash_equals($expected, $actual);
    }
}

/* ---------- ค่าคงที่ ---------- */
$TEAM_GROUPS = array(
    'QUALITY'    => 'ทีมคุณภาพ / ความปลอดภัย',
    'CLINICAL'   => 'ทีมดูแลผู้ป่วย (PCT / Clinical)',
    'SUPPORT'    => 'ทีมสนับสนุนบริการ',
    'MANAGEMENT' => 'ทีมบริหารระบบ / ทรัพยากร',
    'OTHER'      => 'ทีมอื่น ๆ'
);

/* ---------- ตัวแปรแสดงข้อความ ---------- */
$message = '';
if (isset($_GET['message'])) {
    $message = trim($_GET['message']);
}

/* ---------- ทีมที่ถูกเลือก (สำหรับ modal สมาชิกทีม) ---------- */
$selected_team_id = isset($_GET['team_id']) ? (int)$_GET['team_id'] : 0;

/* ---------- โครงฟอร์มทีมเริ่มต้น ---------- */
$form_team = array(
    'id'          => 0,
    'code'        => '',
    'name_th'     => '',
    'name_en'     => '',
    'team_group'  => 'OTHER',
    'description' => '',
    'is_active'   => 1,
);

/* =========================================================
   จัดการ POST
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $form_type = isset($_POST['form_type']) ? $_POST['form_type'] : '';

    /* ===== 1) บันทึกทีม (เพิ่ม/แก้ไข) ===== */
    if ($form_type === 'team') {

        $id          = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $code        = trim(isset($_POST['code']) ? $_POST['code'] : '');
        $name_th     = trim(isset($_POST['name_th']) ? $_POST['name_th'] : '');
        $name_en     = trim(isset($_POST['name_en']) ? $_POST['name_en'] : '');
        $team_group  = trim(isset($_POST['team_group']) ? $_POST['team_group'] : 'OTHER');
        $description = trim(isset($_POST['description']) ? $_POST['description'] : '');
        $is_active   = isset($_POST['is_active']) ? 1 : 0;

        if (!isset($TEAM_GROUPS[$team_group])) {
            $team_group = 'OTHER';
        }

        if ($code === '' || $name_th === '') {
            $message = 'กรุณากรอกทั้งรหัสทีม และชื่อทีมภาษาไทย';
            $form_team['id']          = $id;
            $form_team['code']        = $code;
            $form_team['name_th']     = $name_th;
            $form_team['name_en']     = $name_en;
            $form_team['team_group']  = $team_group;
            $form_team['description'] = $description;
            $form_team['is_active']   = $is_active;
        } else {
            if ($id > 0) {
                // UPDATE
                $sql = "UPDATE tb_teams
                        SET code = ?, name_th = ?, name_en = ?, team_group = ?, description = ?, is_active = ?
                        WHERE id = ?";
                if ($st = mysqli_prepare($conn, $sql)) {
                    mysqli_stmt_bind_param($st, "ssssssi",
                        $code,
                        $name_th,
                        $name_en,
                        $team_group,
                        $description,
                        $is_active,
                        $id
                    );
                    if (mysqli_stmt_execute($st)) {
                        mysqli_stmt_close($st);
                        header("Location: teams.php?message=" . urlencode("อัปเดตข้อมูลทีมเรียบร้อย"));
                        exit();
                    } else {
                        $message = "อัปเดตทีมไม่สำเร็จ: " . mysqli_error($conn);
                        mysqli_stmt_close($st);
                    }
                } else {
                    $message = "Prepare UPDATE ล้มเหลว: " . mysqli_error($conn);
                }
                $form_team['id']          = $id;
                $form_team['code']        = $code;
                $form_team['name_th']     = $name_th;
                $form_team['name_en']     = $name_en;
                $form_team['team_group']  = $team_group;
                $form_team['description'] = $description;
                $form_team['is_active']   = $is_active;

            } else {
                // INSERT
                $sql = "INSERT INTO tb_teams (code, name_th, name_en, team_group, description, is_active)
                        VALUES (?,?,?,?,?,?)";
                if ($st = mysqli_prepare($conn, $sql)) {
                    mysqli_stmt_bind_param($st, "sssssi",
                        $code,
                        $name_th,
                        $name_en,
                        $team_group,
                        $description,
                        $is_active
                    );
                    if (mysqli_stmt_execute($st)) {
                        mysqli_stmt_close($st);
                        header("Location: teams.php?message=" . urlencode("เพิ่มทีมใหม่เรียบร้อย"));
                        exit();
                    } else {
                        $err = mysqli_errno($conn);
                        if ($err == 1062) {
                            $message = "ไม่สามารถบันทึกได้: รหัสทีมซ้ำกับที่มีอยู่แล้ว";
                        } else {
                            $message = "เพิ่มทีมไม่สำเร็จ: " . mysqli_error($conn);
                        }
                        mysqli_stmt_close($st);
                    }
                } else {
                    $message = "Prepare INSERT ล้มเหลว: " . mysqli_error($conn);
                }
                $form_team['code']        = $code;
                $form_team['name_th']     = $name_th;
                $form_team['name_en']     = $name_en;
                $form_team['team_group']  = $team_group;
                $form_team['description'] = $description;
                $form_team['is_active']   = $is_active;
            }
        }

    /* ===== 2) เพิ่ม/อัปเดตสมาชิกทีม (จาก modal) ===== */
    } elseif ($form_type === 'add_member') {
        $team_id   = isset($_POST['team_id']) ? (int)$_POST['team_id'] : 0;
        $user_id   = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
        $is_leader = isset($_POST['is_leader']) ? 1 : 0;

        $selected_team_id = $team_id;

        if ($team_id <= 0 || $user_id <= 0) {
            $message = 'กรุณาเลือกทีมและผู้ใช้ให้ครบก่อนบันทึก';
        } else {
            // มี mapping อยู่แล้วหรือยัง
            $sqlChk = "SELECT id FROM tb_user_team_map WHERE team_id = ? AND user_id = ? LIMIT 1";
            if ($st = mysqli_prepare($conn, $sqlChk)) {
                mysqli_stmt_bind_param($st, "ii", $team_id, $user_id);
                mysqli_stmt_execute($st);
                mysqli_stmt_bind_result($st, $mid);
                if (mysqli_stmt_fetch($st)) {
                    mysqli_stmt_close($st);
                    // UPDATE is_leader
                    $sqlUp = "UPDATE tb_user_team_map SET is_leader = ? WHERE id = ?";
                    if ($st2 = mysqli_prepare($conn, $sqlUp)) {
                        mysqli_stmt_bind_param($st2, "ii", $is_leader, $mid);
                        mysqli_stmt_execute($st2);
                        mysqli_stmt_close($st2);
                    }
                } else {
                    mysqli_stmt_close($st);
                    // INSERT ใหม่
                    $sqlIns = "INSERT INTO tb_user_team_map (user_id, team_id, is_leader, created_at)
                               VALUES (?,?,?,NOW())";
                    if ($st2 = mysqli_prepare($conn, $sqlIns)) {
                        mysqli_stmt_bind_param($st2, "iii", $user_id, $team_id, $is_leader);
                        mysqli_stmt_execute($st2);
                        mysqli_stmt_close($st2);
                    }
                }
            }
            header("Location: teams.php?team_id={$team_id}&message=" . urlencode("บันทึกสมาชิกทีมเรียบร้อย"));
            exit();
        }
    }
}

/* =========================================================
   GET action: ลบสมาชิก / toggle leader / ลบทีม / แก้ไขทีม
   ========================================================= */

/* ----- ลบสมาชิกทีม ----- */
if (isset($_GET['remove_member']) && isset($_GET['team_id'])) {
    if (!team_action_token_valid()) {
        http_response_code(400);
        exit('Bad Request: invalid CSRF token');
    }
    $team_id = (int)$_GET['team_id'];
    $uid     = (int)$_GET['remove_member'];

    if ($team_id > 0 && $uid > 0) {
        $sqlDel = "DELETE FROM tb_user_team_map WHERE team_id = ? AND user_id = ?";
        if ($st = mysqli_prepare($conn, $sqlDel)) {
            mysqli_stmt_bind_param($st, "ii", $team_id, $uid);
            mysqli_stmt_execute($st);
            mysqli_stmt_close($st);
        }
        header("Location: teams.php?team_id={$team_id}&message=" . urlencode("ลบสมาชิกออกจากทีมแล้ว"));
        exit();
    }
}

/* ----- toggle หัวหน้าทีม ----- */
if (isset($_GET['toggle_leader']) && isset($_GET['team_id'])) {
    if (!team_action_token_valid()) {
        http_response_code(400);
        exit('Bad Request: invalid CSRF token');
    }
    $team_id = (int)$_GET['team_id'];
    $uid     = (int)$_GET['toggle_leader'];

    if ($team_id > 0 && $uid > 0) {
        $current = 0;
        $sqlChk = "SELECT is_leader FROM tb_user_team_map WHERE team_id = ? AND user_id = ? LIMIT 1";
        if ($st = mysqli_prepare($conn, $sqlChk)) {
            mysqli_stmt_bind_param($st, "ii", $team_id, $uid);
            mysqli_stmt_execute($st);
            mysqli_stmt_bind_result($st, $current);
            mysqli_stmt_fetch($st);
            mysqli_stmt_close($st);
        }
        $newVal = $current ? 0 : 1;
        $sqlUp = "UPDATE tb_user_team_map SET is_leader = ? WHERE team_id = ? AND user_id = ?";
        if ($st2 = mysqli_prepare($conn, $sqlUp)) {
            mysqli_stmt_bind_param($st2, "iii", $newVal, $team_id, $uid);
            mysqli_stmt_execute($st2);
            mysqli_stmt_close($st2);
        }
        header("Location: teams.php?team_id={$team_id}&message=" . urlencode("อัปเดตสถานะหัวหน้าทีมเรียบร้อย"));
        exit();
    }
}

/* ----- ลบทีม ----- */
if (isset($_GET['delete_team'])) {
    if (!team_action_token_valid()) {
        http_response_code(400);
        exit('Bad Request: invalid CSRF token');
    }
    $del_id = (int)$_GET['delete_team'];
    if ($del_id > 0) {
        if ($st = mysqli_prepare($conn, "DELETE FROM tb_teams WHERE id = ?")) {
            mysqli_stmt_bind_param($st, "i", $del_id);
            if (mysqli_stmt_execute($st)) {
                mysqli_stmt_close($st);
                header("Location: teams.php?message=" . urlencode("ลบทีมเรียบร้อย"));
                exit();
            } else {
                $message = "ลบทีมไม่สำเร็จ อาจมีการใช้งานอยู่ในตาราง tb_user_team_map: " . mysqli_error($conn);
                mysqli_stmt_close($st);
            }
        } else {
            $message = "Prepare DELETE ล้มเหลว: " . mysqli_error($conn);
        }
    }
}

/* ----- แก้ไขทีม (โหลดขึ้นฟอร์มด้านบน) ----- */
if (isset($_GET['edit_team'])) {
    $edit_id = (int)$_GET['edit_team'];
    if ($edit_id > 0) {
        $q = mysqli_query($conn, "SELECT * FROM tb_teams WHERE id = {$edit_id} LIMIT 1");
        if ($q && mysqli_num_rows($q) > 0) {
            $row = mysqli_fetch_assoc($q);
            $form_team['id']          = (int)$row['id'];
            $form_team['code']        = $row['code'];
            $form_team['name_th']     = $row['name_th'];
            $form_team['name_en']     = $row['name_en'];
            $form_team['team_group']  = $row['team_group'];
            $form_team['description'] = $row['description'];
            $form_team['is_active']   = (int)$row['is_active'];
        } else {
            $message = 'ไม่พบทีมที่ต้องการแก้ไข';
        }
        if ($q) mysqli_free_result($q);
    }
}

/* =========================================================
   โหลดข้อมูลหลัก
   ========================================================= */

/* ----- teams ทั้งหมด ----- */
$teams = array();
$resT = mysqli_query($conn, "SELECT * FROM tb_teams ORDER BY team_group, name_th");
if ($resT) {
    while ($r = mysqli_fetch_assoc($resT)) {
        $teams[] = $r;
    }
    mysqli_free_result($resT);
}

/* ----- team ที่ถูกเลือก (สำหรับ modal) ----- */
$selected_team = null;
if ($selected_team_id > 0) {
    foreach ($teams as $t) {
        if ((int)$t['id'] === $selected_team_id) {
            $selected_team = $t;
            break;
        }
    }
}

/* ----- users ทั้งหมด ----- */
$users = array();
$resU = mysqli_query($conn, "
    SELECT id, username, fullname, department, division, role
    FROM tb_users
    ORDER BY fullname, username
");
if ($resU) {
    while ($r = mysqli_fetch_assoc($resU)) {
        $users[] = $r;
    }
    mysqli_free_result($resU);
}

/* ----- สมาชิกทีมที่เลือก + user ที่ยังไม่ได้อยู่ทีมนี้ ----- */
$team_members    = array();
$available_users = array();

if ($selected_team_id > 0 && $selected_team) {
    $member_ids = array();

    $sqlM = "
        SELECT ut.user_id, ut.is_leader,
               u.username, u.fullname, u.department, u.division, u.role
        FROM tb_user_team_map ut
        INNER JOIN tb_users u ON u.id = ut.user_id
        WHERE ut.team_id = {$selected_team_id}
        ORDER BY u.fullname, u.username
    ";
    if ($resM = mysqli_query($conn, $sqlM)) {
        while ($r = mysqli_fetch_assoc($resM)) {
            $team_members[] = $r;
            $member_ids[(int)$r['user_id']] = true;
        }
        mysqli_free_result($resM);
    }

    foreach ($users as $usr) {
        $uid = (int)$usr['id'];
        if (!isset($member_ids[$uid])) {
            $available_users[] = $usr;
        }
    }
}

/* flag ให้ JS รู้ว่าต้องเปิด modal ทันทีไหม */
$open_member_modal = ($selected_team_id > 0 && $selected_team) ? 1 : 0;

/* ปิด connection */
mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>จัดการทีมและสมาชิกทีม | โรงพยาบาลศรีรัตนะ</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 min-h-screen">

<?php
  $active_nav = 'teams';
  include __DIR__ . '/navbar_kpi.php';
?>

<div class="w-full bg-white/95 p-6 rounded-3xl shadow-xl shadow-slate-200/70 border border-slate-200 mt-4 mb-6">
  <!-- Header -->
  <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-5 border-b border-slate-200 pb-4">
    <div>
      <h1 class="text-xl md:text-2xl font-semibold text-gray-900">
        จัดการทีม (Teams) และสมาชิกทีม
      </h1>
      <p class="text-sm text-gray-500 mt-1">
        กำหนดทีม เช่น PCT, RM, ENV, ทีมคุณภาพ และคลิกชื่อทีมเพื่อดู/แก้ไขสมาชิกในรูปแบบ Modal
      </p>
    </div>
    <?php if ($message !== ''): ?>
      <span class="text-xs md:text-sm font-medium text-emerald-700 bg-emerald-50 border border-emerald-200 px-3 py-1.5 rounded-xl shadow-sm">
        <?php echo h($message); ?>
      </span>
    <?php endif; ?>
  </div>

  <!-- ฟอร์มทีมด้านบน -->
  <div id="team-form" class="rounded-2xl border border-slate-200 p-5 bg-slate-50/80 shadow-inner mb-6">
    <h2 class="text-lg font-semibold text-gray-800 mb-3">
      <?php echo $form_team['id'] > 0 ? 'แก้ไขทีม' : 'เพิ่มทีมใหม่'; ?>
    </h2>

    <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <input type="hidden" name="form_type" value="team">
      <input type="hidden" name="id" value="<?php echo (int)$form_team['id']; ?>">
      <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">รหัสทีม (Code)</label>
        <input type="text" name="code" maxlength="50"
               value="<?php echo h($form_team['code']); ?>"
               class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-sky-400 focus:outline-none focus:ring-4 focus:ring-sky-100" required>
        <p class="text-xs text-gray-500 mt-1">เช่น PCT-CHRONIC, RM, ENV</p>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">กลุ่มทีม (Team Group)</label>
        <select name="team_group" class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-sky-400 focus:outline-none focus:ring-4 focus:ring-sky-100">
          <?php foreach ($TEAM_GROUPS as $tg_code => $tg_name): ?>
            <option value="<?php echo h($tg_code); ?>"
              <?php echo ($form_team['team_group'] === $tg_code ? 'selected' : ''); ?>>
              <?php echo h($tg_name); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">ชื่อทีม (ภาษาไทย)</label>
        <input type="text" name="name_th"
               value="<?php echo h($form_team['name_th']); ?>"
               class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-sky-400 focus:outline-none focus:ring-4 focus:ring-sky-100" required>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">
          ชื่อทีม (ภาษาอังกฤษ) <span class="text-xs text-gray-400">(ถ้ามี)</span>
        </label>
        <input type="text" name="name_en"
               value="<?php echo h($form_team['name_en']); ?>"
               class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-sky-400 focus:outline-none focus:ring-4 focus:ring-sky-100">
      </div>

      <div class="md:col-span-2">
        <label class="block text-sm font-medium text-gray-700 mb-1">รายละเอียดเพิ่มเติม</label>
        <textarea name="description" rows="2"
                  class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-sky-400 focus:outline-none focus:ring-4 focus:ring-sky-100"><?php
          echo h($form_team['description']);
        ?></textarea>
      </div>

      <div class="flex items-center gap-2 md:col-span-2">
        <input type="checkbox" id="is_active" name="is_active"
               class="h-4 w-4 text-blue-600 border-gray-300 rounded"
               <?php echo ($form_team['is_active'] ? 'checked' : ''); ?>>
        <label for="is_active" class="text-sm text-gray-700">ทีมนี้ใช้งานอยู่ (Active)</label>
      </div>

      <div class="pt-2 flex gap-2 md:col-span-2">
        <button type="submit"
                class="rounded-xl bg-blue-700 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-800">
          บันทึกทีม
        </button>
        <a href="teams.php"
           class="rounded-xl bg-slate-200 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-300">
          ล้างฟอร์ม
        </a>
      </div>
    </form>
  </div>

  <!-- ตารางทีม -->
  <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
    <div class="border-b border-slate-200 px-4 py-4">
    <h2 class="text-lg font-semibold text-gray-800 mb-3">รายการทีมทั้งหมด</h2>
    <p class="text-xs text-gray-500 mb-2">
      คลิกที่ <span class="font-semibold">ชื่อทีม</span> เพื่อเปิดดูสมาชิกในทีม (Modal)
    </p>

    </div>
    <div class="overflow-x-auto">
    <table class="min-w-full text-sm border-collapse">
      <thead class="bg-slate-100">
        <tr>
          <th class="border px-2 py-1 text-left">#</th>
          <th class="border px-2 py-1 text-left">รหัสทีม</th>
          <th class="border px-2 py-1 text-left">ชื่อทีม</th>
          <th class="border px-2 py-1 text-left">กลุ่มทีม</th>
          <th class="border px-2 py-1 text-left">สถานะ</th>
          <th class="border px-2 py-1 text-left">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($teams)): ?>
          <tr>
            <td colspan="6" class="text-center text-gray-500 py-3">
              ยังไม่มีการกำหนดทีม
            </td>
          </tr>
        <?php else: $i=1; foreach ($teams as $t): ?>
          <tr class="hover:bg-slate-50 transition">
            <td class="border px-2 py-1"><?php echo $i++; ?></td>
            <td class="border px-2 py-1 font-mono text-xs"><?php echo h($t['code']); ?></td>
            <td class="border px-2 py-1">
              <!-- คลิกแล้ว reload พร้อม team_id แล้ว JS จะเปิด modal ให้ -->
              <a href="teams.php?team_id=<?php echo (int)$t['id']; ?>#memberModal"
                 class="font-medium text-blue-700 hover:underline">
                <?php echo h($t['name_th']); ?>
              </a>
              <?php if (!empty($t['name_en'])): ?>
                <div class="text-xs text-gray-500"><?php echo h($t['name_en']); ?></div>
              <?php endif; ?>
            </td>
            <td class="border px-2 py-1 text-xs">
              <?php
                $tg = isset($TEAM_GROUPS[$t['team_group']]) ? $TEAM_GROUPS[$t['team_group']] : $t['team_group'];
                echo h($tg);
              ?>
            </td>
            <td class="border px-2 py-1 text-xs">
              <?php if ((int)$t['is_active']): ?>
                <span class="inline-flex px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700 border border-emerald-200">
                  ใช้งาน
                </span>
              <?php else: ?>
                <span class="inline-flex px-2 py-0.5 rounded-full bg-gray-100 text-gray-500 border border-gray-200">
                  ปิดใช้งาน
                </span>
              <?php endif; ?>
            </td>
            <td class="border px-2 py-1 whitespace-nowrap text-xs">
              <a href="teams.php?edit_team=<?php echo (int)$t['id']; ?>#team-form"
                 class="inline-flex items-center rounded-lg bg-amber-500 px-2.5 py-1 text-white transition hover:bg-amber-600 mr-1">
                แก้ไข
              </a>
              <a href="teams.php?delete_team=<?php echo (int)$t['id']; ?>&csrf_token=<?php echo h(csrf_token()); ?>"
                 onclick="return confirm('ยืนยันลบทีมนี้หรือไม่? การลบอาจมีผลต่อข้อมูลสมาชิกทีม');"
                 class="inline-flex items-center rounded-lg bg-red-600 px-2.5 py-1 text-white transition hover:bg-red-700">
                ลบ
              </a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<!-- Modal สมาชิกทีม -->
<div id="memberModal"
     class="fixed inset-0 bg-slate-950/55 hidden items-center justify-center z-50 backdrop-blur-sm">
  <div class="bg-white rounded-3xl border border-slate-200 shadow-2xl w-full max-w-5xl mx-2 max-h-[90vh] flex flex-col">
    <div class="flex items-center justify-between px-4 py-3 border-b">
      <h2 class="text-lg font-semibold text-gray-800">
        สมาชิกทีม
        <?php if ($selected_team): ?>
          : <?php echo h($selected_team['name_th']); ?>
          <?php if (!empty($selected_team['name_en'])): ?>
            <span class="text-sm text-gray-500">
              (<?php echo h($selected_team['name_en']); ?>)
            </span>
          <?php endif; ?>
        <?php endif; ?>
      </h2>
      <button type="button"
              onclick="closeMemberModal();"
              class="text-gray-500 hover:text-gray-800 text-xl leading-none">
        &times;
      </button>
    </div>

    <div class="p-4 overflow-y-auto text-sm">
      <?php if (!$selected_team): ?>
        <p class="text-gray-500">
          กรุณาคลิกเลือกทีมจากตารางด้านบนก่อน
        </p>
      <?php else: ?>

        <!-- ตารางสมาชิก -->
        <div class="mb-4 overflow-x-auto">
          <table class="min-w-full text-sm border border-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="border px-2 py-1 text-left">#</th>
                <th class="border px-2 py-1 text-left">Username</th>
                <th class="border px-2 py-1 text-left">ชื่อ–สกุล</th>
                <th class="border px-2 py-1 text-left">หน่วยงาน</th>
                <th class="border px-2 py-1 text-left">Role</th>
                <th class="border px-2 py-1 text-left">หัวหน้าทีม</th>
                <th class="border px-2 py-1 text-left">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($team_members)): ?>
                <tr>
                  <td colspan="7" class="text-center text-gray-500 py-3">
                    ยังไม่มีสมาชิกในทีมนี้
                  </td>
                </tr>
              <?php else: $i=1; foreach ($team_members as $m): ?>
                <tr class="hover:bg-gray-50">
                  <td class="border px-2 py-1"><?php echo $i++; ?></td>
                  <td class="border px-2 py-1 font-mono text-xs"><?php echo h($m['username']); ?></td>
                  <td class="border px-2 py-1"><?php echo h($m['fullname']); ?></td>
                  <td class="border px-2 py-1 text-xs">
                    <?php echo h($m['department']); ?>
                    <?php if ($m['division'] !== ''): ?>
                      <div class="text-[11px] text-gray-500">
                        <?php echo h($m['division']); ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td class="border px-2 py-1 text-xs"><?php echo h($m['role']); ?></td>
                  <td class="border px-2 py-1 text-xs">
                    <?php if ((int)$m['is_leader']): ?>
                      <span class="inline-flex px-2 py-0.5 rounded-full bg-indigo-100 text-indigo-700 border border-indigo-200">
                        หัวหน้าทีม
                      </span>
                    <?php else: ?>
                      <span class="inline-flex px-2 py-0.5 rounded-full bg-gray-100 text-gray-500 border border-gray-200">
                        สมาชิก
                      </span>
                    <?php endif; ?>
                  </td>
                  <td class="border px-2 py-1 whitespace-nowrap text-xs">
                    <a href="teams.php?team_id=<?php echo $selected_team_id; ?>&toggle_leader=<?php echo (int)$m['user_id']; ?>&csrf_token=<?php echo h(csrf_token()); ?>#memberModal"
                       class="inline-flex items-center px-2 py-0.5 rounded bg-sky-600 hover:bg-sky-700 text-white mr-1">
                      <?php echo ((int)$m['is_leader'] ? 'ยกเลิกหัวหน้า' : 'ตั้งเป็นหัวหน้า'); ?>
                    </a>
                    <a href="teams.php?team_id=<?php echo $selected_team_id; ?>&remove_member=<?php echo (int)$m['user_id']; ?>&csrf_token=<?php echo h(csrf_token()); ?>#memberModal"
                       onclick="return confirm('ยืนยันลบผู้ใช้คนนี้ออกจากทีม?');"
                       class="inline-flex items-center px-2 py-0.5 rounded bg-red-600 hover:bg-red-700 text-white">
                      ลบ
                    </a>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

        <!-- ฟอร์มเพิ่มสมาชิก -->
        <div class="border-t pt-3 mt-3">
          <h3 class="text-sm font-semibold text-gray-800 mb-2">
            เพิ่มสมาชิกในทีมนี้
          </h3>

          <?php if (empty($available_users)): ?>
            <p class="text-sm text-gray-500">
              ผู้ใช้ทั้งหมดในระบบอยู่ในทีมนี้แล้ว หรือยังไม่มีผู้ใช้ในระบบ
            </p>
          <?php else: ?>
            <form method="post" class="flex flex-col md:flex-row gap-3 md:items-center">
              <input type="hidden" name="form_type" value="add_member">
              <input type="hidden" name="team_id" value="<?php echo $selected_team_id; ?>">
              <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">

              <div class="flex-1">
                <label class="block text-sm font-medium text-gray-700 mb-1">เลือกผู้ใช้</label>
                <select name="user_id" class="w-full border rounded px-2 py-1.5 text-sm" required>
                  <option value="">-- เลือกผู้ใช้ --</option>
                  <?php foreach ($available_users as $usr): ?>
                    <?php
                      $label = $usr['fullname'] !== ''
                             ? $usr['fullname'] . ' (' . $usr['username'] . ')'
                             : $usr['username'];
                      if ($usr['department'] !== '') {
                          $label .= ' - ' . $usr['department'];
                      }
                    ?>
                    <option value="<?php echo (int)$usr['id']; ?>">
                      <?php echo h($label); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="flex items-center gap-2">
                <input type="checkbox" id="is_leader" name="is_leader"
                       class="h-4 w-4 text-blue-600 border-gray-300 rounded">
                <label for="is_leader" class="text-sm text-gray-700">ตั้งเป็นหัวหน้าทีม</label>
              </div>

              <div>
                <button type="submit"
                        class="px-4 py-1.5 rounded bg-blue-600 hover:bg-blue-700 text-white text-sm">
                  เพิ่มสมาชิก
                </button>
              </div>
            </form>
          <?php endif; ?>
        </div>

      <?php endif; ?>
    </div>
  </div>
</div>

<script>
  function openMemberModal() {
    var m = document.getElementById('memberModal');
    if (!m) return;
    m.classList.remove('hidden');
    m.classList.add('flex');
  }
  function closeMemberModal() {
    var m = document.getElementById('memberModal');
    if (!m) return;
    m.classList.add('hidden');
    m.classList.remove('flex');

    // ล้าง anchor team_id ออกจาก URL (แต่ยังอยู่หน้าเดิม)
    if (window.history && window.history.replaceState) {
      var url = new URL(window.location.href);
      url.hash = '';
      url.searchParams.delete('team_id');
      window.history.replaceState({}, '', url.pathname + (url.searchParams.toString() ? '?' + url.searchParams.toString() : ''));
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    var shouldOpen = <?php echo (int)$open_member_modal; ?>;
    if (shouldOpen) {
      openMemberModal();
    }
  });
</script>

</body>
</html>

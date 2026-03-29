<?php
require_once __DIR__ . '/db_connect.php';
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

require_once __DIR__ . '/auth.php';
require_login();
require_role('admin');

$u = current_user();
$message = '';
$errors = array();

if (!function_exists('h')) {
    function h($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}

function oauth_sync_users_to_legacy($conn, $currentSessionRole, $currentProviderId)
{
    $result = array(
        'success' => false,
        'message' => '',
    );

    mysqli_begin_transaction($conn);

    if (!mysqli_query($conn, "DELETE FROM tb_user_team_map")) {
        mysqli_rollback($conn);
        $result['message'] = mysqli_error($conn);
        return $result;
    }

    @mysqli_query($conn, "DELETE FROM tb_user_teams");

    $sql = "
        SELECT
            au.provider_id,
            au.name_th,
            au.name_eng,
            au.position_name,
            au.position_type,
            org.hname_th
        FROM app_user au
        LEFT JOIN app_user_org org
            ON org.app_user_id = au.id AND org.is_default = 1
        WHERE au.is_active = 1
        ORDER BY au.name_th, au.name_eng, au.provider_id
    ";

    $res = mysqli_query($conn, $sql);
    if (!$res) {
        mysqli_rollback($conn);
        $result['message'] = mysqli_error($conn);
        return $result;
    }

    $count = 0;
    while ($row = mysqli_fetch_assoc($res)) {
        $username = trim((string) $row['provider_id']);
        if ($username === '') {
            continue;
        }

        $fullname = trim((string) $row['name_th']);
        if ($fullname === '') {
            $fullname = trim((string) $row['name_eng']);
        }
        if ($fullname === '') {
            $fullname = $username;
        }

        $position = trim((string) $row['position_name']);
        if ($position === '') {
            $position = trim((string) $row['position_type']);
        }
        $department = trim((string) $row['hname_th']);
        $division = '';
        $role = 'staff';

        if ($currentProviderId !== '' && $username === $currentProviderId) {
            $role = $currentSessionRole !== '' ? $currentSessionRole : 'admin';
        }

        $existingStmt = mysqli_prepare($conn, "SELECT id, password FROM tb_users WHERE username = ? LIMIT 1");
        if (!$existingStmt) {
            mysqli_free_result($res);
            mysqli_rollback($conn);
            $result['message'] = mysqli_error($conn);
            return $result;
        }

        mysqli_stmt_bind_param($existingStmt, 's', $username);
        mysqli_stmt_execute($existingStmt);
        $existingRes = mysqli_stmt_get_result($existingStmt);
        $existingRow = $existingRes ? mysqli_fetch_assoc($existingRes) : null;
        mysqli_stmt_close($existingStmt);

        $passwordHash = '';
        if ($existingRow && !empty($existingRow['password'])) {
            $passwordHash = (string) $existingRow['password'];
        } else {
            $passwordHash = password_hash(md5(uniqid(mt_rand(), true)), PASSWORD_BCRYPT);
        }

        if ($existingRow) {
            $userId = (int) $existingRow['id'];
            $updateStmt = mysqli_prepare(
                $conn,
                "UPDATE tb_users
                 SET fullname = ?, position = ?, department = ?, division = ?, role = ?
                 WHERE id = ?"
            );
            if (!$updateStmt) {
                mysqli_free_result($res);
                mysqli_rollback($conn);
                $result['message'] = mysqli_error($conn);
                return $result;
            }
            mysqli_stmt_bind_param($updateStmt, 'sssssi', $fullname, $position, $department, $division, $role, $userId);
            if (!mysqli_stmt_execute($updateStmt)) {
                mysqli_stmt_close($updateStmt);
                mysqli_free_result($res);
                mysqli_rollback($conn);
                $result['message'] = mysqli_error($conn);
                return $result;
            }
            mysqli_stmt_close($updateStmt);
        } else {
            $insertStmt = mysqli_prepare(
                $conn,
                "INSERT INTO tb_users
                 (username, fullname, position, department, division, password, role)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            if (!$insertStmt) {
                mysqli_free_result($res);
                mysqli_rollback($conn);
                $result['message'] = mysqli_error($conn);
                return $result;
            }
            mysqli_stmt_bind_param($insertStmt, 'sssssss', $username, $fullname, $position, $department, $division, $passwordHash, $role);
            if (!mysqli_stmt_execute($insertStmt)) {
                mysqli_stmt_close($insertStmt);
                mysqli_free_result($res);
                mysqli_rollback($conn);
                $result['message'] = mysqli_error($conn);
                return $result;
            }
            mysqli_stmt_close($insertStmt);
        }

        $count++;
    }
    mysqli_free_result($res);

    mysqli_commit($conn);
    $result['success'] = true;
    $result['message'] = 'รีเซ็ตสิทธิ์/ทีมเดิมและดึงผู้ใช้จาก Provider แล้ว ' . $count . ' รายการ';
    return $result;
}

function fetch_active_workgroups($conn)
{
    $workgroups = array();
    $sql = "
        SELECT group_name
        FROM tb_workgroups
        WHERE is_active = 1
        ORDER BY sort_order ASC, group_name ASC
    ";
    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $groupName = trim((string) $row['group_name']);
            if ($groupName !== '') {
                $workgroups[] = $groupName;
            }
        }
        mysqli_free_result($res);
    }

    return $workgroups;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'sync_oauth_users') {
        $sync = oauth_sync_users_to_legacy(
            $conn,
            isset($_SESSION['role']) ? normalize_role_name($_SESSION['role']) : 'admin',
            isset($_SESSION['provider_id']) ? (string) $_SESSION['provider_id'] : ''
        );
        if ($sync['success']) {
            $message = $sync['message'];
        } else {
            $errors[] = 'ไม่สามารถรีเซ็ตและดึงผู้ใช้จาก Provider ได้: ' . $sync['message'];
        }
    } elseif ($action === 'update') {
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $fullname = trim(isset($_POST['fullname']) ? $_POST['fullname'] : '');
        $position = trim(isset($_POST['position']) ? $_POST['position'] : '');
        $department = trim(isset($_POST['department']) ? $_POST['department'] : '');
        $division = trim(isset($_POST['division']) ? $_POST['division'] : '');
        $role = normalize_role_name(isset($_POST['role']) ? $_POST['role'] : 'staff');

        if ($id <= 0) {
            $errors[] = 'ไม่พบรหัสผู้ใช้';
        } else {
            $stmt = mysqli_prepare(
                $conn,
                "UPDATE tb_users
                 SET fullname = ?, position = ?, department = ?, division = ?, role = ?
                 WHERE id = ?"
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'sssssi', $fullname, $position, $department, $division, $role, $id);
                if (mysqli_stmt_execute($stmt)) {
                    $message = 'บันทึกสิทธิ์การใช้งานเรียบร้อย';
                } else {
                    $errors[] = 'ไม่สามารถบันทึกข้อมูลได้: ' . mysqli_error($conn);
                }
                mysqli_stmt_close($stmt);
            } else {
                $errors[] = 'Prepare failed: ' . mysqli_error($conn);
            }
        }
    }
}

$users = array();
$workgroups = fetch_active_workgroups($conn);
$sqlUsers = "
    SELECT
        u.id,
        u.username,
        u.fullname,
        u.position,
        u.department,
        u.division,
        u.role,
        au.position_name AS provider_position_name,
        au.position_type AS provider_position_type
    FROM tb_users u
    INNER JOIN app_user au
        ON au.provider_id = u.username
    ORDER BY u.fullname, u.username
";
$res = mysqli_query($conn, $sqlUsers);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        if (trim((string) $row['position']) === '') {
            $row['position'] = trim((string) $row['provider_position_name']);
            if (trim((string) $row['position']) === '') {
                $row['position'] = trim((string) $row['provider_position_type']);
            }
        }
        $users[] = $row;
    }
    mysqli_free_result($res);
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สิทธิ์การใช้งาน</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 min-h-screen">
<?php
$active_nav = 'settings';
include __DIR__ . '/navbar_kpi.php';
?>
<div class="w-full bg-white/95 p-6 rounded-3xl shadow-xl shadow-slate-200/70 border border-slate-200 mt-4 mb-6">
    <div class="flex flex-col gap-3 border-b border-slate-200 pb-4 mb-5 md:flex-row md:items-center md:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">สิทธิ์การใช้งาน</h1>
            <p class="mt-1 text-sm text-slate-500">หน้านี้จะแสดงเฉพาะผู้ใช้ที่มาจาก Provider/OAuth เท่านั้น ข้อมูลทีมเดิมจะถูกล้างก่อนแล้วค่อยกำหนดใหม่ภายหลัง</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <form method="post" class="inline-flex">
                <input type="hidden" name="action" value="sync_oauth_users">
                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                <button type="submit" class="inline-flex items-center rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700" onclick="return confirm('ระบบจะล้างข้อมูลสมาชิกทีมเดิมและรีเซ็ต role ของผู้ใช้จาก Provider เป็นค่าเริ่มต้น ต้องการดำเนินการต่อหรือไม่?');">
                    รีเซ็ตข้อมูลเดิมและดึงผู้ใช้จาก Provider
                </button>
            </form>
            <a href="teams.php" class="inline-flex items-center rounded-xl bg-sky-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-700">
                จัดการทีม
            </a>
            <a href="dashboard.php" class="inline-flex items-center rounded-xl bg-slate-800 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-900">
                กลับหน้า Dashboard
            </a>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 shadow-sm">
            <?php echo h($message); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="mb-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 shadow-sm space-y-1">
            <?php foreach ($errors as $error): ?>
                <div><?php echo h($error); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="border-b border-slate-200 px-4 py-4">
            <h2 class="text-lg font-semibold text-gray-800">รายชื่อผู้ใช้งานจาก Provider</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full border-collapse text-sm">
                <thead>
                    <tr class="bg-slate-100 text-gray-700">
                        <th class="px-2 py-2 text-left">ID</th>
                        <th class="px-2 py-2 text-left">USERNAME</th>
                        <th class="px-2 py-2 text-left">ชื่อ-สกุล</th>
                        <th class="px-2 py-2 text-left">ตำแหน่ง</th>
                        <th class="px-2 py-2 text-left">งาน/ฝ่าย</th>
                        <th class="px-2 py-2 text-left">กลุ่มงาน</th>
                        <th class="px-2 py-2 text-left">ROLE</th>
                        <th class="px-2 py-2 text-center">จัดการ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="8" class="px-4 py-6 text-center text-slate-500">ยังไม่มีผู้ใช้จาก Provider ในรายการ กรุณากดปุ่มรีเซ็ตและดึงข้อมูลก่อน</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($users as $row): ?>
                        <tr>
                            <td class="px-2 py-2 text-gray-500"><?php echo (int) $row['id']; ?></td>
                            <td class="px-2 py-2 font-mono text-xs"><?php echo h($row['username']); ?></td>
                            <td class="px-2 py-2">
                                <form method="post" class="space-y-1 md:space-y-0 md:flex md:flex-wrap md:items-center">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                                    <input type="text" name="fullname" class="border border-slate-300 rounded-xl px-3 py-2 w-full md:w-48 mb-1 md:mb-0 text-sm shadow-sm" value="<?php echo h($row['fullname']); ?>">
                            </td>
                            <td class="px-2 py-2">
                                    <input type="text" name="position" class="border border-slate-300 rounded-xl px-3 py-2 w-full md:w-40 text-sm shadow-sm bg-slate-50" value="<?php echo h($row['position']); ?>" readonly>
                            </td>
                            <td class="px-2 py-2">
                                    <input type="text" name="department" class="border border-slate-300 rounded-xl px-3 py-2 w-full md:w-40 text-sm shadow-sm" value="<?php echo h($row['department']); ?>">
                            </td>
                            <td class="px-2 py-2">
                                    <select name="division" class="border border-slate-300 rounded-xl px-3 py-2 w-full md:w-48 text-sm shadow-sm">
                                        <option value="">เลือกกลุ่มงาน</option>
                                        <?php foreach ($workgroups as $workgroupName): ?>
                                            <option value="<?php echo h($workgroupName); ?>" <?php echo ($row['division'] === $workgroupName ? 'selected' : ''); ?>>
                                                <?php echo h($workgroupName); ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <?php if (trim((string) $row['division']) !== '' && !in_array($row['division'], $workgroups, true)): ?>
                                            <option value="<?php echo h($row['division']); ?>" selected>
                                                <?php echo h($row['division']); ?>
                                            </option>
                                        <?php endif; ?>
                                    </select>
                            </td>
                            <td class="px-2 py-2">
                                    <select name="role" class="border border-slate-300 rounded-xl px-3 py-2 w-full md:w-28 text-sm shadow-sm">
                                        <?php foreach (array('staff', 'manager', 'admin') as $role): ?>
                                            <option value="<?php echo h($role); ?>" <?php echo ($row['role'] === $role ? 'selected' : ''); ?>>
                                                <?php echo h($role); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                            </td>
                            <td class="px-2 py-2 text-center align-top">
                                    <button type="submit" class="rounded-lg bg-blue-600 px-3 py-2 text-white text-xs font-medium transition hover:bg-blue-700">
                                        บันทึก
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>

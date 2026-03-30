<?php
session_start();
include 'db_connect.php';
require_once __DIR__ . '/auth.php';
require_login();

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function pretty_json($value)
{
    if ($value === null || $value === '') {
        return '';
    }
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $value;
    }
    return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

$user_id = (int)$_SESSION['user_id'];
$message = '';
$message_type = 'success';

$providerId = isset($_SESSION['provider_id']) ? (string)$_SESSION['provider_id'] : '';
$displayNameTh = isset($_SESSION['name_th']) ? (string)$_SESSION['name_th'] : '';
$displayNameEn = isset($_SESSION['name_eng']) ? (string)$_SESSION['name_eng'] : '';
$positionName = isset($_SESSION['position_name']) ? (string)$_SESSION['position_name'] : '';
$hcode = isset($_SESSION['hcode']) ? (string)$_SESSION['hcode'] : '';
$hnameTh = isset($_SESSION['hname_th']) ? (string)$_SESSION['hname_th'] : '';
$healthOnlyLogin = !empty($_SESSION['health_only_login']);
$healthOnlyReason = isset($_SESSION['health_only_reason']) ? (string)$_SESSION['health_only_reason'] : '';
$providerProfile = isset($_SESSION['oauth_v2_last_profile']) ? $_SESSION['oauth_v2_last_profile'] : null;
$providerPayload = is_array($providerProfile) && isset($providerProfile['data']) && is_array($providerProfile['data']) ? $providerProfile['data'] : (is_array($providerProfile) ? $providerProfile : array());
$providerOrganizations = array();
if (isset($providerPayload['organization']) && is_array($providerPayload['organization'])) {
    $providerOrganizations = $providerPayload['organization'];
} elseif (isset($providerPayload['organizations']) && is_array($providerPayload['organizations'])) {
    $providerOrganizations = $providerPayload['organizations'];
}
$resolvedPosition = $positionName;
if ($resolvedPosition === '' && !empty($providerPayload['position'])) {
    $resolvedPosition = (string)$providerPayload['position'];
}
if ($resolvedPosition === '' && !empty($providerPayload['position_name'])) {
    $resolvedPosition = (string)$providerPayload['position_name'];
}
if ($resolvedPosition === '' && !empty($providerPayload['position_type'])) {
    $resolvedPosition = (string)$providerPayload['position_type'];
}
if ($resolvedPosition === '' && !empty($providerOrganizations)) {
    foreach ($providerOrganizations as $organization) {
        if (!is_array($organization)) {
            continue;
        }
        if (!empty($organization['position'])) {
            $resolvedPosition = (string)$organization['position'];
            break;
        }
        if (!empty($organization['position_name'])) {
            $resolvedPosition = (string)$organization['position_name'];
            break;
        }
        if (!empty($organization['position_type'])) {
            $resolvedPosition = (string)$organization['position_type'];
            break;
        }
    }
}

$user = array(
    'username' => '',
    'fullname' => '',
    'position' => '',
    'department' => '',
    'division' => '',
    'role' => ''
);

$sql = "SELECT username, fullname, position, department, division, role FROM tb_users WHERE id = ?";
if ($st = mysqli_prepare($conn, $sql)) {
    mysqli_stmt_bind_param($st, "i", $user_id);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    if ($res && mysqli_num_rows($res) > 0) {
        $user = mysqli_fetch_assoc($res);
    }
    mysqli_stmt_close($st);
}

$workgroups = array();
$sql_wg = "
  SELECT id, group_code, group_name
  FROM tb_workgroups
  WHERE is_active = 1
  ORDER BY sort_order ASC, group_code ASC, group_name ASC
";
if ($res = mysqli_query($conn, $sql_wg)) {
    while ($r = mysqli_fetch_assoc($res)) {
        $workgroups[] = $r;
    }
    mysqli_free_result($res);
}

$departments = array();
$res = mysqli_query($conn, "SELECT id, department_name FROM tb_departments ORDER BY department_name ASC");
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $departments[] = $r['department_name'];
    }
    mysqli_free_result($res);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $fullname = isset($_POST['fullname']) ? trim($_POST['fullname']) : '';
    $position = isset($_POST['position']) ? trim($_POST['position']) : '';
    $department = isset($_POST['department']) ? trim($_POST['department']) : '';
    $division = isset($_POST['division']) ? trim($_POST['division']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';

    if ($fullname === '' || $position === '') {
        $message = 'กรุณากรอกชื่อ-สกุล และตำแหน่ง';
        $message_type = 'error';
    } else {
        if ($password !== '') {
            $hashed_password = password_hash($password, PASSWORD_BCRYPT);
            $update_sql = "UPDATE tb_users SET fullname = ?, position = ?, department = ?, division = ?, password = ? WHERE id = ?";
        } else {
            $update_sql = "UPDATE tb_users SET fullname = ?, position = ?, department = ?, division = ? WHERE id = ?";
        }

        if ($st = mysqli_prepare($conn, $update_sql)) {
            if ($password !== '') {
                mysqli_stmt_bind_param($st, "sssssi", $fullname, $position, $department, $division, $hashed_password, $user_id);
            } else {
                mysqli_stmt_bind_param($st, "ssssi", $fullname, $position, $department, $division, $user_id);
            }

            if (mysqli_stmt_execute($st)) {
                $message = 'อัปเดตโปรไฟล์เรียบร้อย';
                $message_type = 'success';
                $_SESSION['fullname'] = $fullname;
                $user['fullname'] = $fullname;
                $user['position'] = $position;
                $user['department'] = $department;
                $user['division'] = $division;
            } else {
                $message = 'เกิดข้อผิดพลาดในการอัปเดตข้อมูล: ' . mysqli_error($conn);
                $message_type = 'error';
            }
            mysqli_stmt_close($st);
        } else {
            $message = 'Prepare failed: ' . mysqli_error($conn);
            $message_type = 'error';
        }
    }
}

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>โปรไฟล์ผู้ใช้งาน | ระบบบริหารตัวชี้วัด KPI</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="css/enterprise-ui.css">
</head>
<body class="min-h-screen">
<?php
$active_nav = 'profile';
include __DIR__ . '/navbar_kpi.php';

kpi_page_header(
    'โปรไฟล์ผู้ใช้งาน',
    'หน้านี้แสดงข้อมูลบัญชีในระบบและข้อมูลตัวตนจาก Provider ID เพื่อให้ตรวจสอบการเชื่อมโยงข้อมูลได้จากหน้าเดียว',
    array(
        array('label' => 'หน้าแรก', 'href' => 'index.php'),
        array('label' => 'โปรไฟล์ผู้ใช้งาน')
    ),
    kpi_enterprise_action_link('index.php', 'กลับหน้าแรก', 'secondary')
);
?>

<main class="enterprise-shell">
    <section class="grid gap-4 md:grid-cols-3 mb-6">
        <div class="enterprise-metric">
            <div class="text-sm text-slate-500">บัญชีเข้าสู่ระบบ</div>
            <div class="mt-2 text-2xl font-semibold text-slate-950 break-all"><?php echo h($providerId !== '' ? $providerId : $user['username']); ?></div>
            <div class="mt-1 text-sm text-slate-500">บทบาท <?php echo h($user['role'] !== '' ? $user['role'] : 'user'); ?></div>
        </div>
        <div class="enterprise-metric">
            <div class="text-sm text-slate-500">ชื่อและตำแหน่ง</div>
            <div class="mt-2 text-lg font-semibold text-slate-950"><?php echo h($displayNameTh !== '' ? $displayNameTh : ($user['fullname'] !== '' ? $user['fullname'] : '-')); ?></div>
            <div class="mt-1 text-sm text-slate-500"><?php echo h($resolvedPosition !== '' ? $resolvedPosition : ($user['position'] !== '' ? $user['position'] : 'ยังไม่ระบุตำแหน่ง')); ?></div>
        </div>
        <div class="enterprise-metric">
            <div class="text-sm text-slate-500">สถานะข้อมูล</div>
            <div class="mt-2">
                <span class="enterprise-status <?php echo $message_type === 'error' ? 'enterprise-status-danger' : 'enterprise-status-success'; ?>">
                    <?php echo $message_type === 'error' ? 'ต้องตรวจสอบ' : 'พร้อมใช้งาน'; ?>
                </span>
            </div>
            <div class="mt-2 text-sm text-slate-500"><?php echo h($healthOnlyLogin ? 'โหมดสำรองชั่วคราว' : 'ใช้ข้อมูลจาก Provider ID / Session ปัจจุบัน'); ?></div>
        </div>
    </section>

    <section class="enterprise-panel p-6 sm:p-8 mb-6">
        <div class="mb-6">
            <div class="enterprise-kicker mb-3"><span class="inline-flex h-2 w-2 rounded-full bg-teal-500"></span>Identity Snapshot</div>
            <h2 class="text-2xl font-semibold text-slate-950">ข้อมูลผู้เข้าใช้งานที่ระบบได้รับ</h2>
            <p class="mt-2 text-sm text-slate-500">ใช้ส่วนนี้ตรวจสอบว่า session ปัจจุบันของผู้ใช้ถูก map มาจากข้อมูลใดบ้าง</p>
        </div>

        <?php if ($healthOnlyLogin && $healthOnlyReason !== ''): ?>
            <div class="mb-6 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                กำลังใช้โหมดสำรองชั่วคราว
                <div class="mt-1 break-words font-mono text-xs"><?php echo h($healthOnlyReason); ?></div>
            </div>
        <?php endif; ?>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="enterprise-metric">
                <div class="text-sm text-slate-500">Provider ID</div>
                <div class="mt-2 text-lg font-semibold text-slate-950 break-all"><?php echo h($providerId !== '' ? $providerId : '-'); ?></div>
            </div>
            <div class="enterprise-metric">
                <div class="text-sm text-slate-500">ชื่อภาษาไทย</div>
                <div class="mt-2 text-lg font-semibold text-slate-950"><?php echo h($displayNameTh !== '' ? $displayNameTh : '-'); ?></div>
            </div>
            <div class="enterprise-metric">
                <div class="text-sm text-slate-500">ชื่อภาษาอังกฤษ</div>
                <div class="mt-2 text-lg font-semibold text-slate-950"><?php echo h($displayNameEn !== '' ? $displayNameEn : '-'); ?></div>
            </div>
            <div class="enterprise-metric">
                <div class="text-sm text-slate-500">หน่วยบริการ</div>
                <div class="mt-2 text-lg font-semibold text-slate-950"><?php echo h($hcode !== '' ? $hcode : '-'); ?></div>
                <div class="mt-1 text-sm text-slate-500"><?php echo h($hnameTh !== '' ? $hnameTh : 'ไม่มีข้อมูลหน่วยบริการ'); ?></div>
            </div>
        </div>

        <?php
        $infoCards = array(
            'ข้อมูลบัญชีหลัก' => array(
                'Provider ID' => $providerId !== '' ? $providerId : '-',
                'ชื่อภาษาไทย' => $displayNameTh !== '' ? $displayNameTh : '-',
                'ชื่อภาษาอังกฤษ' => $displayNameEn !== '' ? $displayNameEn : '-',
                'ตำแหน่ง' => $resolvedPosition !== '' ? $resolvedPosition : '-',
                'หน่วยบริการ (HCODE)' => $hcode !== '' ? $hcode : '-',
                'ชื่อหน่วยบริการ' => $hnameTh !== '' ? $hnameTh : '-',
                'โหมดการเข้าสู่ระบบ' => $healthOnlyLogin ? 'สำรองชั่วคราว' : 'Provider ID',
            ),
            'ข้อมูลจาก Provider' => array(
                'provider_id' => isset($providerPayload['provider_id']) ? $providerPayload['provider_id'] : '-',
                'name_th' => isset($providerPayload['name_th']) ? $providerPayload['name_th'] : '-',
                'name_eng' => isset($providerPayload['name_eng']) ? $providerPayload['name_eng'] : '-',
                'position' => isset($providerPayload['position']) ? $providerPayload['position'] : (isset($providerPayload['position_name']) ? $providerPayload['position_name'] : ($resolvedPosition !== '' ? $resolvedPosition : '-')),
                'position_type' => isset($providerPayload['position_type']) ? $providerPayload['position_type'] : ($resolvedPosition !== '' ? $resolvedPosition : '-'),
                'organizations' => isset($providerPayload['organization']) && is_array($providerPayload['organization']) ? count($providerPayload['organization']) . ' รายการ' : (isset($providerPayload['organizations']) && is_array($providerPayload['organizations']) ? count($providerPayload['organizations']) . ' รายการ' : '-'),
            ),
        );
        ?>

        <div class="mt-6 grid gap-6 xl:grid-cols-3">
            <?php foreach ($infoCards as $sectionTitle => $rows): ?>
                <section class="enterprise-section p-5">
                    <h3 class="text-lg font-semibold text-slate-900"><?php echo h($sectionTitle); ?></h3>
                    <div class="mt-4 divide-y divide-slate-200">
                        <?php foreach ($rows as $label => $value): ?>
                            <div class="grid grid-cols-1 gap-1 py-3 sm:grid-cols-[170px_1fr] sm:gap-4">
                                <div class="text-sm font-medium text-slate-500"><?php echo h($label); ?></div>
                                <div class="text-sm font-semibold text-slate-900 break-words"><?php echo h((string)$value); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>

        <div class="mt-6 grid gap-6">
            <section class="enterprise-section p-5">
                <h3 class="text-lg font-semibold text-slate-900">Provider Profile Raw</h3>
                <pre class="mt-4 overflow-auto rounded-3xl bg-slate-950 px-4 py-4 text-xs leading-6 text-slate-100"><?php echo h(pretty_json($providerProfile)); ?></pre>
            </section>
        </div>
    </section>
</main>
</body>
</html>

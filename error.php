<?php
require_once __DIR__ . '/config/oauth-config-loader.php';

session_start();

$type = isset($_GET['type']) ? $_GET['type'] : 'unknown';
$error_data = isset($_SESSION['oauth_error']) ? $_SESSION['oauth_error'] : array();

$error_details = array(
    'oauth_error' => array(
        'title' => 'เกิดข้อผิดพลาดในกระบวนการยืนยันตัวตน',
        'message' => 'ระบบตรวจพบปัญหาระหว่างขั้นตอน OAuth และยังไม่สามารถดำเนินการเข้าสู่ระบบต่อได้',
        'icon' => '⚠',
        'status' => 'enterprise-status-warn',
    ),
    'invalid_callback' => array(
        'title' => 'ข้อมูล callback ไม่สมบูรณ์',
        'message' => 'ระบบไม่ได้รับ authorization code หรือ state ครบถ้วน กรุณาเริ่มกระบวนการเข้าสู่ระบบใหม่อีกครั้ง',
        'icon' => '⛔',
        'status' => 'enterprise-status-danger',
    ),
    'csrf_error' => array(
        'title' => 'Session สำหรับการยืนยันตัวตนหมดอายุ',
        'message' => 'เพื่อความปลอดภัย ระบบต้องให้คุณเริ่มขั้นตอนยืนยันตัวตนใหม่อีกครั้ง',
        'icon' => '🔒',
        'status' => 'enterprise-status-warn',
    ),
    'login_failed' => array(
        'title' => 'ยังไม่สามารถเข้าสู่ระบบได้',
        'message' => 'ระบบตรวจสอบข้อมูลของคุณไม่สำเร็จในรอบนี้ กรุณาลองใหม่หรือตรวจสอบรายละเอียดด้านล่าง',
        'icon' => '✕',
        'status' => 'enterprise-status-danger',
    ),
    'unknown' => array(
        'title' => 'เกิดข้อผิดพลาดที่ไม่ทราบสาเหตุ',
        'message' => 'ระบบพบเหตุการณ์ที่ยังไม่สามารถระบุสาเหตุได้ชัดเจน',
        'icon' => '⚠',
        'status' => 'enterprise-status-warn',
    ),
);

$details = isset($error_details[$type]) ? $error_details[$type] : $error_details['unknown'];
unset($_SESSION['oauth_error']);
?><!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>สถานะการเข้าสู่ระบบ | KPI Enterprise</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="css/enterprise-ui.css">
</head>
<body class="min-h-screen">
    <div class="enterprise-shell py-8">
        <div class="mx-auto max-w-3xl">
            <section class="enterprise-panel enterprise-glow overflow-hidden">
                <div class="bg-[linear-gradient(135deg,#0f172a,#0f4c81)] px-8 py-8 text-white">
                    <div class="text-5xl"><?php echo $details['icon']; ?></div>
                    <div class="mt-5 flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <div class="enterprise-kicker text-cyan-200"><span class="inline-flex h-2 w-2 rounded-full bg-cyan-300"></span>Login Incident</div>
                            <h1 class="mt-3 text-3xl font-semibold"><?php echo htmlspecialchars($details['title']); ?></h1>
                        </div>
                        <span class="enterprise-status <?php echo $details['status']; ?>"><?php echo $type === 'login_failed' ? 'Action Required' : 'Verification Needed'; ?></span>
                    </div>
                </div>

                <div class="p-8">
                    <p class="text-base leading-8 text-slate-600"><?php echo htmlspecialchars($details['message']); ?></p>

                    <?php if (!empty($error_data['code']) || !empty($error_data['message'])): ?>
                        <div class="mt-6 rounded-[1.5rem] border border-slate-200 bg-slate-50 p-5">
                            <div class="text-sm font-semibold text-slate-700">รายละเอียดทางเทคนิค</div>
                            <?php if (!empty($error_data['code'])): ?>
                                <div class="mt-3 text-xs uppercase tracking-[0.18em] text-slate-400">Error Code</div>
                                <div class="mt-1 break-all rounded-2xl bg-white px-4 py-3 font-mono text-sm text-slate-700 shadow-sm"><?php echo htmlspecialchars($error_data['code']); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($error_data['message'])): ?>
                                <div class="mt-4 text-xs uppercase tracking-[0.18em] text-slate-400">Message</div>
                                <div class="mt-1 break-words rounded-2xl bg-white px-4 py-3 font-mono text-sm leading-7 text-slate-700 shadow-sm"><?php echo htmlspecialchars(substr($error_data['message'], 0, 500)); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="mt-6 grid gap-4 md:grid-cols-2">
                        <section class="enterprise-section p-5">
                            <div class="text-sm font-semibold text-slate-800">ข้อเสนอแนะเบื้องต้น</div>
                            <ul class="mt-3 space-y-2 text-sm leading-7 text-slate-600">
                                <li>เริ่มเข้าสู่ระบบใหม่อีกครั้งจากหน้า login</li>
                                <li>ตรวจสอบว่า browser ยังถือ session เดิมอยู่หรือไม่</li>
                                <li>หากเป็นกรณี Provider ID ให้ยืนยันข้อมูลบัญชีอีกครั้ง</li>
                            </ul>
                        </section>
                        <section class="enterprise-section p-5">
                            <div class="text-sm font-semibold text-slate-800">ช่องทางดำเนินการต่อ</div>
                            <div class="mt-3 flex flex-col gap-3">
                                <a href="login-oauth-v2.php" class="enterprise-button enterprise-button-primary justify-center text-sm">เริ่มเข้าสู่ระบบใหม่</a>
                                <a href="index.php" class="enterprise-button enterprise-button-secondary justify-center text-sm">กลับหน้าเริ่มต้น</a>
                            </div>
                        </section>
                    </div>
                </div>
            </section>
        </div>
    </div>
</body>
</html>

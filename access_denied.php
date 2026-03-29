<?php
session_start();

$reason = isset($_GET['reason']) ? $_GET['reason'] : 'unknown';

$reasons = array(
    'no_provider_id' => array(
        'title' => 'ยังไม่พบสิทธิ์ Provider ID สำหรับบัญชีนี้',
        'message' => 'บัญชีของคุณยังไม่ผ่านเงื่อนไข Provider ID ที่ระบบต้องใช้สำหรับการเข้าใช้งานบางส่วน',
        'detail' => 'หากคุณมีบัญชีในอีก environment หนึ่งอยู่แล้ว กรุณาตรวจสอบว่ากำลังทดสอบบนระบบที่ถูกต้อง และติดต่อผู้ดูแลเพื่อยืนยัน mapping ของบัญชี',
        'status' => 'enterprise-status-warn',
        'icon' => '🪪',
    ),
    'user_disabled' => array(
        'title' => 'บัญชีนี้ถูกระงับการใช้งาน',
        'message' => 'ผู้ดูแลระบบได้ปิดสิทธิ์การใช้งานบัญชีนี้ไว้ชั่วคราวหรือถาวร',
        'detail' => 'กรุณาติดต่อผู้ดูแลระบบเพื่อขอเปิดใช้งานบัญชีอีกครั้ง',
        'status' => 'enterprise-status-danger',
        'icon' => '🚫',
    ),
    'insufficient_role' => array(
        'title' => 'สิทธิ์ของบัญชีไม่เพียงพอ',
        'message' => 'บัญชีของคุณยังไม่มี role หรือ permission ที่จำเป็นสำหรับหน้าที่ร้องขอ',
        'detail' => 'กรุณาติดต่อผู้ดูแลระบบเพื่อมอบสิทธิ์ที่เหมาะสมกับบทบาทงานของคุณ',
        'status' => 'enterprise-status-danger',
        'icon' => '🛡',
    ),
    'unknown' => array(
        'title' => 'ไม่สามารถอนุญาตให้เข้าถึงได้',
        'message' => 'ระบบยังไม่สามารถอนุญาตให้บัญชีนี้ใช้งานต่อได้',
        'detail' => 'กรุณาติดต่อผู้ดูแลระบบพร้อมระบุขั้นตอนที่ทำก่อนเกิดเหตุการณ์นี้',
        'status' => 'enterprise-status-warn',
        'icon' => '⚠',
    ),
);

$reason_info = isset($reasons[$reason]) ? $reasons[$reason] : $reasons['unknown'];
?><!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Policy | KPI Enterprise</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="css/enterprise-ui.css">
</head>
<body class="min-h-screen">
    <div class="enterprise-shell py-8">
        <div class="mx-auto max-w-3xl">
            <section class="enterprise-panel enterprise-glow overflow-hidden">
                <div class="bg-[linear-gradient(135deg,#7c2d12,#b45309)] px-8 py-8 text-white">
                    <div class="text-5xl"><?php echo $reason_info['icon']; ?></div>
                    <div class="mt-5 flex flex-wrap items-center justify-between gap-4">
                        <div>
                            <div class="enterprise-kicker text-amber-100"><span class="inline-flex h-2 w-2 rounded-full bg-amber-200"></span>Access Policy</div>
                            <h1 class="mt-3 text-3xl font-semibold"><?php echo htmlspecialchars($reason_info['title']); ?></h1>
                        </div>
                        <span class="enterprise-status <?php echo $reason_info['status']; ?>">Restricted</span>
                    </div>
                </div>

                <div class="p-8">
                    <p class="text-lg font-medium text-slate-800"><?php echo htmlspecialchars($reason_info['message']); ?></p>
                    <p class="mt-3 text-sm leading-7 text-slate-600"><?php echo htmlspecialchars($reason_info['detail']); ?></p>

                    <div class="mt-6 grid gap-4 md:grid-cols-2">
                        <section class="enterprise-section p-5">
                            <div class="text-sm font-semibold text-slate-800">สิ่งที่ควรตรวจสอบ</div>
                            <ul class="mt-3 space-y-2 text-sm leading-7 text-slate-600">
                                <li>บัญชี Health ID ที่ใช้เข้าสู่ระบบเป็นบัญชีที่ถูกต้อง</li>
                                <li>environment ที่กำลังทดสอบตรงกับ environment ของสิทธิ์ที่มี</li>
                                <li>บัญชีนี้ได้รับ role และสถานะ active แล้วในระบบภายใน</li>
                            </ul>
                        </section>
                        <section class="enterprise-section p-5">
                            <div class="text-sm font-semibold text-slate-800">ดำเนินการต่อ</div>
                            <div class="mt-3 flex flex-col gap-3">
                                <a href="login-oauth-v2.php" class="enterprise-button enterprise-button-primary justify-center text-sm">กลับไปหน้าเข้าสู่ระบบ</a>
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

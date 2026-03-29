<?php
require_once __DIR__ . '/config/oauth-config-loader.php';
require_once __DIR__ . '/lib/HttpClient.php';
require_once __DIR__ . '/lib/OAuthGatewayV2.php';

ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
session_start();

$http = new HttpClient(array(
    'timeout' => 30,
    'verify_ssl' => true,
));

$basePath = isset($config['app']['base_path']) ? (string) $config['app']['base_path'] : '';
$baseUrl = isset($config['app']['base_url']) ? rtrim((string) $config['app']['base_url'], '/') : '';
$loginActionUrl = $basePath . '/login-oauth-v2.php';
$indexUrl = $basePath . '/index.php';
$v2RedirectUri = $baseUrl . $basePath . '/oauth/callback-v2.php';
$gateway = new OAuthGatewayV2($config, $http, $v2RedirectUri);

if (!function_exists('oauth_v2_random_hex')) {
    function oauth_v2_random_hex($bytes)
    {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes($bytes));
        }

        if (function_exists('openssl_random_pseudo_bytes')) {
            $strong = false;
            $buffer = openssl_random_pseudo_bytes($bytes, $strong);
            if ($buffer !== false && strlen($buffer) === $bytes) {
                return bin2hex($buffer);
            }
        }

        return md5(uniqid(mt_rand(), true)) . md5(uniqid(mt_rand(), true));
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'start') {
    $state = substr(oauth_v2_random_hex(16), 0, 32);
    $_SESSION['oauth_v2_state'] = $state;
    $_SESSION['oauth_v2_state_time'] = time();
    header('Location: ' . $gateway->buildHealthAuthorizeUrl($state));
    exit();
}
?><!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ KPI</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="css/enterprise-ui.css">
</head>
<body class="min-h-screen overflow-x-hidden">
    <div class="relative min-h-screen px-4 py-8 sm:px-6 lg:px-8">
        <div class="absolute inset-0 -z-10 bg-[radial-gradient(circle_at_top_left,rgba(20,184,166,.15),transparent_28rem),radial-gradient(circle_at_bottom_right,rgba(15,76,129,.14),transparent_26rem),linear-gradient(180deg,#f8fafc_0%,#ecfeff_45%,#eff6ff_100%)]"></div>

        <div class="mx-auto flex min-h-[calc(100vh-4rem)] max-w-7xl items-center">
            <div class="grid w-full gap-8 lg:grid-cols-[1.1fr_0.9fr]">
                <section class="enterprise-panel enterprise-glow p-8 sm:p-10">
                    <h1 class="enterprise-page-title max-w-3xl font-semibold">ศูนย์กลางบริหารตัวชี้วัดองค์กร สำหรับงานคุณภาพ กลยุทธ์ และการติดตามผลแบบมืออาชีพ</h1>
                    <p class="enterprise-page-subtitle mt-5 text-base">
                        ระบบนี้ออกแบบให้รองรับงาน KPI ระดับองค์กรอย่างเป็นทางการ ทั้งการบันทึกผล ติดตามความก้าวหน้า รายงานเชิงผู้บริหาร และการกำกับดูแลข้อมูลด้วยมาตรฐานเดียวกันทั้งหน่วยงาน
                    </p>

                    <div class="mt-8 grid gap-4 sm:grid-cols-3">
                        <div class="enterprise-metric">
                            <div class="text-sm text-slate-500">Authentication</div>
                            <div class="mt-2 text-xl font-semibold text-slate-950">Health ID</div>
                            <div class="mt-1 text-sm text-slate-500">Single sign-on พร้อม OTP</div>
                        </div>
                        <div class="enterprise-metric">
                            <div class="text-sm text-slate-500">Governance</div>
                            <div class="mt-2 text-xl font-semibold text-slate-950">Enterprise-ready</div>
                            <div class="mt-1 text-sm text-slate-500">รองรับ workflow และ role ภายในองค์กร</div>
                        </div>
                        <div class="enterprise-metric">
                            <div class="text-sm text-slate-500">Current Callback</div>
                            <div class="mt-2 text-lg font-semibold text-slate-950">v2 Flow</div>
                            <div class="mt-1 truncate text-xs text-slate-500"><?php echo htmlspecialchars($v2RedirectUri, ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                    </div>
                </section>

                <section class="enterprise-panel p-8 sm:p-10">
                    <div class="inline-flex h-14 w-14 items-center justify-center rounded-3xl bg-gradient-to-br from-teal-500 to-sky-700 text-2xl text-white shadow-lg shadow-teal-500/20">K</div>
                    <h2 class="mt-6 text-3xl font-semibold text-slate-950">เข้าสู่ระบบ KPI</h2>
                    <p class="mt-3 text-sm leading-7 text-slate-500">
                        ใช้บัญชี Health ID เพื่อยืนยันตัวตนและเข้าสู่ระบบจัดการตัวชี้วัดขององค์กรอย่างปลอดภัย
                    </p>

                    <div class="mt-8 rounded-[1.75rem] border border-slate-200 bg-slate-50/80 p-5">
                        <div class="text-sm font-semibold text-slate-700">สิ่งที่ระบบจะทำหลังยืนยันตัวตน</div>
                        <ul class="mt-3 space-y-3 text-sm text-slate-600">
                            <li>ยืนยันตัวตนด้วย OTP ผ่าน Health ID</li>
                            <li>สร้าง session สำหรับเข้าใช้งานระบบ KPI ของคุณ</li>
                            <li>พาเข้าสู่หน้าเริ่มต้นของระบบโดยอัตโนมัติ</li>
                        </ul>
                    </div>

                    <div class="mt-8 flex flex-col gap-3">
                        <form action="<?php echo htmlspecialchars($loginActionUrl, ENT_QUOTES, 'UTF-8'); ?>" method="get" class="relative z-20 block">
                            <input type="hidden" name="action" value="start">
                            <button type="submit" class="enterprise-button enterprise-button-primary relative z-20 w-full justify-center text-base" style="pointer-events:auto;cursor:pointer;">
                                เข้าสู่ระบบด้วย Health ID
                            </button>
                        </form>
                        <form action="<?php echo htmlspecialchars($indexUrl, ENT_QUOTES, 'UTF-8'); ?>" method="get" class="relative z-20 block">
                            <button type="submit" class="enterprise-button enterprise-button-secondary relative z-20 w-full justify-center text-sm" style="pointer-events:auto;cursor:pointer;">
                                ไปหน้าเริ่มต้นของระบบ
                            </button>
                        </form>
                    </div>
                </section>
            </div>
        </div>
    </div>
</body>
</html>

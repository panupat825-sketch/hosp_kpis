<?php
session_start();

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

$healthToken = isset($_SESSION['debug_health_access_token']) ? (string)$_SESSION['debug_health_access_token'] : '';
$providerToken = isset($_SESSION['debug_provider_access_token']) ? (string)$_SESSION['debug_provider_access_token'] : '';
$healthTime = isset($_SESSION['debug_health_token_time']) ? date('Y-m-d H:i:s', (int)$_SESSION['debug_health_token_time']) : '';
$providerTime = isset($_SESSION['debug_provider_token_time']) ? date('Y-m-d H:i:s', (int)$_SESSION['debug_provider_token_time']) : '';
$healthRaw = isset($_SESSION['oauth_v2_health']) ? $_SESSION['oauth_v2_health'] : null;
$healthUserInfo = isset($_SESSION['oauth_v2_health_userinfo']) ? $_SESSION['oauth_v2_health_userinfo'] : null;
$providerProfile = isset($_SESSION['oauth_v2_last_profile']) ? $_SESSION['oauth_v2_last_profile'] : null;
?><!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug Tokens | KPI Enterprise</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="css/enterprise-ui.css">
</head>
<body class="min-h-screen">
    <main class="enterprise-shell py-8">
        <section class="enterprise-panel enterprise-glow p-6 sm:p-8">
            <div class="enterprise-kicker mb-3"><span class="inline-flex h-2 w-2 rounded-full bg-sky-500"></span>Token Inspector</div>
            <h1 class="enterprise-page-title font-semibold">ดู token และข้อมูลที่ได้จาก Health ID / Provider</h1>
            <p class="enterprise-page-subtitle mt-3">เปิดหน้านี้หลังเข้าสู่ระบบสำเร็จ แล้วคัดลอก token ไปใช้ใน Postman ได้ทันที</p>

            <div class="mt-8 grid gap-6 lg:grid-cols-2">
                <section class="enterprise-section p-5">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-lg font-semibold text-slate-900">Health Access Token</h2>
                        <span class="enterprise-status <?php echo $healthToken !== '' ? 'enterprise-status-success' : 'enterprise-status-warn'; ?>">
                            <?php echo $healthToken !== '' ? 'Available' : 'Missing'; ?>
                        </span>
                    </div>
                    <div class="mt-3 text-sm text-slate-500">Captured: <?php echo h($healthTime !== '' ? $healthTime : '-'); ?></div>
                    <textarea class="mt-4 min-h-[220px] w-full px-4 py-3 font-mono text-xs leading-6" readonly><?php echo h($healthToken); ?></textarea>
                </section>

                <section class="enterprise-section p-5">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-lg font-semibold text-slate-900">Provider Access Token</h2>
                        <span class="enterprise-status <?php echo $providerToken !== '' ? 'enterprise-status-success' : 'enterprise-status-warn'; ?>">
                            <?php echo $providerToken !== '' ? 'Available' : 'Missing'; ?>
                        </span>
                    </div>
                    <div class="mt-3 text-sm text-slate-500">Captured: <?php echo h($providerTime !== '' ? $providerTime : '-'); ?></div>
                    <textarea class="mt-4 min-h-[220px] w-full px-4 py-3 font-mono text-xs leading-6" readonly><?php echo h($providerToken); ?></textarea>
                </section>
            </div>

            <div class="mt-8 grid gap-6">
                <section class="enterprise-section p-5">
                    <h2 class="text-lg font-semibold text-slate-900">Health Raw</h2>
                    <pre class="mt-4 overflow-auto rounded-3xl bg-slate-950 px-4 py-4 text-xs leading-6 text-slate-100"><?php echo h(pretty_json($healthRaw)); ?></pre>
                </section>

                <section class="enterprise-section p-5">
                    <h2 class="text-lg font-semibold text-slate-900">Health Userinfo Raw</h2>
                    <pre class="mt-4 overflow-auto rounded-3xl bg-slate-950 px-4 py-4 text-xs leading-6 text-slate-100"><?php echo h(pretty_json($healthUserInfo)); ?></pre>
                </section>

                <section class="enterprise-section p-5">
                    <h2 class="text-lg font-semibold text-slate-900">Provider Profile Raw</h2>
                    <pre class="mt-4 overflow-auto rounded-3xl bg-slate-950 px-4 py-4 text-xs leading-6 text-slate-100"><?php echo h(pretty_json($providerProfile)); ?></pre>
                </section>
            </div>

            <div class="mt-8 flex flex-wrap gap-3">
                <a href="index.php" class="enterprise-button enterprise-button-secondary text-sm">กลับหน้าแรก</a>
                <a href="login-oauth-v2.php" class="enterprise-button enterprise-button-primary text-sm">เข้าสู่ระบบใหม่</a>
            </div>
        </section>
    </main>
</body>
</html>

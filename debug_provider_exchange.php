<?php
require_once __DIR__ . '/config/oauth-config-loader.php';
require_once __DIR__ . '/lib/HttpClient.php';
require_once __DIR__ . '/lib/ProviderIdService.php';

ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
session_start();

if (empty($config['debug'])) {
    http_response_code(403);
    echo 'Debug mode is disabled.';
    exit();
}

$health_access_token = isset($_SESSION['debug_health_access_token']) ? (string)$_SESSION['debug_health_access_token'] : '';
$captured_at = isset($_SESSION['debug_health_token_time']) ? (int)$_SESSION['debug_health_token_time'] : 0;

$result = null;
if ($health_access_token !== '') {
    $http_client = new HttpClient(array(
        'timeout' => 30,
        'verify_ssl' => true,
        'mask_tokens' => false,
    ));
    $provider_id_service = new ProviderIdService($config, $http_client);
    $result = $provider_id_service->exchangeHealthIdTokenForProviderToken($health_access_token);
}

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?><!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug Provider Exchange</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2rem; background: #f7f7f7; color: #222; }
        .card { background: #fff; border-radius: 12px; padding: 1.25rem; box-shadow: 0 8px 24px rgba(0,0,0,.08); max-width: 960px; }
        pre { background: #111827; color: #e5e7eb; padding: 1rem; border-radius: 8px; overflow: auto; white-space: pre-wrap; word-break: break-word; }
        .muted { color: #666; }
        .ok { color: #166534; }
        .bad { color: #b91c1c; }
        a { color: #2563eb; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Debug Provider Exchange</h1>
        <p class="muted">ใช้สำหรับทดสอบเรียก Provider token endpoint ตรง ๆ จาก Health ID token ที่เพิ่งได้ใน session</p>

        <p><strong>Environment:</strong> <?php echo h(isset($config['environment']) ? $config['environment'] : 'unknown'); ?></p>
        <p><strong>Provider Base URL:</strong> <?php echo h($config['provider_id']['base_url']); ?></p>
        <p><strong>Token Endpoint:</strong> <?php echo h($config['provider_id']['token_endpoint']); ?></p>
        <p><strong>Captured Token Time:</strong> <?php echo $captured_at ? h(date('Y-m-d H:i:s', $captured_at)) : 'none'; ?></p>

        <?php if ($health_access_token === ''): ?>
            <p class="bad">ยังไม่มี Health ID token ใน session</p>
            <p><a href="login-oauth.php">กลับไปเริ่ม login ใหม่</a></p>
        <?php else: ?>
            <p><strong>Health Token Tail:</strong> <?php echo h(substr($health_access_token, -24)); ?></p>
            <p class="<?php echo !empty($result['success']) ? 'ok' : 'bad'; ?>">
                <strong>Result:</strong> <?php echo !empty($result['success']) ? 'SUCCESS' : 'FAILED'; ?>
            </p>
            <pre><?php echo h(print_r($result, true)); ?></pre>
            <p><a href="login-oauth.php">กลับไปหน้า login</a></p>
        <?php endif; ?>
    </div>
</body>
</html>

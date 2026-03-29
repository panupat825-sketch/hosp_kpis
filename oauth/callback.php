<?php
/**
 * oauth/callback.php
 * 
 * OAuth Callback Handler
 * 
 * Health ID จะ redirect มาที่นี่ พร้อมกับ authorization code + state
 * ที่อยู่: /hosp_kpis/oauth/callback.php
 * 
 * ขั้นตอนที่ประมวลผล:
 * 1. รับ code + state จาก query parameters
 * 2. Validate state (CSRF protection)
 * 3. Exchange code เป็น Health ID token
 * 4. Exchange Health ID token เป็น Provider token
 * 5. Fetch user profile
 * 6. Create/update user ในฐานข้อมูล local
 * 7. Create session
 * 8. Redirect ไป dashboard
 */

require_once __DIR__ . '/../config/oauth-config-loader.php';
require_once __DIR__ . '/../lib/HttpClient.php';
require_once __DIR__ . '/../lib/HealthIdService.php';
require_once __DIR__ . '/../lib/ProviderIdService.php';
require_once __DIR__ . '/../lib/AuthService.php';
require_once __DIR__ . '/../db_connect.php';

// ============================================================
// SESSION SETUP
// ============================================================
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');

$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
            (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
if ($is_https) {
    ini_set('session.cookie_secure', '1');
}

session_start();

// ============================================================
// INITIALIZE SERVICES
// ============================================================
$http_client = new HttpClient(array(
    'timeout' => 30,
    'verify_ssl' => true,
));

$health_id_service = new HealthIdService($config, $http_client);
$provider_id_service = new ProviderIdService($config, $http_client);
$auth_service = new AuthService($conn, $config, $health_id_service, $provider_id_service);

// ============================================================
// EXTRACT CALLBACK PARAMETERS
// ============================================================
$code = isset($_GET['code']) ? trim($_GET['code']) : '';
$state = isset($_GET['state']) ? trim($_GET['state']) : '';
$error = isset($_GET['error']) ? trim($_GET['error']) : '';
$error_description = isset($_GET['error_description']) ? trim($_GET['error_description']) : '';

// ============================================================
// HANDLE OAUTH ERRORS FROM HEALTH ID
// ============================================================
if (!empty($error)) {
    // Health ID returned an error
    error_log('[oauth_callback] Health ID error: ' . $error . ' | ' . $error_description);
    
    $_SESSION['oauth_error'] = array(
        'code' => $error,
        'message' => $error_description ?: 'OAuth error from Health ID',
    );
    
    header('Location: ' . $config['app']['error_path'] . '?type=oauth_error');
    exit();
}

// ============================================================
// VALIDATE PARAMETERS
// ============================================================
if (empty($code) || empty($state)) {
    error_log('[oauth_callback] Missing code or state parameter');
    
    $_SESSION['oauth_error'] = array(
        'code' => 'INVALID_CALLBACK',
        'message' => 'Missing authorization code or state',
    );
    
    header('Location: ' . $config['app']['error_path'] . '?type=invalid_callback');
    exit();
}

// ============================================================
// PROCESS OAUTH FLOW
// ============================================================
$oauth_result = $auth_service->handleOAuthCallback($code, $state);

// ============================================================
// HANDLE RESULTS
// ============================================================
if ($oauth_result['success']) {
    // ✅ LOGIN SUCCESSFUL
    // Redirect ไปที่ dashboard
    header('Location: ' . $config['app']['dashboard_path']);
    exit();
    
} elseif ($oauth_result['error_code'] === 'NO_PROVIDER_ID') {
    // ❌ User ไม่มี Provider ID
    header('Location: ' . $config['app']['access_denied_path'] . '?reason=no_provider_id');
    exit();
    
} elseif ($oauth_result['error_code'] === 'CSRF_FAILED') {
    // ❌ CSRF validation failed
    error_log('[oauth_callback] CSRF validation failed');
    $_SESSION['oauth_error'] = array(
        'code' => 'CSRF_FAILED',
        'message' => 'Session validation failed. Please try login again.',
    );
    header('Location: ' . $config['app']['error_path'] . '?type=csrf_error');
    exit();
    
} elseif ($oauth_result['error_code'] === 'USER_DISABLED') {
    // ❌ User ถูก disable ใน local system
    header('Location: ' . $config['app']['access_denied_path'] . '?reason=user_disabled');
    exit();
    
} else {
    // ❌ Other error
    error_log('[oauth_callback] OAuth error: ' . $oauth_result['error_code'] . ' | ' . $oauth_result['error_message']);
    
    $_SESSION['oauth_error'] = array(
        'code' => $oauth_result['error_code'],
        'message' => $oauth_result['error_message'],
    );
    
    header('Location: ' . $config['app']['error_path'] . '?type=login_failed');
    exit();
}

// Should never reach here
mysqli_close($conn);
?>

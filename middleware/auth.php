<?php
/**
 * middleware/auth.php
 * 
 * Auth Middleware - ตรวจสอบสิทธิ์การเข้าถึงหน้า
 * 
 * วิธีใช้ที่ด้านบนของไฟล์ที่ต้องการป้องกัน:
 * 
 *   require_once __DIR__ . '/config/oauth-config-loader.php';
 *   require_once __DIR__ . '/lib/AuthService.php';
 *   require_once __DIR__ . '/middleware/auth.php';
 *   
 *   $auth = require_apps_auth_middleware($config);
 *   // returns AuthService object, already checked login status
 *   
 *   // Check role
 *   if (!$auth->hasRole('admin')) {
 *       http_response_code(403);
 *       die('Forbidden: insufficient role');
 *   }
 */

function require_apps_auth_middleware(&$config, &$conn = null)
{
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
    
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // ============================================================
    // INITIALIZE DB CONNECTION IF NOT PROVIDED
    // ============================================================
    if (!isset($conn)) {
        require_once __DIR__ . '/../db_connect.php';
    }
    
    // ============================================================
    // INITIALIZE AUTH SERVICE
    // ============================================================
    require_once __DIR__ . '/../lib/HttpClient.php';
    require_once __DIR__ . '/../lib/HealthIdService.php';
    require_once __DIR__ . '/../lib/ProviderIdService.php';
    require_once __DIR__ . '/../lib/AuthService.php';
    
    $http_client = new HttpClient(array(
        'timeout' => 30,
        'verify_ssl' => true,
    ));
    
    $health_id_service = new HealthIdService($config, $http_client);
    $provider_id_service = new ProviderIdService($config, $http_client);
    $auth_service = new AuthService($conn, $config, $health_id_service, $provider_id_service);
    
    // ============================================================
    // CHECK IF LOGGED IN
    // ============================================================
    if (!$auth_service->isLoggedIn()) {
        // ไม่ login - redirect ไป login page
        header('Location: ' . $config['app']['login_path']);
        exit();
    }
    
    // ============================================================
    // CHECK SESSION IDLE TIMEOUT
    // ============================================================
    if (!$auth_service->checkSessionIdleTimeout()) {
        // Session expired
        header('Location: ' . $config['app']['login_path'] . '?expired=1');
        exit();
    }
    
    // ============================================================
    // RETURN AUTH SERVICE OBJECT FOR USE IN PAGE
    // ============================================================
    return $auth_service;
}

/**
 * Helper function - require user to have specific role
 */
function require_apps_role(&$auth_service, $required_roles)
{
    if (!is_array($required_roles)) {
        $required_roles = array($required_roles);
    }
    
    if (!$auth_service->hasRole($required_roles)) {
        http_response_code(403);
        ?><!DOCTYPE html>
        <html lang="th">
        <head>
            <meta charset="UTF-8">
            <title>403 - ไม่อนุญาตให้เข้าถึง</title>
        </head>
        <body>
            <h1>403 - ไม่อนุญาตให้เข้าถึง</h1>
            <p>คุณไม่มีสิทธิ์เข้าถึงหน้านี้</p>
            <p><a href="dashboard.php">กลับไปแดชบอร์ด</a></p>
        </body>
        </html>
        <?php
        exit();
    }
}

/**
 * Helper function - get current logged-in user
 */
function get_apps_current_user(&$auth_service)
{
    return $auth_service->getCurrentUser();
}

/**
 * Helper function - check if user has role
 */
function apps_user_has_role(&$auth_service, $role_names)
{
    return $auth_service->hasRole($role_names);
}

/**
 * Helper function - HTML escape
 */
function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/**
 * Helper function - redirect to logout
 */
function apps_logout($config = null)
{
    if (is_array($config) && isset($config['app']['logout_path'])) {
        header('Location: ' . $config['app']['logout_path']);
        exit();
    }

    header('Location: logout.php');
    exit();
}

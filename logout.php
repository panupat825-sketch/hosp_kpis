<?php
/**
 * logout.php [NEW - OAuth Version]
 * 
 * Logout handler
 * จัดการการออกจากระบบ:
 * - Clear session
 * - Log logout event
 * - Redirect ไป login page
 */

require_once __DIR__ . '/config/oauth-config-loader.php';
require_once __DIR__ . '/lib/AuthService.php';
require_once __DIR__ . '/db_connect.php';

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
// INITIALIZE AUTH SERVICE
// ============================================================
require_once __DIR__ . '/lib/HttpClient.php';
require_once __DIR__ . '/lib/HealthIdService.php';
require_once __DIR__ . '/lib/ProviderIdService.php';

$http_client = new HttpClient(array(
    'timeout' => 30,
    'verify_ssl' => true,
));

$health_id_service = new HealthIdService($config, $http_client);
$provider_id_service = new ProviderIdService($config, $http_client);
$auth_service = new AuthService($conn, $config, $health_id_service, $provider_id_service);

// ============================================================
// PERFORM LOGOUT
// ============================================================
$auth_service->logout('MANUAL');

// ============================================================
// SHOW LOGOUT SUCCESS MESSAGE
// ============================================================
?><!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ออกจากระบบ - KPI System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        // Auto redirect after 3 seconds
        setTimeout(function() {
            window.location.href = <?php echo json_encode($config['app']['login_path']); ?>;
        }, 3000);
    </script>
</head>
<body>
    <div class="min-h-screen bg-gradient-to-br from-green-50 to-blue-50 flex items-center justify-center p-4">
        <div class="max-w-md w-full">
            <!-- Logout Confirmation Card -->
            <div class="bg-white rounded-2xl shadow-2xl overflow-hidden border-l-4 border-green-500">
                <!-- Header -->
                <div class="bg-gradient-to-r from-green-500 to-blue-500 px-6 py-8">
                    <div class="text-6xl text-center mb-3">
                        👋
                    </div>
                    <h1 class="text-2xl font-bold text-white text-center">
                        ออกจากระบบสำเร็จ
                    </h1>
                </div>

                <!-- Content -->
                <div class="px-6 py-8 text-center">
                    <p class="text-gray-700 text-lg mb-4">
                        คุณได้ออกจากระบบแล้ว
                    </p>

                    <p class="text-gray-600 mb-6">
                        ขอบคุณที่ใช้งาน KPI System
                    </p>

                    <!-- Countdown -->
                    <div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                        <p class="text-blue-800 text-sm">
                            ⏳ กำลังกลับไปยังหน้า login ภายใน 3 วินาที...
                        </p>
                    </div>

                    <!-- Manual Action Button -->
                    <div class="flex gap-3">
                        <a href="login-oauth.php" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-lg text-center transition">
                            🔄 ไปหน้า Login
                        </a>
                        <a href="https://healthcare.th" class="flex-1 bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-3 px-4 rounded-lg text-center transition">
                            🏠 หน้าแรก
                        </a>
                    </div>
                </div>

                <!-- Footer -->
                <div class="px-6 py-4 bg-gray-50 border-t text-center text-xs text-gray-600">
                    หากไม่ได้กลับไปที่หน้า login โปรด <a href="login-oauth.php" class="text-blue-600 hover:underline">คลิกที่นี่</a>
                </div>
            </div>

            <!-- Footer Info -->
            <div class="mt-6 text-center text-gray-600 text-xs">
                <p>KPI Management System v2.0</p>
                <p class="mt-1">© 2026 Healthcare Organization</p>
            </div>
        </div>
    </div>
</body>
</html>
<?php
mysqli_close($conn);
?>

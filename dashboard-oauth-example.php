<?php
/**
 * dashboard-oauth-example.php
 * 
 * ตัวอย่างหน้า Dashboard ที่ใช้งาน OAuth Auth ใหม่
 * 
 * นี่คือ template สำหรับอัพเดต dashboard.php และหน้าอื่น ๆ ให้ใช้ OAuth auth
 * 
 * วิธีการใช้:
 * 1. ที่ด้านบนของไฟล์ หลัง <?php เพิ่มวรรค้ OAuth auth initialization
 * 2. ในไฟล์ เขียนเพศเพื่อใช้ $auth service
 * 3. Update session references จาก $_SESSION['user_id'] -> $_SESSION['app_user_id']
 * 4. Update user info references => $current_user = $auth->getCurrentUser()
 */

// ============================================================
// SECTION 1: REQUIRED INCLUDES (อยู่ด้านบนสุดของไฟล์)
// ============================================================

require_once __DIR__ . '/config/oauth-config-loader.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/middleware/auth.php';

// ============================================================
// SECTION 2: AUTH MIDDLEWARE (ตรวจสอบ login)
// ============================================================

$auth = require_apps_auth_middleware($config, $conn);

// ============================================================
// SECTION 3: GET CURRENT USER DATA
// ============================================================

$current_user = get_apps_current_user($auth);

// ============================================================
// (OPTIONAL) REQUIRE SPECIFIC ROLE
// ============================================================
// require_apps_role($auth, 'user'); // or array('admin', 'manager')

// ============================================================
// SECTION 4: GET YOUR EXISTING DATA (db queries, etc.)
// ============================================================

// ตัวอย่าง: โหลด master data
$fiscal_years_list = array();
if ($rs = mysqli_query($conn, "SELECT DISTINCT fiscal_year FROM tb_kpi_instances ORDER BY fiscal_year DESC")) {
    while ($r = mysqli_fetch_assoc($rs)) {
        $fiscal_years_list[$r['fiscal_year']] = $r['fiscal_year'];
    }
    mysqli_free_result($rs);
}

// ============================================================
// ส่วนที่เหลือของ dashboard logic จะเหมือนเดิม
// ============================================================

?><!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - KPI System</title>
    <link rel="stylesheet" href="css/style.css">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
    <!-- Navbar -->
    <nav class="bg-white shadow-md border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <!-- Logo -->
                <div class="flex items-center space-x-2">
                    <svg class="w-8 h-8 text-blue-600" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M2 11a1 1 0 011-1h2a1 1 0 011 1v5a1 1 0 01-1 1H3a1 1 0 01-1-1v-5zM8 7a1 1 0 011-1h2a1 1 0 011 1v9a1 1 0 01-1 1H9a1 1 0 01-1-1V7zM14 4a1 1 0 011-1h2a1 1 0 011 1v12a1 1 0 01-1 1h-2a1 1 0 01-1-1V4z" />
                    </svg>
                    <span class="text-xl font-bold text-gray-900">KPI System</span>
                </div>

                <!-- Navigation Links -->
                <div class="hidden md:flex items-center space-x-6">
                    <a href="dashboard.php" class="text-gray-700 hover:text-blue-600 font-medium">Dashboard</a>
                    <a href="kpi_manage.php" class="text-gray-700 hover:text-blue-600 font-medium">KPI</a>
                    
                    <!-- Admin-only links -->
                    <?php if (apps_user_has_role($auth, array('admin', 'manager'))): ?>
                        <a href="users.php" class="text-gray-700 hover:text-blue-600 font-medium">Users</a>
                        <a href="roles.php" class="text-gray-700 hover:text-blue-600 font-medium">Roles</a>
                    <?php endif; ?>
                </div>

                <!-- User Menu -->
                <div class="flex items-center space-x-4">
                    <div class="text-right">
                        <p class="text-sm font-semibold text-gray-900">
                            <?php echo h($current_user['name_th'] ?: $current_user['name_eng']); ?>
                        </p>
                        <p class="text-xs text-gray-600">
                            <?php echo h($current_user['position_name']); ?>
                        </p>
                    </div>
                    <button class="relative group">
                        <div class="w-10 h-10 bg-gradient-to-br from-blue-400 to-purple-500 rounded-full flex items-center justify-center text-white font-bold">
                            <?php 
                            $name = $current_user['name_th'] ?: $current_user['name_eng'];
                            echo substr($name, 0, 1);
                            ?>
                        </div>
                        <div class="absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-xl hidden group-hover:block py-2 z-50">
                            <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Profile</a>
                            <a href="settings.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Settings</a>
                            <hr class="my-2">
                            <a href="logout.php" class="block px-4 py-2 text-sm text-red-600 hover:bg-red-50">
                                🚪 Logout
                            </a>
                        </div>
                    </button>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Welcome Section -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900 mb-2">
                ยินดีต้อนรับ, <?php echo h($current_user['name_th'] ?: $current_user['name_eng']); ?>!
            </h1>
            <p class="text-gray-600">
                <strong>Provider ID:</strong> <?php echo h($current_user['provider_id']); ?><br>
                <strong>Organization:</strong> <?php echo h($current_user['hname_th']); ?><br>
                <strong>Roles:</strong> <?php echo h(implode(', ', $current_user['roles'])); ?>
            </p>
        </div>

        <!-- KPI Cards Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <!-- Card 1 -->
            <div class="bg-white rounded-lg shadow-md p-6 border-l-4 border-blue-500">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">📊 KPI Count</h3>
                <p class="text-4xl font-bold text-blue-600">148</p>
                <p class="text-sm text-gray-600 mt-2">Active KPI Templates</p>
            </div>

            <!-- Card 2 -->
            <div class="bg-white rounded-lg shadow-md p-6 border-l-4 border-green-500">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">📈 Performance</h3>
                <p class="text-4xl font-bold text-green-600">94.2%</p>
                <p class="text-sm text-gray-600 mt-2">Target Achievement</p>
            </div>

            <!-- Card 3 -->
            <div class="bg-white rounded-lg shadow-md p-6 border-l-4 border-purple-500">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">👥 Users</h3>
                <p class="text-4xl font-bold text-purple-600">342</p>
                <p class="text-sm text-gray-600 mt-2">Total System Users</p>
            </div>
        </div>

        <!-- Recent Activity Section -->
        <div class="mt-8 bg-white rounded-lg shadow-md p-6">
            <h2 class="text-2xl font-bold text-gray-900 mb-4">📋 Recent Activity</h2>
            <p class="text-gray-600">
                กิจกรรมล่าสุดจะแสดงที่นี่ (Last Login: <?php echo $current_user['login_time'] ? date('Y-m-d H:i:s', $current_user['login_time']) : 'N/A'; ?>)
            </p>
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-white border-t border-gray-200 mt-8 py-6">
        <div class="max-w-7xl mx-auto text-center text-gray-600 text-sm">
            <p>KPI Management System v2.0 (OAuth) | Powered by Health ID + Provider ID</p>
            <p>© 2026 Healthcare Organization</p>
        </div>
    </footer>

    <script>
        // Session timeout warning (optional)
        const loginTime = <?php echo $current_user['login_time']; ?>;
        const idleTimeout = <?php echo isset($config['session']['idle_timeout']) ? $config['session']['idle_timeout'] : 1800; ?> * 1000;
        
        let lastActivityTime = Date.now();
        
        // Reset timer on user activity
        document.addEventListener('mousemove', () => {
            lastActivityTime = Date.now();
        });
        
        document.addEventListener('keypress', () => {
            lastActivityTime = Date.now();
        });
        
        // Check every minute if session is about to expire
        setInterval(() => {
            const elapsedTime = Date.now() - lastActivityTime;
            if (elapsedTime > (idleTimeout - 300000)) { // 5 minutes before actual timeout
                console.warn('⚠️ Your session will expire soon due to inactivity. Please click to continue using the system.');
            }
        }, 60000);
    </script>
</body>
</html>

<?php
mysqli_close($conn);
?>

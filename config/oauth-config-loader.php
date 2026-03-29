<?php
/**
 * config/oauth-config-loader.php
 *
 * Selects OAuth config for the active environment and normalizes
 * application URLs so the app can run under different domains/paths.
 */

if (!function_exists('oauth_detect_request_scheme')) {
    function oauth_detect_request_scheme()
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $proto = strtolower(trim((string)$_SERVER['HTTP_X_FORWARDED_PROTO']));
            if ($proto === 'https') {
                return 'https';
            }
        }

        if (!empty($_SERVER['REQUEST_SCHEME'])) {
            return strtolower((string)$_SERVER['REQUEST_SCHEME']) === 'https' ? 'https' : 'http';
        }

        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return 'https';
        }

        if (!empty($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443') {
            return 'https';
        }

        return 'http';
    }
}

if (!function_exists('oauth_detect_host')) {
    function oauth_detect_host()
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
            return trim((string)$_SERVER['HTTP_X_FORWARDED_HOST']);
        }

        if (!empty($_SERVER['HTTP_HOST'])) {
            return trim((string)$_SERVER['HTTP_HOST']);
        }

        if (!empty($_SERVER['SERVER_NAME'])) {
            $host = trim((string)$_SERVER['SERVER_NAME']);
            $port = isset($_SERVER['SERVER_PORT']) ? trim((string)$_SERVER['SERVER_PORT']) : '';
            if ($port !== '' && $port !== '80' && $port !== '443' && strpos($host, ':') === false) {
                $host .= ':' . $port;
            }
            return $host;
        }

        return 'localhost';
    }
}

if (!function_exists('oauth_detect_app_base_path')) {
    function oauth_detect_app_base_path()
    {
        if (!empty($_SERVER['SCRIPT_NAME'])) {
            $script_dir = str_replace('\\', '/', dirname((string)$_SERVER['SCRIPT_NAME']));
            $script_dir = rtrim($script_dir, '/');

            if (substr($script_dir, -6) === '/oauth') {
                $script_dir = substr($script_dir, 0, -6);
            } elseif (substr($script_dir, -11) === '/middleware') {
                $script_dir = substr($script_dir, 0, -11);
            } elseif (substr($script_dir, -7) === '/config') {
                $script_dir = substr($script_dir, 0, -7);
            }

            if ($script_dir === '/' || $script_dir === '\\' || $script_dir === '.') {
                return '';
            }
            return rtrim($script_dir, '/');
        }

        return '';
    }
}

if (!function_exists('oauth_join_base_url')) {
    function oauth_join_base_url($base_url, $path)
    {
        $base_url = rtrim((string)$base_url, '/');
        $path = ltrim((string)$path, '/');
        return $path === '' ? $base_url : $base_url . '/' . $path;
    }
}

if (!function_exists('oauth_merge_config_recursive')) {
    function oauth_merge_config_recursive($base, $override)
    {
        if (!is_array($base)) {
            $base = array();
        }
        if (!is_array($override)) {
            return $base;
        }

        foreach ($override as $key => $value) {
            if (isset($base[$key]) && is_array($base[$key]) && is_array($value)) {
                $base[$key] = oauth_merge_config_recursive($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}

$environment = 'prd';

if (getenv('HOSP_KPI_ENV')) {
    $env = strtolower(trim((string)getenv('HOSP_KPI_ENV')));
    if (in_array($env, array('uat', 'prd', 'production'), true)) {
        $environment = ($env === 'production') ? 'prd' : $env;
    }
}

if (isset($_GET['__env']) && strtolower((string)$_GET['__env']) === 'prd') {
    $environment = 'prd';
}

if (isset($_SERVER['HTTP_HOST'])) {
    $host = strtolower((string)$_SERVER['HTTP_HOST']);
    if (strpos($host, 'uat') !== false) {
        $environment = 'uat';
    } elseif (strpos($host, 'prod') !== false || strpos($host, '.com') !== false) {
        $environment = 'prd';
    }
}

$config_file = __DIR__ . '/oauth-' . $environment . '.php';
if (!is_file($config_file)) {
    error_log('[oauth_config] Config file not found: ' . $config_file);
    die('Configuration error: oauth config file not found');
}

$config = require $config_file;

$app_base_url = getenv('APP_URL');
if (!$app_base_url) {
    $app_base_url = oauth_detect_request_scheme() . '://' . oauth_detect_host();
}
$app_base_url = rtrim((string)$app_base_url, '/');

$app_base_path = getenv('APP_BASE_PATH');
if ($app_base_path === false || $app_base_path === '') {
    $app_base_path = oauth_detect_app_base_path();
}
$app_base_path = trim(str_replace('\\', '/', (string)$app_base_path));
if ($app_base_path === '/' || $app_base_path === '.') {
    $app_base_path = '';
}
if ($app_base_path !== '') {
    $app_base_path = '/' . trim($app_base_path, '/');
}

$config['app'] = array(
    'base_url' => $app_base_url,
    'base_path' => $app_base_path,
    'callback_path' => ($app_base_path === '' ? '' : $app_base_path) . '/oauth/callback.php',
    'login_path' => ($app_base_path === '' ? '' : $app_base_path) . '/login-oauth.php',
    'logout_path' => ($app_base_path === '' ? '' : $app_base_path) . '/logout.php',
    'dashboard_path' => ($app_base_path === '' ? '' : $app_base_path) . '/dashboard.php',
    'error_path' => ($app_base_path === '' ? '' : $app_base_path) . '/error.php',
    'access_denied_path' => ($app_base_path === '' ? '' : $app_base_path) . '/access_denied.php',
);

if (empty($config['health_id']['redirect_uri'])) {
    $config['health_id']['redirect_uri'] = oauth_join_base_url(
        $app_base_url,
        ltrim($config['app']['callback_path'], '/')
    );
}

$local_override_file = __DIR__ . '/oauth.local.php';
if (is_file($local_override_file)) {
    $local_override = require $local_override_file;
    if (is_array($local_override)) {
        if (isset($local_override[$environment]) && is_array($local_override[$environment])) {
            $local_override = oauth_merge_config_recursive($local_override, $local_override[$environment]);
        }
        unset($local_override['uat'], $local_override['prd']);
        $config = oauth_merge_config_recursive($config, $local_override);
    }
}

if (empty($config['app']['base_url'])) {
    $config['app']['base_url'] = $app_base_url;
}
$config['app']['base_url'] = rtrim((string)$config['app']['base_url'], '/');

if (!isset($config['app']['base_path'])) {
    $config['app']['base_path'] = $app_base_path;
}
$config['app']['base_path'] = trim(str_replace('\\', '/', (string)$config['app']['base_path']));
if ($config['app']['base_path'] === '/' || $config['app']['base_path'] === '.') {
    $config['app']['base_path'] = '';
}
if ($config['app']['base_path'] !== '') {
    $config['app']['base_path'] = '/' . trim($config['app']['base_path'], '/');
}

$config['app']['callback_path'] = $config['app']['base_path'] . '/oauth/callback.php';
$config['app']['login_path'] = $config['app']['base_path'] . '/login-oauth.php';
$config['app']['logout_path'] = $config['app']['base_path'] . '/logout.php';
$config['app']['dashboard_path'] = $config['app']['base_path'] . '/dashboard.php';
$config['app']['error_path'] = $config['app']['base_path'] . '/error.php';
$config['app']['access_denied_path'] = $config['app']['base_path'] . '/access_denied.php';

if (empty($config['health_id']['redirect_uri'])) {
    $config['health_id']['redirect_uri'] = oauth_join_base_url(
        $config['app']['base_url'],
        ltrim($config['app']['callback_path'], '/')
    );
}

$required_fields = array(
    'health_id.base_url',
    'health_id.client_id',
    'health_id.client_secret',
    'health_id.redirect_uri',
    'provider_id.base_url',
    'provider_id.client_id',
    'provider_id.secret_key',
);

foreach ($required_fields as $field) {
    list($section, $key) = explode('.', $field);
    if (empty($config[$section][$key])) {
        continue;
    }

    $value = (string)$config[$section][$key];
    if (strpos($value, 'YOUR_') === 0 || strpos($value, '_HERE') !== false) {
        $msg = '[oauth_config] WARN: ' . $field . ' is still using a placeholder in ' . $config_file;
        error_log($msg);
        if (!empty($config['debug'])) {
            echo '<div style="color:red;padding:1rem;background:#ffe0e0">' . htmlspecialchars($msg) . '</div>';
        }
    }
}

if (!empty($config['logging']['enabled']) && !empty($config['logging']['dir'])) {
    $log_dir = $config['logging']['dir'];
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }
}

return $config;

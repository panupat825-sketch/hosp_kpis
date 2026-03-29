<?php
require_once __DIR__ . '/../config/oauth-config-loader.php';
require_once __DIR__ . '/../lib/HttpClient.php';
require_once __DIR__ . '/../lib/OAuthGatewayV2.php';
require_once __DIR__ . '/../lib/OAuthUserRepositoryV2.php';
require_once __DIR__ . '/../db_connect.php';

ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
session_start();

function v2_redirect($path)
{
    header('Location: ' . $path);
    exit();
}

function v2_fail($code, $message)
{
    $_SESSION['oauth_error'] = array(
        'code' => $code,
        'message' => $message,
    );
}

function v2_set_legacy_session($sessionUser, $role)
{
    $_SESSION['user_id'] = $sessionUser['app_user_id'];
    $_SESSION['fullname'] = $sessionUser['name_th'] !== '' ? $sessionUser['name_th'] : $sessionUser['name_eng'];
    $_SESSION['username'] = $sessionUser['provider_id'];
    $_SESSION['role'] = $role;
    $_SESSION['position'] = isset($sessionUser['position_name']) ? $sessionUser['position_name'] : '';
    $_SESSION['department'] = isset($sessionUser['hcode']) ? $sessionUser['hcode'] : '';
}

function v2_index_path($config)
{
    return $config['app']['base_path'] . '/index.php';
}

function v2_build_health_fallback_name($health)
{
    $healthAccountId = isset($health['account_id']) ? (string)$health['account_id'] : '';
    $fallbackName = 'Health ID User ' . $healthAccountId;

    if (!empty($health['raw']['data']) && is_array($health['raw']['data'])) {
        $healthData = $health['raw']['data'];
        if (!empty($healthData['name'])) {
            return (string)$healthData['name'];
        }
        if (!empty($healthData['full_name'])) {
            return (string)$healthData['full_name'];
        }
        if (!empty($healthData['display_name'])) {
            return (string)$healthData['display_name'];
        }
    }

    return $fallbackName;
}

function v2_resolve_health_identity($health, $healthUserInfo = null)
{
    $healthAccountId = isset($health['account_id']) ? (string)$health['account_id'] : '';

    if ($healthAccountId === '' && is_array($healthUserInfo) && !empty($healthUserInfo['data']) && is_array($healthUserInfo['data'])) {
        $data = $healthUserInfo['data'];
        $identityKeys = array('account_id', 'sub', 'pid', 'cid', 'id', 'username');
        foreach ($identityKeys as $key) {
            if (!empty($data[$key])) {
                $healthAccountId = (string)$data[$key];
                break;
            }
        }
    }

    return $healthAccountId;
}

function v2_login_with_health_fallback($users, $health, $message, $config, $healthUserInfo = null)
{
    $healthAccountId = v2_resolve_health_identity($health, $healthUserInfo);
    if ($healthAccountId === '') {
        v2_fail('V2_HEALTH_ACCOUNT_ID_MISSING', $message);
        v2_redirect($config['app']['error_path'] . '?type=login_failed');
    }

    $fallbackName = v2_build_health_fallback_name($health);
    if ($fallbackName === 'Health ID User ' . $healthAccountId && is_array($healthUserInfo) && !empty($healthUserInfo['data']) && is_array($healthUserInfo['data'])) {
        $data = $healthUserInfo['data'];
        if (!empty($data['name'])) {
            $fallbackName = (string)$data['name'];
        } elseif (!empty($data['full_name'])) {
            $fallbackName = (string)$data['full_name'];
        } elseif (!empty($data['display_name'])) {
            $fallbackName = (string)$data['display_name'];
        }
    }

    $save = $users->saveHealthOnlyUser($healthAccountId, $fallbackName);
    if (!$save['success']) {
        v2_fail('V2_HEALTH_ONLY_SAVE_FAILED', $save['error']);
        v2_redirect($config['app']['error_path'] . '?type=login_failed');
    }

    $sessionUser = $users->fetchSessionUser($save['app_user_id']);
    if (!$sessionUser) {
        v2_fail('V2_HEALTH_ONLY_SESSION_FAILED', 'Unable to load fallback user session');
        v2_redirect($config['app']['error_path'] . '?type=login_failed');
    }

    session_regenerate_id(true);
    $_SESSION['app_user_id'] = $sessionUser['app_user_id'];
    $_SESSION['provider_id'] = $sessionUser['provider_id'];
    $_SESSION['name_th'] = $sessionUser['name_th'];
    $_SESSION['name_eng'] = $sessionUser['name_eng'];
    $_SESSION['position_name'] = 'Health ID Only';
    $_SESSION['hcode'] = '';
    $_SESSION['hname_th'] = '';
    $_SESSION['roles'] = array('user');
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['health_only_login'] = 1;
    $_SESSION['health_only_reason'] = $message;
    $_SESSION['oauth_v2_health'] = isset($health['raw']) ? $health['raw'] : array();
    v2_set_legacy_session($sessionUser, 'user');

    v2_redirect(v2_index_path($config));
}

$state = isset($_GET['state']) ? trim((string)$_GET['state']) : '';
$code = isset($_GET['code']) ? trim((string)$_GET['code']) : '';

if ($state === '' || $code === '') {
    v2_fail('V2_INVALID_CALLBACK', 'Missing code or state');
    v2_redirect($config['app']['error_path'] . '?type=invalid_callback');
}

if (empty($_SESSION['oauth_v2_state']) || !hash_equals((string)$_SESSION['oauth_v2_state'], $state)) {
    v2_fail('V2_CSRF_FAILED', 'State mismatch');
    v2_redirect($config['app']['error_path'] . '?type=csrf_error');
}

if (!empty($_SESSION['oauth_v2_state_time']) && (time() - (int)$_SESSION['oauth_v2_state_time']) > 600) {
    v2_fail('V2_STATE_EXPIRED', 'State expired');
    v2_redirect($config['app']['error_path'] . '?type=csrf_error');
}

unset($_SESSION['oauth_v2_state'], $_SESSION['oauth_v2_state_time']);

$http = new HttpClient(array(
    'timeout' => 30,
    'verify_ssl' => true,
));
$v2RedirectUri = rtrim($config['app']['base_url'], '/') . $config['app']['base_path'] . '/oauth/callback-v2.php';
$gateway = new OAuthGatewayV2($config, $http, $v2RedirectUri);
$users = new OAuthUserRepositoryV2($conn);

$health = $gateway->exchangeHealthCode($code);
if (!$health['success']) {
    v2_fail($health['error_code'], $health['error_message']);
    v2_redirect($config['app']['error_path'] . '?type=login_failed');
}

$_SESSION['debug_health_access_token'] = $health['access_token'];
$_SESSION['debug_health_token_time'] = time();
$healthUserInfo = $gateway->fetchHealthUserInfo($health['access_token']);
if ($healthUserInfo['success']) {
    $_SESSION['oauth_v2_health_userinfo'] = $healthUserInfo['raw'];
}

$providerToken = $gateway->exchangeProviderToken($health['access_token']);
if (!$providerToken['success']) {
    v2_login_with_health_fallback($users, $health, $providerToken['error_message'], $config, $healthUserInfo['success'] ? $healthUserInfo : null);
}

$_SESSION['debug_provider_access_token'] = $providerToken['access_token'];
$_SESSION['debug_provider_token_time'] = time();

$profile = $gateway->fetchProviderProfile($providerToken['access_token']);
if (!$profile['success']) {
    v2_login_with_health_fallback($users, $health, $profile['error_message'], $config, $healthUserInfo['success'] ? $healthUserInfo : null);
}

$save = $users->saveFromProviderProfile($profile['provider_id'], $profile['profile']);
if (!$save['success']) {
    v2_login_with_health_fallback($users, $health, $save['error'], $config, $healthUserInfo['success'] ? $healthUserInfo : null);
}

$sessionUser = $users->fetchSessionUser($save['app_user_id']);
if (!$sessionUser) {
    v2_login_with_health_fallback($users, $health, 'User session record not found after save', $config, $healthUserInfo['success'] ? $healthUserInfo : null);
}

session_regenerate_id(true);
$_SESSION['app_user_id'] = $sessionUser['app_user_id'];
$_SESSION['provider_id'] = $sessionUser['provider_id'];
$_SESSION['name_th'] = $sessionUser['name_th'];
$_SESSION['name_eng'] = $sessionUser['name_eng'];
$_SESSION['position_name'] = $sessionUser['position_name'];
$_SESSION['hcode'] = $sessionUser['hcode'];
$_SESSION['hname_th'] = $sessionUser['hname_th'];
$_SESSION['roles'] = array('user');
$_SESSION['login_time'] = time();
$_SESSION['last_activity'] = time();
$_SESSION['oauth_v2_last_profile'] = $profile['raw'];
v2_set_legacy_session($sessionUser, 'user');

v2_redirect(v2_index_path($config));

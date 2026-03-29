<?php
// auth.php — include หลัง session_start();
function app_is_https(){
  if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
  if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) return true;
  return false;
}
function apply_session_cookie_settings(){
  ini_set('session.use_only_cookies', '1');
  ini_set('session.cookie_httponly', '1');
  if (app_is_https()) {
    ini_set('session.cookie_secure', '1');
  }
}
function refresh_session_cookie_flags(){
  if (session_id() === '') return;
  if (headers_sent()) return;
  setcookie(session_name(), session_id(), 0, '/', '', app_is_https(), true);
}
function is_logged_in(){
  return !empty($_SESSION['user_id']);
}
function require_login(){
  if (!is_logged_in()){
    header('Location: login.php'); exit();
  }
  refresh_session_cookie_flags();
}
function current_user(){
  return array(
    'id'         => isset($_SESSION['user_id'])? (int)$_SESSION['user_id'] : 0,
    'username'   => isset($_SESSION['username'])? $_SESSION['username'] : '',
    'fullname'   => isset($_SESSION['fullname'])? $_SESSION['fullname'] : '',
    'role'       => normalize_role_name(isset($_SESSION['role'])? $_SESSION['role'] : 'staff'),
    'position'   => isset($_SESSION['position'])? $_SESSION['position'] : '',
    'department' => isset($_SESSION['department'])? $_SESSION['department'] : '',
    'division'   => isset($_SESSION['division'])? $_SESSION['division'] : '',
  );
}
function normalize_role_name($role){
  $role = strtolower(trim((string)$role));
  if ($role === 'editor') return 'manager';
  if ($role === 'viewer') return 'staff';
  if ($role === 'guest' || $role === '') return 'staff';
  if (in_array($role, array('admin', 'manager', 'staff'), true)) {
    return $role;
  }
  return 'staff';
}
function has_role($roles){
  if (!is_array($roles)) $roles = array($roles);
  $normalized_roles = array();
  foreach ($roles as $role) {
    $normalized_roles[] = normalize_role_name($role);
  }
  $r = normalize_role_name(isset($_SESSION['role'])? $_SESSION['role'] : 'staff');
  return in_array($r, $normalized_roles, true);
}
function require_role($roles){
  if (!has_role($roles)){
    http_response_code(403);
    echo '<div style="padding:2rem;font-family:sans-serif">Forbidden: insufficient role</div>';
    exit();
  }
}
function csrf_token(){
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(16));
  }
  return $_SESSION['csrf_token'];
}
function require_post_csrf(){
  $expected = csrf_token();
  $actual = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
  if (!hash_equals($expected, $actual)) {
    http_response_code(400);
    echo '<div style="padding:2rem;font-family:sans-serif">Bad Request: invalid CSRF token</div>';
    exit();
  }
}
function db_bind_params($stmt, $types, &$params){
  if ($types === '') return true;
  $bind = array($stmt, $types);
  foreach ($params as $k => $v) {
    $bind[] = &$params[$k];
  }
  return call_user_func_array('mysqli_stmt_bind_param', $bind);
}
function perf_logging_enabled(){
  $flag = getenv('HOSP_KPI_LOG_SLOW_QUERIES');
  return ($flag === '1' || $flag === 'true' || $flag === 'on');
}
function perf_now(){
  return microtime(true);
}
function perf_log_if_slow($label, $started_at, $context){
  if (!perf_logging_enabled()) return;
  $elapsed_ms = (microtime(true) - (float)$started_at) * 1000;
  if ($elapsed_ms < 500) return;
  $context['elapsed_ms'] = round($elapsed_ms, 2);
  error_log('[hosp_kpis][slow-query] ' . $label . ' | ' . json_encode($context));
}

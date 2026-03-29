<?php
$default_config = array(
    'host' => '127.0.0.1',
    'port' => 3306,
    'database' => 'hospital_kpi',
    'username' => '',
    'password' => '',
    'charset' => 'utf8'
);

$local_config_file = __DIR__ . '/config/database.local.php';
if (is_file($local_config_file)) {
    $loaded = include $local_config_file;
    if (is_array($loaded)) {
        $default_config = array_merge($default_config, $loaded);
    }
}

$host = isset($default_config['host']) ? (string)$default_config['host'] : '127.0.0.1';
$port = isset($default_config['port']) ? (int)$default_config['port'] : 3306;
$database = isset($default_config['database']) ? (string)$default_config['database'] : 'hospital_kpi';
$username = isset($default_config['username']) ? (string)$default_config['username'] : '';
$password = isset($default_config['password']) ? (string)$default_config['password'] : '';
$charset = isset($default_config['charset']) ? (string)$default_config['charset'] : 'utf8';

$conn = @mysqli_init();
if (!$conn) {
    die('Database connection failed.');
}

if (!@mysqli_real_connect($conn, $host, $username, $password, $database, $port)) {
    error_log('[hosp_kpis] DB connect failed | ' . mysqli_connect_error());
    die('Database connection failed. Please complete installation or check local database configuration.');
}

if ($charset === '') {
    $charset = 'utf8';
}
@mysqli_set_charset($conn, $charset);

?>

<?php
require_once __DIR__ . '/config/oauth-config-loader.php';
header('Location: ' . $config['app']['base_path'] . '/login-oauth-v2.php');
exit();

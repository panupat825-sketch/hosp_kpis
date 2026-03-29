<?php
@set_time_limit(0);
session_start();

$lock_file = __DIR__ . '/install.lock';
$config_file = dirname(__DIR__) . '/config/database.local.php';
$db_dir = dirname(__DIR__) . '/db';
$fallback_dump = $db_dir . '/hospital_kpi.sql';
$schema_file = $db_dir . '/schema.sql';
$seed_file = $db_dir . '/seed.sql';
$data_file = $db_dir . '/data.sql';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function installer_csrf_token() {
    if (empty($_SESSION['installer_csrf'])) {
        $_SESSION['installer_csrf'] = bin2hex(openssl_random_pseudo_bytes(16));
    }
    return $_SESSION['installer_csrf'];
}

function installer_require_csrf() {
    $expected = installer_csrf_token();
    $actual = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    return hash_equals($expected, $actual);
}

function installer_state() {
    if (empty($_SESSION['hosp_kpis_installer']) || !is_array($_SESSION['hosp_kpis_installer'])) {
        $_SESSION['hosp_kpis_installer'] = array(
            'db' => array(
                'host' => '127.0.0.1',
                'port' => 3306,
                'name' => 'hospital_kpi',
                'user' => '',
                'pass' => '',
                'charset' => 'utf8',
                'create_db' => 0
            ),
            'mode' => 'schema_seed',
            'admin_action' => 'ensure_admin',
            'admin_username' => 'admin',
            'admin_password' => '',
            'admin_fullname' => 'System Administrator'
        );
    }
    return $_SESSION['hosp_kpis_installer'];
}

function installer_save_state($state) {
    $_SESSION['hosp_kpis_installer'] = $state;
}

function file_has_sql_statements($path) {
    if (!is_file($path)) {
        return false;
    }
    $fh = fopen($path, 'rb');
    if (!$fh) {
        return false;
    }
    while (($line = fgets($fh)) !== false) {
        $trim = trim($line);
        if ($trim === '') continue;
        if (strpos($trim, '--') === 0) continue;
        if (strpos($trim, '#') === 0) continue;
        if (strpos($trim, '/*') === 0) continue;
        fclose($fh);
        return true;
    }
    fclose($fh);
    return false;
}

function strip_definer_clause($sql) {
    $sql = preg_replace('/\/\*![0-9]{5}\s+DEFINER=`[^`]+`@`[^`]+`\s*\*\//i', '', $sql);
    $sql = preg_replace('/\s+DEFINER=`[^`]+`@`[^`]+`/i', '', $sql);
    return $sql;
}

function installer_normalize_legacy_sql($sql) {
    /*
     * Legacy dumps in this project reference `users` in one FK constraint,
     * but the runtime schema uses `tb_users`. Rewrite that reference only
     * during import so existing dumps remain installable.
     */
    $sql = preg_replace('/(\bREFERENCES\s+)`users`(\s*\()/i', '$1`tb_users`$2', $sql);
    $sql = preg_replace('/(\bREFERENCES\s+)users(\s*\()/i', '$1tb_users$2', $sql);
    return $sql;
}

function strip_sql_comments_line($line, &$in_block_comment) {
    $line = str_replace("\r", '', $line);
    $result = '';
    $len = strlen($line);
    $in_single = false;
    $in_double = false;

    for ($i = 0; $i < $len; $i++) {
        $ch = $line[$i];
        $next = ($i + 1 < $len) ? $line[$i + 1] : '';

        if ($in_block_comment) {
            if ($ch === '*' && $next === '/') {
                $in_block_comment = false;
                $i++;
            }
            continue;
        }

        if (!$in_single && !$in_double) {
            if ($ch === '/' && $next === '*') {
                $in_block_comment = true;
                $i++;
                continue;
            }
            if ($ch === '#') {
                break;
            }
            if ($ch === '-' && $next === '-') {
                $prev = $result === '' ? ' ' : substr($result, -1);
                $after = ($i + 2 < $len) ? $line[$i + 2] : ' ';
                if (preg_match('/\s/', $prev) && ($after === '' || preg_match('/\s/', $after))) {
                    break;
                }
            }
            if ($ch === "'") {
                $in_single = true;
                $result .= $ch;
                continue;
            }
            if ($ch === '"') {
                $in_double = true;
                $result .= $ch;
                continue;
            }
        } else {
            if ($in_single && $ch === "'" && ($i === 0 || $line[$i - 1] !== '\\')) {
                if ($next === "'") {
                    $result .= $ch . $next;
                    $i++;
                    continue;
                }
                $in_single = false;
            } elseif ($in_double && $ch === '"' && ($i === 0 || $line[$i - 1] !== '\\')) {
                $in_double = false;
            }
        }

        $result .= $ch;
    }

    return $result;
}

function statement_has_delimiter($sql, $delimiter) {
    $trimmed = rtrim($sql);
    if ($trimmed === '' || $delimiter === '') return false;
    return substr($trimmed, -strlen($delimiter)) === $delimiter;
}

function remove_statement_delimiter($sql, $delimiter) {
    $trimmed = rtrim($sql);
    if ($delimiter !== '' && substr($trimmed, -strlen($delimiter)) === $delimiter) {
        $trimmed = substr($trimmed, 0, -strlen($delimiter));
    }
    return trim($trimmed);
}

function should_execute_statement($sql, $mode) {
    $trim = ltrim($sql);
    if ($trim === '') return false;
    if (preg_match('/^(CREATE\s+DATABASE|USE)\b/i', $trim)) return false;
    if ($mode === 'schema_only' && preg_match('/^(INSERT|REPLACE|UPDATE|DELETE|LOCK\s+TABLES|UNLOCK\s+TABLES)\b/i', $trim)) {
        return false;
    }
    return true;
}

function installer_detect_fy_summary() {
    $year_ce = (int)date('Y');
    if ((int)date('n') >= 10) {
        $year_ce++;
    }
    return 'Fiscal year rolls over on October 1 and stores Buddhist Era (BE) values. Current FY: ' . ($year_ce + 543);
}

function installer_connect($cfg, $with_database) {
    $conn = mysqli_init();
    if (!$conn) {
        return array(false, 'Unable to initialize mysqli.');
    }
    $db_name = $with_database ? $cfg['name'] : null;
    if (!@mysqli_real_connect($conn, $cfg['host'], $cfg['user'], $cfg['pass'], $db_name, (int)$cfg['port'])) {
        return array(false, mysqli_connect_error() ? mysqli_connect_error() : 'Database connection failed.');
    }
    if (!@mysqli_set_charset($conn, $cfg['charset'])) {
        @mysqli_set_charset($conn, 'utf8');
    }
    return array($conn, '');
}

function installer_exec($conn, $sql) {
    return @mysqli_query($conn, $sql);
}

function installer_import_stream($conn, $path, $mode) {
    $fh = fopen($path, 'rb');
    if (!$fh) {
        return array('ok' => false, 'error' => 'Unable to open SQL file.', 'line' => 0, 'excerpt' => '');
    }

    $delimiter = ';';
    $statement = '';
    $line_number = 0;
    $statement_start_line = 1;
    $in_block_comment = false;

    while (($line = fgets($fh)) !== false) {
        $line_number++;
        if ($line_number === 1) {
            $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
        }
        $clean_line = strip_sql_comments_line($line, $in_block_comment);
        if ($clean_line === '' && $statement === '') {
            continue;
        }
        $trim = trim($clean_line);
        if ($trim === '' && $statement === '') {
            continue;
        }
        if ($statement === '') {
            $statement_start_line = $line_number;
        }

        if (preg_match('/^DELIMITER\s+(.+)$/i', $trim, $m)) {
            $delimiter = trim($m[1]) !== '' ? trim($m[1]) : ';';
            continue;
        }

        $statement .= $clean_line;
        if (!statement_has_delimiter($statement, $delimiter)) {
            $statement .= "\n";
            continue;
        }

        $ready = remove_statement_delimiter($statement, $delimiter);
        $statement = '';
        $ready = strip_definer_clause($ready);
        $ready = installer_normalize_legacy_sql($ready);
        if (!should_execute_statement($ready, $mode)) {
            continue;
        }

        if (!installer_exec($conn, $ready)) {
            fclose($fh);
            $excerpt = preg_replace('/\s+/', ' ', trim($ready));
            if (strlen($excerpt) > 500) {
                $excerpt = substr($excerpt, 0, 500) . '...';
            }
            return array(
                'ok' => false,
                'error' => mysqli_error($conn),
                'line' => $statement_start_line,
                'excerpt' => $excerpt
            );
        }
    }

    fclose($fh);
    return array('ok' => true);
}

function installer_table_exists($conn, $table) {
    $sql = "SHOW TABLES LIKE '" . mysqli_real_escape_string($conn, $table) . "'";
    $res = mysqli_query($conn, $sql);
    if (!$res) return false;
    $exists = mysqli_num_rows($res) > 0;
    mysqli_free_result($res);
    return $exists;
}

function installer_fetch_columns($conn, $table) {
    $cols = array();
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `" . str_replace('`', '``', $table) . "`");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $cols[$row['Field']] = true;
        }
        mysqli_free_result($res);
    }
    return $cols;
}

function installer_count_tables($conn) {
    $count = 0;
    $res = mysqli_query($conn, 'SHOW TABLES');
    if ($res) {
        while (mysqli_fetch_row($res)) {
            $count++;
        }
        mysqli_free_result($res);
    }
    return $count;
}

function installer_bootstrap_admin($conn, $action, $username, $password, $fullname) {
    if ($action === 'skip') {
        return array('ok' => true, 'message' => 'Skipped admin bootstrap.');
    }
    if (!installer_table_exists($conn, 'tb_users')) {
        return array('ok' => false, 'message' => 'tb_users table was not found after import.');
    }
    $cols = installer_fetch_columns($conn, 'tb_users');
    if (empty($cols['username']) || empty($cols['password'])) {
        return array('ok' => false, 'message' => 'tb_users schema does not contain username/password columns.');
    }
    if ($username === '' || $password === '') {
        return array('ok' => false, 'message' => 'Admin username and password are required.');
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $existing_id = 0;
    if ($st = mysqli_prepare($conn, 'SELECT id FROM tb_users WHERE username=? LIMIT 1')) {
        mysqli_stmt_bind_param($st, 's', $username);
        mysqli_stmt_execute($st);
        mysqli_stmt_bind_result($st, $existing_id);
        mysqli_stmt_fetch($st);
        mysqli_stmt_close($st);
    }

    if ($existing_id > 0) {
        if ($action === 'create') {
            return array('ok' => false, 'message' => 'The selected admin username already exists.');
        }
        $updates = array('password=?');
        $types = 's';
        $params = array($hash);
        if (!empty($cols['fullname']) && $fullname !== '') {
            $updates[] = 'fullname=?';
            $types .= 's';
            $params[] = $fullname;
        }
        if (!empty($cols['role'])) {
            $updates[] = 'role=?';
            $types .= 's';
            $params[] = 'admin';
        }
        $types .= 'i';
        $params[] = $existing_id;
        $sql = 'UPDATE tb_users SET ' . implode(', ', $updates) . ' WHERE id=?';
        $st = mysqli_prepare($conn, $sql);
        if (!$st) return array('ok' => false, 'message' => 'Unable to prepare admin reset.');
        $bind = array($st, $types);
        foreach ($params as $k => $v) { $bind[] = &$params[$k]; }
        call_user_func_array('mysqli_stmt_bind_param', $bind);
        $ok = mysqli_stmt_execute($st);
        mysqli_stmt_close($st);
        if (!$ok) return array('ok' => false, 'message' => 'Unable to reset the admin password.');
        return array('ok' => true, 'message' => 'Admin password reset completed.');
    }

    $fields = array('username', 'password');
    $placeholders = array('?', '?');
    $types = 'ss';
    $params = array($username, $hash);
    if (!empty($cols['fullname'])) {
        $fields[] = 'fullname';
        $placeholders[] = '?';
        $types .= 's';
        $params[] = ($fullname !== '' ? $fullname : 'System Administrator');
    }
    if (!empty($cols['role'])) {
        $fields[] = 'role';
        $placeholders[] = '?';
        $types .= 's';
        $params[] = 'admin';
    }
    if (!empty($cols['created_at'])) {
        $fields[] = 'created_at';
        $placeholders[] = 'NOW()';
    }
    $sql = 'INSERT INTO tb_users (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $st = mysqli_prepare($conn, $sql);
    if (!$st) return array('ok' => false, 'message' => 'Unable to prepare admin creation.');
    $bind = array($st, $types);
    foreach ($params as $k => $v) { $bind[] = &$params[$k]; }
    call_user_func_array('mysqli_stmt_bind_param', $bind);
    $ok = mysqli_stmt_execute($st);
    mysqli_stmt_close($st);
    if (!$ok) return array('ok' => false, 'message' => 'Unable to create the admin user.');
    return array('ok' => true, 'message' => 'Admin user created.');
}

function installer_write_local_config($path, $cfg) {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
    }
    $body = "<?php\nreturn array(\n"
          . "    'host' => " . var_export($cfg['host'], true) . ",\n"
          . "    'port' => " . (int)$cfg['port'] . ",\n"
          . "    'database' => " . var_export($cfg['name'], true) . ",\n"
          . "    'username' => " . var_export($cfg['user'], true) . ",\n"
          . "    'password' => " . var_export($cfg['pass'], true) . ",\n"
          . "    'charset' => " . var_export($cfg['charset'], true) . "\n"
          . ");\n";
    return file_put_contents($path, $body, LOCK_EX) !== false;
}

function installer_write_lock($path) {
    return file_put_contents($path, "Installed: " . date('c') . "\n", LOCK_EX) !== false;
}

function installer_import_plan($mode, $paths) {
    $plan = array();
    $schema_has = file_has_sql_statements($paths['schema']);
    $seed_has = file_has_sql_statements($paths['seed']);
    $data_has = file_has_sql_statements($paths['data']);
    $fallback_has = file_has_sql_statements($paths['fallback']);

    if ($mode === 'schema_only') {
        if ($schema_has) $plan[] = array('path' => $paths['schema'], 'mode' => 'all', 'label' => 'db/schema.sql');
        elseif ($fallback_has) $plan[] = array('path' => $paths['fallback'], 'mode' => 'schema_only', 'label' => 'db/hospital_kpi.sql (schema fallback)');
    } elseif ($mode === 'schema_seed') {
        if ($schema_has) $plan[] = array('path' => $paths['schema'], 'mode' => 'all', 'label' => 'db/schema.sql');
        if ($seed_has) $plan[] = array('path' => $paths['seed'], 'mode' => 'all', 'label' => 'db/seed.sql');
        if (empty($plan) && $fallback_has) $plan[] = array('path' => $paths['fallback'], 'mode' => 'all', 'label' => 'db/hospital_kpi.sql (full fallback)');
    } else {
        if ($data_has) $plan[] = array('path' => $paths['data'], 'mode' => 'all', 'label' => 'db/data.sql');
        elseif ($fallback_has) $plan[] = array('path' => $paths['fallback'], 'mode' => 'all', 'label' => 'db/hospital_kpi.sql (full fallback)');
        elseif ($schema_has) {
            $plan[] = array('path' => $paths['schema'], 'mode' => 'all', 'label' => 'db/schema.sql');
            if ($seed_has) $plan[] = array('path' => $paths['seed'], 'mode' => 'all', 'label' => 'db/seed.sql');
        }
    }

    return $plan;
}

if (is_file($lock_file)) {
    http_response_code(403);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Installer Locked</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100">
  <div class="max-w-2xl mx-auto mt-16 bg-white border border-slate-200 rounded-2xl shadow-sm p-8">
    <h1 class="text-2xl font-semibold text-slate-900">Installer Locked</h1>
    <p class="mt-3 text-slate-600">Installation has already completed on this environment. Remove or rotate <code>install/install.lock</code> only if you intentionally need to reinstall.</p>
  </div>
</body>
</html>
    <?php
    exit();
}

$state = installer_state();
$current_step = 1;
$messages = array();
$errors = array();
$success = false;
$success_details = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!installer_require_csrf()) {
        $errors[] = 'Invalid installer session token. Refresh the page and try again.';
        $current_step = 1;
    } else {
        $action = isset($_POST['wizard_action']) ? (string)$_POST['wizard_action'] : '';
        if ($action === 'save_db') {
            $state['db']['host'] = trim(isset($_POST['db_host']) ? $_POST['db_host'] : '');
            $state['db']['port'] = (int)(isset($_POST['db_port']) ? $_POST['db_port'] : 3306);
            $state['db']['name'] = trim(isset($_POST['db_name']) ? $_POST['db_name'] : '');
            $state['db']['user'] = trim(isset($_POST['db_user']) ? $_POST['db_user'] : '');
            $state['db']['pass'] = isset($_POST['db_pass']) ? (string)$_POST['db_pass'] : '';
            $state['db']['charset'] = trim(isset($_POST['db_charset']) ? $_POST['db_charset'] : 'utf8');
            $state['db']['create_db'] = !empty($_POST['create_db']) ? 1 : 0;
            if ($state['db']['host'] === '') $errors[] = 'DB host is required.';
            if ($state['db']['port'] <= 0) $errors[] = 'DB port must be a valid number.';
            if ($state['db']['name'] === '') $errors[] = 'DB name is required.';
            if ($state['db']['user'] === '') $errors[] = 'DB user is required.';
            if ($state['db']['charset'] === '') $state['db']['charset'] = 'utf8';
            installer_save_state($state);
            $current_step = empty($errors) ? 2 : 1;
        } elseif ($action === 'save_mode') {
            $mode = isset($_POST['import_mode']) ? (string)$_POST['import_mode'] : 'schema_seed';
            if (!in_array($mode, array('schema_only', 'schema_seed', 'full'), true)) {
                $mode = 'schema_seed';
            }
            $state['mode'] = $mode;
            installer_save_state($state);
            $current_step = 3;
        } elseif ($action === 'back_to_1') {
            $current_step = 1;
        } elseif ($action === 'back_to_2') {
            $current_step = 2;
        } elseif ($action === 'execute') {
            $current_step = 3;
            $state['admin_action'] = isset($_POST['admin_action']) ? (string)$_POST['admin_action'] : 'ensure_admin';
            $state['admin_username'] = trim(isset($_POST['admin_username']) ? $_POST['admin_username'] : 'admin');
            $state['admin_password'] = isset($_POST['admin_password']) ? (string)$_POST['admin_password'] : '';
            $state['admin_fullname'] = trim(isset($_POST['admin_fullname']) ? $_POST['admin_fullname'] : 'System Administrator');
            if (!in_array($state['admin_action'], array('skip', 'create', 'ensure_admin'), true)) {
                $state['admin_action'] = 'ensure_admin';
            }
            installer_save_state($state);

            $plan = installer_import_plan($state['mode'], array(
                'schema' => $schema_file,
                'seed' => $seed_file,
                'data' => $data_file,
                'fallback' => $fallback_dump
            ));
            if (empty($plan)) {
                $errors[] = 'No importable SQL files were found for the selected mode.';
            } else {
                list($conn, $connect_error) = installer_connect($state['db'], false);
                if ($conn === false) {
                    $errors[] = 'Unable to connect to the database server: ' . $connect_error;
                } else {
                    $db_name_escaped = str_replace('`', '``', $state['db']['name']);
                    if (!empty($state['db']['create_db']) && !installer_exec($conn, "CREATE DATABASE IF NOT EXISTS `" . $db_name_escaped . "`")) {
                        $errors[] = 'Unable to create the target database: ' . mysqli_error($conn);
                    }
                    if (empty($errors) && !installer_exec($conn, "USE `" . $db_name_escaped . "`")) {
                        $errors[] = 'Unable to select the target database: ' . mysqli_error($conn);
                    }
                    if (empty($errors)) {
                        $table_count = installer_count_tables($conn);
                        if ($table_count > 0) {
                            $errors[] = 'The target database is not empty (' . (int)$table_count . ' tables found). Use a new empty database, or drop the existing tables before running the installer again.';
                        }
                    }
                    if (empty($errors)) {
                        foreach ($plan as $item) {
                            $result = installer_import_stream($conn, $item['path'], $item['mode']);
                            if (!$result['ok']) {
                                $excerpt = $result['excerpt'] !== '' ? (' Statement: ' . $result['excerpt']) : '';
                                $errors[] = 'Import failed in ' . $item['label'] . ' near line ' . (int)$result['line'] . ': ' . $result['error'] . '.' . $excerpt;
                                break;
                            }
                        }
                    }
                    if (empty($errors)) {
                        $admin_result = installer_bootstrap_admin($conn, $state['admin_action'], $state['admin_username'], $state['admin_password'], $state['admin_fullname']);
                        if (!$admin_result['ok']) $errors[] = $admin_result['message'];
                        else $messages[] = $admin_result['message'];
                    }
                    if (empty($errors) && !installer_write_local_config($config_file, $state['db'])) {
                        $errors[] = 'Unable to write config/database.local.php. Check filesystem permissions.';
                    }
                    if (empty($errors) && !installer_write_lock($lock_file)) {
                        $errors[] = 'Unable to create install/install.lock. Check filesystem permissions.';
                    }
                    mysqli_close($conn);
                }
            }

            if (empty($errors)) {
                $success = true;
                $success_details = array(
                    'mode' => $state['mode'],
                    'database' => $state['db']['name'],
                    'config' => 'config/database.local.php',
                    'lock' => 'install/install.lock',
                    'fy' => installer_detect_fy_summary()
                );
            }
        }
    }
}

$plan_preview = installer_import_plan($state['mode'], array(
    'schema' => $schema_file,
    'seed' => $seed_file,
    'data' => $data_file,
    'fallback' => $fallback_dump
));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>hosp_kpis Installer</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100">
  <div class="max-w-5xl mx-auto px-4 py-10">
    <div class="rounded-3xl border border-slate-200 bg-white shadow-sm overflow-hidden">
      <div class="border-b border-slate-200 bg-slate-900 px-6 py-6 text-white">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <h1 class="text-2xl font-semibold">hosp_kpis Installer</h1>
            <p class="mt-2 text-sm text-slate-300">Configure the database, import SQL with a streaming parser, and lock the installer after success.</p>
          </div>
          <div class="rounded-2xl bg-slate-800 px-4 py-3 text-sm text-slate-200">
            <div>Step <?php echo $success ? 'Done' : (int)$current_step; ?> of 3</div>
            <div class="mt-1 text-xs text-slate-400"><?php echo h(installer_detect_fy_summary()); ?></div>
          </div>
        </div>
      </div>
      <div class="px-6 py-6">
        <?php if (!empty($errors)): ?>
          <div class="mb-5 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <?php foreach ($errors as $err): ?><div><?php echo h($err); ?></div><?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if (!empty($messages)): ?>
          <div class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            <?php foreach ($messages as $msg): ?><div><?php echo h($msg); ?></div><?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if ($success): ?>
          <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-5">
            <h2 class="text-xl font-semibold text-emerald-900">Installation Complete</h2>
            <div class="mt-3 space-y-2 text-sm text-emerald-900">
              <div>Database: <?php echo h($success_details['database']); ?></div>
              <div>Import mode: <?php echo h($success_details['mode']); ?></div>
              <div>Config written: <?php echo h($success_details['config']); ?></div>
              <div>Installer lock written: <?php echo h($success_details['lock']); ?></div>
              <div><?php echo h($success_details['fy']); ?></div>
            </div>
            <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
              <div>1. Block or remove the <code>/install</code> directory at the web server after verification.</div>
              <div>2. Keep <code>config/database.local.php</code> outside version control.</div>
              <div>3. Run the smoke checklist in <code>SMOKE_TEST.md</code>.</div>
            </div>
          </div>
        <?php else: ?>
          <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
            <div class="lg:col-span-3">
              <?php if ($current_step === 1): ?>
                <form method="post" class="space-y-5">
                  <input type="hidden" name="csrf_token" value="<?php echo h(installer_csrf_token()); ?>">
                  <input type="hidden" name="wizard_action" value="save_db">
                  <div>
                    <h2 class="text-xl font-semibold text-slate-900">Step 1: Database Connection</h2>
                    <p class="mt-1 text-sm text-slate-500">The installer writes these settings into <code>config/database.local.php</code> only after a successful import.</p>
                  </div>
                  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium text-slate-700 mb-1">DB_HOST</label><input type="text" name="db_host" class="w-full rounded-xl border border-slate-300 px-3 py-2.5" value="<?php echo h($state['db']['host']); ?>" required></div>
                    <div><label class="block text-sm font-medium text-slate-700 mb-1">DB_PORT</label><input type="number" name="db_port" class="w-full rounded-xl border border-slate-300 px-3 py-2.5" value="<?php echo (int)$state['db']['port']; ?>" min="1" max="65535" required></div>
                    <div><label class="block text-sm font-medium text-slate-700 mb-1">DB_NAME</label><input type="text" name="db_name" class="w-full rounded-xl border border-slate-300 px-3 py-2.5" value="<?php echo h($state['db']['name']); ?>" required></div>
                    <div><label class="block text-sm font-medium text-slate-700 mb-1">DB_USER</label><input type="text" name="db_user" class="w-full rounded-xl border border-slate-300 px-3 py-2.5" value="<?php echo h($state['db']['user']); ?>" required></div>
                    <div><label class="block text-sm font-medium text-slate-700 mb-1">DB_PASS</label><input type="password" name="db_pass" class="w-full rounded-xl border border-slate-300 px-3 py-2.5" value=""></div>
                    <div><label class="block text-sm font-medium text-slate-700 mb-1">DB_CHARSET</label><input type="text" name="db_charset" class="w-full rounded-xl border border-slate-300 px-3 py-2.5" value="<?php echo h($state['db']['charset']); ?>"></div>
                  </div>
                  <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" name="create_db" value="1" <?php echo !empty($state['db']['create_db']) ? 'checked' : ''; ?>>
                    <span>Create database if it does not exist</span>
                  </label>
                  <div class="flex justify-end"><button type="submit" class="rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-medium text-white hover:bg-slate-800">Continue</button></div>
                </form>
              <?php elseif ($current_step === 2): ?>
                <form method="post" class="space-y-5">
                  <input type="hidden" name="csrf_token" value="<?php echo h(installer_csrf_token()); ?>">
                  <div>
                    <h2 class="text-xl font-semibold text-slate-900">Step 2: Import Mode</h2>
                    <p class="mt-1 text-sm text-slate-500">Choose the smallest safe import path for the target environment.</p>
                  </div>
                  <div class="space-y-3">
                    <label class="block rounded-2xl border border-slate-200 p-4"><input type="radio" name="import_mode" value="schema_only" <?php echo $state['mode'] === 'schema_only' ? 'checked' : ''; ?>><span class="ml-2 font-medium text-slate-900">Mode 1: Schema only</span><div class="mt-2 text-sm text-slate-500">Uses <code>db/schema.sql</code> when present, otherwise streams <code>db/hospital_kpi.sql</code> and skips data statements.</div></label>
                    <label class="block rounded-2xl border border-slate-200 p-4"><input type="radio" name="import_mode" value="schema_seed" <?php echo $state['mode'] === 'schema_seed' ? 'checked' : ''; ?>><span class="ml-2 font-medium text-slate-900">Mode 2: Schema + Seed</span><div class="mt-2 text-sm text-slate-500">Uses <code>db/schema.sql</code> + <code>db/seed.sql</code>, or falls back to the bundled full dump.</div></label>
                    <label class="block rounded-2xl border border-slate-200 p-4"><input type="radio" name="import_mode" value="full" <?php echo $state['mode'] === 'full' ? 'checked' : ''; ?>><span class="ml-2 font-medium text-slate-900">Mode 3: Full import</span><div class="mt-2 text-sm text-slate-500">Uses <code>db/data.sql</code> when present, otherwise streams <code>db/hospital_kpi.sql</code>.</div></label>
                  </div>
                  <div class="flex justify-between">
                    <button type="submit" name="wizard_action" value="back_to_1" class="rounded-xl border border-slate-300 px-5 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50">Back</button>
                    <button type="submit" name="wizard_action" value="save_mode" class="rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-medium text-white hover:bg-slate-800">Continue</button>
                  </div>
                </form>
              <?php else: ?>
                <form method="post" class="space-y-5">
                  <input type="hidden" name="csrf_token" value="<?php echo h(installer_csrf_token()); ?>">
                  <input type="hidden" name="wizard_action" value="execute">
                  <div>
                    <h2 class="text-xl font-semibold text-slate-900">Step 3: Admin Bootstrap</h2>
                    <p class="mt-1 text-sm text-slate-500">The runtime uses <code>tb_users</code> with bcrypt-based login via <code>password_verify()</code>.</p>
                  </div>
                  <div class="space-y-3">
                    <label class="block rounded-2xl border border-slate-200 p-4"><input type="radio" name="admin_action" value="ensure_admin" <?php echo $state['admin_action'] === 'ensure_admin' ? 'checked' : ''; ?>><span class="ml-2 font-medium text-slate-900">Create admin user or reset password if it exists</span></label>
                    <label class="block rounded-2xl border border-slate-200 p-4"><input type="radio" name="admin_action" value="create" <?php echo $state['admin_action'] === 'create' ? 'checked' : ''; ?>><span class="ml-2 font-medium text-slate-900">Create admin user only</span></label>
                    <label class="block rounded-2xl border border-slate-200 p-4"><input type="radio" name="admin_action" value="skip" <?php echo $state['admin_action'] === 'skip' ? 'checked' : ''; ?>><span class="ml-2 font-medium text-slate-900">Skip admin bootstrap</span></label>
                  </div>
                  <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div><label class="block text-sm font-medium text-slate-700 mb-1">Admin username</label><input type="text" name="admin_username" class="w-full rounded-xl border border-slate-300 px-3 py-2.5" value="<?php echo h($state['admin_username']); ?>"></div>
                    <div><label class="block text-sm font-medium text-slate-700 mb-1">Admin password</label><input type="password" name="admin_password" class="w-full rounded-xl border border-slate-300 px-3 py-2.5" value=""></div>
                    <div><label class="block text-sm font-medium text-slate-700 mb-1">Admin full name</label><input type="text" name="admin_fullname" class="w-full rounded-xl border border-slate-300 px-3 py-2.5" value="<?php echo h($state['admin_fullname']); ?>"></div>
                  </div>
                  <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-600">
                    <div class="font-medium text-slate-900 mb-2">Execution preview</div>
                    <div>Database: <?php echo h($state['db']['name']); ?> on <?php echo h($state['db']['host']); ?>:<?php echo (int)$state['db']['port']; ?></div>
                    <div>Config target: <code>config/database.local.php</code></div>
                    <div>Installer lock: <code>install/install.lock</code></div>
                    <div class="mt-2">Import files:</div>
                    <?php if (empty($plan_preview)): ?>
                      <div class="text-red-700">No importable SQL files detected.</div>
                    <?php else: ?>
                      <ul class="mt-1 list-disc pl-5">
                        <?php foreach ($plan_preview as $item): ?><li><?php echo h($item['label']); ?></li><?php endforeach; ?>
                      </ul>
                    <?php endif; ?>
                  </div>
                  <div class="flex justify-between">
                    <button type="submit" name="wizard_action" value="back_to_2" class="rounded-xl border border-slate-300 px-5 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50">Back</button>
                    <button type="submit" class="rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-emerald-700">Run Installer</button>
                  </div>
                </form>
              <?php endif; ?>
            </div>
            <div class="lg:col-span-1">
              <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-600">
                <div class="font-medium text-slate-900 mb-2">Safety Notes</div>
                <div>1. The installer never prints database passwords after submission.</div>
                <div class="mt-2">2. Large SQL imports are streamed line-by-line and stop on the first error.</div>
                <div class="mt-2">3. CREATE DATABASE and USE statements inside SQL files are ignored.</div>
                <div class="mt-2">4. Remove or block <code>/install</code> after successful setup.</div>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>

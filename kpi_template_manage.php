<?php
// kpi_template_manage.php — จัดการแม่แบบตัวชี้วัด (KPI Templates)
// - เลือก Strategy → auto เติม Mission + Strategic Issue
// Compatible: PHP 5.6

include 'db_connect.php';
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
require_once __DIR__ . '/auth.php';
require_login();
require_role(array('admin', 'manager', 'staff'));
$u = current_user(); // ใช้ $u['fullname'], $u['role'] ได้

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/* ===== config การอัปโหลดไฟล์เทมเพลต ===== */
$upload_dir      = __DIR__ . '/uploads_kpi_templates';
$upload_url_base = 'uploads_kpi_templates';

if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0777, true);
}

/* ===== GET param ===== */
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$message = "";

/* ---------- โหลด master: Strategies + Missions + Strategic Issues ---------- */
/*
   tb_strategies : id, mission_id, name, description
   tb_missions   : id, name, strategic_issue, description
*/
$strategies = array();
$sql_str = "
  SELECT
    s.id,
    s.name AS strategy_name,
    m.name AS mission_name,
    m.strategic_issue
  FROM tb_strategies s
  LEFT JOIN tb_missions m ON m.id = s.mission_id
  ORDER BY m.strategic_issue, m.name, s.name
";
if ($res = mysqli_query($conn, $sql_str)) {
    while ($r = mysqli_fetch_assoc($res)) {
        $strategies[] = $r;
    }
    mysqli_free_result($res);
}

/* ---------- ค่าเริ่มต้นฟอร์ม ---------- */
$form = array(
    'id'              => 0,
    'kpi_name'        => '',
    'description'     => '',
    'strategic_issue' => '',
    'mission'         => '',
    'strategy_id'     => 0,
    'department_id'   => '',   // ยังเก็บใน DB แต่ไม่มีฟอร์มแก้ไขแล้ว
    'workgroup_id'    => '',   // ยังเก็บใน DB แต่ไม่มีฟอร์มแก้ไขแล้ว
    'agg_type'        => 'AVG',
    'template_file'   => ''
);

/* ---------- โหลดข้อมูลเดิม (ถ้าแก้ไข) ---------- */
if ($edit_id > 0) {
    if ($st = mysqli_prepare($conn, "SELECT * FROM tb_kpi_templates WHERE id=? LIMIT 1")) {
        mysqli_stmt_bind_param($st, "i", $edit_id);
        mysqli_stmt_execute($st);
        $q = mysqli_stmt_get_result($st);
        if ($q && mysqli_num_rows($q) > 0) {
            $form = mysqli_fetch_assoc($q);
            if (!isset($form['agg_type']) || $form['agg_type'] === '') {
                $form['agg_type'] = 'AVG';
            }
            if (!isset($form['strategy_id'])) {
                $form['strategy_id'] = 0;
            }
            if (!isset($form['department_id'])) {
                $form['department_id'] = '';
            }
            if (!isset($form['workgroup_id'])) {
                $form['workgroup_id'] = '';
            }
        } else {
            $message = "ไม่พบแม่แบบที่ต้องการแก้ไข";
        }
        if ($q) mysqli_free_result($q);
        mysqli_stmt_close($st);
    } else {
        error_log('[hosp_kpis] KPI template load prepare failed | ' . mysqli_error($conn));
        $message = "ไม่สามารถโหลดแม่แบบได้ในขณะนี้";
    }
}

/* ---------- ฟังก์ชันจัดการ upload ไฟล์เทมเพลต ---------- */
function handle_template_upload($field_name, $upload_dir, $current_file, &$error_msg)
{
    if (!isset($_FILES[$field_name]) || !is_array($_FILES[$field_name])) {
        return $current_file;
    }
    $file = $_FILES[$field_name];
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return $current_file;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error_msg = "อัปโหลดไฟล์ไม่สำเร็จ (code " . $file['error'] . ")";
        return $current_file;
    }

    $allowed_ext = array('pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png');
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_ext)) {
        $error_msg = "ชนิดไฟล์ไม่ถูกต้อง อนุญาต: " . implode(', ', $allowed_ext);
        return $current_file;
    }

    $base = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
    if ($base === '') $base = 'kpi_tpl';
    $new_name = $base . '_' . date('Ymd_His') . '_' . mt_rand(1000, 9999) . '.' . $ext;
    $dest     = rtrim($upload_dir, '/\\') . DIRECTORY_SEPARATOR . $new_name;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        $error_msg = "บันทึกไฟล์ไม่สำเร็จ (move_uploaded_file ล้มเหลว)";
        return $current_file;
    }

    if ($current_file) {
        $old = rtrim($upload_dir, '/\\') . DIRECTORY_SEPARATOR . $current_file;
        if (is_file($old)) { @unlink($old); }
    }

    return $new_name;
}

/* ---------- บันทึก (POST) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] !== 'delete')) {
    require_post_csrf();
    $id          = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $kpi_name    = trim(isset($_POST['kpi_name']) ? $_POST['kpi_name'] : '');
    $description = trim(isset($_POST['description']) ? $_POST['description'] : '');

    // กลยุทธ์ / mission / strategic_issue
    $strategy_id     = isset($_POST['strategy_id']) ? (int)$_POST['strategy_id'] : 0;
    $strategic_issue = trim(isset($_POST['strategic_issue']) ? $_POST['strategic_issue'] : '');
    $mission         = trim(isset($_POST['mission']) ? $_POST['mission'] : '');

    $agg_type = isset($_POST['agg_type']) ? $_POST['agg_type'] : 'AVG';
    if ($agg_type !== 'SUM' && $agg_type !== 'AVG') {
        $agg_type = 'AVG';
    }

    // ไม่มีฟอร์มกำหนดหน่วยงาน/ทีมแล้ว → ใช้ค่าที่โหลดมาจาก DB เดิม
    $department_csv = isset($form['department_id']) ? (string)$form['department_id'] : '';
    $team_csv       = isset($form['workgroup_id'])  ? (string)$form['workgroup_id']  : '';

    // ไฟล์ปัจจุบัน
    $current_file = isset($form['template_file']) ? (string)$form['template_file'] : '';
    $upload_error = '';

    $template_file = handle_template_upload('template_file', $upload_dir, $current_file, $upload_error);

    if ($upload_error !== '') {
        $message = $upload_error;
    } else {
        if ($kpi_name === '') {
            $message = "กรุณากรอกชื่อ KPI";
        } elseif ($strategy_id <= 0 || $mission === '' || $strategic_issue === '') {
            $message = "กรุณาเลือกกลยุทธ์ เพื่อระบุเป้าประสงค์และประเด็นยุทธศาสตร์";
        } else {
            if ($id > 0) {
                // UPDATE
                $sql = "UPDATE tb_kpi_templates
                        SET kpi_name=?, description=?, strategic_issue=?, mission=?,
                            strategy_id=?, department_id=?, workgroup_id=?,
                            agg_type=?, template_file=?
                        WHERE id=?";
                if ($st = mysqli_prepare($conn, $sql)) {
                    mysqli_stmt_bind_param(
                        $st,
                        "ssssissssi",
                        $kpi_name,
                        $description,
                        $strategic_issue,
                        $mission,
                        $strategy_id,
                        $department_csv,
                        $team_csv,
                        $agg_type,
                        $template_file,
                        $id
                    );
                }
            } else {
                // INSERT (ตั้งต้น department_id / workgroup_id ว่างไปก่อน)
                $sql = "INSERT INTO tb_kpi_templates
                        (kpi_name, description, strategic_issue, mission,
                         strategy_id, department_id, workgroup_id,
                         agg_type, template_file)
                        VALUES (?,?,?,?,?,?,?,?,?)";
                if ($st = mysqli_prepare($conn, $sql)) {
                    mysqli_stmt_bind_param(
                        $st,
                        "ssssissss",
                        $kpi_name,
                        $description,
                        $strategic_issue,
                        $mission,
                        $strategy_id,
                        $department_csv,   // ส่วนมากเป็น ''
                        $team_csv,         // ส่วนมากเป็น ''
                        $agg_type,
                        $template_file
                    );
                }
            }

            if (isset($st) && $st) {
                if (mysqli_stmt_execute($st)) {
                    if ($id > 0) {
                        header("Location: kpi_template_manage.php?edit=" . $id . "&message=Updated");
                        exit();
                    } else {
                        header("Location: kpi_template_manage.php?message=Created");
                        exit();
                    }
                } else {
                    error_log('[hosp_kpis] KPI template save failed | ' . mysqli_error($conn));
                    $message = "บันทึกไม่สำเร็จ กรุณาลองใหม่อีกครั้ง";
                }
                mysqli_stmt_close($st);
            } elseif (!isset($message) || $message === "") {
                error_log('[hosp_kpis] KPI template prepare failed | ' . mysqli_error($conn));
                $message = "บันทึกไม่สำเร็จ กรุณาลองใหม่อีกครั้ง";
            }
        }
    }

    // คืนค่าฟอร์มเมื่อบันทึกผิดพลาด
    $form['kpi_name']        = $kpi_name;
    $form['description']     = $description;
    $form['strategic_issue'] = $strategic_issue;
    $form['mission']         = $mission;
    $form['strategy_id']     = $strategy_id;
    $form['agg_type']        = $agg_type;
    $form['template_file']   = $template_file;
    $form['department_id']   = $department_csv;
    $form['workgroup_id']    = $team_csv;
}

/* ---------- ลบ ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    require_post_csrf();
    $del = isset($_POST['delete_id']) ? (int)$_POST['delete_id'] : 0;

    $old_file = '';
    if ($del > 0 && $stf = mysqli_prepare($conn, "SELECT template_file FROM tb_kpi_templates WHERE id=? LIMIT 1")) {
        mysqli_stmt_bind_param($stf, "i", $del);
        mysqli_stmt_execute($stf);
        $qf = mysqli_stmt_get_result($stf);
        if ($qf && mysqli_num_rows($qf) > 0) {
            $rowf    = mysqli_fetch_assoc($qf);
            $old_file = isset($rowf['template_file']) ? $rowf['template_file'] : '';
        }
        if ($qf) mysqli_free_result($qf);
        mysqli_stmt_close($stf);
    }

    if ($del > 0 && $st = mysqli_prepare($conn, "DELETE FROM tb_kpi_templates WHERE id=?")) {
        mysqli_stmt_bind_param($st, "i", $del);
        if (mysqli_stmt_execute($st)) {
            if ($old_file) {
                $path_old = rtrim($upload_dir, '/\\') . DIRECTORY_SEPARATOR . $old_file;
                if (is_file($path_old)) { @unlink($path_old); }
            }
            header("Location: kpi_template_manage.php?message=Deleted");
            exit();
        } else {
            error_log('[hosp_kpis] KPI template delete failed | ' . mysqli_error($conn));
            $message = "ลบไม่สำเร็จ กรุณาลองใหม่อีกครั้ง";
        }
        mysqli_stmt_close($st);
    } elseif ($del > 0) {
        error_log('[hosp_kpis] KPI template delete prepare failed | ' . mysqli_error($conn));
        $message = "ลบไม่สำเร็จ กรุณาลองใหม่อีกครั้ง";
    }
}

/* ---------- รายการแม่แบบ ---------- */
$rows = array();
$list_q = trim(isset($_GET['list_q']) ? $_GET['list_q'] : '');
$list_pp = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
if ($list_pp < 10) $list_pp = 10;
if ($list_pp > 100) $list_pp = 100;
$list_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($list_page < 1) $list_page = 1;
$list_offset = ($list_page - 1) * $list_pp;
$list_where_sql = '';
$list_types = '';
$list_params = array();
if ($list_q !== '') {
    $like = '%' . $list_q . '%';
    $list_where_sql = "WHERE (
        t.kpi_name LIKE ?
        OR t.strategic_issue LIKE ?
        OR t.mission LIKE ?
        OR s.name LIKE ?
    )";
    $list_types = 'ssss';
    $list_params = array($like, $like, $like, $like);
}
$list_total = 0;
$sql_count = "
  SELECT COUNT(*)
  FROM tb_kpi_templates t
  LEFT JOIN tb_strategies s ON s.id = t.strategy_id
  $list_where_sql
";
$count_started = perf_now();
if ($stc = mysqli_prepare($conn, $sql_count)) {
    if (db_bind_params($stc, $list_types, $list_params) && mysqli_stmt_execute($stc)) {
        mysqli_stmt_bind_result($stc, $list_total);
        mysqli_stmt_fetch($stc);
        perf_log_if_slow('kpi_template_manage.count', $count_started, array('search' => $list_q));
    }
    mysqli_stmt_close($stc);
}
$list_total_pages = $list_total > 0 ? (int)ceil($list_total / $list_pp) : 1;
if ($list_page > $list_total_pages) {
    $list_page = $list_total_pages;
    if ($list_page < 1) $list_page = 1;
    $list_offset = ($list_page - 1) * $list_pp;
}
$sql_list = "
  SELECT
    t.id,
    t.kpi_name,
    t.strategic_issue,
    t.mission,
    t.agg_type,
    t.template_file,
    s.name AS strategy_name
  FROM tb_kpi_templates t
  LEFT JOIN tb_strategies s ON s.id = t.strategy_id
  $list_where_sql
  ORDER BY t.strategic_issue, t.mission, t.kpi_name
  LIMIT ?, ?
";
$list_types_with_limit = $list_types . 'ii';
$list_params_with_limit = $list_params;
$list_params_with_limit[] = (int)$list_offset;
$list_params_with_limit[] = (int)$list_pp;
$list_started = perf_now();
if ($stl = mysqli_prepare($conn, $sql_list)) {
    if (db_bind_params($stl, $list_types_with_limit, $list_params_with_limit) && mysqli_stmt_execute($stl)) {
        $q = mysqli_stmt_get_result($stl);
        if ($q) {
            while ($r = mysqli_fetch_assoc($q)) {
                if (!isset($r['agg_type']) || $r['agg_type'] === '') {
                    $r['agg_type'] = 'AVG';
                }
                $rows[] = $r;
            }
            mysqli_free_result($q);
        }
        perf_log_if_slow('kpi_template_manage.list', $list_started, array('search' => $list_q, 'page' => $list_page, 'per_page' => $list_pp));
    }
    mysqli_stmt_close($stl);
}

/* ---------- หาค่า strategy ที่ควรเลือก ---------- */
$selected_strategy_id = isset($form['strategy_id']) ? (int)$form['strategy_id'] : 0;
if ($selected_strategy_id <= 0 && (!empty($form['mission']) || !empty($form['strategic_issue']))) {
    foreach ($strategies as $st) {
        if ($st['mission_name'] === $form['mission'] &&
            $st['strategic_issue'] === $form['strategic_issue']) {
            $selected_strategy_id = (int)$st['id'];
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <title>จัดการแบบฟอร์มตัวชี้วัด (KPI Templates) | โรงพยาบาลศรีรัตนะ</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css">
</head>
<body class="bg-slate-100 min-h-screen">

<?php
  $active_nav = 'template';
  include __DIR__ . '/navbar_kpi.php';
?>

<div class="w-full bg-white/95 p-6 rounded-3xl shadow-xl shadow-slate-200/70 border border-slate-200 mt-4">

  <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-5 border-b border-slate-200 pb-4">
    <div>
      <h1 class="text-xl md:text-2xl font-semibold text-gray-900">
        จัดการแบบฟอร์มตัวชี้วัด (KPI Templates)
      </h1>
      <p class="text-sm text-gray-500 mt-1">
        ใช้กำหนดรายละเอียดตัวชี้วัดมาตรฐาน เพื่อใช้ซ้ำในการบันทึกผลตามปีงบประมาณและไตรมาส
      </p>
    </div>
    <?php if (isset($_GET['message'])): ?>
      <span class="inline-flex items-center px-3 py-1.5 rounded-2xl bg-emerald-50 border border-emerald-200 text-sm font-medium text-emerald-700 shadow-sm shadow-emerald-100">
        <?php echo h($_GET['message']); ?>
      </span>
    <?php endif; ?>
  </div>

  <?php if ($message !== ""): ?>
    <div class="mb-5 p-4 rounded-2xl border border-red-300 bg-red-50 text-red-700 text-sm shadow-sm shadow-red-100">
      <?php echo h($message); ?>
    </div>
  <?php endif; ?>

  <!-- ฟอร์มแม่แบบ -->
  <form method="post" enctype="multipart/form-data" class="space-y-5 rounded-2xl border border-slate-200 bg-slate-50/80 p-5 shadow-inner shadow-slate-100">
    <input type="hidden" name="id" value="<?php echo isset($form['id']) ? (int)$form['id'] : 0; ?>">
    <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">

    <!-- KPI Name -->
    <div>
      <label class="block font-semibold text-gray-700 mb-1">ชื่อตัวชี้วัด (KPI Name)</label>
      <input type="text" name="kpi_name"
             value="<?php echo h(isset($form['kpi_name']) ? $form['kpi_name'] : ''); ?>"
             class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200" required>
    </div>

    <!-- Description -->
    <div>
      <label class="block font-semibold text-gray-700 mb-1">รายละเอียดตัวชี้วัด (Description)</label>
      <textarea name="description" class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200" rows="3"><?php
        echo h(isset($form['description']) ? $form['description'] : '');
      ?></textarea>
    </div>

    <!-- *** ลบส่วน Responsible ออกแล้ว *** -->

    <!-- Strategy -->
    <div>
      <label class="block font-semibold text-gray-700 mb-1">
        กลยุทธ์ (Strategy) ที่ตัวชี้วัดนี้สังกัด
      </label>
      <select id="strategySelect" name="strategy_id" class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200">
        <option value="">-- เลือกกลยุทธ์ / เป้าประสงค์ / ประเด็นยุทธศาสตร์ --</option>
        <?php foreach ($strategies as $st): ?>
          <option
            value="<?php echo (int)$st['id']; ?>"
            data-mission="<?php echo h($st['mission_name']); ?>"
            data-issue="<?php echo h($st['strategic_issue']); ?>"
            <?php echo ($selected_strategy_id === (int)$st['id']) ? 'selected' : ''; ?>
          >
            <?php
              echo h(
                  $st['strategy_name']
                  . ' — ' . $st['mission_name']
                  . ' — ' . $st['strategic_issue']
              );
            ?>
          </option>
        <?php endforeach; ?>
      </select>
      <p class="text-xs text-gray-500 mt-1">
        เมื่อเลือกกลยุทธ์ ระบบจะเติมชื่อเป้าประสงค์ และประเด็นยุทธศาสตร์ให้อัตโนมัติ
      </p>
    </div>

    <!-- Mission -->
    <div>
      <label class="block font-semibold text-gray-700 mb-1">
        เป้าประสงค์ (Mission)
      </label>
      <input type="text"
             id="missionDisplay"
             class="w-full p-2.5 border border-slate-300 rounded-xl text-sm bg-slate-50 text-gray-800 shadow-sm"
             value="<?php echo h($form['mission']); ?>"
             readonly>
      <input type="hidden"
             name="mission"
             id="missionInput"
             value="<?php echo h($form['mission']); ?>">
    </div>

    <!-- Strategic Issue -->
    <div>
      <label class="block font-semibold text-gray-700 mb-1">
        ประเด็นยุทธศาสตร์ (Strategic Issue)
      </label>
      <input type="text"
             id="strategicIssueDisplay"
             class="w-full p-2.5 border border-slate-300 rounded-xl text-sm bg-slate-50 text-gray-800 shadow-sm"
             value="<?php echo h($form['strategic_issue']); ?>"
             readonly>
      <input type="hidden"
             name="strategic_issue"
             id="strategicIssueInput"
             value="<?php echo h($form['strategic_issue']); ?>">
    </div>

    <!-- Aggregation Type -->
    <div>
      <label class="block font-semibold text-gray-700 mb-1">
        รูปแบบการคำนวณผลรวมทั้งปี (Aggregation Type)
      </label>
      <select name="agg_type" class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200">
        <option value="SUM" <?php echo (isset($form['agg_type']) && $form['agg_type'] === 'SUM') ? 'selected' : ''; ?>>
          สะสมทั้งปี (SUM) – ใช้ผลรวม Q1+Q2+Q3+Q4
        </option>
        <option value="AVG" <?php
            echo (!isset($form['agg_type']) || $form['agg_type'] === '' || $form['agg_type'] === 'AVG') ? 'selected' : '';
        ?>>
          ค่าเฉลี่ยต่อไตรมาส (AVG) – ใช้ค่าเฉลี่ยของ Q ที่มีข้อมูล
        </option>
      </select>
      <p class="text-xs text-gray-500 mt-1">
        * SUM: เหมาะกับตัวชี้วัดที่ต้องการยอดสะสมทั้งปี เช่น จำนวนครั้ง, จำนวนราย<br>
        * AVG: เหมาะกับตัวชี้วัดที่ต้องการค่าเฉลี่ย เช่น ร้อยละ, คะแนนเฉลี่ย, เวลาเฉลี่ย
      </p>
    </div>

    <!-- Template file -->
    <div>
      <label class="block font-semibold text-gray-700 mb-1">แนบเอกสารเทมเพลตตัวชี้วัด</label>
      <input type="file" name="template_file"
             class="block w-full text-sm text-gray-700
                    file:mr-3 file:py-1.5 file:px-3
                    file:rounded file:border-0
                    file:bg-blue-50 file:text-blue-700
                    hover:file:bg-blue-100">
      <p class="text-xs text-gray-500 mt-1">
        รองรับไฟล์: pdf, doc, docx, xls, xlsx, ppt, pptx, jpg, jpeg, png
      </p>
      <?php if (!empty($form['template_file'])): ?>
        <p class="text-xs text-gray-600 mt-2">
          ไฟล์ปัจจุบัน:
          <a href="<?php echo h($upload_url_base . '/' . $form['template_file']); ?>"
             target="_blank"
             class="text-blue-600 underline">
            <?php echo h($form['template_file']); ?>
          </a>
        </p>
      <?php endif; ?>
    </div>

    <div class="flex gap-3 pt-2">
      <button type="submit" class="px-4 py-2 bg-blue-700 hover:bg-blue-800 text-white rounded-xl text-sm shadow-sm shadow-blue-200">
        บันทึกแบบฟอร์ม
      </button>
      <a href="kpi_template_manage.php" class="px-4 py-2 bg-slate-700 hover:bg-slate-800 text-white rounded-xl text-sm shadow-sm shadow-slate-200">
        ล้างฟอร์ม
      </a>
    </div>
  </form>

  <!-- ตารางรายการแม่แบบ -->
  <h2 class="text-lg md:text-xl font-semibold mt-8 mb-3">
    รายการแบบฟอร์มตัวชี้วัดที่มีอยู่ (Templates List)
  </h2>
  <form method="get" class="mb-4 grid grid-cols-1 md:grid-cols-4 gap-3 rounded-2xl border border-slate-200 bg-slate-50/80 p-4 shadow-inner shadow-slate-100">
    <div class="md:col-span-2">
      <input type="text"
             name="list_q"
             value="<?php echo h($list_q); ?>"
             class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200"
             placeholder="ค้นหา KPI / ประเด็นยุทธศาสตร์ / เป้าประสงค์ / กลยุทธ์">
    </div>
    <div>
      <select name="per_page" class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200">
        <?php foreach (array(10,20,50,100) as $pp): ?>
          <option value="<?php echo (int)$pp; ?>" <?php echo ($pp === $list_pp ? 'selected' : ''); ?>>
            <?php echo (int)$pp; ?> ต่อหน้า
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="flex gap-2">
      <button type="submit" class="px-4 py-2 bg-gray-700 text-white rounded text-sm w-full">ค้นหา</button>
      <a href="kpi_template_manage.php" class="px-4 py-2 bg-gray-200 text-gray-700 rounded text-sm w-full text-center">ล้าง</a>
    </div>
  </form>
  <div class="mb-3 text-sm text-gray-500">
    พบ <?php echo number_format((int)$list_total); ?> รายการ
  </div>
  <div class="overflow-x-auto">
    <table class="min-w-full text-sm border border-gray-200">
      <thead>
        <tr class="bg-gray-50 text-gray-700">
          <th class="p-2 border text-left">#</th>
          <th class="p-2 border text-left">KPI Name</th>
          <th class="p-2 border text-left">Strategic Issue</th>
          <th class="p-2 border text-left">Mission</th>
          <th class="p-2 border text-left">กลยุทธ์ (Strategy)</th>
          <th class="p-2 border text-left">รูปแบบ KPI</th>
          <th class="p-2 border text-left">Template</th>
          <th class="p-2 border text-left">Actions</th>
        </tr>
      </thead>
      <tbody class="bg-white divide-y">
        <?php if (empty($rows)): ?>
          <tr>
            <td colspan="8" class="p-3 text-center text-gray-500">
              ยังไม่มีแม่แบบตัวชี้วัด
            </td>
          </tr>
        <?php else: $i = $list_offset + 1; foreach ($rows as $r): ?>
          <tr>
            <td class="p-2 border"><?php echo $i++; ?></td>
            <td class="p-2 border"><?php echo h($r['kpi_name']); ?></td>
            <td class="p-2 border"><?php echo h($r['strategic_issue']); ?></td>
            <td class="p-2 border"><?php echo h($r['mission']); ?></td>
            <td class="p-2 border"><?php echo h(isset($r['strategy_name']) ? $r['strategy_name'] : ''); ?></td>
            <td class="p-2 border">
              <?php
                $agg = isset($r['agg_type']) ? $r['agg_type'] : 'AVG';
                $aggText = ($agg === 'SUM')
                  ? 'สะสมทั้งปี (SUM)'
                  : 'ค่าเฉลี่ยต่อไตรมาส (AVG)';
                echo h($aggText);
              ?>
            </td>
            <td class="p-2 border">
              <?php
                $f = isset($r['template_file']) ? trim($r['template_file']) : '';
                if ($f !== ''):
              ?>
                <a href="<?php echo h($upload_url_base . '/' . $f); ?>"
                   target="_blank"
                   class="inline-flex items-center px-2 py-1 text-xs rounded bg-blue-50 text-blue-700 hover:bg-blue-100">
                  📎 เปิดไฟล์
                </a>
              <?php else: ?>
                <span class="text-xs text-gray-400">—</span>
              <?php endif; ?>
            </td>
            <td class="p-2 border whitespace-nowrap">
              <a href="kpi_template_manage.php?edit=<?php echo (int)$r['id']; ?>"
                 class="px-2 py-1 bg-yellow-500 hover:bg-yellow-600 text-white rounded text-xs">
                Edit
              </a>
              <form method="post" class="inline" onsubmit="return confirm('ยืนยันลบแม่แบบนี้?');">
                <input type="hidden" name="csrf_token" value="<?php echo h(csrf_token()); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="delete_id" value="<?php echo (int)$r['id']; ?>">
                <button type="submit"
                        class="px-2 py-1 bg-red-600 hover:bg-red-700 text-white rounded text-xs">
                  Delete
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($list_total_pages > 1): ?>
    <div class="mt-4 flex flex-col md:flex-row md:items-center md:justify-between gap-3 text-sm">
      <div class="text-gray-500">
        หน้า <?php echo (int)$list_page; ?> / <?php echo (int)$list_total_pages; ?>
      </div>
      <div class="flex gap-2">
        <?php
          $prev_page = $list_page > 1 ? $list_page - 1 : 1;
          $next_page = $list_page < $list_total_pages ? $list_page + 1 : $list_total_pages;
          $base_qs = 'list_q=' . urlencode($list_q) . '&per_page=' . (int)$list_pp;
        ?>
        <a href="kpi_template_manage.php?<?php echo $base_qs; ?>&page=<?php echo (int)$prev_page; ?>"
           class="px-3 py-2 rounded border <?php echo ($list_page <= 1 ? 'pointer-events-none opacity-50' : ''); ?>">
          ก่อนหน้า
        </a>
        <a href="kpi_template_manage.php?<?php echo $base_qs; ?>&page=<?php echo (int)$next_page; ?>"
           class="px-3 py-2 rounded border <?php echo ($list_page >= $list_total_pages ? 'pointer-events-none opacity-50' : ''); ?>">
          ถัดไป
        </a>
      </div>
    </div>
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>

<script>
// Choices.js + เติม Mission / Issue อัตโนมัติ
document.addEventListener('DOMContentLoaded', function () {
  var sel  = document.getElementById('strategySelect');
  var mIn  = document.getElementById('missionInput');
  var mDis = document.getElementById('missionDisplay');
  var sIn  = document.getElementById('strategicIssueInput');
  var sDis = document.getElementById('strategicIssueDisplay');
  if (sel && mIn && mDis && sIn && sDis) {
    var choices = new Choices(sel, {
      searchEnabled: true,
      searchPlaceholderValue: 'พิมพ์เพื่อค้นหากลยุทธ์...',
      shouldSort: false,
      itemSelectText: '',
      placeholder: true
    });

    function applyFromSelected(){
      var opt = sel.options[sel.selectedIndex] || null;
      if (!opt || !opt.getAttribute) return;
      var m  = opt.getAttribute('data-mission') || '';
      var si = opt.getAttribute('data-issue')   || '';
      mIn.value  = m;
      mDis.value = m;
      sIn.value  = si;
      sDis.value = si;
    }
    sel.addEventListener('change', applyFromSelected);
    applyFromSelected();
  }
});
</script>
</body>
</html>

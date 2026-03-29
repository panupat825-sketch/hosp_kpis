<?php
// missions.php — จัดการพันธกิจ / เป้าประสงค์ (tb_missions)

session_start();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/auth.php';
require_login();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$message = '';

// ---------- ข้อความจาก redirect ----------
if (isset($_GET['message'])) {
    $message = trim($_GET['message']);
}

/* ---------- ดึงรายการประเด็นยุทธศาสตร์ (ใช้เป็น master dropdown/datalist) ---------- */
$strategic_issues_list = array();
$sql_issues = "SELECT name FROM tb_strategic_issues ORDER BY name ASC";
if ($res_issues = mysqli_query($conn, $sql_issues)) {
    while ($issue = mysqli_fetch_assoc($res_issues)) {
        $strategic_issues_list[] = $issue['name'];
    }
    mysqli_free_result($res_issues);
}

/* ---------- ตัวกรองรายการตามประเด็นยุทธศาสตร์ ---------- */
$filter_issue = isset($_GET['filter_issue']) ? trim($_GET['filter_issue']) : '';

/* ---------- ตรวจสอบการลบพันธกิจ / เป้าประสงค์ ---------- */
if (isset($_GET['delete'])) {
    $delete_id = (int)$_GET['delete'];
    if ($delete_id > 0) {
        $sql_delete = "DELETE FROM tb_missions WHERE id = ?";
        if ($st = mysqli_prepare($conn, $sql_delete)) {
            mysqli_stmt_bind_param($st, "i", $delete_id);
            if (mysqli_stmt_execute($st)) {
                $message = 'ลบพันธกิจ/เป้าประสงค์เรียบร้อยแล้ว';
            } else {
                $message = 'ไม่สามารถลบพันธกิจ/เป้าประสงค์ได้: ' . mysqli_error($conn);
            }
            mysqli_stmt_close($st);
        }
    }
    header("Location: missions.php?message=" . urlencode($message));
    exit();
}

/* ---------- เตรียมข้อมูลเริ่มต้นสำหรับฟอร์ม ---------- */
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$mission_data = array(
    'id'             => 0,
    'name'           => '',
    'strategic_issue'=> '',
    'description'    => ''
);

/* ---------- โหลดข้อมูลพันธกิจที่จะแก้ไข (ถ้ามี) ---------- */
if ($edit_id > 0) {
    $sql = "SELECT id, name, strategic_issue, description FROM tb_missions WHERE id = ? LIMIT 1";
    if ($st = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($st, "i", $edit_id);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        if ($res && $row = mysqli_fetch_assoc($res)) {
            $mission_data = $row;
        } else {
            $message = 'ไม่พบข้อมูลพันธกิจ/เป้าประสงค์ที่เลือก';
        }
        mysqli_stmt_close($st);
    }
}

/* ---------- เพิ่ม / ปรับปรุง พันธกิจ / เป้าประสงค์ ---------- */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $id             = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $name           = isset($_POST['name']) ? trim($_POST['name']) : '';
    $strategic_issue= isset($_POST['strategic_issue']) ? trim($_POST['strategic_issue']) : '';
    $description    = isset($_POST['description']) ? trim($_POST['description']) : '';

    if ($name === '' || $strategic_issue === '') {
        $message = 'กรุณากรอกชื่อพันธกิจ/เป้าประสงค์ และเลือกประเด็นยุทธศาสตร์';
    } else {
        if ($id > 0) {
            // UPDATE
            $sql_update = "UPDATE tb_missions
                           SET name = ?, strategic_issue = ?, description = ?
                           WHERE id = ?";
            if ($st = mysqli_prepare($conn, $sql_update)) {
                mysqli_stmt_bind_param($st, "sssi", $name, $strategic_issue, $description, $id);
                if (mysqli_stmt_execute($st)) {
                    $message = 'ปรับปรุงพันธกิจ/เป้าประสงค์เรียบร้อยแล้ว';
                } else {
                    $message = 'ไม่สามารถปรับปรุงพันธกิจ/เป้าประสงค์ได้: ' . mysqli_error($conn);
                }
                mysqli_stmt_close($st);
            }
        } else {
            // INSERT
            $sql_insert = "INSERT INTO tb_missions (name, strategic_issue, description)
                           VALUES (?, ?, ?)";
            if ($st = mysqli_prepare($conn, $sql_insert)) {
                mysqli_stmt_bind_param($st, "sss", $name, $strategic_issue, $description);
                if (mysqli_stmt_execute($st)) {
                    $message = 'เพิ่มพันธกิจ/เป้าประสงค์ใหม่เรียบร้อยแล้ว';
                } else {
                    $message = 'ไม่สามารถเพิ่มพันธกิจ/เป้าประสงค์ได้: ' . mysqli_error($conn);
                }
                mysqli_stmt_close($st);
            }
        }
    }

    header("Location: missions.php?message=" . urlencode($message));
    exit();
}

/* ---------- ดึงรายการพันธกิจทั้งหมด (ตามตัวกรอง) ---------- */
$missions = array();
$sql = "SELECT id, name, strategic_issue, description
        FROM tb_missions";
if ($filter_issue !== '') {
    $esc = mysqli_real_escape_string($conn, $filter_issue);
    $sql .= " WHERE strategic_issue = '{$esc}'";
}
$sql .= " ORDER BY strategic_issue, name";

if ($res = mysqli_query($conn, $sql)) {
    while ($row = mysqli_fetch_assoc($res)) {
        $missions[] = $row;
    }
    mysqli_free_result($res);
}
mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการพันธกิจ / เป้าประสงค์ | ระบบบริหารตัวชี้วัด KPI โรงพยาบาลศรีรัตนะ</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 min-h-screen">

  <?php
    // Navbar กลาง
    $active_nav = 'kpi_template_manage';
    include __DIR__ . '/navbar_kpi.php';
  ?>

  <!-- MAIN CONTENT -->
  <div class="w-full bg-white/95 p-6 rounded-3xl shadow-xl shadow-slate-200/70 border border-slate-200">

    <!-- Header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-5 border-b border-slate-200 pb-4">
      <div>
        <h1 class="text-xl md:text-2xl font-semibold text-gray-900">
          📜 จัดการพันธกิจ / เป้าประสงค์ (Missions)
        </h1>
        <p class="text-sm text-gray-500 mt-1">
          ใช้กำหนด “เป้าประสงค์ / พันธกิจ” ภายใต้แต่ละประเด็นยุทธศาสตร์ เพื่อเชื่อมโยงกับตัวชี้วัด (KPI)
        </p>
      </div>
    </div>

    <?php if ($message !== ''): ?>
      <div class="mb-5 px-4 py-3 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm shadow-sm shadow-emerald-100">
        <?php echo h($message); ?>
      </div>
    <?php endif; ?>

    <!-- ฟอร์มเพิ่ม / แก้ไข พันธกิจ -->
    <div class="mb-6 p-5 rounded-2xl border border-slate-200 bg-slate-50/80 shadow-inner shadow-slate-100">
      <h2 class="text-lg font-semibold mb-3 text-gray-800">
        <?php echo $mission_data['id'] ? '✏️ แก้ไขพันธกิจ / เป้าประสงค์' : '➕ เพิ่มพันธกิจ / เป้าประสงค์ใหม่'; ?>
      </h2>

      <form method="POST" class="space-y-4">
        <input type="hidden" name="id" value="<?php echo (int)$mission_data['id']; ?>">

        <div>
          <label class="block font-semibold text-gray-700 mb-1">
            ชื่อพันธกิจ / เป้าประสงค์
            <span class="text-xs text-gray-500">
              (เช่น เป้าประสงค์ที่ 1 ผู้ป่วยปลอดภัย ไม่มีภาวะแทรกซ้อน PCT)
            </span>
          </label>
          <input type="text"
                 name="name"
                 class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200"
                 value="<?php echo h($mission_data['name']); ?>"
                 required>
        </div>

        <!-- เปลี่ยนเป็น input + datalist ให้พิมพ์ค้นหาได้ -->
        <div>
          <label class="block font-semibold text-gray-700 mb-1">
            ประเด็นยุทธศาสตร์ (Strategic Issue)
          </label>
          <input
            type="text"
            name="strategic_issue"
            list="strategic_issue_list"
            class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200"
            placeholder="เริ่มพิมพ์ชื่อประเด็นยุทธศาสตร์ เช่น ประเด็นยุทธศาสตร์ที่ 1 ..."
            value="<?php echo h($mission_data['strategic_issue']); ?>"
            required
          >
          <datalist id="strategic_issue_list">
            <?php foreach ($strategic_issues_list as $issue_name): ?>
              <option value="<?php echo h($issue_name); ?>"></option>
            <?php endforeach; ?>
          </datalist>
          <p class="text-xs text-gray-400 mt-1">
            ใช้สำหรับเลือกประเด็นยุทธศาสตร์ที่สอดคล้องกับเป้าประสงค์นี้ (ไม่ถูกบังคับจากฐานข้อมูลโดยตรง)
          </p>
        </div>

        <div>
          <label class="block font-semibold text-gray-700 mb-1">
            กลยุทธ์ / คำอธิบาย (Description / Strategies)
          </label>
          <textarea name="description"
                    rows="4"
                    class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200"
                    placeholder="เช่น กลยุทธ์: 1) พัฒนาระบบการดูแลผู้ป่วยตามมาตรฐาน 2) พัฒนาสมรรถนะบุคลากร 3) พัฒนาระบบสนับสนุนบริการ"><?php
            echo h($mission_data['description']);
          ?></textarea>
        </div>

        <div class="flex flex-wrap gap-2">
          <button type="submit"
                  class="px-4 py-2 bg-blue-700 hover:bg-blue-800 text-white rounded-xl text-sm shadow-sm shadow-blue-200">
            <?php echo $mission_data['id'] ? 'บันทึกการแก้ไข' : 'เพิ่มพันธกิจ / เป้าประสงค์'; ?>
          </button>
          <?php if ($mission_data['id']): ?>
            <a href="missions.php"
               class="px-4 py-2 bg-slate-700 hover:bg-slate-800 text-white rounded-xl text-sm shadow-sm shadow-slate-200">
              ยกเลิก
            </a>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <!-- รายการพันธกิจ -->
    <div>
      <div class="flex items-center justify-between mb-3">
        <h2 class="text-lg font-semibold text-gray-800">
          รายการพันธกิจ / เป้าประสงค์ทั้งหมด
        </h2>

        <!-- ตัวกรองประเด็นยุทธศาสตร์ -->
        <form method="get" class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 text-sm rounded-2xl border border-slate-200 bg-slate-50/80 p-2 shadow-inner shadow-slate-100">
          <label class="text-gray-700">ประเด็นยุทธศาสตร์:</label>
          <select name="filter_issue" class="border border-slate-300 rounded-xl px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200">
            <option value="">ทั้งหมด</option>
            <?php foreach ($strategic_issues_list as $issue_name): ?>
              <option value="<?php echo h($issue_name); ?>"
                <?php echo ($filter_issue === $issue_name ? 'selected' : ''); ?>>
                <?php echo h($issue_name); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <button type="submit"
                  class="px-3 py-2 bg-slate-800 text-white rounded-xl hover:bg-slate-900 shadow-sm shadow-slate-200">
            กรอง
          </button>
          <?php if ($filter_issue !== ''): ?>
            <a href="missions.php"
               class="px-3 py-2 bg-white border border-slate-300 text-gray-800 rounded-xl hover:bg-gray-50">
              ล้างตัวกรอง
            </a>
          <?php endif; ?>
        </form>
      </div>

      <?php if (empty($missions)): ?>
        <div class="p-5 text-gray-500 text-sm border border-slate-200 rounded-2xl bg-white shadow-sm shadow-slate-200/70">
          ยังไม่มีพันธกิจ / เป้าประสงค์ในระบบ
        </div>
      <?php else: ?>
        <div class="overflow-x-auto rounded-2xl border border-slate-200 shadow-sm shadow-slate-200/70">
          <table class="min-w-full text-sm border-collapse">
            <thead class="bg-slate-100 text-gray-700">
              <tr>
                <th class="border px-3 py-2 text-left w-12">ลำดับ</th>
                <th class="border px-3 py-2 text-left w-72">ชื่อพันธกิจ / เป้าประสงค์</th>
                <th class="border px-3 py-2 text-left w-64">ประเด็นยุทธศาสตร์</th>
                <th class="border px-3 py-2 text-left">กลยุทธ์ / คำอธิบาย</th>
                <th class="border px-3 py-2 text-center w-40">จัดการ</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $i = 1;
              $currentIssue = null;
              foreach ($missions as $mission):

                  // ตัดคำอธิบายให้สั้นลง
                  $desc = trim($mission['description']);
                  if ($desc === '') {
                      $shortDesc = '—';
                  } else {
                      if (function_exists('mb_strlen')) {
                          $shortDesc = (mb_strlen($desc, 'UTF-8') > 120)
                            ? mb_substr($desc, 0, 120, 'UTF-8') . '...'
                            : $desc;
                      } else {
                          $shortDesc = (strlen($desc) > 120)
                            ? substr($desc, 0, 120) . '...'
                            : $desc;
                      }
                  }

                  // ถ้าเปลี่ยนประเด็นยุทธศาสตร์ ให้เพิ่มแถวหัวกลุ่ม
                  if ($currentIssue !== $mission['strategic_issue']) {
                      $currentIssue = $mission['strategic_issue'];
                      echo '<tr class="bg-slate-900 text-white">';
                      echo '<td colspan="5" class="px-3 py-2 font-semibold">';
                      echo 'ประเด็นยุทธศาสตร์: ' . h($currentIssue);
                      echo '</td></tr>';
                  }
              ?>
                <tr class="border-t hover:bg-gray-50">
                  <td class="border px-3 py-2 align-top"><?php echo $i++; ?></td>
                  <td class="border px-3 py-2 font-semibold text-gray-900 align-top">
                    <?php echo h($mission['name']); ?>
                  </td>
                  <td class="border px-3 py-2 text-gray-700 align-top">
                    <?php echo h($mission['strategic_issue']); ?>
                  </td>
                  <td class="border px-3 py-2 text-gray-700 align-top">
                    <?php echo h($shortDesc); ?>
                  </td>
                  <td class="border px-3 py-2 text-center align-top">
                    <a href="missions.php?edit=<?php echo (int)$mission['id']; ?>"
                       class="inline-block px-3 py-1 text-xs rounded-lg bg-amber-500 text-white hover:bg-amber-600 mr-1">
                      แก้ไข
                    </a>
                    <a href="missions.php?delete=<?php echo (int)$mission['id']; ?>"
                       onclick="return confirm('ยืนยันลบพันธกิจ / เป้าประสงค์ &quot;<?php echo h($mission['name']); ?>&quot; หรือไม่?');"
                       class="inline-block px-3 py-1 text-xs rounded-lg bg-red-600 text-white hover:bg-red-700">
                      ลบ
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

  </div>
</body>
</html>

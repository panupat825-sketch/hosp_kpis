<?php
// strategic_issues.php — จัดการประเด็นยุทธศาสตร์ (tb_strategic_issues)

session_start();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/auth.php';
require_login();
$u = current_user(); // ยังไม่ได้ใช้ แต่อาจใช้แสดงชื่อผู้ใช้ภายหลังได้

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$message = '';

// ---------- ลบข้อมูล ----------
if (isset($_GET['delete'])) {
    $delete_id = (int)$_GET['delete'];
    if ($delete_id > 0) {
        $sql_delete = "DELETE FROM tb_strategic_issues WHERE id = ?";
        if ($st = mysqli_prepare($conn, $sql_delete)) {
            mysqli_stmt_bind_param($st, "i", $delete_id);
            if (mysqli_stmt_execute($st)) {
                $message = 'ลบประเด็นยุทธศาสตร์เรียบร้อยแล้ว';
            } else {
                $message = 'ไม่สามารถลบประเด็นยุทธศาสตร์ได้: ' . mysqli_error($conn);
            }
            mysqli_stmt_close($st);
        }
    }
    header("Location: strategic_issues.php?message=" . urlencode($message));
    exit();
}

// ---------- เพิ่ม / ปรับปรุง ----------
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $id          = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $name        = isset($_POST['name']) ? trim($_POST['name']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';

    if ($name === '') {
        $message = 'กรุณากรอกชื่อประเด็นยุทธศาสตร์';
    } else {
        if ($id > 0) {
            // อัปเดต
            $sql_update = "UPDATE tb_strategic_issues
                           SET name = ?, description = ?
                           WHERE id = ?";
            if ($st = mysqli_prepare($conn, $sql_update)) {
                mysqli_stmt_bind_param($st, "ssi", $name, $description, $id);
                if (mysqli_stmt_execute($st)) {
                    $message = 'ปรับปรุงประเด็นยุทธศาสตร์เรียบร้อยแล้ว';
                } else {
                    $message = 'ไม่สามารถปรับปรุงได้: ' . mysqli_error($conn);
                }
                mysqli_stmt_close($st);
            }
        } else {
            // เพิ่มใหม่
            $sql_insert = "INSERT INTO tb_strategic_issues (name, description)
                           VALUES (?, ?)";
            if ($st = mysqli_prepare($conn, $sql_insert)) {
                mysqli_stmt_bind_param($st, "ss", $name, $description);
                if (mysqli_stmt_execute($st)) {
                    $message = 'เพิ่มประเด็นยุทธศาสตร์ใหม่เรียบร้อยแล้ว';
                } else {
                    $message = 'ไม่สามารถเพิ่มประเด็นยุทธศาสตร์ได้: ' . mysqli_error($conn);
                }
                mysqli_stmt_close($st);
            }
        }
    }

    header("Location: strategic_issues.php?message=" . urlencode($message));
    exit();
}

// ---------- รับข้อความจาก redirect ----------
if (isset($_GET['message'])) {
    $message = trim($_GET['message']);
}

// ---------- ดึงข้อมูลทั้งหมด ----------
$strategic_issues = array();
$sql = "
  SELECT id,
         name,
         description
  FROM tb_strategic_issues
  ORDER BY id ASC
";
if ($res = mysqli_query($conn, $sql)) {
    while ($r = mysqli_fetch_assoc($res)) {
        $strategic_issues[] = $r;
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
    <title>จัดการประเด็นยุทธศาสตร์ | ระบบบริหารตัวชี้วัด KPI โรงพยาบาลศรีรัตนะ</title>
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
          🧭 จัดการประเด็นยุทธศาสตร์ (Strategic Issues)
        </h1>
        <p class="text-sm text-gray-500 mt-1">
          ใช้กำหนดกรอบประเด็นยุทธศาสตร์หลักของโรงพยาบาล เพื่อเชื่อมโยงกับพันธกิจ / เป้าประสงค์ และตัวชี้วัด (KPI)
        </p>
      </div>
    </div>

    <?php if ($message !== ''): ?>
      <div class="mb-5 px-4 py-3 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm shadow-sm shadow-emerald-100">
        <?php echo h($message); ?>
      </div>
    <?php endif; ?>

    <!-- ฟอร์มเพิ่ม/แก้ไข (ใช้ฟอร์มเดียว, แก้ไขผ่าน JS กรอกค่า) -->
    <div class="mb-6 p-5 rounded-2xl border border-slate-200 bg-slate-50/80 shadow-inner shadow-slate-100">
      <h2 class="text-lg font-semibold mb-3 text-gray-800">
        ➕ เพิ่ม / แก้ไขประเด็นยุทธศาสตร์
      </h2>
      <form method="POST" class="space-y-3">
        <input type="hidden" name="id" id="issue_id">

        <div>
          <label class="block text-sm text-gray-700 mb-1">ชื่อประเด็นยุทธศาสตร์</label>
          <input type="text"
                 name="name"
                 id="issue_name"
                 class="w-full border border-slate-300 rounded-xl px-3 py-2.5 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200"
                 placeholder="เช่น ประเด็นยุทธศาสตร์ที่ 1 พัฒนาระบบบริการสุขภาพให้ได้มาตรฐานสากล"
                 required>
        </div>

        <div>
          <label class="block text-sm text-gray-700 mb-1">คำอธิบาย (ถ้ามี)</label>
          <textarea name="description"
                    id="issue_description"
                    rows="3"
                    class="w-full border border-slate-300 rounded-xl px-3 py-2.5 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200"
                    placeholder="ระบุขอบเขต, แนวทาง หรือกรอบการดำเนินงานของประเด็นยุทธศาสตร์นี้ เช่น กลุ่มบริการที่เกี่ยวข้อง หรือเป้าหมายหลัก"></textarea>
        </div>

        <div class="flex flex-wrap gap-2">
          <button type="submit"
                  class="px-4 py-2 bg-blue-700 text-white rounded-xl text-sm hover:bg-blue-800 shadow-sm shadow-blue-200">
            บันทึกข้อมูล
          </button>
          <button type="reset"
                  onclick="clearIssueForm();"
                  class="px-4 py-2 bg-slate-700 text-white rounded-xl text-sm hover:bg-slate-800 shadow-sm shadow-slate-200">
            ล้างฟอร์ม
          </button>
        </div>
      </form>
    </div>

    <!-- ตารางรายการ -->
    <div>
      <h2 class="text-lg font-semibold mb-3 text-gray-800">
        รายการประเด็นยุทธศาสตร์ทั้งหมด
      </h2>

      <?php if (empty($strategic_issues)): ?>
        <div class="p-5 text-gray-500 text-sm border border-slate-200 rounded-2xl bg-white shadow-sm shadow-slate-200/70">
          ยังไม่มีประเด็นยุทธศาสตร์ในระบบ
        </div>
      <?php else: ?>
        <div class="overflow-x-auto rounded-2xl border border-slate-200 shadow-sm shadow-slate-200/70">
          <table class="min-w-full text-sm border-collapse">
            <thead class="bg-slate-100 text-gray-700">
              <tr>
                <th class="px-3 py-2 text-left w-16">ลำดับ</th>
                <th class="px-3 py-2 text-left w-72">ชื่อประเด็นยุทธศาสตร์</th>
                <th class="px-3 py-2 text-left">คำอธิบาย</th>
                <th class="px-3 py-2 text-center w-40">จัดการ</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $i = 1;
              foreach ($strategic_issues as $issue):
                $desc = trim($issue['description']);
                if ($desc === '') {
                    $shortDesc = '—';
                } else {
                    if (function_exists('mb_strlen')) {
                        $shortDesc = (mb_strlen($desc, 'UTF-8') > 80)
                          ? mb_substr($desc, 0, 80, 'UTF-8') . '...'
                          : $desc;
                    } else {
                        $shortDesc = (strlen($desc) > 80)
                          ? substr($desc, 0, 80) . '...'
                          : $desc;
                    }
                }
              ?>
                <tr class="border-t hover:bg-gray-50">
                  <td class="px-3 py-2"><?php echo $i++; ?></td>
                  <td class="px-3 py-2 font-semibold text-gray-900">
                    <?php echo h($issue['name']); ?>
                  </td>
                  <td class="px-3 py-2 text-gray-700">
                    <?php echo h($shortDesc); ?>
                  </td>
                  <td class="px-3 py-2 text-center">
                    <button
                      type="button"
                      class="inline-block px-3 py-1 text-xs rounded-lg bg-amber-500 text-white hover:bg-amber-600 mr-1"
                      onclick='editIssue(
                        <?php echo (int)$issue["id"]; ?>,
                        <?php echo json_encode($issue["name"]); ?>,
                        <?php echo json_encode($issue["description"]); ?>
                      )'>
                      แก้ไข
                    </button>
                    <a href="strategic_issues.php?delete=<?php echo (int)$issue['id']; ?>"
                       onclick="return confirm('ยืนยันลบประเด็นยุทธศาสตร์ &quot;<?php echo h($issue['name']); ?>&quot; หรือไม่?');"
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

  <script>
    function editIssue(id, name, description) {
      document.getElementById("issue_id").value = id || '';
      document.getElementById("issue_name").value = name || '';
      document.getElementById("issue_description").value = description || '';
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    function clearIssueForm(){
      document.getElementById("issue_id").value = '';
      document.getElementById("issue_name").value = '';
      document.getElementById("issue_description").value = '';
    }
  </script>

</body>
</html>

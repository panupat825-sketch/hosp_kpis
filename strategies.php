<?php
// strategies.php — จัดการกลยุทธ์ (tb_strategies)

session_start();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/auth.php';
require_login();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$message = '';

// ---------- รับ message จาก redirect ----------
if (isset($_GET['message'])) {
    $message = trim($_GET['message']);
}

/* ---------- โหลด Master: พันธกิจ / เป้าประสงค์ ---------- */
// ดึงพันธกิจทั้งหมด (มีชื่อประเด็นยุทธศาสตร์ติดมาด้วยใน field strategic_issue)
$missions = array();
$sql_mis = "SELECT id, name, strategic_issue FROM tb_missions ORDER BY strategic_issue, name";
if ($res_mis = mysqli_query($conn, $sql_mis)) {
    while ($r = mysqli_fetch_assoc($res_mis)) {
        $missions[] = $r;
    }
    mysqli_free_result($res_mis);
}

/* ---------- ลบกลยุทธ์ ---------- */
if (isset($_GET['delete'])) {
    $delete_id = (int)$_GET['delete'];
    if ($delete_id > 0) {
        $sql_delete = "DELETE FROM tb_strategies WHERE id = ?";
        if ($st = mysqli_prepare($conn, $sql_delete)) {
            mysqli_stmt_bind_param($st, "i", $delete_id);
            if (mysqli_stmt_execute($st)) {
                $message = 'ลบกลยุทธ์เรียบร้อยแล้ว';
            } else {
                $message = 'ไม่สามารถลบกลยุทธ์ได้: ' . mysqli_error($conn);
            }
            mysqli_stmt_close($st);
        }
    }
    header("Location: strategies.php?message=" . urlencode($message));
    exit();
}

/* ---------- เตรียมค่าเริ่มต้นฟอร์ม ---------- */
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$strategy_data = array(
    'id'          => 0,
    'mission_id'  => 0,
    'name'        => '',
    'description' => ''
);

/* ---------- โหลดข้อมูลกลยุทธ์ที่จะ edit ---------- */
if ($edit_id > 0) {
    $sql = "SELECT id, mission_id, name, description FROM tb_strategies WHERE id = ? LIMIT 1";
    if ($st = mysqli_prepare($conn, $sql)) {
        mysqli_stmt_bind_param($st, "i", $edit_id);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        if ($res && $row = mysqli_fetch_assoc($res)) {
            $strategy_data = $row;
        } else {
            $message = 'ไม่พบข้อมูลกลยุทธ์ที่เลือก';
        }
        mysqli_stmt_close($st);
    }
}

/* ---------- บันทึก (เพิ่ม / แก้ไข) กลยุทธ์ ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id          = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $mission_id  = isset($_POST['mission_id']) ? (int)$_POST['mission_id'] : 0;
    $name        = isset($_POST['name']) ? trim($_POST['name']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';

    if ($name === '' || $mission_id <= 0) {
        $message = 'กรุณากรอกชื่อกลยุทธ์และเลือกเป้าประสงค์ / พันธกิจ';
    } else {
        if ($id > 0) {
            // UPDATE
            $sql_up = "UPDATE tb_strategies
                       SET mission_id = ?, name = ?, description = ?
                       WHERE id = ?";
            if ($st = mysqli_prepare($conn, $sql_up)) {
                mysqli_stmt_bind_param($st, "issi", $mission_id, $name, $description, $id);
                if (mysqli_stmt_execute($st)) {
                    $message = 'ปรับปรุงกลยุทธ์เรียบร้อยแล้ว';
                } else {
                    $message = 'ไม่สามารถปรับปรุงกลยุทธ์ได้: ' . mysqli_error($conn);
                }
                mysqli_stmt_close($st);
            }
        } else {
            // INSERT
            $sql_in = "INSERT INTO tb_strategies (mission_id, name, description)
                       VALUES (?, ?, ?)";
            if ($st = mysqli_prepare($conn, $sql_in)) {
                mysqli_stmt_bind_param($st, "iss", $mission_id, $name, $description);
                if (mysqli_stmt_execute($st)) {
                    $message = 'เพิ่มกลยุทธ์ใหม่เรียบร้อยแล้ว';
                } else {
                    $message = 'ไม่สามารถเพิ่มกลยุทธ์ได้: ' . mysqli_error($conn);
                }
                mysqli_stmt_close($st);
            }
        }
    }

    header("Location: strategies.php?message=" . urlencode($message));
    exit();
}

/* ---------- ดึงรายการกลยุทธ์ทั้งหมด พร้อมชื่อพันธกิจ+ประเด็นยุทธศาสตร์ ---------- */
$strategies = array();
$sql_str = "
  SELECT s.id, s.name, s.description,
         m.id   AS mission_id,
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
mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>จัดการกลยุทธ์ (Strategies) | ระบบบริหารตัวชี้วัด KPI โรงพยาบาลศรีรัตนะ</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 min-h-screen">

<?php
  // ใช้เมนูเดียวกับหน้าแม่แบบ KPI
  $active_nav = 'kpi_template_manage';
  include __DIR__ . '/navbar_kpi.php';
?>

<div class="w-full bg-white/95 p-6 rounded-3xl shadow-xl shadow-slate-200/70 border border-slate-200">

  <!-- Header -->
  <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-5 border-b border-slate-200 pb-4">
    <div>
      <h1 class="text-xl md:text-2xl font-semibold text-gray-900">
        🎯 จัดการกลยุทธ์ (Strategies)
      </h1>
      <p class="text-sm text-gray-500 mt-1">
        กลยุทธ์เชื่อมโยงกับ <strong>เป้าประสงค์ / พันธกิจ</strong> (ซึ่งมีประเด็นยุทธศาสตร์พ่วงมาด้วย)
        เพื่อให้เวลาเลือกกลยุทธ์ใน KPI Template รู้ได้ว่ากลยุทธ์นี้อยู่ใต้เป้าประสงค์และประเด็นยุทธศาสตร์ใด
      </p>
    </div>
  </div>

  <?php if ($message !== ''): ?>
    <div class="mb-5 px-4 py-3 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm shadow-sm shadow-emerald-100">
      <?php echo h($message); ?>
    </div>
  <?php endif; ?>

  <!-- ฟอร์มเพิ่ม / แก้ไข กลยุทธ์ -->
  <div class="mb-6 p-5 rounded-2xl border border-slate-200 bg-slate-50/80 shadow-inner shadow-slate-100">
    <h2 class="text-lg font-semibold mb-3 text-gray-800">
      <?php echo $strategy_data['id'] ? '✏️ แก้ไขกลยุทธ์' : '➕ เพิ่มกลยุทธ์ใหม่'; ?>
    </h2>

    <form method="POST" class="space-y-4">
      <input type="hidden" name="id" value="<?php echo (int)$strategy_data['id']; ?>">

      <!-- เลือกเป้าประสงค์ / พันธกิจ (มีช่องค้นหา) -->
      <div>
        <label class="block font-semibold text-gray-700 mb-1">
          เป้าประสงค์ / พันธกิจ ที่กลยุทธ์นี้สังกัด
        </label>

        <!-- ช่องค้นหา -->
        <input  type="text"
                id="mission_search"
                class="w-full p-2.5 mb-2 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200"
                placeholder="พิมพ์ค้นหาเป้าประสงค์ / พันธกิจ เช่น ผู้ป่วย, ประชาชน, คุณภาพ ฯลฯ">

        <!-- select รายการพันธกิจ -->
        <select name="mission_id"
                id="mission_select"
                class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200"
                required>
          <option value="">-- เลือกเป้าประสงค์ / พันธกิจ --</option>
          <?php
            $current_mission_id = (int)$strategy_data['mission_id'];
            foreach ($missions as $m):
              // แสดงชื่อแบบ: เป้าประสงค์ที่ 1 ... — [ประเด็นยุทธศาสตร์ที่ 1 ...]
              $label = $m['name'];
              if ($m['strategic_issue'] !== '') {
                  $label .= ' — [' . $m['strategic_issue'] . ']';
              }
          ?>
            <option value="<?php echo (int)$m['id']; ?>"
              <?php echo ($current_mission_id === (int)$m['id']) ? 'selected' : ''; ?>>
              <?php echo h($label); ?>
            </option>
          <?php endforeach; ?>
        </select>

        <p class="text-xs text-gray-400 mt-1">
          สามารถพิมพ์คำบางส่วน เช่น “ผู้ป่วย”, “ประชาชน”, “คุณภาพ” เพื่อค้นหาและเลือกเป้าประสงค์ / พันธกิจได้รวดเร็ว
        </p>
      </div>

      <div>
        <label class="block font-semibold text-gray-700 mb-1">
          ชื่อกลยุทธ์ (Strategy Name)
        </label>
        <input type="text"
               name="name"
               class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200"
               value="<?php echo h($strategy_data['name']); ?>"
               placeholder="เช่น พัฒนาศักยภาพทีมนำ, พัฒนาสมรรถนะบุคลากร, พัฒนาระบบสนับสนุนบริการ"
               required>
      </div>

      <div>
        <label class="block font-semibold text-gray-700 mb-1">
          รายละเอียด / คำอธิบายเพิ่มเติม (ถ้ามี)
        </label>
        <textarea name="description"
                  rows="3"
                  class="w-full p-2.5 border border-slate-300 rounded-xl text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-200"
                  placeholder="เช่น แนวทางดำเนินงาน ระยะเวลา หรือหน่วยงานรับผิดชอบหลัก"><?php
          echo h($strategy_data['description']);
        ?></textarea>
      </div>

      <div class="flex flex-wrap gap-2">
        <button type="submit"
                class="px-4 py-2 bg-blue-700 hover:bg-blue-800 text-white rounded-xl text-sm shadow-sm shadow-blue-200">
          <?php echo $strategy_data['id'] ? 'บันทึกการแก้ไข' : 'เพิ่มกลยุทธ์'; ?>
        </button>
        <?php if ($strategy_data['id']): ?>
          <a href="strategies.php"
             class="px-4 py-2 bg-slate-700 hover:bg-slate-800 text-white rounded-xl text-sm shadow-sm shadow-slate-200">
            ยกเลิก
          </a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <!-- ตารางรายการกลยุทธ์ -->
  <div>
    <h2 class="text-lg font-semibold mb-3 text-gray-800">
      รายการกลยุทธ์ทั้งหมด
    </h2>

    <?php if (empty($strategies)): ?>
      <div class="p-5 text-gray-500 text-sm border border-slate-200 rounded-2xl bg-white shadow-sm shadow-slate-200/70">
        ยังไม่มีกลยุทธ์ในระบบ
      </div>
    <?php else: ?>
      <div class="overflow-x-auto rounded-2xl border border-slate-200 shadow-sm shadow-slate-200/70">
        <table class="min-w-full text-sm border-collapse">
          <thead class="bg-slate-100 text-gray-700">
            <tr>
              <th class="border px-3 py-2 text-left w-12">ลำดับ</th>
              <th class="border px-3 py-2 text-left w-72">ชื่อกลยุทธ์</th>
              <th class="border px-3 py-2 text-left w-72">เป้าประสงค์ / พันธกิจ</th>
              <th class="border px-3 py-2 text-left w-80">ประเด็นยุทธศาสตร์</th>
              <th class="border px-3 py-2 text-center w-32">จัดการ</th>
            </tr>
          </thead>
          <tbody>
            <?php $i=1; foreach ($strategies as $s): ?>
              <tr class="border-t hover:bg-gray-50">
                <td class="border px-3 py-2"><?php echo $i++; ?></td>
                <td class="border px-3 py-2 font-semibold text-gray-900">
                  <?php echo h($s['name']); ?>
                </td>
                <td class="border px-3 py-2 text-gray-800">
                  <?php echo h($s['mission_name']); ?>
                </td>
                <td class="border px-3 py-2 text-gray-700">
                  <?php echo h($s['strategic_issue']); ?>
                </td>
                <td class="border px-3 py-2 text-center">
                  <a href="strategies.php?edit=<?php echo (int)$s['id']; ?>"
                     class="inline-block px-3 py-1 text-xs rounded-lg bg-amber-500 text-white hover:bg-amber-600 mr-1">
                    แก้ไข
                  </a>
                  <a href="strategies.php?delete=<?php echo (int)$s['id']; ?>"
                     onclick="return confirm('ยืนยันลบกลยุทธ์ &quot;<?php echo h($s['name']); ?>&quot; หรือไม่?');"
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
// ค้นหา / กรองรายการเป้าประสงค์ / พันธกิจ แบบพิมพ์แล้วให้มีผลทันที
(function () {
  var missionSearch = document.getElementById('mission_search');
  var missionSelect = document.getElementById('mission_select');
  if (!missionSearch || !missionSelect) return;

  missionSearch.addEventListener('input', function () {
    var q = (this.value || '').toLowerCase();
    var opts = missionSelect.options;
    var firstVisible = '';

    for (var i = 0; i < opts.length; i++) {
      var opt = opts[i];
      if (!opt.value) {
        // แถว "-- เลือก..." แสดงไว้เสมอ
        opt.style.display = '';
        continue;
      }
      var txt = (opt.text || '').toLowerCase();
      if (q === '' || txt.indexOf(q) !== -1) {
        opt.style.display = '';
        if (!firstVisible) firstVisible = opt.value;
      } else {
        opt.style.display = 'none';
      }
    }

    if (q === '') {
      // ถ้าลบข้อความค้นหา ให้ reset การเลือก
      missionSelect.value = '';
    } else if (firstVisible) {
      // auto select ตัวแรกที่ match
      missionSelect.value = firstVisible;
    }
  });
})();
</script>

</body>
</html>

<?php
// categories.php
// จัดการหมวดหมู่ตัวชี้วัด (tb_categories)

session_start();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/auth.php';
require_login();

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$message = '';

// ---------- จัดการ action จาก POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'add') {
        $name = isset($_POST['name']) ? trim($_POST['name']) : '';
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';

        if ($name !== '') {
            if ($st = mysqli_prepare($conn, "INSERT INTO tb_categories (name, description) VALUES (?, ?)")) {
                $descParam = ($description !== '' ? $description : NULL);
                mysqli_stmt_bind_param($st, "ss", $name, $descParam);
                if (mysqli_stmt_execute($st)) {
                    $message = 'เพิ่มหมวดหมู่เรียบร้อยแล้ว';
                } else {
                    $code = mysqli_errno($conn);
                    if ($code == 1062) {
                        $message = 'ไม่สามารถเพิ่มได้: มีชื่อหมวดหมู่นี้อยู่แล้วในระบบ';
                    } else {
                        $message = 'ไม่สามารถเพิ่มหมวดหมู่ได้: ' . mysqli_error($conn);
                    }
                }
                mysqli_stmt_close($st);
            }
        } else {
            $message = 'กรุณากรอกชื่อหมวดหมู่';
        }

    } elseif ($action === 'update') {
        $id   = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $name = isset($_POST['name']) ? trim($_POST['name']) : '';
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';

        if ($id > 0 && $name !== '') {
            if ($st = mysqli_prepare($conn, "UPDATE tb_categories SET name=?, description=? WHERE id=?")) {
                $descParam = ($description !== '' ? $description : NULL);
                mysqli_stmt_bind_param($st, "ssi", $name, $descParam, $id);
                if (mysqli_stmt_execute($st)) {
                    $message = 'ปรับปรุงหมวดหมู่เรียบร้อยแล้ว';
                } else {
                    $code = mysqli_errno($conn);
                    if ($code == 1062) {
                        $message = 'ไม่สามารถบันทึกได้: มีชื่อหมวดหมู่นี้อยู่แล้วในระบบ';
                    } else {
                        $message = 'ไม่สามารถปรับปรุงหมวดหมู่ได้: ' . mysqli_error($conn);
                    }
                }
                mysqli_stmt_close($st);
            }
        } else {
            $message = 'ข้อมูลไม่ครบถ้วน';
        }

    } elseif ($action === 'delete') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id > 0) {
            if ($st = mysqli_prepare($conn, "DELETE FROM tb_categories WHERE id=?")) {
                mysqli_stmt_bind_param($st, "i", $id);
                if (mysqli_stmt_execute($st)) {
                    $message = 'ลบหมวดหมู่เรียบร้อยแล้ว';
                } else {
                    $message = 'ไม่สามารถลบหมวดหมู่ได้: ' . mysqli_error($conn);
                }
                mysqli_stmt_close($st);
            }
        }
    }

    header("Location: categories.php?msg=" . urlencode($message));
    exit();
}

// ---------- รับข้อความจาก redirect ----------
if (isset($_GET['msg'])) {
    $message = trim($_GET['msg']);
}

// ---------- โหมดแก้ไข ----------
$edit_id  = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$edit_row = null;
if ($edit_id > 0) {
    if ($st = mysqli_prepare($conn, "SELECT id, name, description FROM tb_categories WHERE id=?")) {
        mysqli_stmt_bind_param($st, "i", $edit_id);
        mysqli_stmt_execute($st);
        $res = mysqli_stmt_get_result($st);
        if ($res && $row = mysqli_fetch_assoc($res)) {
            $edit_row = $row;
        }
        mysqli_stmt_close($st);
    }
}

// ---------- ดึงรายการ categories ทั้งหมด ----------
$categories = array();
$res = mysqli_query($conn, "SELECT id, name, description, created_at FROM tb_categories ORDER BY name ASC");
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $categories[] = $r;
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
  <title>จัดการหมวดหมู่ตัวชี้วัด | Hospital KPI</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen">

  <?php
    // Navbar กลาง
    $active_nav = 'kpi_template_manage';
    include __DIR__ . '/navbar_kpi.php';
  ?>

  <!-- MAIN CONTENT -->
  <div class="max-w-7xl mx-auto bg-white p-6 rounded-lg shadow-lg">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2 mb-4 border-b pb-3">
      <div>
        <h1 class="text-xl md:text-2xl font-semibold text-gray-900">
          จัดการหมวดหมู่ตัวชี้วัด (KPI Categories)
        </h1>
        <p class="text-sm text-gray-500 mt-1">
          ใช้กำหนดหมวดหมู่หลักของตัวชี้วัด เพื่อจัดกลุ่มและรายงานผลให้เป็นระบบมากขึ้น
        </p>
      </div>
    </div>

    <?php if ($message !== ''): ?>
      <div class="mb-4 px-4 py-2 rounded bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm">
        <?php echo h($message); ?>
      </div>
    <?php endif; ?>

    <!-- ฟอร์มเพิ่ม/แก้ไข -->
    <div class="mb-6 p-4 rounded-lg border border-gray-200 bg-gray-50">
      <?php if ($edit_row): ?>
        <h2 class="text-lg font-semibold mb-3 text-gray-800">แก้ไขหมวดหมู่ตัวชี้วัด</h2>
        <form method="post" class="space-y-3">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" value="<?php echo (int)$edit_row['id']; ?>">

          <div>
            <label class="block text-sm text-gray-700 mb-1">ชื่อหมวดหมู่</label>
            <input type="text" name="name" class="w-full border rounded px-3 py-2"
                   value="<?php echo h($edit_row['name']); ?>" required>
          </div>

          <div>
            <label class="block text-sm text-gray-700 mb-1">คำอธิบาย (ถ้ามี)</label>
            <textarea name="description" rows="3"
                      class="w-full border rounded px-3 py-2"><?php echo h($edit_row['description']); ?></textarea>
          </div>

          <div class="flex gap-2">
            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 text-sm">
              บันทึกการแก้ไข
            </button>
            <a href="categories.php" class="px-4 py-2 bg-gray-300 text-gray-800 rounded hover:bg-gray-400 text-sm">
              ยกเลิก
            </a>
          </div>
        </form>
      <?php else: ?>
        <h2 class="text-lg font-semibold mb-3 text-gray-800">เพิ่มหมวดหมู่ตัวชี้วัดใหม่</h2>
        <form method="post" class="space-y-3">
          <input type="hidden" name="action" value="add">

          <div>
            <label class="block text-sm text-gray-700 mb-1">ชื่อหมวดหมู่</label>
            <input type="text" name="name" class="w-full border rounded px-3 py-2"
                   placeholder="เช่น คุณภาพบริการ, ความปลอดภัยผู้ป่วย" required>
          </div>

          <div>
            <label class="block text-sm text-gray-700 mb-1">คำอธิบาย (ถ้ามี)</label>
            <textarea name="description" rows="3"
                      class="w-full border rounded px-3 py-2"
                      placeholder="รายละเอียด / ขอบเขตของหมวดหมู่นี้ (ไม่บังคับกรอก)"></textarea>
          </div>

          <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700 text-sm">
            เพิ่มหมวดหมู่
          </button>
        </form>
      <?php endif; ?>
    </div>

    <!-- ตารางรายการ -->
    <div>
      <h2 class="text-lg font-semibold mb-3 text-gray-800">รายการหมวดหมู่ทั้งหมด</h2>

      <?php if (empty($categories)): ?>
        <div class="p-4 text-gray-500 text-sm border rounded bg-white">
          ยังไม่มีหมวดหมู่ตัวชี้วัดในระบบ
        </div>
      <?php else: ?>
        <div class="overflow-x-auto border rounded">
          <table class="min-w-full text-sm">
            <thead class="bg-gray-100">
              <tr>
                <th class="px-3 py-2 text-left w-16">ลำดับ</th>
                <th class="px-3 py-2 text-left w-64">ชื่อหมวดหมู่</th>
                <th class="px-3 py-2 text-left">คำอธิบาย</th>
                <th class="px-3 py-2 text-left w-40">สร้างเมื่อ</th>
                <th class="px-3 py-2 text-center w-40">จัดการ</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $i = 1;
              foreach ($categories as $cat):
                $desc = trim($cat['description']);
                $shortDesc = ($desc === '' ? '—' : (mb_strlen($desc, 'UTF-8') > 80
                    ? mb_substr($desc, 0, 80, 'UTF-8').'...'
                    : $desc));
              ?>
                <tr class="border-t hover:bg-gray-50">
                  <td class="px-3 py-2"><?php echo $i++; ?></td>
                  <td class="px-3 py-2 font-semibold"><?php echo h($cat['name']); ?></td>
                  <td class="px-3 py-2 text-gray-700"><?php echo h($shortDesc); ?></td>
                  <td class="px-3 py-2 text-gray-500">
                    <?php echo h($cat['created_at']); ?>
                  </td>
                  <td class="px-3 py-2 text-center">
                    <a href="categories.php?edit=<?php echo (int)$cat['id']; ?>"
                       class="inline-block px-3 py-1 text-xs rounded bg-yellow-500 text-white hover:bg-yellow-600">
                      แก้ไข
                    </a>
                    <form method="post" action="categories.php" class="inline-block"
                          onsubmit="return confirm('ยืนยันลบหมวดหมู่ &quot;<?php echo h($cat['name']); ?>&quot; หรือไม่?');">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?php echo (int)$cat['id']; ?>">
                      <button type="submit"
                              class="px-3 py-1 text-xs rounded bg-red-600 text-white hover:bg-red-700">
                        ลบ
                      </button>
                    </form>
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

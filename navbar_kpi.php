<?php
// navbar_kpi.php - shared enterprise navigation shell

if (!function_exists('h')) {
    function h($s)
    {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

$active_nav = isset($active_nav) ? (string)$active_nav : '';

if (!function_exists('kpi_nav_btn')) {
    function kpi_nav_btn($href, $label, $icon, $isActive)
    {
        $base = 'enterprise-nav-chip inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl font-medium text-sm transition-all duration-150 focus:outline-none focus:ring-2 focus:ring-cyan-300 focus:ring-offset-2 focus:ring-offset-slate-950 whitespace-nowrap ';
        $cls = $isActive ? 'is-active' : '';
        echo '<a href="' . h($href) . '" class="' . $base . $cls . '">' . $icon . '<span>' . h($label) . '</span></a>';
    }
}

if (!function_exists('kpi_enterprise_action_link')) {
    function kpi_enterprise_action_link($href, $label, $variant)
    {
        $variantClass = 'enterprise-button-secondary text-sm';
        if ($variant === 'primary') {
            $variantClass = 'enterprise-button-primary text-sm';
        } elseif ($variant === 'danger') {
            $variantClass = 'enterprise-button-danger text-sm';
        }

        return '<a href="' . h($href) . '" class="enterprise-button ' . $variantClass . '">' . h($label) . '</a>';
    }
}

if (!function_exists('kpi_page_header')) {
    function kpi_page_header($title, $subtitle, $crumbs, $actionsHtml)
    {
        if (!is_array($crumbs)) {
            $crumbs = array();
        }

        echo '<section class="w-full px-4 sm:px-6 lg:px-8 mb-5">';
        echo '<div class="enterprise-panel enterprise-glow px-5 py-6 sm:px-7 sm:py-7">';

        if (!empty($crumbs)) {
            echo '<nav aria-label="Breadcrumb" class="mb-3 text-xs sm:text-sm text-slate-500">';
            $last = count($crumbs) - 1;
            foreach ($crumbs as $i => $crumb) {
                if ($i > 0) {
                    echo '<span class="mx-2 text-slate-300">/</span>';
                }
                $label = isset($crumb['label']) ? $crumb['label'] : '';
                $href = isset($crumb['href']) ? $crumb['href'] : '';
                if ($href !== '' && $i !== $last) {
                    echo '<a class="hover:text-slate-700 focus:outline-none focus:underline" href="' . h($href) . '">' . h($label) . '</a>';
                } else {
                    echo '<span class="' . ($i === $last ? 'font-semibold text-slate-700' : '') . '">' . h($label) . '</span>';
                }
            }
            echo '</nav>';
        }

        echo '<div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">';
        echo '<div>';
        echo '<div class="enterprise-kicker mb-3"><span class="inline-flex h-2 w-2 rounded-full bg-teal-500"></span>Enterprise KPI Workspace</div>';
        echo '<h1 class="enterprise-page-title font-semibold tracking-tight">' . h($title) . '</h1>';
        if ($subtitle !== '') {
            echo '<p class="enterprise-page-subtitle mt-3 text-sm sm:text-base">' . h($subtitle) . '</p>';
        }
        echo '</div>';
        if ($actionsHtml !== '') {
            echo '<div class="flex flex-wrap gap-2">' . $actionsHtml . '</div>';
        }
        echo '</div>';
        echo '</div>';
        echo '</section>';
    }
}
?>
<link rel="stylesheet" href="css/enterprise-ui.css">

<nav class="enterprise-nav sticky top-0 z-40 mb-6 text-white shadow-2xl backdrop-blur">
  <div class="w-full px-4 sm:px-6 lg:px-8">
    <div class="flex items-center justify-between gap-3 min-h-[4.75rem] py-3 relative">
      <a href="dashboard.php" class="flex items-center gap-4 font-semibold tracking-wide min-w-0">
        <span class="enterprise-nav-brand-badge inline-flex items-center justify-center w-11 h-11 rounded-2xl text-slate-950 font-extrabold">KPI</span>
        <span class="enterprise-nav-brand min-w-0 leading-tight">
          <span class="block text-[0.68rem] uppercase tracking-[0.24em] text-cyan-200/90">Enterprise Performance</span>
          <span class="block text-sm sm:text-base truncate">โรงพยาบาลศรีรัตนะ</span>
        </span>
      </a>

      <button id="navToggle" class="md:hidden p-2 rounded-xl hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-cyan-300" aria-label="Toggle menu" aria-expanded="false" aria-controls="navMenus">
        <svg viewBox="0 0 24 24" class="w-6 h-6 fill-current">
          <path d="M4 6h16M4 12h16M4 18h16"/>
        </svg>
      </button>

      <div id="navMenus" class="hidden absolute left-0 right-0 top-full mt-2 rounded-3xl border border-white/10 bg-slate-950/95 p-3 shadow-2xl md:static md:mt-0 md:flex md:items-center md:gap-2 md:rounded-none md:border-0 md:bg-transparent md:p-0 md:shadow-none">
        <?php
        kpi_nav_btn('dashboard.php', 'แดชบอร์ดไตรมาส', '📊', $active_nav === 'dashboard_quarter');
        kpi_nav_btn('dashboard_yearly.php', 'แดชบอร์ดรายปี', '📈', $active_nav === 'dashboard_yearly');
        kpi_nav_btn('kpi_instance_manage.php', 'บันทึกผลตัวชี้วัด', '📝', $active_nav === 'instance');
        kpi_nav_btn('kpi_template_manage.php', 'แบบฟอร์มตัวชี้วัด', '📄', $active_nav === 'template');
        kpi_nav_btn('kpi_table.php', 'ตาราง KPI', '📋', $active_nav === 'table');
        ?>

        <div class="relative group">
          <button class="enterprise-nav-chip inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl text-sm font-medium focus:outline-none focus:ring-2 focus:ring-cyan-300 focus:ring-offset-2 focus:ring-offset-slate-950">
            <span>⚙️</span>
            <span>ตั้งค่า</span>
            <svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.24a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z" clip-rule="evenodd"/>
            </svg>
          </button>
          <div class="absolute right-0 mt-2 w-64 rounded-3xl bg-white text-slate-800 shadow-2xl ring-1 ring-black/5 hidden group-hover:block z-50 overflow-hidden">
            <a class="block px-4 py-2.5 hover:bg-slate-100" href="strategic_issues.php">ประเด็นยุทธศาสตร์</a>
            <a class="block px-4 py-2.5 hover:bg-slate-100" href="missions.php">เป้าประสงค์</a>
            <a class="block px-4 py-2.5 hover:bg-slate-100" href="strategies.php">กลยุทธ์</a>
            <a class="block px-4 py-2.5 hover:bg-slate-100" href="fiscal_years.php">ปีงบประมาณ</a>
            <a class="block px-4 py-2.5 hover:bg-slate-100" href="workgroups.php">กลุ่มงาน / หน่วยงาน</a>
            <a class="block px-4 py-2.5 hover:bg-slate-100" href="departments.php">แผนก</a>
            <a class="block px-4 py-2.5 hover:bg-slate-100" href="teams.php">ทีม</a>
            <a class="block px-4 py-2.5 hover:bg-slate-100" href="users.php">ผู้ใช้งาน</a>
            <a class="block px-4 py-2.5 hover:bg-slate-100" href="roles.php">สิทธิ์การใช้งาน</a>
          </div>
        </div>

        <a href="index.php" class="enterprise-nav-chip inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl text-sm font-medium focus:outline-none focus:ring-2 focus:ring-cyan-300 focus:ring-offset-2 focus:ring-offset-slate-950 whitespace-nowrap">
          <span>🏠</span>
          <span>หน้าแรก</span>
        </a>
        <a href="profile.php" class="enterprise-nav-chip inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl text-sm font-medium focus:outline-none focus:ring-2 focus:ring-cyan-300 focus:ring-offset-2 focus:ring-offset-slate-950 whitespace-nowrap">
          <span>👤</span>
          <span>โปรไฟล์</span>
        </a>
        <a href="logout.php" class="enterprise-button enterprise-button-danger text-sm focus:outline-none focus:ring-2 focus:ring-red-300 focus:ring-offset-2 focus:ring-offset-slate-950 whitespace-nowrap">
          ออกจากระบบ
        </a>
      </div>
    </div>
  </div>
</nav>

<script>
  (function () {
    var btn = document.getElementById('navToggle');
    var box = document.getElementById('navMenus');
    if (!btn || !box) {
      return;
    }

    btn.addEventListener('click', function () {
      box.classList.toggle('hidden');
      btn.setAttribute('aria-expanded', box.classList.contains('hidden') ? 'false' : 'true');
    });
  })();
</script>

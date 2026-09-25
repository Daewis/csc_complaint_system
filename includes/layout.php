<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Ensure we have the fresh user record
$user = currentUser();

// Redirect to login if user session is lost but they are on a protected page
if (!$user && !str_contains($_SERVER['PHP_SELF'], 'login.php') && !str_contains($_SERVER['PHP_SELF'], 'register')) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

// Use the function name you have in functions.php
$unreadNotifications = getUnreadCount($user['id']);

// Role-specific task counts
// HOD uses the department name string; Lecturer uses their user ID
$pendingHOD = ($user['is_hod']) ? getPendingHODCount($user['department']) : 0;
$pendingLecturer = ($user['is_lecturer']) ? getPendingLecturerCount((int)$user['id']) : 0;


checkSLAStatus(); // Runs background SLA check on every page load

$pageTitle = $pageTitle ?? 'LASU Result Complaint Portal';
$activeNav = $activeNav ?? '';

/**
 * Helper to generate sidebar links with active state detection
 * Synchronized with the correct local UI styling
 */
function sidebarLink(string $href, string $icon, string $label, bool $badge = false, ?int $count = 0): string {
    $count = $count ?? 0;
    $fullHref = rtrim(BASE_URL, '/') . '/' . ltrim($href, '/');

    // Check if current URL contains the target href to set active class
    $isActive = (basename($_SERVER['PHP_SELF']) === basename(explode('?', $href)[0]));
    $cls = $isActive ? 'bg-[#001e40] text-white shadow-md' : 'text-[#43474f] hover:bg-white/50';

    $badgeHtml = ($badge && $count > 0) ?
        "<span class=\"ml-auto text-[10px] font-black bg-red-500 text-white px-2 py-0.5 rounded-full\">$count</span>" : '';

    return "
    <a href=\"$fullHref\" class=\"flex items-center gap-3 px-4 py-3 rounded-xl transition-all font-semibold text-sm $cls\">
        <span class=\"material-symbols-outlined text-[20px] " . ($isActive ? 'fill-1' : '') . "\">$icon</span>
        <span class=\"flex-1\">$label</span>
        $badgeHtml
    </a>";
}

function sidebarDivider(string $label): string {
    return "<p class=\"px-5 pt-5 pb-1 text-[9px] font-black uppercase tracking-[0.2em] text-[#001e40]/30\">$label</p>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="google-site-verification" content="WsPDbEfWnpQQ2OWRyUrsBZfNmyTrmeXWKwb0QcppGmQ" />
  <title><?= htmlspecialchars($pageTitle) ?> | LASU Result Complaint Portal</title>
  <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            "primary": "#001e40", "secondary-container": "#fecb00", "surface": "#f7f9ff",
            "on-surface": "#0b1d2c", "on-surface-variant": "#43474f"
          },
          fontFamily: { body: ['Inter'] }
        }
      }
    }
  </script>
  <style>
    .fill-1 { font-variation-settings: 'FILL' 1; }
    .material-symbols-outlined { font-variation-settings: 'wght' 500, 'opsz' 24; }
    ::-webkit-scrollbar { width: 5px; }
    ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    .glass-sidebar { background: linear-gradient(180deg, #edf4ff 0%, #f7f9ff 100%); }
  </style>
</head>
<body class="bg-[#f7f9ff] font-body text-on-surface antialiased">

<?php if ($user): ?>

<!-- Mobile Overlay -->
<div id="sidebarOverlay"
     class="fixed inset-0 bg-black/50 z-40 hidden lg:hidden"
     onclick="toggleSidebar()"></div>

<!-- SIDEBAR -->
<aside id="sidebar"
class="
fixed left-0 top-0 h-full w-72
glass-sidebar flex flex-col
border-r border-blue-100
overflow-y-auto
z-50

transform -translate-x-full
lg:translate-x-0
transition-transform duration-300
">

  <!-- Logo -->
  <div class="p-5 mb-2">
    <div class="flex items-center gap-3">
      <img
        src="<?= BASE_URL ?>assets/img/lasu_logo.jpg"
        alt="LASU Logo"
        class="w-11 h-11 rounded-lg object-contain bg-white p-0.5 shadow-sm">

      <div>
        <h1 class="text-md font-black tracking-tighter text-[#001e40] uppercase leading-none">
          LASU Result<br>Complaint Portal
        </h1>

        <p class="text-[9px] uppercase tracking-[0.2em] text-[#43474f] font-bold opacity-50">
          Academic Redress System
        </p>
      </div>
    </div>
  </div>

  <nav class="flex-1 px-4 space-y-1">

    <?php
    $role = strtolower($user['role']);

    if ($role === 'student'): ?>

      <?= sidebarLink('student/dashboard.php','dashboard','My Dashboard') ?>
      <?= sidebarLink('student/new_complaint.php','edit_document','Log Complaint') ?>
      <?= sidebarLink('student/history.php','history','My History') ?>

    <?php elseif ($role === 'staff' || $role === 'admin'): ?>

      <?php if ($role === 'admin'): ?>
        <?= sidebarDivider('Administration') ?>
        <?= sidebarLink('admin/dashboard.php','monitoring','System Overview') ?>
        <?= sidebarLink('admin/import_staff.php','group_add','Staff Whitelist') ?>
        <?= sidebarLink('admin/manage_staff.php','manage_accounts','Manage Faculty') ?>
        <?= sidebarLink('admin/manage_student.php','school','Manage Students') ?>
        <?= sidebarLink('admin/import_courses.php','collections_bookmark','Manage Courses') ?>
      <?php endif; ?>

      <?php if ($user['is_hod']): ?>
        <?= sidebarDivider('HOD Portal') ?>
        <?= sidebarLink('hod/dashboard.php','account_balance','Dashboard',true,$pendingHOD) ?>
        <?= sidebarLink('hod/course_assignments.php','menu_book','Course Assignments') ?>
      <?php endif; ?>

      <?php if ($user['is_level_adviser']): ?>
        <?= sidebarDivider('Level Adviser') ?>
        <?= sidebarLink('level_adviser/dashboard.php','verified_user','Dashboard',true,(int)$unreadNotifications) ?>
        <?= sidebarLink('level_adviser/batch_process.php','dynamic_feed','Batch Process') ?>
      <?php endif; ?>

      <?php if ($user['is_lecturer']): ?>
        <?= sidebarDivider('Lecturer') ?>
        <?= sidebarLink('lecturer/dashboard.php','school','Score Verification',true,$pendingLecturer) ?>
      <?php endif; ?>

    <?php endif; ?>

    <?= sidebarDivider('Account') ?>

    <?= sidebarLink(
      'notifications.php',
      'notifications',
      'Notifications',
      true,
      $unreadNotifications
    ) ?>

    <?= sidebarLink(
      'profile.php',
      'person',
      'My Profile'
    ) ?>

  </nav>

  <!-- User Card -->
  <div class="p-6 mt-auto">

    <div class="bg-white rounded-2xl p-4 mb-4 flex items-center gap-3 shadow-sm">

      <div class="w-10 h-10 rounded-full bg-[#001e40] flex items-center justify-center text-[#fecb00] font-black">
        <?= strtoupper(substr($user['full_name'],0,2)) ?>
      </div>

      <div class="min-w-0">
        <p class="text-[11px] font-black text-[#001e40] truncate">
          <?= strtoupper($user['full_name']) ?>
        </p>

        <span class="text-[9px] font-bold text-blue-700">
          <?= $user['PF_NO'] ?? $user['matric_number'] ?>
        </span>
      </div>

    </div>

    <a href="<?= BASE_URL ?>logout.php"
       class="flex items-center gap-3 px-4 py-3 rounded-xl text-red-600 font-bold hover:bg-red-50">

      <span class="material-symbols-outlined">logout</span>
      Sign Out

    </a>

  </div>

</aside>

<!-- HEADER -->
<header
class="
fixed top-0 right-0 left-0
lg:left-72
h-16 lg:h-20
bg-white/90 backdrop-blur-md
border-b border-gray-100
z-30
">

<div class="h-full flex items-center justify-between px-4 lg:px-10">

  <!-- Left -->
  <div class="flex items-center gap-3">

    <!-- Mobile Menu -->
    <button
      onclick="toggleSidebar()"
      class="lg:hidden p-2 rounded-lg hover:bg-gray-100">

      <span class="material-symbols-outlined">
        menu
      </span>

    </button>

    <div class="h-8 w-1 bg-[#fecb00] rounded-full hidden md:block"></div>

    <h2 class="text-sm md:text-lg font-black text-[#001e40] uppercase italic truncate">
      <?= htmlspecialchars($pageTitle) ?>
    </h2>

  </div>

  <!-- Right -->
  <div class="flex items-center gap-3 lg:gap-6">

    <div class="hidden md:block text-right border-r border-gray-100 pr-6">
      <p class="text-[10px] uppercase font-bold text-gray-400">
        Local Time
      </p>
      <p class="text-xs font-black text-[#001e40]">
        <?= date('H:i') ?> WAT
      </p>
    </div>

    <a href="<?= BASE_URL ?>notifications.php"
       class="relative p-2 bg-[#f7f9ff] rounded-xl">

      <span class="material-symbols-outlined">
        notifications
      </span>

      <?php if ($unreadNotifications > 0): ?>
      <span
      class="absolute -top-1 -right-1
      w-5 h-5 rounded-full
      bg-red-500 text-white
      text-[10px]
      flex items-center justify-center">

      <?= $unreadNotifications ?>

      </span>
      <?php endif; ?>

    </a>

    <a href="<?= BASE_URL ?>profile.php">

      <div class="w-10 h-10 rounded-xl bg-[#edf4ff] flex items-center justify-center">

        <?php if (!empty($user['profile_picture'])): ?>

          <img
          src="<?= BASE_URL . $user['profile_picture'] ?>"
          class="w-full h-full object-cover rounded-xl">

        <?php else: ?>

          <span class="material-symbols-outlined text-[#001e40]">
            account_circle
          </span>

        <?php endif; ?>

      </div>

    </a>

  </div>

</div>
</header>

<!-- MAIN -->
<main
class="
pt-16 lg:pt-20
min-h-screen
lg:ml-72
">

<div class="p-4 lg:p-10">

<?php else: ?>
<main>
<?php endif; ?>

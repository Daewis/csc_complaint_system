<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Restricted to Admin only
requireLogin();
$user = currentUser();
if ($user['role'] !== 'admin') {
    header('Location: ' . BASE_URL . 'dashboard.php');
    exit;
}

$db = getDB();
$success = ''; $error = '';

// ── 1. ACTION: Toggle student active status ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_flag'])) {
    $uid  = (int)$_POST['user_id'];
    $flag = $_POST['toggle_flag'];
    $val  = (int)$_POST['new_value'];

    // Students only have `is_active` to toggle (no role flags like staff).
    $allowedFlags = ['is_active'];

    if (in_array($flag, $allowedFlags)) {
        try {
            $db->prepare("UPDATE users SET $flag = ? WHERE id = ? AND role = 'student'")
               ->execute([$val, $uid]);

            $success = 'Student status updated successfully.';
            logAudit(0, $user['id'], 'admin_toggle_student_flag', getClientIP(),
                "Toggled $flag for student ID $uid to $val");
        } catch (Exception $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}

// ── 2. ACTION: Reset Password ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $uid  = (int)$_POST['user_id'];
    $pass = trim($_POST['new_password'] ?? 'LASU@Change2026');

    if (strlen($pass) >= 6) {
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $db->prepare("UPDATE users SET password_hash=?, must_change_password=1 WHERE id=? AND role='student'")
           ->execute([$hash, $uid]);
        $success = 'Password reset. Student must change it on next login.';
    } else {
        $error = 'Password must be at least 6 characters.';
    }
}

// ── 3. FILTERS & SEARCH ──────────────────────────────────────────────────────
$filterDept  = $_GET['dept']  ?? '';
$filterLevel = $_GET['level'] ?? '';
$search      = trim($_GET['q'] ?? '');

$sql = "SELECT * FROM users WHERE role = 'student'";
$params = [];

if ($filterDept)  { $sql .= ' AND department = ?'; $params[] = $filterDept; }
if ($filterLevel) { $sql .= ' AND level = ?';      $params[] = $filterLevel; }
if ($search) {
    $sql .= ' AND (full_name LIKE ? OR email LIKE ? OR matric_number LIKE ?)';
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}

$sql .= ' ORDER BY department ASC, level ASC, full_name ASC';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

// Unique departments for the filter dropdown
$depts = $db->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND role='student' ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);

// Unique levels for the level filter
$levels = $db->query("SELECT DISTINCT level FROM users WHERE level IS NOT NULL AND role='student' ORDER BY level ASC")->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Manage Students';
$activeNav = 'manage_student.php';
include __DIR__ . '/../includes/layout.php';
?>

<div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
  <div class="flex items-center gap-4">
    <a href="<?= BASE_URL ?>admin/dashboard.php" class="w-10 h-10 rounded-xl bg-white border border-gray-100 flex items-center justify-center text-gray-400 hover:text-primary transition-all">
        <span class="material-symbols-outlined">arrow_back</span>
    </a>
    <div>
        <h2 class="text-2xl font-black text-[#001e40] tracking-tight">Student Management</h2>
        <p class="text-xs text-gray-400 font-bold uppercase tracking-widest"><?= count($students) ?> Active Student Records</p>
    </div>
  </div>
  <a href="<?= BASE_URL ?>admin/import_courses.php" class="flex items-center gap-2 bg-[#fecb00] text-[#001e40] px-6 py-3 rounded-2xl font-black text-sm hover:bg-[#f1c100] transition-all shadow-lg shadow-yellow-500/10">
    <span class="material-symbols-outlined text-base">school</span> Manage Courses
  </a>
</div>

<?php if ($success): ?>
<div class="mb-6 p-4 bg-green-50 border border-green-100 text-green-700 rounded-2xl font-bold flex items-center gap-3">
    <span class="material-symbols-outlined">check_circle</span> <?= $success ?>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="mb-6 p-4 bg-red-50 border border-red-100 text-red-700 rounded-2xl font-bold flex items-center gap-3">
    <span class="material-symbols-outlined">error</span> <?= $error ?>
</div>
<?php endif; ?>

<div class="bg-white p-4 rounded-[1.5rem] shadow-sm border border-gray-100 mb-6">
  <form method="GET" class="flex flex-col md:flex-row gap-4">
    <div class="relative flex-1">
        <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-gray-300">search</span>
        <input type="text" name="q" value="<?= sanitize($search) ?>" placeholder="Search name, email or matric number..."
          class="w-full bg-[#f7f9ff] border-none rounded-xl pl-12 pr-4 py-3 text-sm font-bold focus:ring-2 focus:ring-[#001e40]">
    </div>
    <select name="dept" class="bg-[#f7f9ff] border-none rounded-xl px-6 py-3 text-sm font-bold focus:ring-2 focus:ring-[#001e40]">
      <option value="">All Departments</option>
      <?php foreach ($depts as $d): ?>
      <option value="<?= sanitize($d) ?>" <?= $filterDept===$d?'selected':'' ?>><?= sanitize($d) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="level" class="bg-[#f7f9ff] border-none rounded-xl px-6 py-3 text-sm font-bold focus:ring-2 focus:ring-[#001e40]">
      <option value="">All Levels</option>
      <?php foreach ($levels as $lvl): ?>
      <option value="<?= sanitize($lvl) ?>" <?= $filterLevel===(string)$lvl?'selected':'' ?>><?= sanitize($lvl) ?> Level</option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="bg-[#001e40] text-white px-8 py-3 rounded-xl font-black text-sm hover:bg-[#003366] transition-all">Apply Filter</button>
  </form>
</div>

<div class="bg-white rounded-[2rem] shadow-sm border border-gray-100 overflow-hidden">
  <div class="overflow-x-auto">
    <table class="w-full text-left">
      <thead>
        <tr class="text-[10px] font-black uppercase text-gray-400 tracking-[0.2em] border-b border-gray-50">
          <th class="px-8 py-5">Student Identity</th>
          <th class="px-8 py-5">Department</th>
          <th class="px-4 py-5 text-center">Level</th>
          <th class="px-4 py-5 text-center">Active</th>
          <th class="px-4 py-5 text-center">Verified</th>
          <th class="px-8 py-5 text-right">Actions</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-50">
        <?php foreach ($students as $s): ?>
        <tr class="hover:bg-blue-50/30 transition-colors <?= !$s['is_active'] ? 'bg-gray-50/50' : '' ?>">
          <td class="px-8 py-5">
            <div class="flex items-center gap-4">
              <div class="w-10 h-10 rounded-xl bg-[#001e40] text-white flex items-center justify-center font-black text-xs">
                <?= strtoupper(substr($s['full_name'],0,1)) ?>
              </div>
              <div>
                <p class="font-black text-[#001e40] text-sm leading-tight"><?= sanitize($s['full_name']) ?></p>
                <p class="text-[10px] font-bold text-gray-400"><?= sanitize($s['email']) ?> • <span class="text-blue-500"><?= $s['matric_number'] ?: 'NO MATRIC' ?></span></p>
              </div>
            </div>
          </td>
          <td class="px-8 py-5">
            <p class="text-xs font-black text-gray-500 uppercase tracking-tighter"><?= sanitize($s['department'] ?: 'Unassigned') ?></p>
          </td>

          <td class="px-4 py-5 text-center">
            <span class="text-xs font-black text-[#001e40]"><?= sanitize($s['level'] ?: '—') ?></span>
          </td>

          <!-- Active toggle -->
          <td class="px-4 py-5 text-center">
            <form method="POST" class="inline">
              <input type="hidden" name="user_id" value="<?= $s['id'] ?>">
              <input type="hidden" name="toggle_flag" value="is_active">
              <input type="hidden" name="new_value" value="<?= $s['is_active'] ? 0 : 1 ?>">
              <button type="submit"
                class="w-10 h-6 rounded-full transition-all duration-300 relative inline-flex items-center <?= $s['is_active'] ? 'bg-green-500 shadow-inner' : 'bg-red-200' ?>">
                <span class="w-4 h-4 rounded-full bg-white shadow-md transition-all absolute <?= $s['is_active'] ? 'translate-x-5' : 'translate-x-1' ?>"></span>
              </button>
            </form>
          </td>

          <!-- Verified badge (read-only — students verify via OTP) -->
          <td class="px-4 py-5 text-center">
            <?php if ($s['is_verified']): ?>
              <span class="text-[10px] font-black bg-green-100 text-green-700 px-2 py-0.5 rounded">VERIFIED</span>
            <?php else: ?>
              <span class="text-[10px] font-black bg-amber-100 text-amber-700 px-2 py-0.5 rounded">PENDING</span>
            <?php endif; ?>
          </td>

          <td class="px-8 py-5 text-right">
            <button onclick="document.getElementById('reset_<?= $s['id'] ?>').classList.toggle('hidden')"
              class="w-8 h-8 rounded-lg border border-gray-100 text-gray-400 hover:text-primary hover:bg-white hover:shadow-sm transition-all">
              <span class="material-symbols-outlined text-sm">vpn_key</span>
            </button>
          </td>
        </tr>

        <tr id="reset_<?= $s['id'] ?>" class="hidden bg-[#f7f9ff]">
          <td colspan="6" class="px-8 py-4">
            <form method="POST" class="flex items-center gap-4">
              <input type="hidden" name="user_id" value="<?= $s['id'] ?>">
              <input type="hidden" name="reset_password" value="1">
              <p class="text-[10px] font-black text-blue-800 uppercase tracking-widest">Emergency Password Reset:</p>
              <input type="text" name="new_password" value="LASU@Change2026" class="bg-white border border-blue-100 rounded-lg px-4 py-2 text-xs font-bold w-48">
              <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-[10px] font-black uppercase tracking-widest hover:bg-blue-700 transition-all">Confirm Reset</button>
              <button type="button" onclick="document.getElementById('reset_<?= $s['id'] ?>').classList.add('hidden')" class="text-[10px] font-bold text-gray-400 uppercase">Cancel</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($students)): ?>
        <tr><td colspan="6" class="px-8 py-20 text-center text-gray-300 font-bold text-sm italic">No students found matching your filters.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/layout_end.php'; ?>

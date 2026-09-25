<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('level_adviser');
$user  = currentUser();
$db    = getDB();
$stats = getStats('level_adviser', $user['id']);

$filter = $_GET['status'] ?? '';
$sql = '
    SELECT c.*, co.course_code, co.course_title, u.full_name as student_name, u.matric_number
    FROM complaints c JOIN courses co ON c.course_id = co.id JOIN users u ON c.student_id = u.id
    WHERE u.level = ? AND u.department = ?
';
$params = [$user['level'], $user['department']];
if ($filter) { $sql .= ' AND c.status = ?'; $params[] = $filter; }
$sql .= ' ORDER BY c.created_at DESC';
$stmt = $db->prepare($sql); $stmt->execute($params);
$complaints = $stmt->fetchAll();

$pageTitle = 'Academic Oversight';
$activeNav = 'dashboard.php';
include __DIR__ . '/../includes/layout.php';
?>

<!-- Page Header -->
<div class="mb-8">
  <h2 class="text-3xl font-black text-[#0b1d2c] tracking-tight">Academic Oversight</h2>
  <p class="text-[#43474f] mt-1 text-sm font-medium">
    <?= sanitize($user['department']) ?> &bull; Level <?= sanitize($user['level']) ?> &bull; <?= date('Y') ?>/<?= date('Y')+1 ?> Session
  </p>
</div>

<!-- Stats -->
<div class="grid grid-cols-1 md:grid-cols-4 gap-5 mb-8">
  <?php
  $statItems = [
    ['label'=>'Total Complaints','key'=>'total',    'icon'=>'confirmation_number','bg'=>'bg-[#edf4ff]',     'ic'=>'text-[#001e40]'],
    ['label'=>'New / Pending',   'key'=>'pending',  'icon'=>'emergency',          'bg'=>'bg-[#ffdad6]/40',  'ic'=>'text-[#93000a]','badge'=>'ACTION REQUIRED'],
    ['label'=>'Endorsed',        'key'=>'endorsed', 'icon'=>'verified',           'bg'=>'bg-[#d2e4f9]/50',  'ic'=>'text-[#001e40]'],
    ['label'=>'Resolved',        'key'=>'approved', 'icon'=>'task_alt',           'bg'=>'bg-green-50',       'ic'=>'text-green-700'],
  ];
  foreach ($statItems as $s): ?>
  <div class="bg-[#ffffff] rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] p-6 flex flex-col gap-3">
    <div class="flex justify-between items-start">
      <div class="p-2.5 <?= $s['bg'] ?> rounded-lg">
        <span class="material-symbols-outlined <?= $s['ic'] ?>"><?= $s['icon'] ?></span>
      </div>
      <?php if (!empty($s['badge']) && ($stats[$s['key']] ?? 0) > 0): ?>
        <span class="text-[9px] font-bold text-[#93000a] bg-[#ffdad6] px-2 py-0.5 rounded-full"><?= $s['badge'] ?></span>
      <?php endif; ?>
    </div>
    <div>
      <div class="text-3xl font-black text-[#0b1d2c]"><?= $stats[$s['key']] ?? 0 ?></div>
      <div class="text-xs font-semibold text-[#43474f] mt-0.5"><?= $s['label'] ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Complaints Feed -->
<div class="flex items-center justify-between mb-4">
  <h3 class="text-xl font-bold text-[#0b1d2c]">Active Complaints Feed</h3>
  <div class="flex gap-2 flex-wrap">
    <a href="?" class="px-3 py-1.5 text-xs font-bold rounded-xl <?= !$filter ? 'bg-[#001e40] text-white' : 'bg-[#edf4ff] text-[#43474f] hover:bg-[#d2e4f9]' ?> transition-all">All</a>
    <?php foreach (['pending','endorsed','assigned_to_lecturer','verified','approved','returned_to_student'] as $s): ?>
    <a href="?status=<?= $s ?>" class="px-3 py-1.5 text-xs font-bold rounded-xl <?= $filter===$s ? 'bg-[#001e40] text-white' : 'bg-[#edf4ff] text-[#43474f] hover:bg-[#d2e4f9]' ?> transition-all">
      <?= ucfirst(str_replace('_',' ',$s)) ?>
    </a>
    <?php endforeach; ?>
  </div>
</div>

<div class="bg-[#ffffff] rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] overflow-hidden">

  <?php if (empty($complaints)): ?>
  <div class="flex flex-col items-center py-16 text-[#43474f]">
    <span class="material-symbols-outlined text-5xl opacity-30 mb-3">inbox</span>
    <p class="text-sm font-semibold">No complaints found for the selected filter.</p>
  </div>
  <?php else: ?>
  <table class="w-full text-left border-collapse text-sm">
    <thead>
      <tr class="bg-[#edf4ff] text-[#43474f] text-[10px] uppercase tracking-[0.12em] font-bold">
        <th class="px-6 py-4">Student Details</th>
        <th class="px-6 py-4">Course</th>
        <th class="px-6 py-4">Category</th>
        <th class="px-6 py-4 text-center">Status</th>
        <th class="px-6 py-4">Date</th>
        <th class="px-6 py-4 text-right">Action</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($complaints as $c): ?>
      <tr class="hover:bg-[#f7f9ff] transition-colors border-t border-[#d2e4f9]/30">
        <td class="px-6 py-5">
          <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-lg bg-[#d2e4f9] flex items-center justify-center font-bold text-[#001e40] text-xs flex-shrink-0">
              <?= strtoupper(substr($c['student_name'],0,2)) ?>
            </div>
            <div>
              <p class="font-bold text-[#001e40] text-xs leading-none"><?= strtoupper(sanitize($c['student_name'])) ?></p>
              <p class="text-[10px] text-[#43474f] mt-0.5 font-mono"><?= sanitize($c['matric_number']) ?></p>
            </div>
          </div>
        </td>
        <td class="px-6 py-5">
          <span class="px-2 py-1 bg-[#edf4ff] text-[#001e40] font-bold text-xs rounded"><?= sanitize($c['course_code']) ?></span>
          <p class="text-[10px] text-[#43474f] mt-1"><?= sanitize($c['course_title']) ?></p>
        </td>
        <td class="px-6 py-5 text-xs text-[#43474f]"><?= sanitize($c['category']) ?></td>
        <td class="px-6 py-5 text-center"><?= statusBadge($c['status']) ?></td>
        <td class="px-6 py-5 text-xs text-[#43474f]"><?= formatDate($c['created_at']) ?></td>
        <td class="px-6 py-5 text-right">
          <a href="<?= BASE_URL ?>level_adviser/review_complaint.php?id=<?= $c['id'] ?>"
            class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-bold transition-all <?= $c['status']==='pending' ? 'bg-[#001e40] text-white hover:bg-[#003366]' : 'bg-[#edf4ff] text-[#001e40] hover:bg-[#d2e4f9]' ?>">
            <?= $c['status']==='pending' ? 'Review' : 'View' ?>
            <span class="material-symbols-outlined text-xs">arrow_forward</span>
          </a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <div class="px-6 py-3 bg-[#f7f9ff] text-xs text-[#43474f] font-medium text-right">
    Showing <?= count($complaints) ?> record(s)
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/layout_end.php'; ?>

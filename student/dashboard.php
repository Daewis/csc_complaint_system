<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('student');

$user  = currentUser();
$db    = getDB();
$stats = getStats('student', $user['id']);

$filter = $_GET['status'] ?? '';
$sql    = 'SELECT c.*, co.course_code, co.course_title FROM complaints c JOIN courses co ON c.course_id = co.id WHERE c.student_id = ?';
$params = [$user['id']];

if ($filter) {
    $sql .= ' AND c.status = ?';
    $params[] = $filter;
}

$sql .= ' ORDER BY c.created_at DESC';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$complaints = $stmt->fetchAll();

$pageTitle = 'My Dashboard';
$activeNav = 'dashboard.php';
include __DIR__ . '/../includes/layout.php';
?>

<div class="mb-8 flex items-start justify-between">
  <div>
    <h2 class="text-3xl font-black text-[#001e40] tracking-tight">Welcome, <?= sanitize(explode(' ',$user['full_name'])[0]) ?></h2>
    <p class="text-[#43474f] mt-1 text-sm font-medium"><?= sanitize($user['department']) ?> &bull; Level <?= sanitize($user['level']) ?> &bull; <span class="font-mono"><?= sanitize($user['matric_number'] ?? '') ?></span></p>
  </div>
  <a href="new_complaint.php"
    class="flex items-center gap-2 bg-[#001e40] text-white px-5 py-3 rounded-xl font-bold text-sm shadow-lg shadow-[#001e40]/20 hover:bg-[#003366] transition-all hover:scale-[1.02] active:scale-[0.98]">
    <span class="material-symbols-outlined text-base">add</span>
    New Complaint
  </a>
</div>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
  <?php
  $statItems = [
    ['label'=>'Total Submitted', 'key'=>'total',               'icon'=>'confirmation_number', 'bg'=>'bg-[#edf4ff]',   'ic'=>'text-[#001e40]'],
    ['label'=>'Pending Review',  'key'=>'pending',              'icon'=>'pending_actions',    'bg'=>'bg-[#ffe08b]/30','ic'=>'text-[#745b00]'],
    ['label'=>'Resolved',        'key'=>'approved',             'icon'=>'task_alt',           'bg'=>'bg-green-50',    'ic'=>'text-green-700'],
    ['label'=>'Needs Attention', 'key'=>'returned_to_student',  'icon'=>'reply',              'bg'=>'bg-[#ffdad6]/50','ic'=>'text-[#93000a]'],
  ];
  foreach ($statItems as $s): ?>
  <div class="bg-[#ffffff] rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] p-5">
    <div class="flex items-start justify-between mb-3">
      <div class="p-2 <?= $s['bg'] ?> rounded-lg">
        <span class="material-symbols-outlined <?= $s['ic'] ?>"><?= $s['icon'] ?></span>
      </div>
    </div>
    <div class="text-3xl font-black text-[#0b1d2c]"><?= $stats[$s['key']] ?? 0 ?></div>
    <div class="text-xs font-semibold text-[#43474f] mt-1"><?= $s['label'] ?></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="flex flex-wrap gap-2 mb-5">
  <a href="?" class="px-4 py-2 rounded-xl text-xs font-bold transition-all <?= !$filter ? 'bg-[#001e40] text-white shadow-md' : 'bg-[#edf4ff] text-[#43474f] hover:bg-[#d2e4f9]' ?>">All</a>
  <?php foreach (['pending','returned_to_student','endorsed','assigned_to_lecturer','verified','approved','rejected'] as $s): ?>
  <a href="?status=<?= $s ?>" class="px-4 py-2 rounded-xl text-xs font-bold transition-all <?= $filter===$s ? 'bg-[#001e40] text-white shadow-md' : 'bg-[#edf4ff] text-[#43474f] hover:bg-[#d2e4f9]' ?>">
    <?= ucfirst(str_replace('_',' ',$s)) ?>
  </a>
  <?php endforeach; ?>
</div>

<div class="bg-[#ffffff] rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] overflow-hidden">
  <div class="px-6 py-4 bg-[#edf4ff]/50 flex items-center justify-between">
    <h3 class="font-bold text-[#001e40] flex items-center gap-2">
      <span class="material-symbols-outlined text-base">list_alt</span>
      My Complaints
    </h3>
    <span class="text-xs font-bold text-[#43474f] uppercase tracking-widest"><?= count($complaints) ?> record(s)</span>
  </div>

  <?php if (empty($complaints)): ?>
  <div class="flex flex-col items-center justify-center py-16 text-[#43474f]">
    <span class="material-symbols-outlined text-5xl opacity-30 mb-3">inbox</span>
    <p class="font-semibold text-sm">No complaints found.</p>
    <a href="new_complaint.php" class="mt-3 text-[#001e40] font-bold text-sm underline underline-offset-2">File your first complaint</a>
  </div>
  <?php else: ?>
  <div class="overflow-x-auto">
    <table class="w-full text-left border-collapse text-sm">
      <thead>
        <tr class="bg-[#f7f9ff] text-[#43474f] text-[10px] uppercase tracking-[0.12em] font-bold">
          <th class="px-6 py-3">Ticket</th>
          <th class="px-6 py-3">Course</th>
          <th class="px-6 py-3">Category</th>
          <th class="px-6 py-3">Session</th>
          <th class="px-6 py-3">Status</th>
          <th class="px-6 py-3">SLA</th>
          <th class="px-6 py-3">Filed</th>
          <th class="px-6 py-3"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($complaints as $c): ?>
        <tr class="hover:bg-[#f7f9ff] transition-colors cursor-pointer border-t border-[#d2e4f9]/30"
            onclick="location.href='view_complaint.php?id=<?= $c['id'] ?>'">
          <td class="px-6 py-4">
            <code class="text-xs bg-[#edf4ff] text-[#001e40] px-2 py-1 rounded font-mono font-bold"><?= sanitize($c['ticket_number']) ?></code>
          </td>
          <td class="px-6 py-4">
            <p class="font-bold text-[#001e40] text-xs"><?= sanitize($c['course_code']) ?></p>
            <p class="text-[10px] text-[#43474f]"><?= sanitize($c['course_title']) ?></p>
          </td>
          <td class="px-6 py-4 text-xs text-[#43474f]"><?= sanitize($c['category']) ?></td>
          <td class="px-6 py-4 text-xs text-[#43474f]"><?= sanitize($c['academic_session']) ?> / <?= sanitize($c['semester']) ?></td>
          <td class="px-6 py-4"><?= statusBadge($c['status']) ?></td>
          <td class="px-6 py-4 text-xs">
            <?php if ($c['sla_deadline'] && $c['status']==='assigned_to_lecturer'): ?>
              <span data-sla-deadline="<?= $c['sla_deadline'] ?>" class="<?= $c['is_overdue'] ? 'text-red-600 font-bold' : 'text-[#43474f]' ?>">…</span>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="px-6 py-4 text-xs text-[#43474f]"><?= formatDate($c['created_at']) ?></td>
          <td class="px-6 py-4 text-right">
            <a href="view_complaint.php?id=<?= $c['id'] ?>" onclick="event.stopPropagation()"
              class="p-2 hover:bg-[#d2e4f9] rounded-lg transition-colors text-[#43474f] hover:text-[#001e40] inline-flex">
              <span class="material-symbols-outlined text-base">open_in_new</span>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/layout_end.php'; ?>

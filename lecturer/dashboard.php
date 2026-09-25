<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('lecturer');

$user  = currentUser();
$db    = getDB();
$stats = getStats('lecturer', $user['id']);

/** * 1. Fetch Official Course Portfolio
 * Retrieves assigned courses for the current academic session.
 */
$stmtPortfolio = $db->prepare('
    SELECT co.course_code, co.course_title, ca.academic_session, ca.semester
    FROM course_assignments ca
    JOIN courses co ON ca.course_id = co.id
    WHERE ca.lecturer_id = ?
    ORDER BY co.course_code ASC
');
$stmtPortfolio->execute([$user['id']]);
$myPortfolio = $stmtPortfolio->fetchAll();

/**
 * 2. Fetch Complaints Grouped by Course
 */
$filter = $_GET['status'] ?? '';
$sql = '
    SELECT c.*, co.course_code, co.course_title, u.full_name as student_name, u.matric_number
    FROM complaints c
    JOIN courses co ON c.course_id = co.id
    JOIN users u ON c.student_id = u.id
    WHERE c.hod_assigned_lecturer_id = ?
';
$params = [$user['id']];
if ($filter) { $sql .= ' AND c.status = ?'; $params[] = $filter; }
$sql .= ' ORDER BY co.course_code ASC, c.is_overdue DESC, c.sla_deadline ASC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$allComplaints = $stmt->fetchAll();

// Group complaints by Course Code for the UI
$groupedComplaints = [];
foreach ($allComplaints as $complaint) {
    $groupedComplaints[$complaint['course_code']][] = $complaint;
}

$pageTitle = 'Lecturer Dashboard';
$activeNav = 'dashboard.php';
include __DIR__ . '/../includes/layout.php';
?>

<div class="mb-8">
  <h2 class="text-3xl font-black text-[#001e40] tracking-tight italic">My Assignments</h2>
  <p class="text-[#43474f] mt-1 text-sm font-medium">
    <?= sanitize($user['full_name']) ?> &bull;
    <span class="text-[#001e40] font-bold"><?= sanitize($user['department']) ?></span> &bull;
    Course Lecturer
  </p>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-10">
  <?php
  $statItems = [
    ['label'=>'Total Assigned',       'key'=>'total',               'icon'=>'assignment','bg'=>'bg-[#edf4ff]','ic'=>'text-[#001e40]'],
    ['label'=>'Pending Verification', 'key'=>'assigned_to_lecturer','icon'=>'pending',   'bg'=>'bg-[#ffdad6]/30','ic'=>'text-[#93000a]'],
    ['label'=>'Verified',             'key'=>'verified',            'icon'=>'verified',  'bg'=>'bg-green-50','ic'=>'text-green-700'],
  ];
  foreach ($statItems as $s): ?>
  <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-6 flex flex-col gap-3">
    <div class="p-2.5 <?= $s['bg'] ?> rounded-2xl w-fit">
      <span class="material-symbols-outlined <?= $s['ic'] ?>"><?= $s['icon'] ?></span>
    </div>
    <div>
      <div class="text-3xl font-black text-[#0b1d2c]"><?= $stats[$s['key']] ?? 0 ?></div>
      <div class="text-[10px] font-black uppercase tracking-widest text-[#43474f] mt-1"><?= $s['label'] ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="mb-10">
  <div class="flex items-center gap-3 mb-4">
    <div class="h-4 w-1 bg-[#fecb00] rounded-full"></div>
    <p class="text-[10px] font-black uppercase tracking-[0.2em] text-[#001e40]/50">Teaching Portfolio (<?= $myPortfolio[0]['academic_session'] ?? '2025/2026' ?>)</p>
  </div>
  <div class="grid gridcols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
    <?php foreach ($myPortfolio as $ac): ?>
    <div class="bg-white border border-[#d2e4f9]/50 p-4 rounded-[2rem] shadow-sm hover:border-[#fecb00] transition-all group">
      <div class="flex justify-between items-start mb-3">
        <span class="bg-[#001e40] text-[#fecb00] text-[10px] font-black px-2.5 py-1 rounded-xl">
            <?= sanitize($ac['course_code']) ?>
        </span>
        <span class="text-[9px] font-black text-blue-500 uppercase italic tracking-tighter"><?= sanitize($ac['semester']) ?></span>
      </div>
      <p class="text-xs font-black text-[#001e40] leading-tight group-hover:text-blue-600 transition-colors"><?= sanitize($ac['course_title']) ?></p>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="flex flex-wrap items-center justify-between gap-4 mb-6">
    <div class="flex gap-2">
        <a href="?" class="px-5 py-2.5 rounded-2xl text-[10px] font-black uppercase tracking-widest transition-all <?= !$filter ? 'bg-[#001e40] text-white shadow-lg shadow-blue-900/20' : 'bg-white text-[#43474f] border border-gray-100 hover:bg-gray-50' ?>">All Tasks</a>
        <?php foreach (['assigned_to_lecturer' => 'Pending', 'verified' => 'Completed'] as $val => $label): ?>
        <a href="?status=<?= $val ?>" class="px-5 py-2.5 rounded-2xl text-[10px] font-black uppercase tracking-widest transition-all <?= $filter===$val ? 'bg-[#001e40] text-white shadow-lg shadow-blue-900/20' : 'bg-white text-[#43474f] border border-gray-100 hover:bg-gray-50' ?>">
            <?= $label ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="space-y-8">
  <?php if (empty($groupedComplaints)): ?>
    <div class="bg-white rounded-[3rem] p-20 text-center border border-dashed border-[#d2e4f9]">
        <div class="w-20 h-20 bg-[#f7f9ff] rounded-full flex items-center justify-center mx-auto mb-6">
            <span class="material-symbols-outlined text-4xl text-[#d2e4f9]">assignment_turned_in</span>
        </div>
        <p class="text-sm font-black text-[#001e40]">No verification tasks found.</p>
        <p class="text-xs text-gray-400 mt-1 font-medium">Relax! You are all caught up for today.</p>
    </div>
  <?php else: ?>
    <?php foreach ($groupedComplaints as $courseCode => $list): ?>
    <div class="bg-white rounded-[2.5rem] shadow-sm border border-[#d2e4f9]/30 overflow-hidden">
      <div class="px-10 py-6 bg-[#f7f9ff] border-b border-[#d2e4f9]/30 flex justify-between items-center">
        <div class="flex items-center gap-4">
          <h3 class="text-sm font-black text-[#001e40] uppercase tracking-tighter italic"><?= $courseCode ?></h3>
          <div class="h-4 w-px bg-gray-300"></div>
          <p class="text-xs font-bold text-gray-500"><?= sanitize($list[0]['course_title']) ?></p>
        </div>
        <span class="text-[10px] font-black bg-[#001e40] text-white px-3 py-1.5 rounded-xl"><?= count($list) ?> COMPLAINTS</span>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
          <thead>
            <tr class="bg-gray-50/50 text-[#43474f] text-[9px] font-black uppercase tracking-[0.2em] border-b border-gray-100">
              <th class="px-10 py-5">Student / Ticket</th>
              <th class="px-10 py-5">Category</th>
              <th class="px-10 py-5">SLA Deadline</th>
              <th class="px-10 py-5">Status</th>
              <th class="px-10 py-5 text-right">Action</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <?php foreach ($list as $c): ?>
            <tr class="hover:bg-blue-50/30 transition-all <?= $c['is_overdue'] ? 'bg-red-50/20' : '' ?>">
              <td class="px-10 py-6">
                <div class="flex items-center gap-4">
                    <div class="w-10 h-10 rounded-2xl bg-[#edf4ff] flex items-center justify-center font-black text-[#001e40] text-xs">
                        <?= strtoupper(substr($c['student_name'],0,2)) ?>
                    </div>
                    <div>
                        <p class="font-black text-[#001e40] text-xs leading-none mb-1.5"><?= strtoupper(sanitize($c['student_name'])) ?></p>
                        <div class="flex items-center gap-2">
                            <span class="text-[9px] font-bold text-[#43474f] font-mono"><?= sanitize($c['matric_number']) ?></span>
                            <span class="text-gray-300">•</span>
                            <code class="text-[9px] font-black text-blue-600 uppercase">#<?= sanitize($c['ticket_number']) ?></code>
                        </div>
                    </div>
                </div>
              </td>
              <td class="px-10 py-6 text-[11px] font-bold text-[#43474f]"><?= sanitize($c['category']) ?></td>
              <td class="px-10 py-6 text-[11px]">
                <?php if ($c['sla_deadline'] && $c['status'] === 'assigned_to_lecturer'): ?>
                    <span class="<?= $c['is_overdue'] ? 'text-red-600 font-black italic' : 'text-gray-500 font-bold' ?>">
                        <?= $c['is_overdue'] ? 'OVERDUE' : formatDate($c['sla_deadline']) ?>
                    </span>
                <?php else: ?>
                    <span class="text-gray-300">N/A</span>
                <?php endif; ?>
              </td>
              <td class="px-10 py-6"><?= statusBadge($c['status']) ?></td>
              <td class="px-10 py-6 text-right">
                <a href="<?= BASE_URL ?>lecturer/verify_complaint.php?id=<?= $c['id'] ?>" class="inline-flex items-center px-5 py-2.5 rounded-2xl text-[10px] font-black uppercase tracking-widest transition-all <?= $c['status']==='assigned_to_lecturer' ? 'bg-[#fecb00] text-[#001e40] hover:scale-105 shadow-md shadow-yellow-500/10' : 'bg-gray-100 text-gray-500 hover:bg-gray-200' ?>">
                  <?= $c['status']==='assigned_to_lecturer' ? 'Verify' : 'View' ?>
                  <span class="material-symbols-outlined text-xs ml-2">arrow_forward</span>
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/layout_end.php'; ?>

<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$user = currentUser();
$db   = getDB();

// 1. Complaint Statistics
$total    = (int)$db->query('SELECT COUNT(*) FROM complaints')->fetchColumn();
$pending  = (int)$db->query("SELECT COUNT(*) FROM complaints WHERE status='pending'")->fetchColumn();
$overdue  = (int)$db->query('SELECT COUNT(*) FROM complaints WHERE is_overdue=1')->fetchColumn();
$approved = (int)$db->query("SELECT COUNT(*) FROM complaints WHERE status='approved'")->fetchColumn();

// 2. Analytics Data
$catStats  = $db->query('SELECT category, COUNT(*) as cnt FROM complaints GROUP BY category ORDER BY cnt DESC')->fetchAll();
$deptStats = $db->query('SELECT u.department, COUNT(*) as cnt FROM complaints c JOIN users u ON c.student_id = u.id GROUP BY u.department ORDER BY cnt DESC')->fetchAll();

// 3. Recent Activity (Joins with the new courses table structure)
$recent = $db->query('
    SELECT c.ticket_number, c.status, c.updated_at, u.full_name as student_name, co.course_code
    FROM complaints c
    JOIN users u ON c.student_id = u.id
    JOIN courses co ON c.course_id = co.id
    ORDER BY c.updated_at DESC
    LIMIT 20
')->fetchAll();

/**
 * 4. NEW LOGIC: Multi-Role Flag Counting
 * Instead of grouping by the 'role' column, we count based on boolean flags
 * to handle users who hold multiple positions simultaneously.
 */
$counts = $db->query("
    SELECT
        SUM(CASE WHEN role = 'student' THEN 1 ELSE 0 END) as total_students,
        SUM(CASE WHEN is_lecturer = 1 THEN 1 ELSE 0 END) as total_lecturers,
        SUM(CASE WHEN is_hod = 1 THEN 1 ELSE 0 END) as total_hods,
        SUM(CASE WHEN is_level_adviser = 1 THEN 1 ELSE 0 END) as total_advisers,
        SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as total_admins
    FROM users
    WHERE is_active = 1
")->fetch();

$pageTitle = 'System Overview';
$activeNav = 'dashboard.php';
include __DIR__ . '/../includes/layout.php';
?>

<div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
  <div>
    <h2 class="text-3xl font-black text-[#001e40] tracking-tight italic">System Overview</h2>
    <p class="text-[#43474f] mt-1 text-sm font-medium flex items-center gap-2">
        <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
        LASU Result Discrepancy Control Center
    </p>
  </div>
  <div class="flex gap-3">
    <a href="<?= BASE_URL ?>admin/import_staff.php"
      class="flex items-center gap-2 bg-[#fecb00] text-[#001e40] px-6 py-3 rounded-2xl font-bold text-sm hover:bg-[#f1c100] transition-all shadow-sm">
      <span class="material-symbols-outlined text-base">upload_file</span>Import Staff
    </a>
    <a href="<?= BASE_URL ?>admin/manage_staff.php"
      class="flex items-center gap-2 bg-[#001e40] text-white px-6 py-3 rounded-2xl font-bold text-sm hover:bg-[#003366] transition-all shadow-lg shadow-[#001e40]/20">
      <span class="material-symbols-outlined text-base">manage_accounts</span>Manage Users
    </a>
  </div>
</div>

<div class="grid grid-cols-12 gap-6 mb-8">
  <div class="col-span-12 md:col-span-3 bg-[#001e40] p-8 rounded-[2rem] text-white relative overflow-hidden shadow-xl">
    <div class="absolute -right-6 -top-6 w-32 h-32 bg-white/5 rounded-full"></div>
    <p class="text-[10px] font-bold uppercase tracking-[0.2em] opacity-50 mb-3">Submissions</p>
    <p class="text-6xl font-black italic tracking-tighter"><?= $total ?></p>
    <p class="text-white/40 text-[10px] mt-4 font-bold uppercase">Total Tickets Filed</p>
  </div>

  <div class="col-span-12 md:col-span-3 bg-white p-8 rounded-[2rem] shadow-sm border border-gray-100 flex flex-col justify-between">
    <div>
        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-3">Pending Action</p>
        <p class="text-5xl font-black text-[#001e40] italic"><?= $pending ?></p>
    </div>
    <p class="text-[10px] text-amber-600 font-black mt-4 flex items-center gap-1 uppercase tracking-tighter">
        <span class="material-symbols-outlined text-sm">hourglass_empty</span> Awaiting Level Adviser
    </p>
  </div>

  <div class="col-span-12 md:col-span-3 bg-white p-8 rounded-[2rem] shadow-sm border border-gray-100 flex flex-col justify-between">
    <div>
        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-3">Overdue SLA</p>
        <p class="text-5xl font-black text-red-600 italic"><?= $overdue ?></p>
    </div>
    <p class="text-[10px] text-red-400 font-black mt-4 flex items-center gap-1 uppercase tracking-tighter">
        <span class="material-symbols-outlined text-sm">report</span> Deadline Exceeded
    </p>
  </div>

  <div class="col-span-12 md:col-span-3 bg-[#f7f9ff] p-8 rounded-[2rem] shadow-inner border border-blue-50 flex flex-col justify-between">
    <div>
        <p class="text-[10px] font-bold text-blue-400 uppercase tracking-widest mb-3">Resolution Rate</p>
        <p class="text-5xl font-black text-blue-800 italic"><?= $total > 0 ? round($approved/$total*100) : 0 ?>%</p>
    </div>
    <p class="text-[10px] text-blue-500 font-black mt-4 uppercase tracking-tighter">
        <?= $approved ?> Cases Successfully Resolved
    </p>
  </div>
</div>

<div class="grid gridcols-12 gap-6 mb-8">
  <div class="col-span-12 md:col-span-4 bg-white rounded-[2rem] shadow-sm border border-gray-100 p-8">
    <h4 class="text-xs font-black text-[#001e40] uppercase tracking-widest mb-6 flex items-center gap-2">
      <span class="material-symbols-outlined text-base">pie_chart</span>Issue Breakdown
    </h4>
    <div class="space-y-4">
        <?php if (empty($catStats)): ?>
            <p class="text-xs text-gray-400 italic">No submissions recorded.</p>
        <?php else: ?>
            <?php foreach ($catStats as $c): ?>
            <div class="group">
                <div class="flex justify-between items-center mb-1">
                    <span class="text-xs font-bold text-gray-600"><?= sanitize($c['category']) ?></span>
                    <span class="text-xs font-black text-[#001e40]"><?= $c['cnt'] ?></span>
                </div>
                <div class="w-full bg-gray-50 h-1.5 rounded-full overflow-hidden">
                    <div class="bg-[#001e40] h-full rounded-full" style="width: <?= ($total > 0) ? ($c['cnt']/$total*100) : 0 ?>%"></div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
  </div>

  <div class="col-span-12 md:col-span-4 bg-white rounded-[2rem] shadow-sm border border-gray-100 p-8">
    <h4 class="text-xs font-black text-[#001e40] uppercase tracking-widest mb-6 flex items-center gap-2">
      <span class="material-symbols-outlined text-base">account_balance</span>Departmental Load
    </h4>
    <div class="space-y-3">
        <?php foreach ($deptStats as $d): ?>
        <div class="flex justify-between items-center p-3 bg-[#f7f9ff] rounded-xl">
            <span class="text-[10px] font-black text-[#001e40] uppercase tracking-tighter truncate max-w-[150px]"><?= sanitize($d['department'] ?? 'General') ?></span>
            <span class="text-xs font-black bg-white px-3 py-1 rounded-lg shadow-sm"><?= $d['cnt'] ?></span>
        </div>
        <?php endforeach; ?>
    </div>
  </div>

  <div class="col-span-12 md:col-span-4 bg-white rounded-[2rem] shadow-sm border border-gray-100 p-8">
    <h4 class="text-xs font-black text-[#001e40] uppercase tracking-widest mb-6 flex items-center gap-2">
      <span class="material-symbols-outlined text-base">group</span>Platform Users
    </h4>
    <div class="grid grid-cols-2 gap-4">
        <div class="p-4 bg-gray-50 rounded-2xl">
            <p class="text-[9px] font-bold text-gray-400 uppercase mb-1">Students</p>
            <p class="text-xl font-black"><?= number_format($counts['total_students']) ?></p>
        </div>
        <div class="p-4 bg-gray-50 rounded-2xl">
            <p class="text-[9px] font-bold text-gray-400 uppercase mb-1">Lecturers</p>
            <p class="text-xl font-black"><?= number_format($counts['total_lecturers']) ?></p>
        </div>
        <div class="p-4 bg-gray-50 rounded-2xl">
            <p class="text-[9px] font-bold text-gray-400 uppercase mb-1">Advisers</p>
            <p class="text-xl font-black"><?= number_format($counts['total_advisers']) ?></p>
        </div>
        <div class="p-4 bg-gray-50 rounded-2xl border-2 border-dashed border-blue-100">
            <p class="text-[9px] font-bold text-blue-400 uppercase mb-1">HODs</p>
            <p class="text-xl font-black text-blue-800"><?= number_format($counts['total_hods']) ?></p>
        </div>
    </div>
  </div>
</div>

<div class="bg-white rounded-[2rem] shadow-sm border border-gray-100 overflow-hidden">
  <div class="px-8 py-6 border-b border-gray-50 flex items-center gap-3">
    <span class="material-symbols-outlined text-[#001e40]">history</span>
    <h3 class="font-black text-[#001e40] text-sm uppercase tracking-widest">Global Activity Stream</h3>
  </div>
  <div class="overflow-x-auto">
    <table class="w-full text-left border-collapse">
      <thead>
        <tr class="text-gray-400 text-[10px] uppercase tracking-[0.2em] font-black border-b border-gray-50">
          <th class="px-8 py-4">Ticket</th>
          <th class="px-8 py-4">Student</th>
          <th class="px-8 py-4">Course</th>
          <th class="px-8 py-4">Current Status</th>
          <th class="px-8 py-4">Last Sync</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-50">
        <?php foreach ($recent as $r): ?>
        <tr class="hover:bg-blue-50/30 transition-colors group">
          <td class="px-8 py-5">
            <span class="text-xs font-black text-[#001e40] bg-[#f7f9ff] px-3 py-1.5 rounded-lg border border-blue-50 group-hover:bg-white transition-colors"><?= sanitize($r['ticket_number']) ?></span>
          </td>
          <td class="px-8 py-5">
            <p class="text-xs font-bold text-gray-700"><?= sanitize($r['student_name']) ?></p>
          </td>
          <td class="px-8 py-5">
            <p class="text-xs font-black text-[#001e40] uppercase tracking-tighter"><?= sanitize($r['course_code']) ?></p>
          </td>
          <td class="px-8 py-5"><?= statusBadge($r['status']) ?></td>
          <td class="px-8 py-5">
            <p class="text-[10px] font-bold text-gray-400"><?= date('H:i, M j', strtotime($r['updated_at'])) ?></p>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($recent)): ?>
        <tr><td colspan="5" class="px-8 py-20 text-center text-gray-300 font-bold text-sm italic">No active cases found in the system.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/layout_end.php'; ?>

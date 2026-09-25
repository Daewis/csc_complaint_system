<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();
$user = currentUser();
if (!$user['is_hod']) {
    header('Location: ' . BASE_URL . 'dashboard.php');
    exit;
}

$db    = getDB();
$stats = getStats('hod', $user['id']);

$hodStatuses  = ['endorsed', 'assigned_to_lecturer', 'verified', 'approved', 'rejected'];
$filter       = $_GET['status'] ?? '';
$levelFilter  = $_GET['level']  ?? '';

if ($filter && !in_array($filter, $hodStatuses)) {
    $filter = '';
}

$placeholders = implode(',', array_fill(0, count($hodStatuses), '?'));

/*
|--------------------------------------------------------------------------
| Allowed levels
|--------------------------------------------------------------------------
*/
$availableLevels = ['100', '200', '300', '400', '500'];

/*
|--------------------------------------------------------------------------
| Validate level filter
|--------------------------------------------------------------------------
*/
if ($levelFilter && !in_array($levelFilter, $availableLevels)) {
    $levelFilter = '';
}

// ── Main query ────────────────────────────────────────────────────────────────
$sql = "
    SELECT
        c.*,
        co.course_code,
        co.course_title,
        u.full_name  AS student_name,
        u.matric_number,
        u.level,
        la.full_name  AS la_name,
        lec.full_name AS assigned_lec
    FROM complaints c
    JOIN  courses co ON c.course_id                   = co.id
    JOIN  users   u  ON c.student_id                  = u.id
    LEFT JOIN users la  ON c.level_adviser_id          = la.id
    LEFT JOIN users lec ON c.hod_assigned_lecturer_id  = lec.id
    WHERE u.department = ?
      AND c.status IN ($placeholders)
";
$params = array_merge([$user['department']], $hodStatuses);

if ($filter) {
    $sql    .= ' AND c.status = ?';
    $params[] = $filter;
}
if (!empty($levelFilter)) {
    $sql .= " AND CAST(u.level AS CHAR) = ? ";
    $params[] = $levelFilter;
}

$sql .= ' ORDER BY c.is_overdue DESC, c.created_at DESC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$complaints = $stmt->fetchAll();

// ── Group by level ────────────────────────────────────────────────────────────
$complaintsByLevel = [];
foreach ($complaints as $c) {
    $lvl = $c['level'] ?? 'Unknown';
    $complaintsByLevel[$lvl][] = $c;
}
uksort($complaintsByLevel, function ($a, $b) {
    return (int)$a <=> (int)$b;
});

$pageTitle = 'Departmental Overview';
$activeNav = 'dashboard.php';
include __DIR__ . '/../includes/layout.php';
?>

<!-- ── Page header ──────────────────────────────────────────────────────────── -->
<div class="mb-8 flex items-start justify-between">
  <div>
    <h2 class="text-3xl font-black text-[#001e40] tracking-tight">Departmental Overview</h2>
    <p class="text-[#43474f] mt-1 text-sm font-medium flex items-center gap-2">
      Analytical summary — <?= sanitize($user['department']) ?>
      <?php if ($levelFilter): ?>
        <span class="px-2 py-0.5 bg-[#fecb00] text-[#001e40] text-xs font-black rounded-full">
          Level <?= sanitize($levelFilter) ?>
        </span>
      <?php endif; ?>
    </p>
  </div>
  <a href="<?= BASE_URL ?>hod/batch_process.php"
    class="flex items-center gap-2 bg-[#edf4ff] text-[#001e40] px-5 py-3 rounded-xl font-bold text-sm hover:bg-[#d2e4f9] transition-all">
    <span class="material-symbols-outlined text-base">layers</span>Batch Process
  </a>
</div>

<!-- ── Bento stats ──────────────────────────────────────────────────────────── -->
<div class="grid grid-cols-12 gap-5 mb-8">

  <div class="col-span-12 md:col-span-5 bg-[#001e40] p-8 rounded-xl relative overflow-hidden flex flex-col justify-between min-h-[180px]">
    <div class="absolute -right-10 -bottom-10 w-48 h-48 bg-white/5 rounded-full blur-3xl"></div>
    <div class="relative z-10">
      <div class="flex items-center gap-2 text-white/60 text-xs font-bold uppercase tracking-widest mb-3">
        <span class="material-symbols-outlined text-base">priority_high</span>Endorsed · Awaiting Action
      </div>
      <div class="text-6xl font-black text-white"><?= $stats['endorsed'] ?? 0 ?></div>
      <p class="text-white/70 text-sm mt-1">Complaints requiring HOD direction</p>
    </div>
    <div class="relative z-10 mt-6">
      <a href="?status=endorsed<?= $levelFilter ? '&level='.$levelFilter : '' ?>"
        class="inline-flex items-center gap-2 bg-[#fecb00] text-[#001e40] px-5 py-2.5 rounded-xl font-bold text-sm hover:scale-105 transition-transform">
        Review Actions <span class="material-symbols-outlined text-sm">arrow_forward</span>
      </a>
    </div>
  </div>

  <div class="col-span-12 md:col-span-3 bg-white p-7 rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] border-l-4 border-[#fecb00] flex flex-col justify-between">
    <div>
      <p class="text-xs font-bold text-[#43474f] uppercase tracking-widest mb-2">With Lecturer</p>
      <div class="text-4xl font-black text-[#001e40]"><?= $stats['assigned_to_lecturer'] ?? 0 ?></div>
    </div>
    <div class="flex items-center justify-between mt-4">
      <span class="text-xs font-medium text-[#745b00] flex items-center gap-1 bg-[#ffe08b] px-2 py-1 rounded-full">
        <span class="material-symbols-outlined text-xs">schedule</span>In Progress
      </span>
      <span class="text-xs text-[#43474f]">SLA <?= SLA_HOURS ?>h</span>
    </div>
  </div>

  <div class="col-span-12 md:col-span-4 bg-[#edf4ff] p-7 rounded-xl flex flex-col justify-between">
    <div>
      <div class="flex justify-between items-start">
        <p class="text-xs font-bold text-[#43474f] uppercase tracking-widest">Resolved</p>
        <span class="text-green-600 font-bold text-xs flex items-center gap-1">
          <span class="material-symbols-outlined text-xs">trending_up</span>Total
        </span>
      </div>
      <div class="text-4xl font-black text-[#001e40] mt-2"><?= $stats['approved'] ?? 0 ?></div>
    </div>
    <div class="mt-4">
      <div class="w-full bg-[#d2e4f9] h-2 rounded-full overflow-hidden">
        <?php
          $total = max(1, $stats['total'] ?? 1);
          $pct   = min(100, round(($stats['approved'] ?? 0) / $total * 100));
        ?>
        <div class="bg-[#001e40] h-full rounded-full" style="width:<?= $pct ?>%"></div>
      </div>
      <p class="text-xs text-[#43474f] mt-1"><?= $pct ?>% resolution rate</p>
    </div>
  </div>

</div>

<!-- ── Filter bar ───────────────────────────────────────────────────────────── -->
<div class="bg-white rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] px-5 py-4 mb-5 space-y-3">

  <!-- Status pills -->
  <div class="flex flex-wrap gap-2 items-center">
    <span class="text-[10px] font-bold text-[#43474f] uppercase tracking-widest w-14 flex-shrink-0">Status:</span>
    <a href="?<?= $levelFilter ? 'level='.$levelFilter : '' ?>"
      class="px-3 py-1.5 text-xs font-bold rounded-xl transition-all
        <?= !$filter ? 'bg-[#001e40] text-white' : 'bg-[#edf4ff] text-[#43474f] hover:bg-[#d2e4f9]' ?>">
      All
    </a>
    <?php foreach ($hodStatuses as $s): ?>
    <a href="?status=<?= $s ?><?= $levelFilter ? '&level='.$levelFilter : '' ?>"
      class="px-3 py-1.5 text-xs font-bold rounded-xl transition-all
        <?= $filter === $s ? 'bg-[#001e40] text-white' : 'bg-[#edf4ff] text-[#43474f] hover:bg-[#d2e4f9]' ?>">
      <?= ucfirst(str_replace('_', ' ', $s)) ?>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Level pills — always shows all levels that have any HOD-stage complaint -->
  <?php if (!empty($availableLevels)): ?>
  <div class="flex flex-wrap gap-2 items-center">
    <span class="text-[10px] font-bold text-[#43474f] uppercase tracking-widest w-14 flex-shrink-0">Level:</span>
    <!-- All Levels pill -->
    <a href="?<?= $filter ? 'status='.$filter : '' ?>"
      class="px-3 py-1.5 text-xs font-bold rounded-xl transition-all
        <?= !$levelFilter ? 'bg-[#fecb00] text-[#001e40]' : 'bg-[#edf4ff] text-[#43474f] hover:bg-[#d2e4f9]' ?>">
      All Levels
    </a>
    <?php foreach ($availableLevels as $lvl): ?>
    <a href="?level=<?= urlencode($lvl) ?><?= $filter ? '&status='.$filter : '' ?>"
      class="px-3 py-1.5 text-xs font-bold rounded-xl transition-all
        <?= $levelFilter == $lvl ? 'bg-[#fecb00] text-[#001e40]' : 'bg-[#edf4ff] text-[#43474f] hover:bg-[#d2e4f9]' ?>">
      <?= sanitize($lvl) ?> Level
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>

<!-- ── Complaints feed ──────────────────────────────────────────────────────── -->
<?php if (empty($complaints)): ?>
<div class="bg-white rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] flex flex-col items-center py-20 text-[#43474f]">
  <span class="material-symbols-outlined text-5xl opacity-30 mb-3">inbox</span>
  <p class="text-sm font-semibold">No complaints found.</p>
  <p class="text-xs mt-1 text-center max-w-xs">Try adjusting your filters, or wait for Level Advisers to endorse complaints.</p>
</div>

<?php else: ?>

<div class="mb-4">
  <p class="text-xs font-bold text-[#43474f] uppercase tracking-widest">
    <?= count($complaints) ?> total record(s)<?= $levelFilter ? ' — Level '.sanitize($levelFilter) : '' ?>
  </p>
</div>

<?php foreach ($complaintsByLevel as $lvl => $lvlComplaints): ?>

<div class="mb-5 rounded-xl overflow-hidden shadow-[0_20px_40px_rgba(11,29,44,0.06)]" id="section-level-<?= $lvl ?>">

  <!-- Level header -->
  <button type="button"
    onclick="toggleLevel('<?= $lvl ?>')"
    class="w-full flex items-center justify-between px-6 py-4 bg-[#001e40] text-white hover:bg-[#003060] transition-colors">
    <div class="flex items-center gap-3">
      <div class="w-8 h-8 rounded-lg bg-[#fecb00] flex items-center justify-center flex-shrink-0">
        <span class="text-[#001e40] font-black text-xs"><?= $lvl ?></span>
      </div>
      <div class="text-left">
        <p class="font-black text-sm">Level <?= sanitize($lvl) ?> Students</p>
        <p class="text-white/60 text-[10px]"><?= count($lvlComplaints) ?> complaint<?= count($lvlComplaints) !== 1 ? 's' : '' ?></p>
      </div>
    </div>
    <div class="flex items-center gap-3">
      <?php
        $sc = array_count_values(array_column($lvlComplaints, 'status'));
        $badges = ['endorsed' => '#fecb00 text-[#001e40]', 'assigned_to_lecturer' => 'text-white bg-white/20', 'verified' => 'text-white bg-white/20'];
        foreach ($badges as $st => $cls):
          if (!empty($sc[$st])):
      ?>
      <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-white/10 text-white">
        <?= $sc[$st] ?> <?= str_replace('_',' ',$st) ?>
      </span>
      <?php endif; endforeach; ?>
      <span class="material-symbols-outlined text-white/60 transition-transform duration-200" id="chevron-<?= $lvl ?>">expand_more</span>
    </div>
  </button>

  <!-- Table -->
  <div id="body-level-<?= $lvl ?>" class="overflow-x-auto bg-white">
    <table class="w-full text-left border-collapse text-sm">
      <thead>
        <tr class="bg-[#f7f9ff] text-[#43474f] text-[10px] uppercase tracking-[0.12em] font-bold">
          <th class="px-6 py-4">Student Details</th>
          <th class="px-6 py-4">Course</th>
          <th class="px-6 py-4">Category</th>
          <th class="px-6 py-4">Status</th>
          <th class="px-6 py-4">SLA</th>
          <th class="px-6 py-4">Date</th>
          <th class="px-6 py-4 text-right">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lvlComplaints as $c): ?>
        <tr class="hover:bg-[#f7f9ff] transition-colors border-t border-[#d2e4f9]/30 <?= $c['is_overdue'] ? 'bg-[#ffdad6]/10' : '' ?>">

          <td class="px-6 py-5">
            <div class="flex items-center gap-3">
              <div class="w-9 h-9 rounded-lg bg-[#d2e4f9] flex items-center justify-center font-bold text-[#001e40] text-xs flex-shrink-0">
                <?= strtoupper(substr($c['student_name'], 0, 2)) ?>
              </div>
              <div>
                <p class="font-bold text-[#001e40] text-xs leading-none"><?= strtoupper(sanitize($c['student_name'])) ?></p>
                <p class="text-[10px] text-[#43474f] mt-0.5 font-mono"><?= sanitize($c['matric_number']) ?></p>
              </div>
            </div>
          </td>

          <td class="px-6 py-5">
            <p class="font-bold text-[#001e40] text-xs"><?= sanitize($c['course_code']) ?></p>
            <p class="text-[10px] text-[#43474f]"><?= sanitize($c['course_title']) ?></p>
          </td>

          <td class="px-6 py-5 text-xs text-[#43474f]"><?= sanitize($c['category']) ?></td>

          <td class="px-6 py-5"><?= statusBadge($c['status']) ?></td>

          <td class="px-6 py-5 text-xs">
            <?php if ($c['sla_deadline'] && $c['status'] === 'assigned_to_lecturer'): ?>
              <span data-sla-deadline="<?= $c['sla_deadline'] ?>"
                class="<?= $c['is_overdue'] ? 'text-red-600 font-bold' : 'text-[#43474f]' ?>">…</span>
            <?php else: ?>—<?php endif; ?>
          </td>

          <td class="px-6 py-5 text-xs text-[#43474f]"><?= formatDate($c['created_at']) ?></td>

          <td class="px-6 py-5 text-right">
            <a href="<?= BASE_URL ?>hod/review_complaint.php?id=<?= $c['id'] ?>"
              class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-bold transition-all
                <?= in_array($c['status'], ['endorsed','verified']) ? 'bg-[#001e40] text-white hover:bg-[#003366]' : 'bg-[#edf4ff] text-[#001e40] hover:bg-[#d2e4f9]' ?>">
              <?= in_array($c['status'], ['endorsed','verified']) ? 'Action' : 'View' ?>
              <span class="material-symbols-outlined text-xs">more_vert</span>
            </a>
          </td>

        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="px-6 py-3 bg-[#f7f9ff] text-xs text-[#43474f] text-right border-t border-[#d2e4f9]/30">
      <?= count($lvlComplaints) ?> record(s) — Level <?= sanitize($lvl) ?>
    </div>
  </div>

</div>
<?php endforeach; ?>

<?php endif; ?>

<script>
function toggleLevel(lvl) {
  const body    = document.getElementById('body-level-' + lvl);
  const chevron = document.getElementById('chevron-' + lvl);
  const hidden  = body.classList.toggle('hidden');
  chevron.style.transform = hidden ? 'rotate(-90deg)' : '';
}

document.querySelectorAll('[data-sla-deadline]').forEach(el => {
  const deadline = new Date(el.dataset.slaDeadline);
  function tick() {
    const diff = deadline - new Date();
    if (diff <= 0) { el.textContent = 'Overdue'; el.classList.add('text-red-600','font-bold'); return; }
    const h = Math.floor(diff / 3600000);
    const m = Math.floor((diff % 3600000) / 60000);
    el.textContent = h + 'h ' + m + 'm';
  }
  tick();
  setInterval(tick, 60000);
});
</script>

<?php include __DIR__ . '/../includes/layout_end.php'; ?>

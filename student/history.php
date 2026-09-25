<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('student');

$user = currentUser();
$db   = getDB();

// ── Filters ───────────────────────────────────────────────────────────────────
$status  = $_GET['status']  ?? '';
$session = $_GET['session'] ?? '';
$search  = trim($_GET['q']  ?? '');

$sql    = '
    SELECT c.*, co.course_code, co.course_title
    FROM complaints c
    JOIN courses co ON c.course_id = co.id
    WHERE c.student_id = ?
';
$params = [$user['id']];

if ($status)  { $sql .= ' AND c.status = ?';           $params[] = $status; }
if ($session) { $sql .= ' AND c.academic_session = ?'; $params[] = $session; }
if ($search)  { $sql .= ' AND (co.course_code LIKE ? OR co.course_title LIKE ? OR c.ticket_number LIKE ? OR c.category LIKE ?)';
                $like = "%$search%";
                array_push($params, $like, $like, $like, $like); }

$sql .= ' ORDER BY c.created_at DESC';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$complaints = $stmt->fetchAll();

// ── Distinct sessions for the session filter dropdown ─────────────────────────
$sessStmt = $db->prepare('SELECT DISTINCT academic_session FROM complaints WHERE student_id = ? ORDER BY academic_session DESC');
$sessStmt->execute([$user['id']]);
$sessions = $sessStmt->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'My History';
$activeNav = 'history.php';
include __DIR__ . '/../includes/layout.php';
?>

<!-- ── PAGE HEADER ─────────────────────────────────────────────────────────── -->
<div class="mb-8 flex items-start justify-between flex-wrap gap-4">
  <div>
    <h2 class="text-3xl font-black text-[#001e40] tracking-tight">Complaint History</h2>
    <p class="text-[#43474f] mt-1 text-sm font-medium">
      Full record of all complaints filed under
      <span class="font-mono font-bold"><?= sanitize($user['matric_number'] ?? '') ?></span>
    </p>
  </div>
  <a href="new_complaint.php"
    class="flex items-center gap-2 bg-[#001e40] text-white px-5 py-3 rounded-xl font-bold text-sm shadow-lg shadow-[#001e40]/20 hover:bg-[#003366] transition-all hover:scale-[1.02] active:scale-[0.98]">
    <span class="material-symbols-outlined text-base">add</span>
    New Complaint
  </a>
</div>

<!-- ── FILTER BAR ──────────────────────────────────────────────────────────── -->
<form method="GET" class="bg-white rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] p-5 mb-6 flex flex-wrap gap-3 items-end">

  <!-- Search -->
  <div class="flex-1 min-w-[180px]">
    <label class="block text-[10px] font-bold text-[#43474f] uppercase tracking-widest mb-1.5">Search</label>
    <div class="relative">
      <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-[#43474f] text-base pointer-events-none">search</span>
      <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
        placeholder="Ticket, course, category…"
        class="w-full pl-9 pr-4 py-2.5 bg-[#d2e4f9] border-none rounded-xl text-sm text-[#0b1d2c] font-medium focus:ring-2 focus:ring-[#001e40]">
    </div>
  </div>

  <!-- Status -->
  <div class="min-w-[160px]">
    <label class="block text-[10px] font-bold text-[#43474f] uppercase tracking-widest mb-1.5">Status</label>
    <div class="relative">
      <select name="status"
        class="w-full bg-[#d2e4f9] border-none rounded-xl px-4 py-2.5 text-sm font-bold text-[#0b1d2c] focus:ring-2 focus:ring-[#001e40] appearance-none">
        <option value="">All Statuses</option>
        <?php foreach ([
          'pending'              => 'Pending Review',
          'returned_to_student'  => 'Returned',
          'endorsed'             => 'Endorsed',
          'assigned_to_lecturer' => 'Assigned to Lecturer',
          'verified'             => 'Lecturer Verified',
          'approved'             => 'Approved',
          'rejected'             => 'Rejected',
        ] as $val => $label): ?>
        <option value="<?= $val ?>" <?= $status === $val ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
      <span class="material-symbols-outlined absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none text-[#43474f] text-lg">expand_more</span>
    </div>
  </div>

  <!-- Session -->
  <div class="min-w-[150px]">
    <label class="block text-[10px] font-bold text-[#43474f] uppercase tracking-widest mb-1.5">Session</label>
    <div class="relative">
      <select name="session"
        class="w-full bg-[#d2e4f9] border-none rounded-xl px-4 py-2.5 text-sm font-bold text-[#0b1d2c] focus:ring-2 focus:ring-[#001e40] appearance-none">
        <option value="">All Sessions</option>
        <?php foreach ($sessions as $s): ?>
        <option value="<?= $s ?>" <?= $session === $s ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
      <span class="material-symbols-outlined absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none text-[#43474f] text-lg">expand_more</span>
    </div>
  </div>

  <!-- Buttons -->
  <div class="flex gap-2">
    <button type="submit"
      class="flex items-center gap-2 bg-[#001e40] text-white px-5 py-2.5 rounded-xl font-bold text-sm hover:bg-[#003366] transition-all">
      <span class="material-symbols-outlined text-base">filter_alt</span>Apply
    </button>
    <?php if ($status || $session || $search): ?>
    <a href="history.php"
      class="flex items-center gap-2 bg-[#edf4ff] text-[#001e40] px-4 py-2.5 rounded-xl font-bold text-sm hover:bg-[#d2e4f9] transition-all">
      <span class="material-symbols-outlined text-base">close</span>Clear
    </a>
    <?php endif; ?>
  </div>
</form>

<!-- ── RESULTS TABLE ───────────────────────────────────────────────────────── -->
<div class="bg-white rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] overflow-hidden">

  <!-- Table header -->
  <div class="px-6 py-4 bg-[#edf4ff]/50 flex items-center justify-between flex-wrap gap-2">
    <h3 class="font-bold text-[#001e40] flex items-center gap-2 text-sm">
      <span class="material-symbols-outlined text-base">history</span>
      Complaint Records
    </h3>
    <div class="flex items-center gap-3">
      <?php if ($status || $session || $search): ?>
      <span class="text-[10px] font-bold text-[#fecb00] bg-[#001e40] px-3 py-1 rounded-full uppercase tracking-widest">
        Filtered
      </span>
      <?php endif; ?>
      <span class="text-xs font-bold text-[#43474f] uppercase tracking-widest">
        <?= count($complaints) ?> record<?= count($complaints) !== 1 ? 's' : '' ?>
      </span>
    </div>
  </div>

  <?php if (empty($complaints)): ?>
  <div class="flex flex-col items-center justify-center py-20 text-[#43474f]">
    <span class="material-symbols-outlined text-5xl opacity-20 mb-3">manage_search</span>
    <p class="font-semibold text-sm">No complaints match your filters.</p>
    <?php if ($status || $session || $search): ?>
      <a href="history.php" class="mt-3 text-[#001e40] font-bold text-sm underline underline-offset-2">Clear filters</a>
    <?php else: ?>
      <a href="new_complaint.php" class="mt-3 text-[#001e40] font-bold text-sm underline underline-offset-2">File your first complaint</a>
    <?php endif; ?>
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
          <th class="px-6 py-3">Filed</th>
          <th class="px-6 py-3">Resolved</th>
          <th class="px-6 py-3"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($complaints as $c): ?>
        <tr class="hover:bg-[#f7f9ff] transition-colors cursor-pointer border-t border-[#d2e4f9]/30"
            onclick="location.href='view_complaint.php?id=<?= $c['id'] ?>'">

          <!-- Ticket -->
          <td class="px-6 py-4">
            <code class="text-xs bg-[#edf4ff] text-[#001e40] px-2 py-1 rounded font-mono font-bold">
              <?= sanitize($c['ticket_number']) ?>
            </code>
          </td>

          <!-- Course -->
          <td class="px-6 py-4">
            <p class="font-bold text-[#001e40] text-xs"><?= sanitize($c['course_code']) ?></p>
            <p class="text-[10px] text-[#43474f] truncate max-w-[160px]" title="<?= sanitize($c['course_title']) ?>">
              <?= sanitize($c['course_title']) ?>
            </p>
          </td>

          <!-- Category -->
          <td class="px-6 py-4 text-xs text-[#43474f]"><?= sanitize($c['category']) ?></td>

          <!-- Session -->
          <td class="px-6 py-4 text-xs text-[#43474f]">
            <?= sanitize($c['academic_session']) ?><br>
            <span class="text-[10px] opacity-70"><?= sanitize($c['semester']) ?> Sem.</span>
          </td>

          <!-- Status -->
          <td class="px-6 py-4"><?= statusBadge($c['status']) ?></td>

          <!-- Filed -->
          <td class="px-6 py-4 text-xs text-[#43474f] whitespace-nowrap"><?= formatDate($c['created_at']) ?></td>

          <!-- Resolved -->
          <td class="px-6 py-4 text-xs">
            <?php if ($c['resolved_at']): ?>
              <span class="text-green-600 font-bold"><?= formatDate($c['resolved_at']) ?></span>
            <?php elseif ($c['status'] === 'rejected'): ?>
              <span class="text-red-500 font-bold text-[10px]">Rejected</span>
            <?php else: ?>
              <span class="text-[#43474f]/40">—</span>
            <?php endif; ?>
          </td>

          <!-- Action -->
          <td class="px-6 py-4 text-right">
            <a href="view_complaint.php?id=<?= $c['id'] ?>" onclick="event.stopPropagation()"
              class="p-2 hover:bg-[#d2e4f9] rounded-lg transition-colors text-[#43474f] hover:text-[#001e40] inline-flex"
              title="View complaint">
              <span class="material-symbols-outlined text-base">open_in_new</span>
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Summary footer -->
  <div class="px-6 py-4 border-t border-[#d2e4f9]/30 bg-[#f7f9ff]/50 flex flex-wrap gap-4 text-[10px] font-bold text-[#43474f] uppercase tracking-widest">
    <?php
    $totals = array_count_values(array_column($complaints, 'status'));
    $summary = [
      'approved' => ['Final Approval', 'text-green-600'],
      'rejected' => ['Rejected',       'text-red-500'],
      'pending'  => ['Pending',        'text-[#745b00]'],
    ];
    foreach ($summary as $key => [$label, $colour]):
      if (!empty($totals[$key])):
    ?>
    <span class="<?= $colour ?>"><?= $totals[$key] ?> <?= $label ?></span>
    <?php endif; endforeach; ?>
  </div>
  <?php endif; ?>

</div>

<?php include __DIR__ . '/../includes/layout_end.php'; ?>

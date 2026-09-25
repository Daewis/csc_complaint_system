<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('level_adviser');
$user = currentUser();
$db   = getDB();

$success = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids     = $_POST['complaint_ids'] ?? [];
    $comment = trim($_POST['batch_comment'] ?? '');
    $ip      = getClientIP();
    $now     = date('Y-m-d H:i:s');

    if (empty($ids)) {
        $error = 'No complaints selected.';
    } else {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge(array_map('intval', $ids), [$user['level'], $user['department']]);
        $pendingComplaints = $db->prepare("
            SELECT c.id, c.ticket_number, c.student_id FROM complaints c
            JOIN users u ON c.student_id = u.id
            WHERE c.id IN ($placeholders) AND u.level = ? AND u.department = ? AND c.status = 'pending'
        ");
        $pendingComplaints->execute($params);
        $toProcess = $pendingComplaints->fetchAll();

        if (empty($toProcess)) {
            $error = 'None of the selected complaints are in a pending state.';
        } else {
            $hodStmt = $db->prepare("SELECT id FROM users WHERE is_hod=1 AND department=? LIMIT 1");
            $hodStmt->execute([$user['department']]);
            $hod = $hodStmt->fetch();

            $count = 0;
            foreach ($toProcess as $comp) {
                $db->prepare("UPDATE complaints SET status='endorsed', level_adviser_id=?, la_comment=?, la_signed_at=?, la_ip=?, updated_at=NOW() WHERE id=?")
                   ->execute([$user['id'], $comment ?: null, $now, $ip, $comp['id']]);
                logAudit($comp['id'], $user['id'], 'batch_endorsed', $ip, 'Batch endorsed by Level Adviser and forwarded to HOD.');
                if ($hod) {
                    addNotification($hod['id'], $comp['id'], "Complaint #{$comp['ticket_number']} batch-endorsed by Level Adviser {$user['full_name']} and forwarded.");
                }
                addNotification($comp['student_id'], $comp['id'], "Your complaint #{$comp['ticket_number']} has been endorsed and forwarded to the HOD.");
                $count++;
            }
            $success = "$count complaint(s) successfully endorsed and forwarded to the HOD.";
        }
    }
}

$stmt = $db->prepare("
    SELECT c.*, co.course_code, co.course_title,
           u.full_name as student_name, u.matric_number
    FROM complaints c
    JOIN courses co ON c.course_id = co.id
    JOIN users u ON c.student_id = u.id
    WHERE u.level = ? AND u.department = ? AND c.status = 'pending'
    ORDER BY c.created_at ASC
");
$stmt->execute([$user['level'], $user['department']]);
$pending = $stmt->fetchAll();

$pageTitle = 'Batch Forward to HOD';
$activeNav = 'batch_process.php';
include __DIR__ . '/../includes/layout.php';
?>

<!-- Header -->
<div class="mb-6 flex items-center gap-3">
  <a href="<?= BASE_URL ?>level_adviser/dashboard.php" class="text-[#43474f] hover:text-[#001e40] transition-colors">
    <span class="material-symbols-outlined">arrow_back</span>
  </a>
  <h2 class="text-2xl font-black text-[#001e40]">Batch Forward to HOD</h2>
  <span class="text-sm bg-[#edf4ff] text-[#001e40] px-3 py-1 rounded-full font-bold"><?= count($pending) ?> pending complaint(s)</span>
</div>

<?php if ($success): ?>
<div class="mb-5 flex items-center gap-3 bg-green-50 text-green-800 border border-green-200 px-5 py-4 rounded-xl text-sm font-medium alert-auto">
  <span class="material-symbols-outlined text-base">check_circle</span><?= sanitize($success) ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="mb-5 flex items-center gap-3 bg-[#ffdad6] text-[#93000a] px-5 py-4 rounded-xl text-sm font-medium">
  <span class="material-symbols-outlined text-base">error</span><?= sanitize($error) ?>
</div>
<?php endif; ?>

<!-- Info Banner -->
<div class="flex items-start gap-3 bg-[#edf4ff] text-[#001e40] px-5 py-4 rounded-xl text-sm font-medium mb-6">
  <span class="material-symbols-outlined text-base flex-shrink-0 mt-0.5">info</span>
  <p>Select one or more <strong>pending</strong> complaints below and endorse them all at once. They will be immediately forwarded to the HOD for assignment. Only pending complaints appear here — complaints already endorsed or further in the workflow are excluded.</p>
</div>

<?php if (empty($pending)): ?>
<div class="bg-white rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] flex flex-col items-center py-20 text-[#43474f]">
  <span class="material-symbols-outlined text-5xl opacity-30 mb-3">done_all</span>
  <p class="font-bold text-sm">No pending complaints awaiting endorsement.</p>
  <p class="text-xs mt-1">New student complaints will appear here once submitted.</p>
</div>
<?php else: ?>
<form method="POST">

  <!-- Select All Bar -->
  <div class="bg-[#edf4ff] rounded-xl px-6 py-4 flex items-center gap-4 mb-4">
    <label class="flex items-center gap-3 cursor-pointer">
      <input type="checkbox" id="select_all" class="w-4 h-4 rounded accent-[#001e40]">
      <span class="text-sm font-bold text-[#001e40]">Select All</span>
    </label>
    <span class="text-sm text-[#43474f]"><span id="batch_count">0</span> selected</span>
    <a href="<?= BASE_URL ?>level_adviser/dashboard.php?status=pending"
      class="ml-auto text-xs text-[#001e40] font-bold hover:underline flex items-center gap-1">
      <span class="material-symbols-outlined text-sm">open_in_new</span>Review individually
    </a>
  </div>

  <!-- Complaints Table -->
  <div class="bg-white rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] overflow-hidden mb-6">
    <div class="overflow-x-auto">
      <table class="w-full text-left border-collapse text-sm">
        <thead>
          <tr class="bg-[#f7f9ff] text-[#43474f] text-[10px] uppercase tracking-[0.12em] font-bold">
            <th class="px-4 py-4 w-10"></th>
            <th class="px-4 py-4">Ticket</th>
            <th class="px-6 py-4">Student</th>
            <th class="px-4 py-4">Course</th>
            <th class="px-4 py-4">Category</th>
            <th class="px-4 py-4">Date Filed</th>
            <th class="px-4 py-4 text-right">Preview</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pending as $c): ?>
          <tr class="hover:bg-[#f7f9ff] transition-colors border-t border-[#d2e4f9]/30 complaint-row">
            <td class="px-4 py-4">
              <input type="checkbox" name="complaint_ids[]" value="<?= $c['id'] ?>"
                class="complaint-check w-4 h-4 rounded accent-[#001e40]">
            </td>
            <td class="px-4 py-4">
              <code class="text-xs bg-[#edf4ff] text-[#001e40] px-2 py-1 rounded font-mono font-bold"><?= sanitize($c['ticket_number']) ?></code>
            </td>
            <td class="px-6 py-4">
              <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg bg-[#d2e4f9] flex items-center justify-center font-bold text-[#001e40] text-xs flex-shrink-0">
                  <?= strtoupper(substr($c['student_name'], 0, 2)) ?>
                </div>
                <div>
                  <p class="font-bold text-[#001e40] text-xs leading-none"><?= strtoupper(sanitize($c['student_name'])) ?></p>
                  <p class="text-[10px] text-[#43474f] mt-0.5 font-mono"><?= sanitize($c['matric_number']) ?></p>
                </div>
              </div>
            </td>
            <td class="px-4 py-4">
              <span class="px-2 py-1 bg-[#edf4ff] text-[#001e40] font-bold text-xs rounded"><?= sanitize($c['course_code']) ?></span>
              <p class="text-[10px] text-[#43474f] mt-1"><?= sanitize($c['course_title']) ?></p>
            </td>
            <td class="px-4 py-4 text-xs text-[#43474f]"><?= sanitize($c['category']) ?></td>
            <td class="px-4 py-4 text-xs text-[#43474f]"><?= formatDate($c['created_at']) ?></td>
            <td class="px-4 py-4 text-right">
              <a href="<?= BASE_URL ?>level_adviser/review_complaint.php?id=<?= $c['id'] ?>" target="_blank"
                class="p-1.5 hover:bg-[#edf4ff] rounded-lg transition-colors text-[#43474f] hover:text-[#001e40] inline-flex"
                title="Review individually">
                <span class="material-symbols-outlined text-sm">open_in_new</span>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Batch Action Card -->
  <div class="bg-white rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] overflow-hidden">
    <div class="px-6 py-4 bg-[#001e40] flex items-center gap-2">
      <span class="material-symbols-outlined text-[#fecb00] text-base">forward_to_inbox</span>
      <h4 class="font-bold text-white text-sm">Batch Endorse &amp; Forward to HOD</h4>
    </div>
    <div class="p-6">
      <div class="mb-5">
        <label class="block text-xs font-bold text-[#001e40] uppercase tracking-wider mb-2">Endorsement Comment (optional — applies to all selected)</label>
        <textarea name="batch_comment" rows="3"
          class="w-full bg-[#d2e4f9] border-none rounded-xl px-4 py-3 text-[#0b1d2c] focus:ring-2 focus:ring-[#001e40] text-sm resize-none placeholder-[#43474f]/50"
          placeholder="Add a collective remark for these complaints (e.g. 'Reviewed and found credible. Forward for lecturer verification.')"></textarea>
      </div>
      <div class="flex gap-3 items-center flex-wrap">
        <button type="submit" id="batch_endorse_btn" disabled
          data-confirm="Endorse and forward all selected complaints to the HOD?"
          class="flex items-center gap-2 bg-[#001e40] text-white px-6 py-3 rounded-xl font-bold text-sm hover:bg-[#003366] transition-all shadow-lg shadow-[#001e40]/20 disabled:opacity-40 disabled:cursor-not-allowed">
          <span class="material-symbols-outlined text-base">verified</span>
          Endorse &amp; Forward (<span id="batch_count_btn">0</span>)
        </button>
        <p class="text-xs text-[#43474f]">Selected complaints will be marked as <strong>Endorsed</strong> and the HOD will be notified immediately.</p>
      </div>
    </div>
  </div>

</form>
<?php endif; ?>

<script>
(function () {
  const selectAll  = document.getElementById('select_all');
  const checks     = document.querySelectorAll('.complaint-check');
  const countEl    = document.getElementById('batch_count');
  const countBtn   = document.getElementById('batch_count_btn');
  const endorseBtn = document.getElementById('batch_endorse_btn');

  function updateCount() {
    const n = document.querySelectorAll('.complaint-check:checked').length;
    if (countEl)    countEl.textContent    = n;
    if (countBtn)   countBtn.textContent   = n;
    if (endorseBtn) endorseBtn.disabled    = n === 0;
    if (selectAll)  selectAll.indeterminate = n > 0 && n < checks.length;
    if (selectAll)  selectAll.checked      = n === checks.length && checks.length > 0;
  }

  if (selectAll) {
    selectAll.addEventListener('change', () => {
      checks.forEach(c => c.checked = selectAll.checked);
      updateCount();
    });
  }
  checks.forEach(c => c.addEventListener('change', updateCount));
  updateCount();
})();
</script>

<?php include __DIR__ . '/../includes/layout_end.php'; ?>

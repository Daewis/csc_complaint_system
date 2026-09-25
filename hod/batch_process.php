<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('hod');
$user = currentUser();
$db   = getDB();

$success = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ids     = $_POST['complaint_ids'] ?? [];
    $action  = $_POST['batch_action'] ?? '';
    $comment = trim($_POST['batch_comment'] ?? '');
    $ip      = getClientIP();
    $now     = date('Y-m-d H:i:s');

    if (empty($ids)) { $error = 'No complaints selected.'; }
    elseif (!in_array($action, ['approve','reject'])) { $error = 'Invalid action.'; }
    else {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge(array_map('intval', $ids), [$user['department']]);
        $verifiedComplaints = $db->prepare("
            SELECT c.id, c.ticket_number, c.student_id FROM complaints c
            JOIN users u ON c.student_id = u.id
            WHERE c.id IN ($placeholders) AND u.department = ? AND c.status = 'verified'
        ");
        $verifiedComplaints->execute($params);
        $toProcess = $verifiedComplaints->fetchAll();
        $count = 0;
        foreach ($toProcess as $comp) {
            $newStatus = $action === 'approve' ? 'approved' : 'rejected';
            $db->prepare('UPDATE complaints SET status=?, hod_final_comment=?, hod_final_signed_at=?, hod_final_ip=?, resolved_at=?, updated_at=NOW() WHERE id=?')
               ->execute([$newStatus, $comment, $now, $ip, $now, $comp['id']]);
            logAudit($comp['id'], $user['id'], 'batch_' . $action, $ip, 'Batch processed by HOD.');
            $msg = $action === 'approve'
                ? "Your complaint #{$comp['ticket_number']} has been approved and resolved via batch processing."
                : "Your complaint #{$comp['ticket_number']} has been rejected. Reason: $comment";
            addNotification($comp['student_id'], $comp['id'], $msg);
            $count++;
        }
        $success = "$count complaint(s) batch " . ($action === 'approve' ? 'approved' : 'rejected') . ".";
    }
}

$stmt = $db->prepare("
    SELECT c.*, co.course_code, u.full_name as student_name, u.matric_number, u.level,
           lec.full_name as lec_name
    FROM complaints c JOIN courses co ON c.course_id = co.id JOIN users u ON c.student_id = u.id
    LEFT JOIN users lec ON c.lecturer_id = lec.id
    WHERE u.department = ? AND c.status = 'verified' ORDER BY c.created_at ASC
");
$stmt->execute([$user['department']]);
$verified = $stmt->fetchAll();

$pageTitle = 'Batch Processing';
$activeNav = 'batch_process.php';
include __DIR__ . '/../includes/layout.php';
?>

<!-- Header -->
<div class="mb-6 flex items-center gap-3">
  <a href="<?= BASE_URL ?>hod/dashboard.php" class="text-[#43474f] hover:text-[#001e40] transition-colors">
    <span class="material-symbols-outlined">arrow_back</span>
  </a>
  <h2 class="text-2xl font-black text-[#001e40]">Batch Processing</h2>
  <span class="text-sm bg-[#edf4ff] text-[#001e40] px-3 py-1 rounded-full font-bold"><?= count($verified) ?> verified complaint(s)</span>
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

<?php if (empty($verified)): ?>
<div class="bg-[#ffffff] rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] flex flex-col items-center py-20 text-[#43474f]">
  <span class="material-symbols-outlined text-5xl opacity-30 mb-3">done_all</span>
  <p class="font-bold text-sm">No verified complaints pending batch approval.</p>
  <p class="text-xs mt-1">All caught up! Verified complaints will appear here.</p>
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
  </div>

  <!-- Table -->
  <div class="bg-[#ffffff] rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] overflow-hidden mb-6">
    <div class="overflow-x-auto">
      <table class="w-full text-left border-collapse text-sm">
        <thead>
          <tr class="bg-[#f7f9ff] text-[#43474f] text-[10px] uppercase tracking-[0.12em] font-bold">
            <th class="px-4 py-4 w-10"></th>
            <th class="px-4 py-4">Ticket</th>
            <th class="px-6 py-4">Student</th>
            <th class="px-4 py-4">Course</th>
            <th class="px-4 py-4">Category</th>
            <th class="px-4 py-4">Level</th>
            <th class="px-4 py-4">Verified By</th>
            <th class="px-4 py-4">Score Change</th>
            <th class="px-4 py-4 text-right"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($verified as $c): ?>
          <tr class="hover:bg-[#f7f9ff] transition-colors border-t border-[#d2e4f9]/30">
            <td class="px-4 py-4">
              <input type="checkbox" name="complaint_ids[]" value="<?= $c['id'] ?>"
                class="complaint-check w-4 h-4 rounded accent-[#001e40]">
            </td>
            <td class="px-4 py-4">
              <code class="text-xs bg-[#edf4ff] text-[#001e40] px-2 py-1 rounded font-mono font-bold"><?= sanitize($c['ticket_number']) ?></code>
            </td>
            <td class="px-6 py-4">
              <p class="font-bold text-[#001e40] text-xs"><?= strtoupper(sanitize($c['student_name'])) ?></p>
              <p class="text-[10px] text-[#43474f] font-mono"><?= sanitize($c['matric_number']) ?></p>
            </td>
            <td class="px-4 py-4 text-xs font-bold text-[#001e40]"><?= sanitize($c['course_code']) ?></td>
            <td class="px-4 py-4 text-xs text-[#43474f]"><?= sanitize($c['category']) ?></td>
            <td class="px-4 py-4 text-xs font-bold text-[#001e40]"><?= sanitize($c['level']) ?></td>
            <td class="px-4 py-4 text-xs text-[#43474f]"><?= sanitize($c['lec_name'] ?? '—') ?></td>
            <td class="px-4 py-4 text-xs">
              <?php if ($c['corrected_ca_score'] !== null): ?>
                <span class="text-[#001e40]">CA: <?= $c['original_ca_score'] ?>→<strong class="text-green-700"><?= $c['corrected_ca_score'] ?></strong></span><br>
                <span class="text-[#001e40]">Exam: <?= $c['original_exam_score'] ?>→<strong class="text-green-700"><?= $c['corrected_exam_score'] ?></strong></span>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td class="px-4 py-4 text-right">
              <a href="<?= BASE_URL ?>hod/review_complaint.php?id=<?= $c['id'] ?>" target="_blank"
                class="p-1.5 hover:bg-[#edf4ff] rounded-lg transition-colors text-[#43474f] hover:text-[#001e40] inline-flex">
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
  <div class="bg-[#ffffff] rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] overflow-hidden">
    <div class="px-6 py-4 bg-[#001e40] flex items-center gap-2">
      <span class="material-symbols-outlined text-[#fecb00] text-base">draw</span>
      <h4 class="font-bold text-white text-sm">Batch Action — HOD Digital Signature</h4>
    </div>
    <div class="p-6">
      <div class="mb-5">
        <label class="block text-xs font-bold text-[#001e40] uppercase tracking-wider mb-2">Comment / Directive (applies to all selected)</label>
        <textarea name="batch_comment" rows="2"
          class="w-full bg-[#d2e4f9] border-none rounded-xl px-4 py-3 text-[#0b1d2c] focus:ring-2 focus:ring-[#001e40] text-sm resize-none placeholder-[#43474f]/50"
          placeholder="Optional remarks for batch action..."></textarea>
      </div>
      <div class="flex gap-3 flex-wrap">
        <button type="submit" name="batch_action" value="approve" id="batch_sign_btn" disabled
          data-confirm="Approve all selected complaints?"
          class="flex items-center gap-2 bg-green-600 text-white px-6 py-3 rounded-xl font-bold text-sm hover:bg-green-700 transition-all shadow-lg shadow-green-600/20 disabled:opacity-40 disabled:cursor-not-allowed">
          <span class="material-symbols-outlined text-base">done_all</span>Batch Approve (<span id="batch_count">0</span>)
        </button>
        <button type="submit" name="batch_action" value="reject" id="batch_reject_btn" disabled
          data-confirm="Reject all selected complaints?"
          class="flex items-center gap-2 bg-red-600 text-white px-6 py-3 rounded-xl font-bold text-sm hover:bg-red-700 transition-all disabled:opacity-40 disabled:cursor-not-allowed">
          <span class="material-symbols-outlined text-base">cancel</span>Batch Reject
        </button>
      </div>
    </div>
  </div>
</form>
<?php endif; ?>

<?php include __DIR__ . '/../includes/layout_end.php'; ?>

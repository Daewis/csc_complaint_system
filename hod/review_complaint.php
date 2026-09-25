<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();
$user = currentUser();
if (!$user['is_hod']) {
    header('Location: ' . BASE_URL . 'dashboard.php');
    exit;
}

$db = getDB();
$id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare('
    SELECT c.*,
           co.course_code, co.course_title,
           u.full_name  as student_name,  u.matric_number, u.department,
           u.faculty,   u.level,
           u.email      as student_email, u.signature_path as student_sig,
           la.full_name as la_name,       la.signature_path as la_sig,  la.title as la_title,
           lec.full_name as lec_name,     lec.signature_path as lec_sig,
           al.full_name  as assigned_lec_name
    FROM complaints c
    JOIN  courses co ON c.course_id   = co.id
    JOIN  users   u  ON c.student_id  = u.id
    LEFT JOIN users la  ON c.level_adviser_id        = la.id
    LEFT JOIN users lec ON c.lecturer_id             = lec.id
    LEFT JOIN users al  ON c.hod_assigned_lecturer_id = al.id
    WHERE c.id = ?
');
$stmt->execute([$id]);
$c = $stmt->fetch();

if (!$c || $c['department'] !== $user['department']) {
    header('Location: ' . BASE_URL . 'hod/dashboard.php');
    exit;
}

$lecStmt = $db->prepare("
    SELECT u.id, u.full_name, u.PF_NO, u.title
    FROM users u
    JOIN course_assignments ca ON ca.lecturer_id = u.id
    WHERE ca.course_id = ? AND u.is_active = 1
    ORDER BY u.full_name
");
$lecStmt->execute([$c['course_id']]);
$lecturers = $lecStmt->fetchAll();

$success = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action'] ?? '';
    $comment = trim($_POST['comment'] ?? '');
    $ip      = getClientIP();
    $now     = date('Y-m-d H:i:s');

   if ($action === 'assign_lecturer' && $c['status'] === 'endorsed') {
    $lecId = (int)($_POST['lecturer_id'] ?? 0);
    if (!$lecId) {
        $error = 'Please select a lecturer.';
    } else {
        $sla = date('Y-m-d H:i:s', strtotime('+72 hours'));
        $db->prepare("UPDATE complaints SET status='assigned_to_lecturer', hod_id=?, hod_comment=?, hod_assigned_lecturer_id=?, hod_signed_at=?, hod_ip=?, sla_deadline=?, updated_at=NOW() WHERE id=?")
           ->execute([$user['id'], $comment, $lecId, $now, $ip, $sla, $id]);
        logAudit($id, $user['id'], 'assigned_to_lecturer', $ip, "HOD assigned to lecturer ID $lecId.");

        // Category-aware notification
        $notifMsg = $c['category'] === 'Appeal for Course Upgrade'
            ? "HOD has assigned complaint #{$c['ticket_number']} to you for grade upgrade recommendation."
            : "HOD assigned complaint #{$c['ticket_number']} to you for verification.";
        addNotification($lecId, $id, $notifMsg);

        $success = 'Complaint assigned to lecturer for verification.';
    }

    } elseif ($action === 'approve' && $c['status'] === 'verified') {
        $db->prepare("UPDATE complaints SET status='approved', hod_final_comment=?, hod_final_signed_at=?, hod_final_ip=?, resolved_at=?, updated_at=NOW() WHERE id=?")
           ->execute([$comment, $now, $ip, $now, $id]);
        logAudit($id, $user['id'], 'approved', $ip, 'HOD approved and resolved the complaint.');
        addNotification($c['student_id'], $id, "Your complaint #{$c['ticket_number']} has been approved and resolved.");
        $success = 'Complaint approved and resolved.';

    } elseif ($action === 'reject') {
        $db->prepare("UPDATE complaints SET status='rejected', hod_final_comment=?, hod_final_signed_at=?, hod_final_ip=?, updated_at=NOW() WHERE id=?")
           ->execute([$comment, $now, $ip, $id]);
        logAudit($id, $user['id'], 'rejected', $ip, 'HOD rejected the complaint.');
        addNotification($c['student_id'], $id, "Your complaint #{$c['ticket_number']} has been rejected. Reason: $comment");
        $success = 'Complaint rejected.';
    }

    $stmt->execute([$id]);
    $c = $stmt->fetch();
}

// ── Letter body paragraphs ────────────────────────────────────────────────
$letterBodyDecoded = null;
if (!empty($c['letter_body'])) {
    $decoded = json_decode($c['letter_body'], true);
    if (json_last_error() === JSON_ERROR_NONE && isset($decoded['intro'])) {
        $letterBodyDecoded = $decoded;
    }
}
$defaultIntro   = "I, {$c['student_name']}, Matriculation Number {$c['matric_number']}, Level {$c['level']} student in the Department of {$c['department']}, wish to formally report a discrepancy in my academic result for {$c['course_code']} – {$c['course_title']}.";
$defaultClosing = "I humbly request that this matter be investigated and the necessary correction effected through appropriate channels. Thank you for your kind attention.";
$defaultSubject = "COMPLAINT ON " . strtoupper($c['category']) . " – " . $c['course_code'] . " (" . $c['academic_session'] . ", " . strtoupper($c['semester']) . " SEMESTER)";

$introText   = $letterBodyDecoded['intro']   ?? $defaultIntro;
$closingText = $letterBodyDecoded['closing'] ?? $defaultClosing;
$subjectText = $letterBodyDecoded['subject'] ?? $defaultSubject;

$isCourseUpgrade = (trim($c['category']) === 'Appeal for Course Upgrade');

// ── Signature helpers ─────────────────────────────────────────────────────
$studentSigUrl = (!empty($c['student_sig']) && file_exists(__DIR__ . '/../' . $c['student_sig']))
    ? BASE_URL . $c['student_sig'] : null;

$laSigUrl = (!empty($c['la_sig']) && file_exists(__DIR__ . '/../' . $c['la_sig']))
    ? BASE_URL . $c['la_sig'] : null;

$hodSigPath = $user['signature_path'] ?? null;
$hodSigUrl  = ($hodSigPath && file_exists(__DIR__ . '/../' . $hodSigPath))
    ? BASE_URL . $hodSigPath : null;

$lecSigUrl = (!empty($c['lec_sig']) && file_exists(__DIR__ . '/../' . $c['lec_sig']))
    ? BASE_URL . $c['lec_sig'] : null;

$laTitle       = $c['la_title'] ?? '';
$laDisplayName = trim(($laTitle ? $laTitle . ' ' : '') . ($c['la_name'] ?? ''));
$faculty       = $c['faculty'] ?? '';

// Visibility gates
$laEndorsed  = !empty($c['la_signed_at']);
$hodSigned   = !empty($c['hod_signed_at']);   // HOD sig shows once they've acted
$lecVerified = in_array($c['status'], ['verified', 'approved', 'rejected']);

$courseLecStmt = $db->prepare("
    SELECT u.full_name, u.email FROM users u
    JOIN course_assignments ca ON ca.lecturer_id = u.id
    WHERE ca.course_id = ? ORDER BY u.full_name
");
$courseLecStmt->execute([$c['course_id']]);
$courseLecturers = $courseLecStmt->fetchAll();

$pageTitle = 'HOD Review – ' . $c['ticket_number'];
$activeNav = 'dashboard.php';
include __DIR__ . '/../includes/layout.php';
?>

<style>
@media print {
  body > *, body * { visibility:hidden !important; }
  #printable-letter, #printable-letter * { visibility:visible !important; }
  #printable-letter {
    position:fixed !important; top:0 !important; left:0 !important;
    width:100% !important; margin:0 !important; padding:0 !important;
    box-shadow:none !important; border:none !important;
    border-radius:0 !important; z-index:99999 !important;
  }
  @page { margin:0; }
}
</style>

<!-- PAGE HEADER -->
<div class="mb-6 flex items-center gap-3">
  <a href="<?= BASE_URL ?>hod/dashboard.php" class="text-[#43474f] hover:text-[#001e40] transition-colors">
    <span class="material-symbols-outlined">arrow_back</span>
  </a>
  <h2 class="text-2xl font-black text-[#001e40]">HOD Review</h2>
  <code class="text-sm bg-[#edf4ff] text-[#001e40] px-3 py-1 rounded-lg font-mono font-bold"><?= sanitize($c['ticket_number']) ?></code>
  <?= statusBadge($c['status']) ?>
  <div class="ml-auto flex items-center gap-2">
    <?php if (in_array($c['status'], ['approved','rejected','verified','endorsed'])): ?>
    <a href="<?= BASE_URL ?>download_letter.php?id=<?= $id ?>"
       class="flex items-center gap-1 bg-[#fecb00] text-[#001e40] px-3 py-2 text-xs font-bold rounded-lg hover:bg-[#f1c100] transition-colors">
      <span class="material-symbols-outlined text-sm">download</span>Download PDF
    </a>
    <a href="<?= BASE_URL ?>hod/generate_letter.php?id=<?= $id ?>" target="_blank"
       class="flex items-center gap-1 bg-[#001e40] text-white px-3 py-2 text-xs font-bold rounded-lg hover:bg-[#003366] transition-colors">
      <span class="material-symbols-outlined text-sm">description</span>View Official Letter
    </a>
    <?php endif; ?>
    <button onclick="window.print()"
      class="flex items-center gap-1 text-[#43474f] hover:text-[#001e40] text-xs font-bold transition-colors p-2 hover:bg-[#edf4ff] rounded-lg">
      <span class="material-symbols-outlined text-sm">print</span>Print
    </button>
  </div>
</div>

<?php if ($success): ?>
<div class="mb-5 flex items-center gap-3 bg-green-50 text-green-800 border border-green-200 px-5 py-4 rounded-xl text-sm font-medium">
  <span class="material-symbols-outlined text-base">check_circle</span><?= sanitize($success) ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="mb-5 flex items-center gap-3 bg-[#ffdad6] text-[#93000a] px-5 py-4 rounded-xl text-sm font-medium">
  <span class="material-symbols-outlined text-base">error</span><?= sanitize($error) ?>
</div>
<?php endif; ?>

<div class="grid grid-cols-12 gap-6">

  <!-- LEFT: Letter + Actions -->
  <div class="col-span-12 lg:col-span-8 space-y-5">

    <!-- ══ PRINTABLE LETTER ══ -->
    <div id="printable-letter"
         style="font-family:'Times New Roman',Times,serif;font-size:13px;line-height:1.75;
                border:0.5px solid #e2e8f0;border-radius:8px;overflow:hidden;background:#fff;
                box-shadow:0 4px 30px rgba(0,0,0,0.06);">

      <!-- Tri-colour TOP -->
      <div style="height:5px;background:#c0392b;"></div>
      <div style="height:6px;background:#001e40;"></div>
      <div style="height:5px;background:#fecb00;"></div>

      <!-- Letterhead -->
      <div style="display:flex;align-items:center;padding:14px 28px;border-bottom:2px solid #001e40;gap:16px;">
        <img src="<?= BASE_URL ?>assets/img/lasu_logo.jpg" alt="LASU Crest"
             style="width:60px;height:60px;object-fit:contain;flex-shrink:0;border-radius:8px;">
        <div style="flex:1;text-align:center;">
          <div style="font-family:Arial,sans-serif;font-size:17px;font-weight:900;color:#001e40;
                      text-transform:uppercase;letter-spacing:0.4px;line-height:1.2;">Lagos State University, Ojo</div>
          <div style="font-family:Arial,sans-serif;font-size:10.5px;color:#444;margin-top:3px;">Department of <?= sanitize($c['department']) ?></div>
          <div style="font-family:Arial,sans-serif;font-size:10px;color:#666;">Result Complaint Management Office</div>
        </div>
        <div style="text-align:right;font-size:9px;color:#444;line-height:1.6;min-width:130px;">
          <strong style="color:#001e40;">Lagos State University,</strong><br>
          Badagry Expressway,<br>P.M.B. 0001, Ojo, Lagos.<br>
          <span style="color:#001e40;font-weight:700;">web:</span> www.lasu.edu.ng
        </div>
      </div>

      <!-- Letter body -->
      <div style="padding:2rem 2.5rem;position:relative;">

        <!-- Watermark -->
        <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-55%);
                    width:220px;height:220px;
                    background:url('<?= BASE_URL ?>assets/img/lasu_logo.jpg') center/contain no-repeat;
                    opacity:0.055;pointer-events:none;z-index:0;"></div>

        <div style="position:relative;z-index:1;">

          <!-- Ref + Date -->
          <div style="display:flex;justify-content:space-between;margin-bottom:20px;font-size:12px;">
            <div style="color:#666;">Ref: <strong><?= sanitize($c['ticket_number']) ?></strong></div>
            <div><strong>Date:</strong> <?= formatDate($c['created_at']) ?></div>
          </div>

          <!-- To: HOD — HOD sig appears beside address once they've acted -->
          <div style="display:flex;justify-content:flex-start;align-items:flex-end;gap:48px;margin-bottom:24px;">
            <div style="font-family:'Times New Roman',Times,serif;font-size:12.5px;line-height:1.8;">
              <p style="margin:0 0 4px;"><strong>To:</strong></p>
              <p style="margin:0;">The Head of Department,</p>
              <p style="margin:0;">Department of <?= sanitize($c['department']) ?>,</p>
              <?php if ($faculty): ?>
              <p style="margin:0;"><?= sanitize($faculty) ?>,</p>
              <?php endif; ?>
              <p style="margin:0;">Lagos State University, Ojo,</p>
              <p style="margin:0;">Lagos State.</p>
            </div>

            <!-- HOD sig slot — shown once HOD has acted (hod_signed_at set) -->
            <?php if ($hodSigned): ?>
            <div style="min-width:160px;">
              <div style="height:56px;display:flex;align-items:flex-end;padding-bottom:4px;">
                <?php if ($hodSigUrl): ?>
                  <img src="<?= $hodSigUrl ?>" alt="HOD Signature"
                       style="max-height:52px;max-width:150px;object-fit:contain;mix-blend-mode:multiply;">
                <?php else: ?>
                  <div style="width:150px;"></div>
                <?php endif; ?>
              </div>
              <div style="border-top:1.5px solid #333;padding-top:5px;">
                <p style="font-weight:bold;font-size:12px;margin:0 0 1px;color:#001e40;"><?= sanitize($user['full_name']) ?></p>
                <p style="font-size:10px;font-style:italic;color:#555;margin:0;">
                  Head of Department, <?= sanitize($c['department']) ?>
                </p>
                <p style="font-size:9px;color:#888;margin:2px 0 0;font-family:Arial,sans-serif;">
                  <?= formatDate($c['hod_signed_at']) ?>
                </p>
              </div>
            </div>
            <?php else: ?>
            <div style="min-width:160px;"></div>
            <?php endif; ?>
          </div>

          <!-- Through: Level Adviser — LA sig appears beside address once endorsed -->
          <div style="display:flex;justify-content:flex-start;align-items:flex-end;gap:48px;margin-bottom:32px;">
            <div style="font-family:'Times New Roman',Times,serif;font-size:12.5px;line-height:1.8;">
              <p style="margin:0 0 4px;"><strong>Through:</strong></p>
              <p style="margin:0;">Level Adviser (<?= sanitize($c['level']) ?> Level),</p>
              <p style="margin:0;">Department of <?= sanitize($c['department']) ?>,</p>
              <?php if ($faculty): ?>
              <p style="margin:0;"><?= sanitize($faculty) ?>,</p>
              <?php endif; ?>
              <p style="margin:0;">Lagos State University, Ojo,</p>
              <p style="margin:0;">Lagos State.</p>
            </div>

            <!-- LA sig — always visible at HOD stage (complaint is already endorsed) -->
            <?php if ($laEndorsed): ?>
            <div style="min-width:160px;">
              <div style="height:56px;display:flex;align-items:flex-end;padding-bottom:4px;">
                <?php if ($laSigUrl): ?>
                  <img src="<?= $laSigUrl ?>" alt="Level Adviser Signature"
                       style="max-height:52px;max-width:150px;object-fit:contain;mix-blend-mode:multiply;">
                <?php else: ?>
                  <div style="width:150px;"></div>
                <?php endif; ?>
              </div>
              <div style="border-top:1.5px solid #333;padding-top:5px;">
                <p style="font-weight:bold;font-size:12px;margin:0 0 1px;color:#001e40;">
                  <?= sanitize($laDisplayName ?: 'Level Adviser') ?>
                </p>
                <p style="font-size:10px;font-style:italic;color:#555;margin:0;">
                  Level Adviser, <?= sanitize($c['department']) ?>
                </p>
                <?php if (!empty($c['la_signed_at'])): ?>
                  <p style="font-size:9px;color:#888;margin:2px 0 0;font-family:Arial,sans-serif;">
                    <?= formatDate($c['la_signed_at']) ?>
                  </p>
                <?php endif; ?>
              </div>
            </div>
            <?php else: ?>
            <div style="min-width:160px;"></div>
            <?php endif; ?>
          </div>

          <!-- Salutation -->
          <p style="margin-bottom:14px;">Dear Sir/Ma,</p>

          <!-- Subject -->
          <p style="text-align:center;font-weight:bold;font-size:13px;text-decoration:underline;
                    text-transform:uppercase;margin:10px 0 16px;letter-spacing:0.3px;">
            <?= sanitize($subjectText) ?>
          </p>

          <!-- Body -->
          <p style="text-align:justify;margin-bottom:12px;"><?= nl2br(sanitize($introText)) ?></p>
          <p style="text-align:justify;margin-bottom:32px;"><?= nl2br(sanitize($closingText)) ?></p>

          <p style="margin-bottom:48px;">Yours faithfully,</p>

          

          <!-- Bottom signature row: Student | Lecturer + Score aligned close together -->
<div style="display:flex; flex-wrap:wrap; gap:48px; align-items:flex-end; margin-top:32px;">

  <!-- ① Student -->
  <div style="min-width:150px;">
    <div style="height:56px;display:flex;align-items:flex-end;padding-bottom:4px;">
      <?php if ($studentSigUrl): ?>
        <img src="<?= $studentSigUrl ?>" alt="Student Signature"
             style="height:48px;max-width:150px;object-fit:contain;mix-blend-mode:multiply;">
      <?php else: ?>
        <div style="height:1px;width:150px;"></div>
      <?php endif; ?>
    </div>
    <!-- Shortened solid line (width: 120px) -->
    <div style="border-top:1.5px solid #333; padding-top:5px; width: 120px;">
      <p style="font-weight:bold;font-size:12px;margin:0 0 1px;white-space:nowrap;"><?= sanitize($c['student_name']) ?></p>
      <p style="font-size:10px;font-style:italic;color:#555;margin:0;white-space:nowrap;">
        <?= sanitize($c['matric_number']) ?> &bull; Level <?= sanitize($c['level']) ?>
      </p>
    </div>
  </div>

  <!-- ② Lecturer sig — sitting close to the score box -->
  <?php if ($lecVerified && !empty($c['lec_name'])): ?>
  <div style="min-width:150px;">
    <div style="height:56px;display:flex;align-items:flex-end;padding-bottom:4px;">
      <?php if ($lecSigUrl): ?>
        <img src="<?= $lecSigUrl ?>" alt="Lecturer Signature"
             style="height:48px;max-width:150px;object-fit:contain;mix-blend-mode:multiply;">
      <?php else: ?>
        <div style="height:1px;width:150px;"></div>
      <?php endif; ?>
    </div>
    <!-- Shortened solid line (width: 120px) -->
    <div style="border-top:1.5px solid #333; padding-top:5px; width: 120px;">
      <p style="font-weight:bold;font-size:12px;margin:0 0 1px;white-space:nowrap;"><?= sanitize($c['lec_name']) ?></p>
      <p style="font-size:10px;font-style:italic;color:#555;margin:0;">Course Lecturer</p>
    </div>
  </div>
  <?php endif; ?>

  <!-- ③ Score correction box — now close to the Lecturer sig -->
  <?php if ($lecVerified && ($c['original_ca_score'] !== null || $c['original_exam_score'] !== null)): ?>
  <div style="border:1.2px solid #001e40; padding:10px 14px; border-radius:6px; margin-bottom:2px;">
    <p style="font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:0.1em;
              color:#001e40;margin:0 0 8px;">Score Correction Detail</p>
    <div style="display:flex;gap:24px;">
      <div>
        <p style="font-size:8px;color:#555;text-transform:uppercase;margin:0 0 2px;font-weight:bold;">CA Score</p>
        <p style="font-weight:bold;font-size:12px;margin:0;color:#000;">
          <?= $c['original_ca_score'] ?? '—' ?> &rarr; <span style="color:#15803d;"><?= $c['corrected_ca_score'] ?? '—' ?></span>
        </p>
      </div>
      <div>
        <p style="font-size:8px;color:#555;text-transform:uppercase;margin:0 0 2px;font-weight:bold;">Exam Score</p>
        <p style="font-weight:bold;font-size:12px;margin:0;color:#000;">
          <?= $c['original_exam_score'] ?? '—' ?> &rarr; <span style="color:#15803d;"><?= $c['corrected_exam_score'] ?? '—' ?></span>
        </p>
      </div>
    </div>
  </div>
  <?php endif; ?>
    
  <!-- ④ Upgrade recommendation badge — course upgrade only -->
<?php if ($lecVerified && $isCourseUpgrade && !empty($c['upgrade_recommendation'])): ?>
<div style="border:1.2px solid #001e40;padding:10px 14px;border-radius:6px;margin-bottom:2px;">
  <p style="font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:0.1em;color:#001e40;margin:0 0 4px;">
    Lecturer Recommendation
  </p>

  <p style="font-weight:bold;font-size:12px;margin:0;color:#000;">
    <?= $c['upgrade_recommendation'] === 'eligible'
        ? '✓ Student Eligible'
        : '✗ Not Eligible' ?>
  </p>
</div>
<?php endif; ?>
</div><!-- /flex wrapper -->


        </div><!-- /z-index wrapper -->
      </div><!-- /letter body -->

      <!-- Footer -->
      <div style="text-align:center;font-size:9px;color:#555;padding:5px 20px;border-top:1px solid #ddd;font-family:Arial,sans-serif;">
        Lagos State University, Badagry Expressway, P.M.B. 0001, Ojo, Lagos, Nigeria. &bull; www.lasu.edu.ng
      </div>
      <div style="height:5px;background:#001e40;"></div>
      <div style="height:5px;background:#fecb00;"></div>

    </div><!-- /printable-letter -->


    <!-- COMPLAINT DETAILS -->
    <div class="bg-white rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] overflow-hidden">
      <div class="px-6 py-4 bg-[#edf4ff]/50 flex items-center gap-2">
        <span class="material-symbols-outlined text-[#001e40] text-base">notes</span>
        <h4 class="font-bold text-[#001e40] text-sm">Complaint Details</h4>
      </div>
      <div class="p-6">
        <div class="bg-[#f7f9ff] rounded-xl p-5 border-l-4 border-[#001e40]">
          <p class="text-sm text-[#0b1d2c] leading-relaxed"><?= nl2br(sanitize($c['complaint_text'])) ?></p>
        </div>
        <?php if ($c['evidence_path']): ?>
        <a href="<?= BASE_URL . sanitize($c['evidence_path']) ?>" target="_blank"
           class="inline-flex items-center gap-2 mt-4 bg-[#edf4ff] text-[#001e40] px-4 py-2.5 rounded-xl text-xs font-bold hover:bg-[#d2e4f9] transition-all">
          <span class="material-symbols-outlined text-sm">attach_file</span>View Attached Evidence
        </a>
        <?php endif; ?>
      </div>
    </div>

    <!-- ACTION PANELS -->

    <!-- Assign lecturer -->
    <?php if ($c['status'] === 'endorsed' && $c['category'] !== 'Appeal for Course Removal'): ?>
    <div class="bg-white rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] overflow-hidden">
      <div class="px-6 py-4 bg-[#edf4ff]/50 flex items-center gap-2">
        <span class="material-symbols-outlined text-[#001e40] text-base">assignment_ind</span>
        <h4 class="font-bold text-[#001e40] text-sm">Assign to Lecturer for Verification</h4>
      </div>
      <div class="p-6">
        <form method="POST" class="space-y-5">
          <div>
            <label class="block text-xs font-bold text-[#001e40] uppercase tracking-wider mb-2">
              Select Lecturer <span class="text-red-500">*</span>
            </label>
            <?php if (empty($lecturers)): ?>
              <div class="bg-[#ffdad6]/30 border border-red-200 rounded-xl px-4 py-3 text-xs text-red-700 font-medium">
                No lecturers assigned to <strong><?= sanitize($c['course_code']) ?></strong> yet.
                Please assign via Course Management.
              </div>
            <?php else: ?>
            <div class="relative">
              <select name="lecturer_id" required
                class="w-full bg-[#f7f9ff] border-none rounded-xl px-4 py-3 text-sm font-bold focus:ring-2 focus:ring-[#001e40] appearance-none">
                <option value="">— Select Course Lecturer —</option>
                <?php foreach ($lecturers as $l): ?>
                <option value="<?= $l['id'] ?>">
                  <?= sanitize(($l['title'] ? $l['title'] . ' ' : '') . $l['full_name']) ?>
                  <?php if ($l['PF_NO']): ?>(<?= sanitize($l['PF_NO']) ?>)<?php endif; ?>
                </option>
                <?php endforeach; ?>
              </select>
              <span class="material-symbols-outlined absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none text-[#43474f] text-lg">expand_more</span>
            </div>
            <?php endif; ?>
          </div>
          <div>
            <label class="block text-xs font-bold text-[#001e40] uppercase tracking-wider mb-2">HOD Directive</label>
            <textarea name="comment" rows="3"
              class="w-full bg-[#f7f9ff] border-none rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-[#001e40] resize-none placeholder-[#43474f]/50"
              placeholder="Optional instructions to the lecturer..."></textarea>
          </div>
          <div class="flex items-center gap-2 text-xs text-[#43474f]">
            <span class="material-symbols-outlined text-sm">timer</span>
            Lecturer has <strong>72 hours</strong> before this is flagged overdue.
          </div>
          <?php if (!empty($lecturers)): ?>
          <button type="submit" name="action" value="assign_lecturer"
            onclick="return confirm('Assign this complaint to the selected lecturer?')"
            class="flex items-center gap-2 bg-[#001e40] text-white px-6 py-3 rounded-xl font-bold text-sm hover:bg-[#003366] transition-all shadow-lg shadow-[#001e40]/20">
            <span class="material-symbols-outlined text-base">send</span>Request Verification
          </button>
          <?php endif; ?>
        </form>
      </div>
    </div>

      <!-- Course Removal: HOD approves/rejects directly (no lecturer needed) -->
<?php elseif ($c['status'] === 'endorsed' && $c['category'] === 'Appeal for Course Removal'): ?>
<div class="bg-white rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] overflow-hidden">
  <div class="px-6 py-4 bg-[#edf4ff]/50 flex items-center gap-2">
    <span class="material-symbols-outlined text-[#001e40] text-base">gavel</span>
    <h4 class="font-bold text-[#001e40] text-sm">Course Removal — Final Decision</h4>
  </div>
  <div class="p-6">

    <?php if (!empty($c['ai_validation_status'])): ?>
    <div class="mb-5 flex items-start gap-3 px-4 py-3 rounded-xl text-sm font-medium
      <?= match($c['ai_validation_status']) {
        'passed'        => 'bg-green-50 text-green-800 border border-green-200',
        'failed'        => 'bg-[#ffdad6] text-[#93000a] border border-red-100',
        'manual_review' => 'bg-[#ffe08b]/40 text-[#745b00] border border-[#fecb00]',
        default         => 'bg-[#edf4ff] text-[#001e40]',
      } ?>">
      <span class="material-symbols-outlined text-base flex-shrink-0 mt-0.5">smart_toy</span>
      <div>
        <p class="font-bold text-xs uppercase tracking-wider mb-0.5">
          AI Verification: <?= strtoupper(str_replace('_', ' ', $c['ai_validation_status'])) ?>
        </p>
        <?php if (!empty($c['ai_validation_reason'])): ?>
          <p class="text-xs"><?= sanitize($c['ai_validation_reason']) ?></p>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <form method="POST" class="space-y-5">
      <div>
        <label class="block text-xs font-bold text-[#001e40] uppercase tracking-wider mb-2">
          Decision Comment <span class="text-red-500">*</span>
        </label>
        <textarea name="comment" rows="3" required
          class="w-full bg-[#f7f9ff] border-none rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-[#001e40] resize-none placeholder-[#43474f]/50"
          placeholder="State your ruling on this course removal request..."></textarea>
      </div>
      <div class="flex gap-3 flex-wrap">
        <button type="submit" name="action" value="approve"
          onclick="return confirm('Approve this course removal request?')"
          class="flex items-center gap-2 bg-green-600 text-white px-6 py-3 rounded-xl font-bold text-sm hover:bg-green-700 transition-all">
          <span class="material-symbols-outlined text-base">task_alt</span>Approve Removal
        </button>
        <button type="submit" name="action" value="reject"
          onclick="return confirm('Reject this course removal request?')"
          class="flex items-center gap-2 bg-red-600 text-white px-6 py-3 rounded-xl font-bold text-sm hover:bg-red-700 transition-all">
          <span class="material-symbols-outlined text-base">cancel</span>Reject
        </button>
      </div>
    </form>
  </div>
</div>

   <!-- Final verdict -->
<?php elseif ($c['status'] === 'verified'): ?>
<div class="bg-white rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] overflow-hidden">

  <div class="px-6 py-4 bg-[#edf4ff]/50 flex items-center gap-2">
    <span class="material-symbols-outlined text-[#001e40] text-base">gavel</span>

    <h4 class="font-bold text-[#001e40] text-sm">
      <?= $isCourseUpgrade
          ? 'Course Upgrade — Final Decision'
          : 'Final Verdict' ?>
    </h4>
  </div>

  <div class="p-6 space-y-5">

    <!-- Lecturer recommendation -->
    <?php if ($isCourseUpgrade && !empty($c['upgrade_recommendation'])): ?>

    <div class="flex items-start gap-3 px-4 py-3 rounded-xl border text-sm
      <?= $c['upgrade_recommendation'] === 'eligible'
          ? 'bg-green-50 border-green-200 text-green-800'
          : 'bg-[#ffdad6] border-red-100 text-[#93000a]' ?>">

      <span class="material-symbols-outlined text-base flex-shrink-0 mt-0.5">
        school
      </span>

      <div>
        <p class="font-bold text-xs uppercase tracking-wider mb-0.5">
          Lecturer
          <?= $c['upgrade_recommendation'] === 'eligible'
              ? 'Recommends — Student Eligible'
              : 'Recommends — Not Eligible' ?>
        </p>

        <?php if (!empty($c['lecturer_comment'])): ?>
          <p class="text-xs italic mt-1">
            "<?= sanitize($c['lecturer_comment']) ?>"
          </p>
        <?php endif; ?>

        <p class="text-[10px] opacity-60 mt-1">
          <?= sanitize($c['lec_name'] ?? '') ?>
          &bull;
          <?= formatDate($c['lecturer_signed_at'] ?? '') ?>
        </p>
      </div>
    </div>

    <?php endif; ?>

    <form method="POST" class="space-y-5">

      <div>
        <label class="block text-xs font-bold text-[#001e40] uppercase tracking-wider mb-2">
          Final Comment <span class="text-red-500">*</span>
        </label>

        <textarea
          name="comment"
          rows="3"
          required
          class="w-full bg-[#f7f9ff] border-none rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-[#001e40] resize-none placeholder-[#43474f]/50"
          placeholder="<?= $isCourseUpgrade
              ? 'State your final ruling on this grade upgrade appeal...'
              : 'Provide your final ruling on this complaint...' ?>"></textarea>
      </div>

      <div class="flex gap-3 flex-wrap">

        <button
          type="submit"
          name="action"
          value="approve"
          onclick="return confirm('<?= $isCourseUpgrade
              ? 'Approve this grade upgrade appeal?'
              : 'Approve and resolve this complaint?' ?>')"
          class="flex items-center gap-2 bg-green-600 text-white px-6 py-3 rounded-xl font-bold text-sm hover:bg-green-700 transition-all">

          <span class="material-symbols-outlined text-base">task_alt</span>

          <?= $isCourseUpgrade
              ? 'Approve Upgrade'
              : 'Approve &amp; Resolve' ?>
        </button>

        <button
          type="submit"
          name="action"
          value="reject"
          onclick="return confirm('Reject this <?= $isCourseUpgrade
              ? 'grade upgrade appeal'
              : 'complaint' ?>?')"
          class="flex items-center gap-2 bg-red-600 text-white px-6 py-3 rounded-xl font-bold text-sm hover:bg-red-700 transition-all">

          <span class="material-symbols-outlined text-base">cancel</span>
          Reject
        </button>

      </div>
    </form>

  </div>
</div>

    <!-- Waiting on lecturer -->
    <!-- Waiting on lecturer -->
<?php elseif ($c['status'] === 'assigned_to_lecturer' && $c['category'] !== 'Appeal for Course Removal'): ?>
<div class="flex items-center gap-3 bg-[#fffbeb] border border-[#fecb00] text-[#745b00] px-5 py-4 rounded-xl text-sm font-medium">
  <span class="material-symbols-outlined text-base">hourglass_top</span>
  Awaiting verification from <strong><?= sanitize($c['assigned_lec_name'] ?? 'the assigned lecturer') ?></strong>.
  SLA deadline: <?= formatDate($c['sla_deadline'] ?? '') ?>
</div>

   <?php else: ?>
    <!-- Added flex-wrap and items-center to keep the badge aligned with the text -->
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 bg-[#edf4ff] text-[#001e40] px-5 py-4 rounded-xl text-sm font-medium leading-relaxed">
      <span class="material-symbols-outlined text-base self-start mt-0.5">info</span>

      <span class="flex items-center gap-1">
        This complaint has been fully processed (Status:
        <span class="inline-block transform scale-90 origin-left">
            <?= statusBadge($c['status']) ?>
        </span>
        ). No further action required.
      </span>
    </div>
<?php endif; ?>

  </div><!-- /left col -->


  <!-- RIGHT SIDEBAR -->
  <aside class="col-span-12 lg:col-span-4 space-y-4">

    <!-- Student info -->
    <div class="bg-[#001e40] text-white p-6 rounded-xl">
      <p class="text-[10px] font-bold uppercase tracking-[0.2em] opacity-60 mb-4">Student Information</p>
      <div class="space-y-3 text-sm">
        <div><p class="text-[9px] opacity-60 uppercase font-bold">Name</p><p class="font-bold"><?= sanitize($c['student_name']) ?></p></div>
        <div><p class="text-[9px] opacity-60 uppercase font-bold">Matric No.</p><p class="font-mono font-bold"><?= sanitize($c['matric_number']) ?></p></div>
        <div><p class="text-[9px] opacity-60 uppercase font-bold">Department</p><p class="font-medium"><?= sanitize($c['department']) ?></p></div>
        <div><p class="text-[9px] opacity-60 uppercase font-bold">Level</p><p class="font-medium"><?= sanitize($c['level']) ?> Level</p></div>
        <div><p class="text-[9px] opacity-60 uppercase font-bold">Email</p><p class="font-medium text-[#a7c8ff]"><?= sanitize($c['student_email']) ?></p></div>
      </div>
    </div>

    <!-- Course details -->
    <div class="bg-white rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] p-5">
      <p class="text-xs font-bold text-[#001e40] uppercase tracking-widest mb-3">Course Details</p>
      <p class="font-black text-[#001e40]"><?= sanitize($c['course_code']) ?></p>
      <p class="text-sm text-[#43474f]"><?= sanitize($c['course_title']) ?></p>
      <div class="mt-3 pt-3 border-t border-[#d2e4f9] space-y-1">
        <p class="text-xs text-[#43474f]"><strong>Category:</strong> <?= sanitize($c['category']) ?></p>
        <p class="text-xs text-[#43474f]"><strong>Session:</strong> <?= sanitize($c['academic_session']) ?></p>
        <p class="text-xs text-[#43474f]"><strong>Semester:</strong> <?= sanitize($c['semester']) ?></p>
        <p class="text-xs text-[#43474f]"><strong>Filed:</strong> <?= formatDate($c['created_at']) ?></p>
      </div>
    </div>

    <!-- Assigned course staff -->
    <?php if ($courseLecturers): ?>
    <div class="bg-white rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] p-5">
      <p class="text-xs font-bold text-[#001e40] uppercase tracking-widest mb-4 flex items-center gap-2">
        <span class="material-symbols-outlined text-sm">group</span>Assigned Course Staff
      </p>
      <div class="space-y-3">
        <?php foreach ($courseLecturers as $l): ?>
        <div class="flex items-center gap-3 p-3 bg-[#f7f9ff] rounded-xl">
          <div class="w-8 h-8 rounded-lg bg-[#001e40] text-white flex items-center justify-center text-[10px] font-bold flex-shrink-0">
            <?= strtoupper(substr($l['full_name'], 0, 1)) ?>
          </div>
          <div class="text-xs truncate">
            <p class="font-bold text-[#001e40]"><?= sanitize($l['full_name']) ?></p>
            <p class="text-[#43474f] text-[10px]"><?= sanitize($l['email']) ?></p>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </aside>
</div>

<?php include __DIR__ . '/../includes/layout_end.php'; ?>

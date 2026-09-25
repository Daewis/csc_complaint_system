<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('student');
$user = currentUser();
$db   = getDB();

$id = (int)($_GET['id'] ?? 0);

// ── Course/offering join updated for the normalized schema ───────────────
// courses no longer has course_code/course_title alongside credit_units —
// course_code/course_title live on `courses`, credit_units/level/semester/
// status/curriculum_type live on `course_offerings`. We scope the offering
// to the student's own department so a shared/compulsory course offered
// across multiple departments doesn't return more than one row.
$stmt = $db->prepare('
    SELECT c.*, crs.course_code, crs.course_title, co.credit_units,
           la.full_name  AS la_name,  la.signature_path  AS la_sig,
           hod.full_name AS hod_name, hod.signature_path AS hod_sig,
           lec.full_name AS lec_name, lec.signature_path AS lec_sig,
           al.full_name  AS assigned_lec_name
    FROM complaints c
    JOIN courses crs ON c.course_id = crs.id
    LEFT JOIN course_offerings co
        ON co.course_id = crs.id
        AND co.department_id = (
            SELECT d.id FROM departments d WHERE d.name = ? LIMIT 1
        )
    LEFT JOIN users la  ON c.level_adviser_id        = la.id
    LEFT JOIN users hod ON c.hod_id                  = hod.id
    LEFT JOIN users lec ON c.lecturer_id             = lec.id
    LEFT JOIN users al  ON c.hod_assigned_lecturer_id = al.id
    WHERE c.id = ? AND c.student_id = ?
');
$stmt->execute([$user['department'], $id, $user['id']]);
$c = $stmt->fetch();
if (!$c) { header('Location: ' . BASE_URL . 'student/dashboard.php'); exit; }

$auditStmt = $db->prepare('
    SELECT a.*, u.full_name
    FROM audit_log a JOIN users u ON a.actor_id = u.id
    WHERE a.complaint_id = ?
    ORDER BY a.created_at ASC
');
$auditStmt->execute([$id]);
$auditLog = $auditStmt->fetchAll();

$error = ''; $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $c['status'] === 'returned_to_student') {
    $complaint    = trim($_POST['complaint_text'] ?? $c['complaint_text']);
    $evidencePath = null;

    // Decode letter_body (base64 encoded to avoid WAF)
    $letterBodyRaw = trim($_POST['letter_body'] ?? '');
    $letterBody = '';
    if ($letterBodyRaw) {
        $decoded = base64_decode($letterBodyRaw, true);
        $letterBody = ($decoded !== false) ? $decoded : '';
    }

    if (!empty($_FILES['evidence_file']['name'])) {
        $evidencePath = uploadEvidence($_FILES['evidence_file']);
        if (!$evidencePath) $error = 'Invalid file. Use JPG, PNG, PDF (max 2OOKB).';
    }

    if (!$error) {
        $db->prepare("
            UPDATE complaints
            SET status='pending', complaint_text=?,
                letter_body=COALESCE(NULLIF(?,''),(SELECT letter_body FROM complaints WHERE id=?)),
                evidence_path=COALESCE(?,evidence_path), updated_at=NOW()
            WHERE id=?
        ")->execute([$complaint, $letterBody, $id, $evidencePath, $id]);

        logAudit($id, $user['id'], 'resubmitted', getClientIP(), 'Student resubmitted complaint.');

        $laStmt = $db->prepare("SELECT id FROM users WHERE is_level_adviser=1 AND department=? AND `level`=? LIMIT 1");
        $laStmt->execute([$user['department'], $user['level']]);
        $la = $laStmt->fetch();
        if ($la) addNotification($la['id'], $id, "Student {$user['full_name']} resubmitted complaint #{$c['ticket_number']}.");

        header("Location: " . BASE_URL . "student/view_complaint.php?id={$id}&success=resubmitted");
        exit;
    }

    $stmt->execute([$user['department'], $id, $user['id']]);
    $c = $stmt->fetch();
}

if (!function_exists('generateAuditHash')) {
    function generateAuditHash(int $userId, int $complaintId, string $action, string $timestamp): string {
        return substr(hash('sha256', "$userId|$complaintId|$action|$timestamp"), 0, 16);
    }
}

$letterBodyDecoded = null;
if (!empty($c['letter_body'])) {
    $decoded = json_decode($c['letter_body'], true);
    if (json_last_error() === JSON_ERROR_NONE && isset($decoded['intro'])) {
        $letterBodyDecoded = $decoded;
    }
}
$defaultIntro   = "I, " . $user['full_name'] . ", Matriculation Number " . ($user['matric_number'] ?? 'N/A') . ", Level " . $user['level'] . " student in the Department of " . $user['department'] . ", wish to formally report a discrepancy in my academic result for " . $c['course_code'] . " – " . $c['course_title'] . ".";
$defaultClosing = "I humbly request that this matter be investigated and the necessary correction effected through appropriate channels. Thank you for your kind attention.";
$defaultSubject = "COMPLAINT ON " . strtoupper($c['category']) . " – " . $c['course_code'] . " (" . $c['academic_session'] . ", " . strtoupper($c['semester']) . " SEMESTER)";

$introText   = $letterBodyDecoded['intro']   ?? $defaultIntro;
$closingText = $letterBodyDecoded['closing'] ?? $defaultClosing;
$subjectText = $letterBodyDecoded['subject'] ?? $defaultSubject;

$studentHasSig = !empty($user['signature_path']) && file_exists(__DIR__ . '/../' . $user['signature_path']);
$studentSigUrl = $studentHasSig ? BASE_URL . $user['signature_path'] : '';

// Sig URLs — direct BASE_URL concat, no file_exists (fails on some hosts)
$hodSigUrl = !empty($c['hod_sig']) ? BASE_URL . $c['hod_sig'] : null;
$laSigUrl  = !empty($c['la_sig'])  ? BASE_URL . $c['la_sig']  : null;
$lecSigUrl = !empty($c['lec_sig']) ? BASE_URL . $c['lec_sig'] : null;

$pageTitle = $c['ticket_number'];
$activeNav = 'dashboard.php';
include __DIR__ . '/../includes/layout.php';
?>
<style>
@media print {
  .no-print { display:none !important; }
  body { background:#fff !important; }
}
.audit-hash { font-size:9px; color:#888; font-family:monospace; margin-top:2px; }
</style>

<!-- Page header -->
<div class="mb-6 flex items-center gap-3 no-print">
  <a href="<?= BASE_URL ?>student/dashboard.php" class="text-[#43474f] hover:text-[#001e40] transition-colors">
    <span class="material-symbols-outlined">arrow_back</span>
  </a>
  <h2 class="text-2xl font-black text-[#001e40]">Complaint Detail</h2>
  <code class="text-sm bg-[#edf4ff] text-[#001e40] px-3 py-1 rounded-lg font-mono font-bold"><?= sanitize($c['ticket_number']) ?></code>
  <?= statusBadge($c['status']) ?>
  <button onclick="window.print()"
    class="ml-auto flex items-center gap-1 text-[#43474f] hover:text-[#001e40] text-xs font-bold transition-colors p-2 hover:bg-[#edf4ff] rounded-lg">
    <span class="material-symbols-outlined text-sm">print</span>Print
  </button>
</div>

<?php if (isset($_GET['success'])): ?>
<div class="mb-5 flex items-center gap-3 bg-green-50 text-green-800 border border-green-200 px-5 py-4 rounded-xl text-sm font-medium no-print">
  <span class="material-symbols-outlined text-base">check_circle</span>
  <?= $_GET['success'] === 'filed' ? 'Your complaint has been submitted successfully!' : 'Complaint resubmitted successfully!' ?>
</div>
<?php endif; ?>

<div class="grid grid-cols-12 gap-6">

  <div class="col-span-12 lg:col-span-8 space-y-5">

    <!-- ══ LASU FORMAL LETTER ══ -->
    <div style="max-width:100%;font-family:'Times New Roman',Times,serif;font-size:13px;line-height:1.75;
                border:0.5px solid #e2e8f0;border-radius:8px;overflow:hidden;background:#fff;
                box-shadow:0 4px 30px rgba(0,0,0,0.06);">

      <!-- Tri-colour bar TOP -->
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
          <div style="font-family:Arial,sans-serif;font-size:10.5px;color:#444;margin-top:3px;">Department of <?= sanitize($user['department']) ?></div>
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
          <div style="display:flex;justify-content:space-between;margin-bottom:20px;font-size:12px;border-bottom:1px;">
            <div style="color:#666;">Ref: <strong><?= sanitize($c['ticket_number']) ?></strong></div>
            <div><strong>Date:</strong> <?= formatDate($c['created_at']) ?></div>
          </div>

       <!-- To: HOD -->
<div style="display:flex;justify-content:flex-start;align-items:flex-end;gap:48px;margin-bottom:24px;">
  <div style="font-family:'Times New Roman',Times,serif;font-size:12.5px;line-height:1.8;">
    <p style="margin:0 0 4px;"><strong>To:</strong></p>
    <p style="margin:0;">The Head of Department,</p>
    <p style="margin:0;">Department of <?= sanitize($user['department']) ?>,</p>
    <p style="margin:0;"><?= sanitize($user['faculty']) ?>,</p>
    <p style="margin:0;">Lagos State University, Ojo,</p>
    <p style="margin:0;">Lagos State.</p>
  </div>

  <?php if (!empty($c['hod_name'])): ?>
  <div style="min-width:160px;">
    <div style="height:56px;display:flex;align-items:flex-end;padding-bottom:4px;">
      <?php if (!empty($c['hod_sig'])): ?>
        <img src="<?= BASE_URL ?><?= sanitize($c['hod_sig']) ?>" alt="HOD Signature"
             style="max-height:52px;max-width:150px;object-fit:contain;mix-blend-mode:multiply;">
      <?php else: ?>
        <div style="width:150px;"></div>
      <?php endif; ?>
    </div>
    <div style="border-top:1.5px solid #333;padding-top:5px;">
      <p style="font-weight:bold;font-size:12px;margin:0 0 1px;color:#001e40;"><?= sanitize($c['hod_name']) ?></p>
      <?php if (!empty($c['hod_signed_at'])): ?>
        <p style="font-size:9px;color:#888;margin:2px 0 0;font-family:Arial,sans-serif;"><?= formatDate($c['hod_signed_at']) ?></p>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Through: Level Adviser -->
<div style="display:flex;justify-content:flex-start;align-items:flex-end;gap:48px;margin-bottom:32px;">
  <div style="font-family:'Times New Roman',Times,serif;font-size:12.5px;line-height:1.8;">
    <p style="margin:0 0 4px;"><strong>Through:</strong></p>
    <p style="margin:0;">Level Adviser (<?= sanitize($user['level']) ?> Level),</p>
    <p style="margin:0;">Department of <?= sanitize($user['department']) ?>,</p>
    <p style="margin:0;"><?= sanitize($user['faculty']) ?>,</p>
    <p style="margin:0;">Lagos State University, Ojo,</p>
    <p style="margin:0;">Lagos State.</p>
  </div>

  <?php if (!empty($c['la_name'])): ?>
  <div style="min-width:160px;">
    <div style="height:56px;display:flex;align-items:flex-end;padding-bottom:4px;">
      <?php if (!empty($c['la_sig'])): ?>
        <img src="<?= BASE_URL ?><?= sanitize($c['la_sig']) ?>" alt="Level Adviser Signature"
             style="max-height:52px;max-width:150px;object-fit:contain;mix-blend-mode:multiply;">
      <?php else: ?>
        <div style="width:150px;"></div>
      <?php endif; ?>
    </div>
    <div style="border-top:1.5px solid #333;padding-top:5px;">
      <p style="font-weight:bold;font-size:12px;margin:0 0 1px;color:#001e40;"><?= sanitize($c['la_name']) ?></p>
      <?php if (!empty($c['la_signed_at'])): ?>
        <p style="font-size:9px;color:#888;margin:2px 0 0;font-family:Arial,sans-serif;"><?= formatDate($c['la_signed_at']) ?></p>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
          <!-- Salutation -->
          <p style="margin-bottom:14px;">Dear Sir/Ma,</p>

          <!-- Subject -->
<p style="text-align:center;font-weight:bold;font-size:13px;text-decoration:underline;
          text-transform:uppercase;margin:0 0 16px;letter-spacing:0.3px;">
    <?= sanitize($subjectText) ?>
</p>

          <!-- Body -->
          <p style="text-align:justify;margin-bottom:12px;"><?= nl2br(sanitize($introText)) ?></p>
          <p style="text-align:justify;margin-bottom:32px;"><?= nl2br(sanitize($closingText)) ?></p>

          <p style="margin-bottom:48px;">Yours faithfully,</p>

          <!-- Bottom signature row -->
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:24px;align-items:end;">

            <!-- Student -->
            <div style="display:inline-block;">
  <div style="height:56px;display:flex;align-items:flex-end;padding-bottom:4px;">
    <?php if ($studentHasSig): ?>
      <img src="<?= sanitize($studentSigUrl) ?>" alt="Student Signature"
           style="height:48px;max-width:150px;object-fit:contain;mix-blend-mode:multiply;">
    <?php else: ?>
      <div style="height:1px;width:150px;"></div>
    <?php endif; ?>
  </div>
  <div style="border-top:1.5px solid #333;padding-top:5px;width:fit-content;min-width:150px;">
    <p style="font-weight:bold;font-size:12px;margin:0 0 1px;white-space:nowrap;"><?= sanitize($user['full_name']) ?></p>
    <p style="font-size:10px;font-style:italic;color:#555;margin:0;white-space:nowrap;">
      <?= sanitize($user['matric_number'] ?? 'N/A') ?> &bull; Level <?= sanitize($user['level']) ?>
    </p>
  </div>
</div>

            <!-- Lecturer — only once verified/approved -->
<?php if (!empty($c['lec_name']) && in_array($c['status'], ['verified','approved','rejected'])): ?>
<div style="display:inline-block;">
  <div style="height:56px;display:flex;align-items:flex-end;padding-bottom:4px;">
    <?php if ($lecSigUrl): ?>
      <img src="<?= sanitize($lecSigUrl) ?>" alt="Lecturer Signature"
           style="height:48px;max-width:150px;object-fit:contain;mix-blend-mode:multiply;">
    <?php else: ?>
      <div style="height:1px;width:150px;"></div>
    <?php endif; ?>
  </div>
  <div style="border-top:1.5px solid #333;padding-top:5px;width:fit-content;min-width:150px;">
    <p style="font-weight:bold;font-size:12px;margin:0 0 1px;white-space:nowrap;"><?= sanitize($c['lec_name']) ?></p>
    <p style="font-size:10px;font-style:italic;color:#555;margin:0;white-space:nowrap;">Course Lecturer</p>
  </div>
</div>
<?php else: ?>
<div></div>
<?php endif; ?>

            <!-- Score box — only once corrected scores exist -->
            <?php if ($c['corrected_ca_score'] !== null || $c['corrected_exam_score'] !== null): ?>
            <div style="border:1.5px solid #001e40;border-radius:8px;padding:8px 12px;font-size:11px;">
              <p style="font-size:9px;font-weight:700;text-transform:uppercase;color:#001e40;
                        letter-spacing:0.1em;margin:0 0 6px;">Correct Score</p>
              <?php if ($c['corrected_ca_score'] !== null): ?>
                <p style="margin:0 0 3px;">CA: <?= $c['original_ca_score'] ?> → <strong style="color:#15803d;"><?= $c['corrected_ca_score'] ?></strong></p>
              <?php endif; ?>
              <?php if ($c['corrected_exam_score'] !== null): ?>
                <p style="margin:0;">Exam: <?= $c['original_exam_score'] ?> → <strong style="color:#15803d;"><?= $c['corrected_exam_score'] ?></strong></p>
              <?php endif; ?>
            </div>
            <?php else: ?>
            <div></div>
            <?php endif; ?>

          </div><!-- /bottom sig row -->

        </div><!-- /z-index wrapper -->
      </div><!-- /letter body -->

      <!-- Footer -->
      <div style="text-align:center;font-size:9px;color:#555;padding:5px 20px;border-top:1px solid #ddd;font-family:Arial,sans-serif;">
        Lagos State University, Badagry Expressway, P.M.B. 0001, Ojo, Lagos, Nigeria. &bull; www.lasu.edu.ng
      </div>
      <div style="height:5px;background:#001e40;"></div>
      <div style="height:5px;background:#fecb00;"></div>

    </div><!-- /letter -->


    <!-- Complaint Details -->
    <div class="bg-white rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] overflow-hidden no-print">
      <div class="px-6 py-4 bg-[#edf4ff]/50 flex items-center gap-2">
        <span class="material-symbols-outlined text-[#001e40] text-base">notes</span>
        <h4 class="font-bold text-[#001e40] text-sm">Complaint Details</h4>
      </div>
      <div class="p-6">
        <div class="bg-[#f7f9ff] rounded-xl p-5 border-l-4 border-[#001e40]">
          <p class="text-sm text-[#0b1d2c] leading-relaxed"><?= nl2br(sanitize($c['complaint_text'])) ?></p>
        </div>
      </div>
    </div>

    <!-- Evidence -->
    <?php if ($c['evidence_path']): ?>
    <div class="bg-white rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] overflow-hidden no-print">
      <div class="px-6 py-4 bg-[#edf4ff]/50 flex items-center gap-2">
        <span class="material-symbols-outlined text-[#001e40] text-base">attach_file</span>
        <h4 class="font-bold text-[#001e40] text-sm">Attached Evidence</h4>
      </div>
      <div class="p-6">
        <a href="<?= BASE_URL . sanitize($c['evidence_path']) ?>" target="_blank"
          class="inline-flex items-center gap-2 bg-[#edf4ff] text-[#001e40] px-5 py-3 rounded-xl text-sm font-bold hover:bg-[#d2e4f9] transition-all">
          <span class="material-symbols-outlined text-base">open_in_new</span>View Attached Evidence
        </a>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($c['status'] === 'returned_to_student'): ?>
<div class="bg-white rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] overflow-hidden no-print">
  <div class="px-6 py-4 bg-[#ffe08b]/30 flex items-center gap-2">
    <span class="material-symbols-outlined text-[#745b00] text-base">reply</span>
    <h4 class="font-bold text-[#745b00] text-sm">Action Required — Resubmit Your Complaint</h4>
  </div>
  <div class="p-6">
    <div class="flex items-start gap-3 bg-[#ffdad6]/30 px-4 py-3 rounded-xl mb-5 text-sm text-[#93000a]">
      <span class="material-symbols-outlined text-base flex-shrink-0">warning</span>
      <div><strong>Return Reason:</strong> <?= nl2br(sanitize($c['return_reason'] ?? 'Please review and correct your complaint.')) ?></div>
    </div>
    <?php if ($error): ?>
    <div class="mb-4 flex items-center gap-3 bg-[#ffdad6] text-[#93000a] px-4 py-3 rounded-xl text-sm"><?= sanitize($error) ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="resubmitForm" class="space-y-4">
      <!-- Hidden fields synced from the editable letter -->
      <input type="hidden" name="complaint_text" id="resubmit_complaint_hidden" value="<?= htmlspecialchars($c['complaint_text']) ?>">
      <input type="hidden" name="letter_body"    id="resubmit_letter_body">

      <!-- Editable letter preview -->
      <div>
        <label class="block text-xs font-bold text-[#001e40] uppercase tracking-wider mb-2">
          Edit Your Letter Content
        </label>
        <div class="bg-[#f7f9ff] border border-[#d2e4f9] rounded-xl p-5 space-y-3">
          <p class="text-[10px] font-bold text-[#001e40] uppercase tracking-wider flex items-center gap-1">
            <span class="material-symbols-outlined text-sm">edit</span>
            Click the paragraphs below to edit them directly
          </p>
          <!-- Editable intro -->
          <div id="edit_body1" contenteditable="true" spellcheck="true"
            class="text-sm text-[#0b1d2c] leading-relaxed p-3 rounded-lg border border-transparent hover:border-[#fecb00] hover:bg-[#fffef0] focus:outline-none focus:border-[#fecb00] focus:bg-[#fffef0] transition-all cursor-text"
            style="min-height:60px;"><?= htmlspecialchars($introText) ?></div>
          <!-- Editable closing -->
          <div id="edit_body2" contenteditable="true" spellcheck="true"
            class="text-sm text-[#0b1d2c] leading-relaxed p-3 rounded-lg border border-transparent hover:border-[#fecb00] hover:bg-[#fffef0] focus:outline-none focus:border-[#fecb00] focus:bg-[#fffef0] transition-all cursor-text"
            style="min-height:40px;"><?= htmlspecialchars($closingText) ?></div>
          <!-- Complaint narrative (maps to complaint_text field) -->
          <div>
            <p class="text-[10px] font-bold text-[#001e40] uppercase tracking-wider mb-1">Complaint Narrative</p>
            <textarea id="edit_narrative" rows="4"
              class="w-full bg-white border border-[#d2e4f9] rounded-xl px-4 py-3 text-sm text-[#0b1d2c] focus:ring-2 focus:ring-[#001e40] resize-none"
              placeholder="Describe your complaint in detail..."><?= htmlspecialchars($c['complaint_text']) ?></textarea>
          </div>
        </div>
      </div>

      <!-- Re-upload evidence -->
      <div>
        <label class="block text-xs font-bold text-[#001e40] uppercase tracking-wider mb-2">Re-upload Evidence <span class="text-[#43474f] font-normal">(optional)</span></label>
        <div class="bg-[#edf4ff] border-2 border-dashed border-[#c3c6d1]/40 rounded-xl p-5 flex flex-col items-center cursor-pointer"
             onclick="document.getElementById('resubmit_evidence').click()">
          <span class="material-symbols-outlined text-[#001e40] text-2xl mb-1">upload_file</span>
          <p class="text-xs text-[#43474f]">Click to upload or drag &amp; drop</p>
          <input type="file" name="evidence_file" id="resubmit_evidence" accept="image/*,.pdf" class="hidden"
                 onchange="document.getElementById('resubmit_filename').textContent = this.files[0]?.name || ''">
          <p id="resubmit_filename" class="text-xs font-bold text-[#001e40] mt-2"></p>
        </div>
      </div>

      <button type="button" onclick="submitResubmit()"
        class="flex items-center gap-2 bg-[#fecb00] text-[#001e40] px-6 py-3 rounded-xl font-bold text-sm hover:bg-[#f1c100] transition-all">
        <span class="material-symbols-outlined text-base">send</span>Resubmit Complaint
      </button>
    </form>
  </div>
</div>

<script>
function submitResubmit() {
  const intro    = document.getElementById('edit_body1').innerText.trim();
  const closing  = document.getElementById('edit_body2').innerText.trim();
  const narrative = document.getElementById('edit_narrative').value.trim();

  if (!narrative) {
    alert('Please fill in the complaint narrative.');
    return;
  }

  // Sync hidden fields
  document.getElementById('resubmit_complaint_hidden').value = narrative;
  document.getElementById('resubmit_letter_body').value = btoa(unescape(encodeURIComponent(
    JSON.stringify({ intro, closing })
  )));

  document.getElementById('resubmitForm').submit();
}
</script>
<?php endif; ?>


    <!-- Activity Timeline -->
    <div class="bg-white rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] overflow-hidden no-print">
      <div class="px-6 py-4 bg-[#edf4ff]/50 flex items-center gap-2">
        <span class="material-symbols-outlined text-[#001e40] text-base">history</span>
        <h4 class="font-bold text-[#001e40] text-sm">Activity Timeline</h4>
      </div>
      <div class="p-6">
        <?php if (empty($auditLog)): ?>
          <p class="text-xs text-[#43474f]">No activity recorded yet.</p>
        <?php else: ?>
        <div class="space-y-4 relative">
          <div class="absolute left-[11px] top-0 bottom-0 w-px bg-[#d2e4f9]/60"></div>
          <?php foreach ($auditLog as $log): ?>
          <div class="relative flex gap-4 items-start">
            <div class="w-6 h-6 rounded-full bg-[#001e40] flex items-center justify-center flex-shrink-0 z-10">
              <span class="material-symbols-outlined text-white" style="font-size:11px">check</span>
            </div>
            <div class="flex-1 pb-1">
              <p class="text-sm font-bold text-[#001e40]"><?= sanitize(ucwords(str_replace('_', ' ', $log['action']))) ?></p>
              <p class="text-xs text-[#43474f]">By <?= sanitize($log['full_name']) ?> &bull; <?= formatDate($log['created_at']) ?></p>
              <?php if (!empty($log['notes'])): ?>
                <p class="text-xs text-[#43474f] italic mt-0.5"><?= sanitize($log['notes']) ?></p>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /left col -->


  <!-- Right Sidebar -->
  <div class="col-span-12 lg:col-span-4 space-y-4 no-print">

    <div class="bg-[#001e40] text-white p-6 rounded-xl">
      <p class="text-[10px] font-bold uppercase tracking-[0.2em] opacity-60 mb-4">Complaint Status</p>
      <div class="mb-4"><?= statusBadge($c['status']) ?></div>
      <div class="space-y-2 text-sm">
        <div>
          <p class="text-[9px] opacity-60 uppercase font-bold">Filed</p>
          <p><?= formatDate($c['created_at']) ?></p>
        </div>
        <div>
          <p class="text-[9px] opacity-60 uppercase font-bold">Last Updated</p>
          <p><?= formatDate($c['updated_at']) ?></p>
        </div>
        <?php if ($c['resolved_at']): ?>
        <div>
          <p class="text-[9px] opacity-60 uppercase font-bold">Resolved</p>
          <p><?= formatDate($c['resolved_at']) ?></p>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="bg-white rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] p-5">
      <p class="text-[10px] font-bold uppercase tracking-widest text-[#43474f] mb-3">Course Details</p>
      <p class="font-black text-[#001e40]"><?= sanitize($c['course_code']) ?></p>
      <p class="text-sm text-[#43474f]"><?= sanitize($c['course_title']) ?></p>
      <div class="mt-3 pt-3 border-t border-[#d2e4f9] space-y-1">
        <p class="text-xs text-[#43474f]"><strong>Category:</strong> <?= sanitize($c['category']) ?></p>
        <p class="text-xs text-[#43474f]"><strong>Session:</strong> <?= sanitize($c['academic_session']) ?></p>
        <p class="text-xs text-[#43474f]"><strong>Semester:</strong> <?= sanitize($c['semester']) ?></p>
      </div>
    </div>

    <!-- Pipeline tracker -->
    <div class="bg-white rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] p-5">
      <p class="text-[10px] font-bold uppercase tracking-widest text-[#43474f] mb-4">Processing Pipeline</p>
      <?php
      $stages = [
        ['pending',              'edit',           'Submitted',         'Filed by student'],
        ['endorsed',             'verified_user',  'Level Adviser',     'Endorsed & forwarded'],
        ['assigned_to_lecturer', 'account_balance','HOD Assignment',    'Assigned to Lecturer'],
        ['verified',             'school',         'Lecturer Verified', 'Score checked'],
        ['approved',             'task_alt',       'Resolved',          'HOD final approval'],
      ];
      $statusOrder = [
        'pending'              => 0,
        'returned_to_student'  => 0,
        'endorsed'             => 1,
        'assigned_to_lecturer' => 2,
        'verified'             => 3,
        'approved'             => 4,
        'rejected'             => 4,
      ];
      $current = $statusOrder[$c['status']] ?? 0;
      ?>
      <div class="space-y-3 relative">
        <div class="absolute left-[11px] top-0 bottom-0 w-px bg-[#d2e4f9]/60"></div>
        <?php foreach ($stages as $i => [$stKey, $icon, $title, $sub]):
          $done   = $i < $current;
          $active = $i === $current;
          $future = $i > $current;
        ?>
        <div class="relative flex gap-3 items-start">
          <div class="w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0 z-10
            <?= $done ? 'bg-green-500' : ($active ? 'bg-[#001e40]' : 'bg-[#d2e4f9]') ?>">
            <span class="material-symbols-outlined text-[12px] <?= ($done || $active) ? 'text-white' : 'text-[#43474f]' ?>"
              <?= $done ? "style=\"font-variation-settings:'FILL' 1\"" : '' ?>>
              <?= $done ? 'done' : $icon ?>
            </span>
          </div>
          <div class="<?= $future ? 'opacity-40' : '' ?>">
            <p class="text-xs font-bold text-[#001e40] leading-none"><?= $title ?></p>
            <p class="text-[10px] text-[#43474f] mt-0.5"><?= $sub ?></p>
          </div>
        </div>
        <?php endforeach; ?>

        <?php if ($c['status'] === 'rejected'): ?>
        <div class="relative flex gap-3 items-start">
          <div class="w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0 z-10 bg-red-500">
            <span class="material-symbols-outlined text-white text-[12px]"
                  style="font-variation-settings:'FILL' 1">cancel</span>
          </div>
          <div>
            <p class="text-xs font-bold text-red-600">Rejected</p>
            <p class="text-[10px] text-[#43474f]">Complaint not upheld</p>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /right sidebar -->
</div>

<?php include __DIR__ . '/../includes/layout_end.php'; ?>

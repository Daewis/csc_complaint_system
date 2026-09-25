<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('lecturer');
$user = currentUser();
$db   = getDB();

$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare('
    SELECT c.*, co.course_code, co.course_title,
           u.full_name  as student_name,  u.matric_number, u.department, u.level,
           u.faculty,   u.signature_path as student_sig,
           la.full_name as la_name,  la.signature_path as la_sig,  la.title as la_title,
           hod.full_name as hod_name, hod.signature_path as hod_sig, hod.title as hod_title
    FROM complaints c
    JOIN  courses co  ON c.course_id          = co.id
    JOIN  users   u   ON c.student_id         = u.id
    LEFT JOIN users la  ON c.level_adviser_id = la.id
    LEFT JOIN users hod ON c.hod_id           = hod.id
    WHERE c.id = ? AND c.hod_assigned_lecturer_id = ?
');
$stmt->execute([$id, $user['id']]);
$c = $stmt->fetch();
if (!$c) {
    header('Location: ' . BASE_URL . 'lecturer/dashboard.php');
    exit;
}

// ── Determine complaint type early so it's available everywhere ───────────────
$isCourseUpgrade = (trim($c['category']) === 'Appeal for Course Upgrade');

$success = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $c['status'] === 'assigned_to_lecturer') {
    $comment = trim($_POST['comment'] ?? '');
    $ip      = getClientIP();
    $now     = date('Y-m-d H:i:s');

    // ── Course Upgrade: recommendation ────────────────────────────────────
    if ($isCourseUpgrade) {
        $recommendation = trim($_POST['upgrade_recommendation'] ?? '');

        if (!$comment) {
            $error = 'Please provide your reasoning.';
        } elseif (!in_array($recommendation, ['eligible', 'not_eligible'])) {
            $error = 'Please select a recommendation.';
        } else {
            $db->prepare("
                UPDATE complaints
                SET status            = 'verified',
                    lecturer_id       = ?,
                    upgrade_recommendation = ?,
                    lecturer_comment  = ?,
                    lecturer_signed_at= ?,
                    lecturer_ip       = ?,
                    updated_at        = NOW()
                WHERE id = ?
            ")->execute([$user['id'], $recommendation, $comment, $now, $ip, $id]);

            logAudit($id, $user['id'], 'upgrade_recommendation', $ip,
                "Lecturer submitted upgrade recommendation: $recommendation");

            if ($c['hod_id']) {
                $recText = $recommendation === 'eligible'
                    ? 'considers student ELIGIBLE for grade upgrade'
                    : 'considers student NOT ELIGIBLE for grade upgrade';
                addNotification($c['hod_id'], $id,
                    "Lecturer {$user['full_name']} {$recText}. Complaint #{$c['ticket_number']} awaiting your final decision.");
            }
            $success = 'Recommendation submitted. HOD will review and take final action.';
            $stmt->execute([$id, $user['id']]);
            $c = $stmt->fetch();
        }

    // ── Default: score verification ───────────────────────────────────────
    } else {
        $orig_ca   = ($_POST['original_ca_score']   ?? '') !== '' ? $_POST['original_ca_score']   : null;
        $orig_exam = ($_POST['original_exam_score']  ?? '') !== '' ? $_POST['original_exam_score']  : null;
        $new_ca    = ($_POST['corrected_ca_score']   ?? '') !== '' ? $_POST['corrected_ca_score']   : null;
        $new_exam  = ($_POST['corrected_exam_score'] ?? '') !== '' ? $_POST['corrected_exam_score'] : null;

        if (!$comment) {
            $error = 'Please provide a verification statement.';
        } else {
            $db->prepare("
                UPDATE complaints
                SET status              = 'verified',
                    lecturer_id         = ?,
                    original_ca_score   = ?,
                    original_exam_score = ?,
                    corrected_ca_score  = ?,
                    corrected_exam_score= ?,
                    lecturer_comment    = ?,
                    lecturer_signed_at  = ?,
                    lecturer_ip         = ?,
                    updated_at          = NOW()
                WHERE id = ?
            ")->execute([$user['id'], $orig_ca, $orig_exam, $new_ca, $new_exam, $comment, $now, $ip, $id]);

            logAudit($id, $user['id'], 'lecturer_verified', $ip, 'Lecturer submitted score verification.');

            if ($c['hod_id']) {
                addNotification($c['hod_id'], $id,
                    "Lecturer {$user['full_name']} has verified complaint #{$c['ticket_number']}. Awaiting your final approval.");
            }
            $success = 'Score verification submitted. HOD will review and take final action.';
            $stmt->execute([$id, $user['id']]);
            $c = $stmt->fetch();
        }
    }
}

// ── Letter body decode ────────────────────────────────────────────────────────
$letterBodyDecoded = null;
if (!empty($c['letter_body'])) {
    $decoded = json_decode($c['letter_body'], true);
    if (json_last_error() === JSON_ERROR_NONE && isset($decoded['intro'])) {
        $letterBodyDecoded = $decoded;
    }
}
$defaultIntro   = "I, {$c['student_name']}, Matriculation Number {$c['matric_number']}, Level {$c['level']} student in the Department of {$c['department']}, wish to formally submit this appeal regarding {$c['course_code']} – {$c['course_title']}.";
$defaultClosing = "I humbly request that this matter be investigated and the necessary correction effected through appropriate channels. Thank you for your kind attention.";
$defaultSubject = "COMPLAINT ON " . strtoupper($c['category']) . " – " . $c['course_code'] . " (" . $c['academic_session'] . ", " . strtoupper($c['semester']) . " SEMESTER)";

$introText   = $letterBodyDecoded['intro']   ?? $defaultIntro;
$closingText = $letterBodyDecoded['closing'] ?? $defaultClosing;
$subjectText = $letterBodyDecoded['subject'] ?? $defaultSubject;

// ── Signature URL helpers ─────────────────────────────────────────────────────
function lecSigUrl(string $base, ?string $path): ?string {
    if (!$path || !file_exists(__DIR__ . '/../' . $path)) return null;
    return $base . $path;
}
$studentSigUrl = lecSigUrl(BASE_URL, $c['student_sig'] ?? null);
$laSigUrl      = lecSigUrl(BASE_URL, $c['la_sig']      ?? null);
$hodSigUrl     = lecSigUrl(BASE_URL, $c['hod_sig']     ?? null);
$lecSigUrl     = lecSigUrl(BASE_URL, $user['signature_path'] ?? null);

$laTitle        = $c['la_title']  ?? '';
$hodTitle       = $c['hod_title'] ?? '';
$laDisplayName  = trim(($laTitle  ? $laTitle  . ' ' : '') . ($c['la_name']  ?? ''));
$hodDisplayName = trim(($hodTitle ? $hodTitle . ' ' : '') . ($c['hod_name'] ?? ''));
$faculty        = $c['faculty'] ?? '';

$laEndorsed  = !empty($c['la_signed_at']);
$hodSigned   = !empty($c['hod_signed_at']);
$lecVerified = in_array($c['status'], ['verified', 'approved', 'rejected']);

$pageTitle = 'Verify ' . $c['ticket_number'];
$activeNav = 'dashboard.php';
include __DIR__ . '/../includes/layout.php';
?>

<style>
@media print {
  .no-print { display:none !important; }
  body { background:#fff !important; }
}
</style>

<!-- PAGE HEADER -->
<div class="mb-6 flex items-center gap-3 no-print">
  <a href="<?= BASE_URL ?>lecturer/dashboard.php" class="text-[#43474f] hover:text-[#001e40] transition-colors">
    <span class="material-symbols-outlined">arrow_back</span>
  </a>
  <h2 class="text-2xl font-black text-[#001e40]">
    <?= $isCourseUpgrade ? 'Grade Upgrade Review' : 'Score Verification' ?>
  </h2>
  <code class="text-sm bg-[#edf4ff] text-[#001e40] px-3 py-1 rounded-lg font-mono font-bold"><?= sanitize($c['ticket_number']) ?></code>
  <?= statusBadge($c['status']) ?>
  <?php if ($c['is_overdue']): ?>
  <span class="text-[9px] font-bold text-red-600 bg-red-50 px-2 py-1 rounded-full">⚠ OVERDUE SLA</span>
  <?php endif; ?>
  <button onclick="window.print()" class="ml-auto text-[#43474f] hover:text-[#001e40] p-2 hover:bg-[#edf4ff] rounded-lg transition-colors">
    <span class="material-symbols-outlined">print</span>
  </button>
</div>

<!-- SLA Alert -->
<?php if ($c['sla_deadline'] && $c['status'] === 'assigned_to_lecturer'): ?>
<div class="mb-5 flex items-center gap-3 <?= $c['is_overdue'] ? 'bg-[#ffdad6] text-[#93000a]' : 'bg-[#ffe08b]/30 text-[#745b00]' ?> px-5 py-3 rounded-xl text-sm font-medium no-print">
  <span class="material-symbols-outlined text-base">timer</span>
  <strong>SLA Deadline:</strong> <?= formatDate($c['sla_deadline']) ?>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="mb-5 flex items-center gap-3 bg-green-50 text-green-800 border border-green-200 px-5 py-4 rounded-xl text-sm font-medium no-print">
  <span class="material-symbols-outlined text-base">check_circle</span><?= sanitize($success) ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="mb-5 flex items-center gap-3 bg-[#ffdad6] text-[#93000a] px-5 py-4 rounded-xl text-sm font-medium no-print">
  <span class="material-symbols-outlined text-base">error</span><?= sanitize($error) ?>
</div>
<?php endif; ?>

<div class="grid grid-cols-12 gap-6">

  <!-- LEFT COLUMN -->
  <div class="col-span-12 lg:col-span-8 space-y-5">

    <!-- OFFICIAL LASU LETTER -->
    <div class="bg-white rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] overflow-hidden border border-[#d2e4f9]">

      <div style="height:5px;background:#c0392b;"></div>
      <div style="height:6px;background:#001e40;"></div>
      <div style="height:5px;background:#fecb00;"></div>

      <!-- Letterhead -->
      <div style="display:flex;align-items:center;padding:14px 28px;border-bottom:2px solid #001e40;gap:16px;">
        <img src="<?= BASE_URL ?>assets/img/lasu_logo.jpg" alt="LASU Crest"
             style="width:60px;height:60px;object-fit:contain;flex-shrink:0;">
        <div style="flex:1;text-align:center;">
          <div style="font-family:Arial,sans-serif;font-size:17px;font-weight:900;color:#001e40;text-transform:uppercase;letter-spacing:0.4px;line-height:1.2;">
            Lagos State University, Ojo
          </div>
          <div style="font-family:Arial,sans-serif;font-size:10.5px;color:#444;margin-top:2px;">
            Department of <?= sanitize($c['department']) ?>
          </div>
          <div style="font-family:Arial,sans-serif;font-size:10px;color:#666;">
            Result Complaint Management Office
          </div>
        </div>
        <div style="text-align:right;font-size:9px;color:#444;line-height:1.5;min-width:130px;">
          <strong style="color:#001e40;">Lagos State University,</strong><br>
          Badagry Expressway,<br>P.M.B. 0001, Ojo, Lagos.<br>
          <span style="color:#001e40;font-weight:700;">web:</span> www.lasu.edu.ng
        </div>
      </div>

      <!-- Letter body -->
      <div style="padding:28px 36px;position:relative;font-family:'Times New Roman',Times,serif;font-size:13px;line-height:1.75;">

        <!-- Watermark -->
        <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-55%);
                    width:230px;height:230px;
                    background:url('<?= BASE_URL ?>assets/img/lasu_logo.jpg') center/contain no-repeat;
                    opacity:0.05;pointer-events:none;z-index:0;"></div>

        <div style="position:relative;z-index:1;">

          <!-- Ref + Date -->
          <div style="display:flex;justify-content:space-between;margin-bottom:20px;font-size:12px;">
            <div style="color:#666;">Ref: <strong><?= sanitize($c['ticket_number']) ?></strong></div>
            <div><strong>Date:</strong> <?= formatDate($c['created_at']) ?></div>
          </div>

          <!-- To: HOD -->
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
                <p style="font-weight:bold;font-size:12px;margin:0 0 1px;color:#001e40;"><?= sanitize($hodDisplayName ?: 'Head of Department') ?></p>
                <p style="font-size:10px;font-style:italic;color:#555;margin:0;">Head of Department, <?= sanitize($c['department']) ?></p>
                <?php if (!empty($c['hod_signed_at'])): ?>
                  <p style="font-size:9px;color:#888;margin:2px 0 0;font-family:Arial,sans-serif;"><?= formatDate($c['hod_signed_at']) ?></p>
                <?php endif; ?>
              </div>
            </div>
            <?php else: ?>
            <div style="min-width:160px;"></div>
            <?php endif; ?>
          </div>

          <!-- Through: Level Adviser -->
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
                <p style="font-weight:bold;font-size:12px;margin:0 0 1px;color:#001e40;"><?= sanitize($laDisplayName ?: 'Level Adviser') ?></p>
                <p style="font-size:10px;font-style:italic;color:#555;margin:0;">Level Adviser, <?= sanitize($c['department']) ?></p>
                <?php if (!empty($c['la_signed_at'])): ?>
                  <p style="font-size:9px;color:#888;margin:2px 0 0;font-family:Arial,sans-serif;"><?= formatDate($c['la_signed_at']) ?></p>
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
          <p style="text-align:justify;margin-bottom:10px;"><?= nl2br(sanitize($introText)) ?></p>
          <p style="text-align:justify;margin-bottom:28px;"><?= nl2br(sanitize($closingText)) ?></p>

          <p style="margin-bottom:48px;">Yours faithfully,</p>

          <!-- Bottom signature row -->
          <div style="display:flex;flex-wrap:wrap;gap:48px;align-items:flex-end;margin-top:32px;">

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
              <div style="border-top:1.5px solid #333;padding-top:5px;width:120px;">
                <p style="font-weight:bold;font-size:12px;margin:0 0 1px;white-space:nowrap;"><?= sanitize($c['student_name']) ?></p>
                <p style="font-size:10px;font-style:italic;color:#555;margin:0;white-space:nowrap;">
                  <?= sanitize($c['matric_number']) ?> &bull; Level <?= sanitize($c['level']) ?>
                </p>
                   <p style="font-size:9px;margin:2px 0 0;visibility:hidden;">placeholder</p>
              </div>
            </div>

            <!-- ② Lecturer — shown once verified -->
            <?php if ($lecVerified): ?>
            <div style="display:inline-block;">
              <div style="height:56px;display:flex;align-items:flex-end;padding-bottom:4px;">
                <?php if ($lecSigUrl): ?>
                  <img src="<?= $lecSigUrl ?>" alt="Lecturer Signature"
                       style="height:48px;max-width:150px;object-fit:contain;mix-blend-mode:multiply;">
                <?php else: ?>
                  <div style="height:1px;width:150px;"></div>
                <?php endif; ?>
              </div>
              <div style="border-top:1.5px solid #333;padding-top:5px;width:fit-content;min-width:140px;">
                <p style="font-weight:bold;font-size:12px;margin:0 0 1px;white-space:nowrap;">
                  <?= sanitize(($user['title'] ? $user['title'] . ' ' : '') . $user['full_name']) ?>
                </p>
                <p style="font-size:10px;font-style:italic;color:#555;margin:0;white-space:nowrap;">Course Lecturer</p>
                <?php if (!empty($c['lecturer_signed_at'])): ?>
                  <p style="font-size:9px;color:#888;margin:2px 0 0;font-family:Arial,sans-serif;">
                    <?= formatDate($c['lecturer_signed_at']) ?>
                  </p>
                <?php endif; ?>
              </div>
            </div>
            <?php endif; ?>

  <!-- ③ Score box — grade dispute only -->
            <?php if ($lecVerified && !$isCourseUpgrade && ($c['original_ca_score'] !== null || $c['original_exam_score'] !== null)): ?>
            <div style="border:1.2px solid #001e40;padding:10px 14px;border-radius:6px;margin-bottom:2px;">
              <p style="font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:0.1em;color:#001e40;margin:0 0 8px;">Score Correction Detail</p>
              <div style="display:flex;gap:24px;">
                <?php if ($c['corrected_ca_score'] !== null): ?>
                <div>
                  <p style="font-size:8px;color:#555;text-transform:uppercase;margin:0 0 2px;font-weight:bold;">CA Score</p>
                  <p style="font-weight:bold;font-size:12px;margin:0;color:#000;">
                    <?= $c['original_ca_score'] ?? '—' ?> &rarr; <span style="color:#15803d;"><?= $c['corrected_ca_score'] ?></span>
                  </p>
                </div>
                <?php endif; ?>
                <?php if ($c['corrected_exam_score'] !== null): ?>
                <div>
                  <p style="font-size:8px;color:#555;text-transform:uppercase;margin:0 0 2px;font-weight:bold;">Exam Score</p>
                  <p style="font-weight:bold;font-size:12px;margin:0;color:#000;">
                    <?= $c['original_exam_score'] ?? '—' ?> &rarr; <span style="color:#15803d;"><?= $c['corrected_exam_score'] ?></span>
                  </p>
                </div>
                <?php endif; ?>
              </div>
            </div>
            <?php endif; ?>

            <!-- ③ Upgrade recommendation badge — course upgrade only -->
<?php if ($lecVerified && $isCourseUpgrade && !empty($c['upgrade_recommendation'])): ?>
<div style="border:1.2px solid #001e40;padding:10px 14px;border-radius:6px;margin-bottom:2px;">
  <p style="font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:0.1em;color:#001e40;margin:0 0 4px;">
    Lecturer Recommendation
  </p>
  <p style="font-weight:bold;font-size:12px;margin:0;color:#000;">
    <?= $c['upgrade_recommendation'] === 'eligible' ? '✓ Student Eligible' : '✗ Not Eligible' ?>
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

    </div><!-- /letter card -->


    <!-- EVIDENCE LINK -->
    <?php if ($c['evidence_path']): ?>
    <div class="bg-white rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] overflow-hidden no-print">
      <div class="px-6 py-4 bg-[#edf4ff]/50 flex items-center gap-2">
        <span class="material-symbols-outlined text-[#001e40] text-base">attach_file</span>
        <h4 class="font-bold text-[#001e40] text-sm">Attached Evidence</h4>
      </div>
      <div class="p-6">
        <a href="<?= BASE_URL . sanitize($c['evidence_path']) ?>" target="_blank"
           class="inline-flex items-center gap-2 bg-[#edf4ff] text-[#001e40] px-5 py-3 rounded-xl text-sm font-bold hover:bg-[#d2e4f9] transition-all">
          <span class="material-symbols-outlined text-base">open_in_new</span>View Evidence
        </a>
      </div>
    </div>
    <?php endif; ?>


    <!-- ══ ACTION PANEL ══════════════════════════════════════════════════════ -->
    <?php if ($c['status'] === 'assigned_to_lecturer'): ?>

      <?php if ($isCourseUpgrade): ?>
      <!-- ── Course Upgrade: Grade Recommendation Panel ── -->
      <div class="bg-white rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] overflow-hidden no-print">
        <div class="px-6 py-4 bg-[#edf4ff]/50 flex items-center gap-2">
          <span class="material-symbols-outlined text-[#001e40] text-base">school</span>
          <h4 class="font-bold text-[#001e40] text-sm">Grade Upgrade Recommendation</h4>
        </div>
        <div class="p-6">
          <div class="flex items-start gap-3 bg-[#edf4ff] px-4 py-3 rounded-xl mb-6 text-xs text-[#001e40]">
            <span class="material-symbols-outlined text-sm flex-shrink-0">info</span>
            <p>Review the student's appeal and provide your professional recommendation. Consider their attendance record, participation, and exam performance context.</p>
          </div>
          <form method="POST" class="space-y-6">

            <!-- Recommendation radio -->
            <div>
              <label class="block text-xs font-bold text-[#001e40] uppercase tracking-wider mb-3">
                Your Recommendation <span class="text-red-500">*</span>
              </label>
              <div class="grid grid-cols-2 gap-3">
                <label class="flex items-center gap-3 p-4 bg-[#f7f9ff] rounded-xl cursor-pointer border-2 border-transparent hover:border-green-300 transition-all has-[:checked]:border-green-500 has-[:checked]:bg-green-50">
                  <input type="radio" name="upgrade_recommendation" value="eligible" required class="accent-green-600 w-4 h-4">
                  <div>
                    <p class="font-bold text-sm text-[#001e40]">Student Eligible</p>
                    <p class="text-[10px] text-[#43474f] mt-0.5">Deserves the grade waiver</p>
                  </div>
                </label>
                <label class="flex items-center gap-3 p-4 bg-[#f7f9ff] rounded-xl cursor-pointer border-2 border-transparent hover:border-red-300 transition-all has-[:checked]:border-red-500 has-[:checked]:bg-red-50">
                  <input type="radio" name="upgrade_recommendation" value="not_eligible" class="accent-red-600 w-4 h-4">
                  <div>
                    <p class="font-bold text-sm text-[#001e40]">Not Eligible</p>
                    <p class="text-[10px] text-[#43474f] mt-0.5">Does not qualify for waiver</p>
                  </div>
                </label>
              </div>
            </div>

            <!-- Reasoning textarea -->
            <div>
              <label class="block text-xs font-bold text-[#001e40] uppercase tracking-wider mb-2">
                Reasoning <span class="text-red-500">*</span>
              </label>
              <textarea name="comment" required rows="4"
                class="w-full bg-[#d2e4f9] border-none rounded-xl px-4 py-3 text-[#0b1d2c] focus:ring-2 focus:ring-[#001e40] text-sm resize-none placeholder-[#43474f]/50"
                placeholder="State your assessment: attendance record, participation, exam performance context, reason for recommending or declining..."></textarea>
            </div>

            <p class="text-xs text-[#43474f] flex items-center gap-1">
              <span class="material-symbols-outlined text-sm">draw</span>
              Your digital signature (<strong><?= sanitize(($user['title'] ? $user['title'] . ' ' : '') . $user['full_name']) ?></strong>) will be appended automatically.
            </p>

            <button type="submit"
              onclick="return confirm('Submit your recommendation? This cannot be undone.')"
              class="flex items-center gap-2 bg-[#001e40] text-white px-6 py-3 rounded-xl font-bold text-sm hover:bg-[#003366] transition-all shadow-lg shadow-[#001e40]/20">
              <span class="material-symbols-outlined text-base">send</span>Submit Recommendation
            </button>
          </form>
        </div>
      </div>

      <?php else: ?>
      <!-- ── Score Verification Panel (default for grade disputes) ── -->
      <div class="bg-white rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] overflow-hidden no-print">
        <div class="px-6 py-4 bg-[#edf4ff]/50 flex items-center gap-2">
          <span class="material-symbols-outlined text-[#001e40] text-base">fact_check</span>
          <h4 class="font-bold text-[#001e40] text-sm">Score Verification Panel</h4>
        </div>
        <div class="p-6">
          <div class="flex items-start gap-3 bg-[#edf4ff] px-4 py-3 rounded-xl mb-6 text-xs text-[#001e40]">
            <span class="material-symbols-outlined text-sm flex-shrink-0">info</span>
            <p>Check your raw score sheets and enter the scores below. If there is no error, still confirm with a comment.</p>
          </div>
          <form method="POST" class="space-y-6">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div>
                <p class="text-xs font-bold text-[#43474f] uppercase tracking-wider mb-3">Original Scores (As Recorded)</p>
                <div class="grid grid-cols-2 gap-3">
                  <div>
                    <label class="block text-[10px] font-bold text-[#43474f] uppercase mb-1">Original CA</label>
                    <input type="number" name="original_ca_score" step="0.01" min="0" max="100"
                      class="w-full bg-[#d2e4f9] border-none rounded-xl px-3 py-2.5 text-[#0b1d2c] focus:ring-2 focus:ring-[#001e40] text-sm"
                      placeholder="e.g. 15" value="<?= htmlspecialchars($c['original_ca_score'] ?? '') ?>">
                  </div>
                  <div>
                    <label class="block text-[10px] font-bold text-[#43474f] uppercase mb-1">Original Exam</label>
                    <input type="number" name="original_exam_score" step="0.01" min="0" max="100"
                      class="w-full bg-[#d2e4f9] border-none rounded-xl px-3 py-2.5 text-[#0b1d2c] focus:ring-2 focus:ring-[#001e40] text-sm"
                      placeholder="e.g. 40" value="<?= htmlspecialchars($c['original_exam_score'] ?? '') ?>">
                  </div>
                </div>
              </div>
              <div>
                <p class="text-xs font-bold text-green-700 uppercase tracking-wider mb-3">Corrected Scores</p>
                <div class="grid grid-cols-2 gap-3">
                  <div>
                    <label class="block text-[10px] font-bold text-green-700 uppercase mb-1">Correct CA</label>
                    <input type="number" name="corrected_ca_score" step="0.01" min="0" max="100"
                      class="w-full bg-green-50 border border-green-200 rounded-xl px-3 py-2.5 text-[#0b1d2c] focus:ring-2 focus:ring-green-400 text-sm"
                      placeholder="e.g. 18" value="<?= htmlspecialchars($c['corrected_ca_score'] ?? '') ?>">
                  </div>
                  <div>
                    <label class="block text-[10px] font-bold text-green-700 uppercase mb-1">Correct Exam</label>
                    <input type="number" name="corrected_exam_score" step="0.01" min="0" max="100"
                      class="w-full bg-green-50 border border-green-200 rounded-xl px-3 py-2.5 text-[#0b1d2c] focus:ring-2 focus:ring-green-400 text-sm"
                      placeholder="e.g. 45" value="<?= htmlspecialchars($c['corrected_exam_score'] ?? '') ?>">
                  </div>
                </div>
              </div>
            </div>

            <div>
              <label class="block text-xs font-bold text-[#001e40] uppercase tracking-wider mb-2">
                Verification Statement <span class="text-red-500">*</span>
              </label>
              <textarea name="comment" required rows="4"
                class="w-full bg-[#d2e4f9] border-none rounded-xl px-4 py-3 text-[#0b1d2c] focus:ring-2 focus:ring-[#001e40] text-sm resize-none placeholder-[#43474f]/50"
                placeholder="State your findings after checking the raw score sheets..."></textarea>
            </div>

            <p class="text-xs text-[#43474f] flex items-center gap-1">
              <span class="material-symbols-outlined text-sm">draw</span>
              Your digital signature (<strong><?= sanitize(($user['title'] ? $user['title'] . ' ' : '') . $user['full_name']) ?></strong>) will be appended automatically.
            </p>

            <button type="submit"
              onclick="return confirm('Submit score verification? This cannot be undone.')"
              class="flex items-center gap-2 bg-[#001e40] text-white px-6 py-3 rounded-xl font-bold text-sm hover:bg-[#003366] transition-all shadow-lg shadow-[#001e40]/20">
              <span class="material-symbols-outlined text-base">send</span>Submit Verification
            </button>
          </form>
        </div>
      </div>
      <?php endif; // end isCourseUpgrade check ?>

    <?php else: ?>
    <!-- Already submitted -->
    <div class="flex items-center gap-3 bg-green-50 text-green-800 border border-green-200 px-5 py-4 rounded-xl text-sm font-medium no-print">
      <span class="material-symbols-outlined text-base">check_circle</span>
      <?= $isCourseUpgrade ? 'Recommendation submitted.' : 'Verification submitted.' ?>
      Status: <?= statusBadge($c['status']) ?>
    </div>
    <?php endif; // end assigned_to_lecturer check ?>

  </div><!-- /left col -->


  <!-- RIGHT SIDEBAR -->
  <div class="col-span-12 lg:col-span-4 space-y-4 no-print">

    <div class="bg-[#001e40] text-white p-6 rounded-xl">
      <p class="text-[10px] font-bold uppercase tracking-[0.2em] opacity-60 mb-4">Case Details</p>
      <div class="space-y-3 text-sm">
        <div><p class="text-[9px] opacity-60 uppercase font-bold">Student</p><p class="font-bold"><?= sanitize($c['student_name']) ?></p></div>
        <div><p class="text-[9px] opacity-60 uppercase font-bold">Matric</p><p class="font-mono"><?= sanitize($c['matric_number']) ?></p></div>
        <div><p class="text-[9px] opacity-60 uppercase font-bold">Course</p><p class="font-bold"><?= sanitize($c['course_code']) ?> – <?= sanitize($c['course_title']) ?></p></div>
        <div><p class="text-[9px] opacity-60 uppercase font-bold">Category</p><p><?= sanitize($c['category']) ?></p></div>
        <div><p class="text-[9px] opacity-60 uppercase font-bold">Session</p><p><?= sanitize($c['academic_session']) ?> / <?= sanitize($c['semester']) ?></p></div>
      </div>
    </div>

    <?php if ($c['sla_deadline']): ?>
    <div class="bg-white rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] p-5">
      <p class="text-[10px] font-bold uppercase tracking-widest text-[#43474f] mb-2">SLA Status</p>
      <p class="text-sm font-bold <?= $c['is_overdue'] ? 'text-red-600' : 'text-green-600' ?>">
        <?= $c['is_overdue'] ? '⚠ OVERDUE' : 'On Track' ?>
      </p>
      <p class="text-xs text-[#43474f] mt-1">Deadline: <?= formatDate($c['sla_deadline']) ?></p>
    </div>
    <?php endif; ?>

    <?php if (!empty($c['hod_comment'])): ?>
    <div class="bg-[#fffbeb] border border-[#fecb00] rounded-xl p-5">
      <p class="text-[10px] font-bold uppercase tracking-widest text-[#745b00] mb-2 flex items-center gap-1">
        <span class="material-symbols-outlined text-sm">gavel</span>HOD Directive
      </p>
      <p class="text-sm text-[#0b1d2c] italic">"<?= nl2br(sanitize($c['hod_comment'])) ?>"</p>
      <p class="text-[10px] text-[#43474f]/60 mt-2"><?= formatDate($c['hod_signed_at']) ?></p>
    </div>
    <?php endif; ?>

  </div><!-- /right sidebar -->
</div>

<?php include __DIR__ . '/../includes/layout_end.php'; ?>

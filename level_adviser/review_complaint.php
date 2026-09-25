<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('level_adviser');
$user = currentUser();
$db   = getDB();

$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare('
    SELECT c.*, co.course_code, co.course_title,
           u.full_name  AS student_name,  u.matric_number, u.department,
           u.faculty,   u.level,          u.email AS student_email,
           u.signature_path               AS student_sig,
           la.full_name AS la_name,       la.signature_path AS la_sig,
           la.title     AS la_title
    FROM complaints c
    JOIN  courses co ON c.course_id        = co.id
    JOIN  users   u  ON c.student_id       = u.id
    LEFT JOIN users la ON c.level_adviser_id = la.id
    WHERE c.id = ?
');
$stmt->execute([$id]);
$c = $stmt->fetch();

if (!$c || $c['department'] !== $user['department'] || $c['level'] !== $user['level']) {
    header('Location: ' . BASE_URL . 'level_adviser/dashboard.php'); exit;
}

$success = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $c['status'] === 'pending') {
    $action  = $_POST['action'] ?? '';
    $comment = trim($_POST['comment'] ?? '');
    $ip      = getClientIP();
    $now     = date('Y-m-d H:i:s');

    if ($action === 'endorse') {
        $db->prepare("
            UPDATE complaints
            SET status='endorsed', level_adviser_id=?, la_comment=?, la_signed_at=?, la_ip=?, updated_at=NOW()
            WHERE id=?
        ")->execute([$user['id'], $comment, $now, $ip, $id]);

        logAudit($id, $user['id'], 'endorsed', $ip, 'Level Adviser endorsed the complaint.');

        $hodStmt = $db->prepare("SELECT id FROM users WHERE is_hod=1 AND department=? LIMIT 1");
        $hodStmt->execute([$user['department']]);
        $hod = $hodStmt->fetch();
        if ($hod) addNotification($hod['id'], $id,
            "Complaint #{$c['ticket_number']} endorsed by Level Adviser {$user['full_name']} and forwarded.");

        $success = 'Complaint endorsed and forwarded to HOD.';

    } elseif ($action === 'return') {
        $reason = trim($_POST['return_reason'] ?? '');
        if (!$reason) {
            $error = 'Return reason is required.';
        } else {
            $db->prepare("
                UPDATE complaints
                SET status='returned_to_student', return_reason=?, returned_at=?, updated_at=NOW()
                WHERE id=?
            ")->execute([$reason, $now, $id]);

            logAudit($id, $user['id'], 'returned_to_student', $ip, 'Level Adviser returned: ' . $reason);
            addNotification($c['student_id'], $id,
                "Your complaint #{$c['ticket_number']} has been returned. Reason: $reason");
            $success = 'Complaint returned to student for additional information.';
        }
    }

    // Refresh after action
    $stmt->execute([$id]);
    $c = $stmt->fetch();
}

// ── Letter body decode ────────────────────────────────────────────────────────
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

// ── Signature helpers ─────────────────────────────────────────────────────────
$studentSigUrl = (!empty($c['student_sig']) && file_exists(__DIR__ . '/../' . $c['student_sig']))
    ? BASE_URL . $c['student_sig'] : null;

// LA sig: use endorsing LA's path if already endorsed, else logged-in LA's sig (preview)
$laSigPath = $c['la_sig'] ?? $user['signature_path'] ?? null;
$laSigUrl  = ($laSigPath && file_exists(__DIR__ . '/../' . $laSigPath))
    ? BASE_URL . $laSigPath : null;

$laTitle   = $c['la_title'] ?? $user['title'] ?? '';
$laName    = $c['la_name']  ?? ($laTitle ? $laTitle . ' ' . $user['full_name'] : $user['full_name']);
$laEndorsed = in_array($c['status'], ['endorsed','assigned_to_lecturer','verified','approved','rejected']);

// Faculty — from student row (u.faculty joined above)
$faculty = $c['faculty'] ?? '';

$pageTitle = 'Review ' . $c['ticket_number'];
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
  <a href="<?= BASE_URL ?>level_adviser/dashboard.php" class="text-[#43474f] hover:text-[#001e40] transition-colors">
    <span class="material-symbols-outlined">arrow_back</span>
  </a>
  <div class="flex items-center gap-3 flex-1">
    <h2 class="text-2xl font-black text-[#001e40]">Review Complaint</h2>
    <code class="text-sm bg-[#edf4ff] text-[#001e40] px-3 py-1 rounded-lg font-mono font-bold"><?= sanitize($c['ticket_number']) ?></code>
    <?= statusBadge($c['status']) ?>
  </div>
  <button onclick="window.print()"
    class="flex items-center gap-1 text-[#43474f] hover:text-[#001e40] text-xs font-bold p-2 hover:bg-[#edf4ff] rounded-lg transition-colors">
    <span class="material-symbols-outlined text-sm">print</span>Print
  </button>
</div>

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

  <!-- LEFT: Letter + panels -->
  <div class="col-span-12 lg:col-span-8 space-y-5">

    <!-- OFFICIAL LASU LETTER -->
    <div class="bg-white rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] overflow-hidden border border-[#d2e4f9]">

      <!-- Tri-colour bars -->
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
            <!-- HOD hasn't acted yet at LA stage — empty slot preserves layout -->
            <div style="min-width:160px;"></div>
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

            <!-- LA sig — only after endorsement -->
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
                <p style="font-weight:bold;font-size:12px;margin:0 0 1px;color:#001e40;"><?= sanitize($laName) ?></p>
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
          <p style="text-align:justify;margin-bottom:10px;"><?= nl2br(sanitize($introText)) ?></p>
          <p style="text-align:justify;margin-bottom:32px;"><?= nl2br(sanitize($closingText)) ?></p>

          <!-- Yours faithfully -->
          <p style="margin-bottom:48px;">Yours faithfully,</p>

          <!-- Bottom signature row: Student | LA -->
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:32px 40px;align-items:end;">

            <!-- Student -->
            <div style="display:inline-block;">
              <div style="height:56px;display:flex;align-items:flex-end;padding-bottom:4px;">
                <?php if ($studentSigUrl): ?>
                  <img src="<?= $studentSigUrl ?>" alt="Student Signature"
                       style="height:48px;max-width:150px;object-fit:contain;mix-blend-mode:multiply;">
                <?php else: ?>
                  <div style="height:1px;width:150px;"></div>
                <?php endif; ?>
              </div>
              <div style="border-top:1.5px solid #333;padding-top:5px;width:fit-content;min-width:150px;">
                <p style="font-weight:bold;font-size:12px;margin:0 0 1px;white-space:nowrap;"><?= sanitize($c['student_name']) ?></p>
                <p style="font-size:10px;font-style:italic;color:#555;margin:0;white-space:nowrap;">
                  <?= sanitize($c['matric_number']) ?> &bull; Level <?= sanitize($c['level']) ?>
                </p>
              </div>
            </div>



          </div><!-- /sig grid -->

        </div><!-- /z-index wrapper -->
      </div><!-- /letter body -->

      <!-- Footer bar -->
      <div style="text-align:center;font-size:9px;color:#555;padding:5px 20px;border-top:1px solid #ddd;font-family:Arial,sans-serif;">
        Lagos State University, Badagry Expressway, P.M.B. 0001, Ojo, Lagos, Nigeria. &bull; www.lasu.edu.ng
      </div>
      <div style="height:5px;background:#001e40;"></div>
      <div style="height:5px;background:#fecb00;"></div>

    </div><!-- /letter card -->


    <!-- COMPLAINT DETAILS -->
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

    <!-- ATTACHED EVIDENCE -->
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

    <!-- ACTION PANEL -->
    <?php if ($c['status'] === 'pending'): ?>
    <div class="bg-white rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] overflow-hidden no-print">
      <div class="px-6 py-4 bg-[#edf4ff]/50 flex items-center gap-2">
        <span class="material-symbols-outlined text-[#001e40] text-base">edit_note</span>
        <h4 class="font-bold text-[#001e40] text-sm">Your Action</h4>
      </div>
      <div class="p-6">
        <form method="POST" id="actionForm">
          <div class="mb-5">
            <label class="block text-xs font-bold text-[#001e40] uppercase tracking-wider mb-2">
              Comment <span class="text-[#43474f] font-normal normal-case">(optional — will appear on the record)</span>
            </label>
            <textarea name="comment" rows="3"
              class="w-full bg-[#d2e4f9] border-none rounded-xl px-4 py-3 text-[#0b1d2c] focus:ring-2 focus:ring-[#001e40] text-sm placeholder-[#43474f]/50 resize-none"
              placeholder="Add any remarks to attach to this complaint..."></textarea>
          </div>

          <div id="return_reason_block" class="hidden mb-5">
            <label class="block text-xs font-bold text-red-600 uppercase tracking-wider mb-2">
              Return Reason <span class="text-red-500">*</span>
            </label>
            <textarea name="return_reason" rows="3"
              class="w-full bg-[#ffdad6]/30 border border-red-200 rounded-xl px-4 py-3 text-[#0b1d2c] focus:ring-2 focus:ring-red-400 text-sm resize-none"
              placeholder="Explain what the student needs to fix or re-upload..."></textarea>
          </div>

          <div class="flex gap-3 flex-wrap">
            <button type="submit" name="action" value="endorse"
              onclick="return confirm('Endorse and forward this complaint to the HOD?')"
              class="flex items-center gap-2 bg-[#001e40] text-white px-6 py-3 rounded-xl font-bold text-sm hover:bg-[#003366] transition-all shadow-lg shadow-[#001e40]/20">
              <span class="material-symbols-outlined text-base">verified</span>Endorse &amp; Forward to HOD
            </button>

            <button type="button" id="show_return_btn"
              onclick="document.getElementById('return_reason_block').classList.remove('hidden');
                       document.getElementById('confirm_return_btn').classList.remove('hidden');
                       this.classList.add('hidden');"
              class="flex items-center gap-2 bg-[#ffe08b]/50 text-[#745b00] px-6 py-3 rounded-xl font-bold text-sm hover:bg-[#ffe08b] transition-all">
              <span class="material-symbols-outlined text-base">reply</span>Return to Student
            </button>

            <button type="submit" name="action" value="return" id="confirm_return_btn"
              onclick="return confirm('Return this complaint to the student?')"
              class="hidden flex items-center gap-2 bg-red-600 text-white px-6 py-3 rounded-xl font-bold text-sm hover:bg-red-700 transition-all">
              <span class="material-symbols-outlined text-base">send</span>Confirm Return
            </button>
          </div>
        </form>
      </div>
    </div>

    <?php else: ?>
    <!-- Added flex-wrap and items-center to keep the badge aligned with the text -->
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 bg-[#edf4ff] text-[#001e40] px-5 py-4 rounded-xl text-sm font-medium leading-relaxed">
      <span class="material-symbols-outlined text-base self-start mt-0.5">info</span>

      <span class="flex items-center gap-1">
        This complaint has been (Status:
        <span class="inline-block transform scale-90 origin-left">
            <?= statusBadge($c['status']) ?>
        </span>
        ). No further action required.
      </span>
    </div>
<?php endif; ?>

  </div><!-- /left col -->


  <!-- RIGHT SIDEBAR -->
  <div class="col-span-12 lg:col-span-4 space-y-4 no-print">

    <!-- Student info -->
    <div class="bg-[#001e40] text-white p-6 rounded-xl">
      <p class="text-[10px] font-bold uppercase tracking-[0.2em] opacity-60 mb-4">Student Information</p>
      <div class="space-y-3 text-sm">
        <div>
          <p class="text-[9px] opacity-60 uppercase font-bold">Name</p>
          <p class="font-bold"><?= sanitize($c['student_name']) ?></p>
        </div>
        <div>
          <p class="text-[9px] opacity-60 uppercase font-bold">Matric No.</p>
          <p class="font-mono font-bold"><?= sanitize($c['matric_number']) ?></p>
        </div>
        <div>
          <p class="text-[9px] opacity-60 uppercase font-bold">Department</p>
          <p class="font-medium"><?= sanitize($c['department']) ?></p>
        </div>
        <div>
          <p class="text-[9px] opacity-60 uppercase font-bold">Level</p>
          <p class="font-medium"><?= sanitize($c['level']) ?> Level</p>
        </div>
        <div>
          <p class="text-[9px] opacity-60 uppercase font-bold">Email</p>
          <p class="font-medium text-[#a7c8ff]"><?= sanitize($c['student_email']) ?></p>
        </div>
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

    <!-- Endorsement comment -->
    <?php if ($c['la_comment']): ?>
    <div class="bg-white rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] p-5">
      <p class="text-xs font-bold text-[#001e40] uppercase tracking-widest mb-3 flex items-center gap-2">
        <span class="material-symbols-outlined text-sm">comment</span>Your Endorsement Comment
      </p>
      <p class="text-sm text-[#43474f] italic">"<?= nl2br(sanitize($c['la_comment'])) ?>"</p>
      <p class="text-[10px] text-[#43474f]/60 mt-2"><?= formatDate($c['la_signed_at']) ?></p>
    </div>
    <?php endif; ?>

  </div><!-- /right sidebar -->

</div>

<?php include __DIR__ . '/../includes/layout_end.php'; ?>

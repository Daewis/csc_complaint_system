<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/groq_scan.php';
require_once __DIR__ . '/../includes/complaint_rules.php';
requireRole('student');
$user  = currentUser();
$db    = getDB();
$error = '';
$aiWarning = ''; // Non-blocking AI warning (low confidence)

// ── Course list: joined against the new normalized schema ───────────────
// (courses no longer has department/level/semester/status/curriculum_type —
//  those now live on course_offerings, scoped via departments)
$stmt = $db->prepare('
    SELECT
        c.id,
        c.course_code,
        c.course_title,
        co.credit_units,
        co.level,
        co.semester,
        co.status,
        co.curriculum_type
    FROM course_offerings co
    JOIN courses c       ON c.id = co.course_id
    JOIN departments d   ON d.id = co.department_id
    WHERE TRIM(LOWER(d.name)) = TRIM(LOWER(?))
    ORDER BY c.course_code
');
$stmt->execute([$user['department']]);
$courses = $stmt->fetchAll();

// ── DEBUG: if no courses came back, surface why (remove once confirmed fixed) ──
if (empty($courses) && isset($_GET['debug'])) {
    $deptCheck = $db->query("SELECT id, name, faculty_id FROM departments ORDER BY name")->fetchAll();
    echo '<pre style="background:#fee;padding:20px;font-size:12px;">';
    echo "User's department value: '" . htmlspecialchars($user['department']) . "'\n\n";
    echo "Departments currently in DB:\n";
    print_r($deptCheck);
    echo '</pre>';
}

$currentYear = (int)date('Y');
$sessions = [];
for ($i = 0; $i < 4; $i++) $sessions[] = ($currentYear - $i) . '/' . ($currentYear - $i + 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirmed'] ?? '') === '1') {
    $courseId  = (int)($_POST['course_id'] ?? 0);
    $session   = trim($_POST['academic_session'] ?? '');
    $semester  = $_POST['semester'] ?? '';
    $category  = trim($_POST['category'] ?? '');
    $complaint = trim($_POST['complaint_text'] ?? '');

    // ── letter_body: decode from base64 (WAF-safe transport) ─────────────
    $letterBodyRaw = trim($_POST['letter_body'] ?? '');
    $letterBody = '';
    if ($letterBodyRaw) {
        $decoded = base64_decode($letterBodyRaw, true);
        $letterBody = ($decoded !== false) ? $decoded : $letterBodyRaw;
    }

    // ── Determine complaint type from category ────────────────────────────
    $complaintType = 'grade_dispute';
    if ($category === 'Appeal for Course Removal') {
        $complaintType = 'course_removal';
    } elseif ($category === 'Appeal for Course Upgrade') {
        $complaintType = 'course_upgrade';
    }

    $allowed_sem = ['First', 'Second'];

    // ── Basic field validation ────────────────────────────────────────────
    if (!$courseId || !$session || !in_array($semester, $allowed_sem) || empty($category) || !$complaint) {
        $missing = [];
        if (!$courseId)                        $missing[] = 'course_id=EMPTY';
        if (!$session)                         $missing[] = 'session=EMPTY';
        if (!in_array($semester,$allowed_sem)) $missing[] = 'semester=' . ($semester ?: 'EMPTY');
        if (empty($category))                  $missing[] = 'category=EMPTY';
        if (!$complaint)                       $missing[] = 'complaint=EMPTY';
        $error = 'Missing fields: ' . implode(', ', $missing);

    } elseif (strlen($category) > 100) {
        $error = 'Category must be 100 characters or fewer.';

    } else {

        // ── Course Upgrade: final year check BEFORE file upload ───────────
        if ($complaintType === 'course_upgrade') {
            $allowedLevels = ['400', '500'];
            if (!in_array((string)$user['level'], $allowedLevels)) {
                $error = 'Course upgrade appeals are only available to final year students (400/500 Level).';
            }
        }

        // ── Course Removal: evidence is REQUIRED ─────────────────────────
        if (!$error && $complaintType === 'course_removal' && empty($_FILES['evidence_file']['name'])) {
            $error = 'Course removal appeals require evidence (course form, docket, or result PDF).';
        }

        // ── File upload ───────────────────────────────────────────────────
        $evidencePath = null;
        if (!$error && !empty($_FILES['evidence_file']['name'])) {
            $evidencePath = uploadEvidence($_FILES['evidence_file']);
            if (!$evidencePath) {
                $error = 'Invalid file. Use JPG, PNG, PDF (max 2OOKB).';
            }
        }

        // ── AI scan for course removal ────────────────────────────────────
        if (!$error && $complaintType === 'course_removal' && $evidencePath) {
            $fullPath = __DIR__ . '/../' . $evidencePath;
            $scan = scanDocumentWithGroq($fullPath);

            if (!$scan['success']) {
                // AI failed — don't block, flag for manual LA review
         //       $aiWarning = 'AI document scan could not verify your evidence automatically. Your Level Adviser will review it manually.';
                //die('<pre>' . print_r($scan, true) . '</pre>');
                // Store a flag so LA knows this needs manual check
          //      $complaint .= "\n\n[AI_SCAN_FAILED: Manual verification required]";
            } else {
                $scanData = $scan['data'];

                // Low confidence — don't block, flag for manual review
                if (isset($scanData['confidence']) && (int)$scanData['confidence'] < 70) {
                    $aiWarning = 'AI scan confidence was low. Your Level Adviser will verify the document manually.';
                    $complaint .= "\n\n[AI_LOW_CONFIDENCE: " . (int)$scanData['confidence'] . "% — Manual verification required]";
                } elseif (isset($scanData['error'])) {
                    $aiWarning = 'AI could not read your document clearly. Your Level Adviser will review it manually.';
                    $complaint .= "\n\n[AI_UNREADABLE: Manual verification required]";
                } else {
                    // Fetch the course being appealed for removal
                    // NOTE: courses no longer has the offering-specific columns
                    // (level/semester/units/status/curriculum_type). If
                    // validateCourseRemoval() needs those, fetch the
                    // student's specific offering row instead of just the course.
                    $courseStmt = $db->prepare('
                        SELECT
                            c.id,
                            c.course_code,
                            c.course_title,
                            co.credit_units,
                            co.level,
                            co.semester,
                            co.status,
                            co.curriculum_type
                        FROM course_offerings co
                        JOIN courses c     ON c.id = co.course_id
                        JOIN departments d ON d.id = co.department_id
                        WHERE c.id = ? AND TRIM(LOWER(d.name)) = TRIM(LOWER(?))
                        LIMIT 1
                    ');
                    $courseStmt->execute([$courseId, $user['department']]);
                    $courseRow = $courseStmt->fetch();

                    if ($courseRow) {
                        $validation = validateCourseRemoval($scanData, $courseRow);
                        if (!$validation['eligible']) {
                            $error = '❌ Course removal not eligible: ' . $validation['reason'];
                        }
                        // Append AI scan summary to complaint for LA reference
                        if (!$error) {
                            $scannedTotal = $scanData['total_units'] ?? 'N/A';
                            $complaint .= "\n\n[AI_VERIFIED: Scanned total units = {$scannedTotal}. Eligibility confirmed.]";
                        }
                    }
                }
            }
        }

        // ── Save complaint ────────────────────────────────────────────────
        if (!$error) {
            $ticketNumber = generateTicketNumber();
            $savedBody    = $letterBody ?: null;

  // ── AI validation status ─────────────────────────────
$aiStatus = 'pending';
$aiReason = null;

if ($complaintType === 'course_removal') {

    if (!$scan['success'] || isset($scanData['error'])) {

        $aiStatus = 'manual_review';
        $aiReason = $scan['message'] ?? 'AI could not read document';

    } elseif (isset($scanData['confidence']) && (int)$scanData['confidence'] < 70) {

        $aiStatus = 'manual_review';
        $aiReason = 'Low confidence: ' . $scanData['confidence'] . '%';

    } elseif (!$validation['eligible']) {

        $aiStatus = 'failed';
        $aiReason = $validation['reason'];

    } else {

        $aiStatus = 'passed';
        $aiReason = 'Total units verified: ' . ($scanData['total_units'] ?? 'N/A');
    }
}

// ── Insert complaint ─────────────────────────────────
$stmt = $db->prepare("
    INSERT INTO complaints (
        ticket_number,
        student_id,
        course_id,
        academic_session,
        semester,
        category,
        complaint_text,
        letter_body,
        evidence_path,
        ai_validation_status,
        ai_validation_reason,
        status
    ) VALUES (
        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending'
    )
");

$stmt->execute([
    $ticketNumber,
    $user['id'],
    $courseId,
    $session,
    $semester,
    $category,
    $complaint,
    $savedBody,
    $evidencePath,
    $aiStatus,
    $aiReason
]);
            $newId = $db->lastInsertId();

            logAudit($newId, $user['id'], 'complaint_filed', getClientIP(),
                "New {$complaintType} complaint submitted.");

            // Notify Level Adviser
            $laStmt = $db->prepare("
                SELECT id FROM users
                WHERE is_level_adviser = 1 AND department = ? AND `level` = ?
                LIMIT 1
            ");
            $laStmt->execute([$user['department'], $user['level']]);
            $la = $laStmt->fetch();

            $notifMsg = "New complaint filed by {$user['full_name']} ({$user['matric_number']}). Ticket: $ticketNumber";
            if ($aiWarning) {
                $notifMsg .= ' [Requires manual document verification]';
            }
            if ($la) addNotification($la['id'], $newId, $notifMsg);

            header("Location: " . BASE_URL . "student/view_complaint.php?id={$newId}&success=filed");
            exit;
        }
    }
}

$hasSignature = !empty($user['signature_path']) && file_exists(__DIR__ . '/../' . $user['signature_path']);
$sigUrl       = $hasSignature ? BASE_URL . $user['signature_path'] : '';

$pageTitle = 'Submit Complaint';
$activeNav = 'new_complaint.php';
include __DIR__ . '/../includes/layout.php';

$courseMap = [];
foreach ($courses as $c) {
    $courseMap[$c['id']] = [
        'code'   => $c['course_code'],
        'title'  => $c['course_title'],
        'status' => $c['status'] ?? 'C', // C=Compulsory, E=Elective, R=Required
    ];
}
?>

<!-- Header -->
<div class="mb-8">
  <div class="flex items-center gap-3 mb-2">
    <a href="<?= BASE_URL ?>student/dashboard.php" class="text-[#43474f] hover:text-[#001e40] transition-colors">
      <span class="material-symbols-outlined">arrow_back</span>
    </a>
    <h2 class="text-3xl font-black text-[#001e40] tracking-tight">Student Complaint Form</h2>
  </div>
  <p class="text-[#43474f] text-sm ml-9">Lodge official academic grievances. Your submission will be formatted as an institutional memorandum.</p>
</div>

<?php if ($error): ?>
<div class="mb-6 flex items-center gap-3 bg-[#ffdad6] text-[#93000a] px-5 py-4 rounded-xl text-sm font-medium">
  <span class="material-symbols-outlined text-base">error</span><?= sanitize($error) ?>
</div>
<?php endif; ?>

<?php if ($aiWarning): ?>
<div class="mb-6 flex items-start gap-3 bg-[#ffe08b]/40 text-[#745b00] px-5 py-4 rounded-xl text-sm font-medium">
  <span class="material-symbols-outlined text-base flex-shrink-0 mt-0.5">smart_toy</span>
  <span><strong>AI Notice:</strong> <?= sanitize($aiWarning) ?></span>
</div>
<?php endif; ?>

<?php if (!$hasSignature): ?>
<div class="mb-6 flex items-center gap-3 bg-[#ffe08b]/40 text-[#745b00] px-5 py-4 rounded-xl text-sm font-medium">
  <span class="material-symbols-outlined text-base flex-shrink-0">draw</span>
  <span>You have not uploaded your signature yet. Your letter will show a blank signature line.
    <a href="<?= BASE_URL ?>profile.php" class="font-bold underline ml-1">Upload signature in Profile →</a>
  </span>
</div>
<?php endif; ?>

<div class="grid grid-cols-12 gap-6">

  <!-- ── Left: Form ── -->
  <form method="POST" enctype="multipart/form-data" id="complaintForm" class="col-span-12 lg:col-span-8 space-y-5">
    <input type="hidden" name="confirmed"   id="confirmedInput"  value="">
    <input type="hidden" name="letter_body" id="letterBodyInput">
    <input type="hidden" name="category"    id="categoryHidden">

    <!-- Session & Semester -->
    <div class="bg-white p-6 rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] grid grid-cols-2 gap-5">
      <div>
        <label class="block text-xs font-bold text-[#001e40] uppercase tracking-wider mb-2">Academic Session <span class="text-red-500">*</span></label>
        <div class="relative">
          <select name="academic_session" id="f_session" required
            class="w-full bg-[#d2e4f9] border-none rounded-xl px-4 py-3 text-[#0b1d2c] focus:ring-2 focus:ring-[#001e40] appearance-none text-sm">
            <option value="">— Select Session —</option>
            <?php foreach ($sessions as $s): ?><option value="<?= $s ?>"><?= $s ?></option><?php endforeach; ?>
          </select>
          <span class="material-symbols-outlined absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none text-[#43474f] text-lg">expand_more</span>
        </div>
      </div>
      <div>
        <label class="block text-xs font-bold text-[#001e40] uppercase tracking-wider mb-2">Semester <span class="text-red-500">*</span></label>
        <div class="relative">
          <select name="semester" id="f_semester" required
            class="w-full bg-[#d2e4f9] border-none rounded-xl px-4 py-3 text-[#0b1d2c] focus:ring-2 focus:ring-[#001e40] appearance-none text-sm">
            <option value="">— Select —</option>
            <option value="First">First Semester</option>
            <option value="Second">Second Semester</option>
          </select>
          <span class="material-symbols-outlined absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none text-[#43474f] text-lg">expand_more</span>
        </div>
      </div>
    </div>

    <!-- Course & Category -->
    <div class="bg-white p-6 rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)] grid grid-cols-2 gap-5">
      <div>
        <label class="block text-xs font-bold text-[#001e40] uppercase tracking-wider mb-2">Course Code <span class="text-red-500">*</span></label>
        <div class="relative">
          <input list="course_list" name="course_display" id="f_course_search"
            placeholder="e.g. CSC 111" autocomplete="off" required
            class="w-full bg-[#d2e4f9] border-none rounded-xl px-4 py-3 text-[#0b1d2c] focus:ring-2 focus:ring-[#001e40] text-sm font-bold transition-all"
            oninput="resolveCourseId(this.value)" onblur="resolveCourseId(this.value)">
          <datalist id="course_list">
            <?php foreach ($courses as $course): ?>
              <option value="<?= sanitize($course['course_code']) ?> – <?= sanitize($course['course_title']) ?>"></option>
            <?php endforeach; ?>
          </datalist>
          <span id="courseMatchIcon" class="absolute right-3 top-1/2 -translate-y-1/2 text-green-500 material-symbols-outlined text-base hidden">check_circle</span>
          <input type="hidden" name="course_id" id="course_id_hidden">
        </div>
        <p id="courseMatchLabel" class="mt-1.5 text-[10px] font-bold text-green-600 hidden"></p>
      </div>
      <div>
        <label class="block text-xs font-bold text-[#001e40] uppercase tracking-wider mb-2">
          Discrepancy Category <span class="text-red-500">*</span>
        </label>
        <input list="category_suggestions" type="text" id="f_category"
          placeholder="e.g. Missing Score, Wrong Grade" maxlength="100"
          class="w-full bg-[#d2e4f9] border-none rounded-xl px-4 py-3 text-[#0b1d2c] focus:ring-2 focus:ring-[#001e40] text-sm font-bold transition-all"
          oninput="onCategoryChange(this.value)"
          onchange="onCategoryChange(this.value)">
        <datalist id="category_suggestions">
          <option value="Wrong Grade Assigned">
          <option value="Outstanding Result">
          <option value="Appeal for Course Removal">
          <option value="Appeal for Course Upgrade">
          <option value="Other">
        </datalist>
        <p id="categoryHint" class="mt-1.5 text-[10px] font-bold hidden"></p>
      </div>
    </div>

    <!-- Context-aware info banner — shown for special categories -->
    <div id="categoryInfoBanner" class="hidden bg-[#edf4ff] border-l-4 border-[#001e40] px-5 py-4 rounded-r-xl text-sm text-[#001e40]">
      <p id="categoryInfoText" class="font-medium"></p>
    </div>

    <!-- Description -->
    <div class="bg-white p-6 rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)]">
      <div class="flex items-center justify-between mb-2">
        <label class="text-xs font-bold text-[#001e40] uppercase tracking-wider">Detailed Description <span class="text-red-500">*</span></label>
        <span class="text-[10px] text-[#43474f] font-medium" id="charCount">0 / 1000</span>
      </div>
      <textarea name="complaint_text" id="f_complaint" required rows="5" maxlength="1000"
        class="w-full bg-[#d2e4f9] border-none rounded-xl px-4 py-3 text-[#0b1d2c] focus:ring-2 focus:ring-[#001e40] placeholder-[#43474f]/50 text-sm resize-none"
        placeholder="Provide a detailed account: expected scores, recorded scores, discrepancy observed..."></textarea>
    </div>

    <!-- Evidence Upload -->
    <div id="evidenceUploadBlock"
         class="bg-[#edf4ff] border-2 border-dashed border-[#c3c6d1]/40 rounded-xl p-8 flex flex-col items-center text-center gap-4 transition-all hover:bg-[#d2e4f9]/50 cursor-pointer"
         onclick="document.getElementById('evidence_file').click()">
      <div class="w-14 h-14 bg-white rounded-full flex items-center justify-center text-[#001e40] shadow-sm">
        <span class="material-symbols-outlined text-2xl">upload_file</span>
      </div>
      <div>
        <p class="font-bold text-[#001e40] text-sm" id="evidenceLabel">Upload Portal Evidence <span class="text-[#43474f] font-normal">(optional)</span></p>
        <p class="text-xs text-[#43474f] mt-1" id="evidenceSublabel">Drag &amp; drop or click to browse</p>
      </div>
      <input type="file" name="evidence_file" id="evidence_file" accept="image/*,.pdf" class="hidden" onchange="previewEvidence(this)">
      <p class="text-[10px] uppercase tracking-widest text-[#43474f] font-bold">JPG · PNG · PDF &bull; Max 2MB</p>
      <p id="evidenceFileName" class="hidden text-xs font-bold text-[#001e40]"></p>
      <img id="evidence_preview" src="#" class="hidden max-h-40 rounded-lg shadow-sm">
    </div>

    <!-- Action Buttons -->
    <div class="flex gap-3 pt-2">
      <button type="button" onclick="showPreview()"
        class="flex-1 bg-[#001e40] text-white py-4 rounded-xl font-bold text-base shadow-xl shadow-[#001e40]/20 hover:bg-[#003366] hover:scale-[1.01] active:scale-[0.98] transition-all flex items-center justify-center gap-2">
        <span class="material-symbols-outlined text-base">preview</span>Preview &amp; Submit
      </button>
      <a href="<?= BASE_URL ?>student/dashboard.php"
        class="px-8 bg-[#edf4ff] text-[#001e40] font-bold rounded-xl hover:bg-[#d2e4f9] transition-all flex items-center">
        Cancel
      </a>
    </div>
  </form>

  <!-- ── Right: Sidebar ── -->
  <aside class="col-span-12 lg:col-span-4 space-y-5">

 <!-- Identity Card -->
    <div class="bg-[#001e40] text-white p-6 rounded-xl relative overflow-hidden">
      <div class="absolute top-0 left-0 w-full h-1 bg-[#fecb00]"></div>
      <p class="text-[10px] font-bold uppercase tracking-[0.2em] opacity-60 mb-5">Submission Identity</p>
      <div class="space-y-4">
        <?php foreach ([
          ['fingerprint', 'Matriculation No.', $user['matric_number'] ?? 'N/A'],
          ['person',      'Student Name',       strtoupper($user['full_name'])],
          ['school',      'Department',         $user['department']],
          ['grade',       'Level',              $user['level'] . ' Level'],
        ] as [$icon, $label, $val]): ?>
        <div class="flex items-start gap-3">
          <div class="w-10 h-10 bg-white/10 rounded-lg flex items-center justify-center flex-shrink-0">
            <span class="material-symbols-outlined text-white text-base"><?= $icon ?></span>
          </div>
          <div>
            <p class="text-[9px] font-bold uppercase opacity-60"><?= $label ?></p>
            <p class="font-bold tracking-tight text-sm"><?= sanitize($val) ?></p>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="mt-5 pt-4 border-t border-white/10">
        <p class="text-[9px] font-bold uppercase opacity-60 mb-2">Your Signature (on letter)</p>
        <?php if ($hasSignature): ?>
          <img src="<?= sanitize($sigUrl) ?>" alt="Your Signature" class="h-12 bg-white/10 rounded-lg p-1 max-w-[160px]">
        <?php else: ?>
          <div class="flex items-center gap-2 text-[#fecb00] text-xs">
            <span class="material-symbols-outlined text-sm">warning</span>
            No signature uploaded.
            <a href="<?= BASE_URL ?>profile.php" class="underline font-bold">Add one →</a>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Institutional Note -->
    <div class="bg-[#ffe08b]/30 border-l-4 border-[#fecb00] p-5 rounded-r-xl">
      <div class="flex items-center gap-2 mb-2 text-[#745b00]">
        <span class="material-symbols-outlined text-sm" style="font-variation-settings:'FILL' 1">info</span>
        <p class="text-[10px] font-bold uppercase tracking-wider">Institutional Note</p>
      </div>
      <p class="text-xs text-[#0b1d2c] leading-relaxed italic">
        "Click Preview &amp; Submit to see exactly how your complaint will appear as a formal LASU letter before it is submitted."
      </p>
    </div>

    <!-- Processing Pipeline — dynamic based on category -->
    <div class="bg-white p-6 rounded-xl shadow-[0_20px_40px_rgba(11,29,44,0.06)]">
      <h4 class="font-bold text-[#001e40] text-sm mb-5 flex items-center gap-2">
        <span class="material-symbols-outlined text-base">alt_route</span>Processing Pipeline
      </h4>
      <!-- Default pipeline -->
      <div id="pipeline_default" class="space-y-4 relative">
        <div class="absolute left-[11px] top-0 bottom-0 w-px bg-[#c3c6d1]/40"></div>
        <?php foreach ([
          ['edit',           'Submission',    'You file the complaint',  true],
          ['verified_user',  'Level Adviser', 'Portal record audit',     false],
          ['account_balance','HOD Review',    'Faculty escalation',      false],
          ['school',         'Lecturer',      'Score verification',      false],
          ['task_alt',       'Resolution',    'Final decision',          false],
        ] as [$icon, $title, $sub, $active]): ?>
        <div class="relative flex gap-3 items-start">
          <div class="w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0 z-10 <?= $active ? 'bg-[#001e40]' : 'bg-[#d2e4f9]' ?>">
            <span class="material-symbols-outlined text-[12px] <?= $active ? 'text-white' : 'text-[#43474f]' ?>"><?= $icon ?></span>
          </div>
          <div class="<?= $active ? '' : 'opacity-40' ?>">
            <p class="text-sm font-bold text-[#0b1d2c] leading-none"><?= $title ?></p>
            <p class="text-[10px] text-[#43474f] mt-0.5"><?= $sub ?></p>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Course Removal pipeline -->
      <div id="pipeline_removal" class="space-y-4 relative hidden">
        <div class="absolute left-[11px] top-0 bottom-0 w-px bg-[#c3c6d1]/40"></div>
        <?php foreach ([
          ['edit',           'Submission',    'AI scans your evidence',  true],
          ['smart_toy',      'AI Check',      'Unit & eligibility check',false],
          ['verified_user',  'Level Adviser', 'Manual verification',     false],
          ['account_balance','HOD',           'Final approval',          false],
          ['task_alt',       'Resolution',    'Course removed',          false],
        ] as [$icon, $title, $sub, $active]): ?>
        <div class="relative flex gap-3 items-start">
          <div class="w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0 z-10 <?= $active ? 'bg-[#001e40]' : 'bg-[#d2e4f9]' ?>">
            <span class="material-symbols-outlined text-[12px] <?= $active ? 'text-white' : 'text-[#43474f]' ?>"><?= $icon ?></span>
          </div>
          <div class="<?= $active ? '' : 'opacity-40' ?>">
            <p class="text-sm font-bold text-[#0b1d2c] leading-none"><?= $title ?></p>
            <p class="text-[10px] text-[#43474f] mt-0.5"><?= $sub ?></p>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Course Upgrade pipeline -->
      <div id="pipeline_upgrade" class="space-y-4 relative hidden">
        <div class="absolute left-[11px] top-0 bottom-0 w-px bg-[#c3c6d1]/40"></div>
        <?php foreach ([
          ['edit',           'Submission',    'Final year students only', true],
          ['verified_user',  'Level Adviser', 'Endorsement',             false],
          ['account_balance','HOD Review',    'Initial review',          false],
          ['school',         'Lecturer',      'Grade recommendation',    false],
          ['account_balance','HOD Final',     'Final approval',          false],
          ['task_alt',       'Resolution',    'Grade upgraded',          false],
        ] as [$icon, $title, $sub, $active]): ?>
        <div class="relative flex gap-3 items-start">
          <div class="w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0 z-10 <?= $active ? 'bg-[#001e40]' : 'bg-[#d2e4f9]' ?>">
            <span class="material-symbols-outlined text-[12px] <?= $active ? 'text-white' : 'text-[#43474f]' ?>"><?= $icon ?></span>
          </div>
          <div class="<?= $active ? '' : 'opacity-40' ?>">
            <p class="text-sm font-bold text-[#0b1d2c] leading-none"><?= $title ?></p>
            <p class="text-[10px] text-[#43474f] mt-0.5"><?= $sub ?></p>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </aside>
</div>


<!-- ═══════════════════════════════════════════════════════════════════
     PREVIEW MODAL — unchanged from your original
══════════════════════════════════════════════════════════════════════ -->
<div id="previewModal" class="fixed inset-0 z-[200] hidden" role="dialog" aria-modal="true">
  <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" onclick="closePreview()"></div>
  <div class="absolute inset-4 md:inset-8 lg:inset-16 bg-[#f0f4f8] rounded-2xl flex flex-col overflow-hidden shadow-2xl">

    <div class="flex items-center gap-3 px-6 py-4 bg-[#001e40] flex-shrink-0">
      <span class="material-symbols-outlined text-[#fecb00]">preview</span>
      <div>
        <p class="text-white font-bold text-sm">Letter Preview</p>
        <p class="text-white/60 text-xs">Review your complaint letter before submitting.</p>
      </div>
      <button onclick="closePreview()" class="ml-auto text-white/60 hover:text-white transition-colors p-1">
        <span class="material-symbols-outlined">close</span>
      </button>
    </div>

    <div class="flex-1 overflow-y-auto p-6">
      <div style="max-width:780px;margin:0 auto;font-family:'Times New Roman',Times,serif;font-size:13px;line-height:1.75;border:0.5px solid #e2e8f0;border-radius:8px;overflow:hidden;background:#fff;box-shadow:0 4px 30px rgba(0,0,0,0.12);">
        <div style="height:5px;background:#c0392b;"></div>
        <div style="height:6px;background:#001e40;"></div>
        <div style="height:5px;background:#fecb00;"></div>
        <div style="display:flex;align-items:center;padding:14px 28px;border-bottom:2px solid #001e40;gap:16px;">
          <img src="<?= BASE_URL ?>assets/img/lasu_logo.jpg" alt="LASU Crest" style="width:60px;height:60px;object-fit:contain;flex-shrink:0;border-radius:8px;">
          <div style="flex:1;text-align:center;">
            <div style="font-family:Arial,sans-serif;font-size:17px;font-weight:900;color:#001e40;text-transform:uppercase;letter-spacing:0.4px;line-height:1.2;">Lagos State University, Ojo</div>
            <div style="font-family:Arial,sans-serif;font-size:10.5px;color:#444;margin-top:3px;">Department of <?= sanitize($user['department']) ?></div>
            <div style="font-family:Arial,sans-serif;font-size:10px;color:#666;">Result Complaint Management Office</div>
          </div>
          <div style="text-align:right;font-size:9px;color:#444;line-height:1.6;min-width:130px;">
            <strong style="color:#001e40;">Lagos State University,</strong><br>
            Badagry Expressway,<br>P.M.B. 0001, Ojo, Lagos.<br>
            <span style="color:#001e40;font-weight:700;">web:</span> www.lasu.edu.ng
          </div>
        </div>
        <div style="padding:2rem 2.5rem;position:relative;">
          <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-55%);width:220px;height:220px;background:url('<?= BASE_URL ?>assets/img/lasu_logo.jpg') center/contain no-repeat;opacity:0.055;pointer-events:none;"></div>
          <div style="position:relative;">
            <div style="display:flex;justify-content:space-between;margin-bottom:16px;font-size:12px;">
              <div></div>
              <div><strong>Date:</strong> <?= date('j M Y, g:i A') ?></div>
            </div>
            <p style="margin-bottom:16px;font-size:12.5px;">
              <strong>To:</strong><br>The Head of Department,<br>
              Department of <?= sanitize($user['department']) ?>,<br>
              <?= sanitize($user['faculty']) ?>,<br>
              Lagos State University, Ojo,<br>Lagos State.
            </p>
            <p style="margin-bottom:16px;font-size:12.5px;">
              <strong>Through:</strong><br>Level Adviser (<?= sanitize($user['level']) ?> Level),<br>
              Department of <?= sanitize($user['department']) ?>,<br>
              <?= sanitize($user['faculty']) ?>,<br>
              Lagos State University, Ojo,<br>Lagos State.
            </p>
            <p style="margin-bottom:14px;font-size:12.5px;">Dear Sir/Ma,</p>
            <p id="prev_subject" contenteditable="true" spellcheck="false"
               style="text-align:center;font-weight:bold;font-size:13px;text-decoration:underline;text-transform:uppercase;margin:14px 0 16px;letter-spacing:0.3px;outline:none;border-radius:4px;padding:4px 6px;transition:background 0.15s;cursor:text;"></p>
            <div id="editHint" style="background:#fffbe6;border:1px dashed #f0c040;border-radius:6px;padding:7px 12px;margin-bottom:14px;font-family:Arial,sans-serif;font-size:11px;color:#7a5c00;display:flex;align-items:center;gap:6px;">
              <span>✏️</span><span><strong>Editable letter</strong> — click any highlighted paragraph to make changes before submitting.</span>
            </div>
            <div id="prev_body1" contenteditable="true" spellcheck="true" style="text-align:justify;margin-bottom:12px;outline:none;border-radius:4px;padding:4px 6px;transition:background 0.15s;cursor:text;"></div>
            <div id="prev_body2" contenteditable="true" spellcheck="true" style="text-align:justify;margin-bottom:32px;outline:none;border-radius:4px;padding:4px 6px;transition:background 0.15s;cursor:text;">I humbly request that this matter be investigated and the necessary correction effected through appropriate channels. Thank you for your kind attention.</div>
            <p style="margin-bottom:48px;">Yours faithfully,</p>
            <div style="display:inline-block;min-width:220px;">
              <div style="height:60px;display:flex;align-items:flex-end;padding-bottom:4px;">
                <?php if ($hasSignature): ?>
                  <img src="<?= sanitize($sigUrl) ?>" alt="Student Signature" style="height:52px;max-width:160px;object-fit:contain;mix-blend-mode:multiply;">
                <?php else: ?>
                  <div style="width:160px;height:1px;"></div>
                <?php endif; ?>
              </div>
              <div style="border-top:1.5px solid #333;padding-top:6px;">
                <p style="font-weight:bold;font-size:12px;margin:0 0 2px;"><?= sanitize($user['full_name']) ?></p>
                <p style="font-size:10px;font-style:italic;color:#555;margin:0;"><?= sanitize($user['matric_number'] ?? 'N/A') ?> &bull; Level <?= sanitize($user['level']) ?></p>
              </div>
            </div>
          </div>
        </div>
        <div style="text-align:center;font-size:9px;color:#555;padding:5px 20px;border-top:1px solid #ddd;font-family:Arial,sans-serif;">
          Lagos State University, Badagry Expressway, P.M.B. 0001, Ojo, Lagos, Nigeria. &bull; www.lasu.edu.ng
        </div>
        <div style="height:5px;background:#001e40;"></div>
        <div style="height:5px;background:#fecb00;"></div>
      </div>
    </div>

    <div class="flex-shrink-0 flex items-center gap-4 px-6 py-4 bg-white border-t border-[#d2e4f9]">
      <button onclick="closePreview()" class="flex items-center gap-2 bg-[#edf4ff] text-[#001e40] px-6 py-3 rounded-xl font-bold text-sm hover:bg-[#d2e4f9] transition-all">
        <span class="material-symbols-outlined text-base">edit</span>Go Back &amp; Edit
      </button>
      <div class="flex-1 text-xs text-[#43474f]">Once submitted, this letter will be forwarded to your Level Adviser for review.</div>
      <button onclick="confirmSubmit()" class="flex items-center gap-2 bg-[#001e40] text-white px-8 py-3 rounded-xl font-black text-sm hover:bg-[#003366] transition-all shadow-lg shadow-[#001e40]/20">
        <span class="material-symbols-outlined text-base">send</span>Confirm &amp; Submit
      </button>
    </div>
  </div>
</div>

   <!-- ── AI Scanning Loader ── -->
<div id="aiScanLoader" class="fixed inset-0 z-[300] hidden">
  <div class="absolute inset-0 bg-[#001e40]/80 backdrop-blur-sm"></div>
  <div class="absolute inset-0 flex flex-col items-center justify-center gap-6">
    <!-- Animated ring -->
    <div class="relative w-20 h-20">
      <div class="absolute inset-0 rounded-full border-4 border-white/20"></div>
      <div class="absolute inset-0 rounded-full border-4 border-t-[#fecb00] border-r-transparent border-b-transparent border-l-transparent animate-spin"></div>
      <div class="absolute inset-0 flex items-center justify-center">
        <span class="material-symbols-outlined text-[#fecb00] text-2xl">smart_toy</span>
      </div>
    </div>
    <!-- Status messages that cycle -->
    <div class="text-center">
      <p class="text-white font-black text-lg" id="loaderTitle">Submitting Complaint</p>
      <p class="text-white/60 text-sm mt-1" id="loaderSubtitle">Please wait...</p>
    </div>
    <!-- Progress dots -->
    <div class="flex gap-2">
      <div class="w-2 h-2 rounded-full bg-[#fecb00] animate-bounce" style="animation-delay:0ms"></div>
      <div class="w-2 h-2 rounded-full bg-[#fecb00] animate-bounce" style="animation-delay:150ms"></div>
      <div class="w-2 h-2 rounded-full bg-[#fecb00] animate-bounce" style="animation-delay:300ms"></div>
    </div>
    <p class="text-white/40 text-xs max-w-xs text-center">Do not close this page. This may take up to 30 seconds for AI document verification.</p>
  </div>
</div>

<script>
const COURSES        = <?= json_encode($courseMap) ?>;
const COURSE_OPTIONS = <?= json_encode(array_values(array_map(fn($id, $c) => [
    'id'      => $id,
    'display' => $c['code'] . ' – ' . $c['title'],
    'code'    => $c['code'],
    'title'   => $c['title'],
    'status'  => $c['status'],
], array_keys($courseMap), $courseMap))) ?>;

const STUDENT_LEVEL = '<?= (int)$user['level'] ?>';
let letterEdited = false;

const complaintTA = document.getElementById('f_complaint');
complaintTA.addEventListener('input', () => {
  document.getElementById('charCount').textContent = complaintTA.value.length + ' / 1000';
});

/* ── Category change handler ── */
function onCategoryChange(val) {
  val = val.trim();
  document.getElementById('categoryHidden').value = val;

  const banner  = document.getElementById('categoryInfoBanner');
  const bannerT = document.getElementById('categoryInfoText');
  const hint    = document.getElementById('categoryHint');
  const label   = document.getElementById('evidenceLabel');
  const sublabel= document.getElementById('evidenceSublabel');

  // Reset pipelines
  document.getElementById('pipeline_default').classList.add('hidden');
  document.getElementById('pipeline_removal').classList.add('hidden');
  document.getElementById('pipeline_upgrade').classList.add('hidden');

  if (val === 'Appeal for Course Removal') {
    document.getElementById('pipeline_removal').classList.remove('hidden');
    banner.classList.remove('hidden');
    bannerT.innerHTML = '📋 <strong>Course Removal Appeal:</strong> Upload your course registration form or docket. Our AI will scan it to verify your total units (minimum 18 must remain after removal). Compulsory courses cannot be removed.';
    label.innerHTML   = 'Upload Course Form / Docket <span class="text-red-500 font-normal">(required)</span>';
    sublabel.textContent = 'JPG, PNG or PDF of your course registration form';
    hint.textContent  = '✓ Evidence required for course removal';
    hint.className    = 'mt-1.5 text-[10px] font-bold text-amber-600';
    hint.classList.remove('hidden');

  } else if (val === 'Appeal for Course Upgrade') {
    document.getElementById('pipeline_upgrade').classList.remove('hidden');
    banner.classList.remove('hidden');
    const isFinalYear = ['400','500'].includes(STUDENT_LEVEL);
    if (!isFinalYear) {
      bannerT.innerHTML = '⚠️ <strong>Not eligible:</strong> Course upgrade appeals are only available to final year students (400/500 Level). You are currently on <strong>' + STUDENT_LEVEL + ' Level</strong>.';
      banner.className  = 'bg-[#ffdad6] border-l-4 border-red-500 px-5 py-4 rounded-r-xl text-sm text-red-800';
    } else {
      bannerT.innerHTML = '🎓 <strong>Course Upgrade Appeal:</strong> As a final year student you may appeal for a grade waiver. This will go through Level Adviser → HOD → Lecturer → HOD for final approval.';
      banner.className  = 'bg-[#edf4ff] border-l-4 border-[#001e40] px-5 py-4 rounded-r-xl text-sm text-[#001e40]';
    }
    label.innerHTML   = 'Upload Supporting Evidence <span class="text-[#43474f] font-normal">(optional)</span>';
    sublabel.textContent = 'Result slip, transcript, or any supporting document';
    hint.classList.add('hidden');

  } else {
    document.getElementById('pipeline_default').classList.remove('hidden');
    banner.classList.add('hidden');
    label.innerHTML   = 'Upload Portal Evidence <span class="text-[#43474f] font-normal">(optional)</span>';
    sublabel.textContent = 'Drag & drop or click to browse';
    hint.classList.add('hidden');
  }
}

function resolveCourseId(val) {
  val = val.trim();
  const hidden = document.getElementById('course_id_hidden');
  const icon   = document.getElementById('courseMatchIcon');
  const label  = document.getElementById('courseMatchLabel');
  let match = COURSE_OPTIONS.find(c => c.display === val);
  if (!match) {
    const upper = val.toUpperCase().replace(/\s+/g, '');
    match = COURSE_OPTIONS.find(c => c.code.replace(/\s+/g, '').toUpperCase() === upper);
  }
  if (match) {
    hidden.value = match.id;
    icon.classList.remove('hidden');
    // Warn if compulsory course selected for removal
    const cat = document.getElementById('f_category').value.trim();
    if (cat === 'Appeal for Course Removal' && match.status === 'C') {
      label.textContent = '⚠ ' + match.display + ' — This is a COMPULSORY course and cannot be removed.';
      label.className = 'mt-1.5 text-[10px] font-bold text-red-600';
    } else {
      label.textContent = '✓ ' + match.display;
      label.className   = 'mt-1.5 text-[10px] font-bold text-green-600';
    }
    label.classList.remove('hidden');
  } else {
    hidden.value = '';
    icon.classList.add('hidden');
    label.classList.add('hidden');
  }
}

function previewEvidence(inp) {
  const file = inp.files[0];
  if (!file) return;
  document.getElementById('evidenceFileName').textContent = '📎 ' + file.name;
  document.getElementById('evidenceFileName').classList.remove('hidden');
  const prev = document.getElementById('evidence_preview');
  if (file.type.startsWith('image/')) {
    prev.src = URL.createObjectURL(file);
    prev.classList.remove('hidden');
  } else { prev.classList.add('hidden'); }
}

function setupEditablePara(el) {
  el.addEventListener('mouseenter', () => { if (document.activeElement !== el) { el.style.background='#fffef0'; el.style.outline='1.5px dashed #f0c040'; }});
  el.addEventListener('mouseleave', () => { if (document.activeElement !== el) { el.style.background=''; el.style.outline=''; }});
  el.addEventListener('focus',      () => { el.style.background='#fffef0'; el.style.outline='1.5px dashed #f0c040'; letterEdited = true; });
  el.addEventListener('blur',       () => { el.style.background=''; el.style.outline=''; });
}
setupEditablePara(document.getElementById('prev_body1'));
setupEditablePara(document.getElementById('prev_body2'));
setupEditablePara(document.getElementById('prev_subject'));

function showPreview() {
  const session   = document.getElementById('f_session').value;
  const semester  = document.getElementById('f_semester').value;
  const courseVal = document.getElementById('f_course_search').value.trim();
  const category  = document.getElementById('f_category').value.trim();
  const complaint = complaintTA.value.trim();

  resolveCourseId(courseVal);
  const courseId = document.getElementById('course_id_hidden').value;

  // Block final year check client-side too
  if (category === 'Appeal for Course Upgrade' && !['400','500'].includes(STUDENT_LEVEL)) {
    alert('Course upgrade appeals are only available to final year students (400/500 Level).');
    return;
  }

  if (!session || !semester || !courseId || !category || !complaint) {
    const missing = [];
    if (!session)   missing.push('Academic Session');
    if (!semester)  missing.push('Semester');
    if (!courseId)  missing.push('Course (select a valid course from the list)');
    if (!category)  missing.push('Discrepancy Category');
    if (!complaint) missing.push('Detailed Description');
    alert('Please complete:\n\n• ' + missing.join('\n• '));
    return;
  }

  // Block if evidence is missing for course removal
  if (category === 'Appeal for Course Removal') {
    const fileInput = document.getElementById('evidence_file');
    if (!fileInput.files || fileInput.files.length === 0) {
      alert('Evidence (course form or docket) is required for a Course Removal appeal.');
      return;
    }
  }

  const course = COURSES[courseId] || { code: '—', title: '—' };

  document.getElementById('prev_subject').textContent =
    `${category.toUpperCase()} – ${course.code} (${session}, ${semester.toUpperCase()} SEMESTER)`;

  const body1El = document.getElementById('prev_body1');
  if (!letterEdited || !body1El.textContent.trim()) {
    body1El.textContent =
      `I, <?= sanitize($user['full_name']) ?>, Matriculation Number <?= sanitize($user['matric_number'] ?? 'N/A') ?>, ` +
      `Level <?= sanitize($user['level']) ?> student in the Department of <?= sanitize($user['department']) ?>, ` +
      `wish to formally submit this appeal regarding ${course.code} – ${course.title}.`;
  }

  document.getElementById('previewModal').classList.remove('hidden');
  document.body.style.overflow = 'hidden';
}

function closePreview() {
  document.getElementById('previewModal').classList.add('hidden');
  document.body.style.overflow = '';
}

function confirmSubmit() {
  if (!confirm('Are you sure you want to officially submit this complaint?\n\nThis will be forwarded to your Level Adviser and cannot be undone.')) return;

  resolveCourseId(document.getElementById('f_course_search').value.trim());

  const intro   = document.getElementById('prev_body1').innerText.trim();
  const closing = document.getElementById('prev_body2').innerText.trim();
  const subject = document.getElementById('prev_subject').innerText.trim();
  const category = document.getElementById('f_category').value.trim();

  document.getElementById('categoryHidden').value = category;

  const rawJson = JSON.stringify({ intro, closing, subject });
  document.getElementById('letterBodyInput').value = btoa(unescape(encodeURIComponent(rawJson)));
  document.getElementById('confirmedInput').value = '1';

  // Show loader — different messages based on category
  const loader    = document.getElementById('aiScanLoader');
  const loaderTitle = document.getElementById('loaderTitle');
  const loaderSub   = document.getElementById('loaderSubtitle');

  loader.classList.remove('hidden');
  document.body.style.overflow = 'hidden';

  if (category === 'Appeal for Course Removal') {
    loaderTitle.textContent = 'AI Scanning Document';
    loaderSub.textContent   = 'Verifying course units and eligibility...';

    // Cycle through messages so it feels alive
    const messages = [
      ['Reading your course form...', 'Extracting course codes and units'],
      ['Checking unit totals...', 'Verifying minimum unit requirements'],
      ['Validating eligibility...', 'Checking course removal rules'],
      ['Almost done...', 'Finalising your submission'],
    ];
    let i = 0;
    const interval = setInterval(() => {
      i = (i + 1) % messages.length;
      loaderTitle.textContent = messages[i][0];
      loaderSub.textContent   = messages[i][1];
    }, 3000);

    // Store interval so we don't leak it (form submission will navigate away)
    window._loaderInterval = interval;
  } else {
    loaderTitle.textContent = 'Submitting Complaint';
    loaderSub.textContent   = 'Saving your complaint and notifying your Level Adviser...';
  }

  // Submit after a tiny delay so the loader renders first
  setTimeout(() => {
    document.getElementById('complaintForm').submit();
  }, 100);
}

// Init pipeline on page load
document.getElementById('pipeline_default').classList.remove('hidden');
document.addEventListener('keydown', e => { if (e.key === 'Escape') closePreview(); });
</script>

<?php include __DIR__ . '/../includes/layout_end.php'; ?>

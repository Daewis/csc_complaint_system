<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireRole('admin');

$db      = getDB();
$success = '';
$error   = '';
$preview = [];
$errors  = [];
$step    = 'upload';

// ── CANONICAL COLUMNS ────────────────────────────────────────────────────────
const EXPECTED_COLS = ['course_code','course_title','faculty','credit_units','department','level','semester','status','curriculum_type'];

const VALID_CURRICULUM_TYPES = ['CCMAS', 'BMAS'];

const VALID_STATUSES = ['C', 'E', 'R'];

// ── VALID VALUES ─────────────────────────────────────────────────────────────
const VALID_LEVELS = [100,200,300,400,500,600,700];

// ── SEMESTER MAP (standard + Nigerian convention) ─────────────────────────────
const SEMESTER_MAP = [
    'first'     => 'Harmattan', '1st'  => 'Harmattan', '1' => 'Harmattan',
    'harmattan' => 'Harmattan', 'hammattan' => 'Harmattan',
    'second'    => 'Rain',      '2nd'  => 'Rain',       '2' => 'Rain',
    'rain'      => 'Rain',      'wet'  => 'Rain',
    'third'     => 'Third',     '3rd'  => 'Third',      '3' => 'Third',
    'dry'       => 'Third',
];

// ── FUZZY HEADER ALIASES ──────────────────────────────────────────────────────
const HEADER_ALIASES = [
    'code'           => 'course_code',
    'course_no'      => 'course_code',
    'coursecode'     => 'course_code',
    'title'          => 'course_title',
    'coursetitle'    => 'course_title',
    'course_name'    => 'course_title',
    'name'           => 'course_title',
    'units'          => 'credit_units',
    'credit'         => 'credit_units',
    'creditunits'    => 'credit_units',
    'unit'           => 'credit_units',
    'credits'        => 'credit_units',
    'dept'           => 'department',
    'dep'            => 'department',
    'departmentname' => 'department',
    'yr'             => 'level',
    'year'           => 'level',
    'studylevel'     => 'level',
    'sem'            => 'semester',
    'term'           => 'semester',
    'session'        => 'semester',
    'curriculum'     => 'curriculum_type',
    'curr_type'      => 'curriculum_type',
    'curriculumtype' => 'curriculum_type',
    'course_status'  => 'status',
    'coursestatus'   => 'status',
    'state'          => 'status',
];

// ── HELPERS ───────────────────────────────────────────────────────────────────
function normaliseHeader(string $h): string {
    $h = mb_strtolower(trim($h));
    $h = preg_replace('/[^a-z0-9]+/', '_', $h);
    $h = trim($h, '_');
    // Any header starting with "faculty" (e.g. "Faculty Of Science") → 'faculty'
    if (str_starts_with($h, 'faculty')) return 'faculty';
    return HEADER_ALIASES[$h] ?? $h;
}

function normaliseSemester(string $raw): ?string {
    return SEMESTER_MAP[strtolower(trim($raw))] ?? null;
}

function validateRow(array $data, int $rowNum): array {
    $row = []; $issues = [];

    $code = strtoupper(trim($data['course_code'] ?? ''));
    if (empty($code)) {
        $issues[] = 'Missing course_code';
    } elseif (!preg_match('/^[A-Z]{2,6}\s?\d{3,4}[A-Z]?$/i', $code)) {
        $issues[] = "Unusual course_code format: '{$code}' (imported as-is)";
    }
    $row['code'] = $code;

    $title = trim($data['course_title'] ?? '');
    if (empty($title)) $issues[] = 'Missing course_title';
    $row['title'] = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

    $faculty = trim($data['faculty'] ?? '');
    if (empty($faculty)) $issues[] = 'Missing faculty';
    $row['faculty'] = htmlspecialchars($faculty, ENT_QUOTES, 'UTF-8');

    $units = (int)($data['credit_units'] ?? 0);

    // Changed the lower bound from 1 to 0 to allow 0-unit courses
    if ($units < 0 || $units > 18) {
        $issues[] = "credit_units out of range (got {$units}) — defaulted to 3";
        $units = 3;
    }

    $row['units'] = $units;

    $dept = trim($data['department'] ?? '');
    if (empty($dept)) $issues[] = 'Missing department';
    $row['dept'] = htmlspecialchars($dept, ENT_QUOTES, 'UTF-8');

    $level = (int)($data['level'] ?? 0);
    if (!in_array($level, VALID_LEVELS, true)) {
        $issues[] = "Unrecognised level ({$level}) — defaulted to 100";
        $level = 100;
    }
    $row['level'] = $level;

    $rawSem = trim($data['semester'] ?? '');
    $sem    = normaliseSemester($rawSem);
    if ($sem === null) {
        $issues[] = "Unrecognised semester ('{$rawSem}') — defaulted to Harmattan";
        $sem = 'Harmattan';
    }
    $row['semester'] = $sem;

    $status = strtoupper(trim($data['status'] ?? ''));
    if (!in_array($status, VALID_STATUSES, true)) {
        $issues[] = "Unrecognised status ('{$status}') — defaulted to C";
        $status = 'C';
    }
    $row['status'] = $status;

    $curriculum = strtoupper(trim($data['curriculum_type'] ?? ''));
    if (!in_array($curriculum, VALID_CURRICULUM_TYPES, true)) {
        $issues[] = "Unrecognised curriculum_type ('{$curriculum}') — defaulted to CCMAS";
        $curriculum = 'CCMAS';
    }
    $row['curriculum_type'] = $curriculum;

    return ['row' => $row, 'issues' => $issues, 'rowNum' => $rowNum];
}

// ── NORMALIZED-SCHEMA HELPERS ──────────────────────────────────────────────
// These look up (or create) the faculty/department/course rows needed before
// an offering can be inserted. Wrapped in functions so the confirm step below
// stays readable.

function getOrCreateFaculty(PDO $db, string $name): int {
    $stmt = $db->prepare("SELECT id FROM faculties WHERE name = ?");
    $stmt->execute([$name]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;

    $stmt = $db->prepare("INSERT INTO faculties (name) VALUES (?)");
    $stmt->execute([$name]);
    return (int)$db->lastInsertId();
}

function getOrCreateDepartment(PDO $db, int $facultyId, string $name): int {
    $stmt = $db->prepare("SELECT id FROM departments WHERE faculty_id = ? AND name = ?");
    $stmt->execute([$facultyId, $name]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;

    $stmt = $db->prepare("INSERT INTO departments (faculty_id, name) VALUES (?, ?)");
    $stmt->execute([$facultyId, $name]);
    return (int)$db->lastInsertId();
}

function getOrCreateCourse(PDO $db, string $code, string $title): int {
    $stmt = $db->prepare("SELECT id FROM courses WHERE course_code = ?");
    $stmt->execute([$code]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;

    $stmt = $db->prepare("INSERT INTO courses (course_code, course_title) VALUES (?, ?)");
    $stmt->execute([$code, $title]);
    return (int)$db->lastInsertId();
}

// ── STEP 2: DB COMMIT (ROW-BY-ROW DEBUGGING MODE) ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'confirm') {
    $rows     = json_decode($_POST['import_data'] ?? '[]', true);
    $inserted = 0; $updated = 0; $failed = 0;
    $debug_errors = [];

    $offeringStmt = $db->prepare("
        INSERT INTO course_offerings
            (course_id, department_id, credit_units, level, semester, status, curriculum_type)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            credit_units    = VALUES(credit_units),
            status          = VALUES(status),
            curriculum_type = VALUES(curriculum_type)
    ");

    foreach ($rows as $index => $row) {
        $currentRowNum = $index + 2;
        try {
            $facultyId    = getOrCreateFaculty($db, $row['faculty']);
            $departmentId = getOrCreateDepartment($db, $facultyId, $row['dept']);
            $courseId     = getOrCreateCourse($db, $row['code'], $row['title']);

            $offeringStmt->execute([
                $courseId,
                $departmentId,
                $row['units'],
                $row['level'],
                $row['semester'],
                $row['status'],
                $row['curriculum_type']
            ]);

            $rc = $offeringStmt->rowCount();
            if ($rc === 2)    $updated++;
            else              $inserted++;

        } catch (Exception $e) {
            $failed++;
            $debug_errors[] = "<strong>Row {$currentRowNum} ({$row['code']}):</strong> " . htmlspecialchars($e->getMessage());
        }
    }

    if (!empty($debug_errors)) {
        $error = "<strong>Import encountered errors:</strong><br><ul class='list-disc pl-5 space-y-1'>" .
                 implode("", array_map(fn($err) => "<li>$err</li>", array_slice($debug_errors, 0, 5))) .
                 "</ul>";
        if (count($debug_errors) > 5) {
            $error .= "<p class='text-xs mt-2 font-normal'>...and " . (count($debug_errors) - 5) . " more errors logged.</p>";
        }
    } else {
        $parts = [];
        if ($inserted) $parts[] = "{$inserted} new offering" . ($inserted > 1 ? 's' : '') . " added";
        if ($updated)  $parts[] = "{$updated} updated";
        $success = implode(', ', $parts) . '.';
    }

    $step = 'upload';
}

// ── STEP 1: PARSE CSV (file upload OR pasted text) ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'parse') {

    $rawHeaders = null;
    $rowsSource = null;

    $file   = $_FILES['course_file'] ?? null;
    $pasted = trim($_POST['csv_paste'] ?? '');

    if ($file && $file['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($ext !== 'csv') {
            $error = 'Only .csv files are accepted. Please export your spreadsheet as CSV first.';
        } elseif ($file['size'] > 5 * 1024 * 1024) {
            $error = 'File exceeds 5 MB limit.';
        } else {
            try {
                $handle = fopen($file['tmp_name'], 'r');
                $bom = fread($handle, 3);
                if ($bom !== "\xef\xbb\xbf") rewind($handle);

                $rawHeaders = fgetcsv($handle);
                $rowsSource = [];
                while (($raw = fgetcsv($handle)) !== false) {
                    $rowsSource[] = $raw;
                }
                fclose($handle);
            } catch (Exception $e) {
                $error = 'File processing error: ' . $e->getMessage();
            }
        }

    } elseif ($pasted !== '') {
        $lines = array_filter(
            explode("\n", str_replace("\r", "", $pasted)),
            fn($l) => trim($l) !== ''
        );
        $lines = array_values($lines);

        if (empty($lines)) {
            $error = 'No data found in pasted text.';
        } else {
            $rawHeaders = str_getcsv(array_shift($lines));
            $rowsSource = array_map('str_getcsv', $lines);
        }

    } else {
        $error = 'No file uploaded and no data pasted. Please provide a CSV file or paste CSV data.';
    }

    if (!$error && $rawHeaders) {
        try {
            if (!$rawHeaders) throw new Exception("Could not read headers — is the file/text empty?");

            $headers = array_map('normaliseHeader', $rawHeaders);

            $missing = array_diff(EXPECTED_COLS, $headers);
            if ($missing) {
                $error = 'These expected columns were not found: <strong>'
                       . implode(', ', $missing) . '</strong>. '
                       . 'Detected headers: <em>' . implode(', ', array_filter($headers)) . '</em>';
            }

            $rowNum = 1;
            foreach ($rowsSource as $raw) {
                $rowNum++;
                if (empty(array_filter($raw))) continue;

                // --- ADD THIS TRUNCATION LOGIC ---
                $headerCount = count($headers);
                if (count($raw) > $headerCount) {
                    $raw = array_slice($raw, 0, $headerCount); // Chop off extra columns
                }
                $data = array_combine($headers, array_pad($raw, $headerCount, ''));
                $result = validateRow($data, $rowNum);
                $preview[] = $result['row'];
                if ($result['issues']) $errors[] = ['row' => $rowNum, 'issues' => $result['issues']];
            }

            if (empty($preview)) {
                $error = 'No data rows were found. Make sure row 1 has headers and row 2 onwards has data.';
            } else {
                $step = 'preview';
            }

        } catch (Exception $e) {
            $error = 'Data processing error: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Import Courses';
$activeNav = 'import_courses.php';
include __DIR__ . '/../includes/layout.php';
?>
<style>
  .drop-zone-active { border-color: #fecb00 !important; background: #fffdf0 !important; }
  .row-warn td { background: #fffbeb; }
</style>

<!-- ── PAGE HEADER ─────────────────────────────────────────────────────────── -->
<div class="mb-10 flex items-center justify-between flex-wrap gap-4">
  <div class="flex items-center gap-4">
    <div class="h-10 w-1.5 bg-[#fecb00] rounded-full"></div>
    <div>
      <h2 class="text-3xl font-black text-[#001e40] tracking-tighter">Course Catalog Import</h2>
      <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-gray-400 mt-1">
        Bulk-load course offerings into the academic redress system
      </p>
    </div>
  </div>

  <!-- Step breadcrumb -->
  <div class="flex items-center gap-2 text-[10px] font-black uppercase tracking-widest">
    <span class="flex items-center gap-1.5 px-3 py-1.5 rounded-full
      <?= $step === 'upload' ? 'bg-[#001e40] text-white' : 'bg-[#c8f0d4] text-[#1a6b36]' ?>">
      <span class="material-symbols-outlined text-xs"><?= $step !== 'upload' ? 'check_circle' : 'upload_file' ?></span> Upload
    </span>
    <span class="material-symbols-outlined text-gray-300 text-sm">chevron_right</span>
    <span class="flex items-center gap-1.5 px-3 py-1.5 rounded-full
      <?= $step === 'preview' ? 'bg-[#001e40] text-white' : 'bg-gray-100 text-gray-400' ?>">
      <span class="material-symbols-outlined text-xs">preview</span> Preview
    </span>
    <span class="material-symbols-outlined text-gray-300 text-sm">chevron_right</span>
    <span class="flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-gray-100 text-gray-400">
      <span class="material-symbols-outlined text-xs">check_circle</span> Confirm
    </span>
  </div>
</div>

<!-- ── ALERTS ─────────────────────────────────────────────────────────────────-->
<?php if ($success): ?>
<div class="mb-6 p-5 bg-green-50 text-green-700 rounded-2xl font-bold text-sm border border-green-100 flex items-center gap-3">
  <span class="material-symbols-outlined">check_circle</span> <?= $success ?>
</div>
<?php endif; ?>

<?php if ($error && $step !== 'preview'): ?>
<div class="mb-6 p-5 bg-red-50 text-red-700 rounded-2xl font-bold text-sm border border-red-100 flex items-start gap-3">
  <span class="material-symbols-outlined mt-0.5 shrink-0">error</span>
  <span><?= $error ?></span>
</div>
<?php endif; ?>

<?php if ($error && $step === 'preview'): ?>
<div class="mb-6 p-5 bg-amber-50 text-amber-800 rounded-2xl font-bold text-sm border border-amber-100 flex items-start gap-3">
  <span class="material-symbols-outlined mt-0.5 shrink-0">warning</span>
  <span><?= $error ?></span>
</div>
<?php endif; ?>


<!-- ════════════════════════════════════════════════════════════════════════════
     STEP 1 — UPLOAD
═══════════════════════════════════════════════════════════════════════════════ -->
<?php if ($step === 'upload'): ?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

  <!-- Upload card -->
  <div class="lg:col-span-2 bg-white p-10 rounded-3xl shadow-sm border border-gray-100">
    <form method="POST" enctype="multipart/form-data" id="uploadForm" class="space-y-8">
      <input type="hidden" name="step" value="parse">

      <!-- Drop zone -->
      <div id="dropZone"
           class="bg-[#f7f9ff] border-2 border-dashed border-blue-100 rounded-3xl p-16 text-center transition-all cursor-pointer select-none"
           onclick="document.getElementById('file_input').click()"
           ondragover="event.preventDefault();this.classList.add('drop-zone-active')"
           ondragleave="this.classList.remove('drop-zone-active')"
           ondrop="handleDrop(event)">
        <div class="w-20 h-20 bg-white rounded-2xl flex items-center justify-center mx-auto mb-5 shadow-sm">
          <span class="material-symbols-outlined text-4xl text-blue-300" id="dropIconSym">upload_file</span>
        </div>
        <p class="text-sm font-black text-[#001e40]" id="dropLabel">
          Drag &amp; drop your CSV file here, or click to browse
        </p>
        <p class="text-[10px] text-gray-400 font-bold uppercase tracking-widest mt-2">
          .csv only &nbsp;·&nbsp; max 5 MB
        </p>
        <div id="fileChip" class="hidden mt-5 inline-flex items-center gap-2 bg-[#001e40] text-white text-xs font-black px-4 py-2 rounded-xl">
          <span class="material-symbols-outlined text-sm">description</span>
          <span id="fileChipName"></span>
          <button type="button" onclick="event.stopPropagation();clearFile()"
            class="ml-1 hover:text-[#fecb00] transition-colors">
            <span class="material-symbols-outlined text-sm">close</span>
          </button>
        </div>
        <input type="file" name="course_file" id="file_input" class="hidden"
               accept=".csv" onchange="onFileSelected(this)">
      </div>

      <!-- OR divider -->
      <div class="relative">
        <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-100"></div></div>
        <div class="relative flex justify-center text-[10px] font-bold uppercase tracking-widest text-gray-300">
          <span class="bg-white px-4">OR PASTE CSV DATA</span>
        </div>
      </div>

      <textarea name="csv_paste" id="csvPaste"
        class="w-full bg-[#f7f9ff] border-none rounded-2xl p-6 text-xs font-mono"
        rows="8"
        placeholder="course_code,course_title,faculty,credit_units,department,level,semester,status,curriculum_type"
        oninput="onPasteInput(this)"></textarea>

      <button type="submit" id="submitBtn" disabled
        class="w-full bg-[#001e40] text-white py-5 rounded-2xl font-black text-sm shadow-xl
               hover:bg-[#003366] transition-all flex items-center justify-center gap-3
               disabled:opacity-40 disabled:cursor-not-allowed disabled:shadow-none">
        <span class="material-symbols-outlined">analytics</span>
        Process &amp; Preview Catalog
      </button>
    </form>
  </div>

  <!-- Format guide -->
  <div class="flex flex-col gap-5">
    <div class="bg-[#fecb00] p-6 rounded-3xl">
      <p class="text-xs font-black text-[#6e5700] uppercase tracking-widest mb-4">Required CSV Headers</p>
      <ul class="space-y-2">
        <?php foreach (EXPECTED_COLS as $col): ?>
        <li class="flex items-center gap-2 text-xs font-bold text-[#001e40]">
          <span class="material-symbols-outlined text-sm text-[#6e5700]">check</span>
          <code class="bg-white/60 px-2 py-0.5 rounded-lg font-black"><?= $col ?></code>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <div class="bg-[#edf4ff] p-6 rounded-3xl space-y-3">
      <p class="text-xs font-black text-[#001e40] uppercase tracking-widest">
        <span class="material-symbols-outlined text-sm align-middle">info</span> Notes
      </p>
      <ul class="space-y-2 text-[11px] text-[#43474f] font-medium leading-relaxed">
        <li>• <strong>semester</strong>: Harmattan / Rain
          <span class="block text-[10px] text-[#43474f]/60 mt-0.5 ml-2">
            or First / Second / Third
          </span>
        </li>
        <li>• <strong>level</strong>: 100, 200 … 700</li>
        <li>• <strong>credit_units</strong>: 1 – 6</li>
        <li>• <strong>curriculum_type</strong>: BMAS or CCMAS</li>
        <li>• <strong>status</strong>: C (Core) / E (Elective) / R (Required)</li>
        <li>• The <strong>same course_code</strong> can appear multiple times
          for <em>different departments</em> — each becomes a separate offering,
          so shared/compulsory courses (e.g. GNS 101) are handled automatically.</li>
        <li>• Faculties and departments are created automatically if they don't
          already exist.</li>
        <li>• Re-importing the same course_code + department + level + semester
          <em>updates</em> that offering instead of duplicating it.</li>
        <li>• Save your Excel file as <em>CSV UTF-8</em> before uploading, or paste data directly</li>
      </ul>
    </div>
  </div>
</div>


<!-- ════════════════════════════════════════════════════════════════════════════
     STEP 2 — PREVIEW & CONFIRM
═══════════════════════════════════════════════════════════════════════════════ -->
<?php else: ?>

<?php
  $validCount = count($preview);
  $warnCount  = count($errors);
  $deptCount  = count(array_unique(array_column($preview, 'dept')));
  $levelCount = count(array_unique(array_column($preview, 'level')));
?>

<!-- Summary stat cards -->
<div class="mb-6 grid grid-cols-2 sm:grid-cols-4 gap-4">
  <?php foreach ([
    ['school',    $validCount, 'Offerings Ready', 'bg-[#001e40] text-white'],
    ['warning',   $warnCount,  'With Warnings',   'bg-amber-50 text-amber-700 border border-amber-100'],
    ['apartment', $deptCount,  'Departments',     'bg-[#edf4ff] text-[#001e40]'],
    ['sort',      $levelCount, 'Levels',          'bg-[#f7f9ff] text-[#001e40]'],
  ] as [$icon, $val, $label, $cls]): ?>
  <div class="<?= $cls ?> p-5 rounded-2xl flex items-center gap-4">
    <span class="material-symbols-outlined text-2xl opacity-60"><?= $icon ?></span>
    <div>
      <p class="text-2xl font-black leading-none"><?= $val ?></p>
      <p class="text-[10px] font-bold uppercase tracking-widest opacity-70 mt-0.5"><?= $label ?></p>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Collapsible warnings panel -->
<?php if ($errors): ?>
<details class="mb-6 bg-amber-50 border border-amber-100 rounded-2xl overflow-hidden">
  <summary class="px-6 py-4 cursor-pointer font-black text-xs text-amber-700 uppercase tracking-widest flex items-center gap-2 list-none">
    <span class="material-symbols-outlined text-sm">warning</span>
    <?= count($errors) ?> row<?= count($errors) > 1 ? 's have' : ' has' ?> warnings — values corrected to defaults · click to review
  </summary>
  <div class="px-6 pb-5 pt-2 space-y-2 max-h-64 overflow-y-auto">
    <?php foreach ($errors as $e): ?>
    <div class="text-xs font-bold text-amber-800 flex items-start gap-2">
      <span class="bg-amber-200 text-amber-900 px-2 py-0.5 rounded-lg shrink-0">Row <?= $e['row'] ?></span>
      <span><?= implode(' &nbsp;·&nbsp; ', array_map('htmlspecialchars', $e['issues'])) ?></span>
    </div>
    <?php endforeach; ?>
  </div>
</details>
<?php endif; ?>

<!-- Table -->
<div class="bg-white rounded-3xl shadow-sm border border-gray-100 overflow-hidden">

  <div class="px-8 py-5 bg-[#f7f9ff] border-b border-gray-100 flex items-center justify-between flex-wrap gap-4">
    <div>
      <h3 class="font-black text-[#001e40] text-sm">Catalog Preview</h3>
      <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mt-0.5">
        <?= $validCount ?> entries parsed
      </p>
    </div>
    <div class="flex items-center gap-3">
      <a href="import_courses.php"
         class="px-5 py-3 rounded-xl border border-gray-200 text-xs font-black text-[#43474f]
                hover:border-[#001e40] hover:text-[#001e40] transition-all flex items-center gap-2">
        <span class="material-symbols-outlined text-sm">arrow_back</span> Re-upload
      </a>
      <form method="POST">
        <input type="hidden" name="step" value="confirm">
        <input type="hidden" name="import_data" value="<?= htmlspecialchars(json_encode($preview), ENT_QUOTES) ?>">
        <button type="submit"
          class="bg-[#001e40] text-white px-8 py-3 rounded-xl font-black text-xs shadow-lg
                 hover:bg-[#003366] active:scale-[0.98] transition-all flex items-center gap-2">
          <span class="material-symbols-outlined text-sm">check_circle</span>
          Confirm &amp; Import <?= $validCount ?> Offerings
        </button>
      </form>
    </div>
  </div>

  <!-- Live search bar -->
  <div class="px-8 py-4 border-b border-gray-50 flex items-center gap-3">
    <span class="material-symbols-outlined text-gray-300 text-lg">search</span>
    <input type="text" id="tableSearch"
      placeholder="Filter by code, title, faculty or department…"
      class="flex-1 bg-transparent text-sm text-[#001e40] placeholder-gray-300 focus:outline-none font-medium">
    <span class="text-[10px] font-bold text-gray-300 uppercase tracking-widest" id="filterCount">
      <?= $validCount ?> shown
    </span>
  </div>

  <div class="overflow-x-auto">
    <table class="w-full text-left">
      <thead>
        <tr class="text-[9px] font-black uppercase tracking-widest text-gray-400 border-b border-gray-50 bg-gray-50/50">
          <th class="px-6 py-4">#</th>
          <th class="px-6 py-4">Code</th>
          <th class="px-6 py-4">Title</th>
          <th class="px-6 py-4">Faculty</th>
          <th class="px-6 py-4">Department</th>
          <th class="px-6 py-4">Level</th>
          <th class="px-6 py-4">Semester</th>
          <th class="px-6 py-4">Units</th>
          <th class="px-6 py-4">Status</th>
          <th class="px-6 py-4">Curriculum</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-gray-50" id="tableBody">
        <?php foreach ($preview as $i => $p):
          $hasWarn  = in_array($i + 2, array_column($errors, 'row'));
          $semColor = match($p['semester']) {
            'Harmattan' => 'bg-blue-50 text-blue-600',
            'Rain'      => 'bg-green-50 text-green-600',
            'Third'     => 'bg-purple-50 text-purple-600',
            default     => 'bg-gray-100 text-gray-500',
          };
          $curColor = $p['curriculum_type'] === 'BMAS' ? 'bg-indigo-50 text-indigo-600' : 'bg-teal-50 text-teal-600';
          $statusColor = match($p['status']) {
            'C'     => 'bg-emerald-50 text-emerald-600',
            'E'     => 'bg-sky-50 text-sky-600',
            'R'     => 'bg-rose-50 text-rose-600',
            default => 'bg-gray-100 text-gray-500',
          };
        ?>
        <tr class="hover:bg-blue-50/30 transition-colors <?= $hasWarn ? 'row-warn' : '' ?>"
            data-search="<?= strtolower(htmlspecialchars($p['code'] . ' ' . $p['title'] . ' ' . $p['faculty'] . ' ' . $p['dept'], ENT_QUOTES)) ?>">
          <td class="px-6 py-4 text-[10px] font-bold text-gray-300"><?= $i + 1 ?></td>
          <td class="px-6 py-4">
            <span class="font-black text-xs text-[#001e40] tracking-wider"><?= htmlspecialchars($p['code']) ?></span>
            <?php if ($hasWarn): ?>
              <span class="material-symbols-outlined text-amber-400 text-sm align-middle ml-1" title="Row has corrected values">warning</span>
            <?php endif; ?>
          </td>
          <td class="px-6 py-4 text-xs text-gray-500 font-medium max-w-[200px] truncate"
              title="<?= htmlspecialchars($p['title']) ?>">
            <?= htmlspecialchars($p['title']) ?>
          </td>
          <td class="px-6 py-4 text-[10px] font-black text-amber-600 italic max-w-[120px] truncate"
              title="<?= htmlspecialchars($p['faculty']) ?>">
            <?= htmlspecialchars($p['faculty']) ?>
          </td>
          <td class="px-6 py-4 text-[10px] font-black text-blue-500 uppercase max-w-[120px] truncate"
              title="<?= htmlspecialchars($p['dept']) ?>">
            <?= htmlspecialchars($p['dept']) ?>
          </td>
          <td class="px-6 py-4">
            <span class="px-2.5 py-1 bg-[#edf4ff] text-[#001e40] text-[10px] font-black rounded-lg">
              <?= $p['level'] ?>
            </span>
          </td>
          <td class="px-6 py-4">
            <span class="px-2.5 py-1 <?= $semColor ?> text-[10px] font-black rounded-lg">
              <?= htmlspecialchars($p['semester']) ?>
            </span>
          </td>
          <td class="px-6 py-4 text-center">
            <span class="px-2.5 py-1 bg-[#fecb00]/20 text-[#6e5700] text-[10px] font-black rounded-lg">
              <?= $p['units'] ?>
            </span>
          </td>
          <td class="px-6 py-4 text-center">
            <span class="px-2.5 py-1 <?= $statusColor ?> text-[10px] font-black rounded-lg">
              <?= htmlspecialchars($p['status']) ?>
            </span>
          </td>
          <td class="px-6 py-4">
            <span class="px-2.5 py-1 <?= $curColor ?> text-[10px] font-black rounded-lg">
              <?= htmlspecialchars($p['curriculum_type']) ?>
            </span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<script>
// ── Drop zone ─────────────────────────────────────────────────────────────
function handleDrop(e) {
  e.preventDefault();
  document.getElementById('dropZone').classList.remove('drop-zone-active');
  const file = e.dataTransfer.files[0];
  if (!file) return;
  const ext = file.name.split('.').pop().toLowerCase();
  if (ext !== 'csv') { alert('Please drop a .csv file.'); return; }
  const dt = new DataTransfer();
  dt.items.add(file);
  const inp = document.getElementById('file_input');
  inp.files = dt.files;
  onFileSelected(inp);
}

function onFileSelected(inp) {
  const file = inp.files[0];
  if (!file) return;
  const ext = file.name.split('.').pop().toLowerCase();
  if (ext !== 'csv') {
    alert('Only .csv files are accepted. Please export your spreadsheet as CSV first.');
    clearFile(); return;
  }
  document.getElementById('fileChip').classList.remove('hidden');
  document.getElementById('fileChipName').textContent = file.name;
  document.getElementById('dropLabel').textContent    = 'File selected — ready to process';
  document.getElementById('dropIconSym').textContent  = 'description';
  document.getElementById('dropIconSym').classList.replace('text-blue-300', 'text-[#001e40]');
  document.getElementById('submitBtn').disabled = false;
}

function clearFile() {
  document.getElementById('file_input').value = '';
  document.getElementById('fileChip').classList.add('hidden');
  document.getElementById('dropLabel').textContent   = 'Drag & drop your CSV file here, or click to browse';
  document.getElementById('dropIconSym').textContent = 'upload_file';
  document.getElementById('dropIconSym').classList.replace('text-[#001e40]', 'text-blue-300');
  const pasteVal = document.getElementById('csvPaste').value.trim();
  document.getElementById('submitBtn').disabled = pasteVal.length === 0;
}

function onPasteInput(ta) {
  const hasFile = document.getElementById('file_input').files.length > 0;
  document.getElementById('submitBtn').disabled = ta.value.trim().length === 0 && !hasFile;
}

// ── Live table search ──────────────────────────────────────────────────────
(function () {
  const inp   = document.getElementById('tableSearch');
  const count = document.getElementById('filterCount');
  if (!inp) return;
  inp.addEventListener('input', () => {
    const q       = inp.value.toLowerCase().trim();
    const rows    = document.querySelectorAll('#tableBody tr');
    let   visible = 0;
    rows.forEach(r => {
      const show = !q || r.dataset.search.includes(q);
      r.style.display = show ? '' : 'none';
      if (show) visible++;
    });
    count.textContent = visible + ' shown';
  });
})();
</script>

<?php include __DIR__ . '/../includes/layout_end.php'; ?>

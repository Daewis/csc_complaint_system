<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Check if user is staff and has HOD privileges
requireLogin();
$user = currentUser();
if (!$user['is_hod']) {
    header('Location: ' . BASE_URL . 'dashboard.php');
    exit;
}

$db = getDB();
$success = ''; $error = '';

// ── 1. ACTION: CSV/Paste Import ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'csv_import') {
    $csvText = '';
    if (!empty($_FILES['csv_file']['tmp_name'])) {
        $csvText = file_get_contents($_FILES['csv_file']['tmp_name']);
    } elseif (!empty($_POST['csv_paste'])) {
        $csvText = trim($_POST['csv_paste']);
    }

    if (!$csvText) {
        $error = 'No CSV data provided.';
    } else {
        $lines   = array_filter(explode("\n", str_replace("\r", "", $csvText)));
        $headers = array_map(fn($h) => strtolower(trim($h)), str_getcsv(array_shift($lines)));
        $count = 0; $skipped = 0;

        foreach ($lines as $line) {
            if (!trim($line)) continue;
            $cols = array_map('trim', str_getcsv($line));
            $row  = [];
            foreach ($headers as $i => $h) $row[$h] = $cols[$i] ?? '';

            $courseCode = $row['course_code'] ?? $row['course'] ?? '';
            $lecEmail   = $row['lecturer_email'] ?? $row['email'] ?? '';
            $session    = $row['academic_session'] ?? $row['session'] ?? date('Y') . '/' . (date('Y')+1);
            $semester   = ucfirst(strtolower($row['semester'] ?? 'First'));

            if (!$courseCode || !$lecEmail) { $skipped++; continue; }

            // Verify Course exists, offered to the HOD's department
            // (courses.department was removed — course_code is now matched
            //  through course_offerings -> departments instead)
            $stmtC = $db->prepare("
                SELECT c.id
                FROM courses c
                JOIN course_offerings co ON co.course_id = c.id
                JOIN departments d       ON d.id = co.department_id
                WHERE c.course_code = ? AND d.name = ?
                LIMIT 1
            ");
            $stmtC->execute([strtoupper($courseCode), $user['department']]);
            $courseRow = $stmtC->fetch();

            // Verify Lecturer exists and has is_lecturer flag
            $stmtL = $db->prepare("SELECT id FROM users WHERE email=? AND is_active=1 AND is_lecturer=1");
            $stmtL->execute([strtolower($lecEmail)]);
            $lecRow = $stmtL->fetch();

            if (!$courseRow || !$lecRow) { $skipped++; continue; }

            try {
                $db->prepare("INSERT IGNORE INTO course_assignments (course_id, lecturer_id, academic_session, semester) VALUES (?,?,?,?)")
                   ->execute([$courseRow['id'], $lecRow['id'], $session, $semester]);
                $count++;
            } catch (Exception $e) { $skipped++; }
        }
        $success = "$count assignment(s) created. $skipped row(s) skipped.";
    }
}

// ── 2. ACTION: Manual Add ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $courseId  = (int)$_POST['course_id'];
    $lecId     = (int)$_POST['lecturer_id'];
    $session   = trim($_POST['academic_session']);
    $semester  = $_POST['semester'];

    if ($courseId && $lecId && $session) {
        try {
            $db->prepare("INSERT IGNORE INTO course_assignments (course_id, lecturer_id, academic_session, semester) VALUES (?,?,?,?)")
               ->execute([$courseId, $lecId, $session, $semester]);
            $success = 'Lecturer successfully assigned to course.';
        } catch (Exception $e) { $error = 'Assignment already exists for this session.'; }
    } else { $error = 'All fields are required.'; }
}

// ── 3. ACTION: Remove Assignment ─────────────────────────────────────────────
// (courses.department removed — scope the deletion through course_offerings
//  + departments instead, matching the HOD's own department)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove') {
    $aId = (int)$_POST['assignment_id'];
    $db->prepare("
        DELETE ca FROM course_assignments ca
        JOIN courses c           ON c.id = ca.course_id
        JOIN course_offerings co ON co.course_id = c.id
        JOIN departments d       ON d.id = co.department_id
        WHERE ca.id = ? AND d.name = ?
    ")->execute([$aId, $user['department']]);
    $success = 'Assignment removed.';
}

// ── 4. DATA FETCHING ─────────────────────────────────────────────────────────
$filterSession = $_GET['session'] ?? '';
$filterCourse  = $_GET['course']  ?? '';

// (courses.department removed — join through course_offerings + departments
//  to scope assignments to the HOD's own department; DISTINCT guards against
//  duplicate rows if a course is offered to more than one department)
$sql = "SELECT DISTINCT ca.id, ca.academic_session, ca.semester, c.course_code, c.course_title,
               u.full_name as lecturer_name, u.email as lecturer_email, u.pf_no
        FROM course_assignments ca
        JOIN courses c            ON c.id = ca.course_id
        JOIN course_offerings co  ON co.course_id = c.id
        JOIN departments d        ON d.id = co.department_id
        JOIN users u               ON u.id = ca.lecturer_id
        WHERE d.name = ?";

$params = [$user['department']];
if ($filterSession) { $sql .= ' AND ca.academic_session=?'; $params[] = $filterSession; }
if ($filterCourse)  { $sql .= ' AND c.course_code=?';        $params[] = $filterCourse; }

$sql .= ' ORDER BY ca.academic_session DESC, c.course_code ASC';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$assignments = $stmt->fetchAll();

// Dropdown data — courses offered to the HOD's department
// (courses.department removed — same join pattern as above)
$courses = $db->prepare("
    SELECT DISTINCT c.id, c.course_code, c.course_title
    FROM courses c
    JOIN course_offerings co ON co.course_id = c.id
    JOIN departments d       ON d.id = co.department_id
    WHERE d.name = ?
    ORDER BY c.course_code
");
$courses->execute([$user['department']]);
$courses = $courses->fetchAll();

// Fetch staff who are marked as lecturers
$lecturers = $db->prepare("SELECT id, full_name, pf_no FROM users WHERE is_lecturer=1 AND department=? AND is_active=1 ORDER BY full_name");
$lecturers->execute([$user['department']]);
$lecturers = $lecturers->fetchAll();

$sessions = $db->query("SELECT DISTINCT academic_session FROM course_assignments ORDER BY academic_session DESC")->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'Course Assignments';
$activeNav = 'course_assignments.php';
include __DIR__ . '/../includes/layout.php';
?>

<div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
  <div class="flex items-center gap-4">
    <a href="dashboard.php" class="w-10 h-10 rounded-xl bg-white border border-gray-100 flex items-center justify-center text-gray-400 hover:text-[#001e40] transition-all">
        <span class="material-symbols-outlined">arrow_back</span>
    </a>
    <div>
        <h2 class="text-2xl font-black text-[#001e40] tracking-tight">Lecturer Assignments</h2>
        <p class="text-xs text-gray-400 font-bold uppercase tracking-widest"><?= sanitize($user['department']) ?> Faculty</p>
    </div>
  </div>
</div>

<?php if ($success): ?>
<div class="mb-6 p-4 bg-green-50 border border-green-100 text-green-700 rounded-2xl font-bold flex items-center gap-3">
    <span class="material-symbols-outlined">check_circle</span> <?= $success ?>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="mb-6 p-4 bg-red-50 border border-red-100 text-red-700 rounded-2xl font-bold flex items-center gap-3">
    <span class="material-symbols-outlined">error</span> <?= sanitize($error) ?>
</div>
<?php endif; ?>

<div class="grid grid-cols-12 gap-8">

  <div class="col-span-12 lg:col-span-8 space-y-6">

    <div class="bg-white p-4 rounded-[1.5rem] shadow-sm border border-gray-100">
      <form method="GET" class="flex flex-wrap gap-4">
        <select name="session" class="bg-[#f7f9ff] border-none rounded-xl px-4 py-2 text-sm font-bold focus:ring-2 focus:ring-[#001e40]">
          <option value="">All Sessions</option>
          <?php foreach ($sessions as $s): ?>
            <option value="<?= sanitize($s) ?>" <?= $filterSession===$s?'selected':'' ?>><?= sanitize($s) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="course" class="bg-[#f7f9ff] border-none rounded-xl px-4 py-2 text-sm font-bold focus:ring-2 focus:ring-[#001e40]">
          <option value="">All Courses</option>
          <?php foreach ($courses as $c): ?>
            <option value="<?= sanitize($c['course_code']) ?>" <?= $filterCourse===$c['course_code']?'selected':'' ?>><?= sanitize($c['course_code']) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="bg-[#001e40] text-white px-6 py-2 rounded-xl text-sm font-black hover:bg-[#003366] transition-all">Filter</button>
        <a href="course_assignments.php" class="bg-gray-100 text-gray-500 px-6 py-2 rounded-xl text-sm font-black hover:bg-gray-200 transition-all flex items-center">Clear</a>
      </form>
    </div>

    <div class="bg-white rounded-[2rem] shadow-sm border border-gray-100 overflow-hidden">
      <?php if (empty($assignments)): ?>
      <div class="flex flex-col items-center py-20 text-gray-300">
        <span class="material-symbols-outlined text-6xl mb-4">clinical_notes</span>
        <p class="font-black uppercase tracking-widest text-xs">No active assignments found</p>
      </div>
      <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full text-left">
          <thead>
            <tr class="text-[10px] font-black uppercase text-gray-400 tracking-[0.2em] border-b border-gray-50">
              <th class="px-8 py-5">Course</th>
              <th class="px-8 py-5">Assigned Lecturer</th>
              <th class="px-8 py-5 text-center">Period</th>
              <th class="px-8 py-5 text-right">Action</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <?php foreach ($assignments as $a): ?>
            <tr class="hover:bg-blue-50/30 transition-colors group">
              <td class="px-8 py-5">
                <p class="text-xs font-black text-[#001e40]"><?= sanitize($a['course_code']) ?></p>
                <p class="text-[10px] font-bold text-gray-400 truncate max-w-[180px]"><?= sanitize($a['course_title']) ?></p>
              </td>
              <td class="px-8 py-5">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg bg-[#edf4ff] text-primary flex items-center justify-center font-black text-[10px]"><?= substr($a['lecturer_name'],0,1) ?></div>
                    <div>
                        <p class="text-xs font-bold text-[#001e40]"><?= sanitize($a['lecturer_name']) ?></p>
                        <p class="text-[9px] text-gray-400"><?= sanitize($a['pf_no'] ?? 'Staff') ?></p>
                    </div>
                </div>
              </td>
              <td class="px-8 py-5 text-center">
                <span class="text-[10px] font-black text-blue-600 bg-blue-50 px-2 py-1 rounded"><?= sanitize($a['academic_session']) ?></span>
                <p class="text-[9px] font-bold text-gray-400 mt-1"><?= $a['semester'] ?> Semester</p>
              </td>
              <td class="px-8 py-5 text-right">
                <form method="POST" onsubmit="return confirm('Revoke this assignment?')">
                  <input type="hidden" name="action" value="remove">
                  <input type="hidden" name="assignment_id" value="<?= $a['id'] ?>">
                  <button type="submit" class="w-8 h-8 rounded-lg text-gray-300 hover:text-red-500 hover:bg-red-50 transition-all">
                    <span class="material-symbols-outlined text-sm">delete_sweep</span>
                  </button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-span-12 lg:col-span-4 space-y-6">

    <div class="bg-white rounded-[2rem] p-8 shadow-sm border border-gray-100">
      <h4 class="font-black text-[#001e40] text-sm mb-6 flex items-center gap-2">
        <span class="material-symbols-outlined text-blue-500">person_add</span> Manual Link
      </h4>
      <form method="POST" class="space-y-5" id="manualLinkForm">
        <input type="hidden" name="action" value="add">

        <!-- Searchable Course input (datalist) -->
        <div>
          <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Select Course</label>
          <input list="course_options" id="course_search" autocomplete="off" placeholder="Type to search course..."
                 class="w-full bg-[#f7f9ff] border-none rounded-xl p-3 text-xs font-bold focus:ring-2 focus:ring-[#001e40]"
                 oninput="resolveCourse(this.value)" onblur="resolveCourse(this.value)">
          <datalist id="course_options">
            <?php foreach ($courses as $c): ?>
              <option value="<?= sanitize($c['course_code']) ?> – <?= sanitize($c['course_title']) ?>"></option>
            <?php endforeach; ?>
          </datalist>
          <input type="hidden" name="course_id" id="course_id_hidden" required>
          <p id="courseMatchMsg" class="mt-1.5 text-[10px] font-bold hidden"></p>
        </div>

        <!-- Searchable Lecturer input (datalist) -->
        <div>
          <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Select Lecturer</label>
          <input list="lecturer_options" id="lecturer_search" autocomplete="off" placeholder="Type to search lecturer..."
                 class="w-full bg-[#f7f9ff] border-none rounded-xl p-3 text-xs font-bold focus:ring-2 focus:ring-[#001e40]"
                 oninput="resolveLecturer(this.value)" onblur="resolveLecturer(this.value)">
          <datalist id="lecturer_options">
            <?php foreach ($lecturers as $l): ?>
              <option value="<?= sanitize($l['full_name']) ?><?= $l['pf_no'] ? ' – ' . sanitize($l['pf_no']) : '' ?>"></option>
            <?php endforeach; ?>
          </datalist>
          <input type="hidden" name="lecturer_id" id="lecturer_id_hidden" required>
          <p id="lecturerMatchMsg" class="mt-1.5 text-[10px] font-bold hidden"></p>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Session</label>
                <input type="text" name="academic_session" value="<?= date('Y') . '/' . (date('Y')+1) ?>" class="w-full bg-[#f7f9ff] border-none rounded-xl p-3 text-xs font-bold">
            </div>
            <div>
                <label class="block text-[10px] font-black text-gray-400 uppercase tracking-widest mb-2">Semester</label>
                <select name="semester" class="w-full bg-[#f7f9ff] border-none rounded-xl p-3 text-xs font-bold">
                    <option>First</option><option>Second</option>
                </select>
            </div>
        </div>
        <button type="submit" class="w-full bg-[#001e40] text-white py-4 rounded-2xl font-black text-xs shadow-xl shadow-blue-900/10 hover:bg-[#003366] transition-all">
          Confirm Assignment
        </button>
      </form>
    </div>

    <div class="bg-[#001e40] rounded-[2rem] p-8 text-white shadow-xl relative overflow-hidden">
      <div class="absolute top-0 right-0 w-24 h-24 bg-white/5 rounded-full -mr-12 -mt-12"></div>
      <h4 class="font-black text-sm uppercase tracking-widest mb-6 flex items-center gap-2">
        <span class="material-symbols-outlined text-[#fecb00]">rocket_launch</span> Bulk Import
      </h4>
      <form method="POST" enctype="multipart/form-data" class="space-y-5">
        <input type="hidden" name="action" value="csv_import">
        <div class="bg-white/5 border border-white/10 rounded-2xl p-6 text-center cursor-pointer hover:bg-white/10 transition-all" onclick="document.getElementById('ca_csv').click()">
            <span class="material-symbols-outlined text-white/40 mb-2">cloud_upload</span>
            <p class="text-[10px] font-bold text-white/60">Upload CSV mapping file</p>
            <input type="file" name="csv_file" id="ca_csv" accept=".csv" class="hidden">
        </div>
        <textarea name="csv_paste" class="w-full bg-white/5 border border-white/10 rounded-2xl p-4 text-[10px] font-mono text-white/80 resize-none" rows="3" placeholder="course_code,lecturer_email,session,semester..."></textarea>
        <button type="submit" class="w-full bg-[#fecb00] text-[#001e40] py-4 rounded-2xl font-black text-xs hover:bg-[#f1c100] transition-all">
          Execute Bulk Mapping
        </button>
      </form>
    </div>

  </div>
</div>

<script>
const COURSES = <?= json_encode(array_map(fn($c) => [
    'id'      => $c['id'],
    'display' => $c['course_code'] . ' – ' . $c['course_title'],
    'code'    => $c['course_code'],
], $courses)) ?>;

const LECTURERS = <?= json_encode(array_map(fn($l) => [
    'id'      => $l['id'],
    'display' => $l['full_name'] . ($l['pf_no'] ? ' – ' . $l['pf_no'] : ''),
    'name'    => $l['full_name'],
], $lecturers)) ?>;

function resolveCourse(val) {
  val = val.trim();
  const hidden = document.getElementById('course_id_hidden');
  const msg    = document.getElementById('courseMatchMsg');
  const match  = COURSES.find(c => c.display === val);
  if (match) {
    hidden.value = match.id;
    msg.textContent = '✓ ' + match.display;
    msg.className = 'mt-1.5 text-[10px] font-bold text-green-600';
    msg.classList.remove('hidden');
  } else {
    hidden.value = '';
    if (val) {
      msg.textContent = '⚠ No matching course — please select from the list';
      msg.className = 'mt-1.5 text-[10px] font-bold text-red-500';
      msg.classList.remove('hidden');
    } else {
      msg.classList.add('hidden');
    }
  }
}

function resolveLecturer(val) {
  val = val.trim();
  const hidden = document.getElementById('lecturer_id_hidden');
  const msg    = document.getElementById('lecturerMatchMsg');
  const match  = LECTURERS.find(l => l.display === val);
  if (match) {
    hidden.value = match.id;
    msg.textContent = '✓ ' + match.display;
    msg.className = 'mt-1.5 text-[10px] font-bold text-green-600';
    msg.classList.remove('hidden');
  } else {
    hidden.value = '';
    if (val) {
      msg.textContent = '⚠ No matching lecturer — please select from the list';
      msg.className = 'mt-1.5 text-[10px] font-bold text-red-500';
      msg.classList.remove('hidden');
    } else {
      msg.classList.add('hidden');
    }
  }
}

// Block submission if course/lecturer weren't resolved to a valid id
document.getElementById('manualLinkForm').addEventListener('submit', function(e) {
  const courseId   = document.getElementById('course_id_hidden').value;
  const lecturerId = document.getElementById('lecturer_id_hidden').value;
  if (!courseId || !lecturerId) {
    e.preventDefault();
    alert('Please select a valid course and lecturer from the suggested list.');
  }
});
</script>

<?php include __DIR__ . '/../includes/layout_end.php'; ?>

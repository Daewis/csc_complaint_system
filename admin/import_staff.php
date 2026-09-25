<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// Ensure only Admin can access this
requireLogin();
$user = currentUser();
if ($user['role'] !== 'admin') {
    header('Location: ' . BASE_URL . 'dashboard.php');
    exit;
}

$db = getDB();
$success = '';
$error   = '';
$preview = [];
$step    = 'upload';

// ── STEP 2: CONFIRM IMPORT ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'confirm') {

    $rows    = json_decode($_POST['import_data'] ?? '[]', true);
    $count   = 0;
    $skipped = 0;
    $errors  = [];

    foreach ($rows as $row) {

        if (empty($row['email']) || empty($row['full_name'])) {
            $skipped++;
            continue;
        }

        try {
            /*
             * KEY FIX:
             * ─────────────────────────────────────────────────────────────────
             * Since you use Supabase for authentication, staff accounts are
             * created in two phases:
             *
             *   Phase 1 (this import): whitelist the email in MySQL with role
             *            flags so the system knows who is allowed to register.
             *            We do NOT touch password_hash or supabase_uid here —
             *            those are set when the staff member actually signs up
             *            through Supabase.
             *
             *   Phase 2 (self-registration): the staff member registers via
             *            Supabase → your callback/webhook sets supabase_uid
             *            and marks is_verified = 1 in MySQL.
             *
             * Columns deliberately excluded from this INSERT:
             *   • password_hash  — managed by Supabase, not us
             *   • supabase_uid   — assigned after Supabase sign-up
             *   • matric_number  — not applicable to staff
             *   • profile_picture, phone, title, signature_path — user fills later
             * ─────────────────────────────────────────────────────────────────
             */
            $stmt = $db->prepare("
                INSERT INTO users (
                    full_name,
                    email,
                    role,
                    PF_NO,
                    department,
                    faculty,
                    `level`,
                    is_lecturer,
                    is_level_adviser,
                    is_hod,
                    is_active,
                    is_verified
                )
                VALUES (?, ?, 'staff', ?, ?, ?, ?, ?, ?, ?, 1, 0)
                ON DUPLICATE KEY UPDATE
                    full_name        = VALUES(full_name),
                    PF_NO            = COALESCE(VALUES(PF_NO),        PF_NO),
                    department       = COALESCE(VALUES(department),    department),
                    faculty          = COALESCE(VALUES(faculty),       faculty),
                    `level`          = COALESCE(VALUES(`level`),       `level`),
                    is_lecturer      = VALUES(is_lecturer),
                    is_level_adviser = VALUES(is_level_adviser),
                    is_hod           = VALUES(is_hod)
            ");

            $stmt->execute([
                trim($row['full_name']),
                strtolower(trim($row['email'])),
                $row['pf_no']         ?: null,
                $row['department']    ?: null,
                $row['faculty']       ?: null,
                $row['level']         ?: null,
                (int)($row['is_lecturer']      ?? 1),
                (int)($row['is_level_adviser'] ?? 0),
                (int)($row['is_hod']           ?? 0),
            ]);

            $count++;

        } catch (Exception $e) {
            // Surface the real error so you can debug instead of silent skips
            $errors[] = htmlspecialchars($row['email']) . ': ' . $e->getMessage();
            error_log("Staff Import Error [{$row['email']}]: " . $e->getMessage());
            $skipped++;
        }
    }

    if ($count > 0) {
        $success = "$count staff record(s) whitelisted successfully."
                 . ($skipped > 0 ? " $skipped row(s) skipped." : '');
    } else {
        $error = "No records were saved. $skipped row(s) skipped.";
    }

    // Show per-row DB errors to the admin so nothing is silent
    if (!empty($errors)) {
        $error .= '<ul class="mt-2 list-disc list-inside text-xs">'
                . implode('', array_map(fn($e) => "<li>$e</li>", $errors))
                . '</ul>';
    }

    $step = 'upload';
}

// ── STEP 1: PARSE FILE ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'parse') {

    $rawData = [];
    $headers = [];

    // ── FILE UPLOAD ────────────────────────────────────────────────────────────
    if (!empty($_FILES['staff_file']['tmp_name'])) {

        $fileTmpPath = $_FILES['staff_file']['tmp_name'];
        $extension   = strtolower(pathinfo($_FILES['staff_file']['name'], PATHINFO_EXTENSION));

        try {
            if ($extension === 'csv') {

                if (($handle = fopen($fileTmpPath, 'r')) !== false) {
                    $firstLine = fgets($handle);
                    $headers   = array_map(
                        fn($h) => strtolower(trim(str_replace(' ', '_', (string)$h))),
                        str_getcsv($firstLine)
                    );

                    while (($data = fgetcsv($handle)) !== false) {
                        $data      = array_pad(array_slice($data, 0, count($headers)), count($headers), '');
                        $rawData[] = array_combine($headers, $data);
                    }
                    fclose($handle);
                }

            } elseif (in_array($extension, ['xlsx', 'xls'])) {

                $spreadsheet = IOFactory::load($fileTmpPath);
                $worksheet   = $spreadsheet->getActiveSheet();
                $rows        = $worksheet->toArray();
                $headers     = array_map(
                    fn($h) => strtolower(trim(str_replace(' ', '_', (string)$h))),
                    array_shift($rows)
                );

                foreach ($rows as $row) {
                    if (!is_array($row)) continue;
                    $row       = array_pad(array_slice($row, 0, count($headers)), count($headers), '');
                    $rawData[] = array_combine($headers, $row);
                }

            } else {
                $error = 'Unsupported file format. Please upload CSV, XLS or XLSX.';
            }

        } catch (Exception $e) {
            $error = 'Error processing file: ' . $e->getMessage();
        }

    // ── CSV PASTE ──────────────────────────────────────────────────────────────
    } elseif (!empty($_POST['csv_paste'])) {

        $lines   = array_filter(explode("\n", str_replace("\r", '', trim($_POST['csv_paste']))));
        $headers = array_map(
            fn($h) => strtolower(trim(str_replace(' ', '_', (string)$h))),
            str_getcsv(array_shift($lines))
        );

        foreach ($lines as $line) {
            $data      = str_getcsv($line);
            $data      = array_pad(array_slice($data, 0, count($headers)), count($headers), '');
            $rawData[] = array_combine($headers, $data);
        }
    }

    // ── PROCESS RAW DATA INTO PREVIEW ─────────────────────────────────────────
    if (empty($rawData) && !$error) {
        $error = 'No valid data found. Please check your file or pasted content.';
    } else {

        foreach ($rawData as $row) {

            $fullName = trim((string)($row['full_name'] ?? $row['name'] ?? ''));
            $email    = strtolower(trim((string)($row['email'] ?? $row['email_address'] ?? '')));

            if (!$email || !$fullName) continue;
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue; // skip garbage rows

            $designation = strtolower((string)($row['designation'] ?? ''));

            // Determine is_lecturer
            if (isset($row['is_lecturer'])) {
                $isLecturer = in_array(strtolower(trim((string)$row['is_lecturer'])), ['1', 'true', 'yes']) ? 1 : 0;
            } elseif (!empty($designation)) {
                $isLecturer = (str_contains($designation, 'lecturer')
                            || str_contains($designation, 'prof')
                            || str_contains($designation, 'dr.')) ? 1 : 0;
            } else {
                $isLecturer = 1; // default: treat as lecturer
            }

            $preview[] = [
                'full_name'       => $fullName,
                'email'           => $email,
                'pf_no'           => trim((string)($row['pf_no'] ?? ''))    ?: null,
                'department'      => trim((string)($row['department'] ?? '')) ?: null,
                'faculty'         => trim((string)($row['faculty'] ?? ''))   ?: null,
                'level'           => trim((string)($row['level'] ?? ''))     ?: null,
                'is_lecturer'     => $isLecturer,
                'is_hod'          => (str_contains($designation, 'hod') || str_contains($designation, 'head')) ? 1 : 0,
                'is_level_adviser'=> (str_contains($designation, 'adviser') || str_contains($designation, 'advisor')) ? 1 : 0,
            ];
        }

        $step = !empty($preview) ? 'preview' : 'upload';

        if (empty($preview) && !$error) {
            $error = 'No valid rows found. Make sure your file has full_name and a valid email column.';
        }
    }
}

$pageTitle = 'Staff Batch Import';
$activeNav = 'import_staff.php';
include __DIR__ . '/../includes/layout.php';
?>

<div class="mb-8 flex items-center justify-between">
    <div class="flex items-center gap-4">
        <a href="<?= BASE_URL ?>admin/dashboard.php"
           class="w-10 h-10 rounded-xl bg-white border border-gray-100 flex items-center justify-center text-gray-400 hover:text-[#001e40] transition-all">
            <span class="material-symbols-outlined">arrow_back</span>
        </a>
        <h2 class="text-3xl font-black text-[#001e40] tracking-tight">Staff Whitelist</h2>
    </div>
</div>

<?php if ($success): ?>
<div class="mb-6 p-4 bg-green-50 border border-green-100 text-green-700 rounded-2xl font-bold flex items-center gap-3">
    <span class="material-symbols-outlined">check_circle</span> <?= $success ?>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="mb-6 p-4 bg-red-50 border border-red-100 text-red-700 rounded-2xl font-bold flex items-start gap-3">
    <span class="material-symbols-outlined mt-0.5">error</span>
    <div><?= $error ?></div>
</div>
<?php endif; ?>

<?php if ($step === 'upload'): ?>
<!-- ── UPLOAD STEP ──────────────────────────────────────────────────────────── -->
<div class="grid grid-cols-12 gap-8">
    <div class="col-span-12 lg:col-span-8">
        <form method="POST" enctype="multipart/form-data" class="space-y-6">
            <input type="hidden" name="step" value="parse">

            <div class="bg-white rounded-[2rem] p-10 shadow-sm border border-gray-100">
                <div class="mb-8">
                    <h4 class="font-black text-[#001e40] mb-1">Authorization List</h4>
                    <p class="text-xs text-gray-400 font-medium">
                        Upload staff details (Excel or CSV) to authorize their account creation via Supabase.
                    </p>
                </div>

                <div class="bg-[#f7f9ff] border-2 border-dashed border-blue-100 rounded-3xl p-12 text-center group hover:bg-blue-50 transition-all cursor-pointer mb-8"
                     onclick="document.getElementById('staff_file').click()">
                    <span class="material-symbols-outlined text-4xl text-blue-200 group-hover:scale-110 transition-transform mb-4">upload_file</span>
                    <p class="text-sm font-bold text-[#001e40]">Upload CSV or Excel File</p>
                    <p id="fileNameDisplay" class="mt-2 text-[10px] text-gray-400 font-bold uppercase"></p>
                    <input type="file" name="staff_file" id="staff_file" class="hidden"
                           accept=".csv,.xlsx,.xls"
                           onchange="document.getElementById('fileNameDisplay').innerText = this.files[0].name">
                </div>

                <div class="relative mb-8">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-100"></div></div>
                    <div class="relative flex justify-center text-[10px] font-bold uppercase tracking-widest text-gray-300">
                        <span class="bg-white px-4">OR PASTE CSV DATA</span>
                    </div>
                </div>

                <textarea name="csv_paste"
                          class="w-full bg-[#f7f9ff] border-none rounded-2xl p-6 text-xs font-mono mb-4"
                          rows="8"
                          placeholder="full_name,email,pf_no,designation,department,faculty,level,is_lecturer&#10;Dr. Jane Doe,jane@lasu.edu.ng,LS9001,lecturer,Computer Science,Science,400,1"></textarea>
            </div>

            <button type="submit"
                    class="w-full bg-[#001e40] text-white py-5 rounded-2xl font-black shadow-xl shadow-blue-900/20 hover:bg-[#003366] transition-all flex items-center justify-center gap-3">
                <span class="material-symbols-outlined">analytics</span> Preview Authorization List
            </button>
        </form>
    </div>

    <div class="col-span-12 lg:col-span-4 space-y-6">
        <div class="bg-[#001e40] text-white p-8 rounded-[2rem] shadow-xl border-t-8 border-[#fecb00]">
            <h4 class="font-black text-sm uppercase tracking-widest mb-6 text-[#fecb00]">Supported Formats</h4>
            <div class="space-y-6">
                <div class="flex gap-4">
                    <div class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-[#fecb00] flex-shrink-0">
                        <span class="material-symbols-outlined text-sm">table_view</span>
                    </div>
                    <p class="text-xs font-medium leading-relaxed text-white/70">
                        <strong>Excel (.xlsx, .xls)</strong><br>First row must be column headers.
                    </p>
                </div>
                <div class="flex gap-4">
                    <div class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-[#fecb00] flex-shrink-0">
                        <span class="material-symbols-outlined text-sm">description</span>
                    </div>
                    <p class="text-xs font-medium leading-relaxed text-white/70">
                        <strong>CSV</strong><br>Standard comma-separated value files.
                    </p>
                </div>
            </div>
        </div>

        <div class="bg-[#fecb00]/10 border border-[#fecb00] p-6 rounded-2xl">
            <p class="font-black text-[#6e5700] text-xs uppercase tracking-widest mb-3">Column Guide</p>
            <div class="space-y-2 text-xs text-[#6e5700]/80">
                <p><strong>Required:</strong> full_name, email</p>
                <p><strong>Optional:</strong> pf_no, department, faculty, level</p>
                <p><strong>Role Detection:</strong> designation (lecturer/prof/dr., hod/head, adviser/advisor)</p>
                <p><strong>Or explicit:</strong> is_lecturer (1/0, true/false, yes/no)</p>
            </div>
        </div>

        <div class="bg-blue-50 border border-blue-100 p-6 rounded-2xl">
            <p class="font-black text-blue-800 text-xs uppercase tracking-widest mb-2">How It Works</p>
            <ol class="space-y-1 text-xs text-blue-700 list-decimal list-inside">
                <li>Import whitelists the staff email in MySQL</li>
                <li>Staff registers using their email via Supabase</li>
                <li>System links Supabase UID to the MySQL record</li>
                <li>Staff can now log in and access the portal</li>
            </ol>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ── PREVIEW STEP ────────────────────────────────────────────────────────── -->
<form method="POST" class="space-y-6">
    <input type="hidden" name="step" value="confirm">
    <input type="hidden" name="import_data" value="<?= htmlspecialchars(json_encode($preview)) ?>">

    <div class="bg-white rounded-[2rem] shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-10 py-6 border-b border-gray-50 flex justify-between items-center bg-[#f7f9ff]">
            <div>
                <h3 class="font-black text-[#001e40] text-sm uppercase tracking-widest">
                    <?= count($preview) ?> Member<?= count($preview) !== 1 ? 's' : '' ?> Awaiting Authorization
                </h3>
                <p class="text-[10px] text-gray-400 font-medium mt-0.5">
                    Review before whitelisting. Staff will register via Supabase using their email.
                </p>
            </div>
            <div class="flex gap-3">
                <a href="import_staff.php"
                   class="bg-white border border-gray-200 px-6 py-3 rounded-xl font-black text-xs hover:bg-gray-50 transition-all">
                    Cancel
                </a>
                <button type="submit"
                        class="bg-[#001e40] text-white px-8 py-3 rounded-xl font-black text-xs shadow-lg hover:bg-[#003366] transition-all">
                    Authorize Staff
                </button>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="text-[10px] font-black uppercase text-gray-400 border-b border-gray-50">
                        <th class="px-10 py-4">#</th>
                        <th class="px-10 py-4">Full Name</th>
                        <th class="px-10 py-4">Email</th>
                        <th class="px-10 py-4">PF No.</th>
                        <th class="px-10 py-4">Department</th>
                        <th class="px-10 py-4 text-center">Permissions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <?php foreach ($preview as $i => $row): ?>
                    <tr class="hover:bg-blue-50/30 transition-colors">
                        <td class="px-10 py-5 text-xs text-gray-400 font-mono"><?= $i + 1 ?></td>
                        <td class="px-10 py-5 text-xs font-bold text-[#001e40]"><?= sanitize($row['full_name']) ?></td>
                        <td class="px-10 py-5 text-xs text-gray-500 font-mono"><?= sanitize($row['email']) ?></td>
                        <td class="px-10 py-5 text-xs text-gray-500 font-mono"><?= $row['pf_no'] ?? '—' ?></td>
                        <td class="px-10 py-5 text-xs text-gray-600"><?= sanitize($row['department'] ?? '—') ?></td>
                        <td class="px-10 py-5">
                            <div class="flex gap-2 justify-center flex-wrap">
                                <?php if ($row['is_lecturer']): ?>
                                    <span class="bg-blue-100 text-blue-800 px-2 py-0.5 rounded text-[9px] font-black">LEC</span>
                                <?php endif; ?>
                                <?php if ($row['is_level_adviser']): ?>
                                    <span class="bg-amber-100 text-amber-800 px-2 py-0.5 rounded text-[9px] font-black">ADV</span>
                                <?php endif; ?>
                                <?php if ($row['is_hod']): ?>
                                    <span class="bg-purple-100 text-purple-800 px-2 py-0.5 rounded text-[9px] font-black">HOD</span>
                                <?php endif; ?>
                                <?php if (!$row['is_lecturer'] && !$row['is_level_adviser'] && !$row['is_hod']): ?>
                                    <span class="bg-gray-100 text-gray-500 px-2 py-0.5 rounded text-[9px] font-black">STAFF</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</form>
<?php endif; ?>

<?php include __DIR__ . '/../includes/layout_end.php'; ?>

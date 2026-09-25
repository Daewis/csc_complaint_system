<?php
/**
 * download_letter.php — Server-side PDF generation endpoint
 *
 * Renders the same LASU official letter as hod/generate_letter.php, but
 * instead of using the browser's "Save as PDF" dialog, it asks PDF.co
 * to convert the HTML to a real PDF file and streams it back to the
 * browser as an attachment.
 *
 * Authorisation: only the HOD of the complaint's department OR an admin
 * can download. (Same rules as generate_letter.php.)
 *
 * Usage:  download_letter.php?id=<complaint_id>
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireRole(['hod', 'admin']);

$user = currentUser();
$db   = getDB();

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    exit('Missing complaint ID.');
}

// ── Load complaint + student + signatures + course ─────────────────────────
$stmt = $db->prepare('
    SELECT c.*, co.course_code, co.course_title,
           u.full_name as student_name, u.matric_number, u.department, u.level,
           u.signature_path as student_sig,
           la.full_name as la_name, la.signature_path as la_sig, la.title as la_title,
           lec.full_name as lec_name, lec.signature_path as lec_sig,
           hod_u.full_name as hod_name, hod_u.signature_path as hod_sig, hod_u.title as hod_title,
           al.full_name as assigned_lec_name
    FROM complaints c
    JOIN courses co ON c.course_id = co.id
    JOIN users u    ON c.student_id = u.id
    LEFT JOIN users la    ON c.level_adviser_id = la.id
    LEFT JOIN users lec   ON c.lecturer_id = lec.id
    LEFT JOIN users hod_u ON c.hod_id = hod_u.id
    LEFT JOIN users al    ON c.hod_assigned_lecturer_id = al.id
    WHERE c.id = ?
');
$stmt->execute([$id]);
$c = $stmt->fetch();

if (!$c) {
    http_response_code(404);
    exit('Complaint not found.');
}

// Authorization: HOD must own the department; admin can access any.
if ($user['role'] !== 'admin' && $c['department'] !== $user['department']) {
    http_response_code(403);
    exit('You are not authorised to download this letter.');
}

// ── Build the same HTML the printable letter uses ───────────────────────────
// To keep things DRY we capture the HTML output of generate_letter.php by
// including it with output buffering — but generate_letter.php prints its
// own `no-print` toolbar, which we don't want in the PDF. So instead we
// build the letter HTML inline here, identical to generate_letter.php's
// letter markup but without the toolbar.
$refNo   = 'LASU/' . strtoupper(preg_replace('/\s+/','',$c['department'])) . '/RC/' . sanitize($c['ticket_number']);
$dateStr = date('jS F, Y', strtotime($c['created_at']));
$finalDate = $c['hod_final_signed_at'] ? date('jS F, Y', strtotime($c['hod_final_signed_at'])) : $dateStr;
$isResolved = in_array($c['status'], ['approved','rejected']);

// Logo fallback — if the asset file doesn't exist on disk, use an inline
// placeholder so the PDF still renders cleanly.
$logoPath = __DIR__ . '/assets/img/lasu_logo.jpg';
$logoUrl  = file_exists($logoPath)
    ? BASE_URL . 'assets/img/lasu_logo.jpg'
    : 'https://www.lasu.edu.ng/home/img/logo.png';

// Signature URL helpers (same pattern as generate_letter.php)
$studentSigUrl = !empty($c['student_sig']) ? BASE_URL . $c['student_sig'] : null;
$laSigUrl      = !empty($c['la_sig'])      ? BASE_URL . $c['la_sig']      : null;
$hodSigUrl     = !empty($c['hod_sig'])     ? BASE_URL . $c['hod_sig']     : null;
$lecSigUrl     = !empty($c['lec_sig'])     ? BASE_URL . $c['lec_sig']     : null;

$laTitle       = $c['la_title'] ?? '';
$laDisplayName = trim(($laTitle ? $laTitle . ' ' : '') . ($c['la_name'] ?? ''));
$hodTitle      = $c['hod_title'] ?? '';
$hodDisplayName= trim(($hodTitle ? $hodTitle . ' ' : '') . ($c['hod_name'] ?? ''));

// Build letter HTML
$html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<style>
  body { font-family: 'Times New Roman', Times, serif; color:#111; font-size:13px; line-height:1.7; margin:0; padding:40px; }
  .top-bars { display:flex; flex-direction:column; }
  .bar-gold  { height: 7px; background: #fecb00; }
  .bar-navy  { height: 7px; background: #001e40; }
  .bar-red   { height: 4px; background: #c0392b; }
  .lh-header { display:flex; align-items:center; padding:18px 0 14px; border-bottom:2px solid #001e40; gap:18px; }
  .lh-crest  { width:76px; height:76px; object-fit:contain; flex-shrink:0; }
  .lh-title  { flex:1; text-align:center; font-family:Arial,sans-serif; }
  .lh-title h1 { font-size:20px; font-weight:900; color:#001e40; text-transform:uppercase; letter-spacing:0.5px; line-height:1.2; margin:0; }
  .lh-title p { font-size:11px; color:#333; margin-top:2px; }
  .lh-address { text-align:right; font-size:9.5px; color:#444; line-height:1.5; min-width:150px; }
  .lh-address strong { color:#001e40; }
  .ref-row { display:flex; justify-content:space-between; margin:18px 0; font-size:12px; }
  .addressee { margin-bottom:18px; font-size:12.5px; }
  .subject-line { text-align:center; font-weight:bold; font-size:13px; text-decoration:underline; text-transform:uppercase; margin:14px 0 16px; letter-spacing:0.3px; }
  p { margin-bottom:10px; text-align:justify; }
  .score-table { border-collapse:collapse; width:100%; margin:10px 0 14px; font-size:12px; }
  .score-table th, .score-table td { border:1px solid #bbb; padding:5px 10px; text-align:center; }
  .score-table th { background:#f0f5ff; font-weight:bold; color:#001e40; }
  .sig-section { margin-top:20px; border-top:1px solid #ccc; padding-top:16px; }
  .sig-grid { display:grid; gap:16px; margin-top:12px; }
  .sig-grid.one  { grid-template-columns: 1fr; max-width: 280px; }
  .sig-grid.two  { grid-template-columns: 1fr 1fr; }
  .sig-grid.three{ grid-template-columns: 1fr 1fr 1fr; }
  .sig-block img { height:52px; max-width:180px; object-fit:contain; display:block; margin-bottom:2px; }
  .sig-block .sig-line { width:160px; border-bottom:1px solid #444; height:44px; margin-bottom:2px; }
  .sig-block .sig-name { font-weight:bold; font-size:12px; margin-top:2px; }
  .sig-block .sig-title { font-style:italic; font-size:11px; color:#444; }
  .audit-strip { background:#f5f8ff; border:1px solid #d2e4f9; border-radius:4px; padding:6px 10px; font-size:9px; color:#666; font-family:monospace; margin-top:16px; word-break:break-all; }
  .lh-footer { text-align:center; font-size:9px; color:#444; padding:6px 0; border-top:1px solid #ddd; }
  .footer-tagline { background:#001e40; color:#fecb00; text-align:center; font-size:10px; font-style:italic; letter-spacing:0.5px; padding:3px 0; font-family:Arial,sans-serif; }
</style></head>
<body>
  <div class="top-bars">
    <div class="bar-red"></div>
    <div class="bar-navy"></div>
    <div class="bar-gold"></div>
  </div>
  <div class="lh-header">
    <img src="{$logoUrl}" alt="LASU Crest" class="lh-crest">
    <div class="lh-title">
      <h1>Lagos State University, Ojo</h1>
      <p>Department of {$c['department']}</p>
      <p>Result Complaint Management Office</p>
    </div>
    <div class="lh-address">
      <strong>Lagos State University,</strong><br>
      Badagry Expressway,<br>P.M.B. 0001, Ojo, Lagos,<br>Nigeria.<br>
      <strong>web:</strong> www.lasu.edu.ng
    </div>
  </div>

  <div class="ref-row">
    <div><strong>Ref:</strong> {$refNo}</div>
    <div><strong>Date:</strong> {$finalDate}</div>
  </div>

  <div class="addressee">
    <strong>{$c['student_name']}</strong><br>
    {$c['matric_number']}<br>
    Department of {$c['department']},<br>Lagos State University, Ojo.
  </div>

  <p>Dear {$c['student_name']},</p>
HTML;

if ($c['status'] === 'approved') {
    $html .= <<<HTML
    <div class="subject-line">Result Complaint Resolution – {$c['course_code']} ({$c['academic_session']})</div>
    <p>I write on behalf of the Department of {$c['department']} to formally notify you of the outcome of your result complaint regarding your academic record for <strong>{$c['course_code']} – {$c['course_title']}</strong> in the {$c['semester']} Semester of the {$c['academic_session']} Academic Session.</p>
    <p>Following a thorough review of your complaint by the Level Adviser, verification by the Course Lecturer, and final deliberation by the Head of Department, your complaint has been <strong>upheld and approved for correction</strong>.</p>
HTML;
    if ($c['corrected_ca_score'] !== null) {
        $html .= <<<HTML
        <table class="score-table">
          <thead><tr><th>Assessment</th><th>Original Score</th><th>Corrected Score</th></tr></thead>
          <tbody>
            <tr><td>Continuous Assessment (CA)</td><td>{$c['original_ca_score']}</td><td><strong>{$c['corrected_ca_score']}</strong></td></tr>
            <tr><td>Examination Score</td><td>{$c['original_exam_score']}</td><td><strong>{$c['corrected_exam_score']}</strong></td></tr>
          </tbody>
        </table>
HTML;
    }
    if ($c['hod_final_comment']) {
        $html .= "<p><strong>HOD's Remarks:</strong> " . sanitize($c['hod_final_comment']) . "</p>";
    }
    $html .= "<p>You are advised to monitor your academic transcript through the student portal. Should you have any further concerns, please do not hesitate to contact the Department.</p><p>Accept the assurances of the Department's commitment to transparency and academic integrity.</p>";
} elseif ($c['status'] === 'rejected') {
    $html .= <<<HTML
    <div class="subject-line">Result Complaint Decision – {$c['course_code']} ({$c['academic_session']})</div>
    <p>I write on behalf of the Department of {$c['department']} regarding your result complaint for <strong>{$c['course_code']} – {$c['course_title']}</strong>, {$c['semester']} Semester, {$c['academic_session']} Academic Session.</p>
    <p>After careful review of the available records and verification by the Course Lecturer, the Head of Department has deliberated on the matter and is unable to uphold your complaint at this time.</p>
HTML;
    if ($c['hod_final_comment']) {
        $html .= "<p><strong>Reason:</strong> " . sanitize($c['hod_final_comment']) . "</p>";
    }
    $html .= "<p>If you believe there has been an error in this decision, you may escalate the matter to the Faculty or the Examinations and Records Office through the appropriate channels.</p>";
} else {
    $html .= <<<HTML
    <div class="subject-line">Acknowledgement of Result Complaint – {$c['course_code']} ({$c['academic_session']})</div>
    <p>I write to acknowledge receipt of your result complaint regarding <strong>{$c['course_code']} – {$c['course_title']}</strong> in the {$c['semester']} Semester of the {$c['academic_session']} Academic Session.</p>
    <p>Your complaint (<strong>Ref: {$refNo}</strong>) has been received and is currently being reviewed through the appropriate academic channels.</p>
    <p>The Department remains committed to ensuring transparency and fairness in all matters relating to academic records.</p>
HTML;
}
$html .= "<p>Thank you.</p>";

// Signatures
$html .= '<div class="sig-section"><p style="margin-bottom:10px;">Yours faithfully,</p>';

$hasHOD     = !empty($c['hod_name']) && $isResolved;
$hasLec     = !empty($c['lec_name']) && in_array($c['status'], ['verified','approved','rejected']);
$hasLA      = !empty($c['la_name']);
$cols       = array_sum([$hasHOD, $hasLec, $hasLA]) ?: 1;
$gridClass  = $cols === 1 ? 'one' : ($cols === 2 ? 'two' : 'three');
$html .= '<div class="sig-grid ' . $gridClass . '">';

if ($hasHOD) {
    $html .= '<div class="sig-block">';
    $html .= $hodSigUrl ? "<img src=\"{$hodSigUrl}\" alt=\"HOD Signature\">" : '<div class="sig-line"></div>';
    $html .= '<div class="sig-name">' . sanitize($c['hod_name']) . '</div>';
    $html .= '<div class="sig-title">Head of Department</div>';
    $html .= '<div class="sig-title">' . sanitize($c['department']) . ', LASU</div>';
    $html .= '<div class="audit-strip-style" style="font-family:monospace;font-size:9px;color:#666;margin-top:3px;">Signed: ' . formatDate($c['hod_final_signed_at']) . '</div>';
    $html .= '</div>';
} elseif (!empty($user['full_name'])) {
    $html .= '<div class="sig-block">';
    $html .= $hodSigUrl ? "<img src=\"{$hodSigUrl}\" alt=\"HOD Signature\">" : '<div class="sig-line"></div>';
    $html .= '<div class="sig-name">' . sanitize($user['full_name']) . '</div>';
    $html .= '<div class="sig-title">Head of Department</div>';
    $html .= '<div class="sig-title">' . sanitize($c['department']) . ', LASU</div>';
    $html .= '</div>';
}
if ($hasLec) {
    $html .= '<div class="sig-block">';
    $html .= $lecSigUrl ? "<img src=\"{$lecSigUrl}\" alt=\"Lecturer Signature\">" : '<div class="sig-line"></div>';
    $html .= '<div class="sig-name">' . sanitize($c['lec_name']) . '</div>';
    $html .= '<div class="sig-title">Course Lecturer</div>';
    $html .= '<div class="sig-title">' . sanitize($c['course_code']) . '</div>';
    $html .= '</div>';
}
if ($hasLA) {
    $html .= '<div class="sig-block">';
    $html .= $laSigUrl ? "<img src=\"{$laSigUrl}\" alt=\"LA Signature\">" : '<div class="sig-line"></div>';
    $html .= '<div class="sig-name">' . sanitize($laDisplayName) . '</div>';
    $html .= '<div class="sig-title">Level Adviser</div>';
    $html .= '<div class="sig-title">Department of ' . sanitize($c['department']) . '</div>';
    $html .= '</div>';
}
$html .= '</div>'; // sig-grid

// Audit chain strip
$laHash   = generateAuditHash($c['level_adviser_id'] ?? 0, $id, 'endorsed', $c['la_signed_at'] ?? '');
$lecHash  = $c['lecturer_id'] ? generateAuditHash($c['lecturer_id'], $id, 'lecturer_verified', $c['lecturer_signed_at'] ?? '') : '—';
$hodHash  = generateAuditHash($c['hod_id'] ?? 0, $id, 'hod_final', $c['hod_final_signed_at'] ?? '');
$html .= '<div class="audit-strip">';
$html .= "AUDIT CHAIN — Ticket: " . sanitize($c['ticket_number']) . " | Filed: " . formatDate($c['created_at']) . " | ";
$html .= "LA Hash: {$laHash} | ";
if ($c['lecturer_id']) $html .= "Lec Hash: {$lecHash} | ";
$html .= "HOD Hash: {$hodHash}";
$html .= '</div>';

$html .= '</div>'; // sig-section
$html .= '<div class="lh-footer">Lagos State University, Badagry Expressway, P.M.B. 0001, LASU Post Office, Ojo, Lagos, Nigeria. &nbsp;|&nbsp; website: www.lasu.edu.ng</div>';
$html .= '<div class="bar-navy" style="height:7px;background:#001e40;"></div>';
$html .= '<div class="bar-gold" style="height:7px;background:#fecb00;"></div>';
$html .= '<div class="footer-tagline">...we are LASU, we are Proud!</div>';
$html .= '</body></html>';

// ── Send to PDF.co for HTML → PDF conversion ────────────────────────────────
if (!defined('PDFCO_API_KEY') || !PDFCO_API_KEY) {
    http_response_code(500);
    exit('PDF generation unavailable: PDFCO_API_KEY is not configured.');
}

$ch = curl_init('https://api.pdf.co/v1/pdf/convert/from/html');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_TIMEOUT        => 120,
    CURLOPT_HTTPHEADER     => [
        'x-api-key: ' . PDFCO_API_KEY,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'html'      => $html,
        'name'      => 'LASU_Letter_' . $c['ticket_number'] . '.pdf',
        'margins'   => '10px 10px 10px 10px',
        'paperSize' => 'A4',
        'orientation'=> 'portrait',
        'async'     => false,
    ]),
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    http_response_code(502);
    exit('PDF.co request failed: ' . $curlErr);
}
if ($httpCode >= 400) {
    http_response_code(502);
    exit('PDF.co returned HTTP ' . $httpCode . '. Response: ' . substr($response, 0, 500));
}

$data = json_decode($response, true);
if (!$data || !empty($data['error']) || empty($data['url'])) {
    http_response_code(502);
    exit('PDF.co response invalid: ' . substr($response, 0, 500));
}

// Download the rendered PDF from PDF.co CDN and stream to the browser
$pdfUrl = $data['url'];

$dlCh = curl_init($pdfUrl);
curl_setopt_array($dlCh, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
]);
$pdfContent = curl_exec($dlCh);
$dlErr      = curl_error($dlCh);
curl_close($dlCh);

if ($dlErr || !$pdfContent) {
    http_response_code(502);
    exit('Failed to download rendered PDF from PDF.co CDN.');
}

// Stream as attachment
$filename = 'LASU_Letter_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $c['ticket_number']) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdfContent));
echo $pdfContent;
exit;

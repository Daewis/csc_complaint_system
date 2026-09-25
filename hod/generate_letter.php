<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole('hod');
$user = currentUser();
$db   = getDB();

$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare('
    SELECT c.*, co.course_code, co.course_title,
           u.full_name as student_name, u.matric_number, u.department, u.level,
           u.signature_path as student_sig,
           la.full_name as la_name, la.signature_path as la_sig, la.staff_id as la_staff_id,
           lec.full_name as lec_name, lec.signature_path as lec_sig, lec.staff_id as lec_staff_id,
           hod_u.full_name as hod_name, hod_u.signature_path as hod_sig, hod_u.staff_id as hod_staff_id,
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
if (!$c || $c['department'] !== $user['department']) {
    header('Location: /hod/dashboard.php'); exit;
}

$refNo   = 'LASU/' . strtoupper(preg_replace('/\s+/','',$c['department'])) . '/RC/' . sanitize($c['ticket_number']);
$dateStr = date('jS F, Y', strtotime($c['created_at']));
$finalDate = $c['hod_final_signed_at'] ? date('jS F, Y', strtotime($c['hod_final_signed_at'])) : $dateStr;
$isResolved = in_array($c['status'], ['approved','rejected']);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Official Letter – <?= sanitize($c['ticket_number']) ?> | LASU</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  @import url('https://fonts.googleapis.com/css2?family=Times+New+Roman&family=Inter:wght@400;700&display=swap');

  body {
    font-family: 'Times New Roman', Times, serif;
    background: #e8e8e8;
    color: #111;
    font-size: 13px;
    line-height: 1.7;
  }

  /* A4 page */
  .page {
    width: 210mm;
    min-height: 297mm;
    background: #fff;
    margin: 20px auto;
    padding: 0;
    box-shadow: 0 4px 40px rgba(0,0,0,0.18);
    position: relative;
    display: flex;
    flex-direction: column;
  }

  /* ── Top colour bars ─────────────────────────── */
  .top-bars {
    display: flex;
    flex-direction: column;
  }
  .bar-gold  { height: 7px; background: #fecb00; }
  .bar-navy  { height: 7px; background: #001e40; }
  .bar-red   { height: 4px; background: #c0392b; }

  /* ── Letterhead header ──────────────────────── */
  .lh-header {
    display: flex;
    align-items: center;
    padding: 18px 28px 14px;
    border-bottom: 2px solid #001e40;
    gap: 18px;
  }
  .lh-crest {
    width: 76px; height: 76px;
    object-fit: contain;
    flex-shrink: 0;
  }
  .lh-title {
    flex: 1;
    text-align: center;
  }
  .lh-title h1 {
    font-family: Arial, sans-serif;
    font-size: 20px;
    font-weight: 900;
    color: #001e40;
    letter-spacing: 0.5px;
    text-transform: uppercase;
    line-height: 1.2;
  }
  .lh-title p {
    font-family: Arial, sans-serif;
    font-size: 11px;
    color: #333;
    margin-top: 2px;
  }
  .lh-address {
    text-align: right;
    font-size: 9.5px;
    color: #444;
    line-height: 1.5;
    min-width: 150px;
  }
  .lh-address strong { color: #001e40; }

  /* ── Watermark ───────────────────────────────── */
  .letter-body {
    flex: 1;
    padding: 24px 40px 32px;
    position: relative;
  }
  .letter-body::before {
    content: '';
    position: absolute;
    top: 50%;  left: 50%;
    transform: translate(-50%, -55%);
    width: 260px; height: 260px;
    background: url('<?= $logoSrc ?>') center/contain no-repeat;
    opacity: 0.055;
    pointer-events: none;
    z-index: 0;
  }
  .letter-body > * { position: relative; z-index: 1; }

  /* ── Ref / Date row ──────────────────────────── */
  .ref-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 18px;
    font-size: 12px;
  }

  /* ── Addressee ───────────────────────────────── */
  .addressee {
    margin-bottom: 18px;
    font-size: 12.5px;
  }
  .addressee strong { font-size: 13px; }

  /* ── Subject line ────────────────────────────── */
  .subject-line {
    text-align: center;
    font-weight: bold;
    font-size: 13px;
    text-decoration: underline;
    text-transform: uppercase;
    margin: 14px 0 16px;
    letter-spacing: 0.3px;
  }

  /* ── Body paragraphs ─────────────────────────── */
  .letter-body p { margin-bottom: 10px; text-align: justify; }

  /* ── Score table ─────────────────────────────── */
  .score-table {
    border-collapse: collapse;
    width: 100%;
    margin: 10px 0 14px;
    font-size: 12px;
  }
  .score-table th, .score-table td {
    border: 1px solid #bbb;
    padding: 5px 10px;
    text-align: center;
  }
  .score-table th { background: #f0f5ff; font-weight: bold; color: #001e40; }

  /* ── Signature blocks ────────────────────────── */
  .sig-section {
    margin-top: 20px;
    border-top: 1px solid #ccc;
    padding-top: 16px;
  }
  .sig-grid {
    display: grid;
    gap: 16px;
    margin-top: 12px;
  }
  .sig-grid.one  { grid-template-columns: 1fr; max-width: 280px; }
  .sig-grid.two  { grid-template-columns: 1fr 1fr; }
  .sig-grid.three{ grid-template-columns: 1fr 1fr 1fr; }

  .sig-block { }
  .sig-block img {
    height: 52px;
    max-width: 180px;
    object-fit: contain;
    display: block;
    margin-bottom: 2px;
  }
  .sig-block .sig-line {
    width: 160px;
    border-bottom: 1px solid #444;
    height: 44px;
    margin-bottom: 2px;
  }
  .sig-block .sig-name  { font-weight: bold; font-size: 12px; margin-top: 2px; }
  .sig-block .sig-title { font-style: italic; font-size: 11px; color: #444; }
  .sig-block .sig-stamp {
    font-size: 9px;
    color: #666;
    margin-top: 3px;
    font-family: monospace;
  }

  /* ── Audit watermark strip ───────────────────── */
  .audit-strip {
    background: #f5f8ff;
    border: 1px solid #d2e4f9;
    border-radius: 4px;
    padding: 6px 10px;
    font-size: 9px;
    color: #666;
    font-family: monospace;
    margin-top: 16px;
    word-break: break-all;
  }

  /* ── Footer ──────────────────────────────────── */
  .lh-footer {
    display: flex;
    flex-direction: column;
  }
  .footer-address {
    text-align: center;
    font-size: 9px;
    color: #444;
    padding: 6px 28px;
    border-top: 1px solid #ddd;
  }
  .bar-navy-bottom  { height: 7px; background: #001e40; }
  .bar-gold-bottom  { height: 7px; background: #fecb00; }
  .footer-tagline {
    background: #001e40;
    color: #fecb00;
    text-align: center;
    font-size: 10px;
    font-style: italic;
    letter-spacing: 0.5px;
    padding: 3px 0;
    font-family: Arial, sans-serif;
  }

  /* ── Print controls ─────────────────────────── */
  .no-print {
    background: #fff;
    width: 210mm;
    margin: 0 auto 16px;
    display: flex;
    gap: 10px;
    align-items: center;
    padding: 12px 16px;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    font-family: Arial, sans-serif;
    font-size: 13px;
  }
  .no-print a, .no-print button {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 18px; border-radius: 6px; font-size: 13px;
    cursor: pointer; border: none; text-decoration: none; font-weight: 700;
  }
  .btn-back   { background:#edf4ff; color:#001e40; }
  .btn-print  { background:#001e40; color:#fff; margin-left: auto; }

  @media print {
    body { background: #fff; }
    .no-print { display: none !important; }
    .page { margin: 0; box-shadow: none; width: 100%; min-height: 100vh; }
    @page { size: A4; margin: 0; }
  }
</style>
</head>
<body>

<!-- Controls (hidden on print) -->
<div class="no-print">
  <a href="<?= BASE_URL ?>hod/review_complaint.php?id=<?= $id ?>" class="btn-back">← Back to Review</a>
  <span style="color:#43474f">Official letter for <strong><?= sanitize($c['ticket_number']) ?></strong></span>
  <a href="<?= BASE_URL ?>download_letter.php?id=<?= $id ?>" class="btn-back" style="margin-left:auto;background:#fecb00;color:#001e40;border:1px solid #f1c100;">
    <span style="font-size:14px;">⬇</span> Download PDF
  </a>
  <button onclick="window.print()" class="btn-print">🖨 Print / Save PDF</button>
</div>

<!-- A4 Page -->
<div class="page">

  <!-- Top colour bars -->
  <div class="top-bars">
    <div class="bar-red"></div>
    <div class="bar-navy"></div>
    <div class="bar-gold"></div>
  </div>

  <!-- Letterhead Header -->
  <div class="lh-header">
    <?php
    // Use the local logo if it exists, otherwise fall back to the
    // public LASU logo (keeps the letter rendering even if the
    // assets/img folder is not deployed yet).
    $localLogo = __DIR__ . '/../assets/img/lasu_logo.jpg';
    $logoSrc   = file_exists($localLogo)
        ? '/assets/img/lasu_logo.jpg'
        : 'https://www.lasu.edu.ng/home/img/logo.png';
    ?>
    <img src="<?= $logoSrc ?>" alt="LASU Crest" class="lh-crest" onerror="this.src='https://www.lasu.edu.ng/home/img/logo.png'">
    <div class="lh-title">
      <h1>Lagos State University, Ojo</h1>
      <p>Department of <?= sanitize($c['department']) ?></p>
      <p>Result Complaint Management Office</p>
    </div>
    <div class="lh-address">
      <strong>Lagos State University,</strong><br>
      Badagry Expressway,<br>
      P.M.B. 0001, Ojo, Lagos,<br>
      Nigeria.<br>
      <strong>web:</strong> www.lasu.edu.ng
    </div>
  </div>

  <!-- Letter Body -->
  <div class="letter-body">

    <!-- Ref / Date -->
    <div class="ref-row">
      <div><strong>Ref:</strong> <?= sanitize($refNo) ?></div>
      <div><strong>Date:</strong> <?= $isResolved ? $finalDate : $dateStr ?></div>
    </div>

    <!-- Addressee -->
    <div class="addressee">
      <strong><?= sanitize($c['student_name']) ?></strong><br>
      <?= sanitize($c['matric_number']) ?><br>
      Department of <?= sanitize($c['department']) ?>,<br>
      Lagos State University, Ojo.
    </div>

    <!-- Dear -->
    <p>Dear <?= sanitize(explode(' ', $c['student_name'])[0]) ?>,</p>

    <!-- Subject -->
    <div class="subject-line">
      <?php if ($c['status'] === 'approved'): ?>
        Result Complaint Resolution – <?= sanitize($c['course_code']) ?> (<?= sanitize($c['academic_session']) ?>)
      <?php elseif ($c['status'] === 'rejected'): ?>
        Result Complaint Decision – <?= sanitize($c['course_code']) ?> (<?= sanitize($c['academic_session']) ?>)
      <?php else: ?>
        Acknowledgement of Result Complaint – <?= sanitize($c['course_code']) ?> (<?= sanitize($c['academic_session']) ?>)
      <?php endif; ?>
    </div>

    <!-- Body paragraphs -->
    <?php if ($c['status'] === 'approved'): ?>

    <p>I write on behalf of the Department of <?= sanitize($c['department']) ?> to formally notify you of the outcome of your result complaint regarding your academic record for <strong><?= sanitize($c['course_code']) ?> – <?= sanitize($c['course_title']) ?></strong> in the <?= sanitize($c['semester']) ?> Semester of the <?= sanitize($c['academic_session']) ?> Academic Session.</p>

    <p>Following a thorough review of your complaint by the Level Adviser, verification by the Course Lecturer, and final deliberation by the Head of Department, your complaint has been <strong>upheld and approved for correction</strong>. The relevant record correction will be forwarded to the Examinations and Records Office for appropriate action.</p>

    <?php if ($c['corrected_ca_score'] !== null): ?>
    <table class="score-table">
      <thead>
        <tr><th>Assessment</th><th>Original Score</th><th>Corrected Score</th></tr>
      </thead>
      <tbody>
        <tr><td>Continuous Assessment (CA)</td><td><?= $c['original_ca_score'] ?? '—' ?></td><td><strong><?= $c['corrected_ca_score'] ?></strong></td></tr>
        <tr><td>Examination Score</td><td><?= $c['original_exam_score'] ?? '—' ?></td><td><strong><?= $c['corrected_exam_score'] ?></strong></td></tr>
      </tbody>
    </table>
    <?php endif; ?>

    <?php if ($c['hod_final_comment']): ?>
    <p><strong>HOD's Remarks:</strong> <?= sanitize($c['hod_final_comment']) ?></p>
    <?php endif; ?>

    <p>You are advised to monitor your academic transcript through the student portal. Should you have any further concerns, please do not hesitate to contact the Department.</p>
    <p>Accept the assurances of the Department's commitment to transparency and academic integrity.</p>

    <?php elseif ($c['status'] === 'rejected'): ?>

    <p>I write on behalf of the Department of <?= sanitize($c['department']) ?> regarding your result complaint for <strong><?= sanitize($c['course_code']) ?> – <?= sanitize($c['course_title']) ?></strong>, <?= sanitize($c['semester']) ?> Semester, <?= sanitize($c['academic_session']) ?> Academic Session.</p>

    <p>After careful review of the available records and verification by the Course Lecturer, the Head of Department has deliberated on the matter and is unable to uphold your complaint at this time.</p>

    <?php if ($c['hod_final_comment']): ?>
    <p><strong>Reason:</strong> <?= sanitize($c['hod_final_comment']) ?></p>
    <?php endif; ?>

    <p>If you believe there has been an error in this decision, you may escalate the matter to the Faculty or the Examinations and Records Office through the appropriate channels.</p>

    <?php else: ?>

    <p>I write to acknowledge receipt of your result complaint regarding <strong><?= sanitize($c['course_code']) ?> – <?= sanitize($c['course_title']) ?></strong> in the <?= sanitize($c['semester']) ?> Semester of the <?= sanitize($c['academic_session']) ?> Academic Session.</p>

    <p>Your complaint (<strong>Ref: <?= sanitize($refNo) ?></strong>) has been received and is currently being reviewed through the appropriate academic channels. You will be notified of the outcome upon conclusion of the review process.</p>

    <p>The Department remains committed to ensuring transparency and fairness in all matters relating to academic records.</p>

    <?php endif; ?>

    <p>Thank you.</p>

    <!-- Signature Section -->
    <div class="sig-section">
      <p style="margin-bottom:10px;">Yours faithfully,</p>

      <?php
        $sigCount = 0;
        $hasStudent = !empty($c['student_sig']) && file_exists(__DIR__ . '/../' . $c['student_sig']);
        $hasLA      = !empty($c['la_name']);
        $hasLec     = !empty($c['lec_name']) && in_array($c['status'],['verified','approved','rejected']);
        $hasHOD     = !empty($c['hod_name']) && $isResolved;
        $cols = array_sum([$hasStudent, $hasLA, $hasLec, $hasHOD]) ?: 1;
        $gridClass = $cols === 1 ? 'one' : ($cols === 2 ? 'two' : ($cols === 3 ? 'three' : 'three'));
      ?>
      <div class="sig-grid <?= $gridClass ?>">

        <!-- HOD signs last (resolution) -->
        <?php if ($hasHOD): ?>
        <div class="sig-block">
          <?php if (!empty($c['hod_sig']) && file_exists(__DIR__ . '/../' . $c['hod_sig'])): ?>
            <img src="<?= BASE_URL . ltrim($c['hod_sig'], '/') ?>" alt="HOD Signature">
          <?php else: ?>
            <div class="sig-line"></div>
          <?php endif; ?>
          <div class="sig-name"><?= sanitize($c['hod_name']) ?></div>
          <div class="sig-title">Head of Department</div>
          <div class="sig-title"><?= sanitize($c['department']) ?>, LASU</div>
          <div class="sig-stamp">Signed: <?= formatDate($c['hod_final_signed_at']) ?></div>
        </div>
        <?php elseif (!empty($user['full_name'])): ?>
        <div class="sig-block">
          <?php if (!empty($user['signature_path']) && file_exists(__DIR__ . '/../' . $user['signature_path'])): ?>
            <img src="<?= BASE_URL . ltrim($user['signature_path'], '/') ?>" alt="HOD Signature">
          <?php else: ?>
            <div class="sig-line"></div>
          <?php endif; ?>
          <div class="sig-name"><?= sanitize($user['full_name']) ?></div>
          <div class="sig-title">Head of Department</div>
          <div class="sig-title"><?= sanitize($c['department']) ?>, LASU</div>
        </div>
        <?php endif; ?>

        <!-- Lecturer (if verified) -->
        <?php if ($hasLec): ?>
        <div class="sig-block">
          <?php if (!empty($c['lec_sig']) && file_exists(__DIR__ . '/../' . $c['lec_sig'])): ?>
            <img src="<?= BASE_URL . ltrim($c['lec_sig'], '/') ?>" alt="Lecturer Signature">
          <?php else: ?>
            <div class="sig-line"></div>
          <?php endif; ?>
          <div class="sig-name"><?= sanitize($c['lec_name']) ?></div>
          <div class="sig-title">Course Lecturer</div>
          <div class="sig-title"><?= sanitize($c['course_code']) ?></div>
          <div class="sig-stamp">Verified: <?= formatDate($c['lecturer_signed_at'] ?? null) ?></div>
        </div>
        <?php endif; ?>

        <!-- Level Adviser -->
        <?php if ($hasLA): ?>
        <div class="sig-block">
          <?php if (!empty($c['la_sig']) && file_exists(__DIR__ . '/../' . $c['la_sig'])): ?>
            <img src="<?= BASE_URL . ltrim($c['la_sig'], '/') ?>" alt="Level Adviser Signature">
          <?php else: ?>
            <div class="sig-line"></div>
          <?php endif; ?>
          <div class="sig-name"><?= sanitize($c['la_name']) ?></div>
          <div class="sig-title">Level Adviser</div>
          <div class="sig-title">Department of <?= sanitize($c['department']) ?></div>
          <div class="sig-stamp">Endorsed: <?= formatDate($c['la_signed_at'] ?? null) ?></div>
        </div>
        <?php endif; ?>

      </div><!-- /sig-grid -->

      <!-- Audit integrity strip -->
      <div class="audit-strip">
        AUDIT CHAIN — Ticket: <?= sanitize($c['ticket_number']) ?> |
        Filed: <?= formatDate($c['created_at']) ?> |
        LA Hash: <?= generateAuditHash($c['level_adviser_id'] ?? 0, $id, 'endorsed', $c['la_signed_at'] ?? '') ?> |
        <?php if ($c['lecturer_id']): ?>Lec Hash: <?= generateAuditHash($c['lecturer_id'] ?? 0, $id, 'lecturer_verified', $c['lecturer_signed_at'] ?? '') ?> |<?php endif; ?>
        HOD Hash: <?= generateAuditHash($c['hod_id'] ?? 0, $id, 'hod_final', $c['hod_final_signed_at'] ?? '') ?>
      </div>

    </div><!-- /sig-section -->
  </div><!-- /letter-body -->

  <!-- Footer -->
  <div class="lh-footer">
    <div class="footer-address">
      Lagos State University, Badagry Expressway, P.M.B. 0001, LASU Post Office, Ojo, Lagos, Nigeria.
      &nbsp;|&nbsp; website: www.lasu.edu.ng
    </div>
    <div class="bar-navy-bottom"></div>
    <div class="bar-gold-bottom"></div>
    <div class="footer-tagline">...we are LASU, we are Proud!</div>
  </div>

</div><!-- /page -->
</body>
</html>

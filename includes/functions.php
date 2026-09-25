<?php
/**
 * Global Utility Functions
 * Handles Ticket generation, File Uploads, Stats, and SLA logic.
 */

require_once __DIR__ . '/../config/config.php';

/**
 * Gets the count of unread notifications for a specific user
 */
function getUnreadCount(int $userId): int {
    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("Notification Count Error: " . $e->getMessage());
        return 0;
    }
}

function generateTicketNumber(): string {
    return 'LASU-' . date('Y') . '-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));
}

function sanitize(?string $input): string {
    return htmlspecialchars(trim($input ?? ''), ENT_QUOTES, 'UTF-8');
}

function formatDate(?string $date): string {
    if (!$date || $date === '0000-00-00 00:00:00') return 'Not Set';
    return date('j M Y, g:i A', strtotime($date));
}

/**
 * Renders stylized status pills
 */
function statusBadge(string $status): string {
    $map = [
        'pending'              => ['bg-yellow-100 text-yellow-700', 'Pending Review'],
        'returned_to_student'  => ['bg-orange-100 text-orange-700', 'Returned for Corrections'],
        'endorsed'             => ['bg-blue-100 text-blue-700',     'Endorsed (HOD Hub)'],
        'assigned_to_lecturer' => ['bg-purple-100 text-purple-700', 'Awaiting Verification'],
        'verified'             => ['bg-indigo-100 text-indigo-700', 'Lecturer Verified'],
        'approved'             => ['bg-green-100 text-green-700',   'Final Approval'],
        'rejected'             => ['bg-red-100 text-red-700',       'Complaint Denied'],
    ];
    [$style, $label] = $map[strtolower($status)] ?? ['bg-gray-100 text-gray-600', ucfirst($status)];
    return "<span class=\"px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest $style\">$label</span>";
}

function addNotification(int $userId, ?int $complaintId, string $message, string $type = 'complaint'): void {
    try {
        $db = getDB();
        $stmt = $db->prepare('INSERT INTO notifications (user_id, complaint_id, message, type, created_at) VALUES (?,?,?,?, NOW())');
        $stmt->execute([$userId, $complaintId, $message, $type]);
    } catch (Exception $e) {
        error_log("Notification Error: " . $e->getMessage());
    }
}

/**
 * Gets count of complaints pending HOD approval for their department
 * Links complaints to courses to filter by department name
 */
function getPendingHODCount(string $deptName): int {
    try {
        $db = getDB();
        // Normalized schema: courses has no `department` column — route via
        // course_offerings → departments.
        $stmt = $db->prepare("
            SELECT COUNT(c.id)
            FROM complaints c
            JOIN users u ON c.student_id = u.id
            WHERE u.department = ?
              AND c.status IN ('endorsed')
        ");
        $stmt->execute([$deptName]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("HOD Count Error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Gets count of complaints pending Lecturer verification
 * Targets the 'lecturer_id' column in the complaints table
 */
function getPendingLecturerCount(int $lecturerId): int {
    try {
        $db = getDB();
        // Complaints assigned to this lecturer awaiting their verification.
        $stmt = $db->prepare("SELECT COUNT(*) FROM complaints WHERE hod_assigned_lecturer_id = ? AND status = 'assigned_to_lecturer'");
        $stmt->execute([$lecturerId]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("Lecturer Count Error: " . $e->getMessage());
        return 0;
    }
}

/**
 * SLA Logic: Marks tickets as overdue if the lecturer takes > 72 hours
 */
function checkSLAStatus(): void {
    try {
        $db = getDB();
        $db->exec("
            UPDATE complaints
            SET is_overdue = 1
            WHERE status = 'assigned_to_lecturer'
            AND sla_deadline < NOW()
            AND is_overdue = 0
        ");
    } catch (Exception $e) {
        error_log("SLA Job Error: " . $e->getMessage());
    }
}

/**
 * Statistics Generator for Dashboards
 */
function getStats(string $role, int $userId): array {
    $db = getDB();
    $stats = [
    'total'                => 0,
    'pending'              => 0,
    'endorsed'             => 0,
    'assigned_to_lecturer' => 0,
    'verified'             => 0,
    'approved'             => 0,
    'rejected'             => 0,
    'returned_to_student'  => 0,
    'overdue'              => 0,
];
    $role = strtolower($role);

    try {
        if ($role === 'student') {
            $stmt = $db->prepare('SELECT status, COUNT(*) as cnt FROM complaints WHERE student_id = ? GROUP BY status');
            $stmt->execute([$userId]);
        } elseif ($role === 'level_adviser') {
            $u = $db->prepare('SELECT level, department FROM users WHERE id=?');
            $u->execute([$userId]);
            $la = $u->fetch();
            $stmt = $db->prepare('SELECT c.status, COUNT(*) as cnt FROM complaints c JOIN users u ON c.student_id = u.id WHERE u.level = ? AND u.department = ? GROUP BY c.status');
            $stmt->execute([$la['level'], $la['department']]);
        } elseif ($role === 'hod') {
            $u = $db->prepare('SELECT department FROM users WHERE id=?');
            $u->execute([$userId]);
            $hod = $u->fetch();
            $stmt = $db->prepare('SELECT c.status, COUNT(*) as cnt FROM complaints c JOIN users u ON c.student_id = u.id WHERE u.department = ? GROUP BY c.status');
            $stmt->execute([$hod['department']]);
        } elseif ($role === 'lecturer') {
            $stmt = $db->prepare('SELECT status, COUNT(*) as cnt FROM complaints WHERE hod_assigned_lecturer_id = ? GROUP BY status');
            $stmt->execute([$userId]);
        } else {
            return $stats;
        }

        $rows = $stmt->fetchAll();
        foreach ($rows as $r) {
            $statusKey = strtolower($r['status']);
            if (array_key_exists($statusKey, $stats)) {
                $stats[$statusKey] = (int)$r['cnt'];
            }
            $stats['total'] += (int)$r['cnt'];
        }
    } catch (Exception $e) {
        error_log("Stats Error: " . $e->getMessage());
    }

    return $stats;
}



function uploadEvidence(array $file): ?string
{
    /*
    |--------------------------------------------------------------------------
    | CONFIG
    |--------------------------------------------------------------------------
    */
    $maxFileSize = 200 * 1024; // 200 KB

    /*
    |--------------------------------------------------------------------------
    | BASIC UPLOAD CHECK
    |--------------------------------------------------------------------------
    */
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        error_log('uploadEvidence: upload error code = ' . ($file['error'] ?? 'unknown'));
        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | ALLOWED EXTENSIONS
    |--------------------------------------------------------------------------
    */
    $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowedExtensions, true)) {
        error_log("uploadEvidence: invalid extension: {$ext}");
        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | MIME VALIDATION
    |--------------------------------------------------------------------------
    */
    $allowedMimeTypes = [
        'application/pdf',
        'image/jpeg',
        'image/png'
    ];

    $mime = mime_content_type($file['tmp_name']);

    if (!in_array($mime, $allowedMimeTypes, true)) {
        error_log("uploadEvidence: invalid MIME type: {$mime}");
        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | FILE SIZE VALIDATION (200 KB)
    |--------------------------------------------------------------------------
    */
    if ($file['size'] > $maxFileSize) {
        error_log(
            "uploadEvidence: file too large ({$file['size']} bytes). Maximum allowed: {$maxFileSize} bytes."
        );
        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | ENSURE UPLOAD DIRECTORY EXISTS
    |--------------------------------------------------------------------------
    */
    $uploadDir = __DIR__ . '/../assets/uploads/evidence/';

    if (!is_dir($uploadDir)) {

        if (!mkdir($uploadDir, 0755, true)) {
            error_log("uploadEvidence: failed to create directory: {$uploadDir}");
            return null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | GENERATE SAFE UNIQUE FILE NAME
    |--------------------------------------------------------------------------
    */
    $filename = 'EVID_' . bin2hex(random_bytes(8)) . '.' . $ext;

    $destination = $uploadDir . $filename;

    /*
    |--------------------------------------------------------------------------
    | MOVE FILE
    |--------------------------------------------------------------------------
    */
    if (move_uploaded_file($file['tmp_name'], $destination)) {

        return 'assets/uploads/evidence/' . $filename;
    }

    error_log(
        "uploadEvidence: move_uploaded_file failed. tmp={$file['tmp_name']} destination={$destination}"
    );

    return null;
}



/**
 * Save user signature images
 */
function saveSignature(array $file, string $identifier, string $role, string $type = 'signatures'): ?string {
    $subFolder      = ($role === 'student') ? 'students' : 'staff';
    $baseUploadPath = __DIR__ . "/../assets/uploads/$type/$subFolder/";

    if (!is_dir($baseUploadPath)) {
        if (!mkdir($baseUploadPath, 0755, true)) {
            error_log("saveSignature: Failed to create directory: $baseUploadPath");
            return null;
        }
    }

    $ext               = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];

    if (!in_array($ext, $allowedExtensions)) return null;

    $cleanIdentifier = preg_replace('/[^a-zA-Z0-9_-]/', '', $identifier);
    $newFileName     = $cleanIdentifier . '_' . time() . '.' . $ext;
    $fullDestination = $baseUploadPath . $newFileName;

    if (move_uploaded_file($file['tmp_name'], $fullDestination)) {
        return "assets/uploads/$type/$subFolder/$newFileName";
    }

    error_log("saveSignature: move_uploaded_file failed — dest=$fullDestination");
    return null;
}

function lookupStaff(string $pfNo): ?array {
    if (empty(trim($pfNo))) return null;

    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE PF_NO = ? AND role = 'staff' LIMIT 1");
    $stmt->execute([trim($pfNo)]);
    $row = $stmt->fetch();

    if (!$row) return null;

    $nameParts = explode(' ', trim($row['full_name']), 2);

    return [
        'id'         => $row['id'],
        'title'      => $row['title'] ?? '',
        'firstName'  => $nameParts[0] ?? '',
        'surname'    => $nameParts[1] ?? '',
        'email'      => $row['email'],
        'department' => $row['department'] ?? '',
        'faculty'    => $row['faculty'] ?? '',
        'role'       => $row['role'],
        'pf_no'      => $row['PF_NO'],
    ];
}

/**
 * logAudit — records an action in the audit_log table.
 */
function logAudit(int $targetId, int $actorId, string $action, string $ip, string $note = ''): void {
    try {
        $db   = getDB();
        $stmt = $db->prepare("
            INSERT INTO audit_log (actor_id, target_id, action, ip_address, notes, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$actorId, $targetId, $action, $ip, $note]);
    } catch (Throwable $e) {
        error_log('logAudit failed: ' . $e->getMessage());
    }
}

/**
 * getClientIP — returns the best-guess real IP of the current request.
 */
function getClientIP(): string {
    foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

/**
 * generateAuditHash — produces a tamper-evident short hash for an audit-chain
 * signature. Uses SHA-256 over (actorId|complaintId|action|timestamp) and
 * returns the first 16 hex chars (so it fits comfortably in the printed letter
 * audit strip).
 */
function generateAuditHash(int $userId, int $complaintId, string $action, string $timestamp): string {
    return substr(hash('sha256', "$userId|$complaintId|$action|$timestamp"), 0, 16);
}

/**
 * generateOtpCode — cryptographically-secure 6-digit OTP.
 * Uses random_int() (CSPRNG) so codes are not predictable.
 */
function generateOtpCode(int $length = 6): string {
    $min = (int) pow(10, $length - 1);
    $max = (int) pow(10, $length) - 1;
    return (string) random_int($min, $max);
}

/**
 * storeOtp — writes a fresh OTP row to the otp_codes table and invalidates
 * any previous unused OTPs for the same email + purpose (so only the newest
 * code works at a time).
 *
 * Returns the generated code (so the caller can send it via email or, in
 * development, surface it on screen).
 */
function storeOtp(string $email, string $purpose, string $userDataJson = null): string {
    $db   = getDB();
    $code = generateOtpCode();
    $expiresAt = date('Y-m-d H:i:s', time() + (defined('OTP_EXPIRY_MINUTES') ? OTP_EXPIRY_MINUTES : 15) * 60);

    // Invalidate any prior unused codes for this email/purpose
    $db->prepare("UPDATE otp_codes SET is_used = 1 WHERE email = ? AND purpose = ? AND is_used = 0")
       ->execute([$email, $purpose]);

    // Insert the new code
    $db->prepare("INSERT INTO otp_codes (email, code, purpose, user_data, expires_at) VALUES (?, ?, ?, ?, ?)")
       ->execute([$email, $code, $purpose, $userDataJson, $expiresAt]);

    return $code;
}

/**
 * verifyOtp — checks the supplied OTP against the latest unused, unexpired
 * row for (email, purpose). Handles attempt counting and lockout (5 attempts).
 *
 * Returns an array: ['ok' => bool, 'error' => string|null, 'user_data' => string|null]
 */
function verifyOtp(string $email, string $purpose, string $code): array {
    $db = getDB();

    $stmt = $db->prepare("SELECT * FROM otp_codes
        WHERE email = ? AND purpose = ? AND is_used = 0
        ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$email, $purpose]);
    $row = $stmt->fetch();

    if (!$row) {
        return ['ok' => false, 'error' => 'No active code found. Please request a new one.', 'user_data' => null];
    }
    if (strtotime($row['expires_at']) < time()) {
        // Mark as used so it can't be retried
        $db->prepare("UPDATE otp_codes SET is_used = 1 WHERE id = ?")->execute([$row['id']]);
        return ['ok' => false, 'error' => 'The code has expired. Please request a new one.', 'user_data' => null];
    }
    if ((int)$row['attempts'] >= 5) {
        $db->prepare("UPDATE otp_codes SET is_used = 1 WHERE id = ?")->execute([$row['id']]);
        return ['ok' => false, 'error' => 'Too many failed attempts. Please request a new code.', 'user_data' => null];
    }
    if (!hash_equals($row['code'], $code)) {
        // Increment attempts
        $db->prepare("UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?")->execute([$row['id']]);
        $remaining = 5 - ((int)$row['attempts'] + 1);
        return ['ok' => false, 'error' => "Incorrect code. {$remaining} attempt(s) remaining.", 'user_data' => null];
    }

    // Success — mark as used
    $db->prepare("UPDATE otp_codes SET is_used = 1 WHERE id = ?")->execute([$row['id']]);
    return ['ok' => true, 'error' => null, 'user_data' => $row['user_data']];
}

/**
 * sendOtpEmail — best-effort email dispatch.
 *
 * Uses PHP mail() if available; if mail() fails or returns false, the OTP
 * code is logged AND returned to the caller so it can be surfaced to the
 * user during development (or shown via flash message on hosts where
 * mail() is disabled).
 *
 * Returns ['sent' => bool, 'code' => string]
 */
function sendOtpEmail(string $toEmail, string $code, string $purpose = 'registration'): array {
    $subject = $purpose === 'password_reset'
        ? 'LASU Portal — Password Reset Code'
        : 'LASU Portal — Email Verification Code';

    $app    = defined('APP_NAME') ? APP_NAME : 'LASU Result Complaint Portal';
    $body   = "Hello,\n\n"
            . "Your {$app} verification code is: {$code}\n\n"
            . "This code will expire in "
            . (defined('OTP_EXPIRY_MINUTES') ? OTP_EXPIRY_MINUTES : 15)
            . " minutes. If you did not request this, you can safely ignore this email.\n\n"
            . "— {$app}";

    $headers = "From: no-reply@lasu.edu.ng\r\n"
             . "Reply-To: no-reply@lasu.edu.ng\r\n"
             . "X-Mailer: PHP/" . phpversion();

    // Suppress warnings — we handle the failure ourselves
    $sent = @mail($toEmail, $subject, $body, $headers);

    if (!$sent) {
        error_log("sendOtpEmail: mail() failed for {$toEmail}. Code was: {$code}");
    }

    return ['sent' => (bool)$sent, 'code' => $code];
}

/**
 * markNotificationRead — flips is_read on a single notification if it
 * belongs to the given user. Used by mark_notification_read.php (AJAX).
 */
function markNotificationRead(int $notificationId, int $userId): bool {
    try {
        $db = getDB();
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->execute([$notificationId, $userId]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('markNotificationRead failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * markAllNotificationsRead — flips is_read = 1 for ALL of the user's
 * notifications. Used by the explicit "Mark all as read" button on the
 * notifications page.
 */
function markAllNotificationsRead(int $userId): int {
    try {
        $db = getDB();
        $stmt = $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    } catch (Throwable $e) {
        error_log('markAllNotificationsRead failed: ' . $e->getMessage());
        return 0;
    }
}

<?php


/**
 * Global Configuration Loader - LASU Result Complaint Portal
 */


// ── LOAD .env ─────────────────────────────────────────────
function loadEnv($path) {
    if (!file_exists($path)) {
        // If .env is missing on the server, we need to know
        error_log("Config Warning: .env file not found at " . $path);
        return;
    }



    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        $line = trim($line);
        // Skip comments and empty lines
        if (empty($line) || strpos($line, '#') === 0) continue;

        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);

            $name  = trim($name);
            $value = trim($value, " \t\n\r\0\x0B\"'");

            // InfinityFree Fix: Set across all global arrays
            $_ENV[$name]    = $value;
            $_SERVER[$name] = $value;

            // putenv might be disabled on free hosting, so we suppress errors
            @putenv("$name=$value");
        }
    }
}

// Load .env from the root directory
loadEnv(__DIR__ . '/../.env');

// ── DATABASE CONFIG ───────────────────────────────────────
// config.php — replace the loadEnv() block entirely
define('DB_HOST', 'sql100.infinityfree.com'); // exact host from control panel
define('DB_PORT', '3306');
define('DB_NAME', 'if0_41681597_uniportal');  // starts with if0_
define('DB_USER', 'if0_41681597');              // same prefix
define('DB_PASS', '56dVUYUykv4W');

/**
 * Authentication — pure PHP, no Supabase.
 * (SUPABASE_URL / SUPABASE_KEY constants removed — auth handled by
 *  includes/auth.php + otp_codes table.)
 */
define('APP_NAME', 'LASU Curator');
define('APP_VERSION', '1.0.0');

define('GROQ_API_KEY', $_ENV['GROQ_API_KEY'] ?? '');
define('PDFCO_API_KEY', 'oludav55@gmail.com_42QMDu5XnzHnIsgl79JMQi33CCxjUgT0y7ZMqvdqgHbDDTanJOX5Kgi1xq1HUGV1');

define('MIN_UNITS', (int)($_ENV['MIN_UNITS'] ?? 18));
define('MAX_UNITS', (int)($_ENV['MAX_UNITS'] ?? 26));

// ── OTP / EMAIL VERIFICATION ──────────────────────────────
// Pure-PHP OTP flow replaces Supabase Auth. Codes are stored in
// the otp_codes table with an expiry; see functions.php for the
// storeOtp() / verifyOtp() / sendOtpEmail() helpers.
define('OTP_EXPIRY_MINUTES', 15);
define('OTP_LENGTH',         6);
define('OTP_MAX_ATTEMPTS',    5);

/**
 * 🚀 DYNAMIC BASE_URL FIX
 * This prevents the Redirect Loop on InfinityFree by automatically
 * detecting if you are on localhost or oludevops.infinityfreeapp.com
 */
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
// If your project is in a subfolder like /uniportal/, ensure it is included
$scriptName = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
$basePath = ($scriptName === '/') ? '/' : rtrim($scriptName, '/') . '/';

// If .env has a BASE_URL, use it, otherwise use the auto-detected one
$detectedUrl = $protocol . $host . $basePath;
define('BASE_URL', 'https://oludevops.infinityfreeapp.com/uniportal/');

// ── SYSTEM SETTINGS ──────────────────────────────────────
define('UPLOAD_DIR', __DIR__ . '/../assets/uploads/');
define('SLA_HOURS', 72);
define('SESSION_TIMEOUT', 3600);

date_default_timezone_set('Africa/Lagos');

// ── ERROR HANDLING ───────────────────────────────────────
// Set to 1 to debug the current 404/Connection issues
ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);




// ══════════════════════════════════════════════════════════
// MAINTENANCE MODE
// To turn ON:  set $maintenanceMode = true
// To turn OFF: set $maintenanceMode = false
// Admin bypass: visit your site with ?bypass=lasu
// ══════════════════════════════════════════════════════════
$maintenanceMode   = false;       // ← TOGGLE HERE
$maintenanceSecret = 'lasu'; // ← change to your own secret
$maintenancePage   = BASE_URL . 'maintenance.php';

if ($maintenanceMode) {

    // Set bypass cookie when secret key is passed in URL
    if (!empty($_GET['bypass']) && $_GET['bypass'] === $maintenanceSecret) {
        setcookie('maint_bypass', $maintenanceSecret, time() + 3600, '/');
        // Redirect cleanly without query string
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }

    // Check for valid bypass cookie
    $bypassed = !empty($_COOKIE['maint_bypass'])
                && $_COOKIE['maint_bypass'] === $maintenanceSecret;

    // Check if current page IS the maintenance page (avoid redirect loop)
    $isMaintPage = basename($_SERVER['PHP_SELF']) === 'maintenance.php';

    if (!$bypassed && !$isMaintPage) {
        header('Location: ' . $maintenancePage);
        exit;
    }
}

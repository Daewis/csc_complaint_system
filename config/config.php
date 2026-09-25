<?php
/**
 * LASU Result Complaint Portal — Central Configuration
 * ====================================================================
 *
 * This file is the ONLY place where environment-specific values live.
 * Every other file in the project loads its config from here via the
 * constants defined below. Never hardcode DB credentials, base URLs,
 * or absolute paths in any other file.
 *
 * LOADING ORDER (highest priority wins):
 *   1. Real environment variables (e.g. Docker, production server env)
 *   2. .env file at project root (loaded by loadEnv() below)
 *   3. Default values defined in this file (only used if no override)
 *
 * PORTABILITY:
 *   • BASE_URL is auto-detected from the request. Override it in .env
 *     only if you need a fixed URL (e.g. behind a proxy or for CLI use).
 *   • DB credentials default to typical XAMPP/MAMP values (localhost,
 *     root, no password) so the project runs on localhost out-of-the-box.
 *     Override them in .env for production.
 *   • All file paths use __DIR__-based resolution — never absolute.
 *
 * SETUP:
 *   1. Copy `config.example.php` → `config.php` (already done if you
 *      are reading this).
 *   2. Copy `.env.example` → `.env` at the project root.
 *   3. Fill in your DB credentials and (optionally) BASE_URL in `.env`.
 *   4. Import `database/schema.sql` into your MySQL server.
 *   5. Point your web server's document root at the project folder.
 *
 * @package LASU Result Complaint Portal
 * @since   1.0.0
 */

// ── 1. Load .env file (if present) ──────────────────────────────────────────
/**
 * Lightweight .env loader. Reads a KEY=VALUE file at $path and populates
 * $_ENV, $_SERVER, and putenv() so the rest of the app can use getenv().
 *
 * Comments (lines starting with #) and blank lines are skipped.
 * Values may be quoted with single or double quotes; quotes are stripped.
 */
function loadEnv(string $path): void {
    if (!file_exists($path)) {
        return; // .env is optional — env vars may come from the server itself
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;

        [$name, $value] = explode('=', $line, 2);
        $name  = trim($name);
        $value = trim($value, " \t\n\r\0\x0B\"'");

        $_ENV[$name]    = $value;
        $_SERVER[$name] = $value;
        @putenv("{$name}={$value}");
    }
}

loadEnv(__DIR__ . '/../.env');

// ── 2. Helper: env() — read with fallback ──────────────────────────────────
/**
 * Read an environment variable. Returns $default if not set.
 * Always defined before any define() calls below use it.
 */
function env(string $key, $default = null) {
    $val = getenv($key);
    if ($val === false || $val === '') {
        return $default;
    }
    return $val;
}

// ── 3. Database Configuration ───────────────────────────────────────────────
// Defaults below are for a typical local dev setup (XAMPP/MAMP/Laragon).
// Override in .env for production.
define('DB_HOST', env('DB_HOST', '127.0.0.1'));
define('DB_PORT', env('DB_PORT', '3306'));
define('DB_NAME', env('DB_NAME', 'lasu_uniportal'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));

// ── 4. Application Identity ─────────────────────────────────────────────────
define('APP_NAME',    env('APP_NAME', 'LASU Result Complaint Portal'));
define('APP_VERSION', env('APP_VERSION', '1.0.0'));

// ── 5. Third-Party API Keys ─────────────────────────────────────────────────
// Groq (AI document scan) — https://console.groq.com
define('GROQ_API_KEY', env('GROQ_API_KEY', ''));

// PDF.co (HTML → PDF conversion for the official letter) — https://app.pdf.co
define('PDFCO_API_KEY', env('PDFCO_API_KEY', ''));

// ── 6. Business Rules ────────────────────────────────────────────────────────
// Minimum / maximum units a student must remain registered for after a
// course removal appeal. Tunable per faculty policy.
define('MIN_UNITS', (int) env('MIN_UNITS', 18));
define('MAX_UNITS', (int) env('MAX_UNITS', 26));

// ── 7. OTP / Email Verification ──────────────────────────────────────────────
// Pure-PHP OTP flow — codes are stored in the `otp_codes` table with an
// expiry. See includes/functions.php for the storeOtp/verifyOtp helpers.
define('OTP_EXPIRY_MINUTES', (int) env('OTP_EXPIRY_MINUTES', 15));
define('OTP_LENGTH',         (int) env('OTP_LENGTH', 6));
define('OTP_MAX_ATTEMPTS',   (int) env('OTP_MAX_ATTEMPTS', 5));

// Email sender address used in the OTP email. Override with a real
// inbox in production. The local part can be anything; the domain must
// be one your server is allowed to send from.
define('MAIL_FROM',     env('MAIL_FROM', 'no-reply@lasu.edu.ng'));
define('MAIL_FROM_NAME',env('MAIL_FROM_NAME', APP_NAME));

// ── 8. SLA & Session ────────────────────────────────────────────────────────
define('SLA_HOURS',        (int) env('SLA_HOURS', 72));
define('SESSION_TIMEOUT',  (int) env('SESSION_TIMEOUT', 3600));

// ── 9. Dynamic BASE_URL Detection ───────────────────────────────────────────
/**
 * The BASE_URL is computed from the incoming request, so the project
 * works on localhost, in a subfolder, on a custom domain, or behind a
 * proxy — without any code changes.
 *
 * If you must force a specific BASE_URL (e.g. for CLI scripts where
 * there's no request context), set BASE_URL in .env.
 *
 * Examples of auto-detected BASE_URL:
 *   • http://localhost/uniportal/         (XAMPP, project in htdocs/uniportal)
 *   • http://localhost:8000/              (PHP built-in server, project root)
 *   • https://portal.lasu.edu.ng/          (production, root domain)
 *   • https://myapp.example.com/portal/    (production, subfolder)
 */
function detectBaseUrl(): string {
    // 1. Explicit override via env
    $forced = env('BASE_URL');
    if ($forced) return rtrim($forced, '/') . '/';

    // 2. Detect from request context
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || (($_SERVER['HTTP_FRONT_END_HTTPS'] ?? '') === 'on');
    $scheme = $isHttps ? 'https' : 'http';

    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    // Strip default ports from the host
    if (($scheme === 'http'  && substr($host, -5) === ':80') ||
        ($scheme === 'https' && substr($host, -6) === ':443')) {
        // host:port → host (only if the port matches the scheme default)
        $host = preg_replace('/:\d+$/', '', $host);
    }

    // Compute the base path (subfolder the project lives in).
    // E.g. for http://localhost/uniportal/admin/dashboard.php
    // $_SERVER['SCRIPT_NAME'] = '/uniportal/admin/dashboard.php'
    // dirname() gives '/uniportal/admin', then we strip the last segment
    // to get the project root: '/uniportal'.
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $scriptDir  = dirname($scriptName);

    // If the script is at the project root (e.g. /index.php), $scriptDir
    // is '/' or '\'. We want a clean '/' in that case.
    if ($scriptDir === '/' || $scriptDir === '\\') {
        $basePath = '/';
    } else {
        // Walk up until we leave the role-specific subfolder (admin/, hod/,
        // lecturer/, level_adviser/, student/) — this lets deep links
        // resolve the BASE_URL even when accessed from a role page.
        $roleFolders = ['/admin', '/hod', '/lecturer', '/level_adviser', '/student', '/config', '/includes'];
        foreach ($roleFolders as $rf) {
            if (substr($scriptDir, -strlen($rf)) === $rf) {
                $scriptDir = substr($scriptDir, 0, -strlen($rf));
                break;
            }
        }
        $basePath = rtrim($scriptDir, '/') . '/';
        if ($basePath === '//') $basePath = '/';
    }

    return $scheme . '://' . $host . $basePath;
}

define('BASE_URL', detectBaseUrl());

// ── 10. Filesystem Paths (all __DIR__-based — never hardcoded) ──────────────
define('PROJECT_ROOT',     dirname(__DIR__));          // /path/to/uniportal
define('UPLOAD_DIR',       PROJECT_ROOT . '/assets/uploads/');
define('EVIDENCE_DIR',     PROJECT_ROOT . '/assets/uploads/evidence/');
define('SIGNATURE_DIR',    PROJECT_ROOT . '/assets/uploads/signatures/');
define('PROFILE_PIC_DIR',  PROJECT_ROOT . '/assets/uploads/profiles/');

// ── 11. Timezone ────────────────────────────────────────────────────────────
date_default_timezone_set(env('APP_TIMEZONE', 'Africa/Lagos'));

// ── 12. Error Handling ──────────────────────────────────────────────────────
// In development, surface every error. In production, log only.
$appEnv = env('APP_ENV', 'production');
if ($appEnv === 'local' || $appEnv === 'development') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}

// ── 13. Maintenance Mode ────────────────────────────────────────────────────
// Toggle by setting MAINTENANCE_MODE=true in .env.
// Bypass with ?bypass=<secret> — sets a cookie valid for 1 hour.
$maintenanceMode   = (env('MAINTENANCE_MODE', 'false') === 'true');
$maintenanceSecret = env('MAINTENANCE_SECRET', 'lasu');
$maintenancePage   = BASE_URL . 'maintenance.php';

if ($maintenanceMode) {
    if (!empty($_GET['bypass']) && $_GET['bypass'] === $maintenanceSecret) {
        setcookie('maint_bypass', $maintenanceSecret, time() + 3600, '/');
        header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? '', '?'));
        exit;
    }

    $bypassed = !empty($_COOKIE['maint_bypass']) && $_COOKIE['maint_bypass'] === $maintenanceSecret;
    $isMaintPage = (basename($_SERVER['PHP_SELF'] ?? '')) === 'maintenance.php';

    if (!$bypassed && !$isMaintPage) {
        header('Location: ' . $maintenancePage);
        exit;
    }
}

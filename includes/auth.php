<?php
/**
 * Authentication Helper for LASU Digital Curator
 */

require_once __DIR__ . '/../config/config.php';

// 🚀 THE FIX: Point to database.php instead of functions.php
if (file_exists(__DIR__ . '/../config/database.php')) {
    require_once __DIR__ . '/../config/database.php';
} else {
    // If database.php is missing, surface the resolved path so the user
    // can fix their deployment (instead of a silent failure).
    die("CRITICAL ERROR: database.php not found in " . __DIR__);
}

// Ensure functions.php is also loaded if you have other helpers there
if (file_exists(__DIR__ . '/functions.php')) {
    require_once __DIR__ . '/functions.php';
}

// Ensure session is started globally
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * PUBLIC PAGES (no auth required)
 */
function isPublicPage(): bool {
    $publicPages = [
        'login.php',
        'register_staff.php',
        'register_student.php',
        'forgot_password.php'
    ];

    $current = basename($_SERVER['PHP_SELF']);
    return in_array($current, $publicPages);
}

/**
 * Checks if a user session exists
 */
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && isset($_SESSION['role']);
}

/**
 * Redirect logged-in users away from login/register pages
 */
function redirectIfLoggedIn(): void {
    if (isLoggedIn() && isPublicPage()) {
        $user = currentUser();
        header('Location: ' . BASE_URL . getRoleDashboard($_SESSION['role'], $user));
        exit;
    }
}

/**
 * Force redirect to login if not authenticated.
 */
function requireLogin(): void {
    // 🚀 KEY FIX: Allow public pages without redirect loop
    if (isPublicPage()) return;

    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . 'login.php');
        exit;
    }

    $user = currentUser();

    // If user hasn't completed registration
    if ($user && $user['password_hash'] === 'PRE_REGISTERED') {
        header('Location: ' . BASE_URL . 'register_staff.php?error=complete_setup');
        exit;
    }

    // Force password change if required
    if ($user && ($user['must_change_password'] ?? 0)) {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (!str_contains($uri, 'profile.php') && !str_contains($uri, 'logout.php')) {
            header('Location: ' . BASE_URL . 'profile.php?force_change=1');
            exit;
        }
    }
}

/**
 * Restrict access based on role
 */
function requireRole(string|array $roles): void {
    requireLogin();

    $user = currentUser();
    $allowed = is_array($roles) ? array_map('strtolower', $roles) : [strtolower($roles)];

    if (in_array(strtolower($user['role']), $allowed)) return;

    $flagMap = [
        'lecturer'      => (int)($user['is_lecturer'] ?? 0),
        'level_adviser' => (int)($user['is_level_adviser'] ?? 0),
        'hod'           => (int)($user['is_hod'] ?? 0),
    ];

    foreach ($allowed as $r) {
        if (isset($flagMap[$r]) && $flagMap[$r] === 1) return;
    }

    header('Location: ' . BASE_URL . 'login.php?error=unauthorized');
    exit;
}

/**
 * Fetch current user
 */
function currentUser(): ?array {
    if (!isLoggedIn()) return null;

    // Static variable persists only for the duration of a single script execution (one request)
    static $cachedUser = null;

    // Guard: If we already fetched the user in this request, return it immediately
    if ($cachedUser !== null) {
        return $cachedUser;
    }

    try {
        $db = getDB();
        $stmt = $db->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
        $stmt->execute([$_SESSION['user_id']]);

        // Fetch and store in the static variable for the rest of the request
        $cachedUser = $stmt->fetch() ?: null;

        return $cachedUser;
    } catch (Exception $e) {
        error_log("Auth Error: " . $e->getMessage());
        return null;
    }
}

/**
 * Login handler (pure PHP — no Supabase)
 *
 * Accepts a matric number, PF_NO, or email as the identifier along with
 * the plaintext password. On success, populates $_SESSION with user_id,
 * role, and name. Returns true on success, false on failure.
 */
function login(string $identifier, string $password): bool {
    try {
        $db = getDB();

        $stmt = $db->prepare('
            SELECT * FROM users
            WHERE (email = ? OR matric_number = ? OR PF_NO = ?)
            AND is_active = 1
        ');
        $stmt->execute([$identifier, $identifier, $identifier]);
        $user = $stmt->fetch();

        if (!$user) return false;

        // Pre-registered staff (whitelisted via admin import) must complete
        // self-registration before they can log in.
        if ($user['password_hash'] === 'PRE_REGISTERED') {
            // Surface a friendly error via the session so login.php can show it.
            $_SESSION['login_error'] = 'Please complete your staff registration before logging in.';
            return false;
        }

        // Active accounts must be verified AND have a usable password hash.
        if (!$user['is_verified']) {
            $_SESSION['login_error'] = 'Your email has not been verified yet. Please complete the OTP verification step first.';
            return false;
        }

        if (empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        // ── Success ────────────────────────────────────────────────────────
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role']    = strtolower($user['role']);
        $_SESSION['name']    = $user['full_name'];

        // Legacy Supabase UID is preserved on the row for backward reference,
        // but no longer used by the auth pipeline.
        return true;

    } catch (Exception $e) {
        error_log("Login Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Logout
 */
function logout(): void {
    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }

    session_destroy();
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

/**
 * Dashboard routing
 */
function getRoleDashboard(string $role, ?array $user = null): string {
    $role = strtolower($role);

    if ($role === 'student') return 'student/dashboard.php';
    if ($role === 'admin')   return 'admin/dashboard.php';

    if ($user) {
        if ($user['is_hod'])           return 'hod/dashboard.php';
        if ($user['is_level_adviser']) return 'level_adviser/dashboard.php';
        if ($user['is_lecturer'])      return 'lecturer/dashboard.php';
    }

    return 'login.php';
}

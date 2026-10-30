<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Access denied.');
}

// ============================================================================
// includes/auth.php — Central Session Security, RBAC Guard & CSRF Layer
// Enforces:
//   - FR-01: Hardened session cookies (HttpOnly, SameSite=Lax), session fixation
//            regeneration, no-cache headers, and live account status check
//   - FR-02 / BR-11: Server-side least-privilege role guards on every page & API
//   - FR-03: Cryptographic CSRF token generation & timing-safe verification
// ============================================================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../modules/audit_module.php';
require_once __DIR__ . '/../modules/auth_users_module.php';

// Configure hardened session cookie parameters before starting session (FR-01, Phase 5 Sec. 1.5)
if (session_status() === PHP_SESSION_NONE) {
    session_cache_limiter('nocache');
    session_cache_expire(0);

    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * Establish an authenticated session and regenerate the session ID
 * to prevent session fixation (FR-01).
 */
function establishUserSession(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id']          = (int) $user['user_id'];
    $_SESSION['username']         = (string) $user['username'];
    $_SESSION['full_name']        = (string) $user['full_name'];
    $_SESSION['role_id']          = (int) $user['role_id'];
    $_SESSION['role']             = (string) $user['role_name'];
    $_SESSION['bound_patient_id'] = $user['bound_patient_id'] ?? null;
    $_SESSION['bound_upi']        = $user['bound_upi'] ?? null;

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

/**
 * Check if the current request has an authenticated session.
 */
function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']) && !empty($_SESSION['role']);
}

/**
 * Return the logged-in user's role name (e.g. 'Administrator', 'Nurse/Midwife').
 */
function getUserRole(): string
{
    return (string) ($_SESSION['role'] ?? '');
}

/**
 * Return the logged-in user's ID.
 */
function currentUserId(): int
{
    return (int) ($_SESSION['user_id'] ?? 0);
}

/**
 * Return the default landing page for a given role.
 */
function roleLandingUrl(string $role = ''): string
{
    $r = $role ?: getUserRole();
    if ($r === ROLE_RESIDENT) {
        return appUrl('public/resident_portal.php');
    }
    return appUrl('public/dashboard.php');
}

/**
 * Gate that blocks non-logged-in users or accounts deactivated mid-session (BR-11).
 */
function requireLogin(bool $isJsonApi = false): void
{
    global $pdo;

    if (!isLoggedIn()) {
        if ($isJsonApi) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Authentication required. Please sign in.']);
            exit();
        }
        header('Location: ' . appUrl('modules/auth/login.php'));
        exit();
    }

    // Verify account is still Active in database and refresh Resident FK binding (BR-11)
    $stmt = $pdo->prepare("SELECT status, patient_id FROM users WHERE user_id = :id LIMIT 1");
    $stmt->execute([':id' => currentUserId()]);
    $liveUser = $stmt->fetch();

    if (!$liveUser || $liveUser['status'] !== 'Active') {
        session_unset();
        session_destroy();
        if ($isJsonApi) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Account has been deactivated.']);
            exit();
        }
        header('Location: ' . appUrl('modules/auth/login.php?error=deactivated'));
        exit();
    }

    if (getUserRole() === ROLE_RESIDENT) {
        $_SESSION['bound_patient_id'] = !empty($liveUser['patient_id']) ? (int) $liveUser['patient_id'] : null;
    }

    // Prevent browser back-button caching of sensitive health data after logout
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');
}

/**
 * FR-02 / BR-11: Server-side Role-Based Access Control enforcement.
 * Every protected page and API endpoint must call requireRole().
 * If a user requests a page outside their role privileges, logs the denial to `audit_logs`
 * and redirects or returns 403 JSON.
 *
 * @param string|array<string> $allowedRoles
 */
function requireRole(string|array $allowedRoles, bool $isJsonApi = false): void
{
    global $pdo;

    requireLogin($isJsonApi);

    $allowedList = (array) $allowedRoles;
    $currentRole = getUserRole();

    if (!in_array($currentRole, $allowedList, true)) {
        $uri = $_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? 'unknown');
        logAudit(
            $pdo,
            'ACCESS_DENIED',
            "Role '{$currentRole}' denied access to {$uri}",
            'security',
            null,
            currentUserId()
        );

        if ($isJsonApi) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error'   => 'Access Denied: Your role (' . $currentRole . ') is not authorized for this action (BR-11).',
            ]);
            exit();
        }

        $target = ($currentRole === ROLE_RESIDENT)
            ? appUrl('public/resident_portal.php?error=access_denied')
            : appUrl('public/dashboard.php?error=access_denied');
        header('Location: ' . $target);
        exit();
    }
}

/**
 * Securely destroy session and log out user.
 */
function logoutUser(): void
{
    global $pdo;

    if (isLoggedIn()) {
        logAudit(
            $pdo,
            'LOGOUT',
            "User '{$_SESSION['full_name']}' ({$_SESSION['role']}) signed out",
            'users',
            currentUserId(),
            currentUserId()
        );
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }
    session_destroy();

    header('Location: ' . appUrl('modules/auth/login.php'));
    exit();
}

/**
 * FR-03: Generate or retrieve cryptographic CSRF token per session.
 */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

/**
 * FR-03: Render hidden CSRF input field for HTML forms.
 */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

/**
 * FR-03: Verify CSRF token on state-changing POST forms or JSON API writes.
 */
function verifyCsrf(string $redirectBack = '', bool $isJsonApi = false): void
{
    $submitted = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

    if ($submitted === '' && $isJsonApi) {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['csrf_token'])) {
                $submitted = (string) $decoded['csrf_token'];
            }
        }
    }

    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if ($submitted === '' || $sessionToken === '' || !hash_equals($sessionToken, (string) $submitted)) {
        if ($isJsonApi) {
            http_response_code(419);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Invalid or expired CSRF token (FR-03). Please refresh the page.']);
            exit();
        }

        $_SESSION['error'] = 'Invalid or expired security token (FR-03). Please try submitting the form again.';
        $target = $redirectBack !== '' ? $redirectBack : roleLandingUrl();
        header('Location: ' . $target);
        exit();
    }
}

/**
 * Set a flash message in session ('success' or 'error').
 */
function setFlash(string $type, string $message): void
{
    $_SESSION[$type] = $message;
}

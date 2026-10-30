<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Access denied.');
}

// ============================================================================
// modules/auth_users_module.php — Module M1: User Authentication & Administration
// Covers: FR-01 (Username + Password auth, deactivation without deletion),
//         FR-02 (Least-privilege RBAC for 5 roles),
//         FR-04 (Admin user/role management & account status toggling),
//         FR-24 / BR-11 (Strict 1-to-1 `users.patient_id` FK binding for Resident accounts)
// ============================================================================

require_once __DIR__ . '/audit_module.php';

/**
 * Authenticate a user by username and password (FR-01, BR-11).
 *
 * @return array{success: bool, user?: array, error?: string}
 */
function authenticateUser(PDO $pdo, string $username, string $password): array
{
    $username = trim($username);
    if ($username === '' || $password === '') {
        return ['success' => false, 'error' => 'Please enter both your username and password.'];
    }

    $stmt = $pdo->prepare("
        SELECT u.*, r.role_name, r.description AS role_description,
               p.upi AS bound_upi
        FROM users u
        INNER JOIN roles r ON u.role_id = r.role_id
        LEFT JOIN patients p ON u.patient_id = p.patient_id
        WHERE u.username = :username
        LIMIT 1
    ");
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();

    // Generic message on wrong username or password per Phase 8 Sec. 54.1
    if (!$user || !password_verify($password, (string) $user['password_hash'])) {
        logAudit(
            $pdo,
            'LOGIN_FAILED',
            "Failed login attempt for username '{$username}'",
            'users',
            $user['user_id'] ?? null,
            $user['user_id'] ?? null
        );
        return ['success' => false, 'error' => 'Invalid username or password.'];
    }

    // BR-11: Deactivated accounts cannot sign in, but their history is retained
    if ($user['status'] !== 'Active') {
        logAudit(
            $pdo,
            'LOGIN_BLOCKED',
            "Blocked login attempt for deactivated account '{$username}'",
            'users',
            $user['user_id'],
            (int) $user['user_id']
        );
        return ['success' => false, 'error' => 'This account has been deactivated. Please contact the System Administrator.'];
    }

    // FR-24 / BR-11: Resident accounts MUST have an explicit `users.patient_id` FK binding.
    // Never parse display names or fall back to another patient's ID.
    $boundPatientId = null;
    $boundUpi       = null;
    if ($user['role_name'] === ROLE_RESIDENT) {
        if (empty($user['patient_id']) || empty($user['bound_upi'])) {
            logAudit(
                $pdo,
                'LOGIN_BLOCKED',
                "Blocked unlinked Resident account '{$username}' (missing users.patient_id FK)",
                'users',
                $user['user_id'],
                (int) $user['user_id']
            );
            return [
                'success' => false,
                'error'   => 'This Resident account is not linked to a verified Patient Registry record (BR-11). Please contact the Health Center Administrator.',
            ];
        }
        $boundPatientId = (int) $user['patient_id'];
        $boundUpi       = (string) $user['bound_upi'];
    }

    // Update last_login_at
    $upd = $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE user_id = :id");
    $upd->execute([':id' => $user['user_id']]);

    $user['bound_patient_id'] = $boundPatientId;
    $user['bound_upi']        = $boundUpi;

    logAudit(
        $pdo,
        'LOGIN',
        "User '{$user['full_name']}' signed in as {$user['role_name']}",
        'users',
        $user['user_id'],
        (int) $user['user_id']
    );

    return ['success' => true, 'user' => $user];
}

/**
 * Fetch all roles from `roles` table (D2).
 */
function getAllRoles(PDO $pdo): array
{
    return $pdo->query("SELECT * FROM roles ORDER BY role_id ASC")->fetchAll();
}

/**
 * Fetch all system users joined with their role name and optional bound patient UPI (D1 + D2 + D3).
 */
function getAllUsers(PDO $pdo): array
{
    return $pdo->query("
        SELECT u.user_id, u.username, u.full_name, u.role_id, u.patient_id, u.status, u.created_at, u.last_login_at,
               r.role_name, r.description AS role_description,
               p.upi AS bound_upi, p.last_name AS patient_last_name, p.first_name AS patient_first_name
        FROM users u
        INNER JOIN roles r ON u.role_id = r.role_id
        LEFT JOIN patients p ON u.patient_id = p.patient_id
        ORDER BY u.status ASC, r.role_id ASC, u.full_name ASC
    ")->fetchAll();
}

/**
 * Fetch a single user by user_id.
 */
function getUserById(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare("
        SELECT u.*, r.role_name, p.upi AS bound_upi
        FROM users u
        INNER JOIN roles r ON u.role_id = r.role_id
        LEFT JOIN patients p ON u.patient_id = p.patient_id
        WHERE u.user_id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Validate `patient_id` FK binding rules for Resident vs Staff roles (FR-24, BR-11).
 *
 * @return array{ok: bool, patient_id: ?int, error?: string}
 */
function validateResidentPatientBinding(PDO $pdo, string $roleName, ?int $patientId, int $excludeUserId = 0): array
{
    if ($roleName !== ROLE_RESIDENT) {
        return ['ok' => true, 'patient_id' => null];
    }

    $pid = (int) ($patientId ?? 0);
    if ($pid <= 0) {
        return [
            'ok'         => false,
            'patient_id' => null,
            'error'      => 'Resident accounts must be explicitly linked to a registered Patient (UPI) record (FR-24 / BR-11).',
        ];
    }

    $pStmt = $pdo->prepare("SELECT patient_id, upi FROM patients WHERE patient_id = :pid LIMIT 1");
    $pStmt->execute([':pid' => $pid]);
    if (!$pStmt->fetch()) {
        return [
            'ok'         => false,
            'patient_id' => null,
            'error'      => 'Selected patient record does not exist in the Master Patient Index.',
        ];
    }

    $dupStmt = $pdo->prepare("SELECT username FROM users WHERE patient_id = :pid AND user_id <> :uid LIMIT 1");
    $dupStmt->execute([':pid' => $pid, ':uid' => $excludeUserId]);
    $existingUsername = $dupStmt->fetchColumn();
    if ($existingUsername) {
        return [
            'ok'         => false,
            'patient_id' => null,
            'error'      => "That patient record is already bound to user account '{$existingUsername}'.",
        ];
    }

    return ['ok' => true, 'patient_id' => $pid];
}

/**
 * Create a new staff/resident account (FR-04, FR-24, BR-11).
 * Returns null on success or an error message string.
 */
function createUserAccount(
    PDO $pdo,
    string $username,
    string $password,
    string $fullName,
    int $roleId,
    ?int $actorUserId = null,
    ?int $patientId = null
): ?string {
    $username = strtolower(trim($username));
    $fullName = trim($fullName);

    if ($username === '' || $password === '' || $fullName === '' || $roleId <= 0) {
        return 'Username, full name, password, and role are required.';
    }

    if (!preg_match('/^[a-z0-9._-]{3,50}$/', $username)) {
        return 'Username must be 3–50 characters using letters, numbers, dots, underscores, or hyphens.';
    }

    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters long.';
    }

    // Check duplicate username
    $chk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = :u");
    $chk->execute([':u' => $username]);
    if ((int) $chk->fetchColumn() > 0) {
        return "Username '{$username}' is already taken.";
    }

    // Verify role exists
    $rChk = $pdo->prepare("SELECT role_name FROM roles WHERE role_id = :rid");
    $rChk->execute([':rid' => $roleId]);
    $roleName = $rChk->fetchColumn();
    if (!$roleName) {
        return 'Selected role is invalid.';
    }

    $binding = validateResidentPatientBinding($pdo, (string) $roleName, $patientId, 0);
    if (!$binding['ok']) {
        return $binding['error'] ?? 'Invalid patient binding.';
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("
        INSERT INTO users (username, password_hash, full_name, role_id, patient_id, status)
        VALUES (:username, :hash, :full_name, :role_id, :patient_id, 'Active')
    ");
    $stmt->execute([
        ':username'   => $username,
        ':hash'       => $hash,
        ':full_name'  => $fullName,
        ':role_id'    => $roleId,
        ':patient_id' => $binding['patient_id'],
    ]);

    $newId = (int) $pdo->lastInsertId();
    logAudit(
        $pdo,
        'USER_CREATE',
        "Created user account '{$username}' ({$fullName}) with role '{$roleName}'" . ($binding['patient_id'] ? " bound to patient_id={$binding['patient_id']}" : ''),
        'users',
        $newId,
        $actorUserId
    );

    return null;
}

/**
 * Update an existing user's full name, role, patient binding, and optionally reset their password (FR-04).
 */
function updateUserAccount(
    PDO $pdo,
    int $userId,
    string $fullName,
    int $roleId,
    string $newPassword = '',
    ?int $actorUserId = null,
    ?int $patientId = null
): ?string {
    $fullName = trim($fullName);
    if ($userId <= 0 || $fullName === '' || $roleId <= 0) {
        return 'Full name and valid role are required.';
    }

    $existing = getUserById($pdo, $userId);
    if (!$existing) {
        return 'User account not found.';
    }

    $rChk = $pdo->prepare("SELECT role_name FROM roles WHERE role_id = :rid");
    $rChk->execute([':rid' => $roleId]);
    $roleName = $rChk->fetchColumn();
    if (!$roleName) {
        return 'Selected role is invalid.';
    }

    $binding = validateResidentPatientBinding($pdo, (string) $roleName, $patientId, $userId);
    if (!$binding['ok']) {
        return $binding['error'] ?? 'Invalid patient binding.';
    }

    if ($newPassword !== '') {
        if (strlen($newPassword) < 8) {
            return 'New password must be at least 8 characters long.';
        }
        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("
            UPDATE users
            SET full_name = :full_name, role_id = :role_id, patient_id = :patient_id, password_hash = :hash
            WHERE user_id = :id
        ");
        $stmt->execute([
            ':full_name'  => $fullName,
            ':role_id'    => $roleId,
            ':patient_id' => $binding['patient_id'],
            ':hash'       => $hash,
            ':id'         => $userId,
        ]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE users
            SET full_name = :full_name, role_id = :role_id, patient_id = :patient_id
            WHERE user_id = :id
        ");
        $stmt->execute([
            ':full_name'  => $fullName,
            ':role_id'    => $roleId,
            ':patient_id' => $binding['patient_id'],
            ':id'         => $userId,
        ]);
    }

    logAudit(
        $pdo,
        'USER_UPDATE',
        "Updated user account #{$userId} ({$existing['username']})" . ($binding['patient_id'] ? " bound to patient_id={$binding['patient_id']}" : ''),
        'users',
        $userId,
        $actorUserId
    );

    return null;
}

/**
 * Toggle user status between 'Active' and 'Deactivated' (FR-01, FR-04, BR-11).
 * Accounts are NEVER deleted so clinical and audit history remains intact.
 */
function toggleUserAccountStatus(PDO $pdo, int $targetUserId, int $actingUserId): ?string
{
    if ($targetUserId === $actingUserId) {
        return 'You cannot deactivate your own active Administrator session.';
    }

    $user = getUserById($pdo, $targetUserId);
    if (!$user) {
        return 'User account not found.';
    }

    $newStatus = ($user['status'] === 'Active') ? 'Deactivated' : 'Active';
    $stmt = $pdo->prepare("UPDATE users SET status = :status WHERE user_id = :id");
    $stmt->execute([
        ':status' => $newStatus,
        ':id'     => $targetUserId,
    ]);

    logAudit(
        $pdo,
        'USER_STATUS',
        "Changed status of user '{$user['username']}' ({$user['full_name']}) to {$newStatus}",
        'users',
        $targetUserId,
        $actingUserId
    );

    return null;
}

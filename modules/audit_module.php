<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Access denied.');
}

// ============================================================================
// modules/audit_module.php — Module M9: Audit & Cross-Cutting Helpers
// Covers: BR-12 (Immutable Audit Logs, defensive logging never blocks user),
//         NFR-SEC-01 (Output escaping, typed input helpers, upload/download guards)
// ============================================================================

/**
 * HTML output escaping shorthand (NFR-SEC-01).
 */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Strip embedded `[FORM_DATA_JSON:{...}]` metadata from `service_records.findings_notes`
 * so longitudinal history views and the Resident Portal show clean human-readable text.
 */
function formatClinicalNotesForDisplay(?string $rawFindingsNotes): string
{
    $raw = (string) ($rawFindingsNotes ?? '');
    if ($raw === '') {
        return '';
    }
    $cleaned = trim((string) preg_replace('/\s*\[FORM_DATA_JSON:\{.*\}\]\s*$/s', '', $raw));
    return $cleaned !== '' ? $cleaned : $raw;
}

/**
 * Typed string input helper from $_POST or $_GET.
 */
function inputStr(array $source, string $key, string $default = ''): string
{
    return isset($source[$key]) && is_scalar($source[$key])
        ? trim((string) $source[$key])
        : $default;
}

/**
 * Typed integer input helper from $_POST or $_GET.
 */
function inputInt(array $source, string $key, int $default = 0): int
{
    return isset($source[$key]) && is_numeric($source[$key])
        ? (int) $source[$key]
        : $default;
}

/**
 * Resolve client IPv4/IPv6 address for audit trail (max 45 chars).
 */
function getClientIp(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    return substr((string) $ip, 0, 45);
}

/**
 * BR-12: Defensive Immutable Audit Logger.
 * - Inserts an append-only row into `audit_logs` (no UPDATE or DELETE ever exists).
 * - Wrapped in try/catch so an audit failure NEVER blocks clinical care (BR-12, NFR-REL-01).
 */
function logAudit(
    PDO $pdo,
    string $actionType,
    string $description,
    ?string $affectedRecordType = null,
    string|int|null $affectedRecordId = null,
    ?int $actingUserId = null
): bool {
    try {
        if ($actingUserId === null && isset($_SESSION['user_id'])) {
            $actingUserId = (int) $_SESSION['user_id'];
        }

        $stmt = $pdo->prepare("
            INSERT INTO audit_logs
                (user_id, action_type, description, source_ip, affected_record_type, affected_record_id)
            VALUES
                (:user_id, :action_type, :description, :source_ip, :record_type, :record_id)
        ");

        $stmt->execute([
            ':user_id'     => $actingUserId ?: null,
            ':action_type' => substr(trim($actionType), 0, 50),
            ':description' => substr(trim($description), 0, 255),
            ':source_ip'   => getClientIp(),
            ':record_type' => $affectedRecordType ? substr(trim($affectedRecordType), 0, 50) : null,
            ':record_id'   => $affectedRecordId !== null ? substr((string) $affectedRecordId, 0, 50) : null,
        ]);

        return true;
    } catch (Throwable $e) {
        // BR-12 / Phase 8 Sec. 54.1: Never interrupt user action if audit write fails
        error_log('[Tubtubon eHealth Audit Warning] ' . $e->getMessage());
        return false;
    }
}

/**
 * Fetch paginated or recent audit logs for Administrator review (FR-04).
 */
function getAuditLogs(PDO $pdo, int $limit = 100, string $actionFilter = '', string $search = ''): array
{
    $limit = max(1, min(500, $limit));
    $sql = "
        SELECT a.*, u.username, u.full_name, r.role_name
        FROM audit_logs a
        LEFT JOIN users u ON a.user_id = u.user_id
        LEFT JOIN roles r ON u.role_id = r.role_id
        WHERE 1=1
    ";
    $params = [];

    if ($actionFilter !== '') {
        $sql .= " AND a.action_type = :action_type";
        $params[':action_type'] = $actionFilter;
    }

    if ($search !== '') {
        $sql .= " AND (a.description LIKE :q OR u.full_name LIKE :q OR a.affected_record_id LIKE :q)";
        $params[':q'] = '%' . $search . '%';
    }

    $sql .= " ORDER BY a.created_at DESC, a.log_id DESC LIMIT :limit";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

/**
 * FR-12 / NFR-SEC-01: Validate and store an uploaded scan/photo outside the web root
 * with a cryptographically hashed filename.
 *
 * @return array{success: bool, filename?: string, error?: string}
 */
function storeSecureUpload(array $file, string $prefix = 'scan'): array
{
    if (empty($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'No valid file uploaded or upload was interrupted.'];
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > UPLOAD_MAX_BYTES) {
        return ['success' => false, 'error' => 'File size must be between 1 byte and 5 MB (FR-12).'];
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        return ['success' => false, 'error' => 'Invalid upload source.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string) $finfo->file($tmpPath);

    if (!array_key_exists($mime, UPLOAD_ALLOWED_MIMES)) {
        return ['success' => false, 'error' => 'Unsupported file type. Allowed: PDF, JPG, PNG, WEBP (FR-12).'];
    }

    $ext = UPLOAD_ALLOWED_MIMES[$mime];
    $safePrefix = preg_replace('/[^a-z0-9_]/i', '', $prefix) ?: 'doc';
    $hashedName = bin2hex(random_bytes(8)) . '_' . strtolower($safePrefix) . '.' . $ext;

    if (!is_dir(STORAGE_UPLOADS_PATH)) {
        mkdir(STORAGE_UPLOADS_PATH, 0755, true);
    }

    $targetPath = STORAGE_UPLOADS_PATH . '/' . $hashedName;
    if (!move_uploaded_file($tmpPath, $targetPath)) {
        return ['success' => false, 'error' => 'Failed to save uploaded file to secure storage.'];
    }

    return ['success' => true, 'filename' => $hashedName, 'mime' => $mime];
}

/**
 * FR-12 / NFR-SEC-01: Resolve and verify that a requested file lies strictly inside
 * STORAGE_UPLOADS_PATH to block path-traversal attacks (e.g. "../").
 */
function resolveSafeUploadPath(string $storedFilename): ?string
{
    $cleanName = basename(trim($storedFilename));
    if ($cleanName === '' || $cleanName === '.' || $cleanName === '..') {
        return null;
    }

    $baseDir  = realpath(STORAGE_UPLOADS_PATH);
    $fullPath = realpath(STORAGE_UPLOADS_PATH . '/' . $cleanName);

    if ($baseDir === false || $fullPath === false || !is_file($fullPath)) {
        return null;
    }

    // Strict containment check (anti-traversal)
    $normalizedBase = rtrim(str_replace('\\', '/', $baseDir), '/') . '/';
    $normalizedFull = str_replace('\\', '/', $fullPath);

    if (!str_starts_with($normalizedFull, $normalizedBase)) {
        return null;
    }

    return $fullPath;
}

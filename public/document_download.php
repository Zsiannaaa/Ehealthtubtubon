<?php
// ============================================================================
// public/document_download.php — Secure Scan Streamer (FR-12, NFR-SEC-01)
// Re-validates session, role guard, and realpath() containment inside
// STORAGE_UPLOADS_PATH before streaming any file, preventing path traversal.
// ============================================================================
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../modules/documents_module.php';

requireRole([ROLE_ADMIN, ROLE_NURSE, ROLE_BHW]);

$docId = inputInt($_GET, 'id');
$doc   = getDocumentById($pdo, $docId);

if (!$doc || empty($doc['file_path'])) {
    http_response_code(404);
    exit('Requested document scan not found.');
}

// FR-12: Re-validate that the file lies strictly inside STORAGE_UPLOADS_PATH
$safePath = resolveSafeUploadPath((string) $doc['file_path']);
if ($safePath === null) {
    logAudit(
        $pdo,
        'FILE_ACCESS_BLOCKED',
        "Blocked invalid or missing file download for document #{$docId}",
        'documents',
        $docId,
        currentUserId()
    );
    http_response_code(404);
    exit('File attachment is unavailable or blocked by path-traversal security guard (FR-12).');
}

$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mimeType = (string) $finfo->file($safePath);
$filename = basename($safePath);

logAudit(
    $pdo,
    'DOCUMENT_VIEW',
    "Viewed attached scan '{$filename}' for document #{$docId} ({$doc['upi']})",
    'documents',
    $docId,
    currentUserId()
);

header('Content-Type: ' . ($mimeType ?: 'application/octet-stream'));
header('Content-Disposition: inline; filename="' . addslashes($filename) . '"');
header('Content-Length: ' . filesize($safePath));
header('X-Content-Type-Options: nosniff');
readfile($safePath);
exit();

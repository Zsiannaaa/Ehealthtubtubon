<?php
// ============================================================================
// public/api/document_transition.php — Internal REST/JSON Write Endpoint
// Covers: FR-11 / BR-03 (4-state transition with timestamp + audit), Phase 8 Sec. 50.3
// Requires valid session, Nurse/Midwife or BHW role, and CSRF token.
// ============================================================================
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../modules/documents_module.php';

requireRole([ROLE_NURSE, ROLE_BHW], true);
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit();
}

verifyCsrf('', true);

$payload = $_POST;
if (empty($payload)) {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }
}

$documentId = (int) ($payload['document_id'] ?? 0);
$newStatus  = trim((string) ($payload['new_status'] ?? ''));
$cabinet    = isset($payload['cabinet_location']) ? (string) $payload['cabinet_location'] : null;
$box        = isset($payload['box_location'])     ? (string) $payload['box_location']     : null;
$folder     = isset($payload['folder_location'])  ? (string) $payload['folder_location']  : null;

$res = transitionDocumentState(
    $pdo,
    $documentId,
    $newStatus,
    $cabinet,
    $box,
    $folder,
    null,
    currentUserId()
);

if (!$res['success']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $res['error'] ?? 'Transition failed.']);
    exit();
}

echo json_encode([
    'success' => true,
    'data'    => $res['document'],
]);

<?php
/**
 * Tubtubon eHealth — API Endpoint: OCR Draft Verification / Rejection (M8)
 * Phase 8 Sec. 50 (M8) | FR-25, FR-26, FR-27 | BR-10
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../modules/ocr_module.php';

header('Content-Type: application/json; charset=UTF-8');

requireRole([ROLE_NURSE, ROLE_BHW], true);

$roleName = getUserRole();
$userId   = currentUserId();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $draftId = inputInt($_GET, 'draft_id', 0);
    if ($draftId > 0) {
        $draft = getOcrDraftById($draftId);
        if (!$draft) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'OCR draft not found.']);
            exit;
        }
        echo json_encode(['ok' => true, 'draft' => $draft]);
        exit;
    }

    $status = inputStr($_GET, 'status', '');
    echo json_encode([
        'ok'     => true,
        'drafts' => listOcrDrafts($status),
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole([ROLE_NURSE, ROLE_BHW], true);
    verifyCsrf('', true);
    $draftId  = inputInt($_POST, 'draft_id', 0);
    $decision = inputStr($_POST, 'decision', '');

    $res = processOcrDraftDecision(
        $draftId,
        $decision,
        $_POST,
        $userId,
        $roleName
    );

    if (!$res['ok']) {
        http_response_code(400);
    }
    echo json_encode($res);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);

<?php
/**
 * Tubtubon eHealth — API Endpoint: Service Records (M3)
 * Supports GET (single record or list) and POST (verify or create).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../modules/service_records_module.php';

header('Content-Type: application/json; charset=UTF-8');

requireRole([ROLE_NURSE, ROLE_BHW], true);

$roleName = getUserRole();
$userId   = currentUserId();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $recordId = inputInt($_GET, 'record_id', 0);
    if ($recordId > 0) {
        $rec = getServiceRecordById($recordId);
        if (!$rec) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Service record not found.']);
            exit;
        }
        echo json_encode(['ok' => true, 'record' => $rec]);
        exit;
    }

    $filters = [
        'patient_id'      => inputInt($_GET, 'patient_id', 0),
        'program_type'    => inputStr($_GET, 'program_type', ''),
        'unverified_only' => !empty($_GET['unverified_only']),
        'search'          => inputStr($_GET, 'search', ''),
    ];
    echo json_encode([
        'ok'      => true,
        'records' => listServiceRecords($filters, 100),
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole([ROLE_NURSE, ROLE_BHW], true);
    verifyCsrf('', true);
    $action = inputStr($_POST, 'action', '');

    if ($action === 'verify') {
        requireRole([ROLE_NURSE], true);
        $recordId = inputInt($_POST, 'record_id', 0);
        $res      = verifyServiceRecord($recordId, $userId, $roleName);
        if (!$res['ok']) {
            http_response_code(400);
        }
        echo json_encode($res);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unsupported action.']);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);

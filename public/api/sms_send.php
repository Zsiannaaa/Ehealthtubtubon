<?php
// ============================================================================
// public/api/sms_send.php — Internal REST/JSON Write Endpoint
// Covers: FR-21 & FR-22 / BR-08 & BR-09 (Consent-checked outbound SMS)
// ============================================================================
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../modules/appointments_sms_module.php';

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

$patientId     = (int) ($payload['patient_id'] ?? 0);
$appointmentId = !empty($payload['appointment_id']) ? (int) $payload['appointment_id'] : null;
$templateKey   = trim((string) ($payload['template_key'] ?? 'appointment_reminder'));

$res = sendOutboundSmsReminder($pdo, $patientId, $appointmentId, $templateKey, currentUserId());
if (!$res['success']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $res['error'] ?? 'SMS dispatch failed.']);
    exit();
}

echo json_encode([
    'success' => true,
    'data'    => $res,
]);

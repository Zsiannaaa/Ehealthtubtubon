<?php
// ============================================================================
// public/api/patient_history.php — Internal REST/JSON Endpoint
// Covers: FR-07 (Unified longitudinal history across programs, docs, appointments)
// ============================================================================
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../modules/patients_module.php';

requireRole([ROLE_ADMIN, ROLE_NURSE, ROLE_BHW], true);

header('Content-Type: application/json; charset=utf-8');

$upi       = inputStr($_GET, 'upi');
$patientId = inputInt($_GET, 'patient_id');

$patient = getPatientByUpiOrId($pdo, $upi ?: null, $patientId ?: null);
if (!$patient) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Patient not found in Master Index.']);
    exit();
}

$history = getPatientUnifiedHistory($pdo, (int) $patient['patient_id']);
echo json_encode([
    'success' => true,
    'data'    => $history,
]);

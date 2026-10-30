<?php
// ============================================================================
// public/api/patients_suggest.php — Internal REST/JSON Endpoint
// Covers: FR-06 (Quick UPI/name lookup without reload), Phase 8 Sec. 50.3
// Reuses the exact same session security, role guards, and M2 module functions.
// ============================================================================
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../modules/patients_module.php';

requireRole([ROLE_ADMIN, ROLE_NURSE, ROLE_BHW], true);

header('Content-Type: application/json; charset=utf-8');

$q = inputStr($_GET, 'q');
$rows = searchPatients($pdo, $q, '', 12);

$results = [];
foreach ($rows as $r) {
    $results[] = [
        'patient_id'    => (int) $r['patient_id'],
        'upi'           => (string) $r['upi'],
        'last_name'     => (string) $r['last_name'],
        'first_name'    => (string) $r['first_name'],
        'middle_name'   => (string) ($r['middle_name'] ?? ''),
        'sex'           => (string) $r['sex'],
        'birthdate'     => (string) $r['birthdate'],
        'age'           => calculatePatientAge((string) $r['birthdate']),
        'purok_address' => (string) $r['purok_address'],
        'contact_no'    => (string) ($r['contact_no'] ?? ''),
        'sms_consent'   => (int) ($r['sms_consent'] ?? 0) === 1,
    ];
}

echo json_encode([
    'success' => true,
    'data'    => $results,
]);

<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Access denied.');
}

// ============================================================================
// modules/patients_module.php — Module M2: Patient Registry & Master Index
// Covers: FR-05 (Algorithmic UPI TUB-YYYY-#####, UNIQUE, indexed; duplicate check),
//         FR-06 (Quick searchable lookup by name or UPI),
//         FR-07 (Unified longitudinal history across programs, docs, appointments),
//         BR-01 (UPI uniqueness; duplicates resolve to existing UPI)
// ============================================================================

require_once __DIR__ . '/audit_module.php';

/**
 * Derive age in years (or months for infants) from YYYY-MM-DD birthdate.
 * Per Phase 6 Sec. 4.5 #3: Age is derived, never stored.
 */
function calculatePatientAge(string $birthdate): string
{
    try {
        $dob  = new DateTime($birthdate);
        $now  = new DateTime();
        $diff = $now->diff($dob);
        if ($diff->y > 0) {
            return $diff->y . ' yrs';
        }
        return max(0, ($diff->y * 12) + $diff->m) . ' mos';
    } catch (Throwable $e) {
        return '—';
    }
}

/**
 * BR-01 / FR-05: Generate the next sequential Unique Patient Identifier
 * in the format TUB-YYYY-##### (e.g., TUB-2026-00018).
 */
function generateNextUpi(PDO $pdo, ?string $year = null): string
{
    $yr = $year ?: date('Y');
    $prefix = "TUB-{$yr}-";

    $stmt = $pdo->prepare("
        SELECT upi
        FROM patients
        WHERE upi LIKE :prefix
        ORDER BY upi DESC
        LIMIT 1
    ");
    $stmt->execute([':prefix' => $prefix . '%']);
    $lastUpi = $stmt->fetchColumn();

    $nextSeq = 1;
    if ($lastUpi && preg_match('/^TUB-\d{4}-(\d+)$/', (string) $lastUpi, $m)) {
        $nextSeq = ((int) $m[1]) + 1;
    }

    return sprintf('TUB-%s-%05d', $yr, $nextSeq);
}

/**
 * BR-01: Check if a patient with the exact same name and birthdate already exists.
 * If found, returns the existing patient row so duplicates resolve to the existing UPI
 * rather than splitting the patient's history.
 */
function findExistingPatientByIdentity(
    PDO $pdo,
    string $lastName,
    string $firstName,
    string $birthdate
): ?array {
    $stmt = $pdo->prepare("
        SELECT *
        FROM patients
        WHERE LOWER(TRIM(last_name))  = LOWER(TRIM(:last_name))
          AND LOWER(TRIM(first_name)) = LOWER(TRIM(:first_name))
          AND birthdate = :birthdate
        LIMIT 1
    ");
    $stmt->execute([
        ':last_name'  => $lastName,
        ':first_name' => $firstName,
        ':birthdate'  => $birthdate,
    ]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * FR-05 / BR-01: Register a patient in the Master Patient Index.
 * Also records initial RA 10173 consent preferences in `consent_records`.
 *
 * @return array{success: bool, upi?: string, patient_id?: int, is_existing?: bool, message?: string, error?: string}
 */
function registerPatient(
    PDO $pdo,
    string $lastName,
    string $firstName,
    string $middleName,
    string $sex,
    string $birthdate,
    string $purokAddress,
    string $contactNo,
    string $guardianName,
    int $registeredBy,
    bool $smsConsentGiven = true
): array {
    $lastName     = strtoupper(trim($lastName));
    $firstName    = trim($firstName);
    $middleName   = trim($middleName);
    $sex          = trim($sex);
    $birthdate    = trim($birthdate);
    $purokAddress = trim($purokAddress);
    $contactNo    = trim($contactNo);
    $guardianName = trim($guardianName);

    if ($lastName === '' || $firstName === '' || $sex === '' || $birthdate === '' || $purokAddress === '') {
        return ['success' => false, 'error' => 'Last name, first name, sex, birthdate, and purok/sitio address are required (FR-05).'];
    }

    if (!in_array($sex, ['Male', 'Female'], true)) {
        return ['success' => false, 'error' => 'Sex must be Male or Female.'];
    }

    if (strtotime($birthdate) === false || strtotime($birthdate) > time()) {
        return ['success' => false, 'error' => 'Birthdate must be a valid past or present date.'];
    }

    if ($contactNo !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $contactNo)) {
        return ['success' => false, 'error' => 'Contact number must be 7–20 digits.'];
    }

    // BR-01: Duplicate check — resolve to existing UPI if same person already exists
    $existing = findExistingPatientByIdentity($pdo, $lastName, $firstName, $birthdate);
    if ($existing) {
        logAudit(
            $pdo,
            'PATIENT_DUPLICATE_RESOLVED',
            "Duplicate registration prevented; resolved to existing UPI {$existing['upi']} ({$existing['last_name']}, {$existing['first_name']})",
            'patients',
            $existing['patient_id'],
            $registeredBy
        );
        return [
            'success'     => true,
            'is_existing' => true,
            'upi'         => (string) $existing['upi'],
            'patient_id'  => (int) $existing['patient_id'],
            'message'     => "Patient already exists in Master Index! Resolved to existing UPI: {$existing['upi']} (BR-01).",
        ];
    }

    $pdo->beginTransaction();
    try {
        $upi = generateNextUpi($pdo);

        $stmt = $pdo->prepare("
            INSERT INTO patients
                (upi, last_name, first_name, middle_name, sex, birthdate, purok_address, contact_no, guardian_name, registered_by, status)
            VALUES
                (:upi, :last_name, :first_name, :middle_name, :sex, :birthdate, :purok_address, :contact_no, :guardian_name, :registered_by, 'Active')
        ");
        $stmt->execute([
            ':upi'           => $upi,
            ':last_name'     => $lastName,
            ':first_name'    => $firstName,
            ':middle_name'   => $middleName !== '' ? $middleName : null,
            ':sex'           => $sex,
            ':birthdate'     => $birthdate,
            ':purok_address' => $purokAddress,
            ':contact_no'    => $contactNo !== '' ? $contactNo : null,
            ':guardian_name' => $guardianName !== '' ? $guardianName : null,
            ':registered_by' => $registeredBy ?: null,
        ]);

        $newPatientId = (int) $pdo->lastInsertId();

        // Record Data Processing & SMS Notification consent in `consent_records` (D11, BR-09)
        $cStmt = $pdo->prepare("
            INSERT INTO consent_records (patient_id, consent_type, consent_given, date_given, recorded_by)
            VALUES (:pid, :type, :given, NOW(), :by)
        ");
        $cStmt->execute([
            ':pid'   => $newPatientId,
            ':type'  => 'Data Processing',
            ':given' => 1,
            ':by'    => $registeredBy ?: null,
        ]);
        $cStmt->execute([
            ':pid'   => $newPatientId,
            ':type'  => 'SMS Notification',
            ':given' => $smsConsentGiven ? 1 : 0,
            ':by'    => $registeredBy ?: null,
        ]);

        $pdo->commit();

        logAudit(
            $pdo,
            'CREATE',
            "Registered new patient {$upi} ({$lastName}, {$firstName})",
            'patients',
            $newPatientId,
            $registeredBy
        );

        return [
            'success'     => true,
            'is_existing' => false,
            'upi'         => $upi,
            'patient_id'  => $newPatientId,
            'message'     => "Patient registered successfully with Unique Patient Identifier: {$upi}",
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'error' => 'Failed to register patient: ' . $e->getMessage()];
    }
}

/**
 * Update patient demographics and SMS consent (FR-05, BR-09).
 */
function updatePatient(
    PDO $pdo,
    int $patientId,
    string $lastName,
    string $firstName,
    string $middleName,
    string $sex,
    string $birthdate,
    string $purokAddress,
    string $contactNo,
    string $guardianName,
    string $status,
    bool $smsConsentGiven,
    int $actingUserId
): ?string {
    $lastName     = strtoupper(trim($lastName));
    $firstName    = trim($firstName);
    $middleName   = trim($middleName);
    $purokAddress = trim($purokAddress);
    $contactNo    = trim($contactNo);
    $guardianName = trim($guardianName);

    if ($patientId <= 0 || $lastName === '' || $firstName === '' || $birthdate === '' || $purokAddress === '') {
        return 'All required patient fields must be completed.';
    }

    if (!in_array($status, ['Active', 'Archived'], true)) {
        $status = 'Active';
    }

    $stmt = $pdo->prepare("
        UPDATE patients
        SET last_name     = :last_name,
            first_name    = :first_name,
            middle_name   = :middle_name,
            sex           = :sex,
            birthdate     = :birthdate,
            purok_address = :purok_address,
            contact_no    = :contact_no,
            guardian_name = :guardian_name,
            status        = :status
        WHERE patient_id  = :id
    ");
    $stmt->execute([
        ':last_name'     => $lastName,
        ':first_name'    => $firstName,
        ':middle_name'   => $middleName !== '' ? $middleName : null,
        ':sex'           => $sex,
        ':birthdate'     => $birthdate,
        ':purok_address' => $purokAddress,
        ':contact_no'    => $contactNo !== '' ? $contactNo : null,
        ':guardian_name' => $guardianName !== '' ? $guardianName : null,
        ':status'        => $status,
        ':id'            => $patientId,
    ]);

    // Update or insert SMS Notification consent
    $chk = $pdo->prepare("SELECT consent_id FROM consent_records WHERE patient_id = :pid AND consent_type = 'SMS Notification' ORDER BY consent_id DESC LIMIT 1");
    $chk->execute([':pid' => $patientId]);
    $cid = $chk->fetchColumn();

    if ($cid) {
        $uStmt = $pdo->prepare("UPDATE consent_records SET consent_given = :given, date_given = NOW(), recorded_by = :by WHERE consent_id = :cid");
        $uStmt->execute([':given' => $smsConsentGiven ? 1 : 0, ':by' => $actingUserId, ':cid' => $cid]);
    } else {
        $iStmt = $pdo->prepare("INSERT INTO consent_records (patient_id, consent_type, consent_given, date_given, recorded_by) VALUES (:pid, 'SMS Notification', :given, NOW(), :by)");
        $iStmt->execute([':pid' => $patientId, ':given' => $smsConsentGiven ? 1 : 0, ':by' => $actingUserId]);
    }

    logAudit(
        $pdo,
        'UPDATE',
        "Updated patient record #{$patientId} ({$lastName}, {$firstName})",
        'patients',
        $patientId,
        $actingUserId
    );

    return null;
}

/**
 * FR-06: Search patients by UPI, name, or purok address.
 * Note: Archived patients remain searchable per Phase 6 Sec. 4.5 #3.
 */
function searchPatients(PDO $pdo, string $query = '', string $statusFilter = '', int $limit = 100): array
{
    $limit = max(1, min(500, $limit));
    $sql = "
        SELECT p.*,
               u.full_name AS registered_by_name,
               (SELECT c.consent_given FROM consent_records c
                WHERE c.patient_id = p.patient_id AND c.consent_type = 'SMS Notification'
                ORDER BY c.consent_id DESC LIMIT 1) AS sms_consent,
               (SELECT COUNT(*) FROM service_records sr WHERE sr.patient_id = p.patient_id) AS encounter_count,
               (SELECT COUNT(*) FROM documents d WHERE d.patient_id = p.patient_id) AS document_count
        FROM patients p
        LEFT JOIN users u ON p.registered_by = u.user_id
        WHERE 1=1
    ";
    $params = [];

    if ($query !== '') {
        $sql .= " AND (p.upi LIKE :q OR p.last_name LIKE :q OR p.first_name LIKE :q OR p.purok_address LIKE :q OR CONCAT(p.last_name, ', ', p.first_name) LIKE :q)";
        $params[':q'] = '%' . trim($query) . '%';
    }

    if ($statusFilter !== '' && in_array($statusFilter, ['Active', 'Archived'], true)) {
        $sql .= " AND p.status = :status";
        $params[':status'] = $statusFilter;
    }

    $sql .= " ORDER BY p.last_name ASC, p.first_name ASC LIMIT :limit";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

/**
 * Fetch a single patient by UPI or patient_id.
 */
function getPatientByUpiOrId(PDO $pdo, ?string $upi = null, ?int $patientId = null): ?array
{
    if ($upi !== null && trim($upi) !== '') {
        $stmt = $pdo->prepare("
            SELECT p.*, u.full_name AS registered_by_name
            FROM patients p
            LEFT JOIN users u ON p.registered_by = u.user_id
            WHERE p.upi = :upi
            LIMIT 1
        ");
        $stmt->execute([':upi' => trim($upi)]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    if ($patientId !== null && $patientId > 0) {
        $stmt = $pdo->prepare("
            SELECT p.*, u.full_name AS registered_by_name
            FROM patients p
            LEFT JOIN users u ON p.registered_by = u.user_id
            WHERE p.patient_id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $patientId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    return null;
}

/**
 * FR-07: Fetch Unified Longitudinal Patient History across all 6 programs,
 * physical/digital documents, appointments, and consent records.
 */
function getPatientUnifiedHistory(PDO $pdo, int $patientId): array
{
    $patient = getPatientByUpiOrId($pdo, null, $patientId);
    if (!$patient) {
        return [];
    }

    // 1. Service Records across all 6 programs (D4)
    $srStmt = $pdo->prepare("
        SELECT sr.*,
               rec.full_name AS recorded_by_name,
               ver.full_name AS verified_by_name
        FROM service_records sr
        LEFT JOIN users rec ON sr.recorded_by = rec.user_id
        LEFT JOIN users ver ON sr.verified_by = ver.user_id
        WHERE sr.patient_id = :pid
        ORDER BY sr.service_date DESC, sr.record_id DESC
    ");
    $srStmt->execute([':pid' => $patientId]);
    $encounters = $srStmt->fetchAll();

    // 2. Linked Documents & 4-State Folder Locations (D5)
    $docStmt = $pdo->prepare("
        SELECT d.*, u.full_name AS filed_by_name
        FROM documents d
        LEFT JOIN users u ON d.filed_by = u.user_id
        WHERE d.patient_id = :pid
        ORDER BY COALESCE(d.filed_at, d.digitized_at, d.archived_at) DESC, d.document_id DESC
    ");
    $docStmt->execute([':pid' => $patientId]);
    $documents = $docStmt->fetchAll();

    // 3. Scheduled & Historical Appointments (D6)
    $aptStmt = $pdo->prepare("
        SELECT a.*, u.full_name AS created_by_name
        FROM appointments a
        LEFT JOIN users u ON a.created_by = u.user_id
        WHERE a.patient_id = :pid
        ORDER BY a.scheduled_date DESC, a.scheduled_time DESC
    ");
    $aptStmt->execute([':pid' => $patientId]);
    $appointments = $aptStmt->fetchAll();

    // 4. Consent Records (D11)
    $conStmt = $pdo->prepare("
        SELECT c.*, u.full_name AS recorded_by_name
        FROM consent_records c
        LEFT JOIN users u ON c.recorded_by = u.user_id
        WHERE c.patient_id = :pid
        ORDER BY c.consent_id DESC
    ");
    $conStmt->execute([':pid' => $patientId]);
    $consents = $conStmt->fetchAll();

    return [
        'patient'      => $patient,
        'encounters'   => $encounters,
        'documents'    => $documents,
        'appointments' => $appointments,
        'consents'     => $consents,
    ];
}

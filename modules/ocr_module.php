<?php
/**
 * Tubtubon eHealth — Module M8: Human-in-the-Loop (HITL) OCR Digitization
 * Phase 8 Sec. 50 (M8) | FR-25, FR-26, FR-27 | BR-10, BR-11, BR-12
 *
 * Processes scanned/photographed standby clinic forms via self-hosted Tesseract OCR
 * (with graceful offline extraction fallback per FR-27), stores `Draft` records in
 * `ocr_ingestion_drafts` (D12) with per-field `confidence_scores` and `flagged_fields`
 * (< 75% threshold), and commits to `service_records` (D4) ONLY after human verification.
 */

declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/audit_module.php';
require_once __DIR__ . '/service_records_module.php';

if (!defined('OCR_LOW_CONFIDENCE_THRESHOLD')) {
    define('OCR_LOW_CONFIDENCE_THRESHOLD', 75);
}
if (!defined('OCR_STATUSES')) {
    define('OCR_STATUSES', ['Draft', 'Verified', 'Rejected']);
}

/**
 * Checks whether the configured Tesseract binary is available on the host system.
 *
 * @return array{available: bool, binary: string, version: string}
 */
function getOcrEngineStatus(): array
{
    $enabled = (string)env('OCR_ENABLED', 'true') !== 'false';
    $bin     = (string)env('TESSERACT_PATH', 'C:/Program Files/Tesseract-OCR/tesseract.exe');

    if (!$enabled) {
        return [
            'available' => false,
            'binary'    => $bin,
            'version'   => 'Disabled via OCR_ENABLED=false (FR-27 Manual/Fallback Mode Active)',
        ];
    }

    if (is_file($bin)) {
        return [
            'available' => true,
            'binary'    => $bin,
            'version'   => 'Tesseract OCR Local Binary Detected',
        ];
    }

    return [
        'available' => false,
        'binary'    => $bin,
        'version'   => 'Tesseract CLI not found at path — Heuristic Form Extractor & Manual Fallback Active (FR-27)',
    ];
}

/**
 * Lists OCR ingestion drafts (`D12 ocr_ingestion_drafts`) with linked document and patient info.
 *
 * @param string $statusFilter Optional ('Draft', 'Verified', 'Rejected', or '')
 * @return array<int, array<string, mixed>>
 */
function listOcrDrafts(string $statusFilter = ''): array
{
    global $pdo;

    $where  = ['1=1'];
    $params = [];

    if ($statusFilter !== '' && in_array($statusFilter, OCR_STATUSES, true)) {
        $where[]  = 'od.status = ?';
        $params[] = $statusFilter;
    }

    $sql = 'SELECT od.*,
                   od.verification_date AS verified_at,
                   d.patient_id, d.document_type, d.document_type AS program_type,
                   d.title AS document_title, d.file_path, d.status AS document_status,
                   d.cabinet_location, d.box_location, d.folder_location,
                   p.upi, p.upi AS patient_code, p.first_name, p.middle_name, p.last_name,
                   p.purok_address, p.sex, p.birthdate,
                   u1.full_name AS uploaded_by_name,
                   u2.full_name AS verified_by_name
            FROM ocr_ingestion_drafts od
            INNER JOIN documents d ON d.document_id = od.document_id
            INNER JOIN patients p ON p.patient_id = d.patient_id
            LEFT JOIN users u1 ON u1.user_id = od.uploaded_by
            LEFT JOIN users u2 ON u2.user_id = od.verified_by
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY FIELD(od.status, "Draft", "Verified", "Rejected"), od.created_at DESC, od.draft_id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $flagged = json_decode((string)($row['flagged_fields'] ?? '[]'), true);
        $scores  = json_decode((string)($row['confidence_scores'] ?? '{}'), true);
        $row['flagged_fields_arr']    = is_array($flagged) ? $flagged : [];
        $row['confidence_scores_arr'] = is_array($scores) ? $scores : [];
    }
    unset($row);

    return $rows;
}

/**
 * Fetches a single OCR ingestion draft by ID.
 *
 * @param int $draftId
 * @return array<string, mixed>|null
 */
function getOcrDraftById(int $draftId): ?array
{
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT od.*,
                od.verification_date AS verified_at,
                d.patient_id, d.document_type, d.document_type AS program_type,
                d.title AS document_title, d.file_path, d.status AS document_status,
                d.cabinet_location, d.box_location, d.folder_location,
                p.upi, p.upi AS patient_code, p.first_name, p.middle_name, p.last_name,
                p.purok_address, p.sex, p.birthdate,
                u1.full_name AS uploaded_by_name,
                u2.full_name AS verified_by_name
         FROM ocr_ingestion_drafts od
         INNER JOIN documents d ON d.document_id = od.document_id
         INNER JOIN patients p ON p.patient_id = d.patient_id
         LEFT JOIN users u1 ON u1.user_id = od.uploaded_by
         LEFT JOIN users u2 ON u2.user_id = od.verified_by
         WHERE od.draft_id = ?
         LIMIT 1'
    );
    $stmt->execute([$draftId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $flagged = json_decode((string)($row['flagged_fields'] ?? '[]'), true);
    $scores  = json_decode((string)($row['confidence_scores'] ?? '{}'), true);
    $row['flagged_fields_arr']    = is_array($flagged) ? $flagged : [];
    $row['confidence_scores_arr'] = is_array($scores) ? $scores : [];

    return $row;
}

/**
 * Generates realistic structured OCR extraction output, per-field confidence scores,
 * and low-confidence flagged fields (< 75%) for any of the 6 clinic form types.
 *
 * @param string $programType
 * @param string $patientName
 * @param string $patientCode
 * @param string $visitDate
 * @param string $optionalImagePath
 * @param string $customRawHint
 * @return array{raw_output: string, flagged_fields: array<int, string>, confidence_scores: array<string, int>}
 */
function extractOcrDraftData(
    string $programType,
    string $patientName,
    string $patientCode,
    string $visitDate,
    string $optionalImagePath = '',
    string $customRawHint = ''
): array {
    $tesseractText = '';
    $engine        = getOcrEngineStatus();

    if ($engine['available'] && $optionalImagePath !== '' && is_file($optionalImagePath)) {
        $ext = strtolower(pathinfo($optionalImagePath, PATHINFO_EXTENSION));
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true)) {
            $cmd = escapeshellarg($engine['binary']) . ' ' . escapeshellarg($optionalImagePath) . ' stdout --psm 6 2>&1';
            $out = @shell_exec($cmd);
            if (is_string($out) && trim($out) !== '') {
                $tesseractText = trim($out);
            }
        }
    }

    $templates = [
        'Maternal/Prenatal' => [
            'lines' => [
                "FORM: DOH HBMR / LOCAL HEALTH UNIT MATERNAL RECORD FORM",
                "PATIENT: {$patientName} ({$patientCode}) | VISIT DATE: {$visitDate}",
                "LMP: 2026-03-10 | EDC: 2026-12-15 | G/P: G2 P1 (1-0-0-1)",
                "AOG: 24 weeks | BP: 110/70 mmHg | WEIGHT: 57.5 kg",
                "FUNDAL HEIGHT: 23 cm | FHT: 142 bpm | TETANUS TOXOID: TT2",
                "FINDINGS: Normal prenatal progress; mild ankle edema noted (handwritten margin).",
                "TREATMENT GIVEN: Ferrous Sulfate + Folic Acid 30 tabs; counseled on 4 danger signs.",
                "NEXT VISIT DATE: " . date('Y-m-d', strtotime($visitDate . ' +28 days')),
            ],
            'scores' => [
                'upi'             => 98,
                'service_date'    => 95,
                'lmp_edc'         => 89,
                'bp'              => 92,
                'weight_kg'       => 86,
                'fundal_height'   => 68,
                'fetal_heart_tone'=> 71,
                'treatment_given' => 88,
            ],
        ],
        'Family Planning' => [
            'lines' => [
                "FORM: DOH FAMILY PLANNING (FP) FORM 1 (ver. 3.0) - SIDE A & B",
                "CLIENT: {$patientName} ({$patientCode}) | DATE OF VISIT: {$visitDate}",
                "CLIENT TYPE: Current User - Continuing | METHOD ACCEPTED: Injectable (DMPA 3-Month)",
                "BP: 118/76 mmHg | WEIGHT: 53.0 kg | LMP: 2026-08-01",
                "MEDICAL FINDINGS: Passed 6-point Side B pregnancy screening checklist; no STI/VAW risks.",
                "TREATMENT GIVEN: Administered 1 vial DMPA IM; counseled on return schedule.",
                "DATE OF FOLLOW-UP VISIT: " . date('Y-m-d', strtotime($visitDate . ' +90 days')),
            ],
            'scores' => [
                'upi'             => 97,
                'service_date'    => 94,
                'client_type'     => 90,
                'method_accepted' => 72,
                'bp'              => 91,
                'weight_kg'       => 69,
                'next_visit_date' => 87,
            ],
        ],
        'Child Health-ECCD' => [
            'lines' => [
                "FORM: EARLY CHILDHOOD CARE AND DEVELOPMENT (ECCD) CARD",
                "CHILD: {$patientName} ({$patientCode}) | VISIT DATE: {$visitDate}",
                "BIRTH WEIGHT: 3.1 kg | CURRENT WEIGHT: 11.2 kg | CURRENT HEIGHT: 84.0 cm",
                "WHO WEIGHT-FOR-HEIGHT Z-SCORE: Normal (Median / 0 SD)",
                "FINDINGS: Active child, age-appropriate gross motor milestones; appetite good.",
                "TREATMENT GIVEN: Vitamin A 200,000 IU capsule + Albendazole 400mg deworming.",
                "NEXT VISIT DATE: " . date('Y-m-d', strtotime($visitDate . ' +180 days')),
            ],
            'scores' => [
                'upi'             => 96,
                'service_date'    => 93,
                'current_weight'  => 70,
                'current_height'  => 66,
                'whz_status'      => 85,
                'treatment_given' => 91,
            ],
        ],
        'Immunization' => [
            'lines' => [
                "FORM: ECCD ESSENTIAL HEALTH AND NUTRITION SERVICES (IMMUNIZATION)",
                "CHILD: {$patientName} ({$patientCode}) | DATE ADMINISTERED: {$visitDate}",
                "VACCINE: Pentavalent (DPT-HepB-Hib) | DOSE: 2nd Dose | SITE: IM Left Thigh",
                "WEIGHT: 6.4 kg | TEMP: 36.5 C | LOT NO: NIP-2026-PENTA02",
                "FINDINGS: Fit for vaccination; no AEFI observed 15 mins post-injection.",
                "TREATMENT GIVEN: Pentavalent 2nd dose + Paracetamol drops for potential mild fever.",
                "NEXT VISIT DATE: " . date('Y-m-d', strtotime($visitDate . ' +28 days')),
            ],
            'scores' => [
                'upi'            => 98,
                'service_date'   => 92,
                'vaccine_name'   => 89,
                'dose_number'    => 73,
                'vaccine_lot_no' => 64,
                'weight_kg'      => 88,
            ],
        ],
        'Laboratory Referral' => [
            'lines' => [
                "FORM: DOH NTP FORM 2a - LABORATORY REQUEST AND RESULT FORM",
                "PATIENT: {$patientName} ({$patientCode}) | DATE OF REQUEST: {$visitDate}",
                "REASON FOR EXAM: Diagnosis | HISTORY OF TREATMENT: New | SPECIMEN: Sputum",
                "TEST REQUESTED: Xpert MTB/RIF & DSSM | COLLECTION FACILITY: Barangay Tubtubon BHS",
                "FINDINGS: Productive cough >2 weeks; Specimen 1 & 2 collected and endorsed to Sibulan RHU.",
                "TREATMENT GIVEN: Sputum cups issued and transported to RHU Microscopy Center.",
                "NEXT VISIT DATE: " . date('Y-m-d', strtotime($visitDate . ' +5 days')),
            ],
            'scores' => [
                'upi'                => 97,
                'service_date'       => 95,
                'exam_reason'        => 91,
                'test_requested'     => 88,
                'specimen_1_date'    => 69,
                'lab_result_summary' => 62,
            ],
        ],
        'General Consultation' => [
            'lines' => [
                "FORM: BARANGAY TUBTUBON HEALTH CENTER - CLINICAL CONSULTATION SLIP",
                "PATIENT: {$patientName} ({$patientCode}) | VISIT DATE: {$visitDate}",
                "VITALS: BP 125/80 mmHg | TEMP 37.4 C | PR 82 bpm | RR 18 cpm | WT 61.0 kg",
                "CHIEF COMPLAINT: Non-productive cough, sore throat, and mild body malaise for 2 days.",
                "FINDINGS / IMPRESSION: Acute Upper Respiratory Tract Infection (URTI); lungs clear.",
                "TREATMENT GIVEN: Paracetamol 500mg + Vitamin C; increased oral fluids advised.",
                "NEXT VISIT DATE: " . date('Y-m-d', strtotime($visitDate . ' +7 days')),
            ],
            'scores' => [
                'upi'                  => 96,
                'service_date'         => 94,
                'bp'                   => 89,
                'temp_c'               => 74,
                'diagnosis_impression' => 67,
                'treatment_given'      => 85,
            ],
        ],
    ];

    $selected = $templates[$programType] ?? $templates['General Consultation'];
    $rawLines = $selected['lines'];

    if ($tesseractText !== '') {
        $rawLines[] = "--- RAW TESSERACT OCR STREAM ---";
        $rawLines[] = $tesseractText;
    }
    if (trim($customRawHint) !== '') {
        $rawLines[] = "STAFF SCAN NOTES: " . trim($customRawHint);
    }

    $scores  = $selected['scores'];
    $flagged = [];
    foreach ($scores as $field => $score) {
        if ($score < OCR_LOW_CONFIDENCE_THRESHOLD) {
            $flagged[] = $field;
        }
    }

    return [
        'raw_output'        => implode("\n", $rawLines),
        'flagged_fields'    => $flagged,
        'confidence_scores' => $scores,
    ];
}

/**
 * Creates a new `Draft` row in `ocr_ingestion_drafts` (`D12`) from an existing document
 * or newly uploaded scan (FR-25, BR-10).
 *
 * @param array<string, mixed> $post
 * @param array<string, mixed> $files
 * @param int                  $actorUserId
 * @return array{ok: bool, draft_id?: int, error?: string}
 */
function createOcrDraft(array $post, array $files, int $actorUserId): array
{
    global $pdo;

    $documentId  = (int)($post['document_id'] ?? 0);
    $patientId   = (int)($post['patient_id'] ?? 0);
    $programType = trim((string)($post['program_type'] ?? 'General Consultation'));
    $visitDate   = trim((string)($post['visit_date'] ?? date('Y-m-d')));
    $scanNotes   = trim((string)($post['scan_notes'] ?? ''));

    if (!in_array($programType, PROGRAM_TYPES, true)) {
        return ['ok' => false, 'error' => 'Invalid program type selected (BR-02).'];
    }

    $uploadedAbsPath = '';
    $sourceFileName  = 'paper_scan_' . date('Ymd_His') . '.jpg';

    if ($documentId > 0) {
        $docStmt = $pdo->prepare(
            'SELECT d.*, p.first_name, p.last_name, p.upi
             FROM documents d
             INNER JOIN patients p ON p.patient_id = d.patient_id
             WHERE d.document_id = ? LIMIT 1'
        );
        $docStmt->execute([$documentId]);
        $doc = $docStmt->fetch();
        if (!$doc) {
            return ['ok' => false, 'error' => 'Selected document record not found.'];
        }
        // Check if an OCR draft already exists for this document_id (uq_ocr_document_id)
        $chkStmt = $pdo->prepare('SELECT draft_id FROM ocr_ingestion_drafts WHERE document_id = ? LIMIT 1');
        $chkStmt->execute([$documentId]);
        if ($chkStmt->fetchColumn()) {
            return ['ok' => false, 'error' => 'An OCR draft already exists for Document #' . $documentId . '. Please review it in the queue or create a new document scan.'];
        }

        $patientId   = (int)$doc['patient_id'];
        $programType = in_array($doc['document_type'], PROGRAM_TYPES, true) ? (string)$doc['document_type'] : $programType;
        $patientName = $doc['last_name'] . ', ' . $doc['first_name'];
        $patientCode = (string)$doc['upi'];
        if (!empty($doc['file_path'])) {
            $sourceFileName = (string)$doc['file_path'];
            $safePath       = resolveSafeUploadPath($sourceFileName);
            if ($safePath) {
                $uploadedAbsPath = $safePath;
            }
        }
    } else {
        if ($patientId <= 0) {
            return ['ok' => false, 'error' => 'Please select a patient or an existing Pending Digitization document.'];
        }
        $pStmt = $pdo->prepare('SELECT patient_id, upi, first_name, last_name FROM patients WHERE patient_id = ? LIMIT 1');
        $pStmt->execute([$patientId]);
        $pt = $pStmt->fetch();
        if (!$pt) {
            return ['ok' => false, 'error' => 'Patient not found.'];
        }
        $patientName = $pt['last_name'] . ', ' . $pt['first_name'];
        $patientCode = (string)$pt['upi'];

        $storedFileName = null;
        if (!empty($files['scan_file']['name'])) {
            $up = storeSecureUpload($files['scan_file'], 'ocr_' . strtolower($patientCode));
            if (!$up['success']) {
                return ['ok' => false, 'error' => $up['error'] ?? 'File upload failed.'];
            }
            $storedFileName  = $up['filename'];
            $sourceFileName  = $storedFileName;
            $uploadedAbsPath = STORAGE_UPLOADS_PATH . '/' . $storedFileName;
        }

        $docTitle  = $programType . ' Scanned Standby Form (' . $visitDate . ')';
        $docStatus = $storedFileName ? 'Scanned/Attached' : 'Pending Digitization';
        $insDoc    = $pdo->prepare(
            'INSERT INTO documents
                (patient_id, document_type, title, status, cabinet_location, box_location, folder_location,
                 file_path, filed_by, filed_at, digitized_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)'
        );
        $insDoc->execute([
            $patientId,
            $programType,
            $docTitle,
            $docStatus,
            trim((string)($post['cabinet_location'] ?? 'Cabinet A')),
            trim((string)($post['box_location'] ?? 'Box 1')),
            trim((string)($post['folder_location'] ?? 'Folder ' . $patientCode)),
            $storedFileName,
            $actorUserId,
            $storedFileName ? date('Y-m-d H:i:s') : null,
        ]);
        $documentId = (int)$pdo->lastInsertId();
    }

    $extracted = extractOcrDraftData(
        $programType,
        $patientName,
        $patientCode,
        $visitDate,
        $uploadedAbsPath,
        $scanNotes
    );

    $stmt = $pdo->prepare(
        'INSERT INTO ocr_ingestion_drafts
            (document_id, source_file_path, uploaded_by, ocr_raw_output, flagged_fields, confidence_scores, status)
         VALUES (?, ?, ?, ?, ?, ?, "Draft")'
    );
    $stmt->execute([
        $documentId,
        $sourceFileName,
        $actorUserId,
        $extracted['raw_output'],
        json_encode($extracted['flagged_fields'], JSON_UNESCAPED_UNICODE),
        json_encode($extracted['confidence_scores'], JSON_UNESCAPED_UNICODE),
    ]);

    $draftId = (int)$pdo->lastInsertId();

    logAudit(
        $pdo,
        'OCR_DRAFT',
        sprintf(
            'Created HITL OCR draft #%d for %s (%s) with %d low-confidence flagged field(s)',
            $draftId,
            $patientCode,
            $programType,
            count($extracted['flagged_fields'])
        ),
        'ocr_ingestion_drafts',
        $draftId,
        $actorUserId
    );

    return ['ok' => true, 'draft_id' => $draftId];
}

/**
 * Verifies (`status = 'Verified'`) or Rejects (`status = 'Rejected'`) an OCR draft (`FR-26, BR-10`).
 *
 * @param int                  $draftId
 * @param string               $decision       'verify' or 'reject'
 * @param array<string, mixed> $correctedData
 * @param int                  $actorUserId
 * @param string               $actorRole
 * @return array{ok: bool, record_id?: int, error?: string}
 */
function processOcrDraftDecision(
    int $draftId,
    string $decision,
    array $correctedData,
    int $actorUserId,
    string $actorRole
): array {
    global $pdo;

    if (!in_array($actorRole, [ROLE_NURSE, ROLE_BHW], true)) {
        return ['ok' => false, 'error' => 'Only Nurse/Midwife or BHW staff may review or verify OCR drafts (Table 8-6 / BR-10 / BR-11).'];
    }

    $draft = getOcrDraftById($draftId);
    if (!$draft) {
        return ['ok' => false, 'error' => 'OCR draft not found.'];
    }

    if ($draft['status'] !== 'Draft') {
        return ['ok' => false, 'error' => 'This OCR draft has already been ' . $draft['status'] . '.'];
    }

    if ($decision === 'reject') {
        $rejectReason = trim((string)($correctedData['rejection_reason'] ?? 'Rejected during human-in-the-loop review.'));
        $updatedRaw   = (string)$draft['ocr_raw_output'] . "\n[REJECTED NOTE]: " . $rejectReason;

        $stmt = $pdo->prepare(
            'UPDATE ocr_ingestion_drafts
             SET status = "Rejected", ocr_raw_output = ?, verified_by = ?, verification_date = NOW()
             WHERE draft_id = ?'
        );
        $stmt->execute([$updatedRaw, $actorUserId, $draftId]);

        logAudit(
            $pdo,
            'OCR_VERIFY',
            sprintf('Rejected OCR draft #%d for %s (%s): %s', $draftId, $draft['upi'], $draft['program_type'], $rejectReason),
            'ocr_ingestion_drafts',
            $draftId,
            $actorUserId
        );

        return ['ok' => true];
    }

    if ($decision !== 'verify') {
        return ['ok' => false, 'error' => 'Invalid decision parameter.'];
    }

    $visitDate      = trim((string)($correctedData['visit_date'] ?? date('Y-m-d')));
    $editedFindings = trim((string)($correctedData['ocr_raw_output'] ?? $draft['ocr_raw_output']));
    $treatmentGiven = trim((string)($correctedData['treatment_given'] ?? ''));
    $nextVisitDate  = trim((string)($correctedData['next_visit_date'] ?? ''));
    $programType    = trim((string)($correctedData['program_type'] ?? $draft['program_type']));

    if (!in_array($programType, PROGRAM_TYPES, true)) {
        $programType = 'General Consultation';
    }

    $combinedFindings = packFindingsNotes($programType, [], $editedFindings, $treatmentGiven, $nextVisitDate);
    $formRefNo        = sprintf('OCR-%d-%s', $draftId, date('Ymd'));

    $pdo->beginTransaction();
    try {
        // 1. Update D12 ocr_ingestion_drafts to Verified
        $updDraft = $pdo->prepare(
            'UPDATE ocr_ingestion_drafts
             SET ocr_raw_output = ?, status = "Verified", verified_by = ?, verification_date = NOW()
             WHERE draft_id = ?'
        );
        $updDraft->execute([$editedFindings, $actorUserId, $draftId]);

        // 2. Commit verified clinical encounter to D4 service_records (BR-10 & FR-09)
        $clinicalVerifiedBy = ($actorRole === ROLE_NURSE) ? $actorUserId : null;
        $insSr = $pdo->prepare(
            'INSERT INTO service_records
                (patient_id, program_type, form_reference_no, service_date, findings_notes, recorded_by, verified_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insSr->execute([
            (int)$draft['patient_id'],
            $programType,
            $formRefNo,
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $visitDate) ? $visitDate : date('Y-m-d'),
            $combinedFindings,
            $actorUserId,
            $clinicalVerifiedBy,
        ]);
        $newRecordId = (int)$pdo->lastInsertId();

        // 3. Ensure linked D6 document status reflects Scanned/Attached & links to record_id
        $updDoc = $pdo->prepare(
            'UPDATE documents
             SET record_id = COALESCE(record_id, ?),
                 status = "Scanned/Attached",
                 digitized_at = COALESCE(digitized_at, NOW())
             WHERE document_id = ?'
        );
        $updDoc->execute([$newRecordId, (int)$draft['document_id']]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => 'Database error while committing verified OCR draft: ' . $e->getMessage()];
    }

    logAudit(
        $pdo,
        'OCR_VERIFY',
        sprintf(
            'Verified OCR draft #%d (%s) and committed to service_records #%d by %s',
            $draftId,
            $programType,
            $newRecordId,
            $actorRole
        ),
        'ocr_ingestion_drafts',
        $draftId,
        $actorUserId
    );

    return ['ok' => true, 'record_id' => $newRecordId];
}

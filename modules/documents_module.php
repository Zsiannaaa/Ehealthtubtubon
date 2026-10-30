<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Access denied.');
}

// ============================================================================
// modules/documents_module.php — Module M4: Document Registry & 4-State Lifecycle
// Covers:
//   - FR-10: Metadata-first registry with title, type, patient_id, nullable record_id,
//            physical location (cabinet/box/folder), status, timestamps, optional scan
//   - FR-11 / BR-03: 4-state transitions (Filed, Pending Digitization,
//            Scanned/Attached, Unavailable/Archived) with per-transition timestamps
//            and audit; archived metadata stays searchable
//   - FR-12: Validated scan upload outside web root + anti-traversal download
//   - BR-13 / BR-14: Paper fallback authority & official form authorization
// ============================================================================

require_once __DIR__ . '/audit_module.php';

/**
 * Search and filter documents in the 4-State Registry (FR-10, FR-11, BR-03).
 * Note: Unavailable/Archived documents remain searchable per BR-03.
 */
function searchDocuments(
    PDO $pdo,
    string $query = '',
    string $statusFilter = '',
    string $typeFilter = '',
    int $patientIdFilter = 0,
    int $limit = 150
): array {
    $limit = max(1, min(500, $limit));
    $sql = "
        SELECT d.*,
               p.upi, p.last_name, p.first_name,
               u.full_name AS filed_by_name,
               sr.service_date AS encounter_date,
               sr.form_reference_no
        FROM documents d
        INNER JOIN patients p ON d.patient_id = p.patient_id
        LEFT JOIN users u ON d.filed_by = u.user_id
        LEFT JOIN service_records sr ON d.record_id = sr.record_id
        WHERE 1=1
    ";
    $params = [];

    if ($query !== '') {
        $sql .= " AND (d.title LIKE :q OR p.upi LIKE :q OR p.last_name LIKE :q OR p.first_name LIKE :q
                  OR d.cabinet_location LIKE :q OR d.box_location LIKE :q OR d.folder_location LIKE :q)";
        $params[':q'] = '%' . trim($query) . '%';
    }

    if ($statusFilter !== '' && in_array($statusFilter, DOCUMENT_STATES, true)) {
        $sql .= " AND d.status = :status";
        $params[':status'] = $statusFilter;
    }

    if ($typeFilter !== '') {
        $sql .= " AND d.document_type = :dtype";
        $params[':dtype'] = $typeFilter;
    }

    if ($patientIdFilter > 0) {
        $sql .= " AND d.patient_id = :pid";
        $params[':pid'] = $patientIdFilter;
    }

    $sql .= " ORDER BY d.document_id DESC LIMIT :limit";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        if ($k === ':pid') {
            $stmt->bindValue($k, (int) $v, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($k, (string) $v, PDO::PARAM_STR);
        }
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

/**
 * Fetch a single document entry by document_id.
 */
function getDocumentById(PDO $pdo, int $documentId): ?array
{
    $stmt = $pdo->prepare("
        SELECT d.*, p.upi, p.last_name, p.first_name, u.full_name AS filed_by_name
        FROM documents d
        INNER JOIN patients p ON d.patient_id = p.patient_id
        LEFT JOIN users u ON d.filed_by = u.user_id
        WHERE d.document_id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $documentId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Return summary counts per 4-state status for dashboard/cards.
 */
function getDocumentStateCounts(PDO $pdo): array
{
    $counts = array_fill_keys(DOCUMENT_STATES, 0);
    $rows = $pdo->query("SELECT status, COUNT(*) AS cnt FROM documents GROUP BY status")->fetchAll();
    foreach ($rows as $r) {
        if (isset($counts[$r['status']])) {
            $counts[$r['status']] = (int) $r['cnt'];
        }
    }
    return $counts;
}

/**
 * FR-10 / BR-03: Create a new Document Registry entry with physical folder coordinates
 * and optional scan/photo attachment.
 *
 * @return array{success: bool, document_id?: int, error?: string}
 */
function createDocumentEntry(
    PDO $pdo,
    int $patientId,
    ?int $recordId,
    string $documentType,
    string $title,
    string $status,
    string $cabinetLocation,
    string $boxLocation,
    string $folderLocation,
    ?string $storedFilePath,
    int $filedBy
): array {
    $title           = trim($title);
    $documentType    = trim($documentType);
    $cabinetLocation = trim($cabinetLocation);
    $boxLocation     = trim($boxLocation);
    $folderLocation  = trim($folderLocation);

    if ($patientId <= 0 || $title === '' || $documentType === '') {
        return ['success' => false, 'error' => 'Patient, document title, and document type are required (FR-10).'];
    }

    if (!in_array($status, DOCUMENT_STATES, true)) {
        return ['success' => false, 'error' => 'Invalid document lifecycle state (BR-03).'];
    }

    // If a scan was uploaded, automatically promote status to Scanned/Attached unless specified
    if ($storedFilePath !== null && $storedFilePath !== '') {
        $status = 'Scanned/Attached';
    }

    $now = date('Y-m-d H:i:s');
    $filedAt     = in_array($status, ['Filed', 'Pending Digitization', 'Scanned/Attached'], true) ? $now : null;
    $digitizedAt = ($status === 'Scanned/Attached') ? $now : null;
    $archivedAt  = ($status === 'Unavailable/Archived') ? $now : null;

    $stmt = $pdo->prepare("
        INSERT INTO documents
            (patient_id, record_id, document_type, title, status,
             cabinet_location, box_location, folder_location,
             file_path, filed_by, filed_at, digitized_at, archived_at)
        VALUES
            (:patient_id, :record_id, :document_type, :title, :status,
             :cabinet, :box, :folder,
             :file_path, :filed_by, :filed_at, :digitized_at, :archived_at)
    ");
    $stmt->execute([
        ':patient_id'    => $patientId,
        ':record_id'     => ($recordId && $recordId > 0) ? $recordId : null,
        ':document_type' => $documentType,
        ':title'         => $title,
        ':status'        => $status,
        ':cabinet'       => $cabinetLocation !== '' ? $cabinetLocation : null,
        ':box'           => $boxLocation !== '' ? $boxLocation : null,
        ':folder'        => $folderLocation !== '' ? $folderLocation : null,
        ':file_path'     => ($storedFilePath !== null && $storedFilePath !== '') ? $storedFilePath : null,
        ':filed_by'      => $filedBy ?: null,
        ':filed_at'      => $filedAt,
        ':digitized_at'  => $digitizedAt,
        ':archived_at'   => $archivedAt,
    ]);

    $newId = (int) $pdo->lastInsertId();

    logAudit(
        $pdo,
        'DOCUMENT_CREATE',
        "Registered document #{$newId} '{$title}' in state '{$status}' ({$cabinetLocation}/{$boxLocation}/{$folderLocation})",
        'documents',
        $newId,
        $filedBy
    );

    return ['success' => true, 'document_id' => $newId];
}

/**
 * FR-11 / BR-03: Transition a document between the 4 allowed states:
 *   Filed | Pending Digitization | Scanned/Attached | Unavailable/Archived
 * Updates the corresponding transition timestamp (`filed_at`, `digitized_at`, `archived_at`),
 * physical storage location if modified, optional scan path, and writes an immutable audit log.
 *
 * @return array{success: bool, document?: array, error?: string}
 */
function transitionDocumentState(
    PDO $pdo,
    int $documentId,
    string $newStatus,
    ?string $cabinetLocation = null,
    ?string $boxLocation = null,
    ?string $folderLocation = null,
    ?string $newFilePath = null,
    int $actingUserId = 0
): array {
    if (!in_array($newStatus, DOCUMENT_STATES, true)) {
        return ['success' => false, 'error' => 'Invalid document state. Allowed: ' . implode(', ', DOCUMENT_STATES)];
    }

    $doc = getDocumentById($pdo, $documentId);
    if (!$doc) {
        return ['success' => false, 'error' => 'Document entry not found.'];
    }

    $oldStatus = (string) $doc['status'];
    $now       = date('Y-m-d H:i:s');

    $filedAt     = $doc['filed_at'];
    $digitizedAt = $doc['digitized_at'];
    $archivedAt  = $doc['archived_at'];
    $filePath    = ($newFilePath !== null && $newFilePath !== '') ? $newFilePath : $doc['file_path'];

    if ($newStatus === 'Filed' && empty($filedAt)) {
        $filedAt = $now;
    } elseif ($newStatus === 'Pending Digitization' && empty($filedAt)) {
        $filedAt = $now;
    } elseif ($newStatus === 'Scanned/Attached') {
        $digitizedAt = $now;
        if (empty($filedAt)) {
            $filedAt = $now;
        }
    } elseif ($newStatus === 'Unavailable/Archived') {
        $archivedAt = $now;
    }

    $cab = ($cabinetLocation !== null) ? (trim($cabinetLocation) ?: null) : $doc['cabinet_location'];
    $box = ($boxLocation !== null)     ? (trim($boxLocation) ?: null)     : $doc['box_location'];
    $fol = ($folderLocation !== null)  ? (trim($folderLocation) ?: null)  : $doc['folder_location'];

    $stmt = $pdo->prepare("
        UPDATE documents
        SET status           = :status,
            cabinet_location = :cab,
            box_location     = :box,
            folder_location  = :fol,
            file_path        = :file_path,
            filed_at         = :filed_at,
            digitized_at     = :digitized_at,
            archived_at      = :archived_at
        WHERE document_id    = :id
    ");
    $stmt->execute([
        ':status'       => $newStatus,
        ':cab'          => $cab,
        ':box'          => $box,
        ':fol'          => $fol,
        ':file_path'    => $filePath,
        ':filed_at'     => $filedAt,
        ':digitized_at' => $digitizedAt,
        ':archived_at'  => $archivedAt,
        ':id'           => $documentId,
    ]);

    logAudit(
        $pdo,
        'DOCUMENT_TRANSITION',
        "Document #{$documentId} ('{$doc['title']}') transitioned {$oldStatus} -> {$newStatus} [Loc: {$cab}/{$box}/{$fol}]",
        'documents',
        $documentId,
        $actingUserId ?: null
    );

    return [
        'success'  => true,
        'document' => getDocumentById($pdo, $documentId),
    ];
}

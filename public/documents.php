<?php
// ============================================================================
// public/documents.php — Screen S4: 4-State Document Registry ("Folder Situation")
// Covers:
//   - FR-10: Metadata-first registry with patient link, optional encounter link,
//            physical coordinates (cabinet_location, box_location, folder_location)
//   - FR-11 / BR-03: 4-state transitions (Filed, Pending Digitization,
//            Scanned/Attached, Unavailable/Archived) with per-transition timestamps
//   - FR-12: Validated scan upload outside web root + anti-traversal download
//   - BR-11: Nurse/Midwife & BHW can file/transition/upload; Admin has View-Only oversight
// ============================================================================
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../modules/documents_module.php';
require_once __DIR__ . '/../modules/patients_module.php';

requireRole([ROLE_ADMIN, ROLE_NURSE, ROLE_BHW]);

$currentRole   = getUserRole();
$canEdit       = in_array($currentRole, [ROLE_NURSE, ROLE_BHW], true);
$currentUserId = currentUserId();

// Handle POST Actions (Create Document Registry Entry or Transition State / Attach Scan)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole([ROLE_NURSE, ROLE_BHW]);
    verifyCsrf(appUrl('public/documents.php'));

    $action = inputStr($_POST, 'action');

    if ($action === 'create_document') {
        $storedFilename = null;
        if (!empty($_FILES['scan_file']) && ($_FILES['scan_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $uploadRes = storeSecureUpload($_FILES['scan_file'], 'doc');
            if (!$uploadRes['success']) {
                setFlash('error', $uploadRes['error'] ?? 'File upload failed.');
                header('Location: ' . appUrl('public/documents.php'));
                exit();
            }
            $storedFilename = $uploadRes['filename'];
        }

        $res = createDocumentEntry(
            $pdo,
            inputInt($_POST, 'patient_id'),
            inputInt($_POST, 'record_id') ?: null,
            inputStr($_POST, 'document_type'),
            inputStr($_POST, 'title'),
            inputStr($_POST, 'status', 'Filed'),
            inputStr($_POST, 'cabinet_location'),
            inputStr($_POST, 'box_location'),
            inputStr($_POST, 'folder_location'),
            $storedFilename,
            $currentUserId
        );

        setFlash($res['success'] ? 'success' : 'error', $res['success']
            ? 'Document indexed in 4-State Registry with physical coordinates (BR-03).'
            : ($res['error'] ?? 'Failed to index document.'));
        header('Location: ' . appUrl('public/documents.php'));
        exit();
    }

    if ($action === 'transition_document') {
        $docId     = inputInt($_POST, 'document_id');
        $newStatus = inputStr($_POST, 'new_status');
        $cabinet   = inputStr($_POST, 'cabinet_location');
        $box       = inputStr($_POST, 'box_location');
        $folder    = inputStr($_POST, 'folder_location');

        $storedFilename = null;
        if (!empty($_FILES['scan_file']) && ($_FILES['scan_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $uploadRes = storeSecureUpload($_FILES['scan_file'], 'doc_' . $docId);
            if (!$uploadRes['success']) {
                setFlash('error', $uploadRes['error'] ?? 'Scan upload failed.');
                header('Location: ' . appUrl('public/documents.php'));
                exit();
            }
            $storedFilename = $uploadRes['filename'];
            $newStatus      = 'Scanned/Attached';
        }

        $res = transitionDocumentState(
            $pdo,
            $docId,
            $newStatus,
            $cabinet,
            $box,
            $folder,
            $storedFilename,
            $currentUserId
        );

        setFlash($res['success'] ? 'success' : 'error', $res['success']
            ? "Document #{$docId} transitioned to '{$newStatus}' with timestamp & audit log (BR-03)."
            : ($res['error'] ?? 'Transition failed.'));
        header('Location: ' . appUrl('public/documents.php'));
        exit();
    }
}

$searchQuery     = inputStr($_GET, 'q');
$statusFilter    = inputStr($_GET, 'status');
$typeFilter      = inputStr($_GET, 'dtype');
$patientIdFilter = inputInt($_GET, 'patient_id');

$stateCounts = getDocumentStateCounts($pdo);
$documents   = searchDocuments($pdo, $searchQuery, $statusFilter, $typeFilter, $patientIdFilter);
$allPatients = searchPatients($pdo, '', 'Active', 200);

// Fetch service records for optional linking dropdown
$recentRecords = $pdo->query("
    SELECT sr.record_id, sr.program_type, sr.service_date, sr.form_reference_no, p.upi, p.last_name, p.first_name
    FROM service_records sr
    INNER JOIN patients p ON sr.patient_id = p.patient_id
    ORDER BY sr.service_date DESC, sr.record_id DESC
    LIMIT 100
")->fetchAll();

// Edit / Transition modal target
$editDoc = null;
if ($canEdit && isset($_GET['transition_id'])) {
    $editDoc = getDocumentById($pdo, inputInt($_GET, 'transition_id'));
}

$pageTitle    = '4-State Document Registry ("Folder Situation")';
$pageSubtitle = 'FR-10 to FR-12 • BR-03 Physical Location (Cabinet/Box/Folder) & Digital Scan Lifecycle';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- 4-STATE LIFECYCLE SUMMARY CARDS -->
<div class="row g-3 mb-4">
  <div class="col-6 col-xl-3">
    <a href="<?= e(appUrl('public/documents.php?status=' . urlencode('Filed'))) ?>" class="stat-card tint-pine d-block text-decoration-none">
      <div class="stat-label">1. Filed (Physical Cabinet)</div>
      <div class="stat-value"><?= (int) $stateCounts['Filed'] ?></div>
      <div class="stat-foot"><span class="stat-hint">Cataloged in archive box</span></div>
    </a>
  </div>
  <div class="col-6 col-xl-3">
    <a href="<?= e(appUrl('public/documents.php?status=' . urlencode('Pending Digitization'))) ?>" class="stat-card tint-peach d-block text-decoration-none">
      <div class="stat-label">2. Pending Digitization</div>
      <div class="stat-value"><?= (int) $stateCounts['Pending Digitization'] ?></div>
      <div class="stat-foot"><span class="stat-hint">Paper done offline, queued</span></div>
    </a>
  </div>
  <div class="col-6 col-xl-3">
    <a href="<?= e(appUrl('public/documents.php?status=' . urlencode('Scanned/Attached'))) ?>" class="stat-card tint-blue d-block text-decoration-none">
      <div class="stat-label">3. Scanned / Attached</div>
      <div class="stat-value"><?= (int) $stateCounts['Scanned/Attached'] ?></div>
      <div class="stat-foot"><span class="stat-hint">Hashed file outside web root</span></div>
    </a>
  </div>
  <div class="col-6 col-xl-3">
    <a href="<?= e(appUrl('public/documents.php?status=' . urlencode('Unavailable/Archived'))) ?>" class="stat-card tint-mint d-block text-decoration-none">
      <div class="stat-label">4. Unavailable / Archived</div>
      <div class="stat-value"><?= (int) $stateCounts['Unavailable/Archived'] ?></div>
      <div class="stat-foot"><span class="stat-hint">Retired; metadata searchable</span></div>
    </a>
  </div>
</div>

<div class="row g-3">
  <!-- Left Column: Index New Folder / Transition Existing Document -->
  <?php if ($canEdit): ?>
  <div class="col-12 col-lg-4">
    <div class="card h-100">
      <div class="card-body">
        <?php if ($editDoc): ?>
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="fw-700 text-green mb-0">
              <i data-lucide="arrow-right-left" class="me-1"></i>Transition Document #<?= (int) $editDoc['document_id'] ?>
            </h6>
            <span class="badge bg-dark-subtle text-dark"><?= e($editDoc['upi']) ?></span>
          </div>

          <form method="POST" action="<?= e(appUrl('public/documents.php')) ?>" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="transition_document">
            <input type="hidden" name="document_id" value="<?= (int) $editDoc['document_id'] ?>">

            <div class="mb-2">
              <label class="form-label small fw-600">Document Title</label>
              <input type="text" class="form-control form-control-sm" value="<?= e($editDoc['title']) ?>" readonly disabled>
            </div>

            <div class="mb-2">
              <label class="form-label small fw-600">New 4-State Lifecycle Status (BR-03) <span class="text-danger">*</span></label>
              <select name="new_status" class="form-select form-select-sm" required>
                <?php foreach (DOCUMENT_STATES as $st): ?>
                  <option value="<?= e($st) ?>" <?= ($editDoc['status'] === $st) ? 'selected' : '' ?>>
                    <?= e($st) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="row g-2 mb-2">
              <div class="col-4">
                <label class="form-label small fw-600">Cabinet</label>
                <input type="text" name="cabinet_location" class="form-control form-control-sm"
                       value="<?= e($editDoc['cabinet_location'] ?? '') ?>" placeholder="Cabinet A">
              </div>
              <div class="col-4">
                <label class="form-label small fw-600">Box</label>
                <input type="text" name="box_location" class="form-control form-control-sm"
                       value="<?= e($editDoc['box_location'] ?? '') ?>" placeholder="Box 1">
              </div>
              <div class="col-4">
                <label class="form-label small fw-600">Folder</label>
                <input type="text" name="folder_location" class="form-control form-control-sm"
                       value="<?= e($editDoc['folder_location'] ?? '') ?>" placeholder="MAT-01">
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label small fw-600">Attach / Replace Scan (PDF, JPG, PNG ≤ 5MB)</label>
              <input type="file" name="scan_file" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,.webp">
              <div class="form-text" style="font-size:11px;">
                Uploading a scan automatically transitions status to <strong>Scanned/Attached</strong> and records `digitized_at` (FR-12).
              </div>
            </div>

            <div class="d-flex gap-2">
              <button type="submit" class="btn btn-success btn-sm flex-grow-1" style="background:#0B3B22;border-color:#0B3B22;">
                <i class="bi bi-check2-circle me-1"></i>Save Transition
              </button>
              <a href="<?= e(appUrl('public/documents.php')) ?>" class="btn btn-outline-secondary btn-sm">Cancel</a>
            </div>
          </form>
        <?php else: ?>
          <h6 class="fw-700 text-green mb-3">
            <i data-lucide="folder-plus" class="me-1"></i>Index Physical / Digital Folder (FR-10)
          </h6>

          <form method="POST" action="<?= e(appUrl('public/documents.php')) ?>" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_document">

            <div class="mb-2">
              <label class="form-label small fw-600">Owner Patient (UPI) <span class="text-danger">*</span></label>
              <select name="patient_id" class="form-select form-select-sm" required>
                <option value="">-- Select Patient --</option>
                <?php foreach ($allPatients as $p): ?>
                  <option value="<?= (int) $p['patient_id'] ?>"
                    <?= ($patientIdFilter === (int) $p['patient_id']) ? 'selected' : '' ?>>
                    <?= e($p['upi'] . ' — ' . $p['last_name'] . ', ' . $p['first_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="mb-2">
              <label class="form-label small fw-600">Link to Clinical Encounter (`record_id` — Nullable)</label>
              <select name="record_id" class="form-select form-select-sm">
                <option value="0">-- None (Paper filed before encoding) --</option>
                <?php foreach ($recentRecords as $rec): ?>
                  <option value="<?= (int) $rec['record_id'] ?>">
                    #<?= (int) $rec['record_id'] ?> • <?= e($rec['upi']) ?> • <?= e($rec['program_type']) ?> (<?= e($rec['service_date']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="row g-2 mb-2">
              <div class="col-6">
                <label class="form-label small fw-600">Document / Program Type <span class="text-danger">*</span></label>
                <select name="document_type" class="form-select form-select-sm" required>
                  <?php foreach (PROGRAM_TYPES as $pt): ?>
                    <option value="<?= e($pt) ?>"><?= e($pt) ?></option>
                  <?php endforeach; ?>
                  <option value="Blank Fallback Template">Blank Fallback Template</option>
                </select>
              </div>
              <div class="col-6">
                <label class="form-label small fw-600">Initial Lifecycle State <span class="text-danger">*</span></label>
                <select name="status" class="form-select form-select-sm" required>
                  <?php foreach (DOCUMENT_STATES as $st): ?>
                    <option value="<?= e($st) ?>"><?= e($st) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="mb-2">
              <label class="form-label small fw-600">Document Title <span class="text-danger">*</span></label>
              <input type="text" name="title" class="form-control form-control-sm" required
                     placeholder="e.g. FP Form 1 - Dela Cruz, Juana">
            </div>

            <div class="row g-2 mb-2">
              <div class="col-4">
                <label class="form-label small fw-600">Cabinet</label>
                <input type="text" name="cabinet_location" class="form-control form-control-sm" placeholder="Cabinet A">
              </div>
              <div class="col-4">
                <label class="form-label small fw-600">Box</label>
                <input type="text" name="box_location" class="form-control form-control-sm" placeholder="Box 1">
              </div>
              <div class="col-4">
                <label class="form-label small fw-600">Folder</label>
                <input type="text" name="folder_location" class="form-control form-control-sm" placeholder="FP-01">
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label small fw-600">Optional Scan / Photo Upload (FR-12)</label>
              <input type="file" name="scan_file" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,.webp">
              <div class="form-text" style="font-size:11px;">
                Stored outside web root with a random hash name. Validated on download to block traversal.
              </div>
            </div>

            <button type="submit" class="btn btn-success btn-sm w-100" style="background:#0B3B22;border-color:#0B3B22;">
              <i class="bi bi-folder-check me-1"></i>Save to Document Registry
            </button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Right Column: Searchable 4-State Document Registry Table -->
  <div class="col-12 <?= $canEdit ? 'col-lg-8' : 'col-lg-12' ?>">
    <div class="card h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
          <div>
            <h6 class="fw-700 text-green mb-0">
              <i data-lucide="folder-open" class="me-1"></i>Document Registry Index (<?= count($documents) ?> Entries)
            </h6>
            <small class="text-muted">Tracks exact physical cabinet/box/folder coordinates alongside digital scan status.</small>
          </div>

          <form method="GET" action="<?= e(appUrl('public/documents.php')) ?>" class="d-flex gap-2 flex-wrap">
            <input type="search" name="q" class="form-control form-control-sm" style="width:190px;"
                   placeholder="Title, UPI, Cabinet…" value="<?= e($searchQuery) ?>">
            <select name="status" class="form-select form-select-sm" style="width:auto;">
              <option value="">All 4 States</option>
              <?php foreach (DOCUMENT_STATES as $st): ?>
                <option value="<?= e($st) ?>" <?= ($statusFilter === $st) ? 'selected' : '' ?>><?= e($st) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-outline-success">Filter</button>
            <?php if ($searchQuery !== '' || $statusFilter !== '' || $patientIdFilter > 0): ?>
              <a href="<?= e(appUrl('public/documents.php')) ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
            <?php endif; ?>
          </form>
        </div>

        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Title &amp; Type</th>
                <th>Patient (UPI)</th>
                <th>4-State Status</th>
                <th>Physical Location</th>
                <th>Transition Timestamps</th>
                <th class="text-end">Scan / Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($documents)): ?>
              <tr>
                <td colspan="6" class="text-center text-muted py-4">No documents match your filter criteria.</td>
              </tr>
              <?php else: ?>
                <?php foreach ($documents as $doc):
                  $badgeClass = match ($doc['status']) {
                      'Filed'                => 'bg-success-subtle text-success border-success-subtle',
                      'Pending Digitization' => 'bg-warning-subtle text-warning-emphasis border-warning-subtle',
                      'Scanned/Attached'     => 'bg-primary-subtle text-primary border-primary-subtle',
                      default                => 'bg-secondary-subtle text-secondary border-secondary-subtle',
                  };
                ?>
                <tr>
                  <td>
                    <div class="fw-600 small"><?= e($doc['title']) ?></div>
                    <span class="badge bg-light text-dark border" style="font-size:10px;"><?= e($doc['document_type']) ?></span>
                    <?php if (!empty($doc['record_id'])): ?>
                      <span class="text-muted" style="font-size:11px;">• Encounter #<?= (int) $doc['record_id'] ?></span>
                    <?php endif; ?>
                  </td>
                  <td class="small">
                    <strong><?= e($doc['last_name'] . ', ' . $doc['first_name']) ?></strong><br>
                    <code><?= e($doc['upi']) ?></code>
                  </td>
                  <td>
                    <span class="badge border <?= e($badgeClass) ?>"><?= e($doc['status']) ?></span>
                  </td>
                  <td class="small">
                    <div><i class="bi bi-archive text-muted me-1"></i><strong><?= e($doc['cabinet_location'] ?: '—') ?></strong></div>
                    <div class="text-muted" style="font-size:11px;">
                      <?= e(($doc['box_location'] ?: '—') . ' • ' . ($doc['folder_location'] ?: '—')) ?>
                    </div>
                  </td>
                  <td class="small text-muted" style="font-size:11px;">
                    <?php if (!empty($doc['filed_at'])): ?>
                      <div>Filed: <?= e(substr($doc['filed_at'], 0, 16)) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($doc['digitized_at'])): ?>
                      <div class="text-primary">Scanned: <?= e(substr($doc['digitized_at'], 0, 16)) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($doc['archived_at'])): ?>
                      <div class="text-secondary">Archived: <?= e(substr($doc['archived_at'], 0, 16)) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="text-end text-nowrap">
                    <?php if (!empty($doc['file_path'])): ?>
                      <a href="<?= e(appUrl('public/document_download.php?id=' . (int) $doc['document_id'])) ?>"
                         target="_blank" class="btn btn-sm btn-outline-primary" title="View Scan (Anti-Traversal Checked)">
                        <i class="bi bi-file-earmark-pdf"></i> Scan
                      </a>
                    <?php endif; ?>
                    <?php if ($canEdit): ?>
                      <a href="<?= e(appUrl('public/documents.php?transition_id=' . (int) $doc['document_id'])) ?>"
                         class="btn btn-sm btn-outline-success" title="Transition State / Update Location">
                        <i class="bi bi-arrow-left-right"></i> State
                      </a>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

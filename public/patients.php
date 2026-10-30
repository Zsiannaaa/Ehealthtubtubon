<?php
// ============================================================================
// public/patients.php — Screen S2: Patient Registry (Master Patient Index + UPI)
// Covers:
//   - FR-05 / BR-01: Register patient once with algorithmic UPI (TUB-YYYY-#####);
//                    duplicates resolve to existing UPI
//   - FR-06: Instant searchable lookup by UPI, name, or purok
//   - FR-07: Unified Longitudinal History across all 6 programs, 4-state folders,
//            and appointments
//   - BR-11: Nurse/Midwife & BHW can register/edit; Administrator has View-Only oversight
// ============================================================================
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../modules/patients_module.php';

requireRole([ROLE_ADMIN, ROLE_NURSE, ROLE_BHW]);

$currentRole   = getUserRole();
$canEdit       = in_array($currentRole, [ROLE_NURSE, ROLE_BHW], true);
$currentUserId = currentUserId();

// Handle POST (Register or Update Patient — Nurse/Midwife & BHW only per Table 8-6)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole([ROLE_NURSE, ROLE_BHW]);
    verifyCsrf(appUrl('public/patients.php'));

    $action = inputStr($_POST, 'action');

    if ($action === 'register_patient') {
        $res = registerPatient(
            $pdo,
            inputStr($_POST, 'last_name'),
            inputStr($_POST, 'first_name'),
            inputStr($_POST, 'middle_name'),
            inputStr($_POST, 'sex'),
            inputStr($_POST, 'birthdate'),
            inputStr($_POST, 'purok_address'),
            inputStr($_POST, 'contact_no'),
            inputStr($_POST, 'guardian_name'),
            $currentUserId,
            isset($_POST['sms_consent'])
        );

        if ($res['success']) {
            setFlash('success', $res['message'] ?? 'Patient registered.');
            header('Location: ' . appUrl('public/patients.php?view_upi=' . urlencode((string) $res['upi'])));
            exit();
        }

        setFlash('error', $res['error'] ?? 'Could not register patient.');
        header('Location: ' . appUrl('public/patients.php'));
        exit();
    }

    if ($action === 'update_patient') {
        $pid = inputInt($_POST, 'patient_id');
        $err = updatePatient(
            $pdo,
            $pid,
            inputStr($_POST, 'last_name'),
            inputStr($_POST, 'first_name'),
            inputStr($_POST, 'middle_name'),
            inputStr($_POST, 'sex'),
            inputStr($_POST, 'birthdate'),
            inputStr($_POST, 'purok_address'),
            inputStr($_POST, 'contact_no'),
            inputStr($_POST, 'guardian_name'),
            inputStr($_POST, 'status', 'Active'),
            isset($_POST['sms_consent']),
            $currentUserId
        );
        setFlash($err ? 'error' : 'success', $err ?? 'Patient profile updated.');
        header('Location: ' . appUrl('public/patients.php?view_id=' . $pid));
        exit();
    }
}

$searchQuery  = inputStr($_GET, 'q');
$statusFilter = inputStr($_GET, 'status');
$patients     = searchPatients($pdo, $searchQuery, $statusFilter);
$nextPreviewUpi = generateNextUpi($pdo);

// Edit mode
$editPatient = null;
if ($canEdit && isset($_GET['edit_id'])) {
    $editPatient = getPatientByUpiOrId($pdo, null, inputInt($_GET, 'edit_id'));
}

// Unified History Drawer/Panel (FR-07)
$selectedHistory = null;
if (isset($_GET['view_upi']) || isset($_GET['view_id'])) {
    $target = getPatientByUpiOrId(
        $pdo,
        isset($_GET['view_upi']) ? inputStr($_GET, 'view_upi') : null,
        isset($_GET['view_id']) ? inputInt($_GET, 'view_id') : null
    );
    if ($target) {
        $selectedHistory = getPatientUnifiedHistory($pdo, (int) $target['patient_id']);
    }
}

$pageTitle    = 'Patient Registry & Master Patient Index (UPI)';
$pageSubtitle = 'FR-05 to FR-07 • BR-01 Algorithmic UPI (TUB-YYYY-#####) • Unified Longitudinal History';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($selectedHistory):
  $hp = $selectedHistory['patient'];
?>
<!-- UNIFIED LONGITUDINAL PATIENT HISTORY PANEL (FR-07) -->
<div class="card border-success mb-4 shadow-sm">
  <div class="card-header bg-success text-white d-flex justify-content-between align-items-center flex-wrap gap-2" style="background:#0B3B22!important;">
    <div>
      <span class="badge bg-light text-dark me-2"><?= e($hp['upi']) ?></span>
      <strong><?= e($hp['last_name'] . ', ' . $hp['first_name'] . ($hp['middle_name'] ? ' ' . $hp['middle_name'] : '')) ?></strong>
      <span class="ms-2 small opacity-75">
        (<?= e($hp['sex']) ?> • DOB: <?= e($hp['birthdate']) ?> [<?= e(calculatePatientAge($hp['birthdate'])) ?>] • <?= e($hp['purok_address']) ?>)
      </span>
    </div>
    <div class="d-flex gap-2">
      <?php if ($canEdit): ?>
        <a href="<?= e(appUrl('public/service_records.php?patient_id=' . (int) $hp['patient_id'])) ?>" class="btn btn-sm btn-light fw-600">
          <i class="bi bi-file-earmark-medical me-1"></i>+ New Service Record
        </a>
        <a href="<?= e(appUrl('public/documents.php?patient_id=' . (int) $hp['patient_id'])) ?>" class="btn btn-sm btn-outline-light">
          <i class="bi bi-folder-plus me-1"></i>+ File Document
        </a>
      <?php endif; ?>
      <a href="<?= e(appUrl('public/patients.php')) ?>" class="btn btn-sm btn-outline-light">Close History</a>
    </div>
  </div>
  <div class="card-body">
    <div class="row g-3">
      <!-- Encounters across all 6 programs -->
      <div class="col-12 col-lg-5">
        <h6 class="fw-700 text-green mb-2">
          <i class="bi bi-clipboard2-pulse me-1"></i>Clinical Encounters Across 6 Programs (<?= count($selectedHistory['encounters']) ?>)
        </h6>
        <?php if (empty($selectedHistory['encounters'])): ?>
          <p class="small text-muted mb-0">No service encounters recorded yet for this patient.</p>
        <?php else: ?>
          <div class="list-group list-group-flush border rounded">
            <?php foreach ($selectedHistory['encounters'] as $sr): ?>
            <div class="list-group-item small">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="badge bg-success-subtle text-success border border-success-subtle"><?= e($sr['program_type']) ?></span>
                <span class="text-muted fw-600"><?= e($sr['service_date']) ?></span>
              </div>
              <div class="mb-1"><?= nl2br(e(formatClinicalNotesForDisplay((string) ($sr['findings_notes'] ?? '')))) ?></div>
              <div class="text-muted" style="font-size:11px;">
                Form Ref: <code><?= e($sr['form_reference_no'] ?: 'N/A') ?></code> •
                Encoded: <?= e($sr['recorded_by_name'] ?? 'Staff') ?> •
                Verified: <?= $sr['verified_by_name'] ? '<span class="text-success fw-600">' . e($sr['verified_by_name']) . '</span>' : '<span class="text-warning-emphasis">Pending Verification</span>' ?>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Linked 4-State Physical/Digital Documents -->
      <div class="col-12 col-lg-4">
        <h6 class="fw-700 text-green mb-2">
          <i class="bi bi-folder2-open me-1"></i>Physical &amp; Digital Folders (<?= count($selectedHistory['documents']) ?>)
        </h6>
        <?php if (empty($selectedHistory['documents'])): ?>
          <p class="small text-muted mb-0">No folder entries linked yet.</p>
        <?php else: ?>
          <div class="list-group list-group-flush border rounded">
            <?php foreach ($selectedHistory['documents'] as $doc): ?>
            <div class="list-group-item small">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <strong><?= e($doc['title']) ?></strong>
                <span class="badge bg-secondary-subtle text-dark border"><?= e($doc['status']) ?></span>
              </div>
              <div class="text-muted" style="font-size:11px;">
                <i class="bi bi-archive me-1"></i>
                Location: <strong><?= e(($doc['cabinet_location'] ?: '—') . ' / ' . ($doc['box_location'] ?: '—') . ' / ' . ($doc['folder_location'] ?: '—')) ?></strong>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Appointments & Consents -->
      <div class="col-12 col-lg-3">
        <h6 class="fw-700 text-green mb-2">
          <i class="bi bi-calendar-event me-1"></i>Appointments (<?= count($selectedHistory['appointments']) ?>)
        </h6>
        <?php if (empty($selectedHistory['appointments'])): ?>
          <p class="small text-muted">No scheduled appointments.</p>
        <?php else: ?>
          <ul class="list-group list-group-flush border rounded mb-3">
            <?php foreach ($selectedHistory['appointments'] as $apt): ?>
            <li class="list-group-item small d-flex justify-content-between align-items-center">
              <div>
                <strong><?= e($apt['scheduled_date']) ?></strong><br>
                <span class="text-muted" style="font-size:11px;"><?= e($apt['program_type']) ?></span>
              </div>
              <span class="badge bg-light text-dark border"><?= e($apt['status']) ?></span>
            </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <h6 class="fw-700 text-green mb-1"><i class="bi bi-shield-check me-1"></i>RA 10173 Consents</h6>
        <?php foreach ($selectedHistory['consents'] as $con): ?>
          <div class="small d-flex justify-content-between border-bottom py-1">
            <span><?= e($con['consent_type']) ?></span>
            <?php if ((int) $con['consent_given'] === 1): ?>
              <span class="badge bg-success-subtle text-success">Granted</span>
            <?php else: ?>
              <span class="badge bg-secondary-subtle text-secondary">Withdrawn</span>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="row g-3">
  <!-- Left Column: Register / Edit Patient Form (Nurse/Midwife & BHW) -->
  <?php if ($canEdit): ?>
  <div class="col-12 col-lg-4">
    <div class="card h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h6 class="fw-700 text-green mb-0">
            <i data-lucide="<?= $editPatient ? 'user-pen' : 'user-plus' ?>" class="me-1"></i>
            <?= $editPatient ? 'Edit Patient (' . e($editPatient['upi']) . ')' : 'Register Patient (BR-01)' ?>
          </h6>
          <?php if (!$editPatient): ?>
            <span class="badge bg-success-subtle text-success border border-success-subtle" title="Auto-generated algorithmic UPI">
              Next: <?= e($nextPreviewUpi) ?>
            </span>
          <?php endif; ?>
        </div>

        <form method="POST" action="<?= e(appUrl('public/patients.php')) ?>">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="<?= $editPatient ? 'update_patient' : 'register_patient' ?>">
          <?php if ($editPatient): ?>
            <input type="hidden" name="patient_id" value="<?= (int) $editPatient['patient_id'] ?>">
          <?php endif; ?>

          <div class="row g-2">
            <div class="col-6">
              <label class="form-label small fw-600">Last Name <span class="text-danger">*</span></label>
              <input type="text" name="last_name" class="form-control form-control-sm" required
                     value="<?= e($editPatient['last_name'] ?? '') ?>" placeholder="DELA CRUZ">
            </div>
            <div class="col-6">
              <label class="form-label small fw-600">First Name <span class="text-danger">*</span></label>
              <input type="text" name="first_name" class="form-control form-control-sm" required
                     value="<?= e($editPatient['first_name'] ?? '') ?>" placeholder="Juana">
            </div>
            <div class="col-6">
              <label class="form-label small fw-600">Middle Name</label>
              <input type="text" name="middle_name" class="form-control form-control-sm"
                     value="<?= e($editPatient['middle_name'] ?? '') ?>" placeholder="Santos">
            </div>
            <div class="col-6">
              <label class="form-label small fw-600">Sex <span class="text-danger">*</span></label>
              <select name="sex" class="form-select form-select-sm" required>
                <option value="Female" <?= (($editPatient['sex'] ?? 'Female') === 'Female') ? 'selected' : '' ?>>Female</option>
                <option value="Male"   <?= (($editPatient['sex'] ?? '') === 'Male') ? 'selected' : '' ?>>Male</option>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label small fw-600">Birthdate <span class="text-danger">*</span></label>
              <input type="date" name="birthdate" class="form-control form-control-sm" required
                     max="<?= e(date('Y-m-d')) ?>"
                     value="<?= e($editPatient['birthdate'] ?? '') ?>">
            </div>
            <div class="col-6">
              <label class="form-label small fw-600">Mobile Contact No.</label>
              <input type="text" name="contact_no" class="form-control form-control-sm"
                     value="<?= e($editPatient['contact_no'] ?? '') ?>" placeholder="09171234567">
            </div>
            <div class="col-12">
              <label class="form-label small fw-600">Purok / Sitio &amp; Barangay Address <span class="text-danger">*</span></label>
              <input type="text" name="purok_address" class="form-control form-control-sm" required
                     value="<?= e($editPatient['purok_address'] ?? 'Purok 1 - Sampaguita, Brgy. Tubtubon, Sibulan') ?>">
            </div>
            <div class="col-12">
              <label class="form-label small fw-600">Guardian / Spouse Name (for minors/maternal)</label>
              <input type="text" name="guardian_name" class="form-control form-control-sm"
                     value="<?= e($editPatient['guardian_name'] ?? '') ?>" placeholder="Optional guardian name">
            </div>

            <?php if ($editPatient): ?>
            <div class="col-12">
              <label class="form-label small fw-600">Registry Status</label>
              <select name="status" class="form-select form-select-sm">
                <option value="Active"   <?= ($editPatient['status'] === 'Active') ? 'selected' : '' ?>>Active</option>
                <option value="Archived" <?= ($editPatient['status'] === 'Archived') ? 'selected' : '' ?>>Archived (Long-term storage, searchable)</option>
              </select>
            </div>
            <?php endif; ?>

            <div class="col-12 mt-2">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="sms_consent" id="smsConsentChk" value="1" checked>
                <label class="form-check-label small" for="smsConsentChk">
                  Patient/Guardian consents to receive privacy-safe SMS appointment reminders (<strong>BR-09 / RA 10173</strong>)
                </label>
              </div>
            </div>
          </div>

          <div class="d-flex gap-2 mt-3">
            <button type="submit" class="btn btn-success btn-sm flex-grow-1" style="background:#0B3B22;border-color:#0B3B22;">
              <i class="bi bi-check2-circle me-1"></i><?= $editPatient ? 'Update Patient' : 'Assign UPI & Register' ?>
            </button>
            <?php if ($editPatient): ?>
              <a href="<?= e(appUrl('public/patients.php')) ?>" class="btn btn-outline-secondary btn-sm">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Right Column: Searchable Master Patient Index Table -->
  <div class="col-12 <?= $canEdit ? 'col-lg-8' : 'col-lg-12' ?>">
    <div class="card h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
          <div>
            <h6 class="fw-700 text-green mb-0">
              <i data-lucide="users" class="me-1"></i>Master Patient Index (<?= count($patients) ?> Records)
            </h6>
            <small class="text-muted">Click <strong>Unified History</strong> to view all encounters, folders, and schedules bound to a patient's UPI.</small>
          </div>

          <form method="GET" action="<?= e(appUrl('public/patients.php')) ?>" class="d-flex gap-2 flex-wrap">
            <input type="search" name="q" class="form-control form-control-sm" style="width:210px;"
                   placeholder="Search UPI, name, purok…" value="<?= e($searchQuery) ?>">
            <select name="status" class="form-select form-select-sm" style="width:auto;">
              <option value="">All Statuses</option>
              <option value="Active"   <?= ($statusFilter === 'Active') ? 'selected' : '' ?>>Active</option>
              <option value="Archived" <?= ($statusFilter === 'Archived') ? 'selected' : '' ?>>Archived</option>
            </select>
            <button type="submit" class="btn btn-sm btn-outline-success">Search</button>
            <?php if ($searchQuery !== '' || $statusFilter !== ''): ?>
              <a href="<?= e(appUrl('public/patients.php')) ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
            <?php endif; ?>
          </form>
        </div>

        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>UPI (BR-01)</th>
                <th>Patient Name</th>
                <th>Sex / Age</th>
                <th>Purok / Address</th>
                <th>SMS Consent</th>
                <th>Linked Records</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($patients)): ?>
              <tr>
                <td colspan="7" class="text-center text-muted py-4">No patients match your search criteria.</td>
              </tr>
              <?php else: ?>
                <?php foreach ($patients as $p): ?>
                <tr>
                  <td>
                    <code class="fw-bold text-success"><?= e($p['upi']) ?></code>
                    <?php if ($p['status'] === 'Archived'): ?>
                      <span class="badge bg-secondary ms-1" style="font-size:10px;">Archived</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="fw-600"><?= e($p['last_name'] . ', ' . $p['first_name'] . ($p['middle_name'] ? ' ' . $p['middle_name'] : '')) ?></div>
                    <?php if (!empty($p['guardian_name'])): ?>
                      <div class="text-muted" style="font-size:11px;">Guardian: <?= e($p['guardian_name']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="small">
                    <?= e($p['sex']) ?> • <strong><?= e(calculatePatientAge($p['birthdate'])) ?></strong><br>
                    <span class="text-muted" style="font-size:11px;"><?= e($p['birthdate']) ?></span>
                  </td>
                  <td class="small">
                    <div><?= e($p['purok_address']) ?></div>
                    <div class="text-muted" style="font-size:11px;"><?= e($p['contact_no'] ?: 'No mobile') ?></div>
                  </td>
                  <td>
                    <?php if ((int) ($p['sms_consent'] ?? 0) === 1): ?>
                      <span class="badge bg-success-subtle text-success border border-success-subtle">Consented</span>
                    <?php else: ?>
                      <span class="badge bg-secondary-subtle text-secondary">No Consent</span>
                    <?php endif; ?>
                  </td>
                  <td class="small">
                    <span class="badge bg-light text-dark border"><?= (int) $p['encounter_count'] ?> visits</span>
                    <span class="badge bg-light text-dark border"><?= (int) $p['document_count'] ?> folders</span>
                  </td>
                  <td class="text-end text-nowrap">
                    <a href="<?= e(appUrl('public/patients.php?view_upi=' . urlencode($p['upi']))) ?>"
                       class="btn btn-sm btn-outline-success" title="View Unified Longitudinal History (FR-07)">
                      <i class="bi bi-clock-history me-1"></i>History
                    </a>
                    <?php if ($canEdit): ?>
                    <a href="<?= e(appUrl('public/patients.php?edit_id=' . (int) $p['patient_id'])) ?>"
                       class="btn btn-sm btn-outline-primary" title="Edit Demographics">
                      <i class="bi bi-pencil"></i>
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

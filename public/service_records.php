<?php
/**
 * Tubtubon eHealth — Screen S3: Clinical Service Records & Program Encounters (M3)
 * Phase 8 Sec. 51 (S3) | FR-06, FR-07, FR-08, FR-09 | BR-02, BR-10, BR-11, BR-12
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../modules/service_records_module.php';
require_once __DIR__ . '/../modules/patients_module.php';

requireRole([ROLE_NURSE, ROLE_BHW]);

$roleName  = getUserRole();
$userId    = currentUserId();
$fullName  = (string)($_SESSION['full_name'] ?? 'Clinic Staff');
$flashMsg  = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole([ROLE_NURSE, ROLE_BHW]);
    verifyCsrf(appUrl('public/service_records.php'));
    $action = inputStr($_POST, 'action', '');

    if ($action === 'create_record') {
        $res = createServiceRecord($_POST, $userId, $roleName);
        if ($res['ok']) {
            $flashMsg = 'Clinical service record #' . $res['record_id'] . ' saved successfully.';
        } else {
            $flashMsg  = $res['error'] ?? 'Failed to save service record.';
            $flashType = 'danger';
        }
    } elseif ($action === 'update_record') {
        $recordId = inputInt($_POST, 'record_id', 0);
        $res      = updateServiceRecord($recordId, $_POST, $userId, $roleName);
        if ($res['ok']) {
            $flashMsg = 'Clinical service record #' . $recordId . ' updated successfully.';
        } else {
            $flashMsg  = $res['error'] ?? 'Failed to update service record.';
            $flashType = 'danger';
        }
    } elseif ($action === 'verify_record') {
        requireRole([ROLE_NURSE]);
        $recordId = inputInt($_POST, 'record_id', 0);
        $res      = verifyServiceRecord($recordId, $userId, $roleName);
        if ($res['ok']) {
            $flashMsg = 'Service record #' . $recordId . ' clinically verified by ' . $fullName . '.';
        } else {
            $flashMsg  = $res['error'] ?? 'Verification failed.';
            $flashType = 'danger';
        }
    }
}

$filterProgram    = inputStr($_GET, 'program', '');
$filterPatientId  = inputInt($_GET, 'patient_id', 0);
$filterSearch     = inputStr($_GET, 'q', '');
$filterUnverified = !empty($_GET['unverified']);
$filterDateFrom   = inputStr($_GET, 'date_from', '');
$filterDateTo     = inputStr($_GET, 'date_to', '');

$records = listServiceRecords([
    'program_type'    => $filterProgram,
    'patient_id'      => $filterPatientId,
    'search'          => $filterSearch,
    'unverified_only' => $filterUnverified,
    'date_from'       => $filterDateFrom,
    'date_to'         => $filterDateTo,
], 200);

$stats          = getServiceRecordStats();
$programSchemas = getProgramFormSchemas();
$allPatients    = searchPatients($pdo, '', 'Active', 300);

$pageTitle  = 'Clinical Service Records (M3)';
$activePage = 'service_records';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<style>
.bg-purple-subtle { background-color: #f3e8ff !important; }
.text-purple { color: #6b21a8 !important; }
.border-purple { border-color: #d8b4fe !important; }
.program-pill {
    cursor: pointer;
    transition: all 0.15s ease;
    text-decoration: none;
}
.program-pill:hover, .program-pill.active-pill {
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(15, 118, 110, 0.12);
}
@media print {
    body * { visibility: hidden !important; }
    #printableSlipArea, #printableSlipArea * { visibility: visible !important; }
    #printableSlipArea {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        padding: 0;
        margin: 0;
    }
    .no-print-slip { display: none !important; }
}
</style>

<div class="container-fluid py-3">
    <!-- Top Page Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h4 class="fw-bold mb-0 text-dark">
                <i class="bi bi-journal-medical text-success me-2"></i>Clinical Service Records &amp; Program Encounters
            </h4>
            <p class="text-muted small mb-0">
                Module M3 (BR-02 / FR-06 to FR-09) &mdash; Longitudinal Consultation Ledger Mapped to Official DOH / Clinic Forms
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= e(appUrl('public/templates.php')) ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-printer me-1"></i>Print Blank Standby Forms (S5)
            </a>
            <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#encodeRecordModal" onclick="openCreateRecordModal()">
                <i class="bi bi-plus-circle me-1"></i>Encode Clinical Encounter
            </button>
        </div>
    </div>

    <?php if ($flashMsg !== ''): ?>
        <div class="alert alert-<?= e($flashType) ?> alert-dismissible fade show py-2 small" role="alert">
            <i class="bi bi-info-circle me-1"></i><?= e($flashMsg) ?>
            <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Program Filter Pills (All 6 BR-02 Programs + Pending Verification Queue) -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3 col-xl-2">
            <a href="<?= e(appUrl('public/service_records.php')) ?>"
               class="card h-100 border-0 shadow-sm program-pill <?= ($filterProgram === '' && !$filterUnverified) ? 'border-start border-4 border-success active-pill' : '' ?>">
                <div class="card-body p-2 px-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="small text-muted fw-semibold">All Encounters</span>
                        <i class="bi bi-collection text-success"></i>
                    </div>
                    <div class="fs-5 fw-bold text-dark"><?= (int)$stats['total'] ?></div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-3 col-xl-2">
            <a href="<?= e(appUrl('public/service_records.php?unverified=1')) ?>"
               class="card h-100 border-0 shadow-sm program-pill <?= $filterUnverified ? 'border-start border-4 border-warning active-pill' : '' ?>">
                <div class="card-body p-2 px-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="small text-muted fw-semibold">Awaiting Nurse Verify</span>
                        <i class="bi bi-patch-exclamation text-warning"></i>
                    </div>
                    <div class="fs-5 fw-bold text-warning-emphasis"><?= (int)$stats['unverified'] ?></div>
                </div>
            </a>
        </div>
        <?php foreach ($programSchemas as $progName => $schemaMeta): ?>
            <div class="col-6 col-md-3 col-xl-2">
                <a href="<?= e(appUrl('public/service_records.php?program=' . urlencode($progName))) ?>"
                   class="card h-100 border-0 shadow-sm program-pill <?= ($filterProgram === $progName) ? 'border-start border-4 border-primary active-pill' : '' ?>">
                    <div class="card-body p-2 px-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="small text-muted fw-semibold text-truncate" title="<?= e($progName) ?>"><?= e($progName) ?></span>
                            <i class="bi <?= e($schemaMeta['icon']) ?> text-secondary"></i>
                        </div>
                        <div class="fs-5 fw-bold text-dark"><?= (int)($stats[$progName] ?? 0) ?></div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Search & Filter Bar -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2 px-3">
            <form method="GET" action="<?= e(appUrl('public/service_records.php')) ?>" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1">Search Patient / UPI / Findings</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
                        <input type="text" name="q" class="form-control" placeholder="e.g. TUB-2026-00001, BP, Prenatal..." value="<?= e($filterSearch) ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1">Health Program (BR-02)</label>
                    <select name="program" class="form-select form-select-sm">
                        <option value="">All 6 Clinic Programs</option>
                        <?php foreach (PROGRAM_TYPES as $pt): ?>
                            <option value="<?= e($pt) ?>" <?= $filterProgram === $pt ? 'selected' : '' ?>><?= e($pt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">From Date</label>
                    <input type="date" name="date_from" class="form-control form-control-sm" value="<?= e($filterDateFrom) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1">To Date</label>
                    <input type="date" name="date_to" class="form-control form-control-sm" value="<?= e($filterDateTo) ?>">
                </div>
                <div class="col-md-2 d-flex gap-1">
                    <button type="submit" class="btn btn-success btn-sm flex-grow-1">
                        <i class="bi bi-funnel me-1"></i>Filter
                    </button>
                    <a href="<?= e(appUrl('public/service_records.php')) ?>" class="btn btn-outline-secondary btn-sm" title="Reset">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Service Records Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-2 px-3 d-flex justify-content-between align-items-center">
            <span class="fw-semibold small text-dark">
                Showing <?= count($records) ?> Clinical Encounter Record(s)
                <?php if ($filterProgram !== ''): ?>
                    &mdash; Program: <span class="badge bg-success"><?= e($filterProgram) ?></span>
                <?php endif; ?>
                <?php if ($filterUnverified): ?>
                    &mdash; <span class="badge bg-warning text-dark">Pending Nurse/Midwife Verification</span>
                <?php endif; ?>
            </span>
            <span class="small text-muted">Sorted chronologically (Newest Visit First)</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Visit Date &amp; Ref</th>
                        <th>Patient (UPI)</th>
                        <th>Program &amp; Official Form</th>
                        <th style="min-width: 280px;">Clinical Findings &amp; Form Data</th>
                        <th>Treatment / Action Given</th>
                        <th>Next Visit</th>
                        <th>Encoded / Verified By</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">
                                <i class="bi bi-journal-x fs-4 d-block mb-1"></i>
                                No clinical service records match your current filter.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($records as $rec): ?>
                            <?php
                            $prog       = (string)$rec['program_type'];
                            $meta       = $programSchemas[$prog] ?? ['badge_cls' => 'bg-secondary-subtle text-secondary', 'icon' => 'bi-file-medical', 'form_code' => 'Clinic Form'];
                            $isVerified = $rec['verified_by'] !== null;
                            $canEdit    = ($roleName === ROLE_NURSE || ($roleName === ROLE_BHW && !$isVerified));
                            $jsonRec    = htmlspecialchars(json_encode($rec, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
                            ?>
                            <tr>
                                <td class="fw-semibold text-nowrap">
                                    <?= e($rec['visit_date']) ?>
                                    <div class="text-muted font-monospace" style="font-size: 0.72rem;">
                                        <?= e($rec['form_reference_no'] ?? ('Rec #' . (int)$rec['record_id'])) ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark">
                                        <?= e($rec['last_name'] . ', ' . $rec['first_name']) ?>
                                    </div>
                                    <span class="badge bg-light text-dark border font-monospace" style="font-size: 0.72rem;">
                                        <?= e($rec['upi']) ?>
                                    </span>
                                    <div class="text-muted text-truncate" style="font-size: 0.71rem; max-width: 170px;"><?= e($rec['purok_address'] ?? '') ?></div>
                                </td>
                                <td>
                                    <span class="badge border <?= e($meta['badge_cls']) ?>">
                                        <i class="bi <?= e($meta['icon']) ?> me-1"></i><?= e($prog) ?>
                                    </span>
                                    <div class="text-muted mt-1" style="font-size: 0.71rem;"><?= e($meta['form_code']) ?></div>
                                </td>
                                <td>
                                    <?php if (!empty($rec['form_fields'])): ?>
                                        <div class="d-flex flex-wrap gap-1 mb-1">
                                            <?php
                                            $shownCount = 0;
                                            foreach ($rec['form_fields'] as $fk => $fv):
                                                if ($shownCount >= 4) break;
                                                $fLabel = $meta['fields'][$fk]['label'] ?? $fk;
                                                $shownCount++;
                                            ?>
                                                <span class="badge bg-light text-dark border fw-normal" style="font-size: 0.71rem;">
                                                    <strong><?= e($fLabel) ?>:</strong> <?= e($fv) ?>
                                                </span>
                                            <?php endforeach; ?>
                                            <?php if (count($rec['form_fields']) > 4): ?>
                                                <span class="badge bg-secondary-subtle text-secondary" style="font-size: 0.7rem;">
                                                    +<?= count($rec['form_fields']) - 4 ?> more fields
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="text-dark">
                                        <?= nl2br(e(mb_strimwidth((string)($rec['narrative_notes'] !== '' ? $rec['narrative_notes'] : $rec['display_summary']), 0, 180, '...'))) ?>
                                    </div>
                                </td>
                                <td>
                                    <?= $rec['treatment_given'] !== '' ? e((string)$rec['treatment_given']) : '<span class="text-muted">&mdash;</span>' ?>
                                </td>
                                <td class="text-nowrap">
                                    <?php if (!empty($rec['next_visit_date'])): ?>
                                        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">
                                            <i class="bi bi-calendar-event me-1"></i><?= e((string)$rec['next_visit_date']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="small">
                                        <span class="text-muted">Enc:</span> <?= e((string)($rec['encoded_by_name'] ?? 'Staff')) ?>
                                    </div>
                                    <?php if ($isVerified): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle mt-1" style="font-size: 0.7rem;">
                                            <i class="bi bi-patch-check-fill me-1"></i>Verified: <?= e((string)$rec['verified_by_name']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle mt-1" style="font-size: 0.7rem;">
                                            <i class="bi bi-hourglass-split me-1"></i>Pending Nurse Verify
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="btn btn-outline-primary btn-sm py-0 px-2"
                                            title="View &amp; Print Official Clinical Slip"
                                            onclick='openViewSlipModal(<?= $jsonRec ?>)'>
                                        <i class="bi bi-printer"></i> Slip
                                    </button>
                                    <?php if ($canEdit): ?>
                                        <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2"
                                                title="Edit Clinical Record"
                                                onclick='openEditRecordModal(<?= $jsonRec ?>)'>
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if (!$isVerified && $roleName === ROLE_NURSE): ?>
                                        <form method="POST" action="<?= e(appUrl('public/service_records.php')) ?>" class="d-inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="verify_record">
                                            <input type="hidden" name="record_id" value="<?= (int)$rec['record_id'] ?>">
                                            <button type="submit" class="btn btn-success btn-sm py-0 px-2" title="Clinically Verify BHW Record (FR-09)">
                                                <i class="bi bi-check2-circle"></i> Verify
                                            </button>
                                        </form>
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

<!-- Modal 1: Encode / Edit Clinical Service Record (Dynamic 6-Program Form Builder) -->
<div class="modal fade" id="encodeRecordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <form method="POST" action="<?= e(appUrl('public/service_records.php')) ?>" class="modal-content">
            <?= csrfField() ?>
            <input type="hidden" name="action" id="srFormAction" value="create_record">
            <input type="hidden" name="record_id" id="srRecordId" value="0">

            <div class="modal-header bg-success text-white py-2">
                <h6 class="modal-title fw-bold" id="srModalTitle">
                    <i class="bi bi-file-earmark-medical me-2"></i>Encode Clinical Service Record
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <!-- Row 1: Patient, Program Type, Visit Date, Next Visit Date -->
                <div class="row g-3 mb-3 pb-3 border-bottom">
                    <div class="col-md-5">
                        <label class="form-label small fw-bold">Patient (Resident UPI) <span class="text-danger">*</span></label>
                        <select name="patient_id" id="srPatientId" class="form-select form-select-sm" required>
                            <option value="">-- Select Registered Patient --</option>
                            <?php foreach ($allPatients as $pt): ?>
                                <option value="<?= (int)$pt['patient_id'] ?>"
                                        <?= $filterPatientId === (int)$pt['patient_id'] ? 'selected' : '' ?>>
                                    <?= e($pt['upi'] . ' — ' . $pt['last_name'] . ', ' . $pt['first_name'] . ' (' . $pt['sex'] . ', ' . ($pt['purok_address'] ?? '') . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">Health Program (BR-02) <span class="text-danger">*</span></label>
                        <select name="program_type" id="srProgramType" class="form-select form-select-sm" required onchange="renderProgramSpecificFields()">
                            <?php foreach (PROGRAM_TYPES as $pt): ?>
                                <option value="<?= e($pt) ?>" <?= $filterProgram === $pt ? 'selected' : '' ?>><?= e($pt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold">Visit Date <span class="text-danger">*</span></label>
                        <input type="date" name="visit_date" id="srVisitDate" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold">Follow-Up / Next Visit</label>
                        <input type="date" name="next_visit_date" id="srNextVisitDate" class="form-control form-control-sm">
                    </div>
                </div>

                <!-- Dynamic Official DOH/Clinic Form Fields Container -->
                <div class="p-3 rounded-3 bg-light border mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-bold small text-success" id="srFormBannerTitle">
                            <i class="bi bi-card-checklist me-1"></i>Official DOH / Clinic Form Fields
                        </span>
                        <span class="badge bg-white text-dark border small" id="srFormCodeBadge">Form Template</span>
                    </div>
                    <div class="row g-2" id="srDynamicFieldsContainer">
                        <!-- Populated dynamically by renderProgramSpecificFields() -->
                    </div>
                </div>

                <!-- Clinical Narrative & Treatment Given -->
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Clinical Narrative / Additional Findings Notes</label>
                        <textarea name="narrative_notes" id="srNarrativeNotes" rows="3" class="form-control form-control-sm"
                                  placeholder="Enter additional physical exam observations, patient complaints, or referral remarks..."></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold">Health Services Given / Treatment / Prescription</label>
                        <textarea name="treatment_given" id="srTreatmentGiven" rows="3" class="form-control form-control-sm"
                                  placeholder="Enter medicines dispensed, micronutrient supplementation, counseling, or RHU referral instructions..."></textarea>
                    </div>
                </div>

                <?php if ($roleName === ROLE_BHW): ?>
                    <div class="alert alert-warning py-2 px-3 small mt-3 mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        <strong>BHW Intake Notice (FR-09):</strong> Records encoded by a Barangay Health Worker are saved immediately and queued for clinical verification by the Midwife/Nurse.
                    </div>
                <?php endif; ?>
            </div>

            <div class="modal-footer py-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success btn-sm">
                    <i class="bi bi-check2-circle me-1"></i>Save Clinical Encounter
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 2: View & Print Official Clinical Encounter Slip -->
<div class="modal fade" id="viewSlipModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white py-2 no-print-slip">
                <h6 class="modal-title fw-bold">
                    <i class="bi bi-printer me-2"></i>Official Clinical Encounter Record Slip
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="printableSlipArea">
                <div class="border border-2 border-dark p-3">
                    <div class="text-center border-bottom border-dark pb-2 mb-3">
                        <div class="small fw-bold text-uppercase">Republic of the Philippines &bull; Department of Health</div>
                        <h5 class="fw-bold mb-0 text-uppercase">Barangay Tubtubon Health Center</h5>
                        <div class="small">Municipality of Sibulan, Province of Negros Oriental</div>
                        <div class="badge bg-dark text-white mt-1" id="slipProgramBadge">CLINICAL ENCOUNTER RECORD</div>
                    </div>

                    <div class="row g-2 small border-bottom pb-2 mb-3">
                        <div class="col-6"><strong>Patient Name:</strong> <span id="slipPatientName"></span></div>
                        <div class="col-6"><strong>Patient Code (UPI):</strong> <span id="slipPatientCode" class="font-monospace"></span></div>
                        <div class="col-4"><strong>Sex / DOB:</strong> <span id="slipSexDob"></span></div>
                        <div class="col-4"><strong>Purok / Address:</strong> <span id="slipAddress"></span></div>
                        <div class="col-4"><strong>Visit Date:</strong> <span id="slipVisitDate" class="fw-bold"></span></div>
                    </div>

                    <div class="mb-3">
                        <div class="fw-bold small text-uppercase border-bottom pb-1 mb-2" id="slipFormHeader">Structured Program Data</div>
                        <div class="row g-2 small" id="slipStructuredGrid"></div>
                    </div>

                    <div class="mb-3">
                        <div class="fw-bold small text-uppercase border-bottom pb-1 mb-1">Clinical Findings / Notes</div>
                        <div class="small p-2 bg-light border" id="slipNarrative"></div>
                    </div>

                    <div class="mb-3">
                        <div class="fw-bold small text-uppercase border-bottom pb-1 mb-1">Health Services Given / Treatment</div>
                        <div class="small p-2 bg-light border" id="slipTreatment"></div>
                    </div>

                    <div class="row g-2 small mt-3 pt-2 border-top">
                        <div class="col-4">
                            <strong>Scheduled Follow-Up:</strong><br>
                            <span id="slipNextVisit" class="fw-bold"></span>
                        </div>
                        <div class="col-4 text-center">
                            <div class="border-bottom border-dark mt-3 pt-1" id="slipEncodedBy"></div>
                            <div class="text-muted" style="font-size: 0.72rem;">Encoded By</div>
                        </div>
                        <div class="col-4 text-center">
                            <div class="border-bottom border-dark mt-3 pt-1" id="slipVerifiedBy"></div>
                            <div class="text-muted" style="font-size: 0.72rem;">Clinically Verified By (Midwife/Nurse)</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2 no-print-slip">
                <a href="<?= e(appUrl('public/templates.php')) ?>" id="slipBlankFormLink" class="btn btn-outline-secondary btn-sm me-auto">
                    <i class="bi bi-file-earmark-ruled me-1"></i>Open Full Blank Standby Form
                </a>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i>Print Encounter Slip
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const PROGRAM_SCHEMAS = <?= json_encode($programSchemas, JSON_UNESCAPED_UNICODE) ?>;

function renderProgramSpecificFields(prefill = {}) {
    const prog = document.getElementById('srProgramType').value;
    const container = document.getElementById('srDynamicFieldsContainer');
    const codeBadge = document.getElementById('srFormCodeBadge');
    const bannerTitle = document.getElementById('srFormBannerTitle');

    const schema = PROGRAM_SCHEMAS[prog];
    if (!schema) {
        container.innerHTML = '<div class="col-12 small text-muted">Select a program to load form fields.</div>';
        return;
    }

    codeBadge.textContent = schema.form_code;
    bannerTitle.innerHTML = `<i class="bi ${schema.icon} me-1"></i>${prog} — Official Form Fields`;

    let html = '';
    const fields = schema.fields || {};
    for (const [key, meta] of Object.entries(fields)) {
        const val = (prefill[key] !== undefined) ? prefill[key] : '';
        const escapedVal = String(val).replace(/"/g, '&quot;');
        html += `<div class="col-md-4 col-sm-6">
            <label class="form-label small text-muted mb-1">${meta.label}</label>`;

        if (meta.type === 'select') {
            html += `<select name="form_fields[${key}]" class="form-select form-select-sm">
                <option value="">-- Select --</option>`;
            for (const opt of (meta.options || [])) {
                const sel = (String(val) === String(opt)) ? 'selected' : '';
                html += `<option value="${opt.replace(/"/g, '&quot;')}" ${sel}>${opt}</option>`;
            }
            html += `</select>`;
        } else {
            const inputType = meta.type || 'text';
            const ph = (meta.placeholder || '').replace(/"/g, '&quot;');
            html += `<input type="${inputType}" name="form_fields[${key}]" class="form-control form-control-sm" value="${escapedVal}" placeholder="${ph}">`;
        }
        html += `</div>`;
    }

    container.innerHTML = html;
}

function openCreateRecordModal() {
    document.getElementById('srFormAction').value = 'create_record';
    document.getElementById('srRecordId').value = '0';
    document.getElementById('srModalTitle').innerHTML = '<i class="bi bi-plus-circle me-2"></i>Encode Clinical Service Record';
    document.getElementById('srVisitDate').value = '<?= date('Y-m-d') ?>';
    document.getElementById('srNextVisitDate').value = '';
    document.getElementById('srNarrativeNotes').value = '';
    document.getElementById('srTreatmentGiven').value = '';
    renderProgramSpecificFields({});
}

function openEditRecordModal(rec) {
    document.getElementById('srFormAction').value = 'update_record';
    document.getElementById('srRecordId').value = rec.record_id;
    document.getElementById('srModalTitle').innerHTML = `<i class="bi bi-pencil-square me-2"></i>Edit Service Record #${rec.record_id} (${rec.upi})`;
    document.getElementById('srPatientId').value = rec.patient_id;
    document.getElementById('srProgramType').value = rec.program_type;
    document.getElementById('srVisitDate').value = rec.visit_date || '';
    document.getElementById('srNextVisitDate').value = rec.next_visit_date || '';
    document.getElementById('srNarrativeNotes').value = rec.narrative_notes || '';
    document.getElementById('srTreatmentGiven').value = rec.treatment_given || '';
    renderProgramSpecificFields(rec.form_fields || {});

    const modal = new bootstrap.Modal(document.getElementById('encodeRecordModal'));
    modal.show();
}

function openViewSlipModal(rec) {
    const schema = PROGRAM_SCHEMAS[rec.program_type] || {};
    document.getElementById('slipProgramBadge').textContent = `${rec.program_type} — ${schema.form_code || 'Official Record'}`;
    document.getElementById('slipPatientName').textContent = `${rec.last_name}, ${rec.first_name} ${rec.middle_name || ''}`;
    document.getElementById('slipPatientCode').textContent = rec.upi;
    document.getElementById('slipSexDob').textContent = `${rec.sex} / ${rec.birthdate || ''}`;
    document.getElementById('slipAddress').textContent = `${rec.purok_address || ''}`;
    document.getElementById('slipVisitDate').textContent = rec.visit_date;

    const grid = document.getElementById('slipStructuredGrid');
    const fields = rec.form_fields || {};
    const schemaFields = schema.fields || {};
    let gridHtml = '';
    for (const [k, v] of Object.entries(fields)) {
        const label = (schemaFields[k] && schemaFields[k].label) ? schemaFields[k].label : k;
        gridHtml += `<div class="col-6"><span class="text-muted">${label}:</span> <strong>${v}</strong></div>`;
    }
    if (!gridHtml) {
        gridHtml = '<div class="col-12 text-muted fst-italic">See narrative clinical findings below.</div>';
    }
    grid.innerHTML = gridHtml;

    document.getElementById('slipNarrative').textContent = rec.narrative_notes || rec.display_summary || 'None';
    document.getElementById('slipTreatment').textContent = rec.treatment_given || 'See clinical findings above';
    document.getElementById('slipNextVisit').textContent = rec.next_visit_date || 'As needed / PRN';
    document.getElementById('slipEncodedBy').textContent = rec.encoded_by_name || '';
    document.getElementById('slipVerifiedBy').textContent = rec.verified_by_name || 'Pending Verification';

    const modal = new bootstrap.Modal(document.getElementById('viewSlipModal'));
    modal.show();
}

document.addEventListener('DOMContentLoaded', () => {
    renderProgramSpecificFields({});
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<?php
/**
 * Tubtubon eHealth — Screen S10: Human-in-the-Loop (HITL) OCR Digitization (M8)
 * Phase 8 Sec. 51 (S10) | FR-25, FR-26, FR-27 | BR-10, BR-11, BR-12
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../modules/ocr_module.php';
require_once __DIR__ . '/../modules/patients_module.php';
require_once __DIR__ . '/../modules/documents_module.php';

requireRole([ROLE_NURSE, ROLE_BHW]);

$userId    = currentUserId();
$roleName  = getUserRole();
$flashMsg  = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole([ROLE_NURSE, ROLE_BHW]);
    verifyCsrf(appUrl('public/ocr.php'));
    $action = inputStr($_POST, 'action', '');

    if ($action === 'create_ocr_draft') {
        $res = createOcrDraft($_POST, $_FILES, $userId);
        if ($res['ok']) {
            $flashMsg = 'OCR Draft #' . $res['draft_id'] . ' generated and placed in the Human-in-the-Loop Verification Queue (BR-10).';
        } else {
            $flashMsg  = $res['error'] ?? 'Failed to create OCR draft.';
            $flashType = 'danger';
        }
    } elseif ($action === 'review_ocr_draft') {
        $draftId  = inputInt($_POST, 'draft_id', 0);
        $decision = inputStr($_POST, 'decision', '');
        $res      = processOcrDraftDecision($draftId, $decision, $_POST, $userId, $roleName);
        if ($res['ok']) {
            if ($decision === 'verify') {
                $flashMsg = 'OCR Draft #' . $draftId . ' verified and committed to Clinical Service Records as Record #' . ($res['record_id'] ?? '') . ' (BR-10).';
            } else {
                $flashMsg  = 'OCR Draft #' . $draftId . ' marked as Rejected and retained in D12 audit history (BR-10).';
                $flashType = 'warning';
            }
        } else {
            $flashMsg  = $res['error'] ?? 'Failed to process OCR draft decision.';
            $flashType = 'danger';
        }
    }
}

$statusFilter = inputStr($_GET, 'status', '');
$allDrafts    = listOcrDrafts('');
$drafts       = ($statusFilter !== '') ? listOcrDrafts($statusFilter) : $allDrafts;
$engineStatus = getOcrEngineStatus();
$allPatients  = searchPatients($pdo, '', 'Active', 300);
$pendingDocs  = searchDocuments($pdo, '', 'Pending Digitization');

$counts = ['Draft' => 0, 'Verified' => 0, 'Rejected' => 0];
foreach ($allDrafts as $d) {
    $st = (string)$d['status'];
    if (isset($counts[$st])) {
        $counts[$st]++;
    }
}

$pageTitle  = 'HITL OCR Digitization Queue (M8)';
$activePage = 'ocr';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>

<div class="container-fluid py-3">
    <!-- Top Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h4 class="fw-bold mb-0 text-dark">
                <i class="bi bi-file-earmark-break text-success me-2"></i>Human-in-the-Loop (HITL) OCR Digitization Queue
            </h4>
            <p class="text-muted small mb-0">
                Module M8 (FR-25 to FR-27 &bull; BR-10) &mdash; Scanned Standby Forms Must Be Verified by Staff Before Committing to Clinical Service Records
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= e(appUrl('public/service_records.php')) ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-journal-medical me-1"></i>Manual Encoding Fallback (FR-27)
            </a>
            <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#newOcrDraftModal">
                <i class="bi bi-scanner me-1"></i>Scan / Ingest Paper Form to OCR Draft
            </button>
        </div>
    </div>

    <?php if ($flashMsg !== ''): ?>
        <div class="alert alert-<?= e($flashType) ?> alert-dismissible fade show py-2 small" role="alert">
            <i class="bi bi-info-circle me-1"></i><?= e($flashMsg) ?>
            <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Engine & BR-10 Rule Banner -->
    <div class="alert alert-light border shadow-sm py-2 px-3 mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2 small">
        <div>
            <span class="badge <?= $engineStatus['available'] ? 'bg-success' : 'bg-info text-dark' ?> me-2">
                <i class="bi bi-cpu me-1"></i><?= e($engineStatus['version']) ?>
            </span>
            <span class="text-muted">
                <strong>BR-10 Safeguard:</strong> OCR output is stored strictly as a <code>Draft</code> in <code>D12 ocr_ingestion_drafts</code>. Fields with confidence <strong>&lt; <?= (int)OCR_LOW_CONFIDENCE_THRESHOLD ?>%</strong> are highlighted in <span class="text-danger fw-bold">red</span> for mandatory human review.
            </span>
        </div>
        <span class="badge bg-danger-subtle text-danger border border-danger-subtle">
            Low-Confidence Flag Threshold: &lt; <?= (int)OCR_LOW_CONFIDENCE_THRESHOLD ?>%
        </span>
    </div>

    <!-- Status Summary Cards -->
    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <a href="<?= e(appUrl('public/ocr.php')) ?>" class="card border-0 shadow-sm text-decoration-none h-100 <?= $statusFilter === '' ? 'border-start border-4 border-success' : '' ?>">
                <div class="card-body py-2 px-3">
                    <div class="small text-muted fw-semibold">All OCR Ingestion Drafts</div>
                    <div class="fs-4 fw-bold text-dark"><?= count($allDrafts) ?></div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="<?= e(appUrl('public/ocr.php?status=Draft')) ?>" class="card border-0 shadow-sm text-decoration-none h-100 <?= $statusFilter === 'Draft' ? 'border-start border-4 border-warning' : '' ?>">
                <div class="card-body py-2 px-3">
                    <div class="small text-muted fw-semibold">Pending HITL Review (Draft)</div>
                    <div class="fs-4 fw-bold text-warning-emphasis"><?= (int)$counts['Draft'] ?></div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="<?= e(appUrl('public/ocr.php?status=Verified')) ?>" class="card border-0 shadow-sm text-decoration-none h-100 <?= $statusFilter === 'Verified' ? 'border-start border-4 border-success' : '' ?>">
                <div class="card-body py-2 px-3">
                    <div class="small text-muted fw-semibold">Verified &amp; Committed to D4</div>
                    <div class="fs-4 fw-bold text-success"><?= (int)$counts['Verified'] ?></div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a href="<?= e(appUrl('public/ocr.php?status=Rejected')) ?>" class="card border-0 shadow-sm text-decoration-none h-100 <?= $statusFilter === 'Rejected' ? 'border-start border-4 border-danger' : '' ?>">
                <div class="card-body py-2 px-3">
                    <div class="small text-muted fw-semibold">Rejected (Retained in D12)</div>
                    <div class="fs-4 fw-bold text-danger"><?= (int)$counts['Rejected'] ?></div>
                </div>
            </a>
        </div>
    </div>

    <!-- OCR Ingestion Drafts Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-2 px-3 d-flex justify-content-between align-items-center">
            <span class="fw-semibold small text-dark">
                OCR Ingestion Queue (<code>D12 ocr_ingestion_drafts</code>) &mdash; Showing <?= count($drafts) ?> Item(s)
            </span>
            <span class="small text-muted">Click "Review &amp; Verify" to inspect flagged fields</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Draft ID</th>
                        <th>Patient (UPI)</th>
                        <th>Program &amp; Source Document</th>
                        <th>Low-Confidence Flagged Fields (&lt; <?= (int)OCR_LOW_CONFIDENCE_THRESHOLD ?>%)</th>
                        <th>Extracted OCR Preview</th>
                        <th>HITL Status</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($drafts)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">
                                <i class="bi bi-inbox fs-4 d-block mb-1"></i>
                                No OCR drafts found for this filter.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($drafts as $d): ?>
                            <?php
                            $st = (string)$d['status'];
                            $stBadge = match ($st) {
                                'Draft'    => 'bg-warning-subtle text-warning-emphasis border-warning',
                                'Verified' => 'bg-success-subtle text-success border-success',
                                'Rejected' => 'bg-danger-subtle text-danger border-danger',
                                default    => 'bg-secondary-subtle text-secondary',
                            };
                            $flaggedArr = $d['flagged_fields_arr'] ?? [];
                            $scoresArr  = $d['confidence_scores_arr'] ?? [];
                            $jsonDraft  = htmlspecialchars(json_encode($d, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
                            ?>
                            <tr>
                                <td class="fw-bold text-nowrap">
                                    #<?= (int)$d['draft_id'] ?>
                                    <div class="text-muted fw-normal" style="font-size: 0.71rem;">
                                        <?= e(substr((string)$d['created_at'], 0, 16)) ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark">
                                        <?= e($d['last_name'] . ', ' . $d['first_name']) ?>
                                    </div>
                                    <span class="badge bg-light text-dark border font-monospace" style="font-size: 0.72rem;">
                                        <?= e($d['upi']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">
                                        <?= e($d['program_type']) ?>
                                    </span>
                                    <div class="text-dark mt-1 fw-semibold" style="font-size: 0.75rem;">
                                        <i class="bi bi-folder2-open me-1 text-muted"></i><?= e($d['document_title']) ?>
                                    </div>
                                    <div class="text-muted" style="font-size: 0.7rem;">
                                        Loc: <?= e(($d['cabinet_location'] ?? '-') . ' / ' . ($d['box_location'] ?? '-') . ' / ' . ($d['folder_location'] ?? '-')) ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($flaggedArr)): ?>
                                        <div class="d-flex flex-wrap gap-1">
                                            <?php foreach ($flaggedArr as $ff): ?>
                                                <?php $sc = isset($scoresArr[$ff]) ? (int)$scoresArr[$ff] : null; ?>
                                                <span class="badge bg-danger-subtle text-danger border border-danger" style="font-size: 0.72rem;">
                                                    <i class="bi bi-exclamation-triangle-fill me-1"></i><?= e((string)$ff) ?>
                                                    <?= $sc !== null ? '(' . $sc . '%)' : '' ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge bg-success-subtle text-success border">All fields &ge; <?= (int)OCR_LOW_CONFIDENCE_THRESHOLD ?>%</span>
                                    <?php endif; ?>
                                </td>
                                <td style="max-width: 290px;">
                                    <div class="font-monospace text-muted bg-light border rounded p-1" style="font-size: 0.72rem; white-space: pre-wrap; max-height: 64px; overflow: hidden;">
                                        <?= e(mb_strimwidth((string)$d['ocr_raw_output'], 0, 170, '...')) ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge border <?= e($stBadge) ?>"><?= e($st) ?></span>
                                    <?php if (!empty($d['verified_by_name'])): ?>
                                        <div class="text-muted mt-1" style="font-size: 0.7rem;">
                                            By: <?= e((string)$d['verified_by_name']) ?><br>
                                            <?= e(substr((string)($d['verified_at'] ?? ''), 0, 16)) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <?php if ($st === 'Draft'): ?>
                                        <button type="button" class="btn btn-success btn-sm py-1 px-2"
                                                onclick='openOcrReviewModal(<?= $jsonDraft ?>)'>
                                            <i class="bi bi-eye-fill me-1"></i>Review &amp; Verify
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-outline-secondary btn-sm py-1 px-2"
                                                onclick='openOcrReviewModal(<?= $jsonDraft ?>)'>
                                            <i class="bi bi-info-circle me-1"></i>Inspect
                                        </button>
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

<!-- Modal 1: Create / Ingest Paper Form into OCR Draft -->
<div class="modal fade" id="newOcrDraftModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" action="<?= e(appUrl('public/ocr.php')) ?>" enctype="multipart/form-data" class="modal-content">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create_ocr_draft">

            <div class="modal-header bg-success text-white py-2">
                <h6 class="modal-title fw-bold">
                    <i class="bi bi-scanner me-2"></i>Scan / Ingest Standby Paper Form into HITL OCR Queue
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info py-2 px-3 small mb-3">
                    <i class="bi bi-shield-check me-1"></i>
                    <strong>BR-10 Compliance:</strong> Submitting a scan creates an unverified <code>Draft</code> in <code>D12 ocr_ingestion_drafts</code> with confidence scores and red-flagged handwritten fields. It will not enter <code>D4 service_records</code> until verified.
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold">Option A: Select Existing Document Queued for Digitization (Optional)</label>
                    <select name="document_id" id="ocrDocSelect" class="form-select form-select-sm" onchange="toggleNewScanFields()">
                        <option value="0">-- Or Upload a New Scanned Standby Form Below (Option B) --</option>
                        <?php foreach ($pendingDocs as $pdoc): ?>
                            <option value="<?= (int)$pdoc['document_id'] ?>">
                                Doc #<?= (int)$pdoc['document_id'] ?>: <?= e($pdoc['upi'] . ' — ' . $pdoc['title'] . ' (' . $pdoc['document_type'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="newScanFieldsBlock">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Patient (Resident UPI)</label>
                            <select name="patient_id" id="ocrPatientId" class="form-select form-select-sm">
                                <option value="">-- Select Patient --</option>
                                <?php foreach ($allPatients as $pt): ?>
                                    <option value="<?= (int)$pt['patient_id'] ?>">
                                        <?= e($pt['upi'] . ' — ' . $pt['last_name'] . ', ' . $pt['first_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Official Clinic Form / Program Type (BR-02)</label>
                            <select name="program_type" class="form-select form-select-sm">
                                <?php foreach (PROGRAM_TYPES as $pt): ?>
                                    <option value="<?= e($pt) ?>"><?= e($pt) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold">Encounter Date on Form</label>
                            <input type="date" name="visit_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small fw-bold">Upload Scanned Image / PDF (Optional in Demo Mode)</label>
                            <input type="file" name="scan_file" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,.webp">
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small text-muted mb-1">Physical Cabinet</label>
                            <input type="text" name="cabinet_location" class="form-control form-control-sm" value="Cabinet A">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted mb-1">Box Location</label>
                            <input type="text" name="box_location" class="form-control form-control-sm" value="Box 1">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small text-muted mb-1">Folder Code</label>
                            <input type="text" name="folder_location" class="form-control form-control-sm" placeholder="Folder OCR-01">
                        </div>
                    </div>
                </div>

                <div>
                    <label class="form-label small fw-bold">Handwritten Margin Notes / Additional OCR Text Hints (Optional)</label>
                    <textarea name="scan_notes" rows="2" class="form-control form-control-sm"
                              placeholder="Any handwritten notes observed on the paper standby form..."></textarea>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success btn-sm">
                    <i class="bi bi-magic me-1"></i>Run OCR Extraction &amp; Create Draft
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 2: Interactive HITL Verification & Field Correction Modal -->
<div class="modal fade" id="ocrReviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <form method="POST" action="<?= e(appUrl('public/ocr.php')) ?>" class="modal-content">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="review_ocr_draft">
            <input type="hidden" name="draft_id" id="revDraftId" value="0">
            <input type="hidden" name="decision" id="revDecisionInput" value="verify">

            <div class="modal-header bg-dark text-white py-2">
                <h6 class="modal-title fw-bold" id="revModalTitle">
                    <i class="bi bi-check2-square me-2"></i>Human-in-the-Loop OCR Verification
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <div class="row g-3">
                    <!-- Left Column: Confidence Scores, Red Flagged Fields, & Source Document Metadata -->
                    <div class="col-lg-5 border-end">
                        <div class="p-2 bg-light rounded border mb-3 small">
                            <div class="fw-bold text-dark mb-1">
                                <i class="bi bi-person-vcard text-success me-1"></i>Patient &amp; Source Document
                            </div>
                            <div><strong>Patient:</strong> <span id="revPatientName"></span> (<span id="revPatientCode" class="font-monospace"></span>)</div>
                            <div><strong>Document:</strong> <span id="revDocTitle"></span></div>
                            <div><strong>Physical Folder:</strong> <span id="revPhysicalLoc" class="text-muted"></span></div>
                            <div class="mt-2" id="revDownloadLinkWrap"></div>
                        </div>

                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="fw-bold small text-danger">
                                    <i class="bi bi-exclamation-octagon-fill me-1"></i>Flagged Low-Confidence Fields (&lt; <?= (int)OCR_LOW_CONFIDENCE_THRESHOLD ?>%)
                                </span>
                            </div>
                            <div id="revFlaggedContainer" class="p-2 rounded border border-danger bg-danger-subtle small"></div>
                        </div>

                        <div>
                            <div class="fw-bold small text-dark mb-2">
                                <i class="bi bi-bar-chart-steps text-success me-1"></i>Per-Field OCR Confidence Scores
                            </div>
                            <div id="revScoresContainer" class="small"></div>
                        </div>
                    </div>

                    <!-- Right Column: Editable Clinical Fields to Commit to D4 service_records -->
                    <div class="col-lg-7">
                        <div class="alert alert-warning py-2 px-3 small mb-3" id="revInstructionBanner">
                            <i class="bi bi-pencil-square me-1"></i>
                            <strong>Mandatory Human Review (FR-25 / FR-26):</strong> Please verify and correct any red-flagged fields in the extracted text below before committing to <code>D4 service_records</code>.
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">Program Type (BR-02)</label>
                                <select name="program_type" id="revProgramType" class="form-select form-select-sm">
                                    <?php foreach (PROGRAM_TYPES as $pt): ?>
                                        <option value="<?= e($pt) ?>"><?= e($pt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-bold">Encounter Date</label>
                                <input type="date" name="visit_date" id="revVisitDate" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-bold">Next Visit Date</label>
                                <input type="date" name="next_visit_date" id="revNextVisitDate" class="form-control form-control-sm">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">
                                Extracted &amp; Corrected Clinical Findings (Commits to <code>service_records.findings_notes</code>)
                            </label>
                            <textarea name="ocr_raw_output" id="revRawOutput" rows="8" class="form-control form-control-sm font-monospace" required></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Health Services Given / Treatment</label>
                            <input type="text" name="treatment_given" id="revTreatmentGiven" class="form-control form-control-sm"
                                   placeholder="Verified treatment / medicines dispensed from scanned form...">
                        </div>

                        <div id="revRejectReasonWrap" class="border-top pt-2">
                            <label class="form-label small text-muted mb-1">Rejection Note (Only used if clicking "Reject Draft")</label>
                            <input type="text" name="rejection_reason" id="revRejectionReason" class="form-control form-control-sm"
                                   placeholder="e.g., Illegible scan / duplicate standby sheet / wrong patient folder">
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer py-2 d-flex justify-content-between" id="revModalFooter">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                <div class="d-flex gap-2" id="revActionButtons">
                    <button type="submit" class="btn btn-outline-danger btn-sm"
                            onclick="document.getElementById('revDecisionInput').value='reject';">
                        <i class="bi bi-x-circle me-1"></i>Reject Draft (Retain in D12)
                    </button>
                    <button type="submit" class="btn btn-success btn-sm"
                            onclick="document.getElementById('revDecisionInput').value='verify';">
                        <i class="bi bi-check2-all me-1"></i>Verify &amp; Commit to Service Records (D4)
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
const LOW_CONF_THRESHOLD = <?= (int)OCR_LOW_CONFIDENCE_THRESHOLD ?>;

function toggleNewScanFields() {
    const docId = parseInt(document.getElementById('ocrDocSelect').value || '0', 10);
    const block = document.getElementById('newScanFieldsBlock');
    block.style.opacity = docId > 0 ? '0.55' : '1';
}

function openOcrReviewModal(draft) {
    document.getElementById('revDraftId').value = draft.draft_id;
    document.getElementById('revModalTitle').innerHTML =
        `<i class="bi bi-file-earmark-check me-2"></i>HITL OCR Draft #${draft.draft_id} — Status: ${draft.status}`;
    document.getElementById('revPatientName').textContent = `${draft.last_name}, ${draft.first_name}`;
    document.getElementById('revPatientCode').textContent = draft.upi;
    document.getElementById('revDocTitle').textContent = draft.document_title || '';
    document.getElementById('revPhysicalLoc').textContent =
        `${draft.cabinet_location || '-'} / ${draft.box_location || '-'} / ${draft.folder_location || '-'}`;

    const dlWrap = document.getElementById('revDownloadLinkWrap');
    if (draft.file_path) {
        dlWrap.innerHTML = `<a href="<?= e(appUrl('public/document_download.php')) ?>?id=${draft.document_id}" target="_blank" class="btn btn-outline-primary btn-sm py-0 px-2">
            <i class="bi bi-file-earmark-pdf me-1"></i>View Attached Scan File
        </a>`;
    } else {
        dlWrap.innerHTML = `<span class="badge bg-secondary-subtle text-secondary">Paper Folder Queued (${draft.source_file_path || 'No digital attachment'})</span>`;
    }

    const flagged = draft.flagged_fields_arr || [];
    const scores = draft.confidence_scores_arr || {};
    const flaggedEl = document.getElementById('revFlaggedContainer');

    if (flagged.length > 0) {
        let fHtml = '<div class="mb-1 fw-semibold text-danger">Please verify these low-confidence handwritten fields:</div><div class="d-flex flex-wrap gap-1">';
        for (const f of flagged) {
            const sc = scores[f] !== undefined ? `${scores[f]}%` : '<75%';
            fHtml += `<span class="badge bg-danger text-white"><i class="bi bi-exclamation-triangle me-1"></i>${f} (${sc})</span>`;
        }
        fHtml += '</div>';
        flaggedEl.innerHTML = fHtml;
        flaggedEl.className = 'p-2 rounded border border-danger bg-danger-subtle small';
    } else {
        flaggedEl.innerHTML = '<span class="text-success fw-semibold"><i class="bi bi-check-circle me-1"></i>No fields fell below the 75% confidence threshold.</span>';
        flaggedEl.className = 'p-2 rounded border border-success bg-success-subtle small';
    }

    const scoresEl = document.getElementById('revScoresContainer');
    let sHtml = '';
    for (const [field, score] of Object.entries(scores)) {
        const numScore = parseInt(score, 10) || 0;
        const isLow = numScore < LOW_CONF_THRESHOLD;
        const barCls = isLow ? 'bg-danger' : 'bg-success';
        const textCls = isLow ? 'text-danger fw-bold' : 'text-dark';
        sHtml += `<div class="mb-2">
            <div class="d-flex justify-content-between ${textCls}" style="font-size: 0.75rem;">
                <span>${field} ${isLow ? '<i class="bi bi-flag-fill text-danger ms-1"></i>' : ''}</span>
                <span>${numScore}%</span>
            </div>
            <div class="progress" style="height: 6px;">
                <div class="progress-bar ${barCls}" role="progressbar" style="width: ${numScore}%"></div>
            </div>
        </div>`;
    }
    scoresEl.innerHTML = sHtml || '<div class="text-muted">No granular score metrics recorded.</div>';

    document.getElementById('revProgramType').value = draft.program_type || 'General Consultation';
    document.getElementById('revRawOutput').value = draft.ocr_raw_output || '';
    document.getElementById('revTreatmentGiven').value =
        `Verified from digitized ${draft.program_type || 'Clinic'} form (OCR Draft #${draft.draft_id})`;

    const isEditable = (draft.status === 'Draft');
    document.getElementById('revRawOutput').readOnly = !isEditable;
    document.getElementById('revTreatmentGiven').readOnly = !isEditable;
    document.getElementById('revActionButtons').style.display = isEditable ? 'flex' : 'none';
    document.getElementById('revRejectReasonWrap').style.display = isEditable ? 'block' : 'none';

    const modal = new bootstrap.Modal(document.getElementById('ocrReviewModal'));
    modal.show();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

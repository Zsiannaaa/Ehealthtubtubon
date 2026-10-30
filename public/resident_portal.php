<?php
// ============================================================================
// public/resident_portal.php — Screen S12: Controlled Resident Self-Access Portal
// Covers:
//   - FR-24: Controlled resident portal strictly limited to own data:
//            own demographic info, own schedule view, and own service updates
//   - BR-11: Enforces least-privilege isolation bound to `$_SESSION['bound_patient_id']`.
//            A resident can NEVER query or view another patient's record.
// ============================================================================
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../modules/patients_module.php';

requireRole([ROLE_RESIDENT]);

// FR-24 / BR-11: Strict FK-bound isolation. NEVER fall back to patient_id = 1.
$boundPatientId = (int) ($_SESSION['bound_patient_id'] ?? 0);
$history        = ($boundPatientId > 0) ? getPatientUnifiedHistory($pdo, $boundPatientId) : [];
$patient        = $history['patient'] ?? null;

if ($boundPatientId <= 0 || !$patient) {
    logAudit(
        $pdo,
        'ACCESS_DENIED',
        'Resident portal access blocked due to missing or invalid users.patient_id FK binding',
        'users',
        currentUserId(),
        currentUserId()
    );
}

$pageTitle    = 'My Health Portal (Resident Self-Access)';
$pageSubtitle = 'FR-24 & BR-11 • Controlled Self-Access Strictly Bound to Your Own UPI';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="alert alert-info d-flex align-items-center justify-content-between mb-4">
  <div>
    <i class="bi bi-shield-lock-fill me-2"></i>
    <strong>Strict Data Isolation Active (FR-24 / BR-11):</strong> You are signed in as a <strong>Resident</strong>. Your session is cryptographically bound to your own Master Patient Index record (<code><?= e($patient['upi'] ?? 'N/A') ?></code>). You cannot view other residents' records or modify clinical entries.
  </div>
</div>

<?php if (!$patient): ?>
  <div class="card"><div class="card-body text-muted">No linked patient profile found for this resident account.</div></div>
<?php else: ?>
<div class="row g-3 mb-4">
  <!-- 1. My Personal & UPI Profile -->
  <div class="col-12 col-lg-4">
    <div class="card h-100 border-success-subtle">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h6 class="fw-700 text-green mb-0">
            <i data-lucide="id-card" class="me-1"></i>My Health Center Profile
          </h6>
          <span class="badge bg-success"><?= e($patient['upi']) ?></span>
        </div>

        <dl class="row small mb-0">
          <dt class="col-5 text-muted">Full Name</dt>
          <dd class="col-7 fw-bold"><?= e($patient['last_name'] . ', ' . $patient['first_name'] . ($patient['middle_name'] ? ' ' . $patient['middle_name'] : '')) ?></dd>

          <dt class="col-5 text-muted">Sex &amp; Age</dt>
          <dd class="col-7"><?= e($patient['sex']) ?> • <?= e(calculatePatientAge($patient['birthdate'])) ?></dd>

          <dt class="col-5 text-muted">Birthdate</dt>
          <dd class="col-7"><?= e($patient['birthdate']) ?></dd>

          <dt class="col-5 text-muted">Purok / Address</dt>
          <dd class="col-7"><?= e($patient['purok_address']) ?></dd>

          <dt class="col-5 text-muted">Mobile Number</dt>
          <dd class="col-7"><?= e($patient['contact_no'] ?: 'None on file') ?></dd>

          <dt class="col-5 text-muted">Guardian / Spouse</dt>
          <dd class="col-7"><?= e($patient['guardian_name'] ?: '—') ?></dd>
        </dl>
      </div>
    </div>
  </div>

  <!-- 2. My Scheduled Visits -->
  <div class="col-12 col-lg-8">
    <div class="card h-100">
      <div class="card-body">
        <h6 class="fw-700 text-green mb-3">
          <i data-lucide="calendar-check" class="me-1"></i>My Scheduled Appointments &amp; Follow-Ups
        </h6>
        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Scheduled Date</th>
                <th>Time</th>
                <th>Health Program</th>
                <th>Status</th>
                <th>SMS Reminder</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($history['appointments'])): ?>
              <tr>
                <td colspan="5" class="text-center text-muted py-3">You have no scheduled appointments.</td>
              </tr>
              <?php else: ?>
                <?php foreach ($history['appointments'] as $apt): ?>
                <tr>
                  <td class="fw-600 small"><?= e($apt['scheduled_date']) ?></td>
                  <td class="small"><?= $apt['scheduled_time'] ? e(substr($apt['scheduled_time'], 0, 5)) : 'Whole Day' ?></td>
                  <td><span class="badge bg-light text-dark border"><?= e($apt['program_type']) ?></span></td>
                  <td><span class="badge bg-success-subtle text-success"><?= e($apt['status']) ?></span></td>
                  <td class="small"><?= ((int) $apt['reminder_sent'] === 1) ? 'Sent to mobile' : 'Pending' ?></td>
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

<!-- 3. My Recorded Health Service History -->
<div class="card">
  <div class="card-body">
    <h6 class="fw-700 text-green mb-3">
      <i data-lucide="clipboard-list" class="me-1"></i>My Health Service Updates &amp; Visit History
    </h6>
    <div class="table-responsive">
      <table class="table table-sm table-striped align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Service Date</th>
            <th>Program</th>
            <th>Form Ref No.</th>
            <th>Summary / Service Notes</th>
            <th>Attending Staff</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($history['encounters'])): ?>
          <tr>
            <td colspan="5" class="text-center text-muted py-3">No service records found.</td>
          </tr>
          <?php else: ?>
            <?php foreach ($history['encounters'] as $sr): ?>
            <tr>
              <td class="small fw-600"><?= e($sr['service_date']) ?></td>
              <td><span class="badge bg-success-subtle text-success"><?= e($sr['program_type']) ?></span></td>
              <td><code><?= e($sr['form_reference_no'] ?: 'N/A') ?></code></td>
              <td class="small"><?= nl2br(e(formatClinicalNotesForDisplay((string) ($sr['findings_notes'] ?? '')))) ?></td>
              <td class="small text-muted"><?= e($sr['verified_by_name'] ?: ($sr['recorded_by_name'] ?? 'Clinic Staff')) ?></td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

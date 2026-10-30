<?php
// ============================================================================
// public/scheduling.php — Screen S8: Service Scheduling & 4-Status Calendar
// Covers:
//   - FR-20 / BR-08: Manage appointments across the 6 programs with 4 statuses
//                    (Scheduled | Completed | Cancelled | No-Show) and reminder_sent
//   - BR-11: Nurse/Midwife & BHW create/update schedules; Administrator has View-Only
// ============================================================================
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../modules/appointments_sms_module.php';
require_once __DIR__ . '/../modules/patients_module.php';

requireRole([ROLE_ADMIN, ROLE_NURSE, ROLE_BHW]);

$currentRole   = getUserRole();
$canSchedule   = in_array($currentRole, [ROLE_NURSE, ROLE_BHW], true);
$currentUserId = currentUserId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole([ROLE_NURSE, ROLE_BHW]);
    verifyCsrf(appUrl('public/scheduling.php'));

    $action = inputStr($_POST, 'action');

    if ($action === 'create_appointment') {
        $res = createAppointment(
            $pdo,
            inputInt($_POST, 'patient_id'),
            inputStr($_POST, 'program_type'),
            inputStr($_POST, 'scheduled_date'),
            inputStr($_POST, 'scheduled_time') ?: null,
            $currentUserId
        );
        setFlash($res['success'] ? 'success' : 'error', $res['success']
            ? 'Appointment scheduled successfully (`reminder_sent = 0`).'
            : ($res['error'] ?? 'Could not schedule appointment.'));
        header('Location: ' . appUrl('public/scheduling.php'));
        exit();
    }

    if ($action === 'update_status') {
        $err = updateAppointmentStatus(
            $pdo,
            inputInt($_POST, 'appointment_id'),
            inputStr($_POST, 'new_status'),
            $currentUserId
        );
        setFlash($err ? 'error' : 'success', $err ?? 'Appointment status updated (BR-08).');
        header('Location: ' . appUrl('public/scheduling.php'));
        exit();
    }
}

$statusFilter  = inputStr($_GET, 'status');
$programFilter = inputStr($_GET, 'program');
$dateFilter    = inputStr($_GET, 'date');

$appointments = getAppointments($pdo, $statusFilter, $programFilter, $dateFilter);
$allPatients  = searchPatients($pdo, '', 'Active', 200);

// Status counts
$statusCounts = array_fill_keys(APPOINTMENT_STATUSES, 0);
$sRows = $pdo->query("SELECT status, COUNT(*) AS cnt FROM appointments GROUP BY status")->fetchAll();
foreach ($sRows as $sr) {
    if (isset($statusCounts[$sr['status']])) {
        $statusCounts[$sr['status']] = (int) $sr['cnt'];
    }
}

$pageTitle    = 'Service Scheduling & Appointment Lifecycle';
$pageSubtitle = 'FR-20 • BR-02 6 Programs • BR-08 4 Statuses (Scheduled, Completed, Cancelled, No-Show)';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- 4-STATUS SUMMARY CARDS -->
<div class="row g-3 mb-4">
  <div class="col-6 col-xl-3">
    <a href="<?= e(appUrl('public/scheduling.php?status=Scheduled')) ?>" class="stat-card tint-pine d-block text-decoration-none">
      <div class="stat-label">Scheduled</div>
      <div class="stat-value"><?= (int) $statusCounts['Scheduled'] ?></div>
      <div class="stat-foot"><span class="stat-hint">Upcoming visits</span></div>
    </a>
  </div>
  <div class="col-6 col-xl-3">
    <a href="<?= e(appUrl('public/scheduling.php?status=Completed')) ?>" class="stat-card tint-mint d-block text-decoration-none">
      <div class="stat-label">Completed</div>
      <div class="stat-value"><?= (int) $statusCounts['Completed'] ?></div>
      <div class="stat-foot"><span class="stat-hint">Attended encounters</span></div>
    </a>
  </div>
  <div class="col-6 col-xl-3">
    <a href="<?= e(appUrl('public/scheduling.php?status=No-Show')) ?>" class="stat-card tint-peach d-block text-decoration-none">
      <div class="stat-label">No-Show</div>
      <div class="stat-value"><?= (int) $statusCounts['No-Show'] ?></div>
      <div class="stat-foot"><span class="stat-hint">Requires BHW follow-up</span></div>
    </a>
  </div>
  <div class="col-6 col-xl-3">
    <a href="<?= e(appUrl('public/scheduling.php?status=Cancelled')) ?>" class="stat-card tint-blue d-block text-decoration-none">
      <div class="stat-label">Cancelled</div>
      <div class="stat-value"><?= (int) $statusCounts['Cancelled'] ?></div>
      <div class="stat-foot"><span class="stat-hint">Rescheduled / cancelled</span></div>
    </a>
  </div>
</div>

<div class="row g-3">
  <!-- Left Column: Book Appointment Form (Nurse/Midwife & BHW) -->
  <?php if ($canSchedule): ?>
  <div class="col-12 col-lg-4">
    <div class="card h-100">
      <div class="card-body">
        <h6 class="fw-700 text-green mb-3">
          <i data-lucide="calendar-plus" class="me-1"></i>Schedule Recurring / Follow-Up Visit (FR-20)
        </h6>

        <form method="POST" action="<?= e(appUrl('public/scheduling.php')) ?>">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="create_appointment">

          <div class="mb-2">
            <label class="form-label small fw-600">Patient (UPI) <span class="text-danger">*</span></label>
            <select name="patient_id" class="form-select form-select-sm" required>
              <option value="">-- Select Patient --</option>
              <?php foreach ($allPatients as $p): ?>
                <option value="<?= (int) $p['patient_id'] ?>">
                  <?= e($p['upi'] . ' — ' . $p['last_name'] . ', ' . $p['first_name']) ?>
                  <?= ((int) ($p['sms_consent'] ?? 0) === 1) ? '[SMS OK]' : '[No SMS Consent]' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-2">
            <label class="form-label small fw-600">Program Type (BR-02) <span class="text-danger">*</span></label>
            <select name="program_type" class="form-select form-select-sm" required>
              <?php foreach (PROGRAM_TYPES as $pt): ?>
                <option value="<?= e($pt) ?>"><?= e($pt) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-7">
              <label class="form-label small fw-600">Scheduled Date <span class="text-danger">*</span></label>
              <input type="date" name="scheduled_date" class="form-control form-control-sm"
                     value="<?= e(date('Y-m-d')) ?>" required>
            </div>
            <div class="col-5">
              <label class="form-label small fw-600">Time (Optional)</label>
              <input type="time" name="scheduled_time" class="form-control form-control-sm" value="08:30">
            </div>
          </div>

          <button type="submit" class="btn btn-success btn-sm w-100" style="background:#0B3B22;border-color:#0B3B22;">
            <i class="bi bi-calendar-check me-1"></i>Save Appointment
          </button>
        </form>

        <hr class="my-3">
        <div class="small text-muted">
          <strong>BR-08 Rule:</strong> New appointments start as <code>Scheduled</code> with <code>reminder_sent = 0</code>. Sending an SMS reminder in <a href="<?= e(appUrl('public/sms.php')) ?>">SMS Compose &amp; Logs</a> automatically flips <code>reminder_sent = 1</code> once logged to <code>sms_logs</code>.
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Right Column: Appointments Schedule Table -->
  <div class="col-12 <?= $canSchedule ? 'col-lg-8' : 'col-lg-12' ?>">
    <div class="card h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
          <div>
            <h6 class="fw-700 text-green mb-0">
              <i data-lucide="calendar" class="me-1"></i>Clinic Appointments (`appointments` — <?= count($appointments) ?>)
            </h6>
            <small class="text-muted">Manage status transitions or send consent-verified SMS reminders.</small>
          </div>

          <form method="GET" action="<?= e(appUrl('public/scheduling.php')) ?>" class="d-flex gap-2 flex-wrap">
            <input type="date" name="date" class="form-control form-control-sm" style="width:auto;" value="<?= e($dateFilter) ?>">
            <select name="program" class="form-select form-select-sm" style="width:auto;">
              <option value="">All 6 Programs</option>
              <?php foreach (PROGRAM_TYPES as $pt): ?>
                <option value="<?= e($pt) ?>" <?= ($programFilter === $pt) ? 'selected' : '' ?>><?= e($pt) ?></option>
              <?php endforeach; ?>
            </select>
            <select name="status" class="form-select form-select-sm" style="width:auto;">
              <option value="">All 4 Statuses</option>
              <?php foreach (APPOINTMENT_STATUSES as $st): ?>
                <option value="<?= e($st) ?>" <?= ($statusFilter === $st) ? 'selected' : '' ?>><?= e($st) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-outline-success">Filter</button>
            <?php if ($statusFilter !== '' || $programFilter !== '' || $dateFilter !== ''): ?>
              <a href="<?= e(appUrl('public/scheduling.php')) ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
            <?php endif; ?>
          </form>
        </div>

        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Date / Time</th>
                <th>Patient (UPI)</th>
                <th>Program</th>
                <th>Status (BR-08)</th>
                <th>SMS Reminder</th>
                <?php if ($canSchedule): ?>
                  <th class="text-end">Actions</th>
                <?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($appointments)): ?>
              <tr>
                <td colspan="6" class="text-center text-muted py-4">No appointments match your filter.</td>
              </tr>
              <?php else: ?>
                <?php foreach ($appointments as $apt):
                  $aBadge = match ($apt['status']) {
                      'Scheduled' => 'bg-primary-subtle text-primary border-primary-subtle',
                      'Completed' => 'bg-success-subtle text-success border-success-subtle',
                      'No-Show'   => 'bg-danger-subtle text-danger border-danger-subtle',
                      'Cancelled' => 'bg-secondary-subtle text-secondary border-secondary-subtle',
                      default     => 'bg-light text-dark',
                  };
                ?>
                <tr>
                  <td class="small fw-600 text-nowrap">
                    <?= e($apt['scheduled_date']) ?><br>
                    <span class="text-muted fw-normal"><?= $apt['scheduled_time'] ? e(substr($apt['scheduled_time'], 0, 5)) : 'Whole Day' ?></span>
                  </td>
                  <td class="small">
                    <strong><?= e($apt['last_name'] . ', ' . $apt['first_name']) ?></strong><br>
                    <code><?= e($apt['upi']) ?></code>
                  </td>
                  <td><span class="badge bg-light text-dark border"><?= e($apt['program_type']) ?></span></td>
                  <td><span class="badge border <?= e($aBadge) ?>"><?= e($apt['status']) ?></span></td>
                  <td class="small">
                    <?php if ((int) $apt['reminder_sent'] === 1): ?>
                      <span class="badge bg-success-subtle text-success border border-success-subtle">
                        <i class="bi bi-check2-all"></i> Sent (`1`)
                      </span>
                    <?php elseif ((int) ($apt['sms_consent'] ?? 0) === 1): ?>
                      <span class="badge bg-warning-subtle text-warning-emphasis">Not Sent (`0`)</span>
                    <?php else: ?>
                      <span class="badge bg-secondary-subtle text-secondary" title="BR-09: No SMS consent">No Consent</span>
                    <?php endif; ?>
                  </td>
                  <?php if ($canSchedule): ?>
                  <td class="text-end text-nowrap">
                    <div class="d-inline-flex gap-1 align-items-center">
                      <form method="POST" action="<?= e(appUrl('public/scheduling.php')) ?>" class="d-inline-flex gap-1">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="appointment_id" value="<?= (int) $apt['appointment_id'] ?>">
                        <select name="new_status" class="form-select form-select-sm" style="width:118px;font-size:12px;" onchange="this.form.submit()">
                          <?php foreach (APPOINTMENT_STATUSES as $st): ?>
                            <option value="<?= e($st) ?>" <?= ($apt['status'] === $st) ? 'selected' : '' ?>><?= e($st) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </form>
                      <?php if ($apt['status'] === 'Scheduled'): ?>
                      <a href="<?= e(appUrl('public/sms.php?appointment_id=' . (int) $apt['appointment_id'])) ?>"
                         class="btn btn-sm btn-outline-success" title="Send Templated SMS Reminder">
                        <i class="bi bi-chat-dots"></i> SMS
                      </a>
                      <?php endif; ?>
                    </div>
                  </td>
                  <?php endif; ?>
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

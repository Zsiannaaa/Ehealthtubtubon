<?php
// ============================================================================
// public/sms.php — Screen S9: Outbound Privacy-Safe SMS Reminders & Delivery Log
// Covers:
//   - FR-21 / BR-09: Outbound-only SMS via Philippine gateway adapter using
//                    approved privacy-safe templates, strictly requiring valid
//                    `consent_records` (`consent_given = 1`)
//   - FR-22 / BR-08: Logs recipient_number snapshot, gateway_used, delivery_status,
//                    and sets `appointments.reminder_sent = 1` ONLY after log commit
//   - BR-11: Nurse/Midwife & BHW (per clinic policy) can send; Admin views logs
// ============================================================================
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../modules/appointments_sms_module.php';
require_once __DIR__ . '/../modules/patients_module.php';

requireRole([ROLE_ADMIN, ROLE_NURSE, ROLE_BHW]);

$currentRole   = getUserRole();
$canSendSms    = in_array($currentRole, [ROLE_NURSE, ROLE_BHW], true);
$currentUserId = currentUserId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole([ROLE_NURSE, ROLE_BHW]);
    verifyCsrf(appUrl('public/sms.php'));

    $action = inputStr($_POST, 'action');
    if ($action === 'send_sms') {
        $appointmentId = inputInt($_POST, 'appointment_id') ?: null;
        $patientId     = inputInt($_POST, 'patient_id');

        if ($appointmentId && $patientId <= 0) {
            $aStmt = $pdo->prepare("SELECT patient_id FROM appointments WHERE appointment_id = :aid LIMIT 1");
            $aStmt->execute([':aid' => $appointmentId]);
            $patientId = (int) $aStmt->fetchColumn();
        }

        $res = sendOutboundSmsReminder(
            $pdo,
            $patientId,
            $appointmentId,
            inputStr($_POST, 'template_key', 'appointment_reminder'),
            $currentUserId
        );

        setFlash($res['success'] ? 'success' : 'error', $res['success']
            ? ($res['message'] ?? 'SMS reminder dispatched and logged.')
            : ($res['error'] ?? 'SMS blocked or failed.'));
        header('Location: ' . appUrl('public/sms.php'));
        exit();
    }
}

$selectedApptId = inputInt($_GET, 'appointment_id');
$scheduledAppts = getAppointments($pdo, 'Scheduled');
$allPatients    = searchPatients($pdo, '', 'Active', 200);
$smsLogs        = getSmsLogs($pdo, 100);

$pageTitle    = 'Outbound SMS Reminders, Consent Verification & Delivery Logs';
$pageSubtitle = 'FR-21 & FR-22 • BR-08 & BR-09 Consent-Gated Privacy-Safe Templates (`sms_logs` & `consent_records`)';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row g-3">
  <!-- Left Column: Privacy-Safe SMS Composer (Nurse/Midwife & BHW) -->
  <?php if ($canSendSms): ?>
  <div class="col-12 col-lg-4">
    <div class="card h-100">
      <div class="card-body">
        <h6 class="fw-700 text-green mb-2">
          <i data-lucide="send" class="me-1"></i>Send Privacy-Safe Outbound SMS (FR-21)
        </h6>
        <p class="small text-muted mb-3">
          <strong>BR-09 Safeguard:</strong> Free-text clinical details are prohibited. Messages are composed strictly from approved privacy-safe templates and blocked if `consent_records` shows `consent_given = 0`.
        </p>

        <form method="POST" action="<?= e(appUrl('public/sms.php')) ?>" id="smsComposeForm">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="send_sms">

          <div class="mb-2">
            <label class="form-label small fw-600">Select Scheduled Appointment (Auto-fills Patient)</label>
            <select name="appointment_id" id="smsApptSelect" class="form-select form-select-sm">
              <option value="0">-- Or select patient directly below --</option>
              <?php foreach ($scheduledAppts as $apt): ?>
                <option value="<?= (int) $apt['appointment_id'] ?>"
                        data-pid="<?= (int) $apt['patient_id'] ?>"
                        data-name="<?= e($apt['first_name'] . ' ' . $apt['last_name']) ?>"
                        data-prog="<?= e($apt['program_type']) ?>"
                        data-date="<?= e($apt['scheduled_date']) ?>"
                        data-consent="<?= (int) ($apt['sms_consent'] ?? 0) ?>"
                        <?= ($selectedApptId === (int) $apt['appointment_id']) ? 'selected' : '' ?>>
                  #<?= (int) $apt['appointment_id'] ?> • <?= e($apt['scheduled_date']) ?> — <?= e($apt['last_name'] . ', ' . $apt['first_name']) ?>
                  <?= ((int) $apt['reminder_sent'] === 1) ? '[Reminded]' : '' ?>
                  <?= ((int) ($apt['sms_consent'] ?? 0) === 1) ? '[Consent=1]' : '[NO CONSENT]' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-2">
            <label class="form-label small fw-600">Recipient Patient (UPI) <span class="text-danger">*</span></label>
            <select name="patient_id" id="smsPatientSelect" class="form-select form-select-sm" required>
              <option value="">-- Choose Patient --</option>
              <?php foreach ($allPatients as $p): ?>
                <option value="<?= (int) $p['patient_id'] ?>"
                        data-name="<?= e($p['first_name'] . ' ' . $p['last_name']) ?>"
                        data-consent="<?= (int) ($p['sms_consent'] ?? 0) ?>">
                  <?= e($p['upi'] . ' — ' . $p['last_name'] . ', ' . $p['first_name'] . ' (' . ($p['contact_no'] ?: 'No #') . ')') ?>
                  <?= ((int) ($p['sms_consent'] ?? 0) === 1) ? '✓ Consented' : '✗ No Consent' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-2">
            <label class="form-label small fw-600">Approved Privacy-Safe Template <span class="text-danger">*</span></label>
            <select name="template_key" id="smsTemplateSelect" class="form-select form-select-sm" required>
              <?php foreach (SMS_TEMPLATES as $tKey => $tpl): ?>
                <option value="<?= e($tKey) ?>" data-body="<?= e($tpl['body']) ?>">
                  <?= e($tpl['label']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label small fw-600">Message Preview (Read-Only Template — BR-09)</label>
            <textarea id="smsPreviewBox" class="form-control form-control-sm bg-light" rows="3" readonly></textarea>
            <div id="smsConsentAlert" class="small mt-1"></div>
          </div>

          <button type="submit" class="btn btn-success btn-sm w-100" style="background:#0B3B22;border-color:#0B3B22;">
            <i class="bi bi-send-check me-1"></i>Verify Consent &amp; Dispatch SMS
          </button>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Right Column: Outbound SMS Delivery Logs (`sms_logs` — D10) -->
  <div class="col-12 <?= $canSendSms ? 'col-lg-8' : 'col-lg-12' ?>">
    <div class="card h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
          <div>
            <h6 class="fw-700 text-green mb-0">
              <i data-lucide="messages-square" class="me-1"></i>Outbound SMS Audit &amp; Delivery Receipts (`sms_logs` — <?= count($smsLogs) ?>)
            </h6>
            <small class="text-muted">Stores `recipient_number` snapshot even if `patients.contact_no` changes later (Phase 8 Sec. 49.3).</small>
          </div>
          <span class="badge bg-light text-dark border">Gateway: <?= e((string) env('SMS_PROVIDER', 'Semaphore Mock')) ?> (Outbound-Only)</span>
        </div>

        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Log ID</th>
                <th>Sent Timestamp</th>
                <th>Patient (UPI)</th>
                <th>Snapshot Number</th>
                <th>Privacy-Safe Message</th>
                <th>Gateway / Receipt</th>
                <th>Consent</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($smsLogs)): ?>
              <tr>
                <td colspan="7" class="text-center text-muted py-4">No SMS logs recorded yet.</td>
              </tr>
              <?php else: ?>
                <?php foreach ($smsLogs as $log): ?>
                <tr>
                  <td><code>#<?= (int) $log['sms_log_id'] ?></code></td>
                  <td class="small text-nowrap"><?= e($log['sent_at']) ?></td>
                  <td class="small">
                    <strong><?= e($log['last_name'] . ', ' . $log['first_name']) ?></strong><br>
                    <code><?= e($log['upi']) ?></code>
                  </td>
                  <td><code><?= e($log['recipient_number']) ?></code></td>
                  <td class="small" style="max-width:280px;"><?= e($log['message_content']) ?></td>
                  <td class="small">
                    <div><?= e($log['gateway_used'] ?? 'Gateway') ?></div>
                    <span class="badge bg-success-subtle text-success border border-success-subtle">
                      <?= e($log['delivery_status'] ?? 'Sent') ?>
                    </span>
                  </td>
                  <td>
                    <?php if ((int) $log['consent_verified'] === 1): ?>
                      <span class="badge bg-success-subtle text-success">Verified (`1`)</span>
                    <?php else: ?>
                      <span class="badge bg-danger-subtle text-danger">Unverified</span>
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

<script>
  document.addEventListener('DOMContentLoaded', function() {
    var apptSel = document.getElementById('smsApptSelect');
    var patSel  = document.getElementById('smsPatientSelect');
    var tplSel  = document.getElementById('smsTemplateSelect');
    var preview = document.getElementById('smsPreviewBox');
    var alertEl = document.getElementById('smsConsentAlert');
    if (!apptSel || !patSel || !tplSel || !preview) return;

    function updatePreview() {
      var apptOpt = apptSel.options[apptSel.selectedIndex];
      if (apptOpt && apptOpt.value !== '0') {
        var pid = apptOpt.getAttribute('data-pid');
        if (pid) patSel.value = pid;
      }

      var patOpt = patSel.options[patSel.selectedIndex];
      var tplOpt = tplSel.options[tplSel.selectedIndex];
      var rawTpl = tplOpt ? (tplOpt.getAttribute('data-body') || '') : '';

      var pName = (patOpt && patOpt.value) ? (patOpt.getAttribute('data-name') || 'Patient') : '[patient_name]';
      var prog  = (apptOpt && apptOpt.value !== '0') ? (apptOpt.getAttribute('data-prog') || 'health service') : 'health service';
      var date  = (apptOpt && apptOpt.value !== '0') ? (apptOpt.getAttribute('data-date') || 'scheduled date') : 'scheduled date';

      preview.value = rawTpl
        .replace('[patient_name]', pName)
        .replace('[program]', prog)
        .replace('[date]', date);

      if (patOpt && patOpt.value) {
        var consent = patOpt.getAttribute('data-consent');
        if (consent === '1') {
          alertEl.innerHTML = '<span class="text-success fw-600"><i class="bi bi-check-circle-fill me-1"></i>Consent Verified (`consent_given = 1` in `consent_records`). Ready to send.</span>';
        } else {
          alertEl.innerHTML = '<span class="text-danger fw-600"><i class="bi bi-x-octagon-fill me-1"></i>BR-09 Block: Patient has NOT granted SMS Notification consent. Sending will be rejected by the server.</span>';
        }
      } else {
        alertEl.innerHTML = '';
      }
    }

    apptSel.addEventListener('change', updatePreview);
    patSel.addEventListener('change', updatePreview);
    tplSel.addEventListener('change', updatePreview);
    updatePreview();
  });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

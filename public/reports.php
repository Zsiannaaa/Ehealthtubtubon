<?php
// ============================================================================
// public/reports.php — Screen S11: Statistical Reports, MHO Tally & Backup/Export
// Covers:
//   - FR-23: Dashboards / basic reports (server-aggregated counts, Chart.js) plus
//            scheduled nightly database + uploads backup and on-demand Admin export
//   - BR-15: Backups cover both MySQL database and uploaded files outside web root
//   - Scope/Limitations: Printable MHO/DOH statistical tally only (no auto-submission)
// ============================================================================
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../modules/reports_backup_module.php';

requireRole([ROLE_ADMIN, ROLE_NURSE, ROLE_BHW, ROLE_INVENTORY]);

$currentRole   = getUserRole();
$isAdmin       = ($currentRole === ROLE_ADMIN);
$currentUserId = currentUserId();

// Handle Admin On-Demand Backup Download or Creation (FR-04, FR-23, BR-15)
if ($isAdmin && isset($_GET['download_backup'])) {
    $file = basename(inputStr($_GET, 'download_backup'));
    $full = realpath(STORAGE_BACKUPS_PATH . '/' . $file);
    $base = realpath(STORAGE_BACKUPS_PATH);

    if ($full && $base && str_starts_with(str_replace('\\', '/', $full), rtrim(str_replace('\\', '/', $base), '/') . '/') && is_file($full)) {
        logAudit($pdo, 'BACKUP_DOWNLOAD', "Administrator downloaded backup archive '{$file}'", 'system', $file, $currentUserId);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . addslashes($file) . '"');
        header('Content-Length: ' . filesize($full));
        readfile($full);
        exit();
    }
    setFlash('error', 'Requested backup archive not found.');
    header('Location: ' . appUrl('public/reports.php'));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole([ROLE_ADMIN]);
    verifyCsrf(appUrl('public/reports.php'));

    $action = inputStr($_POST, 'action');
    if ($action === 'run_backup') {
        $res = createSystemBackupArchive($pdo, $currentUserId);
        setFlash($res['success'] ? 'success' : 'error', $res['success']
            ? "Backup archive '{$res['filename']}' (" . number_format((int) $res['size_bytes'] / 1024, 1) . " KB) generated in storage/backups (BR-15)."
            : ($res['error'] ?? 'Backup failed.'));
        header('Location: ' . appUrl('public/reports.php'));
        exit();
    }
}

$summary  = getClinicSummaryReport($pdo);
$kpis     = $summary['kpis'];
$archives = $isAdmin ? listBackupArchives() : [];

$pageTitle    = 'Clinic Statistical Reports & System Backup / Export';
$pageSubtitle = 'FR-23 & BR-15 • Offline MHO Tally & Database + Uploads Backup';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- PRINT HEADER FOR MHO STATISTICAL REPORT -->
<div class="d-none d-print-block mb-4 border-bottom pb-3 text-center">
  <h5 class="fw-bold mb-0">REPUBLIC OF THE PHILIPPINES • PROVINCE OF NEGROS ORIENTAL</h5>
  <h6 class="fw-bold mb-1">MUNICIPALITY OF SIBULAN • BARANGAY TUBTUBON HEALTH CENTER</h6>
  <h5 class="fw-bold text-uppercase mt-2">Consolidated Clinic Service, Document &amp; Inventory Statistical Tally</h5>
  <p class="small text-muted mb-0">
    Generated on <?= e(date('F j, Y h:i A')) ?> by <?= e($_SESSION['full_name'] ?? 'Staff') ?> (Offline MHO Copy — No Auto-Submission per Scope/Limitations)
  </p>
</div>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 no-print">
  <div>
    <span class="badge bg-success-subtle text-success border border-success-subtle">
      FHSIS-Aligned Local Summary • Ready for Hand-Carry / Print to Municipal Health Office
    </span>
  </div>
  <button type="button" class="btn btn-sm btn-outline-dark" onclick="window.print()">
    <i class="bi bi-printer me-1"></i>Print Consolidated MHO Report
  </button>
</div>

<!-- SUMMARY TALLY TABLES -->
<div class="row g-3 mb-4">
  <!-- 1. Service Encounters by Program (BR-02) -->
  <div class="col-12 col-lg-4">
    <div class="card h-100">
      <div class="card-body">
        <h6 class="fw-700 text-green mb-3">
          <i class="bi bi-clipboard2-data me-1"></i>1. Encounters by Program (BR-02)
        </h6>
        <table class="table table-sm table-bordered mb-0">
          <thead class="table-light">
            <tr>
              <th>Health Program</th>
              <th class="text-end">Total Visits</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($summary['program_tally'] as $prog => $cnt): ?>
            <tr>
              <td class="small"><?= e($prog) ?></td>
              <td class="text-end fw-bold"><?= (int) $cnt ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="table-light fw-bold">
              <td>Total Encounters (`service_records`)</td>
              <td class="text-end text-success"><?= (int) $kpis['total_records'] ?></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- 2. 4-State Folder Registry Tally (BR-03) -->
  <div class="col-12 col-lg-4">
    <div class="card h-100">
      <div class="card-body">
        <h6 class="fw-700 text-green mb-3">
          <i class="bi bi-folder2-open me-1"></i>2. Document 4-State Lifecycle (BR-03)
        </h6>
        <table class="table table-sm table-bordered mb-3">
          <thead class="table-light">
            <tr>
              <th>Document State</th>
              <th class="text-end">Folders</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($summary['doc_tally'] as $st => $cnt): ?>
            <tr>
              <td class="small"><?= e($st) ?></td>
              <td class="text-end fw-bold"><?= (int) $cnt ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="table-light fw-bold">
              <td>Total Indexed Folders</td>
              <td class="text-end text-success"><?= (int) $kpis['total_documents'] ?></td>
            </tr>
          </tbody>
        </table>

        <h6 class="fw-700 text-green mb-2">
          <i class="bi bi-calendar-check me-1"></i>3. Appointment Outcomes (BR-08)
        </h6>
        <table class="table table-sm table-bordered mb-0">
          <tbody>
            <?php foreach ($summary['appt_tally'] as $ast => $cnt): ?>
            <tr>
              <td class="small"><?= e($ast) ?></td>
              <td class="text-end fw-bold"><?= (int) $cnt ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- 3. Inventory Ledger Movements & Patient MPI -->
  <div class="col-12 col-lg-4">
    <div class="card h-100">
      <div class="card-body">
        <h6 class="fw-700 text-green mb-3">
          <i class="bi bi-box-seam me-1"></i>4. Inventory Ledger Totals (BR-04/07)
        </h6>
        <table class="table table-sm table-bordered mb-3">
          <thead class="table-light">
            <tr>
              <th>Transaction Type</th>
              <th class="text-end">Total Units</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($summary['stock_tally'] as $tt => $qty): ?>
            <tr>
              <td class="small"><?= e($tt) ?></td>
              <td class="text-end fw-bold"><?= (int) $qty ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <h6 class="fw-700 text-green mb-2">
          <i class="bi bi-people me-1"></i>5. Master Patient Index &amp; SMS
        </h6>
        <ul class="list-group list-group-flush small">
          <li class="list-group-item d-flex justify-content-between px-0">
            <span>Active Registered Residents (UPI):</span>
            <strong><?= (int) $kpis['active_patients'] ?></strong>
          </li>
          <li class="list-group-item d-flex justify-content-between px-0">
            <span>Consent-Verified Outbound SMS Sent:</span>
            <strong><?= (int) $kpis['sms_sent'] ?></strong>
          </li>
          <li class="list-group-item d-flex justify-content-between px-0">
            <span>Low-Stock Items Needing Reorder:</span>
            <strong class="text-danger"><?= (int) $kpis['low_stock_items'] ?></strong>
          </li>
        </ul>
      </div>
    </div>
  </div>
</div>

<!-- ADMINISTRATOR BACKUP & EXPORT PANEL (FR-04, FR-23, BR-15) -->
<?php if ($isAdmin): ?>
<div class="card border-success-subtle no-print">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
      <div>
        <h6 class="fw-700 text-green mb-0">
          <i data-lucide="database-backup" class="me-1"></i>System Backup &amp; Export Manager (Administrator Only — FR-23, BR-15)
        </h6>
        <small class="text-muted">
          <strong>BR-15 Rule:</strong> Backups package both the 13-table MySQL database (`database_dump.sql`) and all uploaded document scans (`storage/uploads/`) into a timestamped archive stored in `storage/backups/`. Scheduled nightly backups also run via `cli/backup_job.php`.
        </small>
      </div>

      <form method="POST" action="<?= e(appUrl('public/reports.php')) ?>">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="run_backup">
        <button type="submit" class="btn btn-success btn-sm" style="background:#0B3B22;border-color:#0B3B22;">
          <i class="bi bi-cloud-arrow-down-fill me-1"></i>Generate On-Demand Backup Now
        </button>
      </form>
    </div>

    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Archive Filename</th>
            <th>Created Timestamp</th>
            <th>Archive Size</th>
            <th class="text-end">Download</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($archives)): ?>
          <tr>
            <td colspan="4" class="text-center text-muted py-3">
              No backup archives generated yet. Click <strong>Generate On-Demand Backup Now</strong> above to create one.
            </td>
          </tr>
          <?php else: ?>
            <?php foreach ($archives as $arc): ?>
            <tr>
              <td><code><?= e($arc['filename']) ?></code></td>
              <td class="small"><?= e($arc['created_at']) ?></td>
              <td class="small"><?= e(number_format($arc['size_bytes'] / 1024, 1)) ?> KB</td>
              <td class="text-end">
                <a href="<?= e(appUrl('public/reports.php?download_backup=' . urlencode($arc['filename']))) ?>"
                   class="btn btn-sm btn-outline-primary">
                  <i class="bi bi-download me-1"></i>Download
                </a>
              </td>
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

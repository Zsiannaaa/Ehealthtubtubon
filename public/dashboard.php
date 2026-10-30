<?php
// ============================================================================
// public/dashboard.php — Screen S1 / S11: Role-Aware Operational Dashboard
// Enforces server-side RBAC (FR-02, BR-11) and displays live MySQL counts,
// Chart.js program distribution, low-stock/expiry alerts, and recent activity.
// ============================================================================
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../modules/inventory_module.php';
requireRole([ROLE_ADMIN, ROLE_NURSE, ROLE_BHW, ROLE_INVENTORY]);

$currentRole = getUserRole();

// Pure-read inventory alerts (NFR-PERF-01: zero UPDATEs on GET; evaluates usable FEFO & live expiry)
$invAlerts       = getInventoryAlerts($pdo, 90);
$lowStockItems   = $invAlerts['low_stock'];
$expiringBatches = array_slice($invAlerts['expiry_alerts'], 0, 6);

// Live KPI aggregations from the 13-table MySQL database
$totalPatients = (int) $pdo->query("SELECT COUNT(*) FROM patients WHERE status = 'Active'")->fetchColumn();
$totalRecords  = (int) $pdo->query("SELECT COUNT(*) FROM service_records")->fetchColumn();
$todayAppts    = (int) $pdo->query("SELECT COUNT(*) FROM appointments WHERE scheduled_date = CURDATE() AND status = 'Scheduled'")->fetchColumn();
$lowStockCount = count($lowStockItems);

// Program distribution for Chart.js (BR-02: 6 Programs)
$progRows = $pdo->query("
    SELECT program_type, COUNT(*) AS total
    FROM service_records
    GROUP BY program_type
")->fetchAll();
$programCounts = array_fill_keys(PROGRAM_TYPES, 0);
foreach ($progRows as $r) {
    if (isset($programCounts[$r['program_type']])) {
        $programCounts[$r['program_type']] = (int) $r['total'];
    }
}

// Document 4-State distribution for Chart.js (BR-03)
$docRows = $pdo->query("
    SELECT status, COUNT(*) AS total
    FROM documents
    GROUP BY status
")->fetchAll();
$docStateCounts = array_fill_keys(DOCUMENT_STATES, 0);
foreach ($docRows as $r) {
    if (isset($docStateCounts[$r['status']])) {
        $docStateCounts[$r['status']] = (int) $r['total'];
    }
}

// Upcoming scheduled appointments
$upcomingAppts = $pdo->query("
    SELECT a.*, p.upi, p.last_name, p.first_name
    FROM appointments a
    INNER JOIN patients p ON a.patient_id = p.patient_id
    WHERE a.status = 'Scheduled'
    ORDER BY a.scheduled_date ASC, a.scheduled_time ASC
    LIMIT 6
")->fetchAll();

$pageTitle    = 'Dashboard & Operational Overview';
$pageSubtitle = 'Live MySQL 8.4 Metrics • Logged in as ' . $currentRole;
require_once __DIR__ . '/../includes/header.php';
?>

<!-- KPI CARDS -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <a class="stat-card tint-pine d-block text-decoration-none"
       href="<?= in_array($currentRole, [ROLE_ADMIN, ROLE_NURSE, ROLE_BHW], true) ? e(appUrl('public/patients.php')) : '#' ?>">
      <div class="stat-label">Registered Patients</div>
      <div class="stat-value"><?= $totalPatients ?></div>
      <div class="stat-foot"><span class="stat-hint">Master index by UPI (BR-01)</span></div>
    </a>
  </div>
  <div class="col-sm-6 col-xl-3">
    <a class="stat-card tint-blue d-block text-decoration-none"
       href="<?= in_array($currentRole, [ROLE_NURSE, ROLE_BHW], true) ? e(appUrl('public/service_records.php')) : '#' ?>">
      <div class="stat-label">Service Records</div>
      <div class="stat-value"><?= $totalRecords ?></div>
      <div class="stat-foot"><span class="stat-hint">Across 6 DOH programs (BR-02)</span></div>
    </a>
  </div>
  <div class="col-sm-6 col-xl-3">
    <a class="stat-card tint-mint d-block text-decoration-none"
       href="<?= in_array($currentRole, [ROLE_ADMIN, ROLE_NURSE, ROLE_BHW], true) ? e(appUrl('public/scheduling.php')) : '#' ?>">
      <div class="stat-label">Today's Appointments</div>
      <div class="stat-value"><?= $todayAppts ?></div>
      <div class="stat-foot"><span class="stat-hint"><?= e(date('M d, Y')) ?></span></div>
    </a>
  </div>
  <div class="col-sm-6 col-xl-3">
    <a class="stat-card tint-peach d-block text-decoration-none"
       href="<?= in_array($currentRole, [ROLE_ADMIN, ROLE_NURSE, ROLE_INVENTORY], true) ? e(appUrl('public/inventory.php')) : '#' ?>">
      <div class="stat-label">Low Stock Items</div>
      <div class="stat-value"><?= $lowStockCount ?></div>
      <div class="stat-foot">
        <?php if ($lowStockCount > 0): ?>
          <span class="badge bg-danger-subtle text-danger">Needs Replenishment</span>
        <?php else: ?>
          <span class="chip-allgood">all good</span>
        <?php endif; ?>
        <span class="stat-hint">at or below threshold</span>
      </div>
    </a>
  </div>
</div>

<!-- CHARTS ROW -->
<div class="row g-3 mb-4">
  <div class="col-12 col-lg-7">
    <div class="card h-100">
      <div class="card-body">
        <h6 class="fw-700 text-green mb-3">
          <i data-lucide="chart-column" class="me-1"></i>Service Encounters by Program (`service_records`)
        </h6>
        <div style="height:250px;">
          <canvas id="chartPrograms"></canvas>
        </div>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-5">
    <div class="card h-100">
      <div class="card-body">
        <h6 class="fw-700 text-green mb-3">
          <i data-lucide="folder-open" class="me-1"></i>4-State Document Registry ("Folder Situation")
        </h6>
        <div style="height:250px;">
          <canvas id="chartDocStates"></canvas>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- UPCOMING APPOINTMENTS & INVENTORY ALERTS -->
<div class="row g-3">
  <div class="col-12 col-lg-6">
    <div class="card h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h6 class="fw-700 text-green mb-0">
            <i data-lucide="calendar-clock" class="me-1"></i>Upcoming Scheduled Visits
          </h6>
          <?php if (in_array($currentRole, [ROLE_ADMIN, ROLE_NURSE, ROLE_BHW], true)): ?>
          <a href="<?= e(appUrl('public/scheduling.php')) ?>" class="btn btn-sm btn-outline-success">View Calendar</a>
          <?php endif; ?>
        </div>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Date / Time</th>
                <th>Patient (UPI)</th>
                <th>Program</th>
                <th>SMS</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($upcomingAppts as $apt): ?>
              <tr>
                <td class="small fw-600">
                  <?= e($apt['scheduled_date']) ?>
                  <span class="text-muted"><?= $apt['scheduled_time'] ? e(substr($apt['scheduled_time'], 0, 5)) : '' ?></span>
                </td>
                <td class="small">
                  <strong><?= e($apt['last_name'] . ', ' . $apt['first_name']) ?></strong><br>
                  <code style="font-size:11px;"><?= e($apt['upi']) ?></code>
                </td>
                <td><span class="badge bg-light text-dark border"><?= e($apt['program_type']) ?></span></td>
                <td>
                  <?php if ((int) $apt['reminder_sent'] === 1): ?>
                    <span class="badge bg-success-subtle text-success">Sent</span>
                  <?php else: ?>
                    <span class="badge bg-secondary-subtle text-secondary">Pending</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h6 class="fw-700 text-green mb-0">
            <i data-lucide="triangle-alert" class="me-1"></i>Low-Stock &amp; Batch Expiry Alerts (FEFO)
          </h6>
          <?php if (in_array($currentRole, [ROLE_ADMIN, ROLE_NURSE, ROLE_INVENTORY], true)): ?>
          <a href="<?= e(appUrl('public/inventory.php')) ?>" class="btn btn-sm btn-outline-warning">Inventory View</a>
          <?php endif; ?>
        </div>

        <?php if (!empty($lowStockItems)): ?>
        <div class="mb-3">
          <div class="small fw-bold text-danger mb-1">Low Usable FEFO Stock Threshold Reached (FR-18 / BR-04 / BR-06):</div>
          <?php foreach ($lowStockItems as $item): ?>
            <div class="d-flex justify-content-between align-items-center py-1 border-bottom small">
              <span><strong><?= e($item['item_name']) ?></strong> (<?= e($item['category']) ?>)</span>
              <span class="badge bg-danger-subtle text-danger">
                <?= (int) ($item['usable_fefo_qty'] ?? $item['current_quantity']) ?> usable / <?= (int) $item['minimum_stock_threshold'] ?> <?= e($item['unit']) ?>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($expiringBatches)): ?>
        <div>
          <div class="small fw-bold text-warning-emphasis mb-1">Expired / Near-Expiry Batches (BR-05, BR-06):</div>
          <?php foreach ($expiringBatches as $batch): ?>
            <div class="d-flex justify-content-between align-items-center py-1 border-bottom small">
              <span>
                <strong><?= e($batch['item_name']) ?></strong>
                <code class="ms-1"><?= e($batch['batch_no']) ?></code>
              </span>
              <span>
                <?php if ($batch['status'] === 'Expired'): ?>
                  <span class="badge bg-danger">EXPIRED (<?= e($batch['expiry_date']) ?>) — Excluded from FEFO</span>
                <?php else: ?>
                  <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                    Exp: <?= e($batch['expiry_date']) ?> (<?= (int) $batch['quantity_remaining'] ?> left)
                  </span>
                <?php endif; ?>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
  document.addEventListener('DOMContentLoaded', function() {
    var progCtx = document.getElementById('chartPrograms');
    if (progCtx && window.Chart) {
      new Chart(progCtx, {
        type: 'bar',
        data: {
          labels: <?= json_encode(array_keys($programCounts)) ?>,
          datasets: [{
            label: 'Service Encounters',
            data: <?= json_encode(array_values($programCounts)) ?>,
            backgroundColor: '#0E4D2B',
            borderRadius: 6
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
        }
      });
    }

    var docCtx = document.getElementById('chartDocStates');
    if (docCtx && window.Chart) {
      new Chart(docCtx, {
        type: 'doughnut',
        data: {
          labels: <?= json_encode(array_keys($docStateCounts)) ?>,
          datasets: [{
            data: <?= json_encode(array_values($docStateCounts)) ?>,
            backgroundColor: ['#0E4D2B', '#E6A100', '#2456A6', '#6c757d']
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { position: 'bottom' } }
        }
      });
    }
  });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<?php
// ============================================================================
// public/inventory.php — Screen S6: Inventory Catalog, FEFO Batches & Alerts
// Covers:
//   - FR-15 / BR-04: Catalog with derived `current_quantity` (never directly edited)
//   - FR-16 / BR-05: Batch tracking with expiry_date (NULL = non-expiring) & FEFO order
//   - FR-18 / BR-06: Low-stock and expiry alerts; expired batches flagged & excluded
//   - FR-19: Printable MHO Replenishment Request Document
//   - BR-11: Inventory/Admin Personnel can add catalog items; Nurse & Admin view alerts
// ============================================================================
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../modules/inventory_module.php';

requireRole([ROLE_ADMIN, ROLE_NURSE, ROLE_INVENTORY]);

$currentRole    = getUserRole();
$canManageStock = ($currentRole === ROLE_INVENTORY);
$currentUserId  = currentUserId();

// Handle POST: Add New Catalog Item (Inventory/Admin Personnel only per Table 8-6)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole([ROLE_INVENTORY]);
    verifyCsrf(appUrl('public/inventory.php'));

    $action = inputStr($_POST, 'action');
    if ($action === 'create_item') {
        $err = createCatalogItem(
            $pdo,
            inputStr($_POST, 'item_name'),
            inputStr($_POST, 'category'),
            inputStr($_POST, 'unit'),
            inputInt($_POST, 'minimum_stock_threshold'),
            $currentUserId
        );
        setFlash($err ? 'error' : 'success', $err ?? 'New catalog item added (Initial quantity = 0; use Stock-In to receive batches).');
        header('Location: ' . appUrl('public/inventory.php'));
        exit();
    }

    if ($action === 'sync_expired') {
        $flagged = syncExpiredBatches($pdo);
        $itemIds = $pdo->query("SELECT item_id FROM inventory_items")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($itemIds as $id) {
            recalculateItemQuantityAndStatus($pdo, (int) $id);
        }
        setFlash('success', "Persisted expiry sync complete: {$flagged} newly-expired batch(es) flagged and catalog statuses refreshed.");
        header('Location: ' . appUrl('public/inventory.php'));
        exit();
    }
}

$categoryFilter = inputStr($_GET, 'category');
$searchQuery    = inputStr($_GET, 'q');

$items   = getInventoryItems($pdo, $categoryFilter, $searchQuery);
$batches = getStockBatches($pdo);
$alerts  = getInventoryAlerts($pdo, 90);

$pageTitle    = 'Inventory Catalog, Threshold Alerts & FEFO Batches';
$pageSubtitle = 'FR-15 to FR-19 • BR-04 Derived Quantities • BR-05/06 FEFO & Expired Batch Exclusion';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- LOW-STOCK & EXPIRY ALERT CARDS (FR-18) -->
<div class="row g-3 mb-4 no-print">
  <div class="col-12 col-lg-6">
    <div class="card border-danger-subtle h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h6 class="fw-700 text-danger mb-0">
            <i class="bi bi-exclamation-octagon-fill me-1"></i>Low-Stock Alert (`usable_fefo_qty <= threshold`)
          </h6>
          <button type="button" class="btn btn-sm btn-outline-dark" onclick="window.print()">
            <i class="bi bi-printer me-1"></i>Print Replenishment Request (FR-19)
          </button>
        </div>
        <?php if (empty($alerts['low_stock'])): ?>
          <p class="small text-success mb-0">All catalog items have usable non-expired FEFO stock above minimum thresholds.</p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Item Name</th>
                  <th>Category</th>
                  <th>Usable FEFO Qty</th>
                  <th>Ledger Qty</th>
                  <th>Threshold</th>
                  <th>Recommended Order</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($alerts['low_stock'] as $ls):
                  $usableQty   = (int) ($ls['usable_fefo_qty'] ?? 0);
                  $ledgerQty   = (int) ($ls['current_quantity'] ?? 0);
                  $recommended = max(50, ((int) $ls['minimum_stock_threshold'] * 2) - $usableQty);
                ?>
                <tr>
                  <td class="fw-600 small"><?= e($ls['item_name']) ?></td>
                  <td class="small"><?= e($ls['category']) ?></td>
                  <td><span class="badge bg-danger"><?= $usableQty ?> <?= e($ls['unit']) ?></span></td>
                  <td class="small text-muted"><?= $ledgerQty ?> <?= e($ls['unit']) ?></td>
                  <td class="small"><?= (int) $ls['minimum_stock_threshold'] ?> <?= e($ls['unit']) ?></td>
                  <td class="small fw-bold text-success">+<?= (int) $recommended ?> <?= e($ls['unit']) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card border-warning-subtle h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h6 class="fw-700 text-warning-emphasis mb-0">
            <i class="bi bi-hourglass-split me-1"></i>Expired &amp; Near-Expiry Batches (≤ 90 Days — BR-05/06)
          </h6>
          <?php if ($canManageStock): ?>
            <div class="d-flex gap-1">
              <form method="POST" action="<?= e(appUrl('public/inventory.php')) ?>" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="sync_expired">
                <button type="submit" class="btn btn-sm btn-outline-secondary" title="Persist Expired status on any past-due batches">
                  <i class="bi bi-arrow-repeat me-1"></i>Sync Expiry
                </button>
              </form>
              <a href="<?= e(appUrl('public/stock_moves.php')) ?>" class="btn btn-sm btn-success" style="background:#0B3B22;border-color:#0B3B22;">
                <i class="bi bi-arrow-left-right me-1"></i>Record Stock / Disposal
              </a>
            </div>
          <?php endif; ?>
        </div>
        <?php if (empty($alerts['expiry_alerts'])): ?>
          <p class="small text-success mb-0">No expired or near-expiry batches within 90 days.</p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th>Batch No.</th>
                  <th>Item</th>
                  <th>Remaining</th>
                  <th>Expiry Date</th>
                  <th>FEFO Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($alerts['expiry_alerts'] as $ea): ?>
                <tr>
                  <td><code><?= e($ea['batch_no']) ?></code></td>
                  <td class="small fw-600"><?= e($ea['item_name']) ?></td>
                  <td class="small"><?= (int) $ea['quantity_remaining'] ?> <?= e($ea['unit']) ?></td>
                  <td class="small fw-600"><?= e($ea['expiry_date']) ?></td>
                  <td>
                    <?php if ($ea['status'] === 'Expired'): ?>
                      <span class="badge bg-danger" title="BR-06: Excluded from FEFO dispensing; must be removed via audited Disposal">
                        EXPIRED (Excluded)
                      </span>
                    <?php else: ?>
                      <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                        Issue First (FEFO #1)
                      </span>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- PRINTABLE MHO REPLENISHMENT REQUEST HEADER (Visible during Print — FR-19) -->
<div class="d-none d-print-block mb-4 border-bottom pb-3">
  <div class="text-center">
    <h5 class="fw-bold mb-0">REPUBLIC OF THE PHILIPPINES • PROVINCE OF NEGROS ORIENTAL</h5>
    <h6 class="fw-bold mb-1">MUNICIPALITY OF SIBULAN • BARANGAY TUBTUBON HEALTH CENTER</h6>
    <h5 class="fw-bold text-uppercase mt-2">Medical Supply &amp; Medicine Replenishment Request</h5>
    <p class="small text-muted mb-0">Generated on <?= e(date('F j, Y h:i A')) ?> by <?= e($_SESSION['full_name'] ?? 'Staff') ?> (Offline MHO Submission Copy — FR-19)</p>
  </div>
</div>

<div class="row g-3">
  <!-- Optional Left Form for Inventory Staff to Add New Catalog Item -->
  <?php if ($canManageStock): ?>
  <div class="col-12 col-lg-4 no-print">
    <div class="card h-100">
      <div class="card-body">
        <h6 class="fw-700 text-green mb-3">
          <i data-lucide="package-plus" class="me-1"></i>Add Catalog Item (FR-15)
        </h6>
        <form method="POST" action="<?= e(appUrl('public/inventory.php')) ?>">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="create_item">

          <div class="mb-2">
            <label class="form-label small fw-600">Item Name &amp; Strength <span class="text-danger">*</span></label>
            <input type="text" name="item_name" class="form-control form-control-sm" required
                   placeholder="e.g. Mefenamic Acid 500mg">
          </div>

          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label small fw-600">Category <span class="text-danger">*</span></label>
              <select name="category" class="form-select form-select-sm" required>
                <?php foreach (INVENTORY_CATEGORIES as $cat): ?>
                  <option value="<?= e($cat) ?>"><?= e($cat) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label small fw-600">Unit <span class="text-danger">*</span></label>
              <input type="text" name="unit" class="form-control form-control-sm" required
                     placeholder="tablet, capsule, pc">
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label small fw-600">Minimum Stock Threshold (Reorder Point) <span class="text-danger">*</span></label>
            <input type="number" name="minimum_stock_threshold" class="form-control form-control-sm"
                   min="0" value="50" required>
            <div class="form-text" style="font-size:11px;">
              <strong>BR-04 Rule:</strong> `current_quantity` is never directly editable here. It is derived strictly from `stock_transactions`.
            </div>
          </div>

          <button type="submit" class="btn btn-success btn-sm w-100" style="background:#0B3B22;border-color:#0B3B22;">
            <i class="bi bi-plus-circle me-1"></i>Add to Catalog
          </button>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Catalog Table -->
  <div class="col-12 <?= $canManageStock ? 'col-lg-8' : 'col-lg-12' ?>">
    <div class="card mb-4">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
          <div>
            <h6 class="fw-700 text-green mb-0">
              <i data-lucide="boxes" class="me-1"></i>Master Inventory Catalog (`inventory_items` — D7)
            </h6>
            <small class="text-muted">Quantities are derived from `stock_transactions` and clamped $\ge 0$ (BR-04).</small>
          </div>

          <form method="GET" action="<?= e(appUrl('public/inventory.php')) ?>" class="d-flex gap-2 flex-wrap no-print">
            <input type="search" name="q" class="form-control form-control-sm" style="width:180px;"
                   placeholder="Search item…" value="<?= e($searchQuery) ?>">
            <select name="category" class="form-select form-select-sm" style="width:auto;">
              <option value="">All Categories</option>
              <?php foreach (INVENTORY_CATEGORIES as $cat): ?>
                <option value="<?= e($cat) ?>" <?= ($categoryFilter === $cat) ? 'selected' : '' ?>><?= e($cat) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-outline-success">Filter</button>
          </form>
        </div>

        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Item Name</th>
                <th>Category</th>
                <th>Derived Ledger Qty</th>
                <th>Usable FEFO Qty</th>
                <th>Threshold</th>
                <th>Earliest Expiry (FEFO)</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $it):
                $stBadge = match ($it['status']) {
                    'Available'    => 'bg-success-subtle text-success border-success-subtle',
                    'Low Stock'    => 'bg-warning-subtle text-warning-emphasis border-warning-subtle',
                    default        => 'bg-danger-subtle text-danger border-danger-subtle',
                };
              ?>
              <tr>
                <td class="fw-600 small"><?= e($it['item_name']) ?></td>
                <td class="small"><?= e($it['category']) ?></td>
                <td class="fw-bold"><?= (int) $it['current_quantity'] ?> <?= e($it['unit']) ?></td>
                <td class="small">
                  <span class="fw-600 text-success"><?= (int) $it['usable_fefo_qty'] ?> <?= e($it['unit']) ?></span>
                  <?php if ((int) $it['expired_holding_qty'] > 0): ?>
                    <span class="badge bg-danger ms-1" title="Expired stock held pending audited disposal">
                      +<?= (int) $it['expired_holding_qty'] ?> Expired
                    </span>
                  <?php endif; ?>
                </td>
                <td class="small text-muted"><?= (int) $it['minimum_stock_threshold'] ?> <?= e($it['unit']) ?></td>
                <td class="small">
                  <?= $it['earliest_expiry'] ? '<code>' . e($it['earliest_expiry']) . '</code>' : '<span class="text-muted">Non-expiring / None</span>' ?>
                </td>
                <td><span class="badge border <?= e($stBadge) ?>"><?= e($it['status']) ?></span></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Batch / Expiry FEFO Queue Table -->
    <div class="card">
      <div class="card-body">
        <h6 class="fw-700 text-green mb-1">
          <i data-lucide="layers" class="me-1"></i>Stock Batches Ordered by Deterministic FEFO (`stock_batches` — D8)
        </h6>
        <small class="text-muted d-block mb-3">
          <strong>BR-05 &amp; BR-06:</strong> Earliest `expiry_date` issues first; `NULL` expiry (equipment) issues last; `Expired` batches are flagged, excluded from dispensing, and never deleted.
        </small>

        <div class="table-responsive">
          <table class="table table-sm table-striped align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Batch No.</th>
                <th>Item Name</th>
                <th>Received</th>
                <th>Remaining</th>
                <th>Date Received</th>
                <th>Expiry Date</th>
                <th>Batch Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($batches as $b):
                $bBadge = match ($b['status']) {
                    'Available' => 'bg-success-subtle text-success',
                    'Depleted'  => 'bg-secondary-subtle text-secondary',
                    'Expired'   => 'bg-danger text-white',
                    'Disposed'  => 'bg-dark text-white',
                    default     => 'bg-light text-dark',
                };
              ?>
              <tr>
                <td><code><?= e($b['batch_no']) ?></code></td>
                <td class="small fw-600"><?= e($b['item_name']) ?></td>
                <td class="small"><?= (int) $b['quantity_received'] ?> <?= e($b['unit']) ?></td>
                <td class="small fw-bold"><?= (int) $b['quantity_remaining'] ?> <?= e($b['unit']) ?></td>
                <td class="small text-muted"><?= e($b['date_received']) ?></td>
                <td class="small">
                  <?= $b['expiry_date'] ? '<strong>' . e($b['expiry_date']) . '</strong>' : '<span class="text-muted">NULL (Non-Expiring)</span>' ?>
                </td>
                <td><span class="badge <?= e($bBadge) ?>"><?= e($b['status']) ?></span></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

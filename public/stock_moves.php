<?php
// ============================================================================
// public/stock_moves.php — Screen S7: Stock Movements, FEFO Dispensing & Disposal
// Covers:
//   - FR-17: Record every movement in `stock_transactions` (Stock-In | Stock-Out |
//            Disposal/Expired-Removal) with batch link, quantity, performer,
//            mandatory disposal_reason when disposal, and reference_no
//   - BR-04 to BR-07: Derived quantities, automatic FEFO batch selection on Stock-Out,
//            expired batch exclusion, and audited Disposal with required reason
//   - BR-11: Inventory/Admin Personnel executes transactions; Administrator has View-Only
// ============================================================================
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../modules/inventory_module.php';

requireRole([ROLE_ADMIN, ROLE_INVENTORY]);

$currentRole    = getUserRole();
$canTransact    = ($currentRole === ROLE_INVENTORY);
$currentUserId  = currentUserId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRole([ROLE_INVENTORY]);
    verifyCsrf(appUrl('public/stock_moves.php'));

    $action = inputStr($_POST, 'action');

    if ($action === 'stock_in') {
        $res = recordStockIn(
            $pdo,
            inputInt($_POST, 'item_id'),
            inputStr($_POST, 'batch_no'),
            inputInt($_POST, 'quantity'),
            inputStr($_POST, 'expiry_date') ?: null,
            inputStr($_POST, 'date_received', date('Y-m-d')),
            inputStr($_POST, 'reference_no'),
            $currentUserId
        );
        setFlash($res['success'] ? 'success' : 'error', $res['success']
            ? 'Stock-In batch recorded and derived catalog quantity updated (BR-04).'
            : ($res['error'] ?? 'Stock-In failed.'));
        header('Location: ' . appUrl('public/stock_moves.php'));
        exit();
    }

    if ($action === 'stock_out_fefo') {
        $res = recordStockOutFefo(
            $pdo,
            inputInt($_POST, 'item_id'),
            inputInt($_POST, 'quantity'),
            inputStr($_POST, 'reference_no'),
            $currentUserId
        );
        setFlash($res['success'] ? 'success' : 'error', $res['success']
            ? ($res['message'] ?? 'FEFO Stock-Out completed.')
            : ($res['error'] ?? 'Stock-Out failed.'));
        header('Location: ' . appUrl('public/stock_moves.php'));
        exit();
    }

    if ($action === 'stock_disposal') {
        $res = recordStockDisposal(
            $pdo,
            inputInt($_POST, 'batch_id'),
            inputInt($_POST, 'quantity'),
            inputStr($_POST, 'disposal_reason'),
            inputStr($_POST, 'reference_no'),
            $currentUserId
        );
        setFlash($res['success'] ? 'success' : 'error', $res['success']
            ? ($res['message'] ?? 'Audited disposal recorded.')
            : ($res['error'] ?? 'Disposal failed.'));
        header('Location: ' . appUrl('public/stock_moves.php'));
        exit();
    }
}

$typeFilter = inputStr($_GET, 'type');
$itemFilter = inputInt($_GET, 'item_id');

$items        = getInventoryItems($pdo);
$batches      = getStockBatches($pdo);
$transactions = getStockTransactions($pdo, $typeFilter, $itemFilter, 150);

// Batches with remaining > 0 (Available or Expired) eligible for disposal
$disposableBatches = array_filter($batches, static function (array $b): bool {
    return (int) $b['quantity_remaining'] > 0 && in_array($b['status'], ['Available', 'Expired'], true);
});

$pageTitle    = 'Stock Movements, FEFO Issuance & Audited Disposal';
$pageSubtitle = 'FR-17 & FR-19 • BR-04 to BR-07 Immutable Stock Ledger (`stock_transactions`)';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($canTransact): ?>
<!-- 3 TRANSACTION PANELS: STOCK-IN, FEFO STOCK-OUT, AUDITED DISPOSAL -->
<div class="row g-3 mb-4">
  <!-- 1. Stock-In Receipt -->
  <div class="col-12 col-lg-4">
    <div class="card h-100 border-success-subtle">
      <div class="card-body">
        <h6 class="fw-700 text-success mb-2">
          <i class="bi bi-box-arrow-in-down me-1"></i>1. Stock-In (New Batch Receipt)
        </h6>
        <p class="text-muted small mb-3">Creates a batch in `stock_batches` and logs a `Stock-In` ledger row.</p>

        <form method="POST" action="<?= e(appUrl('public/stock_moves.php')) ?>">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="stock_in">

          <div class="mb-2">
            <label class="form-label small fw-600">Item <span class="text-danger">*</span></label>
            <select name="item_id" class="form-select form-select-sm" required>
              <option value="">-- Select Catalog Item --</option>
              <?php foreach ($items as $it): ?>
                <option value="<?= (int) $it['item_id'] ?>">
                  <?= e($it['item_name'] . ' (' . $it['unit'] . ')') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label small fw-600">Batch / Lot No. <span class="text-danger">*</span></label>
              <input type="text" name="batch_no" class="form-control form-control-sm" required placeholder="AMX-2026-003">
            </div>
            <div class="col-6">
              <label class="form-label small fw-600">Qty Received <span class="text-danger">*</span></label>
              <input type="number" name="quantity" class="form-control form-control-sm" min="1" required placeholder="100">
            </div>
          </div>

          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label small fw-600">Expiry Date (NULL=Equip)</label>
              <input type="date" name="expiry_date" class="form-control form-control-sm" min="<?= e(date('Y-m-d')) ?>">
            </div>
            <div class="col-6">
              <label class="form-label small fw-600">Delivery Ref No.</label>
              <input type="text" name="reference_no" class="form-control form-control-sm" placeholder="DR-2026-1019">
            </div>
          </div>

          <button type="submit" class="btn btn-success btn-sm w-100 mt-2" style="background:#0B3B22;border-color:#0B3B22;">
            <i class="bi bi-plus-circle me-1"></i>Record Stock-In
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- 2. Deterministic FEFO Stock-Out -->
  <div class="col-12 col-lg-4">
    <div class="card h-100 border-primary-subtle">
      <div class="card-body">
        <h6 class="fw-700 text-primary mb-2">
          <i class="bi bi-box-arrow-up-right me-1"></i>2. Stock-Out (Auto-FEFO Dispensing)
        </h6>
        <p class="text-muted small mb-3">
          <strong>BR-05 &amp; BR-06:</strong> Automatically deducts from the earliest-expiring `Available` batch first and skips `Expired` stock.
        </p>

        <form method="POST" action="<?= e(appUrl('public/stock_moves.php')) ?>">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="stock_out_fefo">

          <div class="mb-2">
            <label class="form-label small fw-600">Item to Dispense <span class="text-danger">*</span></label>
            <select name="item_id" class="form-select form-select-sm" required>
              <option value="">-- Select Item --</option>
              <?php foreach ($items as $it): ?>
                <option value="<?= (int) $it['item_id'] ?>" <?= ((int) $it['usable_fefo_qty'] <= 0) ? 'disabled' : '' ?>>
                  <?= e($it['item_name']) ?> — Usable FEFO: <?= (int) $it['usable_fefo_qty'] ?> <?= e($it['unit']) ?>
                  <?= $it['earliest_expiry'] ? '(1st Exp: ' . e($it['earliest_expiry']) . ')' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label small fw-600">Quantity to Issue <span class="text-danger">*</span></label>
              <input type="number" name="quantity" class="form-control form-control-sm" min="1" required placeholder="10">
            </div>
            <div class="col-6">
              <label class="form-label small fw-600">Release / Slip Ref</label>
              <input type="text" name="reference_no" class="form-control form-control-sm" placeholder="ISS-2026-1019">
            </div>
          </div>

          <button type="submit" class="btn btn-primary btn-sm w-100 mt-auto">
            <i class="bi bi-check2-all me-1"></i>Dispense via FEFO Order
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- 3. Audited Disposal / Expired-Removal -->
  <div class="col-12 col-lg-4">
    <div class="card h-100 border-danger-subtle">
      <div class="card-body">
        <h6 class="fw-700 text-danger mb-2">
          <i class="bi bi-shield-exclamation me-1"></i>3. Disposal / Expired-Removal (BR-07)
        </h6>
        <p class="text-muted small mb-3">
          <strong>BR-06 &amp; BR-07:</strong> Expired batches are never deleted. Record their removal with a mandatory `disposal_reason`.
        </p>

        <form method="POST" action="<?= e(appUrl('public/stock_moves.php')) ?>">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="stock_disposal">

          <div class="mb-2">
            <label class="form-label small fw-600">Target Batch <span class="text-danger">*</span></label>
            <select name="batch_id" class="form-select form-select-sm" required>
              <option value="">-- Select Batch --</option>
              <?php foreach ($disposableBatches as $db): ?>
                <option value="<?= (int) $db['batch_id'] ?>">
                  [<?= e($db['status']) ?>] <?= e($db['batch_no']) ?> — <?= e($db['item_name']) ?> (<?= (int) $db['quantity_remaining'] ?> left)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="row g-2 mb-2">
            <div class="col-5">
              <label class="form-label small fw-600">Qty <span class="text-danger">*</span></label>
              <input type="number" name="quantity" class="form-control form-control-sm" min="1" required placeholder="30">
            </div>
            <div class="col-7">
              <label class="form-label small fw-600">Disposal Slip Ref</label>
              <input type="text" name="reference_no" class="form-control form-control-sm" placeholder="DISP-2026-1019">
            </div>
          </div>

          <div class="mb-2">
            <label class="form-label small fw-600">Disposal Reason (Required — BR-07) <span class="text-danger">*</span></label>
            <input type="text" name="disposal_reason" class="form-control form-control-sm" required
                   placeholder="e.g. Expired batch quarantine disposal">
          </div>

          <button type="submit" class="btn btn-danger btn-sm w-100">
            <i class="bi bi-file-earmark-x me-1"></i>Record Audited Disposal
          </button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- IMMUTABLE STOCK TRANSACTION LEDGER TABLE (`stock_transactions` — D9) -->
<div class="card">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
      <div>
        <h6 class="fw-700 text-green mb-0">
          <i data-lucide="arrow-right-left" class="me-1"></i>Immutable Stock Transaction Ledger (`stock_transactions` — <?= count($transactions) ?> Rows)
        </h6>
        <small class="text-muted">Every Stock-In, FEFO Stock-Out, and Disposal is permanently recorded with actor and timestamp.</small>
      </div>

      <form method="GET" action="<?= e(appUrl('public/stock_moves.php')) ?>" class="d-flex gap-2 flex-wrap">
        <select name="type" class="form-select form-select-sm" style="width:auto;">
          <option value="">All Movement Types</option>
          <?php foreach (STOCK_TRANSACTION_TYPES as $tt): ?>
            <option value="<?= e($tt) ?>" <?= ($typeFilter === $tt) ? 'selected' : '' ?>><?= e($tt) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="item_id" class="form-select form-select-sm" style="width:auto;">
          <option value="0">All Catalog Items</option>
          <?php foreach ($items as $it): ?>
            <option value="<?= (int) $it['item_id'] ?>" <?= ($itemFilter === (int) $it['item_id']) ? 'selected' : '' ?>>
              <?= e($it['item_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-sm btn-outline-success">Filter</button>
        <?php if ($typeFilter !== '' || $itemFilter > 0): ?>
          <a href="<?= e(appUrl('public/stock_moves.php')) ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
        <?php endif; ?>
      </form>
    </div>

    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Tx ID</th>
            <th>Date / Time</th>
            <th>Movement Type</th>
            <th>Item</th>
            <th>Batch No.</th>
            <th>Quantity</th>
            <th>Reference / Disposal Reason (BR-07)</th>
            <th>Performed By</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($transactions as $tx):
            $txBadge = match ($tx['transaction_type']) {
                'Stock-In'                 => 'bg-success-subtle text-success border-success-subtle',
                'Stock-Out'                => 'bg-primary-subtle text-primary border-primary-subtle',
                'Disposal/Expired-Removal' => 'bg-danger-subtle text-danger border-danger-subtle',
                default                    => 'bg-light text-dark',
            };
          ?>
          <tr>
            <td><code>#<?= (int) $tx['transaction_id'] ?></code></td>
            <td class="small text-nowrap"><?= e($tx['transaction_date']) ?></td>
            <td><span class="badge border <?= e($txBadge) ?>"><?= e($tx['transaction_type']) ?></span></td>
            <td class="small fw-600"><?= e($tx['item_name']) ?></td>
            <td><code><?= e($tx['batch_no'] ?? 'N/A') ?></code></td>
            <td class="small fw-bold">
              <?= ($tx['transaction_type'] === 'Stock-In' ? '+' : '-') . (int) $tx['quantity'] . ' ' . e($tx['unit']) ?>
            </td>
            <td class="small">
              <?php if (!empty($tx['reference_no'])): ?>
                <code><?= e($tx['reference_no']) ?></code>
              <?php endif; ?>
              <?php if (!empty($tx['disposal_reason'])): ?>
                <span class="text-danger fw-600 ms-1">Reason: <?= e($tx['disposal_reason']) ?></span>
              <?php endif; ?>
            </td>
            <td class="small text-muted"><?= e($tx['performed_by_name'] ?? 'Staff') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

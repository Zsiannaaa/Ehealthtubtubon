<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Access denied.');
}

// ============================================================================
// modules/inventory_module.php — Module M5: Inventory Ledger & FEFO Engine
// Covers:
//   - FR-15 / BR-04: Maintain `inventory_items` with derived `current_quantity`
//                    (never directly editable, clamped >= 0) and threshold status
//   - FR-16 / BR-05: Track `stock_batches` with expiry_date (NULL = non-expiring)
//                    and deterministic FEFO issuance (earliest expiry first, NULL last)
//   - FR-17 / BR-07: Record every movement in `stock_transactions` (Stock-In |
//                    Stock-Out | Disposal/Expired-Removal) with mandatory disposal_reason
//   - FR-18 / BR-06: Flag and exclude expired batches from dispensing without deleting
//   - FR-19: Generate inventory reports and replenishment request data
// ============================================================================

require_once __DIR__ . '/audit_module.php';

/**
 * BR-06: Persist 'Expired' status on any 'Available' batch whose expiry_date has passed
 * so its persisted state matches FEFO exclusion rules without deleting historical rows.
 *
 * NOTE (NFR-PERF-01): Never invoke this write operation on HTTP GET requests.
 * Run via `cli/inventory_sync_job.php` (scheduled task) or inside state-changing POST transactions.
 */
function syncExpiredBatches(PDO $pdo): int
{
    $stmt = $pdo->prepare("
        UPDATE stock_batches
        SET status = 'Expired'
        WHERE status = 'Available'
          AND expiry_date IS NOT NULL
          AND expiry_date < CURDATE()
          AND quantity_remaining > 0
    ");
    $stmt->execute();
    $affected = $stmt->rowCount();

    // Always recompute item statuses if any batch newly expired
    if ($affected > 0) {
        $itemIds = $pdo->query("SELECT item_id FROM inventory_items")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($itemIds as $id) {
            recalculateItemQuantityAndStatus($pdo, (int) $id);
        }
    }

    return $affected;
}

/**
 * BR-04 & FR-18 / BR-06:
 * - Recomputes `inventory_items.current_quantity` strictly from the immutable ledger
 *   (`stock_transactions`: Stock-In - Stock-Out - Disposal/Expired-Removal), clamped >= 0 (BR-04).
 * - Recomputes `inventory_items.status` ('Available' | 'Low Stock' | 'Out of Stock')
 *   strictly from USABLE non-expired FEFO batch balances (`usable_fefo_qty`) compared
 *   against `minimum_stock_threshold` so expired-but-undisposed stock never masks a
 *   low-stock or stockout alert (FR-18, BR-06).
 */
function recalculateItemQuantityAndStatus(PDO $pdo, int $itemId): array
{
    $itemStmt = $pdo->prepare("SELECT * FROM inventory_items WHERE item_id = :id LIMIT 1");
    $itemStmt->execute([':id' => $itemId]);
    $item = $itemStmt->fetch();
    if (!$item) {
        return ['current_quantity' => 0, 'usable_fefo_qty' => 0, 'status' => 'Out of Stock'];
    }

    // 1. Compute net ledger total from stock_transactions (BR-04)
    $txStmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN transaction_type = 'Stock-In' THEN quantity ELSE 0 END), 0)
            - COALESCE(SUM(CASE WHEN transaction_type IN ('Stock-Out', 'Disposal/Expired-Removal') THEN quantity ELSE 0 END), 0)
            AS net_qty
        FROM stock_transactions
        WHERE item_id = :id
    ");
    $txStmt->execute([':id' => $itemId]);
    $netLedgerQty = (int) $txStmt->fetchColumn();

    // Clamp >= 0 per BR-04
    $clampedLedgerQty = max(0, $netLedgerQty);

    // 2. Compute usable non-expired FEFO quantity for operational status & FR-18 alerts (BR-05, BR-06)
    $usableStmt = $pdo->prepare("
        SELECT COALESCE(SUM(quantity_remaining), 0)
        FROM stock_batches
        WHERE item_id = :id
          AND status = 'Available'
          AND quantity_remaining > 0
          AND (expiry_date IS NULL OR expiry_date >= CURDATE())
    ");
    $usableStmt->execute([':id' => $itemId]);
    $usableFefoQty = max(0, (int) $usableStmt->fetchColumn());

    $threshold = (int) $item['minimum_stock_threshold'];

    if ($usableFefoQty === 0) {
        $status = 'Out of Stock';
    } elseif ($usableFefoQty <= $threshold) {
        $status = 'Low Stock';
    } else {
        $status = 'Available';
    }

    $upd = $pdo->prepare("
        UPDATE inventory_items
        SET current_quantity = :qty,
            status           = :status
        WHERE item_id        = :id
    ");
    $upd->execute([
        ':qty'    => $clampedLedgerQty,
        ':status' => $status,
        ':id'     => $itemId,
    ]);

    return [
        'current_quantity' => $clampedLedgerQty,
        'usable_fefo_qty'  => $usableFefoQty,
        'status'           => $status,
    ];
}

/**
 * Pure-read fetch of all catalog items with batch counts and live usable FEFO stock (NFR-PERF-01).
 */
function getInventoryItems(PDO $pdo, string $categoryFilter = '', string $search = ''): array
{
    $sql = "
        SELECT i.item_id, i.item_name, i.category, i.unit,
               i.minimum_stock_threshold, i.current_quantity,
               COALESCE(b_agg.usable_fefo_qty, 0) AS usable_fefo_qty,
               COALESCE(b_agg.expired_holding_qty, 0) AS expired_holding_qty,
               b_agg.earliest_expiry,
               CASE
                   WHEN COALESCE(b_agg.usable_fefo_qty, 0) = 0 THEN 'Out of Stock'
                   WHEN COALESCE(b_agg.usable_fefo_qty, 0) <= i.minimum_stock_threshold THEN 'Low Stock'
                   ELSE 'Available'
               END AS status
        FROM inventory_items i
        LEFT JOIN (
            SELECT b.item_id,
                   SUM(CASE
                       WHEN b.status = 'Available'
                        AND b.quantity_remaining > 0
                        AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())
                       THEN b.quantity_remaining ELSE 0
                   END) AS usable_fefo_qty,
                   SUM(CASE
                       WHEN b.quantity_remaining > 0
                        AND (b.status = 'Expired'
                             OR (b.status = 'Available' AND b.expiry_date IS NOT NULL AND b.expiry_date < CURDATE()))
                       THEN b.quantity_remaining ELSE 0
                   END) AS expired_holding_qty,
                   MIN(CASE
                       WHEN b.status = 'Available'
                        AND b.quantity_remaining > 0
                        AND b.expiry_date IS NOT NULL
                        AND b.expiry_date >= CURDATE()
                       THEN b.expiry_date ELSE NULL
                   END) AS earliest_expiry
            FROM stock_batches b
            GROUP BY b.item_id
        ) b_agg ON b_agg.item_id = i.item_id
        WHERE 1=1
    ";
    $params = [];

    if ($categoryFilter !== '' && in_array($categoryFilter, INVENTORY_CATEGORIES, true)) {
        $sql .= " AND i.category = :cat";
        $params[':cat'] = $categoryFilter;
    }

    if ($search !== '') {
        $sql .= " AND i.item_name LIKE :q";
        $params[':q'] = '%' . trim($search) . '%';
    }

    $sql .= " ORDER BY i.item_name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Pure-read fetch of all batches ordered by deterministic FEFO rules (expiry_date ASC, NULL last).
 * Evaluates past-due expiry dates dynamically at read time so badges never go stale (NFR-PERF-01).
 */
function getStockBatches(PDO $pdo, int $itemIdFilter = 0, string $statusFilter = ''): array
{
    $effectiveStatusExpr = "
        CASE
            WHEN b.status = 'Available'
             AND b.expiry_date IS NOT NULL
             AND b.expiry_date < CURDATE()
             AND b.quantity_remaining > 0
            THEN 'Expired'
            ELSE b.status
        END
    ";

    $sql = "
        SELECT b.batch_id, b.item_id, b.batch_no, b.quantity_received, b.quantity_remaining,
               b.expiry_date, b.date_received,
               b.status AS persisted_status,
               ({$effectiveStatusExpr}) AS status,
               i.item_name, i.category, i.unit
        FROM stock_batches b
        INNER JOIN inventory_items i ON b.item_id = i.item_id
        WHERE 1=1
    ";
    $params = [];

    if ($itemIdFilter > 0) {
        $sql .= " AND b.item_id = :iid";
        $params[':iid'] = $itemIdFilter;
    }

    if ($statusFilter !== '') {
        $sql .= " AND ({$effectiveStatusExpr}) = :status";
        $params[':status'] = $statusFilter;
    }

    // Deterministic FEFO ordering: non-null expiry_date ASC first, NULL expiry last
    $sql .= " ORDER BY (b.expiry_date IS NULL) ASC, b.expiry_date ASC, b.batch_id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Fetch immutable stock transaction ledger rows (D9).
 */
function getStockTransactions(PDO $pdo, string $typeFilter = '', int $itemIdFilter = 0, int $limit = 150): array
{
    $limit = max(1, min(500, $limit));
    $sql = "
        SELECT t.*,
               i.item_name, i.unit, i.category,
               b.batch_no, b.expiry_date,
               u.full_name AS performed_by_name
        FROM stock_transactions t
        INNER JOIN inventory_items i ON t.item_id = i.item_id
        LEFT JOIN stock_batches b ON t.batch_id = b.batch_id
        LEFT JOIN users u ON t.performed_by = u.user_id
        WHERE 1=1
    ";
    $params = [];

    if ($typeFilter !== '' && in_array($typeFilter, STOCK_TRANSACTION_TYPES, true)) {
        $sql .= " AND t.transaction_type = :ttype";
        $params[':ttype'] = $typeFilter;
    }

    if ($itemIdFilter > 0) {
        $sql .= " AND t.item_id = :iid";
        $params[':iid'] = $itemIdFilter;
    }

    $sql .= " ORDER BY t.transaction_date DESC, t.transaction_id DESC LIMIT :limit";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        if ($k === ':iid') {
            $stmt->bindValue($k, (int) $v, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($k, (string) $v, PDO::PARAM_STR);
        }
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * FR-18 & NFR-PERF-01: Pure-read low-stock items (evaluated against usable non-expired
 * FEFO quantity so undisposed expired stock never hides a shortage) and expired / near-expiry batches.
 */
function getInventoryAlerts(PDO $pdo, int $nearExpiryDays = 90): array
{
    $lowStock = $pdo->query("
        SELECT i.item_id, i.item_name, i.category, i.unit,
               i.minimum_stock_threshold, i.current_quantity,
               COALESCE(b_agg.usable_fefo_qty, 0) AS usable_fefo_qty,
               COALESCE(b_agg.expired_holding_qty, 0) AS expired_holding_qty,
               CASE
                   WHEN COALESCE(b_agg.usable_fefo_qty, 0) = 0 THEN 'Out of Stock'
                   ELSE 'Low Stock'
               END AS status
        FROM inventory_items i
        LEFT JOIN (
            SELECT b.item_id,
                   SUM(CASE
                       WHEN b.status = 'Available'
                        AND b.quantity_remaining > 0
                        AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())
                       THEN b.quantity_remaining ELSE 0
                   END) AS usable_fefo_qty,
                   SUM(CASE
                       WHEN b.quantity_remaining > 0
                        AND (b.status = 'Expired'
                             OR (b.status = 'Available' AND b.expiry_date IS NOT NULL AND b.expiry_date < CURDATE()))
                       THEN b.quantity_remaining ELSE 0
                   END) AS expired_holding_qty
            FROM stock_batches b
            GROUP BY b.item_id
        ) b_agg ON b_agg.item_id = i.item_id
        WHERE COALESCE(b_agg.usable_fefo_qty, 0) <= i.minimum_stock_threshold
        ORDER BY COALESCE(b_agg.usable_fefo_qty, 0) ASC, i.item_name ASC
    ")->fetchAll();

    $stmt = $pdo->prepare("
        SELECT b.batch_id, b.item_id, b.batch_no, b.quantity_received, b.quantity_remaining,
               b.expiry_date, b.date_received,
               CASE
                   WHEN b.status = 'Expired' OR b.expiry_date < CURDATE() THEN 'Expired'
                   ELSE b.status
               END AS status,
               i.item_name, i.unit, i.category
        FROM stock_batches b
        INNER JOIN inventory_items i ON b.item_id = i.item_id
        WHERE b.expiry_date IS NOT NULL
          AND b.quantity_remaining > 0
          AND (b.status = 'Expired'
               OR (b.status = 'Available' AND b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL :days DAY)))
        ORDER BY (b.status = 'Expired' OR b.expiry_date < CURDATE()) DESC, b.expiry_date ASC
    ");
    $stmt->bindValue(':days', max(1, $nearExpiryDays), PDO::PARAM_INT);
    $stmt->execute();
    $expiryAlerts = $stmt->fetchAll();

    return [
        'low_stock'     => $lowStock,
        'expiry_alerts' => $expiryAlerts,
    ];
}

/**
 * FR-15: Create a new catalog item in `inventory_items`.
 * Note: `current_quantity` starts at 0 and can only be increased via Stock-In (BR-04).
 */
function createCatalogItem(
    PDO $pdo,
    string $itemName,
    string $category,
    string $unit,
    int $minThreshold,
    int $actingUserId
): ?string {
    $itemName     = trim($itemName);
    $unit         = trim($unit);
    $minThreshold = max(0, $minThreshold);

    if ($itemName === '' || $unit === '' || !in_array($category, INVENTORY_CATEGORIES, true)) {
        return 'Item name, valid category (Medicine/Supply/Equipment), and unit are required (FR-15).';
    }

    $chk = $pdo->prepare("SELECT COUNT(*) FROM inventory_items WHERE LOWER(item_name) = LOWER(:name)");
    $chk->execute([':name' => $itemName]);
    if ((int) $chk->fetchColumn() > 0) {
        return "Item '{$itemName}' already exists in the catalog.";
    }

    $stmt = $pdo->prepare("
        INSERT INTO inventory_items
            (item_name, category, unit, minimum_stock_threshold, current_quantity, status)
        VALUES
            (:name, :cat, :unit, :threshold, 0, 'Out of Stock')
    ");
    $stmt->execute([
        ':name'      => $itemName,
        ':cat'       => $category,
        ':unit'      => $unit,
        ':threshold' => $minThreshold,
    ]);

    $newId = (int) $pdo->lastInsertId();
    logAudit(
        $pdo,
        'CATALOG_CREATE',
        "Created inventory catalog item #{$newId} '{$itemName}' ({$category}, threshold: {$minThreshold} {$unit})",
        'inventory_items',
        $newId,
        $actingUserId
    );

    return null;
}

/**
 * FR-16 / FR-17 / BR-04: Record a Stock-In receipt by creating a new batch in
 * `stock_batches`, writing a `Stock-In` ledger row to `stock_transactions`, and
 * recomputing `inventory_items.current_quantity`.
 *
 * @return array{success: bool, batch_id?: int, transaction_id?: int, error?: string}
 */
function recordStockIn(
    PDO $pdo,
    int $itemId,
    string $batchNo,
    int $quantity,
    ?string $expiryDate,
    string $dateReceived,
    string $referenceNo,
    int $performedBy
): array {
    $batchNo      = strtoupper(trim($batchNo));
    $expiryDate   = ($expiryDate !== null && trim($expiryDate) !== '') ? trim($expiryDate) : null;
    $dateReceived = trim($dateReceived) ?: date('Y-m-d');
    $referenceNo  = trim($referenceNo);

    if ($itemId <= 0 || $batchNo === '' || $quantity <= 0) {
        return ['success' => false, 'error' => 'Item, batch number, and a positive quantity are required for Stock-In.'];
    }

    if ($expiryDate !== null && strtotime($expiryDate) < strtotime(date('Y-m-d'))) {
        return ['success' => false, 'error' => 'Cannot receive an already-expired batch into usable stock.'];
    }

    $pdo->beginTransaction();
    try {
        $bStmt = $pdo->prepare("
            INSERT INTO stock_batches
                (item_id, batch_no, quantity_received, quantity_remaining, expiry_date, date_received, status)
            VALUES
                (:item_id, :batch_no, :qty_rec, :qty_rem, :expiry, :date_rec, 'Available')
        ");
        $bStmt->execute([
            ':item_id'  => $itemId,
            ':batch_no' => $batchNo,
            ':qty_rec'  => $quantity,
            ':qty_rem'  => $quantity,
            ':expiry'   => $expiryDate,
            ':date_rec' => $dateReceived,
        ]);
        $batchId = (int) $pdo->lastInsertId();

        $tStmt = $pdo->prepare("
            INSERT INTO stock_transactions
                (item_id, batch_id, transaction_type, quantity, transaction_date, performed_by, disposal_reason, reference_no)
            VALUES
                (:item_id, :batch_id, 'Stock-In', :qty, NOW(), :by, NULL, :ref)
        ");
        $tStmt->execute([
            ':item_id'  => $itemId,
            ':batch_id' => $batchId,
            ':qty'      => $quantity,
            ':by'       => $performedBy ?: null,
            ':ref'      => $referenceNo !== '' ? $referenceNo : null,
        ]);
        $txId = (int) $pdo->lastInsertId();

        recalculateItemQuantityAndStatus($pdo, $itemId);
        $pdo->commit();

        logAudit(
            $pdo,
            'STOCK_ADJUST',
            "Stock-In +{$quantity} units for item #{$itemId} (Batch {$batchNo}, Exp: " . ($expiryDate ?: 'Non-Expiring') . ")",
            'stock_transactions',
            $txId,
            $performedBy
        );

        return ['success' => true, 'batch_id' => $batchId, 'transaction_id' => $txId];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'error' => 'Stock-In failed: ' . $e->getMessage()];
    }
}

/**
 * FR-17 / FR-19 / BR-04 / BR-05 / BR-06: Deterministic FEFO Stock-Out Engine.
 * - Automatically selects `Available`, non-expired batches ordered by:
 *     `expiry_date IS NULL ASC, expiry_date ASC, batch_id ASC`
 *   so earliest-expiring stock is always dispensed first, and non-expiring (`NULL`)
 *   equipment/supplies are issued last.
 * - Expired batches are strictly excluded (BR-06).
 * - Clamps quantities so stock can never go negative (BR-04).
 *
 * @return array{success: bool, allocations?: array, message?: string, error?: string}
 */
function recordStockOutFefo(
    PDO $pdo,
    int $itemId,
    int $requestedQty,
    string $referenceNo,
    int $performedBy
): array {
    if ($itemId <= 0 || $requestedQty <= 0) {
        return ['success' => false, 'error' => 'Please select a valid item and enter a quantity greater than 0.'];
    }

    syncExpiredBatches($pdo);

    $pdo->beginTransaction();
    try {
        // Lock eligible FEFO batches in deterministic order (BR-05, BR-06)
        $bStmt = $pdo->prepare("
            SELECT *
            FROM stock_batches
            WHERE item_id = :item_id
              AND status = 'Available'
              AND quantity_remaining > 0
              AND (expiry_date IS NULL OR expiry_date >= CURDATE())
            ORDER BY (expiry_date IS NULL) ASC, expiry_date ASC, batch_id ASC
            FOR UPDATE
        ");
        $bStmt->execute([':item_id' => $itemId]);
        $eligibleBatches = $bStmt->fetchAll();

        $totalUsable = 0;
        foreach ($eligibleBatches as $b) {
            $totalUsable += (int) $b['quantity_remaining'];
        }

        if ($totalUsable < $requestedQty) {
            $pdo->rollBack();
            return [
                'success' => false,
                'error'   => "Insufficient non-expired stock for FEFO issuance (BR-04/BR-06). Requested: {$requestedQty}, Usable available: {$totalUsable}.",
            ];
        }

        $remainingToIssue = $requestedQty;
        $allocations      = [];

        foreach ($eligibleBatches as $batch) {
            if ($remainingToIssue <= 0) {
                break;
            }

            $batchId  = (int) $batch['batch_id'];
            $availQty = (int) $batch['quantity_remaining'];
            $takeQty  = min($remainingToIssue, $availQty);
            $newRem   = $availQty - $takeQty;
            $newState = ($newRem === 0) ? 'Depleted' : 'Available';

            $uBatch = $pdo->prepare("
                UPDATE stock_batches
                SET quantity_remaining = :rem,
                    status             = :status
                WHERE batch_id         = :bid
            ");
            $uBatch->execute([
                ':rem'    => $newRem,
                ':status' => $newState,
                ':bid'    => $batchId,
            ]);

            $tStmt = $pdo->prepare("
                INSERT INTO stock_transactions
                    (item_id, batch_id, transaction_type, quantity, transaction_date, performed_by, disposal_reason, reference_no)
                VALUES
                    (:item_id, :batch_id, 'Stock-Out', :qty, NOW(), :by, NULL, :ref)
            ");
            $tStmt->execute([
                ':item_id'  => $itemId,
                ':batch_id' => $batchId,
                ':qty'      => $takeQty,
                ':by'       => $performedBy ?: null,
                ':ref'      => trim($referenceNo) !== '' ? trim($referenceNo) : null,
            ]);

            $allocations[] = [
                'batch_id'    => $batchId,
                'batch_no'    => $batch['batch_no'],
                'expiry_date' => $batch['expiry_date'] ?? 'Non-Expiring',
                'issued_qty'  => $takeQty,
            ];

            $remainingToIssue -= $takeQty;
        }

        recalculateItemQuantityAndStatus($pdo, $itemId);
        $pdo->commit();

        $summaryParts = [];
        foreach ($allocations as $a) {
            $summaryParts[] = "{$a['batch_no']} ({$a['issued_qty']} units, exp: {$a['expiry_date']})";
        }
        $allocSummary = implode('; ', $summaryParts);

        logAudit(
            $pdo,
            'STOCK_ADJUST',
            "FEFO Stock-Out -{$requestedQty} units for item #{$itemId} across batches: {$allocSummary}",
            'inventory_items',
            $itemId,
            $performedBy
        );

        return [
            'success'     => true,
            'allocations' => $allocations,
            'message'     => "Dispensed {$requestedQty} units using FEFO order: {$allocSummary}",
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'error' => 'FEFO Stock-Out failed: ' . $e->getMessage()];
    }
}

/**
 * FR-17 / BR-06 / BR-07: Audited Disposal / Expired-Removal.
 * - Expired or damaged batches are NEVER deleted from the database.
 * - Their removal must be recorded as a `Disposal/Expired-Removal` transaction
 *   with a mandatory `disposal_reason`, `performed_by`, and audit entry.
 *
 * @return array{success: bool, transaction_id?: int, message?: string, error?: string}
 */
function recordStockDisposal(
    PDO $pdo,
    int $batchId,
    int $quantity,
    string $disposalReason,
    string $referenceNo,
    int $performedBy
): array {
    $disposalReason = trim($disposalReason);
    $referenceNo    = trim($referenceNo);

    // BR-07: Disposal requires a mandatory disposal_reason
    if ($disposalReason === '') {
        return ['success' => false, 'error' => 'A specific disposal reason is strictly required for Disposal/Expired-Removal (BR-07).'];
    }

    if ($batchId <= 0 || $quantity <= 0) {
        return ['success' => false, 'error' => 'Please select a valid batch and enter a positive quantity to dispose.'];
    }

    syncExpiredBatches($pdo);

    $pdo->beginTransaction();
    try {
        $bStmt = $pdo->prepare("SELECT * FROM stock_batches WHERE batch_id = :bid FOR UPDATE");
        $bStmt->execute([':bid' => $batchId]);
        $batch = $bStmt->fetch();

        if (!$batch) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Selected stock batch not found.'];
        }

        $remQty = (int) $batch['quantity_remaining'];
        if ($quantity > $remQty) {
            $pdo->rollBack();
            return ['success' => false, 'error' => "Cannot dispose {$quantity} units; batch {$batch['batch_no']} only has {$remQty} remaining."];
        }

        $itemId   = (int) $batch['item_id'];
        $newRem   = $remQty - $quantity;
        $newState = ($newRem === 0) ? 'Disposed' : $batch['status'];

        $uStmt = $pdo->prepare("
            UPDATE stock_batches
            SET quantity_remaining = :rem,
                status             = :status
            WHERE batch_id         = :bid
        ");
        $uStmt->execute([
            ':rem'    => $newRem,
            ':status' => $newState,
            ':bid'    => $batchId,
        ]);

        $tStmt = $pdo->prepare("
            INSERT INTO stock_transactions
                (item_id, batch_id, transaction_type, quantity, transaction_date, performed_by, disposal_reason, reference_no)
            VALUES
                (:item_id, :batch_id, 'Disposal/Expired-Removal', :qty, NOW(), :by, :reason, :ref)
        ");
        $tStmt->execute([
            ':item_id'  => $itemId,
            ':batch_id' => $batchId,
            ':qty'      => $quantity,
            ':by'       => $performedBy ?: null,
            ':reason'   => $disposalReason,
            ':ref'      => $referenceNo !== '' ? $referenceNo : null,
        ]);
        $txId = (int) $pdo->lastInsertId();

        recalculateItemQuantityAndStatus($pdo, $itemId);
        $pdo->commit();

        logAudit(
            $pdo,
            'STOCK_DISPOSAL',
            "Audited Disposal of {$quantity} units from Batch {$batch['batch_no']} — Reason: {$disposalReason}",
            'stock_transactions',
            $txId,
            $performedBy
        );

        return [
            'success'        => true,
            'transaction_id' => $txId,
            'message'        => "Audited disposal of {$quantity} units from batch {$batch['batch_no']} recorded (Reason: {$disposalReason}).",
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'error' => 'Disposal failed: ' . $e->getMessage()];
    }
}

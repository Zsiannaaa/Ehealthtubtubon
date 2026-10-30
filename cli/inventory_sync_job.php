<?php
// ============================================================================
// cli/inventory_sync_job.php — Scheduled Batch Expiry & Usable Stock Sync Runner
// Covers: BR-04, BR-06, FR-18, NFR-PERF-01
//
// Invoked daily (e.g. 00:05 AM via Windows Task Scheduler or cron) so batch
// expiry state transitions (`Available` -> `Expired`) and derived item status
// recalculations run out-of-band rather than executing UPDATEs on HTTP GETs.
// ============================================================================
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be executed from the command line (CLI).');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../modules/inventory_module.php';

try {
    $expiredCount = syncExpiredBatches($pdo);

    // Ensure every catalog item's persisted `current_quantity` and usable-FEFO `status` are aligned
    $itemIds = $pdo->query("SELECT item_id FROM inventory_items")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($itemIds as $id) {
        recalculateItemQuantityAndStatus($pdo, (int) $id);
    }

    logAudit(
        $pdo,
        'INVENTORY_SYNC',
        "CLI inventory sync job flagged {$expiredCount} newly-expired batch(es) and verified " . count($itemIds) . " catalog item(s)",
        'stock_batches',
        null,
        null
    );

    fwrite(
        STDOUT,
        sprintf(
            "[OK] Inventory sync completed: %d newly-expired batch(es) flagged, %d catalog item(s) verified.\n",
            $expiredCount,
            count($itemIds)
        )
    );
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "[ERROR] Inventory sync job failed: " . $e->getMessage() . "\n");
    exit(1);
}

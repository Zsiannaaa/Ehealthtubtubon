<?php
// ============================================================================
// cli/backup_job.php — Scheduled Nightly Backup Runner (Phase 5 Sec. 1.9, FR-23, BR-15)
// Invoked by Windows Task Scheduler or Linux cron off clinic hours.
// Reuses the exact same config/database.php and M7 backup module functions.
// ============================================================================
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be executed from the command line (CLI).');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../modules/reports_backup_module.php';

$res = createSystemBackupArchive($pdo, null);
if ($res['success']) {
    fwrite(STDOUT, "[OK] Backup archive created: {$res['filename']} ({$res['size_bytes']} bytes)\n");
    exit(0);
}

fwrite(STDERR, "[ERROR] Backup job failed: " . ($res['error'] ?? 'Unknown error') . "\n");
exit(1);

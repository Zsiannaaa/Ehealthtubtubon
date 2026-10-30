<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Access denied.');
}

// ============================================================================
// modules/reports_backup_module.php — Module M7: Reports, Backup & Resident View
// Covers:
//   - FR-23 / BR-15: Server-aggregated dashboard/report counts (Chart.js) plus
//                    scheduled nightly + on-demand database & uploads backup
//                    into STORAGE_BACKUPS_PATH
//   - FR-24 / BR-11: Controlled Resident Portal strictly limited to own data
// ============================================================================

require_once __DIR__ . '/audit_module.php';

/**
 * FR-23: Aggregate clinic statistics for MHO/DOH offline summary reports & Chart.js.
 */
function getClinicSummaryReport(PDO $pdo): array
{
    $totalPatients  = (int) $pdo->query("SELECT COUNT(*) FROM patients WHERE status = 'Active'")->fetchColumn();
    $archivedPats   = (int) $pdo->query("SELECT COUNT(*) FROM patients WHERE status = 'Archived'")->fetchColumn();
    $totalRecords   = (int) $pdo->query("SELECT COUNT(*) FROM service_records")->fetchColumn();
    $verifiedRecs   = (int) $pdo->query("SELECT COUNT(*) FROM service_records WHERE verified_by IS NOT NULL")->fetchColumn();
    $totalDocs      = (int) $pdo->query("SELECT COUNT(*) FROM documents")->fetchColumn();
    $totalAppts     = (int) $pdo->query("SELECT COUNT(*) FROM appointments")->fetchColumn();
    $completedAppts = (int) $pdo->query("SELECT COUNT(*) FROM appointments WHERE status = 'Completed'")->fetchColumn();
    $totalSmsSent   = (int) $pdo->query("SELECT COUNT(*) FROM sms_logs WHERE consent_verified = 1")->fetchColumn();
    $lowStockCount  = (int) $pdo->query("
        SELECT COUNT(*)
        FROM inventory_items i
        LEFT JOIN (
            SELECT b.item_id,
                   SUM(CASE
                       WHEN b.status = 'Available'
                        AND b.quantity_remaining > 0
                        AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())
                       THEN b.quantity_remaining ELSE 0
                   END) AS usable_fefo_qty
            FROM stock_batches b
            GROUP BY b.item_id
        ) b_agg ON b_agg.item_id = i.item_id
        WHERE COALESCE(b_agg.usable_fefo_qty, 0) <= i.minimum_stock_threshold
    ")->fetchColumn();

    // Encounters per program (BR-02)
    $programTally = array_fill_keys(PROGRAM_TYPES, 0);
    $pRows = $pdo->query("SELECT program_type, COUNT(*) AS cnt FROM service_records GROUP BY program_type")->fetchAll();
    foreach ($pRows as $r) {
        if (isset($programTally[$r['program_type']])) {
            $programTally[$r['program_type']] = (int) $r['cnt'];
        }
    }

    // Document 4-State tally (BR-03)
    $docTally = array_fill_keys(DOCUMENT_STATES, 0);
    $dRows = $pdo->query("SELECT status, COUNT(*) AS cnt FROM documents GROUP BY status")->fetchAll();
    foreach ($dRows as $r) {
        if (isset($docTally[$r['status']])) {
            $docTally[$r['status']] = (int) $r['cnt'];
        }
    }

    // Appointment 4-Status tally (BR-08)
    $apptTally = array_fill_keys(APPOINTMENT_STATUSES, 0);
    $aRows = $pdo->query("SELECT status, COUNT(*) AS cnt FROM appointments GROUP BY status")->fetchAll();
    foreach ($aRows as $r) {
        if (isset($apptTally[$r['status']])) {
            $apptTally[$r['status']] = (int) $r['cnt'];
        }
    }

    // Stock movement summary
    $stockTally = array_fill_keys(STOCK_TRANSACTION_TYPES, 0);
    $sRows = $pdo->query("SELECT transaction_type, COALESCE(SUM(quantity), 0) AS total_qty FROM stock_transactions GROUP BY transaction_type")->fetchAll();
    foreach ($sRows as $r) {
        if (isset($stockTally[$r['transaction_type']])) {
            $stockTally[$r['transaction_type']] = (int) $r['total_qty'];
        }
    }

    return [
        'kpis' => [
            'active_patients'   => $totalPatients,
            'archived_patients' => $archivedPats,
            'total_records'     => $totalRecords,
            'verified_records'  => $verifiedRecs,
            'total_documents'   => $totalDocs,
            'total_appointments'=> $totalAppts,
            'completed_appts'   => $completedAppts,
            'sms_sent'          => $totalSmsSent,
            'low_stock_items'   => $lowStockCount,
        ],
        'program_tally' => $programTally,
        'doc_tally'     => $docTally,
        'appt_tally'    => $apptTally,
        'stock_tally'   => $stockTally,
    ];
}

/**
 * Generate a complete SQL dump of all 13 tables in `tubtubon_ehealth` using PDO.
 */
function exportDatabaseSqlDump(PDO $pdo): string
{
    $tables = [
        'roles',
        'users',
        'patients',
        'service_records',
        'appointments',
        'documents',
        'inventory_items',
        'stock_batches',
        'stock_transactions',
        'ocr_ingestion_drafts',
        'sms_logs',
        'consent_records',
        'audit_logs',
    ];

    $sql  = "-- ============================================================================\n";
    $sql .= "-- Tubtubon eHealth Database Backup (BR-15 / FR-23)\n";
    $sql .= "-- Generated at: " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- ============================================================================\n\n";
    $sql .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";

    foreach ($tables as $tbl) {
        $createRow = $pdo->query("SHOW CREATE TABLE `{$tbl}`")->fetch(PDO::FETCH_NUM);
        $createSql = $createRow[1] ?? '';

        $sql .= "-- Table: `{$tbl}`\n";
        $sql .= "DROP TABLE IF EXISTS `{$tbl}`;\n";
        $sql .= $createSql . ";\n\n";

        $rows = $pdo->query("SELECT * FROM `{$tbl}`")->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            $cols = array_map(static fn(string $c): string => "`{$c}`", array_keys($rows[0]));
            $colList = implode(', ', $cols);

            foreach ($rows as $row) {
                $vals = [];
                foreach ($row as $v) {
                    if ($v === null) {
                        $vals[] = 'NULL';
                    } else {
                        $vals[] = $pdo->quote((string) $v);
                    }
                }
                $sql .= "INSERT INTO `{$tbl}` ({$colList}) VALUES (" . implode(', ', $vals) . ");\n";
            }
            $sql .= "\n";
        }
    }

    $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    return $sql;
}

/**
 * FR-23 / BR-15: Run a full backup of both the 13-table MySQL database AND
 * all uploaded files in `storage/uploads`, saving the archive in `storage/backups`.
 *
 * @return array{success: bool, filename?: string, size_bytes?: int, error?: string}
 */
function createSystemBackupArchive(PDO $pdo, ?int $actingUserId = null): array
{
    try {
        if (!is_dir(STORAGE_BACKUPS_PATH)) {
            mkdir(STORAGE_BACKUPS_PATH, 0755, true);
        }

        $timestamp = date('Ymd_His');
        $sqlDump   = exportDatabaseSqlDump($pdo);

        if (class_exists('ZipArchive')) {
            $zipFilename = "tubtubon_ehealth_backup_{$timestamp}.zip";
            $zipPath     = STORAGE_BACKUPS_PATH . '/' . $zipFilename;

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                return ['success' => false, 'error' => 'Could not create backup ZIP archive.'];
            }

            // 1. Add full SQL database dump
            $zip->addFromString("database_dump_{$timestamp}.sql", $sqlDump);

            // 2. Add all uploaded scan files from STORAGE_UPLOADS_PATH (BR-15)
            if (is_dir(STORAGE_UPLOADS_PATH)) {
                $files = scandir(STORAGE_UPLOADS_PATH) ?: [];
                foreach ($files as $f) {
                    if ($f === '.' || $f === '..' || $f === 'index.php' || $f === '.htaccess') {
                        continue;
                    }
                    $full = STORAGE_UPLOADS_PATH . '/' . $f;
                    if (is_file($full)) {
                        $zip->addFile($full, 'uploads/' . $f);
                    }
                }
            }

            $zip->close();
            $size = is_file($zipPath) ? (int) filesize($zipPath) : 0;

            logAudit(
                $pdo,
                'BACKUP',
                "Generated full database + uploads backup archive '{$zipFilename}' ({$size} bytes)",
                'system',
                $zipFilename,
                $actingUserId
            );

            return [
                'success'    => true,
                'filename'   => $zipFilename,
                'size_bytes' => $size,
            ];
        }

        // Fallback if ZipArchive extension is disabled: save raw .sql dump
        $sqlFilename = "tubtubon_ehealth_db_{$timestamp}.sql";
        $sqlPath     = STORAGE_BACKUPS_PATH . '/' . $sqlFilename;
        file_put_contents($sqlPath, $sqlDump);
        $size = (int) filesize($sqlPath);

        logAudit(
            $pdo,
            'BACKUP',
            "Generated SQL database backup '{$sqlFilename}' ({$size} bytes)",
            'system',
            $sqlFilename,
            $actingUserId
        );

        return [
            'success'    => true,
            'filename'   => $sqlFilename,
            'size_bytes' => $size,
        ];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'Backup failed: ' . $e->getMessage()];
    }
}

/**
 * List existing backup archives stored in STORAGE_BACKUPS_PATH.
 */
function listBackupArchives(): array
{
    if (!is_dir(STORAGE_BACKUPS_PATH)) {
        return [];
    }

    $files = scandir(STORAGE_BACKUPS_PATH) ?: [];
    $list  = [];
    foreach ($files as $f) {
        if ($f === '.' || $f === '..' || $f === 'index.php' || $f === '.htaccess') {
            continue;
        }
        $full = STORAGE_BACKUPS_PATH . '/' . $f;
        if (is_file($full)) {
            $list[] = [
                'filename'   => $f,
                'size_bytes' => (int) filesize($full),
                'created_at' => date('Y-m-d H:i:s', (int) filemtime($full)),
            ];
        }
    }

    usort($list, static fn(array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));
    return $list;
}

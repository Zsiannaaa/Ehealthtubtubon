<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Access denied.');
}

// ============================================================================
// config/database.php — PDO Database Connection (MySQL 8.4 LTS, utf8mb4)
// Enforces native prepared statements (ATTR_EMULATE_PREPARES => false),
// exceptions enabled, and friendly paper-fallback outage screen (BR-13, NFR-REL-01).
// ============================================================================

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/constants.php';

$dbHost = (string) env('DB_HOST', '127.0.0.1');
$dbPort = (string) env('DB_PORT', '3306');
$dbName = (string) env('DB_NAME', 'tubtubon_ehealth');
$dbUser = (string) env('DB_USER', 'root');
$dbPass = (string) env('DB_PASS', '');

try {
    $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
    if ($dbPort !== '') {
        $dsn .= ";port={$dbPort}";
    }

    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false, // Native server-side prepared statements (Phase 5 Sec. 1.3)
    ]);
} catch (PDOException $e) {
    // If called from an API endpoint, return structured JSON error without leaking internals
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if (str_contains($script, '/api/')) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error'   => 'Database service is temporarily unavailable. Please use official paper fallback forms (BR-13).',
        ]);
        exit();
    }

    // Friendly Paper-Fallback Outage Screen (BR-13, NFR-REL-01, Phase 8 Sec. 54.1)
    http_response_code(503);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>System Unavailable — Tubtubon eHealth</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { background: #f4f7f5; font-family: system-ui, -apple-system, sans-serif; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
            .outage-card { max-width: 560px; background: #fff; border-radius: 14px; border-top: 6px solid #0B3B22; box-shadow: 0 10px 30px rgba(0,0,0,.08); padding: 2rem; }
            .badge-rule { background: #e8f3ec; color: #0B3B22; font-weight: 600; font-size: .8rem; padding: .35rem .65rem; border-radius: 6px; }
        </style>
    </head>
    <body>
        <div class="outage-card">
            <span class="badge-rule mb-2 d-inline-block">BR-13 • Hybrid Paper-Fallback Continuity Mode</span>
            <h3 class="fw-bold text-dark mt-2">Database Service Temporarily Unavailable</h3>
            <p class="text-secondary mb-3">
                Tubtubon eHealth cannot reach the local database server right now. In accordance with clinic continuity rules (<strong>BR-13</strong>), patient care is never blocked:
            </p>
            <ol class="small text-secondary mb-4">
                <li class="mb-1"><strong>Use Official Blank Paper Forms:</strong> Record patient intake and consultation details on the authorized clinic paper form.</li>
                <li class="mb-1"><strong>Paper Remains Official:</strong> The signed physical record serves as the official clinical document during power or system outages.</li>
                <li><strong>Encode &amp; Link Later:</strong> Once database service returns, encode the encounter and file the document under <em>Pending Digitization</em> or <em>Scanned/Attached</em>.</li>
            </ol>
            <a href="" class="btn btn-success btn-sm" style="background:#0B3B22;border-color:#0B3B22;">Retry Connection</a>
        </div>
    </body>
    </html>
    <?php
    exit();
}

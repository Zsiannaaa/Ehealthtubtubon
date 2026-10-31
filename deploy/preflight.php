<?php
// ============================================================================
// deploy/preflight.php — Post-deploy environment checker (CLI only)
//
//   sudo -u www-data php deploy/preflight.php
//
// Verifies everything the app silently depends on. Run it after bootstrap.sh
// and again after the SQL import. Exit code 0 = all pass, 1 = at least one FAIL.
// ============================================================================
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../config/env.php';

$pass = 0;
$fail = 0;
$warn = 0;

function line(string $status, string $label, string $detail = ''): void
{
    $tag = match ($status) {
        'PASS' => "\033[32m[PASS]\033[0m",
        'WARN' => "\033[33m[WARN]\033[0m",
        default => "\033[31m[FAIL]\033[0m",
    };
    printf("%s %-46s %s\n", $tag, $label, $detail);
}

function check(string $label, bool $ok, string $detail = '', bool $isWarn = false): bool
{
    global $pass, $fail, $warn;
    if ($ok) {
        line('PASS', $label, $detail);
        $pass++;
        return true;
    }
    line($isWarn ? 'WARN' : 'FAIL', $label, $detail);
    $isWarn ? $warn++ : $fail++;
    return false;
}

echo "\n=== Tubtubon eHealth — deploy preflight ===\n\n";

// --- PHP runtime -----------------------------------------------------------
check('PHP >= 8.1', version_compare(PHP_VERSION, '8.1', '>='), 'found ' . PHP_VERSION);
check('shell_exec() available', function_exists('shell_exec'), 'required by OCR runner');
check('display_errors off', ini_get('display_errors') == false || ini_get('display_errors') === '0' || ini_get('display_errors') === '', 'ini=' . var_export(ini_get('display_errors'), true), true);

foreach (['pdo_mysql', 'mbstring', 'zip', 'curl', 'fileinfo', 'json'] as $ext) {
    check("ext: {$ext}", extension_loaded($ext));
}

$uploadMax = ini_get('upload_max_filesize');
$postMax   = ini_get('post_max_size');
$toBytes = static function (string $v): int {
    $v = trim($v);
    if ($v === '' || $v === '-1') {
        return -1; // unlimited
    }
    $unit = strtolower(substr($v, -1));
    $num  = (float) $v;
    return (int) match ($unit) {
        'g' => $num * 1024 ** 3,
        'm' => $num * 1024 ** 2,
        'k' => $num * 1024,
        default => $num,
    };
};
$upBytes = $toBytes((string) $uploadMax);
$poBytes = $toBytes((string) $postMax);
check('upload_max_filesize >= 8M', $upBytes < 0 || $upBytes >= 8 * 1024 * 1024, "{$uploadMax} (app caps at 5 MB)");
check('post_max_size >= 8M', $poBytes < 0 || $poBytes >= 8 * 1024 * 1024, $postMax);
$maxExec = (int) ini_get('max_execution_time');
check('max_execution_time >= 60 or unlimited', $maxExec === 0 || $maxExec >= 60,
    $maxExec === 0 ? 'unlimited (0)' : $maxExec . 's');

// --- .env ------------------------------------------------------------------
$envPath = dirname(__DIR__) . '/.env';
check('.env exists', is_file($envPath), $envPath);
check('.env not world-readable', is_file($envPath) && (fileperms($envPath) & 0004) === 0,
    is_file($envPath) ? substr(sprintf('%o', fileperms($envPath)), -4) : '', true);

// --- writable directories --------------------------------------------------
foreach (['storage/uploads', 'storage/backups'] as $dir) {
    $path = dirname(__DIR__) . '/' . $dir;
    check("writable: {$dir}", is_dir($path) && is_writable($path), $path);
}

// --- database --------------------------------------------------------------
$dbOk = false;
$dbDetail = '';
try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        env('DB_HOST', '127.0.0.1'), env('DB_PORT', '3306'), env('DB_NAME', 'tubtubon_ehealth'));
    $pdo = new PDO($dsn, (string) env('DB_USER', ''), (string) env('DB_PASS', ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $dbOk = true;
    $ver = $pdo->query('SELECT VERSION()')->fetchColumn();
    $dbDetail = "MySQL {$ver}";
} catch (Throwable $e) {
    $dbDetail = 'connection failed — check .env DB_*';
}
check('database connection', $dbOk, $dbDetail);

if ($dbOk) {
    $expected = ['roles', 'users', 'patients', 'service_records', 'appointments',
                 'documents', 'inventory_items', 'stock_batches', 'stock_transactions',
                 'ocr_ingestion_drafts', 'sms_logs', 'consent_records', 'audit_logs'];
    $found = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_diff($expected, $found);
    check('13 tables present', count($found) >= 13 && empty($missing),
        empty($missing) ? count($found) . ' tables' : 'missing: ' . implode(', ', $missing));

    try {
        $nUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $nPat   = (int) $pdo->query('SELECT COUNT(*) FROM patients')->fetchColumn();
        check('seed data imported', $nUsers > 0 && $nPat > 0, "users={$nUsers} patients={$nPat}");
        check('users.patient_id FK present (003)', (bool) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='patient_id'"
        )->fetchColumn());
    } catch (Throwable $e) {
        check('seed data imported', false, 'query failed: ' . $e->getMessage());
    }
}

// --- Tesseract -------------------------------------------------------------
$bin = (string) env('TESSERACT_PATH', '');
if ($bin === '') {
    $bin = trim((string) @shell_exec('command -v tesseract 2>/dev/null')) ?: '/usr/bin/tesseract';
}
$tessOk = is_file($bin) && is_executable($bin);
check('Tesseract binary', $tessOk, $bin);
if ($tessOk) {
    $ver = trim((string) @shell_exec(escapeshellarg($bin) . ' --version 2>&1 | head -1'));
    check('Tesseract version 5.x', (bool) preg_match('/tesseract\s+v?5\./i', $ver), $ver, true);
    // --list-langs writes to stderr on many builds, so merge the streams.
    $langs = (string) @shell_exec(escapeshellarg($bin) . ' --list-langs 2>&1');
    check("Tesseract 'eng' language", (bool) preg_match('/^\s*eng\s*$/m', $langs),
        'if missing: sudo apt-get install -y tesseract-ocr-eng');
}

// --- runtime hardening -----------------------------------------------------
check('APP_ENV=production', env('APP_ENV') === 'production', (string) env('APP_ENV'), true);
check('OCR_ENABLED=true', (string) env('OCR_ENABLED') === 'true', (string) env('OCR_ENABLED'), true);

echo "\n---------------------------------------------\n";
printf("PASS %d   WARN %d   FAIL %d\n", $pass, $warn, $fail);
echo $fail === 0
    ? "Environment looks deployable.\n\n"
    : "Fix the FAILs above before demoing.\n\n";

exit($fail === 0 ? 0 : 1);

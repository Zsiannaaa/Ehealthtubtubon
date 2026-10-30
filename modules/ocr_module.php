<?php
/**
 * Tubtubon eHealth — Module M8: Human-in-the-Loop (HITL) OCR Digitization
 * Phase 8 Sec. 50 (M8) | FR-25, FR-26, FR-27 | BR-10, BR-11, BR-12
 *
 * Processes scanned/photographed standby clinic forms via self-hosted Tesseract OCR
 * (with graceful offline extraction fallback per FR-27), stores `Draft` records in
 * `ocr_ingestion_drafts` (D12) with per-field `confidence_scores` and `flagged_fields`
 * (< 75% threshold), and commits to `service_records` (D4) ONLY after human verification.
 */

declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/audit_module.php';
require_once __DIR__ . '/service_records_module.php';

if (!defined('OCR_LOW_CONFIDENCE_THRESHOLD')) {
    define('OCR_LOW_CONFIDENCE_THRESHOLD', 75);
}
if (!defined('OCR_STATUSES')) {
    define('OCR_STATUSES', ['Draft', 'Verified', 'Rejected']);
}

if (!defined('OCR_RAW_TEXT_MARKER')) {
    // Separates human-verifiable field values from raw engine text inside D12 drafts.
    define('OCR_RAW_TEXT_MARKER', '--- RAW OCR TEXT (evidence only, not committed to D4) ---');
}

/**
 * Resolve the Tesseract binary path across platforms.
 *
 * Accepts either `TESSERACT_PATH` or `TESSERACT_BIN` (the key shipped in
 * `.env.example`), then falls back to the conventional Windows install
 * location and finally to a PATH lookup. Phase 5 Sec. 1.8 / Sec. 2.8.
 */
function resolveTesseractBinary(): string
{
    $candidates = [
        (string) env('TESSERACT_PATH', ''),
        (string) env('TESSERACT_BIN', ''),
        'C:/Program Files/Tesseract-OCR/tesseract.exe',
        'C:/Program Files (x86)/Tesseract-OCR/tesseract.exe',
        '/usr/bin/tesseract',
        '/usr/local/bin/tesseract',
    ];

    foreach ($candidates as $candidate) {
        $candidate = trim($candidate);
        if ($candidate === '') {
            continue;
        }
        // Bare command name (e.g. "tesseract") -> resolve through PATH
        if (!preg_match('#[/\\\\]#', $candidate)) {
            $found = @shell_exec(
                DIRECTORY_SEPARATOR === '\\'
                    ? 'where ' . escapeshellarg($candidate) . ' 2>NUL'
                    : 'command -v ' . escapeshellarg($candidate) . ' 2>/dev/null'
            );
            if (is_string($found) && trim($found) !== '') {
                $first = preg_split('/\r?\n/', trim($found))[0] ?? '';
                if ($first !== '' && is_file($first)) {
                    return $first;
                }
            }
            continue;
        }
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return 'C:/Program Files/Tesseract-OCR/tesseract.exe';
}

/**
 * Checks whether the configured Tesseract binary is available on the host system
 * and reports its real version string (FR-25, FR-27).
 *
 * @return array{available: bool, binary: string, version: string, mode: string}
 */
function getOcrEngineStatus(): array
{
    $enabled = (string) env('OCR_ENABLED', 'true') !== 'false';
    $bin     = resolveTesseractBinary();

    if (!$enabled) {
        return [
            'available' => false,
            'binary'    => $bin,
            'version'   => 'Disabled via OCR_ENABLED=false (FR-27 Manual/Fallback Mode Active)',
            'mode'      => 'disabled',
        ];
    }

    if (is_file($bin)) {
        $version = 'Tesseract OCR (version unavailable)';
        $out     = @shell_exec(escapeshellarg($bin) . ' --version 2>&1');
        if (is_string($out) && preg_match('/tesseract\s+v?([\d.]+)/i', $out, $m)) {
            $version = 'Tesseract OCR v' . $m[1];
        }
        return [
            'available' => true,
            'binary'    => $bin,
            'version'   => $version,
            'mode'      => 'tesseract',
        ];
    }

    return [
        'available' => false,
        'binary'    => $bin,
        'version'   => 'Tesseract CLI not found — Heuristic Form Extractor & Manual Fallback Active (FR-27)',
        'mode'      => 'fallback',
    ];
}

/**
 * Run Tesseract against an image and return both the reconstructed text and
 * word-level confidence data from the TSV output.
 *
 * TSV is used instead of plain text because it is the only way to obtain real
 * per-word confidence values, which BR-10 requires in order to flag uncertain
 * fields for human review.
 *
 * @return array{ok: bool, text: string, lines: array<int, array{text: string, conf: float}>, error: string}
 */
function runTesseractOnImage(string $imagePath, ?string $binary = null): array
{
    $fail = static fn (string $msg): array => [
        'ok' => false, 'text' => '', 'lines' => [], 'error' => $msg,
    ];

    if ($imagePath === '' || !is_file($imagePath)) {
        return $fail('Scan file not found on disk.');
    }

    $ext = strtolower(pathinfo($imagePath, PATHINFO_EXTENSION));
    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'bmp', 'tif', 'tiff'], true)) {
        return $fail("Tesseract requires a raster image; '.{$ext}' cannot be OCR-read directly.");
    }

    $bin = $binary ?: resolveTesseractBinary();
    if (!is_file($bin)) {
        return $fail('Tesseract binary not found.');
    }

    $cmd = escapeshellarg($bin)
         . ' ' . escapeshellarg($imagePath)
         . ' stdout --psm 6 tsv 2>&1';

    $out = @shell_exec($cmd);
    if (!is_string($out) || trim($out) === '') {
        return $fail('Tesseract produced no output.');
    }

    $rows = preg_split('/\r?\n/', trim($out));
    if (!is_array($rows) || count($rows) < 2) {
        return $fail('Tesseract TSV output was empty.');
    }

    $header = str_getcsv(array_shift($rows), "\t");
    if (!in_array('text', $header, true) || !in_array('conf', $header, true)) {
        return $fail('Tesseract did not return TSV (check binary integrity).');
    }
    $idx     = array_flip($header);
    $lineMap = [];
    $textLines = [];

    foreach ($rows as $row) {
        if (trim($row) === '') {
            continue;
        }
        $cols = str_getcsv($row, "\t");
        if (count($cols) < count($header)) {
            continue;
        }
        // level 5 = word
        if ((int) ($cols[$idx['level']] ?? 0) !== 5) {
            continue;
        }
        $word = trim((string) ($cols[$idx['text']] ?? ''));
        if ($word === '') {
            continue;
        }
        $conf = (float) ($cols[$idx['conf']] ?? -1);
        if ($conf < 0) {
            continue;
        }
        $key = ($cols[$idx['block_num']] ?? '0') . '-' . ($cols[$idx['par_num']] ?? '0') . '-' . ($cols[$idx['line_num']] ?? '0');
        $lineMap[$key][] = ['text' => $word, 'conf' => $conf];
    }

    $lines = [];
    foreach ($lineMap as $words) {
        $text = trim(implode(' ', array_column($words, 'text')));
        if ($text === '') {
            continue;
        }
        $confs  = array_column($words, 'conf');
        $lines[] = [
            'text'  => $text,
            'conf'  => round(array_sum($confs) / max(1, count($confs)), 1),
            'words' => $words,
        ];
        $textLines[] = $text;
    }

    if (empty($lines)) {
        return $fail('No readable text detected in the scan.');
    }

    return [
        'ok'    => true,
        'text'  => implode("\n", $textLines),
        'lines' => $lines,
        'error' => '',
    ];
}

/**
 * Lists OCR ingestion drafts (`D12 ocr_ingestion_drafts`) with linked document and patient info.
 *
 * @param string $statusFilter Optional ('Draft', 'Verified', 'Rejected', or '')
 * @return array<int, array<string, mixed>>
 */
function listOcrDrafts(string $statusFilter = ''): array
{
    global $pdo;

    $where  = ['1=1'];
    $params = [];

    if ($statusFilter !== '' && in_array($statusFilter, OCR_STATUSES, true)) {
        $where[]  = 'od.status = ?';
        $params[] = $statusFilter;
    }

    $sql = 'SELECT od.*,
                   od.verification_date AS verified_at,
                   d.patient_id, d.document_type, d.document_type AS program_type,
                   d.title AS document_title, d.file_path, d.status AS document_status,
                   d.cabinet_location, d.box_location, d.folder_location,
                   p.upi, p.upi AS patient_code, p.first_name, p.middle_name, p.last_name,
                   p.purok_address, p.sex, p.birthdate,
                   u1.full_name AS uploaded_by_name,
                   u2.full_name AS verified_by_name
            FROM ocr_ingestion_drafts od
            INNER JOIN documents d ON d.document_id = od.document_id
            INNER JOIN patients p ON p.patient_id = d.patient_id
            LEFT JOIN users u1 ON u1.user_id = od.uploaded_by
            LEFT JOIN users u2 ON u2.user_id = od.verified_by
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY FIELD(od.status, "Draft", "Verified", "Rejected"), od.created_at DESC, od.draft_id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $flagged = json_decode((string)($row['flagged_fields'] ?? '[]'), true);
        $scores  = json_decode((string)($row['confidence_scores'] ?? '{}'), true);
        $row['flagged_fields_arr']    = is_array($flagged) ? $flagged : [];
        $row['confidence_scores_arr'] = is_array($scores) ? $scores : [];
    }
    unset($row);

    return $rows;
}

/**
 * Fetches a single OCR ingestion draft by ID.
 *
 * @param int $draftId
 * @return array<string, mixed>|null
 */
function getOcrDraftById(int $draftId): ?array
{
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT od.*,
                od.verification_date AS verified_at,
                d.patient_id, d.document_type, d.document_type AS program_type,
                d.title AS document_title, d.file_path, d.status AS document_status,
                d.cabinet_location, d.box_location, d.folder_location,
                p.upi, p.upi AS patient_code, p.first_name, p.middle_name, p.last_name,
                p.purok_address, p.sex, p.birthdate,
                u1.full_name AS uploaded_by_name,
                u2.full_name AS verified_by_name
         FROM ocr_ingestion_drafts od
         INNER JOIN documents d ON d.document_id = od.document_id
         INNER JOIN patients p ON p.patient_id = d.patient_id
         LEFT JOIN users u1 ON u1.user_id = od.uploaded_by
         LEFT JOIN users u2 ON u2.user_id = od.verified_by
         WHERE od.draft_id = ?
         LIMIT 1'
    );
    $stmt->execute([$draftId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $flagged = json_decode((string)($row['flagged_fields'] ?? '[]'), true);
    $scores  = json_decode((string)($row['confidence_scores'] ?? '{}'), true);
    $row['flagged_fields_arr']    = is_array($flagged) ? $flagged : [];
    $row['confidence_scores_arr'] = is_array($scores) ? $scores : [];

    return $row;
}

/**
 * Canonical label aliases for the fields printed on the 6 DOH standby forms.
 * Keyed by schema field key; values are normalised label variants as they appear
 * on the paper forms. Used to map OCR lines onto structured fields (FR-25).
 *
 * @return array<string, array<int, string>>
 */
function getOcrLabelAliases(): array
{
    return [
        // --- shared identity / encounter ---
        'patient_name'        => ['patient name', 'client name', 'child name', 'name of patient'],
        'upi'                 => ['upi', 'patient upi', 'patient code', 'unique patient identifier'],
        'visit_date'          => ['visit date', 'date of visit', 'service date', 'date of consultation', 'date administered', 'date of request'],
        'next_visit_date'     => ['next visit date', 'next visit', 'date of follow up visit', 'follow up date', 'follow up'],
        'treatment_given'     => ['treatment given', 'health services given', 'services given', 'action given', 'treatment'],
        'guardian_name'       => ['accompanying mother guardian', 'accompanying guardian', 'guardian name'],

        // --- Family Planning (FP Form 1) ---
        'fp_client_id'        => ['fp client id', 'client id'],
        'philhealth_no'       => ['philhealth number', 'philhealth no'],
        'nhts_4ps'            => ['nhts pantawid 4ps', 'nhts 4ps', 'nhts', '4ps'],
        'client_type'         => ['type of client', 'client type'],
        'method_accepted'     => ['method accepted', 'method used'],
        'spouse_name'         => ['spouse name', 'spouse'],
        'living_children'     => ['number of living children', 'no of living children', 'living children'],

        // --- Maternal / Prenatal (HBMR, Maternal Record) ---
        'lmp_date'            => ['last menstrual period', 'lmp date', 'lmp'],
        'edc_date'            => ['estimated date of confinement', 'edc date', 'edc'],
        'gravida_para'        => ['obstetrical score', 'gravida para', 'g p score', 'g p'],
        'trimester'           => ['trimester'],
        'aog_weeks'           => ['age of gestation', 'aog weeks', 'aog'],
        'fundal_height'       => ['fundal height'],
        'fetal_heart_tone'    => ['fetal heart tone', 'fetal heart rate'],
        'tetanus_toxoid'      => ['tetanus toxoid', 'tt dose'],
        'iron_folate'         => ['iron folate supplementation', 'iron folic acid', 'iron folate'],
        'danger_signs'        => ['hbrm danger signs referral flag', 'danger signs'],
        'husband_name'        => ['husband name', 'husbands name', 'partner name'],
        'birth_plan'          => ['planned place of delivery', 'birth plan'],

        // --- shared vitals ---
        'bp'                  => ['blood pressure', 'bp'],
        'weight_kg'           => ['weight kg', 'weight'],
        'height_cm'           => ['height cm', 'height'],
        'temp_c'              => ['axillary temperature', 'temperature', 'temp'],
        'pulse_rate'          => ['pulse rate', 'pulse'],
        'resp_rate'           => ['respiratory rate', 'resp rate'],

        // --- Child Health / ECCD ---
        'eccd_child_no'       => ['childs eccd no', 'eccd child no', 'eccd no'],
        'family_no'           => ['family serial no', 'family no'],
        'mother_name'         => ['mothers name', 'mother name'],
        'father_name'         => ['fathers name', 'father name'],
        'birth_weight_kg'     => ['birth weight kg', 'birth weight'],
        'birth_length_cm'     => ['birth length cm', 'birth length'],
        'birth_attendant'     => ['birth attendant'],
        'current_weight'      => ['current weight kg', 'current weight'],
        'current_height'      => ['current length height', 'current height'],
        'whz_status'          => ['who weight for height z score', 'weight for height', 'whz'],
        'vitamin_a_deworm'    => ['vit a deworming given', 'vitamin a deworming', 'vitamin a', 'deworming'],

        // --- Immunization ---
        'vaccine_name'        => ['vaccine antigen administered', 'vaccine name', 'antigen', 'vaccine'],
        'dose_number'         => ['dose column', 'dose number', 'dose'],
        'route_site'          => ['route injection site', 'route site', 'route'],
        'vaccine_lot_no'      => ['vaccine lot batch no', 'vaccine lot no', 'lot batch no', 'lot no'],
        'aefi_status'         => ['aefi post vaccination observation', 'aefi status', 'aefi'],

        // --- Laboratory Referral (NTP Form 2a) ---
        'collection_facility' => ['requesting facility', 'collection facility'],
        'exam_reason'         => ['reason for examination', 'reason for exam', 'exam reason'],
        'treatment_history'   => ['history of treatment', 'treatment history'],
        'registration_group'  => ['registration group'],
        'tb_case_no'          => ['tb case lab serial no', 'tb case no', 'lab serial no', 'case no'],
        'specimen_type'       => ['specimen type', 'specimen'],
        'specimen_1_date'     => ['specimen 1 collection date', 'specimen 1 date'],
        'specimen_2_date'     => ['specimen 2 collection date', 'specimen 2 date'],
        'test_requested'      => ['test requested'],
        'lab_result_summary'  => ['laboratory result', 'lab result', 'reading', 'result'],

        // --- General Consultation ---
        'chief_complaint'     => ['chief complaint', 'complaint'],
        'diagnosis_impression'=> ['clinical impression', 'diagnosis impression', 'impression', 'diagnosis'],
        'disposition'         => ['disposition'],
    ];
}

/**
 * Normalise a label fragment for alias comparison (lowercase, punctuation removed).
 */
function normaliseOcrLabel(string $value): string
{
    $v = strtolower(trim($value));
    $v = (string) preg_replace('/[^a-z0-9]+/', ' ', $v);
    return trim((string) preg_replace('/\s+/', ' ', $v));
}

/**
 * Apply a field-specific validator/cleaner so OCR noise (stray digits, missing
 * spaces, punctuation) cannot leak into the clinical record. Returns null when
 * the value does not satisfy the field's expected shape, which causes the field
 * to be omitted and therefore surfaced for manual entry (BR-10, FR-26).
 */
function cleanOcrFieldValue(string $fieldKey, string $rawValue): ?string
{
    $v = trim($rawValue);
    $v = (string) preg_replace('/^[:\-\s|]+/', '', $v);
    $v = trim((string) preg_replace('/\s+/', ' ', $v));
    if ($v === '') {
        return null;
    }

    switch ($fieldKey) {
        case 'upi':
            return preg_match('/TUB\s*-?\s*\d{4}\s*-?\s*\d{5}/i', $v, $m)
                ? strtoupper((string) preg_replace('/\s+/', '', $m[0]))
                : null;

        case 'bp':
            return preg_match('/(\d{2,3})\s*\/\s*(\d{2,3})/', $v, $m)
                ? $m[1] . '/' . $m[2]
                : null;

        case 'weight_kg':
        case 'current_weight':
        case 'birth_weight_kg':
            return preg_match('/(\d{1,3}(?:\.\d+)?)/', $v, $m) ? $m[1] . ' kg' : null;

        case 'height_cm':
        case 'current_height':
        case 'birth_length_cm':
            return preg_match('/(\d{1,3}(?:\.\d+)?)/', $v, $m) ? $m[1] . ' cm' : null;

        case 'fundal_height':
            return preg_match('/(\d{1,2}(?:\.\d+)?)/', $v, $m) ? $m[1] . ' cm' : null;

        case 'fetal_heart_tone':
            return preg_match('/(\d{2,3})/', $v, $m) ? $m[1] . ' bpm' : null;

        case 'aog_weeks':
            return preg_match('/(\d{1,2})/', $v, $m) ? $m[1] . ' weeks' : null;

        case 'temp_c':
            return preg_match('/(\d{2}(?:\.\d)?)/', $v, $m) ? $m[1] . ' °C' : null;

        case 'pulse_rate':
        case 'resp_rate':
        case 'living_children':
            return preg_match('/(\d{1,3})/', $v, $m) ? $m[1] : null;

        case 'lmp_date':
        case 'edc_date':
        case 'visit_date':
        case 'next_visit_date':
        case 'specimen_1_date':
        case 'specimen_2_date':
            return preg_match('/(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $v, $m)
                ? sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3])
                : null;

        case 'tetanus_toxoid':
            if (preg_match('/TT\s*([1-5])/i', $v, $m)) {
                return 'TT' . $m[1];
            }
            return preg_match('/\b(none|FIM)\b/i', $v, $m) ? strtoupper($m[1]) : null;

        case 'dose_number':
            if (preg_match('/(\d)\s*(st|nd|rd|th)\s*dose/i', $v, $m)) {
                return $m[1] . strtolower($m[2]) . ' Dose';
            }
            if (preg_match('/booster\s*(\d)/i', $v, $m)) {
                return 'Booster ' . $m[1];
            }
            return $v;

        default:
            // Generic: strip trailing separators and collapse whitespace
            return trim($v, " \t\n\r\0\x0B.-|");
    }
}

/**
 * Map OCR text lines onto structured program fields using label aliases.
 * Confidence per field is the mean confidence of the words on that OCR line,
 * which is the real engine confidence rather than a fabricated score (BR-10).
 *
 * @param array<int, array{text: string, conf: float}> $lines
 * @return array{fields: array<string, string>, scores: array<string, int>}
 */
function parseOcrFields(array $lines, string $programType): array
{
    $aliases = getOcrLabelAliases();

    // Reverse lookup: normalised alias -> field key
    $lookup = [];
    foreach ($aliases as $fieldKey => $variants) {
        foreach ($variants as $variant) {
            $lookup[normaliseOcrLabel($variant)] = $fieldKey;
        }
    }
    // Longest alias first so "birth weight" wins over "weight"
    $aliasKeys = array_keys($lookup);
    usort($aliasKeys, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

    $schemas      = getProgramFormSchemas();
    $schemaFields = $schemas[$programType]['fields'] ?? [];

    // Canonical display labels for shared fields so OCR and the identity
    // fallback cannot produce duplicate keys (e.g. "Upi" vs "UPI").
    $labelOverrides = [
        'upi'             => 'UPI',
        'patient_name'    => 'Patient Name',
        'visit_date'      => 'Visit Date',
        'next_visit_date' => 'Next Visit Date',
        'treatment_given' => 'Treatment Given',
        'guardian_name'   => 'Guardian Name',
    ];

    $fields = [];
    $scores = [];

    foreach ($lines as $line) {
        $text = trim((string) ($line['text'] ?? ''));
        $conf = (float) ($line['conf'] ?? 0);
        if ($text === '') {
            continue;
        }

        $labelPart = null;
        $valuePart = null;

        if (str_contains($text, ':')) {
            [$labelPart, $valuePart] = explode(':', $text, 2);
        } else {
            // No colon on the line: match a known alias at the start of the text,
            // preserving the original value characters (slashes, dots, spaces).
            foreach ($aliasKeys as $alias) {
                $pattern = '/^' . str_replace(' ', '\s*', preg_quote($alias, '/')) . '\b[\s.\-|]*(.*)$/i';
                if (preg_match($pattern, $text, $m)) {
                    $labelPart = $alias;
                    $valuePart = $m[1];
                    break;
                }
            }
        }

        if ($labelPart === null || $valuePart === null) {
            continue;
        }

        $normLabel = normaliseOcrLabel($labelPart);
        $fieldKey  = $lookup[$normLabel] ?? null;

        // Tolerate trailing noise in the label (e.g. "BP 2 :")
        if ($fieldKey === null) {
            foreach ($aliasKeys as $alias) {
                if ($normLabel === $alias || str_starts_with($normLabel, $alias . ' ')) {
                    $fieldKey = $lookup[$alias];
                    break;
                }
            }
        }
        if ($fieldKey === null) {
            continue;
        }

        $clean = cleanOcrFieldValue($fieldKey, (string) $valuePart);
        if ($clean === null || $clean === '') {
            continue;
        }

        $label = $labelOverrides[$fieldKey]
            ?? ($schemaFields[$fieldKey]['label'] ?? ucwords(str_replace('_', ' ', $fieldKey)));
        $fields[$label] = $clean;
        $scores[$label] = (int) round(ocrValueConfidence($line, $conf));
    }

    return ['fields' => $fields, 'scores' => $scores];
}

/**
 * Derive a field's confidence from the OCR words that actually carry its value,
 * not from every token on the line. Without this, stray noise in the label area
 * (for example the "2" Tesseract sometimes emits for a colon) would drag an
 * otherwise clean reading below the review threshold.
 *
 * @param array{text?: string, conf?: float, words?: array<int, array{text: string, conf: float}>} $line
 */
function ocrValueConfidence(array $line, float $lineConf): float
{
    $words = $line['words'] ?? [];
    if (empty($words)) {
        return $lineConf;
    }

    // Skip label tokens: everything after the colon (or after the first word).
    $colonAt = null;
    foreach ($words as $i => $w) {
        if (str_contains((string) $w['text'], ':')) {
            $colonAt = $i;
            break;
        }
    }

    $valueWords = $colonAt !== null
        ? array_slice($words, $colonAt + 1)
        : array_slice($words, 1);

    // Drop punctuation-only and single-character tokens (typical OCR noise)
    $valueWords = array_values(array_filter($valueWords, static function (array $w): bool {
        $t = (string) preg_replace('/[^0-9A-Za-z]/', '', (string) $w['text']);
        return strlen($t) >= 2;
    }));

    if (empty($valueWords)) {
        return $lineConf;
    }

    $confs = array_column($valueWords, 'conf');
    return round(array_sum($confs) / count($confs), 1);
}

/**
 * Fallback content used ONLY when Tesseract cannot run (FR-27). Every value is
 * clearly marked as unverified template text so it can never be mistaken for
 * something read off the scan.
 *
 * @return array{fields: array<string, string>, scores: array<string, int>, flagged: array<int, string>}
 */
function getOcrFallbackTemplate(
    string $programType,
    string $patientName,
    string $patientCode,
    string $visitDate
): array {
    $templates = [
        'Maternal/Prenatal' => [
            'LMP'                => '2026-03-10',
            'EDC'                => '2026-12-15',
            'G/P Score'          => 'G2 P1 (1-0-0-1)',
            'AOG'                => '24 weeks',
            'Blood Pressure'     => '110/70',
            'Weight'             => '57.5 kg',
            'Fundal Height'      => '23 cm',
            'Fetal Heart Tone'   => '142 bpm',
            'Tetanus Toxoid'     => 'TT2',
        ],
        'Family Planning' => [
            'Client Type'        => 'Current User - Continuing',
            'Method Accepted'    => 'Injectable (DMPA 3-Month)',
            'Blood Pressure'     => '118/76',
            'Weight'             => '53.0 kg',
            'LMP'                => '2026-08-01',
        ],
        'Child Health-ECCD' => [
            'Birth Weight'       => '3.1 kg',
            'Current Weight'     => '11.2 kg',
            'Current Height'     => '84.0 cm',
            'WHZ'                => 'Normal (Median / 0 SD)',
        ],
        'Immunization' => [
            'Vaccine'            => 'Pentavalent (DPT-HepB-Hib)',
            'Dose'               => '2nd Dose',
            'Route Site'         => 'IM - Left Vastus Lateralis (Thigh)',
            'Weight'             => '6.4 kg',
            'Temperature'        => '36.5 °C',
            'Lot No'             => 'NIP-2026-PENTA02',
        ],
        'Laboratory Referral' => [
            'Reason for Exam'    => 'Diagnosis',
            'Treatment History'  => 'New',
            'Specimen'           => 'Sputum (Spot & Early Morning)',
            'Test Requested'     => 'Xpert MTB/RIF & DSSM',
        ],
        'General Consultation' => [
            'Blood Pressure'     => '125/80',
            'Temperature'        => '37.4 °C',
            'Pulse Rate'         => '82',
            'Resp Rate'          => '18',
            'Weight'             => '61.0 kg',
            'Chief Complaint'    => 'Cough and sore throat for 2 days',
        ],
    ];

    $base = $templates[$programType] ?? $templates['General Consultation'];

    $fields = [
        'Patient Name' => $patientName,
        'UPI'          => $patientCode,
        'Visit Date'   => $visitDate,
    ];
    $scores = [
        'Patient Name' => 100,
        'UPI'          => 100,
        'Visit Date'   => 100,
    ];

    // Template values carry no real confidence: mark them all as low so the
    // reviewer is forced to confirm each one (BR-10).
    foreach ($base as $label => $value) {
        $fields[$label] = $value;
        $scores[$label] = 0;
    }

    return ['fields' => $fields, 'scores' => $scores, 'flagged' => array_keys($base)];
}

/**
 * Produce a Human-in-the-Loop OCR draft for one of the 6 clinic form types.
 *
 * When Tesseract is available and a raster scan is attached, the structured
 * fields are genuinely read from the image and carry real per-line confidence
 * from Tesseract's TSV output. When it cannot run, a clearly-labelled fallback
 * template is produced and every value is flagged for manual verification
 * (FR-25, FR-26, FR-27, BR-10).
 *
 * @return array{raw_output: string, flagged_fields: array<int, string>, confidence_scores: array<string, int>, mode: string}
 */
function extractOcrDraftData(
    string $programType,
    string $patientName,
    string $patientCode,
    string $visitDate,
    string $optionalImagePath = '',
    string $customRawHint = ''
): array {
    $engine  = getOcrEngineStatus();
    $lines   = [];
    $rawText = '';
    $mode    = 'fallback';
    $note    = '';

    if (!$engine['available']) {
        $note = (string) $engine['version'];
    } elseif ($optionalImagePath === '') {
        $note = 'No raster scan attached to this document';
    } else {
        $ocr = runTesseractOnImage($optionalImagePath, $engine['binary']);
        if ($ocr['ok']) {
            $lines   = $ocr['lines'];
            $rawText = $ocr['text'];
            $mode    = 'tesseract';
        } else {
            $note = $ocr['error'];
        }
    }

    if ($mode === 'tesseract') {
        $parsed = parseOcrFields($lines, $programType);
        $fields = $parsed['fields'];
        $scores = $parsed['scores'];

        // Identity fields come from the verified patient record when the scan
        // did not yield them; they are not OCR-derived so they score 100.
        $identity = [
            'Patient Name' => $patientName,
            'UPI'          => $patientCode,
            'Visit Date'   => $visitDate,
        ];
        foreach ($identity as $label => $value) {
            if (!isset($fields[$label]) && $value !== '') {
                $fields[$label] = $value;
                $scores[$label] = 100;
            }
        }

        // Cross-validate the scanned UPI against the Master Patient Index: an exact
        // match is a stronger guarantee than the engine's own token confidence.
        if (isset($fields['UPI'], $scores['UPI'])
            && $patientCode !== ''
            && strcasecmp($fields['UPI'], $patientCode) === 0) {
            $scores['UPI'] = 100;
        }

        $flagged = [];
        foreach ($scores as $label => $score) {
            if ($score < OCR_LOW_CONFIDENCE_THRESHOLD) {
                $flagged[] = $label;
            }
        }

        $avgConf = round(array_sum(array_column($lines, 'conf')) / max(1, count($lines)), 1);

        $out   = [];
        $out[] = '[OCR ENGINE: ' . $engine['version'] . ' | MODE: REAL EXTRACTION FROM SCAN | AVG CONFIDENCE: ' . $avgConf . '%]';

        if (empty($fields)) {
            $out[] = '(No structured fields could be matched on this scan — please encode manually per FR-27.)';
        } else {
            foreach ($fields as $label => $value) {
                $out[] = $label . ': ' . $value;
            }
        }
    } else {
        $tpl    = getOcrFallbackTemplate($programType, $patientName, $patientCode, $visitDate);
        $fields = $tpl['fields'];
        $scores = $tpl['scores'];
        $flagged = $tpl['flagged'];

        $out   = [];
        $out[] = '[OCR MODE: FALLBACK TEMPLATE — Tesseract was not used: ' . $note . ']';
        $out[] = '[BR-10 WARNING: The values below were NOT read from the scan. Verify every field manually or use manual encoding (FR-27).]';
        foreach ($fields as $label => $value) {
            $out[] = $label . ': ' . $value;
        }
    }

    if (trim($customRawHint) !== '') {
        $out[] = 'Staff Scan Notes: ' . trim($customRawHint);
    }

    // Raw engine text is kept as verification evidence for the reviewer only.
    // processOcrDraftDecision() strips everything from this marker onward before
    // committing to D4, so evidence never pollutes the clinical record.
    $rawSection = '';
    if ($rawText !== '') {
        $rawSection = "\n" . OCR_RAW_TEXT_MARKER . "\n" . $rawText;
    }

    return [
        'raw_output'        => implode("\n", $out) . $rawSection,
        'flagged_fields'    => array_values($flagged),
        'confidence_scores' => $scores,
        'mode'              => $mode,
    ];
}

/**
 * Creates a new `Draft` row in `ocr_ingestion_drafts` (`D12`) from an existing document
 * or newly uploaded scan (FR-25, BR-10).
 *
 * @param array<string, mixed> $post
 * @param array<string, mixed> $files
 * @param int                  $actorUserId
 * @return array{ok: bool, draft_id?: int, error?: string}
 */
function createOcrDraft(array $post, array $files, int $actorUserId): array
{
    global $pdo;

    $documentId  = (int)($post['document_id'] ?? 0);
    $patientId   = (int)($post['patient_id'] ?? 0);
    $programType = trim((string)($post['program_type'] ?? 'General Consultation'));
    $visitDate   = trim((string)($post['visit_date'] ?? date('Y-m-d')));
    $scanNotes   = trim((string)($post['scan_notes'] ?? ''));

    if (!in_array($programType, PROGRAM_TYPES, true)) {
        return ['ok' => false, 'error' => 'Invalid program type selected (BR-02).'];
    }

    $uploadedAbsPath = '';
    $sourceFileName  = 'paper_scan_' . date('Ymd_His') . '.jpg';

    if ($documentId > 0) {
        $docStmt = $pdo->prepare(
            'SELECT d.*, p.first_name, p.last_name, p.upi
             FROM documents d
             INNER JOIN patients p ON p.patient_id = d.patient_id
             WHERE d.document_id = ? LIMIT 1'
        );
        $docStmt->execute([$documentId]);
        $doc = $docStmt->fetch();
        if (!$doc) {
            return ['ok' => false, 'error' => 'Selected document record not found.'];
        }
        // Check if an OCR draft already exists for this document_id (uq_ocr_document_id)
        $chkStmt = $pdo->prepare('SELECT draft_id FROM ocr_ingestion_drafts WHERE document_id = ? LIMIT 1');
        $chkStmt->execute([$documentId]);
        if ($chkStmt->fetchColumn()) {
            return ['ok' => false, 'error' => 'An OCR draft already exists for Document #' . $documentId . '. Please review it in the queue or create a new document scan.'];
        }

        $patientId   = (int)$doc['patient_id'];
        $programType = in_array($doc['document_type'], PROGRAM_TYPES, true) ? (string)$doc['document_type'] : $programType;
        $patientName = $doc['last_name'] . ', ' . $doc['first_name'];
        $patientCode = (string)$doc['upi'];
        if (!empty($doc['file_path'])) {
            $sourceFileName = (string)$doc['file_path'];
            $safePath       = resolveSafeUploadPath($sourceFileName);
            if ($safePath) {
                $uploadedAbsPath = $safePath;
            }
        }
    } else {
        if ($patientId <= 0) {
            return ['ok' => false, 'error' => 'Please select a patient or an existing Pending Digitization document.'];
        }
        $pStmt = $pdo->prepare('SELECT patient_id, upi, first_name, last_name FROM patients WHERE patient_id = ? LIMIT 1');
        $pStmt->execute([$patientId]);
        $pt = $pStmt->fetch();
        if (!$pt) {
            return ['ok' => false, 'error' => 'Patient not found.'];
        }
        $patientName = $pt['last_name'] . ', ' . $pt['first_name'];
        $patientCode = (string)$pt['upi'];

        $storedFileName = null;
        if (!empty($files['scan_file']['name'])) {
            $up = storeSecureUpload($files['scan_file'], 'ocr_' . strtolower($patientCode));
            if (!$up['success']) {
                return ['ok' => false, 'error' => $up['error'] ?? 'File upload failed.'];
            }
            $storedFileName  = $up['filename'];
            $sourceFileName  = $storedFileName;
            $uploadedAbsPath = STORAGE_UPLOADS_PATH . '/' . $storedFileName;
        }

        $docTitle  = $programType . ' Scanned Standby Form (' . $visitDate . ')';
        $docStatus = $storedFileName ? 'Scanned/Attached' : 'Pending Digitization';
        $insDoc    = $pdo->prepare(
            'INSERT INTO documents
                (patient_id, document_type, title, status, cabinet_location, box_location, folder_location,
                 file_path, filed_by, filed_at, digitized_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)'
        );
        $insDoc->execute([
            $patientId,
            $programType,
            $docTitle,
            $docStatus,
            trim((string)($post['cabinet_location'] ?? 'Cabinet A')),
            trim((string)($post['box_location'] ?? 'Box 1')),
            trim((string)($post['folder_location'] ?? 'Folder ' . $patientCode)),
            $storedFileName,
            $actorUserId,
            $storedFileName ? date('Y-m-d H:i:s') : null,
        ]);
        $documentId = (int)$pdo->lastInsertId();
    }

    $extracted = extractOcrDraftData(
        $programType,
        $patientName,
        $patientCode,
        $visitDate,
        $uploadedAbsPath,
        $scanNotes
    );

    $stmt = $pdo->prepare(
        'INSERT INTO ocr_ingestion_drafts
            (document_id, source_file_path, uploaded_by, ocr_raw_output, flagged_fields, confidence_scores, status)
         VALUES (?, ?, ?, ?, ?, ?, "Draft")'
    );
    $stmt->execute([
        $documentId,
        $sourceFileName,
        $actorUserId,
        $extracted['raw_output'],
        json_encode($extracted['flagged_fields'], JSON_UNESCAPED_UNICODE),
        json_encode($extracted['confidence_scores'], JSON_UNESCAPED_UNICODE),
    ]);

    $draftId = (int)$pdo->lastInsertId();

    logAudit(
        $pdo,
        'OCR_DRAFT',
        sprintf(
            'Created HITL OCR draft #%d for %s (%s) with %d low-confidence flagged field(s)',
            $draftId,
            $patientCode,
            $programType,
            count($extracted['flagged_fields'])
        ),
        'ocr_ingestion_drafts',
        $draftId,
        $actorUserId
    );

    return ['ok' => true, 'draft_id' => $draftId];
}

/**
 * Verifies (`status = 'Verified'`) or Rejects (`status = 'Rejected'`) an OCR draft (`FR-26, BR-10`).
 *
 * @param int                  $draftId
 * @param string               $decision       'verify' or 'reject'
 * @param array<string, mixed> $correctedData
 * @param int                  $actorUserId
 * @param string               $actorRole
 * @return array{ok: bool, record_id?: int, error?: string}
 */
function processOcrDraftDecision(
    int $draftId,
    string $decision,
    array $correctedData,
    int $actorUserId,
    string $actorRole
): array {
    global $pdo;

    if (!in_array($actorRole, [ROLE_NURSE, ROLE_BHW], true)) {
        return ['ok' => false, 'error' => 'Only Nurse/Midwife or BHW staff may review or verify OCR drafts (Table 8-6 / BR-10 / BR-11).'];
    }

    $draft = getOcrDraftById($draftId);
    if (!$draft) {
        return ['ok' => false, 'error' => 'OCR draft not found.'];
    }

    if ($draft['status'] !== 'Draft') {
        return ['ok' => false, 'error' => 'This OCR draft has already been ' . $draft['status'] . '.'];
    }

    if ($decision === 'reject') {
        $rejectReason = trim((string)($correctedData['rejection_reason'] ?? 'Rejected during human-in-the-loop review.'));
        $updatedRaw   = (string)$draft['ocr_raw_output'] . "\n[REJECTED NOTE]: " . $rejectReason;

        $stmt = $pdo->prepare(
            'UPDATE ocr_ingestion_drafts
             SET status = "Rejected", ocr_raw_output = ?, verified_by = ?, verification_date = NOW()
             WHERE draft_id = ?'
        );
        $stmt->execute([$updatedRaw, $actorUserId, $draftId]);

        logAudit(
            $pdo,
            'OCR_VERIFY',
            sprintf('Rejected OCR draft #%d for %s (%s): %s', $draftId, $draft['upi'], $draft['program_type'], $rejectReason),
            'ocr_ingestion_drafts',
            $draftId,
            $actorUserId
        );

        return ['ok' => true];
    }

    if ($decision !== 'verify') {
        return ['ok' => false, 'error' => 'Invalid decision parameter.'];
    }

    $visitDate      = trim((string)($correctedData['visit_date'] ?? date('Y-m-d')));
    $editedFindings = trim((string)($correctedData['ocr_raw_output'] ?? $draft['ocr_raw_output']));

    // BR-10: the raw engine text is verification evidence only. Strip it so the
    // committed clinical record contains solely the human-verified field values.
    $markerPos = strpos($editedFindings, OCR_RAW_TEXT_MARKER);
    if ($markerPos !== false) {
        $editedFindings = trim(substr($editedFindings, 0, $markerPos));
    }
    $treatmentGiven = trim((string)($correctedData['treatment_given'] ?? ''));
    $nextVisitDate  = trim((string)($correctedData['next_visit_date'] ?? ''));
    $programType    = trim((string)($correctedData['program_type'] ?? $draft['program_type']));

    if (!in_array($programType, PROGRAM_TYPES, true)) {
        $programType = 'General Consultation';
    }

    $combinedFindings = packFindingsNotes($programType, [], $editedFindings, $treatmentGiven, $nextVisitDate);
    $formRefNo        = sprintf('OCR-%d-%s', $draftId, date('Ymd'));

    $pdo->beginTransaction();
    try {
        // 1. Update D12 ocr_ingestion_drafts to Verified
        $updDraft = $pdo->prepare(
            'UPDATE ocr_ingestion_drafts
             SET ocr_raw_output = ?, status = "Verified", verified_by = ?, verification_date = NOW()
             WHERE draft_id = ?'
        );
        $updDraft->execute([$editedFindings, $actorUserId, $draftId]);

        // 2. Commit verified clinical encounter to D4 service_records (BR-10 & FR-09)
        $clinicalVerifiedBy = ($actorRole === ROLE_NURSE) ? $actorUserId : null;
        $insSr = $pdo->prepare(
            'INSERT INTO service_records
                (patient_id, program_type, form_reference_no, service_date, findings_notes, recorded_by, verified_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insSr->execute([
            (int)$draft['patient_id'],
            $programType,
            $formRefNo,
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $visitDate) ? $visitDate : date('Y-m-d'),
            $combinedFindings,
            $actorUserId,
            $clinicalVerifiedBy,
        ]);
        $newRecordId = (int)$pdo->lastInsertId();

        // 3. Ensure linked D6 document status reflects Scanned/Attached & links to record_id
        $updDoc = $pdo->prepare(
            'UPDATE documents
             SET record_id = COALESCE(record_id, ?),
                 status = "Scanned/Attached",
                 digitized_at = COALESCE(digitized_at, NOW())
             WHERE document_id = ?'
        );
        $updDoc->execute([$newRecordId, (int)$draft['document_id']]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => 'Database error while committing verified OCR draft: ' . $e->getMessage()];
    }

    logAudit(
        $pdo,
        'OCR_VERIFY',
        sprintf(
            'Verified OCR draft #%d (%s) and committed to service_records #%d by %s',
            $draftId,
            $programType,
            $newRecordId,
            $actorRole
        ),
        'ocr_ingestion_drafts',
        $draftId,
        $actorUserId
    );

    return ['ok' => true, 'record_id' => $newRecordId];
}

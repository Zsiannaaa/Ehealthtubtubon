<?php
/**
 * Tubtubon eHealth — Module M3: Service Records & Program-Specific Clinical Forms
 * Phase 8 Sec. 50 (M3) | FR-06, FR-07, FR-08, FR-09 | BR-02, BR-10, BR-11, BR-12
 *
 * Manages clinical encounters across all 6 official Barangay Tubtubon Health Center
 * programs (`Family Planning`, `Maternal/Prenatal`, `Child Health-ECCD`,
 * `Immunization`, `Laboratory Referral`, `General Consultation`) within the strict
 * 13-table schema by storing structured program fields + narrative clinical summary
 * inside `service_records.findings_notes`.
 */

declare(strict_types=1);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/audit_module.php';

/**
 * Returns the field definitions for each of the 6 official clinic forms
 * (matching the photographed standby forms in C:\Users\mzsia\Downloads\forms).
 *
 * @return array<string, array<string, mixed>>
 */
function getProgramFormSchemas(): array
{
    return [
        'Family Planning' => [
            'form_code'  => 'DOH FP Form 1 (ver. 3.0) — Sides A & B',
            'ref_prefix' => 'FP',
            'badge_cls'  => 'bg-purple-subtle text-purple border-purple',
            'icon'       => 'bi-heart-pulse',
            'fields'     => [
                'fp_client_id'       => ['label' => 'FP Client ID', 'type' => 'text', 'placeholder' => 'FP-2026-001'],
                'philhealth_no'      => ['label' => 'PhilHealth No.', 'type' => 'text', 'placeholder' => '12-345678901-2'],
                'nhts_4ps'           => ['label' => 'NHTS / Pantawid 4Ps', 'type' => 'select', 'options' => ['No', 'NHTS', 'Pantawid 4Ps']],
                'client_type'        => ['label' => 'Type of Client', 'type' => 'select', 'options' => ['New Acceptor', 'Current User - Changing Method', 'Current User - Changing Clinic', 'Dropout / Restart']],
                'method_accepted'    => ['label' => 'Method Accepted / Used', 'type' => 'select', 'options' => ['COC (Combined Oral Contraceptive)', 'POP (Progestin-Only Pill)', 'Injectable (DMPA 3-Month)', 'Subdermal Implant', 'IUD (Interval / Postpartum)', 'Condom', 'LAM', 'BTL / NSV Referral', 'Natural FP (SDM/BBT/CMM)']],
                'spouse_name'        => ['label' => 'Spouse Name', 'type' => 'text', 'placeholder' => 'Full name of spouse'],
                'living_children'    => ['label' => 'No. of Living Children', 'type' => 'number', 'placeholder' => '2'],
                'gravida_para'       => ['label' => 'Obstetrical Score (G/P)', 'type' => 'text', 'placeholder' => 'G2 P2 (2-0-0-2)'],
                'lmp_date'           => ['label' => 'Last Menstrual Period (LMP)', 'type' => 'date', 'placeholder' => ''],
                'bp'                 => ['label' => 'Blood Pressure (mmHg)', 'type' => 'text', 'placeholder' => '110/70'],
                'weight_kg'          => ['label' => 'Weight (kg)', 'type' => 'text', 'placeholder' => '54.5'],
                'height_cm'          => ['label' => 'Height (cm)', 'type' => 'text', 'placeholder' => '156'],
                'sti_vaw_screening'  => ['label' => 'STI / VAW Risk Screening', 'type' => 'select', 'options' => ['Negative (No STI/VAW risks reported)', 'STI Risk Flagged - Counseled', 'VAW Risk Flagged - Referred to WCPU/DSWD/VAW Desk']],
                'not_pregnant_check' => ['label' => 'Side B: Reasonably Sure Not Pregnant?', 'type' => 'select', 'options' => ['Yes (Passed 6-point checklist)', 'Pregnancy Test Negative', 'Deferred / Awaiting menses']],
            ],
        ],
        'Maternal/Prenatal' => [
            'form_code'  => 'DOH HBMR & LHU Maternal Record Form',
            'ref_prefix' => 'MAT',
            'badge_cls'  => 'bg-danger-subtle text-danger border-danger',
            'icon'       => 'bi-person-hearts',
            'fields'     => [
                'lmp_date'        => ['label' => 'LMP Date', 'type' => 'date', 'placeholder' => ''],
                'edc_date'        => ['label' => 'EDC Date', 'type' => 'date', 'placeholder' => ''],
                'gravida_para'    => ['label' => 'G / P Score', 'type' => 'text', 'placeholder' => 'G2 P1 (1-0-0-1)'],
                'trimester'       => ['label' => 'Trimester', 'type' => 'select', 'options' => ['1st Trimester (AOG 1-3 mos)', '2nd Trimester (AOG 4-6 mos)', '3rd Trimester (AOG 7-9 mos)', 'Postpartum Follow-up']],
                'aog_weeks'       => ['label' => 'AOG (Weeks)', 'type' => 'text', 'placeholder' => '24 wks'],
                'bp'              => ['label' => 'Blood Pressure (mmHg)', 'type' => 'text', 'placeholder' => '110/70'],
                'weight_kg'       => ['label' => 'Weight (kg)', 'type' => 'text', 'placeholder' => '58.0'],
                'fundal_height'   => ['label' => 'Fundal Height (cm)', 'type' => 'text', 'placeholder' => '24 cm'],
                'fetal_heart_tone'=> ['label' => 'Fetal Heart Tone (bpm)', 'type' => 'text', 'placeholder' => '144 bpm (Cephalic)'],
                'tetanus_toxoid'  => ['label' => 'Tetanus Toxoid Dose', 'type' => 'select', 'options' => ['None today', 'TT1', 'TT2', 'TT3', 'TT4', 'TT5 (FIM)']],
                'iron_folate'     => ['label' => 'Iron/Folate Supplementation', 'type' => 'text', 'placeholder' => '30 tabs Ferrous Sulfate + Folic Acid'],
                'danger_signs'    => ['label' => 'HBMR Danger Signs / Referral Flag', 'type' => 'select', 'options' => ['None (Normal Prenatal Progress)', 'BP >= 140/90 (Refer to RHU/Hospital)', 'Vaginal Bleeding / Fever >= 38C (Refer)', 'Pallor / Edema / UTI Flagged']],
                'husband_name'    => ['label' => "Husband's / Partner's Name", 'type' => 'text', 'placeholder' => 'Partner full name'],
                'birth_plan'      => ['label' => 'Planned Place of Delivery', 'type' => 'select', 'options' => ['Sibulan RHU Birthing Center', 'NOPH / Hospital', 'Private Lying-in Clinic']],
            ],
        ],
        'Child Health-ECCD' => [
            'form_code'  => 'ECCD Card — Child Profile & WHO Growth Monitoring',
            'ref_prefix' => 'ECCD',
            'badge_cls'  => 'bg-info-subtle text-info border-info',
            'icon'       => 'bi-emoji-smile',
            'fields'     => [
                'eccd_child_no'   => ['label' => "Child's ECCD No.", 'type' => 'text', 'placeholder' => 'ECCD-2026-014'],
                'family_no'       => ['label' => 'Family Serial No.', 'type' => 'text', 'placeholder' => 'FAM-TUB-088'],
                'mother_name'     => ['label' => "Mother's Name", 'type' => 'text', 'placeholder' => 'Mother full name'],
                'father_name'     => ['label' => "Father's Name", 'type' => 'text', 'placeholder' => 'Father full name'],
                'birth_weight_kg' => ['label' => 'Birth Weight (kg)', 'type' => 'text', 'placeholder' => '3.1 kg'],
                'birth_length_cm' => ['label' => 'Birth Length (cm)', 'type' => 'text', 'placeholder' => '49 cm'],
                'delivery_place'  => ['label' => 'Place of Delivery', 'type' => 'select', 'options' => ['Hospital', 'RHU / Lying-in', 'Home', 'Others']],
                'birth_attendant' => ['label' => 'Birth Attendant', 'type' => 'select', 'options' => ['Doctor', 'Midwife', 'Nurse', 'Hilot / Others']],
                'current_weight'  => ['label' => 'Current Weight (kg)', 'type' => 'text', 'placeholder' => '11.4 kg'],
                'current_height'  => ['label' => 'Current Length/Height (cm)', 'type' => 'text', 'placeholder' => '84.5 cm'],
                'whz_status'      => ['label' => 'WHO Weight-for-Height Z-Score', 'type' => 'select', 'options' => ['Normal (Median / 0 to +1 SD)', 'Mild Risk (-1 SD)', 'Moderate Wasting / MAM (-2 SD)', 'Severe Wasting / SAM (-3 SD)', 'Overweight (+2 SD)']],
                'vitamin_a_deworm'=> ['label' => 'Vit. A / Deworming Given', 'type' => 'select', 'options' => ['None today', 'Vitamin A 100,000 IU (6-11 mos)', 'Vitamin A 200,000 IU (12-59 mos)', 'Albendazole Deworming', 'Both Vit. A + Deworming']],
            ],
        ],
        'Immunization' => [
            'form_code'  => 'ECCD Essential Health & Nutrition Services — Immunization',
            'ref_prefix' => 'IMM',
            'badge_cls'  => 'bg-success-subtle text-success border-success',
            'icon'       => 'bi-shield-plus',
            'fields'     => [
                'vaccine_name'    => ['label' => 'Vaccine / Antigen Administered', 'type' => 'select', 'options' => ['BCG', 'Hepatitis B (Birth Dose)', 'Pentavalent (DPT-HepB-Hib)', 'OPV (Oral Polio Vaccine)', 'IPV (Inactivated Polio Vaccine)', 'PCV (Pneumococcal Conjugate)', 'MMR / Measles', 'Vitamin A Supplementation', 'Other NIP Vaccine']],
                'dose_number'     => ['label' => 'Dose Column (ECCD 1st–6th)', 'type' => 'select', 'options' => ['1st Dose', '2nd Dose', '3rd Dose', 'Booster 1 (4th)', 'Booster 2 (5th)', '6th Dose / Catch-up']],
                'route_site'      => ['label' => 'Route & Injection Site', 'type' => 'select', 'options' => ['IM - Left Vastus Lateralis (Thigh)', 'IM - Right Vastus Lateralis (Thigh)', 'ID - Right Deltoid (BCG)', 'SC - Left Upper Arm (MMR)', 'Oral Drops (OPV / Vit A)']],
                'vaccine_lot_no'  => ['label' => 'Vaccine Lot / Batch No.', 'type' => 'text', 'placeholder' => 'NIP-2026-BCG04'],
                'weight_kg'       => ['label' => 'Child Weight Today (kg)', 'type' => 'text', 'placeholder' => '6.8 kg'],
                'temp_c'          => ['label' => 'Axillary Temperature (°C)', 'type' => 'text', 'placeholder' => '36.6'],
                'aefi_status'     => ['label' => 'AEFI / Post-Vaccination Observation', 'type' => 'select', 'options' => ['No adverse reaction observed after 15 mins', 'Mild local warmth/fever counseled (Paracetamol advised)', 'AEFI Flagged - Referred to RHU Physician']],
                'guardian_name'   => ['label' => 'Accompanying Mother / Guardian', 'type' => 'text', 'placeholder' => 'Mother / Guardian name'],
            ],
        ],
        'Laboratory Referral' => [
            'form_code'  => 'DOH NTP Form 2a — Laboratory Request & Result Form',
            'ref_prefix' => 'LAB',
            'badge_cls'  => 'bg-warning-subtle text-warning-emphasis border-warning',
            'icon'       => 'bi-eyedropper',
            'fields'     => [
                'collection_facility' => ['label' => 'Requesting Facility', 'type' => 'text', 'placeholder' => 'Barangay Tubtubon BHS -> Sibulan RHU Lab'],
                'exam_reason'         => ['label' => 'Reason for Examination', 'type' => 'select', 'options' => ['Diagnosis', 'Baseline', 'Follow-up (Month 2 / 5 / 6)']],
                'treatment_history'   => ['label' => 'History of Treatment', 'type' => 'select', 'options' => ['New', 'Retreatment']],
                'registration_group'  => ['label' => 'Registration Group', 'type' => 'select', 'options' => ['New', 'Relapse', 'TALF (Treatment After Lost to Follow-up)', 'TAF (Treatment After Failure)', 'PTOU', 'Other']],
                'tb_case_no'          => ['label' => 'TB Case / Lab Serial No.', 'type' => 'text', 'placeholder' => 'NTP-2026-019'],
                'specimen_type'       => ['label' => 'Specimen Type', 'type' => 'select', 'options' => ['Sputum (Spot & Early Morning)', 'Sputum (Single Xpert)', 'Urinalysis / CBC Referral', 'Fasting Blood Sugar (FBS) Referral', 'Other Specimen']],
                'specimen_1_date'     => ['label' => 'Specimen 1 Collection Date', 'type' => 'date', 'placeholder' => ''],
                'specimen_2_date'     => ['label' => 'Specimen 2 Collection Date', 'type' => 'date', 'placeholder' => ''],
                'test_requested'      => ['label' => 'Test Requested', 'type' => 'select', 'options' => ['Xpert MTB/RIF', 'DSSM (Direct Sputum Smear Microscopy)', 'Both DSSM + Xpert MTB/RIF', 'Culture / DST / LPA', 'RHU Basic Lab (CBC/UA/FBS)']],
                'lab_result_summary'  => ['label' => 'Bottom Portion: Laboratory Result / Reading', 'type' => 'select', 'options' => ['Pending RHU MedTech Release', 'DSSM Negative (0 AFB)', 'DSSM Positive (+1 / +2 / +3)', 'MTB NOT Detected (Xpert)', 'MTB Detected, Rifampicin Resistance NOT Detected', 'MTB Detected, Rifampicin Resistance Detected', 'Completed - Non-TB Lab Result Attached']],
            ],
        ],
        'General Consultation' => [
            'form_code'  => 'BHS Outpatient Clinical Consultation Record',
            'ref_prefix' => 'GC',
            'badge_cls'  => 'bg-primary-subtle text-primary border-primary',
            'icon'       => 'bi-clipboard2-pulse',
            'fields'     => [
                'bp'                   => ['label' => 'Blood Pressure (mmHg)', 'type' => 'text', 'placeholder' => '120/80'],
                'temp_c'               => ['label' => 'Temperature (°C)', 'type' => 'text', 'placeholder' => '36.8'],
                'pulse_rate'           => ['label' => 'Pulse Rate (bpm)', 'type' => 'text', 'placeholder' => '78'],
                'resp_rate'            => ['label' => 'Respiratory Rate (cpm)', 'type' => 'text', 'placeholder' => '18'],
                'weight_kg'            => ['label' => 'Weight (kg)', 'type' => 'text', 'placeholder' => '62.0'],
                'chief_complaint'      => ['label' => 'Chief Complaint', 'type' => 'text', 'placeholder' => 'e.g., Cough and mild fever for 3 days'],
                'diagnosis_impression' => ['label' => 'Clinical Impression / Diagnosis', 'type' => 'text', 'placeholder' => 'e.g., Acute Upper Respiratory Tract Infection (URTI)'],
                'disposition'          => ['label' => 'Disposition', 'type' => 'select', 'options' => ['Treated & Sent Home', 'Advised Follow-up at BHS', 'Referred to Sibulan RHU Physician', 'Emergency Referral to Hospital']],
            ],
        ],
    ];
}

/**
 * Serializes structured program fields + narrative clinical notes + treatment + next visit
 * into `service_records.findings_notes` (keeping our schema strictly at 13 tables).
 *
 * @param string               $programType
 * @param array<string, mixed> $structuredFields
 * @param string               $narrativeNotes
 * @param string               $treatmentGiven
 * @param string               $nextVisitDate
 * @return string
 */
function packFindingsNotes(
    string $programType,
    array $structuredFields,
    string $narrativeNotes,
    string $treatmentGiven = '',
    string $nextVisitDate = ''
): string {
    $schemas = getProgramFormSchemas();
    $schema  = $schemas[$programType]['fields'] ?? [];

    $cleanStruct  = [];
    $summaryParts = [];

    foreach ($schema as $key => $meta) {
        $val = trim((string)($structuredFields[$key] ?? ''));
        if ($val !== '') {
            $cleanStruct[$key] = $val;
            $summaryParts[]    = $meta['label'] . ': ' . $val;
        }
    }

    $narrativeNotes = trim($narrativeNotes);
    $treatmentGiven = trim($treatmentGiven);
    $nextVisitDate  = trim($nextVisitDate);

    $lines = [];
    if (!empty($summaryParts)) {
        $lines[] = implode(' | ', $summaryParts);
    }
    if ($narrativeNotes !== '') {
        $lines[] = 'Notes: ' . $narrativeNotes;
    }
    if ($treatmentGiven !== '') {
        $lines[] = 'Treatment/Action: ' . $treatmentGiven;
    }
    if ($nextVisitDate !== '') {
        $lines[] = 'Next Visit: ' . $nextVisitDate;
    }

    $combined = !empty($lines) ? implode("\n", $lines) : 'Clinical consultation recorded.';

    $payload = [
        'program_type'    => $programType,
        'fields'          => $cleanStruct,
        'narrative'       => $narrativeNotes,
        'treatment_given' => $treatmentGiven,
        'next_visit_date' => $nextVisitDate,
    ];
    $combined .= "\n[FORM_DATA_JSON:" . json_encode($payload, JSON_UNESCAPED_UNICODE) . ']';

    return $combined;
}

/**
 * Parses `service_records.findings_notes` into display summary, narrative notes,
 * treatment given, next visit date, and structured key-value fields.
 *
 * @param string $rawFindingsNotes
 * @return array{summary: string, narrative: string, treatment_given: string, next_visit_date: string, fields: array<string, string>}
 */
function unpackFindingsNotes(string $rawFindingsNotes): array
{
    $fields         = [];
    $narrative      = '';
    $treatmentGiven = '';
    $nextVisitDate  = '';
    $cleanText      = $rawFindingsNotes;

    if (preg_match('/\[FORM_DATA_JSON:(\{.*\})\]/s', $rawFindingsNotes, $m)) {
        $cleanText = trim(str_replace($m[0], '', $rawFindingsNotes));
        $decoded   = json_decode($m[1], true);
        if (is_array($decoded)) {
            $fields         = is_array($decoded['fields'] ?? null) ? $decoded['fields'] : [];
            $narrative      = (string)($decoded['narrative'] ?? '');
            $treatmentGiven = (string)($decoded['treatment_given'] ?? '');
            $nextVisitDate  = (string)($decoded['next_visit_date'] ?? '');
        }
    } else {
        $narrative = trim($rawFindingsNotes);
    }

    return [
        'summary'         => $cleanText !== '' ? $cleanText : $rawFindingsNotes,
        'narrative'       => $narrative,
        'treatment_given' => $treatmentGiven,
        'next_visit_date' => $nextVisitDate,
        'fields'          => $fields,
    ];
}

/**
 * Lists service records with optional filters (`program_type`, `patient_id`, `search`, `unverified_only`).
 *
 * @param array<string, mixed> $filters
 * @param int                  $limit
 * @return array<int, array<string, mixed>>
 */
function listServiceRecords(array $filters = [], int $limit = 150): array
{
    global $pdo;

    $where  = ['1=1'];
    $params = [];

    if (!empty($filters['patient_id'])) {
        $where[]  = 'sr.patient_id = ?';
        $params[] = (int)$filters['patient_id'];
    }

    if (!empty($filters['program_type']) && in_array($filters['program_type'], PROGRAM_TYPES, true)) {
        $where[]  = 'sr.program_type = ?';
        $params[] = $filters['program_type'];
    }

    if (!empty($filters['unverified_only'])) {
        $where[] = 'sr.verified_by IS NULL';
    }

    if (!empty($filters['date_from'])) {
        $where[]  = 'sr.service_date >= ?';
        $params[] = $filters['date_from'];
    }

    if (!empty($filters['date_to'])) {
        $where[]  = 'sr.service_date <= ?';
        $params[] = $filters['date_to'];
    }

    if (!empty($filters['search'])) {
        $q        = '%' . trim((string)$filters['search']) . '%';
        $where[]  = '(p.upi LIKE ? OR p.last_name LIKE ? OR p.first_name LIKE ? OR sr.findings_notes LIKE ? OR sr.form_reference_no LIKE ?)';
        $params[] = $q;
        $params[] = $q;
        $params[] = $q;
        $params[] = $q;
        $params[] = $q;
    }

    $sql = 'SELECT sr.record_id, sr.patient_id, sr.program_type, sr.form_reference_no,
                   sr.service_date, sr.service_date AS visit_date,
                   sr.findings_notes, sr.recorded_by, sr.verified_by, sr.created_at,
                   p.upi, p.upi AS patient_code, p.first_name, p.middle_name, p.last_name,
                   p.sex, p.birthdate, p.birthdate AS birth_date,
                   p.purok_address, p.purok_address AS purok, p.contact_no,
                   u1.full_name AS encoded_by_name, r1.role_name AS encoded_by_role,
                   u2.full_name AS verified_by_name
            FROM service_records sr
            INNER JOIN patients p ON p.patient_id = sr.patient_id
            LEFT JOIN users u1 ON u1.user_id = sr.recorded_by
            LEFT JOIN roles r1 ON r1.role_id = u1.role_id
            LEFT JOIN users u2 ON u2.user_id = sr.verified_by
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY sr.service_date DESC, sr.record_id DESC
            LIMIT ' . max(1, min(500, $limit));

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $unpacked               = unpackFindingsNotes((string)$row['findings_notes']);
        $row['display_summary'] = $unpacked['summary'];
        $row['narrative_notes'] = $unpacked['narrative'];
        $row['treatment_given'] = $unpacked['treatment_given'];
        $row['next_visit_date'] = $unpacked['next_visit_date'];
        $row['form_fields']     = $unpacked['fields'];
    }
    unset($row);

    return $rows;
}

/**
 * Fetches a single service record by ID with patient and staff metadata.
 *
 * @param int $recordId
 * @return array<string, mixed>|null
 */
function getServiceRecordById(int $recordId): ?array
{
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT sr.*, sr.service_date AS visit_date,
                p.upi, p.upi AS patient_code, p.first_name, p.middle_name, p.last_name,
                p.sex, p.birthdate, p.birthdate AS birth_date,
                p.purok_address, p.purok_address AS purok, p.contact_no,
                u1.full_name AS encoded_by_name, r1.role_name AS encoded_by_role,
                u2.full_name AS verified_by_name
         FROM service_records sr
         INNER JOIN patients p ON p.patient_id = sr.patient_id
         LEFT JOIN users u1 ON u1.user_id = sr.recorded_by
         LEFT JOIN roles r1 ON r1.role_id = u1.role_id
         LEFT JOIN users u2 ON u2.user_id = sr.verified_by
         WHERE sr.record_id = ?
         LIMIT 1'
    );
    $stmt->execute([$recordId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $unpacked               = unpackFindingsNotes((string)$row['findings_notes']);
    $row['display_summary'] = $unpacked['summary'];
    $row['narrative_notes'] = $unpacked['narrative'];
    $row['treatment_given'] = $unpacked['treatment_given'];
    $row['next_visit_date'] = $unpacked['next_visit_date'];
    $row['form_fields']     = $unpacked['fields'];

    return $row;
}

/**
 * Returns program counts and unverified count for the Service Records summary cards.
 *
 * @return array<string, int>
 */
function getServiceRecordStats(): array
{
    global $pdo;

    $stats = [
        'total'                => 0,
        'unverified'           => 0,
        'Family Planning'      => 0,
        'Maternal/Prenatal'    => 0,
        'Child Health-ECCD'    => 0,
        'Immunization'         => 0,
        'Laboratory Referral'  => 0,
        'General Consultation' => 0,
    ];

    $rows = $pdo->query(
        'SELECT program_type,
                COUNT(*) AS cnt,
                SUM(CASE WHEN verified_by IS NULL THEN 1 ELSE 0 END) AS unv
         FROM service_records
         GROUP BY program_type'
    )->fetchAll();

    foreach ($rows as $r) {
        $prog = (string)$r['program_type'];
        $cnt  = (int)$r['cnt'];
        $unv  = (int)$r['unv'];
        $stats['total']      += $cnt;
        $stats['unverified'] += $unv;
        if (isset($stats[$prog])) {
            $stats[$prog] = $cnt;
        }
    }

    return $stats;
}

/**
 * Creates a new clinical service record (`D4 service_records`) per FR-06, FR-07, BR-02.
 * Automatically marks `verified_by = $actorUserId` if the actor is `Nurse/Midwife`.
 *
 * @param array<string, mixed> $data
 * @param int                  $actorUserId
 * @param string               $actorRole
 * @return array{ok: bool, record_id?: int, error?: string}
 */
function createServiceRecord(array $data, int $actorUserId, string $actorRole): array
{
    global $pdo;

    if (!in_array($actorRole, [ROLE_NURSE, ROLE_BHW], true)) {
        return ['ok' => false, 'error' => 'Only Nurse/Midwife or BHW staff may encode clinical service records (Table 8-6 / BR-11).'];
    }

    $patientId       = (int)($data['patient_id'] ?? 0);
    $programType     = trim((string)($data['program_type'] ?? ''));
    $visitDate       = trim((string)($data['visit_date'] ?? ($data['service_date'] ?? date('Y-m-d'))));
    $formRefNo       = trim((string)($data['form_reference_no'] ?? ''));
    $treatmentGiven  = trim((string)($data['treatment_given'] ?? ''));
    $nextVisitDate   = trim((string)($data['next_visit_date'] ?? ''));
    $narrativeNotes  = trim((string)($data['narrative_notes'] ?? ($data['findings_notes'] ?? '')));
    $structuredInput = is_array($data['form_fields'] ?? null) ? $data['form_fields'] : [];

    if ($patientId <= 0) {
        return ['ok' => false, 'error' => 'Please select a valid registered patient.'];
    }
    if (!in_array($programType, PROGRAM_TYPES, true)) {
        return ['ok' => false, 'error' => 'Invalid health program type (BR-02).'];
    }
    if ($visitDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $visitDate)) {
        return ['ok' => false, 'error' => 'Please provide a valid visit date (YYYY-MM-DD).'];
    }

    $schemas = getProgramFormSchemas();
    if ($formRefNo === '') {
        $prefix    = $schemas[$programType]['ref_prefix'] ?? 'ENC';
        $formRefNo = sprintf('%s-%s-%04d', $prefix, date('Ymd', strtotime($visitDate)), random_int(10, 9999));
    }

    $findingsNotes = packFindingsNotes($programType, $structuredInput, $narrativeNotes, $treatmentGiven, $nextVisitDate);

    // Nurse/Midwife encounters are automatically clinically verified; BHW encounters await Nurse verification
    $verifiedBy = ($actorRole === ROLE_NURSE) ? $actorUserId : null;
    if ($actorRole === ROLE_NURSE && !empty($data['verified_by']) && (int)$data['verified_by'] > 0) {
        $verifiedBy = (int)$data['verified_by'];
    }

    $stmt = $pdo->prepare(
        'INSERT INTO service_records
            (patient_id, program_type, form_reference_no, service_date, findings_notes, recorded_by, verified_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $patientId,
        $programType,
        $formRefNo,
        $visitDate,
        $findingsNotes,
        $actorUserId,
        $verifiedBy,
    ]);

    $recordId = (int)$pdo->lastInsertId();

    logAudit(
        $pdo,
        'CREATE',
        sprintf(
            'Created %s service record #%d (%s) for patient_id=%d on %s (%s)',
            $programType,
            $recordId,
            $formRefNo,
            $patientId,
            $visitDate,
            $verifiedBy ? 'Clinically Verified' : 'Pending Nurse Verification'
        ),
        'service_records',
        $recordId,
        $actorUserId
    );

    return ['ok' => true, 'record_id' => $recordId];
}

/**
 * Updates an existing service record (`D4 service_records`).
 *
 * @param int                  $recordId
 * @param array<string, mixed> $data
 * @param int                  $actorUserId
 * @param string               $actorRole
 * @return array{ok: bool, error?: string}
 */
function updateServiceRecord(int $recordId, array $data, int $actorUserId, string $actorRole): array
{
    global $pdo;

    if (!in_array($actorRole, [ROLE_NURSE, ROLE_BHW], true)) {
        return ['ok' => false, 'error' => 'Only Nurse/Midwife or BHW staff may update clinical service records (Table 8-6 / BR-11).'];
    }

    $existing = getServiceRecordById($recordId);
    if (!$existing) {
        return ['ok' => false, 'error' => 'Service record not found.'];
    }

    if ($actorRole === ROLE_BHW && $existing['verified_by'] !== null) {
        return ['ok' => false, 'error' => 'Clinically verified records can only be modified by a Nurse/Midwife (FR-09).'];
    }

    $programType     = trim((string)($data['program_type'] ?? $existing['program_type']));
    $visitDate       = trim((string)($data['visit_date'] ?? $existing['service_date']));
    $treatmentGiven  = trim((string)($data['treatment_given'] ?? ''));
    $nextVisitDate   = trim((string)($data['next_visit_date'] ?? ''));
    $narrativeNotes  = trim((string)($data['narrative_notes'] ?? ''));
    $structuredInput = is_array($data['form_fields'] ?? null) ? $data['form_fields'] : [];

    if (!in_array($programType, PROGRAM_TYPES, true)) {
        return ['ok' => false, 'error' => 'Invalid health program type (BR-02).'];
    }

    $findingsNotes = packFindingsNotes($programType, $structuredInput, $narrativeNotes, $treatmentGiven, $nextVisitDate);
    $verifiedBy    = $existing['verified_by'] !== null ? (int)$existing['verified_by'] : null;
    if ($actorRole === ROLE_NURSE && (!empty($data['verify_now']) || $verifiedBy === null)) {
        $verifiedBy = $actorUserId;
    }

    $stmt = $pdo->prepare(
        'UPDATE service_records
         SET program_type = ?, service_date = ?, findings_notes = ?, verified_by = ?
         WHERE record_id = ?'
    );
    $stmt->execute([
        $programType,
        $visitDate,
        $findingsNotes,
        $verifiedBy,
        $recordId,
    ]);

    logAudit(
        $pdo,
        'UPDATE',
        sprintf('Updated %s service record #%d for %s', $programType, $recordId, $existing['upi']),
        'service_records',
        $recordId,
        $actorUserId
    );

    return ['ok' => true];
}

/**
 * Marks a BHW-encoded service record as clinically verified by a Nurse/Midwife (`FR-09`).
 *
 * @param int    $recordId
 * @param int    $actorUserId
 * @param string $actorRole
 * @return array{ok: bool, error?: string}
 */
function verifyServiceRecord(int $recordId, int $actorUserId, string $actorRole): array
{
    global $pdo;

    if ($actorRole !== ROLE_NURSE) {
        return ['ok' => false, 'error' => 'Only an authorized Nurse/Midwife may clinically verify service records (Table 8-6 / FR-09).'];
    }

    $existing = getServiceRecordById($recordId);
    if (!$existing) {
        return ['ok' => false, 'error' => 'Service record not found.'];
    }

    $stmt = $pdo->prepare('UPDATE service_records SET verified_by = ? WHERE record_id = ?');
    $stmt->execute([$actorUserId, $recordId]);

    logAudit(
        $pdo,
        'VERIFY',
        sprintf('Nurse/Midwife verified %s service record #%d for %s', $existing['program_type'], $recordId, $existing['upi']),
        'service_records',
        $recordId,
        $actorUserId
    );

    return ['ok' => true];
}

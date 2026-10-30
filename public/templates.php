<?php
// ============================================================================
// public/templates.php — Screen S5: Printable Blank DOH & Clinic Forms
// Covers:
//   - FR-13: Provide printable blank templates where authorized
//   - FR-14 / BR-13 / BR-14: Support paper/offline continuity during power or
//            connectivity outages; manual completion on paper remains official,
//            then later encoded with optional scan attachment
//   - Exact pixel-faithful HTML/CSS reproductions of all 8 form pages collected
//     from Barangay Tubtubon Health Center (C:\Users\mzsia\Downloads\forms)
// ============================================================================
require_once __DIR__ . '/../includes/auth.php';
requireRole([ROLE_NURSE, ROLE_BHW]);

$selectedForm = inputStr($_GET, 'form', 'fp_form_1');

$formsCatalog = [
    'fp_form_1' => [
        'title'   => '1. Family Planning (FP) Form 1 — Side A & Side B (Back-to-Back Bondpaper)',
        'program' => 'Family Planning',
        'source'  => 'family planning form 1.jpg + Side B backside',
    ],
    'eccd_card' => [
        'title'   => '2. The Early Childhood Care and Development (ECCD) Card — Front Cover & Services/WHO Growth Chart',
        'program' => 'Child Health-ECCD / Immunization',
        'source'  => 'THE EARLY CHILDHOOD CARE AND DEVELOPMENT ECCD card.jpg + essential health and nutrition services.jpg',
    ],
    'hbmr_card' => [
        'title'   => '3. Home Based Mother\'s Record (HBMR) — Panels 1, 3, 4 & Present Pregnancy Matrix',
        'program' => 'Maternal/Prenatal',
        'source'  => 'home based mother records.jpg + present pregnancy.jpg',
    ],
    'maternal_record' => [
        'title'   => '4. Maternal Record Form (Local Health Unit Two-Column Consultation & Treatment Ledger)',
        'program' => 'Maternal/Prenatal',
        'source'  => 'maternal record.jpg',
    ],
    'ntp_form_2a' => [
        'title'   => '5. Form 2a. NTP Laboratory Request and Result Form (TB Screening & Microscopy)',
        'program' => 'Laboratory Referral',
        'source'  => 'ntp laboratory request.jpg',
    ],
    'general_consultation' => [
        'title'   => '6. General Consultation & Individual Treatment Record (ITR Standby Slip)',
        'program' => 'General Consultation',
        'source'  => 'Clinic Standby Consultation Form',
    ],
];

if (!array_key_exists($selectedForm, $formsCatalog)) {
    $selectedForm = 'fp_form_1';
}

// Log template view/print preparation to audit trail
if (isset($_GET['audit_print'])) {
    logAudit(
        $pdo,
        'TEMPLATE_PRINT',
        "Printed blank fallback template '{$formsCatalog[$selectedForm]['title']}' (BR-13/14)",
        'templates',
        $selectedForm,
        currentUserId()
    );
}

$pageTitle    = 'Printable Blank DOH & Clinic Fallback Forms';
$pageSubtitle = 'FR-13 & FR-14 • BR-13 Outage Paper Authority • Exact Standby Form Layouts';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
  /* Screen & Print Paper Sheet Styling matching official DOH/Clinic monochrome forms */
  .paper-sheet {
    background: #ffffff;
    color: #000000;
    border: 1px solid #222;
    padding: 22px 26px;
    margin: 0 auto 28px auto;
    max-width: 900px;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 11px;
    line-height: 1.28;
    box-shadow: 0 6px 20px rgba(0,0,0,.08);
    position: relative;
  }
  .paper-sheet table.form-grid {
    width: 100%;
    border-collapse: collapse;
    border: 1.5px solid #000;
  }
  .paper-sheet table.form-grid th,
  .paper-sheet table.form-grid td {
    border: 1px solid #000;
    padding: 3px 5px;
    vertical-align: top;
    color: #000;
  }
  .paper-sheet .chk-box {
    display: inline-block;
    width: 10px;
    height: 10px;
    border: 1px solid #000;
    margin-right: 3px;
    vertical-align: middle;
  }
  .paper-sheet .fill-line {
    display: inline-block;
    border-bottom: 1px solid #000;
    min-width: 90px;
    height: 12px;
  }
  .paper-sheet .section-bar {
    background: #e5e7eb;
    font-weight: 700;
    text-transform: uppercase;
    padding: 3px 6px;
    border: 1px solid #000;
    font-size: 10.5px;
  }
  .paper-sheet .shaded-risk {
    background: repeating-linear-gradient(
      90deg,
      #d1d5db,
      #d1d5db 2px,
      #f3f4f6 2px,
      #f3f4f6 4px
    ) !important;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }
  .paper-sheet .shaded-dark {
    background: #9ca3af !important;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }
  .doh-watermark {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 340px;
    height: 340px;
    opacity: 0.06;
    pointer-events: none;
    z-index: 0;
  }
  .paper-content {
    position: relative;
    z-index: 1;
  }
  @media print {
    body {
      background: #fff !important;
    }
    .paper-sheet {
      box-shadow: none !important;
      border: none !important;
      margin: 0 !important;
      padding: 6mm !important;
      max-width: 100% !important;
      width: 100% !important;
    }
    .page-break-after {
      page-break-after: always;
      break-after: page;
    }
  }
</style>

<!-- TOP TOOLBAR (Hidden when printing) -->
<div class="card mb-4 no-print">
  <div class="card-body">
    <div class="row g-3 align-items-center">
      <div class="col-12 col-lg-7">
        <label class="form-label small fw-700 text-green mb-1">
          <i class="bi bi-file-earmark-medical me-1"></i>Select Official Standby Form Layout to Preview / Print (FR-13, BR-13/14):
        </label>
        <div class="d-flex gap-2 flex-wrap">
          <?php foreach ($formsCatalog as $fKey => $fMeta): ?>
            <a href="<?= e(appUrl('public/templates.php?form=' . urlencode($fKey))) ?>"
               class="btn btn-sm <?= ($selectedForm === $fKey) ? 'btn-success' : 'btn-outline-secondary' ?>"
               style="<?= ($selectedForm === $fKey) ? 'background:#0B3B22;border-color:#0B3B22;' : '' ?>">
              <?= e(explode('—', $fMeta['title'])[0]) ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="col-12 col-lg-5 text-lg-end">
        <div class="d-inline-flex gap-2">
          <button type="button" class="btn btn-success btn-sm px-3" style="background:#0B3B22;border-color:#0B3B22;"
                  onclick="window.print()">
            <i class="bi bi-printer-fill me-1"></i>Print Blank Form Now (`@media print`)
          </button>
          <a href="<?= e(appUrl('public/service_records.php')) ?>" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-keyboard me-1"></i>Encode Completed Form
          </a>
        </div>
        <div class="small text-muted mt-1">
          Program: <strong><?= e($formsCatalog[$selectedForm]['program']) ?></strong>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if ($selectedForm === 'fp_form_1'): ?>
<!-- ============================================================================
     FORM 1: FAMILY PLANNING (FP) FORM 1 — SIDE A & SIDE B (ver. 3.0)
     Exact layout from `family planning form 1.jpg` & `Side B`
     ============================================================================ -->
<div class="paper-sheet page-break-after">
  <div class="paper-content">
    <div class="d-flex justify-content-between align-items-end mb-1">
      <strong style="font-size:12px;">SIDE A</strong>
      <strong style="font-size:14px;letter-spacing:.5px;">FAMILY PLANNING (FP) FORM 1</strong>
      <span style="font-size:11px;">ver. 3.0</span>
    </div>

    <table class="form-grid mb-1">
      <tr>
        <td style="width:62%;">
          <div style="font-weight:700;font-size:12px;">FAMILY PLANNING CLIENT ASSESSMENT RECORD</div>
          <div style="font-size:9.5px;font-style:italic;">
            Instructions for Physicians, Nurses and Midwives: <strong>Make sure that the client is not pregnant by using the questions listed in SIDE B.</strong> Completely fill out or check the required information. Refer accordingly for any abnormal history/findings for further medical evaluation.
          </div>
        </td>
        <td style="width:38%;">
          <div class="mb-1"><strong>CLIENT ID:</strong> <span class="fill-line" style="min-width:170px;"></span></div>
          <div class="mb-1"><strong>PHILHEALTH NO.:</strong> <span class="fill-line" style="min-width:140px;"></span></div>
          <div style="font-size:9.5px;">
            <strong>NHTS?:</strong> <span class="chk-box"></span>Yes <span class="chk-box"></span>No &nbsp;
            <strong>Pantawid Pamilya Pilipino Program(4Ps):</strong> <span class="chk-box"></span>Yes <span class="chk-box"></span>No
          </div>
        </td>
      </tr>
      <tr>
        <td colspan="2">
          <div class="row g-1">
            <div class="col-7">
              <strong>NAME OF CLIENT:</strong>
              <div class="d-flex justify-content-around border-bottom border-dark mt-2 pt-1" style="font-size:9.5px;">
                <span>Last Name</span><span>Given Name</span><span>MI</span>
              </div>
            </div>
            <div class="col-5">
              <div class="d-flex justify-content-between border-bottom border-dark mt-3 pt-1" style="font-size:9.5px;">
                <span>Date of Birth (MM/DD/YYYY)</span><span>Age</span><span>Educ. Attain.</span><span>Occupation</span>
              </div>
            </div>
          </div>

          <div class="row g-1 mt-1">
            <div class="col-7">
              <strong>ADDRESS:</strong>
              <div class="d-flex justify-content-between border-bottom border-dark mt-2 pt-1" style="font-size:9.5px;">
                <span>No.</span><span>Street</span><span>Barangay</span><span>Municipality/City</span><span>Province</span>
              </div>
            </div>
            <div class="col-5">
              <div class="d-flex justify-content-around border-bottom border-dark mt-3 pt-1" style="font-size:9.5px;">
                <span>Contact Number</span><span>Civil Status</span><span>Religion</span>
              </div>
            </div>
          </div>

          <div class="row g-1 mt-1">
            <div class="col-7">
              <strong>NAME OF SPOUSE:</strong>
              <div class="d-flex justify-content-around border-bottom border-dark mt-2 pt-1" style="font-size:9.5px;">
                <span>Last Name</span><span>Given Name</span><span>MI</span>
              </div>
            </div>
            <div class="col-5">
              <div class="d-flex justify-content-around border-bottom border-dark mt-3 pt-1" style="font-size:9.5px;">
                <span>Date of Birth</span><span>Age</span><span>Occupation</span>
              </div>
            </div>
          </div>

          <div class="mt-2 d-flex justify-content-between flex-wrap" style="font-size:10px;">
            <span><strong>NO. OF LIVING CHILDREN:</strong> <span class="fill-line" style="min-width:50px;"></span></span>
            <span><strong>PLAN TO HAVE MORE CHILDREN?</strong> <span class="chk-box"></span>Yes <span class="chk-box"></span>No</span>
            <span><strong>AVERAGE MONTHLY INCOME:</strong> <span class="fill-line" style="min-width:110px;"></span></span>
          </div>
        </td>
      </tr>
      <tr>
        <td colspan="2">
          <div class="row g-1" style="font-size:10px;">
            <div class="col-3">
              <strong>Type of Client</strong><br>
              <span class="chk-box"></span>New Acceptor<br>
              <span class="chk-box"></span>Current User<br>
              &nbsp;&nbsp;<span class="chk-box"></span>Changing Method<br>
              &nbsp;&nbsp;<span class="chk-box"></span>Changing Clinic<br>
              &nbsp;&nbsp;<span class="chk-box"></span>Dropout/Restart
            </div>
            <div class="col-4">
              <strong>Reason for FP:</strong> <span class="chk-box"></span>spacing <span class="chk-box"></span>limiting <span class="chk-box"></span>others <span class="fill-line" style="min-width:50px;"></span><br><br>
              <strong>Reason (Changing):</strong><br>
              <span class="chk-box"></span>medical condition &nbsp; <span class="chk-box"></span>side-effects
            </div>
            <div class="col-5">
              <strong>Method currently used (for Changing Method):</strong>
              <div class="row g-0 mt-1">
                <div class="col-4">
                  <span class="chk-box"></span>COC<br>
                  <span class="chk-box"></span>POP<br>
                  <span class="chk-box"></span>Injectable<br>
                  <span class="chk-box"></span>Implant
                </div>
                <div class="col-4">
                  <span class="chk-box"></span>IUD:<br>
                  &nbsp;<span class="chk-box"></span>Interval<br>
                  &nbsp;<span class="chk-box"></span>Post-Partum<br>
                  <span class="chk-box"></span>Condom
                </div>
                <div class="col-4">
                  <span class="chk-box"></span>BOM/CMM<br>
                  <span class="chk-box"></span>BBT<br>
                  <span class="chk-box"></span>STM<br>
                  <span class="chk-box"></span>SDM / <span class="chk-box"></span>LAM
                </div>
              </div>
            </div>
          </div>
        </td>
      </tr>
      <tr>
        <!-- LEFT COLUMN: I. MEDICAL, II. OBSTETRICAL, III. STI RISKS -->
        <td style="width:52%;">
          <div class="section-bar mb-1">I. MEDICAL HISTORY</div>
          <div class="small mb-1">Does the client have any of the following?</div>
          <?php
          $fpMedItems = [
              'severe headaches / migraine',
              'history of stroke / heart attack / hypertension',
              'non-traumatic hematoma / frequent bruising or gum bleeding',
              'current or history of breast cancer / breast mass',
              'severe chest pain',
              'cough for more than 14 days',
              'jaundice',
              'unexplained vaginal bleeding',
              'abnormal vaginal discharge',
              'intake of phenobarbital (anti-seizure) or rifampicin (anti-TB)',
              'Is the client a SMOKER?',
              'With Disability? (if YES please specify: ______________)',
          ];
          foreach ($fpMedItems as $item): ?>
            <div class="d-flex justify-content-between py-0" style="font-size:9.8px;">
              <span>• <?= e($item) ?></span>
              <span class="text-nowrap"><span class="chk-box"></span>Yes &nbsp;<span class="chk-box"></span>No</span>
            </div>
          <?php endforeach; ?>

          <div class="section-bar mt-2 mb-1">II. OBSTETRICAL HISTORY</div>
          <div style="font-size:9.8px;">
            <div>Number of pregnancies: G____ P____ (Full term____ Premature____ Abortion____ Living children____)</div>
            <div class="mt-1">Date of last delivery: ____/____/______ &nbsp; Type: <span class="chk-box"></span>Vaginal <span class="chk-box"></span>Cesarean Section</div>
            <div class="mt-1">Last menstrual period (LMP): ____/____/______ &nbsp; Previous menstrual period: ____/____/______</div>
            <div class="mt-1">
              Menstrual flow:
              <span class="chk-box"></span>scanty (1-2 pads/day) &nbsp;
              <span class="chk-box"></span>moderate (3-5 pads/day) &nbsp;
              <span class="chk-box"></span>heavy (&gt;5 pads/day)
            </div>
            <div class="mt-1">
              <span class="chk-box"></span>Dysmenorrhea &nbsp;
              <span class="chk-box"></span>Hydatidiform mole (within last 12 mos) &nbsp;
              <span class="chk-box"></span>History of ectopic pregnancy
            </div>
          </div>

          <div class="section-bar mt-2 mb-1">III. RISKS FOR SEXUALLY TRANSMITTED INFECTIONS</div>
          <div style="font-size:9.8px;">
            <div>Does the client or the client's partner have any of the following?</div>
            <div class="d-flex justify-content-between">
              <span>• abnormal discharge from the genital area (if YES: <span class="chk-box"></span>Vagina <span class="chk-box"></span>Penis)</span>
              <span><span class="chk-box"></span>Yes <span class="chk-box"></span>No</span>
            </div>
            <div class="d-flex justify-content-between">
              <span>• sores or ulcers in the genital area</span>
              <span><span class="chk-box"></span>Yes <span class="chk-box"></span>No</span>
            </div>
            <div class="d-flex justify-content-between">
              <span>• pain or burning sensation in the genital area</span>
              <span><span class="chk-box"></span>Yes <span class="chk-box"></span>No</span>
            </div>
            <div class="d-flex justify-content-between">
              <span>• history of treatment for sexually transmitted infections</span>
              <span><span class="chk-box"></span>Yes <span class="chk-box"></span>No</span>
            </div>
            <div class="d-flex justify-content-between">
              <span>• HIV / AIDS / Pelvic inflammatory disease</span>
              <span><span class="chk-box"></span>Yes <span class="chk-box"></span>No</span>
            </div>
          </div>
        </td>

        <!-- RIGHT COLUMN: IV. VAW RISKS, V. PHYSICAL EXAM, ACKNOWLEDGEMENT -->
        <td style="width:48%;">
          <div class="section-bar mb-1">IV. RISKS FOR VIOLENCE AGAINST WOMEN (VAW)</div>
          <div style="font-size:9.8px;">
            <div class="d-flex justify-content-between">
              <span>• unpleasant relationship with partner</span>
              <span><span class="chk-box"></span>Yes <span class="chk-box"></span>No</span>
            </div>
            <div class="d-flex justify-content-between">
              <span>• partner does not approve of the visit to FP clinic</span>
              <span><span class="chk-box"></span>Yes <span class="chk-box"></span>No</span>
            </div>
            <div class="d-flex justify-content-between">
              <span>• history of domestic violence or VAW</span>
              <span><span class="chk-box"></span>Yes <span class="chk-box"></span>No</span>
            </div>
            <div class="mt-1">
              Referred to: <span class="chk-box"></span>DSWD &nbsp; <span class="chk-box"></span>WCPU &nbsp; <span class="chk-box"></span>NGOs &nbsp; <span class="chk-box"></span>Others: _________
            </div>
          </div>

          <div class="section-bar mt-2 mb-1">V. PHYSICAL EXAMINATION</div>
          <div style="font-size:9.8px;">
            <div class="d-flex justify-content-between mb-1">
              <span>Weight: _______ kg &nbsp; Height: _______ m</span>
              <span>Blood Pressure: _______ mmHg &nbsp; Pulse: _____ /min</span>
            </div>
            <div class="row g-1">
              <div class="col-6">
                <strong>SKIN:</strong><br>
                <span class="chk-box"></span>normal &nbsp;<span class="chk-box"></span>pale<br>
                <span class="chk-box"></span>yellowish &nbsp;<span class="chk-box"></span>hematoma<br>
                <strong>CONJUNCTIVA:</strong><br>
                <span class="chk-box"></span>normal &nbsp;<span class="chk-box"></span>pale &nbsp;<span class="chk-box"></span>yellowish<br>
                <strong>NECK:</strong><br>
                <span class="chk-box"></span>normal &nbsp;<span class="chk-box"></span>neck mass<br>
                <span class="chk-box"></span>enlarged lymph nodes<br>
                <strong>BREAST:</strong><br>
                <span class="chk-box"></span>normal &nbsp;<span class="chk-box"></span>mass &nbsp;<span class="chk-box"></span>nipple discharge<br>
                <strong>ABDOMEN:</strong><br>
                <span class="chk-box"></span>normal &nbsp;<span class="chk-box"></span>abdominal mass &nbsp;<span class="chk-box"></span>varicosities
              </div>
              <div class="col-6">
                <strong>EXTREMITIES:</strong><br>
                <span class="chk-box"></span>normal &nbsp;<span class="chk-box"></span>edema &nbsp;<span class="chk-box"></span>varicosities<br>
                <strong>PELVIC EXAMINATION (For IUD Acceptors):</strong><br>
                <span class="chk-box"></span>normal &nbsp;<span class="chk-box"></span>mass &nbsp;<span class="chk-box"></span>abnormal discharge<br>
                <span class="chk-box"></span>cervical abnormalities:<br>
                &nbsp;&nbsp;<span class="chk-box"></span>warts &nbsp;<span class="chk-box"></span>polyp or cyst<br>
                &nbsp;&nbsp;<span class="chk-box"></span>inflammation/erosion &nbsp;<span class="chk-box"></span>bloody discharge<br>
                <span class="chk-box"></span>cervical consistency: <span class="chk-box"></span>firm <span class="chk-box"></span>soft<br>
                <span class="chk-box"></span>cervical tenderness &nbsp;<span class="chk-box"></span>adnexal mass/tenderness<br>
                <span class="chk-box"></span>uterine position: <span class="chk-box"></span>mid <span class="chk-box"></span>anteflexed <span class="chk-box"></span>retroflexed<br>
                <span class="chk-box"></span>uterine depth: ______ cm
              </div>
            </div>
          </div>

          <div class="section-bar mt-2 mb-1">ACKNOWLEDGEMENT</div>
          <div style="font-size:9.3px;">
            This is to certify that the Physician/Nurse/Midwife of the clinic has fully explained to me the different methods available in family planning and I freely choose the _______________________ method.
            <div class="d-flex justify-content-between mt-3 pt-1">
              <span class="border-top border-dark px-4">Client Signature</span>
              <span class="border-top border-dark px-4">Date</span>
            </div>
            <div class="mt-2">
              For WRA below 18 yrs. Old: I hereby consent _____________________ to accept the Family Planning method.
            </div>
            <div class="d-flex justify-content-between mt-3 pt-1">
              <span class="border-top border-dark px-3">Parent/Guardian Signature</span>
              <span class="border-top border-dark px-4">Date</span>
            </div>
          </div>
        </td>
      </tr>
    </table>
    <div style="font-size:8.5px;font-style:italic;" class="text-center">
      Implant = Progestin subdermal implant; IUD = Intrauterine device; BTL = Bilateral tubal ligation; NSV = No-scalpel vasectomy; COC = Combined oral contraceptives; POP = Progestin only pills; LAM = Lactational amenorrhea method; SDM = Standard days method; BBT = Basal body temperature; BOM = Billings ovulation method; CMM = Cervical mucus method; STM = Symptothermal method
    </div>
  </div>
</div>

<!-- FP FORM 1 — SIDE B (Backside of Bondpaper) -->
<div class="paper-sheet">
  <div class="paper-content">
    <div class="d-flex justify-content-between align-items-end mb-1">
      <strong style="font-size:12px;">SIDE B</strong>
      <strong style="font-size:13px;">FP FORM 1</strong>
    </div>
    <div class="text-center fw-bold border border-dark py-1 bg-light">FAMILY PLANNING CLIENT ASSESSMENT RECORD</div>
    <table class="form-grid">
      <thead>
        <tr class="text-center bg-light">
          <th style="width:15%;">DATE OF VISIT<br><small>(MM/DD/YYYY)</small></th>
          <th style="width:43%;">MEDICAL FINDINGS<br><small>(Medical observation, complaints/complication, service rendered/procedures, laboratory examination, treatment and referrals)</small></th>
          <th style="width:15%;">METHOD ACCEPTED</th>
          <th style="width:15%;">NAME AND SIGNATURE OF SERVICE PROVIDER</th>
          <th style="width:12%;">DATE OF FOLLOW-UP VISIT<br><small>(MM/DD/YYYY)</small></th>
        </tr>
      </thead>
      <tbody>
        <?php for ($r = 0; $r < 14; $r++): ?>
        <tr>
          <td style="height:30px;">&nbsp;</td>
          <td>&nbsp;</td>
          <td>&nbsp;</td>
          <td>&nbsp;</td>
          <td>&nbsp;</td>
        </tr>
        <?php endfor; ?>
      </tbody>
    </table>

    <div class="border border-dark p-2 mt-1" style="font-size:10px;">
      <strong>How to be Reasonably Sure a Client is Not Pregnant</strong>
      <div class="d-flex justify-content-between mt-1">
        <span>1. Did you have a baby less than six (6) months ago, are you fully or nearly-fully breastfeeding, and have you had no menstrual period since then?</span>
        <span class="text-nowrap"><span class="chk-box"></span>Yes &nbsp;<span class="chk-box"></span>No</span>
      </div>
      <div class="d-flex justify-content-between">
        <span>2. Have you abstained from sexual intercourse since your last menstrual period or delivery?</span>
        <span class="text-nowrap"><span class="chk-box"></span>Yes &nbsp;<span class="chk-box"></span>No</span>
      </div>
      <div class="d-flex justify-content-between">
        <span>3. Have you had a baby in the last four (4) weeks?</span>
        <span class="text-nowrap"><span class="chk-box"></span>Yes &nbsp;<span class="chk-box"></span>No</span>
      </div>
      <div class="d-flex justify-content-between">
        <span>4. Did your last menstrual period start within the past seven (7) days?</span>
        <span class="text-nowrap"><span class="chk-box"></span>Yes &nbsp;<span class="chk-box"></span>No</span>
      </div>
      <div class="d-flex justify-content-between">
        <span>5. Have you had a miscarriage or abortion in the last seven (7) days?</span>
        <span class="text-nowrap"><span class="chk-box"></span>Yes &nbsp;<span class="chk-box"></span>No</span>
      </div>
      <div class="d-flex justify-content-between">
        <span>6. Have you been using a reliable contraceptive method consistently and correctly?</span>
        <span class="text-nowrap"><span class="chk-box"></span>Yes &nbsp;<span class="chk-box"></span>No</span>
      </div>
      <div class="mt-1" style="font-size:9.5px;">
        ■ If the client answered <strong>YES</strong> to at least one of the questions and she is free of signs or symptoms of pregnancy, provide client with desired method.<br>
        ■ If the client answered <strong>NO</strong> to all of the questions, pregnancy cannot be ruled out. The client should await menses or use a pregnancy test.
      </div>
    </div>
  </div>
</div>

<?php elseif ($selectedForm === 'eccd_card'): ?>
<!-- ============================================================================
     FORM 2: THE EARLY CHILDHOOD CARE AND DEVELOPMENT (ECCD) CARD
     Page 1: Front Cover (`THE EARLY CHILDHOOD CARE AND DEVELOPMENT ECCD card.jpg`)
     Page 2: Essential Health and Nutrition Services + WHO Weight-for-Height Chart
     ============================================================================ -->
<div class="paper-sheet page-break-after">
  <div class="paper-content">
    <div class="text-center bg-dark text-white py-2 mb-3" style="-webkit-print-color-adjust:exact;print-color-adjust:exact;">
      <div style="font-size:14px;font-weight:800;letter-spacing:.5px;">ALWAYS BRING THIS CARD WHEN YOU BRING YOUR CHILD</div>
      <div style="font-size:14px;font-weight:800;letter-spacing:.5px;">TO THE HEALTH CENTER / CLINIC</div>
    </div>

    <div class="row align-items-center text-center mb-3">
      <div class="col-4">
        <div class="border border-dark rounded-circle d-inline-flex align-items-center justify-content-center" style="width:82px;height:82px;font-size:9px;font-weight:700;">
          REPUBLIC OF THE<br>PHILIPPINES<br>DOH SEAL
        </div>
      </div>
      <div class="col-4">
        <div class="border border-dark d-inline-flex align-items-center justify-content-center bg-light" style="width:95px;height:95px;font-size:9px;">
          CHILD PHOTO<br>(2x2)
        </div>
        <div class="fw-bold mt-2" style="font-size:13px;">The Early Childhood Care<br>and Development (ECCD) Card</div>
      </div>
      <div class="col-4">
        <div class="border border-dark rounded-circle d-inline-flex align-items-center justify-content-center" style="width:82px;height:82px;font-size:9px;font-weight:700;">
          PROVINCE OF<br>NEGROS ORIENTAL<br>OFFICIAL SEAL
        </div>
      </div>
    </div>

    <table class="form-grid" style="font-size:11px;">
      <tr>
        <td style="width:65%;">
          <div><strong>Clinic/Health Center:</strong> Barangay Tubtubon Health Center</div>
          <div><strong>Barangay:</strong> Tubtubon</div>
          <div><strong>Purok/Sitio:</strong></div>
        </td>
        <td style="width:35%;">
          <div><strong>Child's No.:</strong></div>
          <div class="mt-2"><strong>Family No.:</strong></div>
        </td>
      </tr>
      <tr>
        <td colspan="2" style="height:36px;">
          <strong>Complete Address of Family (House No., Street, City/Province):</strong><br>
          <span class="text-muted">Tubtubon, Sibulan, Negros Oriental</span>
        </td>
      </tr>
      <tr>
        <td colspan="2" style="height:34px;">
          <strong>Child's Name:</strong>
        </td>
      </tr>
      <tr>
        <td>
          <div><strong>Mother's Name:</strong></div>
          <div><strong>Educational Level:</strong></div>
          <div><strong>Occupation:</strong></div>
        </td>
        <td>
          <strong>No. of Pregnancies:</strong>
        </td>
      </tr>
      <tr>
        <td colspan="2">
          <div><strong>Father's Name:</strong></div>
          <div><strong>Educational Level:</strong></div>
          <div><strong>Occupation:</strong></div>
        </td>
      </tr>
      <tr>
        <td colspan="2" class="p-0">
          <table class="w-100" style="border-collapse:collapse;">
            <tr>
              <td style="width:34%;border-right:1px solid #000;height:40px;"><strong>Birth Date:</strong></td>
              <td style="width:33%;border-right:1px solid #000;"><strong>Gestational Age at Birth:</strong></td>
              <td style="width:33%;">
                <strong>Type of Birth:</strong><br>
                <span class="chk-box"></span>Normal &nbsp;&nbsp; <span class="chk-box"></span>CS
              </td>
            </tr>
            <tr>
              <td style="border-top:1px solid #000;border-right:1px solid #000;height:36px;"><strong>Birth Order:</strong></td>
              <td style="border-top:1px solid #000;border-right:1px solid #000;"><strong>Birth Weight:</strong></td>
              <td style="border-top:1px solid #000;"><strong>Birth Length:</strong></td>
            </tr>
          </table>
        </td>
      </tr>
      <tr>
        <td style="width:62%;">
          <strong>Place of Delivery:</strong><br>
          <div class="row mt-1">
            <div class="col-6">
              <span class="chk-box"></span>Home<br>
              <span class="chk-box"></span>Hospital
            </div>
            <div class="col-6">
              <span class="chk-box"></span>Lying-in<br>
              <span class="chk-box"></span>Others _______________
            </div>
          </div>
        </td>
        <td style="width:38%;">
          <strong>Date of Birth Registration:</strong>
        </td>
      </tr>
      <tr>
        <td colspan="2">
          <strong>Birth Attendant:</strong><br>
          <div class="d-flex justify-content-between mt-1 pe-4">
            <span><span class="chk-box"></span>Doctor</span>
            <span><span class="chk-box"></span>Nurse</span>
            <span><span class="chk-box"></span>Midwife</span>
            <span><span class="chk-box"></span>Hilot</span>
            <span><span class="chk-box"></span>Others ___________________</span>
          </div>
        </td>
      </tr>
    </table>
    <div class="mt-2"><strong>Remarks:</strong></div>
  </div>
</div>

<!-- ECCD CARD PAGE 2: ESSENTIAL HEALTH AND NUTRITION SERVICES + WHO GROWTH CHART -->
<div class="paper-sheet">
  <div class="paper-content">
    <h6 class="fw-bold text-center text-uppercase border-bottom border-dark pb-1 mb-2" style="font-size:13px;">
      ESSENTIAL HEALTH AND NUTRITION SERVICES
    </h6>
    <table class="form-grid text-center mb-3" style="font-size:10px;">
      <thead>
        <tr class="bg-light">
          <th rowspan="2" class="text-start" style="width:38%;">Services</th>
          <th colspan="6">Date Administered</th>
        </tr>
        <tr class="bg-light">
          <th style="width:10.3%;">1st</th>
          <th style="width:10.3%;">2nd</th>
          <th style="width:10.3%;">3rd</th>
          <th style="width:10.3%;">4th</th>
          <th style="width:10.3%;">5th</th>
          <th style="width:10.3%;">6th</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td class="text-start"><strong>Newborn Screening</strong> after the first 24 hrs of life</td>
          <td></td>
          <td class="shaded-dark"></td>
          <td class="shaded-dark"></td>
          <td class="shaded-dark"></td>
          <td class="shaded-dark"></td>
          <td class="shaded-dark"></td>
        </tr>
        <tr>
          <td class="text-start"><strong>Immunization</strong> — <strong>BCG</strong> (at birth)</td>
          <td></td>
          <td class="shaded-dark"></td>
          <td class="shaded-dark"></td>
          <td class="shaded-dark"></td>
          <td class="shaded-dark"></td>
          <td class="shaded-dark"></td>
        </tr>
        <tr>
          <td class="text-start ps-3"><strong>DPT</strong> (6 wks, 10 wks, 14 wks old)</td>
          <td></td><td></td><td></td>
          <td class="shaded-dark"></td><td class="shaded-dark"></td><td class="shaded-dark"></td>
        </tr>
        <tr>
          <td class="text-start ps-3"><strong>OPV</strong> (6 wks, 10 wks, 14 wks old)</td>
          <td></td><td></td><td></td>
          <td class="shaded-dark"></td><td class="shaded-dark"></td><td class="shaded-dark"></td>
        </tr>
        <tr>
          <td class="text-start ps-3"><strong>Hepatitis B</strong> (w/in 24 hrs, 6 wks, 14 wks)</td>
          <td></td><td></td><td></td>
          <td class="shaded-dark"></td><td class="shaded-dark"></td><td class="shaded-dark"></td>
        </tr>
        <tr>
          <td class="text-start ps-3"><strong>Measles</strong> (9 months)</td>
          <td></td>
          <td class="shaded-dark"></td><td class="shaded-dark"></td><td class="shaded-dark"></td><td class="shaded-dark"></td><td class="shaded-dark"></td>
        </tr>
        <tr>
          <td class="text-start ps-3"><strong>Other Vaccines:</strong> 1. ____________</td>
          <td></td><td></td><td></td><td></td><td></td><td></td>
        </tr>
        <tr>
          <td class="text-start ps-3">2. ____________________________</td>
          <td></td><td></td><td></td><td></td><td></td><td></td>
        </tr>
        <tr>
          <td class="text-start">
            <strong>Vitamin A Supplementation</strong><br>
            <small>100,000 I.U. (starting at 6 mos) | 200,000 I.U. (1 yr &amp; above)</small>
          </td>
          <td></td><td></td><td></td><td></td><td></td><td></td>
        </tr>
        <tr>
          <td class="text-start"><strong>Counseling:</strong> Breastfeeding and Complementary feeding</td>
          <td></td><td></td><td></td><td></td><td></td><td></td>
        </tr>
        <tr>
          <td class="text-start"><strong>Growth Monitoring</strong> (monthly for 0-24 mos &amp; bi-annually for 25-71 mos)</td>
          <td></td><td></td><td></td><td></td><td></td><td></td>
        </tr>
        <tr>
          <td class="text-start"><strong>Development Screening</strong></td>
          <td></td><td></td><td></td><td></td><td></td><td></td>
        </tr>
        <tr>
          <td class="text-start"><strong>Deworming</strong> (every 6 months starting at 1 yr. old)</td>
          <td></td><td></td><td></td><td></td><td></td><td></td>
        </tr>
        <tr>
          <td class="text-start"><strong>Dental Check-up</strong> (starting at 2-3 years)</td>
          <td></td><td></td><td></td><td></td><td></td><td></td>
        </tr>
      </tbody>
    </table>

    <!-- WHO Weight-for-Height Z-Score Grid (24 to 60 months, BOYS) -->
    <div class="text-center fw-bold mb-1">24 to 60 months, BOYS* — Weight-for-height (z-scores)</div>
    <div class="border border-dark p-2">
      <svg viewBox="0 0 680 230" width="100%" height="230">
        <rect x="45" y="10" width="540" height="190" fill="#fff" stroke="#000" stroke-width="1.2"/>
        <!-- Horizontal grid lines (Weight 5kg to 32kg) -->
        <?php for ($i = 1; $i < 9; $i++): $y = 10 + ($i * 21); ?>
          <line x1="45" y1="<?= $y ?>" x2="585" y2="<?= $y ?>" stroke="#ccc" stroke-width="0.7" stroke-dasharray="2,2"/>
        <?php endfor; ?>
        <!-- Vertical grid lines (Height 65cm to 120cm) -->
        <?php for ($j = 1; $j < 11; $j++): $x = 45 + ($j * 49); ?>
          <line x1="<?= $x ?>" y1="10" x2="<?= $x ?>" y2="200" stroke="#ccc" stroke-width="0.7"/>
        <?php endfor; ?>
        <!-- Z-score curves (+3, +2, 0, -2, -3) -->
        <path d="M 45 170 Q 310 115 585 20" fill="none" stroke="#000" stroke-width="1.5"/>
        <path d="M 45 176 Q 310 128 585 45" fill="none" stroke="#000" stroke-width="1.5"/>
        <path d="M 45 184 Q 310 148 585 88" fill="none" stroke="#000" stroke-width="1.8"/>
        <path d="M 45 190 Q 310 164 585 122" fill="none" stroke="#000" stroke-width="1.5"/>
        <path d="M 45 195 Q 310 174 585 140" fill="none" stroke="#000" stroke-width="1.5"/>
        <!-- Labels -->
        <text x="592" y="22" font-size="9" font-weight="bold">+3 Obese</text>
        <text x="592" y="47" font-size="9" font-weight="bold">+2 Overweight</text>
        <text x="592" y="90" font-size="9" font-weight="bold">0 Normal</text>
        <text x="592" y="124" font-size="9" font-weight="bold">-2 Wasted</text>
        <text x="592" y="143" font-size="9" font-weight="bold">-3 Severely Wasted</text>
        <text x="275" y="218" font-size="10" font-weight="bold">Height (cm): 65 &nbsp; 70 &nbsp; 75 &nbsp; 80 &nbsp; 85 &nbsp; 90 &nbsp; 95 &nbsp; 100 &nbsp; 105 &nbsp; 110 &nbsp; 115 &nbsp; 120</text>
        <text x="12" y="115" font-size="10" font-weight="bold" transform="rotate(-90 12,115)">Weight (kg)</text>
      </svg>
      <div style="font-size:8.5px;" class="text-muted">Reference: WHO Child Growth Standards, Methods and Development, 2006</div>
    </div>
  </div>
</div>

<?php elseif ($selectedForm === 'hbmr_card'): ?>
<!-- ============================================================================
     FORM 3: HOME BASED MOTHER'S RECORD (HBMR)
     Page 1: Present Pregnancy, Action & Labor/Delivery (`present pregnancy.jpg`)
     Page 2: Panel 1 (Obstetrical/Health Problems), Panel 4 (Postpartum/FP), Panel 3 (Referral)
     ============================================================================ -->
<div class="paper-sheet page-break-after">
  <div class="paper-content">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <strong style="font-size:14px;">PRESENT PREGNANCY:</strong>
      <div style="font-size:9.5px;">
        <span class="d-inline-block border border-dark shaded-dark" style="width:16px;height:10px;"></span> = IMMEDIATE REFERRAL TO HOSPITAL &nbsp;&nbsp;
        <span class="d-inline-block border border-dark shaded-risk" style="width:16px;height:10px;"></span> = REFERRAL TO PHYSICIAN/RHU
      </div>
    </div>

    <div class="mb-2" style="font-size:11px;">
      <strong>LMP:</strong> month _________ day ______ year ______ &nbsp;&nbsp;&nbsp;&nbsp;
      <strong>EDC:</strong> month _________ day ______ year ______
    </div>

    <table class="form-grid text-center mb-2" style="font-size:9.8px;">
      <thead>
        <tr class="bg-light">
          <th class="text-start" style="width:34%;">TRIMESTER</th>
          <th style="width:8.25%;">1ST</th>
          <th colspan="3" style="width:24.75%;">2ND</th>
          <th colspan="4" style="width:33%;">3RD</th>
        </tr>
        <tr class="bg-light">
          <th class="text-start">AOG IN MONTHS</th>
          <th>2 OR 3</th>
          <th>4</th><th>5</th><th>6</th>
          <th>7</th><th>8</th><th>9</th><th>9+</th>
        </tr>
      </thead>
      <tbody>
        <tr><td class="text-start">DATE OF THIS VISIT</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
        <tr><td class="text-start">VAGINAL BLEEDING (Y/N)</td><td class="shaded-dark"></td><td class="shaded-dark"></td><td class="shaded-dark"></td><td class="shaded-dark"></td><td class="shaded-dark"></td><td class="shaded-dark"></td><td class="shaded-dark"></td><td class="shaded-dark"></td></tr>
        <tr><td class="text-start">URINARY TRACT INFECTION (Y/N)</td><td class="shaded-risk"></td><td class="shaded-risk"></td><td class="shaded-risk"></td><td class="shaded-risk"></td><td class="shaded-risk"></td><td class="shaded-risk"></td><td class="shaded-risk"></td><td class="shaded-risk"></td></tr>
        <tr><td class="text-start">WEIGHT IN KG.</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
        <tr><td class="text-start">BLOOD PRESSURE</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
        <tr><td class="text-start">BP 140/90 AND ABOVE (Y/N)</td><td class="shaded-dark"></td><td class="shaded-dark"></td><td class="shaded-dark"></td><td class="shaded-dark"></td><td class="shaded-dark"></td><td class="shaded-dark"></td><td class="shaded-dark"></td><td class="shaded-dark"></td></tr>
        <tr><td class="text-start">FEVER 38°C AND ABOVE (Y/N)</td><td class="shaded-risk"></td><td class="shaded-risk"></td><td class="shaded-risk"></td><td class="shaded-risk"></td><td class="shaded-risk"></td><td class="shaded-risk"></td><td class="shaded-risk"></td><td class="shaded-risk"></td></tr>
        <tr><td class="text-start">PALLOR (Y/N)</td><td class="shaded-risk"></td><td class="shaded-risk"></td><td class="shaded-risk"></td><td class="shaded-risk"></td><td class="shaded-risk"></td><td class="shaded-risk"></td><td class="shaded-risk"></td><td class="shaded-risk"></td></tr>
        <tr>
          <td class="text-start">ABNORMAL FUNDAL HEIGHT (Y/N)</td>
          <td></td><td></td>
          <td><small>20 CMS.</small></td>
          <td><small>21-24 CMS.</small></td>
          <td><small>25-28 CMS.</small></td>
          <td><small>28-30 CMS.</small></td>
          <td colspan="2"><small>30-34 CMS.</small></td>
        </tr>
        <tr><td class="text-start">ABNORMAL PRESENTATION (Y/N)</td><td></td><td></td><td></td><td></td><td></td><td class="shaded-dark"></td><td class="shaded-dark"></td><td class="shaded-dark"></td></tr>
        <tr><td class="text-start">MISSING FETAL HEARTBEAT (Y/N)</td><td></td><td></td><td class="shaded-dark"></td><td class="shaded-dark"></td><td class="shaded-dark"></td><td class="shaded-dark"></td><td class="shaded-dark"></td><td class="shaded-dark"></td></tr>
        <tr><td class="text-start">EDEMA (Y/N)</td><td class="shaded-risk"></td><td class="shaded-risk"></td><td class="shaded-risk"></td><td class="shaded-risk"></td><td class="shaded-risk"></td><td class="shaded-risk"></td><td class="shaded-risk"></td><td class="shaded-risk"></td></tr>
        <tr><td class="text-start">VAGINAL INFECTION (Y/N)</td><td class="shaded-risk"></td><td class="shaded-risk"></td><td class="shaded-risk"></td><td class="shaded-risk"></td><td class="shaded-risk"></td><td class="shaded-risk"></td><td class="shaded-risk"></td><td class="shaded-risk"></td></tr>
        <tr><td class="text-start">LAB TEST RESULTS (e.g. HGB, URINE, VDRL)</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
      </tbody>
    </table>

    <div class="fw-bold mb-1">ACTION:</div>
    <table class="form-grid text-center mb-2" style="font-size:9.8px;">
      <tbody>
        <tr><td class="text-start" style="width:34%;">IRON/FOLATE #/RX</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
        <tr><td class="text-start">MALARIA PROPHYLAXIS (Y/N)</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
        <tr><td class="text-start">MOTHER INTENDS TO BREASTFEED? (Y/N)</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
        <tr><td class="text-start">ADVICE ON 4 DANGER SIGNS (Y/N)</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
        <tr><td class="text-start">DENTAL CHECK-UP? (Y/N)</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
        <tr><td class="text-start">EMERGENCY PLANS AND PLACE OF DELIVERY (Y/N)</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
        <tr><td class="text-start">RISK? (Y/N)</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
        <tr><td class="text-start">DATE OF NEXT VISIT</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
      </tbody>
    </table>

    <div class="fw-bold mb-1">LABOR &amp; DELIVERY:</div>
    <table class="form-grid mb-2" style="font-size:9.8px;">
      <tr>
        <td style="width:42%;">IMMEDIATE BREASTFEEDING (Y/N)</td><td style="width:8%;"></td>
        <td style="width:42%;">BIRTH WEIGHT IN GRAMS</td><td style="width:8%;"></td>
      </tr>
      <tr>
        <td>TYPE OF DELIVERY</td><td></td>
        <td>POST-PARTUM HEMORRHAGE 500 CC + (Y/N)</td><td class="shaded-dark"></td>
      </tr>
      <tr>
        <td>DATE OF DELIVERY</td><td></td>
        <td>BABY ALIVE (Y/N)</td><td></td>
      </tr>
      <tr>
        <td>PLACE OF DELIVERY</td><td></td>
        <td>BABY HEALTHY (Y/N)</td><td></td>
      </tr>
    </table>

    <div class="row g-2" style="font-size:9.5px;">
      <div class="col-7">
        <strong class="text-decoration-underline">SIGNS OF DANGER DURING PREGNANCY:</strong><br>
        1. ANY TYPE OF VAGINAL BLEEDING<br>
        2. PUFFINESS OF THE FACE AND HANDS<br>
        3. HEADACHE, DIZZINESS, BLURRED VISION<br>
        4. BEING PALE OR ANEMIC
      </div>
      <div class="col-5">
        <div class="border border-dark p-1 text-center mb-1">
          <strong>MALARIA PROPHYLAXIS:</strong><br>CHLOROQUINE 150 mg base per tablet, 2 tablets per week
        </div>
        <div class="border border-dark p-1 text-center">
          <strong>IRON FOLATE:</strong><br>60 mg tablet, 2 tablets daily for 180 days
        </div>
      </div>
    </div>
  </div>
</div>

<!-- HBMR PAGE 2: PANELS 1, 4, AND 3 (Tri-Fold Backside from `home based mother records.jpg`) -->
<div class="paper-sheet">
  <div class="paper-content">
    <div class="row g-2">
      <!-- PANEL 3: REFERRAL -->
      <div class="col-4">
        <div class="d-flex justify-content-between"><strong>PANEL 3</strong><strong>REFERRAL:</strong></div>
        <table class="form-grid mt-1" style="font-size:9.5px;">
          <tr class="bg-light text-center">
            <th>PROBLEMS IDENTIFIED AND ACTION TAKEN BY THE MIDWIFE (INDICATE DATE)</th>
          </tr>
          <tr><td style="height:170px;">&nbsp;</td></tr>
          <tr class="bg-light text-center">
            <th>(FINDINGS, ACTIONS TAKEN, AND INSTRUCTIONS FROM REFERRAL CENTER)</th>
          </tr>
          <tr><td style="height:170px;">&nbsp;</td></tr>
        </table>
      </div>

      <!-- PANEL 4: POSTPARTUM CARE & FAMILY PLANNING -->
      <div class="col-4">
        <div class="d-flex justify-content-between"><strong>PANEL 4</strong><strong>POSTPARTUM CARE</strong></div>
        <table class="form-grid text-center mt-1 mb-2" style="font-size:8.8px;">
          <tr class="bg-light">
            <th class="text-start">TIMING OF POST-PARTUM VISIT</th>
            <th>24 HRS</th><th>1 WK</th><th>2-4 WKS</th><th>CLINIC VISIT</th>
          </tr>
          <tr><td class="text-start">DATE OF VISIT</td><td></td><td></td><td></td><td></td></tr>
          <tr><td class="text-start">EXCLUSIVE BREASTFEEDING (Y/N)</td><td></td><td></td><td></td><td></td></tr>
          <tr><td class="text-start">INTENDS TO USE FP (Y/N)</td><td></td><td></td><td></td><td></td></tr>
          <tr><td class="text-start">FEVER &gt; 38°C (Y/N)</td><td class="shaded-dark"></td><td class="shaded-dark"></td><td class="shaded-dark"></td><td class="shaded-dark"></td></tr>
          <tr><td class="text-start">FOUL SMELLING VAGINAL DISCHARGE (Y/N)</td><td class="shaded-dark"></td><td class="shaded-dark"></td><td class="shaded-dark"></td><td class="shaded-dark"></td></tr>
          <tr><td class="text-start">EXCESSIVE BLEEDING (Y/N)</td><td class="shaded-dark"></td><td class="shaded-dark"></td><td class="shaded-dark"></td><td class="shaded-dark"></td></tr>
          <tr><td class="text-start">PALLOR (Y/N)</td><td class="shaded-risk"></td><td class="shaded-risk"></td><td class="shaded-risk"></td><td class="shaded-risk"></td></tr>
          <tr><td class="text-start">CORD OK? (Y/N)</td><td class="shaded-dark"></td><td class="shaded-dark"></td><td class="shaded-dark"></td><td class="shaded-dark"></td></tr>
        </table>

        <div class="text-center fw-bold mb-1" style="font-size:9.5px;">FAMILY PLANNING</div>
        <table class="form-grid text-center" style="font-size:8.5px;">
          <tr class="bg-light">
            <th>DATE OF FOLLOW UP</th><th>DATE OF VISIT</th><th>METHOD</th><th>QTY GIVEN</th><th>REMARKS</th>
          </tr>
          <?php for ($i = 0; $i < 6; $i++): ?>
            <tr><td style="height:22px;"></td><td></td><td></td><td></td><td></td></tr>
          <?php endfor; ?>
        </table>
      </div>

      <!-- PANEL 1: HOME BASED MOTHER'S RECORD COVER & OBSTETRICAL HISTORY -->
      <div class="col-4">
        <div class="d-flex justify-content-between"><strong>PANEL 1</strong><strong>HOME BASED MOTHER'S RECORD</strong></div>
        <div class="border border-dark p-2 mt-1" style="font-size:9.2px;">
          <div class="text-center fw-bold mb-2">ALWAYS BRING THIS CARD WHEN YOU VISIT A HEALTH FACILITY</div>
          <div><strong>NAME:</strong> _______________________________</div>
          <div class="mt-1"><strong>BLOOD TYPE:</strong> ______ <strong>FAMILY SERIAL NO.:</strong> ________</div>
          <div class="mt-1"><strong>ADDRESS:</strong> ____________________________</div>
          <div class="mt-1"><strong>DATE TETANUS TOXOID GIVEN:</strong> [1]____ [2]____ [3]____ [4]____ [5]____</div>
          <div class="mt-1"><strong>AGE:</strong> ____ yr. (<span class="chk-box"></span>Below 18 | <span class="chk-box"></span>18-34 | <span class="chk-box"></span>35+)</div>
          <div class="mt-1"><strong>HEIGHT:</strong> ____ cm (<span class="chk-box"></span>Below 145 cm | <span class="chk-box"></span>145 cm &amp; above)</div>

          <div class="fw-bold text-decoration-underline mt-2 mb-1">OBSTETRICAL HISTORY:</div>
          <div>NUMBER OF PREVIOUS PREGNANCIES: [0] [1] [2] [3] [4+]</div>
          <table class="form-grid mt-1" style="font-size:8.5px;">
            <tr><td>PREVIOUS CAESAREAN SECTION</td><td>NO</td><td class="shaded-dark">YES (H)</td></tr>
            <tr><td>3 CONSECUTIVE MISCARRIAGES</td><td>NO</td><td class="shaded-dark">YES (H)</td></tr>
            <tr><td>STILLBIRTH</td><td>NO</td><td class="shaded-dark">YES (H)</td></tr>
            <tr><td>POST-PARTUM HEMORRHAGE</td><td>NO</td><td class="shaded-dark">YES (H)</td></tr>
          </table>

          <div class="fw-bold text-decoration-underline mt-2 mb-1">PRESENT HEALTH PROBLEMS:</div>
          <table class="form-grid" style="font-size:8.5px;">
            <tr><td>TUBERCULOSIS (14 DAYS+ COUGH)</td><td>NO</td><td class="shaded-dark">YES (H)</td></tr>
            <tr><td>HEART DISEASE</td><td>NO</td><td class="shaded-dark">YES (H)</td></tr>
            <tr><td>DIABETES</td><td>NO</td><td class="shaded-dark">YES (H)</td></tr>
            <tr><td>BRONCHIAL ASTHMA</td><td>NO</td><td class="shaded-dark">YES (H)</td></tr>
            <tr><td>GOITER</td><td>NO</td><td class="shaded-dark">YES (H)</td></tr>
            <tr><td>IODINE SUPPLEMENTATION IN HIGH RISK AREAS</td><td>NO</td><td class="shaded-risk">YES</td></tr>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<?php elseif ($selectedForm === 'maternal_record'): ?>
<!-- ============================================================================
     FORM 4: MATERNAL RECORD FORM (Local Health Unit Two-Column Ledger)
     Exact layout from `maternal record.jpg`
     ============================================================================ -->
<div class="paper-sheet">
  <div class="paper-content">
    <h5 class="text-center fw-bold text-uppercase mb-3" style="letter-spacing:1px;">MATERNAL RECORD</h5>

    <div class="row mb-3" style="font-size:12px;line-height:1.9;">
      <div class="col-7">
        <div><strong>NAME :</strong> <span class="fill-line" style="min-width:300px;"></span></div>
        <div><strong>BIRTH DATE :</strong> <span class="fill-line" style="min-width:260px;"></span></div>
        <div><strong>AGE :</strong> <span class="fill-line" style="min-width:120px;"></span></div>
        <div><strong>ADDRESS :</strong> <span class="fill-line" style="min-width:280px;"></span></div>
        <div><strong>LMP :</strong> <span class="fill-line" style="min-width:200px;"></span></div>
        <div><strong>EDC :</strong> <span class="fill-line" style="min-width:200px;"></span></div>
        <div><strong>G / P :</strong> <span class="fill-line" style="min-width:200px;"></span></div>
      </div>
      <div class="col-5">
        <div><strong>HUSBAND'S NAME :</strong> <span class="fill-line" style="min-width:190px;"></span></div>
        <div><strong>PHILHEALTH NO. :</strong> <span class="fill-line" style="min-width:195px;"></span></div>
        <div><strong>CP NO. :</strong> <span class="fill-line" style="min-width:240px;"></span></div>
      </div>
    </div>

    <table class="form-grid" style="font-size:11.5px;">
      <thead>
        <tr class="bg-light text-center">
          <th style="width:16%;">DATE</th>
          <th style="width:42%;">FINDINGS / DIAGNOSIS</th>
          <th style="width:42%;">HEALTH SERVICES GIVEN / TREATMENT</th>
        </tr>
      </thead>
      <tbody>
        <?php for ($r = 0; $r < 18; $r++): ?>
        <tr>
          <td style="height:32px;">&nbsp;</td>
          <td>&nbsp;</td>
          <td>&nbsp;</td>
        </tr>
        <?php endfor; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($selectedForm === 'ntp_form_2a'): ?>
<!-- ============================================================================
     FORM 5: FORM 2a. NTP LABORATORY REQUEST AND RESULT FORM
     Exact layout from `ntp laboratory request.jpg`
     ============================================================================ -->
<div class="paper-sheet">
  <div class="paper-content">
    <h5 class="text-center fw-bold mb-2" style="font-size:14px;">Form 2a. NTP Laboratory Request and Result Form</h5>
    <div class="fw-bold fst-italic mb-1" style="font-size:10px;">To be filled-out by Health Worker</div>

    <table class="form-grid mb-2" style="font-size:10.5px;">
      <tr>
        <td style="width:55%;">
          <div class="mb-1"><strong>Name of Requesting Facility:</strong> Barangay Tubtubon Health Center</div>
          <div class="mb-1"><strong>Facility Contact Information:</strong> Sibulan, Negros Oriental</div>
          <div class="mb-1"><strong>Name of Patient:</strong> ____________________________________</div>
          <div><strong>Address:</strong> ___________________________________________</div>
        </td>
        <td style="width:45%;">
          <div class="mb-1"><strong>Date of Request:</strong> _______________________</div>
          <div class="mb-1"><strong>Requesting Physician:</strong> __________________</div>
          <div class="mb-1"><strong>Age:</strong> ________ &nbsp;&nbsp; <strong>Sex:</strong> [ &nbsp; ] M &nbsp;&nbsp; [ &nbsp; ] F</div>
          <div><strong>Patient's Contact No.:</strong> __________________</div>
        </td>
      </tr>
    </table>

    <div class="row g-2 mb-2" style="font-size:10px;">
      <div class="col-3">
        <strong>Reason for Examination:</strong><br>
        [ &nbsp; ] Diagnosis<br>
        [ &nbsp; ] Baseline<br>
        [ &nbsp; ] Follow-up
      </div>
      <div class="col-5">
        <strong>History of Treatment:</strong> [ &nbsp; ] New &nbsp;&nbsp; [ &nbsp; ] Retreatment<br><br>
        <strong>TB Case No.:</strong> ________________________<br>
        <strong>For PMDT, month of treatment:</strong> ________
      </div>
      <div class="col-4">
        <strong>If for Diagnosis or Baseline, Registration Group:</strong><br>
        [ &nbsp; ] New &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; [ &nbsp; ] TAF<br>
        [ &nbsp; ] Relapse &nbsp;&nbsp; [ &nbsp; ] PTOU<br>
        [ &nbsp; ] TALF
      </div>
    </div>

    <div class="d-flex justify-content-between mb-2" style="font-size:10px;">
      <div>
        <strong>Specimen Type:</strong> [ &nbsp; ] Sputum &nbsp;&nbsp; [ &nbsp; ] Other (specify): _________________
      </div>
      <div>
        <strong>Repeat Collection?</strong> [ &nbsp; ] No &nbsp;&nbsp; [ &nbsp; ] Yes &nbsp; Reason: _________________
      </div>
    </div>

    <div class="row g-2 align-items-center mb-2" style="font-size:10px;">
      <div class="col-7">
        <div class="d-flex align-items-center gap-2">
          <strong>Date Specimen Collected:</strong>
          <table class="form-grid text-center" style="width:280px;">
            <tr class="bg-light"><th>Specimen</th><th>Date of Collection</th></tr>
            <tr><td>1</td><td style="height:20px;"></td></tr>
            <tr><td>2</td><td style="height:20px;"></td></tr>
          </table>
        </div>
      </div>
      <div class="col-5">
        <div class="border border-dark p-2">
          <strong>If for Xpert, DST or LPA:</strong><br>
          Presumptive DS-TB [ &nbsp; ]<br>
          Presumptive DR-TB [ &nbsp; ]
        </div>
      </div>
    </div>

    <div class="mb-2" style="font-size:10px;">
      <div class="d-flex align-items-center gap-2">
        <strong>Test Requested:</strong>
        <table class="form-grid text-center">
          <tr>
            <td>[ &nbsp; ] DSSM</td>
            <td>[ &nbsp; ] Xpert MTB/RIF</td>
            <td>[ &nbsp; ] Culture</td>
            <td>[ &nbsp; ] DST</td>
            <td>[ &nbsp; ] LPA</td>
          </tr>
        </table>
      </div>
      <div class="d-flex justify-content-between mt-1 ps-5">
        <span><em>If for Xpert, DSSM Result:</em> ___________________</span>
        <span><em>If for DST, Xpert Result:</em> ___________________ &nbsp; <em>HIV Result:</em> ___________</span>
      </div>
    </div>

    <div class="d-flex justify-content-between mb-2 pt-2" style="font-size:10px;">
      <div><strong>Prepared by:</strong> ___________________________________<br><small class="ps-5">Signature over Printed Name</small></div>
      <div><strong>Position:</strong> ___________________________________</div>
    </div>

    <div class="border-top border-bottom border-dark border-2 py-1 text-center fw-bold fst-italic my-2" style="font-size:10px;">
      Portion below to be filled-out by Medical Technologist / Microscopist / Xpert Technician
    </div>

    <div class="d-flex justify-content-between mb-2" style="font-size:10px;">
      <div><strong>Laboratory Serial Number:</strong> ___________________________</div>
      <div>
        <strong>Date Received:</strong> ___________________________<br>
        <strong>Date Examined:</strong> ___________________________
      </div>
    </div>

    <table class="form-grid text-center mb-1" style="font-size:10.5px;">
      <thead>
        <tr class="bg-light">
          <th rowspan="3" style="width:26%;">FINDINGS</th>
          <th colspan="3">LABORATORY TEST</th>
        </tr>
        <tr class="bg-light">
          <th colspan="2">Smear Microscopy</th>
          <th rowspan="2" style="width:36%;">Xpert MTB/RIF</th>
        </tr>
        <tr class="bg-light">
          <th style="width:19%;">1</th>
          <th style="width:19%;">2*</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td class="text-start fw-bold">Specimen</td>
          <td style="height:24px;"></td><td></td><td></td>
        </tr>
        <tr>
          <td class="text-start fw-bold">Visual Appearance**</td>
          <td style="height:28px;"></td><td></td><td></td>
        </tr>
        <tr>
          <td class="text-start fw-bold">Reading</td>
          <td style="height:28px;"></td><td></td><td></td>
        </tr>
        <tr>
          <td class="text-start fw-bold">Laboratory Diagnosis</td>
          <td colspan="2" style="height:28px;"></td><td></td>
        </tr>
      </tbody>
    </table>
    <div class="d-flex justify-content-between mb-3" style="font-size:8.5px;font-style:italic;">
      <span>*Specimen 2 is not applicable for follow-up</span>
      <span>**Muco-purulent, blood-stained, salivary, etc.</span>
    </div>

    <div class="d-flex justify-content-between mt-3" style="font-size:10px;">
      <div><strong>Performed by:</strong> ___________________________________<br><small class="ps-5">Signature over Printed Name</small></div>
      <div><strong>Date of Release:</strong> ___________________________</div>
    </div>
    <div class="mt-2 fst-italic" style="font-size:9.5px;">
      <strong>A separate result form for TB Culture, DST and LPA will be issued.</strong>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ============================================================================
     FORM 6: GENERAL CONSULTATION & INDIVIDUAL TREATMENT RECORD (ITR STANDBY SLIP)
     ============================================================================ -->
<div class="paper-sheet">
  <div class="paper-content">
    <div class="text-center border-bottom border-dark pb-2 mb-3">
      <div class="fw-bold">REPUBLIC OF THE PHILIPPINES • DEPARTMENT OF HEALTH</div>
      <div class="fw-bold" style="font-size:13px;">BARANGAY TUBTUBON HEALTH CENTER — SIBULAN, NEGROS ORIENTAL</div>
      <div class="fw-bold text-uppercase mt-1" style="font-size:14px;">INDIVIDUAL TREATMENT RECORD (ITR) / GENERAL CONSULTATION SLIP</div>
    </div>

    <table class="form-grid mb-3" style="font-size:11px;">
      <tr>
        <td style="width:60%;"><strong>Patient Name (Last, First, Middle):</strong></td>
        <td style="width:40%;"><strong>Unique Patient ID (UPI):</strong> TUB-______-_______</td>
      </tr>
      <tr>
        <td><strong>Birthdate:</strong> ____/____/______ &nbsp;&nbsp; <strong>Age:</strong> ______ &nbsp;&nbsp; <strong>Sex:</strong> [ ] M [ ] F</td>
        <td><strong>Contact No.:</strong></td>
      </tr>
      <tr>
        <td><strong>Purok / Address:</strong> Brgy. Tubtubon, Sibulan</td>
        <td><strong>PhilHealth No.:</strong></td>
      </tr>
    </table>

    <div class="section-bar mb-1">I. VITAL SIGNS &amp; ANTHROPOMETRICS</div>
    <table class="form-grid mb-3" style="font-size:11px;">
      <tr>
        <td><strong>Date of Visit:</strong></td>
        <td><strong>Blood Pressure:</strong> _____/_____ mmHg</td>
        <td><strong>Temperature:</strong> ______ °C</td>
      </tr>
      <tr>
        <td><strong>Weight:</strong> ______ kg &nbsp; <strong>Height:</strong> ______ cm</td>
        <td><strong>Pulse Rate:</strong> ______ bpm</td>
        <td><strong>Respiratory Rate:</strong> ______ cpm</td>
      </tr>
    </table>

    <div class="section-bar mb-1">II. CHIEF COMPLAINT &amp; HISTORY OF PRESENT ILLNESS</div>
    <div class="border border-dark p-2 mb-3" style="height:100px;"></div>

    <div class="section-bar mb-1">III. PHYSICAL EXAMINATION / CLINICAL FINDINGS / ASSESSMENT</div>
    <div class="border border-dark p-2 mb-3" style="height:120px;"></div>

    <div class="section-bar mb-1">IV. TREATMENT / MEDICINES DISPENSED / PLAN &amp; FOLLOW-UP</div>
    <div class="border border-dark p-2 mb-4" style="height:120px;"></div>

    <div class="d-flex justify-content-between pt-3" style="font-size:11px;">
      <span class="border-top border-dark px-4">Encoded / Assisted By (BHW)</span>
      <span class="border-top border-dark px-4">Attending Nurse / Midwife / Physician Signature</span>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

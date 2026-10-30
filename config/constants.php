<?php
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Access denied.');
}

// ============================================================================
// config/constants.php — Domain Constants & Business Rule Enumerations
// Strictly aligned with Phase 6 Sec. 4.4 (BR-01 to BR-15) & Phase 8 Sec. 50/53
// ============================================================================

define('APP_ROOT_PATH', dirname(__DIR__));
define('STORAGE_UPLOADS_PATH', APP_ROOT_PATH . '/storage/uploads');
define('STORAGE_BACKUPS_PATH', APP_ROOT_PATH . '/storage/backups');

// 5 System Roles (Phase 6 Sec. 4.5 #1 & Phase 8 Sec. 53)
define('ROLE_ADMIN',     'Administrator');
define('ROLE_NURSE',     'Nurse/Midwife');
define('ROLE_BHW',       'Barangay Health Worker');
define('ROLE_INVENTORY', 'Inventory/Admin Personnel');
define('ROLE_RESIDENT',  'Resident');

define('ALL_ROLES', [
    ROLE_ADMIN,
    ROLE_NURSE,
    ROLE_BHW,
    ROLE_INVENTORY,
    ROLE_RESIDENT,
]);

// BR-02: The 6 Official Health Center Programs
define('PROGRAM_TYPES', [
    'Family Planning',
    'Maternal/Prenatal',
    'Child Health-ECCD',
    'Immunization',
    'Laboratory Referral',
    'General Consultation',
]);

// BR-03: 4-State Physical + Digital Document Lifecycle ("Folder Situation")
define('DOCUMENT_STATES', [
    'Filed',
    'Pending Digitization',
    'Scanned/Attached',
    'Unavailable/Archived',
]);

// BR-08: 4 Appointment Lifecycle Statuses
define('APPOINTMENT_STATUSES', [
    'Scheduled',
    'Completed',
    'Cancelled',
    'No-Show',
]);

// FR-15 / BR-04: Inventory Categories & Transaction Types
define('INVENTORY_CATEGORIES', [
    'Medicine',
    'Supply',
    'Equipment',
]);

define('STOCK_TRANSACTION_TYPES', [
    'Stock-In',
    'Stock-Out',
    'Disposal/Expired-Removal',
]);

// BR-09: Approved Privacy-Safe SMS Templates (No Sensitive Clinical Diagnoses)
define('SMS_TEMPLATES', [
    'appointment_reminder' => [
        'label' => 'Appointment Reminder',
        'body'  => 'Reminder: [patient_name] has a scheduled [program] visit on [date] at Tubtubon Health Center. Please come on time.',
    ],
    'immunization_due' => [
        'label' => 'Immunization Schedule Reminder',
        'body'  => 'Reminder: An immunization visit for [patient_name] is scheduled on [date] at Tubtubon Health Center. Please bring the ECCD/Immunization Card.',
    ],
    'prenatal_followup' => [
        'label' => 'Prenatal Follow-Up Nudge',
        'body'  => 'Health reminder from Barangay Tubtubon Health Center: Your scheduled prenatal check-up is on [date]. Please bring your Mother\'s Record.',
    ],
    'appointment_cancelled' => [
        'label' => 'Appointment Reschedule / Notice',
        'body'  => 'Notice from Barangay Tubtubon Health Center: Your scheduled visit on [date] has been updated. Please coordinate with your BHW.',
    ],
]);

// FR-12: Upload constraints (Max 5 MB; PDF, JPG, PNG, WEBP)
define('UPLOAD_MAX_BYTES', 5 * 1024 * 1024);
define('UPLOAD_ALLOWED_MIMES', [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
]);

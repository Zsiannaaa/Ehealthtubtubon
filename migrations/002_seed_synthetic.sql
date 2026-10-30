-- ============================================================================
-- Tubtubon eHealth: A Web-Based Health Information and Service System
-- Barangay Tubtubon Health Center, Sibulan, Negros Oriental
--
-- Migration: 002_seed_synthetic.sql
-- Purpose  : Seeds watermarked SYNTHETIC DEMO DATA matching mock-data.js
--            Default demo password for all accounts: Tubtubon2026!
-- ============================================================================

USE `tubtubon_ehealth`;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE `audit_logs`;
TRUNCATE TABLE `consent_records`;
TRUNCATE TABLE `sms_logs`;
TRUNCATE TABLE `ocr_ingestion_drafts`;
TRUNCATE TABLE `stock_transactions`;
TRUNCATE TABLE `stock_batches`;
TRUNCATE TABLE `inventory_items`;
TRUNCATE TABLE `documents`;
TRUNCATE TABLE `appointments`;
TRUNCATE TABLE `service_records`;
TRUNCATE TABLE `patients`;
TRUNCATE TABLE `users`;
TRUNCATE TABLE `roles`;

-- ============================================================================
-- 1. roles (5 defined roles; 4 active in core clinic flows, Resident deferred)
-- ============================================================================
INSERT INTO `roles` (`role_id`, `role_name`, `description`) VALUES
(1, 'Administrator', 'System maintainer: manages user accounts, roles, audit logs, and database/file backups under least-privilege control.'),
(2, 'Nurse/Midwife', 'Primary clinical stakeholder: creates and verifies patient encounters, OCR drafts, document states, and schedules.'),
(3, 'Barangay Health Worker', 'Frontline intake and outreach staff: registers patients, encodes records, assists scheduling, and handles paper-fallback.'),
(4, 'Inventory/Admin Personnel', 'Bodega/administrative staff: manages medical supplies, medicines, equipment, stock batches, FEFO transactions, and reports.'),
(5, 'Resident', 'Deferred portal role: controlled self-access strictly limited to the resident''s own patient record and schedule.');

-- ============================================================================
-- 2. users (Demo accounts — Password for all: Tubtubon2026!)
-- ============================================================================
INSERT INTO `users` (`user_id`, `username`, `password_hash`, `full_name`, `role_id`, `patient_id`, `status`, `created_at`, `last_login_at`) VALUES
(1, 'admin', '$2y$10$lbyPWsfXPuA8j4S9KviQuug6VDOAvzLcnELCWQOUXLNwT.3E7ljdG', 'Admin Cruz', 1, NULL, 'Active', '2026-01-01 08:00:00', '2026-10-18 07:30:00'),
(2, 'nurse.reyes', '$2y$10$lbyPWsfXPuA8j4S9KviQuug6VDOAvzLcnELCWQOUXLNwT.3E7ljdG', 'Nurse Reyes, RNM', 2, NULL, 'Active', '2026-01-01 08:05:00', '2026-10-18 08:00:00'),
(3, 'bhw.lim', '$2y$10$lbyPWsfXPuA8j4S9KviQuug6VDOAvzLcnELCWQOUXLNwT.3E7ljdG', 'BHW Lim', 3, NULL, 'Active', '2026-01-01 08:10:00', '2026-10-18 08:15:00'),
(4, 'bhw.cruz', '$2y$10$lbyPWsfXPuA8j4S9KviQuug6VDOAvzLcnELCWQOUXLNwT.3E7ljdG', 'BHW Cruz', 3, NULL, 'Active', '2026-01-01 08:12:00', '2026-10-17 09:00:00'),
(5, 'inventory.cruz', '$2y$10$lbyPWsfXPuA8j4S9KviQuug6VDOAvzLcnELCWQOUXLNwT.3E7ljdG', 'R. Cruz (Inventory)', 4, NULL, 'Active', '2026-01-01 08:15:00', '2026-10-18 07:45:00'),
(6, 'resident.juana', '$2y$10$lbyPWsfXPuA8j4S9KviQuug6VDOAvzLcnELCWQOUXLNwT.3E7ljdG', 'Juana Dela Cruz', 5, 1, 'Active', '2026-01-10 09:00:00', '2026-10-15 10:00:00'),
(7, 'bhw.inactive', '$2y$10$lbyPWsfXPuA8j4S9KviQuug6VDOAvzLcnELCWQOUXLNwT.3E7ljdG', 'BHW Santos (Deactivated Demo)', 3, NULL, 'Deactivated', '2026-01-05 08:00:00', '2026-05-10 16:00:00');

-- ============================================================================
-- 3. patients (12 Synthetic Filipino Residents from mock-data.js)
-- ============================================================================
INSERT INTO `patients` (`patient_id`, `upi`, `last_name`, `first_name`, `middle_name`, `sex`, `birthdate`, `purok_address`, `contact_no`, `guardian_name`, `registered_by`, `date_registered`, `status`) VALUES
(1,  'TUB-2026-00001', 'DELA CRUZ',  'Juana',     'Santos',     'Female', '1990-03-15', 'Purok 1 - Sampaguita, Brgy. Tubtubon, Sibulan',  '09171234001', 'Pedro Dela Cruz',  2, '2026-01-10 09:00:00', 'Active'),
(2,  'TUB-2026-00002', 'REYES',      'Maria',     'Lim',        'Female', '1985-07-22', 'Purok 2 - Rosal, Brgy. Tubtubon, Sibulan',       '09181234002', 'Jose Reyes',       3, '2026-01-12 10:15:00', 'Active'),
(3,  'TUB-2026-00003', 'SANTOS',     'Ana',       'Garcia',     'Female', '1995-11-08', 'Purok 3 - Waling-waling, Brgy. Tubtubon, Sibulan','09271234003', 'Roberto Santos',   3, '2026-02-01 08:30:00', 'Active'),
(4,  'TUB-2026-00004', 'GARCIA',     'Lourdes',   'Cruz',       'Female', '1978-05-30', 'Purok 4 - Ilang-ilang, Brgy. Tubtubon, Sibulan', '09321234004', NULL,               2, '2026-02-14 11:00:00', 'Active'),
(5,  'TUB-2026-00005', 'MARTINEZ',   'Rosario',   'Bautista',   'Female', '2000-01-10', 'Purok 1 - Sampaguita, Brgy. Tubtubon, Sibulan',  '09191234005', 'Elena Martinez',   3, '2026-03-03 09:20:00', 'Active'),
(6,  'TUB-2026-00006', 'BAUTISTA',   'Carmelita', 'Ramos',      'Female', '1992-09-25', 'Purok 2 - Rosal, Brgy. Tubtubon, Sibulan',       '09231234006', NULL,               3, '2026-03-19 14:10:00', 'Active'),
(7,  'TUB-2026-00007', 'FLORES',     'Teresita',  'Aquino',     'Female', '1988-12-03', 'Purok 5 - Dama de Noche, Brgy. Tubtubon, Sibulan','09451234007', NULL,               2, '2026-04-05 08:45:00', 'Active'),
(8,  'TUB-2026-00008', 'RAMOS',      'Natividad', 'Villanueva', 'Female', '1975-04-18', 'Purok 3 - Waling-waling, Brgy. Tubtubon, Sibulan','09561234008', 'Antonio Ramos',    3, '2026-04-22 10:30:00', 'Active'),
(9,  'TUB-2026-00009', 'VILLANUEVA', 'Corazon',   'Mendoza',    'Female', '1998-06-14', 'Purok 4 - Ilang-ilang, Brgy. Tubtubon, Sibulan', '09671234009', NULL,               4, '2026-05-11 13:15:00', 'Active'),
(10, 'TUB-2026-00010', 'MENDOZA',    'Ligaya',    'Torres',     'Male',   '2020-08-05', 'Purok 1 - Sampaguita, Brgy. Tubtubon, Sibulan',  '09781234010', 'Fe Mendoza',       4, '2026-06-01 08:00:00', 'Active'),
(11, 'TUB-2026-00011', 'AQUINO',     'Catalina',  'Hernandez',  'Female', '1983-02-28', 'Purok 5 - Dama de Noche, Brgy. Tubtubon, Sibulan','09891234011', NULL,               2, '2026-07-08 09:40:00', 'Active'),
(12, 'TUB-2026-00017', 'HERNANDEZ',  'Salvacion', 'Pascual',    'Female', '1970-10-12', 'Purok 2 - Rosal, Brgy. Tubtubon, Sibulan',       '09901234017', NULL,               2, '2026-08-19 15:00:00', 'Active');

-- ============================================================================
-- 4. service_records (8 Synthetic Clinical Encounters across all 6 programs)
-- ============================================================================
INSERT INTO `service_records` (`record_id`, `patient_id`, `program_type`, `form_reference_no`, `service_date`, `findings_notes`, `recorded_by`, `verified_by`, `created_at`) VALUES
(1, 1,  'Maternal/Prenatal',    'MAT-2026-0115',  '2026-06-15', 'AOG 28 weeks. BP 110/70. FHT 140 bpm. Weight 58kg. No complaints. [SYNTHETIC DEMO]', 2, 2, '2026-06-15 09:30:00'),
(2, 1,  'Laboratory Referral',  'LAB-2026-0220',  '2026-06-20', 'CBC, Urinalysis ordered. Referral to district hospital. [SYNTHETIC DEMO]', 3, 2, '2026-06-20 10:00:00'),
(3, 1,  'Family Planning',      'FP-2026-0110',   '2026-01-10', 'FP counseling done. Method: DMPA injectable. Next visit in 3 months. [SYNTHETIC DEMO]', 2, 2, '2026-01-10 14:00:00'),
(4, 2,  'General Consultation', 'GC-2026-0701',   '2026-07-01', 'C/C: Cough and colds for 3 days. Prescribed Amoxicillin 500mg TID x 5 days. [SYNTHETIC DEMO]', 3, 2, '2026-07-01 11:00:00'),
(5, 10, 'Immunization',         'IMM-2026-0810',  '2026-08-10', 'DPT-HepB-Hib 3rd dose given. No adverse reaction. Weight 10.2kg. [SYNTHETIC DEMO]', 4, 2, '2026-08-10 08:30:00'),
(6, 10, 'Child Health-ECCD',    'ECCD-2026-0815', '2026-08-15', 'Growth monitoring: Normal. Developmental milestone assessment: passed. [SYNTHETIC DEMO]', 4, 2, '2026-08-15 09:00:00'),
(7, 3,  'Maternal/Prenatal',    'MAT-2026-0901',  '2026-09-01', 'AOG 32 weeks. BP 120/80. FHT 138 bpm. Edema noted on feet. [SYNTHETIC DEMO]', 2, 2, '2026-09-01 10:00:00'),
(8, 12, 'Family Planning',      'FP-2026-0910',   '2026-09-10', 'FP follow-up. Method: IUD. No complaints. Scheduled next check in 1 year. [SYNTHETIC DEMO]', 2, 2, '2026-09-10 13:00:00');

-- ============================================================================
-- 5. appointments (10 Synthetic Appointments covering all 4 statuses)
-- ============================================================================
INSERT INTO `appointments` (`appointment_id`, `patient_id`, `program_type`, `scheduled_date`, `scheduled_time`, `status`, `reminder_sent`, `created_by`, `created_at`) VALUES
(1,  1,  'Maternal/Prenatal',    '2026-10-19', '08:00:00', 'Scheduled', 0, 2, '2026-09-25 09:00:00'),
(2,  2,  'General Consultation', '2026-10-19', '09:00:00', 'Scheduled', 1, 3, '2026-09-26 10:00:00'),
(3,  3,  'Maternal/Prenatal',    '2026-10-22', '08:30:00', 'Scheduled', 0, 2, '2026-09-28 11:00:00'),
(4,  10, 'Immunization',         '2026-10-25', '07:30:00', 'Scheduled', 1, 4, '2026-10-01 08:30:00'),
(5,  1,  'Laboratory Referral',  '2026-09-15', '10:00:00', 'Completed', 1, 2, '2026-09-10 09:00:00'),
(6,  4,  'Family Planning',      '2026-09-20', '14:00:00', 'No-Show',   1, 2, '2026-09-12 14:00:00'),
(7,  5,  'General Consultation', '2026-09-25', '09:30:00', 'Cancelled', 0, 3, '2026-09-18 10:00:00'),
(8,  12, 'Family Planning',      '2026-10-28', '13:00:00', 'Scheduled', 0, 2, '2026-10-02 13:00:00'),
(9,  7,  'Maternal/Prenatal',    '2026-10-30', '08:00:00', 'Scheduled', 0, 2, '2026-10-05 08:00:00'),
(10, 8,  'General Consultation', '2026-10-19', '10:30:00', 'Scheduled', 0, 3, '2026-10-10 10:30:00');

-- ============================================================================
-- 6. documents (7 Synthetic Document Registry entries across all 4 states)
-- ============================================================================
INSERT INTO `documents` (`document_id`, `patient_id`, `record_id`, `document_type`, `title`, `status`, `cabinet_location`, `box_location`, `folder_location`, `file_path`, `filed_by`, `filed_at`, `digitized_at`, `archived_at`) VALUES
(1, 1,  1, 'Maternal/Prenatal',    'Maternal Form 1 - Dela Cruz, Juana',       'Scanned/Attached',     'Cabinet A', 'Box 1', 'Folder MAT-01',  'a1b2c3d4_mat_001.pdf', 2, '2026-06-15 09:30:00', '2026-06-16 14:00:00', NULL),
(2, 1,  3, 'Family Planning',      'FP Form 1 - Dela Cruz, Juana',             'Filed',                'Cabinet A', 'Box 2', 'Folder FP-03',   NULL,                   2, '2026-01-10 14:00:00', NULL,                  NULL),
(3, 10, 5, 'Immunization',         'Immunization Card - Mendoza, Ligaya',      'Pending Digitization', 'Cabinet B', 'Box 3', 'Folder IMM-05',  NULL,                   4, '2026-08-10 08:30:00', NULL,                  NULL),
(4, 10, 6, 'Child Health-ECCD',    'ECCD Form - Mendoza, Ligaya',              'Filed',                'Cabinet B', 'Box 3', 'Folder ECCD-02', NULL,                   4, '2026-08-15 09:00:00', NULL,                  NULL),
(5, 1,  2, 'Laboratory Referral',  'Lab Referral Slip - Dela Cruz, Juana',     'Unavailable/Archived', 'Cabinet C', 'Box 1', 'Folder LAB-01',  NULL,                   3, '2026-06-20 10:00:00', NULL,                  '2026-07-01 08:00:00'),
(6, 12, 8, 'Family Planning',      'FP Form 1 - Hernandez, Salvacion',         'Scanned/Attached',     'Cabinet A', 'Box 2', 'Folder FP-03',   'e5f6g7h8_fp_017.pdf',  2, '2026-09-10 13:00:00', '2026-09-11 09:00:00', NULL),
(7, 2,  4, 'General Consultation', 'General Consultation - Reyes, Maria',      'Pending Digitization', 'Cabinet C', 'Box 2', 'Folder GC-04',   NULL,                   3, '2026-07-01 11:00:00', NULL,                  NULL);

-- ============================================================================
-- 7. inventory_items (8 Catalog Items with Derived Quantities)
-- ============================================================================
INSERT INTO `inventory_items` (`item_id`, `item_name`, `category`, `unit`, `minimum_stock_threshold`, `current_quantity`, `status`) VALUES
(1, 'Amoxicillin 500mg',            'Medicine',  'capsule', 100, 200, 'Available'),
(2, 'Paracetamol 500mg',            'Medicine',  'tablet',  200, 350, 'Available'),
(3, 'ORS (Oral Rehydration Salts)', 'Medicine',  'sachet',   50,  25, 'Low Stock'),
(4, 'Iron with Folic Acid',         'Medicine',  'tablet',  150, 240, 'Available'),
(5, 'Syringe 5mL',                  'Supply',    'pc',       30,  20, 'Low Stock'),
(6, 'Cotrimoxazole 480mg',          'Medicine',  'tablet',   80, 150, 'Available'),
(7, 'Vitamin A 200,000 IU',         'Medicine',  'capsule',  60,  90, 'Available'),
(8, 'Digital BP Apparatus',         'Equipment', 'unit',      1,   2, 'Available');

-- ============================================================================
-- 8. stock_batches (11 Batches including FEFO, near-expiry, and expired demo)
-- ============================================================================
INSERT INTO `stock_batches` (`batch_id`, `item_id`, `batch_no`, `quantity_received`, `quantity_remaining`, `expiry_date`, `date_received`, `status`) VALUES
(1,  1, 'AMX-2025-001', 200,  80, '2026-11-30', '2025-11-01', 'Available'),
(2,  1, 'AMX-2026-002', 120, 120, '2027-06-30', '2026-01-15', 'Available'),
(3,  2, 'PAR-2025-001', 350, 350, '2027-03-31', '2025-09-01', 'Available'),
(4,  3, 'ORS-2026-001',  50,  25, '2027-12-31', '2026-03-01', 'Available'),
(5,  4, 'IFE-2025-001',  50,  40, '2026-10-31', '2025-10-15', 'Available'),
(6,  4, 'IFE-2026-002', 200, 200, '2027-10-31', '2026-04-01', 'Available'),
(7,  5, 'SYR-2026-001',  50,  20, '2028-01-01', '2026-02-01', 'Available'),
(8,  6, 'CTX-2026-001', 150, 150, '2027-08-31', '2026-05-01', 'Available'),
(9,  7, 'VTA-2026-001',  90,  90, '2026-11-01', '2026-06-01', 'Available'),
(10, 8, 'EQP-2026-001',   2,   2, NULL,         '2026-05-15', 'Available'),
(11, 2, 'PAR-2024-EXP',  30,  30, '2026-08-01', '2024-08-01', 'Expired');

-- ============================================================================
-- 9. stock_transactions (Immutable Ledger matching current_quantity)
-- ============================================================================
INSERT INTO `stock_transactions` (`transaction_id`, `item_id`, `batch_id`, `transaction_type`, `quantity`, `transaction_date`, `performed_by`, `disposal_reason`, `reference_no`) VALUES
(1,  1, 1,  'Stock-In',                 200, '2025-11-01 08:00:00', 5, NULL,                        'DR-2025-1101'),
(2,  1, 2,  'Stock-In',                 120, '2026-01-15 08:30:00', 5, NULL,                        'DR-2026-0115'),
(3,  1, 1,  'Stock-Out',                120, '2026-03-15 10:00:00', 5, NULL,                        'ISS-2026-0315'),
(4,  2, 3,  'Stock-In',                 350, '2025-09-01 09:00:00', 5, NULL,                        'DR-2025-0901'),
(5,  3, 4,  'Stock-In',                  50, '2026-03-01 08:00:00', 5, NULL,                        'DR-2026-0301'),
(6,  3, 4,  'Stock-Out',                 25, '2026-06-15 14:00:00', 5, NULL,                        'ISS-2026-0615'),
(7,  4, 5,  'Stock-In',                  50, '2025-10-15 08:30:00', 5, NULL,                        'DR-2025-1015'),
(8,  4, 6,  'Stock-In',                 200, '2026-04-01 09:00:00', 5, NULL,                        'DR-2026-0401'),
(9,  4, 5,  'Disposal/Expired-Removal',  10, '2026-09-01 09:00:00', 5, 'Damaged blister packaging', 'DISP-2026-0901'),
(10, 5, 7,  'Stock-In',                  50, '2026-02-01 08:00:00', 5, NULL,                        'DR-2026-0201'),
(11, 5, 7,  'Stock-Out',                 30, '2026-08-10 08:00:00', 5, NULL,                        'ISS-2026-0810'),
(12, 6, 8,  'Stock-In',                 150, '2026-05-01 08:00:00', 5, NULL,                        'DR-2026-0501'),
(13, 7, 9,  'Stock-In',                  90, '2026-06-01 08:00:00', 5, NULL,                        'DR-2026-0601'),
(14, 8, 10, 'Stock-In',                   2, '2026-05-15 08:00:00', 5, NULL,                        'DR-2026-0515');

-- ============================================================================
-- 10. ocr_ingestion_drafts (Synthetic HITL OCR Drafts: 1 Draft, 1 Rejected)
-- ============================================================================
INSERT INTO `ocr_ingestion_drafts` (`draft_id`, `document_id`, `source_file_path`, `uploaded_by`, `ocr_raw_output`, `flagged_fields`, `confidence_scores`, `verified_by`, `verification_date`, `created_at`, `status`) VALUES
(1, 3, 'demo_ocr_imm_003.jpg', 4,
 'BARANGAY TUBTUBON HEALTH CENTER\nEARLY CHILDHOOD CARE AND DEVELOPMENT (ECCD) CARD\nPatient UPI: TUB-2026-00010\nName: MENDOZA, Ligaya Torres\nService Date: 2026-08-10\nProgram: Immunization\nVaccine: BCG / DPT-HepB-Hib 3rd Dose\nWeight: 10.2 kg\nRemarks: Handwritten batch lot unclear [SYNTHETIC DEMO]',
 '["vaccine_lot_no", "handwritten_remarks"]',
 '{"upi": 98, "patient_name": 95, "service_date": 92, "program_type": 96, "weight": 89, "vaccine_lot_no": 54, "handwritten_remarks": 61}',
 NULL, NULL, '2026-09-15 10:30:00', 'Draft'),
(2, 7, 'scan_fp_form_old.jpg', 3,
 'FORM 1 FP ... unreadable partial text ... [SYNTHETIC DEMO]',
 '["patient_name", "service_date", "findings_notes"]',
 '{"patient_name": 42, "service_date": 38, "findings_notes": 31}',
 2, '2026-09-01 11:05:00', '2026-09-01 11:00:00', 'Rejected');

-- ============================================================================
-- 11. consent_records (RA 10173 Consent Proofs before SMS/Processing)
-- ============================================================================
INSERT INTO `consent_records` (`consent_id`, `patient_id`, `consent_type`, `consent_given`, `date_given`, `recorded_by`) VALUES
(1,  1,  'SMS Notification',       1, '2026-01-10 09:05:00', 2),
(2,  1,  'Data Processing',        1, '2026-01-10 09:05:00', 2),
(3,  1,  'Digitization/Archiving', 1, '2026-01-10 09:05:00', 2),
(4,  2,  'SMS Notification',       1, '2026-01-12 10:20:00', 3),
(5,  3,  'SMS Notification',       0, '2026-02-01 08:35:00', 3),
(6,  4,  'SMS Notification',       1, '2026-02-14 11:05:00', 2),
(7,  5,  'SMS Notification',       1, '2026-03-03 09:25:00', 3),
(8,  6,  'SMS Notification',       0, '2026-03-19 14:15:00', 3),
(9,  7,  'SMS Notification',       1, '2026-04-05 08:50:00', 2),
(10, 8,  'SMS Notification',       1, '2026-04-22 10:35:00', 3),
(11, 9,  'SMS Notification',       1, '2026-05-11 13:20:00', 4),
(12, 10, 'SMS Notification',       1, '2026-06-01 08:05:00', 4),
(13, 11, 'SMS Notification',       1, '2026-07-08 09:45:00', 2),
(14, 12, 'SMS Notification',       1, '2026-08-19 15:05:00', 2);

-- ============================================================================
-- 12. sms_logs (Outbound SMS Audit with Recipient Snapshot & Gateway Receipt)
-- ============================================================================
INSERT INTO `sms_logs` (`sms_log_id`, `patient_id`, `appointment_id`, `recipient_number`, `message_content`, `gateway_used`, `delivery_status`, `sent_at`, `consent_verified`) VALUES
(1, 1,  5, '09171234001', 'Reminder: You have a scheduled visit on 2026-09-15 at Tubtubon Health Center. Please come on time.', 'Semaphore Mock', 'Delivered', '2026-09-14 10:00:00', 1),
(2, 10, 4, '09781234010', 'Reminder: Ligaya Mendoza has a scheduled Immunization visit on 2026-10-25 at Tubtubon Health Center.', 'Semaphore Mock', 'Sent',      '2026-10-07 09:00:00', 1),
(3, 2,  2, '09181234002', 'Reminder: You have a scheduled visit on 2026-10-19 at Tubtubon Health Center. Please come on time.', 'Semaphore Mock', 'Delivered', '2026-10-18 14:00:00', 1),
(4, 4,  6, '09321234004', 'Reminder: You have a scheduled visit on 2026-09-20 at Tubtubon Health Center. Please come on time.', 'Semaphore Mock', 'Delivered', '2026-09-19 09:00:00', 1);

-- ============================================================================
-- 13. audit_logs (Immutable Audit Trail from mock-data.js)
-- ============================================================================
INSERT INTO `audit_logs` (`log_id`, `user_id`, `action_type`, `description`, `source_ip`, `affected_record_type`, `affected_record_id`, `created_at`) VALUES
(1,  1, 'LOGIN',               'User logged in as Administrator',                                         '192.168.1.10', 'users',              '1',  '2026-09-27 07:30:00'),
(2,  2, 'CREATE',              'Created service record #7 for TUB-2026-00003 (Maternal/Prenatal)',        '192.168.1.11', 'service_records',    '7',  '2026-09-01 10:05:00'),
(3,  3, 'CREATE',              'Created service record #4 for TUB-2026-00002 (General Consultation)',     '192.168.1.12', 'service_records',    '4',  '2026-07-01 11:10:00'),
(4,  5, 'STOCK_ADJUST',        'Stock-In 200 capsule Amoxicillin 500mg (Batch AMX-2025-001)',             '192.168.1.13', 'stock_transactions', '1',  '2025-11-01 08:00:00'),
(5,  2, 'DOCUMENT_TRANSITION', 'Document #1 status changed to Scanned/Attached (Cabinet A / Box 1)',      '192.168.1.11', 'documents',          '1',  '2026-06-16 14:00:00'),
(6,  4, 'SMS_SEND',            'SMS reminder sent to DELA CRUZ, Juana (09171234001) with verified consent','192.168.1.14', 'sms_logs',           '1',  '2026-09-14 10:00:00'),
(7,  5, 'STOCK_ADJUST',        'Stock-Out 30 pc Syringe 5mL (FEFO Batch SYR-2026-001)',                   '192.168.1.13', 'stock_transactions', '11', '2026-08-10 08:00:00'),
(8,  5, 'STOCK_ADJUST',        'Disposal 10 tablet Iron with Folic Acid — Reason: Damaged blister packaging','192.168.1.13','stock_transactions','9',  '2026-09-01 09:00:00'),
(9,  2, 'OCR_VERIFY',          'OCR draft #2 marked Rejected — Unreadable partial text',                  '192.168.1.11', 'ocr_ingestion_drafts','2', '2026-09-01 11:05:00'),
(10, 1, 'BACKUP',              'Database and storage backup archive generated by Administrator',          '192.168.1.10', 'system',             '0',  '2026-09-27 07:35:00');

SET FOREIGN_KEY_CHECKS = 1;

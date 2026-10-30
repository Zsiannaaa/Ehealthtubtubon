-- ============================================================================
-- Tubtubon eHealth: A Web-Based Health Information and Service System
-- Barangay Tubtubon Health Center, Sibulan, Negros Oriental
--
-- Migration: 001_schema.sql
-- Purpose  : Creates the exact 13-table relational schema in 3NF (MySQL 8.4)
-- Sources  : Phase 6 Sec. 4.5 (Data Dictionary), Phase 7 (D1-D13), Phase 8 Sec. 49
-- ============================================================================

CREATE DATABASE IF NOT EXISTS `tubtubon_ehealth`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `tubtubon_ehealth`;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Drop existing tables in reverse dependency order for reproducible rebuilds
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `consent_records`;
DROP TABLE IF EXISTS `sms_logs`;
DROP TABLE IF EXISTS `ocr_ingestion_drafts`;
DROP TABLE IF EXISTS `stock_transactions`;
DROP TABLE IF EXISTS `stock_batches`;
DROP TABLE IF EXISTS `inventory_items`;
DROP TABLE IF EXISTS `documents`;
DROP TABLE IF EXISTS `appointments`;
DROP TABLE IF EXISTS `service_records`;
DROP TABLE IF EXISTS `patients`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `roles`;

-- ============================================================================
-- 1. roles (D2) — Master list of access roles; least-privilege model
-- ============================================================================
CREATE TABLE `roles` (
    `role_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `role_name` VARCHAR(50) NOT NULL,
    `description` VARCHAR(255) NULL DEFAULT NULL,
    PRIMARY KEY (`role_id`),
    UNIQUE KEY `uq_roles_role_name` (`role_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 2. users (D1) — System accounts; authentication + server-side role guards
-- ============================================================================
CREATE TABLE `users` (
    `user_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(50) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(100) NOT NULL,
    `role_id` INT UNSIGNED NOT NULL,
    `patient_id` INT UNSIGNED NULL DEFAULT NULL COMMENT '1-to-1 binding for Resident portal accounts (FR-24, BR-11)',
    `status` ENUM('Active', 'Deactivated') NOT NULL DEFAULT 'Active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_login_at` DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`user_id`),
    UNIQUE KEY `uq_users_username` (`username`),
    UNIQUE KEY `uq_users_patient_id` (`patient_id`),
    KEY `idx_users_role_id` (`role_id`),
    KEY `idx_users_status` (`status`),
    CONSTRAINT `fk_users_role`
        FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. patients (D3) — Master Patient Index; one row per person (BR-01)
-- ============================================================================
CREATE TABLE `patients` (
    `patient_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `upi` VARCHAR(20) NOT NULL COMMENT 'Algorithmic UPI: TUB-YYYY-##### (BR-01)',
    `last_name` VARCHAR(50) NOT NULL,
    `first_name` VARCHAR(50) NOT NULL,
    `middle_name` VARCHAR(50) NULL DEFAULT NULL,
    `sex` ENUM('Male', 'Female') NOT NULL,
    `birthdate` DATE NOT NULL COMMENT 'YYYY-MM-DD; Age is derived, not stored',
    `purok_address` VARCHAR(100) NOT NULL,
    `contact_no` VARCHAR(20) NULL DEFAULT NULL,
    `guardian_name` VARCHAR(100) NULL DEFAULT NULL,
    `registered_by` INT UNSIGNED NULL DEFAULT NULL,
    `date_registered` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `status` ENUM('Active', 'Archived') NOT NULL DEFAULT 'Active',
    PRIMARY KEY (`patient_id`),
    UNIQUE KEY `uq_patients_upi` (`upi`),
    KEY `idx_patients_name` (`last_name`, `first_name`),
    KEY `idx_patients_registered_by` (`registered_by`),
    KEY `idx_patients_status` (`status`),
    CONSTRAINT `fk_patients_registered_by`
        FOREIGN KEY (`registered_by`) REFERENCES `users` (`user_id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `users`
    ADD CONSTRAINT `fk_users_patient`
        FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`)
        ON UPDATE CASCADE ON DELETE SET NULL;

-- ============================================================================
-- 4. service_records (D4) — Health-service encounters per program (BR-02)
-- ============================================================================
CREATE TABLE `service_records` (
    `record_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id` INT UNSIGNED NOT NULL,
    `program_type` ENUM(
        'Family Planning',
        'Maternal/Prenatal',
        'Child Health-ECCD',
        'Immunization',
        'Laboratory Referral',
        'General Consultation'
    ) NOT NULL,
    `form_reference_no` VARCHAR(50) NULL DEFAULT NULL,
    `service_date` DATE NOT NULL,
    `findings_notes` TEXT NULL DEFAULT NULL COMMENT 'Clinical notes + structured JSON fields per DOH form layout',
    `recorded_by` INT UNSIGNED NULL DEFAULT NULL,
    `verified_by` INT UNSIGNED NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`record_id`),
    KEY `idx_sr_patient_id` (`patient_id`),
    KEY `idx_sr_program_type` (`program_type`),
    KEY `idx_sr_service_date` (`service_date`),
    KEY `idx_sr_recorded_by` (`recorded_by`),
    KEY `idx_sr_verified_by` (`verified_by`),
    CONSTRAINT `fk_sr_patient`
        FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_sr_recorded_by`
        FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT `fk_sr_verified_by`
        FOREIGN KEY (`verified_by`) REFERENCES `users` (`user_id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 5. appointments (D6) — Schedules for recurring / fixed programs (BR-08)
-- ============================================================================
CREATE TABLE `appointments` (
    `appointment_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id` INT UNSIGNED NOT NULL,
    `program_type` ENUM(
        'Family Planning',
        'Maternal/Prenatal',
        'Child Health-ECCD',
        'Immunization',
        'Laboratory Referral',
        'General Consultation'
    ) NOT NULL,
    `scheduled_date` DATE NOT NULL,
    `scheduled_time` TIME NULL DEFAULT NULL,
    `status` ENUM('Scheduled', 'Completed', 'Cancelled', 'No-Show') NOT NULL DEFAULT 'Scheduled',
    `reminder_sent` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Becomes 1 only after sms_logs entry (BR-08)',
    `created_by` INT UNSIGNED NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`appointment_id`),
    KEY `idx_appt_patient_id` (`patient_id`),
    KEY `idx_appt_scheduled_date` (`scheduled_date`),
    KEY `idx_appt_status` (`status`),
    KEY `idx_appt_created_by` (`created_by`),
    CONSTRAINT `fk_appt_patient`
        FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_appt_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 6. documents (D5) — Physical + digital registry; 4-state lifecycle (BR-03)
-- ============================================================================
CREATE TABLE `documents` (
    `document_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id` INT UNSIGNED NOT NULL,
    `record_id` INT UNSIGNED NULL DEFAULT NULL,
    `document_type` VARCHAR(50) NOT NULL,
    `title` VARCHAR(150) NOT NULL,
    `status` ENUM(
        'Filed',
        'Pending Digitization',
        'Scanned/Attached',
        'Unavailable/Archived'
    ) NOT NULL DEFAULT 'Filed',
    `cabinet_location` VARCHAR(20) NULL DEFAULT NULL,
    `box_location` VARCHAR(20) NULL DEFAULT NULL,
    `folder_location` VARCHAR(50) NULL DEFAULT NULL,
    `file_path` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Hashed filename outside web root (FR-12)',
    `filed_by` INT UNSIGNED NULL DEFAULT NULL,
    `filed_at` DATETIME NULL DEFAULT NULL,
    `digitized_at` DATETIME NULL DEFAULT NULL,
    `archived_at` DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`document_id`),
    KEY `idx_docs_patient_id` (`patient_id`),
    KEY `idx_docs_record_id` (`record_id`),
    KEY `idx_docs_status` (`status`),
    KEY `idx_docs_location` (`cabinet_location`, `box_location`, `folder_location`),
    KEY `idx_docs_filed_by` (`filed_by`),
    CONSTRAINT `fk_docs_patient`
        FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_docs_record`
        FOREIGN KEY (`record_id`) REFERENCES `service_records` (`record_id`)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT `fk_docs_filed_by`
        FOREIGN KEY (`filed_by`) REFERENCES `users` (`user_id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 7. inventory_items (D7) — Catalog of medicines, supplies, equipment (BR-04)
-- ============================================================================
CREATE TABLE `inventory_items` (
    `item_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `item_name` VARCHAR(100) NOT NULL,
    `category` ENUM('Medicine', 'Supply', 'Equipment') NOT NULL,
    `unit` VARCHAR(20) NOT NULL,
    `minimum_stock_threshold` INT UNSIGNED NOT NULL DEFAULT 0,
    `current_quantity` INT NOT NULL DEFAULT 0 COMMENT 'Derived from stock_transactions, clamped >= 0 (BR-04)',
    `status` ENUM('Available', 'Low Stock', 'Out of Stock') NOT NULL DEFAULT 'Available',
    PRIMARY KEY (`item_id`),
    UNIQUE KEY `uq_inventory_item_name` (`item_name`),
    KEY `idx_inventory_category` (`category`),
    KEY `idx_inventory_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 8. stock_batches (D8) — Batch / expiry tracking; enables FEFO (BR-05, BR-06)
-- ============================================================================
CREATE TABLE `stock_batches` (
    `batch_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `item_id` INT UNSIGNED NOT NULL,
    `batch_no` VARCHAR(50) NOT NULL,
    `quantity_received` INT UNSIGNED NOT NULL,
    `quantity_remaining` INT UNSIGNED NOT NULL,
    `expiry_date` DATE NULL DEFAULT NULL COMMENT 'NULL = non-expiring equipment; issues last in FEFO',
    `date_received` DATE NOT NULL,
    `status` ENUM('Available', 'Depleted', 'Expired', 'Disposed') NOT NULL DEFAULT 'Available',
    PRIMARY KEY (`batch_id`),
    KEY `idx_batches_item_id` (`item_id`),
    KEY `idx_batches_fefo` (`item_id`, `status`, `expiry_date`),
    KEY `idx_batches_expiry_date` (`expiry_date`),
    CONSTRAINT `fk_batches_item`
        FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`item_id`)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 9. stock_transactions (D9) — Immutable ledger of every stock movement (BR-07)
-- ============================================================================
CREATE TABLE `stock_transactions` (
    `transaction_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `item_id` INT UNSIGNED NOT NULL,
    `batch_id` INT UNSIGNED NULL DEFAULT NULL,
    `transaction_type` ENUM('Stock-In', 'Stock-Out', 'Disposal/Expired-Removal') NOT NULL,
    `quantity` INT UNSIGNED NOT NULL,
    `transaction_date` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `performed_by` INT UNSIGNED NULL DEFAULT NULL,
    `disposal_reason` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Required when transaction_type = Disposal/Expired-Removal (BR-07)',
    `reference_no` VARCHAR(50) NULL DEFAULT NULL,
    PRIMARY KEY (`transaction_id`),
    KEY `idx_st_item_id` (`item_id`),
    KEY `idx_st_batch_id` (`batch_id`),
    KEY `idx_st_transaction_type` (`transaction_type`),
    KEY `idx_st_transaction_date` (`transaction_date`),
    KEY `idx_st_performed_by` (`performed_by`),
    CONSTRAINT `fk_st_item`
        FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`item_id`)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_st_batch`
        FOREIGN KEY (`batch_id`) REFERENCES `stock_batches` (`batch_id`)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_st_performed_by`
        FOREIGN KEY (`performed_by`) REFERENCES `users` (`user_id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 10. ocr_ingestion_drafts (D12) — Human-in-the-Loop OCR drafts (BR-10)
-- ============================================================================
CREATE TABLE `ocr_ingestion_drafts` (
    `draft_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `document_id` INT UNSIGNED NOT NULL,
    `source_file_path` VARCHAR(255) NOT NULL,
    `uploaded_by` INT UNSIGNED NULL DEFAULT NULL,
    `ocr_raw_output` MEDIUMTEXT NULL DEFAULT NULL,
    `flagged_fields` TEXT NULL DEFAULT NULL COMMENT 'JSON list of low-confidence field keys',
    `confidence_scores` TEXT NULL DEFAULT NULL COMMENT 'JSON map of field confidence 0-100',
    `verified_by` INT UNSIGNED NULL DEFAULT NULL,
    `verification_date` DATETIME NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `status` ENUM('Draft', 'Verified', 'Rejected') NOT NULL DEFAULT 'Draft',
    PRIMARY KEY (`draft_id`),
    UNIQUE KEY `uq_ocr_document_id` (`document_id`),
    KEY `idx_ocr_status` (`status`),
    KEY `idx_ocr_uploaded_by` (`uploaded_by`),
    KEY `idx_ocr_verified_by` (`verified_by`),
    CONSTRAINT `fk_ocr_document`
        FOREIGN KEY (`document_id`) REFERENCES `documents` (`document_id`)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_ocr_uploaded_by`
        FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`user_id`)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT `fk_ocr_verified_by`
        FOREIGN KEY (`verified_by`) REFERENCES `users` (`user_id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 11. sms_logs (D10) — Outbound SMS audit; delivery receipts + consent (BR-09)
-- ============================================================================
CREATE TABLE `sms_logs` (
    `sms_log_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id` INT UNSIGNED NOT NULL,
    `appointment_id` INT UNSIGNED NULL DEFAULT NULL,
    `recipient_number` VARCHAR(20) NOT NULL COMMENT 'Snapshot of mobile number actually messaged',
    `message_content` VARCHAR(500) NOT NULL,
    `gateway_used` VARCHAR(30) NULL DEFAULT NULL,
    `delivery_status` VARCHAR(30) NULL DEFAULT NULL,
    `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `consent_verified` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`sms_log_id`),
    KEY `idx_sms_patient_id` (`patient_id`),
    KEY `idx_sms_appointment_id` (`appointment_id`),
    KEY `idx_sms_sent_at` (`sent_at`),
    CONSTRAINT `fk_sms_patient`
        FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_sms_appointment`
        FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 12. consent_records (D11) — Consent proofs required before SMS/processing
-- ============================================================================
CREATE TABLE `consent_records` (
    `consent_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `patient_id` INT UNSIGNED NOT NULL,
    `consent_type` ENUM('SMS Notification', 'Data Processing', 'Digitization/Archiving') NOT NULL,
    `consent_given` TINYINT(1) NOT NULL DEFAULT 1,
    `date_given` DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
    `recorded_by` INT UNSIGNED NULL DEFAULT NULL,
    PRIMARY KEY (`consent_id`),
    KEY `idx_consent_patient_type` (`patient_id`, `consent_type`),
    KEY `idx_consent_recorded_by` (`recorded_by`),
    CONSTRAINT `fk_consent_patient`
        FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT `fk_consent_recorded_by`
        FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 13. audit_logs (D13) — Immutable audit trail; accountability + forensics (BR-12)
-- ============================================================================
CREATE TABLE `audit_logs` (
    `log_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NULL DEFAULT NULL,
    `action_type` VARCHAR(50) NOT NULL,
    `description` VARCHAR(255) NULL DEFAULT NULL,
    `source_ip` VARCHAR(45) NULL DEFAULT NULL,
    `affected_record_type` VARCHAR(50) NULL DEFAULT NULL,
    `affected_record_id` VARCHAR(50) NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`log_id`),
    KEY `idx_audit_created_at` (`created_at`),
    KEY `idx_audit_user_id` (`user_id`),
    KEY `idx_audit_action_type` (`action_type`),
    CONSTRAINT `fk_audit_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

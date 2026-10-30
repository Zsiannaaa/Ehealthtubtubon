-- ============================================================================
-- Tubtubon eHealth: A Web-Based Health Information and Service System
-- Migration: 003_users_patient_fk.sql
-- Purpose  : Replaces display-name UPI parsing with an explicit, nullable
--            1-to-1 foreign key `users.patient_id -> patients.patient_id`
--            to enforce strict Resident Portal isolation (FR-24, BR-11)
--            while keeping the database strictly at 13 tables.
-- ============================================================================

USE `tubtubon_ehealth`;

SET NAMES utf8mb4;

-- Add nullable `patient_id` column and unique FK constraint on `users` if not present
SET @col_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'patient_id'
);

SET @sql_add_col := IF(
    @col_exists = 0,
    'ALTER TABLE `users`
        ADD COLUMN `patient_id` INT UNSIGNED NULL DEFAULT NULL COMMENT ''1-to-1 binding for Resident portal accounts (FR-24, BR-11)'' AFTER `role_id`,
        ADD UNIQUE KEY `uq_users_patient_id` (`patient_id`),
        ADD CONSTRAINT `fk_users_patient`
            FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`)
            ON UPDATE CASCADE ON DELETE SET NULL',
    'SELECT 1'
);

PREPARE stmt FROM @sql_add_col;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Bind seeded demo resident account `resident.juana` strictly to `patient_id = 1` (TUB-2026-00001)
UPDATE `users` u
INNER JOIN `patients` p ON p.`upi` = 'TUB-2026-00001'
SET u.`patient_id` = p.`patient_id`
WHERE u.`username` = 'resident.juana'
  AND u.`patient_id` IS NULL;

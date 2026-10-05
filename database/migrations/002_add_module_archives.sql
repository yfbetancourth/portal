USE `AdminLTE`;

ALTER TABLE `panel_modules`
  ADD COLUMN `archive_path` VARCHAR(160) NULL AFTER `is_active`,
  ADD COLUMN `archive_original_name` VARCHAR(255) NULL AFTER `archive_path`,
  ADD COLUMN `archive_sha256` CHAR(64) NULL AFTER `archive_original_name`,
  ADD COLUMN `archive_size` BIGINT UNSIGNED NULL AFTER `archive_sha256`,
  ADD COLUMN `archive_uploaded_at` DATETIME(6) NULL AFTER `archive_size`;

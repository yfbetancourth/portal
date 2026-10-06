USE `AdminLTE`;

ALTER TABLE `portal_users`
  ADD COLUMN `store_name` VARCHAR(100) NULL AFTER `name`;

UPDATE `portal_users`
SET `store_name` = `nickname`
WHERE `store_name` IS NULL;

ALTER TABLE `portal_users`
  MODIFY COLUMN `store_name` VARCHAR(100) NOT NULL;

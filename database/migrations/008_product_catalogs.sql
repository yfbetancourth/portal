USE `AdminLTE`;

ALTER TABLE `portal_users`
  ADD COLUMN `nickname` VARCHAR(50) NULL AFTER `name`;

UPDATE `portal_users`
SET `nickname` = CONCAT('usuario-', `id`)
WHERE `nickname` IS NULL;

ALTER TABLE `portal_users`
  MODIFY COLUMN `nickname` VARCHAR(50) NOT NULL,
  ADD UNIQUE KEY `uq_portal_users_nickname` (`nickname`);

ALTER TABLE `products`
  CHANGE COLUMN `price` `sale_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN `cost_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `description`;

CREATE TABLE IF NOT EXISTS `product_images` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED NOT NULL,
  `file_name` VARCHAR(50) NOT NULL,
  `mime_type` ENUM('image/jpeg', 'image/png', 'image/webp') NOT NULL,
  `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_images_file_name` (`file_name`),
  UNIQUE KEY `uq_product_images_order` (`product_id`, `sort_order`),
  CONSTRAINT `fk_product_images_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

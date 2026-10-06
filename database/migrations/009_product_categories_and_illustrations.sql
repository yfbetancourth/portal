USE `AdminLTE`;

CREATE TABLE IF NOT EXISTS `product_categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `portal_user_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(80) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_categories_owner_name` (`portal_user_id`, `name`),
  CONSTRAINT `fk_product_categories_portal_user` FOREIGN KEY (`portal_user_id`)
    REFERENCES `portal_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `products`
  ADD COLUMN `category_id` INT UNSIGNED NULL AFTER `portal_user_id`,
  ADD COLUMN `illustration_url` VARCHAR(2048) NULL AFTER `description`,
  ADD KEY `idx_products_category` (`category_id`),
  ADD CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`)
    REFERENCES `product_categories` (`id`) ON DELETE SET NULL;

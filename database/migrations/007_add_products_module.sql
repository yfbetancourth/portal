USE `AdminLTE`;

INSERT IGNORE INTO `roles` (`role_key`, `name`, `is_system`) VALUES
  ('gestion', CONVERT(0x4765737469C3B36E USING utf8mb4), 1);

INSERT IGNORE INTO `role_permissions` (`role_key`, `resource`, `action`) VALUES
  ('gestion', 'dashboard', 'read'),
  ('gestion', 'profile', 'read'),
  ('gestion', 'profile', 'update'),
  ('gestion', 'products', 'create'),
  ('gestion', 'products', 'read'),
  ('gestion', 'products', 'update'),
  ('gestion', 'products', 'delete');

CREATE TABLE IF NOT EXISTS `products` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `portal_user_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `description` VARCHAR(1000) NOT NULL DEFAULT '',
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `inventory` INT UNSIGNED NOT NULL DEFAULT 0,
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_products_owner_enabled` (`portal_user_id`, `is_enabled`),
  CONSTRAINT `fk_products_portal_user` FOREIGN KEY (`portal_user_id`)
    REFERENCES `portal_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

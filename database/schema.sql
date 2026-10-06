CREATE DATABASE IF NOT EXISTS `AdminLTE`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `AdminLTE`;

CREATE TABLE IF NOT EXISTS `portal_users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `store_name` VARCHAR(100) NOT NULL,
  `nickname` VARCHAR(50) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_portal_users_email` (`email`),
  UNIQUE KEY `uq_portal_users_nickname` (`nickname`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `portal_password_resets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_portal_password_resets_token` (`token_hash`),
  KEY `idx_portal_password_resets_user` (`user_id`),
  CONSTRAINT `fk_portal_password_resets_user` FOREIGN KEY (`user_id`)
    REFERENCES `portal_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `store_customers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `portal_user_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `delivery_address` VARCHAR(500) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_store_customers_store_email` (`portal_user_id`, `email`),
  CONSTRAINT `fk_store_customers_store` FOREIGN KEY (`portal_user_id`)
    REFERENCES `portal_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `store_customer_password_resets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` INT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_store_customer_password_resets_token` (`token_hash`),
  KEY `idx_store_customer_password_resets_customer` (`customer_id`),
  CONSTRAINT `fk_store_customer_password_resets_customer` FOREIGN KEY (`customer_id`)
    REFERENCES `store_customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS `products` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `portal_user_id` INT UNSIGNED NOT NULL,
  `category_id` INT UNSIGNED NULL,
  `name` VARCHAR(150) NOT NULL,
  `description` VARCHAR(1000) NOT NULL DEFAULT '',
  `illustration_url` VARCHAR(2048) NULL,
  `cost_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `sale_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `inventory` INT UNSIGNED NOT NULL DEFAULT 0,
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_products_owner_enabled` (`portal_user_id`, `is_enabled`),
  KEY `idx_products_category` (`category_id`),
  CONSTRAINT `fk_products_portal_user` FOREIGN KEY (`portal_user_id`)
    REFERENCES `portal_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`)
    REFERENCES `product_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS `roles` (
  `role_key` VARCHAR(80) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `is_system` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`role_key`),
  UNIQUE KEY `uq_roles_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `roles` (`role_key`, `name`, `is_system`) VALUES
  ('administrador', 'Administrador', 1),
  ('colaborador', 'Colaborador', 1),
  ('gestion', CONVERT(0x4765737469C3B36E USING utf8mb4), 1);

CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(100) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `rol` VARCHAR(80) NOT NULL DEFAULT 'colaborador',
  `fecha_registro` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_usuarios_role_active` (`rol`, `activo`),
  UNIQUE KEY `uq_usuarios_email` (`email`),
  CONSTRAINT `fk_usuarios_role` FOREIGN KEY (`rol`)
    REFERENCES `roles` (`role_key`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `role_permissions` (
  `role_key` VARCHAR(80) NOT NULL,
  `resource` VARCHAR(40) NOT NULL,
  `action` ENUM('create', 'read', 'update', 'delete') NOT NULL,
  `granted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`role_key`, `resource`, `action`),
  CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_key`)
    REFERENCES `roles` (`role_key`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `role_permissions` (`role_key`, `resource`, `action`) VALUES
  ('gestion', 'dashboard', 'read'),
  ('gestion', 'profile', 'read'),
  ('gestion', 'profile', 'update'),
  ('gestion', 'products', 'create'),
  ('gestion', 'products', 'read'),
  ('gestion', 'products', 'update'),
  ('gestion', 'products', 'delete');

CREATE TABLE IF NOT EXISTS `user_permissions` (
  `user_id` INT UNSIGNED NOT NULL,
  `resource` VARCHAR(40) NOT NULL,
  `action` ENUM('create', 'read', 'update', 'delete') NOT NULL,
  `granted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `resource`, `action`),
  CONSTRAINT `fk_user_permissions_user` FOREIGN KEY (`user_id`)
    REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `panel_modules` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `module_key` VARCHAR(80) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `description` VARCHAR(500) NOT NULL DEFAULT '',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `archive_path` VARCHAR(160) NULL,
  `archive_original_name` VARCHAR(255) NULL,
  `archive_sha256` CHAR(64) NULL,
  `archive_size` BIGINT UNSIGNED NULL,
  `archive_uploaded_at` DATETIME(6) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_panel_modules_key` (`module_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `auth_sessions` (
  `session_hash` CHAR(64) NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `started_at` DATETIME(6) NOT NULL,
  `last_seen` DATETIME(6) NOT NULL,
  `ended_at` DATETIME(6) NULL,
  `end_reason` ENUM('logout') NULL,
  PRIMARY KEY (`session_hash`),
  KEY `idx_auth_sessions_user_presence` (`user_id`, `ended_at`, `last_seen`),
  CONSTRAINT `fk_auth_sessions_user` FOREIGN KEY (`user_id`)
    REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `auth_login_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `session_hash` CHAR(64) NOT NULL,
  `event_type` ENUM('login', 'logout') NOT NULL,
  `occurred_at` DATETIME(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_auth_login_history_session_event` (`session_hash`, `event_type`),
  KEY `idx_auth_login_history_user_date` (`user_id`, `occurred_at`),
  CONSTRAINT `fk_auth_login_history_user` FOREIGN KEY (`user_id`)
    REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

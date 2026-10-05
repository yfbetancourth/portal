CREATE DATABASE IF NOT EXISTS `AdminLTE`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `AdminLTE`;

CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(100) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `rol` ENUM('administrador', 'colaborador') NOT NULL DEFAULT 'colaborador',
  `fecha_registro` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_usuarios_role_active` (`rol`, `activo`),
  UNIQUE KEY `uq_usuarios_email` (`email`)
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

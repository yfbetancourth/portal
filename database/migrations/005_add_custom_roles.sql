USE `AdminLTE`;

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
  ('colaborador', 'Colaborador', 1);

ALTER TABLE `usuarios`
  MODIFY COLUMN `rol` VARCHAR(80) NOT NULL DEFAULT 'colaborador',
  ADD CONSTRAINT `fk_usuarios_role` FOREIGN KEY (`rol`)
    REFERENCES `roles` (`role_key`) ON UPDATE CASCADE ON DELETE RESTRICT;

CREATE TABLE IF NOT EXISTS `role_permissions` (
  `role_key` VARCHAR(80) NOT NULL,
  `resource` VARCHAR(40) NOT NULL,
  `action` ENUM('create', 'read', 'update', 'delete') NOT NULL,
  `granted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`role_key`, `resource`, `action`),
  CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_key`)
    REFERENCES `roles` (`role_key`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

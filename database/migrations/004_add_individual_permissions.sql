USE `AdminLTE`;

CREATE TABLE IF NOT EXISTS `user_permissions` (
  `user_id` INT UNSIGNED NOT NULL,
  `resource` VARCHAR(40) NOT NULL,
  `action` ENUM('create', 'read', 'update', 'delete') NOT NULL,
  `granted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `resource`, `action`),
  CONSTRAINT `fk_user_permissions_user` FOREIGN KEY (`user_id`)
    REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `user_permissions` (`user_id`, `resource`, `action`)
SELECT `usuarios`.`id`, `permission_defaults`.`resource`, `permission_defaults`.`action`
FROM `usuarios`
CROSS JOIN (
  SELECT 'dashboard' AS `resource`, 'read' AS `action`
  UNION ALL SELECT 'profile', 'read'
  UNION ALL SELECT 'profile', 'update'
  UNION ALL SELECT 'modules', 'create'
  UNION ALL SELECT 'modules', 'read'
  UNION ALL SELECT 'modules', 'update'
  UNION ALL SELECT 'modules', 'delete'
  UNION ALL SELECT 'collaborators', 'create'
  UNION ALL SELECT 'collaborators', 'read'
  UNION ALL SELECT 'collaborators', 'update'
  UNION ALL SELECT 'collaborators', 'delete'
) AS `permission_defaults`
WHERE `usuarios`.`rol` = 'colaborador';

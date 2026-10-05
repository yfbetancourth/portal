USE `AdminLTE`;

ALTER TABLE `usuarios`
  MODIFY COLUMN `rol` ENUM('administrador', 'colaborador') NOT NULL DEFAULT 'colaborador',
  ADD KEY `idx_usuarios_role_active` (`rol`, `activo`);

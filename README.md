# Panel de colaboradores

Portal administrativo servido por XAMPP en `http://localhost/Portal/panel/`. La interfaz usa los recursos locales de AdminLTE 4 que se conservan en `panel/assets/vendor/`.

## Configuración local

1. Enciende Apache y MySQL en XAMPP.
2. Importa `database/schema.sql` en phpMyAdmin. La configuración predeterminada de desarrollo se conecta a `127.0.0.1`, base `AdminLTE`, usuario `root` y contraseña vacía.
3. Abre `http://localhost/Portal/panel/registro.php`, crea una cuenta de colaborador y accede al panel.

Para conectar otra base, define `PORTAL_DB_HOST`, `PORTAL_DB_NAME`, `PORTAL_DB_USER` y `PORTAL_DB_PASSWORD` en el entorno de Apache. En producción utiliza una cuenta SQL exclusiva, con privilegios mínimos, una contraseña segura y HTTPS.

## Funcionalidad

- `panel/login.php`: acceso para colaboradores activos.
- `panel/registro.php`: registro abierto de nuevas cuentas de colaborador.
- `panel/recuperar.php`: muestra el estado de recuperación. No envía enlaces hasta que se configure un servicio de correo.
- `panel/index.php`: panel AdminLTE con el nombre, correo y rol de quien inició sesión.
- `panel/perfil.php`: datos del colaborador y los últimos accesos.
- `panel/modulos.php`: catálogo CRUD de módulos futuros con identificador, descripción y estado activo/inactivo. El registro no genera ni publica funcionalidad; cada módulo debe integrarse al panel durante su desarrollo.
- `panel/cerrar-sesion.php`: cierre de sesión protegido contra CSRF.

Las contraseñas se guardan con `password_hash`; las sesiones se identifican en la base por un hash y el historial usa hora UTC. El formulario de recuperación se habilitará después de configurar y probar el transporte SMTP.

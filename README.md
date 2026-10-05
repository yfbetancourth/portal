# Panel de colaboradores

Portal administrativo servido por XAMPP en `http://localhost/Portal/panel/`. La interfaz usa los recursos locales de AdminLTE 4 que se conservan en `panel/assets/vendor/`.

## Configuración local

1. Enciende Apache y MySQL en XAMPP.
2. Importa `database/schema.sql` en phpMyAdmin. La configuración predeterminada de desarrollo se conecta a `127.0.0.1`, base `AdminLTE`, usuario `root` y contraseña vacía.
3. Abre `http://localhost/Portal/panel/registro.php` y crea una cuenta inicial de colaborador.
4. Desde una terminal local, ejecuta `C:\xampp\php\php.exe scripts\promote-first-admin.php correo@ejemplo.com` para convertir esa cuenta en el primer administrador; después inicia sesión en `http://localhost/Portal/panel/login.php`.

Para conectar otra base, define `PORTAL_DB_HOST`, `PORTAL_DB_NAME`, `PORTAL_DB_USER` y `PORTAL_DB_PASSWORD` en el entorno de Apache. En producción utiliza una cuenta SQL exclusiva, con privilegios mínimos, una contraseña segura y HTTPS.

En una instalación existente que ya tenga `panel_modules`, ejecuta una vez `database/migrations/002_add_module_archives.sql` y después `database/migrations/003_add_administrator_role.sql`. PHP debe tener habilitadas las extensiones `zip` y `fileinfo`.

## Funcionalidad

- `panel/login.php`: acceso para colaboradores activos.
- `panel/registro.php`: registro abierto de nuevas cuentas de colaborador.
- `panel/recuperar.php`: muestra el estado de recuperación. No envía enlaces hasta que se configure un servicio de correo.
- `panel/index.php`: panel AdminLTE con el nombre, correo y rol de quien inició sesión.
- `panel/perfil.php`: datos del colaborador y los últimos accesos.
- `panel/colaboradores.php`: CRUD de cuentas y roles. Los colaboradores gestionan cuentas de colaborador; solo administradores pueden asignar el rol administrador. Siempre se protege la última cuenta administrativa activa y al cambiar una contraseña se revocan las sesiones previas.
- `panel/modulos.php`: catálogo CRUD de módulos futuros con identificador, descripción y estado activo/inactivo. El registro no genera ni publica funcionalidad; cada módulo debe integrarse al panel durante su desarrollo.
- Los paquetes ZIP de módulos se guardan fuera del directorio web en `C:\xampp\modulos\<clave-del-modulo>` (o en la ruta definida en `PORTAL_MODULE_STORAGE`), con límite de 20 MiB por paquete y 512 MiB en total. Se sirven solo mediante una descarga autenticada y se calcula SHA-256. No se extraen ni ejecutan: la revisión y el despliegue son manuales.
- `panel/cerrar-sesion.php`: cierre de sesión protegido contra CSRF.

Las contraseñas se guardan con `password_hash`; las sesiones se identifican en la base por un hash y el historial usa hora UTC. El formulario de recuperación se habilitará después de configurar y probar el transporte SMTP.

La promoción inicial solo puede ejecutarse desde CLI, una única vez mientras no haya un administrador activo; el archivo de inicialización no se puede ejecutar desde HTTP.

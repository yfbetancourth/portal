# Panel de colaboradores

Portal de usuarios y panel administrativo servido por XAMPP en `http://localhost/Portal/`. La interfaz usa los recursos locales de AdminLTE 4 que se conservan en `panel/assets/vendor/`.

## Configuración local

1. Enciende Apache y MySQL en XAMPP.
2. Importa `database/schema.sql` en phpMyAdmin. La configuración predeterminada de desarrollo se conecta a `127.0.0.1`, base `AdminLTE`, usuario `root` y contraseña vacía.
3. Abre `http://localhost/Portal/panel/registro.php` y crea una cuenta inicial de colaborador.
4. Desde una terminal local, ejecuta `C:\xampp\php\php.exe scripts\promote-first-admin.php correo@ejemplo.com` para convertir esa cuenta en el primer administrador; después inicia sesión en `http://localhost/Portal/panel/login.php`.

Para conectar otra base, define `PORTAL_DB_HOST`, `PORTAL_DB_NAME`, `PORTAL_DB_USER` y `PORTAL_DB_PASSWORD` en el entorno de Apache. En producción utiliza una cuenta SQL exclusiva, con privilegios mínimos, una contraseña segura y HTTPS.

En una instalación existente, aplica en orden las migraciones que todavía no se hayan ejecutado: `database/migrations/002_add_module_archives.sql`, `database/migrations/003_add_administrator_role.sql`, `database/migrations/004_add_individual_permissions.sql`, `database/migrations/005_add_custom_roles.sql`, `database/migrations/006_add_portal_users.sql`, `database/migrations/007_add_products_module.sql`, `database/migrations/008_product_catalogs.sql`, `database/migrations/009_product_categories_and_illustrations.sql`, `database/migrations/010_store_names_and_slugs.sql` y `database/migrations/011_storefront_customers.sql`. Las migraciones 007 a 011 crean el catálogo de productos, el rol Gestión y las funciones de microtienda y clientes. PHP debe tener habilitadas las extensiones `zip` y `fileinfo`.

Configura `PORTAL_PUBLIC_URL` con la URL pública base (por ejemplo `https://ejemplo.com/Portal`) para construir enlaces canónicos de microtienda y recuperación. Para enviar enlaces de recuperación de usuarios y clientes, configura además `PORTAL_MAIL_FROM` y el transporte de correo de PHP (`mail()`). Sin esos ajustes de correo, los formularios indicarán que la recuperación no está disponible.

Las imágenes cargadas de productos se guardan fuera del directorio web en `C:\xampp\portal-product-images` de forma predeterminada; `PORTAL_PRODUCT_IMAGE_STORAGE` permite cambiar esa ruta, que también debe quedar fuera del directorio público. Cada producto admite hasta cinco imágenes JPEG/PNG/WebP de 5 MiB cada una. La URL ilustrativa opcional debe usar HTTPS; al mostrarla, el navegador del visitante solicita la imagen al servidor externo indicado.

## Funcionalidad

- `panel/login.php`: acceso para colaboradores activos.
- `panel/registro.php`: registro abierto de nuevas cuentas de colaborador.
- `panel/recuperar.php`: informa sobre la recuperación de cuentas de colaboradores; es independiente de la recuperación de usuarios del portal.
- `index.php`: portada pública con el formulario de acceso del usuario del portal en la parte superior.
- `registro.php`: registro de cuentas independientes de usuarios del portal; requiere nombre de microtienda y genera automáticamente el slug de su micrositio a partir de ese nombre.
- `recuperar.php`: recuperación de contraseña con enlace de un solo uso válido por una hora; requiere configurar el transporte de correo.
- `cuenta.php`: cada usuario del portal puede modificar o eliminar únicamente su propia cuenta y cambiar su contraseña. El slug de su micrositio se actualiza automáticamente al cambiar el nombre de la microtienda.
- `mis-productos.php`: cada usuario autenticado administra sus categorías y productos, con cantidades, costos, precios de venta, hasta cinco imágenes JPEG/PNG/WebP y una URL HTTPS opcional para la imagen ilustrativa. Los productos se pueden asignar a una categoría y los nuevos quedan pendientes de habilitación por Gestión.
- `tienda.php`: catálogo público identificado y titulado con el nombre de la microtienda; muestra el slug generado a partir de ese nombre, productos habilitados agrupados por categoría y enlace/QR para compartirla. El QR se genera mediante QRServer, un servicio externo que recibe el enlace público y los metadatos normales de la solicitud.
- `cliente-registro.php`, `cliente-login.php`, `cliente-recuperar.php` y `cliente-cuenta.php`: registro e inicio de sesión de clientes específicos a cada microtienda; el cliente puede guardar su dirección de domicilio en su perfil. Cada tienda mantiene clientes independientes y recuperación de contraseña propia.
- `producto-imagen.php`: entrega imágenes públicas solo cuando el producto está habilitado; las imágenes de productos pendientes requieren que haya iniciado sesión su propietario.
- `cerrar-sesion.php`: cierre de sesión del usuario del portal protegido contra CSRF.
- `panel/index.php`: panel AdminLTE con el nombre, correo y rol de quien inició sesión.
- `panel/perfil.php`: consulta y actualización de los datos propios del colaborador, además de los últimos accesos. Al cambiar la contraseña se revocan las sesiones previas.
- `panel/colaboradores.php`: CRUD de cuentas y roles, además de permisos CRUD compartidos por rol y permisos individuales adicionales por colaborador. Solo administradores pueden administrar roles y asignarlos; el rol Administrador mantiene acceso total y no se puede eliminar. Siempre se protege la última cuenta administrativa activa.
- `panel/usuarios.php`: módulo exclusivo para el rol Administrador, que permite editar nombre y correo de usuarios del portal. No permite cambiar contraseñas ni eliminar usuarios.
- `panel/productos.php`: gestión del catálogo vinculado a cuentas de `portal_users`; Administración y Gestión pueden revisar, editar, habilitar/deshabilitar y eliminar productos según sus permisos. El Administrador asigna el rol Gestión desde Colaboradores.
- `panel/modulos.php`: catálogo CRUD de módulos futuros con identificador, descripción y estado activo/inactivo. El registro no genera ni publica funcionalidad; cada módulo debe integrarse al panel durante su desarrollo.
- Los permisos se verifican en el servidor y controlan Inicio (lectura), Mi cuenta (lectura/actualización propia), Módulos (CRUD; la carga o reemplazo del ZIP requiere actualización), Colaboradores (CRUD) y Productos (CRUD). Los administradores siempre conservan acceso total; los colaboradores nuevos reciben lectura de Inicio y lectura/actualización de su propia cuenta, y un administrador asigna los permisos restantes.
- Los paquetes ZIP de módulos se guardan fuera del directorio web en `C:\xampp\modulos\<clave-del-modulo>` (o en la ruta definida en `PORTAL_MODULE_STORAGE`), con límite de 20 MiB por paquete y 512 MiB en total. Se sirven solo mediante una descarga autenticada y se calcula SHA-256. No se extraen ni ejecutan: la revisión y el despliegue son manuales.
- `panel/cerrar-sesion.php`: cierre de sesión protegido contra CSRF.

Las contraseñas se guardan con `password_hash`; las sesiones de colaboradores se identifican en la base por un hash y el historial usa hora UTC. Los usuarios del portal y los colaboradores del panel usan tablas y flujos de acceso independientes.

La promoción inicial solo puede ejecutarse desde CLI, una única vez mientras no haya un administrador activo; el archivo de inicialización no se puede ejecutar desde HTTP.

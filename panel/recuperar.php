<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
$pageTitle = 'Recuperar contraseña';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= escape($pageTitle) ?> | Panel</title>
  <link rel="stylesheet" href="<?= escape(app_url('panel/assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css')) ?>">
  <link rel="stylesheet" href="<?= escape(app_url('panel/assets/vendor/overlayscrollbars/styles/overlayscrollbars.min.css')) ?>">
  <link rel="stylesheet" href="<?= escape(app_url('panel/assets/vendor/adminlte/dist/css/adminlte.min.css')) ?>">
</head>
<body class="bg-body-tertiary">
<main class="container py-5">
  <div class="row justify-content-center">
    <div class="col-md-8 col-lg-5">
      <div class="card border-0 shadow-sm">
        <div class="card-body p-4 p-lg-5">
          <h1 class="h3">Recuperar contraseña</h1>
          <p class="text-body-secondary">La recuperación está preparada para habilitarse cuando se configure el servicio de correo del portal.</p>
          <div class="alert alert-info" role="status">Por ahora, solicita a quien administra el sistema que te ayude a restablecer el acceso. No se enviará ningún correo desde esta pantalla.</div>
          <a class="btn btn-primary w-100" href="<?= escape(app_url('panel/login.php')) ?>">Volver al inicio de sesión</a>
        </div>
      </div>
    </div>
  </div>
</main>
</body>
</html>

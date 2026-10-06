<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Portal de servicios y recursos para usuarios.">
  <title><?= escape($pageTitle) ?> | Portal</title>
  <link rel="icon" type="image/png" href="<?= escape(app_url('panel/assets/vendor/adminlte/dist/assets/img/AdminLTELogo.png')) ?>">
  <link rel="stylesheet" href="<?= escape(app_url('panel/assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css')) ?>">
  <link rel="stylesheet" href="<?= escape(app_url('panel/assets/vendor/adminlte/dist/css/adminlte.min.css')) ?>">
  <style>
    .portal-brand-mark { width: 2.5rem; height: 2.5rem; }
    .portal-hero { background: linear-gradient(135deg, #eaf2ff, #f8fbff 60%, #e6f7f4); }
    .portal-login { min-width: min(100%, 23rem); }
    .portal-footer-link { color: inherit; text-decoration: none; }
    .portal-footer-link:hover { text-decoration: underline; }
  </style>
</head>
<body class="bg-body-tertiary">
<header class="bg-white border-bottom shadow-sm">
  <nav class="navbar navbar-expand-lg container py-3" aria-label="Navegación principal">
    <a class="navbar-brand d-flex align-items-center gap-2 fw-semibold" href="<?= escape(app_url('')) ?>">
      <span class="portal-brand-mark rounded-circle bg-primary text-white d-inline-flex align-items-center justify-content-center"><i class="bi bi-grid-1x2"></i></span>
      Portal
    </a>
    <?php if (isset($storeContext)): ?>
      <div class="ms-auto d-flex align-items-center flex-wrap justify-content-end gap-2">
        <a class="navbar-brand fs-6 mb-0" href="<?= escape(app_url('tienda.php?usuario=' . rawurlencode($storeContext['nickname']))) ?>">
          <?= escape($storeContext['store_name']) ?>
        </a>
        <?php if ($storeCustomer === null): ?>
          <a class="btn btn-outline-primary btn-sm" href="<?= escape(storefront_customer_url($storeContext, 'cliente-login.php')) ?>">Iniciar sesión</a>
          <a class="btn btn-primary btn-sm" href="<?= escape(storefront_customer_url($storeContext, 'cliente-registro.php')) ?>">Crear cuenta</a>
        <?php else: ?>
          <span class="navbar-text">Hola, <?= escape($storeCustomer['name']) ?></span>
          <a class="btn btn-outline-primary btn-sm" href="<?= escape(storefront_customer_url($storeContext, 'cliente-cuenta.php')) ?>">Mi cuenta y domicilio</a>
          <form method="post" action="<?= escape(storefront_customer_url($storeContext, 'cliente-cuenta.php')) ?>" class="m-0">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="logout">
            <button class="btn btn-outline-secondary btn-sm" type="submit">Cerrar sesión</button>
          </form>
        <?php endif; ?>
      </div>
    <?php elseif ($user === null): ?>
      <div class="ms-auto portal-login">
        <form class="row g-2 align-items-center" method="post" action="<?= escape(app_url('index.php')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="login">
          <div class="col-12 col-sm">
            <label class="visually-hidden" for="portal-login-email">Correo electrónico</label>
            <input class="form-control form-control-sm" type="email" id="portal-login-email" name="email" placeholder="Correo electrónico" autocomplete="username" maxlength="190" required>
          </div>
          <div class="col-12 col-sm">
            <label class="visually-hidden" for="portal-login-password">Contraseña</label>
            <input class="form-control form-control-sm" type="password" id="portal-login-password" name="password" placeholder="Contraseña" autocomplete="current-password" required>
          </div>
          <div class="col-auto">
            <button class="btn btn-primary btn-sm" type="submit">Iniciar sesión</button>
          </div>
        </form>
        <div class="d-flex justify-content-end gap-3 mt-1 small">
          <a href="<?= escape(app_url('recuperar.php')) ?>">¿Olvidaste tu contraseña?</a>
          <a href="<?= escape(app_url('registro.php')) ?>">Crear cuenta</a>
        </div>
      </div>
    <?php else: ?>
      <div class="ms-auto d-flex align-items-center flex-wrap justify-content-end gap-2">
        <span class="navbar-text">Hola, <?= escape($user['name']) ?></span>
        <a class="btn btn-outline-primary btn-sm" href="<?= escape(app_url('cuenta.php')) ?>">Mi cuenta</a>
        <a class="btn btn-outline-primary btn-sm" href="<?= escape(app_url('mis-productos.php')) ?>">Mis productos</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= escape(app_url('tienda.php?usuario=' . rawurlencode($user['nickname']))) ?>">Mi microtienda</a>
        <form method="post" action="<?= escape(app_url('cerrar-sesion.php')) ?>" class="m-0">
          <?= csrf_field() ?>
          <button class="btn btn-outline-secondary btn-sm" type="submit">Cerrar sesión</button>
        </form>
      </div>
    <?php endif; ?>
  </nav>
</header>
<main>

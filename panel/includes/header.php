<?php
$pageTitle = $pageTitle ?? 'Panel';
$activePage = $activePage ?? 'inicio';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Panel de colaboradores">
  <title><?= escape($pageTitle) ?> | Panel</title>
  <link rel="icon" type="image/png" href="<?= escape(app_url('panel/assets/vendor/adminlte/dist/assets/img/AdminLTELogo.png')) ?>">
  <link rel="stylesheet" href="<?= escape(app_url('panel/assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css')) ?>">
  <link rel="stylesheet" href="<?= escape(app_url('panel/assets/vendor/overlayscrollbars/styles/overlayscrollbars.min.css')) ?>">
  <link rel="stylesheet" href="<?= escape(app_url('panel/assets/vendor/adminlte/dist/css/adminlte.min.css')) ?>">
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary">
  <div class="app-wrapper">
    <nav class="app-header navbar navbar-expand bg-body">
      <div class="container-fluid">
        <ul class="navbar-nav">
          <li class="nav-item">
            <a class="nav-link" data-lte-toggle="sidebar" href="#" role="button" aria-label="Mostrar u ocultar menú">
              <i class="bi bi-list"></i>
            </a>
          </li>
        </ul>
        <div class="d-flex flex-wrap align-items-center gap-3 ms-auto">
          <span class="navbar-text"><i class="bi bi-person-circle me-1"></i><?= escape($collaborator['nombre']) ?></span>
          <span class="badge text-bg-primary"><?= escape($collaborator['rol']) ?></span>
          <a class="btn btn-outline-secondary btn-sm" href="<?= escape(app_url('panel/perfil.php')) ?>">Mi perfil</a>
          <form action="<?= escape(app_url('panel/cerrar-sesion.php')) ?>" method="post" class="m-0">
            <?= csrf_field() ?>
            <button class="btn btn-outline-danger btn-sm" type="submit">
              <i class="bi bi-box-arrow-right me-1"></i>Cerrar sesión
            </button>
          </form>
        </div>
      </div>
    </nav>
    <aside class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark">
      <div class="sidebar-brand">
        <a href="<?= escape(app_url('panel/')) ?>" class="brand-link">
          <span class="brand-text fw-light">Panel administrativo</span>
        </a>
      </div>
      <div class="sidebar-wrapper">
        <nav class="mt-2" aria-label="Menú del panel">
          <ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" role="menu">
            <li class="nav-item">
              <a href="<?= escape(app_url('panel/')) ?>" class="nav-link <?= $activePage === 'inicio' ? 'active' : '' ?>">
                <i class="nav-icon bi bi-speedometer2"></i><p>Inicio</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="<?= escape(app_url('panel/perfil.php')) ?>" class="nav-link <?= $activePage === 'perfil' ? 'active' : '' ?>">
                <i class="nav-icon bi bi-person-vcard"></i><p>Mi cuenta</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="<?= escape(app_url('panel/modulos.php')) ?>" class="nav-link <?= $activePage === 'modulos' ? 'active' : '' ?>">
                <i class="nav-icon bi bi-grid"></i><p>Módulos</p>
              </a>
            </li>
            <li class="nav-item">
              <a href="<?= escape(app_url('panel/colaboradores.php')) ?>" class="nav-link <?= $activePage === 'colaboradores' ? 'active' : '' ?>">
                <i class="nav-icon bi bi-people"></i><p>Colaboradores</p>
              </a>
            </li>
          </ul>
        </nav>
      </div>
    </aside>
    <main class="app-main">
      <div class="app-content-header">
        <div class="container-fluid"><h1 class="h3 mb-0"><?= escape($pageTitle) ?></h1></div>
      </div>
      <div class="app-content"><div class="container-fluid">

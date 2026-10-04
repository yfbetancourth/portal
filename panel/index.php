<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth.php';
$collaborator = require_collaborator();
$pageTitle = 'Inicio';
$activePage = 'inicio';
require __DIR__ . '/includes/header.php';
?>
<div class="row g-4">
  <div class="col-xl-4 col-md-6">
    <div class="small-box text-bg-primary">
      <div class="inner">
        <h3><?= escape($collaborator['nombre']) ?></h3>
        <p>Sesión iniciada</p>
      </div>
      <i class="small-box-icon bi bi-person-check"></i>
      <a href="<?= escape(app_url('panel/perfil.php')) ?>" class="small-box-footer link-light link-underline-opacity-0">
        Ver mis datos <i class="bi bi-arrow-right"></i>
      </a>
    </div>
  </div>
  <div class="col-xl-8 col-md-6">
    <div class="card h-100">
      <div class="card-header"><h2 class="card-title h5 mb-0">Cuenta activa</h2></div>
      <div class="card-body">
        <dl class="row mb-0">
          <dt class="col-sm-3">Nombre</dt><dd class="col-sm-9"><?= escape($collaborator['nombre']) ?></dd>
          <dt class="col-sm-3">Correo</dt><dd class="col-sm-9"><?= escape($collaborator['email']) ?></dd>
          <dt class="col-sm-3">Rol</dt><dd class="col-sm-9"><span class="badge text-bg-primary"><?= escape($collaborator['rol']) ?></span></dd>
        </dl>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

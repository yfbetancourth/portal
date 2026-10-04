<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth.php';
$collaborator = require_collaborator();

try {
    $statement = database()->prepare(
        "SELECT event_type, occurred_at
         FROM auth_login_history
         WHERE user_id = :user_id
         ORDER BY occurred_at DESC, id DESC
         LIMIT 10"
    );
    $statement->execute(['user_id' => $collaborator['id']]);
    $history = $statement->fetchAll();
} catch (PDOException $exception) {
    error_log('No se pudo cargar el historial del colaborador: ' . $exception->getMessage());
    http_response_code(500);
    $history = [];
    $historyError = 'No se pudo cargar el historial de acceso.';
}

$pageTitle = 'Mi cuenta';
$activePage = 'perfil';
require __DIR__ . '/includes/header.php';
?>
<div class="row g-4">
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><h2 class="card-title h5 mb-0">Datos de la sesión</h2></div>
      <div class="card-body">
        <dl class="row mb-0">
          <dt class="col-sm-4">Nombre</dt><dd class="col-sm-8"><?= escape($collaborator['nombre']) ?></dd>
          <dt class="col-sm-4">Correo</dt><dd class="col-sm-8"><?= escape($collaborator['email']) ?></dd>
          <dt class="col-sm-4">Rol</dt><dd class="col-sm-8"><span class="badge text-bg-primary"><?= escape($collaborator['rol']) ?></span></dd>
        </dl>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header"><h2 class="card-title h5 mb-0">Últimos accesos</h2></div>
      <div class="card-body p-0">
        <?php if (isset($historyError)): ?><div class="alert alert-danger m-3" role="alert"><?= escape($historyError) ?></div><?php endif; ?>
        <?php if ($history === []): ?>
          <p class="p-3 mb-0 text-body-secondary">Aún no hay accesos registrados.</p>
        <?php else: ?>
          <ul class="list-group list-group-flush">
            <?php foreach ($history as $event): ?>
              <?php $date = new DateTimeImmutable($event['occurred_at'], new DateTimeZone('UTC')); ?>
              <li class="list-group-item d-flex justify-content-between">
                <span><?= $event['event_type'] === 'login' ? 'Inicio de sesión' : 'Cierre de sesión' ?></span>
                <time datetime="<?= escape($date->format('Y-m-d\TH:i:s.u\Z')) ?>"><?= escape($date->format('Y-m-d H:i:s')) ?> UTC</time>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

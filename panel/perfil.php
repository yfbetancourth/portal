<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth.php';
$collaborator = require_collaborator();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission($collaborator, 'profile', 'update');
} elseif (!has_resource_access($collaborator, 'profile')) {
    http_response_code(403);
    exit('No tienes permiso para acceder a esta sección.');
}
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = is_string($_POST['nombre'] ?? null) ? trim($_POST['nombre']) : '';
    $email = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';
    $password = $_POST['password'] ?? null;
    $confirmation = $_POST['password_confirmation'] ?? null;
    $nameLength = preg_match_all('/./us', $name);

    if (!csrf_is_valid()) {
        http_response_code(400);
        $error = 'El formulario venció. Recarga la página e inténtalo de nuevo.';
    } elseif ($nameLength === false || $nameLength < 1 || $nameLength > 100
        || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || !is_string($password) || strlen($password) > 72
        || ($password !== '' && (strlen($password) < 12 || !is_string($confirmation) || !hash_equals($password, $confirmation)))) {
        http_response_code(422);
        $error = 'Revisa el nombre y el correo. Si cambias la contraseña, usa entre 12 y 72 bytes y confírmala.';
    } else {
        $connection = null;
        try {
            $connection = database();
            $connection->beginTransaction();
            $statement = $connection->prepare(
                'UPDATE usuarios SET nombre = :nombre, email = :email WHERE id = :id'
            );
            $statement->execute([
                'nombre' => $name,
                'email' => $email,
                'id' => $collaborator['id'],
            ]);
            if ($password !== '') {
                $statement = $connection->prepare(
                    'UPDATE usuarios SET password_hash = :password_hash WHERE id = :id'
                );
                $statement->execute([
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'id' => $collaborator['id'],
                ]);
                revoke_user_sessions($connection, (int) $collaborator['id']);
            }
            $connection->commit();
            if ($password !== '') {
                close_collaborator_session();
                redirect('panel/login.php');
            }
            redirect('panel/perfil.php');
        } catch (PDOException $exception) {
            if ($connection instanceof PDO && $connection->inTransaction()) {
                $connection->rollBack();
            }
            if ($exception->getCode() === '23000' && ($exception->errorInfo[1] ?? null) === 1062) {
                http_response_code(409);
                $error = 'Ya existe una cuenta con ese correo electrónico.';
            } else {
                error_log('No se pudo actualizar el perfil: ' . $exception->getMessage());
                http_response_code(500);
                $error = 'No se pudo actualizar el perfil. Inténtalo más tarde.';
            }
        }
    }
}

try {
    if (!has_permission($collaborator, 'profile', 'read')) {
        $history = [];
    } else {
        $statement = database()->prepare(
            "SELECT event_type, occurred_at
             FROM auth_login_history
             WHERE user_id = :user_id
             ORDER BY occurred_at DESC, id DESC
             LIMIT 10"
        );
        $statement->execute(['user_id' => $collaborator['id']]);
        $history = $statement->fetchAll();
    }
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
        <?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?= escape($error) ?></div><?php endif; ?>
        <dl class="row mb-0">
          <dt class="col-sm-4">Nombre</dt><dd class="col-sm-8"><?= escape($collaborator['nombre']) ?></dd>
          <dt class="col-sm-4">Correo</dt><dd class="col-sm-8"><?= escape($collaborator['email']) ?></dd>
          <dt class="col-sm-4">Rol</dt><dd class="col-sm-8"><span class="badge text-bg-primary"><?= escape($collaborator['role_name']) ?></span></dd>
        </dl>
        <?php if (has_permission($collaborator, 'profile', 'update')): ?>
          <hr>
          <h3 class="h6">Actualizar mis datos</h3>
          <form method="post" action="<?= escape(app_url('panel/perfil.php')) ?>">
            <?= csrf_field() ?>
            <div class="mb-3">
              <label class="form-label" for="profile-name">Nombre</label>
              <input class="form-control" id="profile-name" name="nombre" value="<?= escape($collaborator['nombre']) ?>" maxlength="100" required>
            </div>
            <div class="mb-3">
              <label class="form-label" for="profile-email">Correo</label>
              <input class="form-control" type="email" id="profile-email" name="email" value="<?= escape($collaborator['email']) ?>" maxlength="190" required>
            </div>
            <div class="mb-3">
              <label class="form-label" for="profile-password">Nueva contraseña (opcional)</label>
              <input class="form-control" type="password" id="profile-password" name="password" minlength="12" maxlength="72" autocomplete="new-password">
            </div>
            <div class="mb-3">
              <label class="form-label" for="profile-password-confirmation">Confirmar nueva contraseña</label>
              <input class="form-control" type="password" id="profile-password-confirmation" name="password_confirmation" minlength="12" maxlength="72" autocomplete="new-password">
            </div>
            <button class="btn btn-primary" type="submit">Guardar cambios</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php if (has_permission($collaborator, 'profile', 'read')): ?><div class="col-lg-7">
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
      </div><?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

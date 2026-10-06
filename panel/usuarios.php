<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth.php';
$collaborator = require_admin();

$error = null;
$success = null;
$formValues = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid()) {
        http_response_code(400);
        $error = 'El formulario venció. Recarga la página e inténtalo de nuevo.';
    } else {
        $userId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
        $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
        $nameLength = preg_match_all('/./us', $name);

        if ($userId === false || $nameLength === false || $nameLength < 1 || $nameLength > 100
            || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(422);
            $error = 'Revisa el nombre y el correo electrónico.';
            if (is_int($userId)) {
                $formValues[$userId] = ['name' => $name, 'email' => $email];
            }
        } else {
            try {
                $statement = database()->prepare(
                    'UPDATE portal_users SET name = :name, email = :email WHERE id = :id'
                );
                $statement->execute([
                    'name' => $name,
                    'email' => $email,
                    'id' => $userId,
                ]);
                if ($statement->rowCount() === 0) {
                    $exists = database()->prepare('SELECT 1 FROM portal_users WHERE id = :id');
                    $exists->execute(['id' => $userId]);
                    if (!$exists->fetchColumn()) {
                        http_response_code(404);
                        $error = 'No se encontró la cuenta seleccionada.';
                    }
                }
                if ($error === null) {
                    $success = 'Se guardaron los datos de la cuenta.';
                }
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000' && ($exception->errorInfo[1] ?? null) === 1062) {
                    http_response_code(409);
                    $error = 'Ese correo electrónico ya está asociado a otra cuenta.';
                    $formValues[$userId] = ['name' => $name, 'email' => $email];
                } else {
                    error_log('No se pudo actualizar la cuenta del portal desde el panel: ' . $exception->getMessage());
                    http_response_code(500);
                    $error = 'No se pudo guardar la cuenta. Inténtalo más tarde.';
                }
            }
        }
    }
}

try {
    $users = database()->query(
        'SELECT id, name, email, created_at, updated_at FROM portal_users ORDER BY name, id'
    )->fetchAll();
} catch (PDOException $exception) {
    error_log('No se pudo cargar el módulo de usuarios del portal: ' . $exception->getMessage());
    http_response_code(500);
    $users = [];
    $loadError = 'No se pudo cargar la lista de usuarios del portal.';
}

$pageTitle = 'Usuarios';
$activePage = 'usuarios';
require __DIR__ . '/includes/header.php';
?>
<div class="alert alert-info" role="status">
  Desde este módulo puedes modificar únicamente el nombre y el correo de las cuentas del portal. Las contraseñas y la eliminación de cuentas no se gestionan aquí.
</div>

<?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?= escape($error) ?></div><?php endif; ?>
<?php if ($success !== null): ?><div class="alert alert-success" role="status"><?= escape($success) ?></div><?php endif; ?>

<div class="card">
  <div class="card-header">
    <h2 class="card-title h5 mb-0">Cuentas del portal</h2>
  </div>
  <div class="card-body p-0">
    <?php if (isset($loadError)): ?>
      <div class="alert alert-danger m-3" role="alert"><?= escape($loadError) ?></div>
    <?php elseif ($users === []): ?>
      <p class="p-3 mb-0 text-body-secondary">Todavía no hay cuentas registradas en el portal.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th scope="col">Nombre y correo</th>
              <th scope="col">Registro</th>
              <th scope="col">Acción</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $user): ?>
              <?php
              $userId = (int) $user['id'];
              $formId = 'portal-user-' . $userId;
              $values = $formValues[$userId] ?? $user;
              ?>
              <tr>
                <td>
                  <form id="<?= escape($formId) ?>" method="post" action="<?= escape(app_url('panel/usuarios.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= escape($userId) ?>">
                    <label class="visually-hidden" for="<?= escape($formId) ?>-name">Nombre</label>
                    <input class="form-control form-control-sm mb-2" id="<?= escape($formId) ?>-name" name="name" value="<?= escape($values['name']) ?>" maxlength="100" required>
                    <label class="visually-hidden" for="<?= escape($formId) ?>-email">Correo electrónico</label>
                    <input class="form-control form-control-sm" type="email" id="<?= escape($formId) ?>-email" name="email" value="<?= escape($values['email']) ?>" maxlength="190" required>
                  </form>
                </td>
                <td>
                  <time datetime="<?= escape($user['created_at']) ?>"><?= escape($user['created_at']) ?></time>
                </td>
                <td>
                  <button class="btn btn-primary btn-sm" type="submit" form="<?= escape($formId) ?>">
                    <i class="bi bi-check-lg me-1"></i>Guardar
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

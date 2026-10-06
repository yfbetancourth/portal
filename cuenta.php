<?php
declare(strict_types=1);

require __DIR__ . '/includes/portal_users.php';
require_once __DIR__ . '/includes/product_images.php';
$user = require_portal_user();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid()) {
        http_response_code(400);
        $error = 'El formulario venció. Recarga la página e inténtalo de nuevo.';
    } else {
        $action = $_POST['action'] ?? null;
        $currentPassword = $_POST['current_password'] ?? null;
        if (!is_string($currentPassword) || $currentPassword === '' || strlen($currentPassword) > 4096) {
            http_response_code(422);
            $error = 'Ingresa tu contraseña actual para confirmar la operación.';
        } else {
            try {
                $statement = database()->prepare(
                    'SELECT password_hash FROM portal_users WHERE id = :id LIMIT 1'
                );
                $statement->execute(['id' => $user['id']]);
                $passwordHash = $statement->fetchColumn();
                if (!is_string($passwordHash) || !password_verify($currentPassword, $passwordHash)) {
                    http_response_code(403);
                    $error = 'La contraseña actual no es correcta.';
                } elseif ($action === 'update') {
                    $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
                    $storeName = is_string($_POST['store_name'] ?? null) ? trim($_POST['store_name']) : '';
                    $storeSlug = portal_store_slug($storeName);
                    $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
                    $newPassword = $_POST['new_password'] ?? '';
                    $confirmation = $_POST['password_confirmation'] ?? '';
                    $nameLength = preg_match_all('/./us', $name);
                    $storeNameLength = preg_match_all('/./us', $storeName);
                    $passwordProvided = is_string($newPassword) && $newPassword !== '';
                    if ($nameLength === false || $nameLength < 1 || $nameLength > 100
                        || $storeNameLength === false || $storeNameLength < 1 || $storeNameLength > 100
                        || $storeSlug === null
                        || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)
                        || !is_string($newPassword) || !is_string($confirmation)
                        || ($passwordProvided && (strlen($newPassword) < 12 || strlen($newPassword) > 72
                            || !hash_equals($newPassword, $confirmation)))
                        || (!$passwordProvided && $confirmation !== '')) {
                        http_response_code(422);
                        $error = 'Revisa el nombre, microtienda, correo y contraseña. El nombre de microtienda debe generar un slug de 3 a 50 caracteres. Para cambiar la contraseña, usa de 12 a 72 bytes y confirma el mismo valor.';
                    } else {
                        $sql = 'UPDATE portal_users
                                SET name = :name, store_name = :store_name, nickname = :nickname, email = :email';
                        $parameters = [
                            'name' => $name,
                            'store_name' => $storeName,
                            'nickname' => $storeSlug,
                            'email' => $email,
                            'id' => $user['id'],
                        ];
                        if ($passwordProvided) {
                            $sql .= ', password_hash = :password_hash';
                            $parameters['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
                        }
                        $sql .= ' WHERE id = :id';
                        $connection = database();
                        $connection->beginTransaction();
                        $statement = $connection->prepare($sql);
                        $statement->execute($parameters);
                        if ($passwordProvided) {
                            $statement = $connection->prepare(
                                'DELETE FROM portal_password_resets WHERE user_id = :user_id'
                            );
                            $statement->execute(['user_id' => $user['id']]);
                        }
                        $connection->commit();
                        redirect('cuenta.php');
                    }
                } elseif ($action === 'delete') {
                    $connection = database();
                    $statement = $connection->prepare(
                        'SELECT i.file_name
                         FROM product_images i
                         INNER JOIN products p ON p.id = i.product_id
                         WHERE p.portal_user_id = :user_id'
                    );
                    $statement->execute(['user_id' => $user['id']]);
                    $imageFiles = $statement->fetchAll(PDO::FETCH_COLUMN);
                    $statement = $connection->prepare('DELETE FROM portal_users WHERE id = :id');
                    $statement->execute(['id' => $user['id']]);
                    foreach ($imageFiles as $imageFile) {
                        remove_product_image_file($imageFile);
                    }
                    end_portal_user_session();
                    redirect('');
                } else {
                    http_response_code(400);
                    $error = 'La operación solicitada no es válida.';
                }
            } catch (PDOException $exception) {
                if (isset($connection) && $connection instanceof PDO && $connection->inTransaction()) {
                    $connection->rollBack();
                }
                if ($exception->getCode() === '23000' && ($exception->errorInfo[1] ?? null) === 1062) {
                    http_response_code(409);
                    $error = 'Ya existe una cuenta con ese correo o una microtienda con un slug generado igual. Prueba otro nombre de microtienda.';
                } else {
                    error_log('No se pudo gestionar la cuenta del portal: ' . $exception->getMessage());
                    http_response_code(500);
                    $error = 'No se pudo completar la operación. Inténtalo más tarde.';
                }
            }
        }
    }
}

$user = portal_user();
if ($user === null) {
    redirect('');
}
portal_page_start('Gestión de mi cuenta', $user);
?>
<section class="container py-5">
  <div class="row justify-content-center">
    <div class="col-lg-8">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3">
          <h1 class="h4 mb-0"><i class="bi bi-person-gear text-primary me-2"></i>Gestión de mi cuenta</h1>
        </div>
        <div class="card-body p-4">
          <p class="text-body-secondary">Solo puedes administrar tu propia cuenta del portal. Esta cuenta es independiente de la cuenta de colaborador.</p>
          <?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?= escape($error) ?></div><?php endif; ?>
          <form method="post" action="<?= escape(app_url('cuenta.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <div class="mb-3">
              <label class="form-label" for="name">Nombre completo</label>
              <input class="form-control" id="name" name="name" value="<?= escape($user['name']) ?>" maxlength="100" autocomplete="name" required>
            </div>
            <div class="mb-3">
              <label class="form-label" for="store_name">Nombre de la microtienda</label>
              <input class="form-control" id="store_name" name="store_name" value="<?= escape($user['store_name']) ?>" maxlength="100" autocomplete="organization" required>
              <div class="form-text">El slug del enlace público se genera automáticamente con este nombre. Si lo cambias, también cambiará el enlace de la microtienda.</div>
            </div>
            <div class="mb-3">
              <label class="form-label" for="email">Correo electrónico</label>
              <input class="form-control" type="email" id="email" name="email" value="<?= escape($user['email']) ?>" maxlength="190" autocomplete="email" required>
            </div>
            <hr>
            <h2 class="h6">Cambiar contraseña (opcional)</h2>
            <div class="mb-3">
              <label class="form-label" for="new_password">Nueva contraseña</label>
              <input class="form-control" type="password" id="new_password" name="new_password" minlength="12" maxlength="72" autocomplete="new-password">
            </div>
            <div class="mb-3">
              <label class="form-label" for="password_confirmation">Confirmar nueva contraseña</label>
              <input class="form-control" type="password" id="password_confirmation" name="password_confirmation" minlength="12" maxlength="72" autocomplete="new-password">
            </div>
            <div class="mb-4">
              <label class="form-label" for="current_password">Contraseña actual</label>
              <input class="form-control" type="password" id="current_password" name="current_password" autocomplete="current-password" required>
            </div>
            <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg me-1"></i>Guardar cambios</button>
          </form>
          <hr class="my-4">
          <h2 class="h6 text-danger">Eliminar mi cuenta</h2>
          <p class="small text-body-secondary">Esta acción es permanente y eliminará tus datos del portal.</p>
          <form method="post" action="<?= escape(app_url('cuenta.php')) ?>" onsubmit="return confirm('¿Eliminar permanentemente tu cuenta del portal?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <label class="form-label" for="delete-current-password">Confirma tu contraseña actual</label>
            <input class="form-control mb-3" type="password" id="delete-current-password" name="current_password" autocomplete="current-password" required>
            <button class="btn btn-outline-danger" type="submit"><i class="bi bi-trash me-1"></i>Eliminar mi cuenta</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</section>
<?php portal_page_end($user); ?>

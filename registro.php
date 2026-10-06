<?php
declare(strict_types=1);

require __DIR__ . '/includes/portal_users.php';

$user = portal_user();
if ($user !== null) {
    redirect('cuenta.php');
}

$name = '';
$storeName = '';
$email = '';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
    $storeName = is_string($_POST['store_name'] ?? null) ? trim($_POST['store_name']) : '';
    $storeSlug = portal_store_slug($storeName);
    $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
    $password = $_POST['password'] ?? null;
    $confirmation = $_POST['password_confirmation'] ?? null;
    $nameLength = preg_match_all('/./us', $name);

    $storeNameLength = preg_match_all('/./us', $storeName);

    if (!csrf_is_valid()) {
        http_response_code(400);
        $error = 'El formulario venció. Recarga la página e inténtalo de nuevo.';
    } elseif ($nameLength === false || $nameLength < 1 || $nameLength > 100
        || $storeNameLength === false || $storeNameLength < 1 || $storeNameLength > 100
        || $storeSlug === null
        || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || !is_string($password) || strlen($password) < 12 || strlen($password) > 72
        || !is_string($confirmation) || !hash_equals($password, $confirmation)) {
        http_response_code(422);
        $error = 'Revisa tus datos y el nombre de la microtienda. Debe generar un slug de 3 a 50 caracteres; la contraseña debe tener entre 12 y 72 bytes y ambas deben coincidir.';
    } else {
        try {
            $statement = database()->prepare(
                'INSERT INTO portal_users (name, store_name, nickname, email, password_hash)
                 VALUES (:name, :store_name, :nickname, :email, :password_hash)'
            );
            $statement->execute([
                'name' => $name,
                'store_name' => $storeName,
                'nickname' => $storeSlug,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            start_portal_user_session((int) database()->lastInsertId());
            redirect('');
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000' && ($exception->errorInfo[1] ?? null) === 1062) {
                http_response_code(409);
                $error = 'Ya existe una cuenta con ese correo o una microtienda con un slug generado igual. Prueba otro nombre de microtienda.';
            } else {
                error_log('No se pudo registrar el usuario del portal: ' . $exception->getMessage());
                http_response_code(500);
                $error = 'No se pudo crear la cuenta. Inténtalo más tarde.';
            }
        }
    }
}

portal_page_start('Crear cuenta');
?>
<section class="container py-5">
  <div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
      <div class="card border-0 shadow-sm">
        <div class="card-body p-4 p-lg-5">
          <div class="text-center mb-4">
            <i class="bi bi-person-plus display-4 text-primary"></i>
            <h1 class="h3 mt-3">Crear cuenta del portal</h1>
            <p class="text-body-secondary">Esta cuenta es independiente de las cuentas de colaboradores.</p>
          </div>
          <?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?= escape($error) ?></div><?php endif; ?>
          <form method="post" action="<?= escape(app_url('registro.php')) ?>">
            <?= csrf_field() ?>
            <div class="mb-3">
              <label class="form-label" for="name">Nombre completo</label>
              <input class="form-control" id="name" name="name" value="<?= escape($name) ?>" maxlength="100" autocomplete="name" required>
            </div>
            <div class="mb-3">
              <label class="form-label" for="store_name">Nombre de tu microtienda</label>
              <input class="form-control" id="store_name" name="store_name" value="<?= escape($storeName) ?>" maxlength="100" autocomplete="organization" required>
              <div class="form-text">El enlace se generará automáticamente a partir de este nombre; se convertirán los acentos y espacios a un slug web.</div>
            </div>
            <div class="mb-3">
              <label class="form-label" for="email">Correo electrónico</label>
              <input class="form-control" type="email" id="email" name="email" value="<?= escape($email) ?>" maxlength="190" autocomplete="email" required>
            </div>
            <div class="mb-3">
              <label class="form-label" for="password">Contraseña</label>
              <input class="form-control" type="password" id="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required>
              <div class="form-text">Usa al menos 12 caracteres.</div>
            </div>
            <div class="mb-4">
              <label class="form-label" for="password_confirmation">Confirmar contraseña</label>
              <input class="form-control" type="password" id="password_confirmation" name="password_confirmation" minlength="12" maxlength="72" autocomplete="new-password" required>
            </div>
            <button class="btn btn-primary w-100" type="submit">Crear cuenta</button>
          </form>
          <p class="text-center mt-4 mb-0"><a href="<?= escape(app_url('')) ?>">Volver al portal</a></p>
        </div>
      </div>
    </div>
  </div>
</section>
<?php portal_page_end(); ?>

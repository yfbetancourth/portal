<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/permissions.php';

$email = '';
$name = '';
$error = null;

if (isset($_SESSION['user_id']) && is_int($_SESSION['user_id'])) {
    redirect('panel/');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedName = $_POST['nombre'] ?? null;
    $submittedEmail = $_POST['email'] ?? null;
    $password = $_POST['password'] ?? null;
    $confirmation = $_POST['password_confirmation'] ?? null;
    $name = is_string($submittedName) ? trim($submittedName) : '';
    $email = is_string($submittedEmail) ? trim($submittedEmail) : '';
    $nameLength = preg_match_all('/./us', $name);

    if (!csrf_is_valid()) {
        http_response_code(400);
        $error = 'El formulario venció. Recarga la página e inténtalo de nuevo.';
    } elseif ($nameLength === false || $nameLength < 1 || $nameLength > 100
        || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || !is_string($password) || strlen($password) < 12 || strlen($password) > 72
        || !is_string($confirmation) || !hash_equals($password, $confirmation)) {
        $error = 'Revisa los datos. La contraseña debe tener de 12 a 72 bytes y coincidir con la confirmación.';
    } else {
        $connection = null;
        try {
            $connection = database();
            $connection->beginTransaction();
            $statement = $connection->prepare(
                "INSERT INTO usuarios (nombre, email, password_hash, rol)
                 VALUES (:nombre, :email, :password_hash, 'colaborador')"
            );
            $statement->execute([
                'nombre' => $name,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            $userId = (int) $connection->lastInsertId();
            grant_default_collaborator_permissions($connection, $userId);
            $connection->commit();
            start_collaborator_session($userId);
            redirect('panel/');
        } catch (PDOException $exception) {
            if ($connection instanceof PDO && $connection->inTransaction()) {
                $connection->rollBack();
            }
            if ($exception->getCode() === '23000' && ($exception->errorInfo[1] ?? null) === 1062) {
                $error = 'Ya existe una cuenta con ese correo electrónico.';
            } else {
                error_log('Error al registrar un colaborador: ' . $exception->getMessage());
                http_response_code(500);
                $error = 'No se pudo crear la cuenta. Inténtalo más tarde.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Crear colaborador</title>
  <link rel="stylesheet" href="<?= escape(app_url('panel/assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css')) ?>">
  <link rel="stylesheet" href="<?= escape(app_url('panel/assets/vendor/overlayscrollbars/styles/overlayscrollbars.min.css')) ?>">
  <link rel="stylesheet" href="<?= escape(app_url('panel/assets/vendor/adminlte/dist/css/adminlte.min.css')) ?>">
</head>
<body class="bg-body-tertiary">
<main class="container py-5">
  <div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
      <div class="card border-0 shadow-sm">
        <div class="card-body p-4 p-lg-5">
          <h1 class="h3 text-center mb-2">Crear cuenta de colaborador</h1>
          <p class="text-body-secondary text-center mb-4">Completa tus datos para crear una cuenta y acceder al panel.</p>
          <?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?= escape($error) ?></div><?php endif; ?>
          <form method="post" action="<?= escape(app_url('panel/registro.php')) ?>">
            <?= csrf_field() ?>
            <div class="mb-3">
              <label for="nombre" class="form-label">Nombre completo</label>
              <input class="form-control" id="nombre" name="nombre" value="<?= escape($name) ?>" maxlength="100" autocomplete="name" required>
            </div>
            <div class="mb-3">
              <label for="email" class="form-label">Correo electrónico</label>
              <input type="email" class="form-control" id="email" name="email" value="<?= escape($email) ?>" maxlength="190" autocomplete="email" required>
            </div>
            <div class="mb-3">
              <label for="password" class="form-label">Contraseña</label>
              <input type="password" class="form-control" id="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required>
              <div class="form-text">Usa al menos 12 caracteres.</div>
            </div>
            <div class="mb-4">
              <label for="password_confirmation" class="form-label">Confirmar contraseña</label>
              <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" minlength="12" maxlength="72" autocomplete="new-password" required>
            </div>
            <button class="btn btn-primary w-100" type="submit">Crear colaborador</button>
          </form>
          <p class="text-center mt-4 mb-0"><a href="<?= escape(app_url('panel/login.php')) ?>">Volver al inicio de sesión</a></p>
        </div>
      </div>
    </div>
  </div>
</main>
</body>
</html>

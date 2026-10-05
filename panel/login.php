<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
$email = '';
$error = null;

if (isset($_SESSION['user_id']) && is_int($_SESSION['user_id'])) {
    try {
        $statement = database()->prepare(
            "SELECT u.id FROM usuarios u
             INNER JOIN auth_sessions s ON s.user_id = u.id
             WHERE u.id = :id AND u.activo = 1 AND u.rol IN ('administrador', 'colaborador')
               AND s.session_hash = :session_hash AND s.ended_at IS NULL
             LIMIT 1"
        );
        $sessionHash = $_SESSION['auth_session_hash'] ?? null;
        if (!is_string($sessionHash) || !hash_equals($sessionHash, hash('sha256', session_id()))) {
            close_collaborator_session();
            redirect('panel/login.php');
        }
        $statement->execute([
            'id' => $_SESSION['user_id'],
            'session_hash' => $sessionHash,
        ]);
        if ($statement->fetchColumn()) {
            redirect('panel/');
        }
    } catch (PDOException $exception) {
        error_log('No se pudo comprobar la sesión del colaborador: ' . $exception->getMessage());
        http_response_code(500);
        $error = 'No se pudo verificar la sesión. Inténtalo más tarde.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedEmail = $_POST['email'] ?? null;
    $email = is_string($submittedEmail) ? trim($submittedEmail) : '';
    $password = $_POST['password'] ?? null;

    if (!csrf_is_valid()) {
        http_response_code(400);
        $error = 'El formulario venció. Recarga la página e inténtalo de nuevo.';
    } elseif (!is_string($password) || strlen($password) > 4096
        || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Correo o contraseña incorrectos, o la cuenta no está habilitada para el panel.';
    } else {
        try {
            $statement = database()->prepare(
                "SELECT id, password_hash
                 FROM usuarios
                 WHERE email = :email AND activo = 1 AND rol IN ('administrador', 'colaborador')
                 LIMIT 1"
            );
            $statement->execute(['email' => $email]);
            $user = $statement->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                    $update = database()->prepare('UPDATE usuarios SET password_hash = :hash WHERE id = :id');
                    $update->execute([
                        'hash' => password_hash($password, PASSWORD_DEFAULT),
                        'id' => $user['id'],
                    ]);
                }
                start_collaborator_session((int) $user['id']);
                redirect('panel/');
            }
            $error = 'Correo o contraseña incorrectos, o la cuenta no está habilitada para el panel.';
        } catch (PDOException $exception) {
            error_log('Error al iniciar sesión en el panel: ' . $exception->getMessage());
            http_response_code(500);
            $error = 'No se pudo completar el inicio de sesión. Inténtalo más tarde.';
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Acceso al panel</title>
  <link rel="icon" type="image/png" href="<?= escape(app_url('panel/assets/vendor/adminlte/dist/assets/img/AdminLTELogo.png')) ?>">
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
          <div class="text-center mb-4">
            <i class="bi bi-shield-lock display-4 text-primary"></i>
            <h1 class="h3 mt-3">Acceso al panel</h1>
            <p class="text-body-secondary">Ingresa con tu cuenta de colaborador.</p>
          </div>
          <?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?= escape($error) ?></div><?php endif; ?>
          <form method="post" action="<?= escape(app_url('panel/login.php')) ?>">
            <?= csrf_field() ?>
            <div class="mb-3">
              <label for="email" class="form-label">Correo electrónico</label>
              <input type="email" class="form-control" id="email" name="email" value="<?= escape($email) ?>" maxlength="190" autocomplete="username" required autofocus>
            </div>
            <div class="mb-3">
              <label for="password" class="form-label">Contraseña</label>
              <input type="password" class="form-control" id="password" name="password" autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Iniciar sesión</button>
          </form>
          <div class="d-flex justify-content-between mt-4">
            <a href="<?= escape(app_url('panel/recuperar.php')) ?>">¿Olvidaste tu contraseña?</a>
            <a href="<?= escape(app_url('panel/registro.php')) ?>">Crear colaborador</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</main>
</body>
</html>

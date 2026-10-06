<?php
declare(strict_types=1);

require __DIR__ . '/includes/portal_users.php';

$user = portal_user();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid()) {
        http_response_code(400);
        $error = 'El formulario venció. Recarga la página e inténtalo de nuevo.';
    } elseif (($_POST['action'] ?? null) !== 'login') {
        http_response_code(400);
        $error = 'La operación solicitada no es válida.';
    } else {
        $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
        $password = $_POST['password'] ?? null;

        if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || !is_string($password) || strlen($password) > 4096) {
            http_response_code(422);
            $error = 'Correo o contraseña incorrectos.';
        } else {
            try {
                $statement = database()->prepare(
                    'SELECT id, password_hash FROM portal_users WHERE email = :email LIMIT 1'
                );
                $statement->execute(['email' => $email]);
                $account = $statement->fetch();
                if ($account && password_verify($password, $account['password_hash'])) {
                    if (password_needs_rehash($account['password_hash'], PASSWORD_DEFAULT)) {
                        $update = database()->prepare(
                            'UPDATE portal_users SET password_hash = :password_hash WHERE id = :id'
                        );
                        $update->execute([
                            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                            'id' => $account['id'],
                        ]);
                    }
                    start_portal_user_session((int) $account['id']);
                    redirect('');
                }
                http_response_code(401);
                $error = 'Correo o contraseña incorrectos.';
            } catch (PDOException $exception) {
                error_log('No se pudo iniciar sesión en el portal: ' . $exception->getMessage());
                http_response_code(500);
                $error = 'No se pudo iniciar sesión. Inténtalo más tarde.';
            }
        }
    }
}

portal_page_start('Inicio', $user);
?>
<section class="portal-hero py-5 py-lg-6">
  <div class="container py-4">
    <div class="row align-items-center g-5">
      <div class="col-lg-7">
        <span class="badge rounded-pill text-bg-primary mb-3">Bienvenido al portal</span>
        <h1 class="display-4 fw-bold text-dark">Todo lo que necesitas, en un solo lugar.</h1>
        <p class="lead text-body-secondary mt-3 mb-4">Accede a tus recursos y mantén tu cuenta actualizada de forma segura.</p>
        <?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?= escape($error) ?></div><?php endif; ?>
        <?php if ($user === null): ?>
          <a class="btn btn-primary btn-lg" href="<?= escape(app_url('registro.php')) ?>"><i class="bi bi-person-plus me-2"></i>Crear una cuenta</a>
          <a class="btn btn-outline-primary btn-lg ms-2" href="<?= escape(app_url('recuperar.php')) ?>">Recuperar contraseña</a>
        <?php else: ?>
          <p class="h5 mb-3">Tu sesión está activa, <?= escape($user['name']) ?>.</p>
          <a class="btn btn-primary btn-lg" href="<?= escape(app_url('cuenta.php')) ?>"><i class="bi bi-person-gear me-2"></i>Gestionar mi cuenta</a>
        <?php endif; ?>
      </div>
      <div class="col-lg-5">
        <div class="card border-0 shadow">
          <div class="card-body p-4 p-lg-5">
            <div class="rounded-circle bg-primary-subtle text-primary d-inline-flex align-items-center justify-content-center mb-3" style="width:3.5rem;height:3.5rem">
              <i class="bi bi-shield-check fs-3"></i>
            </div>
            <h2 class="h4">Tu cuenta, bajo tu control</h2>
            <p class="text-body-secondary mb-0">Actualiza tus datos y contraseña desde la sección de gestión de cuenta, disponible en el pie de página cuando inicias sesión.</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
<section class="container py-5">
  <div class="row g-4">
    <div class="col-md-4">
      <article class="card h-100 border-0 shadow-sm">
        <div class="card-body p-4">
          <i class="bi bi-box-arrow-in-right fs-3 text-primary"></i>
          <h2 class="h5 mt-3">Acceso sencillo</h2>
          <p class="text-body-secondary mb-0">Inicia sesión desde la parte superior con tu correo y contraseña.</p>
        </div>
      </article>
    </div>
    <div class="col-md-4">
      <article class="card h-100 border-0 shadow-sm">
        <div class="card-body p-4">
          <i class="bi bi-key fs-3 text-primary"></i>
          <h2 class="h5 mt-3">Recuperación de contraseña</h2>
          <p class="text-body-secondary mb-0">Solicita un enlace de restablecimiento si no recuerdas tu contraseña.</p>
        </div>
      </article>
    </div>
    <div class="col-md-4">
      <article class="card h-100 border-0 shadow-sm">
        <div class="card-body p-4">
          <i class="bi bi-person-vcard fs-3 text-primary"></i>
          <h2 class="h5 mt-3">Gestión personal</h2>
          <p class="text-body-secondary mb-0">Administra únicamente tu propia información y credenciales.</p>
        </div>
      </article>
    </div>
  </div>
</section>
<?php portal_page_end($user); ?>

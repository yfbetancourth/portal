<?php
declare(strict_types=1);

require __DIR__ . '/includes/store_customers.php';

$store = require_storefront_owner();
$customer = storefront_customer((int) $store['id']);
if ($customer !== null) {
    redirect('cliente-cuenta.php?tienda=' . rawurlencode($store['nickname']));
}

$email = '';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
    $password = $_POST['password'] ?? null;
    if (!csrf_is_valid()) {
        http_response_code(400);
        $error = 'El formulario venció. Recarga la página e inténtalo de nuevo.';
    } elseif (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || !is_string($password) || strlen($password) > 4096) {
        http_response_code(422);
        $error = 'Correo o contraseña incorrectos.';
    } else {
        try {
            $statement = database()->prepare(
                'SELECT id, password_hash
                 FROM store_customers
                 WHERE portal_user_id = :store_id AND email = :email LIMIT 1'
            );
            $statement->execute(['store_id' => $store['id'], 'email' => $email]);
            $account = $statement->fetch();
            if ($account && password_verify($password, $account['password_hash'])) {
                if (password_needs_rehash($account['password_hash'], PASSWORD_DEFAULT)) {
                    $update = database()->prepare(
                        'UPDATE store_customers SET password_hash = :password_hash WHERE id = :id'
                    );
                    $update->execute([
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        'id' => $account['id'],
                    ]);
                }
                start_storefront_customer_session((int) $account['id'], (int) $store['id']);
                redirect('cliente-cuenta.php?tienda=' . rawurlencode($store['nickname']));
            }
            http_response_code(401);
            $error = 'Correo o contraseña incorrectos.';
        } catch (PDOException $exception) {
            error_log('No se pudo iniciar sesión de cliente en una microtienda: ' . $exception->getMessage());
            http_response_code(500);
            $error = 'No se pudo iniciar sesión. Inténtalo más tarde.';
        }
    }
}

storefront_customer_page_start('Iniciar sesión de cliente', $store);
?>
<section class="container py-5">
  <div class="row justify-content-center">
    <div class="col-md-8 col-lg-5">
      <div class="card border-0 shadow-sm">
        <div class="card-body p-4 p-lg-5">
          <div class="text-center mb-4">
            <i class="bi bi-person-check display-4 text-primary"></i>
            <h1 class="h3 mt-3">Iniciar sesión</h1>
            <p class="text-body-secondary">Accede a tu cuenta de <?= escape($store['store_name']) ?>.</p>
          </div>
          <?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?= escape($error) ?></div><?php endif; ?>
          <form method="post" action="<?= escape(storefront_customer_url($store, 'cliente-login.php')) ?>">
            <?= csrf_field() ?>
            <div class="mb-3">
              <label class="form-label" for="email">Correo electrónico</label>
              <input class="form-control" type="email" id="email" name="email" value="<?= escape($email) ?>" maxlength="190" autocomplete="username" required>
            </div>
            <div class="mb-4">
              <label class="form-label" for="password">Contraseña</label>
              <input class="form-control" type="password" id="password" name="password" autocomplete="current-password" required>
            </div>
            <button class="btn btn-primary w-100" type="submit">Iniciar sesión</button>
          </form>
          <div class="d-flex justify-content-between mt-4 small">
            <a href="<?= escape(storefront_customer_url($store, 'cliente-recuperar.php')) ?>">Recuperar contraseña</a>
            <a href="<?= escape(storefront_customer_url($store, 'cliente-registro.php')) ?>">Crear cuenta</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
<?php storefront_customer_page_end($store); ?>

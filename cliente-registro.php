<?php
declare(strict_types=1);

require __DIR__ . '/includes/store_customers.php';

$store = require_storefront_owner();
$customer = storefront_customer((int) $store['id']);
if ($customer !== null) {
    redirect('cliente-cuenta.php?tienda=' . rawurlencode($store['nickname']));
}

$name = '';
$email = '';
$deliveryAddress = '';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
    $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
    $deliveryAddress = is_string($_POST['delivery_address'] ?? null)
        ? trim($_POST['delivery_address'])
        : '';
    $password = $_POST['password'] ?? null;
    $confirmation = $_POST['password_confirmation'] ?? null;
    $nameLength = preg_match_all('/./us', $name);
    $addressLength = preg_match_all('/./us', $deliveryAddress);

    if (!csrf_is_valid()) {
        http_response_code(400);
        $error = 'El formulario venció. Recarga la página e inténtalo de nuevo.';
    } elseif ($nameLength === false || $nameLength < 1 || $nameLength > 100
        || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || $addressLength === false || $addressLength > 500
        || !is_string($password) || strlen($password) < 12 || strlen($password) > 72
        || !is_string($confirmation) || !hash_equals($password, $confirmation)) {
        http_response_code(422);
        $error = 'Revisa tu nombre, correo y domicilio. La contraseña debe tener de 12 a 72 bytes y ambas deben coincidir.';
    } else {
        try {
            $statement = database()->prepare(
                'INSERT INTO store_customers
                    (portal_user_id, name, email, password_hash, delivery_address)
                 VALUES (:store_id, :name, :email, :password_hash, :delivery_address)'
            );
            $statement->execute([
                'store_id' => $store['id'],
                'name' => $name,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'delivery_address' => $deliveryAddress,
            ]);
            start_storefront_customer_session((int) database()->lastInsertId(), (int) $store['id']);
            redirect('cliente-cuenta.php?tienda=' . rawurlencode($store['nickname']));
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000' && ($exception->errorInfo[1] ?? null) === 1062) {
                http_response_code(409);
                $error = 'Ya existe una cuenta de cliente con ese correo en esta microtienda.';
            } else {
                error_log('No se pudo crear una cuenta de cliente de microtienda: ' . $exception->getMessage());
                http_response_code(500);
                $error = 'No se pudo crear la cuenta. Inténtalo más tarde.';
            }
        }
    }
}

storefront_customer_page_start('Crear cuenta de cliente', $store);
?>
<section class="container py-5">
  <div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
      <div class="card border-0 shadow-sm">
        <div class="card-body p-4 p-lg-5">
          <div class="text-center mb-4">
            <i class="bi bi-person-plus display-4 text-primary"></i>
            <h1 class="h3 mt-3">Crear cuenta de cliente</h1>
            <p class="text-body-secondary">Tu cuenta y domicilio quedan registrados solo en <?= escape($store['store_name']) ?>.</p>
          </div>
          <?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?= escape($error) ?></div><?php endif; ?>
          <form method="post" action="<?= escape(storefront_customer_url($store, 'cliente-registro.php')) ?>">
            <?= csrf_field() ?>
            <div class="mb-3">
              <label class="form-label" for="name">Nombre completo</label>
              <input class="form-control" id="name" name="name" value="<?= escape($name) ?>" maxlength="100" autocomplete="name" required>
            </div>
            <div class="mb-3">
              <label class="form-label" for="email">Correo electrónico</label>
              <input class="form-control" type="email" id="email" name="email" value="<?= escape($email) ?>" maxlength="190" autocomplete="email" required>
            </div>
            <div class="mb-3">
              <label class="form-label" for="delivery-address">Dirección para domicilio (opcional)</label>
              <textarea class="form-control" id="delivery-address" name="delivery_address" rows="3" maxlength="500" autocomplete="street-address"><?= escape($deliveryAddress) ?></textarea>
            </div>
            <div class="mb-3">
              <label class="form-label" for="password">Contraseña</label>
              <input class="form-control" type="password" id="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required>
            </div>
            <div class="mb-4">
              <label class="form-label" for="password-confirmation">Confirmar contraseña</label>
              <input class="form-control" type="password" id="password-confirmation" name="password_confirmation" minlength="12" maxlength="72" autocomplete="new-password" required>
            </div>
            <button class="btn btn-primary w-100" type="submit">Crear cuenta</button>
          </form>
          <p class="text-center mt-4 mb-0">
            <a href="<?= escape(storefront_customer_url($store, 'cliente-login.php')) ?>">Ya tengo una cuenta</a>
          </p>
        </div>
      </div>
    </div>
  </div>
</section>
<?php storefront_customer_page_end($store); ?>

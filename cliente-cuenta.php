<?php
declare(strict_types=1);

require __DIR__ . '/includes/store_customers.php';

$store = require_storefront_owner();
$customer = storefront_customer((int) $store['id']);
if ($customer === null) {
    redirect('cliente-login.php?tienda=' . rawurlencode($store['nickname']));
}

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? null;
    if (!csrf_is_valid()) {
        http_response_code(400);
        $error = 'El formulario venció. Recarga la página e inténtalo de nuevo.';
    } elseif ($action === 'logout') {
        end_storefront_customer_session();
        redirect('tienda.php?usuario=' . rawurlencode($store['nickname']));
    } elseif ($action === 'update') {
        $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
        $deliveryAddress = is_string($_POST['delivery_address'] ?? null)
            ? trim($_POST['delivery_address'])
            : '';
        $nameLength = preg_match_all('/./us', $name);
        $addressLength = preg_match_all('/./us', $deliveryAddress);
        if ($nameLength === false || $nameLength < 1 || $nameLength > 100
            || $addressLength === false || $addressLength > 500) {
            http_response_code(422);
            $error = 'Revisa tu nombre y dirección de domicilio.';
        } else {
            try {
                $statement = database()->prepare(
                    'UPDATE store_customers
                     SET name = :name, delivery_address = :delivery_address
                     WHERE id = :customer_id AND portal_user_id = :store_id'
                );
                $statement->execute([
                    'name' => $name,
                    'delivery_address' => $deliveryAddress,
                    'customer_id' => $customer['id'],
                    'store_id' => $store['id'],
                ]);
                $customer = storefront_customer((int) $store['id']);
                $success = 'Se actualizaron tus datos y dirección de domicilio.';
            } catch (PDOException $exception) {
                error_log('No se pudo actualizar el perfil del cliente de microtienda: ' . $exception->getMessage());
                http_response_code(500);
                $error = 'No se pudo actualizar tu perfil. Inténtalo más tarde.';
            }
        }
    } else {
        http_response_code(400);
        $error = 'La operación solicitada no es válida.';
    }
}

storefront_customer_page_start('Mi cuenta de cliente', $store, $customer);
?>
<section class="container py-5">
  <div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white"><h1 class="h4 mb-0">Mi cuenta de cliente</h1></div>
        <div class="card-body p-4">
          <p class="text-body-secondary">Esta cuenta pertenece únicamente a <?= escape($store['store_name']) ?>.</p>
          <?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?= escape($error) ?></div><?php endif; ?>
          <?php if ($success !== null): ?><div class="alert alert-success" role="status"><?= escape($success) ?></div><?php endif; ?>
          <form method="post" action="<?= escape(storefront_customer_url($store, 'cliente-cuenta.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <div class="mb-3">
              <label class="form-label" for="name">Nombre completo</label>
              <input class="form-control" id="name" name="name" value="<?= escape($customer['name']) ?>" maxlength="100" autocomplete="name" required>
            </div>
            <div class="mb-3">
              <label class="form-label" for="email">Correo electrónico</label>
              <input class="form-control" type="email" id="email" value="<?= escape($customer['email']) ?>" readonly>
              <div class="form-text">El correo identifica tu cuenta en esta microtienda.</div>
            </div>
            <div class="mb-4">
              <label class="form-label" for="delivery-address">Dirección para domicilio</label>
              <textarea class="form-control" id="delivery-address" name="delivery_address" rows="3" maxlength="500" autocomplete="street-address"><?= escape($customer['delivery_address']) ?></textarea>
            </div>
            <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg me-1"></i>Guardar cambios</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</section>
<?php storefront_customer_page_end($store, $customer); ?>

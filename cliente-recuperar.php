<?php
declare(strict_types=1);

require __DIR__ . '/includes/store_customers.php';

$store = require_storefront_owner();
$customer = storefront_customer((int) $store['id']);
$error = null;
$notice = null;
$resetComplete = false;
$token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
$tokenIsValid = false;

if ($token !== '' && preg_match('/\A[a-f0-9]{64}\z/', $token) === 1) {
    try {
        $statement = database()->prepare(
            'SELECT r.id
             FROM store_customer_password_resets r
             INNER JOIN store_customers c ON c.id = r.customer_id
             WHERE r.token_hash = :token_hash
               AND c.portal_user_id = :store_id
               AND r.expires_at > UTC_TIMESTAMP()
             LIMIT 1'
        );
        $statement->execute([
            'token_hash' => hash('sha256', $token),
            'store_id' => $store['id'],
        ]);
        $tokenIsValid = $statement->fetchColumn() !== false;
    } catch (PDOException $exception) {
        error_log('No se pudo validar la recuperación de un cliente: ' . $exception->getMessage());
        http_response_code(500);
        $error = 'No se pudo comprobar el enlace. Inténtalo más tarde.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? null;
    if (!csrf_is_valid()) {
        http_response_code(400);
        $error = 'El formulario venció. Recarga la página e inténtalo de nuevo.';
    } elseif ($action === 'request') {
        $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
        if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(422);
            $error = 'Ingresa un correo electrónico válido.';
        } else {
            $connection = null;
            try {
                $publicUrl = getenv('PORTAL_PUBLIC_URL');
                $mailFrom = getenv('PORTAL_MAIL_FROM') ?: 'no-reply@localhost';
                if (!is_string($publicUrl) || filter_var($publicUrl, FILTER_VALIDATE_URL) === false
                    || !preg_match('/\Ahttps?\z/i', (string) parse_url($publicUrl, PHP_URL_SCHEME))
                    || !filter_var($mailFrom, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Configura PORTAL_PUBLIC_URL y PORTAL_MAIL_FROM para enviar recuperación.');
                }

                $connection = database();
                $statement = $connection->prepare(
                    'SELECT id FROM store_customers
                     WHERE portal_user_id = :store_id AND email = :email LIMIT 1'
                );
                $statement->execute(['store_id' => $store['id'], 'email' => $email]);
                $customerId = $statement->fetchColumn();
                if ($customerId !== false) {
                    $statement = $connection->prepare(
                        'SELECT COUNT(*) FROM store_customer_password_resets
                         WHERE customer_id = :customer_id
                           AND created_at >= CURRENT_TIMESTAMP() - INTERVAL 1 HOUR'
                    );
                    $statement->execute(['customer_id' => $customerId]);
                    if ((int) $statement->fetchColumn() < 3) {
                        $rawToken = bin2hex(random_bytes(32));
                        $statement = $connection->prepare(
                            'INSERT INTO store_customer_password_resets
                                (customer_id, token_hash, expires_at)
                             VALUES (:customer_id, :token_hash, UTC_TIMESTAMP() + INTERVAL 1 HOUR)'
                        );
                        $statement->execute([
                            'customer_id' => $customerId,
                            'token_hash' => hash('sha256', $rawToken),
                        ]);
                        $resetUrl = rtrim($publicUrl, '/') . '/cliente-recuperar.php?tienda='
                            . rawurlencode($store['nickname']) . '&token=' . rawurlencode($rawToken);
                        $subject = 'Restablecer contraseña de cliente';
                        $body = "Recibimos una solicitud para restablecer la contraseña de tu cuenta de cliente en "
                            . $store['store_name'] . ".\n\n"
                            . "Abre este enlace dentro de la próxima hora:\n{$resetUrl}\n\n"
                            . "Si no solicitaste este cambio, ignora este mensaje.";
                        $headers = [
                            'From' => $mailFrom,
                            'Content-Type' => 'text/plain; charset=UTF-8',
                        ];
                        if (!mail($email, $subject, $body, $headers)) {
                            $delete = $connection->prepare(
                                'DELETE FROM store_customer_password_resets WHERE token_hash = :token_hash'
                            );
                            $delete->execute(['token_hash' => hash('sha256', $rawToken)]);
                            error_log('La función mail() rechazó un correo de recuperación de cliente.');
                            throw new RuntimeException('No se pudo enviar el correo de recuperación.');
                        }
                    }
                }
                $notice = 'Si el correo corresponde a una cuenta de esta microtienda, recibirás un mensaje con los pasos para restablecer la contraseña.';
            } catch (PDOException $exception) {
                error_log('No se pudo procesar la recuperación de contraseña de cliente: ' . $exception->getMessage());
                http_response_code(500);
                $error = 'No se pudo procesar la solicitud. Inténtalo más tarde.';
            } catch (RuntimeException $exception) {
                error_log('No se pudo configurar o enviar la recuperación de contraseña de cliente: ' . $exception->getMessage());
                http_response_code(503);
                $error = 'La recuperación por correo no está disponible. Inténtalo más tarde.';
            }
        }
    } elseif ($action === 'reset') {
        $token = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
        $password = $_POST['password'] ?? null;
        $confirmation = $_POST['password_confirmation'] ?? null;
        if (preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1
            || !is_string($password) || strlen($password) < 12 || strlen($password) > 72
            || !is_string($confirmation) || !hash_equals($password, $confirmation)) {
            http_response_code(422);
            $error = 'El enlace o la contraseña no son válidos. Usa una contraseña de 12 a 72 bytes y confirma el mismo valor.';
        } else {
            $connection = null;
            try {
                $connection = database();
                $connection->beginTransaction();
                $statement = $connection->prepare(
                    'SELECT r.id, r.customer_id
                     FROM store_customer_password_resets r
                     INNER JOIN store_customers c ON c.id = r.customer_id
                     WHERE r.token_hash = :token_hash
                       AND c.portal_user_id = :store_id
                       AND r.expires_at > UTC_TIMESTAMP()
                     FOR UPDATE'
                );
                $statement->execute([
                    'token_hash' => hash('sha256', $token),
                    'store_id' => $store['id'],
                ]);
                $reset = $statement->fetch();
                if (!$reset) {
                    $connection->rollBack();
                    http_response_code(410);
                    $error = 'El enlace venció o ya fue utilizado. Solicita uno nuevo.';
                } else {
                    $statement = $connection->prepare(
                        'UPDATE store_customers SET password_hash = :password_hash
                         WHERE id = :customer_id AND portal_user_id = :store_id'
                    );
                    $statement->execute([
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        'customer_id' => $reset['customer_id'],
                        'store_id' => $store['id'],
                    ]);
                    $statement = $connection->prepare(
                        'DELETE FROM store_customer_password_resets WHERE customer_id = :customer_id'
                    );
                    $statement->execute(['customer_id' => $reset['customer_id']]);
                    $connection->commit();
                    $notice = 'Tu contraseña fue restablecida. Ya puedes iniciar sesión en esta microtienda.';
                    $token = '';
                    $resetComplete = true;
                }
            } catch (PDOException $exception) {
                if ($connection instanceof PDO && $connection->inTransaction()) {
                    $connection->rollBack();
                }
                error_log('No se pudo restablecer la contraseña del cliente: ' . $exception->getMessage());
                http_response_code(500);
                $error = 'No se pudo restablecer la contraseña. Inténtalo más tarde.';
            }
        }
    } else {
        http_response_code(400);
        $error = 'La operación solicitada no es válida.';
    }
}

storefront_customer_page_start('Recuperar contraseña de cliente', $store, $customer);
?>
<section class="container py-5">
  <div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
      <div class="card border-0 shadow-sm">
        <div class="card-body p-4 p-lg-5">
          <div class="text-center mb-4">
            <i class="bi bi-key display-4 text-primary"></i>
            <h1 class="h3 mt-3">Recuperar contraseña</h1>
            <p class="text-body-secondary">Cuenta de cliente de <?= escape($store['store_name']) ?>.</p>
          </div>
          <?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?= escape($error) ?></div><?php endif; ?>
          <?php if ($notice !== null): ?><div class="alert alert-success" role="status"><?= escape($notice) ?></div><?php endif; ?>
          <?php if ($tokenIsValid && $notice === null): ?>
            <form method="post" action="<?= escape(storefront_customer_url($store, 'cliente-recuperar.php')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reset">
              <input type="hidden" name="token" value="<?= escape($token) ?>">
              <div class="mb-3">
                <label class="form-label" for="password">Nueva contraseña</label>
                <input class="form-control" type="password" id="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required>
              </div>
              <div class="mb-4">
                <label class="form-label" for="password-confirmation">Confirmar contraseña</label>
                <input class="form-control" type="password" id="password-confirmation" name="password_confirmation" minlength="12" maxlength="72" autocomplete="new-password" required>
              </div>
              <button class="btn btn-primary w-100" type="submit">Restablecer contraseña</button>
            </form>
          <?php elseif (!$tokenIsValid && !$resetComplete): ?>
            <form method="post" action="<?= escape(storefront_customer_url($store, 'cliente-recuperar.php')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="request">
              <div class="mb-4">
                <label class="form-label" for="email">Correo electrónico de tu cuenta en esta tienda</label>
                <input class="form-control" type="email" id="email" name="email" maxlength="190" autocomplete="email" required>
              </div>
              <button class="btn btn-primary w-100" type="submit">Enviar enlace de recuperación</button>
            </form>
          <?php elseif ($resetComplete): ?>
            <a class="btn btn-primary w-100" href="<?= escape(storefront_customer_url($store, 'cliente-login.php')) ?>">Volver al inicio de sesión</a>
          <?php endif; ?>
          <p class="text-center mt-4 mb-0">
            <a href="<?= escape(app_url('tienda.php?usuario=' . rawurlencode($store['nickname']))) ?>">Volver a <?= escape($store['store_name']) ?></a>
          </p>
        </div>
      </div>
    </div>
  </div>
</section>
<?php storefront_customer_page_end($store, $customer); ?>

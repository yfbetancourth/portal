<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/product_images.php';
$collaborator = require_collaborator();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !has_resource_access($collaborator, 'products')) {
    http_response_code(403);
    exit('No tienes permiso para acceder a este módulo.');
}

$error = null;
$success = null;
$formValues = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid()) {
        http_response_code(400);
        $error = 'El formulario venció. Recarga la página e inténtalo de nuevo.';
    } else {
        $action = $_POST['action'] ?? null;
        $requiredPermission = match ($action) {
            'create' => 'create',
            'update', 'toggle' => 'update',
            'delete' => 'delete',
            default => null,
        };

        if ($requiredPermission === null) {
            http_response_code(400);
            $error = 'La operación solicitada no es válida.';
        } else {
            require_permission($collaborator, 'products', $requiredPermission);
            $productId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);

            if ($action === 'delete' || $action === 'toggle') {
                if ($productId === false) {
                    http_response_code(422);
                    $error = 'El producto seleccionado no es válido.';
                } else {
                    try {
                        if ($action === 'delete') {
                            $statement = database()->prepare(
                                'SELECT file_name FROM product_images WHERE product_id = :id'
                            );
                            $statement->execute(['id' => $productId]);
                            $imageFiles = $statement->fetchAll(PDO::FETCH_COLUMN);
                            $statement = database()->prepare('DELETE FROM products WHERE id = :id');
                            $statement->execute(['id' => $productId]);
                            if ($statement->rowCount() > 0) {
                                foreach ($imageFiles as $imageFile) {
                                    remove_product_image_file($imageFile);
                                }
                            }
                        } else {
                            $statement = database()->prepare(
                                'UPDATE products SET is_enabled = :is_enabled WHERE id = :id'
                            );
                            $statement->execute([
                                'is_enabled' => isset($_POST['is_enabled']) && $_POST['is_enabled'] === '1' ? 1 : 0,
                                'id' => $productId,
                            ]);
                        }

                        if ($statement->rowCount() === 0) {
                            $exists = database()->prepare('SELECT 1 FROM products WHERE id = :id');
                            $exists->execute(['id' => $productId]);
                            if ($action === 'delete' || !$exists->fetchColumn()) {
                                http_response_code(404);
                                $error = 'No se encontró el producto seleccionado.';
                            }
                        }
                        if ($error === null) {
                            $success = $action === 'delete'
                                ? 'Se eliminó el producto.'
                                : 'Se actualizó el estado del producto.';
                        }
                    } catch (PDOException $exception) {
                        error_log('No se pudo actualizar el estado del producto: ' . $exception->getMessage());
                        http_response_code(500);
                        $error = 'No se pudo completar la operación del producto.';
                    }
                }
            } else {
                $ownerId = filter_var($_POST['portal_user_id'] ?? null, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1],
                ]);
                $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
                $description = is_string($_POST['description'] ?? null) ? trim($_POST['description']) : '';
                $cost = is_string($_POST['cost_price'] ?? null) ? trim($_POST['cost_price']) : '';
                $salePrice = is_string($_POST['sale_price'] ?? null) ? trim($_POST['sale_price']) : '';
                $inventory = filter_var($_POST['inventory'] ?? null, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 0, 'max_range' => 4294967295],
                ]);
                $enabledValue = $_POST['is_enabled'] ?? '0';
                $nameLength = preg_match_all('/./us', $name);
                $descriptionLength = preg_match_all('/./us', $description);
                $validCost = preg_match('/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/', $cost) === 1;
                $validSalePrice = preg_match('/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/', $salePrice) === 1;

                $formValues = [
                    'portal_user_id' => $ownerId === false ? '' : (string) $ownerId,
                    'name' => $name,
                    'description' => $description,
                    'cost_price' => $cost,
                    'sale_price' => $salePrice,
                    'inventory' => $inventory === false ? '' : (string) $inventory,
                    'is_enabled' => $enabledValue === '1' ? '1' : '0',
                ];

                if ($ownerId === false || $nameLength === false || $nameLength < 1 || $nameLength > 150
                    || $descriptionLength === false || $descriptionLength > 1000
                    || !$validCost || !$validSalePrice || $inventory === false
                    || !in_array($enabledValue, ['0', '1'], true)
                    || ($action === 'update' && $productId === false)) {
                    http_response_code(422);
                    $error = 'Revisa usuario, nombre, descripción, costo, precio de venta e inventario.';
                } else {
                    try {
                        $ownerExists = database()->prepare('SELECT 1 FROM portal_users WHERE id = :id');
                        $ownerExists->execute(['id' => $ownerId]);
                        if (!$ownerExists->fetchColumn()) {
                            http_response_code(422);
                            $error = 'El usuario seleccionado no existe.';
                        } else {
                            $costValue = number_format((float) $cost, 2, '.', '');
                            $salePriceValue = number_format((float) $salePrice, 2, '.', '');
                            if ($action === 'create') {
                                $statement = database()->prepare(
                                    'INSERT INTO products
                                        (portal_user_id, name, description, cost_price, sale_price, inventory, is_enabled)
                                     VALUES
                                        (:portal_user_id, :name, :description, :cost_price, :sale_price, :inventory, :is_enabled)'
                                );
                            } else {
                                $statement = database()->prepare(
                                    'UPDATE products
                                     SET category_id = CASE
                                             WHEN portal_user_id = :category_owner_id THEN category_id
                                             ELSE NULL
                                         END,
                                         portal_user_id = :portal_user_id, name = :name,
                                         description = :description, cost_price = :cost_price,
                                         sale_price = :sale_price,
                                         inventory = :inventory, is_enabled = :is_enabled
                                     WHERE id = :id'
                                );
                            }
                            $parameters = [
                                'portal_user_id' => $ownerId,
                                'name' => $name,
                                'description' => $description,
                                'cost_price' => $costValue,
                                'sale_price' => $salePriceValue,
                                'inventory' => $inventory,
                                'is_enabled' => $enabledValue,
                            ];
                            if ($action === 'update') {
                                $parameters['category_owner_id'] = $ownerId;
                                $parameters['id'] = $productId;
                            }
                            $statement->execute($parameters);

                            if ($action === 'update' && $statement->rowCount() === 0) {
                                $exists = database()->prepare('SELECT 1 FROM products WHERE id = :id');
                                $exists->execute(['id' => $productId]);
                                if (!$exists->fetchColumn()) {
                                    http_response_code(404);
                                    $error = 'No se encontró el producto seleccionado.';
                                }
                            }
                            if ($error === null) {
                                $formValues = [];
                                $success = $action === 'create'
                                    ? 'Se creó el producto.'
                                    : 'Se guardaron los cambios del producto.';
                            }
                        }
                    } catch (PDOException $exception) {
                        error_log('No se pudo guardar el producto: ' . $exception->getMessage());
                        http_response_code(500);
                        $error = 'No se pudo guardar el producto. Inténtalo más tarde.';
                    }
                }
            }
        }
    }
}

try {
    $portalUsers = database()->query(
        'SELECT id, name, email FROM portal_users ORDER BY name, id'
    )->fetchAll();
    $products = database()->query(
        'SELECT p.id, p.portal_user_id, p.name, p.description, p.cost_price, p.sale_price, p.inventory,
                p.is_enabled, p.created_at, u.name AS owner_name, u.email AS owner_email
         FROM products p
         INNER JOIN portal_users u ON u.id = p.portal_user_id
         ORDER BY u.name, p.name, p.id'
    )->fetchAll();
} catch (PDOException $exception) {
    error_log('No se pudo cargar el módulo de productos: ' . $exception->getMessage());
    http_response_code(500);
    $portalUsers = [];
    $products = [];
    $loadError = 'No se pudieron cargar los productos.';
}

$canRead = has_permission($collaborator, 'products', 'read');
$canCreate = has_permission($collaborator, 'products', 'create');
$canUpdate = has_permission($collaborator, 'products', 'update');
$canDelete = has_permission($collaborator, 'products', 'delete');
$pageTitle = 'Productos';
$activePage = 'productos';
require __DIR__ . '/includes/header.php';
?>
<div class="alert alert-info" role="status">
  Cada producto pertenece a una cuenta de usuario del portal. Los usuarios gestionan sus fichas e imágenes; el rol Gestión revisa y administra productos, incluida su publicación en la microtienda.
</div>
<?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?= escape($error) ?></div><?php endif; ?>
<?php if ($success !== null): ?><div class="alert alert-success" role="status"><?= escape($success) ?></div><?php endif; ?>

<?php if (isset($loadError)): ?>
  <div class="alert alert-danger" role="alert"><?= escape($loadError) ?></div>
<?php endif; ?>

<?php if ($canCreate): ?>
  <div class="card mb-4">
    <div class="card-header"><h2 class="card-title h5 mb-0">Agregar producto</h2></div>
    <div class="card-body">
      <?php if ($portalUsers === []): ?>
        <p class="text-body-secondary mb-0">Primero debe registrarse un usuario del portal para asignarle productos.</p>
      <?php else: ?>
        <form method="post" action="<?= escape(app_url('panel/productos.php')) ?>" class="row g-3">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create">
          <div class="col-md-6">
            <label class="form-label" for="new-product-owner">Usuario del portal</label>
            <select class="form-select" id="new-product-owner" name="portal_user_id" required>
              <option value="">Selecciona un usuario</option>
              <?php foreach ($portalUsers as $portalUser): ?>
                <option value="<?= escape($portalUser['id']) ?>" <?= ($formValues['portal_user_id'] ?? '') === (string) $portalUser['id'] ? 'selected' : '' ?>>
                  <?= escape($portalUser['name']) ?> (<?= escape($portalUser['email']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="new-product-name">Nombre</label>
            <input class="form-control" id="new-product-name" name="name" value="<?= escape($formValues['name'] ?? '') ?>" maxlength="150" required>
          </div>
          <div class="col-12">
            <label class="form-label" for="new-product-description">Descripción</label>
            <textarea class="form-control" id="new-product-description" name="description" rows="2" maxlength="1000"><?= escape($formValues['description'] ?? '') ?></textarea>
          </div>
          <div class="col-sm-4">
            <label class="form-label" for="new-product-cost">Costo</label>
            <input class="form-control" type="number" id="new-product-cost" name="cost_price" min="0" max="99999999.99" step="0.01" value="<?= escape($formValues['cost_price'] ?? '0.00') ?>" required>
          </div>
          <div class="col-sm-4">
            <label class="form-label" for="new-product-sale">Valor de venta</label>
            <input class="form-control" type="number" id="new-product-sale" name="sale_price" min="0" max="99999999.99" step="0.01" value="<?= escape($formValues['sale_price'] ?? '0.00') ?>" required>
          </div>
          <div class="col-sm-4">
            <label class="form-label" for="new-product-inventory">Inventario</label>
            <input class="form-control" type="number" id="new-product-inventory" name="inventory" min="0" step="1" value="<?= escape($formValues['inventory'] ?? '0') ?>" required>
          </div>
          <div class="col-sm-4 d-flex align-items-end">
            <div class="form-check form-switch mb-2">
              <input class="form-check-input" type="checkbox" id="new-product-enabled" name="is_enabled" value="1" <?= ($formValues['is_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
              <label class="form-check-label" for="new-product-enabled">Producto habilitado</label>
            </div>
          </div>
          <div class="col-12">
            <button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg me-1"></i>Crear producto</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-header"><h2 class="card-title h5 mb-0">Productos registrados</h2></div>
  <div class="card-body p-0">
    <?php if (!$canRead): ?>
      <p class="p-3 mb-0 text-body-secondary">Tienes permisos para acciones concretas, pero no para consultar el listado.</p>
    <?php elseif ($products === []): ?>
      <p class="p-3 mb-0 text-body-secondary">Todavía no hay productos registrados.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th scope="col">Usuario</th>
              <th scope="col">Producto</th>
              <th scope="col">Costo, venta e inventario</th>
              <th scope="col">Estado</th>
              <th scope="col">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($products as $product): ?>
              <?php
              $productId = (int) $product['id'];
              $formId = 'product-' . $productId;
              ?>
              <tr>
                <td><?= escape($product['owner_name']) ?><div class="small text-body-secondary"><?= escape($product['owner_email']) ?></div></td>
                <td>
                  <?php if ($canUpdate): ?>
                    <form id="<?= escape($formId) ?>" method="post" action="<?= escape(app_url('panel/productos.php')) ?>">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="update">
                      <input type="hidden" name="id" value="<?= escape($productId) ?>">
                      <label class="visually-hidden" for="<?= escape($formId) ?>-owner">Usuario</label>
                      <select class="form-select form-select-sm mb-2" id="<?= escape($formId) ?>-owner" name="portal_user_id" required>
                        <?php foreach ($portalUsers as $portalUser): ?>
                          <option value="<?= escape($portalUser['id']) ?>" <?= (int) $product['portal_user_id'] === (int) $portalUser['id'] ? 'selected' : '' ?>>
                            <?= escape($portalUser['name']) ?> (<?= escape($portalUser['email']) ?>)
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <label class="visually-hidden" for="<?= escape($formId) ?>-name">Nombre del producto</label>
                      <input class="form-control form-control-sm mb-2" id="<?= escape($formId) ?>-name" name="name" value="<?= escape($product['name']) ?>" maxlength="150" required>
                      <label class="visually-hidden" for="<?= escape($formId) ?>-description">Descripción</label>
                      <textarea class="form-control form-control-sm" id="<?= escape($formId) ?>-description" name="description" rows="2" maxlength="1000"><?= escape($product['description']) ?></textarea>
                    </form>
                  <?php else: ?>
                    <strong><?= escape($product['name']) ?></strong>
                    <?php if ($product['description'] !== ''): ?><div class="small text-body-secondary"><?= escape($product['description']) ?></div><?php endif; ?>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($canUpdate): ?>
                    <label class="visually-hidden" for="<?= escape($formId) ?>-cost">Costo</label>
                    <input class="form-control form-control-sm mb-2" type="number" id="<?= escape($formId) ?>-cost" name="cost_price" form="<?= escape($formId) ?>" min="0" max="99999999.99" step="0.01" value="<?= escape($product['cost_price']) ?>" required>
                    <label class="visually-hidden" for="<?= escape($formId) ?>-sale">Valor de venta</label>
                    <input class="form-control form-control-sm mb-2" type="number" id="<?= escape($formId) ?>-sale" name="sale_price" form="<?= escape($formId) ?>" min="0" max="99999999.99" step="0.01" value="<?= escape($product['sale_price']) ?>" required>
                    <label class="visually-hidden" for="<?= escape($formId) ?>-inventory">Inventario</label>
                    <input class="form-control form-control-sm" type="number" id="<?= escape($formId) ?>-inventory" name="inventory" form="<?= escape($formId) ?>" min="0" step="1" value="<?= escape($product['inventory']) ?>" required>
                  <?php else: ?>
                    Costo: <?= escape($product['cost_price']) ?><div class="small text-body-secondary">Venta: <?= escape($product['sale_price']) ?><br>Cantidad: <?= escape($product['inventory']) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="badge <?= (int) $product['is_enabled'] === 1 ? 'text-bg-success' : 'text-bg-secondary' ?>">
                    <?= (int) $product['is_enabled'] === 1 ? 'Habilitado' : 'Deshabilitado' ?>
                  </span>
                </td>
                <td>
                  <div class="d-flex flex-wrap gap-2">
                    <?php if ($canUpdate): ?>
                      <button class="btn btn-primary btn-sm" type="submit" form="<?= escape($formId) ?>">
                        <i class="bi bi-check-lg me-1"></i>Guardar
                      </button>
                      <form method="post" action="<?= escape(app_url('panel/productos.php')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?= escape($productId) ?>">
                        <input type="hidden" name="is_enabled" value="<?= (int) $product['is_enabled'] === 1 ? '0' : '1' ?>">
                        <button class="btn btn-outline-secondary btn-sm" type="submit">
                          <?= (int) $product['is_enabled'] === 1 ? 'Deshabilitar' : 'Habilitar' ?>
                        </button>
                      </form>
                    <?php endif; ?>
                    <?php if ($canDelete): ?>
                      <form method="post" action="<?= escape(app_url('panel/productos.php')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= escape($productId) ?>">
                        <button class="btn btn-outline-danger btn-sm" type="submit" onclick="return confirm('¿Eliminar este producto?')">
                          <i class="bi bi-trash me-1"></i>Eliminar
                        </button>
                      </form>
                    <?php endif; ?>
                  </div>
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

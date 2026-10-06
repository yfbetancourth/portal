<?php
declare(strict_types=1);

require __DIR__ . '/includes/portal_users.php';
require __DIR__ . '/includes/product_images.php';
$user = require_portal_user();
$error = null;
$success = null;
$formValues = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid()) {
        http_response_code(400);
        $error = 'El formulario venció. Recarga la página e inténtalo de nuevo.';
    } else {
        $action = $_POST['action'] ?? null;
        $productId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if (in_array($action, ['create_category', 'update_category', 'delete_category'], true)) {
            $categoryId = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            $categoryName = is_string($_POST['category_name'] ?? null)
                ? trim($_POST['category_name'])
                : '';
            $categoryNameLength = preg_match_all('/./us', $categoryName);

            if ($action !== 'delete_category'
                && ($categoryNameLength === false || $categoryNameLength < 1 || $categoryNameLength > 80)) {
                http_response_code(422);
                $error = 'El nombre de la categoría debe tener entre 1 y 80 caracteres.';
            } elseif ($action !== 'create_category' && $categoryId === false) {
                http_response_code(422);
                $error = 'La categoría seleccionada no es válida.';
            } else {
                try {
                    if ($action === 'create_category') {
                        $statement = database()->prepare(
                            'INSERT INTO product_categories (portal_user_id, name)
                             VALUES (:user_id, :name)'
                        );
                        $statement->execute(['user_id' => $user['id'], 'name' => $categoryName]);
                        $success = 'Se creó la categoría.';
                    } elseif ($action === 'update_category') {
                        $statement = database()->prepare(
                            'UPDATE product_categories
                             SET name = :name
                             WHERE id = :category_id AND portal_user_id = :user_id'
                        );
                        $statement->execute([
                            'name' => $categoryName,
                            'category_id' => $categoryId,
                            'user_id' => $user['id'],
                        ]);
                        if ($statement->rowCount() === 0) {
                            $exists = database()->prepare(
                                'SELECT 1 FROM product_categories
                                 WHERE id = :category_id AND portal_user_id = :user_id'
                            );
                            $exists->execute([
                                'category_id' => $categoryId,
                                'user_id' => $user['id'],
                            ]);
                            if (!$exists->fetchColumn()) {
                                http_response_code(404);
                                $error = 'No se encontró la categoría seleccionada.';
                            } else {
                                $success = 'Se guardó la categoría.';
                            }
                        } else {
                            $success = 'Se guardó la categoría.';
                        }
                    } else {
                        $statement = database()->prepare(
                            'DELETE FROM product_categories
                             WHERE id = :category_id AND portal_user_id = :user_id'
                        );
                        $statement->execute([
                            'category_id' => $categoryId,
                            'user_id' => $user['id'],
                        ]);
                        if ($statement->rowCount() !== 1) {
                            http_response_code(404);
                            $error = 'No se encontró la categoría seleccionada.';
                        } else {
                            $success = 'Se eliminó la categoría; los productos asociados quedaron sin categoría.';
                        }
                    }
                } catch (PDOException $exception) {
                    if ($exception->getCode() === '23000') {
                        http_response_code(409);
                        $error = 'Ya existe una categoría con ese nombre en tu microtienda.';
                    } else {
                        error_log('No se pudo guardar una categoría de producto: ' . $exception->getMessage());
                        http_response_code(500);
                        $error = 'No se pudo guardar la categoría. Inténtalo más tarde.';
                    }
                }
            }
        } elseif ($action === 'remove_image') {
            $imageId = filter_var($_POST['image_id'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            if ($productId === false || $imageId === false) {
                http_response_code(422);
                $error = 'El producto o la imagen seleccionados no son válidos.';
            } else {
                try {
                    $statement = database()->prepare(
                        'SELECT i.file_name
                         FROM product_images i
                         INNER JOIN products p ON p.id = i.product_id
                         WHERE i.id = :image_id AND p.id = :product_id AND p.portal_user_id = :user_id'
                    );
                    $statement->execute([
                        'image_id' => $imageId,
                        'product_id' => $productId,
                        'user_id' => $user['id'],
                    ]);
                    $fileName = $statement->fetchColumn();
                    if (!is_string($fileName)) {
                        http_response_code(404);
                        $error = 'No se encontró la imagen seleccionada.';
                    } else {
                        $statement = database()->prepare(
                            'DELETE FROM product_images WHERE id = :image_id'
                        );
                        $statement->execute(['image_id' => $imageId]);
                        remove_product_image_file($fileName);
                        $success = 'Se eliminó la imagen del producto.';
                    }
                } catch (PDOException $exception) {
                    error_log('No se pudo eliminar la imagen del producto: ' . $exception->getMessage());
                    http_response_code(500);
                    $error = 'No se pudo eliminar la imagen. Inténtalo más tarde.';
                }
            }
        } elseif ($action === 'delete') {
            if ($productId === false) {
                http_response_code(422);
                $error = 'El producto seleccionado no es válido.';
            } else {
                $connection = null;
                try {
                    $connection = database();
                    $connection->beginTransaction();
                    $statement = $connection->prepare(
                        'SELECT i.file_name
                         FROM product_images i
                         INNER JOIN products p ON p.id = i.product_id
                         WHERE p.id = :product_id AND p.portal_user_id = :user_id
                         FOR UPDATE'
                    );
                    $statement->execute(['product_id' => $productId, 'user_id' => $user['id']]);
                    $files = $statement->fetchAll(PDO::FETCH_COLUMN);
                    $statement = $connection->prepare(
                        'DELETE FROM products WHERE id = :product_id AND portal_user_id = :user_id'
                    );
                    $statement->execute(['product_id' => $productId, 'user_id' => $user['id']]);
                    if ($statement->rowCount() !== 1) {
                        $connection->rollBack();
                        http_response_code(404);
                        $error = 'No se encontró el producto seleccionado.';
                    } else {
                        $connection->commit();
                        foreach ($files as $fileName) {
                            remove_product_image_file($fileName);
                        }
                        $success = 'Se eliminó el producto y sus imágenes.';
                    }
                } catch (PDOException $exception) {
                    if ($connection instanceof PDO && $connection->inTransaction()) {
                        $connection->rollBack();
                    }
                    error_log('No se pudo eliminar el producto propio: ' . $exception->getMessage());
                    http_response_code(500);
                    $error = 'No se pudo eliminar el producto. Inténtalo más tarde.';
                }
            }
        } elseif (in_array($action, ['create', 'update'], true)) {
            $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
            $description = is_string($_POST['description'] ?? null) ? trim($_POST['description']) : '';
            $illustrationUrl = is_string($_POST['illustration_url'] ?? null)
                ? trim($_POST['illustration_url'])
                : '';
            $categoryId = ($_POST['category_id'] ?? '') === ''
                ? null
                : filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1],
                ]);
            $cost = is_string($_POST['cost_price'] ?? null) ? trim($_POST['cost_price']) : '';
            $sale = is_string($_POST['sale_price'] ?? null) ? trim($_POST['sale_price']) : '';
            $quantity = filter_var($_POST['inventory'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0, 'max_range' => 4294967295],
            ]);
            $nameLength = preg_match_all('/./us', $name);
            $descriptionLength = preg_match_all('/./us', $description);
            $validCost = preg_match('/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/', $cost) === 1;
            $validSale = preg_match('/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/', $sale) === 1;
            $validIllustrationUrl = $illustrationUrl === ''
                || (strlen($illustrationUrl) <= 2048
                    && filter_var($illustrationUrl, FILTER_VALIDATE_URL) !== false
                    && strtolower((string) parse_url($illustrationUrl, PHP_URL_SCHEME)) === 'https');
            $formValues = [
                'id' => $productId === false ? '' : (string) $productId,
                'name' => $name,
                'description' => $description,
                'illustration_url' => $illustrationUrl,
                'category_id' => $categoryId === false ? '' : (string) $categoryId,
                'cost_price' => $cost,
                'sale_price' => $sale,
                'inventory' => $quantity === false ? '' : (string) $quantity,
            ];

            if ($nameLength === false || $nameLength < 1 || $nameLength > 150
                || $descriptionLength === false || $descriptionLength > 1000
                || !$validCost || !$validSale || !$validIllustrationUrl
                || ($categoryId !== null && $categoryId === false) || $quantity === false
                || ($action === 'update' && $productId === false)) {
                http_response_code(422);
                $error = 'Revisa nombre, categoría, descripción, URL HTTPS de imagen, costo, precio de venta y cantidad.';
            } else {
                $connection = null;
                $storedImages = [];
                try {
                    $connection = database();
                    $connection->beginTransaction();

                    if ($categoryId !== null) {
                        $statement = $connection->prepare(
                            'SELECT id FROM product_categories
                             WHERE id = :category_id AND portal_user_id = :user_id FOR UPDATE'
                        );
                        $statement->execute([
                            'category_id' => $categoryId,
                            'user_id' => $user['id'],
                        ]);
                        if (!$statement->fetchColumn()) {
                            $connection->rollBack();
                            http_response_code(422);
                            $error = 'La categoría seleccionada no pertenece a tu microtienda.';
                        }
                    }

                    if ($error === null && $action === 'create') {
                        $statement = $connection->prepare(
                            'INSERT INTO products
                                (portal_user_id, category_id, name, description, illustration_url,
                                 cost_price, sale_price, inventory, is_enabled)
                             VALUES
                                (:portal_user_id, :category_id, :name, :description, :illustration_url,
                                 :cost_price, :sale_price, :inventory, 0)'
                        );
                        $statement->execute([
                            'portal_user_id' => $user['id'],
                            'category_id' => $categoryId,
                            'name' => $name,
                            'description' => $description,
                            'illustration_url' => $illustrationUrl === '' ? null : $illustrationUrl,
                            'cost_price' => number_format((float) $cost, 2, '.', ''),
                            'sale_price' => number_format((float) $sale, 2, '.', ''),
                            'inventory' => $quantity,
                        ]);
                        $productId = (int) $connection->lastInsertId();
                        $existingImages = 0;
                        $nextImageOrder = 0;
                    } elseif ($error === null) {
                        $statement = $connection->prepare(
                            'SELECT id FROM products WHERE id = :product_id AND portal_user_id = :user_id FOR UPDATE'
                        );
                        $statement->execute(['product_id' => $productId, 'user_id' => $user['id']]);
                        if (!$statement->fetchColumn()) {
                            $connection->rollBack();
                            http_response_code(404);
                            $error = 'No se encontró el producto seleccionado.';
                        } else {
                            $statement = $connection->prepare(
                                'SELECT COUNT(*), COALESCE(MAX(sort_order) + 1, 0)
                                 FROM product_images WHERE product_id = :product_id'
                            );
                            $statement->execute(['product_id' => $productId]);
                            [$existingImages, $nextImageOrder] = $statement->fetch(PDO::FETCH_NUM);
                            $existingImages = (int) $existingImages;
                            $nextImageOrder = (int) $nextImageOrder;
                            $statement = $connection->prepare(
                                'UPDATE products
                                 SET category_id = :category_id, name = :name, description = :description,
                                     illustration_url = :illustration_url,
                                     cost_price = :cost_price, sale_price = :sale_price,
                                     inventory = :inventory
                                 WHERE id = :product_id AND portal_user_id = :user_id'
                            );
                            $statement->execute([
                                'category_id' => $categoryId,
                                'name' => $name,
                                'description' => $description,
                                'illustration_url' => $illustrationUrl === '' ? null : $illustrationUrl,
                                'cost_price' => number_format((float) $cost, 2, '.', ''),
                                'sale_price' => number_format((float) $sale, 2, '.', ''),
                                'inventory' => $quantity,
                                'product_id' => $productId,
                                'user_id' => $user['id'],
                            ]);
                        }
                    }

                    if ($error === null) {
                        $storedImages = receive_product_images(
                            $_FILES['images'] ?? [],
                            $existingImages
                        );
                        $statement = $connection->prepare(
                            'INSERT INTO product_images (product_id, file_name, mime_type, sort_order)
                             VALUES (:product_id, :file_name, :mime_type, :sort_order)'
                        );
                        foreach ($storedImages as $index => $image) {
                            $statement->execute([
                                'product_id' => $productId,
                                'file_name' => $image['file_name'],
                                'mime_type' => $image['mime_type'],
                                'sort_order' => $nextImageOrder + $index,
                            ]);
                        }
                        $connection->commit();
                        $formValues = [];
                        $success = $action === 'create'
                            ? 'Se creó el producto. Quedó pendiente de habilitación para publicarse en tu microtienda.'
                            : 'Se actualizaron los datos del producto.';
                    } elseif ($connection->inTransaction()) {
                        $connection->rollBack();
                    }
                } catch (InvalidArgumentException $exception) {
                    if ($connection instanceof PDO && $connection->inTransaction()) {
                        $connection->rollBack();
                    }
                    foreach ($storedImages as $image) {
                        remove_product_image_file($image['file_name']);
                    }
                    http_response_code(422);
                    $error = $exception->getMessage();
                } catch (PDOException $exception) {
                    if ($connection instanceof PDO && $connection->inTransaction()) {
                        $connection->rollBack();
                    }
                    foreach ($storedImages as $image) {
                        remove_product_image_file($image['file_name']);
                    }
                    if ($exception->getCode() === '23000') {
                        http_response_code(409);
                        $error = 'No se pudo asociar la categoría o las imágenes seleccionadas.';
                    } else {
                        error_log('No se pudo guardar el producto del usuario del portal: ' . $exception->getMessage());
                        http_response_code(500);
                        $error = 'No se pudo guardar el producto. Inténtalo más tarde.';
                    }
                } catch (Throwable $exception) {
                    if ($connection instanceof PDO && $connection->inTransaction()) {
                        $connection->rollBack();
                    }
                    foreach ($storedImages as $image) {
                        remove_product_image_file($image['file_name']);
                    }
                    if ($exception instanceof RuntimeException) {
                        error_log('No se pudo almacenar una imagen de producto: ' . $exception->getMessage());
                        http_response_code(500);
                        $error = 'No se pudo guardar una imagen. Inténtalo más tarde.';
                    } else {
                        throw $exception;
                    }
                }
            }
        } else {
            http_response_code(400);
            $error = 'La operación solicitada no es válida.';
        }
    }
}

try {
    $categories = database()->prepare(
        'SELECT id, name FROM product_categories
         WHERE portal_user_id = :user_id ORDER BY name, id'
    );
    $categories->execute(['user_id' => $user['id']]);
    $categories = $categories->fetchAll();
    $statement = database()->prepare(
        'SELECT p.id, p.category_id, c.name AS category_name,
                p.name, p.description, p.illustration_url, p.cost_price, p.sale_price, p.inventory,
                p.is_enabled, p.created_at
         FROM products p
         LEFT JOIN product_categories c ON c.id = p.category_id
         WHERE p.portal_user_id = :user_id
         ORDER BY p.created_at DESC, p.id DESC'
    );
    $statement->execute(['user_id' => $user['id']]);
    $products = $statement->fetchAll();
    $imagesByProduct = [];
    if ($products !== []) {
        $statement = database()->prepare(
            'SELECT i.id, i.product_id, i.sort_order
             FROM product_images i
             INNER JOIN products p ON p.id = i.product_id
             WHERE p.portal_user_id = :user_id
             ORDER BY i.sort_order, i.id'
        );
        $statement->execute(['user_id' => $user['id']]);
        foreach ($statement->fetchAll() as $image) {
            $imagesByProduct[(int) $image['product_id']][] = $image;
        }
    }
} catch (PDOException $exception) {
    error_log('No se pudieron cargar los productos propios: ' . $exception->getMessage());
    http_response_code(500);
    $categories = [];
    $products = [];
    $imagesByProduct = [];
    $loadError = 'No se pudieron cargar tus productos.';
}

portal_page_start('Mis productos', $user);
?>
<section class="container py-5">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
      <h1 class="h2 mb-1">Mis productos</h1>
      <p class="text-body-secondary mb-0">Agrega productos a tu microtienda. Un colaborador de Gestión debe habilitarlos para publicarlos.</p>
    </div>
    <a class="btn btn-outline-primary" href="<?= escape(app_url('tienda.php?usuario=' . rawurlencode($user['nickname']))) ?>" target="_blank" rel="noopener">
      <i class="bi bi-shop me-1"></i>Ver mi microtienda
    </a>
  </div>

  <?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?= escape($error) ?></div><?php endif; ?>
  <?php if ($success !== null): ?><div class="alert alert-success" role="status"><?= escape($success) ?></div><?php endif; ?>
  <?php if (isset($loadError)): ?><div class="alert alert-danger" role="alert"><?= escape($loadError) ?></div><?php endif; ?>

  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white"><h2 class="h5 mb-0">Categorías de mi microtienda</h2></div>
    <div class="card-body">
      <form method="post" action="<?= escape(app_url('mis-productos.php')) ?>" class="row g-2 align-items-end mb-3">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_category">
        <div class="col-sm-8 col-md-6">
          <label class="form-label" for="new-category-name">Nueva categoría</label>
          <input class="form-control" id="new-category-name" name="category_name" maxlength="80" required>
        </div>
        <div class="col-auto"><button class="btn btn-outline-primary" type="submit"><i class="bi bi-plus-lg me-1"></i>Crear categoría</button></div>
      </form>
      <?php if ($categories === []): ?>
        <p class="text-body-secondary mb-0">Todavía no has creado categorías.</p>
      <?php else: ?>
        <div class="d-flex flex-wrap gap-2">
          <?php foreach ($categories as $category): ?>
            <form method="post" action="<?= escape(app_url('mis-productos.php')) ?>" class="d-flex gap-2 align-items-center border rounded p-2">
              <?= csrf_field() ?>
              <input type="hidden" name="category_id" value="<?= escape($category['id']) ?>">
              <input type="hidden" name="action" value="update_category">
              <label class="visually-hidden" for="category-<?= escape($category['id']) ?>">Nombre de categoría</label>
              <input class="form-control form-control-sm" id="category-<?= escape($category['id']) ?>" name="category_name" value="<?= escape($category['name']) ?>" maxlength="80" required>
              <button class="btn btn-outline-primary btn-sm" type="submit" aria-label="Guardar categoría <?= escape($category['name']) ?>"><i class="bi bi-check-lg"></i></button>
            </form>
            <form method="post" action="<?= escape(app_url('mis-productos.php')) ?>" onsubmit="return confirm('¿Eliminar esta categoría? Los productos asociados quedarán sin categoría.')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_category">
              <input type="hidden" name="category_id" value="<?= escape($category['id']) ?>">
              <button class="btn btn-outline-danger btn-sm" type="submit" aria-label="Eliminar categoría <?= escape($category['name']) ?>"><i class="bi bi-trash"></i></button>
            </form>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white"><h2 class="h5 mb-0">Agregar producto</h2></div>
    <div class="card-body">
      <form method="post" action="<?= escape(app_url('mis-productos.php')) ?>" enctype="multipart/form-data" class="row g-3">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create">
        <div class="col-md-6">
          <label class="form-label" for="new-name">Nombre del producto</label>
          <input class="form-control" id="new-name" name="name" value="<?= escape($formValues['name'] ?? '') ?>" maxlength="150" required>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="new-quantity">Cantidad disponible</label>
          <input class="form-control" type="number" id="new-quantity" name="inventory" min="0" step="1" value="<?= escape($formValues['inventory'] ?? '0') ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="new-category">Categoría</label>
          <select class="form-select" id="new-category" name="category_id">
            <option value="">Sin categoría</option>
            <?php foreach ($categories as $category): ?>
              <option value="<?= escape($category['id']) ?>" <?= ($formValues['category_id'] ?? '') === (string) $category['id'] ? 'selected' : '' ?>><?= escape($category['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="new-cost">Costo</label>
          <input class="form-control" type="number" id="new-cost" name="cost_price" min="0" max="99999999.99" step="0.01" value="<?= escape($formValues['cost_price'] ?? '0.00') ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="new-sale">Valor de venta</label>
          <input class="form-control" type="number" id="new-sale" name="sale_price" min="0" max="99999999.99" step="0.01" value="<?= escape($formValues['sale_price'] ?? '0.00') ?>" required>
        </div>
        <div class="col-12">
          <label class="form-label" for="new-description">Descripción</label>
          <textarea class="form-control" id="new-description" name="description" rows="2" maxlength="1000"><?= escape($formValues['description'] ?? '') ?></textarea>
        </div>
        <div class="col-12">
          <label class="form-label" for="new-illustration-url">URL HTTPS de imagen ilustrativa</label>
          <input class="form-control" type="url" id="new-illustration-url" name="illustration_url" value="<?= escape($formValues['illustration_url'] ?? '') ?>" maxlength="2048" placeholder="https://ejemplo.com/imagen.jpg">
          <div class="form-text">Opcional. Se mostrará en la ficha del producto; las imágenes cargadas también siguen disponibles.</div>
        </div>
        <div class="col-12">
          <label class="form-label" for="new-images">Imágenes (máximo 5 por producto)</label>
          <input class="form-control" type="file" id="new-images" name="images[]" accept="image/jpeg,image/png,image/webp" multiple>
          <div class="form-text">JPEG, PNG o WebP; hasta 5 MiB por imagen y 20 megapíxeles.</div>
        </div>
        <div class="col-12">
          <button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg me-1"></i>Guardar producto</button>
        </div>
      </form>
    </div>
  </div>

  <h2 class="h4 mb-3">Productos registrados</h2>
  <?php if ($products === []): ?>
    <div class="card border-0 shadow-sm"><div class="card-body text-body-secondary">Aún no has agregado productos.</div></div>
  <?php else: ?>
    <div class="row g-4">
      <?php foreach ($products as $product): ?>
        <?php
        $productId = (int) $product['id'];
        $productImages = $imagesByProduct[$productId] ?? [];
        $formId = 'product-' . $productId;
        ?>
        <div class="col-12">
          <article class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
              <h3 class="h5 mb-0"><?= escape($product['name']) ?></h3>
              <span class="badge <?= (int) $product['is_enabled'] === 1 ? 'text-bg-success' : 'text-bg-secondary' ?>">
                <?= (int) $product['is_enabled'] === 1 ? 'Publicado en la microtienda' : 'Pendiente de habilitación por Gestión' ?>
              </span>
            </div>
            <div class="card-body">
              <?php if ($productImages !== []): ?>
                <div class="d-flex flex-wrap gap-3 mb-3">
                  <?php foreach ($productImages as $image): ?>
                    <div class="border rounded p-2 text-center">
                      <img src="<?= escape(app_url('producto-imagen.php?id=' . $image['id'])) ?>" alt="Imagen de <?= escape($product['name']) ?>" width="120" height="100" style="object-fit:cover">
                      <form method="post" action="<?= escape(app_url('mis-productos.php')) ?>" class="mt-2">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="remove_image">
                        <input type="hidden" name="id" value="<?= escape($productId) ?>">
                        <input type="hidden" name="image_id" value="<?= escape($image['id']) ?>">
                        <button class="btn btn-outline-danger btn-sm" type="submit">Quitar imagen</button>
                      </form>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              <form id="<?= escape($formId) ?>" method="post" action="<?= escape(app_url('mis-productos.php')) ?>" enctype="multipart/form-data" class="row g-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= escape($productId) ?>">
                <div class="col-md-6">
                  <label class="form-label" for="<?= escape($formId) ?>-name">Nombre</label>
                  <input class="form-control" id="<?= escape($formId) ?>-name" name="name" value="<?= escape($product['name']) ?>" maxlength="150" required>
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="<?= escape($formId) ?>-quantity">Cantidad</label>
                  <input class="form-control" type="number" id="<?= escape($formId) ?>-quantity" name="inventory" min="0" step="1" value="<?= escape($product['inventory']) ?>" required>
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="<?= escape($formId) ?>-category">Categoría</label>
                  <select class="form-select" id="<?= escape($formId) ?>-category" name="category_id">
                    <option value="">Sin categoría</option>
                    <?php foreach ($categories as $category): ?>
                      <option value="<?= escape($category['id']) ?>" <?= (int) $product['category_id'] === (int) $category['id'] ? 'selected' : '' ?>><?= escape($category['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="<?= escape($formId) ?>-cost">Costo</label>
                  <input class="form-control" type="number" id="<?= escape($formId) ?>-cost" name="cost_price" min="0" max="99999999.99" step="0.01" value="<?= escape($product['cost_price']) ?>" required>
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="<?= escape($formId) ?>-sale">Valor de venta</label>
                  <input class="form-control" type="number" id="<?= escape($formId) ?>-sale" name="sale_price" min="0" max="99999999.99" step="0.01" value="<?= escape($product['sale_price']) ?>" required>
                </div>
                <div class="col-12">
                  <label class="form-label" for="<?= escape($formId) ?>-description">Descripción</label>
                  <textarea class="form-control" id="<?= escape($formId) ?>-description" name="description" rows="2" maxlength="1000"><?= escape($product['description']) ?></textarea>
                </div>
                <div class="col-12">
                  <label class="form-label" for="<?= escape($formId) ?>-illustration-url">URL HTTPS de imagen ilustrativa</label>
                  <input class="form-control" type="url" id="<?= escape($formId) ?>-illustration-url" name="illustration_url" value="<?= escape($product['illustration_url'] ?? '') ?>" maxlength="2048" placeholder="https://ejemplo.com/imagen.jpg">
                </div>
                <?php if (count($productImages) < PORTAL_PRODUCT_IMAGE_MAX_COUNT): ?>
                  <div class="col-12">
                    <label class="form-label" for="<?= escape($formId) ?>-images">Agregar imágenes (<?= PORTAL_PRODUCT_IMAGE_MAX_COUNT - count($productImages) ?> disponibles)</label>
                    <input class="form-control" type="file" id="<?= escape($formId) ?>-images" name="images[]" accept="image/jpeg,image/png,image/webp" multiple>
                  </div>
                <?php endif; ?>
                <div class="col-12 d-flex flex-wrap gap-2">
                  <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg me-1"></i>Guardar cambios</button>
                </div>
              </form>
              <form method="post" action="<?= escape(app_url('mis-productos.php')) ?>" class="mt-2" onsubmit="return confirm('¿Eliminar este producto y todas sus imágenes?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= escape($productId) ?>">
                <button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-trash me-1"></i>Eliminar producto</button>
              </form>
            </div>
          </article>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php portal_page_end($user); ?>

<?php
declare(strict_types=1);

require __DIR__ . '/includes/store_customers.php';

$nickname = is_string($_GET['usuario'] ?? null) ? strtolower(trim($_GET['usuario'])) : '';
if (preg_match('/\A[a-z0-9][a-z0-9-]{2,49}\z/', $nickname) !== 1) {
    http_response_code(404);
    exit('No se encontró la microtienda solicitada.');
}

try {
    $statement = database()->prepare(
        'SELECT id, name, store_name, nickname
         FROM portal_users WHERE nickname = :nickname LIMIT 1'
    );
    $statement->execute(['nickname' => $nickname]);
    $storeOwner = $statement->fetch();
    if (!$storeOwner) {
        http_response_code(404);
        exit('No se encontró la microtienda solicitada.');
    }

    $statement = database()->prepare(
        'SELECT id, name
         FROM product_categories
         WHERE portal_user_id = :portal_user_id
         ORDER BY name, id'
    );
    $statement->execute(['portal_user_id' => $storeOwner['id']]);
    $categories = $statement->fetchAll();

    $statement = database()->prepare(
        'SELECT p.id, p.category_id, p.name, p.description,
                p.illustration_url, p.sale_price, p.inventory
         FROM products p
         WHERE p.portal_user_id = :portal_user_id AND p.is_enabled = 1
         ORDER BY p.name, p.id'
    );
    $statement->execute(['portal_user_id' => $storeOwner['id']]);
    $products = $statement->fetchAll();
    $imagesByProduct = [];
    if ($products !== []) {
        $statement = database()->prepare(
            'SELECT i.id, i.product_id
             FROM product_images i
             INNER JOIN products p ON p.id = i.product_id
             WHERE p.portal_user_id = :portal_user_id AND p.is_enabled = 1
             ORDER BY i.sort_order, i.id'
        );
        $statement->execute(['portal_user_id' => $storeOwner['id']]);
        foreach ($statement->fetchAll() as $image) {
            $imagesByProduct[(int) $image['product_id']][] = $image;
        }
    }

    $productsByCategory = [];
    foreach ($categories as $category) {
        $productsByCategory[(int) $category['id']] = [];
    }
    $uncategorizedProducts = [];
    foreach ($products as $product) {
        if ($product['category_id'] === null) {
            $uncategorizedProducts[] = $product;
        } else {
            $productsByCategory[(int) $product['category_id']][] = $product;
        }
    }
    $storeSections = [];
    foreach ($categories as $category) {
        $storeSections[] = [
            'name' => $category['name'],
            'products' => $productsByCategory[(int) $category['id']],
        ];
    }
    if ($uncategorizedProducts !== []) {
        $storeSections[] = [
            'name' => 'Sin categoría',
            'products' => $uncategorizedProducts,
        ];
    }
} catch (PDOException $exception) {
    error_log('No se pudo cargar una microtienda pública: ' . $exception->getMessage());
    http_response_code(500);
    exit('No se pudo cargar la microtienda. Inténtalo más tarde.');
}

$customer = storefront_customer((int) $storeOwner['id']);
$storeUrl = getenv('PORTAL_PUBLIC_URL');
if (!is_string($storeUrl) || filter_var($storeUrl, FILTER_VALIDATE_URL) === false
    || !preg_match('/\Ahttps?\z/i', (string) parse_url($storeUrl, PHP_URL_SCHEME))) {
    $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!is_string($host) || preg_match('/\A[a-z0-9.-]+(?::[0-9]{1,5})?\z/i', $host) !== 1) {
        $host = 'localhost';
    }
    $storeUrl = $scheme . '://' . $host . APP_BASE_PATH;
}
$storeUrl = rtrim($storeUrl, '/') . '/tienda.php?usuario=' . rawurlencode($storeOwner['nickname']);
$qrImageUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&format=svg&data=' . rawurlencode($storeUrl);

storefront_customer_page_start($storeOwner['store_name'], $storeOwner, $customer);
?>
<section class="container py-5">
  <div class="row g-4 align-items-center mb-4">
    <div class="col-lg">
      <span class="badge text-bg-primary mb-2"><i class="bi bi-shop me-1"></i>Microtienda</span>
      <h1 class="display-6 fw-bold"><?= escape($storeOwner['store_name']) ?></h1>
      <p class="lead text-body-secondary mb-0">Categorías y productos publicados de <?= escape($storeOwner['store_name']) ?>.</p>
    </div>
    <div class="col-lg-auto">
      <div class="card border-0 shadow-sm">
        <div class="card-body d-flex align-items-center gap-3">
          <img src="<?= escape($qrImageUrl) ?>" alt="Código QR para visitar la microtienda de <?= escape($storeOwner['store_name']) ?>" width="120" height="120">
          <div>
            <h2 class="h6">Invita a comprar</h2>
            <p class="small text-body-secondary mb-2">Comparte el enlace o escanea el código QR.</p>
            <label class="visually-hidden" for="store-link">Enlace de la microtienda</label>
            <input class="form-control form-control-sm" id="store-link" value="<?= escape($storeUrl) ?>" readonly>
            <button class="btn btn-outline-primary btn-sm mt-2" type="button" onclick="navigator.clipboard.writeText(document.getElementById('store-link').value).then(() => { this.textContent = 'Enlace copiado'; }).catch(() => { document.getElementById('store-link').select(); });">
              <i class="bi bi-copy me-1"></i>Copiar enlace
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php if ($storeSections === []): ?>
    <div class="card border-0 shadow-sm"><div class="card-body text-center py-5">
      <i class="bi bi-shop display-5 text-body-secondary"></i>
      <h2 class="h4 mt-3">Próximamente</h2>
      <p class="text-body-secondary mb-0">Esta microtienda todavía no tiene categorías ni productos publicados.</p>
    </div></div>
  <?php else: ?>
    <div class="d-flex flex-column gap-5">
      <?php foreach ($storeSections as $section): ?>
        <section aria-label="<?= escape($section['name']) ?>">
          <h2 class="h4 mb-3"><?= escape($section['name']) ?></h2>
        <?php if ($section['products'] === []): ?>
          <p class="text-body-secondary mb-0">Aún no hay productos publicados en esta categoría.</p>
        <?php else: ?>
          <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 row-cols-xl-4 g-4">
            <?php foreach ($section['products'] as $product): ?>
              <?php
              $productImages = $imagesByProduct[(int) $product['id']] ?? [];
              $illustrationUrl = is_string($product['illustration_url'])
                  && filter_var($product['illustration_url'], FILTER_VALIDATE_URL) !== false
                  && strtolower((string) parse_url($product['illustration_url'], PHP_URL_SCHEME)) === 'https'
                      ? $product['illustration_url']
                      : null;
              ?>
              <div class="col">
                <article class="card h-100 border-0 shadow-sm">
                  <?php if ($illustrationUrl !== null): ?>
                    <img class="card-img-top" src="<?= escape($illustrationUrl) ?>" alt="<?= escape($product['name']) ?>" referrerpolicy="no-referrer" loading="lazy" style="height:220px;object-fit:cover">
                  <?php elseif ($productImages !== []): ?>
                    <img class="card-img-top" src="<?= escape(app_url('producto-imagen.php?id=' . $productImages[0]['id'])) ?>" alt="<?= escape($product['name']) ?>" style="height:220px;object-fit:cover">
                  <?php else: ?>
                    <div class="bg-body-secondary d-flex align-items-center justify-content-center" style="height:220px"><i class="bi bi-image display-4 text-body-secondary"></i></div>
                  <?php endif; ?>
                  <div class="card-body d-flex flex-column">
                    <h3 class="h5"><?= escape($product['name']) ?></h3>
                    <?php if ($product['description'] !== ''): ?><p class="small text-body-secondary"><?= nl2br(escape($product['description'])) ?></p><?php endif; ?>
                    <div class="mt-auto">
                      <div class="fs-5 fw-semibold text-primary"><?= escape(number_format((float) $product['sale_price'], 2, ',', '.')) ?></div>
                      <div class="small text-body-secondary"><?= (int) $product['inventory'] > 0 ? 'Disponible' : 'Agotado' ?></div>
                    </div>
                  </div>
                </article>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        </section>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php storefront_customer_page_end($storeOwner, $customer); ?>

<?php
declare(strict_types=1);
?>
</main>
<footer class="bg-dark text-white mt-5 py-4">
  <div class="container d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
    <div>
      <strong>Portal</strong>
      <div class="small text-white-50">Un espacio para acceder a tus recursos y administrar tu cuenta.</div>
    </div>
    <nav class="d-flex flex-wrap gap-3" aria-label="Gestión de cuenta">
      <?php if (isset($storeContext)): ?>
        <a class="portal-footer-link" href="<?= escape(app_url('tienda.php?usuario=' . rawurlencode($storeContext['nickname']))) ?>">Volver a <?= escape($storeContext['store_name']) ?></a>
        <?php if ($storeCustomer === null): ?>
          <a class="portal-footer-link" href="<?= escape(storefront_customer_url($storeContext, 'cliente-login.php')) ?>">Iniciar sesión como cliente</a>
          <a class="portal-footer-link" href="<?= escape(storefront_customer_url($storeContext, 'cliente-registro.php')) ?>">Crear cuenta de cliente</a>
          <a class="portal-footer-link" href="<?= escape(storefront_customer_url($storeContext, 'cliente-recuperar.php')) ?>">Recuperar contraseña</a>
        <?php else: ?>
          <a class="portal-footer-link" href="<?= escape(storefront_customer_url($storeContext, 'cliente-cuenta.php')) ?>">Mi cuenta y domicilio</a>
        <?php endif; ?>
      <?php elseif ($user === null): ?>
        <a class="portal-footer-link" href="<?= escape(app_url('registro.php')) ?>">Crear cuenta</a>
        <a class="portal-footer-link" href="<?= escape(app_url('recuperar.php')) ?>">Recuperar contraseña</a>
      <?php else: ?>
        <a class="portal-footer-link" href="<?= escape(app_url('cuenta.php')) ?>"><i class="bi bi-person-gear me-1"></i>Gestión de mi cuenta</a>
        <a class="portal-footer-link" href="<?= escape(app_url('mis-productos.php')) ?>"><i class="bi bi-box-seam me-1"></i>Mis productos</a>
        <a class="portal-footer-link" href="<?= escape(app_url('tienda.php?usuario=' . rawurlencode($user['nickname']))) ?>"><i class="bi bi-shop me-1"></i>Mi microtienda</a>
      <?php endif; ?>
    </nav>
  </div>
</footer>
<script src="<?= escape(app_url('panel/assets/vendor/bootstrap/dist/js/bootstrap.bundle.min.js')) ?>"></script>
</body>
</html>

<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth.php';
$collaborator = require_collaborator();

$error = null;
$newModule = [
    'module_key' => '',
    'name' => '',
    'description' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid()) {
        http_response_code(400);
        $error = 'El formulario venció. Recarga la página e inténtalo de nuevo.';
    } else {
        $action = $_POST['action'] ?? null;
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($action === 'create') {
            $moduleKey = $_POST['module_key'] ?? null;
            $name = $_POST['name'] ?? null;
            $description = $_POST['description'] ?? null;
            $newModule = [
                'module_key' => is_string($moduleKey) ? trim($moduleKey) : '',
                'name' => is_string($name) ? trim($name) : '',
                'description' => is_string($description) ? trim($description) : '',
            ];
            $keyValid = preg_match('/\A[a-z][a-z0-9_-]{1,79}\z/', $newModule['module_key']) === 1;
            $nameLength = preg_match_all('/./us', $newModule['name']);
            $descriptionLength = preg_match_all('/./us', $newModule['description']);

            if (!$keyValid || $nameLength === false || $nameLength < 2 || $nameLength > 100
                || $descriptionLength === false || $descriptionLength > 500) {
                http_response_code(422);
                $error = 'Revisa los datos. La clave debe tener 2 a 80 caracteres (minúsculas, números, guion o guion bajo); el nombre, de 2 a 100 caracteres; y la descripción, hasta 500.';
            } else {
                try {
                    $statement = database()->prepare(
                        'INSERT INTO panel_modules (module_key, name, description, is_active)
                         VALUES (:module_key, :name, :description, :is_active)'
                    );
                    $statement->execute([
                        'module_key' => $newModule['module_key'],
                        'name' => $newModule['name'],
                        'description' => $newModule['description'],
                        'is_active' => isset($_POST['is_active']) && $_POST['is_active'] === '1' ? 1 : 0,
                    ]);
                    redirect('panel/modulos.php');
                } catch (PDOException $exception) {
                    if ($exception->getCode() === '23000' && ($exception->errorInfo[1] ?? null) === 1062) {
                        http_response_code(409);
                        $error = 'Ya existe un módulo con esa clave.';
                    } else {
                        error_log('No se pudo crear el módulo: ' . $exception->getMessage());
                        http_response_code(500);
                        $error = 'No se pudo guardar el módulo. Inténtalo más tarde.';
                    }
                }
            }
        } elseif ($action === 'update' || $action === 'delete') {
            if ($id === false) {
                http_response_code(400);
                $error = 'El módulo seleccionado no es válido.';
            } else {
                try {
                    $connection = database();
                    $statement = $connection->prepare('SELECT id FROM panel_modules WHERE id = :id');
                    $statement->execute(['id' => $id]);
                    if (!$statement->fetchColumn()) {
                        http_response_code(404);
                        $error = 'No se encontró el módulo seleccionado.';
                    } elseif ($action === 'delete') {
                        $statement = $connection->prepare('DELETE FROM panel_modules WHERE id = :id');
                        $statement->execute(['id' => $id]);
                        redirect('panel/modulos.php');
                    } else {
                        $name = $_POST['name'] ?? null;
                        $description = $_POST['description'] ?? null;
                        $name = is_string($name) ? trim($name) : '';
                        $description = is_string($description) ? trim($description) : '';
                        $nameLength = preg_match_all('/./us', $name);
                        $descriptionLength = preg_match_all('/./us', $description);

                        if ($nameLength === false || $nameLength < 2 || $nameLength > 100
                            || $descriptionLength === false || $descriptionLength > 500) {
                            http_response_code(422);
                            $error = 'El nombre debe tener de 2 a 100 caracteres y la descripción, hasta 500.';
                        } else {
                            $statement = $connection->prepare(
                                'UPDATE panel_modules
                                 SET name = :name, description = :description, is_active = :is_active
                                 WHERE id = :id'
                            );
                            $statement->execute([
                                'name' => $name,
                                'description' => $description,
                                'is_active' => isset($_POST['is_active']) && $_POST['is_active'] === '1' ? 1 : 0,
                                'id' => $id,
                            ]);
                            redirect('panel/modulos.php');
                        }
                    }
                } catch (PDOException $exception) {
                    error_log('No se pudo actualizar el módulo: ' . $exception->getMessage());
                    http_response_code(500);
                    $error = 'No se pudo completar la operación del módulo. Inténtalo más tarde.';
                }
            }
        } else {
            http_response_code(400);
            $error = 'La operación solicitada no es válida.';
        }
    }
}

try {
    $modules = database()->query(
        'SELECT id, module_key, name, description, is_active
         FROM panel_modules
         ORDER BY name, id'
    )->fetchAll();
} catch (PDOException $exception) {
    error_log('No se pudo cargar el catálogo de módulos: ' . $exception->getMessage());
    http_response_code(500);
    $modules = [];
    $loadError = 'No se pudo cargar el catálogo de módulos.';
}

$pageTitle = 'Módulos';
$activePage = 'modulos';
require __DIR__ . '/includes/header.php';
?>
<div class="alert alert-info" role="status">
  Este catálogo organiza módulos futuros. Activar un registro no crea ni publica funcionalidad automáticamente; cada módulo debe integrarse al panel cuando se desarrolle.
</div>

<?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?= escape($error) ?></div><?php endif; ?>

<div class="row g-4">
  <div class="col-xl-4">
    <div class="card">
      <div class="card-header"><h2 class="card-title h5 mb-0">Agregar módulo</h2></div>
      <div class="card-body">
        <form method="post" action="<?= escape(app_url('panel/modulos.php')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create">
          <div class="mb-3">
            <label for="module_key" class="form-label">Clave</label>
            <input class="form-control" id="module_key" name="module_key" value="<?= escape($newModule['module_key']) ?>" maxlength="80" pattern="[a-z][a-z0-9_-]{1,79}" aria-describedby="module-key-help" required>
            <div id="module-key-help" class="form-text">Identificador técnico único: minúsculas, números, guion o guion bajo.</div>
          </div>
          <div class="mb-3">
            <label for="module_name" class="form-label">Nombre</label>
            <input class="form-control" id="module_name" name="name" value="<?= escape($newModule['name']) ?>" maxlength="100" required>
          </div>
          <div class="mb-3">
            <label for="module_description" class="form-label">Descripción</label>
            <textarea class="form-control" id="module_description" name="description" rows="3" maxlength="500"><?= escape($newModule['description']) ?></textarea>
          </div>
          <div class="form-check mb-3">
            <input type="hidden" name="is_active" value="0">
            <input class="form-check-input" type="checkbox" id="module_active" name="is_active" value="1" checked>
            <label class="form-check-label" for="module_active">Activo</label>
          </div>
          <button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg me-1"></i>Agregar módulo</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-xl-8">
    <div class="card">
      <div class="card-header"><h2 class="card-title h5 mb-0">Catálogo de módulos</h2></div>
      <div class="card-body p-0">
        <?php if (isset($loadError)): ?><div class="alert alert-danger m-3" role="alert"><?= escape($loadError) ?></div><?php endif; ?>
        <?php if ($modules === []): ?>
          <p class="p-3 mb-0 text-body-secondary">Todavía no hay módulos registrados.</p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead>
                <tr><th scope="col">Clave</th><th scope="col">Nombre y descripción</th><th scope="col">Estado</th><th scope="col">Acciones</th></tr>
              </thead>
              <tbody>
                <?php foreach ($modules as $module): ?>
                  <tr>
                    <td><code><?= escape($module['module_key']) ?></code></td>
                    <td>
                      <form id="module-<?= escape($module['id']) ?>" method="post" action="<?= escape(app_url('panel/modulos.php')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?= escape($module['id']) ?>">
                        <label class="visually-hidden" for="name-<?= escape($module['id']) ?>">Nombre</label>
                        <input class="form-control form-control-sm mb-2" id="name-<?= escape($module['id']) ?>" name="name" value="<?= escape($module['name']) ?>" maxlength="100" required>
                        <label class="visually-hidden" for="description-<?= escape($module['id']) ?>">Descripción</label>
                        <textarea class="form-control form-control-sm" id="description-<?= escape($module['id']) ?>" name="description" rows="2" maxlength="500"><?= escape($module['description']) ?></textarea>
                      </form>
                    </td>
                    <td>
                      <div class="form-check form-switch">
                        <input type="hidden" name="is_active" value="0" form="module-<?= escape($module['id']) ?>">
                        <input class="form-check-input" type="checkbox" id="active-<?= escape($module['id']) ?>" name="is_active" value="1" form="module-<?= escape($module['id']) ?>" <?= (int) $module['is_active'] === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="active-<?= escape($module['id']) ?>"><?= (int) $module['is_active'] === 1 ? 'Activo' : 'Inactivo' ?></label>
                      </div>
                    </td>
                    <td>
                      <div class="d-flex flex-wrap gap-2">
                        <button class="btn btn-primary btn-sm" type="submit" form="module-<?= escape($module['id']) ?>">
                          <i class="bi bi-check-lg me-1"></i>Guardar
                        </button>
                        <button class="btn btn-outline-danger btn-sm" type="submit" form="module-<?= escape($module['id']) ?>" name="action" value="delete" formnovalidate onclick="return confirm('¿Eliminar este módulo del catálogo?')">
                          <i class="bi bi-trash me-1"></i>Eliminar
                        </button>
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
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/module_packages.php';
$collaborator = require_collaborator();
$canReadModules = has_permission($collaborator, 'modules', 'read');
$canCreateModules = has_permission($collaborator, 'modules', 'create');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !has_resource_access($collaborator, 'modules')) {
    http_response_code(403);
    exit('No tienes permiso para acceder a esta sección.');
}

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
        $permissionAction = $action === 'create' ? 'create'
            : ($action === 'delete' ? 'delete'
                : (in_array($action, ['upload', 'update'], true) ? 'update' : null));
        if ($permissionAction !== null) {
            require_permission($collaborator, 'modules', $permissionAction);
        }

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
        } elseif ($action === 'upload' || $action === 'update' || $action === 'delete') {
            if ($id === false) {
                http_response_code(400);
                $error = 'El módulo seleccionado no es válido.';
            } else {
                $newArchivePath = null;
                try {
                    $connection = database();
                    $statement = $connection->prepare(
                        'SELECT id, module_key, archive_path FROM panel_modules WHERE id = :id'
                    );
                    $statement->execute(['id' => $id]);
                    $module = $statement->fetch();
                    if (!$module) {
                        http_response_code(404);
                        $error = 'No se encontró el módulo seleccionado.';
                    } elseif ($action === 'delete') {
                        $statement = $connection->prepare('DELETE FROM panel_modules WHERE id = :id');
                        $statement->execute(['id' => $id]);
                        if (is_string($module['archive_path'])) {
                            remove_module_archive($module['archive_path'], (int) $id);
                        }
                        redirect('panel/modulos.php');
                    } elseif ($action === 'upload') {
                        $archive = save_module_archive($_FILES['archive'] ?? [], $module['module_key']);
                        $newArchivePath = $archive['path'];
                        $statement = $connection->prepare(
                            'UPDATE panel_modules
                             SET archive_path = :archive_path,
                                 archive_original_name = :archive_original_name,
                                 archive_sha256 = :archive_sha256,
                                 archive_size = :archive_size,
                                 archive_uploaded_at = UTC_TIMESTAMP(6)
                             WHERE id = :id'
                        );
                        $statement->execute([
                            'archive_path' => $archive['path'],
                            'archive_original_name' => $archive['original_name'],
                            'archive_sha256' => $archive['sha256'],
                            'archive_size' => $archive['size'],
                            'id' => $id,
                        ]);
                        if (is_string($module['archive_path'])) {
                            remove_module_archive($module['archive_path'], (int) $id);
                        }
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
                } catch (InvalidArgumentException $exception) {
                    http_response_code(422);
                    $error = $exception->getMessage();
                } catch (PDOException $exception) {
                    if (is_string($newArchivePath)) {
                        remove_module_archive($newArchivePath, (int) $id);
                    }
                    error_log('No se pudo actualizar el módulo: ' . $exception->getMessage());
                    http_response_code(500);
                    $error = 'No se pudo completar la operación del módulo. Inténtalo más tarde.';
                } catch (RuntimeException $exception) {
                    if (is_string($newArchivePath)) {
                        remove_module_archive($newArchivePath, (int) $id);
                    }
                    error_log('No se pudo procesar el ZIP del módulo: ' . $exception->getMessage());
                    http_response_code(500);
                    $error = 'No se pudo almacenar el ZIP. Revisa la configuración del almacenamiento del servidor.';
                } catch (Throwable $exception) {
                    if (is_string($newArchivePath)) {
                        remove_module_archive($newArchivePath, (int) $id);
                    }
                    error_log('Error inesperado al procesar el módulo: ' . $exception->getMessage());
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
    if (!$canReadModules) {
        $modules = [];
        $loadError = null;
    } else {
        $modules = database()->query(
            'SELECT id, module_key, name, description, is_active,
                    archive_path, archive_original_name, archive_sha256, archive_size, archive_uploaded_at
             FROM panel_modules
             ORDER BY name, id'
        )->fetchAll();
    }
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
  Los ZIP se guardan fuera de la carpeta pública y no se extraen ni ejecutan. Descárgalos para revisar el código y desplegarlos manualmente; activar un módulo no instala ni publica su contenido.
</div>

<?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?= escape($error) ?></div><?php endif; ?>

<div class="row g-4">
  <div class="col-xl-4">
    <div class="card">
      <div class="card-header"><h2 class="card-title h5 mb-0">Agregar módulo</h2></div>
      <div class="card-body">
        <?php if ($canCreateModules): ?>
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
        <?php else: ?>
          <p class="text-body-secondary mb-0">No tienes permiso para crear módulos.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-xl-8">
    <div class="card">
      <div class="card-header"><h2 class="card-title h5 mb-0">Catálogo de módulos</h2></div>
      <div class="card-body p-0">
        <?php if (isset($loadError)): ?><div class="alert alert-danger m-3" role="alert"><?= escape($loadError) ?></div><?php endif; ?>
        <?php if (!$canReadModules): ?>
          <p class="p-3 mb-0 text-body-secondary">Tienes acceso a acciones concretas, pero no permiso para consultar el catálogo.</p>
        <?php elseif ($modules === []): ?>
          <p class="p-3 mb-0 text-body-secondary">Todavía no hay módulos registrados.</p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead>
                <tr><th scope="col">Clave</th><th scope="col">Nombre y descripción</th><th scope="col">Estado</th><th scope="col">Paquete ZIP y acciones</th></tr>
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
                        <input class="form-control form-control-sm mb-2" id="name-<?= escape($module['id']) ?>" name="name" value="<?= escape($module['name']) ?>" maxlength="100" <?= has_permission($collaborator, 'modules', 'update') ? 'required' : 'disabled' ?>>
                        <label class="visually-hidden" for="description-<?= escape($module['id']) ?>">Descripción</label>
                        <textarea class="form-control form-control-sm" id="description-<?= escape($module['id']) ?>" name="description" rows="2" maxlength="500" <?= has_permission($collaborator, 'modules', 'update') ? '' : 'disabled' ?>><?= escape($module['description']) ?></textarea>
                      </form>
                    </td>
                    <td>
                      <div class="form-check form-switch">
                        <input type="hidden" name="is_active" value="0" form="module-<?= escape($module['id']) ?>" <?= has_permission($collaborator, 'modules', 'update') ? '' : 'disabled' ?>>
                        <input class="form-check-input" type="checkbox" id="active-<?= escape($module['id']) ?>" name="is_active" value="1" form="module-<?= escape($module['id']) ?>" <?= (int) $module['is_active'] === 1 ? 'checked' : '' ?> <?= has_permission($collaborator, 'modules', 'update') ? '' : 'disabled' ?>>
                        <label class="form-check-label" for="active-<?= escape($module['id']) ?>"><?= (int) $module['is_active'] === 1 ? 'Activo' : 'Inactivo' ?></label>
                      </div>
                    </td>
                    <td>
                      <div class="d-flex flex-wrap gap-2">
                        <?php if (has_permission($collaborator, 'modules', 'update')): ?><button class="btn btn-primary btn-sm" type="submit" form="module-<?= escape($module['id']) ?>">
                          <i class="bi bi-check-lg me-1"></i>Guardar
                        </button><?php endif; ?>
                        <?php if (has_permission($collaborator, 'modules', 'delete')): ?><button class="btn btn-outline-danger btn-sm" type="submit" form="module-<?= escape($module['id']) ?>" name="action" value="delete" formnovalidate onclick="return confirm('¿Eliminar este módulo del catálogo?')">
                          <i class="bi bi-trash me-1"></i>Eliminar
                        </button><?php endif; ?>
                      </div>
                      <div class="border-top mt-3 pt-3">
                        <?php if (is_string($module['archive_path'])): ?>
                          <p class="small mb-2">
                            <i class="bi bi-file-earmark-zip me-1"></i>
                            <?= escape($module['archive_original_name']) ?>
                            <span class="text-body-secondary">(<?= escape(number_format((int) $module['archive_size'] / 1048576, 2)) ?> MiB)</span>
                          </p>
                          <a class="btn btn-outline-primary btn-sm mb-2" href="<?= escape(app_url('panel/modulo-descargar.php?id=' . $module['id'])) ?>">
                            <i class="bi bi-download me-1"></i>Descargar para revisar
                          </a>
                          <p class="small text-body-secondary mb-2">SHA-256: <code><?= escape($module['archive_sha256']) ?></code></p>
                        <?php else: ?>
                          <p class="small text-body-secondary">Sin ZIP adjunto.</p>
                        <?php endif; ?>
                        <?php if (has_permission($collaborator, 'modules', 'update')): ?><form method="post" enctype="multipart/form-data" action="<?= escape(app_url('panel/modulos.php')) ?>">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="upload">
                          <input type="hidden" name="id" value="<?= escape($module['id']) ?>">
                          <label class="form-label small" for="archive-<?= escape($module['id']) ?>">
                            <?= is_string($module['archive_path']) ? 'Reemplazar ZIP' : 'Adjuntar ZIP' ?>
                          </label>
                          <input class="form-control form-control-sm mb-2" type="file" id="archive-<?= escape($module['id']) ?>" name="archive" accept=".zip,application/zip" required>
                          <button class="btn btn-outline-secondary btn-sm" type="submit">
                            <i class="bi bi-upload me-1"></i>Guardar ZIP
                          </button>
                          <div class="form-text">Solo ZIP válido, máximo 20 MiB. No se ejecuta automáticamente.</div>
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
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth.php';
$collaborator = require_collaborator();
$isAdmin = $collaborator['rol'] === 'administrador';

$error = null;
$form = [
    'nombre' => '',
    'email' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid()) {
        http_response_code(400);
        $error = 'El formulario venció. Recarga la página e inténtalo de nuevo.';
    } else {
        $action = $_POST['action'] ?? null;
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $form['nombre'] = is_string($_POST['nombre'] ?? null) ? trim($_POST['nombre']) : '';
        $form['email'] = is_string($_POST['email'] ?? null) ? trim($_POST['email']) : '';

        if ($action === 'create' || $action === 'update') {
            $password = $_POST['password'] ?? null;
            $role = $_POST['rol'] ?? 'colaborador';
            $isActive = isset($_POST['activo']) && $_POST['activo'] === '1' ? 1 : 0;
            $nameLength = preg_match_all('/./us', $form['nombre']);
            $validRole = is_string($role) && in_array($role, ['administrador', 'colaborador'], true);
            $validPassword = $action === 'create'
                ? is_string($password) && strlen($password) >= 12 && strlen($password) <= 72
                : ($password === '' || (is_string($password) && strlen($password) >= 12 && strlen($password) <= 72));

            if ($nameLength === false || $nameLength < 1 || $nameLength > 100
                || strlen($form['email']) > 190 || !filter_var($form['email'], FILTER_VALIDATE_EMAIL)
                || !$validPassword || !$validRole || (!$isAdmin && $role !== 'colaborador')
                || ($action === 'update' && $id === false)) {
                http_response_code(422);
                $error = !$isAdmin && $role !== 'colaborador'
                    ? 'Solo un administrador puede asignar ese rol.'
                    : 'Revisa el nombre, correo, rol y contraseña. La contraseña requiere de 12 a 72 bytes.';
            } else {
                $connection = database();
                $lockAcquired = false;
                try {
                    $connection->beginTransaction();
                    $lockAcquired = (int) $connection->query(
                        "SELECT GET_LOCK('portal_admin_role_change', 10)"
                    )->fetchColumn() === 1;
                    if (!$lockAcquired) {
                        throw new RuntimeException('No se pudo bloquear la operación de administración de cuentas.');
                    }

                    if ($action === 'create') {
                        $statement = $connection->prepare(
                            'INSERT INTO usuarios (nombre, email, password_hash, activo, rol)
                             VALUES (:nombre, :email, :password_hash, :activo, :rol)'
                        );
                        $statement->execute([
                            'nombre' => $form['nombre'],
                            'email' => $form['email'],
                            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                            'activo' => $isActive,
                            'rol' => $role,
                        ]);
                    } else {
                        $statement = $connection->prepare('SELECT id, rol, activo FROM usuarios WHERE id = :id FOR UPDATE');
                        $statement->execute(['id' => $id]);
                        $target = $statement->fetch();
                        if (!$target) {
                            $connection->rollBack();
                            http_response_code(404);
                            $error = 'No se encontró el colaborador seleccionado.';
                        } elseif ((!$isAdmin && $target['rol'] !== 'colaborador')
                            || ((int) $target['id'] === (int) $collaborator['id']
                                && ($role !== $target['rol'] || $isActive !== (int) $target['activo']))) {
                            $connection->rollBack();
                            http_response_code(403);
                            $error = 'No puedes modificar el rol o el estado de esa cuenta.';
                        } elseif ($target['rol'] === 'administrador' && (int) $target['activo'] === 1
                            && ($role !== 'administrador' || $isActive !== 1)
                            && (int) $connection->query(
                                "SELECT COUNT(*) FROM usuarios WHERE rol = 'administrador' AND activo = 1"
                            )->fetchColumn() <= 1) {
                            $connection->rollBack();
                            http_response_code(409);
                            $error = 'Debe quedar al menos un administrador activo.';
                        } else {
                            $sql = 'UPDATE usuarios
                                    SET nombre = :nombre, email = :email, activo = :activo, rol = :rol';
                            $parameters = [
                                'nombre' => $form['nombre'],
                                'email' => $form['email'],
                                'activo' => $isActive,
                                'rol' => $role,
                                'id' => $id,
                            ];
                            if (is_string($password) && $password !== '') {
                                $sql .= ', password_hash = :password_hash';
                                $parameters['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                            }
                            $sql .= ' WHERE id = :id';
                            $statement = $connection->prepare($sql);
                            $statement->execute($parameters);
                            if (is_string($password) && $password !== '') {
                                revoke_user_sessions($connection, (int) $id);
                            }
                            $connection->commit();
                            redirect('panel/colaboradores.php');
                        }
                    }

                    if ($connection->inTransaction()) {
                        $connection->commit();
                    }
                    if ($error === null) {
                        redirect('panel/colaboradores.php');
                    }
                } catch (PDOException $exception) {
                    if ($connection->inTransaction()) {
                        $connection->rollBack();
                    }
                    if ($exception->getCode() === '23000' && ($exception->errorInfo[1] ?? null) === 1062) {
                        http_response_code(409);
                        $error = 'Ya existe una cuenta con ese correo electrónico.';
                    } else {
                        error_log('No se pudo guardar el colaborador: ' . $exception->getMessage());
                        http_response_code(500);
                        $error = 'No se pudo guardar la cuenta. Inténtalo más tarde.';
                    }
                } catch (RuntimeException $exception) {
                    if ($connection->inTransaction()) {
                        $connection->rollBack();
                    }
                    error_log('No se pudo completar la operación de colaboradores: ' . $exception->getMessage());
                    http_response_code(500);
                    $error = 'No se pudo completar la operación. Inténtalo más tarde.';
                } finally {
                    if ($lockAcquired) {
                        $connection->query("SELECT RELEASE_LOCK('portal_admin_role_change')");
                    }
                }
            }
        } elseif ($action === 'delete' && $id !== false) {
            if ((int) $id === (int) $collaborator['id']) {
                http_response_code(403);
                $error = 'No puedes eliminar tu propia cuenta.';
            } else {
                $connection = database();
                $lockAcquired = false;
                try {
                    $connection->beginTransaction();
                    $lockAcquired = (int) $connection->query(
                        "SELECT GET_LOCK('portal_admin_role_change', 10)"
                    )->fetchColumn() === 1;
                    if (!$lockAcquired) {
                        throw new RuntimeException('No se pudo bloquear la operación de administración de cuentas.');
                    }

                    $statement = $connection->prepare('SELECT id, rol FROM usuarios WHERE id = :id FOR UPDATE');
                    $statement->execute(['id' => $id]);
                    $target = $statement->fetch();
                    if (!$target) {
                        $connection->rollBack();
                        http_response_code(404);
                        $error = 'No se encontró el colaborador seleccionado.';
                    } elseif (!$isAdmin && $target['rol'] !== 'colaborador') {
                        $connection->rollBack();
                        http_response_code(403);
                        $error = 'Solo un administrador puede eliminar esa cuenta.';
                    } elseif ($target['rol'] === 'administrador' && (int) $target['activo'] === 1
                        && (int) $connection->query(
                            "SELECT COUNT(*) FROM usuarios WHERE rol = 'administrador' AND activo = 1"
                        )->fetchColumn() <= 1) {
                        $connection->rollBack();
                        http_response_code(409);
                        $error = 'Debe quedar al menos un administrador activo.';
                    } else {
                        $statement = $connection->prepare('DELETE FROM usuarios WHERE id = :id');
                        $statement->execute(['id' => $id]);
                        $connection->commit();
                        redirect('panel/colaboradores.php');
                    }
                } catch (PDOException $exception) {
                    if ($connection->inTransaction()) {
                        $connection->rollBack();
                    }
                    error_log('No se pudo eliminar el colaborador: ' . $exception->getMessage());
                    http_response_code(500);
                    $error = 'No se pudo eliminar la cuenta. Inténtalo más tarde.';
                } catch (RuntimeException $exception) {
                    if ($connection->inTransaction()) {
                        $connection->rollBack();
                    }
                    error_log('No se pudo bloquear la eliminación del colaborador: ' . $exception->getMessage());
                    http_response_code(500);
                    $error = 'No se pudo completar la operación. Inténtalo más tarde.';
                } finally {
                    if ($lockAcquired) {
                        $connection->query("SELECT RELEASE_LOCK('portal_admin_role_change')");
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
    $sql = 'SELECT id, nombre, email, activo, rol, fecha_registro FROM usuarios';
    if (!$isAdmin) {
        $sql .= " WHERE rol = 'colaborador'";
    }
    $sql .= ' ORDER BY nombre, id';
    $users = database()->query($sql)->fetchAll();
} catch (PDOException $exception) {
    error_log('No se pudo cargar el listado de colaboradores: ' . $exception->getMessage());
    http_response_code(500);
    $users = [];
    $loadError = 'No se pudo cargar la lista de colaboradores.';
}

$pageTitle = 'Colaboradores';
$activePage = 'colaboradores';
require __DIR__ . '/includes/header.php';
?>
<div class="alert alert-info" role="status">
  Puedes crear, editar, activar, desactivar y eliminar cuentas. Solo los administradores pueden asignar roles administrativos. Debe permanecer al menos un administrador activo.
</div>

<?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?= escape($error) ?></div><?php endif; ?>

<div class="row g-4">
  <div class="col-xl-4">
    <div class="card">
      <div class="card-header"><h2 class="card-title h5 mb-0">Crear cuenta</h2></div>
      <div class="card-body">
        <form method="post" action="<?= escape(app_url('panel/colaboradores.php')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create">
          <div class="mb-3">
            <label for="new-name" class="form-label">Nombre completo</label>
            <input class="form-control" id="new-name" name="nombre" value="<?= escape($form['nombre']) ?>" maxlength="100" required>
          </div>
          <div class="mb-3">
            <label for="new-email" class="form-label">Correo electrónico</label>
            <input class="form-control" type="email" id="new-email" name="email" value="<?= escape($form['email']) ?>" maxlength="190" required>
          </div>
          <div class="mb-3">
            <label for="new-password" class="form-label">Contraseña inicial</label>
            <input class="form-control" type="password" id="new-password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required>
          </div>
          <div class="mb-3">
            <label for="new-role" class="form-label">Rol</label>
            <?php if ($isAdmin): ?>
              <select class="form-select" id="new-role" name="rol">
                <option value="colaborador">Colaborador</option>
                <option value="administrador">Administrador</option>
              </select>
            <?php else: ?>
              <input type="hidden" name="rol" value="colaborador">
              <input class="form-control" id="new-role" value="Colaborador" readonly>
              <div class="form-text">Solo un administrador puede crear otra cuenta administrativa.</div>
            <?php endif; ?>
          </div>
          <input type="hidden" name="activo" value="0">
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="new-active" name="activo" value="1" checked>
            <label class="form-check-label" for="new-active">Cuenta activa</label>
          </div>
          <button class="btn btn-primary" type="submit"><i class="bi bi-person-plus me-1"></i>Crear cuenta</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-xl-8">
    <div class="card">
      <div class="card-header"><h2 class="card-title h5 mb-0">Cuentas registradas</h2></div>
      <div class="card-body p-0">
        <?php if (isset($loadError)): ?><div class="alert alert-danger m-3" role="alert"><?= escape($loadError) ?></div><?php endif; ?>
        <?php if ($users === []): ?>
          <p class="p-3 mb-0 text-body-secondary">No hay cuentas para mostrar.</p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead>
                <tr><th scope="col">Cuenta</th><th scope="col">Rol y estado</th><th scope="col">Acciones</th></tr>
              </thead>
              <tbody>
                <?php foreach ($users as $user): ?>
                  <?php
                  $isSelf = (int) $user['id'] === (int) $collaborator['id'];
                  $canManage = $isAdmin || $user['rol'] === 'colaborador';
                  ?>
                  <tr>
                    <td>
                      <form id="user-<?= escape($user['id']) ?>" method="post" action="<?= escape(app_url('panel/colaboradores.php')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?= escape($user['id']) ?>">
                        <label class="visually-hidden" for="user-name-<?= escape($user['id']) ?>">Nombre</label>
                        <input class="form-control form-control-sm mb-2" id="user-name-<?= escape($user['id']) ?>" name="nombre" value="<?= escape($user['nombre']) ?>" maxlength="100" <?= $canManage ? 'required' : 'disabled' ?>>
                        <label class="visually-hidden" for="user-email-<?= escape($user['id']) ?>">Correo electrónico</label>
                        <input class="form-control form-control-sm mb-2" type="email" id="user-email-<?= escape($user['id']) ?>" name="email" value="<?= escape($user['email']) ?>" maxlength="190" <?= $canManage ? 'required' : 'disabled' ?>>
                        <label class="visually-hidden" for="user-password-<?= escape($user['id']) ?>">Nueva contraseña opcional</label>
                        <input class="form-control form-control-sm" type="password" id="user-password-<?= escape($user['id']) ?>" name="password" minlength="12" maxlength="72" autocomplete="new-password" placeholder="Nueva contraseña (opcional)" <?= $canManage ? '' : 'disabled' ?>>
                      </form>
                    </td>
                    <td>
                      <?php if ($isAdmin): ?>
                        <label class="visually-hidden" for="role-<?= escape($user['id']) ?>">Rol</label>
                        <select class="form-select form-select-sm mb-2" id="role-<?= escape($user['id']) ?>" name="rol" form="user-<?= escape($user['id']) ?>" <?= $isSelf ? 'disabled' : '' ?>>
                          <option value="colaborador" <?= $user['rol'] === 'colaborador' ? 'selected' : '' ?>>Colaborador</option>
                          <option value="administrador" <?= $user['rol'] === 'administrador' ? 'selected' : '' ?>>Administrador</option>
                        </select>
                      <?php else: ?>
                        <span class="badge text-bg-secondary mb-2">Colaborador</span>
                      <?php endif; ?>
                      <input type="hidden" name="activo" value="0" form="user-<?= escape($user['id']) ?>">
                      <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="active-<?= escape($user['id']) ?>" name="activo" value="1" form="user-<?= escape($user['id']) ?>" <?= (int) $user['activo'] === 1 ? 'checked' : '' ?> <?= $isSelf || !$canManage ? 'disabled' : '' ?>>
                        <label class="form-check-label" for="active-<?= escape($user['id']) ?>"><?= (int) $user['activo'] === 1 ? 'Activa' : 'Inactiva' ?></label>
                      </div>
                      <?php if ($isSelf): ?><small class="text-body-secondary">Tu cuenta no se puede desactivar aquí.</small><?php endif; ?>
                    </td>
                    <td>
                      <?php if ($canManage): ?>
                        <div class="d-flex flex-wrap gap-2">
                          <button class="btn btn-primary btn-sm" type="submit" form="user-<?= escape($user['id']) ?>" <?= $isSelf ? 'disabled' : '' ?>>
                            <i class="bi bi-check-lg me-1"></i>Guardar
                          </button>
                          <button class="btn btn-outline-danger btn-sm" type="submit" form="user-<?= escape($user['id']) ?>" name="action" value="delete" formnovalidate <?= $isSelf ? 'disabled' : '' ?> onclick="return confirm('¿Eliminar esta cuenta y su historial de sesiones?')">
                            <i class="bi bi-trash me-1"></i>Eliminar
                          </button>
                        </div>
                      <?php else: ?>
                        <span class="text-body-secondary small">Solo un administrador puede gestionar administradores.</span>
                      <?php endif; ?>
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

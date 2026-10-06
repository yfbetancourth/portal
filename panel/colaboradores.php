<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth.php';
$collaborator = require_collaborator();
$isAdmin = $collaborator['rol'] === 'administrador';
$canReadCollaborators = has_permission($collaborator, 'collaborators', 'read');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !has_resource_access($collaborator, 'collaborators')) {
    http_response_code(403);
    exit('No tienes permiso para acceder a esta sección.');
}

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

        if (in_array($action, ['role_create', 'role_update', 'role_delete'], true)) {
            if (!$isAdmin) {
                http_response_code(403);
                $error = 'Solo un administrador puede administrar roles.';
            } else {
                $roleKey = is_string($_POST['role_key'] ?? null) ? $_POST['role_key'] : '';
                $roleName = is_string($_POST['role_name'] ?? null) ? trim($_POST['role_name']) : '';
                $nameLength = preg_match_all('/./us', $roleName);
                $submittedPermissions = $_POST['permissions'] ?? [];
                $grants = permission_selection($submittedPermissions);
                $validRoleKey = preg_match('/\A[a-z0-9_]{1,80}\z/', $roleKey) === 1;

                if (($action !== 'role_delete' && ($nameLength === false || $nameLength < 1 || $nameLength > 100))
                    || ($action !== 'role_delete' && $grants === false)
                    || ($action !== 'role_create' && !$validRoleKey)) {
                    http_response_code(422);
                    $error = 'El nombre del rol o los permisos seleccionados no son válidos.';
                } else {
                    $connection = null;
                    $lockAcquired = false;
                    try {
                        $connection = database();
                        $connection->beginTransaction();
                        $lockAcquired = (int) $connection->query(
                            "SELECT GET_LOCK('portal_admin_role_change', 10)"
                        )->fetchColumn() === 1;
                        if (!$lockAcquired) {
                            throw new RuntimeException('No se pudo bloquear la operación de administración de roles.');
                        }

                        if ($action === 'role_create') {
                            $roleKey = 'rol_' . bin2hex(random_bytes(12));
                            $statement = $connection->prepare(
                                'INSERT INTO roles (role_key, name, is_system)
                                 VALUES (:role_key, :name, 0)'
                            );
                            $statement->execute(['role_key' => $roleKey, 'name' => $roleName]);
                            replace_role_permissions($connection, $roleKey, $grants);
                        } else {
                            $statement = $connection->prepare(
                                'SELECT role_key, is_system FROM roles WHERE role_key = :role_key FOR UPDATE'
                            );
                            $statement->execute(['role_key' => $roleKey]);
                            $targetRole = $statement->fetch();

                            if (!$targetRole) {
                                $connection->rollBack();
                                http_response_code(404);
                                $error = 'No se encontró el rol seleccionado.';
                            } elseif ($action === 'role_delete') {
                                $assignedUsers = $connection->prepare(
                                    'SELECT COUNT(*) FROM usuarios WHERE rol = :role_key'
                                );
                                $assignedUsers->execute(['role_key' => $roleKey]);
                                if ((int) $assignedUsers->fetchColumn() > 0) {
                                    $connection->rollBack();
                                    http_response_code(409);
                                    $error = 'No se puede eliminar un rol que tiene cuentas asignadas.';
                                } elseif ((int) $targetRole['is_system'] === 1) {
                                    $connection->rollBack();
                                    http_response_code(409);
                                    $error = 'Los roles del sistema no se pueden eliminar.';
                                } else {
                                    $statement = $connection->prepare('DELETE FROM roles WHERE role_key = :role_key');
                                    $statement->execute(['role_key' => $roleKey]);
                                }
                            } elseif ($roleKey === 'administrador') {
                                $connection->rollBack();
                                http_response_code(409);
                                $error = 'Los permisos del administrador siempre son totales.';
                            } else {
                                if ((int) $targetRole['is_system'] === 0) {
                                    $statement = $connection->prepare(
                                        'UPDATE roles SET name = :name WHERE role_key = :role_key'
                                    );
                                    $statement->execute(['name' => $roleName, 'role_key' => $roleKey]);
                                }
                                replace_role_permissions($connection, $roleKey, $grants);
                            }
                        }

                        if ($connection->inTransaction()) {
                            $connection->commit();
                        }
                        if ($error === null) {
                            redirect('panel/colaboradores.php');
                        }
                    } catch (PDOException $exception) {
                        if ($connection instanceof PDO && $connection->inTransaction()) {
                            $connection->rollBack();
                        }
                        if ($exception->getCode() === '23000'
                            && ($exception->errorInfo[1] ?? null) === 1062) {
                            http_response_code(409);
                            $error = 'Ya existe un rol con ese nombre.';
                        } else {
                            error_log('No se pudo guardar el rol: ' . $exception->getMessage());
                            http_response_code(500);
                            $error = 'No se pudo completar la operación de roles.';
                        }
                    } catch (RuntimeException $exception) {
                        if ($connection instanceof PDO && $connection->inTransaction()) {
                            $connection->rollBack();
                        }
                        error_log('No se pudo bloquear la administración de roles: ' . $exception->getMessage());
                        http_response_code(500);
                        $error = 'No se pudo completar la operación. Inténtalo más tarde.';
                    } finally {
                        if ($connection instanceof PDO && $lockAcquired) {
                            $connection->query("SELECT RELEASE_LOCK('portal_admin_role_change')");
                        }
                    }
                }
            }
        } elseif ($action === 'permissions') {
            if (!$isAdmin) {
                http_response_code(403);
                $error = 'Solo un administrador puede configurar permisos individuales.';
            } elseif ($id === false) {
                http_response_code(422);
                $error = 'La cuenta o los permisos seleccionados no son válidos.';
            } else {
                $grants = permission_selection($_POST['permissions'] ?? []);
                if ($grants === false) {
                    http_response_code(422);
                    $error = 'La selección contiene permisos no válidos.';
                } else {
                    $connection = null;
                    try {
                        $connection = database();
                        $connection->beginTransaction();
                        $statement = $connection->prepare("SELECT rol FROM usuarios WHERE id = :id FOR UPDATE");
                        $statement->execute(['id' => $id]);
                        $targetRole = $statement->fetchColumn();
                        if ($targetRole === false) {
                            $connection->rollBack();
                            http_response_code(404);
                            $error = 'No se encontró el colaborador seleccionado.';
                        } elseif ($targetRole === 'administrador') {
                            $connection->rollBack();
                            http_response_code(409);
                            $error = 'No se pueden configurar permisos individuales para administradores.';
                        } else {
                            $statement = $connection->prepare('DELETE FROM user_permissions WHERE user_id = :user_id');
                            $statement->execute(['user_id' => $id]);
                            $statement = $connection->prepare(
                                'INSERT INTO user_permissions (user_id, resource, action)
                                 VALUES (:user_id, :resource, :action)'
                            );
                            foreach ($grants as [$resource, $permissionAction]) {
                                $statement->execute([
                                    'user_id' => $id,
                                    'resource' => $resource,
                                    'action' => $permissionAction,
                                ]);
                            }
                            $connection->commit();
                            redirect('panel/colaboradores.php');
                        }
                    } catch (PDOException $exception) {
                        if ($connection instanceof PDO && $connection->inTransaction()) {
                            $connection->rollBack();
                        }
                        error_log('No se pudieron guardar los permisos del colaborador: ' . $exception->getMessage());
                        http_response_code(500);
                        $error = 'No se pudieron guardar los permisos. Inténtalo más tarde.';
                    }
                }
            }
        } elseif ($action === 'create' || $action === 'update') {
            require_permission($collaborator, 'collaborators', $action);
            $password = $_POST['password'] ?? null;
            $role = $_POST['rol'] ?? 'colaborador';
            $isActive = isset($_POST['activo']) && $_POST['activo'] === '1' ? 1 : 0;
            $nameLength = preg_match_all('/./us', $form['nombre']);
            $validRole = is_string($role) && preg_match('/\A[a-z0-9_]{1,80}\z/', $role) === 1;
            $validPassword = $action === 'create'
                ? is_string($password) && strlen($password) >= 12 && strlen($password) <= 72
                : ($password === '' || (is_string($password) && strlen($password) >= 12 && strlen($password) <= 72));

            if ($nameLength === false || $nameLength < 1 || $nameLength > 100
                || strlen($form['email']) > 190 || !filter_var($form['email'], FILTER_VALIDATE_EMAIL)
                || !$validPassword || !$validRole
                || (!$isAdmin && $action === 'create' && $role !== 'colaborador')
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

                    $statement = $connection->prepare('SELECT 1 FROM roles WHERE role_key = :role_key');
                    $statement->execute(['role_key' => $role]);
                    if (!$statement->fetchColumn()) {
                        $connection->rollBack();
                        http_response_code(422);
                        $error = 'El rol seleccionado no es válido.';
                    } elseif ($action === 'create') {
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
                        if ($role === 'colaborador') {
                            grant_default_collaborator_permissions($connection, (int) $connection->lastInsertId());
                        }
                    } else {
                        $statement = $connection->prepare('SELECT id, rol, activo FROM usuarios WHERE id = :id FOR UPDATE');
                        $statement->execute(['id' => $id]);
                        $target = $statement->fetch();
                        if (!$target) {
                            $connection->rollBack();
                            http_response_code(404);
                            $error = 'No se encontró el colaborador seleccionado.';
                        } elseif ((!$isAdmin && $target['rol'] !== $role)
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
                            if ($target['rol'] === 'administrador' && $role === 'colaborador') {
                                grant_default_collaborator_permissions($connection, (int) $id);
                            }
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
            require_permission($collaborator, 'collaborators', 'delete');
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

                    $statement = $connection->prepare('SELECT id, rol, activo FROM usuarios WHERE id = :id FOR UPDATE');
                    $statement->execute(['id' => $id]);
                    $target = $statement->fetch();
                    if (!$target) {
                        $connection->rollBack();
                        http_response_code(404);
                        $error = 'No se encontró el colaborador seleccionado.';
                    } elseif (!$isAdmin && $target['rol'] === 'administrador') {
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
    $roles = database()->query(
        'SELECT role_key, name, is_system FROM roles ORDER BY is_system DESC, name'
    )->fetchAll();
    if (!$canReadCollaborators) {
        $users = [];
    } else {
        $sql = 'SELECT u.id, u.nombre, u.email, u.activo, u.rol, r.name AS role_name, u.fecha_registro
                FROM usuarios u INNER JOIN roles r ON r.role_key = u.rol';
        if (!$isAdmin) {
            $sql .= " WHERE u.rol <> 'administrador'";
        }
        $sql .= ' ORDER BY u.nombre, u.id';
        $users = database()->query($sql)->fetchAll();
    }
    $userPermissions = [];
    $rolePermissions = [];
    if ($isAdmin) {
        foreach (database()->query('SELECT user_id, resource, action FROM user_permissions')->fetchAll() as $grant) {
            $userPermissions[(int) $grant['user_id']][$grant['resource'] . '.' . $grant['action']] = true;
        }
        foreach (database()->query('SELECT role_key, resource, action FROM role_permissions')->fetchAll() as $grant) {
            $rolePermissions[$grant['role_key']][$grant['resource'] . '.' . $grant['action']] = true;
        }
    }
} catch (PDOException $exception) {
    error_log('No se pudo cargar el listado de colaboradores: ' . $exception->getMessage());
    http_response_code(500);
    $users = [];
    $roles = [];
    $userPermissions = [];
    $rolePermissions = [];
    $loadError = 'No se pudo cargar la lista de colaboradores.';
}

$pageTitle = 'Colaboradores';
$activePage = 'colaboradores';
require __DIR__ . '/includes/header.php';
?>
<div class="alert alert-info" role="status">
  Los permisos de un rol se comparten con todas sus cuentas. Los permisos individuales se conservan como accesos adicionales. Solo los administradores pueden administrar roles y asignarlos.
</div>

<?php if ($error !== null): ?><div class="alert alert-danger" role="alert"><?= escape($error) ?></div><?php endif; ?>

<div class="row g-4">
  <div class="col-xl-4">
    <div class="card">
      <div class="card-header"><h2 class="card-title h5 mb-0">Crear cuenta</h2></div>
      <div class="card-body">
        <?php if (has_permission($collaborator, 'collaborators', 'create')): ?>
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
                <?php foreach ($roles as $role): ?>
                  <option value="<?= escape($role['role_key']) ?>" <?= $role['role_key'] === 'colaborador' ? 'selected' : '' ?>><?= escape($role['name']) ?></option>
                <?php endforeach; ?>
              </select>
            <?php else: ?>
              <input type="hidden" name="rol" value="colaborador">
              <input class="form-control" id="new-role" value="Colaborador" readonly>
              <div class="form-text">Solo un administrador puede asignar roles.</div>
            <?php endif; ?>
          </div>
          <input type="hidden" name="activo" value="0">
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="new-active" name="activo" value="1" checked>
            <label class="form-check-label" for="new-active">Cuenta activa</label>
          </div>
          <button class="btn btn-primary" type="submit"><i class="bi bi-person-plus me-1"></i>Crear cuenta</button>
        </form>
        <?php else: ?>
          <p class="text-body-secondary mb-0">No tienes permiso para crear cuentas.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-xl-8">
    <div class="card">
      <div class="card-header"><h2 class="card-title h5 mb-0">Cuentas registradas</h2></div>
      <div class="card-body p-0">
        <?php if (isset($loadError)): ?><div class="alert alert-danger m-3" role="alert"><?= escape($loadError) ?></div><?php endif; ?>
        <?php if (!$canReadCollaborators): ?>
          <p class="p-3 mb-0 text-body-secondary">Tienes permiso para acciones concretas, pero no para consultar la lista de cuentas.</p>
        <?php elseif ($users === []): ?>
          <p class="p-3 mb-0 text-body-secondary">No hay cuentas para mostrar.</p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead>
                <tr><th scope="col">Cuenta</th><th scope="col">Rol y estado</th><?php if ($isAdmin): ?><th scope="col">Permisos individuales adicionales</th><?php endif; ?><th scope="col">Acciones</th></tr>
              </thead>
              <tbody>
                <?php foreach ($users as $user): ?>
                  <?php
                  $isSelf = (int) $user['id'] === (int) $collaborator['id'];
                  $canManage = $isAdmin || $user['rol'] !== 'administrador';
                  $canUpdate = has_permission($collaborator, 'collaborators', 'update') && $canManage;
                  $canDelete = has_permission($collaborator, 'collaborators', 'delete') && $canManage && !$isSelf;
                  ?>
                  <tr>
                    <td>
                      <form id="user-<?= escape($user['id']) ?>" method="post" action="<?= escape(app_url('panel/colaboradores.php')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?= escape($user['id']) ?>">
                        <?php if (!$isAdmin): ?><input type="hidden" name="rol" value="<?= escape($user['rol']) ?>"><?php endif; ?>
                        <label class="visually-hidden" for="user-name-<?= escape($user['id']) ?>">Nombre</label>
                        <input class="form-control form-control-sm mb-2" id="user-name-<?= escape($user['id']) ?>" name="nombre" value="<?= escape($user['nombre']) ?>" maxlength="100" <?= $canUpdate ? 'required' : 'disabled' ?>>
                        <label class="visually-hidden" for="user-email-<?= escape($user['id']) ?>">Correo electrónico</label>
                        <input class="form-control form-control-sm mb-2" type="email" id="user-email-<?= escape($user['id']) ?>" name="email" value="<?= escape($user['email']) ?>" maxlength="190" <?= $canUpdate ? 'required' : 'disabled' ?>>
                        <label class="visually-hidden" for="user-password-<?= escape($user['id']) ?>">Nueva contraseña opcional</label>
                        <input class="form-control form-control-sm" type="password" id="user-password-<?= escape($user['id']) ?>" name="password" minlength="12" maxlength="72" autocomplete="new-password" placeholder="Nueva contraseña (opcional)" <?= $canUpdate ? '' : 'disabled' ?>>
                      </form>
                    </td>
                    <td>
                      <?php if ($isAdmin): ?>
                        <label class="visually-hidden" for="role-<?= escape($user['id']) ?>">Rol</label>
                        <select class="form-select form-select-sm mb-2" id="role-<?= escape($user['id']) ?>" name="rol" form="user-<?= escape($user['id']) ?>" <?= $isSelf ? 'disabled' : '' ?>>
                          <?php foreach ($roles as $role): ?>
                            <option value="<?= escape($role['role_key']) ?>" <?= $user['rol'] === $role['role_key'] ? 'selected' : '' ?>><?= escape($role['name']) ?></option>
                          <?php endforeach; ?>
                        </select>
                      <?php else: ?>
                        <span class="badge text-bg-secondary mb-2"><?= escape($user['role_name']) ?></span>
                      <?php endif; ?>
                      <input type="hidden" name="activo" value="0" form="user-<?= escape($user['id']) ?>">
                      <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="active-<?= escape($user['id']) ?>" name="activo" value="1" form="user-<?= escape($user['id']) ?>" <?= (int) $user['activo'] === 1 ? 'checked' : '' ?> <?= $isSelf || !$canUpdate ? 'disabled' : '' ?>>
                        <label class="form-check-label" for="active-<?= escape($user['id']) ?>"><?= (int) $user['activo'] === 1 ? 'Activa' : 'Inactiva' ?></label>
                      </div>
                      <?php if ($isSelf): ?><small class="text-body-secondary">Tu cuenta no se puede desactivar aquí.</small><?php endif; ?>
                    </td>
                    <?php if ($isAdmin): ?>
                    <td>
                      <?php if ($user['rol'] !== 'administrador'): ?>
                        <form id="permissions-<?= escape($user['id']) ?>" method="post" action="<?= escape(app_url('panel/colaboradores.php')) ?>">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="permissions">
                          <input type="hidden" name="id" value="<?= escape($user['id']) ?>">
                          <?php foreach (permission_catalog() as $resource => $definition): ?>
                            <fieldset class="mb-2">
                              <legend class="small fw-semibold mb-1"><?= escape($definition['label']) ?></legend>
                              <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($definition['actions'] as $permissionAction => $permissionLabel): ?>
                                  <?php $permissionValue = $resource . '.' . $permissionAction; ?>
                                  <label class="form-check form-check-inline small mb-0">
                                    <input class="form-check-input" type="checkbox" name="permissions[]" value="<?= escape($permissionValue) ?>" <?= isset($userPermissions[(int) $user['id']][$permissionValue]) ? 'checked' : '' ?>>
                                    <span class="form-check-label"><?= escape($permissionLabel) ?></span>
                                  </label>
                                <?php endforeach; ?>
                              </div>
                            </fieldset>
                          <?php endforeach; ?>
                          <button class="btn btn-outline-primary btn-sm" type="submit">Guardar permisos</button>
                        </form>
                      <?php else: ?>
                        <span class="text-body-secondary small">Acceso total por rol de administrador.</span>
                      <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td>
                      <?php if ($canUpdate || $canDelete): ?>
                        <div class="d-flex flex-wrap gap-2">
                          <?php if ($canUpdate): ?><button class="btn btn-primary btn-sm" type="submit" form="user-<?= escape($user['id']) ?>" <?= $isSelf ? 'disabled' : '' ?>>
                            <i class="bi bi-check-lg me-1"></i>Guardar
                          </button><?php endif; ?>
                          <?php if ($canDelete): ?><button class="btn btn-outline-danger btn-sm" type="submit" form="user-<?= escape($user['id']) ?>" name="action" value="delete" formnovalidate onclick="return confirm('¿Eliminar esta cuenta y su historial de sesiones?')">
                            <i class="bi bi-trash me-1"></i>Eliminar
                          </button><?php endif; ?>
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

<?php if ($isAdmin): ?>
  <div class="card mt-4">
    <div class="card-header">
      <h2 class="card-title h5 mb-0">Administrar roles</h2>
    </div>
    <div class="card-body">
      <p class="text-body-secondary">Crea roles y define sus permisos compartidos. Los permisos individuales de cada cuenta se agregan a los del rol. El rol Administrador siempre conserva acceso total.</p>
      <form method="post" action="<?= escape(app_url('panel/colaboradores.php')) ?>" class="border rounded p-3 mb-4">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="role_create">
        <div class="mb-3">
          <label for="new-role-name" class="form-label">Nombre del nuevo rol</label>
          <input class="form-control" id="new-role-name" name="role_name" maxlength="100" required>
        </div>
        <?php foreach (permission_catalog() as $resource => $definition): ?>
          <fieldset class="mb-3">
            <legend class="small fw-semibold mb-1"><?= escape($definition['label']) ?></legend>
            <div class="d-flex flex-wrap gap-3">
              <?php foreach ($definition['actions'] as $permissionAction => $permissionLabel): ?>
                <label class="form-check form-check-inline small mb-0">
                  <input class="form-check-input" type="checkbox" name="permissions[]" value="<?= escape($resource . '.' . $permissionAction) ?>">
                  <span class="form-check-label"><?= escape($permissionLabel) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </fieldset>
        <?php endforeach; ?>
        <button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg me-1"></i>Crear rol</button>
      </form>

      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead>
            <tr><th scope="col">Rol</th><th scope="col">Permisos compartidos</th><th scope="col">Acciones</th></tr>
          </thead>
          <tbody>
            <?php foreach ($roles as $role): ?>
              <?php $roleFormId = 'role-' . $role['role_key']; ?>
              <tr>
                <td>
                  <?php if ((int) $role['is_system'] === 1): ?>
                    <span class="fw-semibold"><?= escape($role['name']) ?></span>
                    <div class="small text-body-secondary">Rol del sistema</div>
                  <?php else: ?>
                    <label class="visually-hidden" for="<?= escape($roleFormId) ?>-name">Nombre del rol</label>
                    <input class="form-control form-control-sm" id="<?= escape($roleFormId) ?>-name" name="role_name" form="<?= escape($roleFormId) ?>" value="<?= escape($role['name']) ?>" maxlength="100" required>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($role['role_key'] === 'administrador'): ?>
                    <span class="text-body-secondary small">Acceso total protegido</span>
                  <?php else: ?>
                    <form id="<?= escape($roleFormId) ?>" method="post" action="<?= escape(app_url('panel/colaboradores.php')) ?>">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="role_update">
                      <input type="hidden" name="role_key" value="<?= escape($role['role_key']) ?>">
                    </form>
                    <?php foreach (permission_catalog() as $resource => $definition): ?>
                      <fieldset class="mb-2">
                        <legend class="small fw-semibold mb-1"><?= escape($definition['label']) ?></legend>
                        <div class="d-flex flex-wrap gap-2">
                          <?php foreach ($definition['actions'] as $permissionAction => $permissionLabel): ?>
                            <?php $permissionValue = $resource . '.' . $permissionAction; ?>
                            <label class="form-check form-check-inline small mb-0">
                              <input class="form-check-input" type="checkbox" name="permissions[]" form="<?= escape($roleFormId) ?>" value="<?= escape($permissionValue) ?>" <?= isset($rolePermissions[$role['role_key']][$permissionValue]) ? 'checked' : '' ?>>
                              <span class="form-check-label"><?= escape($permissionLabel) ?></span>
                            </label>
                          <?php endforeach; ?>
                        </div>
                      </fieldset>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($role['role_key'] !== 'administrador'): ?>
                    <div class="d-flex flex-wrap gap-2">
                      <button class="btn btn-primary btn-sm" type="submit" form="<?= escape($roleFormId) ?>">
                        <i class="bi bi-check-lg me-1"></i>Guardar
                      </button>
                      <?php if ((int) $role['is_system'] === 0): ?>
                        <form method="post" action="<?= escape(app_url('panel/colaboradores.php')) ?>">
                          <?= csrf_field() ?>
                          <input type="hidden" name="action" value="role_delete">
                          <input type="hidden" name="role_key" value="<?= escape($role['role_key']) ?>">
                          <button class="btn btn-outline-danger btn-sm" type="submit" onclick="return confirm('¿Eliminar este rol? Solo se puede eliminar si no tiene cuentas asignadas.')">
                            <i class="bi bi-trash me-1"></i>Eliminar
                          </button>
                        </form>
                      <?php endif; ?>
                    </div>
                  <?php else: ?>
                    <span class="text-body-secondary small">No modificable</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>

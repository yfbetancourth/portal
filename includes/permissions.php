<?php
declare(strict_types=1);

function permission_catalog(): array
{
    return [
        'dashboard' => [
            'label' => 'Inicio',
            'actions' => ['read' => 'Leer'],
        ],
        'profile' => [
            'label' => 'Mi cuenta',
            'actions' => ['read' => 'Leer', 'update' => 'Actualizar'],
        ],
        'modules' => [
            'label' => 'Módulos',
            'actions' => ['create' => 'Crear', 'read' => 'Leer', 'update' => 'Actualizar', 'delete' => 'Eliminar'],
        ],
        'collaborators' => [
            'label' => 'Colaboradores',
            'actions' => ['create' => 'Crear', 'read' => 'Leer', 'update' => 'Actualizar', 'delete' => 'Eliminar'],
        ],
        'products' => [
            'label' => 'Productos',
            'actions' => ['create' => 'Crear', 'read' => 'Leer', 'update' => 'Actualizar', 'delete' => 'Eliminar'],
        ],
    ];
}

function default_collaborator_permissions(): array
{
    return [
        ['dashboard', 'read'],
        ['profile', 'read'],
        ['profile', 'update'],
    ];
}

function permission_selection(mixed $submitted): array|false
{
    if (!is_array($submitted)) {
        return false;
    }

    $catalog = permission_catalog();
    $grants = [];
    foreach ($submitted as $permission) {
        if (!is_string($permission) || !preg_match('/\A([a-z_]+)\.(create|read|update|delete)\z/', $permission, $matches)
            || !isset($catalog[$matches[1]]['actions'][$matches[2]])) {
            return false;
        }
        $grants[$matches[1] . '.' . $matches[2]] = [$matches[1], $matches[2]];
    }

    return $grants;
}

function replace_role_permissions(PDO $connection, string $roleKey, array $grants): void
{
    $statement = $connection->prepare('DELETE FROM role_permissions WHERE role_key = :role_key');
    $statement->execute(['role_key' => $roleKey]);
    $statement = $connection->prepare(
        'INSERT INTO role_permissions (role_key, resource, action)
         VALUES (:role_key, :resource, :action)'
    );
    foreach ($grants as [$resource, $action]) {
        $statement->execute([
            'role_key' => $roleKey,
            'resource' => $resource,
            'action' => $action,
        ]);
    }
}

function grant_default_collaborator_permissions(PDO $connection, int $userId): void
{
    $statement = $connection->prepare(
        'INSERT IGNORE INTO user_permissions (user_id, resource, action)
         VALUES (:user_id, :resource, :action)'
    );
    foreach (default_collaborator_permissions() as [$resource, $action]) {
        $statement->execute([
            'user_id' => $userId,
            'resource' => $resource,
            'action' => $action,
        ]);
    }
}

function has_permission(array $user, string $resource, string $action): bool
{
    $catalog = permission_catalog();
    if (!isset($catalog[$resource]['actions'][$action])) {
        return false;
    }

    if (($user['rol'] ?? null) === 'administrador') {
        return true;
    }

    static $cache = [];
    $userId = filter_var($user['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $roleKey = $user['rol'] ?? null;
    if (!is_int($userId) || !is_string($roleKey) || $roleKey === '') {
        return false;
    }

    $key = $userId . ':' . $roleKey . ':' . $resource . ':' . $action;
    if (!array_key_exists($key, $cache)) {
        $statement = database()->prepare(
            'SELECT 1 FROM role_permissions
             WHERE role_key = :role_key AND resource = :role_resource AND action = :role_action
             UNION ALL
             SELECT 1 FROM user_permissions
             WHERE user_id = :user_id AND resource = :user_resource AND action = :user_action
             LIMIT 1'
        );
        $statement->execute([
            'role_key' => $roleKey,
            'user_id' => $userId,
            'role_resource' => $resource,
            'role_action' => $action,
            'user_resource' => $resource,
            'user_action' => $action,
        ]);
        $cache[$key] = (bool) $statement->fetchColumn();
    }

    return $cache[$key];
}

function has_resource_access(array $user, string $resource): bool
{
    $catalog = permission_catalog();
    if (!isset($catalog[$resource])) {
        return false;
    }

    foreach (array_keys($catalog[$resource]['actions']) as $action) {
        if (has_permission($user, $resource, $action)) {
            return true;
        }
    }

    return false;
}

function require_permission(array $user, string $resource, string $action): void
{
    if (!has_permission($user, $resource, $action)) {
        http_response_code(403);
        exit('No tienes permiso para realizar esta operación.');
    }
}

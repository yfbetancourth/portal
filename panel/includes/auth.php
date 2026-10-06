<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';

function require_collaborator(): array
{
    $userId = $_SESSION['user_id'] ?? null;
    if (!is_int($userId) || $userId < 1) {
        redirect('panel/login.php');
    }

    try {
        $statement = database()->prepare(
            "SELECT u.id, u.nombre, u.email, u.rol, r.name AS role_name
             FROM usuarios u
             INNER JOIN roles r ON r.role_key = u.rol
             INNER JOIN auth_sessions s ON s.user_id = u.id
             WHERE u.id = :id AND u.activo = 1
               AND s.session_hash = :session_hash AND s.ended_at IS NULL
             LIMIT 1"
        );
        $sessionHash = $_SESSION['auth_session_hash'] ?? null;
        if (!is_string($sessionHash) || !hash_equals($sessionHash, hash('sha256', session_id()))) {
            close_collaborator_session();
            redirect('panel/login.php');
        }
        $statement->execute(['id' => $userId, 'session_hash' => $sessionHash]);
        $user = $statement->fetch();
    } catch (PDOException $exception) {
        error_log('No se pudo verificar la sesión del panel: ' . $exception->getMessage());
        http_response_code(500);
        exit('No se pudo verificar el acceso. Inténtalo más tarde.');
    }

    if (!$user) {
        close_collaborator_session();
        redirect('panel/login.php');
    }

    return $user;
}

function require_admin(): array
{
    $user = require_collaborator();
    if ($user['rol'] !== 'administrador') {
        http_response_code(403);
        exit('No tienes permisos para asignar roles de administrador.');
    }

    return $user;
}

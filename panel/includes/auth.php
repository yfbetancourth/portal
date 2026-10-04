<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

function require_collaborator(): array
{
    $userId = $_SESSION['user_id'] ?? null;
    if (!is_int($userId) || $userId < 1) {
        redirect('panel/login.php');
    }

    try {
        $statement = database()->prepare(
            "SELECT id, nombre, email, rol
             FROM usuarios
             WHERE id = :id AND activo = 1 AND rol = 'colaborador'
             LIMIT 1"
        );
        $statement->execute(['id' => $userId]);
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

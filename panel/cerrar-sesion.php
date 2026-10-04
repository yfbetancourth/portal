<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth.php';
require_collaborator();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método no permitido.');
}

if (!csrf_is_valid()) {
    http_response_code(400);
    exit('El formulario venció. Recarga la página e inténtalo de nuevo.');
}

try {
    close_collaborator_session();
} catch (PDOException $exception) {
    error_log('No se pudo guardar el cierre de sesión del colaborador: ' . $exception->getMessage());
    http_response_code(500);
    exit('No se pudo cerrar la sesión. Inténtalo de nuevo.');
}

redirect('panel/login.php');

<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/config/database.php';

$email = $argv[1] ?? '';
if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
    fwrite(STDERR, "Uso: php scripts/promote-first-admin.php correo@ejemplo.com\n");
    exit(2);
}

$connection = null;
$lockAcquired = false;

try {
    $connection = database_connection();
    $lockAcquired = (int) $connection->query("SELECT GET_LOCK('portal_first_admin', 10)")->fetchColumn() === 1;
    if (!$lockAcquired) {
        throw new RuntimeException('No se pudo bloquear la inicialización del primer administrador.');
    }

    $adminCount = (int) $connection->query(
        "SELECT COUNT(*) FROM usuarios WHERE rol = 'administrador' AND activo = 1"
    )->fetchColumn();
    if ($adminCount > 0) {
        throw new RuntimeException('Ya existe un administrador activo. La inicialización solo funciona una vez.');
    }

    $statement = $connection->prepare(
        "UPDATE usuarios
         SET rol = 'administrador'
         WHERE email = :email AND activo = 1 AND rol = 'colaborador'"
    );
    $statement->execute(['email' => $email]);
    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('No se encontró una cuenta colaboradora activa con ese correo.');
    }

    fwrite(STDOUT, "La cuenta indicada ahora es administrador. Inicia sesión de nuevo para aplicar el rol.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    $exitCode = 1;
} finally {
    if ($connection instanceof PDO && $lockAcquired) {
        $connection->query("SELECT RELEASE_LOCK('portal_first_admin')");
    }
}

if (isset($exitCode)) {
    exit($exitCode);
}

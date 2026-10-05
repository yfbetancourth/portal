<?php
declare(strict_types=1);

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

$scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$appDirectory = basename($scriptDirectory) === 'panel' ? dirname($scriptDirectory) : $scriptDirectory;
$appDirectory = trim(str_replace('\\', '/', $appDirectory), '/');
define('APP_BASE_PATH', $appDirectory === '' ? '' : '/' . $appDirectory);

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_name('portal_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => APP_BASE_PATH === '' ? '/' : APP_BASE_PATH . '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

require_once dirname(__DIR__) . '/config/database.php';

function app_url(string $path = ''): string
{
    return APP_BASE_PATH . '/' . ltrim($path, '/');
}

function escape(string|int|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function database(): PDO
{
    return database_connection();
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . escape(csrf_token()) . '">';
}

function csrf_is_valid(): bool
{
    $submitted = $_POST['csrf_token'] ?? null;

    return is_string($submitted)
        && isset($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $submitted);
}

function redirect(string $path): never
{
    header('Location: ' . app_url($path), true, 303);
    exit;
}

function start_collaborator_session(int $userId): void
{
    session_regenerate_id(true);
    $sessionHash = hash('sha256', session_id());
    $connection = database();
    $connection->beginTransaction();

    try {
        $statement = $connection->prepare(
            'INSERT INTO auth_sessions (session_hash, user_id, started_at, last_seen)
             VALUES (:session_hash, :user_id, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))'
        );
        $statement->execute(['session_hash' => $sessionHash, 'user_id' => $userId]);

        $statement = $connection->prepare(
            "INSERT INTO auth_login_history (user_id, session_hash, event_type, occurred_at)
             VALUES (:user_id, :session_hash, 'login', UTC_TIMESTAMP(6))"
        );
        $statement->execute(['user_id' => $userId, 'session_hash' => $sessionHash]);
        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }

    $_SESSION = [
        'user_id' => $userId,
        'auth_session_hash' => $sessionHash,
        'csrf_token' => bin2hex(random_bytes(32)),
    ];
}

function close_collaborator_session(): void
{
    $userId = $_SESSION['user_id'] ?? null;
    $sessionHash = $_SESSION['auth_session_hash'] ?? null;

    if (is_int($userId) && $userId > 0 && is_string($sessionHash)) {
        $connection = database();
        $connection->beginTransaction();
        try {
            $statement = $connection->prepare(
                "UPDATE auth_sessions
                 SET ended_at = UTC_TIMESTAMP(6), end_reason = 'logout'
                 WHERE session_hash = :session_hash AND user_id = :user_id AND ended_at IS NULL"
            );
            $statement->execute(['session_hash' => $sessionHash, 'user_id' => $userId]);
            if ($statement->rowCount() > 0) {
                $statement = $connection->prepare(
                    "INSERT INTO auth_login_history (user_id, session_hash, event_type, occurred_at)
                     VALUES (:user_id, :session_hash, 'logout', UTC_TIMESTAMP(6))"
                );
                $statement->execute(['user_id' => $userId, 'session_hash' => $sessionHash]);
            }
            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }
    }

    $_SESSION = [];
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $cookie['path'],
        'domain' => $cookie['domain'],
        'secure' => $cookie['secure'],
        'httponly' => $cookie['httponly'],
        'samesite' => $cookie['samesite'] ?? 'Lax',
    ]);
    session_destroy();
}

function revoke_user_sessions(PDO $connection, int $userId): void
{
    $statement = $connection->prepare(
        'SELECT session_hash
         FROM auth_sessions
         WHERE user_id = :user_id AND ended_at IS NULL
         FOR UPDATE'
    );
    $statement->execute(['user_id' => $userId]);
    $sessionHashes = $statement->fetchAll(PDO::FETCH_COLUMN);

    $history = $connection->prepare(
        "INSERT INTO auth_login_history (user_id, session_hash, event_type, occurred_at)
         VALUES (:user_id, :session_hash, 'logout', UTC_TIMESTAMP(6))"
    );
    foreach ($sessionHashes as $sessionHash) {
        $history->execute(['user_id' => $userId, 'session_hash' => $sessionHash]);
    }

    $statement = $connection->prepare(
        "UPDATE auth_sessions
         SET ended_at = UTC_TIMESTAMP(6), end_reason = 'logout'
         WHERE user_id = :user_id AND ended_at IS NULL"
    );
    $statement->execute(['user_id' => $userId]);
}

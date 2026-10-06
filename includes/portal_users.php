<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function portal_store_slug(string $storeName): ?string
{
    if (class_exists(Normalizer::class)) {
        $normalizedName = Normalizer::normalize($storeName, Normalizer::FORM_D);
        if (!is_string($normalizedName)) {
            return null;
        }
        $normalizedName = preg_replace('/\p{Mn}+/u', '', $normalizedName);
    } else {
        $normalizedName = strtr($storeName, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ü' => 'u', 'ñ' => 'n', 'Á' => 'A', 'É' => 'E', 'Í' => 'I',
            'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        ]);
    }
    if (!is_string($normalizedName)) {
        return null;
    }

    $asciiName = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalizedName);
    if (!is_string($asciiName)) {
        return null;
    }

    $slug = strtolower($asciiName);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    if (!is_string($slug)) {
        return null;
    }
    $slug = trim(substr($slug, 0, 50), '-');

    return preg_match('/\A[a-z0-9][a-z0-9-]{2,49}\z/', $slug) === 1 ? $slug : null;
}

function portal_user(): ?array
{
    $userId = filter_var($_SESSION['portal_user_id'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    if (!is_int($userId)) {
        return null;
    }

    try {
        $statement = database()->prepare(
            'SELECT id, name, store_name, nickname, email
             FROM portal_users WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch();
    } catch (PDOException $exception) {
        error_log('No se pudo validar la sesión del usuario del portal: ' . $exception->getMessage());
        http_response_code(500);
        exit('No se pudo validar la sesión del portal. Inténtalo más tarde.');
    }
    if (!$user) {
        unset($_SESSION['portal_user_id']);
        return null;
    }

    return $user;
}

function require_portal_user(): array
{
    $user = portal_user();
    if ($user === null) {
        redirect('');
    }

    return $user;
}

function start_portal_user_session(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['portal_user_id'] = $userId;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function end_portal_user_session(): void
{
    unset($_SESSION['portal_user_id']);
    session_regenerate_id(true);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function portal_page_start(string $title, ?array $user = null): void
{
    $pageTitle = $title;
    require __DIR__ . '/../includes/portal_page_start.php';
}

function portal_page_end(?array $user = null): void
{
    require __DIR__ . '/../includes/portal_page_end.php';
}

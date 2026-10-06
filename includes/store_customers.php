<?php
declare(strict_types=1);

require_once __DIR__ . '/portal_users.php';

function require_storefront_owner(): array
{
    $slug = is_string($_GET['tienda'] ?? null) ? strtolower(trim($_GET['tienda'])) : '';
    if (preg_match('/\A[a-z0-9][a-z0-9-]{2,49}\z/', $slug) !== 1) {
        http_response_code(404);
        exit('No se encontró la microtienda solicitada.');
    }

    try {
        $statement = database()->prepare(
            'SELECT id, store_name, nickname
             FROM portal_users WHERE nickname = :nickname LIMIT 1'
        );
        $statement->execute(['nickname' => $slug]);
        $store = $statement->fetch();
    } catch (PDOException $exception) {
        error_log('No se pudo validar la microtienda del cliente: ' . $exception->getMessage());
        http_response_code(500);
        exit('No se pudo cargar la microtienda. Inténtalo más tarde.');
    }

    if (!$store) {
        http_response_code(404);
        exit('No se encontró la microtienda solicitada.');
    }

    return $store;
}

function storefront_customer(int $storeId): ?array
{
    $customerId = filter_var($_SESSION['store_customer_id'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    $sessionStoreId = filter_var($_SESSION['store_customer_store_id'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    if (!is_int($customerId) || $sessionStoreId !== $storeId) {
        return null;
    }

    try {
        $statement = database()->prepare(
            'SELECT id, portal_user_id, name, email, delivery_address
             FROM store_customers
             WHERE id = :customer_id AND portal_user_id = :store_id LIMIT 1'
        );
        $statement->execute(['customer_id' => $customerId, 'store_id' => $storeId]);
        $customer = $statement->fetch();
    } catch (PDOException $exception) {
        error_log('No se pudo validar la sesión del cliente de microtienda: ' . $exception->getMessage());
        http_response_code(500);
        exit('No se pudo validar la sesión del cliente. Inténtalo más tarde.');
    }

    if (!$customer) {
        unset($_SESSION['store_customer_id'], $_SESSION['store_customer_store_id']);
        return null;
    }

    return $customer;
}

function start_storefront_customer_session(int $customerId, int $storeId): void
{
    session_regenerate_id(true);
    $_SESSION['store_customer_id'] = $customerId;
    $_SESSION['store_customer_store_id'] = $storeId;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function end_storefront_customer_session(): void
{
    unset($_SESSION['store_customer_id'], $_SESSION['store_customer_store_id']);
    session_regenerate_id(true);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function storefront_customer_url(array $store, string $page): string
{
    return app_url($page . '?tienda=' . rawurlencode($store['nickname']));
}

function storefront_customer_page_start(string $title, array $store, ?array $customer = null): void
{
    $pageTitle = $title;
    $storeContext = $store;
    $storeCustomer = $customer;
    require __DIR__ . '/../includes/portal_page_start.php';
}

function storefront_customer_page_end(array $store, ?array $customer = null): void
{
    $storeContext = $store;
    $storeCustomer = $customer;
    require __DIR__ . '/../includes/portal_page_end.php';
}

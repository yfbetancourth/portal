<?php
declare(strict_types=1);

require __DIR__ . '/includes/portal_users.php';
require __DIR__ . '/includes/product_images.php';

$imageId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if (!is_int($imageId)) {
    http_response_code(404);
    exit;
}

try {
    $user = portal_user();
    $statement = database()->prepare(
        'SELECT i.file_name, i.mime_type, p.is_enabled
         FROM product_images i
         INNER JOIN products p ON p.id = i.product_id
         WHERE i.id = :image_id
           AND (p.is_enabled = 1 OR p.portal_user_id = :portal_user_id)
         LIMIT 1'
    );
    $statement->execute([
        'image_id' => $imageId,
        'portal_user_id' => $user === null ? 0 : $user['id'],
    ]);
    $image = $statement->fetch();
} catch (PDOException $exception) {
    error_log('No se pudo cargar una imagen de producto: ' . $exception->getMessage());
    http_response_code(500);
    exit;
}

if (!$image) {
    http_response_code(404);
    exit;
}

$path = product_image_path($image['file_name']);
if ($path === null || !is_file($path) || !is_readable($path)) {
    error_log('No se encontró una imagen registrada para un producto.');
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $image['mime_type']);
header('Content-Length: ' . (string) filesize($path));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline');
header((int) $image['is_enabled'] === 1 ? 'Cache-Control: public, max-age=3600' : 'Cache-Control: private, no-store');
readfile($path);

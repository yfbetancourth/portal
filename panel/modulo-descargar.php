<?php
declare(strict_types=1);

require __DIR__ . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/module_packages.php';
require_collaborator();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit('Método no permitido.');
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!is_int($id)) {
    http_response_code(400);
    exit('El módulo seleccionado no es válido.');
}

try {
    $statement = database()->prepare(
        'SELECT archive_path, archive_original_name, archive_sha256, archive_size
         FROM panel_modules
         WHERE id = :id AND archive_path IS NOT NULL
         LIMIT 1'
    );
    $statement->execute(['id' => $id]);
    $module = $statement->fetch();
} catch (PDOException $exception) {
    error_log('No se pudo cargar el ZIP del módulo: ' . $exception->getMessage());
    http_response_code(500);
    exit('No se pudo preparar la descarga del módulo.');
}

if (!$module) {
    http_response_code(404);
    exit('No se encontró un ZIP asociado a este módulo.');
}

$filePath = module_archive_path($module['archive_path']);
$storageRoot = realpath(module_archive_storage_root());
$resolvedFile = $filePath === null ? false : realpath($filePath);
if ($storageRoot === false || $resolvedFile === false
    || !str_starts_with($resolvedFile, $storageRoot . DIRECTORY_SEPARATOR)
    || !is_file($resolvedFile) || !is_readable($resolvedFile)) {
    error_log('No se encontró el archivo privado asociado al módulo ' . $id . '.');
    http_response_code(404);
    exit('El archivo del módulo no está disponible.');
}

$checksum = hash_file('sha256', $resolvedFile);
if (!is_string($checksum) || !is_string($module['archive_sha256'])
    || !hash_equals($module['archive_sha256'], $checksum)) {
    error_log('La huella del ZIP del módulo ' . $id . ' no coincide con la base de datos.');
    http_response_code(500);
    exit('No se pudo verificar la integridad del archivo del módulo.');
}

$downloadName = is_string($module['archive_original_name']) && $module['archive_original_name'] !== ''
    ? $module['archive_original_name']
    : 'modulo.zip';
$fallbackName = preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName);
header('Content-Type: application/zip');
header('Content-Length: ' . (string) filesize($resolvedFile));
header('Content-Disposition: attachment; filename="' . $fallbackName . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($resolvedFile);

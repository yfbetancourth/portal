<?php
declare(strict_types=1);

const MODULE_ARCHIVE_MAX_BYTES = 20 * 1024 * 1024;
const MODULE_ARCHIVE_STORAGE_MAX_BYTES = 512 * 1024 * 1024;

function module_archive_storage_root(): string
{
    $configured = getenv('PORTAL_MODULE_STORAGE');
    $root = is_string($configured) && $configured !== ''
        ? $configured
        : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'modulos';

    return rtrim($root, DIRECTORY_SEPARATOR);
}

function module_archive_path(string $relativePath): ?string
{
    if (preg_match('/\A[a-z][a-z0-9_-]{1,79}\/[a-f0-9]{32}\.zip\z/', $relativePath) !== 1) {
        return null;
    }

    return module_archive_storage_root() . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
}

function remove_module_archive(string $relativePath, int $moduleId): void
{
    $filePath = module_archive_path($relativePath);
    if ($filePath === null) {
        error_log('La ruta del ZIP del módulo ' . $moduleId . ' no es válida.');
        return;
    }

    if (is_file($filePath) && !unlink($filePath)) {
        error_log('No se pudo eliminar el ZIP privado del módulo ' . $moduleId . '.');
    }
}

function ensure_module_archive_storage(string $moduleKey): string
{
    if (preg_match('/\A[a-z][a-z0-9_-]{1,79}\z/', $moduleKey) !== 1) {
        throw new InvalidArgumentException('La clave del módulo no es válida para almacenamiento.');
    }

    $root = module_archive_storage_root();
    if (!is_dir($root) && !mkdir($root, 0750, true) && !is_dir($root)) {
        throw new RuntimeException('No se pudo crear el almacenamiento privado de módulos.');
    }

    $resolvedRoot = realpath($root);
    $resolvedProject = realpath(dirname(__DIR__));
    if ($resolvedRoot === false || $resolvedProject === false) {
        throw new RuntimeException('No se pudo validar la ruta del almacenamiento de módulos.');
    }
    $publicRoots = [$resolvedProject];
    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
    if (is_string($documentRoot) && $documentRoot !== '') {
        $resolvedDocumentRoot = realpath($documentRoot);
        if ($resolvedDocumentRoot !== false) {
            $publicRoots[] = $resolvedDocumentRoot;
        }
    }
    foreach ($publicRoots as $publicRoot) {
        $comparisonRoot = rtrim(str_replace('\\', '/', $resolvedRoot), '/') . '/';
        $comparisonPublicRoot = rtrim(str_replace('\\', '/', $publicRoot), '/') . '/';
        if (DIRECTORY_SEPARATOR === '\\') {
            $comparisonRoot = strtolower($comparisonRoot);
            $comparisonPublicRoot = strtolower($comparisonPublicRoot);
        }
        if (str_starts_with($comparisonRoot, $comparisonPublicRoot)) {
            throw new RuntimeException('El almacenamiento de módulos debe quedar fuera del directorio público del portal.');
        }
    }

    $moduleDirectory = $resolvedRoot . DIRECTORY_SEPARATOR . $moduleKey;
    if (!is_dir($moduleDirectory) && !mkdir($moduleDirectory, 0750) && !is_dir($moduleDirectory)) {
        throw new RuntimeException('No se pudo crear el directorio privado del módulo.');
    }
    $resolvedModuleDirectory = realpath($moduleDirectory);
    $normalizedRoot = rtrim(str_replace('\\', '/', $resolvedRoot), '/') . '/';
    $normalizedModuleDirectory = $resolvedModuleDirectory === false
        ? ''
        : rtrim(str_replace('\\', '/', $resolvedModuleDirectory), '/') . '/';
    if (DIRECTORY_SEPARATOR === '\\') {
        $normalizedRoot = strtolower($normalizedRoot);
        $normalizedModuleDirectory = strtolower($normalizedModuleDirectory);
    }
    if ($resolvedModuleDirectory === false || !str_starts_with($normalizedModuleDirectory, $normalizedRoot)) {
        throw new RuntimeException('El directorio del módulo sale del almacenamiento privado.');
    }

    return $resolvedModuleDirectory;
}

function save_module_archive(array $upload, string $moduleKey): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('La extensión ZIP de PHP no está disponible.');
    }

    $uploadError = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
    if (!is_int($uploadError) || $uploadError !== UPLOAD_ERR_OK) {
        $message = match ($uploadError) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El ZIP supera el límite permitido por PHP.',
            UPLOAD_ERR_PARTIAL => 'El ZIP se recibió incompleto.',
            UPLOAD_ERR_NO_FILE => 'Selecciona un archivo ZIP.',
            UPLOAD_ERR_NO_TMP_DIR => 'El servidor no tiene una carpeta temporal configurada.',
            UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo escribir el archivo temporal.',
            UPLOAD_ERR_EXTENSION => 'Una extensión de PHP impidió recibir el archivo.',
            default => 'No se pudo recibir el archivo ZIP.',
        };
        throw new InvalidArgumentException($message);
    }

    $temporaryPath = $upload['tmp_name'] ?? null;
    $originalName = $upload['name'] ?? null;
    $fileSize = $upload['size'] ?? null;
    if (!is_string($temporaryPath) || !is_uploaded_file($temporaryPath)
        || !is_string($originalName) || !is_int($fileSize) || $fileSize < 1
        || $fileSize > MODULE_ARCHIVE_MAX_BYTES) {
        throw new InvalidArgumentException('El archivo debe ser un ZIP válido de hasta 20 MiB.');
    }

    $displayName = basename(str_replace('\\', '/', $originalName));
    $displayName = preg_replace('/[\x00-\x1F\x7F]/u', '', $displayName);
    if (!is_string($displayName) || $displayName === ''
        || strtolower(pathinfo($displayName, PATHINFO_EXTENSION)) !== 'zip') {
        throw new InvalidArgumentException('El archivo debe tener la extensión .zip.');
    }

    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo->file($temporaryPath);
    if (!in_array($mimeType, ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'], true)) {
        throw new InvalidArgumentException('El contenido del archivo no parece ser un archivo ZIP.');
    }

    $archive = new ZipArchive();
    $openResult = $archive->open($temporaryPath, ZipArchive::CHECKCONS);
    if ($openResult !== true) {
        throw new InvalidArgumentException('El archivo ZIP está dañado o no es válido.');
    }
    $hasEntries = $archive->numFiles > 0;
    $archive->close();
    if (!$hasEntries) {
        throw new InvalidArgumentException('El archivo ZIP está vacío.');
    }

    $checksum = hash_file('sha256', $temporaryPath);
    if (!is_string($checksum)) {
        throw new RuntimeException('No se pudo calcular la huella del archivo ZIP.');
    }

    $moduleDirectory = ensure_module_archive_storage($moduleKey);
    $storedBytes = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(module_archive_storage_root(), FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $storedFile) {
        if ($storedFile->isFile()) {
            $storedBytes += $storedFile->getSize();
        }
    }
    if ($storedBytes + $fileSize > MODULE_ARCHIVE_STORAGE_MAX_BYTES) {
        throw new InvalidArgumentException('Se alcanzó el límite de almacenamiento de 512 MiB para paquetes ZIP.');
    }

    $storedName = bin2hex(random_bytes(16)) . '.zip';
    $destination = $moduleDirectory . DIRECTORY_SEPARATOR . $storedName;
    if (!move_uploaded_file($temporaryPath, $destination)) {
        throw new RuntimeException('No se pudo guardar el ZIP en el almacenamiento privado.');
    }
    return [
        'path' => $moduleKey . '/' . $storedName,
        'original_name' => mb_strcut($displayName, 0, 255, 'UTF-8'),
        'sha256' => $checksum,
        'size' => $fileSize,
    ];
}

<?php
declare(strict_types=1);

const PORTAL_PRODUCT_IMAGE_MAX_COUNT = 5;
const PORTAL_PRODUCT_IMAGE_MAX_BYTES = 5 * 1024 * 1024;

function product_image_storage_root(): string
{
    $configured = getenv('PORTAL_PRODUCT_IMAGE_STORAGE');
    $root = is_string($configured) && $configured !== ''
        ? $configured
        : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'portal-product-images';

    return rtrim($root, DIRECTORY_SEPARATOR);
}

function ensure_product_image_storage(): string
{
    $root = product_image_storage_root();
    if (!is_dir($root) && !mkdir($root, 0750, true) && !is_dir($root)) {
        throw new RuntimeException('No se pudo crear el almacenamiento privado de imágenes.');
    }

    $resolvedRoot = realpath($root);
    $resolvedProject = realpath(dirname(__DIR__));
    if ($resolvedRoot === false || $resolvedProject === false) {
        throw new RuntimeException('No se pudo validar la ruta de almacenamiento de imágenes.');
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
        $normalizedRoot = rtrim(str_replace('\\', '/', $resolvedRoot), '/') . '/';
        $normalizedPublicRoot = rtrim(str_replace('\\', '/', $publicRoot), '/') . '/';
        if (DIRECTORY_SEPARATOR === '\\') {
            $normalizedRoot = strtolower($normalizedRoot);
            $normalizedPublicRoot = strtolower($normalizedPublicRoot);
        }
        if (str_starts_with($normalizedRoot, $normalizedPublicRoot)) {
            throw new RuntimeException('Las imágenes deben almacenarse fuera del directorio público del portal.');
        }
    }

    return $resolvedRoot;
}

function product_image_path(string $fileName): ?string
{
    if (preg_match('/\A[a-f0-9]{32}\.(?:jpg|png|webp)\z/', $fileName) !== 1) {
        return null;
    }

    return product_image_storage_root() . DIRECTORY_SEPARATOR . $fileName;
}

function receive_product_images(array $files, int $existingCount): array
{
    $names = $files['name'] ?? null;
    $temporaryPaths = $files['tmp_name'] ?? null;
    $errors = $files['error'] ?? null;
    $sizes = $files['size'] ?? null;
    if (!is_array($names) || !is_array($temporaryPaths) || !is_array($errors) || !is_array($sizes)
        || count($names) !== count($temporaryPaths) || count($names) !== count($errors)
        || count($names) !== count($sizes)) {
        throw new InvalidArgumentException('La selección de imágenes no es válida.');
    }

    $selectedIndexes = [];
    foreach ($errors as $index => $uploadError) {
        if ($uploadError !== UPLOAD_ERR_NO_FILE) {
            $selectedIndexes[] = $index;
        }
    }
    $selectedCount = count($selectedIndexes);
    if ($selectedCount === 0) {
        return [];
    }
    if ($existingCount < 0 || $existingCount + $selectedCount > PORTAL_PRODUCT_IMAGE_MAX_COUNT) {
        throw new InvalidArgumentException('Cada producto puede tener un máximo de cinco imágenes.');
    }

    $storageRoot = ensure_product_image_storage();
    $accepted = [];
    try {
        foreach ($selectedIndexes as $index) {
            $originalName = $names[$index] ?? null;
            $uploadError = $errors[$index] ?? UPLOAD_ERR_NO_FILE;
            if (!is_int($uploadError) || $uploadError !== UPLOAD_ERR_OK) {
                $message = match ($uploadError) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Cada imagen debe pesar como máximo 5 MiB.',
                    UPLOAD_ERR_PARTIAL => 'Una imagen se recibió incompleta.',
                    UPLOAD_ERR_NO_FILE => 'Selecciona archivos de imagen válidos.',
                    UPLOAD_ERR_NO_TMP_DIR => 'El servidor no tiene una carpeta temporal configurada.',
                    UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo guardar una imagen temporal.',
                    UPLOAD_ERR_EXTENSION => 'Una extensión de PHP impidió recibir una imagen.',
                    default => 'No se pudo recibir una imagen.',
                };
                throw new InvalidArgumentException($message);
            }

            $temporaryPath = $temporaryPaths[$index] ?? null;
            $fileSize = $sizes[$index] ?? null;
            if (!is_string($temporaryPath) || !is_uploaded_file($temporaryPath)
                || !is_string($originalName) || !is_int($fileSize)
                || $fileSize < 1 || $fileSize > PORTAL_PRODUCT_IMAGE_MAX_BYTES) {
                throw new InvalidArgumentException('Cada imagen debe ser válida y pesar como máximo 5 MiB.');
            }

            $fileInfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $fileInfo->file($temporaryPath);
            $imageInfo = getimagesize($temporaryPath);
            $extensions = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            ];
            if (!is_string($mimeType) || !isset($extensions[$mimeType])
                || !is_array($imageInfo) || ($imageInfo['mime'] ?? null) !== $mimeType
                || !isset($imageInfo[0], $imageInfo[1])
                || $imageInfo[0] < 1 || $imageInfo[1] < 1
                || $imageInfo[0] * $imageInfo[1] > 20000000) {
                throw new InvalidArgumentException('Usa imágenes JPEG, PNG o WebP válidas de hasta 20 megapíxeles.');
            }

            $fileName = bin2hex(random_bytes(16)) . '.' . $extensions[$mimeType];
            $destination = $storageRoot . DIRECTORY_SEPARATOR . $fileName;
            if (!move_uploaded_file($temporaryPath, $destination)) {
                throw new RuntimeException('No se pudo guardar una imagen en el almacenamiento privado.');
            }
            $accepted[] = [
                'file_name' => $fileName,
                'mime_type' => $mimeType,
                'path' => $destination,
            ];
        }
    } catch (Throwable $exception) {
        foreach ($accepted as $image) {
            if (is_file($image['path']) && !unlink($image['path'])) {
                error_log('No se pudo limpiar una imagen temporal tras fallar una carga.');
            }
        }
        throw $exception;
    }

    return $accepted;
}

function remove_product_image_file(string $fileName): void
{
    $path = product_image_path($fileName);
    if ($path === null) {
        error_log('Se encontró una ruta no válida al eliminar una imagen de producto.');
        return;
    }
    if (is_file($path) && !unlink($path)) {
        error_log('No se pudo eliminar una imagen de producto del almacenamiento privado.');
    }
}

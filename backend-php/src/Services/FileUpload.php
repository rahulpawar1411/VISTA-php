<?php
declare(strict_types=1);

namespace App\Services;

use App\Config\Uploads;

/**
 * Disk uploads use the same layout / public_id rename as Node Cloudinary:
 *   Cloudinary:  crm/<folder>/<prefix>-<field>-<ms>-<rand>
 *   Server:      uploads/crm/<folder>/<prefix>-<field>-<ms>-<rand>.jpg
 *   DB path:     uploads/crm/<folder>/<filename>
 */
final class FileUpload
{
    /**
     * Save uploaded file under uploads/crm/<folder>/ and return DB-relative path.
     *
     * @param array{tmp_name: string, name: string, type?: string} $file
     * @param string $folder  e.g. inward_images | outward_images | daily_temp_monitor_images
     * @param string $prefix  e.g. inward | outward | sensor-temp  (Cloudinary filePrefix)
     * @param string|null $fieldName multipart field name (Cloudinary includes this in public_id)
     */
    public static function save(array $file, string $folder, string $prefix = 'file', ?string $fieldName = null): string
    {
        Uploads::ensureCrmFolders();

        $destDir = Uploads::root()
            . DIRECTORY_SEPARATOR . 'crm'
            . DIRECTORY_SEPARATOR . $folder;
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0775, true);
        }

        $field = trim((string) ($fieldName ?: 'photo'));
        $field = preg_replace('/[^a-zA-Z0-9_-]/', '_', $field) ?: 'photo';

        // Node: Date.now() + '-' + Math.round(Math.random() * 1E9)
        $uniqueSuffix = (string) (int) round(microtime(true) * 1000) . '-' . (string) random_int(0, 1_000_000_000);

        $ext = pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION) ?: 'jpg';
        $ext = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $ext) ?: 'jpg');

        // Disk: prefix-field-ms-rand.ext  (same as multer diskStorage / Cloudinary public_id + ext)
        $filename = "{$prefix}-{$field}-{$uniqueSuffix}.{$ext}";
        $dest = $destDir . DIRECTORY_SEPARATOR . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            if (!@copy($file['tmp_name'], $dest)) {
                throw new \RuntimeException('Failed to save uploaded file.');
            }
        }

        return 'uploads/crm/' . $folder . '/' . $filename;
    }
}

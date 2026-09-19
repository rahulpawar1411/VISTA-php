<?php
declare(strict_types=1);

namespace App\Config;

final class Uploads
{
    public const CRM_FOLDERS = [
        'inward_images',
        'outward_images',
        'daily_temp_monitor_images',
    ];

    public static function root(): string
    {
        $configured = Env::get('UPLOADS_DIR');
        if ($configured !== null && $configured !== '') {
            $resolved = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $configured), DIRECTORY_SEPARATOR);
            if (!is_dir($resolved)) {
                @mkdir($resolved, 0775, true);
            }
            if (is_dir($resolved)) {
                return $resolved;
            }
        }

        // Shared-hosting friendly: backend-php/uploads (outside public/)
        $local = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads';
        if (!is_dir($local)) {
            @mkdir($local, 0775, true);
        }
        self::ensureCrmFoldersIn($local);
        return $local;
    }

    /** Ensure uploads/crm/<folder> tree exists (Cloudinary layout). */
    public static function ensureCrmFolders(): void
    {
        self::ensureCrmFoldersIn(self::root());
    }

    private static function ensureCrmFoldersIn(string $root): void
    {
        foreach (self::CRM_FOLDERS as $folder) {
            $dir = $root . DIRECTORY_SEPARATOR . 'crm' . DIRECTORY_SEPARATOR . $folder;
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
    }

    /** Serve a file under uploads root; return true if sent. */
    public static function tryServe(string $requestPath): bool
    {
        if (!str_starts_with($requestPath, '/uploads')) {
            return false;
        }

        $rel = rawurldecode(substr($requestPath, strlen('/uploads')));
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if ($rel === '' || str_contains($rel, '..')) {
            http_response_code(404);
            header('Content-Type: text/plain');
            echo 'Upload not found';
            return true;
        }

        $root = self::root();
        $candidates = [
            $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel),
        ];
        if (str_starts_with($rel, 'crm/')) {
            $candidates[] = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, substr($rel, 4));
        } else {
            $candidates[] = $root . DIRECTORY_SEPARATOR . 'crm' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        }

        $hasExt = pathinfo($rel, PATHINFO_EXTENSION) !== '';
        $extraExts = $hasExt ? [] : ['.jpg', '.jpeg', '.png', '.webp', '.gif'];

        foreach ($candidates as $base) {
            $tries = [$base];
            foreach ($extraExts as $ext) {
                $tries[] = $base . $ext;
            }
            foreach ($tries as $abs) {
                if (is_file($abs)) {
                    $realRoot = realpath($root);
                    $realFile = realpath($abs);
                    if ($realRoot === false || $realFile === false || !str_starts_with($realFile, $realRoot)) {
                        continue;
                    }
                    $mime = mime_content_type($abs) ?: 'application/octet-stream';
                    header('Content-Type: ' . $mime);
                    header('Cross-Origin-Resource-Policy: cross-origin');
                    header('X-Content-Type-Options: nosniff');
                    header('Cache-Control: public, max-age=31536000, immutable');
                    readfile($abs);
                    return true;
                }
            }
        }

        http_response_code(404);
        header('Content-Type: text/plain');
        echo 'Upload not found';
        return true;
    }
}

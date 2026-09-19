<?php
declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $file = __DIR__ . DIRECTORY_SEPARATOR . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

\App\Config\Env::load(dirname(__DIR__));

date_default_timezone_set('Asia/Kolkata');

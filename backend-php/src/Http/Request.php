<?php
declare(strict_types=1);

namespace App\Http;

final class Request
{
    /** @var array<string, mixed>|null */
    public ?array $user = null;

    /** @var array<string, mixed> */
    private array $jsonBody;

    /** @var array<string, string> */
    private array $query;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        array $jsonBody = [],
        array $query = []
    ) {
        $this->jsonBody = $jsonBody;
        $this->query = $query;
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = '/' . trim(str_replace('\\', '/', $path), '/');
        if ($path !== '/') {
            $path = rtrim($path, '/') ?: '/';
        }

        // Shared hosting: app may live in a subdirectory (e.g. /crm/public/index.php)
        $configuredBase = trim((string) (\App\Config\Env::get('APP_BASE_PATH', '') ?? ''), '/');
        if ($configuredBase !== '') {
            $prefix = '/' . $configuredBase;
            if ($path === $prefix) {
                $path = '/';
            } elseif (str_starts_with($path, $prefix . '/')) {
                $path = substr($path, strlen($prefix)) ?: '/';
            }
        } else {
            $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
            $scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
            if ($scriptDir !== '' && $scriptDir !== '/' && $scriptDir !== '.') {
                if ($path === $scriptDir) {
                    $path = '/';
                } elseif (str_starts_with($path, $scriptDir . '/')) {
                    $path = substr($path, strlen($scriptDir)) ?: '/';
                }
            }
        }

        $query = [];
        parse_str((string) (parse_url($uri, PHP_URL_QUERY) ?? ''), $query);

        $raw = file_get_contents('php://input') ?: '';
        $json = [];
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $json = $decoded;
            }
        }
        if (!empty($_POST) && empty($json)) {
            $json = $_POST;
        }

        return new self($method, $path === '' ? '/' : $path, $json, $query);
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return $this->jsonBody;
    }

    /** @param array<string, mixed> $extra */
    public function mergeBody(array $extra): void
    {
        $this->jsonBody = array_merge($this->jsonBody, $extra);
    }

    public function query(string $key, ?string $default = null): ?string
    {
        if (!array_key_exists($key, $this->query)) {
            return $default;
        }
        return (string) $this->query[$key];
    }

    public function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
            return trim($m[1]);
        }
        if (!empty($_COOKIE['token'])) {
            return (string) $_COOKIE['token'];
        }
        return null;
    }
}

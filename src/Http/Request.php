<?php

declare(strict_types=1);

namespace Sso\Http;

use Sso\Support\Str;

/**
 * انتزاعِ درخواست HTTP.
 */
final class Request
{
    /** @var array<string, mixed> */
    private array $body = [];

    private ?array $jsonCache = null;

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $cookies
     */
    public function __construct(
        private array $server = [],
        private array $query = [],
        private array $post = [],
        private array $cookies = [],
        private string $rawBody = ''
    ) {
    }

    /**
     * @param array<string, mixed>|null $server
     */
    public static function fromGlobals(?array $server = null): self
    {
        $server ??= $_SERVER;
        $raw = '';
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'HEAD') {
            $raw = (string) file_get_contents('php://input');
        }
        return new self($server, $_GET, $_POST, $_COOKIE, $raw);
    }

    /**
     * ساخت درخواست مصنوعی (برای CLI و تست‌ها).
     *
     * @param array<string, mixed> $options
     */
    public static function create(
        string $method,
        string $uri,
        array $options = []
    ): self {
        $parts = parse_url($uri);
        $path = (string) ($parts['path'] ?? '/');
        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $query = array_replace($query, (array) ($options['query'] ?? []));

        $headers = [];
        foreach ((array) ($options['headers'] ?? []) as $name => $value) {
            $headers[strtolower(str_replace('_', '-', (string) $name))] = (string) $value;
        }

        $post = (array) ($options['form'] ?? []);
        $raw = (string) ($options['body'] ?? '');
        $json = $options['json'] ?? null;
        if ($json !== null) {
            $raw = json_encode($json, JSON_UNESCAPED_UNICODE);
            $headers['content-type'] = 'application/json';
        }

        $server = array_merge([
            'REQUEST_METHOD' => strtoupper($method),
            'REQUEST_URI' => $uri,
            'SCRIPT_NAME' => (string) ($options['script_name'] ?? '/index.php'),
            'HTTP_HOST' => (string) ($options['host'] ?? 'localhost'),
            'SERVER_PORT' => (string) ($options['port'] ?? 80),
            'HTTPS' => !empty($options['https']) ? 'on' : 'off',
            'REMOTE_ADDR' => (string) ($options['ip'] ?? '127.0.0.1'),
        ], (array) ($options['server'] ?? []));

        foreach ($headers as $name => $value) {
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
            $server[$key] = $value;
        }
        if (isset($headers['content-type'])) {
            $server['CONTENT_TYPE'] = $headers['content-type'];
        }
        if (isset($headers['content-length'])) {
            $server['CONTENT_LENGTH'] = $headers['content-length'];
        }

        return new self($server, $query, $post, (array) ($options['cookies'] ?? []), $raw);
    }

    public function withRoutePath(string $path): self
    {
        $clone = clone $this;
        $clone->server['SSO_ROUTE_PATH'] = $path;
        return $clone;
    }

    public function method(): string
    {
        return strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    public function isMethod(string ...$methods): bool
    {
        return in_array($this->method(), array_map('strtoupper', $methods), true);
    }

    public function uri(): string
    {
        return (string) ($this->server['REQUEST_URI'] ?? '/');
    }

    /**
     * مسیرِ بدون پارامترهای query: /api/v1/users
     */
    public function path(): string
    {
        return (string) (parse_url($this->uri(), PHP_URL_PATH) ?: '/');
    }

    /**
     * مسیر نسبت به پوشه‌ی اسکریپت جاری.
     * اگر rewrite غیرفعال باشد، از ?_route= استفاده می‌شود.
     */
    public function routePath(): string
    {
        if (isset($this->server['SSO_ROUTE_PATH'])) {
            return (string) $this->server['SSO_ROUTE_PATH'];
        }
        $override = $this->query('_route');
        if (is_string($override) && $override !== '') {
            return '/' . ltrim($override, '/');
        }

        $script = (string) ($this->server['SCRIPT_NAME'] ?? '');
        $base = str_replace('\\', '/', dirname($script));
        if ($base === '/' || $base === '.' || $base === '') {
            $base = '';
        }
        $path = $this->path();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        return '/' . ltrim($path, '/');
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $this->server[$key] ?? null;
        return $value === null ? $default : (string) $value;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->server['HTTP_' . strtoupper(str_replace('-', '_', $name))]);
    }

    public function contentType(): string
    {
        return strtolower((string) ($this->server['CONTENT_TYPE'] ?? ''));
    }

    public function isJson(): bool
    {
        return str_contains($this->contentType(), 'json');
    }

    public function wantsJson(): bool
    {
        $accept = strtolower($this->header('Accept', '') ?? '');
        return str_contains($accept, 'application/json') || $this->isJson();
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        if ($this->jsonCache !== null) {
            return $this->jsonCache;
        }
        $raw = trim($this->rawBody);
        if ($raw === '') {
            return $this->jsonCache = [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $this->jsonCache = [];
        }
        return $this->jsonCache = $decoded;
    }

    /**
     * تمام ورودی‌ها (فرم + JSON). فرم اولویت دارد.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->body === []) {
            $this->body = array_replace($this->json(), $this->post);
        }
        return $this->body;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function bearerToken(): ?string
    {
        $authorization = $this->header('Authorization');
        if ($authorization === null) {
            return null;
        }
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($authorization), $m) === 1) {
            return $m[1];
        }
        return null;
    }

    /**
     * پشتیبانی از X-Api-Key و Authorization: Bearer <app-key>
     */
    /**
     * اعتبارنامه‌ی Basic را به صورت [key, secret] برمی‌گرداند.
     *
     * @return array{0: string, 1: string}|null
     */
    public function basicCredentials(): ?array
    {
        $authorization = $this->header('Authorization');
        if ($authorization === null) {
            return null;
        }
        if (preg_match('/^Basic\s+(\S+)$/i', trim($authorization), $m) !== 1) {
            return null;
        }
        $decoded = base64_decode($m[1], true);
        if (!is_string($decoded) || !str_contains($decoded, ':')) {
            return null;
        }
        [$key, $secret] = explode(':', $decoded, 2);
        return [$key, $secret];
    }

    public function appKey(): ?string
    {
        $direct = $this->header('X-Api-Key');
        if ($direct !== null && trim($direct) !== '') {
            return trim($direct);
        }
        $auth = $this->header('Authorization');
        if ($auth !== null && preg_match('/^Key\s+(\S+)$/i', trim($auth), $m) === 1) {
            return $m[1];
        }
        $basic = $this->basicCredentials();
        return $basic === null ? null : $basic[0];
    }

    public function appSecret(): ?string
    {
        $direct = $this->header('X-Api-Secret');
        if ($direct !== null && trim($direct) !== '') {
            return trim($direct);
        }
        $basic = $this->basicCredentials();
        return $basic === null ? null : $basic[1];
    }

    public function ip(): string
    {
        $trusted = [];
        $candidates = [
            $this->server['REMOTE_ADDR'] ?? '0.0.0.0',
        ];

        $forwarded = $this->header('X-Forwarded-For');
        if ($forwarded !== null) {
            foreach (explode(',', $forwarded) as $part) {
                $candidates[] = trim($part);
            }
        }
        $real = $this->header('X-Real-Ip');
        if ($real !== null) {
            $candidates[] = trim($real);
        }

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP)) {
                return Str::ip($candidate);
            }
        }
        return '0.0.0.0';
    }

    public function userAgent(): string
    {
        return Str::userAgent($this->header('User-Agent'));
    }

    public function isSecure(): bool
    {
        $https = strtolower((string) ($this->server['HTTPS'] ?? 'off'));
        if ($https === 'on' || $https === '1') {
            return true;
        }
        return (int) ($this->server['SERVER_PORT'] ?? 80) === 443;
    }

    public function origin(): ?string
    {
        return $this->header('Origin');
    }
}

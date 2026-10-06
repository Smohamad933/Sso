<?php

declare(strict_types=1);

namespace Sso\Http;

/**
 * پاسخ HTTP.
 *
 * در حالت عادی header()/setcookie() صدا زده می‌شوند. در حالت «ضبط»
 * (تست‌ها و اجرا از طریق CLI) هدرها در یک آرایه جمع‌آوری می‌شوند
 * تا هارنس تست بتواند آن‌ها را بررسی کند.
 */
final class Response
{
    private int $status = 200;

    /** @var array<string, array<int, string>> */
    private array $headers = [];

    /** @var array<int, array{name: string, value: string, options: array<string, mixed>}> */
    private array $cookies = [];

    private static bool $capturing = false;

    /** @var array<int, string> */
    private static array $captured = [];

    public function __construct(private string $body = '')
    {
    }

    // ------------------------------------------------------------------ سازنده‌ها

    /**
     * @param array<string, mixed>|null $meta
     */
    public static function json(mixed $data, int $status = 200, ?array $meta = null): self
    {
        $payload = ['ok' => $status >= 200 && $status < 300];
        if ($meta !== null) {
            $payload = array_merge($payload, $meta);
        }
        $payload['data'] = $data;

        $response = new self((string) json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
        ));
        return $response
            ->status($status)
            ->header('Content-Type', 'application/json; charset=utf-8')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    /**
     * @param array<string, mixed> $extra
     */
    public static function apiError(
        string $code,
        string $message,
        int $status = 400,
        ?array $fields = null,
        array $extra = []
    ): self {
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== null && $fields !== []) {
            $error['fields'] = $fields;
        }
        $error = array_merge($error, $extra);

        $response = new self((string) json_encode(
            ['ok' => false, 'error' => $error],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
        return $response
            ->status($status)
            ->header('Content-Type', 'application/json; charset=utf-8')
            ->header('Cache-Control', 'no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public static function html(string $body, int $status = 200): self
    {
        return (new self($body))
            ->status($status)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('X-Frame-Options', 'SAMEORIGIN')
            ->header('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public static function text(string $body, int $status = 200): self
    {
        return (new self($body))
            ->status($status)
            ->header('Content-Type', 'text/plain; charset=utf-8');
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return (new self(''))
            ->status($status)
            ->header('Location', $url)
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public static function noContent(): self
    {
        return (new self(''))->status(204);
    }

    // ------------------------------------------------------------------ تنظیمات

    public function status(int $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function statusCode(): int
    {
        return $this->status;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name][] = $value;
        return $this;
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->header($name, $value);
        }
        return $this;
    }

    /**
     * @param array<string, mixed> $options  expires|path|domain|secure|httponly|samesite
     */
    public function cookie(string $name, string $value, array $options = []): self
    {
        $this->cookies[] = ['name' => $name, 'value' => $value, 'options' => $options];
        return $this;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    // ------------------------------------------------------------------ ارسال

    public function send(): void
    {
        $this->emitHeaders();

        if (!self::$capturing) {
            http_response_code($this->status);
        }

        echo $this->body;
    }

    private function emitHeaders(): void
    {
        foreach ($this->headers as $name => $values) {
            foreach ($values as $value) {
                self::emitRawHeader($name . ': ' . $value);
            }
        }
        foreach ($this->cookies as $cookie) {
            self::emitCookie($cookie['name'], $cookie['value'], $cookie['options']);
        }
        if (self::$capturing) {
            self::$captured[] = 'HTTP/1.1 ' . $this->status;
        }
    }

    public static function emitRawHeader(string $line): void
    {
        if (self::$capturing) {
            self::$captured[] = $line;
            return;
        }
        if (!headers_sent()) {
            header($line);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function emitCookie(string $name, string $value, array $options): void
    {
        $defaults = [
            'expires' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        $options = array_merge($defaults, $options);

        if (self::$capturing) {
            $parts = [rawurlencode($name) . '=' . rawurlencode($value)];
            if (!empty($options['expires'])) {
                $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s', (int) $options['expires']) . ' GMT';
                $parts[] = 'Max-Age=' . max(0, (int) $options['expires'] - time());
            }
            $parts[] = 'Path=' . $options['path'];
            if ($options['domain'] !== '') {
                $parts[] = 'Domain=' . $options['domain'];
            }
            if (!empty($options['secure'])) {
                $parts[] = 'Secure';
            }
            if (!empty($options['httponly'])) {
                $parts[] = 'HttpOnly';
            }
            if ($options['samesite'] !== '') {
                $parts[] = 'SameSite=' . $options['samesite'];
            }
            self::$captured[] = 'Set-Cookie: ' . implode('; ', $parts);
            return;
        }

        if (PHP_VERSION_ID >= 70300) {
            setcookie($name, $value, [
                'expires' => (int) $options['expires'],
                'path' => (string) $options['path'],
                'domain' => (string) $options['domain'],
                'secure' => (bool) $options['secure'],
                'httponly' => (bool) $options['httponly'],
                'samesite' => (string) $options['samesite'],
            ]);
            return;
        }

        setcookie(
            $name,
            $value,
            (int) $options['expires'],
            (string) $options['path'],
            (string) $options['domain'],
            (bool) $options['secure'],
            (bool) $options['httponly']
        );
    }

    // ------------------------------------------------------------------ حالت ضبط

    public static function capture(bool $enabled = true): void
    {
        self::$capturing = $enabled;
        self::$captured = [];
    }

    /**
     * @return array<int, string>
     */
    public static function capturedHeaders(): array
    {
        return self::$captured;
    }
}

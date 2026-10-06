<?php

declare(strict_types=1);

namespace Sso\Http;

/**
 * هدایت به آدرس دیگر.
 */
final class RedirectException extends HttpException
{
    public static function to(string $url, int $status = 302): self
    {
        return new self(Response::redirect($url, $status));
    }
}

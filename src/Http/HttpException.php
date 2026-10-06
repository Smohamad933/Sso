<?php

declare(strict_types=1);

namespace Sso\Http;

/**
 * استثنایی که حامل یک پاسخ HTTP کامل است.
 *
 * به جای این که در میانه‌ی اجرا header() بفرستیم و exit() کنیم،
 * یک استثنا پرتاب می‌کنیم؛ لایه‌ی بالایی (Page::run یا Kernel) پاسخ را
 * به شکل تمیز ارسال می‌کند. این کار تست‌پذیری را هم بسیار بهتر می‌کند.
 */
class HttpException extends \RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('HTTP ' . $response->statusCode());
    }

    public static function forbidden(string $body): self
    {
        return new self(Response::html($body, 403));
    }

    public static function notFound(string $body = 'یافت نشد.'): self
    {
        return new self(Response::html($body, 404));
    }
}

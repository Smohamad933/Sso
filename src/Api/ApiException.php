<?php

declare(strict_types=1);

namespace Sso\Api;

/**
 * خطای قابل تبدیل به پاسخ JSON.
 */
class ApiException extends \RuntimeException
{
    /**
     * @param array<string, mixed>|null $fields
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 400,
        public readonly ?array $fields = null
    ) {
        parent::__construct($message);
    }
}

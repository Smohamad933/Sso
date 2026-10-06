<?php

declare(strict_types=1);

namespace Sso\Api;

/**
 * زمینه‌ی درخواست: چه کسی (اپ / کاربر) این درخواست را می‌زند.
 */
final class Context
{
    /**
     * @param array<string, mixed>|null $app   اپلیکیشنِ احراز‌شده با API Key
     * @param array<string, mixed>|null $user  کاربرِ احراز‌شده با توکن
     * @param array<string, mixed>|null $token رکورد توکنِ کاربر
     */
    public function __construct(
        public ?array $app = null,
        public ?array $user = null,
        public ?array $token = null,
        public string $ip = '',
        public string $userAgent = ''
    ) {
    }

    public function appId(): ?int
    {
        return $this->app === null ? null : (int) $this->app['id'];
    }

    public function userId(): ?int
    {
        return $this->user === null ? null : (int) $this->user['id'];
    }

    public function requireApp(): array
    {
        if ($this->app === null) {
            throw new ApiException('app_credentials_required', 'کلید API معتبر ارسال نشده است.', 401);
        }
        return $this->app;
    }

    public function requireUser(): array
    {
        if ($this->user === null) {
            throw new ApiException('authentication_required', 'توکن دسترسی معتبر ارسال نشده است.', 401);
        }
        return $this->user;
    }

    public function requireToken(): array
    {
        if ($this->token === null) {
            throw new ApiException('authentication_required', 'توکن دسترسی معتبر ارسال نشده است.', 401);
        }
        return $this->token;
    }
}

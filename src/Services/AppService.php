<?php

declare(strict_types=1);

namespace Sso\Services;

use Sso\Data\Database;
use Sso\Models\Application;
use Sso\Support\Str;

/**
 * مدیریت اپلیکیشن‌های متصل و کلیدهای API آن‌ها.
 */
final class AppService
{
    public function __construct(private Database $db)
    {
    }

    public function generateApiKey(): string
    {
        return 'ak_live_' . Str::randomHex(12);
    }

    public function generateApiSecret(): string
    {
        return 'as_live_' . Str::randomHex(24);
    }

    /**
     * @return array{app: array<string, mixed>, api_key: string, api_secret: string}
     */
    public function create(string $name, ?string $slug = null, ?string $description = null): array
    {
        $slug = $this->uniqueSlug($slug !== null && $slug !== '' ? $slug : $name);
        $apiKey = $this->generateApiKey();
        $apiSecret = $this->generateApiSecret();

        $id = Application::create([
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'status' => Application::STATUS_ACTIVE,
            'api_key_prefix' => Str::substr($apiKey, 0, 16),
            'api_key_hash' => Application::hash($apiKey),
            'api_secret_hash' => Application::hash($apiSecret),
            'settings' => [],
        ]);

        /** @var array<string, mixed> $app */
        $app = Application::find($id);

        return [
            'app' => Application::publicArray($app),
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
        ];
    }

    /**
     * تولید کلید/سکرت جدید (کلیدهای قبلی بلافاصله بی‌اعتبار می‌شوند).
     *
     * @return array{api_key: string, api_secret: string}
     */
    public function regenerateKeys(int $appId): array
    {
        $apiKey = $this->generateApiKey();
        $apiSecret = $this->generateApiSecret();

        Application::update($appId, [
            'api_key_prefix' => Str::substr($apiKey, 0, 16),
            'api_key_hash' => Application::hash($apiKey),
            'api_secret_hash' => Application::hash($apiSecret),
        ]);

        return ['api_key' => $apiKey, 'api_secret' => $apiSecret];
    }

    /**
     * فقط سکرت جدید (کلید ثابت می‌ماند).
     */
    public function rotateSecret(int $appId): string
    {
        $apiSecret = $this->generateApiSecret();
        Application::update($appId, ['api_secret_hash' => Application::hash($apiSecret)]);
        return $apiSecret;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function verifyCredentials(string $apiKey, string $apiSecret): ?array
    {
        $app = Application::findByKey($apiKey);
        if ($app === null) {
            return null;
        }
        if (!hash_equals((string) $app['api_secret_hash'], Application::hash($apiSecret))) {
            return null;
        }
        return $app;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function updateSettings(int $appId, array $settings): void
    {
        /** @var array<string, mixed>|null $app */
        $app = Application::find($appId);
        if ($app === null) {
            return;
        }
        $merged = array_replace(Application::settings($app), $settings);
        Application::update($appId, ['settings' => $merged]);
    }

    public function uniqueSlug(string $value): string
    {
        $base = Str::slug($value);
        if ($base === '') {
            $base = 'app';
        }
        $base = Str::substr($base, 0, 100);
        $slug = $base;
        $i = 2;
        while (Application::findBySlug($slug) !== null) {
            $slug = $base . '-' . $i;
            $i++;
        }
        return $slug;
    }
}

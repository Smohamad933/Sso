<?php

declare(strict_types=1);

namespace Sso\Api;

use Sso\Core\App;
use Sso\Http\Request;
use Sso\Http\Response;
use Sso\Models\Application;
use Sso\Models\AuditLog;
use Sso\Models\Token;
use Sso\Models\User;
use Sso\Support\Log;

/**
 * هسته‌ی API: تطبیق مسیر، احراز هویت، محدودسازی نرخ، تبدیل خطا.
 */
final class Kernel
{
    public function __construct(private Router $router)
    {
    }

    public function handle(Request $request): Response
    {
        $context = new Context(ip: $request->ip(), userAgent: $request->userAgent());

        try {
            if ($request->method() === 'OPTIONS') {
                return $this->preflight($request);
            }

            $path = $request->routePath();
            $match = $this->router->match($request->method(), $path);

            if ($match === null) {
                return Response::apiError('not_found', 'مسیر مورد نظر یافت نشد.', 404);
            }
            if ($match->route === null) {
                return Response::apiError(
                    'method_not_allowed',
                    'متد مجاز نیست.',
                    405,
                    null,
                    ['allowed_methods' => $match->allowedMethods]
                )->header('Allow', implode(', ', array_merge($match->allowedMethods, ['OPTIONS'])));
            }

            $auth = (string) ($match->route->meta['auth'] ?? 'public');
            $context = $this->authenticate($request, $auth);

            $response = $this->rateLimit($request, $context);
            if ($response !== null) {
                return $this->withCors($request, $context, $response);
            }

            $handler = $match->route->handler;
            $response = $handler($request, $match->params, $context);

            return $this->withCors($request, $context, $response);
        } catch (ApiException $e) {
            $response = Response::apiError($e->errorCode, $e->getMessage(), $e->httpStatus, $e->fields);
            return $this->withCors($request, $context, $response);
        } catch (\Throwable $e) {
            Log::error('api: ' . $e->getMessage(), [
                'exception' => $e::class,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'path' => $request->path(),
            ]);
            $response = Response::apiError(
                'server_error',
                App::instance()->config('debug', false) ? $e->getMessage() : 'خطای داخلی سرور.',
                500
            );
            return $this->withCors($request, $context, $response);
        }
    }

    // ------------------------------------------------------------------ احراز هویت

    private function authenticate(Request $request, string $mode): Context
    {
        $context = new Context(ip: $request->ip(), userAgent: $request->userAgent());

        if ($mode === 'public') {
            return $context;
        }

        // ۱) اعتبارنامه‌ی اپلیکیشن
        if (in_array($mode, ['app', 'app_or_user', 'optional_app'], true)) {
            $appKey = $request->appKey();
            $appSecret = $request->appSecret();

            if ($appKey !== null && $appSecret !== null) {
                /** @var \Sso\Services\AppService $apps */
                $apps = App::instance()->apps();
                $app = $apps->verifyCredentials($appKey, $appSecret);
                if ($app === null) {
                    throw new ApiException('invalid_app_credentials', 'کلید یا سکرت API نامعتبر است.', 401);
                }
                if ((string) $app['status'] !== Application::STATUS_ACTIVE) {
                    throw new ApiException('app_disabled', 'این اپلیکیشن غیرفعال شده است.', 403);
                }
                $context->app = $app;
            } elseif ($mode === 'app') {
                throw new ApiException(
                    'app_credentials_required',
                    'کلید API الزامی است (هدر X-Api-Key و X-Api-Secret).',
                    401
                );
            }
        }

        // ۲) توکن کاربر
        if (in_array($mode, ['user', 'app_or_user'], true) || $request->bearerToken() !== null) {
            $bearer = $request->bearerToken();
            if ($bearer !== null) {
                $record = Token::findValid($bearer, [Token::TYPE_ACCESS]);
                if ($record === null) {
                    throw new ApiException('invalid_token', 'توکن دسترسی نامعتبر یا منقضی شده است.', 401);
                }
                $user = User::find((int) $record['user_id']);
                if ($user === null || (string) $user['status'] !== User::STATUS_ACTIVE) {
                    throw new ApiException('invalid_token', 'توکن دسترسی نامعتبر یا منقضی شده است.', 401);
                }
                if ($context->app !== null && $record['app_id'] !== null && (int) $record['app_id'] !== (int) $context->app['id']) {
                    throw new ApiException('token_app_mismatch', 'این توکن متعلق به اپلیکیشن دیگری است.', 403);
                }

                Token::touch((int) $record['id']);
                $context->user = $user;
                $context->token = $record;
            } elseif ($mode === 'user') {
                throw new ApiException('authentication_required', 'توکن دسترسی الزامی است (Authorization: Bearer ...).', 401);
            }
        }

        if ($mode === 'app_or_user' && $context->app === null && $context->user === null) {
            throw new ApiException('authentication_required', 'برای این درخواست باید کلید API یا توکن کاربر ارسال شود.', 401);
        }

        return $context;
    }

    // ------------------------------------------------------------------ محدودسازی نرخ

    private function rateLimit(Request $request, Context $context): ?Response
    {
        $limit = (int) App::instance()->config('security.api_rate_limit_per_minute', 600);
        if ($limit <= 0) {
            return null;
        }

        $key = $context->app !== null
            ? 'api:app:' . (int) $context->app['id']
            : 'api:ip:' . $request->ip();

        /** @var \Sso\Services\RateLimiter $limiter */
        $limiter = App::instance()->limiter();
        $hits = $limiter->hit($key, 60);

        if ($hits > $limit) {
            return Response::apiError('rate_limit_exceeded', 'تعداد درخواست‌ها بیش از حد مجاز است.', 429, null, [
                'retry_after' => $limiter->availableIn($key),
            ])
                ->header('Retry-After', (string) max(1, $limiter->availableIn($key)))
                ->header('X-RateLimit-Limit', (string) $limit)
                ->header('X-RateLimit-Remaining', '0');
        }

        // هدرهای اطلاعاتی به پاسخ بعدی اضافه می‌شوند
        $GLOBALS['__sso_rate_headers'] = [
            'X-RateLimit-Limit' => (string) $limit,
            'X-RateLimit-Remaining' => (string) max(0, $limit - $hits),
        ];
        return null;
    }

    // ------------------------------------------------------------------ CORS

    private function preflight(Request $request): Response
    {
        return Response::noContent()
            ->header('Access-Control-Allow-Origin', (string) ($request->origin() ?? '*'))
            ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-Api-Key, X-Api-Secret, X-Requested-With')
            ->header('Access-Control-Max-Age', '86400')
            ->header('Vary', 'Origin');
    }

    private function withCors(Request $request, Context $context, Response $response): Response
    {
        foreach ($GLOBALS['__sso_rate_headers'] ?? [] as $name => $value) {
            $response->header($name, $value);
        }
        unset($GLOBALS['__sso_rate_headers']);

        if ($context->app === null) {
            return $response;
        }

        $origin = $request->origin();
        if ($origin === null) {
            return $response;
        }

        $settings = Application::settings($context->app);
        $allowed = (array) ($settings['allowed_origins'] ?? []);
        foreach ($allowed as $pattern) {
            if ($this->originMatches($origin, (string) $pattern)) {
                return $response
                    ->header('Access-Control-Allow-Origin', $origin)
                    ->header('Vary', 'Origin');
            }
        }
        return $response;
    }

    private function originMatches(string $origin, string $pattern): bool
    {
        $pattern = strtolower(trim($pattern));
        if ($pattern === '*') {
            return true;
        }
        $origin = strtolower(trim($origin));
        if ($pattern === $origin) {
            return true;
        }
        if (str_contains($pattern, '*')) {
            $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#';
            return preg_match($regex, $origin) === 1;
        }
        return false;
    }
}

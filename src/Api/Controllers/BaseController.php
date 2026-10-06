<?php

declare(strict_types=1);

namespace Sso\Api\Controllers;

use Sso\Api\ApiException;
use Sso\Api\Context;
use Sso\Http\Request;
use Sso\Models\Membership;
use Sso\Models\User;
use Sso\Support\Validator;

abstract class BaseController
{
    /**
     * اعتبارسنجی ورودی و برگرداندن فقط فیلدهای تعریف‌شده.
     *
     * @param array<string, string> $rules
     * @return array<string, mixed>
     */
    protected function validated(Request $request, array $rules): array
    {
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            throw new ApiException('validation_error', 'داده‌های ورودی معتبر نیست.', 422, $validator->firstErrors());
        }

        $out = [];
        foreach (array_keys($rules) as $field) {
            if ($request->has($field)) {
                $out[$field] = $request->input($field);
            }
        }
        return $out;
    }

    /**
     * یافتن کاربرِ متعلق به اپلیکیشن جاری (بر اساس id یا uuid).
     *
     * @return array{user: array<string, mixed>, membership: array<string, mixed>}
     */
    protected function userForApp(string $identifier, Context $context): array
    {
        $app = $context->requireApp();

        $identifier = trim($identifier);
        $user = ctype_digit($identifier)
            ? User::find((int) $identifier)
            : User::findByUuid($identifier);

        if ($user === null) {
            throw new ApiException('user_not_found', 'کاربر یافت نشد.', 404);
        }

        $membership = Membership::find((int) $app['id'], (int) $user['id']);
        if ($membership === null) {
            throw new ApiException('user_not_in_app', 'این کاربر عضو این اپلیکیشن نیست.', 404);
        }

        return ['user' => $user, 'membership' => $membership];
    }

    /**
     * نقشِ فعلیِ درخواست‌کننده برای عملیات مدیریتی اپ.
     */
    protected function actorRole(Context $context): string
    {
        // درخواست‌های سرور-به-سرور با کلید اپ در سطح owner عمل می‌کنند
        return 'owner';
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function int(array $data, string $key, int $default = 0): int
    {
        return isset($data[$key]) ? (int) $data[$key] : $default;
    }
}

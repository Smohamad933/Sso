<?php

declare(strict_types=1);

namespace Sso\Support;

/**
 * اعتبارسنجی ساده و صریح برای فرم‌ها و ورودی‌های API.
 */
final class Validator
{
    /** @var array<string, array<int, string>> */
    private array $errors = [];

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $rules  مثال: ['email' => 'required|email|max:190']
     */
    public function __construct(private array $data, private array $rules)
    {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $rules
     */
    public static function make(array $data, array $rules): self
    {
        return new self($data, $rules);
    }

    public function fails(): bool
    {
        $this->run();
        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return !$this->fails();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function errors(): array
    {
        $this->run();
        return $this->errors;
    }

    /**
     * فقط پیام اول هر فیلد.
     *
     * @return array<string, string>
     */
    public function firstErrors(): array
    {
        $out = [];
        foreach ($this->errors() as $field => $messages) {
            $out[$field] = $messages[0] ?? '';
        }
        return $out;
    }

    private bool $ran = false;

    private function run(): void
    {
        if ($this->ran) {
            return;
        }
        $this->ran = true;

        foreach ($this->rules as $field => $ruleString) {
            $value = $this->data[$field] ?? null;
            $present = array_key_exists($field, $this->data) && $value !== null && $value !== '';

            foreach (explode('|', $ruleString) as $rule) {
                if ($rule === '') {
                    continue;
                }
                [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);

                if ($name === 'nullable' && !$present) {
                    break;
                }
                if ($name === 'required') {
                    if (!$present) {
                        $this->add($field, 'این فیلد الزامی است.');
                    }
                    continue;
                }
                if (!$present) {
                    continue;
                }

                switch ($name) {
                    case 'email':
                        if (!is_string($value) || !Str::isEmail($value)) {
                            $this->add($field, 'فرمت ایمیل معتبر نیست.');
                        }
                        break;
                    case 'string':
                        if (!is_string($value) && !is_numeric($value)) {
                            $this->add($field, 'مقدار باید رشته باشد.');
                        }
                        break;
                    case 'min':
                        if (Str::length((string) $value) < (int) $param) {
                            $this->add($field, sprintf('حداقل %d کاراکتر لازم است.', (int) $param));
                        }
                        break;
                    case 'max':
                        if (Str::length((string) $value) > (int) $param) {
                            $this->add($field, sprintf('حداکثر %d کاراکتر مجاز است.', (int) $param));
                        }
                        break;
                    case 'int':
                        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                            $this->add($field, 'مقدار باید عدد صحیح باشد.');
                        }
                        break;
                    case 'bool':
                        if (!in_array($value, [true, false, 0, 1, '0', '1'], true)) {
                            $this->add($field, 'مقدار باید بولین باشد.');
                        }
                        break;
                    case 'array':
                        if (!is_array($value)) {
                            $this->add($field, 'مقدار باید آرایه باشد.');
                        }
                        break;
                    case 'in':
                        $allowed = explode(',', (string) $param);
                        if (!in_array((string) $value, $allowed, true)) {
                            $this->add($field, 'مقدار انتخابی معتبر نیست.');
                        }
                        break;
                    case 'url':
                        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
                            $this->add($field, 'آدرس معتبر نیست.');
                        }
                        break;
                    case 'slug':
                        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $value)) {
                            $this->add($field, 'فقط حروف کوچک انگلیسی، عدد و خط تیره مجاز است.');
                        }
                        break;
                    case 'regex':
                        if (preg_match('/' . str_replace('/', '\/', (string) $param) . '/u', (string) $value) !== 1) {
                            $this->add($field, 'فرمت مقدار معتبر نیست.');
                        }
                        break;
                }
            }
        }
    }

    private function add(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }
}

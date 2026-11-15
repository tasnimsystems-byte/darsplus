<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Rules: required, string, int, email, min:N, max:N (length for strings, value for ints),
 * in:a,b,c, regex:/…/, date, password (school password policy).
 * Field labels are resolved from the given translation prefix: "<prefix>.<field>".
 */
final class Validator
{
    private array $errors = [];
    private array $clean = [];

    public function __construct(private array $data, private array $rules, private string $labelPrefix)
    {
        foreach ($rules as $field => $ruleSet) {
            $this->check($field, is_array($ruleSet) ? $ruleSet : explode('|', $ruleSet));
        }
    }

    public static function make(array $data, array $rules, string $labelPrefix): self
    {
        return new self($data, $rules, $labelPrefix);
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function errors(): array
    {
        return $this->errors;
    }

    /** Only fields that have rules, trimmed; empty optional fields become null. */
    public function validated(): array
    {
        return $this->clean;
    }

    public function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    private function check(string $field, array $rules): void
    {
        $value = $this->data[$field] ?? null;
        $value = is_string($value) ? trim($value) : $value;
        $empty = $value === null || $value === '' || $value === [];

        if ($empty) {
            if (in_array('required', $rules, true)) {
                $this->fail($field, 'required');
            }
            $this->clean[$field] = null;
            return;
        }
        if (!is_scalar($value)) {
            $this->fail($field, 'string');
            return;
        }
        $string = (string) $value;
        $isInt = in_array('int', $rules, true);

        foreach ($rules as $rule) {
            [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
            $ok = match ($name) {
                'required', 'string' => true,
                'int' => preg_match('/^-?\d+$/', $string) === 1,
                'email' => filter_var($string, FILTER_VALIDATE_EMAIL) !== false,
                'min' => $isInt ? (int) $string >= (int) $arg : mb_strlen($string) >= (int) $arg,
                'max' => $isInt ? (int) $string <= (int) $arg : mb_strlen($string) <= (int) $arg,
                'in' => in_array($string, explode(',', (string) $arg), true),
                'regex' => preg_match((string) $arg, $string) === 1,
                'date' => ($d = \DateTime::createFromFormat('Y-m-d', $string)) !== false && $d->format('Y-m-d') === $string,
                'password' => self::passwordIsStrong($string),
                default => throw new \InvalidArgumentException("Unknown validation rule: $name"),
            };
            if (!$ok) {
                $this->fail($field, $name, ['arg' => $arg]);
                return;
            }
        }
        $this->clean[$field] = $isInt ? (int) $string : $string;
    }

    public static function passwordIsStrong(string $password): bool
    {
        return mb_strlen($password) >= (int) App::config('app.password_min_length', 10)
            && preg_match('/\p{L}/u', $password) === 1
            && preg_match('/\d/', $password) === 1;
    }

    private function fail(string $field, string $rule, array $replace = []): void
    {
        $replace['field'] = __($this->labelPrefix . '.' . $field);
        $replace['min'] = App::config('app.password_min_length', 10);
        $this->errors[$field][] = __('validation.' . $rule, $replace);
    }
}

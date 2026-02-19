<?php

declare(strict_types=1);

namespace Fabriq\Http;

/**
 * Simple field validation helper.
 *
 * Validates an associative array against a set of rules.
 * Returns an array of errors (empty = valid).
 *
 * Supported rules: required, string, email, int, min:{n}, max:{n}, in:{a,b,c}
 */
final class Validator
{
    /**
     * Validate data against rules.
     *
     * @param array<string, mixed> $data     Input data
     * @param array<string, string> $rules   Field => pipe-delimited rules (e.g. "required|string|min:3")
     * @return array<string, string>         Field => error message (empty if valid)
     */
    public static function validate(array $data, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $ruleString) {
            $fieldRules = explode('|', $ruleString);
            $value = $data[$field] ?? null;

            foreach ($fieldRules as $rule) {
                $error = self::checkRule($field, $value, $rule);
                if ($error !== null) {
                    $errors[$field] = $error;
                    break; // Stop at first error for this field
                }
            }
        }

        return $errors;
    }

    /**
     * Check a single rule against a value.
     */
    private static function checkRule(string $field, mixed $value, string $rule): ?string
    {
        // Parse rule:param
        $parts = explode(':', $rule, 2);
        $ruleName = $parts[0];
        $param = $parts[1] ?? null;

        return match ($ruleName) {
            'required' => self::ruleRequired($field, $value),
            'string'   => self::ruleString($field, $value),
            'email'    => self::ruleEmail($field, $value),
            'int'      => self::ruleInt($field, $value),
            'min'      => self::ruleMin($field, $value, $param),
            'max'      => self::ruleMax($field, $value, $param),
            'in'       => self::ruleIn($field, $value, $param),
            'uuid'     => self::ruleUuid($field, $value),
            default    => null,
        };
    }

    private static function ruleRequired(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return "{$field} is required";
        }
        return null;
    }

    private static function ruleString(string $field, mixed $value): ?string
    {
        if ($value !== null && !is_string($value)) {
            return "{$field} must be a string";
        }
        return null;
    }

    private static function ruleEmail(string $field, mixed $value): ?string
    {
        if ($value !== null && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return "{$field} must be a valid email address";
        }
        return null;
    }

    private static function ruleInt(string $field, mixed $value): ?string
    {
        if ($value !== null && !is_int($value) && !ctype_digit((string) $value)) {
            return "{$field} must be an integer";
        }
        return null;
    }

    private static function ruleMin(string $field, mixed $value, ?string $param): ?string
    {
        $min = (int) ($param ?? 0);

        if (is_string($value) && mb_strlen($value) < $min) {
            return "{$field} must be at least {$min} characters";
        }
        if (is_int($value) && $value < $min) {
            return "{$field} must be at least {$min}";
        }
        if (is_array($value) && count($value) < $min) {
            return "{$field} must have at least {$min} items";
        }

        return null;
    }

    private static function ruleMax(string $field, mixed $value, ?string $param): ?string
    {
        $max = (int) ($param ?? PHP_INT_MAX);

        if (is_string($value) && mb_strlen($value) > $max) {
            return "{$field} must be at most {$max} characters";
        }
        if (is_int($value) && $value > $max) {
            return "{$field} must be at most {$max}";
        }
        if (is_array($value) && count($value) > $max) {
            return "{$field} must have at most {$max} items";
        }

        return null;
    }

    private static function ruleIn(string $field, mixed $value, ?string $param): ?string
    {
        if ($param === null) {
            return null;
        }

        $allowed = explode(',', $param);
        if ($value !== null && !in_array((string) $value, $allowed, true)) {
            return "{$field} must be one of: {$param}";
        }

        return null;
    }

    private static function ruleUuid(string $field, mixed $value): ?string
    {
        if ($value !== null && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $value)) {
            return "{$field} must be a valid UUID";
        }
        return null;
    }
}


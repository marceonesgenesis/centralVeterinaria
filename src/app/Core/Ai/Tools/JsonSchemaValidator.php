<?php

declare(strict_types=1);

namespace CentralVet\Ai\Tools;

/**
 * Validates tool input against the small JSON Schema subset the tools use.
 * Errors name the field and the rule, never the value (it may carry PII).
 * An unsupported keyword is a programming error (LogicException), so a
 * schema never silently loses a constraint.
 */
final class JsonSchemaValidator
{
    private const SUPPORTED = [
        'type', 'properties', 'required', 'enum', 'minimum', 'maximum',
        'minLength', 'maxLength', 'format', 'description',
    ];

    /**
     * @param array<string, mixed> $schema object schema
     * @param array<string, mixed> $input
     * @return list<string> errors; empty means valid
     */
    public static function validate(array $schema, array $input): array
    {
        self::assertSupported($schema, '$');
        if (($schema['type'] ?? null) !== 'object') {
            throw new \LogicException('Tool input schema must be of type object');
        }

        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? [];
        if (!is_array($properties) || !is_array($required)) {
            throw new \LogicException('Invalid properties/required in tool input schema');
        }
        foreach ($properties as $name => $propertySchema) {
            if (!is_array($propertySchema)) {
                throw new \LogicException('Invalid schema for property ' . $name);
            }
            self::assertSupported($propertySchema, (string) $name);
            if (isset($propertySchema['properties']) || isset($propertySchema['required'])) {
                throw new \LogicException('Nested objects are not supported: ' . $name);
            }
        }

        $errors = [];
        foreach ($required as $name) {
            if (!array_key_exists($name, $input)) {
                $errors[] = $name . ': required';
            }
        }

        foreach ($input as $name => $value) {
            $name = (string) $name;
            if (!array_key_exists($name, $properties)) {
                // The key comes from the model and may carry personal data: never echo it.
                $errors[] = 'unexpected property: not allowed';
                continue;
            }
            $error = self::validateValue($name, $properties[$name], $value);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /** @param array<string, mixed> $schema */
    private static function validateValue(string $name, array $schema, mixed $value): ?string
    {
        $type = $schema['type'] ?? null;
        $typeOk = match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            default => throw new \LogicException('Unsupported type for ' . $name),
        };
        if (!$typeOk) {
            return $name . ': must be ' . $type;
        }

        if (isset($schema['enum'])) {
            if (!is_array($schema['enum']) || !in_array($value, $schema['enum'], true)) {
                return $name . ': not in enum';
            }
        }

        if (is_int($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                return $name . ': below minimum';
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                return $name . ': above maximum';
            }
        }

        if (is_string($value)) {
            $length = mb_strlen($value);
            if (isset($schema['minLength']) && $length < $schema['minLength']) {
                return $name . ': too short';
            }
            if (isset($schema['maxLength']) && $length > $schema['maxLength']) {
                return $name . ': too long';
            }
            if (isset($schema['format'])) {
                if ($schema['format'] !== 'date') {
                    throw new \LogicException('Unsupported format for ' . $name);
                }
                if (!self::isDate($value)) {
                    return $name . ': invalid date';
                }
            }
        }

        return null;
    }

    private static function isDate(string $value): bool
    {
        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** @param array<string, mixed> $schema */
    private static function assertSupported(array $schema, string $path): void
    {
        foreach (array_keys($schema) as $keyword) {
            if (!in_array($keyword, self::SUPPORTED, true)) {
                throw new \LogicException('Unsupported JSON Schema keyword at ' . $path . ': ' . $keyword);
            }
        }
    }
}

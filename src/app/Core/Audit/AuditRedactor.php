<?php

declare(strict_types=1);

namespace CentralVet\Audit;

/**
 * Recursively strips credentials/secrets and common PII fields from audit
 * payloads before they are kept on an AuditEvent or persisted.
 *
 * Deliberately separate from CentralVet\Observability\Logging\JsonLogger's
 * own redaction (technical logs): the two lists overlap on purpose, but
 * audit rows can carry business fields (e.g. `cpf`) that never appear in a
 * technical log context array, so this list is intentionally broader.
 */
final class AuditRedactor
{
    private const SENSITIVE_KEYS = [
        'password', 'senha', 'repassword', 'confirm_password', 'password1', 'password2',
        'token', 'auth_token', 'authorization', 'secret', 'api_key', 'cookie', 'session_id',
        'cpf', 'rg', 'cartao', 'credit_card', 'card_number', 'cvv',
    ];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function redact(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $result[$key] = self::redact($value);
                continue;
            }

            $result[$key] = in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true) ? '*****' : $value;
        }

        return $result;
    }
}

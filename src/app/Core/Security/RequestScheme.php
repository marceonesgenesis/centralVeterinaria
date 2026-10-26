<?php

declare(strict_types=1);

namespace CentralVet\Security;

/**
 * Esquema (http/https) da requisição atual.
 *
 * A fonte é `HTTPS`, que o nginx preenche a partir do proxy confiável da
 * frente. `X-Forwarded-Proto` vem do cliente e só conta quando o operador
 * declara TRUST_FORWARDED_PROTO=true (hospedagem sem o nginx do compose,
 * atrás de um proxy que sobrescreve o cabeçalho).
 */
final class RequestScheme
{
    /**
     * @param array<string, mixed> $server
     */
    public static function isHttps(array $server, bool $trustForwardedProto): bool
    {
        $https = $server['HTTPS'] ?? '';
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        if (!$trustForwardedProto) {
            return false;
        }

        $forwarded = $server['HTTP_X_FORWARDED_PROTO'] ?? '';

        return is_string($forwarded) && strtolower($forwarded) === 'https';
    }

    public static function current(): bool
    {
        return self::isHttps($_SERVER, getenv('TRUST_FORWARDED_PROTO') === 'true');
    }

    /**
     * Liga `session.cookie_secure` em HTTPS; em HTTP mantém o valor atual,
     * para o login do dev (http://localhost:8081) continuar recebendo o cookie.
     */
    public static function secureSessionCookie(bool $https): void
    {
        if ($https) {
            ini_set('session.cookie_secure', '1');
        }
    }
}

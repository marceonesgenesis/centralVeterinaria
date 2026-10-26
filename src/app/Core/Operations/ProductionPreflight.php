<?php

declare(strict_types=1);

namespace CentralVet\Operations;

use PDO;
use Throwable;

/**
 * Preflight de produção e paridade de ambiente (Etapa 4, T-09): confere a
 * configuração pública antes de cada implantação, nos dois alvos (container
 * Docker e CLI da hospedagem compartilhada MySQL 5.7). Recebe o ambiente, o
 * ini, a versão e as extensões do PHP já lidos; devolve um achado por código,
 * sempre na mesma ordem, e nunca expõe valores de variável.
 */
final class ProductionPreflight
{
    /** Valor de APP_SEED no .env.example: nunca serve em produção. */
    private const EXAMPLE_APP_SEED = 'change-this-to-at-least-32-random-characters';

    private const REQUIRED_EXTENSIONS = ['pdo_mysql', 'intl', 'mbstring', 'gd', 'zip', 'bcmath', 'curl', 'openssl'];

    private const BROAD_PROXY_RANGES = ['0.0.0.0/0', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];

    /** @var array<string, string> */
    private readonly array $env;

    /** @var array<string, string> */
    private readonly array $ini;

    /** @var array<string, true> */
    private readonly array $extensions;

    /**
     * @param array<string, mixed> $env
     * @param array<string, mixed> $ini
     * @param list<string> $extensions
     */
    public function __construct(
        array $env,
        array $ini,
        private readonly string $phpVersion,
        array $extensions,
    ) {
        $this->env = array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $env);
        $this->ini = array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $ini);
        $loaded = [];
        foreach ($extensions as $extension) {
            $loaded[strtolower((string) $extension)] = true;
        }
        $this->extensions = $loaded;
    }

    /** @return list<PreflightFinding> */
    public function run(?string $trustedProxiesFile = null, ?PDO $pdo = null): array
    {
        $usesRedis = $this->value('SESSION_DRIVER') === 'redis' || $this->value('RATE_LIMIT_DRIVER') === 'redis';
        $usesS3 = $this->value('STORAGE_DRIVER') === 's3' || $this->value('DOCUMENT_STORAGE_DRIVER') === 's3';
        $seed = $this->value('APP_SEED');
        $dbPassword = $this->value('DB_PASSWORD');
        $smtpFrom = $this->value('SMTP_FROM_ADDRESS');
        $accessKey = $this->value('S3_ACCESS_KEY');
        $secretKey = $this->value('S3_SECRET_KEY');
        $stripeKey = $this->value('STRIPE_SECRET_KEY');

        $findings = [
            $this->check('app_env', $this->value('APP_ENV') !== 'production'),
            $this->check('app_debug', $this->isTrue('APP_DEBUG')),
            $this->check('display_errors', $this->displayErrorsOn()),
            $this->check('public_url_https', !str_starts_with($this->value('APP_PUBLIC_URL'), 'https://')),
            $this->check('app_seed', strlen($seed) < 32 || $seed === self::EXAMPLE_APP_SEED),
            $this->check('db_password', $dbPassword === '' || str_starts_with($dbPassword, 'change-this')),
            $this->check('redis_password', false, $usesRedis && $this->value('REDIS_PASSWORD') === ''),
            $this->check('smtp_driver', $this->value('COMMUNICATION_EMAIL_DRIVER') !== 'smtp'),
            $this->check('smtp_from', $smtpFrom === '' || str_ends_with(strtolower($smtpFrom), '.invalid')),
            $this->check('operator_email', $this->value('BILLING_OPERATOR_EMAIL') === ''),
            $this->check('storage_credentials', $usesS3 && (
                $accessKey === '' || $accessKey === 'minioadmin' || $secretKey === '' || $secretKey === 'minioadmin123'
            )),
            $this->check(
                'stripe_secret_key',
                $stripeKey === '',
                !str_starts_with($stripeKey, 'sk_' . 'live_') && !str_starts_with($stripeKey, 'rk_' . 'live_'),
            ),
            $this->check('stripe_webhook_secret', $this->value('STRIPE_WEBHOOK_SECRET') === ''),
            $this->check('trust_forwarded_proto', false, $this->isTrue('TRUST_FORWARDED_PROTO')),
            $this->check('ai_provider', $this->value('AI_PROVIDER') === 'stub'),
            $this->check('php_version', !str_starts_with($this->phpVersion, '8.4.')),
            $this->check('php_extensions', $this->missingExtensions($usesRedis)),
        ];

        if ($trustedProxiesFile !== null) {
            $findings[] = $this->check('trusted_proxies', $this->trustedProxiesTooBroad($trustedProxiesFile));
        }

        if ($pdo !== null) {
            $findings[] = $this->databaseVersion($pdo);
            $findings[] = $this->check('migrations_pending', $this->migrationsPending($pdo));
        }

        return $findings;
    }

    private function check(string $code, bool $fail, bool $warn = false): PreflightFinding
    {
        $level = $fail ? PreflightFinding::FAIL : ($warn ? PreflightFinding::WARN : PreflightFinding::PASS);

        return new PreflightFinding($level, $code);
    }

    private function value(string $name): string
    {
        return trim($this->env[$name] ?? '');
    }

    private function isTrue(string $name): bool
    {
        return strtolower($this->value($name)) === 'true';
    }

    private function displayErrorsOn(): bool
    {
        $value = strtolower(trim($this->ini['display_errors'] ?? ''));

        return in_array($value, ['1', 'on', 'true', 'yes', 'stdout', 'stderr'], true);
    }

    private function missingExtensions(bool $usesRedis): bool
    {
        $required = self::REQUIRED_EXTENSIONS;
        if ($usesRedis) {
            $required[] = 'redis';
        }
        foreach ($required as $extension) {
            if (!isset($this->extensions[$extension])) {
                return true;
            }
        }

        return false;
    }

    private function trustedProxiesTooBroad(string $file): bool
    {
        if (!is_file($file) || !is_readable($file)) {
            return true;
        }
        $content = @file_get_contents($file);
        if ($content === false) {
            return true;
        }

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim(explode('#', $line, 2)[0]);
            if (preg_match('/^set_real_ip_from\s+([^;\s]+)\s*;/', $line, $match) === 1
                && in_array($match[1], self::BROAD_PROXY_RANGES, true)) {
                return true;
            }
        }

        return false;
    }

    private function databaseVersion(PDO $pdo): PreflightFinding
    {
        try {
            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        } catch (Throwable) {
            $version = '';
        }

        if (str_starts_with($version, '8.0.')) {
            return $this->check('db_version', false);
        }
        if (str_starts_with($version, '5.7.')) {
            return $this->check('db_version', false, !$this->isTrue('DB_STRICT_MODE'));
        }

        return $this->check('db_version', true);
    }

    private function migrationsPending(PDO $pdo): bool
    {
        try {
            $report = (new MigrationStatus($pdo, dirname(__DIR__, 2) . '/database/migrations'))->report();
        } catch (Throwable) {
            return true;
        }

        return $report['pending'] !== [] || $report['unknown'] !== [];
    }
}

<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Operations\PreflightFinding;
use CentralVet\Operations\ProductionPreflight;
use CentralVet\Tests\Support\Assert;
use PDO;
use PDOStatement;

/**
 * Etapa 4, T-09: preflight de produção. Um ambiente de produção fictício
 * completo passa sem FAIL e cada regra isolada reprova com o próprio código;
 * o CLI nunca imprime valores. Nenhum valor real: prefixos do Stripe montados
 * por concatenação.
 */
final class ProductionPreflightTest
{
    private const EXTENSIONS = ['Core', 'pdo_mysql', 'intl', 'mbstring', 'gd', 'zip', 'bcmath', 'curl', 'openssl', 'redis'];

    /** @var list<string> */
    private array $tempFiles = [];

    public function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];
    }

    public function testCompleteProductionEnvironmentHasNoFail(): void
    {
        $findings = $this->preflight()->run();

        $codes = array_map(static fn (PreflightFinding $f): string => $f->code, $findings);
        Assert::same([
            'app_env', 'app_debug', 'display_errors', 'public_url_https', 'app_seed', 'db_password',
            'redis_password', 'smtp_driver', 'smtp_from', 'operator_email', 'support_email', 'storage_credentials',
            'stripe_secret_key', 'stripe_webhook_secret', 'trust_forwarded_proto', 'ai_provider',
            'php_version', 'php_extensions',
        ], $codes);
        foreach ($findings as $finding) {
            Assert::same(PreflightFinding::PASS, $finding->level, 'finding ' . $finding->code);
        }
    }

    public function testAppDebugTrueFails(): void
    {
        Assert::same(PreflightFinding::FAIL, $this->level($this->preflight(['APP_DEBUG' => 'true'])->run(), 'app_debug'));
    }

    public function testInvalidSmtpSenderFails(): void
    {
        $findings = $this->preflight(['SMTP_FROM_ADDRESS' => 'no-reply@example.invalid'])->run();

        Assert::same(PreflightFinding::FAIL, $this->level($findings, 'smtp_from'));
    }

    public function testMissingSupportEmailFails(): void
    {
        $env = $this->productionEnv();
        unset($env['SUPPORT_EMAIL']);
        $findings = (new ProductionPreflight($env, ['display_errors' => '0'], '8.4.13', self::EXTENSIONS))->run();

        Assert::same(PreflightFinding::FAIL, $this->level($findings, 'support_email'));
    }

    public function testInvalidSupportEmailFails(): void
    {
        Assert::same(PreflightFinding::FAIL, $this->level($this->preflight(['SUPPORT_EMAIL' => 'suporte'])->run(), 'support_email'));
        Assert::same(
            PreflightFinding::FAIL,
            $this->level($this->preflight(['SUPPORT_EMAIL' => 'suporte@example.invalid'])->run(), 'support_email'),
        );
    }

    public function testValidSupportEmailPasses(): void
    {
        $findings = $this->preflight(['SUPPORT_EMAIL' => 'suporte@example.com'])->run();

        Assert::same(PreflightFinding::PASS, $this->level($findings, 'support_email'));
    }

    public function testMinioAccessKeyFails(): void
    {
        $findings = $this->preflight(['S3_ACCESS_KEY' => 'minioadmin'])->run();

        Assert::same(PreflightFinding::FAIL, $this->level($findings, 'storage_credentials'));
    }

    public function testPhpOutsideEightFourFails(): void
    {
        $findings = $this->preflight([], '8.3.0')->run();

        Assert::same(PreflightFinding::FAIL, $this->level($findings, 'php_version'));
    }

    public function testMissingIntlExtensionFails(): void
    {
        $extensions = array_values(array_diff(self::EXTENSIONS, ['intl']));
        $findings = $this->preflight([], '8.4.13', $extensions)->run();

        Assert::same(PreflightFinding::FAIL, $this->level($findings, 'php_extensions'));
    }

    public function testBroadTrustedProxyRangeFails(): void
    {
        $file = $this->tempFile("set_real_ip_from 203.0.113.10;\nset_real_ip_from 10.0.0.0/8;\n");

        Assert::same(PreflightFinding::FAIL, $this->level($this->preflight()->run($file), 'trusted_proxies'));
    }

    public function testCommentedBroadTrustedProxyRangePasses(): void
    {
        $file = $this->tempFile("set_real_ip_from 203.0.113.10;\n# set_real_ip_from 10.0.0.0/8;\n");

        Assert::same(PreflightFinding::PASS, $this->level($this->preflight()->run($file), 'trusted_proxies'));
    }

    public function testUnreadableTrustedProxiesFileFails(): void
    {
        $missing = sys_get_temp_dir() . '/cv-preflight-missing-' . bin2hex(random_bytes(6)) . '.conf';

        Assert::same(PreflightFinding::FAIL, $this->level($this->preflight()->run($missing), 'trusted_proxies'));
    }

    public function testUnsupportedMysqlVersionFails(): void
    {
        $findings = $this->preflight()->run(null, new PreflightVersionPdo('5.6.51'));

        Assert::same(PreflightFinding::FAIL, $this->level($findings, 'db_version'));
    }

    public function testMysql57WithStrictModePasses(): void
    {
        $findings = $this->preflight(['DB_STRICT_MODE' => 'true'])->run(null, new PreflightVersionPdo('5.7.44'));

        Assert::same(PreflightFinding::PASS, $this->level($findings, 'db_version'));
        $last = array_slice($findings, -2);
        Assert::same(['db_version', 'migrations_pending'], [$last[0]->code, $last[1]->code]);
    }

    public function testMysql57WithoutStrictModeWarns(): void
    {
        $findings = $this->preflight()->run(null, new PreflightVersionPdo('5.7.44'));

        Assert::same(PreflightFinding::WARN, $this->level($findings, 'db_version'));
    }

    public function testWithoutPdoThereAreNoDatabaseFindings(): void
    {
        $findings = $this->preflight()->run();

        Assert::null($this->level($findings, 'db_version'));
        Assert::null($this->level($findings, 'migrations_pending'));
    }

    public function testCliNeverPrintsSecretValues(): void
    {
        $seed = 'zz-preflight-seed-' . bin2hex(random_bytes(12));
        $stripe = 'sk_' . 'live_' . 'zzpreflight' . bin2hex(random_bytes(8));
        $script = dirname(__DIR__, 2) . '/bin/preflight.php';
        $env = ['APP_SEED' => $seed, 'STRIPE_SECRET_KEY' => $stripe, 'PATH' => (string) getenv('PATH')];

        $process = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        Assert::true(is_resource($process), 'proc_open must start php');
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        Assert::false(str_contains($output, $seed), 'APP_SEED value must never be printed');
        Assert::false(str_contains($output, $stripe), 'STRIPE_SECRET_KEY value must never be printed');
        Assert::true((bool) preg_match('/^preflight fail=\d+ warn=\d+ pass=\d+$/', $this->lastLine($output)), 'last line: ' . $output);
        Assert::same(1, $code, 'APP_ENV is missing, so the CLI must exit 1');
    }

    /**
     * @param array<string, string> $overrides
     * @param list<string>|null $extensions
     */
    private function preflight(array $overrides = [], string $phpVersion = '8.4.13', ?array $extensions = null): ProductionPreflight
    {
        $env = array_merge($this->productionEnv(), $overrides);

        return new ProductionPreflight($env, ['display_errors' => '0'], $phpVersion, $extensions ?? self::EXTENSIONS);
    }

    /** @return array<string, string> ambiente de produção fictício completo */
    private function productionEnv(): array
    {
        return [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_PUBLIC_URL' => 'https://app.example.com',
            'APP_SEED' => 'zz-preflight-production-seed-0123456789abcdef',
            'DB_PASSWORD' => 'zz-preflight-db-password',
            'DB_STRICT_MODE' => 'false',
            'REDIS_PASSWORD' => 'zz-preflight-redis-password',
            'SESSION_DRIVER' => 'redis',
            'RATE_LIMIT_DRIVER' => 'redis',
            'COMMUNICATION_EMAIL_DRIVER' => 'smtp',
            'SMTP_FROM_ADDRESS' => 'no-reply@example.com',
            'BILLING_OPERATOR_EMAIL' => 'operador@example.com',
            'SUPPORT_EMAIL' => 'suporte@example.com',
            'STORAGE_DRIVER' => 's3',
            'DOCUMENT_STORAGE_DRIVER' => 'local',
            'S3_ACCESS_KEY' => 'zz-preflight-access',
            'S3_SECRET_KEY' => 'zz-preflight-secret',
            'STRIPE_SECRET_KEY' => 'sk_' . 'live_' . 'zzpreflighttest',
            'STRIPE_WEBHOOK_SECRET' => 'whsec_test_etapa4',
            'TRUST_FORWARDED_PROTO' => 'false',
            'AI_PROVIDER' => 'anthropic',
        ];
    }

    /** @param list<PreflightFinding> $findings */
    private function level(array $findings, string $code): ?string
    {
        foreach ($findings as $finding) {
            if ($finding->code === $code) {
                return $finding->level;
            }
        }

        return null;
    }

    private function tempFile(string $content): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'cv-preflight-');
        file_put_contents($file, $content);
        $this->tempFiles[] = $file;

        return $file;
    }

    private function lastLine(string $output): string
    {
        $lines = preg_split('/\R/', trim($output)) ?: [''];

        return (string) end($lines);
    }
}

/**
 * Dublê de PDO para o preflight: `SELECT VERSION()` devolve a versão
 * informada, `SELECT DATABASE()` um nome fictício e `schema_migrations`
 * todas as migrations do repositório (nenhuma pendente).
 */
final class PreflightVersionPdo extends PDO
{
    public function __construct(private readonly string $version)
    {
        // Sem conexão real.
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (stripos($query, 'VERSION()') !== false) {
            return new PreflightFixedStatement([$this->version]);
        }
        if (stripos($query, 'DATABASE()') !== false) {
            return new PreflightFixedStatement(['centralvet_preflight']);
        }

        $versions = [];
        foreach (glob(dirname(__DIR__, 2) . '/app/database/migrations/2*_[0-9][0-9][0-9][0-9]_*.sql') ?: [] as $file) {
            if (!str_ends_with($file, '.verify.sql')) {
                $versions[] = substr(basename($file), 0, -4);
            }
        }

        return new PreflightFixedStatement($versions);
    }
}

final class PreflightFixedStatement extends PDOStatement
{
    /** @param list<string> $values */
    public function __construct(private readonly array $values)
    {
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->values[0] ?? false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->values;
    }
}

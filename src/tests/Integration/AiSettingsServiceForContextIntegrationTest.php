<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Application\AiSettingsService;
use CentralVet\Authorization\Exception\AuthorizationDenied;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;
use CentralVet\Tests\Support\SkippedTestException;
use CentralVet\Tests\Support\TestDatabase;

/**
 * AiSettingsService::forContext() (T-10, 8A pendências) wires the PDO
 * repositories, the RBAC policy on the Adianti session and the audit writer
 * on a real connection. Without an Adianti session the policy fails closed;
 * with the `AiSettingsForm` program in the session (subprocess with
 * init.php, own connection in a transaction that is always rolled back)
 * overview() reads the tenant settings and month usage from MySQL.
 */
final class AiSettingsServiceForContextIntegrationTest extends MysqlIntegrationTestCase
{
    private const MARKER = '__T10_FOR_CONTEXT__';

    public function testForContextWiresTheServiceAndFailsClosedWithoutAdiantiSession(): void
    {
        $service = AiSettingsService::forContext(self::context(), $this->pdo);

        Assert::instanceOf(AiSettingsService::class, $service);
        Assert::throws(AuthorizationDenied::class, fn () => $service->overview());
    }

    public function testForContextOverviewReadsTheTenantSettingsWithTheSettingsProgram(): void
    {
        $out = $this->runAdianti(
            '$overview = CentralVet\Application\AiSettingsService::forContext($context, $pdo)->overview();'
            . '$out = ["service" => true, "terms_current" => $overview["terms_current"],'
            . ' "monthly_token_quota" => $overview["monthly_token_quota"], "month_tokens" => $overview["month_tokens"]];',
        );

        Assert::true($out['service'] ?? false, 'subprocess output: ' . json_encode($out));
        Assert::true(is_bool($out['terms_current']), 'terms_current must be a bool');
        Assert::true(is_int($out['monthly_token_quota']));
        Assert::true(is_int($out['month_tokens']));
    }

    /**
     * Runs $body after init.php with the `AiSettingsForm` program in the
     * session; `$context` and `$pdo` (test database, open transaction) are in
     * scope and the transaction is always rolled back. Returns `$out`.
     *
     * @return array<string, mixed>
     */
    private function runAdianti(string $body): array
    {
        $src = dirname(__DIR__, 2);

        if (!is_file($src . '/init.php')) {
            throw new SkippedTestException('init.php not found');
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST') ?: '127.0.0.1',
            getenv('DB_PORT') ?: '3306',
            TestDatabase::resolveName(getenv()),
        );

        $code = 'chdir(' . var_export($src, true) . '); require "init.php";'
            . 'TSession::setValue("programs", ["AiSettingsForm" => true]);'
            . '$out = [];'
            . '$pdo = new PDO(' . var_export($dsn, true) . ', (string) (getenv("DB_USERNAME") ?: "centralvet"),'
            . ' (string) (getenv("DB_PASSWORD") ?: ""), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);'
            . '$pdo->beginTransaction();'
            . '$context = CentralVet\Tenancy\TenantContext::authenticated(1, 1, 1);'
            . 'ob_start();'
            . 'try { ' . $body . ' } catch (Throwable $e) { $out["error"] = get_class($e) . ": " . $e->getMessage(); }'
            . ' finally { $pdo->rollBack(); }'
            . 'ob_end_clean();'
            . 'echo "' . self::MARKER . '", json_encode($out);';

        $raw = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>/dev/null');
        $pos = strrpos($raw, self::MARKER);
        $decoded = $pos === false ? null : json_decode(substr($raw, $pos + strlen(self::MARKER)), true);

        Assert::true(is_array($decoded), 'AiSettingsService subprocess output: ' . substr($raw, -500));

        return $decoded;
    }

    private static function context(): TenantContext
    {
        return TenantContext::authenticated(1, 1, 1);
    }
}

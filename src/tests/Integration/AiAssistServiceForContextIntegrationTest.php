<?php

declare(strict_types=1);

namespace CentralVet\Tests\Integration;

use CentralVet\Application\AiAssistService;
use CentralVet\Domain\AiPurpose;
use CentralVet\Domain\Exception\AiUnavailableException;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\AssertionFailedException;
use CentralVet\Tests\Support\MysqlIntegrationTestCase;

/**
 * AiAssistService::forContext() wires the PDO repositories and the gateway of
 * the environment configuration on a real connection; an invalid AI
 * environment (stub provider in production) fails as `ai_not_configured`.
 * The AI environment variables are restored in tearDown().
 */
final class AiAssistServiceForContextIntegrationTest extends MysqlIntegrationTestCase
{
    private const ENV = ['AI_PROVIDER', 'APP_ENV'];

    /** @var array<string, string|false> */
    private array $saved = [];

    public function setUp(): void
    {
        parent::setUp();

        foreach (self::ENV as $name) {
            $this->saved[$name] = getenv($name);
        }
    }

    public function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }

        parent::tearDown();
    }

    public function testForContextWiresTheServiceWithTheStubOutsideProduction(): void
    {
        putenv('AI_PROVIDER=stub');
        putenv('APP_ENV=testing');

        $service = AiAssistService::forContext(self::context(), $this->pdo, 'pt');

        Assert::instanceOf(AiAssistService::class, $service);
        Assert::null($service->latestDecided(AiPurpose::PATIENT_SUMMARY, 2147483000));
    }

    public function testForContextRejectsTheStubInProductionAsNotConfigured(): void
    {
        putenv('AI_PROVIDER=stub');
        putenv('APP_ENV=production');

        try {
            AiAssistService::forContext(self::context(), $this->pdo, 'pt');
        } catch (AiUnavailableException $e) {
            Assert::same('ai_not_configured', $e->reason());

            return;
        }

        throw new AssertionFailedException('Expected AiUnavailableException ai_not_configured');
    }

    private static function context(): TenantContext
    {
        return TenantContext::authenticated(1, 1, 1);
    }
}

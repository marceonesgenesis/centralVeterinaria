<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Observability\ErrorTracking\ErrorTrackerFactory;
use CentralVet\Observability\ErrorTracking\LogErrorTracker;
use CentralVet\Observability\ErrorTracking\NullErrorTracker;
use CentralVet\Observability\Logging\JsonLogger;
use CentralVet\Observability\Metrics\LogMetrics;
use CentralVet\Observability\Metrics\MetricsFactory;
use CentralVet\Observability\Metrics\NullMetrics;
use CentralVet\Tests\Support\Assert;

final class ObservabilityTest
{
    /** @var list<string> */
    private array $tempFiles = [];

    public function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->tempFiles = [];

        // Restore a clean environment for the next test, regardless of what
        // this one set.
        putenv('METRICS_DRIVER');
        putenv('ERROR_TRACKING_DRIVER');
    }

    public function testJsonLoggerWritesOneStructuredJsonLinePerEvent(): void
    {
        $logger = new JsonLogger('centralvet-test', 'testing', $this->tempFile(), 'debug');

        $logger->info('user.login', ['user_id' => 42]);

        $line = trim($this->readTempFileContents());
        $decoded = json_decode($line, true);

        Assert::true(is_array($decoded), 'Log line must be valid JSON');
        Assert::same('info', $decoded['level']);
        Assert::same('centralvet-test', $decoded['service']);
        Assert::same('user.login', $decoded['message']);
        Assert::same(42, $decoded['context']['user_id']);
    }

    public function testJsonLoggerRedactsSensitiveContextKeys(): void
    {
        $logger = new JsonLogger('centralvet-test', 'testing', $this->tempFile(), 'debug');

        $logger->warning('login.failed', ['username' => 'vet1', 'password' => 'p4ss', 'token' => 'abc']);

        $decoded = json_decode(trim($this->readTempFileContents()), true);

        Assert::same('vet1', $decoded['context']['username']);
        Assert::same('*****', $decoded['context']['password']);
        Assert::same('*****', $decoded['context']['token']);
    }

    public function testJsonLoggerSuppressesEventsBelowMinimumLevel(): void
    {
        $logger = new JsonLogger('centralvet-test', 'testing', $this->tempFile(), 'warning');

        $logger->debug('too-quiet-to-log');
        $logger->info('still-too-quiet');
        $logger->warning('this-one-passes');

        $contents = $this->readTempFileContents();

        Assert::false(str_contains($contents, 'too-quiet-to-log'));
        Assert::false(str_contains($contents, 'still-too-quiet'));
        Assert::stringContains('this-one-passes', $contents);
    }

    public function testMetricsFactoryDefaultsToNullDriver(): void
    {
        putenv('METRICS_DRIVER');

        $logger = new JsonLogger('centralvet-test', 'testing', $this->tempFile());
        $metrics = MetricsFactory::fromEnvironment($logger);

        Assert::instanceOf(NullMetrics::class, $metrics);
    }

    public function testMetricsFactoryBuildsLogDriverWhenConfigured(): void
    {
        putenv('METRICS_DRIVER=log');

        $file = $this->tempFile();
        $logger = new JsonLogger('centralvet-test', 'testing', $file);
        $metrics = MetricsFactory::fromEnvironment($logger);

        Assert::instanceOf(LogMetrics::class, $metrics);

        $metrics->increment('queue.processed');

        Assert::stringContains('metric.increment', $this->readTempFileContents());
    }

    public function testErrorTrackerFactoryDefaultsToNullDriver(): void
    {
        putenv('ERROR_TRACKING_DRIVER');

        $logger = new JsonLogger('centralvet-test', 'testing', $this->tempFile());
        $tracker = ErrorTrackerFactory::fromEnvironment($logger);

        Assert::instanceOf(NullErrorTracker::class, $tracker);
    }

    public function testErrorTrackerFactoryBuildsLogDriverWhenConfiguredAndCapturesExceptions(): void
    {
        putenv('ERROR_TRACKING_DRIVER=log');

        $file = $this->tempFile();
        $logger = new JsonLogger('centralvet-test', 'testing', $file, 'debug');
        $tracker = ErrorTrackerFactory::fromEnvironment($logger);

        Assert::instanceOf(LogErrorTracker::class, $tracker);

        $tracker->captureException(new \RuntimeException('boom'));

        $decoded = json_decode(trim($this->readTempFileContents()), true);
        Assert::same('critical', $decoded['level']);
        Assert::same('exception.captured', $decoded['message']);
        Assert::same('boom', $decoded['context']['exception_message']);
    }

    private function tempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cvlog');

        if ($path === false) {
            throw new \RuntimeException('Unable to create temp file for JsonLogger test');
        }

        $this->tempFiles[] = $path;

        return $path;
    }

    private function readTempFileContents(): string
    {
        $path = end($this->tempFiles);

        return $path === false ? '' : (string) file_get_contents($path);
    }
}

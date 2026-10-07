<?php
declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Security\FileRateLimitBackend;
use CentralVet\Security\LoginRateLimiter;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\SkippedTestException;

final class FileRateLimitBackendTest
{
    private string $directory;

    public function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/cv-rate-test-' . bin2hex(random_bytes(12));
    }

    public function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testLimitPersistsAcrossInstancesAndClearReleasesIt(): void
    {
        $first = new LoginRateLimiter(new FileRateLimitBackend($this->directory, 900), 2, 900);
        $second = new LoginRateLimiter(new FileRateLimitBackend($this->directory, 900), 2, 900);
        Assert::false($first->tooManyAttempts('admin|127.0.0.1'));
        Assert::same(1, $first->hit('admin|127.0.0.1'));
        Assert::same(2, $second->hit('admin|127.0.0.1'));
        Assert::true($first->tooManyAttempts('admin|127.0.0.1'));
        Assert::true($second->secondsUntilAvailable('admin|127.0.0.1') > 0);
        Assert::false($second->tooManyAttempts('another-user|127.0.0.1'));
        $second->clear('admin|127.0.0.1');
        Assert::same(1, $first->hit('admin|127.0.0.1'));
        Assert::false(str_contains(file_get_contents($this->directory . '/buckets.json'), 'admin|127.0.0.1'));
    }

    public function testExpiredBucketsAreRemovedAndWindowDoesNotSlide(): void
    {
        $backend = new FileRateLimitBackend($this->directory, 900);
        $backend->incr('active');
        $path = $this->directory . '/buckets.json';
        $before = json_decode(file_get_contents($path), true);
        $backend->incr('active');
        Assert::same($before['active']['expires'], json_decode(file_get_contents($path), true)['active']['expires']);
        $before['expired'] = ['attempts' => 20, 'expires' => time() - 1];
        file_put_contents($path, json_encode($before));
        Assert::same(false, $backend->get('expired'));
        Assert::same(1, $backend->incr('expired'));
    }

    public function testCorruptStorageFailsClosed(): void
    {
        $backend = new FileRateLimitBackend($this->directory, 900);
        file_put_contents($this->directory . '/buckets.json', '{broken');
        Assert::throws(\JsonException::class, static fn () => $backend->incr('key'));
    }

    public function testConcurrentIncrementsDoNotLoseAttempts(): void
    {
        if (!function_exists('pcntl_fork')) {
            throw new SkippedTestException('pcntl required for concurrency test');
        }
        $backend = new FileRateLimitBackend($this->directory, 900);
        $children = [];
        for ($worker = 0; $worker < 4; $worker++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new \RuntimeException('Unable to fork test worker');
            }
            if ($pid === 0) {
                for ($attempt = 0; $attempt < 25; $attempt++) {
                    $backend->incr('shared');
                }
                exit(0);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            Assert::same(0, pcntl_wexitstatus($status));
        }
        Assert::same(100, $backend->get('shared'));
    }
}

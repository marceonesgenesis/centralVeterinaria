<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\SubmissionKeyRepositoryInterface;
use RuntimeException;

/**
 * In-memory SubmissionKeyRepositoryInterface for use-case tests: one key per
 * scope/token (a single tenant), no transaction check.
 */
final class FakeSubmissionKeyRepository implements SubmissionKeyRepositoryInterface
{
    /** @var array<string, array{systemUserId: int, referenceId: ?int}> */
    private array $keys = [];

    public function __construct()
    {
    }

    public function claim(string $scope, string $token, int $systemUserId): bool
    {
        $key = self::key($scope, $token);

        if (isset($this->keys[$key])) {
            return false;
        }

        $this->keys[$key] = ['systemUserId' => $systemUserId, 'referenceId' => null];

        return true;
    }

    public function attach(string $scope, string $token, int $referenceId): void
    {
        $key = self::key($scope, $token);

        if (!isset($this->keys[$key])) {
            throw new RuntimeException('Submission token was not claimed');
        }

        $this->keys[$key]['referenceId'] = $referenceId;
    }

    public function findReference(string $scope, string $token): ?int
    {
        return $this->keys[self::key($scope, $token)]['referenceId'] ?? null;
    }

    private static function key(string $scope, string $token): string
    {
        return $scope . "\0" . $token;
    }
}

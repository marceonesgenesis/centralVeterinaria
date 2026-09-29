<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\TenantUserDirectoryInterface;

/**
 * In-memory double for TenantUserDirectoryInterface (final-fix): a fixed
 * list of system user ids considered active members of the tenant, or
 * every id when built through allowingAll() (used by the scenarios that
 * only exercise other rules of the service under test).
 */
final class FakeTenantUserDirectory implements TenantUserDirectoryInterface
{
    /** @var list<int> */
    public array $lookups = [];

    /**
     * @param list<int>|null $memberIds null means "every id is a member".
     */
    public function __construct(private readonly ?array $memberIds = [])
    {
    }

    public static function allowingAll(): self
    {
        return new self(null);
    }

    public function isActiveMember(int $systemUserId): bool
    {
        $this->lookups[] = $systemUserId;

        return $this->memberIds === null || in_array($systemUserId, $this->memberIds, true);
    }
}

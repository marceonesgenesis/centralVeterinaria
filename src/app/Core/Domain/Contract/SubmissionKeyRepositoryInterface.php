<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

/**
 * Claimed form submission tokens of the tenant of the context
 * (`submission_key`, migration 0016). The scope is one of the
 * `CentralVet\Domain\SubmissionToken::SCOPE_*` constants.
 */
interface SubmissionKeyRepositoryInterface
{
    /**
     * Records the token inside the caller transaction. True when this call
     * inserted it; false when the tenant/scope/token already exists. A
     * concurrent claim of the same token waits for the first transaction
     * (lock wait 1205 surfaces to the caller).
     *
     * @throws \LogicException outside an open transaction
     */
    public function claim(string $scope, string $token, int $systemUserId): bool;

    /** Links a claimed token to the record it produced (sale or payment id). */
    public function attach(string $scope, string $token, int $referenceId): void;

    /** The id attached to the token, or null when unclaimed or not attached yet. */
    public function findReference(string $scope, string $token): ?int;
}

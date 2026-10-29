<?php

declare(strict_types=1);

namespace CentralVet\Ai\Tools;

use CentralVet\Tenancy\TenantContext;

/**
 * Immutable scope of one tool call: tenant, active unit and user come from
 * the session (never from the model), plus the conversation being served.
 */
final class ToolContext
{
    private function __construct(
        public readonly int $tenantId,
        public readonly int $unitId,
        public readonly int $userId,
        public readonly string $correlationId,
        public readonly ?int $conversationId,
        public readonly string $purpose,
        public readonly ?int $anchorPatientId,
    ) {
    }

    /** @throws \CentralVet\Tenancy\Exception\MissingTenantContext when there is no active unit */
    public static function create(
        TenantContext $tenant,
        string $correlationId,
        ?int $conversationId,
        string $purpose,
        ?int $anchorPatientId,
    ): self {
        return new self(
            $tenant->tenantId(),
            $tenant->requireUnitId(),
            $tenant->userId(),
            $correlationId,
            $conversationId,
            $purpose,
            $anchorPatientId,
        );
    }
}

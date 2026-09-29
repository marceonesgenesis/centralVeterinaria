<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Application\EncounterDocumentService;
use CentralVet\Tenancy\TenantContext;
use CentralVet\Tests\Support\Assert;
use CentralVet\Tests\Support\FakeStorage;

/**
 * Unit tests for EncounterDocumentService (T-08), against FakeStorage — an
 * in-memory StorageInterface double that does no key namespacing of its own,
 * so a passing test here proves EncounterDocumentService itself (not the
 * storage backend) prefixes every key by tenant and by encounterId. Covers
 * T-05's own acceptance criterion.
 */
final class EncounterDocumentServiceTest
{
    public function testAttachGeneratesKeyContainingTenantIdAndEncounterId(): void
    {
        $storage = new FakeStorage();
        $tenant = TenantContext::authenticated(tenantId: 7, userId: 1, unitId: 1);
        $service = new EncounterDocumentService($storage, $tenant);

        $metadata = $service->attach(42, 'exame-sangue.pdf', 'conteudo binario', 'application/pdf');

        Assert::stringContains('tenant/7/', $metadata->objectKey);
        Assert::stringContains('encounter/42/', $metadata->objectKey);
        Assert::true($storage->exists($metadata->objectKey));
    }

    /**
     * Proves the tenant/encounterId prefixing is not decorative: attaching
     * under two different tenants (same encounterId) and, separately, two
     * different encounters (same tenant) never collide on the same storage
     * key — the class docblock's own claim.
     */
    public function testAttachKeysNeverCollideAcrossTenantsOrEncounters(): void
    {
        $storage = new FakeStorage();
        $tenantA = new EncounterDocumentService($storage, TenantContext::authenticated(1, 1, 1));
        $tenantB = new EncounterDocumentService($storage, TenantContext::authenticated(2, 1, 1));

        $metaA = $tenantA->attach(42, 'laudo.pdf', 'a', 'application/pdf');
        $metaB = $tenantB->attach(42, 'laudo.pdf', 'b', 'application/pdf');

        Assert::true($metaA->objectKey !== $metaB->objectKey, 'Keys for different tenants must not collide');

        $otherEncounter = $tenantA->attach(43, 'laudo.pdf', 'c', 'application/pdf');
        Assert::true($metaA->objectKey !== $otherEncounter->objectKey, 'Keys for different encounters must not collide');
    }

    /**
     * list() has no `stored_object` index/repository to read from in this
     * plan (see EncounterDocumentService's own class docblock) — it is a
     * documented gap, not a bug, so this pins the current, honest behaviour
     * rather than pretending it lists what attach() just stored.
     */
    public function testListReturnsEmptyArrayPerDocumentedGap(): void
    {
        $storage = new FakeStorage();
        $service = new EncounterDocumentService($storage, TenantContext::authenticated(1, 1, 1));

        $service->attach(42, 'laudo.pdf', 'conteudo', 'application/pdf');

        Assert::count(0, $service->list(42));
    }
}

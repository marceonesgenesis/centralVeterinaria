<?php

declare(strict_types=1);

namespace CentralVet\Domain\Exception;

use RuntimeException;

/**
 * Thrown when a create/update use case receives a foreign key (e.g.
 * `tutor_id`) that does not resolve within the authenticated tenant —
 * either because it does not exist at all or because it belongs to another
 * tenant. Those two cases are intentionally indistinguishable from the
 * caller's side (fail-closed, ADR 0002): distinguishing them would leak
 * cross-tenant existence.
 *
 * New class instead of reusing `CentralVet\Tenancy\Exception\
 * TenantBoundaryViolation`: that one models a repository asserting that an
 * entity it already loaded belongs to the caller's own tenant
 * (`AbstractTenantRepository::assertEntityTenant()`), i.e. a first-party
 * tenant mismatch. This one models an Application-layer rejection of a
 * *reference* to a second aggregate (tutor_id, service_id, ...) supplied in
 * input data, before anything is persisted. Kept in `Domain\Exception` so
 * it can be reused by every Phase 1 aggregate that accepts a cross-aggregate
 * foreign key (Patient.tutor_id today; Appointment/QueueEntry later), not
 * just Patient.
 */
final class CrossTenantReferenceException extends RuntimeException
{
}

<?php

declare(strict_types=1);

namespace CentralVet\Application;

use CentralVet\Authorization\AuthorizationRequest;
use CentralVet\Authorization\Contract\AuthorizationPolicyInterface;
use CentralVet\Domain\CommunicationChannel;
use CentralVet\Domain\CommunicationPreference;
use CentralVet\Domain\Contract\CommunicationPreferenceRepositoryInterface;
use CentralVet\Domain\Contract\OutboundMessageRepositoryInterface;
use CentralVet\Domain\Contract\TutorRepositoryInterface;
use CentralVet\Domain\Exception\CrossTenantReferenceException;
use CentralVet\Tenancy\TenantContext;
use Closure;
use DateTimeImmutable;

/**
 * Reads and records a tutor's opt-in/opt-out per communication channel
 * (T-09). Preferences are tenant-wide (no unit scope). Recording authorizes
 * with an audit trail carrying channel, status before/after and consent
 * source only — never the tutor's e-mail or phone. Whether a message may be
 * sent is not decided here: that is `CommunicationPreference::permitsSending`.
 * An opt-out cancels, in the same transaction, every queued message of the
 * tutor on that channel in the tenant (`opted_out`; an opt-out blocks both
 * legal bases), so neither the worker nor the Central/ficha offers it again
 * (T-25); an opt-in cancels nothing. Production must pass `$messages`.
 * No transaction is opened: the controller owns it.
 */
final class CommunicationPreferenceService
{
    public const NOT_RECORDED = 'not_recorded';

    private const ENTITY_TYPE = 'communication_preference';

    private readonly Closure $clock;

    public function __construct(
        private readonly CommunicationPreferenceRepositoryInterface $preferences,
        private readonly TutorRepositoryInterface $tutors,
        private readonly AuthorizationPolicyInterface $authorization,
        private readonly TenantContext $context,
        ?Closure $clock = null,
        private readonly ?OutboundMessageRepositoryInterface $messages = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    /**
     * @return array<string, string> channel → `opted_in`, `opted_out` or `not_recorded`
     *
     * @throws CrossTenantReferenceException when the tutor is not in the tenant.
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied
     */
    public function preferencesFor(int $tutorId, string $action): array
    {
        $this->requireTutor($tutorId);
        $this->authorize($action, $tutorId, []);

        return $this->statusMap($this->preferences->findForTutor($tutorId));
    }

    /**
     * @throws CrossTenantReferenceException when the tutor is not in the tenant.
     * @throws \InvalidArgumentException for an unknown channel, status or consent source.
     * @throws \CentralVet\Authorization\Exception\AuthorizationDenied (nothing is written)
     */
    public function record(int $tutorId, string $channel, string $status, string $consentSource, string $action): CommunicationPreference
    {
        $this->requireTutor($tutorId);

        $preference = CommunicationPreference::record(
            $this->context->tenantId(),
            $tutorId,
            $channel,
            $status,
            $consentSource,
            $this->context->userId(),
            ($this->clock)(),
        );

        $before = $this->statusMap($this->preferences->findForTutor($tutorId))[$preference->channel()];

        $this->authorize($action, $tutorId, [
            'channel' => $preference->channel(),
            'status_before' => $before,
            'status_after' => $preference->status(),
            'consent_source' => $preference->consentSource(),
        ]);

        $this->preferences->upsert($preference);

        if (!$preference->isOptedIn()) {
            $this->messages?->cancelQueuedForTutor(
                $tutorId,
                $preference->channel(),
                $this->context->userId(),
                CommunicationPreference::STATUS_OPTED_OUT,
                $preference->changedAt(),
            );
        }

        return $preference;
    }

    /**
     * @param array<string, CommunicationPreference> $recorded
     * @return array<string, string>
     */
    private function statusMap(array $recorded): array
    {
        $map = [];

        foreach (CommunicationChannel::all() as $channel) {
            $preference = $recorded[$channel] ?? null;
            $map[$channel] = $preference instanceof CommunicationPreference ? $preference->status() : self::NOT_RECORDED;
        }

        return $map;
    }

    private function requireTutor(int $tutorId): void
    {
        if ($tutorId <= 0 || $this->tutors->findById($tutorId) === null) {
            throw new CrossTenantReferenceException("tutor_id {$tutorId} was not found for the authenticated tenant");
        }
    }

    /** @param array<string, scalar|null> $metadata */
    private function authorize(string $action, int $tutorId, array $metadata): void
    {
        $this->authorization->decide(new AuthorizationRequest(
            context: $this->context,
            action: $action,
            requiresUnitScope: false,
            entityType: self::ENTITY_TYPE,
            entityId: $tutorId,
            metadata: $metadata,
        ))->assertAllowed();
    }
}

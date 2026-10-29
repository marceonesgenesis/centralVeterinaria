<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Domain\CommunicationPreference;

/**
 * Persistence boundary for tutors' channel preferences
 * (`communication_preference`), always within the current tenant. One row
 * per tutor and channel, written by upsert; there is no delete.
 */
interface CommunicationPreferenceRepositoryInterface
{
    /**
     * Preferences of the tutor keyed by channel; only channels that have a
     * row appear in the map.
     *
     * @return array<string, CommunicationPreference>
     */
    public function findForTutor(int $tutorId): array;

    /**
     * Inserts the preference or, when the tutor already has a row on that
     * channel, overwrites status, consent source, author and change time.
     */
    public function upsert(CommunicationPreference $preference): void;
}

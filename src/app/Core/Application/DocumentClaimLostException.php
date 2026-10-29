<?php

declare(strict_types=1);

namespace CentralVet\Application;

use RuntimeException;

/**
 * Internal signal of DocumentGenerationService (T-23): the conditional
 * `markReady` found the document no longer queued, because another worker
 * reclaimed the stale claim and finished first. It rolls the transaction
 * back and the job ends `skipped`; it never leaves the service.
 */
final class DocumentClaimLostException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Document is no longer claimed');
    }
}

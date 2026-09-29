<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use PDO;

/**
 * Type-only stand-in for a real PDO connection (T-08). EncounterService's
 * constructor takes a concrete PDO (not an interface/port — see that class's
 * own docblock explaining why) purely for timeline(), which reads
 * `audit_log` directly; start()/finish()/autosave()/acceptAiSummary() never
 * touch it. Overriding the constructor without calling parent::__construct()
 * satisfies that type requirement without opening any real connection or
 * running any SQL — calling any real PDO method on this instance would fail,
 * which is intentional: it must never be used as a working connection.
 */
final class NullPdo extends PDO
{
    public function __construct()
    {
        // Intentionally does not call parent::__construct().
    }
}

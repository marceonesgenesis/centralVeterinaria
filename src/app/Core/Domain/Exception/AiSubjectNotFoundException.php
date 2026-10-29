<?php

declare(strict_types=1);

namespace CentralVet\Domain\Exception;

use DomainException;

/**
 * The encounter or exam result the AI would read does not exist or is out
 * of the user's tenant/unit. One message for every case.
 */
final class AiSubjectNotFoundException extends DomainException
{
    public function __construct()
    {
        parent::__construct('AI subject not found');
    }
}

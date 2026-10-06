<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Domain\DocumentContent;
use CentralVet\Domain\GeneratedDocument;

/**
 * Builds the content of a requested document from its sources, per kind.
 * Throws `DocumentSourceNotFoundException` when the source is gone.
 */
interface DocumentContentFactoryInterface
{
    public function build(GeneratedDocument $document): DocumentContent;
}

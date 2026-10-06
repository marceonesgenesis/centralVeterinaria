<?php

declare(strict_types=1);

namespace CentralVet\Domain\Contract;

use CentralVet\Domain\DocumentContent;

/**
 * Turns neutral document content into PDF bytes. Implementations escape
 * every string and never fetch remote resources.
 */
interface DocumentRendererInterface
{
    /** @return string the PDF bytes */
    public function render(DocumentContent $content): string;
}

<?php

declare(strict_types=1);

namespace CentralVet\Domain\Exception;

use RuntimeException;

/**
 * Raised inside the document worker when one generation step fails
 * (Fase 7B). Carries only a stable code, never personal data or PDF bytes:
 * the code is stored in `generated_document.last_error_code`.
 * `source_not_found` fails at once; the other codes are retried.
 */
final class DocumentGenerationFailed extends RuntimeException
{
    public const SOURCE_NOT_FOUND = 'source_not_found';
    public const RENDER_FAILED = 'render_failed';
    public const STORAGE_FAILED = 'storage_failed';
    public const PERSIST_FAILED = 'persist_failed';

    public function __construct(private readonly string $errorCode)
    {
        parent::__construct("Document generation failed: {$errorCode}");
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}

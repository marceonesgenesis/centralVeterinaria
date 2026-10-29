<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;

/**
 * Neutral, already-resolved content of one PDF (Fase 7B): built by
 * `DocumentContentFactoryInterface` and turned into bytes by
 * `DocumentRendererInterface`. Every string is plain text; escaping is the
 * renderer's job.
 */
final class DocumentContent
{
    /**
     * @param list<string>       $subjectLines lines of the identification block (patient, tutor...)
     * @param list<string>       $paragraphs   free-text paragraphs
     * @param list<string>       $tableHeader  column titles; empty when there is no table
     * @param list<list<string>> $tableRows    table rows, one string per column
     */
    public function __construct(
        public readonly string $title,
        public readonly string $clinicName,
        public readonly string $unitName,
        public readonly array $subjectLines,
        public readonly array $paragraphs,
        public readonly array $tableHeader,
        public readonly array $tableRows,
        public readonly ?string $signatureName,
        public readonly DateTimeImmutable $issuedAt,
    ) {
    }
}

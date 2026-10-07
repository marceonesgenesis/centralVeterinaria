<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\DocumentContentFactoryInterface;
use CentralVet\Domain\DocumentContent;
use CentralVet\Domain\GeneratedDocument;
use DateTimeImmutable;
use Throwable;

/**
 * Double for DocumentContentFactoryInterface (T-05): build() returns a
 * minimal DocumentContent carrying the document title and records the
 * document. failWith() makes only the next call throw.
 */
final class FakeDocumentContentFactory implements DocumentContentFactoryInterface
{
    /** @var list<GeneratedDocument> */
    private array $calls = [];
    private ?Throwable $failure = null;

    public function build(GeneratedDocument $document): DocumentContent
    {
        $this->calls[] = $document;

        if ($this->failure !== null) {
            $failure = $this->failure;
            $this->failure = null;

            throw $failure;
        }

        return new DocumentContent($document->title(), 'F7B teste clinic', 'F7B teste unit', [], [], [], [], null, new DateTimeImmutable());
    }

    /** @return list<GeneratedDocument> */
    public function calls(): array
    {
        return $this->calls;
    }

    public function failWith(Throwable $e): void
    {
        $this->failure = $e;
    }
}

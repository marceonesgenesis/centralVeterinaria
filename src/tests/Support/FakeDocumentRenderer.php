<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\DocumentRendererInterface;
use CentralVet\Domain\DocumentContent;
use Throwable;

/**
 * Double for DocumentRendererInterface (T-05): render() returns
 * `"%PDF-FAKE " . $content->title` and records every call (failed ones
 * included). failWith() makes only the next call throw.
 */
final class FakeDocumentRenderer implements DocumentRendererInterface
{
    /** @var list<DocumentContent> */
    private array $calls = [];
    private ?Throwable $failure = null;

    public function render(DocumentContent $content): string
    {
        $this->calls[] = $content;

        if ($this->failure !== null) {
            $failure = $this->failure;
            $this->failure = null;

            throw $failure;
        }

        return '%PDF-FAKE ' . $content->title;
    }

    /** @return list<DocumentContent> */
    public function calls(): array
    {
        return $this->calls;
    }

    public function failWith(Throwable $e): void
    {
        $this->failure = $e;
    }
}

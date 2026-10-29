<?php

declare(strict_types=1);

namespace CentralVet\Domain\Exception;

use RuntimeException;

/**
 * Thrown by the use cases that claim a submission token (sale checkout,
 * payment registration) when the token was already claimed: the form was
 * resent, so nothing new is recorded. `referenceId()` is the record the
 * first submission produced (null when it was not attached).
 */
final class DuplicateSubmissionException extends RuntimeException
{
    private function __construct(private readonly string $scope, private readonly ?int $referenceId)
    {
        parent::__construct('This submission was already processed');
    }

    public static function forReference(string $scope, ?int $referenceId): self
    {
        return new self($scope, $referenceId);
    }

    public function scope(): string
    {
        return $this->scope;
    }

    public function referenceId(): ?int
    {
        return $this->referenceId;
    }
}

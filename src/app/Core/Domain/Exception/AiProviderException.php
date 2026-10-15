<?php

declare(strict_types=1);

namespace CentralVet\Domain\Exception;

use CentralVet\Domain\AiUsage;
use InvalidArgumentException;
use RuntimeException;

/**
 * Failure reported by an AI gateway. Carries only the reason code, the
 * HTTP status and, when the provider answered, the usage and model so the
 * cost of a failed call is still recorded. Never the request or response
 * body, nor any header.
 */
final class AiProviderException extends RuntimeException
{
    /** Same codes as AiUnavailableException, so the service maps one to the other 1:1. */
    public const REASONS = AiUnavailableException::REASONS;

    private readonly string $reason;

    public function __construct(
        string $reason,
        private readonly ?int $httpStatus = null,
        private readonly ?AiUsage $usage = null,
        private readonly ?string $model = null,
    ) {
        if (!in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException('Unknown AI provider failure reason');
        }

        $this->reason = $reason;
        parent::__construct('AI provider failure: ' . $reason . ($httpStatus !== null ? ' (HTTP ' . $httpStatus . ')' : ''));
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function usage(): ?AiUsage
    {
        return $this->usage;
    }

    public function model(): ?string
    {
        return $this->model;
    }
}

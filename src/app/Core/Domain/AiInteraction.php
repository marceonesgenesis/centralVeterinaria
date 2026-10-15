<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use CentralVet\Domain\Exception\AiInteractionNotFoundException;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One AI call and its human review (`ai_interaction`, migration 0014).
 *
 * Born by `start()` in `pending_review` without suggestion; then either
 * `recordSuggestion()` (stays `pending_review` until the professional
 * decides) or `recordFailure()` (`failed`, final). `decide()` moves a
 * suggestion to `accepted` (final text = suggestion), `edited` (final text
 * = the professional's text) or `discarded` (no final text). Only the
 * SHA-256 of the input is kept; suggestion and final texts are clinical
 * data and never go into exception messages or logs.
 */
final class AiInteraction
{
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_EDITED = 'edited';
    public const STATUS_DISCARDED = 'discarded';
    public const STATUS_FAILED = 'failed';

    public const DECISIONS = [self::STATUS_ACCEPTED, self::STATUS_EDITED, self::STATUS_DISCARDED];

    private const STATUSES = [
        self::STATUS_PENDING_REVIEW,
        self::STATUS_ACCEPTED,
        self::STATUS_EDITED,
        self::STATUS_DISCARDED,
        self::STATUS_FAILED,
    ];

    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $systemUnitId,
        private readonly int $systemUserId,
        private readonly string $purpose,
        private readonly string $subjectType,
        private readonly int $subjectId,
        private readonly string $promptVersion,
        private string $provider,
        private ?string $model,
        private string $status,
        private readonly string $inputSha256,
        private AiUsage $usage,
        private ?int $costMicroUsd,
        private ?int $latencyMs,
        private ?string $stopReason,
        private ?string $providerRequestId,
        private ?string $errorCode,
        private ?string $suggestionText,
        private ?string $finalText,
        private ?int $decidedBySystemUserId,
        private ?DateTimeImmutable $decidedAt,
        private readonly string $correlationId,
        private readonly ?DateTimeImmutable $createdAt,
    ) {
    }

    public static function start(
        int $tenantId,
        int $systemUnitId,
        int $systemUserId,
        string $purpose,
        int $subjectId,
        string $promptVersion,
        string $inputSha256,
        string $correlationId,
    ): self {
        foreach ([
            'tenant_id' => $tenantId,
            'system_unit_id' => $systemUnitId,
            'system_user_id' => $systemUserId,
            'subject_id' => $subjectId,
        ] as $field => $value) {
            if ($value <= 0) {
                throw new InvalidArgumentException("{$field} must be positive");
            }
        }

        if (trim($promptVersion) === '') {
            throw new InvalidArgumentException('Prompt version is required');
        }

        if (preg_match('/^[0-9a-f]{64}$/', $inputSha256) !== 1) {
            throw new InvalidArgumentException('Input hash must be a lowercase SHA-256');
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            systemUnitId: $systemUnitId,
            systemUserId: $systemUserId,
            purpose: $purpose,
            subjectType: AiPurpose::subjectType($purpose),
            subjectId: $subjectId,
            promptVersion: $promptVersion,
            provider: '',
            model: null,
            status: self::STATUS_PENDING_REVIEW,
            inputSha256: $inputSha256,
            usage: AiUsage::none(),
            costMicroUsd: null,
            latencyMs: null,
            stopReason: null,
            providerRequestId: null,
            errorCode: null,
            suggestionText: null,
            finalText: null,
            decidedBySystemUserId: null,
            decidedAt: null,
            correlationId: $correlationId,
            createdAt: null,
        );
    }

    /**
     * Rebuilds an interaction from persisted data.
     *
     * @param array<string, mixed> $row raw column values, keyed exactly like the `ai_interaction` table.
     */
    public static function reconstitute(array $row): self
    {
        $status = (string) $row['status'];

        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unknown AI interaction status');
        }

        $purpose = (string) $row['purpose'];

        return new self(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            systemUnitId: (int) $row['system_unit_id'],
            systemUserId: (int) $row['system_user_id'],
            purpose: $purpose,
            subjectType: isset($row['subject_type']) ? (string) $row['subject_type'] : AiPurpose::subjectType($purpose),
            subjectId: (int) $row['subject_id'],
            promptVersion: (string) $row['prompt_version'],
            provider: (string) ($row['provider'] ?? ''),
            model: self::nullableString($row, 'model'),
            status: $status,
            inputSha256: (string) ($row['input_sha256'] ?? ''),
            usage: new AiUsage(
                (int) ($row['input_tokens'] ?? 0),
                (int) ($row['output_tokens'] ?? 0),
                (int) ($row['cache_read_tokens'] ?? 0),
                (int) ($row['cache_write_tokens'] ?? 0),
            ),
            costMicroUsd: self::nullableInt($row, 'cost_micro_usd'),
            latencyMs: self::nullableInt($row, 'latency_ms'),
            stopReason: self::nullableString($row, 'stop_reason'),
            providerRequestId: self::nullableString($row, 'provider_request_id'),
            errorCode: self::nullableString($row, 'error_code'),
            suggestionText: self::nullableString($row, 'suggestion_text'),
            finalText: self::nullableString($row, 'final_text'),
            decidedBySystemUserId: self::nullableInt($row, 'decided_by_system_user_id'),
            decidedAt: self::nullableDate($row, 'decided_at'),
            correlationId: (string) ($row['correlation_id'] ?? ''),
            createdAt: self::nullableDate($row, 'created_at'),
        );
    }

    public function recordSuggestion(AiCompletionResult $result, int $latencyMs): void
    {
        $this->assertAwaitingProvider();

        $text = trim($result->text);

        if ($text === '') {
            throw new InvalidArgumentException('AI suggestion must not be empty');
        }

        $this->suggestionText = $text;
        $this->provider = $result->provider;
        $this->model = $result->model;
        $this->usage = $result->usage;
        $this->costMicroUsd = $result->costMicroUsd;
        $this->stopReason = $result->stopReason;
        $this->providerRequestId = $result->providerRequestId;
        $this->latencyMs = max(0, $latencyMs);
    }

    /**
     * A cost already priced by the gateway (per attempt/model after a fallback)
     * wins; otherwise it is computed from the partial usage when the provider
     * answered with a known model.
     */
    public function recordFailure(string $errorCode, int $latencyMs, ?AiUsage $usage, ?string $model, string $provider, ?int $costMicroUsd = null): void
    {
        $this->assertAwaitingProvider();

        if (trim($errorCode) === '') {
            throw new InvalidArgumentException('Error code is required');
        }

        $this->status = self::STATUS_FAILED;
        $this->errorCode = $errorCode;
        $this->provider = $provider;
        $this->model = $model;
        $this->usage = $usage ?? AiUsage::none();
        $this->costMicroUsd = $costMicroUsd ?? (($usage !== null && $model !== null) ? AiModelPricing::costMicroUsd($model, $usage) : null);
        $this->latencyMs = max(0, $latencyMs);
    }

    /**
     * @throws AiInteractionNotFoundException when the interaction is not awaiting a decision.
     * @throws InvalidArgumentException on an unknown decision or a blank edited text.
     */
    public function decide(string $decision, ?string $finalText, int $systemUserId, DateTimeImmutable $now): void
    {
        if (!in_array($decision, self::DECISIONS, true)) {
            throw new InvalidArgumentException('Unknown AI decision');
        }

        if ($this->status !== self::STATUS_PENDING_REVIEW || $this->suggestionText === null) {
            throw new AiInteractionNotFoundException();
        }

        if ($systemUserId <= 0) {
            throw new InvalidArgumentException('System user id must be positive');
        }

        $final = match ($decision) {
            self::STATUS_ACCEPTED => $this->suggestionText,
            self::STATUS_EDITED => trim((string) $finalText),
            self::STATUS_DISCARDED => null,
        };

        if ($decision === self::STATUS_EDITED && $final === '') {
            throw new InvalidArgumentException('Edited AI text must not be empty');
        }

        $this->status = $decision;
        $this->finalText = $final;
        $this->decidedBySystemUserId = $systemUserId;
        $this->decidedAt = $now;
    }

    /** Used by the repository after the insert. */
    public function assignId(int $id): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('AI interaction already has an id');
        }

        if ($id <= 0) {
            throw new InvalidArgumentException('Id must be positive');
        }

        $this->id = $id;
    }

    /**
     * Column values for `ai_interaction` (without `created_at`/`updated_at`,
     * which the database fills). `decided_at` uses `Y-m-d H:i:s.u`.
     *
     * @return array<string, mixed>
     */
    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'system_unit_id' => $this->systemUnitId,
            'system_user_id' => $this->systemUserId,
            'purpose' => $this->purpose,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'prompt_version' => $this->promptVersion,
            'provider' => $this->provider,
            'model' => $this->model,
            'status' => $this->status,
            'input_sha256' => $this->inputSha256,
            'input_tokens' => $this->usage->inputTokens,
            'output_tokens' => $this->usage->outputTokens,
            'cache_read_tokens' => $this->usage->cacheReadTokens,
            'cache_write_tokens' => $this->usage->cacheWriteTokens,
            'cost_micro_usd' => $this->costMicroUsd,
            'latency_ms' => $this->latencyMs,
            'stop_reason' => $this->stopReason,
            'provider_request_id' => $this->providerRequestId,
            'error_code' => $this->errorCode,
            'suggestion_text' => $this->suggestionText,
            'final_text' => $this->finalText,
            'decided_by_system_user_id' => $this->decidedBySystemUserId,
            'decided_at' => $this->decidedAt?->format('Y-m-d H:i:s.u'),
            'correlation_id' => $this->correlationId,
        ];
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function systemUnitId(): int
    {
        return $this->systemUnitId;
    }

    public function systemUserId(): int
    {
        return $this->systemUserId;
    }

    public function purpose(): string
    {
        return $this->purpose;
    }

    public function subjectType(): string
    {
        return $this->subjectType;
    }

    public function subjectId(): int
    {
        return $this->subjectId;
    }

    public function promptVersion(): string
    {
        return $this->promptVersion;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function model(): ?string
    {
        return $this->model;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function inputSha256(): string
    {
        return $this->inputSha256;
    }

    public function usage(): AiUsage
    {
        return $this->usage;
    }

    public function costMicroUsd(): ?int
    {
        return $this->costMicroUsd;
    }

    public function latencyMs(): ?int
    {
        return $this->latencyMs;
    }

    public function stopReason(): ?string
    {
        return $this->stopReason;
    }

    public function providerRequestId(): ?string
    {
        return $this->providerRequestId;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function suggestionText(): ?string
    {
        return $this->suggestionText;
    }

    public function finalText(): ?string
    {
        return $this->finalText;
    }

    public function decidedBySystemUserId(): ?int
    {
        return $this->decidedBySystemUserId;
    }

    public function decidedAt(): ?DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    private function assertAwaitingProvider(): void
    {
        if ($this->status !== self::STATUS_PENDING_REVIEW || $this->suggestionText !== null) {
            throw new InvalidArgumentException('AI interaction already has an outcome');
        }
    }

    /** @param array<string, mixed> $row */
    private static function nullableInt(array $row, string $key): ?int
    {
        return isset($row[$key]) ? (int) $row[$key] : null;
    }

    /** @param array<string, mixed> $row */
    private static function nullableString(array $row, string $key): ?string
    {
        return isset($row[$key]) ? (string) $row[$key] : null;
    }

    /** @param array<string, mixed> $row */
    private static function nullableDate(array $row, string $key): ?DateTimeImmutable
    {
        return isset($row[$key]) ? new DateTimeImmutable((string) $row[$key]) : null;
    }
}

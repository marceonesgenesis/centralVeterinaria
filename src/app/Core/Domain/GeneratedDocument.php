<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;

/**
 * One requested PDF document and its generation state (domain entity for
 * the `generated_document` table, migration 0013, Fase 7B).
 *
 * The entity is born `queued` with version 0 and no id; the repository
 * assigns both (`MAX(version)+1` per source) through `assignIdentity()`.
 * Every later transition (claim, ready, failed, requeue, notified) is a
 * conditional UPDATE in `GeneratedDocumentRepositoryInterface`, never a
 * full save. The certificate and the surgical consent freeze their text in
 * `body_text` at request time; the text is personal data and never goes
 * into exception messages.
 */
final class GeneratedDocument
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    public const BODY_TEXT_MAX_LENGTH = 20000;

    private const STATUSES = [self::STATUS_QUEUED, self::STATUS_READY, self::STATUS_FAILED];

    private function __construct(
        private ?int $id,
        private readonly int $tenantId,
        private readonly int $systemUnitId,
        private readonly int $patientId,
        private readonly int $tutorId,
        private readonly string $kind,
        private readonly string $sourceType,
        private readonly int $sourceId,
        private int $version,
        private readonly ?int $templateId,
        private readonly string $title,
        private readonly ?string $bodyText,
        private readonly bool $notifyTutor,
        private readonly string $status,
        private readonly int $attemptCount,
        private readonly ?int $storedObjectId,
        private readonly ?string $storageKey,
        private readonly ?string $lastErrorCode,
        private readonly ?DateTimeImmutable $readyAt,
        private readonly ?DateTimeImmutable $notifiedAt,
        private readonly int $requestedBySystemUserId,
        private readonly ?DateTimeImmutable $createdAt,
    ) {
    }

    public static function request(
        int $tenantId,
        int $systemUnitId,
        int $patientId,
        int $tutorId,
        string $kind,
        int $sourceId,
        ?int $templateId,
        ?string $bodyText,
        bool $notifyTutor,
        int $requestedBySystemUserId,
    ): self {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant id must be positive');
        }

        foreach ([
            'system_unit_id' => $systemUnitId,
            'patient_id' => $patientId,
            'tutor_id' => $tutorId,
            'source_id' => $sourceId,
            'requested_by_system_user_id' => $requestedBySystemUserId,
        ] as $field => $value) {
            if ($value <= 0) {
                throw new InvalidArgumentException("{$field} must be positive");
            }
        }

        if ($templateId !== null && $templateId <= 0) {
            throw new InvalidArgumentException('template_id must be positive');
        }

        DocumentKind::assertValid($kind);

        if (DocumentKind::requiresBodyText($kind)) {
            $bodyText = trim((string) $bodyText);
            $length = mb_strlen($bodyText);

            if ($length < 1 || $length > self::BODY_TEXT_MAX_LENGTH) {
                throw new InvalidArgumentException('Document body must be between 1 and 20000 characters');
            }
        } elseif ($bodyText !== null) {
            throw new InvalidArgumentException("Document kind \"{$kind}\" does not accept a body");
        }

        return new self(
            id: null,
            tenantId: $tenantId,
            systemUnitId: $systemUnitId,
            patientId: $patientId,
            tutorId: $tutorId,
            kind: $kind,
            sourceType: DocumentKind::sourceTypeFor($kind),
            sourceId: $sourceId,
            version: 0,
            templateId: $templateId,
            title: DocumentKind::titleFor($kind),
            bodyText: $bodyText,
            notifyTutor: $notifyTutor,
            status: self::STATUS_QUEUED,
            attemptCount: 0,
            storedObjectId: null,
            storageKey: null,
            lastErrorCode: null,
            readyAt: null,
            notifiedAt: null,
            requestedBySystemUserId: $requestedBySystemUserId,
            createdAt: null,
        );
    }

    /**
     * Rebuilds a document from persisted data.
     *
     * @param array<string, mixed> $row raw column values, keyed exactly like the `generated_document` table.
     */
    public static function reconstitute(array $row): self
    {
        $status = (string) $row['status'];

        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException("Unknown document status \"{$status}\"");
        }

        $kind = (string) $row['kind'];
        DocumentKind::assertValid($kind);

        $sourceType = (string) $row['source_type'];

        if ($sourceType !== DocumentKind::sourceTypeFor($kind)) {
            throw new InvalidArgumentException("Document source type \"{$sourceType}\" does not match kind \"{$kind}\"");
        }

        return new self(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            systemUnitId: (int) $row['system_unit_id'],
            patientId: (int) $row['patient_id'],
            tutorId: (int) $row['tutor_id'],
            kind: $kind,
            sourceType: $sourceType,
            sourceId: (int) $row['source_id'],
            version: (int) $row['version'],
            templateId: self::nullableInt($row, 'template_id'),
            title: (string) $row['title'],
            bodyText: self::nullableString($row, 'body_text'),
            notifyTutor: (bool) ($row['notify_tutor'] ?? false),
            status: $status,
            attemptCount: (int) ($row['attempt_count'] ?? 0),
            storedObjectId: self::nullableInt($row, 'stored_object_id'),
            storageKey: self::nullableString($row, 'storage_key'),
            lastErrorCode: self::nullableString($row, 'last_error_code'),
            readyAt: self::nullableDate($row, 'ready_at'),
            notifiedAt: self::nullableDate($row, 'notified_at'),
            requestedBySystemUserId: (int) $row['requested_by_system_user_id'],
            createdAt: self::nullableDate($row, 'created_at'),
        );
    }

    /** Used by the repository after the insert: sets the id and the version it assigned. */
    public function assignIdentity(int $id, int $version): void
    {
        if ($this->id !== null) {
            throw new InvalidArgumentException('Generated document already has an id');
        }

        if ($id <= 0) {
            throw new InvalidArgumentException('Id must be positive');
        }

        if ($version <= 0) {
            throw new InvalidArgumentException('Version must be positive');
        }

        $this->id = $id;
        $this->version = $version;
    }

    /**
     * `<kind>-<id>-v<version>.pdf`, with no personal data.
     *
     * @throws LogicException before the repository assigned the id and version.
     */
    public function fileName(): string
    {
        if ($this->id === null) {
            throw new LogicException('Generated document has no id yet');
        }

        return sprintf('%s-%d-v%d.pdf', $this->kind, $this->id, $this->version);
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::STATUS_READY && $this->storageKey !== null;
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

    public function patientId(): int
    {
        return $this->patientId;
    }

    public function tutorId(): int
    {
        return $this->tutorId;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function sourceType(): string
    {
        return $this->sourceType;
    }

    public function sourceId(): int
    {
        return $this->sourceId;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function templateId(): ?int
    {
        return $this->templateId;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function bodyText(): ?string
    {
        return $this->bodyText;
    }

    public function notifyTutor(): bool
    {
        return $this->notifyTutor;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function attemptCount(): int
    {
        return $this->attemptCount;
    }

    public function storedObjectId(): ?int
    {
        return $this->storedObjectId;
    }

    public function storageKey(): ?string
    {
        return $this->storageKey;
    }

    public function lastErrorCode(): ?string
    {
        return $this->lastErrorCode;
    }

    public function readyAt(): ?DateTimeImmutable
    {
        return $this->readyAt;
    }

    public function notifiedAt(): ?DateTimeImmutable
    {
        return $this->notifiedAt;
    }

    public function requestedBySystemUserId(): int
    {
        return $this->requestedBySystemUserId;
    }

    public function createdAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
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

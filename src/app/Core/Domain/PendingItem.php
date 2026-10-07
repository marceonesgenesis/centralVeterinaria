<?php

declare(strict_types=1);

namespace CentralVet\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One item of the Central de Pendências (Fase 7A, PRD §8.23) — read model
 * built by {@see Contract\PendingItemQueryInterface} from the existing
 * sources; there is no table of its own.
 *
 * ## Due date per type (computed by the query)
 * - `exam_result`: `requested_at` + 72 h;
 * - `exam_review`: `received_at` + 24 h;
 * - `return_appointment`: `scheduled_at`;
 * - `vaccine_due`: `next_dose_at` 00:00 in the application timezone;
 * - `hospitalization_administration`: `scheduled_at` + 30 min;
 * - `message_failed`: `failed_at`;
 * - `message_whatsapp_manual`: `created_at` + 4 h;
 * - `receivable_open`: `created_at` + 7 days.
 *
 * Priority comes from {@see PendingItemPriority::classify()}; status is
 * `overdue` when `$now > dueAt`, otherwise `open`.
 *
 * ## Deep-link
 * {@see self::deepLinkUrl()} only accepts an allow-listed controller class
 * and, per class, the closed key list of {@see self::DEEP_LINK_KEYS}. Each
 * key has a fixed value kind: internal ids are positive integers, `date` is
 * a valid `Y-m-d` and `tab` is the literal `administrations`. Any other key
 * (phone, document, birth date...) or value is refused, so no free text or
 * personal data reaches the URL. No Adianti dependency (ADR 0001).
 */
final class PendingItem
{
    public const TYPE_EXAM_RESULT = 'exam_result';
    public const TYPE_EXAM_REVIEW = 'exam_review';
    public const TYPE_RETURN_APPOINTMENT = 'return_appointment';
    public const TYPE_VACCINE_DUE = 'vaccine_due';
    public const TYPE_HOSPITALIZATION_ADMINISTRATION = 'hospitalization_administration';
    public const TYPE_MESSAGE_FAILED = 'message_failed';
    public const TYPE_MESSAGE_WHATSAPP_MANUAL = 'message_whatsapp_manual';
    public const TYPE_RECEIVABLE_OPEN = 'receivable_open';

    public const TYPES = [
        self::TYPE_EXAM_RESULT,
        self::TYPE_EXAM_REVIEW,
        self::TYPE_RETURN_APPOINTMENT,
        self::TYPE_VACCINE_DUE,
        self::TYPE_HOSPITALIZATION_ADMINISTRATION,
        self::TYPE_MESSAGE_FAILED,
        self::TYPE_MESSAGE_WHATSAPP_MANUAL,
        self::TYPE_RECEIVABLE_OPEN,
    ];

    public const STATUS_OVERDUE = 'overdue';
    public const STATUS_OPEN = 'open';

    public const DEEP_LINK_CLASSES = [
        'ExamResultForm',
        'AgendaView',
        'VaccinationCardView',
        'HospitalizationView',
        'CommunicationMessageView',
        'PaymentForm',
    ];

    /**
     * Closed list of deep-link keys per destination class (T-08 mapping).
     */
    public const DEEP_LINK_KEYS = [
        'ExamResultForm' => ['exam_request_id', 'encounter_id'],
        'AgendaView' => ['date'],
        'VaccinationCardView' => ['patient_id'],
        'HospitalizationView' => ['id', 'tab'],
        'CommunicationMessageView' => ['id'],
        'PaymentForm' => ['receivable_id'],
    ];

    private const VALUE_ID = 'id';
    private const VALUE_DATE = 'date';
    private const VALUE_TAB = 'tab';

    /**
     * Value kind of each allowed key.
     */
    private const DEEP_LINK_VALUE_KINDS = [
        'id' => self::VALUE_ID,
        'exam_request_id' => self::VALUE_ID,
        'encounter_id' => self::VALUE_ID,
        'patient_id' => self::VALUE_ID,
        'receivable_id' => self::VALUE_ID,
        'date' => self::VALUE_DATE,
        'tab' => self::VALUE_TAB,
    ];

    private const DEEP_LINK_TABS = ['administrations'];

    /**
     * @param array<string, int|string> $deepLinkParams
     */
    public function __construct(
        private readonly string $type,
        private readonly int $sourceId,
        private readonly ?string $patientName,
        private readonly string $subjectLabel,
        private readonly DateTimeImmutable $dueAt,
        private readonly ?int $responsibleSystemUserId,
        private readonly string $deepLinkClass,
        private readonly array $deepLinkParams,
    ) {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Invalid pending item type');
        }

        if (!in_array($deepLinkClass, self::DEEP_LINK_CLASSES, true)) {
            throw new InvalidArgumentException('Invalid deep-link class');
        }
    }

    public function type(): string
    {
        return $this->type;
    }

    public function sourceId(): int
    {
        return $this->sourceId;
    }

    public function patientName(): ?string
    {
        return $this->patientName;
    }

    public function subjectLabel(): string
    {
        return $this->subjectLabel;
    }

    public function dueAt(): DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function responsibleSystemUserId(): ?int
    {
        return $this->responsibleSystemUserId;
    }

    public function priority(DateTimeImmutable $now): string
    {
        return PendingItemPriority::classify($this->type, $this->dueAt, $now);
    }

    public function status(DateTimeImmutable $now): string
    {
        return $now > $this->dueAt ? self::STATUS_OVERDUE : self::STATUS_OPEN;
    }

    /**
     * `index.php?class=<class>&<key>=<value>...`, in the given parameter order.
     *
     * @throws InvalidArgumentException `Invalid deep-link parameter <key>`
     *         when the key is not in {@see self::DEEP_LINK_KEYS} for the
     *         class or its value does not match the key's kind
     */
    public function deepLinkUrl(): string
    {
        $url = 'index.php?class=' . $this->deepLinkClass;

        foreach ($this->deepLinkParams as $key => $value) {
            $key = (string) $key;
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1) {
                throw new InvalidArgumentException('Invalid deep-link parameter key');
            }
            if (
                !in_array($key, self::DEEP_LINK_KEYS[$this->deepLinkClass], true)
                || !self::isAllowedValue(self::DEEP_LINK_VALUE_KINDS[$key], $value)
            ) {
                throw new InvalidArgumentException('Invalid deep-link parameter ' . $key);
            }
            $url .= '&' . $key . '=' . $value;
        }

        return $url;
    }

    private static function isAllowedValue(string $kind, mixed $value): bool
    {
        return match ($kind) {
            self::VALUE_ID => (is_int($value) && $value > 0)
                || (is_string($value) && preg_match('/^[1-9][0-9]{0,18}$/', $value) === 1),
            self::VALUE_TAB => is_string($value) && in_array($value, self::DEEP_LINK_TABS, true),
            self::VALUE_DATE => is_string($value) && self::isDate($value),
            default => false,
        };
    }

    private static function isDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}

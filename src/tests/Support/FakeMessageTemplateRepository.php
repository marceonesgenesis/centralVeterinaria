<?php

declare(strict_types=1);

namespace CentralVet\Tests\Support;

use CentralVet\Domain\Contract\MessageTemplateRepositoryInterface;
use CentralVet\Domain\MessageTemplate;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * In-memory double for MessageTemplateRepositoryInterface (T-06). Stores rows
 * keyed like `message_template`; reads return fresh reconstitute() copies.
 */
final class FakeMessageTemplateRepository implements MessageTemplateRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $rows = [];
    private int $nextId = 1;

    public function __construct(private readonly int $tenantId, MessageTemplate ...$seed)
    {
        foreach ($seed as $template) {
            $this->store($template);
        }
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function findById(int|string $id): ?object
    {
        $row = $this->rows[(int) $id] ?? null;

        return $row !== null && $row['tenant_id'] === $this->tenantId ? MessageTemplate::reconstitute($row) : null;
    }

    public function listAll(): array
    {
        $rows = $this->ownRows();
        usort($rows, static fn (array $a, array $b): int => [$a['purpose'], $a['channel'], $a['name'], $a['id']] <=> [$b['purpose'], $b['channel'], $b['name'], $b['id']]);

        return array_map(static fn (array $row): MessageTemplate => MessageTemplate::reconstitute($row), $rows);
    }

    public function findActiveFor(string $purpose, string $channel): ?MessageTemplate
    {
        foreach ($this->ownRows() as $row) {
            if ($row['purpose'] === $purpose && $row['channel'] === $channel && $row['status'] === MessageTemplate::STATUS_ACTIVE) {
                return MessageTemplate::reconstitute($row);
            }
        }

        return null;
    }

    public function countActiveFor(string $purpose, string $channel, ?int $exceptTemplateId): int
    {
        return count(array_filter(
            $this->ownRows(),
            static fn (array $row): bool => $row['purpose'] === $purpose
                && $row['channel'] === $channel
                && $row['status'] === MessageTemplate::STATUS_ACTIVE
                && $row['id'] !== $exceptTemplateId,
        ));
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof MessageTemplate) {
            throw new InvalidArgumentException('FakeMessageTemplateRepository only stores MessageTemplate entities');
        }

        if ($entity->tenantId() !== $this->tenantId) {
            throw new InvalidArgumentException('Message template belongs to another tenant');
        }

        $this->store($entity);

        return $entity;
    }

    public function remove(object $entity): void
    {
        if ($entity instanceof MessageTemplate && $entity->id() !== null && $this->findById($entity->id()) !== null) {
            unset($this->rows[$entity->id()]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function ownRows(): array
    {
        ksort($this->rows);

        return array_values(array_filter($this->rows, fn (array $row): bool => $row['tenant_id'] === $this->tenantId));
    }

    private function store(MessageTemplate $t): void
    {
        if ($t->id() === null) {
            $t->assignId($this->nextId++);
        } else {
            $this->nextId = max($this->nextId, $t->id() + 1);
        }

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s.u');
        $this->rows[(int) $t->id()] = [
            'id' => $t->id(),
            'tenant_id' => $t->tenantId(),
            'purpose' => $t->purpose(),
            'channel' => $t->channel(),
            'name' => $t->name(),
            'subject' => $t->subject(),
            'body_text' => $t->bodyText(),
            'status' => $t->status(),
            'created_by_system_user_id' => $t->createdBySystemUserId(),
            'updated_by_system_user_id' => $t->updatedBySystemUserId(),
            'created_at' => $this->rows[(int) $t->id()]['created_at'] ?? $t->createdAt()?->format('Y-m-d H:i:s.u') ?? $now,
            'updated_at' => $now,
        ];
    }
}

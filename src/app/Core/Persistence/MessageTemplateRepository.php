<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Domain\Contract\MessageTemplateRepositoryInterface;
use CentralVet\Domain\MessageTemplate;
use CentralVet\Tenancy\TenantContext;
use InvalidArgumentException;
use PDO;

/**
 * PDO-backed persistence for message templates (`message_template`,
 * migration 20261006_0012_phase7a_communication). Every query starts from
 * TenantQuery::forTenant() (ADR 0002). "One active template per purpose and
 * channel" is the service's rule, answered by countActiveFor().
 *
 * @implements MessageTemplateRepositoryInterface<MessageTemplate>
 */
final class MessageTemplateRepository extends AbstractTenantRepository implements MessageTemplateRepositoryInterface
{
    public function __construct(TenantContext $context, private readonly PDO $connection)
    {
        parent::__construct($context);
    }

    public function findById(int|string $id): ?object
    {
        $query = $this->tenantQuery()->andEquals('id', (int) $id);

        $statement = $this->connection->prepare("SELECT * FROM message_template WHERE {$query->whereSql()} LIMIT 1");
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : MessageTemplate::reconstitute($row);
    }

    public function listAll(): array
    {
        $query = $this->tenantQuery();

        $statement = $this->connection->prepare(
            "SELECT * FROM message_template WHERE {$query->whereSql()} ORDER BY purpose ASC, channel ASC, name ASC, id ASC",
        );
        $statement->execute($query->parameters());

        return array_map(
            static fn (array $row): MessageTemplate => MessageTemplate::reconstitute($row),
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function findActiveFor(string $purpose, string $channel): ?MessageTemplate
    {
        $query = $this->activeQuery($purpose, $channel);

        $statement = $this->connection->prepare(
            "SELECT * FROM message_template WHERE {$query->whereSql()} ORDER BY id ASC LIMIT 1",
        );
        $statement->execute($query->parameters());

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : MessageTemplate::reconstitute($row);
    }

    public function countActiveFor(string $purpose, string $channel, ?int $exceptTemplateId): int
    {
        $query = $this->activeQuery($purpose, $channel);
        $parameters = $query->parameters();
        $except = '';

        if ($exceptTemplateId !== null) {
            $except = ' AND id <> :except_id';
            $parameters[':except_id'] = $exceptTemplateId;
        }

        $statement = $this->connection->prepare("SELECT COUNT(*) FROM message_template WHERE {$query->whereSql()}{$except}");
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    public function save(object $entity): object
    {
        if (!$entity instanceof MessageTemplate) {
            throw new InvalidArgumentException('Expected a MessageTemplate entity');
        }

        $this->assertEntityTenant($entity->tenantId());

        if ($entity->id() === null) {
            $this->insert($entity);

            return $entity;
        }

        $query = $this->tenantQuery()->andEquals('id', $entity->id());

        $statement = $this->connection->prepare(
            <<<SQL
            UPDATE message_template SET
                purpose = :purpose,
                channel = :channel,
                name = :name,
                subject = :subject,
                body_text = :body_text,
                status = :status,
                updated_by_system_user_id = :updated_by_system_user_id
            WHERE {$query->whereSql()}
            SQL
        );
        $statement->execute([
            ...$query->parameters(),
            ':purpose' => $entity->purpose(),
            ':channel' => $entity->channel(),
            ':name' => $entity->name(),
            ':subject' => $entity->subject(),
            ':body_text' => $entity->bodyText(),
            ':status' => $entity->status(),
            ':updated_by_system_user_id' => $entity->updatedBySystemUserId(),
        ]);

        return $entity;
    }

    public function remove(object $entity): void
    {
        if (!$entity instanceof MessageTemplate) {
            throw new InvalidArgumentException('Expected a MessageTemplate entity');
        }

        $id = $entity->id();

        if ($id === null) {
            return;
        }

        $this->assertEntityTenant($entity->tenantId());

        $query = $this->tenantQuery()->andEquals('id', $id);

        $statement = $this->connection->prepare("DELETE FROM message_template WHERE {$query->whereSql()}");
        $statement->execute($query->parameters());
    }

    private function activeQuery(string $purpose, string $channel): TenantQuery
    {
        return $this->tenantQuery()
            ->andEquals('purpose', $purpose)
            ->andEquals('channel', $channel)
            ->andEquals('status', MessageTemplate::STATUS_ACTIVE);
    }

    private function insert(MessageTemplate $entity): void
    {
        $statement = $this->connection->prepare(
            <<<'SQL'
            INSERT INTO message_template (
                tenant_id, purpose, channel, name, subject, body_text, status,
                created_by_system_user_id, updated_by_system_user_id
            ) VALUES (
                :tenant_id, :purpose, :channel, :name, :subject, :body_text, :status,
                :created_by_system_user_id, :updated_by_system_user_id
            )
            SQL
        );
        $statement->execute([
            ':tenant_id' => $entity->tenantId(),
            ':purpose' => $entity->purpose(),
            ':channel' => $entity->channel(),
            ':name' => $entity->name(),
            ':subject' => $entity->subject(),
            ':body_text' => $entity->bodyText(),
            ':status' => $entity->status(),
            ':created_by_system_user_id' => $entity->createdBySystemUserId(),
            ':updated_by_system_user_id' => $entity->updatedBySystemUserId(),
        ]);

        $entity->assignId((int) $this->connection->lastInsertId());
    }
}

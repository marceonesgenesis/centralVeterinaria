<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Landing\Contract\LeadStoreInterface;
use CentralVet\Landing\LeadSubmission;
use PDO;

/**
 * Leads da landing pública (`landing_lead`, migration 0009). Tabela de
 * plataforma, sem tenant: pré-venda do operador, anterior a qualquer
 * clínica. Por isso recebe só PDO e não usa TenantContext.
 *
 * Datas são gravadas como a string `Y-m-d H:i:s.u` do DateTimeImmutable
 * recebido (fuso de APP_TIMEZONE); `created_at` recebe o mesmo instante do
 * consentimento para que o filtro por período, montado no mesmo fuso, case.
 */
final class LeadRepository implements LeadStoreInterface
{
    private const MAX_LIMIT = 500;

    private const COLUMNS = 'id, created_at, name, clinic_name, email, phone, vets_range, city, uf, '
        . 'plan_id, plan_name, plan_price_cents, consent_version, consent_at, consent_ip';

    public function __construct(private readonly PDO $connection)
    {
    }

    public function insert(LeadSubmission $lead, string $consentIp, \DateTimeImmutable $consentAt): int
    {
        $at = $consentAt->format('Y-m-d H:i:s.u');

        $statement = $this->connection->prepare(
            'INSERT INTO landing_lead (name, clinic_name, email, phone, vets_range, city, uf, plan_id, plan_name, '
            . 'plan_price_cents, consent_version, consent_at, consent_ip, created_at) VALUES (:name, :clinic_name, '
            . ':email, :phone, :vets_range, :city, :uf, :plan_id, :plan_name, :plan_price_cents, :consent_version, '
            . ':consent_at, :consent_ip, :created_at)'
        );
        $statement->bindValue(':name', $lead->name);
        $statement->bindValue(':clinic_name', $lead->clinic);
        $statement->bindValue(':email', $lead->email);
        $statement->bindValue(':phone', $lead->phone);
        $statement->bindValue(':vets_range', $lead->vets);
        $statement->bindValue(':city', $lead->city);
        $statement->bindValue(':uf', $lead->uf);
        $statement->bindValue(':plan_id', $lead->planId);
        $statement->bindValue(':plan_name', $lead->planName);
        $statement->bindValue(':plan_price_cents', $lead->planPriceCents, PDO::PARAM_INT);
        $statement->bindValue(':consent_version', $lead->consentVersion);
        $statement->bindValue(':consent_at', $at);
        $statement->bindValue(':consent_ip', $consentIp);
        $statement->bindValue(':created_at', $at);
        $statement->execute();

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(
        ?string $planId,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        int $limit,
        int $offset,
    ): array {
        [$where, $params] = $this->filters($planId, $from, $to);
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $offset = max(0, $offset);

        $statement = $this->connection->prepare(
            'SELECT ' . self::COLUMNS . ' FROM landing_lead' . $where . ' ORDER BY id DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['id'] = (int) $row['id'];
            $row['plan_price_cents'] = (int) $row['plan_price_cents'];
            $rows[] = $row;
        }

        return $rows;
    }

    public function count(?string $planId, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): int
    {
        [$where, $params] = $this->filters($planId, $from, $to);

        $statement = $this->connection->prepare('SELECT COUNT(*) FROM landing_lead' . $where);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function filters(?string $planId, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        $conditions = [];
        $params = [];

        if ($planId !== null) {
            $conditions[] = 'plan_id = :plan_id';
            $params[':plan_id'] = $planId;
        }
        if ($from !== null) {
            $conditions[] = 'created_at >= :from';
            $params[':from'] = $from->format('Y-m-d') . ' 00:00:00';
        }
        if ($to !== null) {
            $conditions[] = 'created_at < :to';
            $params[':to'] = $to->setTime(0, 0)->modify('+1 day')->format('Y-m-d H:i:s');
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }
}

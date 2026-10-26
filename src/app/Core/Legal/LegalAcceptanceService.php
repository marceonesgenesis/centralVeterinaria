<?php

declare(strict_types=1);

namespace CentralVet\Legal;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * Aceite versionado dos Termos de Uso e da Política de Privacidade (Etapa 5 T-10).
 *
 * Uma linha em `legal_acceptance` por usuário, documento e versão aceita, com o
 * tenant, a hora, o IP e o user agent como evidência do consentimento (LGPD).
 * Só a versão vigente em `LegalDocuments::currentVersions()` conta: versão
 * antiga registrada fica como histórico e o documento volta a ficar pendente.
 * Usuário sem vínculo em `tenant_user` (administrador global) não tem pendência.
 */
final class LegalAcceptanceService
{
    private const IP_MAX_LENGTH = 45;
    private const USER_AGENT_MAX_LENGTH = 255;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Documentos cuja versão vigente o usuário ainda não aceitou, na ordem
     * `LegalDocuments::TERMS`, `LegalDocuments::PRIVACY`.
     *
     * @return list<string>
     */
    public function pendingDocuments(int $userId): array
    {
        if ($this->tenantOf($userId) === null) {
            return [];
        }

        $statement = $this->pdo->prepare(
            'SELECT document, version FROM legal_acceptance WHERE system_user_id = ?',
        );
        $statement->execute([$userId]);

        $accepted = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $accepted[$row['document'] . "\0" . $row['version']] = true;
        }

        $pending = [];
        foreach (LegalDocuments::currentVersions() as $document => $version) {
            if (!isset($accepted[$document . "\0" . $version])) {
                $pending[] = $document;
            }
        }

        return $pending;
    }

    /**
     * Registra o aceite da versão vigente de cada documento pendente. Idempotente
     * pelo índice único `legal_acceptance_user_doc_version_uq`.
     *
     * @return int quantas linhas foram inseridas
     * @throws InvalidArgumentException usuário sem vínculo em `tenant_user`
     */
    public function accept(int $userId, string $ip, ?string $userAgent, DateTimeImmutable $now): int
    {
        $tenantId = $this->tenantOf($userId);
        if ($tenantId === null) {
            throw new InvalidArgumentException('User has no tenant membership to record the legal acceptance');
        }

        $versions = LegalDocuments::currentVersions();
        $insert = $this->pdo->prepare(
            'INSERT INTO legal_acceptance (tenant_id, system_user_id, document, version, accepted_at, ip, user_agent)
             VALUES (:tenant_id, :user_id, :document, :version, :accepted_at, :ip, :user_agent)
             ON DUPLICATE KEY UPDATE id = id',
        );

        $inserted = 0;
        foreach ($this->pendingDocuments($userId) as $document) {
            $insert->execute([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'document' => $document,
                'version' => $versions[$document],
                'accepted_at' => $now->format('Y-m-d H:i:s.u'),
                'ip' => mb_substr($ip, 0, self::IP_MAX_LENGTH, 'UTF-8'),
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, self::USER_AGENT_MAX_LENGTH, 'UTF-8'),
            ]);
            $inserted += $insert->rowCount() === 1 ? 1 : 0;
        }

        return $inserted;
    }

    private function tenantOf(int $userId): ?int
    {
        $statement = $this->pdo->prepare('SELECT MIN(tenant_id) FROM tenant_user WHERE system_user_id = ?');
        $statement->execute([$userId]);
        $tenantId = $statement->fetchColumn();

        return $tenantId === null || $tenantId === false ? null : (int) $tenantId;
    }
}

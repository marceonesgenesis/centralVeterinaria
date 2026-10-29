<?php

declare(strict_types=1);

namespace CentralVet\Backoffice;

use PDO;
use CentralVet\Persistence\PdoConnectionFactory;

/**
 * Gestão centralizada dos horários de suporte e mensagens de atendimento.
 * Alimenta a landing page, tela de suporte e mensagens fora do expediente.
 */
class BackofficeSupportService
{
    private PDO $pdo;
    private BackofficeAuditService $auditService;

    public function __construct(?PDO $pdo = null, ?BackofficeAuditService $auditService = null)
    {
        $this->pdo = $pdo ?? PdoConnectionFactory::create();
        $this->auditService = $auditService ?? new BackofficeAuditService($this->pdo);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSchedule(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM backoffice_support_schedule ORDER BY day_of_week ASC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM backoffice_support_config ORDER BY id ASC LIMIT 1');
        $cfg = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$cfg) {
            return [
                'support_email' => 'suporte@centralvet.com.br',
                'support_whatsapp' => '+55 (11) 98765-4321',
                'out_of_hours_message' => 'Nosso horário de atendimento é de segunda a sexta, das 8h às 18h.',
            ];
        }
        return $cfg;
    }

    /**
     * Atualiza o horário de um dia específico
     */
    public function updateDaySchedule(int $dayOfWeek, bool $isOpen, ?string $openTime, ?string $closeTime, bool $isOnCall, ?string $notes): bool
    {
        $stmt = $this->pdo->prepare('SELECT * FROM backoffice_support_schedule WHERE day_of_week = :day LIMIT 1');
        $stmt->execute([':day' => $dayOfWeek]);
        $before = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $update = $this->pdo->prepare(
            'UPDATE backoffice_support_schedule 
             SET is_open = :is_open, open_time = :open_time, close_time = :close_time, is_on_call = :is_on_call, notes = :notes, updated_at = NOW(6)
             WHERE day_of_week = :day'
        );
        $update->execute([
            ':is_open' => $isOpen ? 1 : 0,
            ':open_time' => $openTime,
            ':close_time' => $closeTime,
            ':is_on_call' => $isOnCall ? 1 : 0,
            ':notes' => $notes,
            ':day' => $dayOfWeek,
        ]);

        $this->auditService->log(
            'support_schedule_update',
            'backoffice_support_schedule',
            (string)$dayOfWeek,
            $before,
            ['is_open' => $isOpen, 'open_time' => $openTime, 'close_time' => $closeTime, 'is_on_call' => $isOnCall]
        );

        return true;
    }

    /**
     * Atualiza canais e mensagem geral
     */
    public function updateConfig(string $email, string $whatsapp, string $outOfHoursMessage): bool
    {
        $stmt = $this->pdo->query('SELECT * FROM backoffice_support_config ORDER BY id ASC LIMIT 1');
        $before = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $update = $this->pdo->prepare(
            'UPDATE backoffice_support_config 
             SET support_email = :email, support_whatsapp = :whatsapp, out_of_hours_message = :message, updated_at = NOW(6)
             WHERE id = 1'
        );
        $update->execute([
            ':email' => $email,
            ':whatsapp' => $whatsapp,
            ':message' => $outOfHoursMessage,
        ]);

        $this->auditService->log(
            'support_config_update',
            'backoffice_support_config',
            '1',
            $before,
            ['support_email' => $email, 'support_whatsapp' => $whatsapp, 'out_of_hours_message' => $outOfHoursMessage]
        );

        return true;
    }
}


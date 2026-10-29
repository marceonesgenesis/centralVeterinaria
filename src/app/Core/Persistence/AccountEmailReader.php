<?php

declare(strict_types=1);

namespace CentralVet\Persistence;

use CentralVet\Billing\Contract\AccountEmailReaderInterface;
use PDO;

/**
 * O e-mail já é login ou já pagou outro pedido. A comparação usa a collation
 * das colunas (`*_ci`: sem diferença de caixa); `refunded`, `expired`,
 * `pending` e `checkout_open` não tomam o e-mail. Erro de banco propaga.
 */
final class AccountEmailReader implements AccountEmailReaderInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function isTaken(string $email): bool
    {
        $email = trim($email);
        if ($email === '') {
            return false;
        }

        $statement = $this->pdo->prepare(
            "SELECT EXISTS (SELECT 1 FROM system_users WHERE login = :login)
                 OR EXISTS (
                     SELECT 1 FROM billing_order
                      WHERE responsible_email = :email
                        AND status IN ('paid', 'provisioned', 'provisioning_failed')
                 )",
        );
        $statement->execute(['login' => $email, 'email' => $email]);

        return (bool) $statement->fetchColumn();
    }
}

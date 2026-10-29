<?php

declare(strict_types=1);

namespace CentralVet\Billing\Contract;

/** E-mail já em uso para o checkout (CheckoutStartHandler, T-27). */
interface AccountEmailReaderInterface
{
    /**
     * true quando o e-mail, com trim e sem diferença de caixa, é login em
     * `system_users` ou `responsible_email` de pedido paid, provisioned ou
     * provisioning_failed. E-mail vazio → false sem consulta.
     */
    public function isTaken(string $email): bool;
}

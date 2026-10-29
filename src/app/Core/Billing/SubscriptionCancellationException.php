<?php

declare(strict_types=1);

namespace CentralVet\Billing;

/**
 * Cancelamento pelo cliente recusado (T-08). A mensagem é a chave em inglês
 * de `translations.json`, traduzida por `_t()` na tela.
 */
final class SubscriptionCancellationException extends \DomainException
{
}

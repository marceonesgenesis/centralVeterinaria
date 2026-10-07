<?php

declare(strict_types=1);

namespace CentralVet\Landing;

/**
 * Todos os erros de um lead de uma vez: campo do payload → mensagem pt.
 */
final class LeadValidationException extends \DomainException
{
    /** @param array<string, string> $errors */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Invalid lead: ' . implode(', ', array_keys($errors)));
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }
}

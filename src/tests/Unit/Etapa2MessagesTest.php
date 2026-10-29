<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Presentation\UserMessage;
use CentralVet\Tests\Support\Assert;

/**
 * Etapa 2 (dinheiro e estoque), T-01: textos pt-BR das mensagens e rótulos
 * novos (idempotência, caixa, recebível, cancelamento da venda) e o
 * mapeamento das mensagens de domínio em UserMessage.
 */
final class Etapa2MessagesTest
{
    private const KEYS = [
        'This submission was already processed' => 'Este envio já foi processado; nada foi gravado novamente.',
        'Invalid submission token' => 'Envio inválido. Recarregue a tela e tente novamente.',
        'Open a cash session for this unit before receiving payment' => 'Abra o caixa desta unidade antes de receber o pagamento.',
        'Open a cash session for this unit before reversing payments' => 'Abra o caixa desta unidade antes de estornar recebimentos.',
        'This receivable is cancelled and cannot receive payments' => 'Este recebível está cancelado e não aceita recebimentos.',
        'A cancellation reason is required' => 'Informe o motivo do cancelamento.',
        'The cancellation reason must have at most 500 characters' => 'O motivo do cancelamento aceita no máximo 500 caracteres.',
        'This sale was already cancelled' => 'Esta venda já estava cancelada; nada foi alterado.',
        'Sale cancelled successfully' => 'Venda cancelada com sucesso.',
        'You are not allowed to cancel sales for this unit' => 'Você não tem permissão para cancelar vendas desta unidade.',
        'Stock changed during the sale; review the cart and try again' => 'O estoque mudou durante a venda; revise o carrinho e tente novamente.',
        'On account (open receivable)' => 'A prazo (recebível em aberto)',
        'Amount received' => 'Valor recebido',
        'Amount due' => 'Saldo a receber',
        'Cancelled sale' => 'Venda cancelada',
        'Sale history' => 'Histórico de vendas',
        'Cancel sale' => 'Cancelar venda',
        'Financial status' => 'Situação financeira',
        'No payment received' => 'Nenhum recebimento registrado',
        'Sale #^1' => 'Venda #^1',
        'Encounter account #^1' => 'Conta de atendimento #^1',
        'Cancelled by ^1 on ^2' => 'Cancelada por ^1 em ^2',
    ];

    private const DOMAIN = [
        'This submission was already processed',
        'Invalid submission token',
        'Open a cash session for this unit before receiving payment',
        'Open a cash session for this unit before reversing payments',
        'This receivable is cancelled and cannot receive payments',
        'A cancellation reason is required',
        'The cancellation reason must have at most 500 characters',
    ];

    /** Chaves já existentes que a Etapa 2 reutiliza e não pode duplicar. */
    private const REUSED = ['Payment method', 'Origin', 'Cancellation reason', 'Status', 'Balance due', 'Paid'];

    public function testEveryEtapa2KeyHasTheExactPortugueseText(): void
    {
        $translations = [];
        foreach (self::decoded() as $entry) {
            $translations[$entry['en']] = $entry['pt'];
        }

        foreach (self::KEYS as $en => $pt) {
            Assert::true(isset($translations[$en]), "Missing translation for Etapa 2 key: {$en}");
            Assert::same($pt, $translations[$en], "Wrong pt for Etapa 2 key: {$en}");
        }
    }

    public function testNoEnglishKeyAppearsTwice(): void
    {
        $counts = array_count_values(array_column(self::decoded(), 'en'));
        $duplicated = array_keys(array_filter($counts, static fn (int $n): bool => $n > 1));
        Assert::same([], $duplicated, 'Duplicated en keys: ' . implode(', ', $duplicated));

        foreach (self::REUSED as $key) {
            Assert::same(1, $counts[$key] ?? 0, "Reused key must exist exactly once: {$key}");
        }
    }

    public function testDomainMessagesResolveToThemselves(): void
    {
        foreach (self::DOMAIN as $message) {
            Assert::same(['key' => $message, 'params' => []], UserMessage::resolve($message), "UserMessage must map: {$message}");
        }
    }

    /** @return list<array{en: string, pt: string}> */
    private static function decoded(): array
    {
        $decoded = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/app/config/translations.json'), true);
        Assert::true(is_array($decoded), 'translations.json must decode to a list');

        return $decoded;
    }
}

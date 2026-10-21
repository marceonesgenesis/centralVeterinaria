<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Presentation\UserMessage;
use CentralVet\Tests\Support\Assert;

/**
 * Etapa 3 (contratação), T-01: textos pt-BR de cobrança, assinatura, quotas,
 * convite e fatura Pix, e o mapeamento das mensagens de domínio em UserMessage.
 */
final class Etapa3MessagesTest
{
    private const KEYS = [
        'Sale not found' => 'Venda não encontrada',
        'Unknown payment method "^1"' => 'Forma de pagamento desconhecida: "^1"',
        'Your plan does not include this feature' => 'Seu plano não inclui este recurso',
        'Your subscription is suspended. Regularize the payment to continue' => 'Sua assinatura está suspensa. Regularize o pagamento para continuar',
        'Your plan allows up to ^1 users' => 'Seu plano permite até ^1 usuários',
        'Your plan allows up to ^1 units' => 'Seu plano permite até ^1 unidades',
        'Central Vet Pro invitation' => 'Convite do Central Vet Pro',
        'Your clinic ^1 is ready. Set your password to access Central Vet Pro: ^2' => 'Sua clínica ^1 está pronta. Defina sua senha para acessar o Central Vet Pro: ^2',
        'This link expires in ^1 hours' => 'Este link expira em ^1 horas',
        'Subscription' => 'Assinatura',
        'Plan' => 'Plano',
        'Current period' => 'Período atual',
        'Grace period until' => 'Tolerância até',
        'Invoices' => 'Faturas',
        'Pay invoice' => 'Pagar fatura',
        'Amount paid' => 'Valor pago',
        'Refunded' => 'Estornado',
        'Suspended' => 'Suspensa',
        'Incomplete' => 'Incompleta',
        'No subscription registered for this clinic' => 'Esta clínica não tem assinatura registrada',
        'Central Vet Pro invoice' => 'Fatura do Central Vet Pro',
        'Your Central Vet Pro invoice of ^1 is available. Pay with Pix by ^2: ^3' => 'Sua fatura do Central Vet Pro de ^1 está disponível. Pague com Pix até ^2: ^3',
    ];

    /**
     * Chaves já existentes reutilizadas sem duplicar: o pt de antes é mantido
     * ("Amount due" é contrato do Etapa2MessagesTest; "Overdue" já aparece na PendingCenter).
     */
    private const REUSED = ['Plan', 'Suspended', 'Amount due', 'Overdue'];

    private const STATIC_DOMAIN = [
        'Your plan does not include this feature',
        'Your subscription is suspended. Regularize the payment to continue',
        'No subscription registered for this clinic',
    ];

    public function testEveryEtapa3KeyHasTheExactPortugueseText(): void
    {
        $translations = [];
        foreach (self::decoded() as $entry) {
            $translations[$entry['en']] = $entry['pt'];
        }

        foreach (self::KEYS as $en => $pt) {
            Assert::true(isset($translations[$en]), "Missing translation for Etapa 3 key: {$en}");
            Assert::same($pt, $translations[$en], "Wrong pt for Etapa 3 key: {$en}");
        }
    }

    public function testPlaceholdersAreOnlyCaretOneToFour(): void
    {
        foreach (self::KEYS as $en => $pt) {
            preg_match_all('/\^(\d+)/', $en . ' ' . $pt, $matches);
            foreach ($matches[1] as $n) {
                Assert::true((int) $n >= 1 && (int) $n <= 4, "Placeholder ^{$n} out of ^1..^4 in: {$en}");
            }
            Assert::true(!str_contains($en . $pt, '%s'), "Use ^N, not %s, in: {$en}");
            preg_match_all('/\^\d+/', $en, $enParams);
            preg_match_all('/\^\d+/', $pt, $ptParams);
            $a = $enParams[0];
            $b = $ptParams[0];
            sort($a);
            sort($b);
            Assert::same($a, $b, "pt must use the same placeholders as en: {$en}");
        }
    }

    public function testReusedKeysExistExactlyOnceAndListHasNoDuplicates(): void
    {
        $counts = array_count_values(array_column(self::decoded(), 'en'));
        $duplicated = array_keys(array_filter($counts, static fn (int $n): bool => $n > 1));
        Assert::same([], $duplicated, 'Duplicated en keys: ' . implode(', ', $duplicated));

        foreach (self::REUSED as $key) {
            Assert::same(1, $counts[$key] ?? 0, "Reused key must exist exactly once: {$key}");
        }
    }

    public function testListIsSortedByEnglishAndHasOnlyEnPt(): void
    {
        $entries = self::decoded();
        foreach ($entries as $i => $entry) {
            $keys = array_keys($entry);
            sort($keys);
            Assert::same(['en', 'pt'], $keys, "Entry {$i} must have only en/pt");
        }
        for ($i = 1, $n = count($entries); $i < $n; $i++) {
            Assert::true(
                strcasecmp($entries[$i - 1]['en'], $entries[$i]['en']) <= 0,
                "translations.json out of order at {$i}: \"{$entries[$i - 1]['en']}\" before \"{$entries[$i]['en']}\""
            );
        }
    }

    public function testSaleNotFoundIsMapped(): void
    {
        Assert::same(['key' => 'Sale not found', 'params' => []], UserMessage::resolve('Sale 42 not found'));
    }

    public function testUnknownPaymentMethodIsMappedWithSpaceOrUnderscore(): void
    {
        $expected = ['key' => 'Unknown payment method "^1"', 'params' => ['x']];
        Assert::same($expected, UserMessage::resolve('Unknown payment_method "x"'));
        Assert::same($expected, UserMessage::resolve('Unknown payment method "x"'));
    }

    public function testQuotaMessagesAreMapped(): void
    {
        Assert::same(['key' => 'Your plan allows up to ^1 users', 'params' => ['2']], UserMessage::resolve('Your plan allows up to 2 users'));
        Assert::same(['key' => 'Your plan allows up to ^1 units', 'params' => ['3']], UserMessage::resolve('Your plan allows up to 3 units'));
    }

    public function testStaticDomainMessagesResolveToThemselves(): void
    {
        foreach (self::STATIC_DOMAIN as $message) {
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

<?php

declare(strict_types=1);

namespace CentralVet\Tests\Unit;

use CentralVet\Presentation\UserMessage;
use CentralVet\Tests\Support\Assert;

/**
 * Etapa 5 (piloto e lançamento), T-02: textos pt-BR de guarda da oferta,
 * cancelamento, onboarding, documentos legais, suporte e exportação, e o
 * mapeamento das mensagens de domínio em UserMessage.
 */
final class Etapa5MessagesTest
{
    private const KEYS = [
        // Guarda/oferta.
        'This module is not available in the pilot yet' => 'Este módulo ainda não está disponível no piloto',
        // Cancelamento.
        'Cancel subscription' => 'Cancelar assinatura',
        'Confirm cancellation' => 'Confirmar cancelamento',
        'Cancellation scheduled for ^1' => 'Cancelamento agendado para ^1',
        'Your subscription remains active until ^1. After that, the clinic data stays available for export for ^2 days and is then deleted' => 'Sua assinatura continua ativa até o fim do período pago, em ^1. Depois disso, os dados da clínica ficam disponíveis para exportação por ^2 dias e então são excluídos',
        'Subscription cancellation requested' => 'Cancelamento da assinatura solicitado',
        'The subscription is already scheduled for cancellation' => 'A assinatura já está com cancelamento agendado',
        'No active subscription to cancel' => 'Não há assinatura ativa para cancelar',
        'The payment provider did not confirm the cancellation. Try again in a few minutes' => 'O provedor de pagamento não confirmou o cancelamento. Tente de novo em alguns minutos',
        // Onboarding.
        'First steps' => 'Primeiros passos',
        'Clinic data' => 'Dados da clínica',
        'Services and prices' => 'Serviços e preços',
        'First encounter guide' => 'Orientações do primeiro atendimento',
        'Mark as read' => 'Marcar como lida',
        'I work alone' => 'Trabalho sozinho',
        'Legal name' => 'Razão social',
        'Trade name' => 'Nome fantasia',
        'Unit name' => 'Nome da unidade',
        'Initial setup completed' => 'Configuração inicial concluída',
        'Clinic data saved' => 'Dados da clínica salvos',
        'Enter the clinic legal name' => 'Informe a razão social da clínica',
        'Enter the unit name' => 'Informe o nome da unidade',
        // Legais.
        'Privacy policy' => 'Política de privacidade',
        'I have read and accept the Terms of Use and the Privacy Policy' => 'Li e aceito os Termos de Uso e a Política de Privacidade',
        'Accept the Terms of Use and the Privacy Policy to continue' => 'Aceite os Termos de Uso e a Política de Privacidade para continuar',
        // Suporte e exportação.
        'Support' => 'Suporte',
        'Support e-mail' => 'E-mail do suporte',
        'Service hours' => 'Horário de atendimento',
        'Support channel not configured' => 'Canal de suporte não configurado',
        'Export clinic data' => 'Exportar dados da clínica',
        'The file contains the clinic records in CSV format, one file per table' => 'O arquivo traz os registros da clínica em CSV, um arquivo por tabela',
        'Cancellation policy' => 'Política de cancelamento',
    ];

    /** Chaves já existentes reutilizadas sem duplicar: o pt de antes é mantido. */
    private const REUSED = ['Team', 'Pending', 'Done', 'Terms of use', 'Help'];

    private const STATIC_DOMAIN = [
        'No active subscription to cancel',
        'The payment provider did not confirm the cancellation. Try again in a few minutes',
        'The subscription is already scheduled for cancellation',
        'Enter the clinic legal name',
        'Enter the unit name',
        'This module is not available in the pilot yet',
    ];

    public function testEveryEtapa5KeyHasTheExactPortugueseText(): void
    {
        $translations = [];
        foreach (self::decoded() as $entry) {
            $translations[$entry['en']] = $entry['pt'];
        }

        foreach (self::KEYS as $en => $pt) {
            Assert::true(isset($translations[$en]), "Missing translation for Etapa 5 key: {$en}");
            Assert::same($pt, $translations[$en], "Wrong pt for Etapa 5 key: {$en}");
        }
    }

    public function testPlaceholdersMatchBetweenEnglishAndPortuguese(): void
    {
        foreach (self::KEYS as $en => $pt) {
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

    public function testEtapa5AndReusedKeysExistExactlyOnce(): void
    {
        $counts = array_count_values(array_column(self::decoded(), 'en'));
        foreach (array_merge(array_keys(self::KEYS), self::REUSED) as $key) {
            Assert::same(1, $counts[$key] ?? 0, "Key must exist exactly once: {$key}");
        }
    }

    public function testReusedKeysAreNotRedefinedInThisContract(): void
    {
        foreach (self::REUSED as $key) {
            Assert::true(!isset(self::KEYS[$key]), "Reused key must keep its current pt: {$key}");
        }
    }

    public function testStaticDomainMessagesResolveToTranslatedKeys(): void
    {
        foreach (self::STATIC_DOMAIN as $message) {
            $resolved = UserMessage::resolve($message);
            Assert::true($resolved !== null, "UserMessage must map: {$message}");
            Assert::same(['key' => $message, 'params' => []], $resolved, "UserMessage must map to itself: {$message}");
            Assert::true(isset(self::KEYS[$message]), "Static domain message needs a pt text: {$message}");
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

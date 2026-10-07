<?php

declare(strict_types=1);

namespace CentralVet\Landing;

/**
 * Fonte única de planos, preços (centavos), opções do formulário e texto do
 * consentimento da landing pública. A página, o `lead.php` e a tela de leads
 * leem daqui; nenhum preço de plano existe fora desta classe.
 *
 * Mudar CONSENT_TEXT exige nova CONSENT_VERSION (prova do consentimento LGPD).
 */
final class LandingCatalog
{
    public const CONSENT_VERSION = 'lgpd-contato-2026-10';

    public const CONSENT_TEXT = 'Concordo que a equipe Central Vet Pro entre em contato sobre a assinatura, conforme a LGPD.';

    public const VETS_OPTIONS = [
        '1' => 'Só eu',
        '2-4' => '2 a 4',
        '5-10' => '5 a 10',
        '11+' => 'Mais de 10',
    ];

    public const UFS = [
        'AC', 'AL', 'AM', 'AP', 'BA', 'CE', 'DF', 'ES', 'GO',
        'MA', 'MG', 'MS', 'MT', 'PA', 'PB', 'PE', 'PI', 'PR',
        'RJ', 'RN', 'RO', 'RR', 'RS', 'SC', 'SE', 'SP', 'TO',
    ];

    private const PLAN_IDS = ['starter', 'pro', 'business', 'enterprise'];

    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
        | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

    private function __construct()
    {
    }

    /**
     * @return list<array{id: string, name: string, price_cents: int, for_who: string, featured: bool, items: list<array{label: string, soon: bool}>}>
     */
    public static function plans(): array
    {
        return [
            [
                'id' => 'starter',
                'name' => 'Starter',
                'price_cents' => 5700,
                'for_who' => 'Veterinário autônomo e consultório',
                'featured' => false,
                'items' => self::items([
                    '1 unidade',
                    'Tutores, pacientes e agenda',
                    'Central de Atendimento',
                    'Prescrição e documentos em PDF',
                    'Exames e vacinação',
                ]),
            ],
            [
                'id' => 'pro',
                'name' => 'Pro',
                'price_cents' => 9700,
                'for_who' => 'Clínica com equipe',
                'featured' => true,
                'items' => self::items([
                    'Tudo do Starter',
                    'Mais usuários na equipe',
                    'Estoque, PDV e caixa',
                    'Financeiro completo',
                    ['Comunicação e automações', true],
                ]),
            ],
            [
                'id' => 'business',
                'name' => 'Business',
                'price_cents' => 13700,
                'for_who' => 'Clínicas maiores',
                'featured' => false,
                'items' => self::items([
                    'Tudo do Pro',
                    'Várias unidades',
                    'Perfis de acesso por equipe',
                    ['Relatórios avançados', true],
                    ['Integrações', true],
                ]),
            ],
            [
                'id' => 'enterprise',
                'name' => 'Enterprise',
                'price_cents' => 19700,
                'for_who' => 'Redes e hospitais',
                'featured' => false,
                'items' => self::items([
                    'Tudo do Business',
                    'Governança da rede',
                    'Suporte dedicado',
                    ['IA e agentes avançados', true],
                    ['SSO e SLA', true],
                ]),
            ],
        ];
    }

    /**
     * @return array{id: string, name: string, price_cents: int, for_who: string, featured: bool, items: list<array{label: string, soon: bool}>}|null
     */
    public static function plan(string $id): ?array
    {
        foreach (self::plans() as $plan) {
            if ($plan['id'] === $id) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * @return list<array{label: string, plans: array<string, bool>, soon: bool}>
     */
    public static function compareRows(): array
    {
        // [recurso, starter, pro, business, enterprise, em breve]
        $rows = [
            ['Tutores, pacientes e agenda', 1, 1, 1, 1, false],
            ['Central de Atendimento', 1, 1, 1, 1, false],
            ['Prescrição, exames e vacinação', 1, 1, 1, 1, false],
            ['Estoque, PDV e caixa', 0, 1, 1, 1, false],
            ['Financeiro completo', 0, 1, 1, 1, false],
            ['Comunicação e automações', 0, 1, 1, 1, true],
            ['Várias unidades', 0, 0, 1, 1, false],
            ['Relatórios avançados', 0, 0, 1, 1, true],
            ['Integrações', 0, 0, 1, 1, true],
            ['IA e agentes avançados', 0, 0, 0, 1, true],
            ['Suporte dedicado', 0, 0, 0, 1, false],
        ];

        return array_map(static function (array $row): array {
            $plans = [];
            foreach (self::PLAN_IDS as $index => $id) {
                $plans[$id] = $row[$index + 1] === 1;
            }

            return ['label' => $row[0], 'plans' => $plans, 'soon' => $row[5]];
        }, $rows);
    }

    /** JSON para `<script type="application/json">`: `<`, `>`, `&`, aspas escapados. */
    public static function publicJson(): string
    {
        return json_encode([
            'plans' => self::plans(),
            'compare' => self::compareRows(),
            'vets' => self::VETS_OPTIONS,
            'ufs' => self::UFS,
            'consent' => ['version' => self::CONSENT_VERSION, 'text' => self::CONSENT_TEXT],
        ], self::JSON_FLAGS);
    }

    /**
     * @param list<string|array{0: string, 1: bool}> $items
     * @return list<array{label: string, soon: bool}>
     */
    private static function items(array $items): array
    {
        return array_map(
            static fn (string|array $item): array => is_array($item)
                ? ['label' => $item[0], 'soon' => $item[1]]
                : ['label' => $item, 'soon' => false],
            $items,
        );
    }
}

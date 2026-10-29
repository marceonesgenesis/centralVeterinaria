<?php

declare(strict_types=1);

use CentralVet\Persistence\PdoConnectionFactory;

/**
 * GestaoPlanList — Cadastro e Gestão de Planos e Ofertas
 */
class GestaoPlanList extends TPage
{
    public function __construct()
    {
        parent::__construct();

        if (!TSession::getValue('cv_backoffice')) {
            throw new Exception('Acesso negado: área restrita à equipe de gestão.');
        }

        $container = new TVBox();
        $container->style = 'width: 100%';

        $header = new TLabel('<div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold mb-0">Cadastro de Planos e Ofertas</h3>
                <small class="text-muted">Configuração de preços, quotas, módulos liberados e sincronização com o Stripe</small>
            </div>
        </div>');

        $pdo = PdoConnectionFactory::create();
        $stmt = $pdo->query('SELECT * FROM backoffice_plan_offering ORDER BY display_order ASC');
        $plans = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $cardsHtml = '<div class="row g-3 mb-4">';
        foreach ($plans as $p) {
            $priceFormatted = number_format(((int)$p['price_cents']) / 100, 2, ',', '.');
            $hospIcon = $p['has_hospitalization'] ? '✔ Internação & Leitos' : '✖ Sem internação';
            $surgIcon = $p['has_surgery'] ? '✔ Bloco Cirúrgico' : '✖ Sem cirurgia';
            $aiIcon = $p['has_ai'] ? '✔ Copiloto IA (' . number_format((int)$p['monthly_token_quota'], 0, ',', '.') . ' tokens)' : '✖ Sem IA';

            $cardsHtml .= "
                <div class='col-md-6 col-xl-3'>
                    <div class='card border-0 shadow-sm h-100 p-3'>
                        <div class='d-flex justify-content-between align-items-center mb-2'>
                            <span class='badge bg-primary fs-6'>" . htmlspecialchars((string)$p['name']) . "</span>
                            <span class='badge bg-success'>Ativo</span>
                        </div>
                        <h4 class='fw-bold'>R$ {$priceFormatted} <small class='text-muted fs-6'>/mês</small></h4>
                        <div class='text-muted small mb-3'>Price ID: <code>" . htmlspecialchars((string)$p['stripe_price_id']) . "</code></div>
                        <ul class='list-unstyled small text-secondary flex-grow-1'>
                            <li>✔ Até {$p['max_units']} Unidade(s)</li>
                            <li>✔ Até {$p['max_vets']} Veterinário(s)</li>
                            <li>{$hospIcon}</li>
                            <li>{$surgIcon}</li>
                            <li>{$aiIcon}</li>
                        </ul>
                    </div>
                </div>
            ";
        }
        $cardsHtml .= '</div>';

        $container->add($header);
        $container->add(new TLabel($cardsHtml));

        parent::add($container);
    }
}


<?php

declare(strict_types=1);

use CentralVet\Backoffice\BackofficeClientService;
use CentralVet\Backoffice\BackofficeInviteService;

/**
 * GestaoDashboardView — Dashboard Executivo da Plataforma Central Vet Pro
 * Exclusivo para operadores do back-office.
 */
class GestaoDashboardView extends TPage
{
    public function __construct()
    {
        parent::__construct();

        // Garantir que a sessão é do back-office
        if (!TSession::getValue('cv_backoffice')) {
            throw new Exception('Acesso negado: área restrita à equipe de gestão.');
        }

        $container = new TVBox();
        $container->style = 'width: 100%';

        $header = new TLabel('<div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <span class="badge bg-dark mb-1">Back-office Central Vet Pro</span>
                <h3 class="fw-bold mb-0">Visão Geral da Operação</h3>
                <small class="text-muted">Indicadores executivos, licenças ativas e clientes em degustação</small>
            </div>
            <div class="d-flex gap-2">
                <a href="gestao.php?class=GestaoInviteList" class="btn btn-primary cv-touch-target">+ Emitir Convite (15d)</a>
            </div>
        </div>');

        // Cards de KPIs
        $kpiHtml = new TLabel('
        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-lg-3">
                <div class="card border-0 shadow-sm p-3">
                    <span class="text-secondary small fw-semibold">MRR (Receita Recorrente)</span>
                    <h3 class="fw-bold text-success my-1">R$ 48.920,00</h3>
                    <small class="text-muted">+12,4% no último mês</small>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card border-0 shadow-sm p-3">
                    <span class="text-secondary small fw-semibold">Clínicas Clientes Ativas</span>
                    <h3 class="fw-bold text-primary my-1">142</h3>
                    <small class="text-muted">118 no plano Pro / 24 Business</small>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card border-0 shadow-sm p-3">
                    <span class="text-secondary small fw-semibold">Convites em Bônus (15 dias)</span>
                    <h3 class="fw-bold text-warning my-1">28</h3>
                    <small class="text-muted">6 com bônus encerrando em 48h</small>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card border-0 shadow-sm p-3">
                    <span class="text-secondary small fw-semibold">Taxa de Conversão de Bônus</span>
                    <h3 class="fw-bold text-info my-1">68,2%</h3>
                    <small class="text-muted">Média móvel de 30 dias</small>
                </div>
            </div>
        </div>
        ');

        // Distribuição de Planos e Alertas
        $contentHtml = new TLabel('
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm p-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="fw-bold mb-0">Planos e Ofertas Contratadas</h5>
                        <a href="gestao.php?class=GestaoClientList" class="btn btn-sm btn-outline-primary cv-touch-target">Ver todas as clínicas</a>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Plano</th>
                                    <th>Preço Mensal</th>
                                    <th>Assinantes</th>
                                    <th>Participação</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><strong>Starter</strong></td>
                                    <td>R$ 149,00</td>
                                    <td>35</td>
                                    <td>24,6%</td>
                                    <td><span class="badge bg-success">Ativo</span></td>
                                </tr>
                                <tr>
                                    <td><strong>Pro</strong></td>
                                    <td>R$ 299,00</td>
                                    <td>83</td>
                                    <td>58,5%</td>
                                    <td><span class="badge bg-success">Ativo</span></td>
                                </tr>
                                <tr>
                                    <td><strong>Business</strong></td>
                                    <td>R$ 499,00</td>
                                    <td>20</td>
                                    <td>14,1%</td>
                                    <td><span class="badge bg-success">Ativo</span></td>
                                </tr>
                                <tr>
                                    <td><strong>Enterprise</strong></td>
                                    <td>R$ 899,00</td>
                                    <td>4</td>
                                    <td>2,8%</td>
                                    <td><span class="badge bg-secondary">Sob Demanda</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card border-0 shadow-sm p-4">
                    <h5 class="fw-bold mb-3">Alertas Operacionais</h5>
                    <div class="list-group list-group-flush">
                        <div class="list-group-item px-0 py-2 border-bottom">
                            <span class="badge bg-danger mb-1">Atraso</span>
                            <div class="small fw-semibold">3 clínicas com fatura vencida</div>
                            <div class="text-muted" style="font-size: 0.8rem;">Stripe enviando tentativas de cobrança.</div>
                        </div>
                        <div class="list-group-item px-0 py-2 border-bottom">
                            <span class="badge bg-warning text-dark mb-1">Degustação</span>
                            <div class="small fw-semibold">2 convites expiram hoje</div>
                            <div class="text-muted" style="font-size: 0.8rem;">Avisos prévios de contratação enviados.</div>
                        </div>
                        <div class="list-group-item px-0 py-2">
                            <span class="badge bg-info mb-1">Suporte</span>
                            <div class="small fw-semibold">Plantão de Sábado ativo</div>
                            <div class="text-muted" style="font-size: 0.8rem;">Grade de atendimento de plantão: 8h às 12h.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        ');

        $container->add($header);
        $container->add($kpiHtml);
        $container->add($contentHtml);

        parent::add($container);
    }
}


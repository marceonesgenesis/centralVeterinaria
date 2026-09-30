# Eficiência — mar-20260923-1630-fidelidade-visual-mocks

- Plano: `/var/www/html/centralvet/.claude/tasks/mar-20260923-1630-fidelidade-visual-mocks` · projeto `/var/www/html/centralvet` · versão 2.5.1? · gerado em 2026-09-29T17:47:22-03:00 · última onda medida 6
- Sessões: 004a97f0-faff-49ba-997a-092d97b4dffc (encontrada), a4d85a4e-ac84-4bb6-b1c2-cdadb6248b2b (encontrada)
- Base: sem base (nenhum plano da versão anterior no acervo)

## Indicadores

| Indicador | Este plano | Base |
|---|---|---|
| Tokens retidos por onda (mediana) | 33.156 | sem base |
| Contexto máximo | 966.800 | sem base |
| Compactações | 4 | sem base |
| Correções por implementador | 0,12 | sem base |
| Retornos acima do limite (%) | 33 | sem base |
| Validador acima da meta (%) | 21 | sem base |
| Aprovadas de primeira (%) | 80 | sem base |
| Precisão do mapa de arquivos (%) | 91 | sem base |
| Rodadas de fix loop | 6 | sem base |
| Tasks de correção pós-Fase 5 (%) | 12 | sem base |

## Tasks

| Task | Onda | Origem | Complexidade | Status | RED | Gate | Fix loop | Revisor (bloq./sug.) | Perguntas | Desvios | Fora do previsto |
|---|---|---|---|---|---|---|---|---|---|---|---|
| T-01 | 1 | plano | alta | [x] | sem teste | 1 | 1 | 0/4 | 0 | 5 | src/app/templates/adminbs5/js/cv-shell.js |
| T-02 | 1 | plano | alta | [x] | sem teste | 0 | 1 | 2/2 | 0 | 4 | — |
| T-03 | 1 | plano | simples | [x] | sem teste | 0 | 0 | 0/0 | 0 | 2 | — |
| T-04 | 1 | plano | média | [x] | ok | 0 | 0 | 0/2 | 0 | 4 | — |
| T-05 | 1 | plano | média | [x] | ok | 0 | 0 | 0/4 | 0 | 4 | — |
| T-06 | 1 | plano | média | [x] | ok | 0 | 0 | 0/4 | 0 | 4 | — |
| T-07 | 1 | plano | simples | [x] | sem teste | 0 | 0 | 0/2 | 0 | 0 | — |
| T-08 | 2 | plano | média | [x] | sem teste | 0 | 0 | 0/4 | 0 | 5 | — |
| T-09 | 2 | plano | alta | [x] | sem teste | 0 | 0 | 0/3 | 0 | 3 | — |
| T-10 | 2 | plano | alta | [x] | sem teste | 0 | 0 | 0/6 | 0 | 6 | — |
| T-11 | 2 | plano | média | [x] | sem teste | 0 | 0 | 1/3 | 0 | 5 | — |
| T-12 | 2 | plano | alta | [x] | sem teste | 0 | 0 | 0/4 | 0 | 5 | — |
| T-13 | 2 | plano | alta | [x] | sem teste | 0 | 0 | 0/5 | 0 | 10 | — |
| T-14 | 3 | plano | média | [x] | sem teste | 0 | 1 | 0/3 | 0 | 4 | — |
| T-15 | 3 | plano | média | [x] | sem teste | 0 | 0 | 0/5 | 0 | 6 | — |
| T-16 | 3 | plano | média | [x] | sem teste | 0 | 0 | 0/4 | 0 | 5 | — |
| T-17 | 3 | plano | alta | [x] | ok | 0 | 1 | 0/5 | 0 | 5 | src/app/Core/Application/StockService.php, src/tests/Unit/StockServiceTest.php |
| T-18 | 3 | plano | média | [x] | ok | 1 | 2 | 0/3 | 0 | 6 | src/app/Core/Application/PayableService.php, src/app/Core/Domain/Payable.php, src/app/Core/Persistence/PayableRepository.php, src/tests/Support/FakePayableRepository.php, src/tests/Unit/PayableServiceTest.php |
| T-19 | 3 | plano | média | [x] | sem teste | 0 | 0 | 0/3 | 0 | 2 | — |
| T-20 | 4 | plano | simples | [x] | sem teste | 0 | 0 | 0/2 | 0 | 1 | — |
| T-21 | 5 | plano | média | [x] | sem teste | n/d | 0 | n/d/n/d | 1 | 3 | — |
| T-22 | 6 | correcao | simples | [x] | sem teste | 0 | 0 | 0/0 | 0 | 1 | — |
| T-23 | 6 | correcao | simples | [x] | sem teste | 0 | 0 | 0/2 | 0 | 1 | — |
| T-24 | 6 | correcao | simples | [x] | sem teste | 0 | 0 | 0/1 | 0 | 0 | — |

## Causas

- T-01: cv-shell.js referenciado no layout sem existir (erro de console) e sidebar recolhida sem largura 0; corrigido com stub e CSS
- T-02: atributos com dado do chamador sem escape (XSS no CvAvatar) e grid do CvForm aplicado a linhas com setLayout
- T-03: 5 chaves já existiam e o tradutor só substitui ^N; mantido %1..%3 com substituição no CvDatagrid
- T-04: Reader exigia findById/save/remove por herdar AbstractTenantRepository; fixture de system_unit corrigida antes do RED
- T-05: schema não liga saídas ao caixa; saldo do caixa aberto = abertura + pagamentos da sessão
- T-06: Reader sem CRUD não estende AbstractTenantRepository; construtor com $today opcional para teste
- T-08: Desvios menores: role determinístico, JSON de erro e unit_id inválido tratados; sem correção
- T-09: Schema sem descrição de lançamento e rótulos do kit fixos; usou reference e textos existentes
- T-10: TPage no lugar de TStandardList; método onEdit na ação de lote e título vindo de T-03
- T-11: ServiceList virou TPage; Editar herda ServiceForm::onSave só create (achado do revisor, vai à onda 3)
- T-12: Right panel removido, onClear novo e veterinário sem critério; desvios documentados
- T-13: Form virou TForm para painéis do wizard; autosave escopado e total pela conta do atendimento
- T-14: Paginação da busca global mostrava total errado; corrigido em 32130d9
- T-17: receiveBatch sem chave de autorização da ação da tela; corrigido em c96fee2
- T-18: Editar conta a pagar não persistia nem carregava valor; PayableService::update criado
- T-20: Route pt trocado de Rota para Via conforme o mock; decisão adiada pela T-12 para T-20
- T-22: Reprodução no navegador não feita pelo agente (Playwright é do validador); entrou reprodução estática, verificação ficou no gate
- T-23: Reprodução por render CLI e SELECT em vez de navegador (Playwright é do validador); sem desvio de escopo

# Eficiência — mar-20261021-1554-etapa-3-contratacao

- Plano: `/var/www/html/centralvet/.claude/tasks/mar-20261021-1554-etapa-3-contratacao` · projeto `/var/www/html/centralvet` · versão 2.7.0? · gerado em 2026-10-26T10:02:25-03:00 · última onda medida 8
- Sessões: fc92cc27-9f66-4ad7-bfe3-efed43d88248 (encontrada), b5eee13a-a154-4bef-8500-0b8809d420de (encontrada)
- Base: sem base (nenhum plano da versão anterior no acervo)

## Indicadores

| Indicador | Este plano | Base |
|---|---|---|
| Tokens retidos por onda (mediana) | 37405,50 | sem base |
| Contexto máximo | 774.171 | sem base |
| Compactações | 2 | sem base |
| Correções por implementador | 0,21 | sem base |
| Retornos acima do limite (%) | 13 | sem base |
| Validador acima da meta (%) | 8 | sem base |
| Aprovadas de primeira (%) | 60 | sem base |
| Precisão do mapa de arquivos (%) | 90 | sem base |
| Rodadas de fix loop | 10 | sem base |
| Tasks de correção pós-Fase 5 (%) | 19 | sem base |

## Tasks

| Task | Onda | Origem | Complexidade | Status | RED | Gate | Fix loop | Revisor (bloq./sug.) | Perguntas | Desvios | Fora do previsto |
|---|---|---|---|---|---|---|---|---|---|---|---|
| T-01 | 1 | plano | simples | [x] | ok | 0 | 0 | 0/4 | 0 | 3 | src/tests/Unit/UserMessageTest.php |
| T-02 | 1 | plano | média | [x] | sem teste | 0 | 1 | 1/3 | 0 | 3 | — |
| T-03 | 1 | plano | média | [x] | inválido | 0 | 1 | 2/2 | 0 | 4 | — |
| T-04 | 1 | plano | alta | [x] | ok | 0 | 0 | 0/3 | 0 | 3 | — |
| T-05 | 1 | plano | simples | [x] | ok | 0 | 1 | 1/2 | 0 | 1 | — |
| T-06 | 1 | plano | média | [x] | ok | 0 | 0 | 0/5 | 0 | 3 | src/tests/Integration/SaleFinanceReaderIntegrationTest.php |
| T-07 | 1 | plano | alta | [x] | ok | 0 | 0 | 0/8 | 0 | 4 | — |
| T-08 | 1 | plano | média | [x] | ok | 0 | 0 | 0/2 | 0 | 0 | — |
| T-09 | 2 | plano | média | [x] | ok | 0 | 0 | 0/6 | 0 | 3 | — |
| T-10 | 2 | plano | média | [x] | ok | 0 | 1 | 1/4 | 0 | 4 | src/tests/Unit/SubscriptionFakesTest.php |
| T-11 | 2 | plano | média | [x] | ok | 0 | 1 | 3/3 | 0 | 1 | — |
| T-12 | 2 | plano | média | [x] | ok | 0 | 0 | 0/2 | 0 | 2 | — |
| T-13 | 3 | plano | alta | [x] | ok | 0 | 1 | 1/6 | 0 | 5 | — |
| T-14 | 3 | plano | alta | [x] | ok | 0 | 0 | 0/5 | 0 | 3 | — |
| T-15 | 3 | plano | média | [x] | ok | 0 | 0 | 0/7 | 0 | 2 | — |
| T-16 | 3 | plano | alta | [x] | ok | 0 | 0 | 0/4 | 0 | 4 | — |
| T-17 | 4 | plano | alta | [x] | ok | 0 | 0 | 0/6 | 0 | 4 | — |
| T-18 | 5 | plano | alta | [x] | ok | 0 | 1 | 3/3 | 0 | 7 | src/app/Core/Billing/Contract/BillingOrderRepositoryInterface.php, src/app/Core/Billing/Contract/SubscriptionInvoiceRepositoryInterface.php, src/app/Core/Billing/PixInvoiceIssuer.php, src/app/Core/Billing/TenantProvisioningService.php, src/app/Core/Persistence/BillingOrderRepository.php, src/app/Core/Persistence/SubscriptionInvoiceRepository.php, src/tests/Integration/BillingOrderRepositoryIntegrationTest.php, src/tests/Support/FakeBillingOrderRepository.php, src/tests/Support/FakeSubscriptionInvoiceRepository.php, src/tests/Unit/PixInvoiceIssuerTest.php |
| T-19 | 4 | plano | alta | [x] | ok | 0 | 1 | 1/5 | 0 | 4 | — |
| T-20 | 4 | plano | média | [x] | ok | 0 | 1 | 1/4 | 0 | 3 | src/app/Core/Landing/LandingPage.php, src/tests/Unit/LandingA11yTest.php, src/tests/Unit/LandingPageTest.php |
| T-21 | 4 | plano | média | [x] | ok | 0 | 0 | 0/5 | 0 | 5 | — |
| T-22 | 6 | plano | alta | [x] | inválido | 0 | 1 | 2/5 | 0 | 3 | — |
| T-23 | 3 | plano | média | [x] | ok | 0 | 0 | 0/6 | 0 | 3 | — |
| T-24 | 4 | plano | alta | [x] | ok | 0 | 0 | 0/6 | 0 | 7 | src/tests/Unit/UserMessageTest.php |
| T-25 | 5 | plano | alta | [x] | ok | 0 | 0 | 0/4 | 0 | 4 | — |
| T-26 | 7 | correcao | alta | [x] | ok | 0 | 0 | 0/4 | 0 | 3 | — |
| T-27 | 7 | correcao | alta | [x] | ok | 0 | 0 | 0/3 | 0 | 3 | — |
| T-28 | 7 | correcao | média | [x] | ok | 0 | 0 | 0/2 | 0 | 1 | — |
| T-29 | 7 | correcao | simples | [x] | ok | 0 | 0 | 0/0 | 0 | 0 | — |
| T-30 | 7 | correcao | simples | [x] | sem teste | 0 | 0 | 0/0 | 0 | 0 | — |
| T-31 | 8 | correcao | alta | [x] | ok | 0 | 0 | 0/3 | 0 | 4 | — |

## Causas

- T-01: Aceite exigia pt novo e proibia duplicar chave; reutilizou pt antigo e atualizou UserMessageTest
- T-02: terms_accepted_at sem DEFAULT ganharia ON UPDATE no MySQL 5.7; corrigido na 0017 com DEFAULT explícito
- T-03: Teste de corrida esperava o recebível (1205) e não só o cash_session; B passou a usar recebível próprio
- T-04: Autorizado editar composer.json; hash de framework_hashes.php já estava desatualizado antes da task
- T-05: Mapa de usos do download.php estava incompleto; completado após revisão
- T-06: Teste de resumo comparava array exato; SaleFinanceReaderIntegrationTest precisou das novas chaves
- T-07: toRow omitiu created_at/updated_at (preenchidos pelo banco) e acrescentou extras ao contrato
- T-09: Fakes e claim com decisões fora do plano (FOR UPDATE exige transação, attempts=1); sem retrabalho
- T-10: Fakes dos *ForUpdate não exigiam transação, reprovado na rodada 1 e corrigido em 8f70d96
- T-11: Seed expunha SystemUser/UnitForm sem tenant e admin sem perfil/senha/2FA; ruling do usuário, corrigido em e9915bf
- T-12: Separador do corpo do e-mail e propagação de MessageDeliveryFailed não fixados no plano; sem retrabalho
- T-13: Slug clinica-+8hex colidia com tenant_slug_uq e deixava o pedido pago em provisioning_failed; plano omitia o desempate
- T-14: Plano contraditório entre regra de evento antigo e Review Focus 500/400 no Pix; implementadora decidiu max(created,last_event_created)
- T-15: Plano não definia o reason de ALWAYS_ALLOWED; escolhidos legacy/grace/full e consulta de assinatura antes do teste
- T-16: Endpoint público sem tradutor: mensagens 422 em pt fixo, limitador com env próprio e extras de 503 não previstos no plano
- T-17: RED ajustado na implementação (pending → checkout_open); attempts sobe 2 por entrega
- T-18: Reconciliador deixava lotes de convite e Pix travados e criava PaymentIntent duplicado na falha do e-mail; corrigido com teto e JOIN
- T-19: Reprovada: GET de página inteira de tenant bloqueado dava tela em branco; corrigida com 403 e HTML autônomo
- T-20: Reprovada: cache busters ?v= não trocados; entryFor da LandingPage precisou abrir retorno do checkout
- T-21: Regex do RED não casava com o padrão do seed; trocada na implementação
- T-22: Shell com 403 na tela de assinatura suspensa; fumaça no dev provocou correções em T-08, T-15, T-18 e T-25.
- T-23: Plano deixava aberto o StripeApiException após o insert em issueNext; fatura fica sem PI e o reconciliador recupera
- T-24: Contagem de UserMessageTest (92→99) fora do escopo provável; ordem de translations.json
- T-26: Construtor com params opcionais, exclusão de login_conflict com IS NULL OR e ordem do runbook diferem do plano
- T-27: Collation de system_users.login e código group_missing no teste de integração diferem do plano
- T-31: Desvios do plano: cartão sem stripe_subscription_id pula o cancelamento; resposta Stripe sem id vira StripeApiException; sem correção nem bloqueante.

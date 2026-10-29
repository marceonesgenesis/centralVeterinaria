# Revisão final
Branch `task/etapa-5-piloto-lancamento` (a852e0e) contra `task/etapa-4-operacao` (fbd6d51). Critério da etapa (`.docs/audits/2026-10-09-prontidao-mvp.md:160`): nenhum bloqueio aberto no escopo anunciado; suporte, onboarding e oferta alinhados. Resultado: **não atendido**, porque há 1 bloqueante no cancelamento Pix, que está no escopo anunciado.

## Bloqueantes
- [bloqueante] Pix cancelado dentro da janela de emissão antecipada: a fatura do período seguinte continua aberta e pagável depois do fim do período, o que contradiz "não há nova cobrança depois dela" → `src/app/view/legal/termos-de-uso.html:34`. A fatura sai 3 dias antes do fim (`src/app/Core/Billing/PixInvoiceIssuer.php:32`, `ISSUE_DAYS_BEFORE = 3`), e o Pix expira em 10 dias (`PixInvoiceIssuer.php:35`, `864000`). Assim, o QR segue válido por cerca de 7 dias depois de a assinatura virar `cancelled`. `SubscriptionCancellationService::request` só grava a marca local no Pix e não cancela a fatura nem o PaymentIntent abertos. Até o fim do período, `refreshOpenPix` ainda renova o PaymentIntent, porque o status `active` está em `REFRESHABLE_STATUSES` (`BillingReconciler.php`). Se o pagamento chegar depois de `cancelled`, `BillingEventProcessor.php:278-282` marca a fatura como paga e `Subscription.php:252` não reativa a assinatura nem dispara estorno: o dinheiro é capturado sem serviço. Nenhum teste cobre o caso. (Pendência T-08, promovida.)

## Pendências promovidas
- [promovida] T-08: "Pix cancelado na janela de emissão antecipada mantém a fatura do próximo período aberta". Motivo: o conjunto da branch agravou a pendência. Os Termos de Uso (T-06), o texto do checkout (`LandingCatalog::TERMS_TEXT`) e a tela de cancelamento (T-14) agora prometem que não há cobrança depois do fim do período, e o aceite versionado (T-16) torna essa promessa contratual. Ver Bloqueantes.

## Triagem
- [aberta] T-01: testes de schema só afirmam nome e ordem das colunas → motivo: sem mudança no diff
- [aberta] T-03: `supportEmail()` aceita `.invalid`; regra duplicada no preflight; `fromEnvironment()` sem teste → motivo: sem mudança
- [aberta] T-03: `release.md`/`observabilidade.md` sem `support_email` → motivo: confirmado pela pendência da T-20
- [aberta] T-04: idempotência dos vínculos só com 3 SystemProfile* → motivo: "segue sem cobertura" (notes)
- [resolvida] T-04: reaplicar o seed inicial reabria AiCopilot ao Veterinário → `src/app/database/seeds/initial-application-programs.sql`, onde os grants da Fase 8B ganharam `NOT LIKE 'Central Vet - Perfil %'`
- [aberta] T-05: `ps -p` sem procps → motivo: sem mudança
- [aberta] T-06: horário de suporte fixo nos textos legais; `Cache-Control: public` no 404 → motivo: sem mudança
- [aberta] T-06: revisão jurídica dos textos → motivo: fora do escopo (Excluído)
- [aberta] 0020 não aplicada no banco dev → motivo: exige autorização; fica no checklist de go-live
- [aberta] PR da Etapa 4 pendente (gh sem autenticação) → motivo: fora do código
- [aberta] T-07: caso `legacy` só com `HospitalizationBoard`; `not_released` reaproveita o link de `SubscriptionStatusView` → motivo: sem mudança
- [promovida] T-08: fatura Pix aberta após o cancelamento na janela de emissão antecipada → ver Bloqueantes (as demais partes do item, o teste do lock e o ALWAYS_ALLOWED após finalizar, seguem como sugestão)
- [aberta] T-08: docblock de `bin/billing-reconcile.php` e runbook mysql57 sem `cancellations_finalized` → motivo: confirmado pela pendência da T-20
- [aberta] T-09: `mark()` público e estático; teste engole exceção; `save()` sem transação própria → motivo: sem mudança
- [aberta] T-10: RED sensível à ordem; fuso de `accepted_at`; truncamento só com ASCII → motivo: sem mudança
- [aberta] T-11: teste só no `tutor.csv`; `tempZip()`; SELECT sem ORDER BY; colunas `*_tokens` excluídas → `src/app/Core/Privacy/TenantDataExporter.php` (SELECT sem ORDER BY, padrão de segredo inalterado)
- [aberta] T-12: docblock de `ClinicUserForm`; nome de grupo duplicado → motivo: não reconferido
- [aberta] T-13: `docs/runbooks/smoke-billing.md:158` com a versão antiga dos termos → motivo: não reconferido
- [aberta] Imagem da :8081 sem rebuild para a fumaça → motivo: operacional
- [aberta] T-14: `testConfirmIsMutating`; log de `getMessage()` → motivo: a parte do ALWAYS_ALLOWED foi resolvida (`SubscriptionAccessGuard.php`, `ALWAYS_ALLOWED` com `SubscriptionCancelForm`); o resto continua
- [aberta] T-15: nomes do menu no guia; "concluído" recalculado; `tenantContext(PDO)` público → motivo: ruling aceitou os desvios
- [aberta] T-16: recusa conta como senha errada; aceite gravado antes do 2FA; `accept_legal` sem `.cv-touch-target` e sem `for` → `src/app/control/admin/LoginForm.php`, `legalAcceptanceVerification` (o TCheckButton segue sem a classe)
- [aberta] T-17: âncora `#cancelamento`; título `Terms of use`; `userId` 0 sem sessão → motivo: não reconferido
- [aberta] T-18: RED do perfil não reproduzível; ramo morto; caixa de outro tenant → motivo: sem mudança
- [resolvida] Registro das telas da Etapa 5 em programa, grupos, menu, CSRF e ALWAYS_ALLOWED (para T-19) → `initial-application-programs.sql:1200-1237`, `plan-groups.sql:289,295`, `SubscriptionAccessGuard.php` ALWAYS_ALLOWED, `docs/security/csrf-inventario.md`, `src/menu.xml`
- [aberta] Diálogo de aceite e telas da onda 3 sem fumaça no navegador; download conferido só por leitura → motivo: operacional
- [aberta] Idempotência da T-04 sem cobertura → motivo: igual ao item da T-04
- [aberta] T-19: `AiNavigationIntegrationTest` deve filtrar os grupos "Plano" → motivo: sem mudança
- [aberta] T-19: comentário desatualizado em `role-groups.sql:10` → `src/app/database/seeds/role-groups.sql:10` ainda diz "até T-19 registrá-los"
- [aberta] T-19: comentário da Fase 8B em `initial-application-programs.sql:1056` → motivo: sem mudança
- [aberta] T-19: exclusão dos perfis nos grants de IA só tem prova textual → motivo: sem teste com banco
- [aberta] T-20: `release.md`/`observabilidade.md`/`shared-hosting-mysql57.md`/docblock → motivo: sem ruling
- [aberta] T-20: prazos de suporte "propostos, a confirmar" → motivo: decisão do dono do piloto
- [resolvida] T-20: seção de mensagens de erro e prazos tratada na rodada 2 → `reviews/T-20.md` § Rodada 2 (não reconferido no diff)

## Rulings
- plano · onda 0 — Branch base `task/etapa-4-operacao` (Etapas 1–4 fora de `dev`/`main`)
- plano · onda 0 — Recorte na guarda (`OfferScope`, `not_released`), não em `PlanEntitlements`; fora: `communication`, `hospital`, `ai_advanced`; legado não é afetado
- plano · onda 0 — Perfis restritos como grupos globais fixos em `role-groups.sql`; editor de perfis fora do escopo
- plano · onda 0 — Aceite legal no `LoginForm` com tabela própria; `require_terms` do Adianti desligado; o admin aceita de novo no 1º login
- plano · onda 0 — `TERMS_VERSION` = `termos-uso-2026-10`; pedidos antigos mantêm `assinatura-2026-10`
- plano · onda 0 — Exportação LGPD síncrona, CSV por tabela com `tenant_id`, sem colunas sensíveis, sem binários
- plano · onda 0 — Cancelamento só no fim do período; sem reativação pelo app; Pix pelo reconciliador, cartão pelo webhook
- plano · onda 0 — Da Etapa 4 entra só o flag de manutenção órfão (T-05)
- plano · onda 0 — Oferta: núcleo, financeiro, estoque/PDV e várias unidades; comunicação, internação/cirurgia, IA e governança "em breve"; "Suporte dedicado" segue anunciado
- plano · onda 0 — 3 perfis fixos além do administrador
- plano · onda 0 — 4 planos no piloto; `OFFER_PLANS` com os 4 por padrão
- plano · onda 0 — Suporte por e-mail (obrigatório) e WhatsApp (opcional), seg–sex 8h–18h; retenção de 30 dias
- T-01 · onda 1 — `migrations/README.md` aceito; `accepted_at` com DEFAULT CURRENT_TIMESTAMP(6)
- T-02 · onda 1 — `UserMessageTest.php` autorizado (100→106)
- T-06 · onda 1 — Evidência via `php -S` aceita; conferir na fumaça pós-deploy
- T-05 · onda 1 — `kill -0` mais `ps -p` por causa do EPERM
- usuário · onda 1 — Confirmação da oferta, perfis, planos, suporte e retenção
- T-08 · onda 2 — `SubscriptionTest.php` no commit RED aceito
- T-12 · onda 2 — Troca do teste do Starter legítima; `UserMessage`/`translations.json`/`UserMessageTest` (106→107) autorizados
- T-13 · onda 2 — Correção do `testScenarioP`, escopo extra e curl via `php -S` aceitos
- T-18 · onda 3 — Correção de `Receivable`/`Cash session not found` em `UserMessage` autorizada
- T-15 · onda 3 — Três desvios aceitos (`tenantContext` público, transação no `onSave`, toast `Record saved`)
- T-14..T-17 · onda 3 — Registro em menu, grupos, CSRF e ALWAYS_ALLOWED delegado à T-19
- T-19 · onda 4 — `SubscriptionAccessGuardTest.php` no RED aceito
- T-19 · onda 4 — `CommunicationNavigationIntegrationTest.php` fora do escopo aceito
- T-19 · onda 4 — Seeds aplicados 2x no `centralvet_test`; banco voltou à linha de base
- T-19 · onda 4 — Sessão interrompida e retomada
- T-19 · onda 4 — `AiNavigationIntegrationTest` depende do estado do banco; grants de IA excluem `Perfil %`
- T-20 · onda 5 — Só docs, sem RED
- T-20 · onda 5 — Suíte não rodou de novo depois de 9241e16 (só docs)

## Achados
- [bloqueante] Fatura Pix aberta e pagável depois do cancelamento (ver Bloqueantes) → `src/app/Core/Billing/SubscriptionCancellationService.php` (`request`, ramo Pix), `src/app/Core/Billing/BillingEventProcessor.php:278-282`
- [sugestão] O link "Copiloto" decide por `AiCopilotAvailability`, que não está no diff e não consulta `OfferScope`; um tenant Enterprise com IA ligada pode ver um link que cai em `not_released` → `src/app/control/clinic/EncounterView.php:500`, `src/app/control/clinic/PatientForm.php:248`
- [sugestão] Itens de IA no menu do administrador Enterprise levam a `not_released` em vez de ficarem ocultos → `src/menu.xml:187,195`
- [sugestão] A landing anuncia "Perfis de acesso por equipe" só no Business, mas `ClinicTeamService::allowedGroups()` oferece os 3 perfis em qualquer plano (anuncia menos do que entrega) → `src/app/Core/Landing/LandingCatalog.php:101`
- [sugestão] A exportação LGPD só inclui tabelas com `tenant_id`; tabelas-filhas sem a coluna ficam de fora, e `*_object_key`/`storage_key` expõem caminhos internos do storage → `src/app/Core/Privacy/TenantDataExporter.php` (`columnsByTable`)

## Verificado
- Guarda: `not_released` vem depois de `blocked_plan` e vale para qualquer usuário do tenant com assinatura; `OfferScope::RELEASED_FEATURES` = `inventory_pos`, `finance_full`, `multi_unit`; `PlanEntitlements::gatedPrograms` lista os controllers Ai*, Hospitalization*/Surgery* e Communication* → `SubscriptionAccessGuard.php`, `OfferScope.php`, `PlanEntitlements.php:56-70`
- Perfis restritos: as listas de `ClinicRoleProfiles` não têm IA, internação nem comunicação; os grants amplos de IA excluem `Central Vet - Perfil %`; exportação e cancelamento só vão ao grupo 1 e aos grupos de plano (administrador), nunca aos perfis → `ClinicRoleProfiles.php`, `initial-application-programs.sql`, `plan-groups.sql:289,295`
- Isolamento A×B na exportação: cada tabela com `WHERE tenant_id = ?`, usuários por `tenant_user.tenant_id`, tenant tirado da sessão autenticada; colunas que casam `password|token|secret|hash` saem → `TenantDataExporter.php`, `ClinicDataExportView.php:66-76`
- Isolamento A×B na equipe: os perfis são grupos globais, mas usuários e unidades seguem filtrados por tenant, e o último administrador fica protegido → `ClinicTeamService.php`
- Cancelamento no cartão: `cancel_at_period_end=true` no Stripe com Idempotency-Key e falha sem gravar nada; no Pix, a emissão ignora assinaturas com cancelamento agendado e `finalizeScheduledCancellation` só age com `current_period_end <= now` → `SubscriptionCancellationService.php`, `SubscriptionRepository.php:117,133-147`, `Subscription.php`
- Aceite versionado: `LegalDocuments` versionado; `INSERT … ON DUPLICATE KEY UPDATE` não duplica; o login para sem o aceite; o checkout usa `TERMS_VERSION = LegalDocuments::TERMS_VERSION` → `LegalAcceptanceService.php:77-79`, `LoginForm.php`, `LandingCatalog.php`
- Oferta: checkout com plano fora de `OFFER_PLANS` → 422 em `plan_id` antes do Stripe; a landing marca "Em breve" em Comunicação/Automações, WhatsApp, IA, Cirurgia e internação, Governança, Relatórios avançados, Integrações e SSO; todo item "Disponível" está no recorte → `CheckoutStartHandler.php`, `landing.html`, `LandingCatalog.php`

## Não verificado
- Segredos fora do repositório: o grep `sk_live_|sk_test_|rk_live_` do plano não rodou, e o conteúdo de `.env.example` e `docker-compose.yml` não foi lido
- SUITE, LINTALL, BASHN e CI local não foram rodados nesta revisão (vale só o relato do orquestrador: 2518/2518); também não foram conferidos `sha256sum .env` e `schema_migrations`
- Migration 0020: risco de perda de dados não lido
- `legal.php`, `SupportView`, `PilotSettings`, telas de onboarding, `menu.xml` e runbooks não lidos em detalhe (suporte e onboarding alinhados só por leitura do stat e de notes.md)
- Se `AiCopilotAvailability` já considera o recorte
- Fumaça no navegador e na :8081

## Revisão final — rodada 2
Escopo: `a852e0e..ede5b44` (Onda 6: T-21, T-22, T-23) contra o bloqueante da rodada 1. Resultado: **bloqueante resolvido**; nenhum bloqueante novo; critério da etapa atendido no código, com sugestões abertas.

### Bloqueante da rodada 1 (Pix pago depois do cancelamento) → resolvido
- Emissão: `PixInvoiceIssuer::issueNext` não emite com `cancelAtPeriodEnd()` → `src/app/Core/Billing/PixInvoiceIssuer.php:74`
- Fatura futura já emitida: `request()` cancela o PI no Stripe (Idempotency-Key `cancel-pix-intent-<id>`) antes da transação e, na transação, relê a fatura com trava e a anula (`voidForCancellation`, só `open` com `local_key`); falha do Stripe vira PROVIDER_FAILED sem gravar nada; com cancelamento já agendado, a fatura que sobrou ainda é anulada → `SubscriptionCancellationService.php:63-106`, `SubscriptionInvoice.php:224`
- Fatura `void` não é renovada: `listOpenPix` só lista `open` → `Persistence/SubscriptionInvoiceRepository.php:136`
- Pagamento que chega mesmo assim: `markPaidByPaymentIntent` aceita `void`→`paid`; o processador não avança a assinatura (`$wasVoid` ou `period_start >= current_period_end` com agendado/`cancelled`) e `Subscription::markInvoicePaid` também recusa → `BillingEventProcessor.php:278-300`, `Subscription.php:259`. A assinatura `cancelled` segue sem reativação (`Subscription.php:252`)
- Estorno uma única vez: reconciliador (fase 1a, depois de `reconcileEvents`) chama `CancelledPixRefundService::refund`, que relê fatura → assinatura com trava, reconfere o critério, estorna com Idempotency-Key `refund-cancelled-pix-<id>` (`charge_already_refunded` reaproveita o estorno existente) e grava `refunded`; a fila exige `paid` e `amount_refunded_cents = 0`, então não repete → `CancelledPixRefundService.php:191-282`, `SubscriptionInvoiceRepository.php:152-168`
- Alerta: `error_log` `cancelled_pix_refunded` sempre e e-mail a `BILLING_OPERATOR_EMAIL` quando definido; contador `cancelled_pix_refunded` no CLI → `CancelledPixRefundService.php:226-317`, `src/bin/billing-reconcile.php:666`
- Wiring: `SubscriptionCancelForm` passa o `SubscriptionInvoiceRepository` → `src/app/control/admin/SubscriptionCancelForm.php:102`

### Triagem das pendências novas (Onda 6)
- [aberta] T-21: corrida com a fase 3 (`futurePixInvoices` sem trava antes da transação) → janela = chamada de rede do PI dentro do `issuePixInvoices`. A fatura que sobra fica `open`, entra em `listOpenPix` (não filtra `cancel_at_period_end`), pode ter o PI renovado e marcar a assinatura `past_due` no vencimento, até a finalização. Não há perda de dinheiro: o pagamento cai em `paidAfterCancellation` e no estorno da fase 1a. Fere só a promessa "fatura não pagável" numa corrida estreita → `SubscriptionCancellationService.php:450`, `SubscriptionInvoiceRepository.php:136`
- [aberta] T-21: ordem de trava invertida (cancelamento: assinatura→fatura; processador/estorno: fatura→assinatura). Deadlock só com pagamento e cancelamento simultâneos da mesma fatura. Vítima processador: o ingestor retenta 1213 e, se falhar, marca `failed` e responde 500 (Stripe reenvia; o reconciliador reingere) → `BillingEventIngestor.php:19-30`. Vítima cancelamento: `TransactionRunner` não retenta; nada é gravado, a tela mostra erro e o PI (já `succeeded`) é cobrado com a assinatura renovada sem a marca de cancelamento. É uma renovação legítima, e o usuário repete o pedido. Sem cobrança sem estorno nem perda de dado; segue sugestão
- [aberta] T-21: ramo `payment_intent_unexpected_state` + GET `succeeded` sem teste → `src/tests/Unit/SubscriptionCancellationServiceTest.php:209-245`. Leitura do caminho: anula a fatura e agenda; o webhook posterior passa por `$wasVoid` e a fase 1a estorna (fatura de período futuro e assinatura agendada na mesma transação). Se o webhook já tinha sido processado, a fatura está `paid` e o período avançou antes da marca, o que é uma renovação legítima. Sem risco de dinheiro; falta só a cobertura
- [aberta] T-22: guarda novo de `Subscription::markInvoicePaid` sem teste próprio → `src/app/Core/Billing/Subscription.php:259`
- [aberta] T-22: docblock duplicado antes de `paidAfterCancellation`; `marked()` perde o seu → `BillingEventProcessor.php:347-355`
- [aberta] T-22: `$wasVoid` sem cancelamento não avança e não entra na fila de estorno. Hoje o único caminho para `void` local é o cancelamento, que grava a marca na mesma transação. Só uma reativação manual pelo suporte entre a anulação e o pagamento deixaria o pagamento sem estorno → `BillingEventProcessor.php:293`
- [aberta] T-23: `observabilidade.md:51` cita `bin/` em vez de `src/bin/`; `:55` diz que sem `BILLING_OPERATOR_EMAIL` o alerta "vai ao error_log" (o código só não envia; o log é do evento) → `docs/runbooks/observabilidade.md:51,55`
- As pendências da rodada 1 mantêm a classificação: a Onda 6 não tocou nos arquivos delas. Exceção: os itens de docs da T-03/T-08/T-20 (`release.md` e `shared-hosting-mysql57.md` com `support_email`/contadores), tratados em 7d260f4 e não reconferidos linha a linha

### Rulings (Onda 6)
- plano · onda 6 — T-21 anula a fatura futura e cancela o PI; T-22 estorna o Pix pago após o cancelamento; sem migration; `TERMS_VERSION` inalterado
- T-21 · onda 6 — Linhas `Task:` fora do trailer em d6b9eb2/b985e48 aceitas sem reescrever o histórico
- T-22 · onda 6 — Idem para 76a1ec6/0fc7b16
- T-23 · onda 6 — Complemento do runbook mysql57 com `cancelled_pix_refunded`

### Achados
- [sugestão] Fatura futura que escapa da corrida segue em `listOpenPix` (PI renovado, `markOverdue`) até a finalização; filtrar `cancel_at_period_end = 1` com `period_start >= current_period_end`, ou anular na fase de finalização → `src/app/Core/Persistence/SubscriptionInvoiceRepository.php:136`

### Não verificado
- SUITE/LINT/BASHN/CI não rodados nesta rodada (vale o relato do gate: 2541/2541)
- Testes de `CancelledPixRefundServiceTest`, `BillingReconcilerIntegrationTest` e `BillingEventProcessorTest` lidos só pelo stat do diff, não linha a linha
- Itens "Não verificado" da rodada 1 continuam iguais (segredos, migration 0020, fumaça)

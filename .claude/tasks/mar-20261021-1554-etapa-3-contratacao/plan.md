# Plano: Etapa 3 — Contratação (F4, F5, direitos por plano) e pendências S5, S6, caixa e Etapa 2

## Objetivo
Fechar a Etapa 3 da auditoria `.docs/audits/2026-10-09-prontidao-mvp.md`: a clínica contrata pela landing (plano com valor do servidor, responsável, aceite dos termos, Checkout Stripe com cartão ou Pix), a confirmação chega só por webhook autenticado e dispara provisionamento idempotente e retomável com convite de senha. A assinatura mensal tem período, estado, faturas, eventos deduplicados, reconciliação e tolerância, e os direitos por plano valem no servidor. Na mesma branch: trava do caixa no fechamento, S5, S6 e as sugestões da Etapa 2.

## Premissas
- Branch base `task/etapa-2-dinheiro-estoque` (HEAD `49ada48`, no origin), por pedido explícito do desenvolvedor; produção é `main` (fonte `padrão` do `branches.py`).
- A entrega preserva os commits por onda: a Fase 5 não roda o `entrega.py`.
- As telas de sistema (`SystemUserList`, `SystemUserForm`, `SystemUnitForm`, `SystemGroupForm`) não respeitam o tenant e ficam só no grupo 1; a clínica usa `ClinicUserList`/`ClinicUserForm`/`ClinicUnitList`/`ClinicUnitForm` (T-24, T-25).
- Execução das 25 tasks de uma vez, sem sub-etapas (decisão do usuário).
- Stripe sem SDK, mesmo com o `composer.json` liberado para o S5: cliente HTTP próprio sobre `ext-curl` (T-08), API fixada em `2024-06-20` (motivo em `notes.md`).
- Cartão: Checkout `mode=subscription`, com a Subscription em `collection_method=charge_automatically`. Pix (fallback decidido pelo usuário): 1º mês por Checkout `mode=payment`; a cada período, uma fatura local com PaymentIntent Pix avulso (T-23), emitida pelo reconciliador 3 dias antes do vencimento. O Pix não tem Subscription no Stripe.
- Nenhuma chamada de rede ao Stripe nos testes (`FakeStripeClient`); webhook validado com o segredo local `whsec_test_etapa3`.
- Quotas e mapa de recursos por plano fixados em T-07 a partir do `LandingCatalog` (Starter 2 usuários/1 unidade, Pro 10/1, Business 30/5, Enterprise sem limite; internação e cirurgia só no Enterprise), confirmados pelo usuário.
- Tenant sem linha em `subscription` (os de hoje) mantém o acesso (`legacy`).
- Migrations compatíveis com MySQL 5.7 (cópia `21-` no preparador). DDL no `centralvet_test` pelo root do container mysql, como na Etapa 2. Dev só com autorização expressa: o orquestrador pergunta quando chegar a Onda 6, e a fumaça de T-22 depende dela.
- `download.php` não serve arquivo legítimo hoje (`src/files/` só tem `system/`, bloqueado): S6 resolvido desabilitando o endpoint.
- S5 completo, autorizado pelo usuário: `src/composer.json` com `dompdf/dompdf ^3.1.6` e `firebase/php-jwt ^7.0`, `composer update` dessas dependências no container e o hash de `composer.json` em `framework_hashes.php` regenerado no mesmo commit (T-04).
- Ondas 7 e 8 — correção da revisão final (`reviews/final.md`), BASE da Onda 7 `fbe280f`: TDD com RED commitado, nenhuma chamada real ao Stripe, nada no `.env` real; o cancelamento e o estorno automáticos do pedido pago com `login_conflict` seguem a recomendação do planejamento, pendente de confirmação do usuário (registrado em `notes.md`).
- Regras de UI do `CLAUDE.md`: rótulos e cabeçalhos à esquerda, alvos de 44 px. Mensagens em pt-BR via `_t()` com `^1..^4`; `translations.json` só com `en`/`pt`, ordenado por `en`.

## Escopo

### Incluso
- F4 seleção do plano e pedido com valor e ciclo do servidor (T-07, T-16).
- F4 responsável e aceite dos termos separado do consentimento de contato (T-02, T-16, T-20).
- F4 Checkout Stripe correlacionando pedido, cliente e cobrança (`client_reference_id`, `metadata[billing_order]`, `Idempotency-Key`) (T-08, T-16).
- F4 confirmação só por webhook com `Stripe-Signature`; o retorno do navegador só mostra mensagem (T-08, T-17, T-20).
- F4 provisionamento idempotente e retomável de tenant, unidade, administrador, permissões e assinatura; falha vira `provisioning_failed` sem nova cobrança (T-11, T-13, T-18).
- F4 convite com `PasswordResetToken` (72 h) e `PublicUrl`, e primeiro acesso que ativa o `tenant_user` (T-12, T-13, T-19).
- F5 assinatura com período, estado e IDs externos; faturas (T-02, T-07, T-10, T-14).
- F5 eventos com ID único e deduplicação (T-02, T-09, T-17).
- F5 reconciliação de eventos perdidos (T-18).
- F5 renovação, atraso, tolerância de 7 dias, recuperação, cancelamento e estorno (T-07, T-14, T-18, T-23).
- F5 renovação Pix: fatura local com PaymentIntent Pix por período, e-mail com o link, vencimento local e regeneração do Pix expirado (T-07, T-10, T-23, T-18).
- F5 guarda contra evento antigo sobrescrevendo um mais recente (T-07, T-14).
- Direitos por plano: quotas de usuários e unidades, recursos por programa, bloqueio por estado (ativa, em tolerância, suspensa), isolamento entre tenants (T-07, T-11, T-15, T-19, T-21, T-24, T-25); quotas aplicadas só na gestão por clínica (T-24, T-25).
- Pendência (a): `FOR UPDATE` no `cash_session` no fechamento e nos lançamentos, teste em duas conexões (T-03).
- Gestão de equipe e unidades por clínica (decisão do usuário depois da revisão da T-11): listar, convidar, editar, desativar e reativar usuários da própria clínica; atribuir só o grupo do plano; criar e renomear unidades com `tenant_id`; quotas, estado da assinatura, CSRF central e RBAC; isolamento entre clínicas (T-24, T-25).
- Pendência (b) S5: dompdf ≥ 3.1.6 e php-jwt ^7 no `composer.json` e no lock, hash regenerado, código JWT na API 7, regressão de PDFs, login por token e reset (T-04).
- Pendência (c) S6: `download.php` desabilitado, usos mapeados (T-05).
- Pendência (d): botão de cancelar escondido em venda cancelada, nomes no recibo, mensagens traduzidas, rótulos de pagamento unificados (T-01, T-06).
- Mensagens pt-BR novas (T-01).
- Cenários de aquisição da auditoria e fumaça no navegador (T-22).
- Correção da revisão final: o checkout recusa com 422, antes do Stripe e sem consumir o token, o e-mail que já é login ou que já tem pedido pago (T-27).
- Correção da revisão final: pedido pago que termina em `login_conflict` tem a Subscription do cartão cancelada no Stripe, o pagamento estornado (cartão e Pix), a assinatura local cancelada, o estado terminal `refunded` e o alerta ao operador (`error_log` estruturado e e-mail para `BILLING_OPERATOR_EMAIL`), de forma idempotente e retomável (T-26, T-31).
- Correção da revisão final: preço do plano não configurado responde 502 antes do `POST /v1/customers`, sem pedido `pending` (T-27); `CHECKOUT_RATE_LIMIT_*` no `.env.example` (T-27); evento Stripe de outra integração sai `ignored` (T-28); mensagem de recurso de outra clínica em pt-BR (T-29); README e inventário CSRF sem `download.php` como caminho de download (T-30).

### Excluído
- Pagamentos de tutores à clínica (seguem manuais).
- Mudança de plano (upgrade/downgrade) e proração.
- Assistente de configuração mínima da clínica no primeiro acesso (onboarding da Etapa 5).
- Nota fiscal e cobrança anual.
- Cancelamento iniciado pela clínica no app (no cartão ele chega por `customer.subscription.deleted`; no Pix, a falta de pagamento suspende).
- Fatura Stripe `send_invoice` com Pix e Pix Automático.
- Aplicação no banco de dev ou em produção sem autorização expressa; Stripe em modo real.
- Estorno automático de outras falhas de provisionamento (`group_missing`, `subscription_missing`, erro de banco): seguem para a retomada do reconciliador; estorno parcial; e-mail ao operador a cada falha de estorno (a falha vai só ao `error_log` e a rodada seguinte retoma).
- Revogação de sessões ativas quando a assinatura é suspensa (o bloqueio vale na próxima requisição após o cache de 60 s).

## Contexto técnico
- Camadas envolvidas: database, backend, frontend, infra, shared, docs, qa
- Projeto/base analisada: `/var/www/html/centralvet` (raiz do repositório, `git -C <DIR> rev-parse --show-toplevel`); código em `src/app/Core` (namespace `CentralVet\`, PSR-4), telas em `src/app/control`, endpoints públicos em `src/*.php` (padrão `lead.php`), testes em `src/tests` (runner `tests/run.php`, container `app`, PHP 8.4)
- Integrações: Stripe (API REST `2024-06-20`, Checkout, Subscriptions, Invoices, PaymentIntents Pix, Events, webhooks com `Stripe-Signature`); SMTP/log pelo `EmailProviderFactory` existente

## Baseline
- php-lint: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -l <arquivo>` nos 26 arquivos PHP existentes que o plano modifica ou usa na regressão, em raiz → baseline/php-lint.txt (0 linhas)

## Exploração read-only
- Caminhos relevantes: `src/lead.php`, `src/landing.php`, `src/app/view/landing/landing.html`, `src/landing/{landing.js,translations.js,i18n.js,landing.css}`, `src/app/Core/Landing/{LandingCatalog,LeadSubmissionHandler,LeadFormToken,LeadResponse}.php`; `src/app/Core/Security/{PasswordResetToken,PasswordResetRequest,PublicUrl}.php`, `src/app/control/admin/{SystemPasswordResetForm,SystemRequestPasswordResetForm,SystemUserForm,SystemUnitForm}.php`; `src/app/service/auth/ApplicationAuthenticationService.php` (`checkMultiSession` → `enforceMutationGuard`, `onAfterLogin`, `resolveTenantId`); `src/app/Core/Tenancy/TenantContext.php`; `src/app/database/migrations/20260920_0001_foundation_multitenancy.sql` (`tenant`, `tenant_user`, `tenant_group`, `system_unit.tenant_id`); `src/app/database/permission.sql` (ids `system_*` sem AUTO_INCREMENT); `src/app/database/seeds/initial-application-programs.sql`; `src/app/Core/Application/{CashSessionService,PaymentService,PaymentReversalService}.php`; `src/app/Core/Presentation/{SaleReceiptHtml,UserMessage}.php`, `src/app/lib/widget/CvFormat.php`, `src/app/control/clinic/{SaleCancelForm,SaleList}.php`; `src/download.php`; `src/composer.json`/`composer.lock`; `src/app/config/framework_hashes.php`; `src/app/Core/Communication/{EmailProviderFactory,OutgoingMessage}.php`; `src/app/Core/Persistence/PdoConnectionFactory.php`; `src/bin/worker.php`; `docs/runbooks/shared-hosting-mysql57.md`; `docs/security/csrf-inventario.md`
- Padrões identificados: endpoint público sem sessão Adianti (`lead.php` + handler em Core devolvendo `LeadResponse`); repositórios PDO em `CentralVet\Persistence`, fakes em `src/tests/Support`; `FOR UPDATE` com guarda de `inTransaction()`; migrations `.sql` + `.verify.sql` com CHECK nomeado; seeds idempotentes com `COALESCE(MAX(id),0)+1` e `NOT EXISTS`; cron one-shot com `flock` (`worker.php --once`); mensagens cruas em inglês → `UserMessage` → `_t()`
- Scripts úteis: LINT, SUITE e MYSQLTEST no cabeçalho de `tasks.md`; `python3 scripts/prepare-mysql57.py <dir> <dir>/out`; `sh scripts/test-db/provision.sh --check`; `docker run composer:2.8.11` para o lock
- Riscos identificados: `composer.json`, `engine.php`, `index.php` e `init.php` protegidos por hash (só o `composer.json` liberado, para T-04, com o hash regenerado); grupo 1 é o administrador da plataforma (não pode ir para o admin da clínica); ids de `system_*` por MAX+1 (corrida no provisionamento: `FOR UPDATE`); Pix recorrente no Stripe BR; testes MySQL com lock implícito do INSERT (pendência da Etapa 2); o container :8081 serve a imagem (rebuild para UI e vendor); código novo sem a 0017 aplicada quebra o login (guard lê `subscription`)

## Mapa de arquivos

| Arquivo | Responsabilidade | Ação | Tasks que tocam |
|---|---|---|---|
| `src/app/config/translations.json` ⚠ | Textos pt-BR da Etapa 3 (T-01, Onda 1) e da equipe e unidades (T-24, Onda 4) | modificar | T-01, T-24 |
| `src/app/Core/Presentation/UserMessage.php` ⚠ | Mensagem crua → chave (T-01, Onda 1; T-24, Onda 4; mensagem de tenant na T-29, Onda 7) | modificar | T-01, T-24, T-29 |
| `src/tests/Unit/Etapa3MessagesTest.php` | Chaves e mapeamentos da Etapa 3 | criar | T-01 |
| `src/app/database/migrations/20261021_0017_etapa3_billing.sql` | Schema de cobrança | criar | T-02 |
| `src/app/database/migrations/20261021_0017_etapa3_billing.verify.sql` | Conferência só com SELECT | criar | T-02 |
| `scripts/test-db/provision.sh` ⚠ | Lista de schema do banco de teste (T-02, Onda 1; 0018 na T-26, Onda 7) | modificar | T-02, T-26 |
| `docs/runbooks/tests.md` ⚠ | Lista de migrations do banco de teste (T-02, Onda 1; 0018 na T-26, Onda 7) | modificar | T-02, T-26 |
| `docs/runbooks/shared-hosting-mysql57.md` ⚠ | Seção da 0017 (T-02, Onda 1); cron da reconciliação e endpoint Stripe (T-18, Onda 5); seção da 0018 (T-26, Onda 7); `BILLING_OPERATOR_EMAIL` e `orders_refunded` (T-31, Onda 8) | modificar | T-02, T-18, T-26, T-31 |
| `src/app/Core/Domain/Contract/CashSessionRepositoryInterface.php` | Leituras com bloqueio | modificar | T-03 |
| `src/app/Core/Persistence/CashSessionRepository.php` | `FOR UPDATE` do caixa | modificar | T-03 |
| `src/tests/Support/FakeCashSessionRepository.php` | Fake dos métodos novos | modificar | T-03 |
| `src/app/Core/Application/CashSessionService.php` | Fechamento com bloqueio | modificar | T-03 |
| `src/app/Core/Application/PaymentService.php` | Lançamento com bloqueio | modificar | T-03 |
| `src/app/Core/Application/PaymentReversalService.php` | Estorno com bloqueio | modificar | T-03 |
| `src/tests/Unit/CashSessionLockTest.php` | Ordem bloqueio → escrita | criar | T-03 |
| `src/tests/Integration/CashSessionCloseRaceIntegrationTest.php` | Duas conexões, fechamento × lançamento | criar | T-03 |
| `src/composer.json` | dompdf `^3.1.6` e php-jwt `^7.0` | modificar | T-04 |
| `src/composer.lock` | dompdf ≥ 3.1.6 e php-jwt ^7 | modificar | T-04 |
| `src/app/config/framework_hashes.php` | Hash sha512 do `composer.json` | modificar | T-04 |
| `src/app/service/auth/ApplicationAuthenticationRestService.php` | `JWT::encode` na API 7 | modificar | T-04 |
| `src/app/Core/Security/PasswordResetToken.php` ⚠ | Chave mínima (T-04, Onda 1); TTL por chamada (T-12, Onda 2) | modificar | T-04, T-12 |
| `src/tests/Unit/DependencyAdvisoryTest.php` | Versões e regressão de PDF/JWT | criar | T-04 |
| `docs/security/dependencias-s5.md` | Decisão S5 | criar | T-04 |
| `src/download.php` | Endpoint desabilitado | modificar | T-05 |
| `src/tests/Unit/DownloadEndpointDisabledTest.php` | 404 sem leitura | criar | T-05 |
| `docs/security/downloads-s6.md` | Mapa dos usos e decisão S6 | criar | T-05 |
| `src/app/control/clinic/SaleCancelForm.php` | Sem ação em venda cancelada | modificar | T-06 |
| `src/app/Core/Presentation/SaleReceiptHtml.php` | Nomes e rótulos unificados | modificar | T-06 |
| `src/app/Core/Persistence/SaleFinanceReader.php` | `tutor_name`/`patient_name` | modificar | T-06 |
| `src/app/lib/widget/CvFormat.php` | `paymentMethod` lê `Payment::METHOD_LABELS` | modificar | T-06 |
| `src/app/Core/Domain/Payment.php` | `METHOD_LABELS` | modificar | T-06 |
| `src/tests/Unit/SaleReceiptHtmlTest.php` | Nomes no recibo | modificar | T-06 |
| `src/tests/Unit/PaymentMethodLabelsTest.php` | Rótulos iguais | criar | T-06 |
| `src/tests/Unit/SaleCancelFormCancelledStateTest.php` | Cortina em venda cancelada | criar | T-06 |
| `src/tests/Integration/SaleFinanceReaderNamesIntegrationTest.php` | Nomes do mesmo tenant | criar | T-06 |
| `src/app/Core/Billing/PlanEntitlements.php` | Quotas e recursos por plano | criar | T-07 |
| `src/app/Core/Billing/Subscription.php` | Estado da assinatura e guarda de evento antigo | criar | T-07 |
| `src/app/Core/Billing/SubscriptionInvoice.php` | Fatura | criar | T-07 |
| `src/app/Core/Billing/BillingOrder.php` ⚠ | Pedido da landing (T-07, Onda 1); estado `refunded` e passos do estorno (T-26, Onda 7) | criar | T-07, T-26 |
| `src/tests/Unit/PlanEntitlementsTest.php` | Direitos por plano | criar | T-07 |
| `src/tests/Unit/SubscriptionTest.php` | Transições e tolerância | criar | T-07 |
| `src/tests/Unit/SubscriptionInvoiceTest.php` | Fatura e estorno | criar | T-07 |
| `src/tests/Unit/BillingOrderTest.php` ⚠ | Pedido idempotente (T-07, Onda 1); transições do estorno (T-26, Onda 7) | criar | T-07, T-26 |
| `src/app/Core/Billing/Stripe/StripeClientInterface.php` | Contrato do cliente | criar | T-08 |
| `src/app/Core/Billing/Stripe/CurlStripeClient.php` | HTTP via cURL | criar | T-08 |
| `src/app/Core/Billing/Stripe/StripeConfig.php` | Variáveis `STRIPE_*` | criar | T-08 |
| `src/app/Core/Billing/Stripe/StripeSignatureVerifier.php` | `Stripe-Signature` | criar | T-08 |
| `src/app/Core/Billing/Stripe/StripeApiException.php` | Erro da API | criar | T-08 |
| `src/app/Core/Billing/Stripe/InvalidWebhookSignature.php` | Assinatura inválida | criar | T-08 |
| `src/tests/Support/FakeStripeClient.php` | Fake sem rede | criar | T-08 |
| `src/tests/Unit/StripeSignatureVerifierTest.php` | Assinatura | criar | T-08 |
| `src/tests/Unit/CurlStripeClientTest.php` | Montagem da requisição | criar | T-08 |
| `.env.example` ⚠ | Placeholders `STRIPE_*` (T-08, Onda 1); `CHECKOUT_RATE_LIMIT_*` comentadas (T-27, Onda 7); `BILLING_OPERATOR_EMAIL=` (T-31, Onda 8) | modificar | T-08, T-27, T-31 |
| `docker-compose.yml` ⚠ | `STRIPE_*` no `app-environment` (T-08, Onda 1); `BILLING_OPERATOR_EMAIL` (T-31, Onda 8) | modificar | T-08, T-31 |
| `src/app/Core/Billing/Contract/BillingOrderRepositoryInterface.php` ⚠ | Contrato do pedido (T-09, Onda 2); `listLoginConflictsToRefund` (T-26, Onda 7) | criar | T-09, T-26 |
| `src/app/Core/Billing/Contract/BillingEventRepositoryInterface.php` | Contrato do evento | criar | T-09 |
| `src/app/Core/Persistence/BillingOrderRepository.php` ⚠ | Pedido no MySQL (T-09, Onda 2); `login_conflict` fora da retomada e listado para estorno (T-26, Onda 7) | criar | T-09, T-26 |
| `src/app/Core/Persistence/BillingEventRepository.php` | Evento com deduplicação | criar | T-09 |
| `src/tests/Support/FakeBillingOrderRepository.php` ⚠ | Fake (T-09, Onda 2); listagens do estorno (T-26, Onda 7) | criar | T-09, T-26 |
| `src/tests/Support/FakeBillingEventRepository.php` | Fake | criar | T-09 |
| `src/tests/Integration/BillingOrderRepositoryIntegrationTest.php` ⚠ | Pedido e bloqueio (T-09, Onda 2); colunas do estorno e listagens (T-26, Onda 7) | criar | T-09, T-26 |
| `src/tests/Integration/BillingEventRepositoryIntegrationTest.php` | `claim` e duplicidade | criar | T-09 |
| `src/app/Core/Billing/Contract/SubscriptionRepositoryInterface.php` | Contrato da assinatura | criar | T-10 |
| `src/app/Core/Billing/Contract/SubscriptionInvoiceRepositoryInterface.php` | Contrato da fatura | criar | T-10 |
| `src/app/Core/Persistence/SubscriptionRepository.php` | Assinatura no MySQL | criar | T-10 |
| `src/app/Core/Persistence/SubscriptionInvoiceRepository.php` | Fatura no MySQL | criar | T-10 |
| `src/tests/Support/FakeSubscriptionRepository.php` | Fake | criar | T-10 |
| `src/tests/Support/FakeSubscriptionInvoiceRepository.php` | Fake | criar | T-10 |
| `src/tests/Integration/SubscriptionRepositoryIntegrationTest.php` | Ida e volta, tenant e bloqueio | criar | T-10 |
| `src/app/Core/Billing/DuplicateInvoice.php` | `local_key` repetido | criar | T-10 |
| `src/app/database/seeds/plan-groups.sql` ⚠ | Grupos dos 4 planos (T-11, Onda 2); `SubscriptionStatusView` (T-21, Onda 4); programas de equipe e unidades (T-25, Onda 5) | criar | T-11, T-21, T-25 |
| `src/tests/Unit/PlanGroupsSeedTest.php` ⚠ | Seed = PlanEntitlements (T-11, Onda 2); programas de equipe e unidades, se o conjunto esperado não derivar do seed inicial (T-25, Onda 5) | criar | T-11, T-25 |
| `src/app/Core/Billing/AccountInviteService.php` | Convite por e-mail | criar | T-12 |
| `src/tests/Unit/AccountInviteServiceTest.php` | Link, TTL e uso único | criar | T-12 |
| `src/app/Core/Billing/TenantProvisioningService.php` | Provisionamento retomável | criar | T-13 |
| `src/app/Core/Billing/ProvisioningResult.php` | Resultado | criar | T-13 |
| `src/app/Core/Billing/ProvisioningConflict.php` | Login já existente | criar | T-13 |
| `src/app/Core/Billing/TransactionRunner.php` | Transação ou savepoint | criar | T-13 |
| `src/tests/Integration/TenantProvisioningIntegrationTest.php` | Idempotência e retomada | criar | T-13 |
| `src/app/Core/Billing/BillingEventProcessor.php` ⚠ | Eventos → domínio (T-14, Onda 3); evento de outra integração `ignored` (T-28, Onda 7) | criar | T-14, T-28 |
| `src/app/Core/Billing/BillingEventOutcome.php` | Resultado do evento | criar | T-14 |
| `src/app/Core/Billing/BillingEventOutOfOrder.php` | Dependência ausente | criar | T-14 |
| `src/tests/Unit/BillingEventProcessorTest.php` ⚠ | Tipos, ordem e duplicidade (T-14, Onda 3); nosso × de outra integração (T-28, Onda 7) | criar | T-14, T-28 |
| `src/tests/Support/Fixtures/stripe-events.php` ⚠ | Eventos de exemplo (T-14, Onda 3); `$billingOrder` opcional com a marca do Central Vet (T-28, Onda 7) | criar | T-14, T-28 |
| `src/app/Core/Billing/SubscriptionAccessGuard.php` | Acesso por estado e plano | criar | T-15 |
| `src/app/Core/Billing/AccessDecision.php` | Decisão | criar | T-15 |
| `src/app/Core/Billing/QuotaGuard.php` | Quotas | criar | T-15 |
| `src/app/Core/Billing/QuotaExceededException.php` | Quota excedida | criar | T-15 |
| `src/app/Core/Billing/Contract/TenantUsageReaderInterface.php` | Contagem de uso | criar | T-15 |
| `src/app/Core/Persistence/TenantUsageReader.php` | Contagem no MySQL | criar | T-15 |
| `src/tests/Support/FakeTenantUsageReader.php` | Fake | criar | T-15 |
| `src/tests/Unit/SubscriptionAccessGuardTest.php` | Decisões | criar | T-15 |
| `src/tests/Unit/QuotaGuardTest.php` | Limites | criar | T-15 |
| `src/tests/Integration/TenantUsageReaderIntegrationTest.php` | Contagem por tenant | criar | T-15 |
| `src/app/Core/Billing/CheckoutStartHandler.php` ⚠ | Pedido e Checkout (T-16, Onda 3); e-mail cadastrado e preço não configurado antes do Stripe (T-27, Onda 7) | criar | T-16, T-27 |
| `src/checkout.php` ⚠ | POST público do checkout (T-16, Onda 3); `AccountEmailReader` (T-27, Onda 7) | criar | T-16, T-27 |
| `src/app/Core/Landing/LandingCatalog.php` | Termos e formas de pagamento | modificar | T-16 |
| `src/tests/Unit/LandingCatalogTest.php` | `publicJson` com termos | modificar | T-16 |
| `src/tests/Unit/CheckoutStartHandlerTest.php` ⚠ | Validação e preço do servidor (T-16, Onda 3); e-mail cadastrado e preço vazio (T-27, Onda 7) | criar | T-16, T-27 |
| `docs/security/csrf-inventario.md` ⚠ | `checkout.php` (T-16, Onda 3); `billing-webhook.php` (T-17, Onda 4); `ClinicUserForm` e `ClinicUnitForm` (T-25, Onda 5); `download.php` desabilitado (T-30, Onda 7) | modificar | T-16, T-17, T-25, T-30 |
| `src/app/Core/Billing/BillingEventIngestor.php` | Claim, processo e provisionamento | criar | T-17 |
| `src/app/Core/Billing/BillingWebhookHandler.php` | Webhook HTTP | criar | T-17 |
| `src/billing-webhook.php` | POST público do webhook | criar | T-17 |
| `src/tests/Integration/BillingWebhookIntegrationTest.php` | Assinatura, duplicado, fora de ordem | criar | T-17 |
| `src/app/Core/Billing/BillingReconciler.php` ⚠ | Reconciliação (T-18, Onda 5); fase `refundLoginConflicts` e `orders_refunded` (T-31, Onda 8) | criar | T-18, T-31 |
| `src/bin/billing-reconcile.php` ⚠ | Cron (T-18, Onda 5); serviço de estorno e `BILLING_OPERATOR_EMAIL` (T-31, Onda 8) | criar | T-18, T-31 |
| `src/tests/Integration/BillingReconcilerIntegrationTest.php` ⚠ | Eventos perdidos e retomada (T-18, Onda 5); estorno na rodada (T-31, Onda 8) | criar | T-18, T-31 |
| `src/app/service/auth/ApplicationAuthenticationService.php` ⚠ | `fromToken` na API 7 (T-04, Onda 1); bloqueio e primeiro acesso (T-19, Onda 4) | modificar | T-04, T-19 |
| `src/app/Core/Tenancy/TenantUserActivation.php` | `invited` → `active` | criar | T-19 |
| `src/tests/Unit/SubscriptionAccessWiringTest.php` | Wiring do bloqueio e do primeiro acesso | criar | T-19 |
| `src/tests/Integration/TenantUserActivationIntegrationTest.php` | Ativação | criar | T-19 |
| `src/app/view/landing/landing.html` | Seção de compra | modificar | T-20 |
| `src/landing/landing.js` | Envio ao checkout | modificar | T-20 |
| `src/landing/translations.js` | Textos pt/en da compra | modificar | T-20 |
| `src/landing/landing.css` | Estilos da seção | modificar | T-20 |
| `src/tests/Unit/LandingCheckoutWiringTest.php` | Wiring da landing | criar | T-20 |
| `src/app/control/admin/SubscriptionStatusView.php` | Tela da assinatura | criar | T-21 |
| `src/app/Core/Presentation/SubscriptionStatusPresenter.php` | Dados da tela | criar | T-21 |
| `src/app/database/seeds/initial-application-programs.sql` ⚠ | Programa `SubscriptionStatusView` (T-21, Onda 4); programas de equipe e unidades (T-25, Onda 5) | modificar | T-21, T-25 |
| `src/menu.xml` ⚠ | Item "Assinatura" (T-21, Onda 4); "Equipe" e "Unidades da clínica" (T-25, Onda 5) | modificar | T-21, T-25 |
| `src/tests/Unit/SubscriptionStatusPresenterTest.php` | Resumo e faturas | criar | T-21 |
| `src/tests/Integration/BillingAcquisitionFlowIntegrationTest.php` ⚠ | Cenários (a)–(o) (T-22, Onda 6); `AccountEmailReader` no builder (T-27, Onda 7); cenário de corrida com estorno (T-31, Onda 8) | criar | T-22, T-27, T-31 |
| `src/app/Core/Billing/PixInvoiceIssuer.php` | Fatura Pix e PaymentIntent por período | criar | T-23 |
| `src/tests/Unit/PixInvoiceIssuerTest.php` | Emissão, repetição e regeneração | criar | T-23 |
| `src/app/Core/Tenancy/ClinicTeamService.php` | Equipe por tenant, grupos do plano, quota e convite | criar | T-24 |
| `src/app/control/admin/ClinicUserList.php` | Lista da equipe | criar | T-24 |
| `src/app/control/admin/ClinicUserForm.php` | Convite, edição, desativação | criar | T-24 |
| `src/tests/Integration/ClinicTeamServiceIntegrationTest.php` | Isolamento, grupos, quota, convite | criar | T-24 |
| `src/tests/Unit/ClinicTeamScreensWiringTest.php` | Combos filtrados e rótulos | criar | T-24 |
| `src/tests/Unit/Etapa3TeamMessagesTest.php` | Chaves da equipe e das unidades | criar | T-24 |
| `src/app/Core/Tenancy/ClinicUnitService.php` | Unidades por tenant com quota | criar | T-25 |
| `src/app/control/admin/ClinicUnitList.php` | Lista de unidades | criar | T-25 |
| `src/app/control/admin/ClinicUnitForm.php` | Criação e edição de unidade | criar | T-25 |
| `src/tests/Integration/ClinicUnitServiceIntegrationTest.php` | Isolamento, quota, `tenant_id` | criar | T-25 |
| `src/tests/Unit/ClinicUnitScreensWiringTest.php` | Seed, menu, inventário e rótulos | criar | T-25 |
| `src/app/database/migrations/20261026_0018_billing_order_refund.sql` | Colunas do estorno e `refunded` no CHECK | criar | T-26 |
| `src/app/database/migrations/20261026_0018_billing_order_refund.verify.sql` | Conferência só com SELECT | criar | T-26 |
| `src/app/Core/Billing/Contract/AccountEmailReaderInterface.php` | E-mail já cadastrado | criar | T-27 |
| `src/app/Core/Persistence/AccountEmailReader.php` | Login ou pedido pago no MySQL | criar | T-27 |
| `src/tests/Support/FakeAccountEmailReader.php` | Fake | criar | T-27 |
| `src/tests/Integration/AccountEmailReaderIntegrationTest.php` | Login, caixa e status do pedido | criar | T-27 |
| `src/tests/Unit/UserMessageTest.php` | Contagem de `STATIC` (99 → 100) | modificar | T-29 |
| `src/tests/Unit/TenantBoundaryMessageTest.php` | Mensagem de tenant em pt-BR | criar | T-29 |
| `README.md` | Downloads e `download.php` desabilitado | modificar | T-30 |
| `src/app/Core/Billing/LoginConflictRefundService.php` | Cancelamento, estorno e alerta | criar | T-31 |
| `src/tests/Integration/LoginConflictRefundIntegrationTest.php` | Cartão, Pix, retomada e idempotência | criar | T-31 |

Os arquivos ⚠ são serializados em ondas diferentes: o runbook nas Ondas 1 e 5; o `PasswordResetToken` nas Ondas 1 e 2; o `ApplicationAuthenticationService` nas Ondas 1 e 4; `translations.json` e `UserMessage.php` nas Ondas 1 e 4; `plan-groups.sql` nas Ondas 2, 4 e 5; `PlanGroupsSeedTest` nas Ondas 2 e 5; o inventário CSRF nas Ondas 3, 4 e 5; `initial-application-programs.sql` e `menu.xml` nas Ondas 4 e 5. Na correção da revisão final, cada arquivo de task anterior tocado nas Ondas 7 e 8 tem uma task só por onda: `.env.example` (T-27 na 7, T-31 na 8), `BillingAcquisitionFlowIntegrationTest` (T-27 na 7, T-31 na 8), o runbook de MySQL 5.7 (T-26 na 7, T-31 na 8) e os demais com uma task nova cada.

## Decisões de arquitetura

| Decisão | Alternativas consideradas | Motivo da escolha |
|---|---|---|
| Cliente Stripe próprio (`StripeClientInterface::request` + cURL, API `2024-06-20`) | `stripe/stripe-php` via composer | A autorização do `composer.json` foi para o S5; o uso é pequeno (customers, checkout sessions, subscriptions, payment intents, events) e a assinatura do webhook é HMAC-SHA256; o `$transport` e o `FakeStripeClient` testam sem rede; sem dependência nova para auditar e implantar na hospedagem compartilhada; a versão da API fica numa constante (detalhes em `notes.md`) |
| Confirmação só por webhook com `Stripe-Signature`; `success_url` só mostra mensagem | Liberar no retorno do navegador | Exigência da auditoria; o retorno é forjável |
| `billing_event` com UNIQUE `(provider, event_id)` e `claim` em transação própria antes do processamento | Deduplicar só pelo estado do domínio | O evento repetido sai como `duplicate` sem reprocessar; o `failed` fica registrado mesmo com rollback do processamento e é reprocessável pelo reenvio do Stripe ou pelo reconciliador |
| Guarda de evento antigo por `last_event_created` na assinatura e na fatura | Comparar status | O `created` do evento ordena as mudanças; um `active` antigo não desfaz um `past_due` novo |
| Evento que depende de algo não registrado (`invoice.paid` antes do checkout) → 500 e `failed` | Ignorar | O Stripe reenvia e o reconciliador busca de novo; nada se perde |
| Provisionamento numa transação (`TransactionRunner`) com chaves determinísticas e falha registrada noutra transação | Saga com passos commitados | Tudo ou nada evita tenant pela metade; a retomada repete a transação; o convite é passo separado, reenviável sem reprovisionar; nunca chama o Stripe |
| Assinatura criada no checkout com `tenant_id` nulo e ligada no provisionamento | Criar só no provisionamento | Faturas e eventos de renovação têm onde cair mesmo com o provisionamento atrasado |
| Grupos por plano (`plan-groups.sql`) + `SubscriptionAccessGuard` no `checkMultiSession` | Só RBAC; só guard | O RBAC esconde o que o plano não tem; o guard é a barreira do servidor e cobre estado da assinatura; o grupo 1 (plataforma) nunca vai para a clínica |
| Pix: 1º mês por Checkout `mode=payment`; renovação por fatura local com PaymentIntent Pix avulso (T-23), emitida pelo reconciliador e paga por `payment_intent.succeeded` | Subscription `send_invoice` com Pix; Pix em `mode=subscription` | Decisão do usuário: não se sabe se a conta aceita Pix em `send_invoice`. O PaymentIntent Pix avulso funciona em qualquer conta com Pix; o ciclo, o vencimento e a tolerância ficam no domínio, iguais aos do cartão |
| Gestão por clínica em telas próprias (`ClinicTeamService`, `ClinicUnitService`) | Corrigir `SystemUserForm`/`SystemUnitForm` para filtrar por tenant | As telas de sistema servem o grupo 1 (plataforma) e listam grupos e unidades de todas as clínicas; telas novas com serviço no Core filtram tudo pelo `TenantContext`, aceitam só o grupo do plano e são testáveis no MySQL sem o Adianti. Decisão do usuário depois da revisão da T-11 |
| Pedido pago com `login_conflict`: o reconciliador cancela a Subscription (cartão), estorna o pagamento (cartão e Pix), cancela a assinatura local e marca `refunded`, com cada passo gravado no pedido (T-26, T-31) | Só recusar no checkout; alertar o operador e estornar à mão; estornar no webhook | A recusa no checkout (T-27) não fecha a corrida entre o checkout e o webhook; o estorno à mão deixa o cartão cobrando até alguém agir. O reconciliador já roda a cada 15 min, fora da requisição do webhook, e a cobrança seguinte é mensal; colunas por passo e `Idempotency-Key` (`cancel-`/`refund-<public_id>`) tornam a rodada repetível. O provisionamento continua sem cliente Stripe |
| Evento sem a marca do Central Vet (`client_reference_id`/`metadata.billing_order`) e sem registro local → `ignored` (T-28) | Ignorar todo evento sem registro local | Mantém o fora de ordem real (o Stripe reenvia e o reconciliador busca) só para os eventos nossos; os de outra integração na mesma conta param de falhar em loop |
| `download.php` desabilitado (404) | Resolver arquivo por id com checagem de tenant | Nenhum fluxo do app serve arquivo por ele hoje; documentos clínicos têm mecanismo próprio |
| php-jwt `^7.0` e dompdf `^3.1.6` no `composer.json`, com o hash regenerado no mesmo commit | Ficar em `^6.0` com mitigação | Autorizado pelo usuário; fecha os avisos de fato. O `PasswordResetToken` recusa chave < 32 bytes com mensagem clara antes do php-jwt 7 |

## Diagrama de dependências

```text
T-01, T-04 → T-12
T-02, T-07 → T-09, T-10
T-07 → T-11
T-02, T-07, T-09, T-10, T-11, T-12 → T-13
T-07, T-08, T-09, T-10 → T-14
T-07, T-10 → T-15
T-01, T-07, T-08, T-09 → T-16
T-08, T-09, T-13, T-14, T-16 → T-17
T-01, T-15 → T-19
T-16 → T-20
T-01, T-07, T-10, T-11, T-15 → T-21
T-01, T-07, T-08, T-10 → T-23
T-08, T-09, T-10, T-13, T-17, T-23 → T-18
T-01, T-07, T-10, T-11, T-12, T-13, T-15 → T-24
T-07, T-11, T-13, T-15, T-21, T-24 → T-25
T-17, T-18, T-19, T-20, T-21, T-24, T-25 → T-22
T-03, T-05, T-06 (sem dependentes)
T-02, T-07, T-09 → T-26 → T-31 (Ondas 7 e 8, correção)
T-08, T-16 → T-27; T-14 → T-28; T-01 → T-29; T-05 → T-30 (Onda 7, correção)
```

## Estratégia de execução
- Branch de trabalho: `task/etapa-3-contratacao`
- Branch base: `task/etapa-2-dinheiro-estoque`
- Commits da onda: cada implementador commita os próprios caminhos (`git commit -- <caminhos>`) com o trailer da task. O fechador usa a skill `new-commit --auto` só se sobrou algo sem commit e sempre registra o estado das tasks num `chore(tasks): registra commits da onda N`. Por premissa do desenvolvedor, a entrega da Fase 5 preserva os commits por onda (não roda o `entrega.py`); push e PR só a pedido dele.
- Isolamento em ondas com edições paralelas: caminho exclusivo (cada agente só nos arquivos da própria task; os arquivos ⚠ estão em ondas diferentes)
- Pré-condição da Onda 2: a 0017 aplicada ao `centralvet_test` (T-02). Sem ela, os testes de integração das Ondas 2–6 saem FAIL.
- Pré-condição de T-04: rede para o packagist no `docker run composer:2.8.11`. O `src/vendor` (fora do git) é atualizado no host; a imagem do :8081 só muda com rebuild de `app` e `worker`.
- Pré-condição da Onda 7: a T-26 aplica a 0018 ao `centralvet_test` e commita a migration antes do `BillingOrder::toRow` com as colunas novas (as outras tasks da onda rodam integração no mesmo banco). Em dev e produção, a 0018 vai antes do código das Ondas 7 e 8.
- Validação cruzada das Ondas 7 e 8: SUITE completo sem filtro de classe (`Failed: 0`, nenhuma classe de integração nova em `SKIP`) e LINT de cada PHP tocado na onda (`git diff --name-only <BASE da onda> -- '*.php'`). BASE da Onda 7: `fbe280f`.
- Pré-condição da Onda 6: o orquestrador pergunta ao usuário se autoriza aplicar a 0017 e os seeds no banco de dev `centralvet`; sem autorização, a fumaça no navegador de T-22 fica pendente.

## Ondas de execução

### Onda 1
- T-01, T-02, T-03, T-04, T-05, T-06, T-07, T-08

### Onda 2
- T-09, T-10, T-11, T-12

### Onda 3
- T-13, T-14, T-15, T-16, T-23

### Onda 4
- T-17, T-19, T-20, T-21, T-24

### Onda 5
- T-18, T-25

### Onda 6
- T-22

### Onda 7 — correção (revisão final)
- T-26, T-27, T-28, T-29, T-30

### Onda 8 — correção (revisão final)
- T-31

## Agentes

| Agente | subagent_type | model | Tasks |
|---|---|---|---|
| Sun Tzu — orquestrador | — | — | todas |
| Maquiavel | general-purpose | inherit | T-01, T-29 |
| Darwin | general-purpose | inherit | T-02, T-11, T-25, T-26 |
| Sherlock | general-purpose | inherit | T-03 |
| Yoda | general-purpose | inherit | T-04 |
| Jaspion | general-purpose | inherit | T-05, T-15, T-24 |
| Levi | general-purpose | inherit | T-06 |
| Platão | general-purpose | inherit | T-07 |
| Tesla | general-purpose | inherit | T-08, T-23 |
| Arquimedes | general-purpose | inherit | T-09 |
| Spock | general-purpose | inherit | T-10 |
| Gandalf | general-purpose | inherit | T-12 |
| Thanos | general-purpose | inherit | T-13 |
| Hegel | general-purpose | inherit | T-14, T-28 |
| Batman | general-purpose | inherit | T-16, T-27 |
| Kratos | general-purpose | inherit | T-17 |
| Naruto | general-purpose | inherit | T-18, T-31 |
| Aang | general-purpose | inherit | T-19 |
| Saitama | general-purpose | inherit | T-20 |
| Athena | general-purpose | inherit | T-21 |
| Goku | general-purpose | inherit | T-22 |
| Gandalf (docs) | geduc:documentador | sonnet | T-30 |

## Review Focus
- Pedido de cartão pago cujo e-mail virou login entre o checkout e o webhook (corrida) → na rodada do reconciliador, 1 `DELETE /v1/subscriptions/<id>`, 1 `POST /v1/refunds`, pedido `refunded`, assinatura local `cancelled`; a rodada seguinte não faz chamada nova ao Stripe → T-31
- E-mail com caixa e espaços diferentes de um login existente (`' Dono.T27@Clinica.TEST '` contra `dono.t27@clinica.test`) → 422 em `responsible_email`, 0 chamadas ao Stripe e o `form_token` ainda válido → T-27
- `invoice.paid` de uma assinatura nossa (com `subscription_details.metadata.billing_order`) antes do `checkout.session.completed` → continua 500 e `failed`, e o reenvio sai `processed`; só o evento sem a marca vira `ignored` → T-28
- Payload do checkout com `price_cents` ou `amount` forjado → pedido e Checkout com o preço do catálogo (9700 no Pro) → T-16
- `payment_intent.succeeded` de fatura Pix processado depois que o reconciliador marcou a assinatura vencida (`markOverdue`) → fatura `paid` e assinatura `active` com o período novo, sem ser descartado como evento antigo → T-14

## Critérios gerais de aceite
- SUITE completo (cabeçalho de `tasks.md`) com MySQL no ar e a 0017 no `centralvet_test` imprime `Failed: 0` e nenhuma classe de integração nova em `SKIP`.
- LINT de cada arquivo PHP tocado imprime `No syntax errors detected`; nenhuma linha nova em relação a `baseline/php-lint.txt` (0 linhas).
- Ondas 7 e 8: com a 0018 no `centralvet_test`, nenhum pedido existente perdido (`SELECT COUNT(*) FROM billing_order` igual antes e depois da 0018) e o provisionamento ainda sem `StripeClientInterface`.
- `git grep -nE "sk_(live|test)_[A-Za-z0-9]{8,}|whsec_[A-Za-z0-9]{20,}"` sem saída; `git diff task/etapa-2-dinheiro-estoque -- src/lib/adianti src/engine.php src/index.php src/init.php` sem linhas; em `src/composer.json`, só as 2 linhas de versão de T-04; em `framework_hashes.php`, só a linha `composer.json`, com o sha512 atual do arquivo.

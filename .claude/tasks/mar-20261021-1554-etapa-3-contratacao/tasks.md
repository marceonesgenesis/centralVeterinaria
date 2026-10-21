# Tasks

Origem: `.docs/audits/2026-10-09-prontidao-mvp.md`, itens F4, F5, "Ordem recomendada e critérios de liberação" (Etapa 3 — Contratação), mais S5, S6 e as pendências de `.claude/tasks/mar-20261021-1145-etapa-2-dinheiro-estoque/notes.md § Pendências`. Caminhos relativos a `/var/www/html/centralvet`; comandos rodam dessa pasta.

Comandos usados em Teste RED e Validação:
- LINT = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -l <arquivo relativo a src/>` → `No syntax errors detected` (baseline `baseline/php-lint.txt`, 0 linhas: qualquer linha de erro é nova).
- SUITE = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro -v /var/www/html/centralvet/.env.example:/var/www/html/.env.example:ro -v /var/www/html/centralvet/docker-compose.yml:/var/www/html/docker-compose.yml:ro -v /var/www/html/centralvet/docker/php/conf.d/app.ini:/var/www/html/docker/php/conf.d/app.ini:ro -v /var/www/html/centralvet/docs:/var/www/html/docs:ro app php tests/run.php 2>&1 | /usr/bin/grep -E '<Classe>|Failed:'` (o runner imprime `  PASS  <Classe>::<método>` / `  FAIL  ...` e `Total: N, Passed: N, Failed: N, Skipped: N`; os testes MySQL saem `SKIP` com o MySQL fora do ar e `FAIL` com a 0017 ausente no `centralvet_test`).
- MYSQLTEST = `docker compose exec -T mysql sh -ceu 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch centralvet_test'` (root do container mysql, padrão autorizado na Etapa 2 para DDL no banco de teste; nunca contra `centralvet`).
- Testes de integração estendem `CentralVet\Tests\Support\MysqlIntegrationTestCase` (`$this->pdo` em transação, rollback no `tearDown`, guarda contra commit). A segunda conexão segue `SurgeryRepositoryIntegrationTest::secondConnection()` com `SET SESSION innodb_lock_wait_timeout = 1`, `beginTransaction()` e `rollBack()` no `finally`. Serviço que abre a própria transação (T-13, T-17) é testado com `SAVEPOINT`/`ROLLBACK TO SAVEPOINT` ou com o PDO de teste injetado e a guarda de commit respeitada (o serviço aceita um `TransactionRunner` que, no teste, usa savepoints).
- Nenhum teste chama a rede: o Stripe é sempre `CentralVet\Tests\Support\FakeStripeClient` (T-08). A assinatura do webhook usa o segredo de teste `whsec_test_etapa3` gerado no próprio teste.
- Mensagens novas ao usuário só pelas chaves de T-01 (`_t('<en>')`); nenhuma task além de T-01 edita `translations.json` nem `UserMessage.php`. A landing tem i18n própria (`src/landing/translations.js`, T-20).
- Credenciais: nenhuma chave real em código, teste, log, relatório ou prompt. Só placeholders vazios em `.env.example` (T-08). Escrever no `.env` exige confirmação do usuário.

| ID | Camada | Descrição | Dependências | Paralelizável | Complexidade | Agente | Status |
|---|---|---|---|---|---|---|---|
| T-01 | shared | Mensagens pt-BR da Etapa 3 e mapeamentos em UserMessage (inclui "Sale not found" e "Unknown payment method") | — | sim | simples | Maquiavel | [x] |
| T-02 | database | Migration 0017 (billing_order, subscription, subscription_invoice, billing_event) e banco de teste | — | sim | média | Darwin | [x] |
| T-03 | backend | Pendência (a): FOR UPDATE no cash_session ao fechar o caixa e ao lançar recebimento ou estorno | — | sim | média | Sherlock | [x] |
| T-04 | infra | S5: dompdf ≥ 3.1.6 e php-jwt ^7 no composer.json e no lock, hash regenerado, JWT na API 7 | — | sim | alta | Yoda | [x] |
| T-05 | backend | S6: download.php desabilitado (404) e mapa dos usos | — | sim | simples | Jaspion | [x] |
| T-06 | frontend | Pendência (d): cortina de cancelamento sem botão em venda cancelada, nomes no recibo, rótulos de forma de pagamento unificados | — | sim | média | Levi | [x] |
| T-07 | backend | Domínio de cobrança: PlanEntitlements, Subscription, SubscriptionInvoice, BillingOrder | — | sim | alta | Platão | [x] |
| T-08 | backend | Cliente Stripe próprio (interface, cURL, config, verificador de Stripe-Signature, fake) e placeholders de ambiente | — | sim | média | Tesla | [x] |
| T-09 | backend | Repositórios de pedido e de evento (BillingOrderRepository, BillingEventRepository) e fakes | T-02, T-07 | sim | média | Arquimedes | [ ] |
| T-10 | backend | Repositórios de assinatura e fatura (SubscriptionRepository, SubscriptionInvoiceRepository) e fakes | T-02, T-07 | sim | média | Spock | [ ] |
| T-11 | database | Seed dos 4 grupos de plano com os programas de cada plano | T-07 | sim | média | Darwin | [ ] |
| T-12 | backend | AccountInviteService: convite por e-mail com PasswordResetToken de 72 h e PublicUrl | T-01, T-04 | sim | média | Gandalf | [ ] |
| T-13 | backend | TenantProvisioningService: provisionamento idempotente e retomável de tenant, unidade, administrador, permissões e assinatura, com convite | T-02, T-07, T-09, T-10, T-11, T-12 | sim | alta | Thanos | [ ] |
| T-14 | backend | BillingEventProcessor: eventos Stripe (cartão e PaymentIntent Pix) → pedido, assinatura e fatura, com guarda contra evento antigo | T-07, T-08, T-09, T-10 | sim | alta | Hegel | [ ] |
| T-15 | backend | SubscriptionAccessGuard, QuotaGuard e TenantUsageReader | T-07, T-10 | sim | média | Jaspion | [ ] |
| T-16 | backend | CheckoutStartHandler e src/checkout.php: pedido com valor do servidor, aceite dos termos e Checkout Stripe (cartão em assinatura, Pix no 1º mês) | T-01, T-07, T-08, T-09 | sim | alta | Batman | [ ] |
| T-17 | backend | Webhook autenticado: src/billing-webhook.php, BillingWebhookHandler e BillingEventIngestor | T-08, T-09, T-13, T-14, T-16 | sim | alta | Kratos | [ ] |
| T-18 | backend | BillingReconciler e bin/billing-reconcile.php: eventos perdidos, provisionamento pendente, faturas Pix, vencimento e fim da tolerância | T-08, T-09, T-10, T-13, T-17, T-23 | não | alta | Naruto | [ ] |
| T-19 | backend | Bloqueio por assinatura e plano no checkMultiSession, quotas nos formulários de usuário e unidade, primeiro acesso | T-01, T-15 | sim | alta | Aang | [ ] |
| T-20 | frontend | Landing: escolha do plano, responsável, termos, forma de pagamento e retorno do checkout | T-16 | sim | média | Saitama | [ ] |
| T-21 | frontend | SubscriptionStatusView: plano, estado, período, tolerância e faturas com link de pagamento | T-01, T-07, T-10, T-11, T-15 | sim | média | Athena | [ ] |
| T-22 | qa | Integração ponta a ponta dos cenários de aquisição da auditoria e fumaça no navegador (condicional à autorização do dev) | T-17, T-18, T-19, T-20, T-21 | não | alta | Goku | [ ] |
| T-23 | backend | PixInvoiceIssuer: fatura local e PaymentIntent Pix por período, com e-mail do link | T-01, T-07, T-08, T-10 | sim | média | Tesla | [ ] |

## Detalhamento

### T-01 — Mensagens pt-BR da Etapa 3 e mapeamentos em UserMessage

**Camada:** shared
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Maquiavel

**Arquivos prováveis**
- `src/app/config/translations.json`
- `src/app/Core/Presentation/UserMessage.php`
- `src/tests/Unit/Etapa3MessagesTest.php`

**Interface**
- Produz: chaves en→pt em `translations.json` (lista de objetos `{"en": ..., "pt": ...}` ordenada por `en`, sem duplicar chave já existente): `Sale not found` → `Venda não encontrada`; `Unknown payment method "^1"` → `Forma de pagamento desconhecida: "^1"`; `Your plan does not include this feature` → `Seu plano não inclui este recurso`; `Your subscription is suspended. Regularize the payment to continue` → `Sua assinatura está suspensa. Regularize o pagamento para continuar`; `Your plan allows up to ^1 users` → `Seu plano permite até ^1 usuários`; `Your plan allows up to ^1 units` → `Seu plano permite até ^1 unidades`; `Central Vet Pro invitation` → `Convite do Central Vet Pro`; `Your clinic ^1 is ready. Set your password to access Central Vet Pro: ^2` → `Sua clínica ^1 está pronta. Defina sua senha para acessar o Central Vet Pro: ^2`; `This link expires in ^1 hours` → `Este link expira em ^1 horas`; `Subscription` → `Assinatura`; `Plan` → `Plano`; `Current period` → `Período atual`; `Grace period until` → `Tolerância até`; `Invoices` → `Faturas`; `Pay invoice` → `Pagar fatura`; `Amount due` → `Valor devido`; `Amount paid` → `Valor pago`; `Refunded` → `Estornado`; `Overdue` → `Em atraso`; `Suspended` → `Suspensa`; `Incomplete` → `Incompleta`; `No subscription registered for this clinic` → `Esta clínica não tem assinatura registrada`; `Central Vet Pro invoice` → `Fatura do Central Vet Pro`; `Your Central Vet Pro invoice of ^1 is available. Pay with Pix by ^2: ^3` → `Sua fatura do Central Vet Pro de ^1 está disponível. Pague com Pix até ^2: ^3`
- Produz: em `UserMessage::PATTERNS`: `'/^Sale \d+ not found$/D' => 'Sale not found'`, `'/^Unknown payment[ _]method "(.+)"$/D' => 'Unknown payment method "^1"'`, `'/^Your plan allows up to (\d+) users$/D' => 'Your plan allows up to ^1 users'`, `'/^Your plan allows up to (\d+) units$/D' => 'Your plan allows up to ^1 units'`; em `UserMessage::STATIC`: `'Your plan does not include this feature'`, `'Your subscription is suspended. Regularize the payment to continue'` e `'No subscription registered for this clinic'` (chave = texto)
- Consome: nada

**Teste RED**
- `src/tests/Unit/Etapa3MessagesTest.php` — cada chave acima existe em `translations.json` com o `pt` listado e só `^1..^4`; `UserMessage::resolve('Sale 42 not found')` devolve `['key' => 'Sale not found', 'params' => []]`; `resolve('Unknown payment_method "x"')` e `resolve('Unknown payment method "x"')` devolvem a chave `Unknown payment method "^1"` com `['x']`; `resolve('Your plan allows up to 2 users')` devolve `['2']`; o arquivo segue ordenado por `en` e só tem as chaves `en`/`pt` (comando: `SUITE com <Classe> = Etapa3MessagesTest`)

**Critério de aceite**
- SUITE com `Etapa3MessagesTest|Etapa2MessagesTest|UserMessageTest` mostra `PASS` em todos os métodos e `Failed: 0`.
- `python3 -c "import json;d=json.load(open('src/app/config/translations.json'));e=[x['en'] for x in d];print(e==sorted(e), len(e)==len(set(e)), all(set(x)=={'en','pt'} for x in d))"` imprime `True True True`.

**Validação**
- SUITE com `<Classe>` = `Etapa3MessagesTest|Etapa2MessagesTest|UserMessageTest` (evidência: `PASS` em cada método e `Failed: 0`)
- o comando python acima (evidência: `True True True`)
- LINT de `app/Core/Presentation/UserMessage.php` (evidência: `No syntax errors detected`)

### T-02 — Migration 0017 e banco de teste

**Camada:** database
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Darwin

**Arquivos prováveis**
- `src/app/database/migrations/20261021_0017_etapa3_billing.sql`
- `src/app/database/migrations/20261021_0017_etapa3_billing.verify.sql`
- `scripts/test-db/provision.sh`
- `docs/runbooks/tests.md`
- `docs/runbooks/shared-hosting-mysql57.md`

**Interface**
- Produz: tabela `billing_order` (`id` bigint unsigned AI, `public_id` char(36) UNIQUE `billing_order_public_id_uq`, `plan_id` varchar(20) com CHECK `billing_order_plan_ck` em `('starter','pro','business','enterprise')`, `price_cents` int unsigned, `currency` char(3) DEFAULT 'brl', `payment_method` varchar(10) CHECK `billing_order_method_ck` em `('card','pix')`, `responsible_name` varchar(160), `responsible_email` varchar(190), `responsible_phone` varchar(30), `clinic_name` varchar(190), `terms_version` varchar(40), `terms_accepted_at` timestamp(6), `terms_ip` varchar(45), `contact_consent` tinyint(1) DEFAULT 0, `status` varchar(24) CHECK `billing_order_status_ck` em `('pending','checkout_open','paid','provisioned','provisioning_failed','expired')`, `stripe_customer_id` varchar(64) NULL, `stripe_checkout_session_id` varchar(100) NULL UNIQUE `billing_order_checkout_uq`, `stripe_subscription_id` varchar(64) NULL, `paid_at` timestamp(6) NULL, `tenant_id` bigint unsigned NULL FK `tenant(id)`, `provisioning_attempts` int unsigned DEFAULT 0, `provisioning_error` varchar(120) NULL, `invite_sent_at` timestamp(6) NULL, `created_at`, `updated_at`; índice `billing_order_status_idx (status)`)
- Produz: tabela `subscription` (`id`, `billing_order_id` bigint unsigned UNIQUE FK `billing_order(id)`, `tenant_id` bigint unsigned NULL UNIQUE `subscription_tenant_uq` FK `tenant(id)`, `plan_id` varchar(20) CHECK `subscription_plan_ck`, `status` varchar(20) CHECK `subscription_status_ck` em `('incomplete','active','past_due','suspended','cancelled')`, `payment_method` varchar(10) CHECK `subscription_method_ck`, `stripe_customer_id` varchar(64), `stripe_subscription_id` varchar(64) NULL UNIQUE `subscription_stripe_uq` (nulo no Pix), `current_period_start` timestamp(6) NULL, `current_period_end` timestamp(6) NULL, `grace_until` timestamp(6) NULL, `cancelled_at` timestamp(6) NULL, `last_event_created` bigint unsigned DEFAULT 0, `created_at`, `updated_at`; índices `subscription_grace_idx (status, grace_until)` e `subscription_pix_due_idx (payment_method, status, current_period_end)`)
- Produz: tabela `subscription_invoice` (`id`, `subscription_id` FK `subscription(id)`, `stripe_invoice_id` varchar(64) NULL UNIQUE `subscription_invoice_stripe_uq` (cartão), `local_key` varchar(80) NULL UNIQUE `subscription_invoice_local_key_uq` (Pix: `pix:<subscription_id>:<period_start Y-m-d>`), `stripe_payment_intent_id` varchar(64) NULL UNIQUE `subscription_invoice_pi_uq`, `due_at` timestamp(6) NULL, `pix_expires_at` timestamp(6) NULL, `payment_intent_attempts` int unsigned DEFAULT 0, `status` varchar(20) CHECK `subscription_invoice_status_ck` em `('draft','open','paid','uncollectible','void','refunded')`, `amount_due_cents` int unsigned, `amount_paid_cents` int unsigned, `amount_refunded_cents` int unsigned DEFAULT 0, `currency` char(3), `period_start` timestamp(6) NULL, `period_end` timestamp(6) NULL, `hosted_invoice_url` varchar(500) NULL (URL de pagamento: fatura Stripe no cartão, `hosted_instructions_url` no Pix), `last_event_created` bigint unsigned DEFAULT 0, `created_at`, `updated_at`; CHECK `subscription_invoice_ref_ck` (`stripe_invoice_id IS NOT NULL OR local_key IS NOT NULL`); índice `subscription_invoice_open_idx (status, due_at)`)
- Produz: tabela `billing_event` (`id`, `provider` varchar(20) DEFAULT 'stripe', `event_id` varchar(100), UNIQUE `billing_event_provider_event_uq (provider, event_id)`, `event_type` varchar(80), `event_created` bigint unsigned, `livemode` tinyint(1), `payload` mediumtext, `status` varchar(12) CHECK `billing_event_status_ck` em `('received','processed','ignored','failed')`, `attempts` int unsigned DEFAULT 0, `last_error` varchar(190) NULL, `received_at` timestamp(6), `processed_at` timestamp(6) NULL; índices `billing_event_status_idx (status)` e `billing_event_created_idx (event_created)`)
- Produz: registro `INSERT INTO schema_migrations (version, checksum) VALUES ('20261021_0017_etapa3_billing', <64 zeros>)` como a 0016; entrada `$migrations_dir/20261021_0017_etapa3_billing.sql` no fim de `schema_files` de `provision.sh`; seção `## Migration 0017 (cobrança da assinatura)` no runbook, com o prefixo `21-`
- Consome: nada

**Teste RED**
- sem teste: migration pura (DDL) e documentação; a prova é o preparador 5.7, o `--check` do provisionamento e o `.verify.sql` no banco de teste, na Validação

**Critério de aceite**
- `prepare-mysql57.py` sobre a cópia `21-20261021_0017_etapa3_billing.sql` termina com código 0 e o arquivo gerado contém `billing_order_status_ck`, `subscription_status_ck`, `subscription_invoice_status_ck` e `billing_event_status_ck`.
- `sh scripts/test-db/provision.sh --check` termina com código 0 listando a 0017.
- A 0017 aplicada no `centralvet_test` por MYSQLTEST termina com código 0; o `.verify.sql` mostra 1 linha em `schema_migrations` para `20261021_0017_etapa3_billing`, as 4 tabelas e 0 violações; `SELECT COUNT(*) FROM tenant` e `SELECT COUNT(*) FROM system_users` têm o mesmo valor antes e depois.

**Validação**
- `d=$(mktemp -d -p /tmp/claude-1000/-var-www-html-centralvet/fc92cc27-9f66-4ad7-bfe3-efed43d88248/scratchpad); cp src/app/database/migrations/20261021_0017_etapa3_billing.sql "$d/21-20261021_0017_etapa3_billing.sql"; python3 scripts/prepare-mysql57.py "$d" "$d/out"; echo exit=$?; grep -c "_status_ck" "$d"/out/*` (evidência: `exit=0` e contagem ≥ 4)
- `sh scripts/test-db/provision.sh --check; echo exit=$?` (evidência: `exit=0`)
- `printf 'SELECT COUNT(*) FROM tenant; SELECT COUNT(*) FROM system_users;\n' | ` MYSQLTEST antes e depois de MYSQLTEST `< src/app/database/migrations/20261021_0017_etapa3_billing.sql`, seguido do `.verify.sql` pelo mesmo cliente (evidência: contagens iguais antes e depois; `.verify.sql` com as contagens dos comentários e 0 nas linhas de violação)
- `grep -n "0017" scripts/test-db/provision.sh docs/runbooks/shared-hosting-mysql57.md docs/runbooks/tests.md` (evidência: uma linha ou mais em cada arquivo, a do runbook com `21-`)

### T-03 — FOR UPDATE no cash_session ao fechar e ao lançar

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Sherlock

**Arquivos prováveis**
- `src/app/Core/Domain/Contract/CashSessionRepositoryInterface.php`
- `src/app/Core/Persistence/CashSessionRepository.php`
- `src/tests/Support/FakeCashSessionRepository.php`
- `src/app/Core/Application/CashSessionService.php`
- `src/app/Core/Application/PaymentService.php`
- `src/app/Core/Application/PaymentReversalService.php`
- `src/tests/Unit/CashSessionLockTest.php`
- `src/tests/Integration/CashSessionCloseRaceIntegrationTest.php`

**Interface**
- Produz: `CashSessionRepositoryInterface::findByIdForUpdate(int $id): ?object` e `CashSessionRepositoryInterface::findOpenBySystemUnitForUpdate(int $systemUnitId): ?object` (`SELECT ... FOR UPDATE` escopado por tenant; fora de transação lançam `LogicException('findByIdForUpdate requires an open transaction')` / `LogicException('findOpenBySystemUnitForUpdate requires an open transaction')`, como `EncounterRepository::lockForDraft`); `CashSessionService::close` usa `findByIdForUpdate` em vez de `findById` (CashSessionService.php:127); `PaymentService::register` usa `findByIdForUpdate` para o caixa (PaymentService.php:130) e recusa caixa não `open` com a `CashSessionRequiredException` existente; `PaymentReversalService` usa `findOpenBySystemUnitForUpdate` (PaymentReversalService.php:97). O fake registra a ordem das chamadas em `FakeCashSessionRepository::$lockCalls` (list<string>)
- Consome: nada

**Teste RED**
- `src/tests/Unit/CashSessionLockTest.php`, `src/tests/Integration/CashSessionCloseRaceIntegrationTest.php` — `close`, `register` e o estorno chamam o método `...ForUpdate` antes de qualquer escrita (`$lockCalls`); no MySQL, com A segurando `findByIdForUpdate` do caixa, `PaymentService::register` na segunda conexão termina em 1205 e, com A segurando o lançamento, `CashSessionService::close` na segunda conexão termina em 1205; um registro num caixa que A fechou e commitou no savepoint recusa com `CashSessionRequiredException` (comando: `SUITE com <Classe> = CashSessionLockTest|CashSessionCloseRaceIntegrationTest`)

**Critério de aceite**
- SUITE com `CashSessionLockTest|CashSessionCloseRaceIntegrationTest|CashSessionServiceTest|PaymentServiceTest|PaymentReversalServiceTest|PaymentIdempotencyIntegrationTest|PaymentReversalIntegrationTest` mostra `PASS` em todos os métodos, sem `SKIP` nas classes de integração, e `Failed: 0`.
- `grep -n "ForUpdate" src/app/Core/Application/CashSessionService.php src/app/Core/Application/PaymentService.php src/app/Core/Application/PaymentReversalService.php` mostra a leitura do caixa com bloqueio nos três serviços.

**Validação**
- SUITE com `<Classe>` acima (evidência: `PASS` nos cenários de 1205 nas duas direções e `Failed: 0`)
- LINT dos 6 arquivos PHP de produção e dos 3 de teste (evidência: `No syntax errors detected`; nada novo em `baseline/php-lint.txt`)

### T-04 — S5: dompdf ≥ 3.1.6 e php-jwt ^7, com hash regenerado

**Camada:** infra
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Yoda

**Arquivos prováveis**
- `src/composer.json`
- `src/composer.lock`
- `src/app/config/framework_hashes.php`
- `src/app/Core/Security/PasswordResetToken.php`
- `src/app/service/auth/ApplicationAuthenticationService.php`
- `src/app/service/auth/ApplicationAuthenticationRestService.php`
- `src/tests/Unit/DependencyAdvisoryTest.php`
- `docs/security/dependencias-s5.md`

**Interface**
- Produz: `src/composer.json` com `"dompdf/dompdf": "^3.1.6"` e `"firebase/php-jwt": "^7.0"`, demais linhas intactas (autorização do usuário, `notes.md § Decisões`); `src/composer.lock` atualizado só por `composer update dompdf/dompdf firebase/php-jwt --with-dependencies`; em `framework_hashes.php`, só a linha `'composer.json' => '<sha512>'` muda, com `hash_file('sha512', <src/composer.json>)` (algoritmo de `AdiantiFileHashGeneratorService::generateHash`), no mesmo commit do `composer.json`
- Produz: `new PasswordResetToken($key)` com `strlen($key) < 32` lança `InvalidArgumentException('JWT HMAC key must have at least 32 bytes')` (a mensagem clara vem antes da recusa do php-jwt 7 a chave HMAC curta); as chamadas `JWT::encode(..., 'HS256')` e `JWT::decode(..., new Key(..., 'HS256'))` em `PasswordResetToken`, `ApplicationAuthenticationService::fromToken` e `ApplicationAuthenticationRestService` ficam ajustadas à API 7 conforme o `CHANGELOG.md` de `src/vendor/firebase/php-jwt` (sem mudança incompatível: arquivos intactos e o motivo no doc)
- Produz: `docs/security/dependencias-s5.md` com versões antes e depois, os avisos resolvidos (6 do dompdf, 1 do php-jwt), os usos (`DompdfDocumentRenderer`, `SaleForm`, `PrescriptionForm`, `ProductList`, `AdiantiStandardListExportTrait`; `PasswordResetToken`, `ApplicationAuthenticationService::fromToken`, `ApplicationAuthenticationRestService`), as mudanças da API 7 aplicadas, o comprimento mínimo da chave e o rebuild de `app` e `worker`
- Consome: nada

**Teste RED**
- `src/tests/Unit/DependencyAdvisoryTest.php` — as versões lidas de `composer.lock` são `dompdf/dompdf` ≥ 3.1.6 e `firebase/php-jwt` ≥ 7.0.0; o `sha512` de `composer.json` é igual ao de `framework_hashes.php`; `new PasswordResetToken(str_repeat('a', 31))` lança `InvalidArgumentException` com `JWT HMAC key must have at least 32 bytes`; com chave de 32 bytes, `issue` seguido de `verify` devolve o login; `new Dompdf\Dompdf()` renderiza `<p>ZZ</p>` e a saída começa com `%PDF` (comando: `SUITE com <Classe> = DependencyAdvisoryTest`)

**Critério de aceite**
- `grep -A1 '"name": "dompdf/dompdf"' src/composer.lock` mostra `v3.1.6` ou maior e `grep -A1 '"name": "firebase/php-jwt"' src/composer.lock` mostra `v7.`; `git diff task/etapa-2-dinheiro-estoque -- src/composer.json` mostra só as 2 linhas de versão e `git diff task/etapa-2-dinheiro-estoque -- src/app/config/framework_hashes.php` só a linha `composer.json`, no mesmo commit.
- No container, `php -r` que carrega a config do app e calcula `strlen(APPLICATION_NAME . seed)` imprime um número ≥ 32 (só o número; o seed nunca é impresso). Com número < 32, a task para antes de subir o php-jwt e devolve `precisa de contexto`: o login por token e o reset quebrariam.
- SUITE com `DependencyAdvisoryTest|PasswordResetTokenTest|PasswordResetRequestTest|PasswordResetFormsWiringTest|MutationGuardWiringTest|DocumentRendererTest|DocumentGenerationServiceTest|DocumentWorkerTest` mostra `PASS` em todos e `Failed: 0`.
- `composer audit --locked` não lista aviso de `dompdf/dompdf` nem de `firebase/php-jwt`.

**Validação**
- `docker run --rm -u "$(id -u):$(id -g)" -v /var/www/html/centralvet/src:/app -w /app composer:2.8.11 update dompdf/dompdf firebase/php-jwt --with-dependencies --no-interaction --no-scripts` (pré-requisito: rede para o packagist; evidência: `Upgrading dompdf/dompdf (v3.1.5 => v3.1.6` ou maior e `Upgrading firebase/php-jwt (v6.11.1 => v7.`)
- `docker run --rm -v /var/www/html/centralvet/src:/app -w /app composer:2.8.11 audit --locked --no-interaction` (evidência: nenhum aviso de `dompdf/dompdf` nem de `firebase/php-jwt`)
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -r "echo hash_file('sha512', 'composer.json'), PHP_EOL;"; grep "'composer.json'" src/app/config/framework_hashes.php` (evidência: o mesmo hash nas duas linhas)
- SUITE com a `<Classe>` acima (evidência: `Failed: 0`)
- LINT de `app/Core/Security/PasswordResetToken.php`, `app/service/auth/ApplicationAuthenticationService.php`, `app/service/auth/ApplicationAuthenticationRestService.php`, `app/config/framework_hashes.php` e do teste (evidência: `No syntax errors detected`; nada novo em `baseline/php-lint.txt`)
- Na fumaça de T-22 (condicional), depois do rebuild de `app` e `worker`: login, pedido de reset com o link no log e um PDF de prescrição gerado

### T-05 — S6: download.php desabilitado

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Jaspion

**Arquivos prováveis**
- `src/download.php`
- `src/tests/Unit/DownloadEndpointDisabledTest.php`
- `docs/security/downloads-s6.md`

**Interface**
- Produz: `src/download.php` responde `http_response_code(404)` com corpo vazio a qualquer requisição, antes de `require init.php` e de qualquer leitura de arquivo (sem `readfile`, `fopen`, `file_get_contents` nem `realpath` do parâmetro), e grava `error_log('download.disabled')` sem o caminho pedido; `docs/security/downloads-s6.md` com o mapa dos usos (`TFile.php:302`, `THyperLink.php:38`, `TImage.php:118`, `download.js:19`, `TPage::openFile` em `SystemSQLPanel`, `SystemTableList`, `SystemDatabaseExplorer`), a constatação de que `src/files/` só tem `system/` (bloqueado) e que os documentos clínicos usam mecanismo próprio, e a decisão de desabilitar
- Consome: nada

**Teste RED**
- `src/tests/Unit/DownloadEndpointDisabledTest.php` — um subprocesso `php -r` que define `$_GET['file']` com um arquivo existente em `tests/` e inclui `download.php` imprime `404` em `http_response_code()` e nenhum byte do arquivo; o fonte de `download.php` não contém `readfile`, `fopen` nem `file_get_contents` (comando: `SUITE com <Classe> = DownloadEndpointDisabledTest`)

**Critério de aceite**
- SUITE com `DownloadEndpointDisabledTest` mostra `PASS` em todos os métodos e `Failed: 0`.
- Na fumaça de T-22, `GET /download.php?file=files/system/` e `GET /download.php?file=<caminho de arquivo de outra clínica>` com o usuário logado da clínica A devolvem 404 e corpo vazio.

**Validação**
- SUITE com `<Classe>` = `DownloadEndpointDisabledTest` (evidência: `PASS` e `Failed: 0`)
- LINT de `download.php` (evidência: `No syntax errors detected`)

### T-06 — Pendência (d): cancelamento, nomes no recibo e rótulos de pagamento

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Levi

**Arquivos prováveis**
- `src/app/control/clinic/SaleCancelForm.php`
- `src/app/Core/Presentation/SaleReceiptHtml.php`
- `src/app/Core/Persistence/SaleFinanceReader.php`
- `src/app/lib/widget/CvFormat.php`
- `src/app/Core/Domain/Payment.php`
- `src/tests/Unit/SaleReceiptHtmlTest.php`
- `src/tests/Unit/PaymentMethodLabelsTest.php`
- `src/tests/Unit/SaleCancelFormCancelledStateTest.php`
- `src/tests/Integration/SaleFinanceReaderNamesIntegrationTest.php`

**Interface**
- Produz: `Payment::METHOD_LABELS` (`array<string, string>` forma → rótulo en, as mesmas chaves e textos hoje em `CvFormat::paymentMethod`); `CvFormat::paymentMethod` e `SaleReceiptHtml` passam a ler `Payment::METHOD_LABELS` (a constante privada `SaleReceiptHtml::METHOD_LABELS` sai); `SaleFinanceReader::receiptSummary(int $saleId): ?array` acrescenta `tutor_name` (string) e `patient_name` (?string) lidos de `tutor`/`patient` do mesmo tenant; `SaleReceiptHtml::render` imprime os nomes escapados e só cai em `#<id>` quando a chave falta; `SaleCancelForm::onEdit` com venda cancelada mostra `CvBadge` `_t('Cancelled')` e não adiciona a ação de confirmar o cancelamento
- Consome: nada

**Teste RED**
- `src/tests/Unit/SaleReceiptHtmlTest.php`, `src/tests/Unit/PaymentMethodLabelsTest.php`, `src/tests/Unit/SaleCancelFormCancelledStateTest.php`, `src/tests/Integration/SaleFinanceReaderNamesIntegrationTest.php` — o recibo com `tutor_name = 'ZZ <Tutor>'` mostra `ZZ &lt;Tutor&gt;` e não `#`; `Payment::METHOD_LABELS` existe e tem as mesmas chaves e textos que `CvFormat::paymentMethod` devolve para cada forma de `Payment`; `SaleReceiptHtml` não declara `METHOD_LABELS`; `onEdit` só chama `addAction` do cancelamento no ramo de venda não cancelada; `receiptSummary` no MySQL devolve o nome do tutor e do paciente da venda (comando: `SUITE com <Classe> = SaleReceiptHtmlTest|PaymentMethodLabelsTest|SaleCancelFormCancelledStateTest|SaleFinanceReaderNamesIntegrationTest`)

**Critério de aceite**
- SUITE com `SaleReceiptHtmlTest|PaymentMethodLabelsTest|SaleCancelFormCancelledStateTest|SaleFinanceReaderNamesIntegrationTest|SaleFinanceReaderIntegrationTest|SaleCancelFormWiringTest|PaymentMethodsTest` mostra `PASS` em todos e `Failed: 0`.
- `grep -n "METHOD_LABELS" src/app/Core/Presentation/SaleReceiptHtml.php` mostra só referências a `Payment::METHOD_LABELS`.

**Validação**
- SUITE com a `<Classe>` acima (evidência: `Failed: 0`)
- LINT dos 5 arquivos de produção e dos 4 de teste (evidência: `No syntax errors detected`)

### T-07 — Domínio de cobrança

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Platão

**Arquivos prováveis**
- `src/app/Core/Billing/PlanEntitlements.php`
- `src/app/Core/Billing/Subscription.php`
- `src/app/Core/Billing/SubscriptionInvoice.php`
- `src/app/Core/Billing/BillingOrder.php`
- `src/tests/Unit/PlanEntitlementsTest.php`
- `src/tests/Unit/SubscriptionTest.php`
- `src/tests/Unit/SubscriptionInvoiceTest.php`
- `src/tests/Unit/BillingOrderTest.php`

**Interface**
- Produz: `CentralVet\Billing\PlanEntitlements::forPlan(string $planId): PlanEntitlements` (lança `InvalidArgumentException('Unknown plan "<id>"')`), `PlanEntitlements::PLAN_IDS` = `['starter', 'pro', 'business', 'enterprise']`, `planId(): string`, `name(): string` e `priceCents(): int` (lidos de `LandingCatalog::plan()`: Starter 5700, Pro 9700, Business 13700, Enterprise 19700), `maxUsers(): ?int` e `maxUnits(): ?int` (Starter 2/1, Pro 10/1, Business 30/5, Enterprise `null`/`null` = sem limite), `hasFeature(string $feature): bool`, `allowsProgram(string $controller): bool` (true para controller fora de `gatedPrograms()`), `PlanEntitlements::gatedPrograms(): array` (controller → feature)
- Produz (quotas e mapa confirmados pelo usuário): features `PlanEntitlements::FEATURE_INVENTORY_POS = 'inventory_pos'` (Pro, Business, Enterprise: `ProductForm`, `ProductList`, `StockBatchForm`, `SaleForm`, `SaleList`, `SaleCancelForm`), `FEATURE_FINANCE_FULL = 'finance_full'` (Pro+: `PayableForm`, `PayableList`, `BankAccountForm`, `BankAccountList`, `FinancialEntryForm`, `FinancialEntryList`, `FinancialOverview`), `FEATURE_COMMUNICATION = 'communication'` (Pro+: `CommunicationComposeForm`, `CommunicationMessageList`, `CommunicationMessageView`, `MessageTemplateForm`, `MessageTemplateList`, `TutorCommunicationForm`), `FEATURE_MULTI_UNIT = 'multi_unit'` (Business+: `SystemUnitForm`, `SystemUnitList`), `FEATURE_HOSPITAL = 'hospital'` (Enterprise: controllers `Hospitalization*`, `Surgery*`, `Bed*` do seed), `FEATURE_AI = 'ai_advanced'` (Enterprise: `AiConversationList`, `AiCopilotPanel`, `AiInteractionList`, `AiSettingsForm`, `AiToolCallList`)
- Produz: `CentralVet\Billing\Subscription` com `STATUS_INCOMPLETE = 'incomplete'`, `STATUS_ACTIVE = 'active'`, `STATUS_PAST_DUE = 'past_due'`, `STATUS_SUSPENDED = 'suspended'`, `STATUS_CANCELLED = 'cancelled'`, `GRACE_DAYS = 7`; `Subscription::start(int $billingOrderId, string $planId, string $paymentMethod, string $stripeCustomerId, ?string $stripeSubscriptionId, string $stripeStatus, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd, int $eventCreated): Subscription`; `Subscription::fromRow(array $row): Subscription` e `toRow(): array` (chaves = colunas de `subscription` da T-02); `assignTenant(int $tenantId): void`; `applyProviderStatus(string $stripeStatus, ?\DateTimeImmutable $periodStart, ?\DateTimeImmutable $periodEnd, int $eventCreated, \DateTimeImmutable $now): bool`; `markInvoicePaid(\DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd, int $eventCreated): bool`; `markPaymentFailed(int $eventCreated, \DateTimeImmutable $now): bool`; `markOverdue(\DateTimeImmutable $now): bool` (vencimento local do Pix: `active` → `past_due` com tolerância, sem mudar `last_event_created`; `false` em outro status); `suspendIfGraceExpired(\DateTimeImmutable $now): bool`; `accessLevel(\DateTimeImmutable $now): string` ∈ `'full'`, `'grace'`, `'blocked'`; getters `id(): ?int`, `billingOrderId(): int`, `tenantId(): ?int`, `planId(): string`, `status(): string`, `paymentMethod(): string`, `stripeCustomerId(): string`, `stripeSubscriptionId(): ?string` (nulo no Pix), `currentPeriodStart(): ?\DateTimeImmutable`, `currentPeriodEnd(): ?\DateTimeImmutable`, `graceUntil(): ?\DateTimeImmutable`, `lastEventCreated(): int`, `withId(int $id): Subscription`. Regras: os métodos `apply*`/`mark*` devolvem `false` e não mudam nada quando `$eventCreated < lastEventCreated()`; status Stripe `trialing`/`active` → `active`, `past_due` → `past_due`, `unpaid`/`paused` → `suspended`, `canceled`/`incomplete_expired` → `cancelled`, `incomplete` → `incomplete`; ao entrar em `past_due`, `grace_until = now + 7 dias` (mantém o valor se já definido); `markInvoicePaid` volta a `active` (também de `suspended`), zera `grace_until` e passa o período ao da fatura; no Pix, `start` recebe `stripeSubscriptionId` nulo e `stripeStatus` `'active'`, e o ciclo é local (T-23, T-18); `accessLevel`: `active` → `full`, `past_due` com `grace_until > now` → `grace`, demais → `blocked`
- Produz: `CentralVet\Billing\SubscriptionInvoice` com `STATUS_DRAFT`, `STATUS_OPEN`, `STATUS_PAID`, `STATUS_UNCOLLECTIBLE`, `STATUS_VOID`, `STATUS_REFUNDED` (valores = CHECK da T-02); `SubscriptionInvoice::fromStripe(int $subscriptionId, array $invoice, int $eventCreated): SubscriptionInvoice` (lê `id`, `status`, `amount_due`, `amount_paid`, `currency`, `hosted_invoice_url`, `lines.data[0].period.start/end`); `applyStripe(array $invoice, int $eventCreated): bool`; `applyRefund(int $amountRefundedCents, int $eventCreated): bool` (status `refunded` quando o estornado iguala o pago); `SubscriptionInvoice::issuePix(int $subscriptionId, int $amountCents, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd, \DateTimeImmutable $dueAt): SubscriptionInvoice` (status `open`, `local_key` = `pix:<subscriptionId>:<periodStart Y-m-d>`, `currency` `brl`); `attachPaymentIntent(string $paymentIntentId, ?string $payUrl, ?\DateTimeImmutable $expiresAt): void` (incrementa `payment_intent_attempts`); `markPaidByPaymentIntent(int $amountPaidCents, int $eventCreated): bool` (`false` sem mudança se já `paid`); `isOverdue(\DateTimeImmutable $now): bool` (`open` com `due_at <= now`); `needsPaymentIntent(\DateTimeImmutable $now): bool` (`open` com `local_key` e PaymentIntent nulo ou `pix_expires_at <= now`); `fromRow(array $row)`/`toRow(): array`; getters `id(): ?int`, `subscriptionId(): int`, `stripeInvoiceId(): ?string`, `localKey(): ?string`, `stripePaymentIntentId(): ?string`, `dueAt(): ?\DateTimeImmutable`, `pixExpiresAt(): ?\DateTimeImmutable`, `paymentIntentAttempts(): int`, `withId(int $id): SubscriptionInvoice`, `status(): string`, `amountDueCents(): int`, `amountPaidCents(): int`, `amountRefundedCents(): int`, `hostedInvoiceUrl(): ?string`, `periodStart(): ?\DateTimeImmutable`, `periodEnd(): ?\DateTimeImmutable`; mesma guarda de evento antigo
- Produz: `CentralVet\Billing\BillingOrder` com `STATUS_PENDING = 'pending'`, `STATUS_CHECKOUT_OPEN = 'checkout_open'`, `STATUS_PAID = 'paid'`, `STATUS_PROVISIONED = 'provisioned'`, `STATUS_PROVISIONING_FAILED = 'provisioning_failed'`, `STATUS_EXPIRED = 'expired'`; `BillingOrder::place(string $publicId, string $planId, string $paymentMethod, string $responsibleName, string $responsibleEmail, string $responsiblePhone, string $clinicName, string $termsVersion, \DateTimeImmutable $termsAcceptedAt, string $termsIp, bool $contactConsent): BillingOrder` (preço de `PlanEntitlements::forPlan($planId)->priceCents()`, nunca de parâmetro); `attachCheckout(string $checkoutSessionId, string $stripeCustomerId): void`; `markPaid(?string $stripeSubscriptionId, \DateTimeImmutable $paidAt): bool` (false sem mudança se já `paid`/`provisioned`/`provisioning_failed`); `expire(): bool` (false se já pago); `markProvisioned(int $tenantId): void`; `markProvisioningFailed(string $errorCode): void` (incrementa `provisioning_attempts`); `markInviteSent(\DateTimeImmutable $at): void`; `fromRow(array $row)`/`toRow(): array` (chaves = colunas de `billing_order` da T-02); getters `id(): ?int`, `publicId(): string`, `planId(): string`, `priceCents(): int`, `paymentMethod(): string`, `responsibleName(): string`, `responsibleEmail(): string`, `clinicName(): string`, `status(): string`, `stripeCustomerId(): ?string`, `stripeCheckoutSessionId(): ?string`, `stripeSubscriptionId(): ?string`, `paidAt(): ?\DateTimeImmutable`, `tenantId(): ?int`, `provisioningAttempts(): int`, `inviteSentAt(): ?\DateTimeImmutable`, `withId(int $id): BillingOrder`
- Consome: nada

**Teste RED**
- `src/tests/Unit/PlanEntitlementsTest.php`, `src/tests/Unit/SubscriptionTest.php`, `src/tests/Unit/SubscriptionInvoiceTest.php`, `src/tests/Unit/BillingOrderTest.php` — Starter nega `SaleList` e permite `TutorList`; Business permite `SystemUnitForm`, Pro nega; preços iguais aos de `LandingCatalog::plans()`; `applyProviderStatus('active', ..., 100, ...)` depois de `markPaymentFailed(200, ...)` devolve `false` e o status fica `past_due`; `past_due` dá `grace` até `now + 7 dias` e `blocked` depois; `markPaid` duas vezes devolve `true` e depois `false`; `expire()` em pedido pago devolve `false`; `forPlan('gold')` lança `Unknown plan "gold"`; `markOverdue` deixa `past_due` com `grace_until = now + 7 dias` e não muda `lastEventCreated()`; `markInvoicePaid` de assinatura `suspended` volta a `active`; `issuePix` gera `local_key` `pix:<id>:<Y-m-d>` e `open`; `markPaidByPaymentIntent` duas vezes devolve `true` e depois `false`; `needsPaymentIntent` é `true` sem PaymentIntent e com `pix_expires_at` no passado (comando: `SUITE com <Classe> = PlanEntitlementsTest|SubscriptionTest|SubscriptionInvoiceTest|BillingOrderTest`)

**Critério de aceite**
- SUITE com `PlanEntitlementsTest|SubscriptionTest|SubscriptionInvoiceTest|BillingOrderTest|LandingCatalogTest` mostra `PASS` em todos e `Failed: 0`.

**Validação**
- SUITE com a `<Classe>` acima (evidência: `PASS` no caso de evento antigo ignorado e `Failed: 0`)
- LINT dos 8 arquivos (evidência: `No syntax errors detected`)

### T-08 — Cliente Stripe próprio e placeholders de ambiente

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Tesla

**Arquivos prováveis**
- `src/app/Core/Billing/Stripe/StripeClientInterface.php`
- `src/app/Core/Billing/Stripe/CurlStripeClient.php`
- `src/app/Core/Billing/Stripe/StripeConfig.php`
- `src/app/Core/Billing/Stripe/StripeSignatureVerifier.php`
- `src/app/Core/Billing/Stripe/StripeApiException.php`
- `src/app/Core/Billing/Stripe/InvalidWebhookSignature.php`
- `src/tests/Support/FakeStripeClient.php`
- `src/tests/Unit/StripeSignatureVerifierTest.php`
- `src/tests/Unit/CurlStripeClientTest.php`
- `.env.example`
- `docker-compose.yml`

**Interface**
- Produz: `CentralVet\Billing\Stripe\StripeClientInterface::request(string $method, string $path, array $params = [], ?string $idempotencyKey = null): array` (corpo `application/x-www-form-urlencoded` com colchetes aninhados, `Authorization: Bearer <secret>`, `Stripe-Version: 2024-06-20`, cabeçalho `Idempotency-Key` quando informado; resposta JSON decodificada; HTTP ≥ 400 ou falha de rede lança `StripeApiException` com `httpStatus(): int` (0 em falha de rede) e `stripeCode(): ?string`, mensagem sem a chave)
- Produz: `CurlStripeClient::__construct(StripeConfig $config, ?\Closure $transport = null)` (o `$transport(string $method, string $url, array $headers, string $body): array{status: int, body: string}` substitui o cURL nos testes)
- Produz: `StripeConfig::fromEnvironment(): StripeConfig` (lê `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_PRICE_STARTER`, `STRIPE_PRICE_PRO`, `STRIPE_PRICE_BUSINESS`, `STRIPE_PRICE_ENTERPRISE`), `StripeConfig::__construct(string $secretKey, string $webhookSecret, array $priceIds)`, `secretKey(): string`, `webhookSecret(): string`, `priceIdFor(string $planId): string` (lança `RuntimeException('Stripe price for plan "<id>" is not configured')` se vazio), `StripeConfig::API_VERSION = '2024-06-20'`
- Produz: `StripeSignatureVerifier::__construct(string $webhookSecret, int $toleranceSeconds = 300)`, `verify(string $payload, string $signatureHeader, int $now): void` (lança `InvalidWebhookSignature` para cabeçalho ausente, sem `t=`/`v1=`, HMAC-SHA256 de `"<t>.<payload>"` diferente em `hash_equals`, ou `|now - t| > tolerância`), `StripeSignatureVerifier::header(string $payload, string $secret, int $timestamp): string` (monta `t=<ts>,v1=<hex>` para testes)
- Produz: `CentralVet\Tests\Support\FakeStripeClient` implementa a interface: `queue(string $method, string $path, array $response): void`, `fail(string $method, string $path, StripeApiException $e): void`, `$calls` (list de `['method' => ..., 'path' => ..., 'params' => ..., 'idempotencyKey' => ...]`); chamada sem resposta enfileirada lança `LogicException('Unexpected Stripe call <method> <path>')`
- Produz: `.env.example` com `STRIPE_SECRET_KEY=`, `STRIPE_WEBHOOK_SECRET=`, `STRIPE_PRICE_STARTER=`, `STRIPE_PRICE_PRO=`, `STRIPE_PRICE_BUSINESS=`, `STRIPE_PRICE_ENTERPRISE=` (vazios) e as mesmas 6 variáveis em `docker-compose.yml` no `&app-environment` como `${STRIPE_SECRET_KEY:-}`
- Consome: nada

**Teste RED**
- `src/tests/Unit/StripeSignatureVerifierTest.php`, `src/tests/Unit/CurlStripeClientTest.php` — `verify` aceita o cabeçalho de `header($payload, 'whsec_test_etapa3', $now)`, recusa payload com 1 byte alterado, cabeçalho de outro segredo e `t` 301 s no passado; o `CurlStripeClient` com `$transport` fake envia `line_items[0][price]=price_x` no corpo, `Stripe-Version: 2024-06-20` e `Idempotency-Key: k1`, e transforma HTTP 402 com `{"error":{"code":"card_declined"}}` em `StripeApiException` com `stripeCode() === 'card_declined'` e mensagem sem `sk_` (comando: `SUITE com <Classe> = StripeSignatureVerifierTest|CurlStripeClientTest`)

**Critério de aceite**
- SUITE com `StripeSignatureVerifierTest|CurlStripeClientTest|PasswordResetFormsWiringTest` mostra `PASS` em todos e `Failed: 0`.
- `grep -nE "^STRIPE_[A-Z_]+=$" .env.example | wc -l` imprime `6`; `grep -c "STRIPE_" docker-compose.yml` imprime `6`; `git grep -nE "sk_(live|test)_[A-Za-z0-9]{8,}|whsec_[A-Za-z0-9]{20,}"` não imprime linha.

**Validação**
- SUITE com a `<Classe>` acima (evidência: `Failed: 0`)
- os dois `grep` e o `git grep` acima (evidência: `6`, `6` e saída vazia)
- LINT dos 9 arquivos PHP (evidência: `No syntax errors detected`)

### T-09 — Repositórios de pedido e de evento

**Camada:** backend
**Dependências:** T-02, T-07
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Arquimedes

**Arquivos prováveis**
- `src/app/Core/Billing/Contract/BillingOrderRepositoryInterface.php`
- `src/app/Core/Billing/Contract/BillingEventRepositoryInterface.php`
- `src/app/Core/Persistence/BillingOrderRepository.php`
- `src/app/Core/Persistence/BillingEventRepository.php`
- `src/tests/Support/FakeBillingOrderRepository.php`
- `src/tests/Support/FakeBillingEventRepository.php`
- `src/tests/Integration/BillingOrderRepositoryIntegrationTest.php`
- `src/tests/Integration/BillingEventRepositoryIntegrationTest.php`

**Interface**
- Produz: `BillingOrderRepositoryInterface::insert(BillingOrder $order): int`, `save(BillingOrder $order): void`, `findByPublicId(string $publicId): ?BillingOrder`, `findByIdForUpdate(int $id): ?BillingOrder` (exige transação, `LogicException` fora dela), `findByCheckoutSessionIdForUpdate(string $checkoutSessionId): ?BillingOrder`, `listPendingFulfillment(int $limit): array` (list<int> de ids com `status` `paid`, ou `provisioning_failed` com `provisioning_attempts < 10`, ou `provisioned` com `invite_sent_at IS NULL`, por `id`)
- Produz: `BillingEventRepositoryInterface::claim(string $eventId, string $eventType, int $eventCreated, bool $livemode, string $payload): ?int` (INSERT com status `received`; se o `event_id` já existe com `processed`/`ignored` devolve `null`; se existe com `received`/`failed` bloqueia a linha com `FOR UPDATE`, incrementa `attempts` e devolve o id), `markDone(int $id, string $status, \DateTimeImmutable $at): void` (`$status` ∈ `processed`, `ignored`), `markFailed(int $id, string $error): void` (corta em 190 caracteres), `latestEventCreated(): ?int`
- Produz: `CentralVet\Persistence\BillingOrderRepository::__construct(\PDO $pdo)` e `CentralVet\Persistence\BillingEventRepository::__construct(\PDO $pdo)` (sem `TenantContext`: o pedido e o evento existem antes do tenant); fakes `FakeBillingOrderRepository` e `FakeBillingEventRepository` com o mesmo contrato
- Consome: T-07 `fromRow(array $row)`, T-07 `toRow(): array`, T-07 `withId(int $id): BillingOrder`

**Teste RED**
- `src/tests/Integration/BillingOrderRepositoryIntegrationTest.php`, `src/tests/Integration/BillingEventRepositoryIntegrationTest.php` — ida e volta de `BillingOrder` com todas as colunas; `findByCheckoutSessionIdForUpdate` na segunda conexão termina em 1205 com A segurando a linha; `claim` do mesmo `event_id` depois de `markDone('processed')` devolve `null` e depois de `markFailed` devolve o mesmo id com `attempts = 2`; `listPendingFulfillment` ignora pedido `provisioned` com convite enviado (comando: `SUITE com <Classe> = BillingOrderRepositoryIntegrationTest|BillingEventRepositoryIntegrationTest`)

**Critério de aceite**
- Com a 0017 no `centralvet_test`, SUITE com `BillingOrderRepositoryIntegrationTest|BillingEventRepositoryIntegrationTest|MysqlIsolationGuardIntegrationTest` mostra `PASS` em todos, sem `SKIP`, e `Failed: 0`.

**Validação**
- SUITE com a `<Classe>` acima (evidência: `PASS` no caso de duplicidade e `Failed: 0`)
- LINT dos 8 arquivos (evidência: `No syntax errors detected`)

### T-10 — Repositórios de assinatura e fatura

**Camada:** backend
**Dependências:** T-02, T-07
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Spock

**Arquivos prováveis**
- `src/app/Core/Billing/Contract/SubscriptionRepositoryInterface.php`
- `src/app/Core/Billing/Contract/SubscriptionInvoiceRepositoryInterface.php`
- `src/app/Core/Persistence/SubscriptionRepository.php`
- `src/app/Core/Persistence/SubscriptionInvoiceRepository.php`
- `src/tests/Support/FakeSubscriptionRepository.php`
- `src/tests/Support/FakeSubscriptionInvoiceRepository.php`
- `src/tests/Integration/SubscriptionRepositoryIntegrationTest.php`
- `src/app/Core/Billing/DuplicateInvoice.php`

**Interface**
- Produz: `SubscriptionRepositoryInterface::insert(Subscription $subscription): int`, `save(Subscription $subscription): void`, `findByTenantId(int $tenantId): ?Subscription`, `findByBillingOrderId(int $billingOrderId): ?Subscription`, `findByStripeSubscriptionIdForUpdate(string $stripeSubscriptionId): ?Subscription` (exige transação), `listGraceExpired(\DateTimeImmutable $now, int $limit): array` (list<int> de ids com `status = 'past_due'` e `grace_until <= now`), `findByIdForUpdate(int $id): ?Subscription`, `listPixDueForIssue(\DateTimeImmutable $until, int $limit): array` (list<int> de ids com `payment_method = 'pix'`, `status` em `active`/`past_due`, `current_period_end <= until` e nenhuma fatura com `period_start >= current_period_end`)
- Produz: `SubscriptionInvoiceRepositoryInterface::findByStripeInvoiceIdForUpdate(string $stripeInvoiceId): ?SubscriptionInvoice`, `insert(SubscriptionInvoice $invoice): int`, `save(SubscriptionInvoice $invoice): void`, `listBySubscription(int $subscriptionId, int $limit): array` (list<SubscriptionInvoice>, `period_start` decrescente), `findByIdForUpdate(int $id): ?SubscriptionInvoice`, `findByPaymentIntentIdForUpdate(string $paymentIntentId): ?SubscriptionInvoice`, `findByLocalKey(string $localKey): ?SubscriptionInvoice`, `listOpenPix(\DateTimeImmutable $now, int $limit): array` (list<int> de faturas `open` com `local_key` e `due_at <= now`, ou `pix_expires_at <= now`, ou `stripe_payment_intent_id` nulo); `insert` com `local_key` repetido lança `CentralVet\Billing\DuplicateInvoice`
- Produz: `CentralVet\Persistence\SubscriptionRepository::__construct(\PDO $pdo)` e `CentralVet\Persistence\SubscriptionInvoiceRepository::__construct(\PDO $pdo)`; fakes `FakeSubscriptionRepository` e `FakeSubscriptionInvoiceRepository` com o mesmo contrato
- Consome: T-07 `Subscription::fromRow(array $row): Subscription`, T-07 `SubscriptionInvoice::fromStripe(int $subscriptionId, array $invoice, int $eventCreated): SubscriptionInvoice`

**Teste RED**
- `src/tests/Integration/SubscriptionRepositoryIntegrationTest.php` — ida e volta de `Subscription` e `SubscriptionInvoice`; `findByTenantId` de um tenant B não devolve a assinatura do tenant A; `findByStripeSubscriptionIdForUpdate` na segunda conexão termina em 1205 com A segurando a linha; `listGraceExpired` devolve só a assinatura com `grace_until` no passado; `listPixDueForIssue` devolve a assinatura Pix com período terminando antes de `until` e deixa de devolvê-la depois do `insert` da fatura do período seguinte; `insert` de 2 faturas com o mesmo `local_key` lança `DuplicateInvoice`; `findByPaymentIntentIdForUpdate` acha a fatura Pix (comando: `SUITE com <Classe> = SubscriptionRepositoryIntegrationTest`)

**Critério de aceite**
- Com a 0017 no `centralvet_test`, SUITE com `SubscriptionRepositoryIntegrationTest|MysqlIsolationGuardIntegrationTest` mostra `PASS` em todos, sem `SKIP`, e `Failed: 0`.

**Validação**
- SUITE com a `<Classe>` acima (evidência: `Failed: 0`)
- LINT dos 7 arquivos (evidência: `No syntax errors detected`)

### T-11 — Seed dos grupos de plano

**Camada:** database
**Dependências:** T-07
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Darwin

**Arquivos prováveis**
- `src/app/database/seeds/plan-groups.sql`
- `src/tests/Unit/PlanGroupsSeedTest.php`

**Interface**
- Produz: `src/app/database/seeds/plan-groups.sql` (cabeçalho "Prepared only; requires separate approval", `START TRANSACTION`/`COMMIT`, idempotente com `WHERE NOT EXISTS` e `COALESCE(MAX(id),0)+1` como `initial-application-programs.sql`) criando os grupos `Central Vet - Plano Starter`, `Central Vet - Plano Pro`, `Central Vet - Plano Business`, `Central Vet - Plano Enterprise` em `system_group` e ligando em `system_group_program` cada controller de `initial-application-programs.sql` (exceto `LandingLeadList`) que `PlanEntitlements::forPlan(<plano>)->allowsProgram()` permite, mais `SystemUserList` e `SystemUserForm` em todos os planos; não liga programas do grupo 1 (administração da plataforma) além desses
- Consome: T-07 `PlanEntitlements::forPlan(string $planId): PlanEntitlements`, T-07 `allowsProgram(string $controller): bool`

**Teste RED**
- `src/tests/Unit/PlanGroupsSeedTest.php` — para cada um dos 4 grupos, o conjunto de controllers ligados no seed é igual ao conjunto de controllers de `initial-application-programs.sql` (menos `LandingLeadList`) permitidos pelo plano, mais `SystemUserList` e `SystemUserForm` (e `SystemUnitForm`/`SystemUnitList` só em Business e Enterprise); nenhum grupo liga `LandingLeadList`, `SystemDatabaseExplorer`, `SystemSQLPanel` nem `SystemGroupForm` (comando: `SUITE com <Classe> = PlanGroupsSeedTest`)

**Critério de aceite**
- SUITE com `PlanGroupsSeedTest` mostra `PASS` em todos e `Failed: 0`.
- `grep -c "NOT EXISTS" src/app/database/seeds/plan-groups.sql` imprime um número ≥ 4 e o arquivo não contém `DELETE` nem `UPDATE`.

**Validação**
- SUITE com `<Classe>` = `PlanGroupsSeedTest` (evidência: `Failed: 0`)
- `grep -c "NOT EXISTS" src/app/database/seeds/plan-groups.sql; grep -cE "^(DELETE|UPDATE)" src/app/database/seeds/plan-groups.sql` (evidência: ≥ 4 e `0`)

### T-12 — Convite por e-mail

**Camada:** backend
**Dependências:** T-01, T-04
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Gandalf

**Arquivos prováveis**
- `src/app/Core/Security/PasswordResetToken.php`
- `src/app/Core/Billing/AccountInviteService.php`
- `src/tests/Unit/AccountInviteServiceTest.php`

**Interface**
- Produz: `PasswordResetToken::issue(string $login, string $passwordHash, int $now, int $ttlSeconds = self::TTL_SECONDS): string` (padrão inalterado para o reset)
- Produz: `CentralVet\Billing\AccountInviteService::__construct(PasswordResetToken $tokens, PublicUrl $url, MessageChannelProviderInterface $email, \Closure $translate)` (`$translate(string $key, string ...$params): string`, no endpoint ligado a `_t`), `AccountInviteService::TTL_SECONDS = 259200`, `send(string $login, string $email, string $passwordHash, string $clinicName, int $now): ?string` (link `PublicUrl::to('index.php?class=SystemPasswordResetForm&method=onLoad&jwt=' . rawurlencode($jwt))`; `OutgoingMessage($email, $translate('Central Vet Pro invitation'), <corpo com 'Your clinic ^1 is ready. Set your password to access Central Vet Pro: ^2' e 'This link expires in ^1 hours' com 72>, 'account-invite')`; devolve o retorno de `deliver`)
- Consome: T-01 `Central Vet Pro invitation`, T-01 `Your clinic ^1 is ready. Set your password to access Central Vet Pro: ^2`, T-01 `This link expires in ^1 hours`

**Teste RED**
- `src/tests/Unit/AccountInviteServiceTest.php` — com `FakeEmailProvider`, `send` entrega 1 mensagem ao e-mail com o link `https://app.test/index.php?class=SystemPasswordResetForm&method=onLoad&jwt=...`; o JWT verifica com `PasswordResetToken::verify` em `now + 259199` e expira em `now + 259201`; `matchesPassword` falha depois que o hash muda (uso único); `issue` sem o 4º argumento continua expirando em 10800 s (comando: `SUITE com <Classe> = AccountInviteServiceTest`)

**Critério de aceite**
- SUITE com `AccountInviteServiceTest|PasswordResetTokenTest|PasswordResetRequestTest|DependencyAdvisoryTest` mostra `PASS` em todos e `Failed: 0`.

**Validação**
- SUITE com a `<Classe>` acima (evidência: `Failed: 0`)
- LINT de `app/Core/Security/PasswordResetToken.php`, `app/Core/Billing/AccountInviteService.php` e do teste (evidência: `No syntax errors detected`)

### T-13 — Provisionamento idempotente e retomável

**Camada:** backend
**Dependências:** T-02, T-07, T-09, T-10, T-11, T-12
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Thanos

**Arquivos prováveis**
- `src/app/Core/Billing/TenantProvisioningService.php`
- `src/app/Core/Billing/ProvisioningResult.php`
- `src/app/Core/Billing/ProvisioningConflict.php`
- `src/app/Core/Billing/TransactionRunner.php`
- `src/tests/Integration/TenantProvisioningIntegrationTest.php`

**Interface**
- Produz: `CentralVet\Billing\TransactionRunner::__construct(\PDO $pdo)`, `run(\Closure $work): mixed` (`beginTransaction` → `$work()` → `commit`; `rollBack` e relança em exceção; com transação já aberta usa `SAVEPOINT`/`RELEASE SAVEPOINT`/`ROLLBACK TO SAVEPOINT`, o que permite o teste dentro da transação de `MysqlIntegrationTestCase`)
- Produz: `CentralVet\Billing\TenantProvisioningService::__construct(\PDO $pdo, TransactionRunner $tx, BillingOrderRepositoryInterface $orders, SubscriptionRepositoryInterface $subscriptions, AccountInviteService $invites, ?\Closure $faultInjector = null)`, `fulfill(int $billingOrderId, \DateTimeImmutable $now): ProvisioningResult`; `TenantProvisioningService::GROUP_NAME_PREFIX = 'Central Vet - Plano '`
- Produz: `ProvisioningResult` com `status(): string` ∈ `'provisioned'`, `'already_provisioned'`, `'failed'`, `'not_paid'` e `tenantId(): ?int`; `ProvisioningConflict` (erro `login_conflict` quando já existe `system_users.login` igual ao e-mail do responsável)
- Regras: numa transação do `TransactionRunner`, com o pedido bloqueado (`findByIdForUpdate`) e só se `paid`/`provisioning_failed`: cria ou reaproveita `tenant` (`public_id` = `public_id` do pedido, `slug` = `clinica-` + 8 primeiros caracteres do `public_id`, `legal_name` = `clinic_name`), `system_unit` (`tenant_id`, `name` = `clinic_name`, `custom_code` = `order:<public_id>`), `system_users` (login e e-mail = `responsible_email`, nome = `responsible_name`, `active = 'Y'`, `system_unit_id`, senha = `password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)`), `system_user_unit`, `system_user_group` com o grupo `GROUP_NAME_PREFIX . PlanEntitlements::name()`, `tenant_user` (`status = 'invited'`), `tenant_group`; ids das tabelas `system_*` por `COALESCE(MAX(id),0)+1` com `FOR UPDATE`; cada passo procura antes pela chave determinística e não duplica; atribui o tenant à `Subscription` do pedido (`assignTenant`) e marca o pedido `provisioned`. Exceção → rollback, depois `markProvisioningFailed(<código>)` noutra transação (`login_conflict`, `group_missing` ou o nome curto da classe) e `ProvisioningResult` `failed`. Pedido `provisioned` com `invite_sent_at` nulo → só reenvia o convite. Convite enviado → `markInviteSent`. Nunca chama o Stripe. `$faultInjector(string $step)` é chamado antes de cada passo (`tenant`, `unit`, `user`, `memberships`, `subscription`, `invite`) e só existe para o teste
- Consome: T-09 `findByIdForUpdate(int $id): ?BillingOrder`, T-09 `save(BillingOrder $order): void`, T-10 `findByBillingOrderId(int $billingOrderId): ?Subscription`, T-10 `save(Subscription $subscription): void`, T-07 `assignTenant(int $tenantId): void`, T-07 `markProvisioned(int $tenantId): void`, T-07 `markProvisioningFailed(string $errorCode): void`, T-07 `markInviteSent(\DateTimeImmutable $at): void`, T-12 `send(string $login, string $email, string $passwordHash, string $clinicName, int $now): ?string`, T-11 `Central Vet - Plano Starter`

**Teste RED**
- `src/tests/Integration/TenantProvisioningIntegrationTest.php` — com os 4 grupos de plano inseridos como fixture e um pedido `paid` com assinatura: `fulfill` cria 1 `tenant`, 1 `system_unit`, 1 `system_users`, 1 `tenant_user` `invited`, 1 `system_user_group` no grupo do plano e liga a assinatura ao tenant; a segunda chamada devolve `already_provisioned` com as mesmas contagens; com `$faultInjector` lançando no passo `memberships`, o pedido fica `provisioning_failed` com `provisioning_attempts = 1` e 0 `tenant` novo, e a chamada seguinte sem falha conclui com 1 de cada; e-mail já usado como login dá `failed` com `provisioning_error = 'login_conflict'`; falha no passo `invite` deixa o pedido `provisioned` com `invite_sent_at` nulo e a próxima chamada só envia o convite (comando: `SUITE com <Classe> = TenantProvisioningIntegrationTest`)

**Critério de aceite**
- Com a 0017 no `centralvet_test`, SUITE com `TenantProvisioningIntegrationTest|MysqlIsolationGuardIntegrationTest` mostra `PASS` em todos, sem `SKIP`, e `Failed: 0`.
- `grep -n "StripeClientInterface" src/app/Core/Billing/TenantProvisioningService.php` não imprime linha (o provisionamento não cobra).

**Validação**
- SUITE com a `<Classe>` acima (evidência: `PASS` nos cenários de retomada e de `login_conflict`, `Failed: 0`)
- o `grep` acima (evidência: saída vazia)
- LINT dos 4 arquivos de produção e do teste (evidência: `No syntax errors detected`)
- Review Focus: no `TenantProvisioningIntegrationTest`, o caso `login_conflict` repetido 2 vezes deixa `provisioning_attempts = 2`, 0 tenant novo e o provisionamento não recebe cliente Stripe (o `grep` acima)

### T-14 — Processador de eventos Stripe

**Camada:** backend
**Dependências:** T-07, T-08, T-09, T-10
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Hegel

**Arquivos prováveis**
- `src/app/Core/Billing/BillingEventProcessor.php`
- `src/app/Core/Billing/BillingEventOutcome.php`
- `src/app/Core/Billing/BillingEventOutOfOrder.php`
- `src/tests/Unit/BillingEventProcessorTest.php`
- `src/tests/Support/Fixtures/stripe-events.php`

**Interface**
- Produz: `CentralVet\Billing\BillingEventProcessor::__construct(BillingOrderRepositoryInterface $orders, SubscriptionRepositoryInterface $subscriptions, SubscriptionInvoiceRepositoryInterface $invoices, StripeClientInterface $stripe)`, `process(array $event, \DateTimeImmutable $now): BillingEventOutcome` (exige transação aberta pelo chamador; não comita)
- Produz: `BillingEventOutcome` com `status(): string` ∈ `'processed'`, `'ignored'` e `orderToFulfill(): ?int`; `BillingEventOutOfOrder` (RuntimeException) quando o evento depende de algo ainda não registrado
- Regras: `checkout.session.completed` com `payment_status = 'paid'` e `checkout.session.async_payment_succeeded` → pedido por `findByCheckoutSessionIdForUpdate` → `markPaid`; cartão: `GET /v1/subscriptions/<id>` e `Subscription::start(...)` com status e período da resposta (`current_period_start`/`current_period_end`, senão `items.data[0].current_period_start`/`end`); Pix, sem chamada ao Stripe: `Subscription::start(..., stripeSubscriptionId: null, stripeStatus: 'active', periodStart: paid_at, periodEnd: paid_at + 1 month, ...)` e `insert`, e a 1ª fatura `SubscriptionInvoice::issuePix(<id>, price_cents, paid_at, paid_at + 1 month, paid_at)` com `attachPaymentIntent(<session.payment_intent>, null, null)` e `markPaidByPaymentIntent(<session.amount_total>, created)`; `markPaid` devolvendo `false` (pedido já pago) não cria nada; `orderToFulfill` = id do pedido. `checkout.session.expired` e `checkout.session.async_payment_failed` → `expire()`. `invoice.created`, `invoice.finalized`, `invoice.paid`, `invoice.payment_failed`, `invoice.voided`, `invoice.marked_uncollectible` → assinatura por `findByStripeSubscriptionIdForUpdate` (ausente → `BillingEventOutOfOrder`), fatura inserida ou `applyStripe`; `invoice.paid` → `markInvoicePaid` com o período da linha; `invoice.payment_failed` → `markPaymentFailed`. `customer.subscription.updated`/`customer.subscription.deleted` → `applyProviderStatus`. `payment_intent.succeeded` com `metadata[subscription_invoice]` → fatura por `findByIdForUpdate` (ausente → `BillingEventOutOfOrder`; `stripe_payment_intent_id` gravado e diferente do evento → `ignored`) → `markPaidByPaymentIntent(amount_received)` e a assinatura da fatura por `findByIdForUpdate` → `markInvoicePaid(<period_start>, <period_end> da fatura)`; sem essa metadata (o PaymentIntent do 1º mês traz só `metadata[billing_order]`) → `ignored`. `payment_intent.payment_failed` e `payment_intent.canceled` → `ignored` (o reconciliador regenera o Pix vencido). `charge.refunded` → fatura por `invoice` (cartão, `findByStripeInvoiceIdForUpdate`) ou por `payment_intent` (Pix, `findByPaymentIntentIdForUpdate`) → `applyRefund(amount_refunded)`; sem nenhuma das duas → `ignored`. Qualquer outro tipo → `ignored`. O `created` do evento é o `$eventCreated` passado ao domínio
- Consome: T-08 `request(string $method, string $path, array $params = [], ?string $idempotencyKey = null): array`, T-09 `findByCheckoutSessionIdForUpdate(string $checkoutSessionId): ?BillingOrder`, T-10 `findByStripeSubscriptionIdForUpdate(string $stripeSubscriptionId): ?Subscription`, T-10 `findByStripeInvoiceIdForUpdate(string $stripeInvoiceId): ?SubscriptionInvoice`, T-07 `markPaid(?string $stripeSubscriptionId, \DateTimeImmutable $paidAt): bool`, T-07 `applyProviderStatus(string $stripeStatus, ?\DateTimeImmutable $periodStart, ?\DateTimeImmutable $periodEnd, int $eventCreated, \DateTimeImmutable $now): bool`, T-07 `markInvoicePaid(\DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd, int $eventCreated): bool`, T-07 `markPaymentFailed(int $eventCreated, \DateTimeImmutable $now): bool`, T-07 `applyRefund(int $amountRefundedCents, int $eventCreated): bool`, T-07 `SubscriptionInvoice::issuePix(int $subscriptionId, int $amountCents, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd, \DateTimeImmutable $dueAt): SubscriptionInvoice`, T-07 `attachPaymentIntent(string $paymentIntentId, ?string $payUrl, ?\DateTimeImmutable $expiresAt): void`, T-07 `markPaidByPaymentIntent(int $amountPaidCents, int $eventCreated): bool`, T-10 `findByIdForUpdate(int $id): ?SubscriptionInvoice`, T-10 `findByIdForUpdate(int $id): ?Subscription`, T-10 `findByPaymentIntentIdForUpdate(string $paymentIntentId): ?SubscriptionInvoice`

**Teste RED**
- `src/tests/Unit/BillingEventProcessorTest.php`, `src/tests/Support/Fixtures/stripe-events.php` — com fakes de T-08, T-09 e T-10 e eventos de fixture: checkout de cartão pago cria assinatura `active` e devolve `orderToFulfill`; checkout Pix (`checkout.session.async_payment_succeeded`) cria assinatura `active` com `stripe_subscription_id` nulo, período de 1 mês e 1 fatura `paid`, sem chamada ao Stripe; o mesmo evento processado duas vezes deixa 1 assinatura e 1 fatura; `payment_intent.succeeded` com `metadata[subscription_invoice]` marca a fatura `paid` e avança `current_period_end`, inclusive com a assinatura em `past_due` por `markOverdue`; o PaymentIntent do 1º mês (só `metadata[billing_order]`) sai `ignored`; `invoice.payment_failed` (created 200) depois `customer.subscription.updated` `active` (created 100) deixa `past_due`; `invoice.paid` sem assinatura lança `BillingEventOutOfOrder`; `charge.refunded` total marca a fatura `refunded`, pela `invoice` no cartão e pelo `payment_intent` no Pix; `checkout.session.expired` de pedido pago não muda o pedido (comando: `SUITE com <Classe> = BillingEventProcessorTest`)

**Critério de aceite**
- SUITE com `BillingEventProcessorTest` mostra `PASS` em todos os métodos e `Failed: 0`.

**Validação**
- SUITE com `<Classe>` = `BillingEventProcessorTest` (evidência: `PASS` nos casos fora de ordem e duplicado, `Failed: 0`)
- LINT dos 3 arquivos de produção, do teste e da fixture (evidência: `No syntax errors detected`)
- Review Focus: no `BillingEventProcessorTest`, a assinatura Pix em `past_due` por `markOverdue` com `lastEventCreated()` = 500 recebe `payment_intent.succeeded` com `created` 400 e volta a `active` com o período da fatura (o vencimento local não move `last_event_created`)

### T-15 — Guardas de acesso e de quota

**Camada:** backend
**Dependências:** T-07, T-10
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Jaspion

**Arquivos prováveis**
- `src/app/Core/Billing/SubscriptionAccessGuard.php`
- `src/app/Core/Billing/AccessDecision.php`
- `src/app/Core/Billing/QuotaGuard.php`
- `src/app/Core/Billing/QuotaExceededException.php`
- `src/app/Core/Billing/Contract/TenantUsageReaderInterface.php`
- `src/app/Core/Persistence/TenantUsageReader.php`
- `src/tests/Support/FakeTenantUsageReader.php`
- `src/tests/Unit/SubscriptionAccessGuardTest.php`
- `src/tests/Unit/QuotaGuardTest.php`
- `src/tests/Integration/TenantUsageReaderIntegrationTest.php`

**Interface**
- Produz: `CentralVet\Billing\SubscriptionAccessGuard::__construct(SubscriptionRepositoryInterface $subscriptions)`, `decide(int $tenantId, string $controller, \DateTimeImmutable $now): AccessDecision`, `SubscriptionAccessGuard::ALWAYS_ALLOWED` = `['LoginForm', 'SubscriptionStatusView', 'SystemPasswordResetForm', 'SystemRequestPasswordResetForm']`
- Produz: `AccessDecision` com `allowed(): bool`, `reason(): string` ∈ `'legacy'` (tenant sem assinatura: acesso inalterado), `'full'`, `'grace'`, `'blocked_status'`, `'blocked_plan'`, e `messageKey(): ?string` (`'Your subscription is suspended. Regularize the payment to continue'` em `blocked_status`, `'Your plan does not include this feature'` em `blocked_plan`). Ordem: `ALWAYS_ALLOWED` → permitido; sem assinatura → `legacy`; `accessLevel` `blocked` → `blocked_status`; controller fora do plano → `blocked_plan`; senão `full` ou `grace`
- Produz: `QuotaGuard::__construct(SubscriptionRepositoryInterface $subscriptions, TenantUsageReaderInterface $usage)`, `assertCanAddUser(int $tenantId): void` e `assertCanAddUnit(int $tenantId): void` (lançam `QuotaExceededException('Your plan allows up to <n> users')` / `('Your plan allows up to <n> units')` quando o uso atual ≥ limite; limite `null` ou tenant sem assinatura não lança)
- Produz: `TenantUsageReaderInterface::countUsers(int $tenantId): int` (`tenant_user` com `status` em `invited`, `active`) e `countUnits(int $tenantId): int` (`system_unit` com o `tenant_id`); `CentralVet\Persistence\TenantUsageReader::__construct(\PDO $pdo)`; fake `FakeTenantUsageReader`
- Consome: T-10 `findByTenantId(int $tenantId): ?Subscription`, T-07 `accessLevel(\DateTimeImmutable $now): string`, T-07 `allowsProgram(string $controller): bool`, T-07 `maxUsers(): ?int`, T-07 `maxUnits(): ?int`, T-01 `Your plan allows up to ^1 users`, T-01 `Your plan allows up to ^1 units`

**Teste RED**
- `src/tests/Unit/SubscriptionAccessGuardTest.php`, `src/tests/Unit/QuotaGuardTest.php`, `src/tests/Integration/TenantUsageReaderIntegrationTest.php` — Starter `active` em `SaleList` → `blocked_plan`; Pro `active` em `SaleList` → `full`; `past_due` dentro da tolerância → `grace`, depois → `blocked_status`; `suspended` em `SubscriptionStatusView` → permitido; tenant sem assinatura → `legacy`; Starter com 2 usuários → `assertCanAddUser` lança `Your plan allows up to 2 users`; Enterprise com 500 usuários não lança; `countUsers` do tenant A não conta usuários do tenant B (comando: `SUITE com <Classe> = SubscriptionAccessGuardTest|QuotaGuardTest|TenantUsageReaderIntegrationTest`)

**Critério de aceite**
- SUITE com `SubscriptionAccessGuardTest|QuotaGuardTest|TenantUsageReaderIntegrationTest` mostra `PASS` em todos, sem `SKIP`, e `Failed: 0`.

**Validação**
- SUITE com a `<Classe>` acima (evidência: `Failed: 0`)
- LINT dos 10 arquivos (evidência: `No syntax errors detected`)

### T-16 — Início do checkout

**Camada:** backend
**Dependências:** T-01, T-07, T-08, T-09
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Batman

**Arquivos prováveis**
- `src/app/Core/Billing/CheckoutStartHandler.php`
- `src/checkout.php`
- `src/app/Core/Landing/LandingCatalog.php`
- `src/tests/Unit/LandingCatalogTest.php`
- `src/tests/Unit/CheckoutStartHandlerTest.php`
- `docs/security/csrf-inventario.md`

**Interface**
- Produz: `LandingCatalog::TERMS_VERSION = 'assinatura-2026-10'`, `LandingCatalog::TERMS_TEXT` (`'Li e aceito os Termos de Assinatura do Central Vet Pro: cobrança mensal recorrente no cartão ou por fatura Pix, cancelamento a qualquer momento com efeito no fim do período pago.'`), `LandingCatalog::PAYMENT_METHODS = ['card' => 'Cartão de crédito', 'pix' => 'Pix']`; `publicJson()` acrescenta `"terms": {"version": ..., "text": ...}` e `"payment_methods": [{"id": "card", "label": ...}, {"id": "pix", "label": ...}]`
- Produz: `CentralVet\Billing\CheckoutStartHandler::__construct(LeadFormToken $formTokens, LoginRateLimiter $limiter, BillingOrderRepositoryInterface $orders, StripeClientInterface $stripe, StripeConfig $config, PublicUrl $url, \Closure $uuid)`, `handle(string $method, array $headers, string $body, string $ip, \DateTimeImmutable $now): LeadResponse`; passa antes por `LeadSubmissionHandler::precheck`, consome o `form_token` com `LeadFormToken::consume`, limita por IP com prefixo `checkout|`, ignora campo `website` preenchido (honeypot) como o lead
- Produz: POST `/checkout.php` (JSON) `{"form_token": "...", "plan_id": "starter|pro|business|enterprise", "payment_method": "card|pix", "responsible_name": "...", "responsible_email": "...", "responsible_phone": "...", "clinic_name": "...", "terms_accepted": true, "terms_version": "assinatura-2026-10", "contact_consent": false, "website": ""}` → 201 `{"checkout_url": "<session.url>", "order": "<public_id>"}`; 422 `{"errors": {"<campo>": "<mensagem pt>"}}` para plano fora de `PLAN_IDS`, forma fora de `card`/`pix`, `terms_accepted` diferente de `true` ou `terms_version` diferente de `TERMS_VERSION`, e-mail inválido ou campo vazio; 429 no limite; 502 `{"error": "gateway_unavailable"}` em `StripeApiException` (pedido fica `pending`, nada cobrado). Qualquer `price_cents`/`amount` no payload é ignorado
- Produz: chamadas Stripe: `POST /v1/customers` (`email`, `name`, `metadata[billing_order]`; `Idempotency-Key` `customer-<public_id>`) e `POST /v1/checkout/sessions` (`Idempotency-Key` `checkout-<public_id>`, `client_reference_id` e `metadata[billing_order]` = `public_id`, `customer`, `success_url` = `PublicUrl::to('landing.php?checkout=success&order=<public_id>')`, `cancel_url` = `PublicUrl::to('landing.php?checkout=cancelled&order=<public_id>')`); cartão: `mode=subscription` (a Subscription nasce com `collection_method=charge_automatically`, padrão do Checkout), `payment_method_types[0]=card`, `line_items[0][price]` = `priceIdFor(plan)`, `line_items[0][quantity]=1`, `subscription_data[metadata][billing_order]`; Pix: `mode=payment`, `payment_method_types[0]=pix`, `line_items[0][price_data][currency]=brl`, `line_items[0][price_data][unit_amount]` = `priceCents()`, `line_items[0][price_data][product_data][name]` = `Central Vet Pro <Plano> — 1º mês`, `line_items[0][quantity]=1`, `payment_method_options[pix][expires_after_seconds]=3600`, `payment_intent_data[metadata][billing_order]` (o 1º mês é o primeiro PaymentIntent Pix avulso; os seguintes saem de T-23); depois `attachCheckout` e status `checkout_open`
- Produz: `src/checkout.php` no padrão de `src/lead.php` (sem sessão Adianti, `PdoConnectionFactory`/config de banco, `RedisConnectionFactory`, `StripeConfig::fromEnvironment()`, `PublicUrl::fromEnvironment()`); linha `checkout.php` em `docs/security/csrf-inventario.md` junto de `lead.php` (POST público com `LeadFormToken`, same-origin e limitador)
- Consome: T-07 `BillingOrder::place(string $publicId, string $planId, string $paymentMethod, string $responsibleName, string $responsibleEmail, string $responsiblePhone, string $clinicName, string $termsVersion, \DateTimeImmutable $termsAcceptedAt, string $termsIp, bool $contactConsent): BillingOrder`, T-07 `attachCheckout(string $checkoutSessionId, string $stripeCustomerId): void`, T-07 `priceCents(): int`, T-09 `insert(BillingOrder $order): int`, T-09 `save(BillingOrder $order): void`, T-08 `request(string $method, string $path, array $params = [], ?string $idempotencyKey = null): array`, T-08 `priceIdFor(string $planId): string`

**Teste RED**
- `src/tests/Unit/CheckoutStartHandlerTest.php`, `src/tests/Unit/LandingCatalogTest.php` — payload Pro com cartão devolve 201 com a `checkout_url` do fake e o pedido `checkout_open` com `price_cents = 9700` mesmo com `"price_cents": 1` no payload; Pix envia `unit_amount=9700` e `payment_method_types[0]=pix`; `terms_accepted: false` devolve 422 com `terms_accepted` e nenhuma chamada ao Stripe; `plan_id: "gold"` devolve 422; `StripeApiException` devolve 502 e o pedido fica `pending`; `publicJson()` traz `terms.version = assinatura-2026-10` e as 2 formas de pagamento (comando: `SUITE com <Classe> = CheckoutStartHandlerTest|LandingCatalogTest`)

**Critério de aceite**
- SUITE com `CheckoutStartHandlerTest|LandingCatalogTest|LeadSubmissionHandlerTest|LeadEndpointPrecheckTest|LandingPageTest` mostra `PASS` em todos e `Failed: 0`.
- `grep -n "checkout.php" docs/security/csrf-inventario.md` imprime 1 linha ou mais.

**Validação**
- SUITE com a `<Classe>` acima (evidência: `PASS` no caso de preço ignorado do cliente e `Failed: 0`)
- `grep -n "checkout.php" docs/security/csrf-inventario.md` (evidência: a linha nova)
- LINT de `app/Core/Billing/CheckoutStartHandler.php`, `checkout.php`, `app/Core/Landing/LandingCatalog.php` e dos 2 testes (evidência: `No syntax errors detected`)
- Review Focus: no `CheckoutStartHandlerTest`, payload com `"price_cents": 1` e `"amount": 1` gera `unit_amount=9700` (Pix) e pedido com `price_cents = 9700`

### T-17 — Webhook autenticado

**Camada:** backend
**Dependências:** T-08, T-09, T-13, T-14, T-16
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Kratos

**Arquivos prováveis**
- `src/app/Core/Billing/BillingEventIngestor.php`
- `src/app/Core/Billing/BillingWebhookHandler.php`
- `src/billing-webhook.php`
- `src/tests/Integration/BillingWebhookIntegrationTest.php`
- `docs/security/csrf-inventario.md`

**Interface**
- Produz: `CentralVet\Billing\BillingEventIngestor::__construct(TransactionRunner $tx, BillingEventRepositoryInterface $events, BillingEventProcessor $processor, TenantProvisioningService $provisioning)`, `ingest(array $event, string $rawPayload, \DateTimeImmutable $now): string` ∈ `'processed'`, `'ignored'`, `'duplicate'` (transação 1: `claim`; `null` → `duplicate`; transação 2: `process` + `markDone`; exceção → rollback da 2, `markFailed` numa transação 3 e relança; depois do commit, com `orderToFulfill`, chama `fulfill` — falha de provisionamento não falha o evento: o pedido fica `provisioning_failed` para o reconciliador)
- Produz: `BillingWebhookHandler::__construct(StripeSignatureVerifier $verifier, BillingEventIngestor $ingestor, \CentralVet\Observability\Logging\LoggerInterface $logger)`, `handle(string $method, array $headers, string $body, \DateTimeImmutable $now): LeadResponse`: não POST → 405; `stripe-signature` ausente ou inválido → 400 `{"error": "invalid_signature"}` sem escrita no banco; JSON sem `id`/`type`/`created` → 400 `{"error": "invalid_payload"}`; sucesso → 200 `{"received": true, "result": "<processed|ignored|duplicate>"}`; exceção do ingestor → 500 `{"error": "processing_failed"}` (o Stripe reenvia); logs só com `event_id`, tipo e resultado
- Produz: `src/billing-webhook.php` (POST público, sem sessão Adianti, `require init.php` para `_t` no convite com idioma `pt`, corpo lido de `php://input` com limite de 256 KiB); linha `billing-webhook.php` em `docs/security/csrf-inventario.md` como exceção autenticada por `Stripe-Signature` (HMAC do segredo, tolerância 300 s), sem cookie nem token CSRF
- Consome: T-08 `verify(string $payload, string $signatureHeader, int $now): void`, T-09 `claim(string $eventId, string $eventType, int $eventCreated, bool $livemode, string $payload): ?int`, T-09 `markDone(int $id, string $status, \DateTimeImmutable $at): void`, T-09 `markFailed(int $id, string $error): void`, T-14 `process(array $event, \DateTimeImmutable $now): BillingEventOutcome`, T-14 `orderToFulfill(): ?int`, T-13 `fulfill(int $billingOrderId, \DateTimeImmutable $now): ProvisioningResult`, T-13 `run(\Closure $work): mixed`, T-16 `LeadResponse`

**Teste RED**
- `src/tests/Integration/BillingWebhookIntegrationTest.php` — no MySQL com fakes de Stripe e e-mail: `checkout.session.completed` assinado com `whsec_test_etapa3` devolve 200 `processed` e provisiona o tenant; o mesmo evento de novo devolve `duplicate` com as mesmas contagens; corpo alterado depois de assinado devolve 400 `invalid_signature` e 0 linhas novas em `billing_event`; `invoice.paid` antes do checkout devolve 500 e deixa o evento `failed`, e o reenvio depois do checkout devolve 200 `processed`; provisionamento com `login_conflict` devolve 200 e o pedido `provisioning_failed` (comando: `SUITE com <Classe> = BillingWebhookIntegrationTest`)

**Critério de aceite**
- Com a 0017 no `centralvet_test`, SUITE com `BillingWebhookIntegrationTest|MysqlIsolationGuardIntegrationTest` mostra `PASS` em todos, sem `SKIP`, e `Failed: 0`.
- `grep -n "billing-webhook.php" docs/security/csrf-inventario.md` imprime a linha da exceção com `Stripe-Signature`.

**Validação**
- SUITE com a `<Classe>` acima (evidência: `PASS` nos casos de assinatura inválida, duplicado e fora de ordem; `Failed: 0`)
- `grep -n "billing-webhook.php" docs/security/csrf-inventario.md` (evidência: a linha com `Stripe-Signature`)
- LINT de `app/Core/Billing/BillingEventIngestor.php`, `app/Core/Billing/BillingWebhookHandler.php`, `billing-webhook.php` e do teste (evidência: `No syntax errors detected`)
- Review Focus: no `BillingWebhookIntegrationTest`, o corpo alterado depois de assinado conta `SELECT COUNT(*) FROM billing_event` igual antes e depois

### T-18 — Reconciliação

**Camada:** backend
**Dependências:** T-08, T-09, T-10, T-13, T-17
**Paralelizável:** não
**Complexidade:** alta
**Agente:** Naruto

**Arquivos prováveis**
- `src/app/Core/Billing/BillingReconciler.php`
- `src/bin/billing-reconcile.php`
- `src/tests/Integration/BillingReconcilerIntegrationTest.php`
- `docs/runbooks/shared-hosting-mysql57.md`

**Interface**
- Produz: `CentralVet\Billing\BillingReconciler::__construct(StripeClientInterface $stripe, BillingEventRepositoryInterface $events, BillingEventIngestor $ingestor, BillingOrderRepositoryInterface $orders, SubscriptionRepositoryInterface $subscriptions, SubscriptionInvoiceRepositoryInterface $invoices, PixInvoiceIssuer $pixIssuer, TenantProvisioningService $provisioning, TransactionRunner $tx)`, `run(\DateTimeImmutable $now): array` → `['events_fetched' => int, 'events_ingested' => int, 'orders_fulfilled' => int, 'orders_failed' => int, 'pix_invoices_issued' => int, 'pix_invoices_overdue' => int, 'pix_intents_refreshed' => int, 'subscriptions_suspended' => int]`
- Regras: (1) `GET /v1/events` com `created[gte]` = `latestEventCreated() - 86400` (sem eventos: `now - 3 dias`), `limit=100` e `starting_after` até `has_more = false`, cada evento em `ingest` (erro de um evento não interrompe os demais); (2) `listPendingFulfillment(50)` → `fulfill`; (3) Pix: `listPixDueForIssue(now + 3 dias, 100)` → por assinatura, numa transação, `findByIdForUpdate` da assinatura e do pedido (e-mail do responsável) → `issueNext`; (4) `listOpenPix(now, 100)` → por fatura, numa transação: `isOverdue` → `markOverdue` da assinatura e `save`; `needsPaymentIntent` → `ensurePaymentIntent`; (5) `listGraceExpired(now, 100)` → `suspendIfGraceExpired` e `save` com bloqueio. A única escrita no Stripe é o `POST /v1/payment_intents` do `PixInvoiceIssuer`, com `Idempotency-Key`; o resto é `GET`
- Produz: `src/bin/billing-reconcile.php` (CLI, `flock` no diretório temporário como `worker.php --once`; imprime `billing-reconcile events_fetched=<n> events_ingested=<n> orders_fulfilled=<n> orders_failed=<n> pix_invoices_issued=<n> pix_invoices_overdue=<n> pix_intents_refreshed=<n> subscriptions_suspended=<n>`; sai 0, ou 1 em erro de Stripe/banco com log só da classe); seção `### Reconciliação da cobrança` no runbook com `*/15 * * * * cd /caminho/da/aplicacao/src && php bin/billing-reconcile.php >> /caminho/dos/logs/billing-reconcile.log 2>&1` e a configuração do endpoint de webhook no painel Stripe (URL `/billing-webhook.php`, API `2024-06-20`, eventos de T-14)
- Consome: T-08 `request(string $method, string $path, array $params = [], ?string $idempotencyKey = null): array`, T-09 `latestEventCreated(): ?int`, T-09 `listPendingFulfillment(int $limit): array`, T-10 `listGraceExpired(\DateTimeImmutable $now, int $limit): array`, T-10 `findByIdForUpdate(int $id): ?Subscription`, T-07 `suspendIfGraceExpired(\DateTimeImmutable $now): bool`, T-07 `markOverdue(\DateTimeImmutable $now): bool`, T-07 `isOverdue(\DateTimeImmutable $now): bool`, T-07 `needsPaymentIntent(\DateTimeImmutable $now): bool`, T-09 `findByIdForUpdate(int $id): ?BillingOrder`, T-10 `listPixDueForIssue(\DateTimeImmutable $until, int $limit): array`, T-10 `listOpenPix(\DateTimeImmutable $now, int $limit): array`, T-10 `findByIdForUpdate(int $id): ?SubscriptionInvoice`, T-13 `fulfill(int $billingOrderId, \DateTimeImmutable $now): ProvisioningResult`, T-17 `ingest(array $event, string $rawPayload, \DateTimeImmutable $now): string`, T-23 `issueNext(Subscription $subscription, int $priceCents, string $recipientEmail, \DateTimeImmutable $now): ?SubscriptionInvoice`, T-23 `ensurePaymentIntent(SubscriptionInvoice $invoice, string $stripeCustomerId, string $recipientEmail, \DateTimeImmutable $now): bool`

**Teste RED**
- `src/tests/Integration/BillingReconcilerIntegrationTest.php` — com o fake devolvendo 2 páginas de `/v1/events` (uma já processada, um `invoice.paid` perdido): `events_ingested = 1` e a fatura `paid`; pedido `provisioning_failed` por falha injetada vira `provisioned` na rodada sem falha, com 1 tenant; assinatura `past_due` com `grace_until` no passado vira `suspended`; assinatura Pix com período terminando em 2 dias ganha 1 fatura `open` com PaymentIntent e 1 e-mail, e a rodada seguinte não emite outra; fatura Pix com `due_at` no passado deixa a assinatura `past_due`; fatura com `pix_expires_at` no passado ganha PaymentIntent novo com `payment_intent_attempts = 2`; todas as chamadas ao fake são `GET`, exceto `POST /v1/payment_intents` (comando: `SUITE com <Classe> = BillingReconcilerIntegrationTest`)

**Critério de aceite**
- Com a 0017 no `centralvet_test`, SUITE com `BillingReconcilerIntegrationTest|MysqlIsolationGuardIntegrationTest` mostra `PASS` em todos, sem `SKIP`, e `Failed: 0`.
- `grep -n "billing-reconcile.php" docs/runbooks/shared-hosting-mysql57.md` imprime a linha do cron.

**Validação**
- SUITE com a `<Classe>` acima (evidência: `PASS` nos cenários de evento perdido, retomada, tolerância e Pix, e `Failed: 0`)
- `grep -n "billing-reconcile.php" docs/runbooks/shared-hosting-mysql57.md` (evidência: a linha `*/15 * * * *`)
- LINT de `app/Core/Billing/BillingReconciler.php`, `bin/billing-reconcile.php` e do teste (evidência: `No syntax errors detected`)

### T-19 — Bloqueio por assinatura, quotas e primeiro acesso

**Camada:** backend
**Dependências:** T-01, T-15
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Aang

**Arquivos prováveis**
- `src/app/service/auth/ApplicationAuthenticationService.php`
- `src/app/control/admin/SystemUserForm.php`
- `src/app/control/admin/SystemUnitForm.php`
- `src/app/Core/Tenancy/TenantUserActivation.php`
- `src/tests/Unit/SubscriptionAccessWiringTest.php`
- `src/tests/Integration/TenantUserActivationIntegrationTest.php`

**Interface**
- Produz: `ApplicationAuthenticationService::enforceSubscriptionAccess(): void`, chamado em `checkMultiSession` logo depois de `self::enforceMutationGuard();` e só com sessão logada: monta `SubscriptionAccessGuard` com `SubscriptionRepository(PdoConnectionFactory::fromEnvironment())`, guarda a decisão em `TSession` (`cv_subscription_access`, 60 s, por tenant e controller); `blocked_status`/`blocked_plan` → HTTP 403 com `_t(<messageKey>)` e link `index.php?class=SubscriptionStatusView`, sem executar o controller (mesmo caminho de `denyRequest`)
- Produz: `SystemUserForm::onSave` chama `QuotaGuard::assertCanAddUser(<tenant>)` só na criação (sem id) e antes de gravar; `SystemUnitForm::onSave` chama `QuotaGuard::assertCanAddUnit(<tenant>)` na criação; a `QuotaExceededException` é mostrada pelo tratamento de erro existente, traduzida pela chave de T-01
- Produz: `CentralVet\Tenancy\TenantUserActivation::activateInvited(\PDO $pdo, int $systemUserId): int` (`UPDATE tenant_user SET status = 'active' WHERE system_user_id = ? AND status = 'invited'`, devolve linhas afetadas), chamado em `ApplicationAuthenticationService::onAfterLogin`
- Consome: T-15 `decide(int $tenantId, string $controller, \DateTimeImmutable $now): AccessDecision`, T-15 `messageKey(): ?string`, T-15 `assertCanAddUser(int $tenantId): void`, T-15 `assertCanAddUnit(int $tenantId): void`, T-01 `Your subscription is suspended. Regularize the payment to continue`, T-01 `Your plan does not include this feature`

**Teste RED**
- `src/tests/Unit/SubscriptionAccessWiringTest.php`, `src/tests/Integration/TenantUserActivationIntegrationTest.php` — `checkMultiSession` chama `self::enforceSubscriptionAccess();` depois de `self::enforceMutationGuard();`; `SystemUserForm::onSave` chama `assertCanAddUser` antes de `->store()` no ramo sem id e `SystemUnitForm::onSave` chama `assertCanAddUnit`; `onAfterLogin` chama `TenantUserActivation::activateInvited`; no MySQL, `activateInvited` muda 1 linha `invited` para `active` e devolve 0 na segunda chamada (comando: `SUITE com <Classe> = SubscriptionAccessWiringTest|TenantUserActivationIntegrationTest`)

**Critério de aceite**
- SUITE com `SubscriptionAccessWiringTest|TenantUserActivationIntegrationTest|MutationGuardWiringTest|PasswordResetFormsWiringTest` mostra `PASS` em todos, sem `SKIP`, e `Failed: 0`.
- Na fumaça de T-22, o administrador de uma clínica ZZ-E3- em Starter abrindo `index.php?class=SaleList` recebe 403 com "Seu plano não inclui este recurso", e na mesma clínica com a assinatura `suspended`, `index.php?class=TutorList` recebe 403 com "Sua assinatura está suspensa" e `index.php?class=SubscriptionStatusView` abre.

**Validação**
- SUITE com a `<Classe>` acima (evidência: `Failed: 0`)
- LINT de `app/service/auth/ApplicationAuthenticationService.php`, `app/control/admin/SystemUserForm.php`, `app/control/admin/SystemUnitForm.php`, `app/Core/Tenancy/TenantUserActivation.php` e dos 2 testes (evidência: `No syntax errors detected`; nada novo em `baseline/php-lint.txt`)
- Review Focus: no `SubscriptionAccessWiringTest`, um caso que confirma que a decisão em cache é por tenant e controller (a chave de `cv_subscription_access` inclui os dois), para que um 403 de `SaleList` não bloqueie `TutorList`

### T-20 — Landing: compra

**Camada:** frontend
**Dependências:** T-16
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Saitama

**Arquivos prováveis**
- `src/app/view/landing/landing.html`
- `src/landing/landing.js`
- `src/landing/translations.js`
- `src/landing/landing.css`
- `src/tests/Unit/LandingCheckoutWiringTest.php`

**Interface**
- Produz: em cada card de plano, botão "Assinar" (`data-plan`) que abre a seção `#assinar` com: plano escolhido e preço vindos de `publicJson()` (só exibição), campos responsável, e-mail, telefone, nome da clínica, rádio de forma de pagamento (`card`/`pix`, de `payment_methods`), checkbox obrigatória dos termos com `terms.text` e checkbox separada e opcional do consentimento de contato (`consent.text`); envio por `fetch('checkout.php', {method: 'POST', credentials: 'same-origin'})` com o payload de T-16 e redirecionamento para `checkout_url`; 422 mostra as mensagens por campo; 502 mostra "Não foi possível iniciar o pagamento. Tente de novo em instantes."; `landing.php?checkout=success` mostra "Pagamento recebido. Você receberá por e-mail o convite para definir sua senha." e `checkout=cancelled` mostra "Pagamento não concluído. Nenhum valor foi cobrado."; os textos de `landing.html:230` e `:276` (ativação pela equipe, nenhum pagamento cobrado) passam a descrever a compra; rótulos à esquerda e alvos de 44 px (`--cv-touch-target`)
- Consome: T-16 `"checkout_url": "<session.url>"`, T-16 `TERMS_VERSION = 'assinatura-2026-10'`

**Teste RED**
- `src/tests/Unit/LandingCheckoutWiringTest.php` — `landing.js` declara `CHECKOUT_PATH = "checkout.php"` e envia `terms_accepted`, `terms_version`, `contact_consent` e `payment_method`; `landing.html` tem os campos com `<label>` associados e a checkbox de termos separada da de consentimento; `translations.js` tem as chaves novas em `pt` e `en`; `landing.css` não usa `text-align: right`/`center` nos rótulos novos (comando: `SUITE com <Classe> = LandingCheckoutWiringTest`)

**Critério de aceite**
- SUITE com `LandingCheckoutWiringTest|LandingPageTest|LandingA11yTest|LandingPageHeadTest` mostra `PASS` em todos e `Failed: 0`.
- `node --check src/landing/landing.js` e `node --check src/landing/translations.js` terminam com código 0.
- Na fumaça de T-22, a seção `#assinar` mostra os rótulos à esquerda e o botão "Assinar" mede 44 px ou mais de altura.

**Validação**
- SUITE com a `<Classe>` acima (evidência: `Failed: 0`)
- `node --check src/landing/landing.js; node --check src/landing/translations.js; echo exit=$?` (evidência: `exit=0`)

### T-21 — Tela da assinatura

**Camada:** frontend
**Dependências:** T-01, T-07, T-10, T-11, T-15
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Athena

**Arquivos prováveis**
- `src/app/control/admin/SubscriptionStatusView.php`
- `src/app/Core/Presentation/SubscriptionStatusPresenter.php`
- `src/app/database/seeds/initial-application-programs.sql`
- `src/app/database/seeds/plan-groups.sql`
- `src/menu.xml`
- `src/tests/Unit/SubscriptionStatusPresenterTest.php`

**Interface**
- Produz: `CentralVet\Presentation\SubscriptionStatusPresenter::summary(?Subscription $subscription, \DateTimeImmutable $now): array` → `['plan' => <nome>, 'status_key' => 'Active'|'Overdue'|'Suspended'|'Cancelled'|'Incomplete', 'period' => 'd/m/Y – d/m/Y', 'grace_until' => ?'d/m/Y', 'message_key' => ?string]` (sem assinatura: `message_key = 'No subscription registered for this clinic'`) e `invoiceRows(array $invoices, \DateTimeImmutable $now): array` (período, valores em reais, status e `pay_url` só em fatura `open` com `hosted_invoice_url` e `pix_expires_at` nulo ou futuro)
- Produz: `SubscriptionStatusView` (só leitura, sem mutação: fora do inventário CSRF) com os dados do tenant da sessão, link "Pagar fatura" com `target="_blank"` e `rel="noopener"`, rótulos e cabeçalhos de grid à esquerda; programa `SubscriptionStatusView` no seed inicial (grupo 1) e nos 4 grupos de `plan-groups.sql`; item "Assinatura" em `src/menu.xml` no formato do item de `SystemUserList`
- Consome: T-10 `findByTenantId(int $tenantId): ?Subscription`, T-10 `listBySubscription(int $subscriptionId, int $limit): array`, T-07 `accessLevel(\DateTimeImmutable $now): string`, T-07 `pixExpiresAt(): ?\DateTimeImmutable`, T-15 `SubscriptionAccessGuard::ALWAYS_ALLOWED`, T-01 `Pay invoice`, T-01 `No subscription registered for this clinic`

**Teste RED**
- `src/tests/Unit/SubscriptionStatusPresenterTest.php` — assinatura `past_due` em tolerância dá `status_key = 'Overdue'` e `grace_until` formatado; fatura `open` com URL tem `pay_url`, fatura `paid` não, fatura Pix com `pix_expires_at` no passado não; sem assinatura dá a `message_key` de T-01; `plan-groups.sql` e `initial-application-programs.sql` contêm `SubscriptionStatusView` (comando: `SUITE com <Classe> = SubscriptionStatusPresenterTest`)

**Critério de aceite**
- SUITE com `SubscriptionStatusPresenterTest|PlanGroupsSeedTest` mostra `PASS` em todos e `Failed: 0`.
- Na fumaça de T-22, `index.php?class=SubscriptionStatusView` da clínica ZZ-E3- mostra o plano, o estado em pt-BR e a fatura de teste, com rótulos à esquerda.

**Validação**
- SUITE com a `<Classe>` acima (evidência: `Failed: 0`)
- LINT de `app/control/admin/SubscriptionStatusView.php`, `app/Core/Presentation/SubscriptionStatusPresenter.php` e do teste (evidência: `No syntax errors detected`)

### T-22 — Cenários de aquisição ponta a ponta

**Camada:** qa
**Dependências:** T-17, T-18, T-19, T-20, T-21
**Paralelizável:** não
**Complexidade:** alta
**Agente:** Goku

**Arquivos prováveis**
- `src/tests/Integration/BillingAcquisitionFlowIntegrationTest.php`

**Interface**
- Produz: nada (teste de regressão; `MysqlIntegrationTestCase`, banco `centralvet_test`, `FakeStripeClient`, eventos assinados com `whsec_test_etapa3`, serviços montados como em `src/billing-webhook.php` e `src/checkout.php`)
- Consome: T-16 `handle(string $method, array $headers, string $body, string $ip, \DateTimeImmutable $now): LeadResponse`, T-17 `ingest(array $event, string $rawPayload, \DateTimeImmutable $now): string`, T-18 `run(\DateTimeImmutable $now): array`, T-23 `issueNext(Subscription $subscription, int $priceCents, string $recipientEmail, \DateTimeImmutable $now): ?SubscriptionInvoice`, T-15 `decide(int $tenantId, string $controller, \DateTimeImmutable $now): AccessDecision`

**Teste RED**
- sem teste: teste de regressão de integração sobre comportamento entregue por T-07 a T-21 nesta branch; não há implementação nesta task para o teste preceder

**Critério de aceite**
- No `centralvet_test`, `PASS` em cada cenário da auditoria: (a) pagamento aprovado (cartão) → pedido `provisioned`, 1 tenant, 1 convite; (b) recusado (`checkout.session.async_payment_failed`) → pedido `expired`, 0 tenant; (c) pendente (Pix com checkout aberto, sem evento) → `checkout_open`, 0 tenant, acesso inexistente; (d) webhook inválido → 400, 0 `billing_event`; (e) evento duplicado → `duplicate`, contagens iguais; (f) fora de ordem (`invoice.paid` antes do checkout, depois reenvio) → `active`; (g) falha do gateway no checkout → 502, pedido `pending`, 0 cobrança no fake; (h) aprovado com provisionamento interrompido → `provisioning_failed`, depois o reconciliador conclui sem nova chamada `POST` ao Stripe; (i) renovação aprovada (`invoice.paid` do 2º período) → `current_period_end` avança; (j) renovação recusada → `past_due`, `grace` até 7 dias, depois `suspended` pelo reconciliador e `blocked_status`; (k) cancelamento (`customer.subscription.deleted`) → `cancelled` e `blocked_status`; (n) renovação Pix: o reconciliador emite a fatura com PaymentIntent e `payment_intent.succeeded` avança `current_period_end`; (o) renovação Pix sem pagamento: vencida → `past_due`, `grace` até 7 dias, depois `suspended`, e o pagamento tardio volta a `active`; (l) estorno (`charge.refunded` total) → fatura `refunded`; (m) recurso de outro plano (Starter em `SaleList`) → `blocked_plan`; recurso de outro tenant (assinatura de B suspensa) → A continua `full`.
- Fumaça no navegador (validador), condicional: só roda se o usuário autorizar, quando chegar a Onda 6, aplicar a 0017 e os seeds no banco de dev `centralvet` (o orquestrador pergunta antes da onda; sem autorização, a fumaça vira pendência registrada e o critério de T-22 é só a integração no `centralvet_test`). Com autorização: 0017 e seeds aplicados, rebuild de `app` e `worker`, login, reset e PDF (regressão do S5), compra pela landing até a chamada ao `checkout.php` com `STRIPE_*` de teste vazios devolvendo 502 e a mensagem em pt-BR (sem Stripe real), depois webhook local assinado com o segredo de teste simulando o pagamento, convite no log (`COMMUNICATION_EMAIL_DRIVER=log`), definição de senha pelo link e primeiro acesso, os 403 de T-19, a tela de T-21 e os 404 de T-05; dados `ZZ-E3-` criados e excluídos, `SELECT COUNT(*) ... LIKE 'ZZ-E3-%'` = 0 ao fim.

**Validação**
- SUITE completo com `<Classe>` = `BillingAcquisitionFlowIntegrationTest|MysqlIsolationGuardIntegrationTest` e a linha final (evidência: `PASS` nos cenários (a)–(o), sem `SKIP`, `Failed: 0` no total)
- LINT de `tests/Integration/BillingAcquisitionFlowIntegrationTest.php` (evidência: `No syntax errors detected`)
- Fumaça no navegador acima, só com a autorização do dev (evidência: snapshots, status HTTP e as contagens `ZZ-E3-` = 0; sem autorização: a pendência registrada em `notes.md`)

### T-23 — Fatura Pix por período

**Camada:** backend
**Dependências:** T-01, T-07, T-08, T-10
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Tesla

**Arquivos prováveis**
- `src/app/Core/Billing/PixInvoiceIssuer.php`
- `src/tests/Unit/PixInvoiceIssuerTest.php`

**Interface**
- Produz: `CentralVet\Billing\PixInvoiceIssuer::__construct(StripeClientInterface $stripe, SubscriptionInvoiceRepositoryInterface $invoices, MessageChannelProviderInterface $email, \Closure $translate)` (`$translate(string $key, string ...$params): string`), `PixInvoiceIssuer::ISSUE_DAYS_BEFORE = 3`, `PixInvoiceIssuer::PIX_EXPIRES_AFTER_SECONDS = 864000` (3 dias até o vencimento + 7 de tolerância); não abre transação (o chamador abre)
- Produz: `issueNext(Subscription $subscription, int $priceCents, string $recipientEmail, \DateTimeImmutable $now): ?SubscriptionInvoice`: só assinatura `pix` em `active`/`past_due` com `current_period_end - 3 dias <= now`; se `findByLocalKey('pix:<id>:<current_period_end Y-m-d>')` já acha a fatura, devolve `null` sem chamada; senão `issuePix(<id>, $priceCents, current_period_end, current_period_end + 1 month, current_period_end)`, `insert` (`DuplicateInvoice` → `null`), `ensurePaymentIntent` e devolve a fatura
- Produz: `ensurePaymentIntent(SubscriptionInvoice $invoice, string $stripeCustomerId, string $recipientEmail, \DateTimeImmutable $now): bool`: só com `needsPaymentIntent(now)`; `POST /v1/payment_intents` com `amount` = `amount_due_cents`, `currency=brl`, `customer`, `payment_method_types[0]=pix`, `payment_method_data[type]=pix`, `confirm=true`, `payment_method_options[pix][expires_after_seconds]=864000`, `metadata[subscription_invoice]` = id da fatura e `Idempotency-Key` `pix-invoice-<id>-<payment_intent_attempts + 1>`; depois `attachPaymentIntent(<id>, next_action.pix_display_qr_code.hosted_instructions_url, next_action.pix_display_qr_code.expires_at)`, `save` e o e-mail `OutgoingMessage($recipientEmail, $translate('Central Vet Pro invoice'), $translate('Your Central Vet Pro invoice of ^1 is available. Pay with Pix by ^2: ^3', <amount_due_cents como `R$ 97,00`>, <due_at d/m/Y>, <URL>), 'pix-invoice')`; devolve `true`. `StripeApiException` sobe ao chamador sem gravar nada
- Consome: T-08 `request(string $method, string $path, array $params = [], ?string $idempotencyKey = null): array`, T-10 `findByLocalKey(string $localKey): ?SubscriptionInvoice`, T-10 `insert(SubscriptionInvoice $invoice): int`, T-10 `save(SubscriptionInvoice $invoice): void`, T-07 `SubscriptionInvoice::issuePix(int $subscriptionId, int $amountCents, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd, \DateTimeImmutable $dueAt): SubscriptionInvoice`, T-07 `attachPaymentIntent(string $paymentIntentId, ?string $payUrl, ?\DateTimeImmutable $expiresAt): void`, T-07 `needsPaymentIntent(\DateTimeImmutable $now): bool`, T-01 `Central Vet Pro invoice`, T-01 `Your Central Vet Pro invoice of ^1 is available. Pay with Pix by ^2: ^3`

**Teste RED**
- `src/tests/Unit/PixInvoiceIssuerTest.php` — com `FakeStripeClient`, `FakeSubscriptionInvoiceRepository` e `FakeEmailProvider`: assinatura Pix com período terminando em 2 dias gera 1 fatura `pix:<id>:<Y-m-d>`, 1 `POST /v1/payment_intents` com `payment_method_types[0]=pix`, `amount=9700` e `Idempotency-Key` `pix-invoice-<id>-1`, a URL `hosted_instructions_url` gravada e 1 e-mail com essa URL; período terminando em 5 dias devolve `null` com 0 chamadas; a segunda `issueNext` devolve `null` sem chamada; `ensurePaymentIntent` com `pix_expires_at` no passado usa `pix-invoice-<id>-2`; assinatura de cartão devolve `null` (comando: `SUITE com <Classe> = PixInvoiceIssuerTest`)

**Critério de aceite**
- SUITE com `PixInvoiceIssuerTest` mostra `PASS` em todos os métodos e `Failed: 0`.

**Validação**
- SUITE com `<Classe>` = `PixInvoiceIssuerTest` (evidência: `PASS` nos casos de emissão, repetição e regeneração, `Failed: 0`)
- LINT de `app/Core/Billing/PixInvoiceIssuer.php` e do teste (evidência: `No syntax errors detected`)

## Legenda

- [ ] pendente
- [~] em andamento
- [x] concluído com evidência
- [!] bloqueado

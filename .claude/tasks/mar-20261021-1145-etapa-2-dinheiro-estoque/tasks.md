# Tasks

Origem: `.docs/audits/2026-10-09-prontidao-mvp.md`, itens F2 e F3 (Etapa 2 — Dinheiro e estoque), mais P1–P3 de `.claude/tasks/mar-20261021-0918-etapa-1-seguranca-mvp/notes.md § Pendências`. Caminhos relativos a `/var/www/html/centralvet`; comandos rodam dessa pasta.

Comandos usados em Teste RED e Validação:
- LINT = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -l <arquivo relativo a src/>` → `No syntax errors detected` (baseline `baseline/php-lint.txt`, 0 linhas: qualquer linha de erro é nova).
- SUITE = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro -v /var/www/html/centralvet/.env.example:/var/www/html/.env.example:ro -v /var/www/html/centralvet/docker-compose.yml:/var/www/html/docker-compose.yml:ro -v /var/www/html/centralvet/docker/php/conf.d/app.ini:/var/www/html/docker/php/conf.d/app.ini:ro -v /var/www/html/centralvet/docs:/var/www/html/docs:ro app php tests/run.php 2>&1 | /usr/bin/grep -E '<Classe>|Failed:'` (o runner imprime `  PASS  <Classe>::<método>` / `  FAIL  ...` e `Total: N, Passed: N, Failed: N, Skipped: N`; os testes MySQL saem `SKIP` com o MySQL fora do ar e `FAIL` com a 0016 ausente no `centralvet_test`).
- Testes de integração estendem `CentralVet\Tests\Support\MysqlIntegrationTestCase` (`$this->pdo` em transação, rollback no `tearDown`, guarda contra commit). A segunda conexão segue `SurgeryRepositoryIntegrationTest::secondConnection()` com `SET SESSION innodb_lock_wait_timeout = 1`, `beginTransaction()` e `rollBack()` no `finally`; nenhuma das duas conexões faz COMMIT.
- Mensagens novas ao usuário só pelas chaves de T-01 (`_t('<en>')`); nenhuma task além de T-01 edita `translations.json` nem `UserMessage.php`.

| ID | Camada | Descrição | Dependências | Paralelizável | Complexidade | Agente | Status |
|---|---|---|---|---|---|---|---|
| T-01 | shared | Mensagens pt-BR da Etapa 2 em translations.json e mapeamentos em UserMessage | — | sim | simples | Maquiavel | [x] |
| T-02 | database | Migration 0016 (sale_id no recebível, cancelamento da venda, payment_reversal, submission_key, motivo sale_cancellation) e banco de teste | — | sim | média | Darwin | [x] |
| T-03 | backend | P2: SearchParameterGuard aceita orderColumn só entre column e key | — | sim | simples | Sherlock | [x] |
| T-04 | backend | P3: regra do class_alias em MutationRequestPolicy::resolveCanonical e guard com 3 argumentos | — | sim | média | Sherlock | [x] |
| T-05 | infra | P1: request_order="GP" na imagem PHP, teste do app.ini e runbook da hospedagem | T-02 | sim | simples | Kratos | [x] |
| T-06 | backend | F2 estoque: lotes com FOR UPDATE, baixa condicional e devolução por referência | T-02 | sim | alta | Goku | [x] |
| T-07 | backend | F2 idempotência: SubmissionToken, SubmissionKeyRepository e DuplicateSubmissionException | T-02 | sim | média | Naruto | [x] |
| T-08 | backend | Recebível de venda, bloqueio do recebível, PaymentReversal e totais de caixa líquidos | T-02 | sim | alta | Thanos | [x] |
| T-09 | backend | Sale::cancel rastreável, SaleRepository com bloqueio e consumo em ordem de product_id | T-02 | sim | média | Batman | [x] |
| T-10 | backend | SaleFinanceReader: lista de vendas e resumo do recibo a partir dos dados gravados | T-02 | sim | média | Saitama | [x] |
| T-11 | backend | PaymentService::register com bloqueio e token; PaymentForm e PendingReceivableList | T-01, T-07, T-08 | sim | alta | Thanos | [ ] |
| T-12 | backend | PaymentReversalService: estorna recebimentos no caixa aberto e cancela o recebível | T-01, T-08 | sim | média | Hegel | [ ] |
| T-13 | backend | SaleCheckoutService: venda + recebível + recebimento à vista na mesma transação, com token | T-06, T-07, T-08, T-09, T-11 | sim | alta | Athena | [ ] |
| T-14 | backend | SaleCancellationService: devolve estoque, estorna e cancela, idempotente | T-06, T-08, T-09, T-12 | sim | alta | Batman | [ ] |
| T-15 | frontend | SaleForm: forma de pagamento, token, checkout e recibo dos dados gravados | T-01, T-10, T-13 | sim | alta | Tesla | [ ] |
| T-16 | frontend | SaleList, SaleCancelForm (POST + token), aba, seed RBAC e inventário CSRF | T-01, T-03, T-10, T-14 | sim | média | Kratos | [ ] |
| T-17 | qa | Integração MySQL ponta a ponta: à vista, a prazo, corrida, rollback, repetição e cancelamento | T-13, T-14, T-15, T-16 | não | alta | Saitama | [ ] |

## Detalhamento

### T-01 — Mensagens pt-BR da Etapa 2 em translations.json e mapeamentos em UserMessage

**Camada:** shared
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Maquiavel

**Arquivos prováveis**
- `src/app/config/translations.json`
- `src/app/Core/Presentation/UserMessage.php`
- `src/tests/Unit/Etapa2MessagesTest.php`

**Interface**
- Produz: chave `This submission was already processed` → `Este envio já foi processado; nada foi gravado novamente.`
- Produz: chave `Invalid submission token` → `Envio inválido. Recarregue a tela e tente novamente.`
- Produz: chave `Open a cash session for this unit before receiving payment` → `Abra o caixa desta unidade antes de receber o pagamento.`
- Produz: chave `Open a cash session for this unit before reversing payments` → `Abra o caixa desta unidade antes de estornar recebimentos.`
- Produz: chave `This receivable is cancelled and cannot receive payments` → `Este recebível está cancelado e não aceita recebimentos.`
- Produz: chave `A cancellation reason is required` → `Informe o motivo do cancelamento.`
- Produz: chave `The cancellation reason must have at most 500 characters` → `O motivo do cancelamento aceita no máximo 500 caracteres.`
- Produz: chave `This sale was already cancelled` → `Esta venda já estava cancelada; nada foi alterado.`
- Produz: chave `Sale cancelled successfully` → `Venda cancelada com sucesso.`
- Produz: chave `You are not allowed to cancel sales for this unit` → `Você não tem permissão para cancelar vendas desta unidade.`
- Produz: chave `Stock changed during the sale; review the cart and try again` → `O estoque mudou durante a venda; revise o carrinho e tente novamente.`
- Produz: rótulos `On account (open receivable)` → `A prazo (recebível em aberto)`, `Amount received` → `Valor recebido`, `Amount due` → `Saldo a receber`, `Cancelled sale` → `Venda cancelada`, `Sale history` → `Histórico de vendas`, `Cancel sale` → `Cancelar venda`, `Financial status` → `Situação financeira`, `No payment received` → `Nenhum recebimento registrado`, `Sale #^1` → `Venda #^1`, `Encounter account #^1` → `Conta de atendimento #^1`, `Cancelled by ^1 on ^2` → `Cancelada por ^1 em ^2`
- Produz: `UserMessage::resolve(<mensagem>)` devolve `['key' => <mensagem>, 'params' => []]` para as 7 mensagens de domínio `This submission was already processed`, `Invalid submission token`, `Open a cash session for this unit before receiving payment`, `Open a cash session for this unit before reversing payments`, `This receivable is cancelled and cannot receive payments`, `A cancellation reason is required`, `The cancellation reason must have at most 500 characters`
- Consome: nada (as chaves já existentes `Payment method`, `Origin`, `Cancellation reason`, `Status`, `Balance due`, `Paid` não são duplicadas)

**Teste RED**
- `src/tests/Unit/Etapa2MessagesTest.php` — cada chave da Interface tem o pt exato em `translations.json` (decodificado como em `SecurityMvpMessagesTest`), e as 7 mensagens de domínio resolvem para si mesmas via `UserMessage::resolve` (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'Etapa2MessagesTest|Failed:'`)

**Critério de aceite**
- SUITE com `<Classe>` = `Etapa2MessagesTest|SecurityMvpMessagesTest|UserMessageTest|CopilotTranslationsTest` imprime só `PASS` nessas classes; `python3 -c "import json;json.load(open('src/app/config/translations.json'))"` sai com código 0; nenhuma chave `en` aparece duas vezes no arquivo.

**Validação**
- SUITE com `<Classe>` = `Etapa2MessagesTest|SecurityMvpMessagesTest|UserMessageTest|CopilotTranslationsTest` (evidência: `PASS  CentralVet\Tests\Unit\Etapa2MessagesTest::...` e nenhum `FAIL` nessas classes)
- `python3 -c "import json,collections;d=json.load(open('src/app/config/translations.json'));print([k for k,v in collections.Counter(e['en'] for e in d).items() if v>1])"` (evidência: `[]`)
- LINT de `app/Core/Presentation/UserMessage.php` e `tests/Unit/Etapa2MessagesTest.php` (evidência: `No syntax errors detected` nos dois)

### T-02 — Migration 0016 (sale_id no recebível, cancelamento da venda, payment_reversal, submission_key, motivo sale_cancellation) e banco de teste

**Camada:** database
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Darwin

**Arquivos prováveis**
- `src/app/database/migrations/20261021_0016_etapa2_sale_finance.sql`
- `src/app/database/migrations/20261021_0016_etapa2_sale_finance.verify.sql`
- `scripts/test-db/provision.sh`
- `docs/runbooks/shared-hosting-mysql57.md`
- `docs/runbooks/tests.md`

**Interface**
- Produz: `receivable.encounter_account_id bigint unsigned NULL` (o UNIQUE `receivable_encounter_account_uq` e a FK continuam)
- Produz: `receivable.sale_id bigint unsigned NULL` com `UNIQUE KEY receivable_sale_uq (sale_id)`, `CONSTRAINT receivable_sale_fk FOREIGN KEY (sale_id) REFERENCES sale (id)` e `CONSTRAINT receivable_origin_ck CHECK ((encounter_account_id IS NOT NULL AND sale_id IS NULL) OR (encounter_account_id IS NULL AND sale_id IS NOT NULL))`
- Produz: `sale.cancelled_at timestamp(6) NULL`, `sale.cancelled_by_system_user_id int NULL` (FK `sale_cancelled_by_fk` → `system_users (id)`), `sale.cancellation_reason varchar(500) NULL` e `CONSTRAINT sale_cancellation_ck CHECK ((status = 'completed' AND cancelled_at IS NULL AND cancelled_by_system_user_id IS NULL AND cancellation_reason IS NULL) OR (status = 'cancelled' AND cancelled_at IS NOT NULL AND cancelled_by_system_user_id IS NOT NULL AND cancellation_reason IS NOT NULL))`
- Produz: tabela `payment_reversal (id bigint unsigned AUTO_INCREMENT, tenant_id bigint unsigned NOT NULL, payment_id bigint unsigned NOT NULL, cash_session_id bigint unsigned NOT NULL, payment_method varchar(20) NOT NULL, amount_cents int unsigned NOT NULL, reason varchar(500) NOT NULL, system_user_id int NOT NULL, reversed_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6))` com `UNIQUE KEY payment_reversal_payment_uq (payment_id)`, FKs para `tenant`, `payment`, `cash_session`, `system_users`, `payment_reversal_amount_ck CHECK (amount_cents >= 1)` e `payment_reversal_method_ck` com a mesma lista de `payment_method_ck`
- Produz: tabela `submission_key (id bigint unsigned AUTO_INCREMENT, tenant_id bigint unsigned NOT NULL, scope varchar(20) NOT NULL, token char(32) NOT NULL, reference_id bigint unsigned NULL, system_user_id int NOT NULL, created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6))` com `UNIQUE KEY submission_key_uq (tenant_id, scope, token)`, FKs para `tenant` e `system_users` e `submission_key_scope_ck CHECK (scope IN ('sale', 'payment'))`
- Produz: `stock_movement_reason_ck` ampliado com `'sale_cancellation'` (`DROP CHECK` + `ADD CONSTRAINT`, como na 0011)
- Produz: registro `INSERT INTO schema_migrations (version, checksum) VALUES ('20261021_0016_etapa2_sale_finance', <placeholder de 64 zeros>)`; entrada `$migrations_dir/20261021_0016_etapa2_sale_finance.sql` no fim de `schema_files` de `provision.sh`; seção `## Migration 0016 (venda e financeiro)` no runbook, com o prefixo `20-`
- Consome: nada

**Teste RED**
- sem teste: migration pura (DDL) e documentação; a prova é o preparador 5.7, o `--check` do provisionamento e o `.verify.sql` no banco de teste, na Validação

**Critério de aceite**
- `python3 scripts/prepare-mysql57.py <dir com a cópia 20-20261021_0016_etapa2_sale_finance.sql> <dir>/out` termina com código 0 e gera os triggers dos CHECKs novos; `sh scripts/test-db/provision.sh --check` termina com código 0 listando a 0016. A aplicação da 0016 (autorizada pelo usuário) ao `centralvet_test` termina com código 0, e o `.verify.sql` mostra 1 linha em `schema_migrations` para `20261021_0016_etapa2_sale_finance`, as 2 tabelas e 4 colunas novas e 0 violações. `SELECT COUNT(*) FROM receivable` e `SELECT COUNT(*) FROM sale` no `centralvet_test` têm o mesmo valor antes e depois da aplicação.

**Validação**
- `d=$(mktemp -d -p /tmp/claude-1000/-var-www-html-centralvet/fc92cc27-9f66-4ad7-bfe3-efed43d88248/scratchpad); cp src/app/database/migrations/20261021_0016_etapa2_sale_finance.sql "$d/20-20261021_0016_etapa2_sale_finance.sql"; python3 scripts/prepare-mysql57.py "$d" "$d/out"; echo exit=$?` (evidência: `exit=0` e o arquivo gerado em `$d/out` contém `receivable_origin_ck`, `sale_cancellation_ck`, `submission_key_scope_ck`)
- `sh scripts/test-db/provision.sh --check; echo exit=$?` (evidência: `exit=0`)
- Autorizado pelo usuário (notes.md § Decisões, resposta 1): `printf 'SELECT COUNT(*) FROM receivable; SELECT COUNT(*) FROM sale;\n' | docker compose exec -T mysql sh -ceu 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --batch --skip-column-names centralvet_test'`, antes e depois de `docker compose exec -T mysql sh -ceu 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 centralvet_test' < src/app/database/migrations/20261021_0016_etapa2_sale_finance.sql`, seguido do `.verify.sql` pelo mesmo cliente (evidência: as duas contagens iguais antes e depois; `.verify.sql` com as contagens "expected" dos comentários e 0 nas linhas de violação).
- `grep -n "0016" scripts/test-db/provision.sh docs/runbooks/shared-hosting-mysql57.md docs/runbooks/tests.md` (evidência: uma linha em cada arquivo, a do runbook com `20-`)

### T-03 — P2: SearchParameterGuard aceita orderColumn só entre column e key

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Sherlock

**Arquivos prováveis**
- `src/app/Core/Security/SearchParameterGuard.php`
- `src/tests/Unit/SearchParameterGuardTest.php`
- `docs/security/csrf-inventario.md`

**Interface**
- Produz: `SearchParameterGuard::check(string $class, array $request, ?int $tenantId): ?string` (assinatura inalterada) devolve `'orderColumn is not a searched column'` quando `orderColumn` não é vazio e não é `key` nem um dos itens de `explode(',', column)`; a checagem de identificador continua antes
- Produz: parágrafo final de `docs/security/csrf-inventario.md` (o "Aguarda decisão" sobre `operator`/`orderColumn`) reescrito com a regra nova e a referência a `SearchParameterGuard`
- Consome: nada

**Teste RED**
- `src/tests/Unit/SearchParameterGuardTest.php` — `check('AdiantiMultiSearchService', <systemUserRequest() com orderColumn = 'password'>, null)` devolve `'orderColumn is not a searched column'` (hoje devolve `null`); `orderColumn = 'name'` com `column = 'name'` e `orderColumn = 'id'` com `key = 'id'` continuam `null` (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'SearchParameterGuardTest|Failed:'`)

**Critério de aceite**
- SUITE com `<Classe>` = `SearchParameterGuardTest|MutationGuardWiringTest` imprime só `PASS` nessas classes, inclusive os casos reais do `SaleForm` (Tutor, Patient, Product, ProcedureCatalogItem com `orderColumn` = `column`), do `SystemUserForm` (SystemProgram `name`) e do `AppointmentForm` (SystemUser `name`); `orderColumn=password` devolve a string `orderColumn is not a searched column`.

**Validação**
- SUITE com `<Classe>` = `SearchParameterGuardTest|MutationGuardWiringTest` (evidência: `PASS  ...SearchParameterGuardTest::...` para o caso `password` e nenhum `FAIL`)
- `grep -rnE "new (TDBMultiSearch|TDBUniqueSearch)\(" src/app` (evidência: os 11 usos têm `orderColumn` igual à coluna exibida ou omitido, o que o widget converte em `column`, em `src/lib/adianti/widget/wrapper/TDBMultiSearch.php:339`)
- LINT de `app/Core/Security/SearchParameterGuard.php` e `tests/Unit/SearchParameterGuardTest.php` (evidência: `No syntax errors detected`)

### T-04 — P3: regra do class_alias em MutationRequestPolicy::resolveCanonical e guard com 3 argumentos

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Sherlock

**Arquivos prováveis**
- `src/app/Core/Security/MutationRequestPolicy.php`
- `src/app/service/auth/ApplicationAuthenticationService.php`
- `src/tests/Unit/MutationRequestPolicyTest.php`
- `src/tests/Unit/MutationGuardWiringTest.php`

**Interface**
- Produz: `MutationRequestPolicy::resolveCanonical(string $class, string $method): ?array` — classe ou método inexistente → `null`. Nome igual ao da Reflection → `[<classe canônica>, <método canônico>]`. Nome diferente cujo nome curto da classe real (depois do último `\`) é byte a byte `$class` → `[$class, <método canônico>]` (alias do `AdiantiClassMap`). Caixa diferente ou barra inicial → o nome canônico, que difere do pedido, e a política nega.
- Produz: em `ApplicationAuthenticationService::enforceMutationGuard()`, a chamada `MutationRequestPolicy::decideFromRequest($_SERVER, $_REQUEST, $token)` (3 argumentos, resolvedor padrão); `ApplicationAuthenticationService::resolveDispatchTarget` deixa de existir; o comentário sobre `request_order` vazio passa a citar `request_order = "GP"` (P1) e manter `withoutCsrfCookie` para hospedagens sem essa configuração
- Consome: nada

**Teste RED**
- `src/tests/Unit/MutationGuardWiringTest.php` — com o fixture `CsrfFixtureFrameworkSearchService` (alias de `CentralVet\Tests\Unit\CsrfFixtureFrameworkSearchService`), `MutationRequestPolicy::decideFromRequest($server, $request, $token, $query, $body)` sem resolvedor devolve `ALLOW` (hoje `DENY`); a classe pedida como `csrffixtureframeworksearchservice` continua `DENY`; `method_exists(\ApplicationAuthenticationService::class, 'resolveDispatchTarget')` é `false`; a fonte de `ApplicationAuthenticationService.php` contém `MutationRequestPolicy::decideFromRequest($_SERVER, $_REQUEST, $token)` (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'MutationGuardWiringTest|Failed:'`)

**Critério de aceite**
- SUITE com `<Classe>` = `MutationGuardWiringTest|MutationRequestPolicyTest|SearchParameterGuardTest` imprime só `PASS`; `grep -rn resolveDispatchTarget src/app src/tests` não imprime nada; `MutationRequestPolicy::resolveCanonical('NoSuchClassAnywhere', 'onReload')` continua `null` e `resolveCanonical('centralvet\\security\\csrftoken', 'ISVALID')` continua devolvendo o nome canônico (teste existente da linha 345).

**Validação**
- SUITE com `<Classe>` = `MutationGuardWiringTest|MutationRequestPolicyTest|SearchParameterGuardTest` (evidência: `PASS` no teste do alias sem resolvedor e no de caixa divergente, nenhum `FAIL`)
- `grep -rn "resolveDispatchTarget" src/app src/tests; echo exit=$?` (evidência: `exit=1`)
- LINT de `app/Core/Security/MutationRequestPolicy.php`, `app/service/auth/ApplicationAuthenticationService.php`, `tests/Unit/MutationRequestPolicyTest.php` e `tests/Unit/MutationGuardWiringTest.php` (evidência: `No syntax errors detected` nos quatro)

### T-05 — P1: request_order="GP" na imagem PHP, teste do app.ini e runbook da hospedagem

**Camada:** infra
**Dependências:** T-02
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Kratos

**Arquivos prováveis**
- `docker/php/conf.d/app.ini`
- `docs/runbooks/shared-hosting-mysql57.md`
- `src/tests/Unit/PhpRequestOrderTest.php`

**Interface**
- Produz: linha `request_order = "GP"` em `docker/php/conf.d/app.ini` (copiado para `/usr/local/etc/php/conf.d/99-centralvet.ini` pelo `docker/php/Dockerfile:62`, vale para CLI e FPM)
- Produz: subseção `### request_order (cookies fora de $_REQUEST)` em `## Configuração da aplicação` do runbook, com `request_order = "GP"` em `.user.ini`/`php.ini` da hospedagem, o sintoma (`PHPSESSID=` nas URLs montadas pelo Adianti) e a conferência por `ini_get('request_order')`
- Consome: nada

**Teste RED**
- `src/tests/Unit/PhpRequestOrderTest.php` — `parse_ini_file(dirname(__DIR__, 3) . '/docker/php/conf.d/app.ini')['request_order']` é `'GP'` (hoje a chave não existe) (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro -v /var/www/html/centralvet/docker/php/conf.d/app.ini:/var/www/html/docker/php/conf.d/app.ini:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'PhpRequestOrderTest|Failed:'`)

**Critério de aceite**
- `PASS  ...PhpRequestOrderTest::...` no SUITE. Depois de `docker compose build app worker && docker compose up -d app worker`, `docker compose exec -T app php -r 'echo ini_get("request_order");'` imprime `GP` e `docker compose exec -T app php-fpm -i | grep request_order` imprime `request_order => GP => GP`. Com o cookie-jar do orquestrador, a página de lista paginada `index.php?class=SystemUserList` não contém `PHPSESSID` (0 ocorrências).

**Validação**
- SUITE com `<Classe>` = `PhpRequestOrderTest|MutationGuardWiringTest` (evidência: `PASS` nas duas classes)
- `docker compose build app worker && docker compose up -d app worker && docker compose exec -T app php -r 'echo ini_get("request_order");'` (evidência: `GP`)
- `docker compose exec -T app php-fpm -i | grep request_order` (evidência: `request_order => GP => GP`)
- `curl -s -b <cookie-jar do orquestrador> 'http://127.0.0.1:8081/index.php?class=SystemUserList' | grep -c PHPSESSID` (evidência: `0`)
- `grep -n 'request_order = "GP"' docs/runbooks/shared-hosting-mysql57.md docker/php/conf.d/app.ini` (evidência: uma linha em cada)

### T-06 — F2 estoque: lotes com FOR UPDATE, baixa condicional e devolução por referência

**Camada:** backend
**Dependências:** T-02
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Goku

**Arquivos prováveis**
- `src/app/Core/Domain/StockMovement.php`
- `src/app/Core/Domain/Contract/StockBatchRepositoryInterface.php`
- `src/app/Core/Domain/Contract/StockMovementRepositoryInterface.php`
- `src/app/Core/Persistence/StockBatchRepository.php`
- `src/app/Core/Persistence/StockMovementRepository.php`
- `src/app/Core/Application/StockService.php`
- `src/tests/Support/FakeStockBatchRepository.php`
- `src/tests/Support/FakeStockMovementRepository.php`
- `src/tests/Unit/StockServiceTest.php`
- `src/tests/Integration/StockConcurrencyIntegrationTest.php`

**Interface**
- Produz: `StockMovement::REASON_SALE_CANCELLATION = 'sale_cancellation'` (incluído em `REASONS`)
- Produz: `StockBatchRepositoryInterface::lockByProductOrderedByExpiry(int $productId, int $systemUnitId): array` — mesma ordem e filtro de `listByProductOrderedByExpiry`, com `FOR UPDATE`; fora de transação lança `\LogicException('lockByProductOrderedByExpiry() requires an open transaction')`
- Produz: `StockBatchRepositoryInterface::decreaseQuantity(int $batchId, int $quantity): bool` — `UPDATE stock_batch SET quantity = quantity - :quantity WHERE <tenant> AND id = :id AND quantity >= :quantity`; `true` só com 1 linha afetada
- Produz: `StockBatchRepositoryInterface::increaseQuantity(int $batchId, int $quantity): void` — `SET quantity = quantity + :quantity` no tenant
- Produz: `StockMovementRepositoryInterface::listByReference(string $referenceType, int $referenceId): array` (`list<StockMovement>` em ordem de `id`)
- Produz: `StockService::consume(int $tenantId, int $systemUnitId, int $productId, int $quantity, string $reason, ?string $referenceType, ?int $referenceId, int $professionalSystemUserId): void` (assinatura e construtor inalterados) lê com `lockByProductOrderedByExpiry` e baixa com `decreaseQuantity`; `false` → `InsufficientStockException::forShortfall($productId, <restante>)`
- Produz: `StockService::reverseReferenceConsumption(int $tenantId, string $referenceType, int $referenceId, string $originalReason, string $reversalReason, int $professionalSystemUserId): int` — para cada movimento `out` da referência com `$originalReason`: `increaseQuantity(lote, qtd)` + movimento `in` com `$reversalReason` na mesma referência; devolve as unidades repostas; se a referência já tem movimento com `$reversalReason`, devolve `0` sem gravar
- Consome: nada (a coluna de motivo vem do CHECK ampliado em T-02, só no MySQL)

**Teste RED**
- `src/tests/Integration/StockConcurrencyIntegrationTest.php` — lote com `quantity = 1`; a conexão A (`$this->pdo`) consome 1 pelo `StockService` com repositórios reais e não commita; a conexão B (segunda conexão, `innodb_lock_wait_timeout = 1`, repositórios próprios) chama `consume` do mesmo produto e recebe `PDOException` com `errorInfo[1] = 1205` (hoje B lê sem bloqueio e não espera) (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'StockConcurrencyIntegrationTest|Failed:'`)

**Critério de aceite**
- No `centralvet_test`, a segunda venda concorrente da última unidade termina em `PDOException` 1205 e não grava movimento; `lockByProductOrderedByExpiry` fora de transação lança `LogicException` com `requires an open transaction`; `decreaseQuantity(lote com 1, 2)` devolve `false` e `stock_batch.quantity` fica 1; `reverseReferenceConsumption` repõe a quantidade consumida e grava movimento `in` com `sale_cancellation`, e a segunda chamada devolve `0`. SUITE com `<Classe>` = `StockConcurrencyIntegrationTest|StockServiceTest|SaleServiceTest|ProcedureExecutionServiceTest|HospitalizationDischargeServiceTest|SurgeryCompletionServiceTest|SaleServiceTenantReferencesIntegrationTest` imprime só `PASS` (sem `SKIP` nas de integração).

**Validação**
- SUITE com `<Classe>` acima (evidência: `PASS` no teste 1205, nos de baixa condicional e devolução, e nos chamadores de `consume`; nenhum `FAIL`)
- `grep -n "FOR UPDATE\|quantity >= :quantity" src/app/Core/Persistence/StockBatchRepository.php` (evidência: as duas cláusulas presentes)
- LINT de cada PHP de Arquivos prováveis (evidência: `No syntax errors detected` em todos)

### T-07 — F2 idempotência: SubmissionToken, SubmissionKeyRepository e DuplicateSubmissionException

**Camada:** backend
**Dependências:** T-02
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Naruto

**Arquivos prováveis**
- `src/app/Core/Domain/SubmissionToken.php`
- `src/app/Core/Domain/Contract/SubmissionKeyRepositoryInterface.php`
- `src/app/Core/Persistence/SubmissionKeyRepository.php`
- `src/app/Core/Domain/Exception/DuplicateSubmissionException.php`
- `src/tests/Support/FakeSubmissionKeyRepository.php`
- `src/tests/Unit/SubmissionTokenTest.php`
- `src/tests/Integration/SubmissionKeyRepositoryIntegrationTest.php`

**Interface**
- Produz: `CentralVet\Domain\SubmissionToken::SCOPE_SALE = 'sale'` e `SubmissionToken::SCOPE_PAYMENT = 'payment'`
- Produz: `SubmissionToken::generate(): string` (`bin2hex(random_bytes(16))`, 32 hex minúsculos)
- Produz: `SubmissionToken::assertValid(string $token): string` — devolve o token quando casa `/\A[0-9a-f]{32}\z/`; senão `InvalidArgumentException('Invalid submission token')`
- Produz: `CentralVet\Domain\Contract\SubmissionKeyRepositoryInterface::claim(string $scope, string $token, int $systemUserId): bool` — `true` quando inseriu; `false` quando já existe a tripla tenant/escopo/token (erro 1062); fora de transação `\LogicException('claim() requires an open transaction')`
- Produz: `SubmissionKeyRepositoryInterface::attach(string $scope, string $token, int $referenceId): void` e `SubmissionKeyRepositoryInterface::findReference(string $scope, string $token): ?int`
- Produz: `CentralVet\Persistence\SubmissionKeyRepository::__construct(TenantContext $context, PDO $connection)`
- Produz: `CentralVet\Domain\Exception\DuplicateSubmissionException::forReference(string $scope, ?int $referenceId): self` com mensagem `This submission was already processed`, `->scope(): string` e `->referenceId(): ?int`
- Produz: `CentralVet\Tests\Support\FakeSubmissionKeyRepository::__construct()` (em memória, sem checagem de transação)
- Consome: T-01 `This submission was already processed`, T-01 `Invalid submission token`

**Teste RED**
- `src/tests/Integration/SubmissionKeyRepositoryIntegrationTest.php` — no mesmo tenant, `claim('sale', $t, $user)` devolve `true` e a segunda chamada `false`; em outro tenant, o mesmo token devolve `true`; com A sem commit, `claim` do mesmo token na segunda conexão termina em `PDOException` 1205; `attach` + `findReference` devolvem o id gravado (hoje a classe não existe) (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'SubmissionKeyRepositoryIntegrationTest|Failed:'`)

**Critério de aceite**
- SUITE com `<Classe>` = `SubmissionKeyRepositoryIntegrationTest|SubmissionTokenTest` imprime só `PASS` (sem `SKIP`): `assertValid` recusa `''`, 31 caracteres, maiúsculas e `"<32 hex>\n"`; `generate()` casa o padrão; `claim` fora de transação lança `LogicException`.

**Validação**
- SUITE com `<Classe>` = `SubmissionKeyRepositoryIntegrationTest|SubmissionTokenTest` (evidência: `PASS` nos casos 1062, 1205, outro tenant e `\n` final; nenhum `FAIL`)
- LINT de cada PHP de Arquivos prováveis (evidência: `No syntax errors detected` em todos)

### T-08 — Recebível de venda, bloqueio do recebível, PaymentReversal e totais de caixa líquidos

**Camada:** backend
**Dependências:** T-02
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Thanos

**Arquivos prováveis**
- `src/app/Core/Domain/Receivable.php`
- `src/app/Core/Domain/Contract/ReceivableRepositoryInterface.php`
- `src/app/Core/Persistence/ReceivableRepository.php`
- `src/tests/Support/FakeReceivableRepository.php`
- `src/app/Core/Domain/PaymentReversal.php`
- `src/app/Core/Domain/Contract/PaymentReversalRepositoryInterface.php`
- `src/app/Core/Persistence/PaymentReversalRepository.php`
- `src/tests/Support/FakePaymentReversalRepository.php`
- `src/app/Core/Domain/Exception/CashSessionRequiredException.php`
- `src/app/Core/Application/CashSessionService.php`
- `src/app/Core/Persistence/FinancialOverviewReader.php`
- `src/tests/Unit/ReceivableSaleOriginTest.php`
- `src/tests/Integration/PaymentReversalIntegrationTest.php`

**Interface**
- Produz: `Receivable::openForSale(int $tenantId, int $saleId, int $tutorId, int $totalCents): self` — `encounterAccountId()` `null`, `saleId()` = `$saleId`, `paidCents()` 0, `status()` `open` (ou `paid` quando `$totalCents === 0`)
- Produz: `Receivable::encounterAccountId(): ?int`, `Receivable::saleId(): ?int`, `Receivable::isCancelled(): bool`
- Produz: `Receivable::cancel(): void` — `status` → `cancelled`, `paid_cents` preservado; em recebível já cancelado não altera nada
- Produz: `Receivable::recordPayment(int $amountCents): void` lança `InvalidStatusTransitionException('This receivable is cancelled and cannot receive payments')` quando `status` é `cancelled`, antes de mexer em qualquer campo
- Produz: `ReceivableRepositoryInterface::findByIdForUpdate(int $id): ?Receivable` (`FOR UPDATE`; fora de transação `\LogicException('findByIdForUpdate() requires an open transaction')`) e `ReceivableRepositoryInterface::findBySaleId(int $saleId): ?Receivable`
- Produz: `ReceivableRepository::save` grava `sale_id` no INSERT; `hydrate` lê `encounter_account_id`/`sale_id` nulos
- Produz: `CentralVet\Domain\PaymentReversal::record(int $tenantId, int $paymentId, int $cashSessionId, string $paymentMethod, int $amountCents, string $reason, int $systemUserId, DateTimeImmutable $reversedAt): self` com `id(): ?int`, `assignId(int $id): void`, `tenantId()`, `paymentId()`, `cashSessionId()`, `paymentMethod()`, `amountCents()`, `reason()`, `systemUserId()`, `reversedAt()`
- Produz: `PaymentReversalRepositoryInterface` (estende `RepositoryInterface`) com `existsForPayment(int $paymentId): bool` e `listByReceivable(int $receivableId): array`; `CentralVet\Persistence\PaymentReversalRepository::__construct(TenantContext $context, PDO $connection)`; `CentralVet\Tests\Support\FakePaymentReversalRepository::__construct()`
- Produz: `CentralVet\Domain\Exception\CashSessionRequiredException::forPayment(): self` (mensagem `Open a cash session for this unit before receiving payment`) e `CashSessionRequiredException::forReversal(): self` (mensagem `Open a cash session for this unit before reversing payments`)
- Produz: `CashSessionService::totalsByPaymentMethod(int $cashSessionId): array` = soma de `payment` menos soma de `payment_reversal` com o mesmo `cash_session_id`, por forma; `FinancialOverviewReader::openCashBalance(int $systemUnitId): ?int` = abertura + pagamentos − estornos do caixa aberto
- Consome: T-01 `This receivable is cancelled and cannot receive payments`, T-01 `Open a cash session for this unit before receiving payment`, T-01 `Open a cash session for this unit before reversing payments`

**Teste RED**
- `src/tests/Integration/PaymentReversalIntegrationTest.php` — caixa aberto com abertura 1000, pagamento `cash` 5000 e `PaymentReversal` 5000 no mesmo caixa gravado por `PaymentReversalRepository`: `totalsByPaymentMethod` devolve `['cash' => 0]` e `openCashBalance` devolve 1000; recebível de `openForSale` gravado e relido por `findBySaleId` com `encounterAccountId()` `null`; `findByIdForUpdate` na segunda conexão com A segurando a linha termina em 1205 (hoje as classes e métodos não existem) (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'PaymentReversalIntegrationTest|Failed:'`)

**Critério de aceite**
- SUITE com `<Classe>` = `PaymentReversalIntegrationTest|ReceivableSaleOriginTest|PaymentServiceTest|CashSessionServiceTest|FinancialOverviewIntegrationTest|CopilotFinanceQueryIntegrationTest|EncounterAccountServiceTest` imprime só `PASS` (sem `SKIP` nas de integração). `recordPayment` em recebível cancelado lança a mensagem da Interface e deixa `paidCents()` igual. Um recebível de atendimento continua gravando `encounter_account_id` e `sale_id` NULL.

**Validação**
- SUITE com `<Classe>` acima (evidência: `PASS` em `totalsByPaymentMethod` líquido, `openCashBalance` líquido, 1205 do `findByIdForUpdate` e `recordPayment` recusado; nenhum `FAIL`)
- LINT de cada PHP de Arquivos prováveis (evidência: `No syntax errors detected` em todos)

### T-09 — Sale::cancel rastreável, SaleRepository com bloqueio e consumo em ordem de product_id

**Camada:** backend
**Dependências:** T-02
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Batman

**Arquivos prováveis**
- `src/app/Core/Domain/Sale.php`
- `src/app/Core/Domain/Contract/SaleRepositoryInterface.php`
- `src/app/Core/Persistence/SaleRepository.php`
- `src/tests/Support/FakeSaleRepository.php`
- `src/app/Core/Application/SaleService.php`
- `src/tests/Unit/SaleCancelTest.php`
- `src/tests/Unit/SaleServiceTest.php`
- `src/tests/Integration/SaleRepositoryCancelIntegrationTest.php`

**Interface**
- Produz: `Sale::cancel(string $reason, int $cancelledBySystemUserId, DateTimeImmutable $cancelledAt): void` — `trim($reason)` vazio → `InvalidArgumentException('A cancellation reason is required')`; `mb_strlen` > 500 → `InvalidArgumentException('The cancellation reason must have at most 500 characters')`; venda já cancelada → `InvalidStatusTransitionException('Sale is already cancelled')`; senão `status` `cancelled` com motivo aparado, usuário e data
- Produz: `Sale::isCancelled(): bool`, `Sale::cancelledAt(): ?DateTimeImmutable`, `Sale::cancelledBySystemUserId(): ?int`, `Sale::cancellationReason(): ?string`
- Produz: `Sale::reconstitute(...)` com três parâmetros finais opcionais `?DateTimeImmutable $cancelledAt = null, ?int $cancelledBySystemUserId = null, ?string $cancellationReason = null`
- Produz: `SaleRepositoryInterface::findByIdForUpdate(int $id): ?Sale` (`FOR UPDATE`; fora de transação `\LogicException('findByIdForUpdate() requires an open transaction')`); `SaleRepository::save` de venda existente grava `status`, `cancelled_at`, `cancelled_by_system_user_id`, `cancellation_reason`
- Produz: `SaleService::create(...)` (assinatura inalterada) chama `StockService::consume` dos itens de produto em ordem crescente de `referenceId`, estável para o mesmo produto
- Consome: T-01 `A cancellation reason is required`, T-01 `The cancellation reason must have at most 500 characters`

**Teste RED**
- `src/tests/Unit/SaleCancelTest.php` — `Sale::cancel('   ', 7, $now)` lança `A cancellation reason is required`; 501 caracteres lança `The cancellation reason must have at most 500 characters`; `cancel('  Cliente desistiu ', 7, $now)` deixa `isCancelled()` `true`, `cancellationReason()` `'Cliente desistiu'`, `cancelledBySystemUserId()` 7 e `cancelledAt()` `$now`; a segunda chamada lança `Sale is already cancelled` (hoje `cancel()` não recebe argumentos) (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'SaleCancelTest|Failed:'`)

**Critério de aceite**
- SUITE com `<Classe>` = `SaleCancelTest|SaleServiceTest|SaleRepositoryCancelIntegrationTest|SaleServiceTenantReferencesIntegrationTest` imprime só `PASS` (sem `SKIP` nas de integração). A venda cancelada gravada e relida no `centralvet_test` traz as 3 colunas preenchidas, e `findByIdForUpdate` na segunda conexão termina em 1205 com A segurando a linha. No `SaleServiceTest`, um carrinho `[produto 9, produto 3]` grava os movimentos do produto 3 antes dos do 9.

**Validação**
- SUITE com `<Classe>` acima (evidência: `PASS` nos casos de motivo, de ordem de consumo e de 1205; nenhum `FAIL`)
- LINT de cada PHP de Arquivos prováveis (evidência: `No syntax errors detected` em todos)

### T-10 — SaleFinanceReader: lista de vendas e resumo do recibo a partir dos dados gravados

**Camada:** backend
**Dependências:** T-02
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Saitama

**Arquivos prováveis**
- `src/app/Core/Persistence/SaleFinanceReader.php`
- `src/tests/Integration/SaleFinanceReaderIntegrationTest.php`

**Interface**
- Produz: `CentralVet\Persistence\SaleFinanceReader::__construct(TenantContext $context, PDO $connection)` (mesma base de `FinancialOverviewReader`, consultas presas ao tenant)
- Produz: `SaleFinanceReader::listSales(int $limit, int $offset): array` → `list<array{id: int, sold_at: string, tutor_name: string, total_cents: int, status: string, receivable_id: ?int, receivable_status: ?string, received_cents: int}>` em `sold_at DESC, id DESC`; e `SaleFinanceReader::countSales(): int`
- Produz: `SaleFinanceReader::receiptSummary(int $saleId): ?array` → `array{sale_id: int, status: string, total_cents: int, receivable_status: ?string, received_cents: int, payment_methods: list<string>, cancelled_at: ?string, cancelled_by_name: ?string, cancellation_reason: ?string}`; `received_cents` = soma de `payment` do recebível da venda menos a soma de `payment_reversal` desses pagamentos; `payment_methods` = formas distintas dos pagamentos sem estorno; `null` para venda de outro tenant ou inexistente
- Consome: nada (lê as colunas e tabelas de T-02 por SQL)

**Teste RED**
- `src/tests/Integration/SaleFinanceReaderIntegrationTest.php` — venda de 10000 com recebível `partially_paid` e pagamento `pix` 4000: `receiptSummary` devolve `received_cents` 4000 e `payment_methods` `['pix']`; com `payment_reversal` desse pagamento, `received_cents` 0 e `payment_methods` `[]`; venda de outro tenant → `null`; `listSales(10, 0)` traz a venda com `receivable_status` e `received_cents` (hoje a classe não existe) (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'SaleFinanceReaderIntegrationTest|Failed:'`)

**Critério de aceite**
- SUITE com `<Classe>` = `SaleFinanceReaderIntegrationTest` imprime só `PASS` (sem `SKIP`), com os valores 4000/`['pix']`, 0/`[]` e `null` do Teste RED; venda cancelada traz `cancelled_by_name` com o nome do `system_users`.

**Validação**
- SUITE com `<Classe>` = `SaleFinanceReaderIntegrationTest` (evidência: `PASS` nos casos acima)
- LINT de `app/Core/Persistence/SaleFinanceReader.php` e `tests/Integration/SaleFinanceReaderIntegrationTest.php` (evidência: `No syntax errors detected`)

### T-11 — PaymentService::register com bloqueio e token; PaymentForm e PendingReceivableList

**Camada:** backend
**Dependências:** T-01, T-07, T-08
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Thanos

**Arquivos prováveis**
- `src/app/Core/Application/PaymentService.php`
- `src/app/control/clinic/PaymentForm.php`
- `src/app/control/clinic/PendingReceivableList.php`
- `src/tests/Unit/PaymentServiceTest.php`
- `src/tests/Unit/PaymentFormSubmissionTokenTest.php`
- `src/tests/Integration/PaymentIdempotencyIntegrationTest.php`

**Interface**
- Produz: `PaymentService::__construct(PaymentRepositoryInterface $payments, ReceivableRepositoryInterface $receivables, CashSessionRepositoryInterface $cashSessions, FinancialEntryService $financialEntries, AuthorizationPolicyInterface $authorization, TenantContext $context, SubmissionKeyRepositoryInterface $submissionKeys)`
- Produz: `PaymentService::register(int $receivableId, int $cashSessionId, string $paymentMethod, int $amountCents, int $systemUserId, string $action, ?string $submissionToken = null): Payment` — com token: `SubmissionToken::assertValid`, depois `claim(SubmissionToken::SCOPE_PAYMENT, ...)`; `false` → `DuplicateSubmissionException::forReference('payment', findReference(...))` antes de qualquer leitura; lê o recebível por `findByIdForUpdate`; ao fim `attach('payment', token, <id do payment>)`. Sem token (chamada interna do checkout): sem deduplicação, com o mesmo bloqueio
- Produz: `PaymentForm` com `THidden('submission_token')` = `SubmissionToken::generate()` em `buildPaymentForm`; `onSave` passa `submissionToken: (string) ($param['submission_token'] ?? '')` (vazio → `Invalid submission token`); `DuplicateSubmissionException` → rollback, `TMessage('info', _t('This submission was already processed'))` e recarga do recebível
- Produz: `PendingReceivableList` com coluna `origin` (título `_t('Origin')`, alinhada à esquerda): `_t('Sale #^1', <sale_id>)` ou `_t('Encounter account #^1', <encounter_account_id>)`
- Consome: T-07 `SubmissionKeyRepositoryInterface::claim(string $scope, string $token, int $systemUserId): bool`, T-07 `SubmissionToken::assertValid(string $token): string`, T-07 `DuplicateSubmissionException::forReference(string $scope, ?int $referenceId): self`, T-07 `SubmissionToken::generate(): string`, T-08 `ReceivableRepositoryInterface::findByIdForUpdate(int $id): ?Receivable`, T-08 `Receivable::saleId(): ?int`, T-01 `This submission was already processed`, T-01 `Sale #^1`, T-01 `Encounter account #^1`

**Teste RED**
- `src/tests/Integration/PaymentIdempotencyIntegrationTest.php` — recebível de 10000 e caixa aberto no `centralvet_test`; `register(..., 3000, ..., $token)` duas vezes: a segunda lança `DuplicateSubmissionException`, `SELECT COUNT(*) FROM payment WHERE receivable_id = ?` é 1 e `receivable.paid_cents` é 3000; com A segurando o recebível sem commit, `register` com outro token na segunda conexão termina em 1205 (hoje o construtor não aceita o repositório de chaves) (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'PaymentIdempotencyIntegrationTest|Failed:'`)

**Critério de aceite**
- SUITE com `<Classe>` = `PaymentIdempotencyIntegrationTest|PaymentServiceTest|PaymentFormSubmissionTokenTest|PaymentMethodsTest|CashSessionServiceTest` imprime só `PASS` (sem `SKIP`). Em recebível cancelado, `register` lança `This receivable is cancelled and cannot receive payments` e `payment` não ganha linha (Review Focus). Na fonte de `PaymentForm.php` aparecem `submission_token`, `SubmissionToken::generate()` e `DuplicateSubmissionException`. Na fonte de `PendingReceivableList.php`, a coluna `origin` é criada com `'left'`.

**Validação**
- SUITE com `<Classe>` acima (evidência: `PASS` nos casos de repetição, 1205 e recebível cancelado; nenhum `FAIL`)
- `grep -n "new TDataGridColumn('origin'" src/app/control/clinic/PendingReceivableList.php` (evidência: a linha contém `'left'`)
- LINT de cada PHP de Arquivos prováveis (evidência: `No syntax errors detected` em todos)

### T-12 — PaymentReversalService: estorna recebimentos no caixa aberto e cancela o recebível

**Camada:** backend
**Dependências:** T-01, T-08
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Hegel

**Arquivos prováveis**
- `src/app/Core/Application/PaymentReversalService.php`
- `src/tests/Unit/PaymentReversalServiceTest.php`

**Interface**
- Produz: `CentralVet\Application\PaymentReversalService::__construct(PaymentRepositoryInterface $payments, PaymentReversalRepositoryInterface $reversals, ReceivableRepositoryInterface $receivables, CashSessionRepositoryInterface $cashSessions, FinancialEntryService $financialEntries, AuthorizationPolicyInterface $authorization, TenantContext $context)`
- Produz: `PaymentReversalService::cancelReceivable(int $receivableId, int $systemUnitId, string $reason, int $systemUserId, string $action): array` (`list<PaymentReversal>`), nesta ordem: autoriza unidade `$systemUnitId` (`entityType` `receivable`, `entityId` `$receivableId`); `findByIdForUpdate` (`null` → `InvalidArgumentException("Receivable {id} not found")`); recebível já cancelado → `[]` sem gravar; pagamentos de `listByReceivable` sem `existsForPayment`; se houver algum e `findOpenBySystemUnit($systemUnitId)` for `null` → `CashSessionRequiredException::forReversal()`; para cada pagamento: `PaymentReversal::record(..., <id do caixa aberto>, <forma>, <valor>, $reason, ...)` salvo e `FinancialEntryService::record($systemUnitId, 'expense', <forma>, <valor>, 'payment_reversal', <id do estorno>, $systemUserId, $action, <forma>)`; por fim `Receivable::cancel()` e `save`
- Consome: T-08 `PaymentReversal::record(int $tenantId, int $paymentId, int $cashSessionId, string $paymentMethod, int $amountCents, string $reason, int $systemUserId, DateTimeImmutable $reversedAt): self`, T-08 `existsForPayment(int $paymentId): bool`, T-08 `CashSessionRequiredException::forReversal(): self`, T-08 `Receivable::cancel(): void`, T-08 `ReceivableRepositoryInterface::findByIdForUpdate(int $id): ?Receivable`, T-08 `FakePaymentReversalRepository::__construct()`

**Teste RED**
- `src/tests/Unit/PaymentReversalServiceTest.php` — recebível com pagamentos `cash` 3000 e `pix` 2000 e caixa aberto 77: `cancelReceivable` devolve 2 estornos com `cashSessionId()` 77, grava 2 `financial_entry` `expense` com `reference_type` `payment_reversal` e deixa o recebível `cancelled`; a segunda chamada devolve `[]` e não grava nada; sem caixa aberto lança `Open a cash session for this unit before reversing payments` (hoje a classe não existe) (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'PaymentReversalServiceTest|Failed:'`)

**Critério de aceite**
- SUITE com `<Classe>` = `PaymentReversalServiceTest` imprime só `PASS`, inclusive: pagamento feito no caixa 50 (fechado) com caixa aberto 77 → estorno com `cashSessionId()` 77, e `listByCashSession(50)` com os mesmos pagamentos de antes (Review Focus); recebível sem pagamento cancela sem exigir caixa; autorização negada lança `AuthorizationDenied` sem gravar.

**Validação**
- SUITE com `<Classe>` = `PaymentReversalServiceTest` (evidência: `PASS` nos casos de 2 estornos, idempotência, caixa fechado, sem caixa e autorização negada)
- LINT de `app/Core/Application/PaymentReversalService.php` e `tests/Unit/PaymentReversalServiceTest.php` (evidência: `No syntax errors detected`)

### T-13 — SaleCheckoutService: venda + recebível + recebimento à vista na mesma transação, com token

**Camada:** backend
**Dependências:** T-06, T-07, T-08, T-09, T-11
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Athena

**Arquivos prováveis**
- `src/app/Core/Application/SaleCheckoutService.php`
- `src/app/Core/Application/SaleCheckoutResult.php`
- `src/tests/Unit/SaleCheckoutServiceTest.php`

**Interface**
- Produz: `CentralVet\Application\SaleCheckoutService::__construct(SaleService $sales, ReceivableRepositoryInterface $receivables, PaymentService $payments, CashSessionRepositoryInterface $cashSessions, SubmissionKeyRepositoryInterface $submissionKeys, TenantContext $context)`
- Produz: `SaleCheckoutService::checkout(int $tenantId, int $systemUnitId, int $tutorId, ?int $patientId, ?int $encounterId, int $systemUserId, array $items, ?string $paymentMethod, string $submissionToken, string $action): SaleCheckoutResult`, nesta ordem: `SubmissionToken::assertValid`; `claim('sale', ...)` `false` → `DuplicateSubmissionException::forReference('sale', findReference(...))`; com `$paymentMethod` fora de `Payment::METHODS` → `InvalidArgumentException`; com `$paymentMethod` e sem `findOpenBySystemUnit($systemUnitId)` → `CashSessionRequiredException::forPayment()` antes de criar a venda; `SaleService::create(...)`; `Receivable::openForSale($tenantId, <saleId>, $tutorId, <total>)` salvo; com `$paymentMethod` e total > 0, `PaymentService::register(<receivableId>, <id do caixa>, $paymentMethod, <total>, $systemUserId, $action)` sem token; recebível relido por `findById`; `attach('sale', $submissionToken, <saleId>)`
- Produz: `CentralVet\Application\SaleCheckoutResult::__construct(public readonly Sale $sale, public readonly Receivable $receivable, public readonly ?Payment $payment)`
- Consome: T-11 `SubmissionKeyRepositoryInterface $submissionKeys)`, T-11 `?string $submissionToken = null): Payment`, T-08 `Receivable::openForSale(int $tenantId, int $saleId, int $tutorId, int $totalCents): self`, T-08 `CashSessionRequiredException::forPayment(): self`, T-07 `SubmissionToken::assertValid(string $token): string`, T-07 `DuplicateSubmissionException::forReference(string $scope, ?int $referenceId): self`, T-07 `FakeSubmissionKeyRepository::__construct()`, T-09 `SaleService::create(...)`, T-06 `lockByProductOrderedByExpiry(int $productId, int $systemUnitId): array`

**Teste RED**
- `src/tests/Unit/SaleCheckoutServiceTest.php` — com fakes: à vista `cash` de uma venda de 10000 devolve `payment->amountCents()` 10000 e `receivable->status()` `paid` com `saleId()` da venda; a prazo (`null`) devolve `payment` `null` e `status()` `open`; o mesmo token duas vezes lança `DuplicateSubmissionException` e o `FakeSaleRepository` guarda 1 venda; à vista sem caixa aberto lança `Open a cash session for this unit before receiving payment` com 0 vendas e 0 movimentos de estoque (hoje a classe não existe) (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'SaleCheckoutServiceTest|Failed:'`)

**Critério de aceite**
- SUITE com `<Classe>` = `SaleCheckoutServiceTest|SaleServiceTest|PaymentServiceTest` imprime só `PASS`, inclusive a venda só com itens de preço 0 e forma `pix`: recebível `paid` com total 0, `payment` `null`, `FakePaymentRepository` vazio (Review Focus); token inválido lança `Invalid submission token` antes de qualquer gravação.

**Validação**
- SUITE com `<Classe>` acima (evidência: `PASS` nos casos à vista, a prazo, repetição, sem caixa, total 0 e token inválido; nenhum `FAIL`)
- LINT de `app/Core/Application/SaleCheckoutService.php`, `app/Core/Application/SaleCheckoutResult.php` e `tests/Unit/SaleCheckoutServiceTest.php` (evidência: `No syntax errors detected`)

### T-14 — SaleCancellationService: devolve estoque, estorna e cancela, idempotente

**Camada:** backend
**Dependências:** T-06, T-08, T-09, T-12
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Batman

**Arquivos prováveis**
- `src/app/Core/Application/SaleCancellationService.php`
- `src/app/Core/Application/SaleCancellationResult.php`
- `src/tests/Unit/SaleCancellationServiceTest.php`

**Interface**
- Produz: `CentralVet\Application\SaleCancellationService::__construct(SaleRepositoryInterface $sales, ReceivableRepositoryInterface $receivables, StockService $stock, PaymentReversalService $reversals, AuthorizationPolicyInterface $authorization, TenantContext $context)`
- Produz: `SaleCancellationService::cancel(int $saleId, string $reason, int $systemUserId, string $action): SaleCancellationResult`, nesta ordem: `findByIdForUpdate($saleId)` (`null` → `InvalidArgumentException("Sale {id} not found")`); autoriza unidade `sale->systemUnitId()` (`entityType` `sale`, `entityId` `$saleId`); venda já cancelada → resultado com `alreadyCancelled` `true`, sem gravar; `Sale::cancel($reason, $systemUserId, new DateTimeImmutable())`; `StockService::reverseReferenceConsumption($tenantId, 'sale', $saleId, StockMovement::REASON_SALE_CONSUMPTION, StockMovement::REASON_SALE_CANCELLATION, $systemUserId)`; `findBySaleId($saleId)` não nulo → `PaymentReversalService::cancelReceivable(<id>, sale->systemUnitId(), $reason, $systemUserId, $action)`; `SaleRepository::save($sale)`
- Produz: `CentralVet\Application\SaleCancellationResult::__construct(public readonly Sale $sale, public readonly bool $alreadyCancelled, public readonly int $returnedUnits, public readonly array $reversals)`
- Consome: T-09 `Sale::cancel(string $reason, int $cancelledBySystemUserId, DateTimeImmutable $cancelledAt): void`, T-09 `SaleRepositoryInterface::findByIdForUpdate(int $id): ?Sale`, T-09 `Sale::isCancelled(): bool`, T-06 `StockService::reverseReferenceConsumption(int $tenantId, string $referenceType, int $referenceId, string $originalReason, string $reversalReason, int $professionalSystemUserId): int`, T-06 `StockMovement::REASON_SALE_CANCELLATION = 'sale_cancellation'`, T-08 `findBySaleId(int $saleId): ?Receivable`, T-12 `PaymentReversalService::cancelReceivable(int $receivableId, int $systemUnitId, string $reason, int $systemUserId, string $action): array`

**Teste RED**
- `src/tests/Unit/SaleCancellationServiceTest.php` — venda à vista de 2 unidades com pagamento `cash` e caixa aberto: `cancel(id, 'Cliente desistiu', 7, ...)` devolve `returnedUnits` 2 e 1 estorno, o lote volta à quantidade anterior, o recebível fica `cancelled` e a venda fica com motivo, usuário 7 e data; a segunda chamada devolve `alreadyCancelled` `true` sem novo movimento nem estorno; motivo vazio lança `A cancellation reason is required` sem mexer no estoque (hoje a classe não existe) (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'SaleCancellationServiceTest|Failed:'`)

**Critério de aceite**
- SUITE com `<Classe>` = `SaleCancellationServiceTest|PaymentReversalServiceTest|StockServiceTest` imprime só `PASS`. Uma venda a prazo sem pagamento cancela sem exigir caixa. Autorização negada lança `AuthorizationDenied` antes de qualquer gravação, e venda já cancelada com autorização negada também lança `AuthorizationDenied`.

**Validação**
- SUITE com `<Classe>` acima (evidência: `PASS` nos casos à vista, a prazo, repetição, motivo vazio e autorização negada)
- LINT de `app/Core/Application/SaleCancellationService.php`, `app/Core/Application/SaleCancellationResult.php` e `tests/Unit/SaleCancellationServiceTest.php` (evidência: `No syntax errors detected`)

### T-15 — SaleForm: forma de pagamento, token, checkout e recibo dos dados gravados

**Camada:** frontend
**Dependências:** T-01, T-10, T-13
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Tesla

**Arquivos prováveis**
- `src/app/control/clinic/SaleForm.php`
- `src/app/Core/Presentation/SaleReceiptHtml.php`
- `src/tests/Unit/SaleReceiptHtmlTest.php`
- `src/tests/Unit/SaleFormPricingTest.php`
- `src/tests/Unit/SaleFormCheckoutWiringTest.php`

**Interface**
- Produz: `CentralVet\Presentation\SaleReceiptHtml::render(Sale $sale, array $items, array $summary, callable $translate): string` — `$items` = `list<SaleItem>` de `SaleItemRepositoryInterface::listBySale`; `$summary` = retorno de `SaleFinanceReader::receiptSummary`; `$translate(string $key, string ...$params): string`; mostra `Amount received` com o valor e as formas só quando `received_cents > 0`, senão `No payment received`; mostra `Amount due` = total − recebido; venda `cancelled` mostra `Cancelled sale`, `Cancelled by ^1 on ^2` e o motivo; todo texto variável passa por `htmlspecialchars`
- Produz: `SaleForm` com `TCombo('payment_method')` (rótulo `_t('Payment method')`, opção vazia `_t('On account (open receivable)')` e `Payment::METHODS` com `CvFormat::paymentMethod`) e `THidden('submission_token')` = `SubmissionToken::generate()`; `onSave` chama `SaleCheckoutService::checkout(..., paymentMethod: <combo ou null>, submissionToken: (string) ($param['submission_token'] ?? ''), action: 'SaleForm::onSave')`
- Produz: `onSave` trata `DuplicateSubmissionException` (rollback, `TMessage('info', _t('This submission was already processed'))` e ida para `index.php?class=SaleForm&sale_id=<referenceId>` quando não nulo), `CashSessionRequiredException` (rollback, `CvFormat::userError`) e `PDOException` com `errorInfo[1]` 1205 ou 1213 (rollback, `_t('Stock changed during the sale; review the cart and try again')`)
- Produz: `onGenerateReceiptPdf` monta o PDF com `SaleReceiptHtml::render` a partir de `listBySale` + `receiptSummary`; o snapshot do carrinho em `receiptSessionKey` deixa de ser gravado e lido
- Consome: T-13 `SaleCheckoutService::checkout(int $tenantId, int $systemUnitId, int $tutorId, ?int $patientId, ?int $encounterId, int $systemUserId, array $items, ?string $paymentMethod, string $submissionToken, string $action): SaleCheckoutResult`, T-13 `SaleCheckoutService::__construct(SaleService $sales, ReceivableRepositoryInterface $receivables, PaymentService $payments, CashSessionRepositoryInterface $cashSessions, SubmissionKeyRepositoryInterface $submissionKeys, TenantContext $context)`, T-10 `SaleFinanceReader::receiptSummary(int $saleId): ?array`, T-01 `No payment received`, T-01 `Amount received`, T-01 `Amount due`, T-01 `On account (open receivable)`, T-01 `Cancelled sale`, T-01 `Cancelled by ^1 on ^2`, T-01 `Stock changed during the sale; review the cart and try again`, T-01 `This submission was already processed`

**Teste RED**
- `src/tests/Unit/SaleReceiptHtmlTest.php` — com tradutor identidade, venda de 10000 e `received_cents` 0: o HTML contém `No payment received` e `Amount due` e não contém `Amount received`; com `received_cents` 10000 e `payment_methods` `['cash']`: contém `Amount received`; venda `cancelled`: contém `Cancelled sale`; descrição de item `<b>x</b>` sai escapada (hoje a classe não existe) (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'SaleReceiptHtmlTest|Failed:'`)

**Critério de aceite**
- SUITE com `<Classe>` = `SaleReceiptHtmlTest|SaleFormPricingTest|SaleFormCheckoutWiringTest` imprime só `PASS`. A fonte de `SaleForm.php` contém `SaleCheckoutService`, `submission_token`, `payment_method`, `SaleReceiptHtml::render` e o tratamento de `1205`/`1213`, e não contém `receiptSessionKey`. O rótulo do combo fica à esquerda (sem `text-align:right`/`center`) e o botão de finalizar mantém a classe `cv-touch-target` ou altura ≥ 44 px.

**Validação**
- SUITE com `<Classe>` acima (evidência: `PASS` nas três classes)
- `grep -c "receiptSessionKey" src/app/control/clinic/SaleForm.php` (evidência: `0`)
- LINT de `app/control/clinic/SaleForm.php`, `app/Core/Presentation/SaleReceiptHtml.php` e dos três testes (evidência: `No syntax errors detected`)

### T-16 — SaleList, SaleCancelForm (POST + token), aba, seed RBAC e inventário CSRF

**Camada:** frontend
**Dependências:** T-01, T-03, T-10, T-14
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Kratos

**Arquivos prováveis**
- `src/app/control/clinic/SaleList.php`
- `src/app/control/clinic/SaleCancelForm.php`
- `src/app/lib/widget/CvNav.php`
- `src/app/database/seeds/initial-application-programs.sql`
- `docs/security/csrf-inventario.md`
- `docs/runbooks/shared-hosting-mysql57.md`
- `src/tests/Unit/SaleCancelFormWiringTest.php`

**Interface**
- Produz: `SaleList` (TPage, `CvNav::tabs('stock', 'sale_history')`) com `onReload` lendo `SaleFinanceReader::listSales`/`countSales` em `TTransaction` e colunas alinhadas à esquerda: `#`, `Date`, `Tutor`, `Total`, `Status`, `Financial status` e `Amount received`; ação `Cancel sale` só em venda `completed`, como link `index.php?class=SaleCancelForm&method=onEdit&sale_id=<id>`
- Produz: `SaleCancelForm` (TPage, `parent::setTargetContainer('adianti_right_panel')`): `onEdit` (leitura) mostra a venda e o campo `TText('cancellation_reason')` (rótulo `_t('Cancellation reason')`, obrigatório, 500 caracteres) com `THidden('sale_id')`; o botão `_t('Cancel sale')` (≥ 44 px) envia `onCancel` por POST de formulário (o `cv-shell.js` põe o `X-CSRF-Token`); `onCancel` abre `TTransaction`, chama `SaleCancellationService::cancel($saleId, $reason, $userId, 'SaleCancelForm::onCancel')`, mostra `Sale cancelled successfully` ou `This sale was already cancelled`, trata `AuthorizationDenied` com `You are not allowed to cancel sales for this unit`, fecha a cortina e recarrega `SaleList`
- Produz: aba `'sale_history' => ['Sale history', 'index.php?class=SaleList']` no grupo `stock` de `CvNav`; programas `Central Vet - Sale List` (`SaleList`) e `Central Vet - Sale Cancel Form` (`SaleCancelForm`) no seed, liberados para o grupo 1 no padrão `INSERT ... WHERE NOT EXISTS`; no inventário, `SaleCancelForm::onCancel` na lista de POST + token e `SaleList::onReload`/`SaleCancelForm::onEdit` como leitura; contagem de controladores do seed no runbook atualizada (45 → 47)
- Consome: T-14 `SaleCancellationService::cancel(int $saleId, string $reason, int $systemUserId, string $action): SaleCancellationResult`, T-14 `public readonly bool $alreadyCancelled`, T-10 `SaleFinanceReader::listSales(int $limit, int $offset): array`, T-10 `SaleFinanceReader::countSales(): int`, T-01 `Sale cancelled successfully`, T-01 `This sale was already cancelled`, T-01 `You are not allowed to cancel sales for this unit`, T-01 `Sale history`, T-01 `Cancel sale`, T-01 `Financial status`

**Teste RED**
- `src/tests/Unit/SaleCancelFormWiringTest.php` — `MutationRequestPolicy::isReadOnly('SaleCancelForm', 'onCancel')` é `false` e `isReadOnly('SaleCancelForm', 'onEdit')` é `true`; a fonte de `SaleCancelForm.php` contém `SaleCancellationService` e `'adianti_right_panel'`; o seed contém `'SaleCancelForm'` e `'SaleList'`; `csrf-inventario.md` contém `SaleCancelForm::onCancel`; `CvNav.php` contém `index.php?class=SaleList` (hoje os arquivos não existem ou não têm esses trechos) (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro -v /var/www/html/centralvet/docs:/var/www/html/docs:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'SaleCancelFormWiringTest|Failed:'`)

**Critério de aceite**
- `PASS  ...SaleCancelFormWiringTest::...` no comando do Teste RED. `grep -nE "TDataGridColumn\([^)]*'(right|center)'" src/app/control/clinic/SaleList.php` não imprime nada. No SUITE, `MutationGuardWiringTest` e `MutationRequestPolicyTest` continuam só com `PASS`.

**Validação**
- Comando do Teste RED e SUITE com `<Classe>` = `MutationGuardWiringTest|MutationRequestPolicyTest` (evidência: `PASS` nas classes e nenhum `FAIL`)
- `grep -nE "TDataGridColumn\([^)]*'(right|center)'" src/app/control/clinic/SaleList.php; echo exit=$?` (evidência: `exit=1`)
- LINT de `app/control/clinic/SaleList.php`, `app/control/clinic/SaleCancelForm.php`, `app/lib/widget/CvNav.php` e `tests/Unit/SaleCancelFormWiringTest.php` (evidência: `No syntax errors detected`)

### T-17 — Integração MySQL ponta a ponta: à vista, a prazo, corrida, rollback, repetição e cancelamento

**Camada:** qa
**Dependências:** T-13, T-14, T-15, T-16
**Paralelizável:** não
**Complexidade:** alta
**Agente:** Saitama

**Arquivos prováveis**
- `src/tests/Integration/SaleCheckoutFlowIntegrationTest.php`

**Interface**
- Produz: nada (teste de regressão; `MysqlIntegrationTestCase`, banco `centralvet_test`, rollback no fim; serviços montados com os repositórios reais como no `SaleForm::buildSaleService`)
- Consome: T-13 `SaleCheckoutService::checkout(int $tenantId, int $systemUnitId, int $tutorId, ?int $patientId, ?int $encounterId, int $systemUserId, array $items, ?string $paymentMethod, string $submissionToken, string $action): SaleCheckoutResult`, T-14 `SaleCancellationService::cancel(int $saleId, string $reason, int $systemUserId, string $action): SaleCancellationResult`, T-10 `SaleFinanceReader::receiptSummary(int $saleId): ?array`

**Teste RED**
- sem teste: teste de regressão de integração sobre comportamento entregue por T-06 a T-16 nesta branch; não há implementação nesta task para o teste preceder

**Critério de aceite**
- No `centralvet_test`, com produto de venda 5000 e lote de 2 unidades, `PASS` em cada cenário:
  (a) à vista `cash` de 2 unidades (10000): 1 `sale`, `receivable.sale_id` preenchido com `status = 'paid'`, 1 `payment` de 10000, 1 `financial_entry` `income` com `reference_type = 'payment'`, `openCashBalance` + 10000 e `receiptSummary.received_cents` 10000.
  (b) a prazo: recebível `open`, `received_cents` 0, depois `PaymentService::register` de 4000 (`partially_paid`) e de 6000 (`paid`).
  (c) última unidade: com A sem commit, a segunda venda na segunda conexão termina em 1205.
  (d) rollback: dentro de `SAVEPOINT`, um checkout em que o `FinancialEntryService` nega a autorização (política de teste que nega só `entityType = 'financial_entry'`) lança `AuthorizationDenied`; depois de `ROLLBACK TO SAVEPOINT`, `sale`, `sale_item`, `stock_movement`, `receivable`, `payment`, `financial_entry` e `submission_key` têm as contagens de antes e `stock_batch.quantity` é 2.
  (e) o mesmo token duas vezes: `DuplicateSubmissionException`, 1 `sale` e 1 baixa de estoque.
  (f) cancelamento da venda de (a): `stock_batch.quantity` volta a 2, movimento `in` `sale_cancellation`, recebível `cancelled`, 1 `payment_reversal`, 1 `financial_entry` `expense`, `openCashBalance` volta ao valor anterior à venda e a `sale` tem `cancelled_at`, `cancelled_by_system_user_id` e `cancellation_reason`; o segundo cancelamento devolve `alreadyCancelled` com as contagens iguais.
  (g) pagamento num caixa depois fechado: o estorno vai para o caixa aberto novo, e `totalsByPaymentMethod` do caixa fechado não muda.

**Validação**
- SUITE completo com `<Classe>` = `SaleCheckoutFlowIntegrationTest|MysqlIsolationGuardIntegrationTest` e a linha final (evidência: `PASS` nos cenários (a)–(g), sem `SKIP`, e `Failed: 0` no total; o `MysqlIsolationGuardIntegrationTest` prova que nenhuma linha ficou no banco)
- LINT de `tests/Integration/SaleCheckoutFlowIntegrationTest.php` (evidência: `No syntax errors detected`)
- Teste de fumaça no navegador (validador), autorizado pelo usuário (notes.md § Decisões, resposta 2): aplicar no banco de dev `centralvet` a 0016 e o seed `src/app/database/seeds/initial-application-programs.sql` (root do container mysql, mesmo cliente de T-02) e rodar o `.verify.sql`, depois `docker compose build app worker && docker compose up -d app worker`. Com o cookie-jar do orquestrador e dados `ZZ-E2-` criados e excluídos pelo validador: venda à vista com caixa aberto (recibo com "Valor recebido"), venda a prazo (recibo com "Nenhum recebimento registrado" e linha "Venda #N" em Recebíveis), recebimento parcial pelo `PaymentForm` com duplo clique (1 `payment`), cancelamento pela cortina (`POST engine.php?class=SaleCancelForm&method=onCancel` com `X-CSRF-Token`, 200) e `GET` do mesmo método sem token (403). Ao fim, `SELECT COUNT(*) ... LIKE 'ZZ-E2-%'` = 0 (evidência: screenshots/snapshots e as contagens)

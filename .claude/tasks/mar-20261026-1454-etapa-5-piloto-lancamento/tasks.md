# Tasks

Origem: `.docs/audits/2026-10-09-prontidao-mvp.md` § "Funcionalidades mínimas para uso", § "Ordem recomendada e critérios de liberação" (Etapa 5 — Piloto e lançamento: nenhum bloqueio aberto no escopo anunciado; suporte/onboarding e oferta alinhados) e § Parecer, mais as pendências que bloqueiam o piloto em `.claude/tasks/mar-20261026-1006-etapa-4-operacao/reviews/final.md` (flag de manutenção órfão, T-05) e `.claude/tasks/mar-20261021-1554-etapa-3-contratacao/reviews/final.md` (nenhuma bloqueia; ver `notes.md`). Caminhos relativos a `/var/www/html/centralvet`; comandos rodam dessa pasta.

Comandos usados em Teste RED e Validação (copiados da Etapa 4, `.claude/tasks/mar-20261026-1006-etapa-4-operacao/tasks.md`):
- LINT = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -l <arquivo relativo a src/>` → `No syntax errors detected`.
- LINTALL = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app sh -c 'cd /var/www/html/src && find app tests bin config -name "*.php" -print0 | xargs -0 -n1 php -l 2>&1 | grep -v "^No syntax errors detected" || true' 2>/dev/null` → compare com `baseline/php-lint-all.txt` (0 linhas: qualquer linha é nova).
- BASHN = `find scripts -name '*.sh' -exec bash -n {} \;` → compare com `baseline/bash-n.txt` (0 linhas).
- SUITE = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro -v /var/www/html/centralvet/.env.example:/var/www/html/.env.example:ro -v /var/www/html/centralvet/docker-compose.yml:/var/www/html/docker-compose.yml:ro -v /var/www/html/centralvet/docker:/var/www/html/docker:ro -v /var/www/html/centralvet/docs:/var/www/html/docs:ro -v /var/www/html/centralvet/scripts:/var/www/html/scripts:ro -v /var/www/html/centralvet/.github:/var/www/html/.github:ro app php tests/run.php 2>&1 | /usr/bin/grep -E '<Classe>|Failed:'` (o runner imprime `  PASS  <Classe>::<método>` / `  FAIL  ...` e `Total: N, Passed: N, Failed: N, Skipped: N`; os testes MySQL saem `SKIP` com o MySQL fora do ar e `FAIL` com a 0020 ausente no `centralvet_test`). Testes que leem arquivos fora de `src/` usam `dirname(__DIR__, 3)` (padrão de `PhpRequestOrderTest`), que no container é `/var/www/html`.
- MYSQLTEST = `docker compose exec -T mysql sh -ceu 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch centralvet_test'` (root do container mysql, padrão autorizado desde a Etapa 2 para DDL no banco de teste; nunca contra `centralvet`).
- SHTEST = `bash <arquivo>.test.sh` a partir da raiz; segue `scripts/test-db/provision-check.test.sh`: comandos falsos num diretório temporário à frente do `PATH`, uma linha `PASS  <caso>` ou `FAIL  <caso>` por caso, `Failed: <n>` no fim e código 0 só com `Failed: 0`. Nenhum SHTEST chama o Docker real nem a rede.
- CI = `CI_PROJECT=centralvet-ci-local CI_WORKDIR=var/ci/local IMAGE_TAG=ci-local scripts/ci/run.sh` (pipeline local completo: build, banco de teste isolado, schema, lint, testes de script e suíte; projeto Docker próprio, nunca `centralvet`).
- Testes de integração estendem `CentralVet\Tests\Support\MysqlIntegrationTestCase` (`$this->pdo` em transação, rollback no `tearDown`, guarda contra commit); cada teste cria os próprios tenants (padrão `createTenant()` privado de `SaleCheckoutFlowIntegrationTest`) e usa `TenantContext::authenticated(tenant, user, unit)`.
- Mensagens ao usuário: chave em inglês traduzida por `_t()` com o texto pt-BR em `src/app/config/translations.json`. As chaves desta etapa são as de T-02; chave nova que uma task precisar fora dessa lista vai ao board como `- [T-<id>] chave nova: "<en>" → "<pt>"` e T-19 a acrescenta (ninguém além de T-02 e T-19 edita `translations.json`).
- Interface: rótulos alinhados à esquerda (sem `text-align:right`/`center` em rótulo ou cabeçalho de grid; regra global em `src/app/templates/adminbs5/custom.css`) e alvos de toque de 44 px (`.cv-touch-target` / `--cv-touch-target`) em todo botão e link de ação novo.
- Credenciais: nenhuma chave, senha ou e-mail real em código, teste, log, relatório ou prompt. Em teste, prefixos de chave do Stripe (`sk_` + `live_`) são montados por concatenação; e-mails de teste em `@example.test`.
- Escrita no `.env` da raiz e SQL de escrita no banco `centralvet` (dev) exigem autorização do usuário; nenhuma task depende disso.
- Arquivos protegidos por hash (`src/app/config/framework_hashes.php`: `engine.php`, `index.php`, `init.php`, `app/lib/util/*`, `lib/adianti/*`, ex.: `AdiantiMenuBuilder`) não são editados.

| ID | Camada | Descrição | Dependências | Paralelizável | Complexidade | Agente | Status |
|---|---|---|---|---|---|---|---|
| T-01 | database | Migration 0020: cancelamento agendado, aceite legal e onboarding | — | sim | simples | Darwin | [x] |
| T-02 | backend | Mensagens pt-BR da Etapa 5 e mapeamento em `UserMessage` | — | sim | simples | Aang | [x] |
| T-03 | backend | Configuração do piloto (`PilotSettings`), recorte da oferta (`OfferScope`), ambiente e preflight | — | sim | média | Jaspion | [x] |
| T-04 | backend | Perfis restritos fixos (`ClinicRoleProfiles`) e seed `role-groups.sql` | — | sim | média | Athena | [x] |
| T-05 | infra | `cron-run.sh` ignora flag de manutenção órfão (pendência da Etapa 4) | — | sim | simples | Naruto | [x] |
| T-06 | backend | Termos de uso e política de privacidade (rascunho) em página pública | — | sim | média | Hegel | [x] |
| T-07 | backend | Guarda de acesso bloqueia módulo fora do recorte do piloto | T-02, T-03 | sim | média | Spock | [x] |
| T-08 | backend | Cancelamento da assinatura no fim do período (Stripe e Pix) | T-01, T-02 | sim | alta | Arquimedes | [x] |
| T-09 | backend | Checklist de onboarding e dados da clínica (serviços) | T-01, T-02 | sim | média | Batman | [x] |
| T-10 | backend | Registro versionado do aceite de termos e privacidade | T-01, T-06 | sim | média | Yoda | [x] |
| T-11 | backend | Exportação dos dados da clínica (LGPD) em ZIP | — | sim | alta | Thanos | [x] |
| T-12 | backend | Equipe da clínica com perfis restritos (`ClinicTeamService`) | T-04 | sim | média | Kratos | [x] |
| T-13 | frontend | Landing e checkout alinhados à oferta e aos documentos legais | T-03, T-06 | sim | média | Tesla | [x] |
| T-14 | frontend | Tela da assinatura com cancelamento pelo app | T-02, T-03, T-08 | sim | média | Maquiavel | [ ] |
| T-15 | frontend | Telas de primeiros passos, dados da clínica e orientações do primeiro atendimento | T-02, T-09 | sim | média | Goku | [ ] |
| T-16 | frontend | Aceite de termos e privacidade no primeiro acesso (`LoginForm`) | T-02, T-06, T-10 | sim | média | Sherlock | [ ] |
| T-17 | frontend | Telas de suporte e de exportação dos dados | T-02, T-03, T-06, T-11 | sim | média | Platão | [ ] |
| T-18 | qa | Jornada do piloto por perfil com 2 tenants (teste de integração) | T-04, T-12 | sim | alta | Saitama | [ ] |
| T-19 | backend | Registro das telas novas: programas, grupos, menu, guarda e CSRF | T-04, T-07, T-14, T-15, T-17 | não | média | Levi | [ ] |
| T-20 | docs | Roteiro de homologação, checklist de go-live e runbook do piloto | T-05, T-08, T-11, T-13, T-17, T-18, T-19 | não | média | Gandalf | [ ] |

## Detalhamento

### T-01 — Migration 0020: cancelamento agendado, aceite legal e onboarding

**Camada:** database
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Darwin

Segue o formato da 0019 (`20261026_0019_ops_monitoring.sql`): cabeçalho com efeito, risco e verificação; `INSERT INTO schema_migrations` com o placeholder de 64 zeros; `.verify.sql` só com `SELECT`. Sem `CHECK` (o preparador MySQL 5.7 só converte collation). Aplica no `centralvet_test` (padrão autorizado); nunca no `centralvet`. Acrescenta a seção da 0020 em `docs/runbooks/shared-hosting-mysql57.md` no padrão da seção "Migration 0019".

**Arquivos prováveis**
- `src/app/database/migrations/20261026_0020_etapa5_piloto.sql`
- `src/app/database/migrations/20261026_0020_etapa5_piloto.verify.sql`
- `scripts/test-db/provision.sh`
- `docs/runbooks/shared-hosting-mysql57.md`
- `src/tests/Integration/Etapa5SchemaIntegrationTest.php`

**Interface**
- Produz: colunas de `subscription` `cancel_at_period_end tinyint(1) NOT NULL DEFAULT 0`, `cancel_requested_at timestamp(6) NULL`, `cancel_requested_by int NULL` (depois de `cancelled_at`)
- Produz: tabela `legal_acceptance` (`id bigint unsigned AUTO_INCREMENT` PK, `tenant_id bigint unsigned NOT NULL` FK `tenant(id)`, `system_user_id int NOT NULL`, `document varchar(20) NOT NULL`, `version varchar(40) NOT NULL`, `accepted_at timestamp(6) NOT NULL`, `ip varchar(45) NULL`, `user_agent varchar(255) NULL`, `UNIQUE KEY legal_acceptance_user_doc_version_uq (system_user_id, document, version)`, `KEY legal_acceptance_tenant_idx (tenant_id)`), InnoDB utf8mb4 `utf8mb4_0900_ai_ci`
- Produz: tabela `tenant_onboarding` (`tenant_id bigint unsigned NOT NULL` PK e FK `tenant(id)`, `clinic_confirmed_at timestamp(6) NULL`, `team_reviewed_at timestamp(6) NULL`, `guide_read_at timestamp(6) NULL`, `created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)`, `updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)`)
- Produz: versão `20261026_0020_etapa5_piloto` em `schema_migrations`; linha `$migrations_dir/20261026_0020_etapa5_piloto.sql` ao fim de `schema_files` em `scripts/test-db/provision.sh`
- Consome: nada

**Teste RED**
- `src/tests/Integration/Etapa5SchemaIntegrationTest.php` — no `centralvet_test`, `information_schema.COLUMNS` tem `subscription.cancel_at_period_end`, `subscription.cancel_requested_at`, `subscription.cancel_requested_by`, as 8 colunas de `legal_acceptance` e as 6 de `tenant_onboarding`, e o índice único `legal_acceptance_user_doc_version_uq` existe em `information_schema.STATISTICS`; falha com a 0020 ainda não aplicada (comando: `SUITE com <Classe> = Etapa5SchemaIntegrationTest`)

**Critério de aceite**
- MYSQLTEST `< src/app/database/migrations/20261026_0020_etapa5_piloto.sql` termina com código 0, e o `.verify.sql` pelo mesmo cliente mostra 1 linha em `schema_migrations` com `20261026_0020_etapa5_piloto` e as colunas novas.
- SUITE com `Etapa5SchemaIntegrationTest` mostra `PASS` em todos os métodos, sem `SKIP`, e `Failed: 0`.
- `grep -c '20261026_0020_etapa5_piloto.sql' scripts/test-db/provision.sh` imprime `1` e `bash scripts/test-db/provision-check.test.sh` imprime `Failed: 0`.
- Com a 0020 copiada num `mktemp -d`, `python3 scripts/prepare-mysql57.py <tmp>/in <tmp>/out` termina com código 0 e `grep -rc 'utf8mb4_0900_ai_ci' <tmp>/out` soma 0.

**Validação**
- `printf 'SELECT COUNT(*) FROM tenant; SELECT COUNT(*) FROM subscription; SELECT COUNT(*) FROM system_users; SELECT COUNT(*) FROM schema_migrations;\n' |` MYSQLTEST antes e depois de MYSQLTEST `< src/app/database/migrations/20261026_0020_etapa5_piloto.sql`, seguido de MYSQLTEST `< src/app/database/migrations/20261026_0020_etapa5_piloto.verify.sql` (evidência: contagens de `tenant`, `subscription` e `system_users` iguais — nenhum registro existente perdido —, `schema_migrations` +1, as colunas listadas)
- SUITE com `<Classe>` = `Etapa5SchemaIntegrationTest` (evidência: `PASS` em cada método e `Failed: 0`)
- `bash scripts/test-db/provision-check.test.sh` (evidência: `Failed: 0`) e o `prepare-mysql57.py` do critério (evidência: código 0)
- BASHN (evidência: nenhuma linha em relação a `baseline/bash-n.txt`)

### T-02 — Mensagens pt-BR da Etapa 5 e mapeamento em `UserMessage`

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Aang

Contrato de textos da etapa, no padrão de `src/tests/Unit/Etapa3MessagesTest.php`: cada chave em inglês com o pt-BR exato em `translations.json` (entrada nova em ordem alfabética do `en`, como o arquivo já está) e as mensagens de domínio mapeadas em `UserMessage` para chegar traduzidas à tela. Chave que já existe com outro pt mantém o pt atual e entra numa constante `REUSED` do teste, como na Etapa 3 (reusadas conhecidas: `Team` → `Equipe`, `Pending` → `Pendente`, `Done` → `Feito`, `Terms of use` → `Termos de uso`, `Help` → `Ajuda`).

**Arquivos prováveis**
- `src/app/config/translations.json`
- `src/app/Core/Presentation/UserMessage.php`
- `src/tests/Unit/Etapa5MessagesTest.php`

**Interface**
- Produz: chaves de guarda/oferta: `This module is not available in the pilot yet` → `Este módulo ainda não está disponível no piloto`
- Produz: chaves de cancelamento: `Cancel subscription` → `Cancelar assinatura`; `Confirm cancellation` → `Confirmar cancelamento`; `Cancellation scheduled for ^1` → `Cancelamento agendado para ^1`; `Your subscription remains active until ^1. After that, the clinic data stays available for export for ^2 days and is then deleted` → `Sua assinatura continua ativa até o fim do período pago, em ^1. Depois disso, os dados da clínica ficam disponíveis para exportação por ^2 dias e então são excluídos`; `Subscription cancellation requested` → `Cancelamento da assinatura solicitado`; `The subscription is already scheduled for cancellation` → `A assinatura já está com cancelamento agendado`; `No active subscription to cancel` → `Não há assinatura ativa para cancelar`; `The payment provider did not confirm the cancellation. Try again in a few minutes` → `O provedor de pagamento não confirmou o cancelamento. Tente de novo em alguns minutos`
- Produz: chaves de onboarding: `First steps` → `Primeiros passos`; `Clinic data` → `Dados da clínica`; `Services and prices` → `Serviços e preços`; `First encounter guide` → `Orientações do primeiro atendimento`; `Mark as read` → `Marcar como lida`; `I work alone` → `Trabalho sozinho`; `Legal name` → `Razão social`; `Trade name` → `Nome fantasia`; `Unit name` → `Nome da unidade`; `Initial setup completed` → `Configuração inicial concluída`; `Clinic data saved` → `Dados da clínica salvos`; `Enter the clinic legal name` → `Informe a razão social da clínica`; `Enter the unit name` → `Informe o nome da unidade`
- Produz: chaves legais: `Privacy policy` → `Política de privacidade`; `I have read and accept the Terms of Use and the Privacy Policy` → `Li e aceito os Termos de Uso e a Política de Privacidade`; `Accept the Terms of Use and the Privacy Policy to continue` → `Aceite os Termos de Uso e a Política de Privacidade para continuar`
- Produz: chaves de suporte e exportação: `Support` → `Suporte`; `Support e-mail` → `E-mail do suporte`; `Service hours` → `Horário de atendimento`; `Support channel not configured` → `Canal de suporte não configurado`; `Export clinic data` → `Exportar dados da clínica`; `The file contains the clinic records in CSV format, one file per table` → `O arquivo traz os registros da clínica em CSV, um arquivo por tabela`; `Cancellation policy` → `Política de cancelamento`
- Produz: `UserMessage::resolve()` reconhece as mensagens de domínio estáticas `No active subscription to cancel`, `The payment provider did not confirm the cancellation. Try again in a few minutes`, `The subscription is already scheduled for cancellation`, `Enter the clinic legal name`, `Enter the unit name`, `This module is not available in the pilot yet` (constante `STATIC_DOMAIN` do teste, como na Etapa 3)
- Consome: nada

**Teste RED**
- `src/tests/Unit/Etapa5MessagesTest.php` — cada chave da Interface existe uma única vez em `translations.json` com o pt exato (ou com o pt atual, se listada em `REUSED`), o JSON segue válido e `UserMessage::resolve()` devolve não nulo para cada mensagem de `STATIC_DOMAIN`; falha antes das chaves (comando: `SUITE com <Classe> = Etapa5MessagesTest`)

**Critério de aceite**
- SUITE com `Etapa5MessagesTest|Etapa3MessagesTest|Etapa2MessagesTest|SecurityMvpMessagesTest|Etapa3TeamMessagesTest` mostra `PASS` em todos os métodos e `Failed: 0`.
- `python3 -m json.tool src/app/config/translations.json > /dev/null` termina com código 0.

**Validação**
- SUITE com `<Classe>` = `Etapa5MessagesTest|Etapa3MessagesTest|Etapa2MessagesTest|SecurityMvpMessagesTest|Etapa3TeamMessagesTest` (evidência: `PASS` em cada método e `Failed: 0`)
- `python3 -m json.tool src/app/config/translations.json > /dev/null; echo $?` (evidência: `0`); `python3 -c "import json;print(len(json.load(open('src/app/config/translations.json'))))"` antes e depois (evidência: o total cresce exatamente pelo número de chaves novas — nenhuma entrada existente perdida)
- LINT de `app/Core/Presentation/UserMessage.php` e do teste (evidência: `No syntax errors detected`)

### T-03 — Configuração do piloto (`PilotSettings`), recorte da oferta (`OfferScope`), ambiente e preflight

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Jaspion

Fonte única da configuração do piloto lida do ambiente e do recorte homologado da oferta. Feature flag por ambiente, sem remover código: os recursos fora do recorte (comunicação/automações, internação/cirurgia, IA) continuam no código e nos direitos do plano (`PlanEntitlements` e `plan-groups.sql` não mudam), mas ficam desligados até o ambiente listá-los em `OFFER_PREVIEW_FEATURES`. O preflight de produção passa a exigir o canal de suporte.

**Arquivos prováveis**
- `src/app/Core/Pilot/PilotSettings.php`
- `src/app/Core/Billing/OfferScope.php`
- `.env.example`
- `docker-compose.yml`
- `docs/runbooks/environments.md`
- `src/app/Core/Operations/ProductionPreflight.php`
- `src/tests/Unit/PilotSettingsTest.php`
- `src/tests/Unit/OfferScopeTest.php`
- `src/tests/Unit/ProductionPreflightTest.php`

**Interface**
- Produz: `final class CentralVet\Pilot\PilotSettings` com `public const DEFAULT_RETENTION_DAYS = 30;`, `public const DEFAULT_SUPPORT_HOURS = 'Segunda a sexta, das 8h às 18h';`, `public static function fromArray(array $env): self`, `public static function fromEnvironment(): self` (lê `getenv`, padrão de `StripeConfig::fromEnvironment`), `public function offeredPlans(): array` (list de ids de `PlanEntitlements::PLAN_IDS` na ordem desse array; lê `OFFER_PLANS` separado por vírgula, ignora id desconhecido e espaços; vazio ou só ids desconhecidos → os 4), `public function previewFeatures(): array` (`OFFER_PREVIEW_FEATURES`, list de constantes `FEATURE_*` de `PlanEntitlements`; desconhecidas ignoradas), `public function supportEmail(): ?string` (`SUPPORT_EMAIL` válido por `FILTER_VALIDATE_EMAIL`, senão null), `public function supportWhatsapp(): ?string` (`SUPPORT_WHATSAPP` só com dígitos, 10 a 15, senão null), `public function supportHours(): string` (`SUPPORT_HOURS` ou `DEFAULT_SUPPORT_HOURS`), `public function retentionDaysAfterCancel(): int` (`DATA_RETENTION_DAYS_AFTER_CANCEL` inteiro de 1 a 3650, senão `DEFAULT_RETENTION_DAYS`)
- Produz: `final class CentralVet\Billing\OfferScope` com `public const RELEASED_FEATURES = [PlanEntitlements::FEATURE_INVENTORY_POS, PlanEntitlements::FEATURE_FINANCE_FULL, PlanEntitlements::FEATURE_MULTI_UNIT];`, `public function __construct(private readonly PilotSettings $settings)`, `public static function fromEnvironment(): self`, `public function isFeatureReleased(string $feature): bool` (em `RELEASED_FEATURES` ou em `previewFeatures()`), `public function isProgramReleased(string $controller): bool` (controller fora de `PlanEntitlements::gatedPrograms()` → true; senão `isFeatureReleased` da feature dele), `public function isPlanOffered(string $planId): bool`, `public function offeredPlans(): array`
- Produz: em `.env.example` e no bloco `environment: &app-environment` do `docker-compose.yml` (`${VAR:-padrão}`): `OFFER_PLANS=starter,pro,business,enterprise`, `OFFER_PREVIEW_FEATURES=`, `SUPPORT_EMAIL=`, `SUPPORT_WHATSAPP=`, `SUPPORT_HOURS=`, `DATA_RETENTION_DAYS_AFTER_CANCEL=30`
- Produz: achado `support_email` no `ProductionPreflight` — `FAIL` em produção com `SUPPORT_EMAIL` vazio, inválido ou terminado em `.invalid`; `PASS` com e-mail válido
- Consome: nada

**Teste RED**
- `src/tests/Unit/PilotSettingsTest.php`, `src/tests/Unit/OfferScopeTest.php`, `src/tests/Unit/ProductionPreflightTest.php` — `PilotSettings::fromArray([])` dá os 4 planos, `[]` em preview, e-mail e WhatsApp null, `DEFAULT_SUPPORT_HOURS` e 30 dias; `OFFER_PLANS=' pro , gold'` dá `['pro']`; `OFFER_PLANS='gold'` dá os 4; `SUPPORT_WHATSAPP='+55 (85) 99999-0000'` dá null e `'5585999990000'` dá o mesmo valor; `DATA_RETENTION_DAYS_AFTER_CANCEL='0'` dá 30; `OfferScope` padrão: `isProgramReleased('SaleList')`, `('TutorList')`, `('SystemUnitForm')` true e `('HospitalizationBoard')`, `('SurgeryList')`, `('AiCopilotPanel')`, `('CommunicationComposeForm')` false; com `OFFER_PREVIEW_FEATURES='hospital'` `HospitalizationBoard` vira true e `AiCopilotPanel` segue false; com `OFFER_PLANS='pro'` `isPlanOffered('starter')` false; no preflight, o env de produção completo do teste sem `SUPPORT_EMAIL` dá `FAIL` `support_email` e com `suporte@example.com` dá `PASS` (comando: `SUITE com <Classe> = PilotSettingsTest|OfferScopeTest|ProductionPreflightTest`)

**Critério de aceite**
- SUITE com `PilotSettingsTest|OfferScopeTest|ProductionPreflightTest|PlanEntitlementsTest|PlanGroupsSeedTest|ProxyTrustConfigTest` mostra `PASS` em todos os métodos e `Failed: 0`.
- `grep -cE '^(OFFER_PLANS|OFFER_PREVIEW_FEATURES|SUPPORT_EMAIL|SUPPORT_WHATSAPP|SUPPORT_HOURS|DATA_RETENTION_DAYS_AFTER_CANCEL)=' .env.example` imprime `6`; `docker compose config` lista as 6 variáveis no serviço `app`.

**Validação**
- SUITE com `<Classe>` = `PilotSettingsTest|OfferScopeTest|ProductionPreflightTest|PlanEntitlementsTest|PlanGroupsSeedTest|ProxyTrustConfigTest` (evidência: `PASS` em cada método e `Failed: 0`; `PlanEntitlementsTest` e `PlanGroupsSeedTest` inalterados provam que os direitos por plano não mudaram)
- o `grep -cE` do critério (evidência: `6`) e `docker compose config | grep -cE 'OFFER_PLANS|OFFER_PREVIEW_FEATURES|SUPPORT_EMAIL|SUPPORT_WHATSAPP|SUPPORT_HOURS|DATA_RETENTION_DAYS_AFTER_CANCEL'` (evidência: ≥ 6)
- LINT de `app/Core/Pilot/PilotSettings.php`, `app/Core/Billing/OfferScope.php`, `app/Core/Operations/ProductionPreflight.php` e dos 3 testes (evidência: `No syntax errors detected`)

### T-04 — Perfis restritos fixos (`ClinicRoleProfiles`) e seed `role-groups.sql`

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Athena

Hoje todo usuário da clínica entra no grupo do plano (acesso total). O piloto precisa de três perfis restritos fixos, além do administrador (grupo do plano): Recepção, Veterinário e Financeiro, como grupos globais `system_group` no padrão de `plan-groups.sql` (seed preparado, idempotente, aplicado só com aprovação). O plano continua limitando pela guarda (`SubscriptionAccessGuard`): o perfil só restringe. Programas das telas novas desta etapa (`SupportView`, `FirstEncounterGuideView`) entram na lista agora; o seed só liga programa que existe em `system_program`, e T-19 os registra.

**Arquivos prováveis**
- `src/app/Core/Tenancy/ClinicRoleProfiles.php`
- `src/app/database/seeds/role-groups.sql`
- `src/tests/Unit/ClinicRoleProfilesTest.php`
- `src/tests/Unit/RoleGroupsSeedTest.php`

**Interface**
- Produz: `final class CentralVet\Tenancy\ClinicRoleProfiles` com `public const RECEPTION = 'reception';`, `public const VETERINARIAN = 'veterinarian';`, `public const FINANCE = 'finance';`, `public const GROUP_NAMES = [self::RECEPTION => 'Central Vet - Perfil Recepção', self::VETERINARIAN => 'Central Vet - Perfil Veterinário', self::FINANCE => 'Central Vet - Perfil Financeiro'];`, `public static function roles(): array` (`['reception', 'veterinarian', 'finance']`), `public static function groupNames(): array` (os 3 nomes, nessa ordem), `public static function programs(string $role): array` (list ordenada; `InvalidArgumentException` para papel desconhecido), `public static function roleOfGroup(string $groupName): ?string`
- Produz: programas comuns aos 3 perfis: `CvShellController`, `GlobalSearchController`, `PendingCenter`, `SupportView`, `FirstEncounterGuideView`, `SystemProfileView`, `SystemProfileForm`, `SystemProfile2FAForm`
- Produz: Recepção = comuns + `AgendaView`, `AppointmentForm`, `TutorList`, `TutorForm`, `PatientList`, `PatientForm`, `QueueEntryView`, `VaccinationCardView`, `DocumentList`
- Produz: Veterinário = comuns + `AgendaView`, `QueueEntryView`, `EncounterView`, `TutorList`, `PatientList`, `PatientForm`, `PrescriptionForm`, `ExamRequestForm`, `ExamResultForm`, `PendingExamResultList`, `VaccinationForm`, `VaccinationCardView`, `ProcedureExecutionForm`, `ProcedureInputForm`, `DocumentList`, `DocumentRequestForm`
- Produz: Financeiro = comuns + `EncounterAccountForm`, `PaymentForm`, `CashSessionForm`, `CashSessionList`, `PendingReceivableList`, `FinancialOverview`, `FinancialEntryForm`, `FinancialEntryList`, `PayableForm`, `PayableList`, `BankAccountForm`, `BankAccountList`, `SaleList`, `TutorList`, `ServiceList`
- Produz: `src/app/database/seeds/role-groups.sql` — cabeçalho `-- Prepared only; requires separate approval.`, `START TRANSACTION`/`COMMIT`, cria os 3 grupos por nome com `INSERT … SELECT MAX+1 … WHERE NOT EXISTS` e liga exatamente os programas de cada perfil (padrão de `plan-groups.sql`); nunca o grupo 1
- Consome: nada

**Teste RED**
- `src/tests/Unit/ClinicRoleProfilesTest.php`, `src/tests/Unit/RoleGroupsSeedTest.php` — os 3 papéis e nomes exatos da Interface; `programs('reception')` contém `AppointmentForm` e não contém `PrescriptionForm`, `PaymentForm`, `FinancialOverview`; `programs('veterinarian')` contém `PrescriptionForm` e não contém `PaymentForm`, `CashSessionForm`; `programs('finance')` contém `PaymentForm` e não contém `PrescriptionForm`, `AppointmentForm`; nenhum perfil contém `ClinicUserForm`, `ClinicUserList`, `ClinicUnitForm`, `ClinicUnitList`, `SubscriptionStatusView`, `SubscriptionCancelForm`, `ClinicProfileForm`, `ClinicDataExportView`, `OnboardingChecklistView`, `LandingLeadList` nem programa `System*` além dos 3 `SystemProfile*`; `programs('admin')` lança `InvalidArgumentException`; o seed tem o cabeçalho, a transação, os 3 `WHERE NOT EXISTS` por nome e, para cada grupo, exatamente os `p.controller='<X>'` de `programs()`, sem `system_group_id=1` nem `, 1, p.id` (comando: `SUITE com <Classe> = ClinicRoleProfilesTest|RoleGroupsSeedTest`)

**Critério de aceite**
- SUITE com `ClinicRoleProfilesTest|RoleGroupsSeedTest|PlanGroupsSeedTest` mostra `PASS` em todos os métodos e `Failed: 0`.
- Aplicado duas vezes por MYSQLTEST no `centralvet_test` (`< src/app/database/seeds/role-groups.sql`), o seed termina com código 0 nas duas e `SELECT g.name, COUNT(*) FROM system_group g JOIN system_group_program gp ON gp.system_group_id = g.id WHERE g.name LIKE 'Central Vet - Perfil %' GROUP BY g.name` dá o mesmo resultado nas duas (idempotente).

**Validação**
- SUITE com `<Classe>` = `ClinicRoleProfilesTest|RoleGroupsSeedTest|PlanGroupsSeedTest` (evidência: `PASS` em cada método e `Failed: 0`)
- `printf 'SELECT COUNT(*) FROM system_group; SELECT COUNT(*) FROM system_group_program; SELECT COUNT(*) FROM system_users;\n' |` MYSQLTEST antes e depois de aplicar o seed duas vezes no `centralvet_test`, mais a consulta do critério (evidência: `system_group` +3 só na primeira, contagens iguais entre a 1ª e a 2ª aplicação, `system_users` igual)
- LINT de `app/Core/Tenancy/ClinicRoleProfiles.php` e dos 2 testes (evidência: `No syntax errors detected`)

### T-05 — `cron-run.sh` ignora flag de manutenção órfão (pendência da Etapa 4)

**Camada:** infra
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Naruto

Pendência aberta da Etapa 4 (T-18, `reviews/final.md` rodada 2): um restore morto por SIGKILL ou queda do host deixa `var/maintenance/<projeto>.restore` e o `cron-run.sh` passa a pular todos os jobs — inclusive backup e `ops-monitor` — com código 0 e sem alerta. Com dados reais no piloto, isso desliga backup e alertas em silêncio. O flag gravado pelo `restore.sh` tem `pid=<n>` (scripts/restore.sh:275).

**Arquivos prováveis**
- `scripts/cron-run.sh`
- `scripts/cron-run.test.sh`
- `docs/runbooks/restore-backup.md`

**Interface**
- Produz: com o flag presente e uma linha `pid=<n>` cujo processo não existe (`kill -0 <n>` falha), `cron-run.sh` imprime em stderr `cron-run stale-maintenance-flag project=<projeto> pid=<n>` e executa o job (`exec "$@"`), sem apagar o flag; com o processo vivo, ou com flag sem linha `pid=`, mantém `cron-run skipped reason=maintenance project=<projeto>` e código 0
- Produz: em `docs/runbooks/restore-backup.md` § 4, um parágrafo sobre o flag órfão (o que o cron faz, como remover à mão depois de conferir o pid) e a variável `CENTRALVET_MAINTENANCE_DIR`
- Consome: nada

**Teste RED**
- `scripts/cron-run.test.sh` — casos novos: flag com `pid=` de um processo encerrado (pid de um `sh -c 'exit 0'` já terminado) executa o job falso e imprime `stale-maintenance-flag`; flag com `pid=$$` (vivo) pula com `reason=maintenance`; flag sem linha `pid=` pula; o flag continua existindo depois do caso órfão (comando: `bash scripts/cron-run.test.sh`)

**Critério de aceite**
- `bash scripts/cron-run.test.sh` imprime `PASS` nos casos novos e nos antigos e termina com `Failed: 0`.

**Validação**
- `bash scripts/cron-run.test.sh` (evidência: `Failed: 0`, linhas `PASS` dos 3 casos novos)
- `bash scripts/restore.test.sh` (evidência: `Failed: 0` — o formato do flag do restore não mudou)
- BASHN (evidência: nenhuma linha em relação a `baseline/bash-n.txt`)

### T-06 — Termos de uso e política de privacidade (rascunho) em página pública

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Hegel

Hoje só existe o consentimento de contato e o texto curto dos "Termos de Assinatura"; não há página de termos nem de privacidade. Cria os dois documentos como rascunho marcado para revisão jurídica, cobrindo o uso efetivo do serviço (contas e perfis, dados de tutores e pacientes inseridos pela clínica, cobrança e cancelamento, exportação, suporte, piloto e módulos "em breve"), e uma página pública sem sessão (`src/legal.php`, servida pela location genérica `\.php$` do nginx; a própria página envia a CSP).

**Arquivos prováveis**
- `src/app/Core/Legal/LegalDocuments.php`
- `src/app/Core/Legal/LegalPage.php`
- `src/legal.php`
- `src/app/view/legal/termos-de-uso.html`
- `src/app/view/legal/politica-de-privacidade.html`
- `src/tests/Unit/LegalPageTest.php`

**Interface**
- Produz: `final class CentralVet\Legal\LegalDocuments` com `public const TERMS = 'terms';`, `public const PRIVACY = 'privacy';`, `public const TERMS_VERSION = 'termos-uso-2026-10';`, `public const PRIVACY_VERSION = 'privacidade-2026-10';`, `public const TERMS_URL = 'legal.php?doc=termos';`, `public const PRIVACY_URL = 'legal.php?doc=privacidade';`, `public const DRAFT_MARKER = 'RASCUNHO — revisar com jurídico';`, `public static function currentVersions(): array` (`['terms' => TERMS_VERSION, 'privacy' => PRIVACY_VERSION]`)
- Produz: `final class CentralVet\Legal\LegalPage` com `public static function render(string $doc): array` → `['status' => 200|404, 'body' => string]`; `termos` → `src/app/view/legal/termos-de-uso.html`, `privacidade` → `src/app/view/legal/politica-de-privacidade.html`, outro valor → 404 com corpo `Documento não encontrado`; `public const CSP = "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'";`
- Produz: `src/legal.php` — sem sessão nem `init.php` de app; envia `http_response_code`, `Content-Type: text/html; charset=UTF-8`, `Content-Security-Policy: <LegalPage::CSP>`, `X-Content-Type-Options: nosniff`, `Cache-Control: public, max-age=300` e o corpo
- Produz: os dois HTML em pt-BR, com `DRAFT_MARKER` visível no topo e a versão no rodapé; termos com as seções `Objeto`, `Contas e perfis de acesso`, `Planos, cobrança e cancelamento`, `Dados pessoais e LGPD`, `Exportação dos dados`, `Suporte`, `Piloto e módulos em breve`, `Responsabilidades`, `Foro`; privacidade com `Dados que tratamos`, `Finalidades e bases legais`, `Compartilhamento` (cita Stripe, provedor de e-mail e hospedagem), `Retenção`, `Direitos do titular`, `Exportação e exclusão`, `Cookies`, `Contato do encarregado`; cancelamento descrito como "pelo app, com efeito no fim do período pago" e retenção de 30 dias para exportação depois do cancelamento
- Consome: nada

**Teste RED**
- `src/tests/Unit/LegalPageTest.php` — `LegalPage::render('termos')` dá status 200 e corpo com `DRAFT_MARKER`, `LegalDocuments::TERMS_VERSION`, `Termos de Uso` e as 9 seções; `render('privacidade')` dá 200 com `PRIVACY_VERSION`, `Política de Privacidade`, `Stripe` e as 8 seções; `render('../config/application')` e `render('')` dão 404 com `Documento não encontrado`; o fonte de `src/legal.php` contém `LegalPage::CSP` e `X-Content-Type-Options` e não contém `session_start` (comando: `SUITE com <Classe> = LegalPageTest`)

**Critério de aceite**
- SUITE com `LegalPageTest` mostra `PASS` em todos os métodos e `Failed: 0`.
- Com a pilha no ar, `curl -s -o /dev/null -w '%{http_code}' 'http://localhost:8081/legal.php?doc=termos'` imprime `200`, `…doc=xyz` imprime `404`, e `curl -sI 'http://localhost:8081/legal.php?doc=privacidade' | grep -i content-security-policy` mostra `default-src 'none'`.

**Validação**
- SUITE com `<Classe>` = `LegalPageTest` (evidência: `PASS` em cada método e `Failed: 0`)
- os 3 `curl` do critério contra `http://localhost:8081` (evidência: `200`, `404`, linha da CSP)
- LINT de `app/Core/Legal/LegalDocuments.php`, `app/Core/Legal/LegalPage.php`, `legal.php` e do teste (evidência: `No syntax errors detected`)

### T-07 — Guarda de acesso bloqueia módulo fora do recorte do piloto

**Camada:** backend
**Dependências:** T-02, T-03
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Spock

A guarda (`SubscriptionAccessGuard::decide`) ganha o motivo `not_released`: controller liberado pelo plano mas cuja feature não está no recorte (`OfferScope`) é bloqueado com mensagem própria. Ordem: `ALWAYS_ALLOWED` → `blocked_status` → `blocked_plan` (plano não inclui) → `not_released` → `full`/`grace`. Tenant sem assinatura (`legacy`) segue inalterado. Não muda `PlanEntitlements` nem os seeds.

**Arquivos prováveis**
- `src/app/Core/Billing/AccessDecision.php`
- `src/app/Core/Billing/SubscriptionAccessGuard.php`
- `src/tests/Unit/SubscriptionAccessGuardTest.php`

**Interface**
- Produz: `AccessDecision::REASON_NOT_RELEASED = 'not_released'`, `public static function notReleased(): self` (não permitido) e `messageKey()` = `This module is not available in the pilot yet` para esse motivo
- Produz: `SubscriptionAccessGuard::__construct(SubscriptionRepositoryInterface $subscriptions, ?OfferScope $offer = null)` — `null` usa `OfferScope::fromEnvironment()`
- Consome: T-03 `public function isProgramReleased(string $controller): bool`, T-03 `final class CentralVet\Billing\OfferScope`, T-02 `This module is not available in the pilot yet`

**Teste RED**
- `src/tests/Unit/SubscriptionAccessGuardTest.php` — com `FakeSubscriptionRepository` e `new OfferScope(PilotSettings::fromArray([]))`: assinatura `enterprise` ativa em `HospitalizationBoard` e `AiCopilotPanel` → `reason()` = `not_released` e `messageKey()` = `This module is not available in the pilot yet`; `pro` ativa em `CommunicationComposeForm` → `not_released`; `pro` em `SaleList` → `full`; `starter` em `HospitalizationBoard` → `blocked_plan`; `enterprise` suspensa em `HospitalizationBoard` → `blocked_status`; tenant sem assinatura em `HospitalizationBoard` → `legacy`; com `OFFER_PREVIEW_FEATURES='hospital,ai_advanced'` o `enterprise` em `HospitalizationBoard` → `full`; `SubscriptionStatusView` segue permitido (comando: `SUITE com <Classe> = SubscriptionAccessGuardTest`)

**Critério de aceite**
- SUITE com `SubscriptionAccessGuardTest|SubscriptionAccessWiringTest|PlanEntitlementsTest` mostra `PASS` em todos os métodos e `Failed: 0`.

**Validação**
- SUITE com `<Classe>` = `SubscriptionAccessGuardTest|SubscriptionAccessWiringTest|PlanEntitlementsTest` (evidência: `PASS` em cada método — inclusive o caso `legacy` do Review Focus — e `Failed: 0`)
- LINT de `app/Core/Billing/AccessDecision.php`, `app/Core/Billing/SubscriptionAccessGuard.php` e do teste (evidência: `No syntax errors detected`)

### T-08 — Cancelamento da assinatura no fim do período (Stripe e Pix)

**Camada:** backend
**Dependências:** T-01, T-02
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Arquimedes

Cancelamento pelo cliente com efeito no fim do período pago. Cartão: `POST /v1/subscriptions/{id}` com `cancel_at_period_end=true` no Stripe antes de gravar; o `customer.subscription.deleted` do fim do período já leva a `cancelled` (`BillingEventProcessor`). Pix (sem Subscription no Stripe): marca local, o reconciliador deixa de emitir fatura e, vencido o período, finaliza como `cancelled`. Falha do Stripe não grava nada. Assinatura `cancelled` cai em `blocked` pela regra atual de `accessLevel`.

**Arquivos prováveis**
- `src/app/Core/Billing/Subscription.php`
- `src/app/Core/Billing/Contract/SubscriptionRepositoryInterface.php`
- `src/app/Core/Persistence/SubscriptionRepository.php`
- `src/tests/Support/FakeSubscriptionRepository.php`
- `src/app/Core/Billing/SubscriptionCancellationService.php`
- `src/app/Core/Billing/SubscriptionCancellationException.php`
- `src/app/Core/Billing/BillingReconciler.php`
- `src/tests/Unit/SubscriptionCancellationServiceTest.php`
- `src/tests/Integration/SubscriptionRepositoryIntegrationTest.php`
- `src/tests/Integration/BillingReconcilerIntegrationTest.php`

**Interface**
- Produz: `Subscription::requestCancellation(int $userId, DateTimeImmutable $now): bool` (false se já agendado ou `cancelled`), `Subscription::cancelAtPeriodEnd(): bool`, `Subscription::cancelRequestedAt(): ?DateTimeImmutable`, `Subscription::cancelRequestedBy(): ?int`, `Subscription::finalizeScheduledCancellation(DateTimeImmutable $now): bool` (só Pix, agendado, `current_period_end <= now` e não `cancelled` → `cancelled` com `cancelled_at = now`); `toRow()`/`fromRow()` com `cancel_at_period_end`, `cancel_requested_at`, `cancel_requested_by`
- Produz: `SubscriptionRepositoryInterface::listScheduledCancellationsDue(DateTimeImmutable $now, int $limit): array` (list de ids Pix agendados com período vencido e status diferente de `cancelled`); `listPixDueForIssue` passa a excluir `cancel_at_period_end = 1`
- Produz: `final class CentralVet\Billing\SubscriptionCancellationService` com `public const RESULT_SCHEDULED = 'scheduled';`, `public const RESULT_ALREADY_SCHEDULED = 'already_scheduled';`, `public function __construct(SubscriptionRepositoryInterface $subscriptions, StripeClientInterface $stripe, TransactionRunner $tx)`, `public function request(int $tenantId, int $userId, DateTimeImmutable $now): string`; sem assinatura ou `cancelled` → `SubscriptionCancellationException('No active subscription to cancel')`; cartão chama `request('POST', '/v1/subscriptions/<stripe_subscription_id>', ['cancel_at_period_end' => 'true'], 'cancel-subscription-<id local>')` e, em `StripeApiException`, lança `SubscriptionCancellationException('The payment provider did not confirm the cancellation. Try again in a few minutes')` sem gravar; grava com `findByIdForUpdate` dentro de `TransactionRunner::run`
- Produz: `final class CentralVet\Billing\SubscriptionCancellationException extends \DomainException`
- Produz: passo do `BillingReconciler::run` que chama `finalizeScheduledCancellation` com a assinatura travada, contador `cancellations_finalized`
- Consome: T-01 `cancel_at_period_end tinyint(1) NOT NULL DEFAULT 0`, T-01 `cancel_requested_at timestamp(6) NULL`, T-01 `cancel_requested_by int NULL`, T-02 `No active subscription to cancel`, T-02 `The payment provider did not confirm the cancellation. Try again in a few minutes`

**Teste RED**
- `src/tests/Unit/SubscriptionCancellationServiceTest.php`, `src/tests/Integration/SubscriptionRepositoryIntegrationTest.php`, `src/tests/Integration/BillingReconcilerIntegrationTest.php` — com `FakeStripeClient` e `FakeSubscriptionRepository`: cartão ativo → 1 chamada `POST /v1/subscriptions/sub_…` com `cancel_at_period_end=true` e chave `cancel-subscription-<id>`, resultado `scheduled`, assinatura com `cancelAtPeriodEnd()` true, `cancelRequestedBy()` = usuário e status `active`; segunda chamada → `already_scheduled` sem nova chamada ao Stripe; Stripe lançando `StripeApiException` → `SubscriptionCancellationException` e assinatura inalterada; Pix → nenhuma chamada ao Stripe e `scheduled`; sem assinatura → exceção `No active subscription to cancel`; no MySQL, `save` e `findByTenantId` preservam as 3 colunas, `listPixDueForIssue` não devolve a Pix agendada e `listScheduledCancellationsDue` devolve só a Pix agendada vencida; o reconciliador com essa assinatura a deixa `cancelled` com `cancelled_at` e conta `cancellations_finalized` = 1, e não emite fatura Pix para ela (comando: `SUITE com <Classe> = SubscriptionCancellationServiceTest|SubscriptionRepositoryIntegrationTest|BillingReconcilerIntegrationTest`)

**Critério de aceite**
- SUITE com `SubscriptionCancellationServiceTest|SubscriptionRepositoryIntegrationTest|BillingReconcilerIntegrationTest|BillingEventProcessorTest|SubscriptionAccessGuardTest|LoginConflictRefundIntegrationTest` mostra `PASS` em todos os métodos, sem `SKIP` nas classes de integração, e `Failed: 0`.

**Validação**
- SUITE com `<Classe>` = `SubscriptionCancellationServiceTest|SubscriptionRepositoryIntegrationTest|BillingReconcilerIntegrationTest|BillingEventProcessorTest|SubscriptionAccessGuardTest|LoginConflictRefundIntegrationTest` (evidência: `PASS` em cada método — inclusive o caso Pix agendado sem fatura nova e finalizado depois do período, do Review Focus — e `Failed: 0`)
- LINT de `app/Core/Billing/Subscription.php`, `SubscriptionCancellationService.php`, `SubscriptionCancellationException.php`, `BillingReconciler.php`, `app/Core/Persistence/SubscriptionRepository.php` e dos testes (evidência: `No syntax errors detected`)

### T-09 — Checklist de onboarding e dados da clínica (serviços)

**Camada:** backend
**Dependências:** T-01, T-02
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Batman

Serviços do primeiro acesso, no padrão de `ClinicTeamService` (PDO + `TenantContext`, tudo filtrado por `tenantId()`). O checklist deriva dos dados (equipe, serviços com preço) e de marcas em `tenant_onboarding` (dados da clínica confirmados, equipe revisada por quem trabalha sozinho, orientações lidas). Os dados da clínica são `tenant.legal_name`/`trade_name` e o nome da unidade da sessão.

**Arquivos prováveis**
- `src/app/Core/Onboarding/OnboardingChecklistService.php`
- `src/app/Core/Onboarding/ClinicProfileService.php`
- `src/tests/Integration/OnboardingChecklistIntegrationTest.php`

**Interface**
- Produz: `final class CentralVet\Onboarding\OnboardingChecklistService` com `public const ITEM_CLINIC = 'clinic_profile';`, `public const ITEM_TEAM = 'team';`, `public const ITEM_SERVICES = 'services';`, `public const ITEM_GUIDE = 'first_encounter_guide';`, `public const ITEMS = [self::ITEM_CLINIC, self::ITEM_TEAM, self::ITEM_SERVICES, self::ITEM_GUIDE];`, `public function __construct(PDO $pdo, TenantContext $context)`, `public function status(): array` (list de `['item' => string, 'done' => bool]` na ordem de `ITEMS`), `public function isComplete(): bool`, `public function markTeamReviewed(DateTimeImmutable $now): void`, `public function markGuideRead(DateTimeImmutable $now): void`
- Produz: regras de `done` — `clinic_profile`: `tenant_onboarding.clinic_confirmed_at` não nulo; `team`: `COUNT(*)` de `tenant_user` do tenant com `status IN ('active','invited')` ≥ 2, ou `team_reviewed_at` não nulo; `services`: ao menos 1 `service` do tenant com `active = 1` e `price_cents > 0`; `first_encounter_guide`: `guide_read_at` não nulo. As marcas fazem upsert da linha do tenant.
- Produz: `final class CentralVet\Onboarding\ClinicProfileService` com `public function __construct(PDO $pdo, TenantContext $context)`, `public function load(): array` (`['legal_name' => string, 'trade_name' => ?string, 'unit_name' => string]`, unidade = `requireUnitId()` do tenant), `public function save(string $legalName, ?string $tradeName, string $unitName, DateTimeImmutable $now): void` (trim; razão social vazia → `\DomainException('Enter the clinic legal name')`; unidade vazia → `\DomainException('Enter the unit name')`; atualiza `tenant` do contexto e `system_unit` com `id = unidade AND tenant_id = tenant` e grava `clinic_confirmed_at`)
- Consome: T-01 `tenant_onboarding`, T-01 `clinic_confirmed_at timestamp(6) NULL`, T-01 `team_reviewed_at timestamp(6) NULL`, T-01 `guide_read_at timestamp(6) NULL`, T-02 `Enter the clinic legal name`, T-02 `Enter the unit name`

**Teste RED**
- `src/tests/Integration/OnboardingChecklistIntegrationTest.php` — tenant novo (1 admin, 1 unidade, sem serviço, sem linha em `tenant_onboarding`): `status()` dá os 4 itens com `done` false e `isComplete()` false; depois de `ClinicProfileService::save('Clínica A Ltda', 'Clínica A', 'Matriz', $now)` o item `clinic_profile` vira true e `load()` devolve os 3 valores; `save('  ', …)` lança `Enter the clinic legal name`; com um `service` ativo de `price_cents` 0 `services` segue false e com 9000 vira true; com 2 linhas em `tenant_user` `team` vira true; `markTeamReviewed` também liga `team` com 1 usuário; `markGuideRead` liga o último e `isComplete()` vira true; um segundo tenant B não enxerga as marcas nem os serviços de A e o `save` no contexto de B não altera `tenant` de A (comando: `SUITE com <Classe> = OnboardingChecklistIntegrationTest`)

**Critério de aceite**
- SUITE com `OnboardingChecklistIntegrationTest|ClinicTeamServiceIntegrationTest` mostra `PASS` em todos os métodos, sem `SKIP`, e `Failed: 0`.

**Validação**
- SUITE com `<Classe>` = `OnboardingChecklistIntegrationTest|ClinicTeamServiceIntegrationTest` (evidência: `PASS` em cada método e `Failed: 0`)
- LINT de `app/Core/Onboarding/OnboardingChecklistService.php`, `app/Core/Onboarding/ClinicProfileService.php` e do teste (evidência: `No syntax errors detected`)

### T-10 — Registro versionado do aceite de termos e privacidade

**Camada:** backend
**Dependências:** T-01, T-06
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Yoda

O consentimento atual cobre só contato comercial e o `require_terms` do Adianti grava um `Y` sem versão e o `$_SERVER` inteiro. O aceite passa a ser por usuário, documento e versão em `legal_acceptance`. Usuário sem vínculo em `tenant_user` (administrador global) não tem pendência.

**Arquivos prováveis**
- `src/app/Core/Legal/LegalAcceptanceService.php`
- `src/tests/Integration/LegalAcceptanceIntegrationTest.php`

**Interface**
- Produz: `final class CentralVet\Legal\LegalAcceptanceService` com `public function __construct(PDO $pdo)`, `public function pendingDocuments(int $userId): array` (list de `LegalDocuments::TERMS`/`PRIVACY`, nessa ordem, cuja versão atual o usuário não aceitou; `[]` sem `tenant_user`), `public function accept(int $userId, string $ip, ?string $userAgent, DateTimeImmutable $now): int` (insere uma linha por documento pendente com o `tenant_id` do menor `tenant_user.tenant_id` do usuário, a versão atual, `ip` cortado em 45 e `user_agent` em 255; idempotente pelo índice único; devolve quantas linhas inseriu; usuário sem tenant → `InvalidArgumentException`)
- Consome: T-01 `legal_acceptance`, T-01 `legal_acceptance_user_doc_version_uq`, T-06 `public static function currentVersions(): array`, T-06 `public const TERMS = 'terms';`, T-06 `public const PRIVACY = 'privacy';`

**Teste RED**
- `src/tests/Integration/LegalAcceptanceIntegrationTest.php` — usuário de um tenant novo: `pendingDocuments` = `['terms', 'privacy']`; `accept(…, '203.0.113.7', 'Teste/1.0', $now)` devolve 2 e grava 2 linhas com `version` = `termos-uso-2026-10` e `privacidade-2026-10`, `tenant_id` do usuário e o IP; `pendingDocuments` vira `[]`; nova chamada de `accept` devolve 0 sem linha nova; linha antiga de versão diferente (`termos-uso-2020-01`) não conta como aceite; usuário sem `tenant_user` → `pendingDocuments` `[]` e `accept` lança `InvalidArgumentException`; o aceite de um usuário do tenant A não aparece para um usuário do tenant B (comando: `SUITE com <Classe> = LegalAcceptanceIntegrationTest`)

**Critério de aceite**
- SUITE com `LegalAcceptanceIntegrationTest` mostra `PASS` em todos os métodos, sem `SKIP`, e `Failed: 0`.

**Validação**
- SUITE com `<Classe>` = `LegalAcceptanceIntegrationTest` (evidência: `PASS` em cada método e `Failed: 0`)
- LINT de `app/Core/Legal/LegalAcceptanceService.php` e do teste (evidência: `No syntax errors detected`)

### T-11 — Exportação dos dados da clínica (LGPD) em ZIP

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Thanos

Acesso e exportação de dados pelo cliente: um ZIP com um CSV por tabela que tem `tenant_id` (descobertas por `information_schema`, sem lista manual que envelhece), só as linhas do tenant, sem colunas sensíveis, mais `users.csv` (equipe) e `manifest.json`. Arquivos binários (fotos, PDFs do storage) ficam fora nesta versão e o manifesto diz isso. Síncrono, gravando em arquivo temporário (piloto com poucas clínicas).

**Arquivos prováveis**
- `src/app/Core/Privacy/TenantDataExporter.php`
- `src/tests/Integration/TenantDataExporterIntegrationTest.php`

**Interface**
- Produz: `final class CentralVet\Privacy\TenantDataExporter` com `public const EXCLUDED_TABLES = ['billing_event', 'feature_flag'];`, `public const SENSITIVE_COLUMN_PATTERN = '/(password|token|secret|hash)/i';`, `public function __construct(PDO $pdo)`, `public function tables(): array` (tabelas de `DATABASE()` com coluna `tenant_id` em `information_schema.COLUMNS`, menos `EXCLUDED_TABLES`, ordenadas), `public function export(int $tenantId, string $zipPath, DateTimeImmutable $now): array` → `['tables' => array<string, int>, 'users' => int]` (linhas por tabela)
- Produz: conteúdo do ZIP — `<tabela>.csv` para cada tabela de `tables()` (BOM UTF-8, separador `;`, cabeçalho com as colunas não sensíveis, `NULL` como vazio, `WHERE tenant_id = ?`); `users.csv` com `id;name;login;email;status` de `tenant_user` × `system_users` do tenant; `manifest.json` com `tenant_id`, `generated_at` (ISO 8601), `tables` (nome → linhas), `users`, `excluded_tables`, `excluded_columns` e `files_included: false`
- Consome: nada

**Teste RED**
- `src/tests/Integration/TenantDataExporterIntegrationTest.php` — com tenants A e B criados no teste (tutor e paciente em cada um, 2 usuários em A): `tables()` contém `tutor`, `patient`, `service` e não contém `billing_event`; `export(A, <tmp>.zip, $now)` gera ZIP que `ZipArchive` abre com `manifest.json`, `users.csv`, `tutor.csv` e `patient.csv`; `tutor.csv` tem o tutor de A e não tem o nome do tutor de B; nenhum cabeçalho de CSV casa com `SENSITIVE_COLUMN_PATTERN`; `users.csv` tem 2 linhas de dados e nenhuma coluna `password`; o manifesto tem `tenant_id` = A e `files_included` false, e `tables['tutor']` = 1 (comando: `SUITE com <Classe> = TenantDataExporterIntegrationTest`)

**Critério de aceite**
- SUITE com `TenantDataExporterIntegrationTest|Phase1TenantIsolationIntegrationTest` mostra `PASS` em todos os métodos, sem `SKIP`, e `Failed: 0`.

**Validação**
- SUITE com `<Classe>` = `TenantDataExporterIntegrationTest|Phase1TenantIsolationIntegrationTest` (evidência: `PASS` em cada método — inclusive o caso "nenhuma linha de B e nenhuma coluna sensível" do Review Focus — e `Failed: 0`)
- LINT de `app/Core/Privacy/TenantDataExporter.php` e do teste (evidência: `No syntax errors detected`)

### T-12 — Equipe da clínica com perfis restritos (`ClinicTeamService`)

**Camada:** backend
**Dependências:** T-04
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Kratos

O administrador passa a escolher, ao convidar ou editar alguém, entre o grupo do plano (administrador) e os 3 perfis restritos. Os perfis são grupos globais identificados pelo nome exato de `ClinicRoleProfiles::groupNames()` (nunca o grupo 1); o grupo do plano continua exigindo o vínculo em `tenant_group`. A listagem mostra o grupo do usuário também quando é um perfil. A tela `ClinicUserForm` já usa `allowedGroups()` e não muda.

**Arquivos prováveis**
- `src/app/Core/Tenancy/ClinicTeamService.php`
- `src/tests/Integration/ClinicTeamServiceIntegrationTest.php`

**Interface**
- Produz: `ClinicTeamService::allowedGroups()` devolve `id → nome` do grupo do plano ligado ao tenant mais os grupos `system_group` com nome em `ClinicRoleProfiles::groupNames()` e `id <> 1`, ordenados por id; sem assinatura segue `[]`
- Produz: `invite(...)` e `update(...)` aceitam `groupId` de perfil e gravam só esse grupo em `system_user_group`; `listUsers()`/`findUser()` devolvem `group_id`/`group` do perfil
- Consome: T-04 `public static function groupNames(): array`

**Teste RED**
- `src/tests/Integration/ClinicTeamServiceIntegrationTest.php` — com os 3 grupos de perfil inseridos no teste pelo nome exato: `allowedGroups()` contém o grupo do plano e os 3 perfis; `invite('Recepção Teste', 'recepcao@example.test', <id Perfil Recepção>, <unidade>, $now)` grava `system_user_group` só com o perfil (sem o grupo do plano) e `findUser()` devolve `group` = `Central Vet - Perfil Recepção`; `update` troca para `Central Vet - Perfil Financeiro`; `invite` com o grupo 1 ou com o grupo do plano de outro tenant segue recusado (comando: `SUITE com <Classe> = ClinicTeamServiceIntegrationTest`)

**Critério de aceite**
- SUITE com `ClinicTeamServiceIntegrationTest|ClinicTeamScreensWiringTest|Etapa3TeamMessagesTest` mostra `PASS` em todos os métodos, sem `SKIP`, e `Failed: 0`.

**Validação**
- SUITE com `<Classe>` = `ClinicTeamServiceIntegrationTest|ClinicTeamScreensWiringTest|Etapa3TeamMessagesTest` (evidência: `PASS` em cada método e `Failed: 0`)
- LINT de `app/Core/Tenancy/ClinicTeamService.php` e do teste (evidência: `No syntax errors detected`)

### T-13 — Landing e checkout alinhados à oferta e aos documentos legais

**Camada:** frontend
**Dependências:** T-03, T-06
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Tesla

A landing já marca "Em breve" para comunicação, IA, internação/cirurgia, Ei, Vet!, Central Intelligence e SSO. Faltam: só exibir e aceitar os planos de `OFFER_PLANS` (no piloto, os 4 planos — decisão do usuário; o filtro existe para o ambiente reduzir a oferta sem mudar código); marcar como "Em breve" o que não é homologado no piloto (`Governança da rede`; `Suporte dedicado` segue anunciado, decisão do usuário); ligar o aceite do checkout aos Termos de Uso e à Política de Privacidade (texto e versão novos) e pôr os dois links no rodapé. `LandingCatalog::plans()` continua com os 4 planos (fonte de `PlanEntitlements`); o filtro é só na saída pública e no checkout.

**Arquivos prováveis**
- `src/app/Core/Landing/LandingCatalog.php`
- `src/app/Core/Landing/LandingPage.php`
- `src/app/Core/Billing/CheckoutStartHandler.php`
- `src/app/view/landing/landing.html`
- `src/landing/landing.js`
- `src/landing/translations.js`
- `src/tests/Unit/LandingOfferAlignmentTest.php`
- `src/tests/Unit/LandingCatalogTest.php`
- `src/tests/Unit/CheckoutStartHandlerTest.php`
- `src/tests/Integration/BillingAcquisitionFlowIntegrationTest.php`

**Interface**
- Produz: `LandingCatalog::TERMS_VERSION = LegalDocuments::TERMS_VERSION` (`termos-uso-2026-10`) e `TERMS_TEXT = 'Li e aceito os Termos de Uso e a Política de Privacidade do Central Vet Pro, com cobrança mensal recorrente no cartão ou por fatura Pix e cancelamento a qualquer momento pelo app, com efeito no fim do período pago.'`; `publicJson(?OfferScope $offer = null): string` (null → `OfferScope::fromEnvironment()`) só com os planos de `offeredPlans()` em `plans` e nas chaves `plans` de cada linha de `compare`, e com `legal` = `{"terms_url": LegalDocuments::TERMS_URL, "privacy_url": LegalDocuments::PRIVACY_URL}`
- Produz: item `Governança da rede` do Enterprise com `soon` true; `Suporte dedicado` (item do Enterprise e linha de `compareRows()`) segue com `soon` false
- Produz: `CheckoutStartHandler` recusa com 422 e `errors['plan_id'] = 'Plano indisponível no momento.'` o plano fora de `OfferScope::isPlanOffered`, antes de gravar pedido ou chamar o Stripe (construtor ganha `?OfferScope $offer = null` como último parâmetro)
- Produz: `landing.html` com links `legal.php?doc=termos` e `legal.php?doc=privacidade` (`target="_blank" rel="noopener"`) junto ao aceite do checkout e no `<footer>`, cache-buster `?v=20261026-piloto` em `landing.css`, `translations.js` e `landing.js`; mensagem de erro do aceite em `landing.js` = `Aceite os Termos de Uso e a Política de Privacidade para continuar.`; textos novos com en/es em `translations.js`
- Consome: T-03 `final class CentralVet\Billing\OfferScope`, T-03 `public function offeredPlans(): array`, T-03 `public function isPlanOffered(string $planId): bool`, T-06 `public const TERMS_VERSION = 'termos-uso-2026-10';`, T-06 `public const TERMS_URL = 'legal.php?doc=termos';`, T-06 `public const PRIVACY_URL = 'legal.php?doc=privacidade';`

**Teste RED**
- `src/tests/Unit/LandingOfferAlignmentTest.php` — com `new OfferScope(PilotSettings::fromArray(['OFFER_PLANS' => 'pro']))`, `json_decode(LandingCatalog::publicJson($offer))` tem só o plano `pro` e cada linha de `compare` só a chave `pro`; com o padrão (`PilotSettings::fromArray([])`, a configuração do piloto) os 4 planos `starter`, `pro`, `business`, `enterprise` e as 4 chaves em cada linha de `compare`; `legal.terms_url` = `legal.php?doc=termos`; `TERMS_VERSION` = `termos-uso-2026-10`; todo item de plano e linha de `compareRows()` com rótulo `Comunicação e automações`, `IA e agentes avançados`, `Relatórios avançados`, `Integrações`, `SSO e SLA`, `Governança da rede` tem `soon` true e `Suporte dedicado` tem `soon` false; `landing.html` tem `legal.php?doc=termos` e `legal.php?doc=privacidade` dentro do `<footer>` e perto de `sub-terms`, e `?v=20261026-piloto` nos 3 assets; `CheckoutStartHandler` com a configuração padrão aceita `plan_id` `starter` e `enterprise` (sem erro em `plan_id`), e com `OFFER_PLANS='pro'` e `plan_id` `starter` responde 422 com `plan_id`, sem pedido em `FakeBillingOrderRepository` e sem chamada no `FakeStripeClient` (comando: `SUITE com <Classe> = LandingOfferAlignmentTest`)

**Critério de aceite**
- SUITE com `LandingOfferAlignmentTest|LandingCatalogTest|LandingPageTest|LandingA11yTest|LandingPageHeadTest|LandingCheckoutWiringTest|CheckoutStartHandlerTest|BillingAcquisitionFlowIntegrationTest|PlanEntitlementsTest|PlanGroupsSeedTest` mostra `PASS` em todos os métodos e `Failed: 0`.
- `node scripts/test-landing-i18n.cjs` termina com código 0.

**Validação**
- SUITE com `<Classe>` = `LandingOfferAlignmentTest|LandingCatalogTest|LandingPageTest|LandingA11yTest|LandingPageHeadTest|LandingCheckoutWiringTest|CheckoutStartHandlerTest|BillingAcquisitionFlowIntegrationTest|PlanEntitlementsTest|PlanGroupsSeedTest` (evidência: `PASS` em cada método — inclusive o POST direto de plano fora da oferta do Review Focus — e `Failed: 0`)
- `node scripts/test-landing-i18n.cjs; echo $?` (evidência: `0`)
- `curl -s http://localhost:8081/ | grep -c 'legal.php?doc='` com a pilha no ar (evidência: ≥ 2)
- LINT de `app/Core/Landing/LandingCatalog.php`, `LandingPage.php`, `app/Core/Billing/CheckoutStartHandler.php` e dos testes (evidência: `No syntax errors detected`)

### T-14 — Tela da assinatura com cancelamento pelo app

**Camada:** frontend
**Dependências:** T-02, T-03, T-08
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Maquiavel

`SubscriptionStatusView` (hoje só leitura) mostra o cancelamento agendado e o botão `Cancelar assinatura`, que abre `SubscriptionCancelForm`: política (fim do período pago, prazo de retenção de `PilotSettings`, link para exportar os dados e para os termos) e o botão `Confirmar cancelamento` (`onConfirm`, POST com CSRF). Sucesso volta à tela da assinatura com `Cancelamento da assinatura solicitado`; erro de domínio aparece em pt-BR por `UserMessage`. Rótulos à esquerda e botões com `.cv-touch-target`.

**Arquivos prováveis**
- `src/app/control/admin/SubscriptionStatusView.php`
- `src/app/control/admin/SubscriptionCancelForm.php`
- `src/app/Core/Presentation/SubscriptionStatusPresenter.php`
- `src/tests/Unit/SubscriptionStatusPresenterTest.php`
- `src/tests/Unit/SubscriptionCancelFormWiringTest.php`

**Interface**
- Produz: controller `SubscriptionCancelForm` com `onConfirm` (mutação) que chama `SubscriptionCancellationService::request(<tenant da sessão>, <usuário da sessão>, new DateTimeImmutable())` e, em `scheduled` ou `already_scheduled`, carrega `SubscriptionStatusView`
- Produz: `SubscriptionStatusPresenter` expõe `cancelScheduledFor` (data do `current_period_end` formatada `d/m/Y` quando `cancelAtPeriodEnd()`, senão null) e `canCancel` (true com status `active`, `past_due` ou `suspended` e sem agendamento)
- Consome: T-08 `public function request(int $tenantId, int $userId, DateTimeImmutable $now): string`, T-08 `public const RESULT_SCHEDULED = 'scheduled';`, T-08 `Subscription::cancelAtPeriodEnd(): bool`, T-03 `public function retentionDaysAfterCancel(): int`, T-02 `Cancel subscription`, T-02 `Confirm cancellation`, T-02 `Cancellation scheduled for ^1`, T-02 `Your subscription remains active until ^1. After that, the clinic data stays available for export for ^2 days and is then deleted`, T-02 `Subscription cancellation requested`, T-02 `Cancellation policy`

**Teste RED**
- `src/tests/Unit/SubscriptionStatusPresenterTest.php`, `src/tests/Unit/SubscriptionCancelFormWiringTest.php` — presenter: assinatura ativa sem agendamento → `canCancel` true e `cancelScheduledFor` null; agendada com período até `2026-11-26` → `cancelScheduledFor` `26/11/2026` e `canCancel` false; `cancelled` → `canCancel` false; wiring (leitura do fonte): `SubscriptionCancelForm` tem `public function onConfirm`, chama `SubscriptionCancellationService` e `->request(`, usa `_t('Confirm cancellation')`, `_t('Your subscription remains active until ^1. After that, the clinic data stays available for export for ^2 days and is then deleted'` e `retentionDaysAfterCancel()`, tem `cv-touch-target` e nenhum `text-align:right`/`text-align: center`; `SubscriptionStatusView` usa `_t('Cancel subscription')`, `SubscriptionCancelForm` e `_t('Cancellation scheduled for ^1'` (comando: `SUITE com <Classe> = SubscriptionStatusPresenterTest|SubscriptionCancelFormWiringTest`)

**Critério de aceite**
- SUITE com `SubscriptionStatusPresenterTest|SubscriptionCancelFormWiringTest|SubscriptionAccessWiringTest` mostra `PASS` em todos os métodos e `Failed: 0`.

**Validação**
- SUITE com `<Classe>` = `SubscriptionStatusPresenterTest|SubscriptionCancelFormWiringTest|SubscriptionAccessWiringTest` (evidência: `PASS` em cada método e `Failed: 0`)
- LINT de `app/control/admin/SubscriptionStatusView.php`, `app/control/admin/SubscriptionCancelForm.php`, `app/Core/Presentation/SubscriptionStatusPresenter.php` e dos testes (evidência: `No syntax errors detected`)

### T-15 — Telas de primeiros passos, dados da clínica e orientações do primeiro atendimento

**Camada:** frontend
**Dependências:** T-02, T-09
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Goku

Assistente de primeiro acesso sem intervenção técnica: `OnboardingChecklistView` lista os 4 itens com estado (`Feito`/`Pendente`) e o atalho de cada um (`ClinicProfileForm`; `ClinicUserList` mais o botão `Trabalho sozinho` → `onMarkTeamReviewed`; `ServiceList` e `ServiceImportForm`; `FirstEncounterGuideView`) e, completo, mostra `Configuração inicial concluída`. `ClinicProfileForm` edita razão social, nome fantasia e nome da unidade. `FirstEncounterGuideView` mostra o passo a passo do primeiro atendimento (agenda → chegada na fila → atendimento → prescrição e PDF → conta do atendimento → recebimento parcial/total → conferência do caixa) e o botão `Marcar como lida` (`onMarkRead`). Rótulos à esquerda, ações com `.cv-touch-target`.

**Arquivos prováveis**
- `src/app/control/admin/OnboardingChecklistView.php`
- `src/app/control/admin/ClinicProfileForm.php`
- `src/app/control/admin/FirstEncounterGuideView.php`
- `src/app/view/onboarding/first-encounter-guide.html`
- `src/tests/Unit/OnboardingScreensWiringTest.php`

**Interface**
- Produz: controllers `OnboardingChecklistView` (`onMarkTeamReviewed`), `ClinicProfileForm` (`onEdit`, `onSave`), `FirstEncounterGuideView` (`onMarkRead`); `ClinicProfileForm::onSave` mostra `Clinic data saved` e volta a `OnboardingChecklistView`
- Produz: `src/app/view/onboarding/first-encounter-guide.html` em pt-BR com os 7 passos na ordem do Contexto, cada um com o nome da tela do menu
- Consome: T-09 `public function status(): array`, T-09 `public function isComplete(): bool`, T-09 `public function markTeamReviewed(DateTimeImmutable $now): void`, T-09 `public function markGuideRead(DateTimeImmutable $now): void`, T-09 `public function load(): array`, T-09 `public function save(string $legalName, ?string $tradeName, string $unitName, DateTimeImmutable $now): void`, T-02 `First steps`, T-02 `Clinic data`, T-02 `Services and prices`, T-02 `First encounter guide`, T-02 `Mark as read`, T-02 `I work alone`, T-02 `Legal name`, T-02 `Trade name`, T-02 `Unit name`, T-02 `Initial setup completed`, T-02 `Clinic data saved`

**Teste RED**
- `src/tests/Unit/OnboardingScreensWiringTest.php` — leitura do fonte: `OnboardingChecklistView` usa `OnboardingChecklistService`, `->status()`, os 4 `ITEM_*`, `ClinicProfileForm`, `ClinicUserList`, `ServiceList`, `ServiceImportForm`, `FirstEncounterGuideView`, `public function onMarkTeamReviewed` e `_t('Initial setup completed')`; `ClinicProfileForm` usa `ClinicProfileService`, `->save(`, `_t('Legal name')`, `_t('Trade name')`, `_t('Unit name')`; `FirstEncounterGuideView` lê `first-encounter-guide.html` e tem `public function onMarkRead`; os 3 têm `cv-touch-target` e nenhum `text-align:right`/`text-align: center`; o HTML do guia tem os 7 passos (`Agenda`, `fila`, `atendimento`, `prescrição`, `conta do atendimento`, `recebimento`, `caixa`) nessa ordem (comando: `SUITE com <Classe> = OnboardingScreensWiringTest`)

**Critério de aceite**
- SUITE com `OnboardingScreensWiringTest` mostra `PASS` em todos os métodos e `Failed: 0`.

**Validação**
- SUITE com `<Classe>` = `OnboardingScreensWiringTest` (evidência: `PASS` em cada método e `Failed: 0`)
- LINT dos 3 controllers e do teste (evidência: `No syntax errors detected`)

### T-16 — Aceite de termos e privacidade no primeiro acesso (`LoginForm`)

**Camada:** frontend
**Dependências:** T-02, T-06, T-10
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Sherlock

No login, depois de `ApplicationAuthenticationService::authenticate` e antes de `checkTwoFactor`, quem tem documento pendente vê um diálogo (padrão de `policyTermsVerification`: `TInputDialog` que reenvia o login com os parâmetros) com os links dos dois documentos (nova aba) e a caixa `accept_legal`. Sem marcar, o login não segue e mostra `Accept the Terms of Use and the Privacy Policy to continue`; marcando, grava o aceite com IP e user agent e segue o fluxo. O `require_terms` do Adianti continua desligado.

**Arquivos prováveis**
- `src/app/control/admin/LoginForm.php`
- `src/tests/Unit/LoginLegalAcceptanceWiringTest.php`

**Interface**
- Produz: `LoginForm::legalAcceptanceVerification($user, $param)` (privado estático) — devolve o `BootstrapFormBuilder` do diálogo quando há pendência e `accept_legal` ausente; com `accept_legal` = `Y` chama `LegalAcceptanceService::accept((int) $user->id, <REMOTE_ADDR>, <HTTP_USER_AGENT ou null>, new DateTimeImmutable())`; com `accept_legal` presente e diferente de `Y` lança `Exception(_t('Accept the Terms of Use and the Privacy Policy to continue'))`
- Consome: T-10 `public function pendingDocuments(int $userId): array`, T-10 `public function accept(int $userId, string $ip, ?string $userAgent, DateTimeImmutable $now): int`, T-06 `public const TERMS_URL = 'legal.php?doc=termos';`, T-06 `public const PRIVACY_URL = 'legal.php?doc=privacidade';`, T-02 `I have read and accept the Terms of Use and the Privacy Policy`, T-02 `Accept the Terms of Use and the Privacy Policy to continue`, T-02 `Privacy policy`

**Teste RED**
- `src/tests/Unit/LoginLegalAcceptanceWiringTest.php` — leitura do fonte de `LoginForm.php`: em `onLogin`, a chamada `self::legalAcceptanceVerification(` vem depois de `ApplicationAuthenticationService::authenticate(` e antes de `self::checkTwoFactor(`, e antes de `loadSessionVars(`; o método usa `LegalAcceptanceService`, `pendingDocuments(`, `->accept(`, `LegalDocuments::TERMS_URL`, `LegalDocuments::PRIVACY_URL`, `'accept_legal'`, `_t('I have read and accept the Terms of Use and the Privacy Policy')` e `_t('Accept the Terms of Use and the Privacy Policy to continue')`; não grava `json_encode($_SERVER)` (comando: `SUITE com <Classe> = LoginLegalAcceptanceWiringTest`)

**Critério de aceite**
- SUITE com `LoginLegalAcceptanceWiringTest|PasswordResetFormsWiringTest|LegalAcceptanceIntegrationTest` mostra `PASS` em todos os métodos e `Failed: 0`.

**Validação**
- SUITE com `<Classe>` = `LoginLegalAcceptanceWiringTest|PasswordResetFormsWiringTest|LegalAcceptanceIntegrationTest` (evidência: `PASS` em cada método e `Failed: 0`)
- LINT de `app/control/admin/LoginForm.php` e do teste (evidência: `No syntax errors detected`)

### T-17 — Telas de suporte e de exportação dos dados

**Camada:** frontend
**Dependências:** T-02, T-03, T-06, T-11
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Platão

`SupportView` (destino de "Ajuda" no menu): e-mail do suporte (`mailto:`), WhatsApp (`https://wa.me/<dígitos>`) quando configurado, horário, links para os termos, a privacidade e a política de cancelamento (âncora `#cancelamento` nos termos) e, sem e-mail configurado, `Support channel not configured`. `ClinicDataExportView`: explica o conteúdo e o botão `Exportar dados da clínica` → `onDownload` (leitura) gera o ZIP num temporário, registra `audit_log` (`AuditEvent` com ação `clinic_data_exported` e o tenant), envia `application/zip` com `Content-Disposition: attachment; filename="dados-clinica-<Y-m-d>.zip"` e `Cache-Control: private, no-store` (padrão de `FinancialOverview`) e apaga o temporário. Rótulos à esquerda, ações com `.cv-touch-target`.

**Arquivos prováveis**
- `src/app/control/admin/SupportView.php`
- `src/app/control/admin/ClinicDataExportView.php`
- `src/tests/Unit/SupportAndExportScreensWiringTest.php`

**Interface**
- Produz: controllers `SupportView` e `ClinicDataExportView` (`onDownload`)
- Consome: T-03 `final class CentralVet\Pilot\PilotSettings`, T-03 `public function supportEmail(): ?string`, T-03 `public function supportWhatsapp(): ?string`, T-03 `public function supportHours(): string`, T-11 `public function export(int $tenantId, string $zipPath, DateTimeImmutable $now): array`, T-06 `public const TERMS_URL = 'legal.php?doc=termos';`, T-06 `public const PRIVACY_URL = 'legal.php?doc=privacidade';`, T-02 `Support`, T-02 `Support e-mail`, T-02 `Service hours`, T-02 `Support channel not configured`, T-02 `Export clinic data`, T-02 `The file contains the clinic records in CSV format, one file per table`, T-02 `Cancellation policy`

**Teste RED**
- `src/tests/Unit/SupportAndExportScreensWiringTest.php` — leitura do fonte: `SupportView` usa `PilotSettings`, `supportEmail()`, `supportWhatsapp()`, `https://wa.me/`, `mailto:`, `LegalDocuments::TERMS_URL`, `LegalDocuments::PRIVACY_URL`, `#cancelamento` e `_t('Support channel not configured')`; `ClinicDataExportView` tem `public function onDownload`, usa `TenantDataExporter`, `->export(`, `clinic_data_exported`, `application/zip`, `Content-Disposition: attachment`, `Cache-Control: private, no-store`, `unlink(` e `_t('Export clinic data')`; os 2 têm `cv-touch-target` e nenhum `text-align:right`/`text-align: center`; `onDownload` é método de leitura por `MutationRequestPolicy::isReadOnly('ClinicDataExportView', 'onDownload')` (comando: `SUITE com <Classe> = SupportAndExportScreensWiringTest`)

**Critério de aceite**
- SUITE com `SupportAndExportScreensWiringTest|MutationRequestPolicyTest` mostra `PASS` em todos os métodos e `Failed: 0`.

**Validação**
- SUITE com `<Classe>` = `SupportAndExportScreensWiringTest|MutationRequestPolicyTest` (evidência: `PASS` em cada método e `Failed: 0`)
- LINT de `app/control/admin/SupportView.php`, `app/control/admin/ClinicDataExportView.php` e do teste (evidência: `No syntax errors detected`)

### T-18 — Jornada do piloto por perfil com 2 tenants (teste de integração)

**Camada:** qa
**Dependências:** T-04, T-12
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Saitama

Prova automatizada da jornada homologável no `centralvet_test`, com os serviços reais (sem controllers): o administrador configura a equipe (`ClinicTeamService::invite` com os perfis) → a recepção cadastra tutor e paciente e agenda (`TutorService::create`, `PatientService::create`, `AppointmentService::schedule`, `QueueEntryService::checkIn`) → o veterinário atende e emite a prescrição com PDF (`EncounterService::start`/`finish`, `PrescriptionService::create`, `DocumentRequestService::request(DocumentKind::PRESCRIPTION, …)` + `DocumentGenerationService::generate` com `DompdfDocumentRenderer` e `FakeStorage`) → o financeiro abre o caixa, fecha a conta e recebe parcial e total (`CashSessionService::open`, `EncounterAccountService::openOrGet`/`close`, `PaymentService::register` duas vezes, `CashSessionService::totalsByPaymentMethod`). Cada perfil age com `RbacAuthorizationService` sobre `AdiantiProgramPermissionProvider` e uma `FakeSessionContextSource` cujos `programs` são `ClinicRoleProfiles::programs(<perfil>)`. A jornada roda nos tenants A (plano `pro`) e B (plano `business`), cada um com `subscription` ativa inserida no teste, para exercitar a guarda e as quotas reais; um terceiro tenant C (`starter`, quota de 2 usuários) cobre o limite de equipe. Só arquivo de teste: defeito de produto encontrado vai ao board e a `Pendências` (não se corrige código de produção nesta task).

**Arquivos prováveis**
- `src/tests/Integration/PilotJourneyIntegrationTest.php`

**Interface**
- Produz: `src/tests/Integration/PilotJourneyIntegrationTest.php` (teste da jornada; nenhum símbolo de produção)
- Consome: T-04 `public static function programs(string $role): array`, T-04 `public const GROUP_NAMES`, T-12 `ClinicTeamService::allowedGroups()`

**Teste RED**
- `src/tests/Integration/PilotJourneyIntegrationTest.php` — para A e B: a jornada completa termina com o recebível do atendimento quitado (saldo 0 depois do pagamento parcial e do total), a soma de `totalsByPaymentMethod` do caixa igual ao total pago e o PDF gravado começando com `%PDF-`; perfis restritos: a recepção chamando `PrescriptionService::create` e o veterinário chamando `PaymentService::register` recebem `AuthorizationDenied`, e o financeiro chamando `AppointmentService::schedule` também; isolamento: no contexto de B, `PatientService::findById(<paciente de A>)` devolve null e `PaymentService::register` sobre o recebível de A lança exceção cuja mensagem `UserMessage::resolve` reconhece (não nula); quotas: no tenant C (`starter`), com o administrador e 1 convidado, o terceiro `ClinicTeamService::invite` lança `QuotaExceededException`; falha antes de T-04/T-12 por perfil ausente em `allowedGroups()` (comando: `SUITE com <Classe> = PilotJourneyIntegrationTest`)

**Critério de aceite**
- SUITE com `PilotJourneyIntegrationTest` mostra `PASS` em todos os métodos, sem `SKIP`, e `Failed: 0`, rodando duas vezes seguidas (sem resíduo entre execuções).

**Validação**
- SUITE com `<Classe>` = `PilotJourneyIntegrationTest`, duas vezes (evidência: `PASS` em cada método e `Failed: 0` nas duas)
- `printf 'SELECT COUNT(*) FROM tenant; SELECT COUNT(*) FROM patient;\n' |` MYSQLTEST antes e depois (evidência: contagens iguais — o teste não deixa dado no `centralvet_test`)
- LINT do teste (evidência: `No syntax errors detected`)

### T-19 — Registro das telas novas: programas, grupos, menu, guarda e CSRF

**Camada:** backend
**Dependências:** T-04, T-07, T-14, T-15, T-17
**Paralelizável:** não
**Complexidade:** média
**Agente:** Levi

Escritor único dos arquivos centrais compartilhados. Registra os 6 programas novos no seed inicial, regenera `plan-groups.sql` (nenhum é gated: entram nos 4 planos), aponta o menu, libera na guarda o que precisa abrir com a assinatura bloqueada ou cancelada (suporte, exportação LGPD, cancelamento) e documenta os métodos de escrita no inventário CSRF. Acrescenta em `translations.json` e no `Etapa5MessagesTest` as chaves novas que outras tasks registraram no board (`chave nova:`).

**Arquivos prováveis**
- `src/app/database/seeds/initial-application-programs.sql`
- `src/app/database/seeds/plan-groups.sql`
- `src/menu.xml`
- `src/app/Core/Billing/SubscriptionAccessGuard.php`
- `docs/security/csrf-inventario.md`
- `src/app/config/translations.json`
- `src/tests/Unit/Etapa5MessagesTest.php`
- `src/tests/Unit/Etapa5WiringTest.php`

**Interface**
- Produz: programas `OnboardingChecklistView`, `ClinicProfileForm`, `FirstEncounterGuideView`, `SupportView`, `ClinicDataExportView`, `SubscriptionCancelForm` em `initial-application-programs.sql` (padrão `Central Vet - <Nome>` + ligação ao grupo 1) e nos 4 grupos de plano de `plan-groups.sql`
- Produz: `menu.xml` — item `Dashboard` (topo) vira `_t{First steps}` → `OnboardingChecklistView`; em `_t{Settings}`, `_t{Clinic data}` → `ClinicProfileForm` e `_t{Export clinic data}` → `ClinicDataExportView`; `_t{Help}` → `SupportView` (sai o `onComingSoon#item=help`)
- Produz: `SubscriptionAccessGuard::ALWAYS_ALLOWED` com `SupportView`, `ClinicDataExportView` e `SubscriptionCancelForm` a mais
- Produz: `docs/security/csrf-inventario.md` com `ClinicProfileForm::onSave`, `FirstEncounterGuideView::onMarkRead`, `OnboardingChecklistView::onMarkTeamReviewed` e `SubscriptionCancelForm::onConfirm` (escrita) e `ClinicDataExportView::onDownload`, `ClinicProfileForm::onEdit` (leitura)
- Consome: T-04 `public static function programs(string $role): array`, T-07 `SubscriptionAccessGuard::__construct(SubscriptionRepositoryInterface $subscriptions, ?OfferScope $offer = null)`, T-14 `SubscriptionCancelForm`, T-15 `OnboardingChecklistView`, T-15 `ClinicProfileForm`, T-15 `FirstEncounterGuideView`, T-17 `SupportView`, T-17 `ClinicDataExportView`

**Teste RED**
- `src/tests/Unit/Etapa5WiringTest.php` — os 6 controllers têm `controller='<X>'` em `initial-application-programs.sql` e aparecem nos 4 grupos de `plan-groups.sql`; todo programa de `ClinicRoleProfiles::programs()` dos 3 perfis existe em `initial-application-programs.sql`; `menu.xml` tem `<action>OnboardingChecklistView</action>`, `<action>ClinicProfileForm</action>`, `<action>ClinicDataExportView</action>`, `<action>SupportView</action>` e não tem `onComingSoon#item=help`; `SubscriptionAccessGuard::ALWAYS_ALLOWED` contém os 3 controllers; `csrf-inventario.md` cita os 6 métodos da Interface (comando: `SUITE com <Classe> = Etapa5WiringTest`)

**Critério de aceite**
- SUITE com `Etapa5WiringTest|PlanGroupsSeedTest|RoleGroupsSeedTest|Etapa5MessagesTest|SubscriptionAccessGuardTest|MutationRequestPolicyTest|ClinicUnitScreensWiringTest|SaleCancelFormWiringTest` mostra `PASS` em todos os métodos e `Failed: 0`.
- `python3 -c "import xml.dom.minidom;xml.dom.minidom.parse('src/menu.xml')"` termina com código 0; `python3 -m json.tool src/app/config/translations.json > /dev/null` termina com código 0.
- Os 2 seeds aplicados duas vezes no `centralvet_test` por MYSQLTEST terminam com código 0 e os 6 programas novos aparecem em `system_program`.

**Validação**
- SUITE com `<Classe>` = `Etapa5WiringTest|PlanGroupsSeedTest|RoleGroupsSeedTest|Etapa5MessagesTest|SubscriptionAccessGuardTest|MutationRequestPolicyTest|ClinicUnitScreensWiringTest|SaleCancelFormWiringTest` (evidência: `PASS` em cada método e `Failed: 0`)
- o parse do `menu.xml` por `xml.dom.minidom` e o `json.tool` (evidência: `0` nos dois)
- `printf 'SELECT COUNT(*) FROM system_program; SELECT COUNT(*) FROM system_group_program; SELECT COUNT(*) FROM system_users;\n' |` MYSQLTEST antes e depois de aplicar `initial-application-programs.sql`, `plan-groups.sql` e `role-groups.sql` duas vezes no `centralvet_test` (evidência: `system_program` +6 só na primeira, contagens iguais entre a 1ª e a 2ª, `system_users` igual)
- LINT de `app/Core/Billing/SubscriptionAccessGuard.php` e do teste (evidência: `No syntax errors detected`)

### T-20 — Roteiro de homologação, checklist de go-live e runbook do piloto

**Camada:** docs
**Dependências:** T-05, T-08, T-11, T-13, T-17, T-18, T-19
**Paralelizável:** não
**Complexidade:** média
**Agente:** Gandalf

Documentação versionada do piloto em `docs/runbooks/` (kebab-case, pt-BR), apontada pelo índice. Sem credencial real; comando de escrita em banco sempre marcado como "exige autorização".

**Arquivos prováveis**
- `docs/runbooks/piloto-homologacao.md`
- `docs/runbooks/piloto-go-live.md`
- `docs/runbooks/piloto-operacao.md`
- `docs/runbooks/README.md`

**Interface**
- Produz: `piloto-homologacao.md` — roteiro por perfil (administrador → recepção → veterinário → financeiro) com dados de teste, repetido em 2 tenants, com os perfis restritos (o que cada um não pode abrir), o primeiro acesso (aceite dos termos, primeiros passos), PDFs, mensagens de erro esperadas em pt-BR, recuperação de uma falha (pagamento recusado, convite expirado), módulo fora do recorte (`Este módulo ainda não está disponível no piloto`), cancelamento e exportação; cada passo com resultado esperado observável e coluna de evidência; cita `PilotJourneyIntegrationTest` como a parte automatizada
- Produz: `piloto-go-live.md` — checklist de go-live: preflight (`support_email` incluso), migrations 0001–0020 aplicadas (`migration-status`), seeds `initial-application-programs.sql`, `plan-groups.sql` e `role-groups.sql` aplicados com autorização, `OFFER_PLANS`/`OFFER_PREVIEW_FEATURES`/`SUPPORT_*`/`DATA_RETENTION_DAYS_AFTER_CANCEL` definidos, backup e restauração ensaiados, crons via `cron-run.sh`, Stripe em modo live com webhook, SMTP real, textos jurídicos revisados (sai o `RASCUNHO — revisar com jurídico`), CI verde (`scripts/ci/run.sh`)
- Produz: `piloto-operacao.md` — runbook do piloto: canal e horário de suporte, triagem e severidade de incidentes, comunicação com as clínicas, coleta de feedback (cadência e registro), política de cancelamento e retenção (30 dias; exportação pelo app, exclusão manual pelo operador depois do prazo, com autorização; fotos e PDFs entregues pelo suporte a pedido), canal de suporte (e-mail obrigatório, WhatsApp opcional, de segunda a sexta, das 8h às 18h), critérios de saída do piloto (nenhum bloqueio aberto no escopo anunciado, jornada homologada nas clínicas piloto, sem incidente sev1 aberto, feedback consolidado)
- Produz: as 3 entradas novas em `docs/runbooks/README.md`
- Consome: T-03 `OFFER_PLANS`, T-08 `public const RESULT_SCHEDULED = 'scheduled';`, T-17 `SupportView`, T-18 `src/tests/Integration/PilotJourneyIntegrationTest.php`, T-19 `ClinicDataExportView`

**Teste RED**
- sem teste: documentação; a prova são os `grep` da Validação

**Critério de aceite**
- `grep -c 'piloto-' docs/runbooks/README.md` imprime `3`; `grep -l 'PilotJourneyIntegrationTest' docs/runbooks/piloto-homologacao.md` lista o arquivo; `grep -c 'support_email\|role-groups.sql\|OFFER_PLANS' docs/runbooks/piloto-go-live.md` ≥ 3; `grep -ci 'critérios de saída' docs/runbooks/piloto-operacao.md` ≥ 1.
- `grep -rnE '(sk_live_|sk_test_)[A-Za-z0-9]+|@(gmail|hotmail)\.com' docs/runbooks/piloto-*.md` não imprime nada.

**Validação**
- os `grep` do critério (evidência: as contagens e nenhuma linha no grep de credencial)
- SUITE completo sem filtro de classe (evidência: `Failed: 0` — os testes que leem `docs/` seguem verdes)

## Legenda

- [ ] pendente
- [~] em andamento
- [x] concluído com evidência
- [!] bloqueado

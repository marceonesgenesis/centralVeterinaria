# Plano: Etapa 1 — Segurança do MVP (S1, S2, S3, S4, F1)

## Objetivo
Fechar os bloqueios P0 de segurança e integridade da auditoria `.docs/audits/2026-10-09-prontidao-mvp.md`: cadastro público legado fechado (S1), mutações autenticadas exigindo POST + token (S2), recuperação de senha endurecida (S3), venda que só aceita referências da própria clínica (S4) e PDV vendendo pelo preço de venda (F1).

## Premissas
- Branch base `task/fase-8b-pendencias` (HEAD `aba0e82`), por pedido explícito do desenvolvedor; branch de trabalho nova a partir dela.
- A entrega preserva os commits por onda (não desfazer commits na entrega).
- Nenhum SQL contra banco de dev/produção sem autorização expressa; testes de integração usam o `centralvet_test` do runner (`docs/runbooks/tests.md`).
- Exceção autorizada pelo desenvolvedor: o validador faz teste de fumaça no navegador no banco de dev só com dados de teste que ele cria e exclui (T-06 e T-08, Validação).
- `main` é a branch de produção para a PR futura.
- Regras de UI do `CLAUDE.md` (rótulos à esquerda, alvos de toque de 44 px) e mensagens em pt-BR via `translations.json`.
- Arquivos listados em `src/app/config/framework_hashes.php` (`engine.php`, `index.php`, `init.php`, `src/lib/adianti/**`, `layout.html`, `iframe.html`, `custom.css` e demais do tema) não são editados (ADR 0006); `cv-shell.js` e `app/service/auth/*` não estão na lista.
- F1: produto sem preço de venda (`sale_price_cents` `NULL`) é recusado com mensagem clara; preço 0 informado explicitamente é aceito; nunca há fallback para o custo.
- S3: o consumo único do token dispensa migration (impressão digital do hash da senha no JWT; ver Decisões).

## Escopo

### Incluso
- S1: `SystemRegistrationForm` fora de `public_classes`, `user_register = '0'`, entradas removidas dos menus públicos; botão "Criar conta" do login continua levando à landing.
- S2: inventário das ações que gravam dados e guard central (POST + token) para toda requisição logada, inclusive ações de grid, exclusão, pagamento, cancelamento, admin e copiloto IA; navegação de topo por `index.php` nunca executa método que grava.
- S3: URL pública canônica `APP_PUBLIC_URL`, resposta uniforme, limite por conta e por IP com o `LoginRateLimiter`, token de uso único com expiração e invalidação após uso; avaliação da revogação de sessões registrada em `notes.md`.
- S4: `SaleService::create` resolve tutor, paciente e atendimento no tenant e valida paciente–tutor e atendimento–paciente/unidade antes de gravar venda ou consumir estoque.
- F1: carrinho, venda gravada e recibo pelo `salePriceCents()`; produto sem preço recusado.
- S7 (achado na execução): `AdiantiMultiSearchService`/`AdiantiAutocompleteService` (framework protegido) recebem `operator`, `operator_idsearch`, `orderColumn`, `mask` e `criteria` fora do hash de parâmetros. Isso permite injeção de SQL, leitura de outra clínica e exibição de atributos como `password`. A correção é um pré-filtro antes do dispatch (`SearchParameterGuard`, T-11) que valida esses parâmetros contra listas fechadas, sem editar o framework. O autocomplete legítimo continua funcionando.
- Runbooks de ambiente e hospedagem compartilhada atualizados.

### Excluído
- S5 (dependências), S6 (download genérico), F2–F5.
- Onboarding controlado (Etapa 3).
- Revogação de sessões após reset (avaliada; adiada pelo desenvolvedor — ver `notes.md`).
- Gravação no construtor de `SystemMessageFormView` (GET que marca mensagem como lida; risco registrado).

## Contexto técnico
- Camadas envolvidas: backend, frontend, shared, docs, qa
- Projeto/base analisada: `/var/www/html/centralvet` (raiz do repositório; código em `src/`)
- Integrações: SMTP via `MailService` (preferências do sistema), Redis (limitador e sessão), MySQL de teste do runner

## Baseline
- php-lint: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -l <arquivo>` nos 11 arquivos PHP existentes que o plano modifica, em raiz → baseline/php-lint.txt (0 linhas)

## Exploração read-only
- Caminhos relevantes: `src/app/config/application.php:50-58` (public_classes, user_register); `src/app/control/admin/SystemRegistrationForm.php:87-171`; `src/menu-public.xml:28`, `src/menu-bottom-public.xml:4`; `src/app/control/admin/LoginForm.php:100-118,162-173`; `src/engine.php:12,24-41` e `src/index.php:23,66` (despacho, protegidos por hash); `src/app/service/auth/ApplicationAuthenticationService.php:240` (`checkMultiSession`, chamado antes do despacho nas duas entradas); `src/app/Core/Security/CsrfToken.php`; `src/app/control/clinic/CvShellController.php:35-113,204-213` (token `cv_shell_csrf`); `src/app/templates/adminbs5/js/cv-shell.js`; `src/lib/adianti/include/adianti.min.js` (`__adianti_load_page` = `$.get`, `__adianti_post_data` = `$.post`); `src/app/control/admin/SystemRequestPasswordResetForm.php:56-118`, `SystemPasswordResetForm.php:78-140`; `src/app/Core/Security/LoginRateLimiter.php`; `src/app/Core/Application/SaleService.php:106-215`; `src/app/control/clinic/SaleForm.php:390-397,521-606,764-790`; `src/app/Core/Domain/Product.php:195-203`; `src/app/Core/Presentation/UserMessage.php`; `src/app/lib/widget/CvFormat.php:90-125`; `src/tests/Support/FakeTutorRepository.php`, `FakePatientRepository.php`, `FakeEncounterRepository.php`, `MysqlIntegrationTestCase.php`.
- Padrões identificados: runner próprio `src/tests/run.php` (`Unit|Integration/*Test.php`, métodos `test*`, `Assert::*`); testes de fonte (ex.: `LandingPageTest`) para código Adianti; mensagens de domínio em inglês mapeadas por `UserMessage::STATIC` e traduzidas em `translations.json` (lista `en`/`pt`); repositórios `findById` já filtrados por tenant; `CrossTenantReferenceException` vira "The selected record does not belong to this clinic" em `CvFormat::userError`.
- Scripts úteis: LINT e SUITE (cabeçalho de `tasks.md`); `docs/runbooks/tests.md` (banco `centralvet_test`, `scripts/test-db/provision.sh`).
- Riscos identificados: ~155 métodos `on*` que gravam e prefixo `on` que não separa leitura de escrita (allowlist de leitura + negação do resto); `index.php` reexecuta `$_REQUEST` por XHR (cabeçalho sozinho seria contornado); `iframe.html` protegido por hash pode não carregar `cv-shell.js`; TFile/uploader usa `XMLHttpRequest` sem jQuery; S4 e F1 dividem `SaleService.php`/`SaleForm.php`; `translations.json` e `UserMessage.php` disputados por várias tasks.

## Mapa de arquivos

| Arquivo | Responsabilidade | Ação | Tasks que tocam |
|---|---|---|---|
| `src/app/config/translations.json` | mensagens pt-BR | modificar | T-01 |
| `src/app/Core/Presentation/UserMessage.php` | mensagem de domínio → chave | modificar | T-01 |
| `src/tests/Unit/SecurityMvpMessagesTest.php` | teste das mensagens | criar | T-01 |
| `src/app/Core/Security/MutationRequestPolicy.php` | decisão POST + token por classe/método | criar | T-02 |
| `src/tests/Unit/MutationRequestPolicyTest.php` | teste da política | criar | T-02 |
| `docs/security/csrf-inventario.md` | inventário das ações que gravam | criar | T-02 |
| `src/app/Core/Security/PublicUrl.php` | URL pública canônica | criar | T-03 |
| `src/app/Core/Security/PasswordResetToken.php` | JWT de reset de uso único | criar | T-03 |
| `src/app/Core/Security/InvalidPasswordResetToken.php` | exceção de token inválido | criar | T-03 |
| `src/app/Core/Security/TooManyPasswordResetAttempts.php` | exceção de limite | criar | T-03 |
| `src/app/Core/Security/PasswordResetRequest.php` | fluxo do pedido de reset | criar | T-03 |
| `src/app/Core/Security/LoginRateLimiter.php` | prefixo em `fromEnvironment` | modificar | T-03 |
| `src/tests/Unit/PublicUrlTest.php` | teste | criar | T-03 |
| `src/tests/Unit/PasswordResetTokenTest.php` | teste | criar | T-03 |
| `src/tests/Unit/PasswordResetRequestTest.php` | teste | criar | T-03 |
| `src/app/config/application.php` | classes públicas e `user_register` | modificar | T-04 |
| `src/menu-public.xml` | menu público | modificar | T-04 |
| `src/menu-bottom-public.xml` | menu público inferior | modificar | T-04 |
| `src/app/control/admin/LoginForm.php` | botão "Criar conta" → landing | modificar | T-04 |
| `src/tests/Unit/PublicRegistrationDisabledTest.php` | teste S1 | criar | T-04 |
| `src/app/Core/Application/SaleService.php` | referências no tenant e preço de venda | modificar | T-05 |
| `src/app/Core/Domain/Product.php` | `requireSalePriceCents()` | modificar | T-05 |
| `src/app/Core/Domain/Exception/ProductWithoutSalePriceException.php` | exceção F1 | criar | T-05 |
| `src/app/Core/Domain/SaleItem.php` | docblock do preço | modificar | T-05 |
| `src/tests/Unit/SaleServiceTest.php` | testes S4/F1 | modificar | T-05 |
| `src/tests/Unit/ProductSalePriceTest.php` | teste do preço obrigatório | criar | T-05 |
| `src/app/service/auth/ApplicationAuthenticationService.php` | guard central em `checkMultiSession` | modificar | T-06 |
| `src/app/templates/adminbs5/js/cv-shell.js` | cabeçalho `X-CSRF-Token` e GET→POST | modificar | T-06 |
| `src/tests/Unit/MutationGuardWiringTest.php` | teste de wiring | criar | T-06 |
| `src/app/control/admin/SystemRequestPasswordResetForm.php` | pedido de reset | modificar | T-07 |
| `src/app/control/admin/SystemPasswordResetForm.php` | troca da senha | modificar | T-07 |
| `.env.example` | `APP_PUBLIC_URL` | modificar | T-07 |
| `docker-compose.yml` | `APP_PUBLIC_URL` no ambiente | modificar | T-07 |
| `src/tests/Unit/PasswordResetFormsWiringTest.php` | teste de wiring | criar | T-07 |
| `src/app/control/clinic/SaleForm.php` | carrinho pelo preço de venda e wiring | modificar | T-08 |
| `src/tests/Unit/SaleFormPricingTest.php` | teste de fonte | criar | T-08 |
| `docs/runbooks/environments.md` | variáveis por ambiente | modificar | T-09 |
| `docs/runbooks/shared-hosting-mysql57.md` | `hosting-environment.php` | modificar | T-09 |
| `docs/runbooks/README.md` | índice | modificar | T-09 |
| `src/tests/Integration/SaleServiceTenantReferencesIntegrationTest.php` | regressão S4/F1 no MySQL | criar | T-10 |
| `src/app/Core/Security/SearchParameterGuard.php` | pré-filtro S7 dos serviços de busca | criar | T-11 |
| `src/tests/Unit/SearchParameterGuardTest.php` | teste S7 | criar | T-11 |
| `src/tests/Support/Fixtures/multisearch-tenant-7-criteria.txt` | `criteria` real serializado (tenant 7) | criar | T-11 |

Nenhum arquivo é tocado por mais de uma task: `translations.json` e `UserMessage.php` ficam só com T-01 (Onda 1, contrato das mensagens), `SaleService.php` só com T-05 e `SaleForm.php` só com T-08 (Onda 3, depois do construtor novo). O pré-filtro S7 mora em classe própria de T-11 (Onda 2); só T-06 (Onda 3) edita `ApplicationAuthenticationService.php` e o invoca.

## Decisões de arquitetura

| Decisão | Alternativas consideradas | Motivo da escolha |
|---|---|---|
| Guard central em `ApplicationAuthenticationService::checkMultiSession()` (T-06) com decisão pura em `MutationRequestPolicy` (T-02) | Editar `engine.php`/`index.php`/`AdiantiCoreApplication`; `enableCSRFProtection()` por formulário; `SystemPermission::checkPermission` | É o único código editável que roda antes do despacho nas duas entradas; as entradas e o framework estão em `framework_hashes.php`; por formulário não cobre grid/exclusão; `checkPermission` também roda ao renderizar botões (`TAction.php:341`) |
| Allowlist de leitura (nomes e prefixos) + `MUTATING_OVERRIDES`; todo o resto exige POST + token | Denylist de métodos que gravam | O prefixo `on` não separa leitura de escrita (~155 de 375 gravam); errar uma leitura como escrita só custa POST via UI, errar o contrário abre CSRF |
| Token da sessão (`cv_shell_csrf`, reuso de `CsrfToken`) exposto em cookie `cv_csrf` `SameSite=Strict` e enviado em `X-CSRF-Token` por `$.ajaxPrefilter`, que troca GET por POST em `engine.php` | `<meta>` no `layout.html` (protegido); token só via `onContext` assíncrono; campo oculto em cada formulário | Cookie é lido de forma síncrona antes de qualquer XHR; origem cruzada não lê cookie nem envia cabeçalho próprio sem CORS; cobre `__adianti_load_page` (grid, TQuestion) e `__adianti_post_data` sem tocar o framework |
| Em `index.php`, método que grava é removido (`STRIP_METHOD`): a página abre sem executá-lo | Negar a página inteira; confiar no cabeçalho | `index.php` reexecuta `$_REQUEST` por XHR, que levaria o cabeçalho: link forjado de topo executaria a ação |
| Guard só para sessão logada; uploader (`AdiantiUploaderService`, `SystemDocumentUploaderService`) e `LoginForm::onLogout` isentos | Proteger também telas públicas | Público só tem login e reset (tratados em S3); uploader usa `XMLHttpRequest` sem jQuery e grava só em `tmp/`; logout por link de topo é CSRF de baixo impacto |
| Uso único do token de reset pela claim `pwd` = HMAC do hash atual da senha | Tabela `password_reset_token` com `used_at` (migration + variante MySQL 5.7 + `provision.sh`) | Trocar a senha muda o hash e invalida o JWT sem migration; mantém expiração de 3 h; tokens emitidos antes ficam inválidos após qualquer troca |
| Limitador do reset = `LoginRateLimiter` com prefixo próprio, identificadores por conta+IP e por IP | Limitador novo | Reuso pedido na demanda; mesmas variáveis `LOGIN_RATE_LIMIT_*` |
| S4: tutor/paciente/atendimento resolvidos por `findById` dos repositórios do tenant; incoerência dentro do tenant → `InvalidArgumentException` mapeada por `UserMessage` | Exceção nova por regra | `SaleForm::onSave` já trata `InvalidArgumentException` com `CvFormat::userError`; segue o padrão de `EncounterAccountService:124-150` |
| S7: `SearchParameterGuard` puro (T-11) invocado por `enforceMutationGuard()` (T-06), com listas fechadas de operadores, identificadores, máscaras e models, e `criteria` restrito ao filtro `tenant_id` da sessão; `AdiantiAutocompleteService` negado | Editar os serviços do framework; subclasses `Cv*` dos widgets com serviço próprio; só validar `operator`/`orderColumn` | O framework é protegido por hash; as subclasses exigiriam trocar 11 usos; `criteria` e `mask` também estão fora do hash (leitura de outra clínica e de `password`); o autocomplete não tem uso no app e o hash dele é forjável (seed fixo) |
| F1: `Product::requireSalePriceCents()` usado por `SaleService` e `SaleForm` | Checagem duplicada em cada chamador | Uma regra, testável no domínio |

## Diagrama de dependências

```text
T-01 ─┬─► T-05 ──► T-08 ──► T-10
      ├─► T-06 ──► T-09
      └─► T-07 ──► T-09
T-02 ─┬─► T-06
      └─► T-11 ──► T-06
T-03 ───► T-07
T-04 (independente)
```

## Estratégia de execução
- Branch de trabalho: `task/etapa-1-seguranca-mvp`
- Branch base: `task/fase-8b-pendencias`
- Commits da onda: cada implementador commita os próprios caminhos (`git commit -- <caminhos>`) com o trailer da task. O fechador usa a skill `new-commit --auto` só se sobrou algo sem commit e sempre registra o estado das tasks num `chore(tasks): registra commits da onda N`. Por premissa do desenvolvedor, a entrega da Fase 5 preserva os commits por onda (não os desfaz); push e PR só a pedido dele.
- Isolamento em ondas com edições paralelas: caminho exclusivo (cada agente só nos arquivos da própria task; nenhum arquivo compartilhado na mesma onda)

## Ondas de execução

### Onda 1
- T-01, T-02, T-03, T-04

### Onda 2
- T-05, T-07, T-11

### Onda 3
- T-06, T-08

### Onda 4
- T-09, T-10

## Agentes

| Agente | subagent_type | model | Tasks |
|---|---|---|---|
| Sun Tzu — orquestrador | — | — | todas |
| Platão | general-purpose | inherit | T-01 |
| Spock | general-purpose | inherit | T-02, T-11 |
| Jaspion | general-purpose | inherit | T-03, T-07 |
| Levi | general-purpose | inherit | T-04 |
| Arquimedes | general-purpose | inherit | T-05, T-08 |
| Aang | general-purpose | inherit | T-06 |
| Gandalf | geduc:documentador | sonnet | T-09 |
| Yoda | general-purpose | inherit | T-10 |

## Review Focus
- Upload por TFile/TMultiFile (`XMLHttpRequest` sem jQuery, sem `X-CSRF-Token`) para `AdiantiUploaderService` → upload aceito (classe isenta) → T-02
- Logout por link de topo `index.php?class=LoginForm&method=onLogout` → logout executa (ação isenta, não sofre `STRIP_METHOD`) → T-02
- Mesmo link de reset usado duas vezes, a segunda depois da troca de senha → segunda tentativa recusada (`matchesPassword` `false`) → T-03
- Pedido de reset com login inexistente, inativo e ativo → mesmo retorno e mesma mensagem; e-mail só para o ativo → T-03
- Tela aberta em `index.php?template=iframe` (o `iframe.html` protegido pode não carregar `cv-shell.js`) → mutação recebe 403 com a mensagem de T-01, nunca grava sem token; registrado no relatório → T-06

## Critérios gerais de aceite
- `engine.php` GET ou POST sem `X-CSRF-Token` válido para método que grava, em sessão logada, responde 403 sem gravar; formulário e ação de grid enviados pela UI chegam como POST com o cabeçalho.
- `SystemRegistrationForm` não aparece em `public_classes` e `user_register` vale `'0'`.
- O link de reset começa por `APP_PUBLIC_URL`; o mesmo JWT não troca a senha duas vezes; a 6ª tentativa em 15 min recebe a mensagem de limite.
- Venda com tutor, paciente ou atendimento de outra clínica é recusada sem linha nova em `sale`, `sale_item` ou `stock_movement`.
- Carrinho, `sale_item.unit_price_cents` e recibo mostram o preço de venda (R$ 35,00 no exemplo custo R$ 20 / venda R$ 35).
- Busca de `TDBUniqueSearch`/`TDBMultiSearch` com `operator`, `orderColumn`, `mask` ou `criteria` fora das listas de T-11 responde 403 sem consultar o banco; `AdiantiAutocompleteService` responde 403; a busca de tutor do PDV lista só tutores da própria clínica.
- SUITE completo com `Failed: 0` e nenhuma linha nova em relação a `baseline/php-lint.txt`; nenhum arquivo de `framework_hashes.php` alterado.

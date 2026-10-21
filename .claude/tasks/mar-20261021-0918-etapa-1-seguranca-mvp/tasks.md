# Tasks

Origem: `.docs/audits/2026-10-09-prontidao-mvp.md`, itens S1, S2, S3, S4 e F1 (Etapa 1 — Segurança). Caminhos relativos a `/var/www/html/centralvet`; comandos rodam dessa pasta.

Comandos usados em Teste RED e Validação:
- LINT = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -l <arquivo relativo a src/>` → `No syntax errors detected` (baseline `baseline/php-lint.txt`, 0 linhas: qualquer linha de erro é nova).
- SUITE = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E '<Classe>|Failed:'` (o runner imprime `  PASS  <Classe>::<método>` / `  FAIL  ...` e `Total: N, Passed: N, Failed: N, Skipped: N`; testes MySQL saem `SKIP` com o MySQL fora do ar).
- Mensagens novas ao usuário só pelas chaves de T-01 (`_t('<en>')`, pt-BR em `translations.json`); nenhuma task além de T-01 edita `translations.json` nem `UserMessage.php`.

| ID | Camada | Descrição | Dependências | Paralelizável | Complexidade | Agente | Status |
|---|---|---|---|---|---|---|---|
| T-01 | shared | Mensagens pt-BR da Etapa 1 em translations.json e mapeamentos em UserMessage | — | sim | simples | Platão | [x] |
| T-02 | backend | MutationRequestPolicy (POST + token para métodos que gravam) e inventário CSRF | — | sim | alta | Spock | [x] |
| T-03 | backend | Núcleo do reset de senha: PublicUrl, PasswordResetToken de uso único, PasswordResetRequest com limitador | — | sim | alta | Jaspion | [x] |
| T-04 | backend | S1: cadastro público legado desligado (config, menus públicos, botão do login) | — | sim | simples | Levi | [x] |
| T-05 | backend | S4 + F1 no SaleService: referências resolvidas no tenant e preço de venda obrigatório | T-01 | sim | alta | Arquimedes | [x] |
| T-06 | frontend | Guard central ligado em checkMultiSession, cookie cv_csrf e cabeçalho/POST no cv-shell.js | T-01, T-02, T-11 | sim | alta | Aang | [ ] |
| T-07 | backend | Formulários de reset usam o núcleo de T-03; APP_PUBLIC_URL no .env.example e no compose | T-01, T-03 | sim | média | Jaspion | [x] |
| T-08 | frontend | SaleForm: carrinho pelo preço de venda, recusa sem preço e wiring dos repositórios no SaleService | T-05 | sim | média | Arquimedes | [ ] |
| T-09 | docs | Runbooks: APP_PUBLIC_URL, limite de reset, proteção CSRF e hospedagem compartilhada | T-02, T-06, T-07 | sim | simples | Gandalf | [ ] |
| T-10 | qa | Integração MySQL: venda com referência de outra clínica não grava venda nem estoque | T-05, T-08 | não | média | Yoda | [ ] |
| T-11 | backend | S7: SearchParameterGuard para AdiantiMultiSearchService e AdiantiAutocompleteService | T-02 | sim | alta | Spock | [x] |

## Detalhamento

### T-01 — Mensagens pt-BR da Etapa 1 em translations.json e mapeamentos em UserMessage

**Camada:** shared
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Platão

**Arquivos prováveis**
- `src/app/config/translations.json`
- `src/app/Core/Presentation/UserMessage.php`
- `src/tests/Unit/SecurityMvpMessagesTest.php`

**Interface**
- Produz: entradas com as chaves `en` e `pt` em `translations.json` (en → pt exatos): `The request could not be verified. Reload the page and try again.` → `Não foi possível validar a requisição. Recarregue a página e tente novamente.`; `If the login is registered and active, you will receive an e-mail with the instructions to reset the password.` → `Se o login estiver cadastrado e ativo, você receberá um e-mail com as instruções para redefinir a senha.`; `Too many password reset attempts. Try again in %s seconds.` → `Muitas tentativas de redefinição de senha. Tente novamente em %s segundos.`; `This password reset link is invalid, expired or has already been used.` → `Este link de redefinição de senha é inválido, expirou ou já foi usado.`; `Product without sale price. Set the sale price in the product registration before selling.` → `Produto sem preço de venda. Informe o preço de venda no cadastro do produto antes de vender.`; `The patient does not belong to the selected tutor` → `O paciente não pertence ao tutor selecionado`; `The encounter does not belong to the selected patient and unit` → `O atendimento não pertence ao paciente e à unidade selecionados`; `Select the patient of the encounter` → `Selecione o paciente do atendimento`
- Produz: entradas em `UserMessage::STATIC` (mensagem de domínio → chave): `'Product without sale price. Set the sale price in the product registration before selling.' => 'Product without sale price. Set the sale price in the product registration before selling.'`, `'The patient does not belong to the selected tutor' => 'The patient does not belong to the selected tutor'`, `'The encounter does not belong to the selected patient and unit' => 'The encounter does not belong to the selected patient and unit'`, `'patient_id is required when encounter_id is informed' => 'Select the patient of the encounter'`
- Consome: nada

**Teste RED**
- `src/tests/Unit/SecurityMvpMessagesTest.php` — cada uma das 8 chaves en acima existe em `src/app/config/translations.json` com o pt exato da Interface, e `UserMessage::resolve()` devolve `['key' => <chave>, 'params' => []]` para as 4 mensagens de domínio; falha porque nenhuma entrada existe hoje (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'SecurityMvpMessagesTest|Failed:'`)

**Critério de aceite**
- `translations.json` continua JSON válido (lista de objetos com só `en` e `pt`) e contém as 8 entradas; o SUITE imprime `PASS  SecurityMvpMessagesTest::...` em todos os métodos, `PASS` em `UserMessageTest` e `CopilotTranslationsTest`, e `Failed: 0`.

**Validação**
- `python3 -c "import json;d=json.load(open('src/app/config/translations.json'));print(len(d), all(sorted(e)==['en','pt'] for e in d))"` (evidência: `1459 True` — 1451 entradas antes + 8)
- SUITE com `SecurityMvpMessagesTest|UserMessageTest|CopilotTranslationsTest` (evidência: só linhas `PASS` dessas classes e `Failed: 0`)
- LINT de `app/Core/Presentation/UserMessage.php` (evidência: `No syntax errors detected`)

### T-02 — MutationRequestPolicy (POST + token para métodos que gravam) e inventário CSRF

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Spock

**Arquivos prováveis**
- `src/app/Core/Security/MutationRequestPolicy.php`
- `src/tests/Unit/MutationRequestPolicyTest.php`
- `docs/security/csrf-inventario.md`

**Interface**
- Produz: `final class CentralVet\Security\MutationRequestPolicy` com as constantes `MutationRequestPolicy::SESSION_KEY = 'cv_shell_csrf'` (a mesma chave de `CvShellController::csrfToken()`), `MutationRequestPolicy::COOKIE_NAME = 'cv_csrf'`, `MutationRequestPolicy::HEADER_NAME = 'X-CSRF-Token'`, `MutationRequestPolicy::SERVER_HEADER_KEY = 'HTTP_X_CSRF_TOKEN'`, `MutationRequestPolicy::ALLOW = 'allow'`, `MutationRequestPolicy::DENY = 'deny'`, `MutationRequestPolicy::STRIP_METHOD = 'strip_method'`
- Produz: `MutationRequestPolicy::isReadOnly(string $class, string $method): bool` — `true` para método vazio (só construtor), nome em `READ_METHODS` (inclui `onContext`, `show`) ou começando por um de `READ_PREFIXES` (`onReload`, `onEdit`, `onLoad`, `onSearch`, `onView`, `onShow`, `onOpen`, `onClear`, `onChangeLimit`, `onExport`, `onGenerate`, `onPrint`, `onDisplay`), exceto `Classe::método` em `MUTATING_OVERRIDES` (inclui `AiCopilotPanel::onAsk` e todo método de leitura que o inventário mostrar gravando); `onClose` não é prefixo de leitura
- Produz: `MutationRequestPolicy::decide(string $entryScript, string $httpMethod, string $class, string $method, mixed $sentToken, mixed $sessionToken): string` — `ALLOW` se `isReadOnly` ou `Classe` em `EXEMPT_CLASSES` (inclui `AdiantiUploaderService`, `SystemDocumentUploaderService`) ou `Classe::método` em `EXEMPT_ACTIONS` (inclui `LoginForm::onLogout`); senão `STRIP_METHOD` quando `$entryScript === 'index.php'`; senão `ALLOW` só se `CsrfToken::isValid($httpMethod, $sentToken, $sessionToken)`, e `DENY` no resto
- Produz: `MutationRequestPolicy::decideFromRequest(array $server, array $request, mixed $sessionToken): string` — lê `basename($server['SCRIPT_NAME'] ?? '')`, `$server['REQUEST_METHOD'] ?? 'GET'`, `$server['HTTP_X_CSRF_TOKEN'] ?? null`, `$request['class']` e `$request['method']` (não-string vira `''`) e delega a `decide()`
- Produz: `docs/security/csrf-inventario.md` — inventário por categoria (formulários, grid/exclusão, pagamento, cancelamento, admin, copiloto IA, troca de unidade, perfil/2FA, endpoints fora do engine) e a justificativa de cada entrada de `READ_METHODS`, `READ_PREFIXES`, `MUTATING_OVERRIDES`, `EXEMPT_CLASSES` e `EXEMPT_ACTIONS`, incluindo o resultado do grep de navegações de topo para métodos que gravam (`__adianti_goto_page`, `location.href`, `window.location` e links `index.php?...&method=` em `src/app` e `src/*.xml`)
- Consome: nada (reusa `CentralVet\Security\CsrfToken::isValid` existente)

**Teste RED**
- `src/tests/Unit/MutationRequestPolicyTest.php` — casos que falham por a classe não existir: `engine.php` GET `PaymentForm::onSave` com token válido → `deny`; POST `SaleForm::onSave` sem token → `deny`; POST com token diferente do da sessão → `deny`; POST com token de sessão vazio → `deny`; POST `SaleForm::onSave` com token igual → `allow`; GET `PatientList::onReload` sem token → `allow`; GET `CvShellController::onContext` → `allow`; GET `AiCopilotPanel::onAsk` sem token → `deny`; `index.php` GET `SystemUserList::onDelete` → `strip_method`; `index.php` GET `PatientList::onReload` → `allow`; POST `AdiantiUploaderService::show` sem token → `allow`; `decideFromRequest` com `SCRIPT_NAME=/engine.php`, `REQUEST_METHOD=POST`, `HTTP_X_CSRF_TOKEN` igual ao da sessão e `method=Delete` → `allow` (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'MutationRequestPolicyTest|Failed:'`)

**Critério de aceite**
- O SUITE imprime `PASS  MutationRequestPolicyTest::...` para os 12 casos do Teste RED e os 2 do Review Focus (`LoginForm::onLogout` por `index.php` → `allow`; `AdiantiUploaderService` POST sem cabeçalho → `allow`), `PASS` em `CsrfTokenTest` e `Failed: 0`.
- `docs/security/csrf-inventario.md` tem uma seção por categoria do Produz e cita cada `Classe::método` de `MUTATING_OVERRIDES` e `EXEMPT_ACTIONS` com o motivo.

**Validação**
- SUITE com `MutationRequestPolicyTest|CsrfTokenTest` (evidência: 14+ linhas `PASS  MutationRequestPolicyTest::`, `PASS` em `CsrfTokenTest`, `Failed: 0`)
- LINT de `app/Core/Security/MutationRequestPolicy.php` (evidência: `No syntax errors detected`)
- `/usr/bin/grep -c -E '^## ' docs/security/csrf-inventario.md` (evidência: 9 ou mais seções) e `/usr/bin/grep -n 'AiCopilotPanel::onAsk\|LoginForm::onLogout' docs/security/csrf-inventario.md` (evidência: as duas entradas presentes)

### T-03 — Núcleo do reset de senha: PublicUrl, PasswordResetToken de uso único, PasswordResetRequest com limitador

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Jaspion

**Arquivos prováveis**
- `src/app/Core/Security/PublicUrl.php`
- `src/app/Core/Security/PasswordResetToken.php`
- `src/app/Core/Security/InvalidPasswordResetToken.php`
- `src/app/Core/Security/TooManyPasswordResetAttempts.php`
- `src/app/Core/Security/PasswordResetRequest.php`
- `src/app/Core/Security/LoginRateLimiter.php`
- `src/tests/Unit/PublicUrlTest.php`
- `src/tests/Unit/PasswordResetTokenTest.php`
- `src/tests/Unit/PasswordResetRequestTest.php`

**Interface**
- Produz: `CentralVet\Security\PublicUrl::fromEnvironment(): self` — lê `getenv('APP_PUBLIC_URL')`; vazio, sem esquema `http`/`https` ou sem host → `\RuntimeException('APP_PUBLIC_URL must be an absolute http(s) URL')`
- Produz: `new PublicUrl(string $baseUrl)` (mesma validação) e `PublicUrl::to(string $pathAndQuery): string` — `rtrim($baseUrl, '/') . '/' . ltrim($pathAndQuery, '/')`
- Produz: `new PasswordResetToken(string $key)`, `PasswordResetToken::TTL_SECONDS = 10800`, `PasswordResetToken::issue(string $login, string $passwordHash, int $now): string` — JWT HS256 com as claims `user`, `expires` (`$now + TTL_SECONDS`) e `pwd` (`hash_hmac('sha256', $passwordHash, $key)`)
- Produz: `PasswordResetToken::verify(string $jwt, int $now): array` — devolve `['user' => string, 'pwd' => string]`; assinatura inválida, JWT malformado, `expires < $now` ou claim `user`/`pwd` ausente → `CentralVet\Security\InvalidPasswordResetToken`
- Produz: `PasswordResetToken::matchesPassword(array $claims, string $currentPasswordHash): bool` — `hash_equals` entre `$claims['pwd']` e o HMAC do hash atual; depois que a senha muda, o mesmo JWT devolve `false` (uso único)
- Produz: `final class CentralVet\Security\TooManyPasswordResetAttempts extends \RuntimeException` com `public readonly int $secondsUntilAvailable` no construtor
- Produz: `LoginRateLimiter::fromEnvironment(string $prefix = 'centralvet:login-throttle:'): self` (chamada sem argumento mantém o comportamento do login)
- Produz: `PasswordResetRequest::PREFIX = 'centralvet:password-reset-throttle:'`
- Produz: `new PasswordResetRequest(LoginRateLimiter $limiter, PasswordResetToken $tokens, PublicUrl $url)` e `PasswordResetRequest::handle(string $login, string $ip, callable $findUser, callable $send, int $now): void` — identificadores `LoginRateLimiter::identifierFor($login, $ip)` (conta) e `LoginRateLimiter::identifierFor('*', $ip)` (IP); qualquer um em `tooManyAttempts` → `TooManyPasswordResetAttempts` com o maior `secondsUntilAvailable`, sem chamar `$findUser`; senão `hit` nos dois; `$findUser($login)` devolve `null` ou `array` com as chaves `login`, `name`, `email`, `active`, `password`; `null` ou `active !== 'Y'` → retorna sem chamar `$send`; senão `$send($email, $name, $url->to('index.php?class=SystemPasswordResetForm&method=onLoad&jwt=' . rawurlencode($jwt)))`
- Consome: nada (reusa `LoginRateLimiter` e `firebase/php-jwt` existentes)

**Teste RED**
- `src/tests/Unit/PublicUrlTest.php`, `src/tests/Unit/PasswordResetTokenTest.php`, `src/tests/Unit/PasswordResetRequestTest.php` — falham por as classes não existirem: `PublicUrl('https://app.centralvet.com.br/')->to('index.php?class=X')` → `https://app.centralvet.com.br/index.php?class=X`, `PublicUrl('')` e `PublicUrl('javascript:x')` lançam; token emitido com o hash A verifica e `matchesPassword` com A → `true`, com o hash B → `false`; `verify` com `$now` além de `expires` e com JWT de outra chave lança `InvalidPasswordResetToken`; `handle` com usuário ativo chama `$send` uma vez com link começando por `https://app.centralvet.com.br/index.php?class=SystemPasswordResetForm&method=onLoad&jwt=`; usuário inexistente e inativo não chamam `$send`; a 6ª chamada com o limitador em 5 tentativas (`new LoginRateLimiter(new FakeRedis(), 5, 900, PasswordResetRequest::PREFIX)`) lança `TooManyPasswordResetAttempts` sem chamar `$findUser` (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'PublicUrlTest|PasswordResetTokenTest|PasswordResetRequestTest|Failed:'`)

**Critério de aceite**
- O SUITE imprime `PASS` em todos os métodos de `PublicUrlTest`, `PasswordResetTokenTest` e `PasswordResetRequestTest` (inclusive os 2 do Review Focus: JWT reutilizado depois da troca de senha → `matchesPassword` `false`; login inexistente e existente produzem o mesmo retorno `void` e `$send` só no existente), `PASS` em `FileRateLimitBackendTest` e `Failed: 0`.

**Validação**
- SUITE com `PublicUrlTest|PasswordResetTokenTest|PasswordResetRequestTest|FileRateLimitBackendTest` (evidência: só linhas `PASS` e `Failed: 0`)
- LINT de cada arquivo `app/Core/Security/*.php` tocado (evidência: `No syntax errors detected` em todos)
- `/usr/bin/grep -rn 'LoginRateLimiter::fromEnvironment()' src/app/control/admin/LoginForm.php` (evidência: a chamada sem argumento continua lá — login inalterado)

### T-04 — S1: cadastro público legado desligado (config, menus públicos, botão do login)

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Levi

**Arquivos prováveis**
- `src/app/config/application.php`
- `src/menu-public.xml`
- `src/menu-bottom-public.xml`
- `src/app/control/admin/LoginForm.php`
- `src/tests/Unit/PublicRegistrationDisabledTest.php`

**Interface**
- Produz: `application.php` com `'SystemRegistrationForm'` fora de `permission.public_classes` e `'user_register' => '0'`
- Produz: `LoginForm` mostra o botão `Create account` (destino `landing.php#cart`, `onCreateAccount`) sem depender de `user_register`
- Consome: nada

**Teste RED**
- `src/tests/Unit/PublicRegistrationDisabledTest.php` — com `APP_SEED` de teste (32+ caracteres, restaurado no `tearDown`), `require 'app/config/application.php'` devolve `public_classes` sem `SystemRegistrationForm` e `user_register === '0'`; `src/menu-public.xml` e `src/menu-bottom-public.xml` não contêm `SystemRegistrationForm`; `LoginForm.php` contém `landing.php#cart` e não contém `user_register`; falha porque hoje a classe é pública com `user_register = '1'` (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'PublicRegistrationDisabledTest|Failed:'`)

**Critério de aceite**
- O SUITE imprime `PASS  PublicRegistrationDisabledTest::...` em todos os métodos e `Failed: 0`.
- `engine.php?class=SystemRegistrationForm&method=onSave` sem sessão cai no ramo `Permission denied` de `engine.php` (classe não pública) e, com sessão, `SystemRegistrationForm::onSave` lança `The user registration is disabled` antes de abrir transação (linha 93 inalterada).

**Validação**
- SUITE com `PublicRegistrationDisabledTest` (evidência: só `PASS` e `Failed: 0`)
- `curl -s 'http://localhost:8081/engine.php?class=SystemRegistrationForm&method=onSave&login=x&password=y' | /usr/bin/grep -o 'Permission denied\|Permissão negada'` com a stack no ar (evidência: uma linha `Permission denied` ou `Permissão negada`; só leitura — sem sessão nada é gravado)
- LINT de `app/config/application.php` e `app/control/admin/LoginForm.php` (evidência: `No syntax errors detected`)

### T-05 — S4 + F1 no SaleService: referências resolvidas no tenant e preço de venda obrigatório

**Camada:** backend
**Dependências:** T-01
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Arquimedes

**Arquivos prováveis**
- `src/app/Core/Application/SaleService.php`
- `src/app/Core/Domain/Product.php`
- `src/app/Core/Domain/Exception/ProductWithoutSalePriceException.php`
- `src/app/Core/Domain/SaleItem.php`
- `src/tests/Unit/SaleServiceTest.php`
- `src/tests/Unit/ProductSalePriceTest.php`

**Interface**
- Produz: `Product::requireSalePriceCents(): int` — devolve `salePriceCents()` quando não é `null` (0 é aceito); `null` → `CentralVet\Domain\Exception\ProductWithoutSalePriceException` (estende `\DomainException`) com a mensagem `Product without sale price. Set the sale price in the product registration before selling.`
- Produz: `SaleService::__construct(SaleRepositoryInterface $sales, SaleItemRepositoryInterface $saleItems, ProductService $products, StockService $stock, ProcedureCatalogService $procedures, AuthorizationPolicyInterface $authorization, TenantContext $context, TutorRepositoryInterface $tutors, PatientRepositoryInterface $patients, EncounterRepositoryInterface $encounters)` — três parâmetros novos no fim; `create()` mantém a assinatura
- Produz: em `create()`, depois da autorização da fase 1 e antes de resolver itens, gravar venda ou consumir estoque: tutor ausente no tenant → `CrossTenantReferenceException`; paciente informado ausente no tenant → `CrossTenantReferenceException`; `$patient->tutorId !== $tutorId` → `InvalidArgumentException('The patient does not belong to the selected tutor')`; `encounterId` com `patientId === null` → `InvalidArgumentException('patient_id is required when encounter_id is informed')`; atendimento ausente no tenant → `CrossTenantReferenceException`; `$encounter->patientId() !== $patientId` ou `$encounter->systemUnitId() !== $systemUnitId` → `InvalidArgumentException('The encounter does not belong to the selected patient and unit')`
- Produz: item de produto com `unitPriceCents = $product->requireSalePriceCents()` (nunca `unitCostCents()`); docblock de `SaleItem` atualizado
- Consome: T-01 `'The patient does not belong to the selected tutor' => 'The patient does not belong to the selected tutor'`, T-01 `'patient_id is required when encounter_id is informed' => 'Select the patient of the encounter'`, T-01 `'The encounter does not belong to the selected patient and unit' => 'The encounter does not belong to the selected patient and unit'`, T-01 `'Product without sale price. Set the sale price in the product registration before selling.' => 'Product without sale price. Set the sale price in the product registration before selling.'`

**Teste RED**
- `src/tests/Unit/SaleServiceTest.php`, `src/tests/Unit/ProductSalePriceTest.php` — `makeService()` passa `FakeTutorRepository`, `FakePatientRepository` e `FakeEncounterRepository` (ctor `(tenantId, ...seed)`); produto com custo 2000 e venda 3500 gera item `unitPriceCents` 3500 e total 3500; produto sem preço de venda lança `ProductWithoutSalePriceException`; tutor do tenant 2, paciente de outro tutor, atendimento de outro paciente, atendimento de outra unidade e atendimento sem paciente lançam a exceção da Interface; em todos os casos de recusa `FakeSaleRepository` e `FakeSaleItemRepository` ficam vazios e `FakeStockMovementRepository` não tem movimento; `Product::requireSalePriceCents()` com `null` lança e com 0 devolve 0; falham porque hoje o preço é o custo e as referências não são resolvidas (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'SaleServiceTest|ProductSalePriceTest|Failed:'`)

**Critério de aceite**
- O SUITE imprime `PASS` em todos os métodos de `SaleServiceTest` (os 2 existentes ajustados para preço de venda e os novos) e `ProductSalePriceTest`, e `Failed: 0`.
- `/usr/bin/grep -n 'unitCostCents' src/app/Core/Application/SaleService.php` não imprime nenhuma linha.

**Validação**
- SUITE com `SaleServiceTest|ProductSalePriceTest|StockServiceTest|ProductServiceTest` (evidência: só `PASS` e `Failed: 0`)
- `/usr/bin/grep -n 'unitCostCents\|requireSalePriceCents' src/app/Core/Application/SaleService.php` (evidência: só linhas com `requireSalePriceCents`)
- LINT de `app/Core/Application/SaleService.php`, `app/Core/Domain/Product.php` e `app/Core/Domain/Exception/ProductWithoutSalePriceException.php` (evidência: `No syntax errors detected`)

### T-06 — Guard central ligado em checkMultiSession, cookie cv_csrf e cabeçalho/POST no cv-shell.js

**Camada:** frontend
**Dependências:** T-01, T-02, T-11
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Aang

**Arquivos prováveis**
- `src/app/service/auth/ApplicationAuthenticationService.php`
- `src/app/templates/adminbs5/js/cv-shell.js`
- `src/tests/Unit/MutationGuardWiringTest.php`

**Interface**
- Produz: `ApplicationAuthenticationService::enforceMutationGuard(): void`, chamado em `checkMultiSession()` logo depois do `if (!TSession::getValue('logged')) return;` e antes da leitura de `concurrent_sessions` — garante o token em `TSession` (`CsrfToken::generate()` quando vazio), grava o cookie `cv_csrf` (`path=/`, `SameSite=Strict`, `Secure` sob HTTPS, sem `HttpOnly`) quando difere do token e aplica a decisão: `STRIP_METHOD` → `unset` de `method` em `$_REQUEST`, `$_GET` e `$_POST`; `DENY` → `ApplicationTranslator::setLanguage(TSession::getValue('user_language'), true)`, `http_response_code(403)`, `error_log('[csrf] denied <classe>::<método>')`, `new TMessage('error', _t('The request could not be verified. Reload the page and try again.'))` e `exit`
- Produz: em `enforceMutationGuard()`, antes da decisão de `MutationRequestPolicy`, `SearchParameterGuard::check((string) ($_REQUEST['class'] ?? ''), $_REQUEST, $tenantId)`, com `$tenantId` vindo de `TenantContext::fromAuthenticatedSession(new AdiantiSessionContextSource())->tenantId()` (`MissingTenantContext` → `null`). Um retorno diferente de `null` segue o mesmo caminho do `DENY` (403, `error_log('[search-guard] denied <classe>: <motivo>')`, mensagem de T-01, `exit`)
- Produz: `cv-shell.js` com `$.ajaxPrefilter` que, em toda requisição para `engine.php` do mesmo host, lê o cookie `cv_csrf`, envia o cabeçalho `X-CSRF-Token` e troca `GET` por `POST` (a query continua na URL); o `fetch` de `onSwitchUnit` também envia `X-CSRF-Token`
- Consome: T-02 `MutationRequestPolicy::decideFromRequest(array $server, array $request, mixed $sessionToken): string`, T-02 `MutationRequestPolicy::SESSION_KEY = 'cv_shell_csrf'`, T-02 `MutationRequestPolicy::COOKIE_NAME = 'cv_csrf'`, T-02 `MutationRequestPolicy::HEADER_NAME = 'X-CSRF-Token'`, T-02 `MutationRequestPolicy::STRIP_METHOD = 'strip_method'`, T-02 `MutationRequestPolicy::DENY = 'deny'`, T-11 `SearchParameterGuard::check(string $class, array $request, ?int $tenantId): ?string`, T-01 `The request could not be verified. Reload the page and try again.`

**Teste RED**
- `src/tests/Unit/MutationGuardWiringTest.php` — lendo o fonte: `ApplicationAuthenticationService.php` chama `enforceMutationGuard()` dentro de `checkMultiSession` entre o retorno de não logado e `concurrent_sessions`, usa `MutationRequestPolicy::decideFromRequest(`, `SearchParameterGuard::check(` e `http_response_code(403)`; `cv-shell.js` contém `ajaxPrefilter`, `X-CSRF-Token`, `cv_csrf` e o `fetch` de `SWITCH_URL` com `X-CSRF-Token`; falha porque nada disso existe hoje (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'MutationGuardWiringTest|Failed:'`)

**Critério de aceite**
- O SUITE imprime `PASS  MutationGuardWiringTest::...` em todos os métodos e `Failed: 0` (suíte inteira, porque o guard roda em toda requisição logada).
- `engine.php` e `index.php` têm o mesmo hash de `framework_hashes.php` (nenhum arquivo protegido alterado).

**Validação**
- SUITE completo (evidência: `PASS  MutationGuardWiringTest::` e `Failed: 0` na linha `Total:`)
- LINT de `app/service/auth/ApplicationAuthenticationService.php` (evidência: `No syntax errors detected`)
- `git diff --name-only task/fase-8b-pendencias -- src/engine.php src/index.php src/init.php src/lib/adianti src/app/templates/adminbs5/layout.html src/app/templates/adminbs5/iframe.html src/app/config/framework_hashes.php` (evidência: saída vazia)
- Teste de fumaça no navegador (validador, chrome-devtools; autorizado pelo desenvolvedor em 2026-10-21) logado em `http://localhost:8081`, só com dados de teste que o próprio validador cria com o prefixo `ZZ-CSRF-` e exclui no fim: salvar um tutor `ZZ-CSRF-<data>`, editar e excluí-lo pela grade (confirmação TQuestion), trocar de unidade e voltar, digitar 3 letras de um tutor existente na busca de tutor do `SaleForm` (evidência: a busca lista o tutor da própria clínica; na aba Network, cada requisição de `engine.php` com método que grava sai como `POST` com o cabeçalho `X-CSRF-Token` e responde 200; a mesma URL de exclusão colada na barra como `index.php?...&method=onDelete` abre a lista sem excluir; nenhum registro `ZZ-CSRF-` resta na lista ao final)
- `/usr/bin/grep -n 'cv-shell.js' src/app/templates/adminbs5/iframe.html src/app/templates/adminbs5/layout.html` (evidência: registrar no relatório se `iframe.html` carrega o script; se não carrega, mutações no modo `template=iframe` recebem 403 — Review Focus)

### T-07 — Formulários de reset usam o núcleo de T-03; APP_PUBLIC_URL no .env.example e no compose

**Camada:** backend
**Dependências:** T-01, T-03
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Jaspion

**Arquivos prováveis**
- `src/app/control/admin/SystemRequestPasswordResetForm.php`
- `src/app/control/admin/SystemPasswordResetForm.php`
- `.env.example`
- `docker-compose.yml`
- `src/tests/Unit/PasswordResetFormsWiringTest.php`

**Interface**
- Produz: `SystemRequestPasswordResetForm::onRequest` mantém as checagens de `reset_password` e `seed`, monta `new PasswordResetRequest(LoginRateLimiter::fromEnvironment(PasswordResetRequest::PREFIX), new PasswordResetToken(APPLICATION_NAME . $ini['general']['seed']), PublicUrl::fromEnvironment())`, busca o usuário por `SystemUser::newFromLogin` dentro de `TTransaction::open('permission')`, envia por `THtmlRenderer('app/resources/system/admin/reset_password.html')` + `MailService::send`; mostra sempre `TMessage('info', _t('If the login is registered and active, you will receive an e-mail with the instructions to reset the password.'))`, inclusive quando `PublicUrl`/`MailService` falham (logados com `error_log`); `TooManyPasswordResetAttempts` → `TMessage('error', _t('Too many password reset attempts. Try again in %s seconds.', $e->secondsUntilAvailable))`; sem `HTTP_REFERER`, `User not found` nem `Inactive user`
- Produz: `SystemPasswordResetForm::onReset` usa `PasswordResetToken::verify` e `matchesPassword` com o hash atual do usuário antes de gravar; falha → `TMessage('error', _t('This password reset link is invalid, expired or has already been used.'))`
- Produz: `APP_PUBLIC_URL=http://localhost:8081` comentado em `.env.example` e `APP_PUBLIC_URL: ${APP_PUBLIC_URL:-http://localhost:8081}` no `environment` do serviço `app` (e do `worker`, se ele recebe as mesmas variáveis) em `docker-compose.yml`
- Consome: T-03 `PublicUrl::fromEnvironment(): self`, T-03 `PasswordResetToken::verify(string $jwt, int $now): array`, T-03 `PasswordResetToken::matchesPassword(array $claims, string $currentPasswordHash): bool`, T-03 `LoginRateLimiter::fromEnvironment(string $prefix = 'centralvet:login-throttle:'): self`, T-03 `PasswordResetRequest::PREFIX = 'centralvet:password-reset-throttle:'`, T-03 `PasswordResetRequest::handle(string $login, string $ip, callable $findUser, callable $send, int $now): void`, T-03 `public readonly int $secondsUntilAvailable`, T-01 `If the login is registered and active, you will receive an e-mail with the instructions to reset the password.`, T-01 `Too many password reset attempts. Try again in %s seconds.`, T-01 `This password reset link is invalid, expired or has already been used.`

**Teste RED**
- `src/tests/Unit/PasswordResetFormsWiringTest.php` — lendo o fonte: `SystemRequestPasswordResetForm.php` não contém `HTTP_REFERER`, `'User not found'` nem `'Inactive user'` e contém `PasswordResetRequest(` e `PublicUrl::fromEnvironment()`; `SystemPasswordResetForm.php` contém `matchesPassword(` e não lê `$token['expires']`; `.env.example` contém `APP_PUBLIC_URL=`; `docker-compose.yml` contém `APP_PUBLIC_URL:`; falha porque os formulários ainda usam `HTTP_REFERER` e o JWT antigo (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro -v /var/www/html/centralvet/.env.example:/var/www/html/.env.example:ro -v /var/www/html/centralvet/docker-compose.yml:/var/www/html/docker-compose.yml:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'PasswordResetFormsWiringTest|Failed:'`)

**Critério de aceite**
- O SUITE (com as duas montagens extras do Teste RED) imprime `PASS  PasswordResetFormsWiringTest::...` em todos os métodos e `Failed: 0`.
- `/usr/bin/grep -n 'HTTP_REFERER' src/app/control/admin/SystemRequestPasswordResetForm.php` não imprime linha.

**Validação**
- Comando do Teste RED (evidência: só `PASS` e `Failed: 0`)
- LINT de `app/control/admin/SystemRequestPasswordResetForm.php` e `app/control/admin/SystemPasswordResetForm.php` (evidência: `No syntax errors detected`)
- `docker compose config 2>/dev/null | /usr/bin/grep -n 'APP_PUBLIC_URL'` (evidência: ao menos uma linha `APP_PUBLIC_URL: http://localhost:8081` ou o valor do `.env`)

### T-08 — SaleForm: carrinho pelo preço de venda, recusa sem preço e wiring dos repositórios no SaleService

**Camada:** frontend
**Dependências:** T-05
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Arquimedes

**Arquivos prováveis**
- `src/app/control/clinic/SaleForm.php`
- `src/tests/Unit/SaleFormPricingTest.php`

**Interface**
- Produz: linha de produto do carrinho com `'unitPriceCents' => $product->requireSalePriceCents()`; `ProductWithoutSalePriceException` ao adicionar → `TMessage('error', CvFormat::userError($e))` sem incluir o item; o recibo continua lendo o snapshot do carrinho (preço de venda)
- Produz: `buildSaleService()` passa `new \CentralVet\Persistence\TutorRepository($context, $connection)`, `new \CentralVet\Persistence\PatientRepository($context, $connection)` e `new \CentralVet\Persistence\EncounterRepository($context, $connection)` nos três últimos parâmetros (conferir o construtor real de cada repositório antes; divergência vai ao board)
- Consome: T-05 `Product::requireSalePriceCents(): int`, T-05 `TutorRepositoryInterface $tutors, PatientRepositoryInterface $patients, EncounterRepositoryInterface $encounters`

**Teste RED**
- `src/tests/Unit/SaleFormPricingTest.php` — lendo o fonte: `SaleForm.php` não contém `unitCostCents()`, contém `requireSalePriceCents()` e `ProductWithoutSalePriceException`, e o corpo de `buildSaleService` contém `TutorRepository(`, `PatientRepository(` e `EncounterRepository(`; falha porque o carrinho usa o custo (linha 396) (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'SaleFormPricingTest|Failed:'`)

**Critério de aceite**
- O SUITE imprime `PASS  SaleFormPricingTest::...` em todos os métodos e `Failed: 0`.
- `/usr/bin/grep -n 'unitCostCents' src/app/control/clinic/SaleForm.php` não imprime linha.
- Formulário sem rótulo com `text-align:right`/`center` novo (regra do `CLAUDE.md`): `git diff task/fase-8b-pendencias -- src/app/control/clinic/SaleForm.php | /usr/bin/grep -c 'text-align'` imprime `0`.

**Validação**
- SUITE com `SaleFormPricingTest|SaleServiceTest` (evidência: só `PASS` e `Failed: 0`)
- LINT de `app/control/clinic/SaleForm.php` (evidência: `No syntax errors detected`)
- `/usr/bin/grep -n 'unitCostCents\|requireSalePriceCents' src/app/control/clinic/SaleForm.php` (evidência: só `requireSalePriceCents`)
- Teste de fumaça no navegador (validador, autorizado pelo desenvolvedor em 2026-10-21), só com dados de teste que ele cria e exclui: produto `ZZ-F1-<data>` com custo R$ 20,00 e venda R$ 35,00 e outro `ZZ-F1-SEMPRECO-<data>` sem preço de venda; no PDV, adicionar os dois ao carrinho sem finalizar a venda (evidência: a linha do primeiro mostra R$ 35,00; o segundo exibe `Produto sem preço de venda. Informe o preço de venda no cadastro do produto antes de vender.` e não entra no carrinho; os dois produtos excluídos ao final)

### T-09 — Runbooks: APP_PUBLIC_URL, limite de reset, proteção CSRF e hospedagem compartilhada

**Camada:** docs
**Dependências:** T-02, T-06, T-07
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Gandalf

**Arquivos prováveis**
- `docs/runbooks/environments.md`
- `docs/runbooks/shared-hosting-mysql57.md`
- `docs/runbooks/README.md`

**Interface**
- Produz: `environments.md` com `APP_PUBLIC_URL` por ambiente (development, staging, production), o limite de reset (mesmas `LOGIN_RATE_LIMIT_*`, prefixo `centralvet:password-reset-throttle:`) e a proteção CSRF (cookie `cv_csrf`, cabeçalho `X-CSRF-Token`, 403 com a mensagem de T-01); `shared-hosting-mysql57.md` com `putenv('APP_PUBLIC_URL=https://<domínio>')` no `hosting-environment.php`; `README.md` dos runbooks aponta para `docs/security/csrf-inventario.md`
- Consome: T-07 `APP_PUBLIC_URL=http://localhost:8081`, T-06 `X-CSRF-Token`, T-02 `docs/security/csrf-inventario.md`

**Teste RED**
- sem teste: documentação

**Critério de aceite**
- `/usr/bin/grep -c 'APP_PUBLIC_URL' docs/runbooks/environments.md docs/runbooks/shared-hosting-mysql57.md` imprime contagem ≥ 1 em cada arquivo; `/usr/bin/grep -n 'csrf-inventario.md' docs/runbooks/README.md` imprime uma linha; `/usr/bin/grep -n 'X-CSRF-Token' docs/runbooks/environments.md` imprime uma linha.

**Validação**
- Os três `grep` do critério (evidência: contagens e linhas citadas)

### T-10 — Integração MySQL: venda com referência de outra clínica não grava venda nem estoque

**Camada:** qa
**Dependências:** T-05, T-08
**Paralelizável:** não
**Complexidade:** média
**Agente:** Yoda

**Arquivos prováveis**
- `src/tests/Integration/SaleServiceTenantReferencesIntegrationTest.php`

**Interface**
- Produz: nada (teste de regressão; estende `CentralVet\Tests\Support\MysqlIntegrationTestCase`, banco `centralvet_test` do runner, rollback no fim)
- Consome: T-05 `TutorRepositoryInterface $tutors, PatientRepositoryInterface $patients, EncounterRepositoryInterface $encounters`, T-05 `Product::requireSalePriceCents(): int`

**Teste RED**
- sem teste: teste de regressão de integração sobre comportamento já entregue por T-05 e T-08 nesta branch; não há implementação nesta task para o teste preceder

**Critério de aceite**
- Com tenants A e B semeados no `centralvet_test`, `SaleService` montado com os repositórios reais (`TutorRepository`, `PatientRepository`, `EncounterRepository`, `SaleRepository`, `SaleItemRepository`, `StockBatchRepository`, `StockMovementRepository`) lança `CrossTenantReferenceException` para tutor, paciente e atendimento de B, e `SELECT COUNT(*)` de `sale`, `sale_item` e `stock_movement` fica igual ao valor antes da chamada; a venda válida em A com produto custo 2000 / venda 3500 grava `sale_item.unit_price_cents = 3500`; o SUITE imprime `PASS  SaleServiceTenantReferencesIntegrationTest::...` (não `SKIP`) e `Failed: 0`.

**Validação**
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'SaleServiceTenantReferencesIntegrationTest|MysqlIsolationGuardIntegrationTest|Failed:'` com MySQL no ar e `centralvet_test` provisionado (evidência: `PASS` sem `SKIP` nessas classes e `Failed: 0`; o `MysqlIsolationGuardIntegrationTest` prova que nenhuma linha ficou no banco)
- LINT de `tests/Integration/SaleServiceTenantReferencesIntegrationTest.php` (evidência: `No syntax errors detected`)

### T-11 — S7: SearchParameterGuard para AdiantiMultiSearchService e AdiantiAutocompleteService

**Camada:** backend
**Dependências:** T-02
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Spock

**Achado (S7, relatado na execução de T-02):** `src/lib/adianti/service/AdiantiAutocompleteService.php:41-61` e `src/lib/adianti/service/AdiantiMultiSearchService.php:45-117` (framework protegido por hash) usam `operator`, `operator_idsearch`, `orderColumn`, `mask` e `criteria` vindos da requisição, fora do hash de parâmetros. Os dois primeiros e o `orderColumn` vão direto ao SQL (`TFilter`, `setProperty('order')`), o que permite injeção de SQL a qualquer usuário logado. Verificações na exploração do plano: o hash do autocomplete usa o seed fixo `'s8dkld83kf73kf094'` (`AdiantiAutocompleteService.php:32`, `TDBEntry.php:126`), então dá para forjá-lo para qualquer `database`/`column`/`model`, e o app não usa `TDBEntry` (0 usos em `src/app`). `criteria` é desserializado do cliente, então quem o omite ou troca deixa de ter o filtro `tenant_id` dos `TDBUniqueSearch` do app (leitura de outra clínica). `mask` renderiza qualquer atributo do model, por exemplo `{password}` de `SystemUser`, cujo hash é legítimo em `SystemMessageForm.php:40` e `AppointmentForm.php:81`.
**Reprodução exigida:** o Teste RED abaixo com os valores perigosos. Nenhuma requisição contra o banco de dev para provar a injeção.
**Veredito:** pendente (revisão de T-11).

**Arquivos prováveis**
- `src/app/Core/Security/SearchParameterGuard.php`
- `src/tests/Unit/SearchParameterGuardTest.php`
- `src/tests/Support/Fixtures/multisearch-tenant-7-criteria.txt`

**Interface**
- Produz: `final class CentralVet\Security\SearchParameterGuard` com `SearchParameterGuard::TENANT_MODELS = ['Tutor', 'Patient', 'Product', 'ProcedureCatalogItem']`, `SearchParameterGuard::GLOBAL_MODELS = ['SystemUser', 'SystemProgram']`, `SearchParameterGuard::OPERATORS = ['', 'like', 'ilike', '=']` e `SearchParameterGuard::ID_OPERATORS = ['', '=']`
- Produz: `SearchParameterGuard::check(string $class, array $request, ?int $tenantId): ?string`. Devolve `null` quando a requisição é permitida e o motivo (texto para log, sem eco do valor recebido) quando é negada. Regras:
  - `AdiantiAutocompleteService` é sempre negado.
  - Classe diferente de `AdiantiMultiSearchService` devolve `null`.
  - Para `AdiantiMultiSearchService`:
    - `database === 'permission'`.
    - `model` em `TENANT_MODELS` ∪ `GLOBAL_MODELS`.
    - `strtolower(operator)` em `OPERATORS` e `operator_idsearch` em `ID_OPERATORS`.
    - `key` e `orderColumn` vazios ou casando `/^[A-Za-z_][A-Za-z0-9_]*$/`; `column` é uma lista desses identificadores separada por vírgula.
    - `mask` casa `/^(<span>)?\{([A-Za-z_][A-Za-z0-9_]*)\}(<\/span>)?$/`, com `<span>` nas duas pontas ou em nenhuma, e o nome capturado é uma coluna de `column` ou `<coluna>_safe`.
    - `idsearch`, `idtextsearch`, `onlyidsearch` e `jsonvalue` vazios, `'0'` ou `'1'`; `minlength` vazio ou só dígitos.
    - Model de `GLOBAL_MODELS` exige `criteria` vazio.
    - Model de `TENANT_MODELS` exige `$tenantId !== null` e um `criteria` que, decodificado como em `TDBMultiSearch.php:343` (`-`→`+`, `_`→`/`, `base64_decode`) e lido com `unserialize(..., ['allowed_classes' => false])`, tem exatamente uma expressão: um `TFilter` com variável `tenant_id`, operador `=` e valor igual a `$tenantId` (int ou string numérica).
- Consome: nada (puro, sem classes do Adianti; o `criteria` é lido como `__PHP_Incomplete_Class`)

**Teste RED**
- `src/tests/Unit/SearchParameterGuardTest.php`, `src/tests/Support/Fixtures/multisearch-tenant-7-criteria.txt` — o teste falha porque a classe ainda não existe. Fixture: o `criteria` real de `new TCriteria` com `TFilter('tenant_id', '=', 7)`, serializado no container com o Adianti carregado e o mesmo transform de `TDBMultiSearch.php:343`; o comando de geração fica no relatório. O pedido legítimo da busca de tutor do `SaleForm` deve dar `null`: `model=Tutor`, `key=id`, `column=full_name`, `orderColumn=full_name`, `operator=` vazio, `mask=<span>{full_name_safe}</span>`, `criteria` = fixture e `$tenantId = 7`. Devem ser negados: `operator` = `= 1 OR 1=1 --`; `operator_idsearch` = `IN (SELECT 1)`; `orderColumn` = `(SELECT password FROM system_users LIMIT 1)`; `mask` = `{password}` com `model=SystemUser`, `column=name`; `Tutor` sem `criteria`; `Tutor` com a fixture e `$tenantId = 8`; `Tutor` com `$tenantId = null`; `model=SystemUnit`; `AdiantiAutocompleteService` com qualquer parâmetro. (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'SearchParameterGuardTest|Failed:'`)

**Critério de aceite**
- O SUITE imprime `PASS  SearchParameterGuardTest::...` para o caso legítimo, para os 9 negados e para um caso legítimo de `SystemUser`: `column=name`, `mask=<span>{name_safe}</span>`, `criteria` vazio. Imprime também `Failed: 0`.
- `git diff --name-only task/fase-8b-pendencias -- src/lib/adianti src/app/config/framework_hashes.php` imprime saída vazia.

**Validação**
- SUITE com `SearchParameterGuardTest` (evidência: só linhas `PASS` dessa classe e `Failed: 0`)
- LINT de `app/Core/Security/SearchParameterGuard.php` (evidência: `No syntax errors detected`)
- `/usr/bin/grep -rn "new TDBUniqueSearch\|new TDBMultiSearch\|new TDBEntry" src/app` (evidência: todo `model` listado está em `TENANT_MODELS` ou `GLOBAL_MODELS`; os de tenant passam `$tenant_criteria`; nenhum `TDBEntry`)

## Legenda

- [ ] pendente
- [~] em andamento
- [x] concluído com evidência
- [!] bloqueado

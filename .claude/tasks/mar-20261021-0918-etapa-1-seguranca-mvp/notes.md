# Notas de execução

## Decisões tomadas
- 2026-10-21 · plano · onda — — Branch base `task/fase-8b-pendencias` (HEAD `aba0e82`) por pedido explícito do desenvolvedor na Fase 1; `branches.py` aponta `main` como produção, com fonte `padrão` (o projeto não declara branches de produção/teste).
- 2026-10-21 · plano · onda — — Entrega preserva os commits por onda (premissa do desenvolvedor): a Fase 5 não roda o desfazer do `entrega.py`.
- 2026-10-21 · T-02/T-06 · onda 1–2 — Mecanismo central em vez de remendo por formulário: `engine.php`, `index.php`, `init.php`, `src/lib/adianti/**` e os HTML/CSS do tema (`layout.html`, `iframe.html`, `custom.css`…) estão em `framework_hashes.php` e não são editados (ADR 0006). O guard entra em `ApplicationAuthenticationService::checkMultiSession()`, que as duas entradas chamam antes do despacho; `cv-shell.js` não tem hash e recebe o `$.ajaxPrefilter`.
- 2026-10-21 · T-02 · onda 1 — Política por allowlist de leitura: o que não é leitura exige POST + token. Quem errar uma leitura como escrita só passa a exigir POST (a UI já envia POST com token); o contrário abriria CSRF. Por isso `onClose` não é prefixo de leitura (pode fechar caixa) e cada método que casa com um prefixo de leitura é conferido no inventário.
- 2026-10-21 · T-06 · onda 2 — Token em cookie `cv_csrf` (`SameSite=Strict`, sem `HttpOnly`) com o mesmo valor de `TSession['cv_shell_csrf']`, para leitura síncrona no prefilter; validação continua contra a sessão (cookie sozinho não autoriza). Origem cruzada não lê o cookie nem envia `X-CSRF-Token` sem preflight CORS.
- 2026-10-21 · T-03 · onda 1 — S3 sem migration: a claim `pwd` (HMAC do hash atual da senha) torna o JWT de uso único, porque gravar a senha nova muda o hash. Alternativa descartada: tabela de tokens com `used_at` (migration, variante MySQL 5.7 com prefixo `20-` e atualização de `scripts/test-db/provision.sh`). Expiração de 3 h mantida.
- 2026-10-21 · T-03/T-07 · onda 1–2 — Resposta uniforme também quando `APP_PUBLIC_URL` falta ou o SMTP falha (erro só no `error_log`); o limite é a única resposta diferente, pois não revela se o login existe.
- 2026-10-21 · T-04 · onda 1 — Desligar `user_register` esconderia o botão "Criar conta" do login, que hoje só leva à landing (commit 76797d1); o botão passa a não depender de `user_register`.
- 2026-10-21 · T-05 · onda 2 — F1: `sale_price_cents` `NULL` (campo em branco no `ProductForm`) é recusado; 0 informado explicitamente é aceito como preço (brinde). Sem fallback para custo.
- 2026-10-21 · T-05 · onda 2 — S4: incoerência dentro do tenant (paciente de outro tutor, atendimento de outro paciente/unidade, atendimento sem paciente) lança `InvalidArgumentException` com mensagem mapeada em `UserMessage`; referência de outro tenant lança `CrossTenantReferenceException` ("The selected record does not belong to this clinic").

- 2026-10-21 · T-01 · onda 1 — Edição de src/tests/Unit/UserMessageTest.php (456f743) fora de Arquivos prováveis aceita: necessária para o critério de aceite.
- 2026-10-21 · T-02 · onda 1 — Achado externo (revisão automática de segurança) de caixa de classe/método aceito como bloqueante; a política resolve nomes canônicos via Reflection e nega divergência de caixa.
- 2026-10-21 · T-02 · onda 1 — (plano-mandou) A política avalia classe/método da mesma fonte que o dispatch executa e nega divergência query×corpo.
- 2026-10-21 · T-02 · onda 1 — SystemSQLPanel::onGenerate e ::onExportCSV tratados como mutação; SystemDatabaseExplorer::onExportCSV/SQL ficam leitura (SQL de metadados, justificado no inventário).
- 2026-10-21 · T-03 · onda 1 — (plano-mandou) Limite duplo por conta (qualquer IP) e por IP (qualquer conta); um LoginRateLimiter com identificadores `account:`/`ip:`.
- 2026-10-21 · plano · onda 1 — Desenvolvedor decidiu: injeção de SQL em AdiantiAutocompleteService/AdiantiMultiSearchService (achada pela T-02) entra como T-11 (S7), onda 2; T-06 vai para a onda 3 e T-09 para a onda 4.
- 2026-10-21 · plano · onda — — Respostas do desenvolvedor: (1) revogação de sessões após reset fica para depois, sem migration nesta etapa; (2) o validador pode fazer teste de fumaça no navegador gravando no banco de dev, só com dados de teste que ele cria (prefixos `ZZ-CSRF-` e `ZZ-F1-`) e exclui no fim — passos em T-06 e T-08, Validação; nenhuma outra gravação no banco de dev; (3) `main` é a branch de produção para a PR futura.

- 2026-10-21 · T-11 · onda 2 — S7 incluído por decisão do desenvolvedor, a partir do achado relatado pelo implementador de T-02. O pré-filtro fica em classe própria (`SearchParameterGuard`, T-11). Só T-06 edita `ApplicationAuthenticationService.php` e o invoca, por isso T-06 passou para a Onda 3 e T-09 para a Onda 4. O escopo cresceu em relação ao relato: o plano também cobre `mask`, `criteria` (filtro `tenant_id` forjável) e o hash do autocomplete com seed fixo, verificados no fonte durante a replanejamento.
- 2026-10-21 · T-07 · onda 2 — PasswordResetFormsWiringTest exige os mounts de .env.example e docker-compose.yml; o comando SUITE do gate/cruzada/revisão final passa a incluí-los (sem eles falha por ambiente).
- 2026-10-21 · T-07 · onda 2 — .env.example com APP_PUBLIC_URL ativo e comentado (desvio de forma) aceito.
- 2026-10-21 · T-07 · onda 2 — Risco de timing do SMTP síncrono fica como sugestão/pendência (mensagem uniforme + limite duplo).
- 2026-10-21 · T-11 · onda 2 — Fixture extra multisearch-tenant-7-active-criteria.txt no commit RED 0653fa5 aceita (RED inválido/escopo por ruling; caso real do StockBatchForm).
- 2026-10-21 · T-11 · onda 2 — Filtros extras `campo = inteiro` unidos por AND após tenant_id aceitos (OR/aninhamento/não-identificador são negados).
- 2026-10-21 · T-11 · onda 2 — criteria só aceito em forma canônica (serialize(obj) === raw) com chaves privadas exatas.
- 2026-10-21 · T-06 · onda 3 — A busca de tutor (AdiantiMultiSearchService, class_alias do framework) é aceita por um resolvedor passado pelo guard que só aceita o alias com nome idêntico; caixa e query×corpo divergentes seguem negados. Levar a regra do alias para a MutationRequestPolicy (T-02) fica como pendência.
- 2026-10-21 · T-06 · onda 3 — cv_csrf retirado dos parâmetros de dispatch (request_order vazio → $_REQUEST inclui cookies e o Adianti os copia para URLs).
- 2026-10-21 · T-06 · onda 3 — template=iframe não carrega cv-shell.js; nenhuma tela usa hoje (validador); risco latente, pendência.
- 2026-10-21 · T-06 · onda 3 — Usuário autorizou rebuild/up do app local e dados de teste ZZ- no banco de dev (todos excluídos, SELECT confirmou 0 linhas ZZ-%) e buscar credenciais nos arquivos: sessão de dev (admin, unidade 1) aberta por curl com CENTRALVET_ADMIN_PASSWORD do .env sem imprimir; validador recebeu só o cookie-jar; senha não resetada.
## Bloqueios
- nenhum

## Descobertas
- `SystemMessageFormView` marca a mensagem como lida no construtor (gravação em GET, fora do alcance do guard por método); não entra nesta etapa.
- `AiCopilotPanel::onAsk` grava (pergunta à IA e registra a interação) apesar do nome de leitura; vai em `MUTATING_OVERRIDES`.
- O recibo do PDV (`SaleForm::renderReceiptHtml`) lê o snapshot do carrinho guardado na sessão, não a venda gravada; com o carrinho pelo preço de venda, recibo e venda coincidem.
- `SaleServiceTest:152` hoje afirma total igual ao custo (`1500 * 2`); T-05 o ajusta.
- T-02 usou MUTATING_OVERRIDES/EXEMPT_ACTIONS além do plano e SystemDataBrowser::onLoad em aba nova (index.php) cai em strip_method, janela sem dados; o teste de fumaça de T-06 inclui abrir tabela + exportar CSV.
- AdiantiAutocompleteService/AdiantiMultiSearchService::onSearch concatenam operator e orderColumn no SQL (injeção para usuário logado); tratado em T-11.
- O container :8081 serve o código da imagem; mudanças só aparecem após rebuild da imagem app (relevante para os testes de fumaça de T-06/T-08).
- T-05: SaleService::__construct ganhou TutorRepositoryInterface, PatientRepositoryInterface e EncounterRepositoryInterface no fim; produto sem sale_price_cents lança ProductWithoutSalePriceException.
- T-07: APP_PUBLIC_URL entrou no environment compartilhado (&app-environment) do compose (app e worker); .env.example traz a linha ativa.
- T-11: SearchParameterGuard::check(string $class, array $request, ?int $tenantId): ?string (null = permite); aceita filtros extras AND `campo = inteiro` após tenant_id e nega criteria com properties não vazias.
- T-06 (b76086b): checkMultiSession() chama enforceMutationGuard(); resolveDispatchTarget aceita class_alias só com nome curto exato; withoutCsrfCookie tira cv_csrf de $_REQUEST; cv-shell.js converte $.ajax para engine.php em POST com X-CSRF-Token.
- T-06: hashes de engine.php/index.php já divergiam de framework_hashes.php desde 9efef4e (anterior à branch).

## Pendências
- Revogação de sessões após reset: com `concurrent_sessions = '1'` e o driver de sessão em arquivos não há como derrubar as sessões de um usuário; com Redis, `SessionRegistry` guarda só a sessão corrente para o bloqueio de sessão concorrente. Revogar exige um marcador por usuário (ex.: `system_users.password_changed_at` comparado a cada requisição no mesmo `checkMultiSession`), com migration. Decisão do desenvolvedor (2026-10-21): fica para depois da Etapa 1.
- T-04: sem teste de fonte que proteja a checagem `user_register !== '1'` em SystemRegistrationForm::onSave (src/app/control/admin/SystemRegistrationForm.php:93).
- T-02: incluir testes para os overrides/isenções acrescentados (CashSessionForm::onOpen, SystemMessageFormView::onView, SaleForm::onClearCart, LoginForm::reloadPermissions) e para o resolveCanonical real (Reflection).
- T-02: SystemDatabaseExplorer::onExport* por GET sem token ainda gera o dump em tmp/ (consumo de disco/CPU); avaliar no teste de fumaça de T-06.
- T-03: timing do reset revela se o login existe (envio SMTP síncrono só no usuário ativo); tratar em T-07 (envio adiado) ou registrar o risco.
- T-03: check-then-hit não atômico em PasswordResetRequest (usar o retorno de hit()); PublicUrl aceita base com query/fragmento; teste de limite não afere o maior dos dois identificadores.
- T-05: sem teste que prove a fase 1b (referências) rodando depois da autorização (AuthorizationDenied antes de CrossTenantReferenceException); reflow do docblock em SaleService.php:78.
- T-07: SMTP síncrono distingue login ativo de inexistente por timing; enfileirar o e-mail no worker em etapa seguinte. PublicUrl::fromEnvironment() lançando antes de handle() não registra a tentativa no limitador.
- T-11: faltam testes dos ramos negativos do AND extra (OR, valor não inteiro, tenant_id repetido, TCriteria aninhado, properties com order/group, variável não identificador); IDENTIFIER/MASK terminam em `$` (aceita \n final; usar `\z` ou modificador D); normalizar ltrim($class, '\\') em SearchParameterGuard.php:57-61.
- T-11: T-06 precisa invocar o guard para AdiantiMultiSearchService/AdiantiAutocompleteService.
- T-06/geral: PHPSESSID vaza para URLs pelo mesmo mecanismo de request_order (anterior à branch); correção natural request_order="GP" no php.ini — levar ao usuário na entrega.
- T-06: levar a regra do class_alias para MutationRequestPolicy::resolveCanonical (T-02); template=iframe sem cv-shell.js (risco latente, cobrir quando alguma tela usar abas).
- T-06: sugestões da revisão: cv_csrf antigo ainda chega a $param em requisição não logada (ApplicationAuthenticationService.php:268-271); isHttps confia em X-Forwarded-Proto (derivar Secure de APP_PUBLIC_URL); error_log de negação sem sanitizar class/method (linhas 286,305); fixture do RED alterada no GREEN (registro de processo).
- T-08: sugestões da revisão: teste de wiring não fixa a ordem tutors/patients/encounters (SaleFormPricingTest.php:39-46); teste do catch específico não verifica a ordem antes de catch (Exception) (:28-37); recibo lê o snapshot do carrinho e pode divergir do sale_item com rascunho antigo ou preço alterado (SaleForm.php:685-701).

## Riscos
- S7: o pré-filtro pode negar uma busca legítima que use parâmetros fora das listas (por exemplo, `setOperator` novo ou máscara composta). Mitigação: o Teste RED de T-11 cobre a busca real do `SaleForm` e de `SystemUser`, a Validação de T-11 confere os 11 usos e o teste de fumaça de T-06 inclui a busca de tutor. Uma sessão sem tenant resolvido (`MissingTenantContext`) passa a ter as buscas de models de tenant negadas.
- `SystemUser` continua pesquisável entre clínicas pelo nome (`SystemMessageForm`, `AppointmentForm`), pois esse uso não passa `criteria`. Fica fora desta etapa e é candidato à revisão final.
- Método que grava classificado como leitura → CSRF por link de topo. Mitigação: inventário de T-02 confere cada método que casa com prefixo de leitura; revisão de T-02 em `inherit`.
- Modo `template=iframe`: `iframe.html` (protegido) pode não carregar `cv-shell.js`; mutações ali passam a receber 403. Mitigação: T-06 registra; corrigir exigiria script fora do tema ou exceção documentada.
- Requisições fora do jQuery (`fetch`, `XMLHttpRequest`) sem o cabeçalho recebem 403. Mitigação: T-06 atualiza o `fetch` de `cv-shell.js`; uploader isento; o inventário de T-02 procura outros `fetch`/`XMLHttpRequest` em `src/app`.
- Limite de reset por IP (5 em 15 min, mesmas `LOGIN_RATE_LIMIT_*`) pode atingir clínicas atrás do mesmo NAT. Mitigação: variáveis documentadas em T-09.
- T-10 depende do MySQL no ar e do `centralvet_test` provisionado; sem eles os testes saem `SKIP` (falha de ambiente, não da task).

## Retomada
- Pasta: `.claude/tasks/mar-20261021-0918-etapa-1-seguranca-mvp/`
- Sessões: fc92cc27-9f66-4ad7-bfe3-efed43d88248
- Branch de trabalho: task/etapa-1-seguranca-mvp (base: task/fase-8b-pendencias)
- BASE da onda 1: aba0e82
- Commits por onda:
  - Onda 1: BASE aba0e82 → HEAD 8dd9f54 (8dd9f54 cca1efc 6454f50 d2ae72f 82fba32 1cbe9a4 dd6049d f9094ab 0deda83 9a8b170 456f743 bdb8eac aa40daf 1c0a05e 9cf70ae)
  - Onda 2: BASE 24876ac → HEAD 7bd0e6c (7bd0e6c 069eb63 9411ffb 75d4646 072b81e d3ad5b5 0653fa5 19015f3)
  - Onda 3: BASE 1983b78 → HEAD b76086b (b76086b e04aff9 32e7c0c fc0edc2 d809b58 b00ff4c)
- Último status conhecido: onda 3 concluída (T-06, T-08 [x])
- Próxima onda recomendada: onda 4 (T-09, T-10)

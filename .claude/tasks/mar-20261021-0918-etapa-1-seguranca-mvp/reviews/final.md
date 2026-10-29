# Revisão final
Branch task/etapa-1-seguranca-mvp contra task/fase-8b-pendencias (merge-base aba0e82). Revisão encerrada por limite de turnos do orquestrador; o que não foi verificado está em "Não verificado".

## Triagem
- [aberta] Revogação de sessões após reset → adiada pelo desenvolvedor (Excluído em plan.md); nada no diff
- [aberta] T-04: sem teste de fonte para `user_register !== '1'` em SystemRegistrationForm::onSave → src/app/control/admin/SystemRegistrationForm.php:93 (não tocado)
- [aberta] T-02: testes dos overrides/isenções e do resolveCanonical real → não conferido no MutationRequestPolicyTest nesta rodada
- [aberta] T-02: SystemDatabaseExplorer::onExport* por GET gera dump em tmp/ → MutationRequestPolicy.php:50 (`onExport` é prefixo de leitura; só SystemTableList/SystemSQLPanel em MUTATING_OVERRIDES)
- [aberta] T-03/T-07: timing do SMTP síncrono distingue login ativo → SystemRequestPasswordResetForm.php:128 (MailService::send dentro do request)
- [aberta] T-03: check-then-hit não atômico; PublicUrl aceita query/fragmento; teste de limite → não reavaliado
- [aberta] T-05: teste da fase 1b depois da autorização; reflow do docblock → SaleService.php:75-78 (linha longa continua)
- [aberta] T-07: PublicUrl::fromEnvironment() lançando antes de handle() não registra tentativa → SystemRequestPasswordResetForm.php:92 (construído antes de handle; Throwable só vai ao error_log)
- [aberta] T-11: ramos negativos do AND extra sem teste → não conferido; `/D` em IDENTIFIER/MASK e ltrim da classe já resolvidos (SearchParameterGuard.php:31-32,44)
- [resolvida] T-11: T-06 precisa invocar o guard → ApplicationAuthenticationService.php: enforceMutationGuard() chama SearchParameterGuard::check antes da política, só em sessão logada
- [aberta] PHPSESSID vaza para URLs via request_order vazio (anterior à branch) → levar ao usuário (request_order="GP")
- [aberta] Regra do class_alias a levar para MutationRequestPolicy::resolveCanonical → hoje só em ApplicationAuthenticationService::resolveDispatchTarget
- [aberta] template=iframe sem cv-shell.js → mutações recebem 403 (falha fechada); nenhuma tela usa hoje
- [aberta] T-06: cv_csrf em requisição não logada, isHttps confia em X-Forwarded-Proto, error_log sem sanitizar → ApplicationAuthenticationService.php:355,368
- [aberta] T-08: ordem do wiring e do catch não fixada no teste; recibo lê o snapshot do carrinho → SaleFormPricingTest.php:28-46, SaleForm.php:685-701
- [aberta] T-10: afirmar stock_batch.quantity; mensagem da CrossTenantReferenceException → SaleServiceTenantReferencesIntegrationTest.php:140,148
- [aberta] Hashes de engine.php/index.php divergentes desde 9efef4e (anterior) → nenhum arquivo de framework_hashes.php no diff --stat da branch
- [aberta] S5/S6/F2–F5 → fora do escopo (Excluído)

## Rulings
- plano · onda — — Branch base task/fase-8b-pendencias (aba0e82) por pedido do desenvolvedor; main como produção
- plano · onda — — Entrega preserva os commits por onda
- T-02/T-06 · onda 1–2 — Guard central em checkMultiSession(), sem editar arquivos com hash (ADR 0006); prefilter no cv-shell.js
- T-02 · onda 1 — Allowlist de leitura; onClose fora dos prefixos de leitura
- T-06 · onda 2 — Token em cookie cv_csrf SameSite=Strict, validação contra a sessão
- T-03 · onda 1 — Uso único do JWT pela claim pwd, sem migration; expiração de 3 h
- T-03/T-07 · onda 1–2 — Resposta uniforme também com APP_PUBLIC_URL ausente ou SMTP falhando
- T-04 · onda 1 — Botão "Criar conta" independente de user_register
- T-05 · onda 2 — F1: sale_price_cents NULL recusado, 0 aceito, sem fallback para custo
- T-05 · onda 2 — S4: incoerência no tenant → InvalidArgumentException; outro tenant → CrossTenantReferenceException
- T-01 · onda 1 — UserMessageTest.php (456f743) fora de Arquivos prováveis aceito
- T-02 · onda 1 — Caixa de classe/método resolvida por Reflection; divergência negada
- T-02 · onda 1 — (plano-mandou) Classe/método da mesma fonte do dispatch; divergência query×corpo negada
- T-02 · onda 1 — SystemSQLPanel::onGenerate/onExportCSV como mutação; SystemDatabaseExplorer::onExport* como leitura
- T-03 · onda 1 — (plano-mandou) Limite duplo por conta e por IP num LoginRateLimiter
- plano · onda 1 — S7 entra como T-11 (onda 2); T-06 → onda 3, T-09 → onda 4
- plano · onda — — Revogação de sessões adiada; teste de fumaça com dados ZZ- autorizado; main produção
- T-11 · onda 2 — S7 em classe própria SearchParameterGuard; escopo ampliado a mask, criteria e autocomplete
- T-07 · onda 2 — SUITE com os mounts de .env.example e docker-compose.yml
- T-07 · onda 2 — .env.example com APP_PUBLIC_URL ativo e comentado aceito
- T-07 · onda 2 — Timing do SMTP síncrono fica como pendência
- T-11 · onda 2 — Fixture multisearch-tenant-7-active-criteria.txt no RED 0653fa5 aceita
- T-11 · onda 2 — Filtros extras `campo = inteiro` por AND após tenant_id aceitos
- T-11 · onda 2 — criteria só em forma canônica com chaves privadas exatas
- T-06 · onda 3 — class_alias aceito só com nome curto idêntico pelo resolvedor do guard; levar à política é pendência
- T-06 · onda 3 — cv_csrf retirado dos parâmetros de dispatch
- T-06 · onda 3 — template=iframe sem cv-shell.js como risco latente
- T-06 · onda 3 — Rebuild local, dados ZZ- no banco de dev (excluídos) e sessão de dev por curl autorizados
- T-09 · onda 4 — Commits extras de relatório (e92d833, d851710) aceitos
- T-10 · onda 4 — FakeAuthorizationPolicy no teste de integração aceita

## Achados
- [bloqueante] plano-mandou — A mensagem de limite do reset nunca mostra os segundos: `_t()` só substitui ^1..^4 (src/app/lib/util/ApplicationTranslator.php:146-160), mas a chave usa `%s`. O usuário vê "Tente novamente em %s segundos." O texto e a chamada vieram de tasks.md (T-01 linha 40, T-07 linha 246). Correção: chave com ^1 em translations.json e no _t → src/app/control/admin/SystemRequestPasswordResetForm.php:135, src/app/config/translations.json:5263, src/tests/Unit/SecurityMvpMessagesTest.php:20
- [sugestão] O mesmo defeito com `%s` já existia no limite do login (anterior à branch) → src/app/control/admin/LoginForm.php:171
- [sugestão] orderColumn só precisa ser um identificador, sem estar entre as colunas pesquisadas: SystemUser pode ser ordenado por `password` (oráculo de ordenação) → src/app/Core/Security/SearchParameterGuard.php:81-85
- [sugestão] Coerência T-02/T-11/T-06 conferida no fonte. Entradas que despacham: engine.php e index.php (checkMultiSession), cmd.php só CLI, lead.php com token próprio. Métodos com prefixo de leitura revisados (onDisplay* do SystemDriveList só devolvem bool; onGenerate*Pdf e 2FA onLoad não gravam), sem regressão nova. Buscas: os 10 TDBUniqueSearch/TDBMultiSearch usam database permission, models das listas e máscara `{col}`/`<span>{col_safe}</span>`, e nenhum TDBEntry usa o autocomplete negado → ApplicationAuthenticationService.php, MutationRequestPolicy.php, SearchParameterGuard.php
- [sugestão] Regras de UI: o diff não altera layout, CSS nem rótulos. LoginForm só tira a condição do botão e cv-shell.js só ganha o prefilter, então não há telas novas a julgar quanto a rótulos à esquerda e 44 px → src/app/control/admin/LoginForm.php:100-103

## Não verificado (limite de turnos)
- SUITE completo com os mounts de .env.example/docker-compose.yml e lint contra baseline/php-lint.txt não reexecutados nesta revisão
- Diffs de testes (MutationRequestPolicyTest, MutationGuardWiringTest, SearchParameterGuardTest, PasswordReset*Test) e docs (csrf-inventario.md, runbooks) não lidos por inteiro

## Re-revisão
Escopo: só o bloqueante `%s` na mensagem de limite e a correção T-12 (5bf3771 RED, e6d8980). Rulings recebidos: chore(tasks) sem trailer (24876ac, 1983b78, fa140e2, a3d3275) aceitos, só tocam .claude/tasks.
- [resolvida] Mensagem de limite do reset com `%s` literal → SystemRequestPasswordResetForm.php:135 e translations.json:5267 usam ^1 em en/pt; `php -r` com o ApplicationTranslator real e 120 → "Muitas tentativas de redefinição de senha. Tente novamente em 120 segundos." / "Too many password reset attempts. Try again in 120 seconds."
- [resolvida] Sugestão do login (`%s`, chave sem tradução → "Message not found") → LoginForm.php:171 com ^1 e entrada nova translations.json:5263; `_t` real → "Muitas tentativas de login. Tente novamente em 120 segundos." / "Too many login attempts. Try again in 120 seconds."
- ok: `git grep "Try again in %s seconds"` fora de .claude → nenhum resultado; nenhum `_t('…%s|%d` em src/app; translations.json válido (1460 entradas)
- ok: RED 5bf3771 só toca o teste; a falha registrada ("Missing translation for rate limit key … ^1") é a asserção da chave, ausência do comportamento. O teste novo confere chave, chamada no controller e renderização real em pt/en sem `%s`/`^1`. Ele roda `_t` em subprocesso porque um `_t` de teste já está declarado na suíte; a justificativa confere
- ok: e6d8980 toca só os 4 arquivos de T-12; nada novo quebrado. Gate (reviews/T-12.md): SUITE 1874/1874 e lint ok, sem reexecução aqui
- Bloqueantes: nenhum. Os demais itens da Triagem e as sugestões seguem como na revisão final

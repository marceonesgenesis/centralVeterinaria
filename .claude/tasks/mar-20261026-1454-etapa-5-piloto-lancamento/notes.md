# Notas de execução

## Decisões tomadas
- 2026-10-26 · plano · onda 0 — Branch base `task/etapa-4-operacao` (não `main`), por pedido explícito do desenvolvedor na demanda: as Etapas 1–4 ainda não estão em `dev`/`main` e o PR da Etapa 4 para `dev` está em preparação.
- 2026-10-26 · plano · onda 0 — Recorte do piloto na guarda (`OfferScope`, motivo `not_released`) e não em `PlanEntitlements`/`plan-groups.sql`: `PlanGroupsSeedTest` amarra o seed a `allowsProgram`, e a demanda pede flag em vez de remover código. Liberados: `inventory_pos`, `finance_full`, `multi_unit` e tudo que não é gated (núcleo clínico, exames, vacinação). Fora: `communication`, `hospital`, `ai_advanced`, ligáveis por `OFFER_PREVIEW_FEATURES`. Tenant sem assinatura (legado/dev) não é afetado.
- 2026-10-26 · plano · onda 0 — Perfis restritos (Recepção, Veterinário, Financeiro) como grupos globais fixos em `role-groups.sql`: sem eles não há "perfis restritos" para homologar (hoje todo usuário recebe o grupo do plano). Editor de perfis fica fora (feature nova).
- 2026-10-26 · plano · onda 0 — Aceite legal no `LoginForm` com tabela própria por usuário/documento/versão; o `require_terms` do Adianti fica desligado (grava `Y` sem versão e o `$_SERVER` inteiro). O administrador que aceitou no checkout aceita de novo no primeiro login (não se copia o aceite do `billing_order` para não tocar o `TenantProvisioningService`).
- 2026-10-26 · plano · onda 0 — Checkout: `LandingCatalog::TERMS_VERSION` passa a `termos-uso-2026-10` (= `LegalDocuments::TERMS_VERSION`) e o texto cita Termos de Uso e Política de Privacidade; pedidos antigos mantêm `assinatura-2026-10` em `billing_order.terms_version`.
- 2026-10-26 · plano · onda 0 — Exportação LGPD síncrona, só dados estruturados (CSV por tabela com `tenant_id`, descobertas em `information_schema`), sem colunas que casam `password|token|secret|hash`; arquivos do storage ficam com o suporte no piloto (`files_included: false` no manifesto).
- 2026-10-26 · plano · onda 0 — Cancelamento só no fim do período; sem reativação pelo app (via suporte/Stripe). Pix finalizado pelo reconciliador; cartão pelo `customer.subscription.deleted` do Stripe.
- 2026-10-26 · plano · onda 0 — Triagem das pendências: da Etapa 4 entra só o flag de manutenção órfão (T-18 da Etapa 4 → T-05 aqui), porque desliga backup e alertas em silêncio com dados reais do piloto. As demais pendências abertas das Etapas 3 e 4 (`reviews/final.md`) são cobertura de teste, cosméticas, mitigadas ou já resolvidas (o `login_conflict` da Etapa 3 foi fechado por `LoginConflictRefundService`) e não bloqueiam o piloto; o banco dev com 0017–0019 pendentes segue exigindo autorização e entra no checklist de go-live (T-20).
- 2026-10-26 · plano · onda 0 — Resposta do usuário (oferta): entram núcleo clínico, exames, vacinação, financeiro, estoque/PDV e várias unidades; ficam "em breve", desligados por flag, comunicação, internação/cirurgia, IA e governança da rede. `Suporte dedicado` não foi listado e segue anunciado (T-13 só marca `Governança da rede`).
- 2026-10-26 · plano · onda 0 — Resposta do usuário (perfis): 3 perfis fixos (Recepção, Veterinário, Financeiro) além do administrador, sem editor.
- 2026-10-26 · plano · onda 0 — Resposta do usuário (planos): os 4 planos no piloto, divergindo da recomendação (só Pro). `OFFER_PLANS` mantém os 4 como padrão; T-13 testa o padrão com os 4 e o filtro só como configuração. T-18 roda a jornada em tenants de planos diferentes (A `pro`, B `business`) e cobre a quota de usuários num tenant `starter`.
- 2026-10-26 · plano · onda 0 — Resposta do usuário (suporte e retenção): e-mail obrigatório e WhatsApp opcional, de segunda a sexta, das 8h às 18h; retenção de 30 dias com exportação pelo app e exclusão pelo operador depois; binários saem pelo suporte.

- 2026-10-26 · T-01 · onda 1 — `migrations/README.md` (fora do escopo) aceito; `legal_acceptance.accepted_at` com DEFAULT CURRENT_TIMESTAMP(6) por causa do MySQL 5.7 (T-10 não deve depender do default).
- 2026-10-26 · T-02 · onda 1 — `src/tests/Unit/UserMessageTest.php` autorizado para a correção do contador (100→106).
- 2026-10-26 · T-06 · onda 1 — Evidência substituta aceita (php -S: 200/404/CSP) porque a imagem do app não tem `src/legal.php` sem rebuild; conferir na fumaça pós-deploy.
- 2026-10-26 · T-05 · onda 1 — `kill -0` mais `ps -p` para o flag órfão, por causa do EPERM com processo de outro usuário.
- 2026-10-26 · usuário · onda 1 — Oferta: núcleo, financeiro e estoque; comunicação, internação/cirurgia, IA e governança da rede "em breve"; 3 perfis fixos (Recepção, Veterinário, Financeiro); 4 planos; suporte por e-mail e WhatsApp seg–sex 8h–18h; retenção de 30 dias; "Suporte dedicado" segue anunciado (já registrado pelo planejador).
- 2026-10-26 · T-08 · onda 2 — `SubscriptionTest.php` (fora do escopo, no commit RED) aceito porque afirma as chaves de `toRow()`.
- 2026-10-26 · T-12 · onda 2 — Troca de `testAllowedGroupsOfAIsOnlyTheStarterGroup` legítima; na correção, `UserMessage`, `translations.json` e `UserMessageTest` (106→107) autorizados; a guarda vale em `update()` e `deactivate()`; convidado não conta como admin.
- 2026-10-26 · T-13 · onda 2 — Correção do `testScenarioP` feita pela T-13 (arquivo dela); escopo extra em `LandingCheckoutWiringTest.php` e `landing.css` aceito; curl na :8081 sem rebuild aceito via `php -S` (mesmo ruling da onda 1); o teste de i18n exige `PLAYWRIGHT_MODULE=~/.npm/_npx/e41f203b7505f1fb/node_modules/playwright`.
- 2026-10-26 · T-18 · onda 3 — Defeito de produto achado pela jornada: `Receivable N not found` e `Cash session N not found` sem padrão em `UserMessage::PATTERNS`; T-18 autorizada a corrigir em `UserMessage.php` e `UserMessageTest.php` (d4d7d9d RED, 8593cc1); `Cash session` mapeia para "Record not found".
- 2026-10-26 · T-15 · onda 3 — Três desvios aceitos: `tenantContext(PDO)` público, transação no `onSave` e toast com a chave `Record saved`.
- 2026-10-26 · T-14..T-17 · onda 3 — Registro em menu, grupos, CSRF e ALWAYS_ALLOWED fica com a T-19 (onda 4).

## Bloqueios
- nenhum

## Descobertas
- [T-03] `PilotSettings`/`OfferScope` prontos; preflight ganhou o achado `support_email` após `operator_email`; `release.md` e `observabilidade.md` não o citam.
- [T-01] 0020 aplicada no centralvet_test: colunas de cancelamento em subscription, `legal_acceptance` e `tenant_onboarding` disponíveis para T-08/T-09/T-10.
- [T-04] `ClinicRoleProfiles` e `role-groups.sql` prontos (3 grupos "Central Vet - Perfil ..."), seed idempotente no centralvet_test; `SupportView` e `FirstEncounterGuideView` só ligam depois que T-19 os registrar.
- [T-04] Reaplicar `initial-application-programs.sql` depois de `role-groups.sql` liga AiCopilotPanel/AiConversationList ao Perfil Veterinário; excluir também `Central Vet - Perfil %` nesses grants.
- [T-06] `LegalDocuments` e `LegalPage` prontos (0982a23); URLs `legal.php?doc=termos|privacidade`; versões `termos-uso-2026-10`/`privacidade-2026-10`; `LegalPage::NOT_FOUND_BODY`.
- [T-07] Guarda com `OfferScope`: `AccessDecision::notReleased()`; testes de integração sem `OfferScope` usam o ambiente.
- [T-08] `SubscriptionCancellationService` pronto; `BillingReconciler::run` ganhou a fase 6 e o contador `cancellations_finalized`.
- [T-09] `OnboardingChecklistService`/`ClinicProfileService` prontos; `load()/save()` lançam `TenantBoundaryViolation` se a unidade não for do tenant.
- [T-10] `LegalAcceptanceService` pronto; `accept` sem tenant lança `InvalidArgumentException` técnica (T-16 não deve exibi-la).
- [T-11] `TenantDataExporter` pronto; o chamador escolhe o `$zipPath` temporário e o remove após o download.
- [T-12] `allowedGroups()` traz o grupo do plano mais os 3 perfis; `update()`/`deactivate()` recusam deixar a clínica sem administrador ativo (`UserMessage` 107).
- [T-13] `TERMS_VERSION` = `termos-uso-2026-10`; `CheckoutStartHandler` ganhou `?OfferScope $offer = null` e `PLAN_UNAVAILABLE_MESSAGE`; pilha :8081 sem rebuild serve landing antiga.
- [T-16] `LoginForm::legalAcceptanceVerification` pronto (afd3028 RED, 1764649): roda entre `policyTermsVerification` e `checkTwoFactor`; diálogo `legal_acceptance` envia `accept_legal` (`N` na ação, `Y` no POST quando marcado). Usuário com `tenant_user` sem aceite das versões vigentes vê o diálogo no login — T-18 (se exercitar o login) e a homologação precisam marcar o aceite.
- [T-15] Telas prontas (5ae5c0f RED, 1a06956): `OnboardingChecklistView` (`onMarkTeamReviewed`), `ClinicProfileForm` (`onEdit`/`onSave`), `FirstEncounterGuideView` (`onMarkRead`); as 3 mutações são POST via TForm e precisam entrar no inventário CSRF e em `system_program`/grupos/menu (T-19). Extra: `OnboardingChecklistView::tenantContext(PDO)` público. Nenhuma chave nova (toast usa `Record saved`).
- [T-14] `SubscriptionCancelForm` pronto (d895460): página inteira (não cortina), `public function onConfirm($param)` de instância (mutação; sai do padrão `onEdit`); construtor só lê. Presenter expõe `SubscriptionStatusPresenter::canCancel(?Subscription): bool` e `::cancelScheduledFor(?Subscription): ?string` (estáticos, fora do array de `summary()`). Para T-19: registrar `SubscriptionCancelForm` em `system_program`/grupos (admin e planos), ALWAYS_ALLOWED se a clínica suspensa deve poder cancelar, e `SubscriptionCancelForm::onConfirm` no inventário CSRF. Link de exportação aponta para `index.php?class=ClinicDataExportView` (T-17); termos `legal.php?doc=termos`. Sem chave nova.
- [T-17] `SupportView` e `ClinicDataExportView` prontos; download em `engine.php?class=ClinicDataExportView&method=onDownload&static=1` (estático, leitura). Para T-19: registrar os 2 programas e pôr `ClinicDataExportView` em `ALWAYS_ALLOWED` da guarda (exportação após cancelamento). O link "Política de cancelamento" usa `legal.php?doc=termos#cancelamento`, mas `app/view/legal/termos-de-uso.html` (T-06) não tem `id="cancelamento"` no `<h2>Planos, cobrança e cancelamento</h2>`; a âncora não posiciona até alguém acrescentá-lo. Card legal usa as chaves existentes `Terms of use`/`Privacy policy`; rótulo `WhatsApp` fica sem `_t()` (nome próprio).
- [T-18] Defeito de produto (não corrigido, só teste nesta task): `PaymentService::register` sobre recebível de outro tenant lança `InvalidArgumentException("Receivable <id> not found")` (PaymentService.php ~linha 127) e `UserMessage::resolve` devolve null — não há padrão para `Receivable \d+ not found` (a chave "Receivable not found" já existe em translations.json). `PilotJourneyIntegrationTest::testTenantBNeitherSeesThePatientNorPaysTheReceivableOfA` fica FAIL até uma task acrescentar o padrão em `UserMessage::PATTERNS` (ex.: `'/^Receivable \d+ not found$/D' => 'Receivable not found'`); `Cash session \d+ not found` (mesmo método) também não tem padrão.

## Pendências
- T-01: testes de schema só afirmam nome/ordem das colunas; tipo, nulidade, DEFAULT e ON UPDATE só no .verify.sql manual.
- T-03: `PilotSettings::supportEmail()` aceita `suporte@x.invalid`, que o preflight reprova; regra de validade duplicada no preflight; `fromEnvironment()` sem teste.
- T-03: `release.md` e `observabilidade.md` sem `support_email` (para T-20).
- T-04: idempotência dos vínculos só exercida com 3 SystemProfile* no centralvet_test; cobrir na jornada de T-18.
- T-04: reaplicar o seed inicial depois de `role-groups.sql` reabre AiCopilot ao Veterinário (para T-19).
- T-05: `ps -p` pode faltar sem procps (restore vivo de outro usuário tratado como órfão); comentar no teste o caminho EPERM.
- T-06: horário de suporte fixo nos textos legais enquanto SUPPORT_HOURS é configurável; `Cache-Control: public` também sai no 404.
- T-06: textos legais precisam de revisão jurídica (DPO, foro, reembolso).
- A 0020 não foi aplicada no banco dev (exige autorização).
- O PR da etapa 4 está pendente porque o gh não está autenticado.
- T-07: o caso `legacy` só exercita `HospitalizationBoard` (faltam `AiCopilotPanel`, `CommunicationComposeForm`); `not_released` reaproveita o link de `SubscriptionStatusView` (avaliar destino próprio em T-19).
- T-08: sem teste de que a exportação segue aberta (ALWAYS_ALLOWED) após finalizar (cobrir em T-18/T-19); Pix cancelado na janela de emissão antecipada mantém a fatura do próximo período aberta; teste "saves with the row locked" não prova a transação.
- T-08: docblock de `bin/billing-reconcile.php` e runbook mysql57 sem `cancellations_finalized` (para T-20).
- T-09: `mark()` público e estático fora da Interface; teste de unidade de outro tenant engole exceção; `save()` sem transação própria (T-15 abre a transação).
- T-10: RED com expectativa sensível à ordem; `accepted_at` sem normalizar fuso; teste de truncamento só com ASCII.
- T-11: teste só procura o tutor de B em `tutor.csv` (conferir `tenant_id` em todo CSV); `tempZip()` deixa temporário; SELECT sem ORDER BY; colunas `ai_interaction.*_tokens` e `ai_tenant_setting.monthly_token_quota` ficam fora da exportação por casar o padrão de segredo.
- T-12: docblock de `ClinicUserForm` desatualizado (para T-19); `allowedGroups()` duplicaria perfil se houver 2 `system_group` com o mesmo nome.
- T-13: `docs/runbooks/smoke-billing.md:158` espera `assinatura-2026-10` (agora `termos-uso-2026-10`); "sem pedido" provado só por `findByPublicId('order-1')`.
- A imagem do app em :8081 precisa de rebuild para a fumaça (T-06, T-13).
- T-14: `testConfirmIsMutating` passa sem a implementação (não prova o vínculo; vale o inventário CSRF); botão "Cancelar assinatura" aparece para `suspended` sem `SubscriptionCancelForm` em ALWAYS_ALLOWED/system_program (T-19 decide); `onConfirm` loga `getMessage()` e o construtor só a classe.
- T-15: o guia diz que o nome entre aspas é o do menu, mas várias telas não estão no `menu.xml`; "concluído" recalculado na tela em vez de `isComplete()`; `tenantContext(PDO)` público em TPage é chamável pela URL (TypeError), preferir privado.
- T-16: recusar o aceite passa pelo `LoginRateLimiter::hit` e `sleep(2)` (conta como senha errada); aceite gravado antes do 2FA (quem só tem a senha registra o aceite); `accept_legal` sem `.cv-touch-target` e sem `for` no rótulo; recusa/`return` só com cobertura estática.
- T-17: âncora `id="cancelamento"` falta em `termos-de-uso.html` (T-06); título `Terms of use` do card legal é restrito; `userId` do AuditEvent vem da sessão (0 sem sessão); `testDownloadIsAReadMethod` já passava no RED.
- T-18: RED do perfil ausente não reproduzível (só o do isolamento é legítimo); ramo morto em `array_sum` dos totais; padrão `Cash session` só com cobertura unitária (jornada não exercita o caixa de outro tenant).
- Para T-19: registrar SubscriptionCancelForm, OnboardingChecklistView, ClinicProfileForm, FirstEncounterGuideView, SupportView e ClinicDataExportView em programa, grupos e menu; inventário CSRF: onConfirm, onSave, onMarkTeamReviewed, onMarkRead e os da exportação; ClinicDataExportView em ALWAYS_ALLOWED.
- Diálogo de aceite e telas da onda 3 sem fumaça no navegador (imagem :8081 sem rebuild); download real da exportação só conferido por leitura do código.
- A idempotência dos vínculos da T-04 segue sem cobertura.

## Riscos
- Divergência de base: o checkout explorado (`merge/task/etapa-4-operacao-dev`) traz `origin/dev`; em relação a `task/etapa-4-operacao`, `.env.example` e `docs/runbooks/shared-hosting-mysql57.md` diferem e podem conflitar no PR para `dev` (T-01 e T-03 os tocam). Mitigação: mudanças em blocos novos no fim de cada arquivo.
- Baselines (`baseline/php-lint-all.txt`, `baseline/bash-n.txt`, 0 linhas) gravadas no checkout `merge/task/etapa-4-operacao-dev`; os arquivos PHP/shell que diferem de `task/etapa-4-operacao` não têm erro de sintaxe, então valem para a branch de trabalho.
- `LoginForm` é o caminho de todo login: erro em T-16 tranca o acesso. Mitigação: usuário sem `tenant_user` não tem pendência; wiring test de ordem; roteiro manual em T-20.
- O teste da jornada (T-18) pode revelar defeito de produto fora dos seus arquivos: vai ao board e a Pendências, e o orquestrador decide se abre task de correção.
- Exportação síncrona pode estourar tempo/memória em clínica grande: piloto pequeno; o CSV é escrito em streaming num temporário.
- Recorte do piloto depende de `OFFER_PREVIEW_FEATURES` vazio em produção: entra no checklist de go-live.

## Retomada
- Pasta: `.claude/tasks/mar-20261026-1454-etapa-5-piloto-lancamento/`
- Sessões: b5eee13a-a154-4bef-8500-0b8809d420de
- Branch de trabalho: task/etapa-5-piloto-lancamento (base: task/etapa-4-operacao)
- BASE da onda 1: fbd6d51
- Commits por onda:
  - Onda 1: BASE fbd6d51 → HEAD 74f5bc7 (6c0f1a2 5ee88dc ce5462d 66409bb 7b6ecaa c7f589b fc738a5 c7cd766 fecdf22 76a59a6 875367d 0982a23 74f5bc7)
  - Onda 2: BASE 2cb9000 → HEAD 8745709 (deaf41d 1df3ae9 560f220 a9bc4ff 7fd7d15 f3e2624 ac90687 5dc6266 3305af8 b2272f8 6d5be2c 9e8a532 3db1aa4 1f5af36 6c04115 f2a951a 8745709)
  - Onda 3: BASE f51adf8 → HEAD 8593cc1 (37301a8 5ae5c0f afd3028 45d94f7 1a06956 1764649 0c3447e d895460 a45f316 d4d7d9d 8593cc1)
- Último status conhecido: onda 3 concluída (T-14 a T-18 [x]); gate SUITE 2511/2511, LINT e BASHN 0 linhas; PilotJourney passou 2x
- Próxima onda recomendada: onda 4 (T-19)

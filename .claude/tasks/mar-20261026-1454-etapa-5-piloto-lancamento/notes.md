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

## Bloqueios
- nenhum

## Descobertas
- [T-03] `PilotSettings`/`OfferScope` prontos; preflight ganhou o achado `support_email` após `operator_email`; `release.md` e `observabilidade.md` não o citam.
- [T-01] 0020 aplicada no centralvet_test: colunas de cancelamento em subscription, `legal_acceptance` e `tenant_onboarding` disponíveis para T-08/T-09/T-10.
- [T-04] `ClinicRoleProfiles` e `role-groups.sql` prontos (3 grupos "Central Vet - Perfil ..."), seed idempotente no centralvet_test; `SupportView` e `FirstEncounterGuideView` só ligam depois que T-19 os registrar.
- [T-04] Reaplicar `initial-application-programs.sql` depois de `role-groups.sql` liga AiCopilotPanel/AiConversationList ao Perfil Veterinário; excluir também `Central Vet - Perfil %` nesses grants.
- [T-06] `LegalDocuments` e `LegalPage` prontos (0982a23); URLs `legal.php?doc=termos|privacidade`; versões `termos-uso-2026-10`/`privacidade-2026-10`; `LegalPage::NOT_FOUND_BODY`.

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
- Último status conhecido: onda 1 concluída (T-01 a T-06 [x]); gate 2436/2436 após correção 74f5bc7
- Próxima onda recomendada: onda 2 (T-07 a T-12, T-13 conforme dependências)

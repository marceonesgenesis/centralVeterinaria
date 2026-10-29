# Plano: Etapa 5 — Piloto e lançamento (onboarding, jornada por perfil, termos, suporte, cancelamento, exportação e oferta)

## Objetivo
Fechar a última etapa do plano de conclusão do MVP (`.docs/audits/2026-10-09-prontidao-mvp.md`, critério: nenhum bloqueio aberto no escopo anunciado; suporte/onboarding e oferta alinhados). Entrega o que é código ou artefato versionável: primeiro acesso sem intervenção técnica, perfis restritos e a jornada homologável provada por teste de integração em 2 tenants, termos e privacidade com aceite versionado, suporte, cancelamento pelo app e exportação LGPD, oferta restrita ao recorte homologado por feature flag, e checklist de go-live e runbook do piloto.

## Premissas
- Branch de trabalho `task/etapa-5-piloto-lancamento`, criada a partir de `task/etapa-4-operacao` (HEAD `fbd6d51`), pedido explícito do desenvolvedor: as Etapas 1–4 ainda não estão em `dev`/`main`. Produção: `main`; teste: `dev` (`scripts/branches.py`).
- A entrega preserva os commits por onda (sem `entrega.py`); push e PR só a pedido do desenvolvedor.
- TDD com o teste RED commitado antes da implementação; testes no container `app` (PHP 8.4) pelos comandos SUITE/LINT/MYSQLTEST da Etapa 4 (cabeçalho de `tasks.md`); pipeline completo por `scripts/ci/run.sh`.
- Rótulos alinhados à esquerda e alvos de toque de 44 px em toda tela nova (`CLAUDE.md`); mensagens ao usuário em pt-BR.
- Nenhuma credencial real; e-mails de teste em `@example.test`. SQL de escrita no banco `centralvet` (dev) e escrita no `.env` da raiz só com autorização; nenhuma task depende disso. DDL e seeds no `centralvet_test` seguem o padrão autorizado desde a Etapa 2.
- Recorte do piloto (confirmado pelo usuário): liberados o núcleo clínico (tutor/paciente, agenda, atendimento, prescrição/PDF, exames, vacinação), financeiro, estoque/PDV (F1–F3 fechados na Etapa 2) e várias unidades; "em breve", desligados por flag: comunicação/automações, internação/cirurgia, IA e governança da rede (relatórios avançados, integrações e SSO/SLA já eram "em breve" na landing). `Suporte dedicado` segue anunciado.
- Planos (confirmado): os 4 planos (Starter, Pro, Business, Enterprise) no piloto; `OFFER_PLANS` tem os 4 como padrão e só serve para um ambiente reduzir a oferta sem mudar código.
- Suporte e retenção (confirmado): e-mail obrigatório (preflight `support_email`) e WhatsApp opcional, de segunda a sexta, das 8h às 18h; retenção de 30 dias após o cancelamento com exportação pelo app e exclusão pelo operador depois; binários (fotos, PDFs) saem pelo suporte.
- Perfis restritos fixos (confirmado): Recepção, Veterinário e Financeiro além do administrador (grupo do plano), sem editor de perfis.
- Textos jurídicos são rascunho marcado `RASCUNHO — revisar com jurídico`.

## Escopo

### Incluso
- Onboarding sem intervenção técnica: checklist de primeiros passos (dados da clínica e unidade, equipe, serviços/preços, orientações do primeiro atendimento) com telas e marcas persistidas (T-01, T-09, T-15, T-19).
- Perfis restritos Recepção, Veterinário e Financeiro, escolhidos pelo administrador na equipe (T-04, T-12, T-19).
- Jornada homologável: teste de integração da jornada por perfil em 2 tenants com perfis restritos, isolamento, PDF e mensagens de erro (T-18) e roteiro versionado de homologação manual (T-20).
- Termos de uso e política de privacidade em página pública, aceite versionado no checkout e no primeiro acesso (T-06, T-10, T-13, T-16).
- Canal de suporte configurável e tela de suporte; preflight exige o e-mail de suporte (T-03, T-17, T-19).
- Cancelamento da assinatura pelo app com efeito no fim do período (Stripe e Pix) e política exibida (T-08, T-14).
- Exportação dos dados da clínica (LGPD) em ZIP pelo administrador, também com a assinatura bloqueada ou cancelada (T-11, T-17, T-19).
- Oferta alinhada ao recorte homologado: feature flags na guarda, os 4 planos na landing e no checkout (filtráveis por `OFFER_PLANS`) e módulos não homologados como "em breve" (T-03, T-07, T-13).
- Checklist de go-live e runbook do piloto (feedback, incidentes, critérios de saída) (T-20).
- Pendência da Etapa 4 que bloqueia o piloto: flag de manutenção órfão desliga backup e alertas (T-05).
- Mensagens pt-BR e registro das telas novas em programas, grupos, menu, guarda e inventário CSRF (T-02, T-19).

### Excluído
- Central Intelligence, Ei, Vet!, SSO e novas features de domínio (inclusive editor de perfis, reativação de assinatura pelo app, troca de plano e Billing Portal do Stripe).
- Exportação de arquivos binários (fotos, PDFs do storage) e exclusão automática após o prazo de retenção: no piloto, pelo suporte/operador conforme runbook.
- Aplicar migrations ou seeds no banco `centralvet` (dev) ou em produção (fica no checklist de go-live, com autorização).
- Texto jurídico final (revisão jurídica externa) e contratação de canais pagos (WhatsApp Business API, helpdesk).
- Remover código ou direitos de plano dos módulos fora do recorte (ficam desligados por flag).
- Pendências das Etapas 3 e 4 que não bloqueiam o piloto (triagem em `notes.md`).

## Contexto técnico
- Camadas envolvidas: database, backend, frontend, infra, qa, docs
- Projeto/base analisada: `/var/www/html/centralvet` (raiz do repositório: `git -C <DIR> rev-parse --show-toplevel`), explorada no checkout `merge/task/etapa-4-operacao-dev`; os arquivos tocados por este plano são iguais em `task/etapa-4-operacao`, exceto `.env.example` e `docs/runbooks/shared-hosting-mysql57.md` (ver `notes.md` § Riscos)
- Integrações: Stripe (`StripeClientInterface::request`, testado com `FakeStripeClient`; sem rede), SMTP (não muda), Dompdf (PDF da prescrição no teste da jornada), `ZipArchive` (extensão `zip` já exigida pelo preflight)

## Baseline
- php-lint-all: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app sh -c 'cd /var/www/html/src && find app tests bin config -name "*.php" -print0 | xargs -0 -n1 php -l 2>&1 | grep -v "^No syntax errors detected" || true'` em raiz → baseline/php-lint-all.txt (0 linhas)
- bash-n: `find scripts -name '*.sh' -exec bash -n {} \;` em raiz → baseline/bash-n.txt (0 linhas)

## Exploração read-only
- Caminhos relevantes: `src/app/Core/Billing/{PlanEntitlements,SubscriptionAccessGuard,AccessDecision,Subscription,BillingReconciler,TenantProvisioningService,CheckoutStartHandler,LoginConflictRefundService}.php`, `src/app/Core/Billing/Stripe/StripeClientInterface.php` (um método `request`), `src/app/Core/Persistence/SubscriptionRepository.php` (`listPixDueForIssue`), `src/app/Core/Landing/{LandingCatalog,LandingPage}.php`, `src/app/view/landing/landing.html`, `src/landing/*.js`, `src/app/Core/Tenancy/{ClinicTeamService,ClinicUnitService,TenantContext}.php`, `src/app/control/admin/{LoginForm,SubscriptionStatusView,ClinicUserForm}.php`, `src/app/Core/Presentation/{UserMessage,SubscriptionStatusPresenter}.php`, `src/app/Core/Security/MutationRequestPolicy.php`, `src/app/Core/Operations/ProductionPreflight.php`, `src/app/database/seeds/{initial-application-programs,plan-groups}.sql`, `src/menu.xml`, `src/app/config/translations.json`, `scripts/cron-run.sh`, `scripts/test-db/provision.sh`, `docs/runbooks/*`, `docs/security/csrf-inventario.md`.
- Padrões identificados: PSR-4 `CentralVet\` → `src/app/Core/`; serviços com PDO + `TenantContext` (padrão `ClinicTeamService`); controllers Adianti em `control/admin` instanciam serviços; mensagens em inglês por `_t()` com pt em `translations.json` e contrato por `EtapaNMessagesTest`; seeds preparados idempotentes (`INSERT … MAX+1 … WHERE NOT EXISTS`) conferidos por teste de texto; migrations com placeholder de checksum, `.verify.sql` e sem `CHECK`; download por `header()` + `php://output` (padrão `FinancialOverview`); testes de tela por leitura do fonte (`*WiringTest`); integração em transação com rollback.
- Scripts úteis: SUITE/LINT/LINTALL/BASHN/MYSQLTEST/SHTEST/CI no cabeçalho de `tasks.md`; `node scripts/test-landing-i18n.cjs`; `bash scripts/test-db/provision-check.test.sh`; `python3 scripts/prepare-mysql57.py`.
- Riscos identificados: arquivos centrais compartilhados (`translations.json`, `menu.xml`, seeds, `SubscriptionAccessGuard`, `csrf-inventario.md`, `.env.example`, `docker-compose.yml`) — concentrados em T-02/T-03 (Onda 1) e T-19 (Onda 4); `PlanGroupsSeedTest` amarra `plan-groups.sql` a `allowsProgram` (por isso o recorte do piloto fica na guarda, não em `PlanEntitlements`); `LoginForm` é o caminho de todo login; o teste da jornada pode revelar defeito de produto fora do escopo de arquivos; testes MySQL dependem da 0020 no `centralvet_test`.

## Mapa de arquivos

| Arquivo | Responsabilidade | Ação | Tasks que tocam |
|---|---|---|---|
| `src/app/database/migrations/20261026_0020_etapa5_piloto.sql` | colunas de cancelamento, `legal_acceptance`, `tenant_onboarding` | criar | T-01 |
| `src/app/database/migrations/20261026_0020_etapa5_piloto.verify.sql` | verificação só leitura da 0020 | criar | T-01 |
| `scripts/test-db/provision.sh` | lista de migrations do banco de teste e da CI | modificar | T-01 |
| ⚠ `docs/runbooks/shared-hosting-mysql57.md` | seção da 0020; contadores do reconciliador | modificar | T-01, T-23 |
| `src/tests/Integration/Etapa5SchemaIntegrationTest.php` | schema da 0020 no `centralvet_test` | criar | T-01 |
| ⚠ `src/app/config/translations.json` | textos pt-BR da etapa | modificar | T-02, T-19 |
| `src/app/Core/Presentation/UserMessage.php` | mensagens de domínio traduzidas | modificar | T-02 |
| ⚠ `src/tests/Unit/Etapa5MessagesTest.php` | contrato das chaves pt-BR | criar | T-02, T-19 |
| `src/app/Core/Pilot/PilotSettings.php` | configuração do piloto pelo ambiente | criar | T-03 |
| `src/app/Core/Billing/OfferScope.php` | recorte homologado e planos ofertados | criar | T-03 |
| `.env.example` | variáveis `OFFER_*`, `SUPPORT_*`, retenção | modificar | T-03 |
| `docker-compose.yml` | repassa as variáveis novas ao app | modificar | T-03 |
| `docs/runbooks/environments.md` | documentação das variáveis | modificar | T-03 |
| `src/app/Core/Operations/ProductionPreflight.php` | achado `support_email` | modificar | T-03 |
| `src/tests/Unit/PilotSettingsTest.php` | teste da configuração | criar | T-03 |
| `src/tests/Unit/OfferScopeTest.php` | teste do recorte | criar | T-03 |
| `src/tests/Unit/ProductionPreflightTest.php` | teste do preflight | modificar | T-03 |
| `src/app/Core/Tenancy/ClinicRoleProfiles.php` | perfis restritos e programas | criar | T-04 |
| `src/app/database/seeds/role-groups.sql` | grupos dos perfis restritos | criar | T-04 |
| `src/tests/Unit/ClinicRoleProfilesTest.php` | teste dos perfis | criar | T-04 |
| `src/tests/Unit/RoleGroupsSeedTest.php` | teste do seed dos perfis | criar | T-04 |
| `scripts/cron-run.sh` | flag de manutenção órfão | modificar | T-05 |
| `scripts/cron-run.test.sh` | casos do flag órfão | modificar | T-05 |
| `docs/runbooks/restore-backup.md` | flag órfão e `CENTRALVET_MAINTENANCE_DIR` | modificar | T-05 |
| `src/app/Core/Legal/LegalDocuments.php` | versões e URLs dos documentos | criar | T-06 |
| `src/app/Core/Legal/LegalPage.php` | renderização pública dos documentos | criar | T-06 |
| `src/legal.php` | entrada pública com CSP | criar | T-06 |
| ⚠ `src/app/view/legal/termos-de-uso.html` | termos de uso (rascunho); âncora `#cancelamento` | criar | T-06, T-23 |
| `src/app/view/legal/politica-de-privacidade.html` | política de privacidade (rascunho) | criar | T-06 |
| ⚠ `src/tests/Unit/LegalPageTest.php` | teste da página legal | criar | T-06, T-23 |
| `src/app/Core/Billing/AccessDecision.php` | motivo `not_released` | modificar | T-07 |
| ⚠ `src/app/Core/Billing/SubscriptionAccessGuard.php` | guarda com recorte; `ALWAYS_ALLOWED` | modificar | T-07, T-19 |
| `src/tests/Unit/SubscriptionAccessGuardTest.php` | teste da guarda | modificar | T-07 |
| ⚠ `src/app/Core/Billing/Subscription.php` | cancelamento agendado; período posterior não reativa | modificar | T-08, T-22 |
| `src/app/Core/Billing/Contract/SubscriptionRepositoryInterface.php` | listagem de cancelamentos vencidos | modificar | T-08 |
| `src/app/Core/Persistence/SubscriptionRepository.php` | colunas novas e filtros | modificar | T-08 |
| `src/tests/Support/FakeSubscriptionRepository.php` | dublê do repositório | modificar | T-08 |
| ⚠ `src/app/Core/Billing/SubscriptionCancellationService.php` | cancelamento pelo cliente; anula fatura Pix futura | criar | T-08, T-21 |
| `src/app/Core/Billing/SubscriptionCancellationException.php` | erro de domínio do cancelamento | criar | T-08 |
| ⚠ `src/app/Core/Billing/BillingReconciler.php` | finaliza cancelamento Pix; estorna Pix pago após cancelamento | modificar | T-08, T-22 |
| ⚠ `src/tests/Unit/SubscriptionCancellationServiceTest.php` | teste do serviço | criar | T-08, T-21 |
| `src/tests/Integration/SubscriptionRepositoryIntegrationTest.php` | colunas e filtros no MySQL | modificar | T-08 |
| ⚠ `src/tests/Integration/BillingReconcilerIntegrationTest.php` | passo de finalização; estorno | modificar | T-08, T-22 |
| `src/app/Core/Onboarding/OnboardingChecklistService.php` | checklist do primeiro acesso | criar | T-09 |
| `src/app/Core/Onboarding/ClinicProfileService.php` | dados da clínica e unidade | criar | T-09 |
| `src/tests/Integration/OnboardingChecklistIntegrationTest.php` | teste do onboarding | criar | T-09 |
| `src/app/Core/Legal/LegalAcceptanceService.php` | aceite versionado | criar | T-10 |
| `src/tests/Integration/LegalAcceptanceIntegrationTest.php` | teste do aceite | criar | T-10 |
| `src/app/Core/Privacy/TenantDataExporter.php` | exportação LGPD em ZIP | criar | T-11 |
| `src/tests/Integration/TenantDataExporterIntegrationTest.php` | teste da exportação | criar | T-11 |
| `src/app/Core/Tenancy/ClinicTeamService.php` | perfis na equipe | modificar | T-12 |
| `src/tests/Integration/ClinicTeamServiceIntegrationTest.php` | teste da equipe com perfis | modificar | T-12 |
| `src/app/Core/Landing/LandingCatalog.php` | termos novos, "em breve", JSON filtrado | modificar | T-13 |
| `src/app/Core/Landing/LandingPage.php` | JSON com o recorte | modificar | T-13 |
| `src/app/Core/Billing/CheckoutStartHandler.php` | recusa plano fora da oferta | modificar | T-13 |
| `src/app/view/landing/landing.html` | links legais e cache-buster | modificar | T-13 |
| `src/landing/landing.js` | mensagem do aceite | modificar | T-13 |
| `src/landing/translations.js` | en/es dos textos novos | modificar | T-13 |
| `src/tests/Unit/LandingOfferAlignmentTest.php` | teste da oferta alinhada | criar | T-13 |
| `src/tests/Unit/LandingCatalogTest.php` | versão e texto dos termos | modificar | T-13 |
| `src/tests/Unit/CheckoutStartHandlerTest.php` | versão dos termos e plano ofertado | modificar | T-13 |
| ⚠ `src/tests/Integration/BillingAcquisitionFlowIntegrationTest.php` | versão dos termos; construtor do reconciliador | modificar | T-13, T-22 |
| `src/app/control/admin/SubscriptionStatusView.php` | botão e aviso de cancelamento | modificar | T-14 |
| ⚠ `src/app/control/admin/SubscriptionCancelForm.php` | confirmação do cancelamento; repositório de faturas | criar | T-14, T-21 |
| `src/app/Core/Presentation/SubscriptionStatusPresenter.php` | estado de cancelamento | modificar | T-14 |
| `src/tests/Unit/SubscriptionStatusPresenterTest.php` | teste do presenter | modificar | T-14 |
| `src/tests/Unit/SubscriptionCancelFormWiringTest.php` | wiring da tela | criar | T-14 |
| `src/app/control/admin/OnboardingChecklistView.php` | primeiros passos | criar | T-15 |
| `src/app/control/admin/ClinicProfileForm.php` | dados da clínica | criar | T-15 |
| `src/app/control/admin/FirstEncounterGuideView.php` | orientações do primeiro atendimento | criar | T-15 |
| `src/app/view/onboarding/first-encounter-guide.html` | conteúdo do guia | criar | T-15 |
| `src/tests/Unit/OnboardingScreensWiringTest.php` | wiring das telas | criar | T-15 |
| `src/app/control/admin/LoginForm.php` | diálogo de aceite no login | modificar | T-16 |
| `src/tests/Unit/LoginLegalAcceptanceWiringTest.php` | wiring do aceite | criar | T-16 |
| `src/app/control/admin/SupportView.php` | tela de suporte | criar | T-17 |
| `src/app/control/admin/ClinicDataExportView.php` | download da exportação | criar | T-17 |
| `src/tests/Unit/SupportAndExportScreensWiringTest.php` | wiring das telas | criar | T-17 |
| `src/tests/Integration/PilotJourneyIntegrationTest.php` | jornada por perfil em 2 tenants | criar | T-18 |
| `src/app/database/seeds/initial-application-programs.sql` | programas novos | modificar | T-19 |
| `src/app/database/seeds/plan-groups.sql` | programas novos nos planos | modificar | T-19 |
| `src/menu.xml` | menu das telas novas | modificar | T-19 |
| `docs/security/csrf-inventario.md` | métodos novos | modificar | T-19 |
| `src/tests/Unit/Etapa5WiringTest.php` | registro das telas | criar | T-19 |
| `docs/runbooks/piloto-homologacao.md` | roteiro de homologação | criar | T-20 |
| `docs/runbooks/piloto-go-live.md` | checklist de go-live | criar | T-20 |
| `docs/runbooks/piloto-operacao.md` | runbook do piloto | criar | T-20 |
| `docs/runbooks/README.md` | índice | modificar | T-20 |
| `src/app/Core/Billing/SubscriptionInvoice.php` | `voidForCancellation()` | modificar | T-21 |
| `src/app/Core/Billing/PixInvoiceIssuer.php` | não emite com cancelamento agendado | modificar | T-21 |
| `src/tests/Unit/SubscriptionInvoiceTest.php` | teste da anulação | modificar | T-21 |
| `src/tests/Unit/PixInvoiceIssuerTest.php` | teste da emissão | modificar | T-21 |
| `src/app/Core/Billing/BillingEventProcessor.php` | Pix pago após cancelamento não avança a assinatura | modificar | T-22 |
| `src/app/Core/Billing/CancelledPixRefundService.php` | estorno automático com alerta | criar | T-22 |
| `src/app/Core/Billing/Contract/SubscriptionInvoiceRepositoryInterface.php` | `listPaidPixAfterCancellation` | modificar | T-22 |
| `src/app/Core/Persistence/SubscriptionInvoiceRepository.php` | consulta do estorno | modificar | T-22 |
| `src/tests/Support/FakeSubscriptionInvoiceRepository.php` | dublê | modificar | T-22 |
| `src/bin/billing-reconcile.php` | monta o serviço; docblock dos contadores | modificar | T-22 |
| `src/tests/Unit/CancelledPixRefundServiceTest.php` | teste do estorno | criar | T-22 |
| `src/tests/Unit/BillingEventProcessorTest.php` | Pix pago após cancelamento | modificar | T-22 |
| `docs/runbooks/release.md` | `support_email` no preflight | modificar | T-23 |
| `docs/runbooks/observabilidade.md` | `support_email`; alerta do estorno | modificar | T-23 |

Arquivo tocado por mais de uma task leva ⚠ na linha e a resolução fica em "Estratégia de execução": os três ⚠ (`translations.json`, `Etapa5MessagesTest.php`, `SubscriptionAccessGuard.php`) são serializados em ondas diferentes (T-02/T-07 antes, T-19 na Onda 4); os ⚠ da Onda 6 dividem o arquivo entre a task nova (T-21, T-22 ou T-23) e uma task original de onda anterior, e na Onda 6 cada arquivo tem uma só task.

## Decisões de arquitetura

| Decisão | Alternativas consideradas | Motivo da escolha |
|---|---|---|
| Recorte do piloto como flag na guarda (`OfferScope` + `not_released`) | tirar features de `PlanEntitlements`; regenerar `plan-groups.sql` sem os programas; apagar menu | sem remover código nem direitos; `PlanGroupsSeedTest` continua válido; liga por ambiente (`OFFER_PREVIEW_FEATURES`) para homologar depois |
| Os 4 planos no piloto; `OFFER_PLANS` (padrão: os 4) filtra só a saída pública e o checkout | catálogo com 1 plano; remover planos do Stripe | decisão do usuário; o filtro deixa um ambiente reduzir a oferta sem retrabalho; `PlanEntitlements` segue lendo os 4 |
| Perfis restritos como grupos globais fixos (`role-groups.sql`) | grupo por tenant; editor de perfis; permissões por método | mesmo padrão dos grupos de plano; a guarda continua limitando pelo plano; editor é feature nova (fora) |
| Aceite legal em tabela própria, por usuário/documento/versão, no `LoginForm` | `require_terms` do Adianti (flag `Y` sem versão e grava `$_SERVER`); guarda pós-login em toda requisição | versão e prova (IP, user agent) por documento; menor raio que um gate em toda requisição |
| Checkout passa a aceitar Termos de Uso + Privacidade com `TERMS_VERSION` = `LegalDocuments::TERMS_VERSION` | manter "Termos de Assinatura" separado | uma versão só por documento; `billing_order.terms_version` já guarda a prova |
| Cancelamento no fim do período (`cancel_at_period_end`); Pix finalizado pelo reconciliador | cancelamento imediato com estorno proporcional; Billing Portal | política simples e alinhada ao texto atual dos termos; sem nova integração |
| Exportação síncrona, CSV por tabela descoberta por `information_schema` | fila assíncrona; lista manual de tabelas; incluir arquivos do storage | piloto pequeno; lista dinâmica não envelhece; arquivos via suporte (Excluído) |
| Suporte, exportação e cancelamento em `ALWAYS_ALLOWED` | só com assinatura ativa | cliente suspenso ou cancelado precisa cancelar, falar com o suporte e exportar (LGPD) |
| Arquivos centrais com escritor único (T-02, T-03 na Onda 1; T-19 na Onda 4) | cada task edita menu/seeds/translations | evita colisão; chave nova vai ao board e T-19 a aplica |
| Páginas legais por `src/legal.php` com CSP no PHP | location nova no nginx | não mexe em `default.conf` (amarrado por `ProxyTrustConfigTest`) |

## Diagrama de dependências

```text
Onda 1: T-01  T-02  T-03  T-04  T-05  T-06
T-02,T-03 → T-07
T-01,T-02 → T-08 → T-14 (+T-03)
T-01,T-02 → T-09 → T-15
T-01,T-06 → T-10 → T-16 (+T-02,T-06)
T-11 → T-17 (+T-02,T-03,T-06)
T-04 → T-12 → T-18 (+T-04)
T-03,T-06 → T-13
T-04,T-07,T-14,T-15,T-17 → T-19
T-05,T-08,T-11,T-13,T-17,T-18,T-19 → T-20
Onda 6 (correção): T-08,T-14 → T-21; T-08 → T-22; T-03,T-06,T-08,T-17 → T-23
```

## Estratégia de execução
- Branch de trabalho: `task/etapa-5-piloto-lancamento`
- Branch base: `task/etapa-4-operacao`
- Commits da onda: cada implementador commita os próprios caminhos (`git commit -- <caminhos>`) com o trailer da task. O fechador usa a skill `new-commit --auto` só se sobrou algo sem commit e sempre registra o estado das tasks num `chore(tasks): registra commits da onda N`. Por premissa do desenvolvedor, a entrega da Fase 5 preserva os commits por onda (não roda o `entrega.py`); push e PR só a pedido dele.
- Isolamento em ondas com edições paralelas: caminho exclusivo (cada agente só nos arquivos da própria task; os ⚠ ficam em ondas diferentes: `translations.json` e `Etapa5MessagesTest.php` em T-02 na Onda 1 e T-19 na Onda 4; `SubscriptionAccessGuard.php` em T-07 na Onda 2 e T-19 na Onda 4)
- Pré-condição da Onda 2: a 0020 aplicada ao `centralvet_test` (T-01). Sem ela, os testes de integração de T-08, T-09 e T-10 saem `FAIL`.
- Pré-condição da Onda 3: `role-groups.sql` não precisa estar no banco (T-12 e T-18 criam os grupos no próprio teste pelo nome exato).
- Pré-condição do critério de T-06 e T-13 (`curl`): pilha local no ar em `http://localhost:8081`; o código servido é o do checkout (bind mount do dev).
- Onda 6 — correção (revisão final), BASE `a852e0e`: origem `reviews/final.md` (bloqueante e pendência promovida de T-08: Pix cancelado na janela de emissão antecipada; pendências abertas de T-03, T-08 e T-17). T-21, T-22 e T-23 têm caminhos disjuntos (caminho exclusivo). Nenhuma chamada real ao Stripe (só `FakeStripeClient`) e nada no banco `centralvet` (dev). Validação cruzada da Onda 6: SUITE completo, LINTALL, BASHN, CI local (`scripts/ci/run.sh`) e `git diff task/etapa-4-operacao..HEAD | grep -cE '^[+].*(sk_live_|sk_test_|rk_live_)[A-Za-z0-9]+'` = `0`.
- Validação cruzada de cada onda: SUITE completo sem filtro de classe (`Failed: 0`, nenhuma classe de integração nova em `SKIP`), LINTALL contra `baseline/php-lint-all.txt` e BASHN contra `baseline/bash-n.txt`. Falhas intermitentes 1213/1205 no `centralvet_test` por suítes paralelas: rodar de novo isolado. Na Onda 5, além disso, CI (`scripts/ci/run.sh` local) termina com código 0.

## Ondas de execução

### Onda 1
- T-01, T-02, T-03, T-04, T-05, T-06

### Onda 2
- T-07, T-08, T-09, T-10, T-11, T-12, T-13

### Onda 3
- T-14, T-15, T-16, T-17, T-18

### Onda 4
- T-19

### Onda 5
- T-20

### Onda 6 — correção (revisão final)
- T-21, T-22, T-23

## Agentes

| Agente | subagent_type | model | Tasks |
|---|---|---|---|
| Sun Tzu — orquestrador | — | — | todas |
| Darwin | general-purpose | inherit | T-01 |
| Aang | general-purpose | inherit | T-02 |
| Jaspion | general-purpose | inherit | T-03 |
| Athena | general-purpose | inherit | T-04 |
| Naruto | general-purpose | inherit | T-05 |
| Hegel | general-purpose | inherit | T-06 |
| Spock | general-purpose | inherit | T-07 |
| Arquimedes | general-purpose | inherit | T-08 |
| Batman | general-purpose | inherit | T-09 |
| Yoda | general-purpose | inherit | T-10 |
| Thanos | general-purpose | inherit | T-11 |
| Kratos | general-purpose | inherit | T-12 |
| Tesla | general-purpose | inherit | T-13 |
| Maquiavel | general-purpose | inherit | T-14 |
| Goku | general-purpose | inherit | T-15 |
| Sherlock | general-purpose | inherit | T-16 |
| Platão | general-purpose | inherit | T-17 |
| Saitama | general-purpose | inherit | T-18 |
| Levi | general-purpose | inherit | T-19 |
| Gandalf (docs) | geduc:documentador | sonnet | T-20 |
| Arquimedes (correção) | general-purpose | inherit | T-21 |
| Sherlock (correção) | general-purpose | inherit | T-22 |
| Gandalf (correção docs) | geduc:documentador | sonnet | T-23 |

## Review Focus
- Administrador de tenant `enterprise`/`pro` abre `HospitalizationBoard`, `AiCopilotPanel` ou `CommunicationComposeForm`; tenant sem assinatura (legado) abre os mesmos → bloqueio com `Este módulo ainda não está disponível no piloto` só para o primeiro; o legado segue com acesso → T-07
- Assinatura Pix com cancelamento agendado chega ao fim do período → nenhuma fatura Pix nova emitida, status `cancelled` depois do reconciliador, e a exportação segue abrindo (`ALWAYS_ALLOWED`) → T-08
- Exportação do tenant A → nenhuma linha do tenant B e nenhuma coluna `password`/`token`/`secret`/`hash` no ZIP → T-11
- POST direto ao checkout com plano fora de `OFFER_PLANS` → 422 em `plan_id`, sem `billing_order` nem chamada ao Stripe → T-13
- Usuário convidado no primeiro login recusa ou ignora o aceite → login não segue; aceitar duas vezes não duplica linha em `legal_acceptance` → T-16

## Critérios gerais de aceite
- SUITE completo sem filtro de classe termina com `Failed: 0` ao fim de cada onda, sem classe de integração nova em `SKIP`.
- LINTALL e BASHN sem nenhuma linha nova em relação a `baseline/php-lint-all.txt` e `baseline/bash-n.txt`.
- CI local (`CI_PROJECT=centralvet-ci-local CI_WORKDIR=var/ci/local IMAGE_TAG=ci-local scripts/ci/run.sh`) termina com código 0 na Onda 5.
- `git diff task/etapa-4-operacao..HEAD | grep -cE '^[+].*(sk_live_|sk_test_|rk_live_)[A-Za-z0-9]+'` imprime `0`.
- `sha256sum .env` igual no início e no fim da execução; `SELECT COUNT(*) FROM centralvet.schema_migrations` igual no início e no fim.
- Toda tela nova sem `text-align:right`/`center` em rótulo e com `.cv-touch-target` nas ações (conferido nos `*WiringTest`).

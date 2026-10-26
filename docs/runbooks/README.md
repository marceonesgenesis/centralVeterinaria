# Runbooks operacionais

Procedimentos práticos para operar o Central Vet Pro: desenvolvimento local,
release, backup e restauração, agendamento, alertas e hospedagem compartilhada.
Staging e produção ainda não têm infraestrutura contratada neste repositório;
onde aplicável, os runbooks indicam o que muda nesses ambientes.

- [`local-environment.md`](./local-environment.md) — subir o ambiente local com Docker Compose.
- [`tests.md`](./tests.md) — rodar a suíte de testes automatizados.
- [`migrations.md`](./migrations.md) — aplicar uma migration versionada.
- [`migration-rollback.md`](./migration-rollback.md) — reverter uma migration mal aplicada.
- [`restore-backup.md`](./restore-backup.md) — backup cifrado (Docker/VPS e hospedagem), cópia externa, retenção, restauração e ensaio.
- [`ensaio-restauracao-rollback.md`](./ensaio-restauracao-rollback.md) — ensaio de restauração e de rollback de release, com RTO e RPO medidos.
- [`release.md`](./release.md) — build por commit, imagem por SHA, manifesto, preflight, `migration-status`, fumaça e rollback.
- [`smoke-billing.md`](./smoke-billing.md) — roteiro de fumaça da cobrança com o Stripe de teste.
- [`cron.md`](./cron.md) — agendamento: crontab do host Docker e agendador da hospedagem compartilhada.
- [`observabilidade.md`](./observabilidade.md) — `ops-monitor`, as 9 chaves de alerta, `BILLING_OPERATOR_EMAIL` e a deduplicação de 6 h.
- [`shared-hosting-mysql57.md`](./shared-hosting-mysql57.md) — hospedagem compartilhada com MySQL 5.7: migrations, cron, backup e restauração.
- [`build-versioning.md`](./build-versioning.md) — estratégia de tag de imagem e a CI (`scripts/ci/run.sh`).
- [`environments.md`](./environments.md) — configuração esperada por ambiente (development/staging/production).
- [`internacao.md`](./internacao.md) — fluxos, regras, banco, permissões e aplicação da internação (Fase 6A).
- [`cirurgia.md`](./cirurgia.md) — fluxos, regras, banco, permissões e aplicação da cirurgia (Fase 6B).
- [`comunicacao.md`](./comunicacao.md) — consentimento, templates, e-mail/WhatsApp, agendador, LGPD e Central de Pendências (Fase 7A).
- [`documentos.md`](./documentos.md) — PDFs assíncronos, storage privado, versionamento, download com RBAC, aviso `document_ready` e operação (Fase 7B).
- [`ia-assistente.md`](./ia-assistente.md) — assistente de IA: casos de uso, ativação por tenant, variáveis `AI_*`, custo, falhas, LGPD e incidente (Fase 8A).
- [`../security/lgpd-retencao.md`](../security/lgpd-retencao.md) — retenção de dados pessoais: o que expira, quando e o que fica.
- [`../security/csrf-inventario.md`](../security/csrf-inventario.md) — inventário de métodos de leitura e escrita da proteção CSRF (ver também a seção "Proteção CSRF" de `environments.md`).

Nenhum destes procedimentos envolve credencial real. Comandos de escrita em
banco (migration, rollback, restore) exigem autorização explícita antes de
qualquer execução, conforme `src/app/database/migrations/README.md` e as
notas de execução da Fase 0.

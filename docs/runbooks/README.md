# Runbooks operacionais

Procedimentos práticos para operar a fundação do Central Vet Pro em
desenvolvimento. Staging e produção ainda não são implantados nesta fase
(sem domínio, TLS ou infraestrutura real contratada); onde aplicável, os
runbooks indicam o que muda nesses ambientes.

- [`local-environment.md`](./local-environment.md) — subir o ambiente local com Docker Compose.
- [`tests.md`](./tests.md) — rodar a suíte de testes automatizados.
- [`migrations.md`](./migrations.md) — aplicar uma migration versionada.
- [`migration-rollback.md`](./migration-rollback.md) — reverter uma migration mal aplicada.
- [`restore-backup.md`](./restore-backup.md) — restaurar o MySQL a partir de um backup.
- [`build-versioning.md`](./build-versioning.md) — estratégia de tag de imagem e o pipeline de CI.
- [`environments.md`](./environments.md) — configuração esperada por ambiente (development/staging/production).
- [`internacao.md`](./internacao.md) — fluxos, regras, banco, permissões e aplicação da internação (Fase 6A).
- [`cirurgia.md`](./cirurgia.md) — fluxos, regras, banco, permissões e aplicação da cirurgia (Fase 6B).
- [`comunicacao.md`](./comunicacao.md) — consentimento, templates, e-mail/WhatsApp, agendador, LGPD e Central de Pendências (Fase 7A).
- [`documentos.md`](./documentos.md) — PDFs assíncronos, storage privado, versionamento, download com RBAC, aviso `document_ready` e operação (Fase 7B).

Nenhum destes procedimentos envolve credencial real. Comandos de escrita em
banco (migration, rollback, restore) exigem autorização explícita antes de
qualquer execução, conforme `src/app/database/migrations/README.md` e as
notas de execução da Fase 0.

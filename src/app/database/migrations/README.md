# Central Vet migrations

Arquivos numerados deste diretório são artefatos auditáveis e não são
executados automaticamente pela aplicação.

Os SQLs originais exigem MySQL 8. Para instalação inicial em MySQL 5.7,
use o preparador documentado em
[`shared-hosting-mysql57.md`](../../../../docs/runbooks/shared-hosting-mysql57.md).
Ele preserva as validações CHECK com triggers e gera um manifesto próprio;
não execute os SQLs originais diretamente nessa versão.

Procedimento obrigatório:

1. validar versão, banco-alvo e precondições, e listar o que falta com
   `scripts/db/migration-status.sh` (Docker/VPS) ou
   `php bin/migration-status.php` (hospedagem): `pending <versão>` é o que
   ainda não foi aplicado;
2. gerar e testar um backup (`scripts/backup.sh` ou `php bin/hosting-backup.php`;
   ver `docs/runbooks/restore-backup.md`);
3. calcular o SHA-256 do SQL aprovado e substituir o placeholder do registro;
4. apresentar efeito e risco e obter autorização SQL específica;
5. aplicar uma única vez com o usuário de migration dedicado (`MIGRATION_DB_USER`/
   `MIGRATION_DB_PASSWORD` em `.env`, privilégio mínimo em `centralvet`.* apenas)
   — nunca com o usuário root nem com o usuário de runtime da aplicação;
6. executar o arquivo `.verify.sql`, que contém somente `SELECT`, e rodar
   `migration-status` de novo: o esperado é `pending=0 unknown=0`.

A `20261026_0019_ops_monitoring.sql` cria `ops_heartbeat` e `ops_alert_state`,
usadas pelo backup, pelo reconciliador, pela retenção e pelo `ops-monitor`.
Aplique-a antes de agendar essas rotinas (`docs/runbooks/cron.md`); na
hospedagem, ver a seção "Migration 0019" de
[`shared-hosting-mysql57.md`](../../../../docs/runbooks/shared-hosting-mysql57.md).

DDL MySQL não é transacional. Em falha parcial, interrompa e inspecione
`information_schema`; não faça rollback destrutivo ou nova tentativa automática.

# Aplicar uma migration

As migrations em `src/app/database/migrations/` são artefatos auditáveis e
**nunca** são executadas automaticamente pela aplicação ou pelo build da
imagem. Este runbook aponta para o procedimento já normativo em
[`src/app/database/migrations/README.md`](../../src/app/database/migrations/README.md)
e o detalha operacionalmente.

> Antes de executar qualquer passo com efeito real (DDL/DML), é obrigatório
> obter autorização SQL específica: objetos afetados, efeito, risco e plano
> de backup apresentados e aprovados explicitamente. Esta skill/checkpoint é
> reforçada pelo próprio ambiente (`sql-write-approval`).

## Procedimento

1. **Validar** a versão, o banco-alvo e as precondições da migration
   (`.sql` numerado, ex. `20260920_0001_foundation_multitenancy.sql`).
2. **Gerar e testar um backup** antes de qualquer alteração:

   ```bash
   make backup
   gzip -t var/backups/centralvet-<timestamp>.sql.gz
   ```

   Ver [`restore-backup.md`](./restore-backup.md) para o procedimento completo.
3. **Calcular o checksum** do SQL aprovado e substituir o placeholder do
   registro de auditoria da migration (SHA-256):

   ```bash
   sha256sum src/app/database/migrations/<arquivo>.sql
   ```

4. **Apresentar efeito e risco** (tabelas/colunas/índices afetados, se é
   reversível, impacto em dados existentes) e **obter autorização SQL
   específica** antes de aplicar. Não aplicar nada sem essa autorização.
5. **Aplicar uma única vez**, usando um usuário de migration com privilégio
   mínimo (não o usuário runtime da aplicação):

   ```bash
   docker compose exec -T mysql sh -ceu \
     'exec mysql -u "$MIGRATION_USER" -p"$MIGRATION_PASSWORD" "$MYSQL_DATABASE"' \
     < src/app/database/migrations/<arquivo>.sql
   ```

6. **Executar o `.verify.sql`** correspondente (contém somente `SELECT`) e
   conferir que o resultado é o esperado (contagens, ausência de órfãos,
   FKs/índices criados):

   ```bash
   docker compose exec -T mysql sh -ceu \
     'exec mysql -u "$MIGRATION_USER" -p"$MIGRATION_PASSWORD" "$MYSQL_DATABASE"' \
     < src/app/database/migrations/<arquivo>.verify.sql
   ```

## Falha parcial

DDL do MySQL não é transacional. Em falha parcial: **interrompa**, inspecione
`information_schema` para identificar o que foi ou não aplicado, e **não**
faça rollback destrutivo nem tente novamente automaticamente. Veja
[`migration-rollback.md`](./migration-rollback.md) para reverter uma
migration já aplicada incorretamente.

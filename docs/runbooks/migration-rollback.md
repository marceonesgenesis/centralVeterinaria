# Rollback de uma migration mal aplicada

O projeto não usa uma ferramenta de migration com "down" automático — cada
arquivo em `src/app/database/migrations/` é DDL/DML puro e auditável. DDL do
MySQL não é transacional, então "desfazer" não é uma operação genérica:
trate cada caso combinando as opções abaixo, sempre sob a mesma exigência de
autorização SQL específica de
[`migrations.md`](./migrations.md).

> Nunca execute rollback sem antes ter um backup **anterior** à migration
> (ver [`restore-backup.md`](./restore-backup.md)) e sem autorização
> explícita para o rollback em si.

## 1. Diagnosticar o estado real primeiro

Antes de reverter qualquer coisa, confirme o que efetivamente foi aplicado:

```bash
docker compose exec -T mysql sh -ceu \
  'exec mysql -u root -p"$MYSQL_ROOT_PASSWORD" -e "SHOW CREATE TABLE <tabela>\G"' \
  "$MYSQL_DATABASE"
```

Compare com o `.verify.sql` da migration e com `information_schema` (colunas,
índices, FKs) para identificar exatamente o que precisa ser desfeito.

## 2. Opção preferida — restaurar o backup pré-migration

Se existir um backup íntegro tirado imediatamente antes da migration (passo
obrigatório do runbook de migrations), a forma mais segura de rollback é
restaurar esse backup, e não tentar um DDL inverso manual:

```bash
CONFIRM_RESTORE=RESTORE_CENTRALVET ./scripts/restore.sh var/backups/<arquivo-pre-migration>.sql.gz
```

Isso descarta qualquer dado gravado depois do backup — por isso a decisão de
usar esta opção também exige autorização explícita, avaliando o que seria
perdido.

## 3. Opção alternativa — DDL de reversão específico

Quando restaurar o backup completo não é aceitável (ex.: dados legítimos
gravados depois da migration precisam ser preservados), escreva um novo
arquivo de migration reverso, numerado após a última migration existente,
seguindo o mesmo processo normativo (`src/app/database/migrations/README.md`):
checksum, apresentação de efeito/risco, autorização, aplicação única,
`.verify.sql` próprio. Nunca edite ou reaplique o arquivo original.

## 4. Depois do rollback

- Registrar no histórico de execução (`notes.md`/ADR relevante) o que deu
  errado, a opção de rollback usada e a evidência do `.verify.sql` pós-rollback.
- Se o rollback foi por restauração de backup, revalidar a aplicação
  (`docker compose exec app php tests/run.php`, `/health`) antes de liberar
  o ambiente novamente.

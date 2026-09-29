# Restaurar o MySQL a partir de um backup

`scripts/backup.sh` gera um dump lógico consistente
(`mysqldump --single-transaction --quick --routines --triggers`) em
`var/backups/centralvet-<timestamp>.sql.gz` (ignorado pelo Git,
`umask 077`). `scripts/restore.sh` faz o caminho inverso.

## 1. Gerar/validar um backup

```bash
make backup
gzip -t var/backups/centralvet-<timestamp>.sql.gz
```

`gzip -t` só valida a integridade do arquivo compactado; não é um teste real
de recuperação (isso exige restaurar em um banco descartável e conferir a
aplicação — ainda não automatizado neste projeto).

## 2. Restaurar (exige autorização explícita)

A restauração **sobrescreve** o banco-alvo. Obtenha autorização explícita
antes de rodar:

```bash
CONFIRM_RESTORE=RESTORE_CENTRALVET ./scripts/restore.sh var/backups/<arquivo>.sql.gz
# ou: make restore FILE=var/backups/<arquivo>.sql.gz
```

Restrições impostas pelo próprio script:

- só aceita arquivos `.sql.gz` dentro de `var/backups/`;
- recusa rodar sem a variável `CONFIRM_RESTORE=RESTORE_CENTRALVET`;
- usa a credencial root apenas dentro do container MySQL (nunca exposta fora dele).

## 3. Depois de restaurar

1. Conferir que os serviços seguem saudáveis:

   ```bash
   make diagnose
   ```

2. Rodar a suíte de testes para detectar qualquer regressão de schema
   (ver [`tests.md`](./tests.md)):

   ```bash
   docker compose exec app php tests/run.php
   ```

3. Se a restauração foi consequência de uma migration mal aplicada, seguir
   também o registro em [`migration-rollback.md`](./migration-rollback.md).

## Limitações conhecidas (herdadas de `README.md`)

- é um dump lógico só do MySQL; volumes Redis não são incluídos;
- sem criptografia, envio para storage externo, retenção automática ou PITR;
- backup/restore exigem o serviço MySQL em execução;
- não há snapshot automático anterior ao restore nem rollback automático do
  próprio restore — por isso o passo 1 (gerar/validar um backup atual antes
  de restaurar um mais antigo) é obrigatório quando o estado atual também
  importa.

Em produção (não implantada nesta fase), os backups devem ser criptografados,
copiados para armazenamento externo e submetidos regularmente a teste de
restauração, de acordo com o RPO/RTO definido no PRD.

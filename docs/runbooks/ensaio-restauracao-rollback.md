# Ensaio de restauração e de rollback de release

Prova, com números, os critérios "restauração comprovada" e "rollback ensaiado"
da Etapa 4. Tudo roda no projeto Compose isolado `centralvet-drill` (porta
18081, volumes próprios, env-file `var/drill/.env`); o projeto `centralvet`
(dev) só é lido. No fim de cada execução, o `centralvet-drill` é removido com
`down -v` (e os volumes `centralvet-drill_*` que sobrarem).

## Execução de 2026-10-26

- Commit anterior: `992877aeebd682b33271bf813aec49e5e6959093` (fim da Etapa 3).
- Commit novo: `1a3d9b0256feef5683d863beedc5be741efe8b63` (HEAD da branch
  `task/etapa-4-operacao` no início da Onda 4).
- Backup do dev (pilha Docker, `scripts/backup.sh`):
  `var/drill/backups/centralvet-20261026T140417Z.tar.gpg`, 76564 bytes,
  `minio=included`, 16 migrations registradas.
- Backup da hospedagem (`php bin/hosting-backup.php`):
  `var/drill/backups-hosting/centralvet-20261026T142249Z.tar.gz.cvbk`, 63613 bytes.

### Restauração verificada (`scripts/restore-drill.sh`)

`.tar.gpg` (pilha Docker):

```text
restore-drill verify files_count manifest=12 restored=12
restore-drill verify documents_count manifest=0 restored=0
restore-drill verify migrations manifest=16 restored=16
restore-drill done elapsed_seconds=20 decrypt_seconds=1 db_seconds=8 files_seconds=1 mismatches=0
```

`.tar.gz.cvbk` (hospedagem compartilhada):

```text
restore-drill verify documents_count manifest=0 restored=0
restore-drill verify migrations manifest=16 restored=16
restore-drill done elapsed_seconds=13 decrypt_seconds=0 db_seconds=2 files_seconds=1 mismatches=0
```

Código de saída 0 nas duas.

### Rollback de release (`scripts/release/rollback-drill.sh`)

```text
rollback-drill start old=992877aeebd682b33271bf813aec49e5e6959093 new=1a3d9b0256feef5683d863beedc5be741efe8b63 backup=centralvet-20261026T140417Z.tar.gpg size_bytes=76564
rollback-drill phase=build seconds=23
rollback-drill phase=restore seconds=14
rollback-drill migration-status after=restore
pending 20261015_0015_phase8b_copilot
pending 20261026_0018_billing_order_refund
pending 20261026_0019_ops_monitoring
migration-status database=centralvet applied=16 pending=3 unknown=0
rollback-drill phase=deploy_old seconds=12
rollback-drill smoke after=deploy_old smoke passed=6 failed=0
rollback-drill pre_backup file=centralvet-20261026T144807Z.tar.gpg size_bytes=76576
rollback-drill phase=pre_backup seconds=1
rollback-drill migrate applied=20261015_0015_phase8b_copilot sha256=a618bd1444877c31b951b76ff3ef62f9c460ae48beaba0c31084929725f6b45e
rollback-drill migrate applied=20261026_0018_billing_order_refund sha256=bca0825f00c9be43d130deb83e58306188afe514853380d05569726bd36487e0
rollback-drill migrate applied=20261026_0019_ops_monitoring sha256=0e4a5a135e248442a6b9d6b46dc6282232c56cabf2664d7bc8d09bc27669e741
rollback-drill phase=migrate seconds=1
rollback-drill migration-status after=migrate
migration-status database=centralvet applied=19 pending=0 unknown=0
rollback-drill phase=deploy_new seconds=7
rollback-drill smoke after=deploy_new smoke passed=6 failed=0
rollback-drill phase=rollback seconds=16
rollback-drill smoke after=rollback smoke passed=6 failed=0
rollback-drill migration-status after=rollback
pending 20261015_0015_phase8b_copilot
pending 20261026_0018_billing_order_refund
pending 20261026_0019_ops_monitoring
migration-status database=centralvet applied=16 pending=3 unknown=0
rollback-drill done total_seconds=74 smoke_failures=0
```

Código de saída 0. Depois do `migrate`, `pending 20261026_0019_ops_monitoring`
some; depois do `rollback`, volta. O backup do dev não registra a 0015 em
`schema_migrations` (o banco de dev nunca recebeu essa linha); o ensaio a
aplicou junto com a 0018 e a 0019 sem erro.

O tempo de `build` é com o cache do Docker e do Composer já aquecido; a
primeira construção de um commit leva mais (download das imagens base e do
Packagist).

### RTO e RPO medidos

| Medida | Valor | Origem |
|---|---|---|
| RTO restauração, pilha Docker (`.tar.gpg`) | 20 s | `restore-drill done elapsed_seconds=20` |
| RTO restauração, hospedagem (`.tar.gz.cvbk`) | 13 s | `restore-drill done elapsed_seconds=13` |
| RTO rollback de release (restore pré-migration + imagem anterior + fumaça) | 16 s | `rollback-drill phase=rollback seconds=16` |
| Ensaio completo de release e rollback | 74 s | `rollback-drill done total_seconds=74` |
| RPO | 24 h | backup diário pelo cron (o alerta `backup_stale` dispara após 26 h) |

Os tempos valem para o volume atual de dados do dev (backup de ~75 KB). Em
produção, o RTO cresce com o tamanho do dump e dos arquivos: repita o ensaio
com um backup recente de produção antes de prometer um prazo.

## Como repetir

Pré-requisitos: Docker com rede para o Packagist e as imagens base; um backup
cifrado e a frase em `var/drill/passphrase` (`openssl rand`, fora do Git);
porta 18081 livre. O `var/drill/.env` é gerado pelo `restore-drill.sh` na
primeira execução, com segredos aleatórios.

1. Gerar o backup do dev, sem gravar heartbeat no banco de dev:

   ```bash
   BACKUP_DIR=var/drill/backups BACKUP_PASSPHRASE_FILE=var/drill/passphrase \
     scripts/backup.sh --no-heartbeat
   ```

   Para o formato da hospedagem, `php bin/hosting-backup.php --no-heartbeat`
   com `BACKUP_DIR=var/drill/backups-hosting` (ver o relatório da T-17 para o
   comando no container com `--user 1000:33`).

2. Ensaiar a restauração dos dois formatos:

   ```bash
   BACKUP_DIR=var/drill/backups BACKUP_PASSPHRASE_FILE=var/drill/passphrase \
     scripts/restore-drill.sh var/drill/backups/centralvet-<AAAAMMDDTHHMMSSZ>.tar.gpg; echo $?
   BACKUP_DIR=var/drill/backups-hosting BACKUP_PASSPHRASE_FILE=var/drill/passphrase \
     scripts/restore-drill.sh var/drill/backups-hosting/centralvet-<AAAAMMDDTHHMMSSZ>.tar.gz.cvbk; echo $?
   ```

   Esperado: `mismatches=0` e código 0.

3. Ensaiar o rollback (passe SHAs fixos quando outros commits estiverem
   entrando na branch):

   ```bash
   BACKUP_DIR=var/drill/backups BACKUP_PASSPHRASE_FILE=var/drill/passphrase \
     scripts/release/rollback-drill.sh <commit-anterior> <commit-novo> \
     var/drill/backups/centralvet-<AAAAMMDDTHHMMSSZ>.tar.gpg; echo $?
   ```

   O script constrói `centralvet/app:<sha40>` dos dois commits
   (`build-release.sh --image`), restaura o backup, sobe a versão anterior,
   roda a fumaça, faz o backup pré-migration (`backup.sh --project
   centralvet-drill --no-heartbeat`, num diretório temporário em `var/drill`,
   apagado no fim), aplica cada `pending` de `migration-status.sh` a partir do
   SQL do commit novo (placeholder de checksum trocado pelo SHA-256 do arquivo)
   com um usuário de migration criado só no ensaio (`cv_drill_migration`,
   `ALL` só em `centralvet.*`, senha aleatória descartada), sobe a versão nova,
   roda a fumaça e faz o rollback: para `app worker nginx`, restaura o backup
   pré-migration, sobe a imagem anterior e roda a fumaça.

   Esperado: as 7 linhas `rollback-drill phase=`, `smoke_failures=0` e
   código 0 (1 com falha de fumaça, 2 uso ou configuração, 3 falha de fase,
   com o fim do log do comando que falhou).

4. Conferir que nada sobrou e que o dev não mudou:

   ```bash
   docker volume ls -q --filter name=centralvet-drill   # nenhuma linha
   docker compose ps --services --status running         # mesmos serviços de antes
   ```

5. Atualizar a seção "Execução" deste runbook com a data, os commits, os
   tamanhos e as saídas novas.

## Observação sobre o build

O `rollback-drill.sh` chama o `build-release.sh` com `umask 022`. Com
`umask 077`, o `git archive` extraído fica com arquivos 0600, a imagem copia
essas permissões e o php-fpm (www-data) não lê
`/usr/local/etc/php-fpm.d/zz-centralvet.conf`: a `app` fica `unhealthy`.
Construa imagens de release sempre com umask 022.

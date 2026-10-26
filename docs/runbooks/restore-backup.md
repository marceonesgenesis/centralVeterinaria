# Backup e restauração

Dois formatos, um contrato: o pacote tem um `MANIFEST` `format=centralvet-backup-v1`
com contagens e um `sha256` por parte, e a restauração confere tudo antes de
escrever. Prova e tempos: [`ensaio-restauracao-rollback.md`](./ensaio-restauracao-rollback.md).

| Alvo | Gerar | Arquivo | Cifra |
|---|---|---|---|
| Docker/VPS | `scripts/backup.sh` | `centralvet-<AAAAMMDDTHHMMSSZ>.tar.gpg` | `gpg --symmetric AES256` |
| Hospedagem compartilhada | `php bin/hosting-backup.php` | `centralvet-<AAAAMMDDTHHMMSSZ>.tar.gz.cvbk` | `CVBK1` (AES-256-CTR + HMAC-SHA256) |

Conteúdo: `MANIFEST`, `db.sql.gz`, `files.tar.gz` (`src/files`),
`documents.tar.gz` e, no Docker com o volume do MinIO, `minio.tar.gz`
(`minio=included`; na hospedagem, `minio=skipped`).

## 1. Configurar

No `.env` (ou no ambiente do cron):

| Variável | Uso |
|---|---|
| `BACKUP_PASSPHRASE_FILE` | **obrigatória**: caminho de um arquivo com a frase (primeira linha), permissão 0600 |
| `BACKUP_DIR` | destino (padrão `var/backups`; relativo vale a partir da raiz do projeto) |
| `BACKUP_RETENTION_DAYS` | dias que os backups locais ficam (padrão 14) |
| `BACKUP_OFFSITE_DIR` | cópia externa em diretório montado (disco, NFS) |
| `BACKUP_OFFSITE_S3_URI`, `BACKUP_OFFSITE_S3_ENDPOINT` | cópia para S3-compatível (só `backup.sh`); credenciais S3 no ambiente |

**A frase fica fora do repositório e fora do backup.** Gere-a com
`openssl rand -base64 32 > /caminho/seguro/backup.pass && chmod 600 ...` e
guarde uma segunda cópia em cofre separado dos arquivos de backup: sem ela, o
backup é irrecuperável, e com ela junto do arquivo a cifra não protege nada.
Girar a frase vale só para os backups novos; mantenha a antiga enquanto houver
arquivo cifrado com ela.

## 2. Gerar

```bash
make backup                       # scripts/backup.sh (Docker/VPS)
php bin/hosting-backup.php        # hospedagem, a partir da raiz de src/
```

Saída: `backup: pruned <arquivo>` (retenção), `backup: offsite copy <destino>`
e, por último, `Backup created: <caminho> parts=<n> bytes=<n>`. Códigos: 0
criado; 2 configuração (frase ausente, opção inválida); 3 falha de dump ou
arquivo (nada parcial fica em `BACKUP_DIR`); 4 cópia externa falhou (o arquivo
local fica e o heartbeat não é gravado). A retenção só apaga
`centralvet-*.tar.gpg`/`*.tar.gz.cvbk` mais antigos que o prazo, nunca o
recém-criado. Um sucesso grava o heartbeat `backup` em `ops_heartbeat`
(`backup: heartbeat not recorded` se o INSERT falhar). No **dev**, use sempre
`--no-heartbeat`. Agendamento diário: [`cron.md`](./cron.md); alerta
`backup_stale` após 26 h: [`observabilidade.md`](./observabilidade.md).

**Cópia externa não é opcional em produção**: um backup só no mesmo disco do
banco não sobrevive ao disco. Confirme periodicamente que o arquivo chegou ao
destino e que ele abre (passo 3).

## 3. Ensaiar a restauração (sem tocar o ambiente real)

`scripts/restore-drill.sh <arquivo>` (`make restore-drill FILE=...`) restaura
em um projeto Compose descartável (`centralvet-drill`, porta 18081), compara
contagens com o `MANIFEST` e remove tudo. Esperado: `mismatches=0` e código 0.
Faça-o depois de mudar o formato, a frase ou o servidor, e ao menos uma vez por
trimestre com um backup recente de produção. Passo a passo e tempos:
[`ensaio-restauracao-rollback.md`](./ensaio-restauracao-rollback.md). Para o
formato da hospedagem, o ensaio também roda pela pilha Docker: o drill
decifra o `.cvbk` com `php bin/hosting-backup.php --decrypt` no container.

## 4. Restaurar na pilha Docker (exige autorização explícita)

A restauração **recria o banco** e **substitui** `files`, `documents` e, se
houver, o volume do MinIO. Gere antes um backup do estado atual
(`scripts/backup.sh`) e obtenha autorização:

```bash
CONFIRM_RESTORE=RESTORE_CENTRALVET ./scripts/restore.sh var/backups/<arquivo>
# ou: make restore FILE=var/backups/<arquivo>
```

Aceita `.tar.gpg`, `.tar.gz.cvbk` e `.sql.gz` (dump antigo só do banco), todos
dentro de `BACKUP_DIR`. Confere o `MANIFEST` e os `sha256` antes de escrever.
`CONFIRM_RESTORE` é `RESTORE_<PROJETO>` (maiúsculas, `-` vira `_`; para outro
projeto, `--project <nome>`). Códigos: 0 restaurado; 2 uso ou configuração; 3
sem confirmação; 5 frase errada, falha de decifragem ou de integridade (nada
foi escrito); 6 falha depois de iniciar a escrita (restaure de novo ou use o
backup anterior). Imprime `restore: timings decrypt_seconds=<n> db_seconds=<n>
files_seconds=<n>` e a linha final.

## 5. Restaurar na hospedagem compartilhada

Sem Docker nem `gpg`: veja "Backup e restauração na hospedagem" em
[`shared-hosting-mysql57.md`](./shared-hosting-mysql57.md) (decifrar com
`--decrypt`, importar `db.sql.gz`, repor `files` e `documents`).

## 6. Depois de restaurar

1. `make diagnose` e `scripts/release/smoke.sh <url>`.
2. `scripts/db/migration-status.sh` (ou `php bin/migration-status.php`): o
   backup pode ser anterior a migrations já aplicadas; aplique as pendentes
   com autorização ([`migrations.md`](./migrations.md)).
3. Se foi consequência de uma migration mal aplicada, registre também em
   [`migration-rollback.md`](./migration-rollback.md).
4. Rode o `bin/billing-reconcile.php` uma vez: ele recupera do Stripe os
   eventos recebidos depois do backup.

## Limites

- Backup lógico diário: RPO de 24 h (medido no ensaio); sem PITR.
- Redis não entra (sessões e filas são descartáveis).
- O RTO medido (20 s Docker, 13 s hospedagem) vale para o volume do dev; repita
  o ensaio com um backup de produção antes de prometer um prazo.

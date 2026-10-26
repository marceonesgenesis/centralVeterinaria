# Release, preflight e rollback

Um release sai de um **commit identificado**, nunca da árvore de trabalho. O
mesmo commit gera dois artefatos: o pacote `.tar.gz` para a hospedagem
compartilhada e a imagem `centralvet/app:<sha40>` para Docker/VPS. A prova de
rollback, com tempos medidos, está em
[`ensaio-restauracao-rollback.md`](./ensaio-restauracao-rollback.md).

## 1. Construir o release

```bash
umask 022
scripts/release/build-release.sh <commit>            # pacote da hospedagem
scripts/release/build-release.sh --image <commit>    # pacote + imagens Docker
```

Passe o SHA fixo (`git rev-parse <ref>`), não `HEAD`, quando outros commits
estão entrando. O script exporta o commit com `git archive`, instala
`src/vendor` com `composer:2.8.11 --no-dev --no-scripts` e grava:

- `var/releases/centralvet-<sha7>.tar.gz` (`src/` + `RELEASE_MANIFEST`) e o
  `.sha256` ao lado;
- com `--image`, `centralvet/app:<sha40>` e `centralvet/worker:<sha40>`, do
  mesmo export. Nenhuma imagem é publicada.

`RELEASE_MANIFEST` traz `commit=`, `built_at=`, `php_target=8.4`,
`composer_lock_sha256=` e uma linha `migration <NNNN> sha256=<hex>` por
migration do commit: é a lista que o `migration-status` e o operador comparam
com o banco. `var/releases/` é ignorado pelo Git. Códigos de saída: 0, 1 falha
de build, 2 commit desconhecido ou uso.

**Use `umask 022`.** Com `umask 077` os arquivos exportados ficam 0600, a
imagem os copia assim e o php-fpm (`www-data`) não lê a própria configuração:
a `app` fica `unhealthy`.

## 2. Preflight de produção

Antes de liberar tráfego, e de novo depois de cada mudança de `.env`:

```bash
# Docker/VPS (dentro do container app; o arquivo de proxies precisa estar
# legível dentro dele, por exemplo montado com -v em docker compose run)
docker compose exec -T app php bin/preflight.php --with-db
docker compose run --rm --no-deps \
  -v "$PWD/docker/nginx/trusted-proxies.production.conf:/tmp/trusted-proxies.conf:ro" \
  app php bin/preflight.php --trusted-proxies-file=/tmp/trusted-proxies.conf
# Hospedagem (a partir da raiz de src/)
php bin/preflight.php --with-db
```

Saída: uma linha `<NÍVEL> <código>` por achado e, por último,
`preflight fail=<n> warn=<n> pass=<n>`. Nunca imprime valores. Sai 1 com algum
FAIL, 0 sem FAIL e 2 em argumento inválido. Checagens:

| Código | FAIL quando |
|---|---|
| `app_env` | `APP_ENV` não é `production` |
| `app_debug`, `display_errors` | `APP_DEBUG=true` ou `display_errors` ligado |
| `public_url_https` | `APP_PUBLIC_URL` não começa com `https://` |
| `app_seed` | `APP_SEED` com menos de 32 caracteres ou igual ao do exemplo |
| `db_password`, `redis_password` | senha vazia ou `change-this...` (Redis: WARN se vazia) |
| `smtp_driver`, `smtp_from` | `COMMUNICATION_EMAIL_DRIVER` não é `smtp`, ou remetente vazio/`.invalid` |
| `operator_email` | `BILLING_OPERATOR_EMAIL` vazio |
| `support_email` | `SUPPORT_EMAIL` (e-mail de suporte mostrado ao cliente) vazio, inválido ou terminado em `.invalid` |
| `storage_credentials` | S3 com credencial vazia ou de exemplo (`minioadmin`) |
| `stripe_secret_key`, `stripe_webhook_secret` | chave ou segredo ausente (chave que não é de produção: WARN) |
| `trust_forwarded_proto` | WARN com `TRUST_FORWARDED_PROTO=true` (ver "Produção" em [`environments.md`](./environments.md)) |
| `ai_provider` | `AI_PROVIDER=stub` |
| `php_version`, `php_extensions` | PHP diferente de 8.4 ou extensão obrigatória ausente |
| `trusted_proxies` | com `--trusted-proxies-file`, arquivo ilegível ou com faixa ampla (só o IP do proxy da frente) |
| `db_version`, `migrations_pending` | com `--with-db`: MySQL que não é 8.0 nem 5.7 (5.7 sem `DB_STRICT_MODE=true`: WARN) ou migrations pendentes |

`--with-db` conecta pelo `DB_*` do ambiente (usuário de runtime). Com falha de
conexão imprime `FAIL db_version` e `FAIL migrations_pending`. No dev o
preflight acusa FAIL por desenho (ambiente de desenvolvimento).

## 3. Migrations: estado antes e depois

```bash
scripts/db/migration-status.sh [--database <nome>]   # Docker/VPS (no host)
php bin/migration-status.php                          # hospedagem (de src/)
```

Saída: `applied <versão>` / `pending <versão>` por arquivo, `unknown <versão>`
(no banco sem arquivo) e `migration-status database=<nome> applied=<n>
pending=<n> unknown=<n>`. Sai 0 com `pending=0 unknown=0`, 1 com algum pendente
ou desconhecido e 2 em argumento inválido ou erro. Só lê.

Aplicar migration continua exigindo backup e autorização SQL específica
([`migrations.md`](./migrations.md)); o release **não** aplica nada sozinho.
Aplique as pendentes **antes** de subir o código que as usa (ex.: 0019 antes do
`ops-monitor` e do backup com heartbeat). Confira `pending=0` ao final.

## 4. Subir a versão (Docker/VPS)

```bash
git rev-parse <ref>                                   # <sha40>
scripts/backup.sh                                     # backup pré-release
IMAGE_TAG=<sha40> docker compose up -d app worker nginx
scripts/release/smoke.sh http://127.0.0.1:${HTTP_PORT:-8081}
```

Na hospedagem: confira o `.sha256`, descompacte o pacote ao lado da versão
atual, troque o link/pasta e mantenha a anterior para o rollback.

## 5. Fumaça (`scripts/release/smoke.sh <base-url>`)

Seis verificações, uma linha cada (`smoke pass|fail <nome>`) e
`smoke passed=<n> failed=<n>`; sai 1 com qualquer falha:

| Verificação | Esperado |
|---|---|
| `live` | `/live` 200 |
| `health` | `/health` 200 |
| `landing` | `/` 200 |
| `login` | `/index.php?class=LoginForm` 200 |
| `download_blocked` | `/download.php?file=app/config/application.php` 404 |
| `config_blocked` | `/app/config/application.php` diferente de 200 |

O roteiro de cobrança (Stripe de teste) é outro:
[`smoke-billing.md`](./smoke-billing.md).

## 6. Rollback

1. Pare `app worker nginx` e confirme o dano com `smoke.sh` e o log.
2. Se **nenhuma migration** foi aplicada no release: suba a imagem anterior
   (`IMAGE_TAG=<sha40 anterior> docker compose up -d app worker nginx`) ou
   restaure a pasta anterior da hospedagem, e rode a fumaça.
3. Se migrations foram aplicadas: restaure o **backup pré-release** (passo 4)
   com `scripts/restore.sh` (Docker/VPS) ou o procedimento da hospedagem
   ([`restore-backup.md`](./restore-backup.md)); isso descarta o que foi
   gravado depois do backup, então exige autorização. Suba a imagem anterior.
4. Rode `migration-status`: as migrations do release voltam a `pending`.
   Rode a fumaça.
5. Registre o motivo e a evidência no histórico da execução.

O ensaio completo (`scripts/release/rollback-drill.sh <anterior> <novo>
<backup>`) mediu 16 s para o rollback e 74 s para todo o ciclo, com
`smoke_failures=0`.

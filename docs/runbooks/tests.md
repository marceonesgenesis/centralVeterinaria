# Rodar a suíte de testes automatizados

A suíte real (`CentralVet\Tests\`) usa um runner próprio em
`src/tests/run.php` (sem PHPUnit) e roda testes de integração reais contra o
Redis do Compose. O container `app` já precisa estar de pé (ver
[`local-environment.md`](./local-environment.md)).

## Dentro do container (forma de referência, usada pela CI)

```bash
docker compose exec app php tests/run.php
# ou, via script do composer.json:
docker compose exec app composer test:unit
```

Saída esperada (evidência do Gate T-11, revalidada após a correção de build):

```
Total: 75, Passed: 75, Failed: 0, Skipped: 0
```

## Isolamento do Redis de teste

As sessões do navegador (`SESSION_PREFIX`, padrão `centralvet:session:`) e o
registro de sessão única ficam no banco Redis da aplicação, que o
`docker-compose.yml` injeta no container `app` como `REDIS_DATABASE=0`. Para
a suíte nunca dividir esse banco com a aplicação, `tests/run.php`:

- sempre ignora o `REDIS_DATABASE` herdado e usa `TEST_REDIS_DATABASE`
  (padrão `15`) para o próprio processo;
- recusa rodar quando o banco de teste resultante é igual ao da aplicação:
  imprime `Refusing to run: test Redis database equals the application
  database (<n>)` e sai com código 1, antes de rodar qualquer teste;
- recusa `TEST_REDIS_DATABASE` que não seja só dígitos ou que passe de 15
  (`-1`, `99`, `abc`, `1.5`, `16`): imprime `Refusing to run:
  TEST_REDIS_DATABASE must be an integer between 0 and 15 (<valor>)` e sai
  com código 1. Sem essa checagem, `-1` pulava o `SELECT` e `99` tinha o
  `SELECT` recusado pelo Redis (`select()` devolve `false`, e
  `RedisConnectionFactory::connect` ignora esse retorno). Nos dois casos a
  suíte rodava no DB 0, junto das sessões;
- com o Redis acessível, faz um `SELECT` de preflight no banco de teste e
  aborta (`Refusing to run: Redis refused SELECT <n> ...`, exit 1) se o
  servidor recusar.

```bash
# banco de teste alternativo
docker compose run --rm --no-deps -T -e TEST_REDIS_DATABASE=14 app php tests/run.php
# recusa (exit 1): banco de teste = banco da aplicação
docker compose run --rm --no-deps -T -e TEST_REDIS_DATABASE=0 app php tests/run.php
# recusa (exit 1): fora de 0..15
docker compose run --rm --no-deps -T -e TEST_REDIS_DATABASE=99 app php tests/run.php
```

## A suíte não derruba a sessão do navegador (T-55)

Medido na onda 11 com o navegador logado (admin, sessão no DB 0). Antes e
depois de uma suíte completa (377 testes) e das classes suspeitas isoladas
(`SessionRedisIntegrationTest`, `Phase1TenantIsolationIntegrationTest`,
`TenantIsolationMysqlIntegrationTest`), nada mudou:

- `system_users` do id 1 (login, active, frontpage_id, unidade, hash da
  senha), `COUNT`/`MAX(id)` de `system_access_log`, vínculos de unidade e
  grupo;
- no DB 0, as mesmas chaves (`centralvet:session:<id>` e
  `centralvet:session:index:user:1`), com o mesmo conteúdo (md5) e o TTL
  descendo sem reinício;
- `tmp/` do `app` (tmpfs próprio; o `docker compose run` recebe outro tmpfs,
  vazio);
- handler: `SESSION_DRIVER=redis` no `app` e no `run`. O `session.save_handler=files`
  do php.ini é substituído em runtime por `session_set_save_handler`.

`concurrent_sessions = '1'` em `app/config/application.php` desliga o
`checkMultiSession`, então o `SessionRegistry` não derruba ninguém.

A causa das "quedas" está no navegador, não na suíte. O log do nginx mostra
dois padrões:

1. **Host diferente.** O login foi feito em `http://127.0.0.1:8081`, mas a
   navegação seguinte foi para `http://localhost:8081`. O cookie
   `PHPSESSID_centralvet` é por host, e o jar de `localhost` guarda outro
   cookie (inclusive de outro projeto, `PHPSESSID_SEMEDGEDUC`). Resultado: a
   tela de login. A sessão de `127.0.0.1` continuava válida: o mesmo id
   voltou minutos depois.
2. **Outro contexto de navegador.** Aparecem ids de sessão intercalados no
   mesmo minuto, de dois jars de cookie ao mesmo tempo, e sessões "caídas"
   que voltam a ser usadas depois. O cookie é de sessão
   (`session.cookie_lifetime=0`): ele some quando o processo do navegador
   reinicia ou quando o agente abre um contexto novo.

Regra: faça o login e todas as navegações no mesmo contexto do Playwright e
sempre em `http://127.0.0.1:8081`, nunca em `localhost`. Antes de concluir
que a sessão caiu, confira no DB 0
(`redis-cli -n 0 --scan --pattern 'centralvet:session:*'` e o `TTL`). Se a
chave existe, o problema é o cookie do navegador, não o servidor.

Os testes usam só chaves sob o namespace `testing` (ou prefixos `cvtest:`),
com nomes únicos por instância (fila `t11-queue-<hex>`, prefixo de sessão
`cvtest:session:test:<uniqid>:`), e apagam no `tearDown` só as próprias
chaves: nenhum `FLUSHDB`/`FLUSHALL` nem `SCAN` + `DEL`. Assim, duas suítes em
paralelo não disputam a mesma fila nem a mesma chave.

## No host (sem ext-redis)

Também é possível rodar fora do container, mas os testes que dependem do
Redis real ficam `skipped` em vez de `passed`:

```bash
php src/tests/run.php
```

## Depois de alterar código em `app/Core` ou em `tests/`

Reconstrua a imagem antes de rodar a suíte pelo container, pois o Dockerfile
gera o autoload PSR-4 (`composer dump-autoload --classmap-authoritative`) em
tempo de build, não em runtime:

```bash
docker compose build app worker && docker compose up -d
docker compose exec app php tests/run.php
```

## Banco MySQL de teste

Os testes que estendem `tests/Support/MysqlIntegrationTestCase.php` abrem uma
transação no `setUp` e fazem rollback no `tearDown`, então nada que inserem
fica gravado.

**Guarda.** Se o `setUp` abriu a transação e ela não está mais ativa no
`tearDown` (um `COMMIT` explícito ou implícito, como DDL, no meio do teste), o
`tearDown` lança `RuntimeException('Integration test left the test
transaction; writes may have been committed to the development database')` e
o teste sai `FAIL`. Sem a guarda, o rollback era pulado em silêncio e as
linhas ficavam no banco. Prova: `Integration\MysqlIsolationGuardIntegrationTest`.

**Nome do banco.** O DSN usa `CentralVet\Tests\Support\TestDatabase::resolveName(getenv())`:

- `TEST_DB_DATABASE`, quando não vazio;
- senão `TestDatabase::DEFAULT_NAME = 'centralvet_test'`, o banco dedicado
  criado por `scripts/test-db/provision.sh` (abaixo). A SUITE não roda mais no
  banco da aplicação;
- recusa nome igual a `DB_DATABASE`:
  `Refusing to run: test MySQL database equals the application database (<nome>)`.

**Checagens do `tests/run.php`.** Antes de rodar qualquer teste, como já faz
com o Redis, o runner:

- chama `TestDatabase::resolveName(getenv())`; na exceção, imprime a mensagem
  e sai com 1;
- com o MySQL acessível e o banco resolvido inexistente
  (`information_schema.SCHEMATA`), imprime `Refusing to run: test MySQL
  database <nome> not found (see docs/runbooks/tests.md)` e sai com 1. Nesse
  caso, provisione o `centralvet_test` (abaixo). MySQL inacessível não é erro
  aqui: os testes MySQL saem `SKIP`.

```bash
docker compose run --rm --no-deps -T app php tests/run.php                                  # centralvet_test
docker compose run --rm --no-deps -T -e TEST_DB_DATABASE=centralvet app php tests/run.php   # recusa, exit 1
```

**Pré-requisito: base Adianti em `var/sql-bootstrap/`.** Os SQL do template
(`src/app/database/permission.sql`, `communication.sql`, `log.sql`) usam
cabeçalhos `--- ` no estilo SQLite, que o MySQL recusa (`ERROR 1064 ... near
'-\nCREATE TABLE system_group'`). O provisionamento aplica as cópias que
`scripts/prepare-adianti-bootstrap.sh` gerou para o dev em `var/sql-bootstrap/`
(gitignored): `01-permission.mysql.sql`, `02-communication.mysql.sql` e
`03-log.mysql.sql`, com os comentários normalizados, sem o usuário demo e com
o hash do admin local. Se elas não existirem, o script para antes de qualquer
SQL e aponta o `prepare-adianti-bootstrap.sh`. Não rode esse script de novo
num ambiente já montado sem necessidade: ele recusa quando o `manifest.txt`
existe e, com `FORCE_PREPARE=1`, troca a senha do admin no `.env`.

**Provisionamento do `centralvet_test`.** `scripts/test-db/provision.sh` cria
o banco a partir dos arquivos em disco. Ele só roda com autorização SQL
específica do usuário no momento (é escrita no MySQL) e depois de um backup
do dev (`scripts/backup.sh`), a partir de `/var/www/html/centralvet`:

0. preflight, sem docker e sem SQL: todos os arquivos abaixo existem e nenhum
   tem linha começando com `---`. Qualquer falha sai com exit 1 antes do
   passo 1, sem criar nada;
1. com o root do container `mysql`: `CREATE DATABASE centralvet_test
   CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci` e `GRANT ALL PRIVILEGES
   ON centralvet_test.*` para `MIGRATION_DB_USER` e `MYSQL_USER` (o usuário de
   migration só tem privilégio em `centralvet`.*, ver
   `src/app/database/migrations/README.md`);
2. com o usuário de migration, em ordem e parando no primeiro erro:
   `var/sql-bootstrap/01-permission.mysql.sql`, `02-communication.mysql.sql`,
   `03-log.mysql.sql` (base Adianti, que também semeia `system_users` e
   `system_unit`), `20260919_add_missing_adianti_foreign_keys.sql` e as
   migrations `0001`…`0008` de `src/app/database/migrations/`, sem os
   `.verify.sql`.

O script recusa (exit 1) quando `centralvet_test` já existe. Antes de pedir
a aprovação, confira os arquivos sem tocar no banco (`--check` roda só o
preflight) e o teste do próprio script (um `docker` falso no PATH garante que
nada chega ao MySQL):

```bash
sh scripts/test-db/provision.sh --check        # "provision: check ok (no SQL executed)"
bash scripts/test-db/provision-check.test.sh   # "Failed: 0"
```

Com a aprovação, aplique e rode `scripts/test-db/verify.sql` (só `SELECT`):

```bash
sh scripts/test-db/provision.sh
docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -t' < scripts/test-db/verify.sql
```

**Banco parcial.** DDL MySQL não é transacional: se o passo 2 falhar no meio,
o `centralvet_test` fica com parte das tabelas (ou vazio, só com o `CREATE` e
os `GRANT`). Não tente completar à mão nem reaplicar só o arquivo que faltou.
Corrija a causa, rode `--check` e, com nova autorização SQL específica do
usuário, apague o banco de teste (nunca o `centralvet`) e rode o script de
novo:

```bash
docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot -e "DROP DATABASE centralvet_test"'
sh scripts/test-db/provision.sh
```

Os `GRANT` em `centralvet_test.*` sobrevivem ao `DROP DATABASE` (ficam em
`mysql.db`), e o passo 1 os reaplica sem erro.

Esperado: nenhuma tabela de `centralvet` ausente em `centralvet_test`,
`system_users`/`system_unit` maiores que zero e as 8 linhas de
`schema_migrations` (0001…0008).

**Checksum de zeros.** Algumas migrations (ex.: a 0006) trazem no arquivo o
placeholder de checksum (`000…0`), que só no dev foi trocado pelo SHA-256
aprovado na hora de aplicar. Como o `centralvet_test` nasce dos arquivos em
disco, essas linhas de `centralvet_test.schema_migrations` ficam com zeros.
Isso é esperado e não indica falha do provisionamento.

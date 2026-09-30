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

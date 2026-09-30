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
  database (<n>)` e sai com código 1, antes de rodar qualquer teste.

```bash
# banco de teste alternativo
docker compose run --rm --no-deps -T -e TEST_REDIS_DATABASE=14 app php tests/run.php
# recusa (exit 1): banco de teste = banco da aplicação
docker compose run --rm --no-deps -T -e TEST_REDIS_DATABASE=0 app php tests/run.php
```

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

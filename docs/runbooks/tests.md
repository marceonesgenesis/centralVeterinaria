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

O runner força `REDIS_DATABASE=15` para o próprio processo quando essa
variável não está definida no ambiente, para nunca rodar por engano contra o
banco `0` de desenvolvimento. Os testes usam apenas chaves sob o namespace
`testing` e fazem limpeza ao final.

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

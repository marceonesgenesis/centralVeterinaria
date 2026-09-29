# Build versionado e pipeline de CI

## Pipeline (`.github/workflows/ci.yml`)

Roda em `push` e `pull_request`. Não publica nada e não se conecta a nenhum
registry — build e teste acontecem só dentro do runner:

1. Gera um `.env` efêmero no runner (credenciais aleatórias por execução,
   via `openssl rand`; descartado com o runner, nunca commitado).
2. `docker compose config --quiet` — valida a configuração antes de construir.
3. `docker compose build app worker` — builda as imagens com a tag da vez
   (ver abaixo).
4. `docker compose up -d --wait mysql redis app worker` — sobe só os
   serviços necessários e espera os healthchecks.
5. `docker compose exec -T app php tests/run.php` — roda a suíte real de
   T-11 (75 testes, contra Redis real).
6. Em falha, imprime os últimos logs; ao final, sempre derruba a stack
   (`docker compose down -v`), pois o runner é descartável.

Este workflow foi preparado sem repositório Git inicializado, para ser
ativado automaticamente quando o repositório remoto existir — nenhuma ação
de push/deploy remoto foi executada para produzi-lo.

## Estratégia de tag de imagem

`docker-compose.yml` define, para `app` e `worker`:

```yaml
image: ${IMAGE_REGISTRY:-centralvet}/app:${IMAGE_TAG:-dev}
image: ${IMAGE_REGISTRY:-centralvet}/worker:${IMAGE_TAG:-dev}
```

- **Local**: sem `IMAGE_TAG` definido, a imagem fica `centralvet/app:dev` /
  `centralvet/worker:dev` — comportamento atual preservado.
- **CI**: o workflow exporta `IMAGE_TAG=${{ github.sha }}`, então cada
  execução builda `centralvet/app:<sha-do-commit>` /
  `centralvet/worker:<sha-do-commit>`, tornando o build rastreável ao commit
  exato sem exigir um registry real.
- **Quando um registry existir** (fora do escopo desta fase): basta apontar
  `IMAGE_REGISTRY` para o registry real (ex.:
  `ghcr.io/<org>/centralvet`) e adicionar um passo de `docker push` — nenhuma
  mudança estrutural é necessária além disso.

Nenhuma credencial de registry é usada ou necessária neste estágio.

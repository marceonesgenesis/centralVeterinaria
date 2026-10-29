# Build versionado e pipeline de CI

## Pipeline (`.github/workflows/ci.yml` e `scripts/ci/run.sh`)

A pipeline é o script versionado `scripts/ci/run.sh`: roda igual no GitHub
Actions (em `push` e `pull_request`) e na máquina do desenvolvedor. Não publica
nada e não usa registry; o detalhe das etapas e do uso local está em
[`tests.md`](./tests.md) (seção "CI reproduzível"). O workflow só faz checkout,
chama o script (`CI_PROJECT=centralvet-ci`, `CI_WORKDIR=var/ci`,
`IMAGE_TAG=<sha>`) e guarda o log como artefato em caso de falha.

Release e rollback (pacote da hospedagem, imagem por SHA, manifesto, preflight,
fumaça): [`release.md`](./release.md).

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

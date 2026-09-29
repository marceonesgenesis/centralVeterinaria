# Configuração por ambiente

Esta fase implementa e testa apenas o ambiente **development** (local, via
Docker Compose). **staging** e **production** são documentados para quando
existir infraestrutura real — nenhum dos dois é implantado, e nenhuma
credencial real existe para eles neste repositório.

## development (implementado e testado)

- `.env.example` reflete este ambiente; copiar para `.env` conforme
  [`local-environment.md`](./local-environment.md).
- `APP_ENV=development`, `APP_DEBUG=false` (mesmo em dev, por padrão seguro).
- `STORAGE_DRIVER=s3` apontando para o MinIO local opcional
  (`profile: minio`), nunca um provedor real.
- `METRICS_DRIVER=null`, `ERROR_TRACKING_DRIVER=null` — sem SaaS externo.
- `IMAGE_TAG=dev` (ver [`build-versioning.md`](./build-versioning.md)).
- MySQL/Redis não publicam porta no host; só o Nginx é exposto em
  `127.0.0.1:${HTTP_PORT}`.

## staging (documentado, não implantado)

Diferenças esperadas em relação a development, quando este ambiente existir:

- `APP_ENV=staging`; `APP_DEBUG` permanece `false`.
- Credenciais (`MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD`, `APP_SEED`,
  `REDIS_PASSWORD`) exclusivas do ambiente, geradas por um cofre de segredos
  (ex.: GitHub Environments/OIDC, Vault) — nunca reaproveitadas de dev/produção.
- `STORAGE_DRIVER=s3` apontando para um bucket S3/R2 real de staging (não
  MinIO), com `S3_ENDPOINT`/`S3_ACCESS_KEY`/`S3_SECRET_KEY` próprios.
- `METRICS_DRIVER`/`ERROR_TRACKING_DRIVER` configurados para o provedor real
  escolhido (fora de escopo desta fase — hoje só há os drivers `null`/`log`).
- `IMAGE_TAG` fixado no SHA do commit promovido para staging (mesma
  estratégia de build versionado usada em CI), nunca `latest`.
- Domínio e TLS próprios de staging — explicitamente fora do escopo desta
  fase (ver `plan.md`, seção "Excluído").

## production (documentado, não implantado)

Tudo o que vale para staging, mais:

- Segredos rotacionados e nunca compartilhados com staging/dev.
- Backups (ver [`restore-backup.md`](./restore-backup.md)) criptografados,
  copiados para armazenamento externo e testados periodicamente, conforme
  RPO/RTO do PRD — a rotina atual (`scripts/backup.sh`) é só a base local.
- Promoção de imagem por tag imutável (o mesmo `IMAGE_TAG` testado em CI é
  promovido, nunca reconstruído a partir do código-fonte de novo).
- Acesso de rede e privilégios de banco ainda mais restritos que o já
  aplicado em `docker-compose.yml` (`read_only`, `cap_drop: ALL`,
  `no-new-privileges`, rede `backend` interna).
- Qualquer aplicação de migration em produção segue
  [`migrations.md`](./migrations.md) sem exceção, com backup e autorização
  específica antes de cada execução.

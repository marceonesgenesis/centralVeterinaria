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
- `APP_PUBLIC_URL=http://localhost:8081` (ver [`APP_PUBLIC_URL`](#app_public_url)).

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
- `APP_PUBLIC_URL=https://<domínio de staging>`, sempre com `https`.

## production (documentado, não implantado)

Tudo o que vale para staging, mais:

- Segredos rotacionados e nunca compartilhados com staging/dev.
- `APP_PUBLIC_URL=https://<domínio de produção>`, sempre com `https`.
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

## APP_PUBLIC_URL

Base pública da aplicação usada para montar o link do e-mail de redefinição
de senha (`CentralVet\Security\PublicUrl`). O link nunca é montado a partir do
cabeçalho `Host` da requisição. Sem a variável, o reset não envia e-mail e só
registra o erro no `error_log`; a resposta ao usuário continua a mesma.

| Ambiente | Valor |
|---|---|
| development | `http://localhost:8081` (já no `.env.example` e no ambiente compartilhado de `app` e `worker` do `docker-compose.yml`) |
| staging | `https://<domínio de staging>` |
| production | `https://<domínio de produção>` |

Em hospedagem compartilhada, ver
[`shared-hosting-mysql57.md`](./shared-hosting-mysql57.md).

## Limite de tentativas do reset de senha

O pedido de redefinição usa as mesmas variáveis do login:
`LOGIN_RATE_LIMIT_MAX_ATTEMPTS` (padrão 5) e `LOGIN_RATE_LIMIT_DECAY_SECONDS`
(padrão 900). O limite é duplo: por conta (login normalizado, de qualquer IP)
e por IP (qualquer conta). Os contadores usam o prefixo
`centralvet:password-reset-throttle:` e o driver de `RATE_LIMIT_DRIVER`.

Clínicas atrás do mesmo NAT compartilham o limite por IP; se isso bloquear
usuários legítimos, aumente `LOGIN_RATE_LIMIT_MAX_ATTEMPTS`, o que também
afrouxa o limite do login. Passado o limite, a resposta é a única que difere
das demais, e não revela se o login existe.

## Proteção CSRF

Toda requisição que grava dados exige `POST` e o token CSRF da sessão. O guard
roda em `ApplicationAuthenticationService::checkMultiSession()`, antes do
método chamado; o inventário dos métodos está em
[`docs/security/csrf-inventario.md`](../security/csrf-inventario.md).

- Cookie `cv_csrf`: espelha o token da sessão, com `SameSite=Strict` e sem
  `HttpOnly` (o `cv-shell.js` o lê). `Secure` quando a requisição é HTTPS.
- Cabeçalho `X-CSRF-Token`: o `cv-shell.js` o envia em todo `$.ajax` para
  `engine.php` do mesmo host, convertido em `POST`. O token é validado contra
  a sessão; o cookie sozinho não autoriza.
- Falha (método de escrita por `GET`, token ausente ou divergente): resposta
  403 com a mensagem "The request could not be verified. Reload the page and
  try again." (em pt-BR, pela chave de `translations.json`) e uma linha no
  `error_log`.
- Clientes fora do jQuery (`fetch`, `XMLHttpRequest`, scripts externos) devem
  enviar o cabeçalho; `template=iframe` não carrega o `cv-shell.js`, então
  mutações ali recebem 403.

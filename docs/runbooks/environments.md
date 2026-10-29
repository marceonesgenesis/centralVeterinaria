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
- Backups (ver [`restore-backup.md`](./restore-backup.md)) cifrados, com
  `BACKUP_PASSPHRASE_FILE` fora do repositório, cópia externa
  (`BACKUP_OFFSITE_DIR` ou S3) e ensaio periódico de restauração; agendados
  conforme [`cron.md`](./cron.md).
- Promoção de imagem por tag imutável: `centralvet/app:<sha40>` construída por
  `scripts/release/build-release.sh --image <commit>` (ver
  [`release.md`](./release.md)); o `IMAGE_TAG` promovido é o SHA completo do
  commit testado, nunca `latest`.
- Alertas ao operador: `BILLING_OPERATOR_EMAIL` preenchido e SMTP real
  (`COMMUNICATION_EMAIL_DRIVER=smtp`), conforme
  [`observabilidade.md`](./observabilidade.md).
- Rode `php bin/preflight.php --with-db` (e `--trusted-proxies-file=<caminho>`
  no Docker) antes de liberar tráfego: ver [`release.md`](./release.md).
- Acesso de rede e privilégios de banco ainda mais restritos que o já
  aplicado em `docker-compose.yml` (`read_only`, `cap_drop: ALL`,
  `no-new-privileges`, rede `backend` interna).
- Qualquer aplicação de migration em produção segue
  [`migrations.md`](./migrations.md) sem exceção, com backup e autorização
  específica antes de cada execução.

## Cookies Secure, proxy e IP do visitante (produção)

- **Cookie de sessão `Secure`**: ligado automaticamente fora do CLI quando a
  requisição é HTTPS (`CentralVet\Security\RequestScheme`); o cookie `cv_csrf`
  segue a mesma regra. Em produção o `APP_PUBLIC_URL` precisa ser `https://`.
- **`TRUST_FORWARDED_PROTO`** (padrão `false`): só com `true` o PHP aceita
  `X-Forwarded-Proto` como prova de HTTPS. No compose, o nginx traduz o
  cabeçalho em `HTTPS=on` apenas quando a requisição vem de um proxy listado
  em `NGINX_TRUSTED_PROXIES_FILE`, então prefira manter o PHP em `false` e
  confiar no nginx. Em hospedagem sem nginx, `true` só é seguro atrás de um
  proxy que **sobrescreve** o cabeçalho; o preflight dá WARN com `true`.
- **`NGINX_TRUSTED_PROXIES_FILE`**: arquivo de `set_real_ip_from` montado em
  `/etc/nginx/trusted-proxies.conf`. O padrão
  (`docker/nginx/trusted-proxies.dev.conf`) confia nas faixas RFC1918 e só vale
  em desenvolvimento. Em produção, copie
  `docker/nginx/trusted-proxies.production.conf.example` para um arquivo fora
  do Git, liste **só o IP do proxy da frente** e aponte a variável para ele no
  `.env` do servidor; qualquer endereço listado pode forjar o IP do visitante
  via `X-Forwarded-For`. O preflight `trusted_proxies` reprova faixas amplas.

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

## Piloto: oferta e suporte

Fonte única: `CentralVet\Pilot\PilotSettings` (lê o ambiente) e
`CentralVet\Billing\OfferScope` (recorte homologado). Os recursos fora do
recorte continuam no código e nos direitos do plano (`PlanEntitlements` e
`plan-groups.sql` não mudam); só ficam desligados por feature flag.

| Variável | Padrão | Uso |
|---|---|---|
| `OFFER_PLANS` | `starter,pro,business,enterprise` | Planos à venda, na ordem de `PlanEntitlements::PLAN_IDS`; id desconhecido é ignorado; vazio ou só desconhecidos = os 4. |
| `OFFER_PREVIEW_FEATURES` | vazio | Recursos fora do recorte ligados em prévia: `communication`, `hospital`, `ai_advanced`. **Vazio em produção** (checklist de go-live). |
| `SUPPORT_EMAIL` | vazio | Canal de suporte obrigatório. O preflight reprova (`support_email` FAIL) vazio, inválido ou terminado em `.invalid`. |
| `SUPPORT_WHATSAPP` | vazio | Opcional; só dígitos com DDI e DDD, 10 a 15 (ex.: `5585999990000`). Fora disso é ignorado. |
| `SUPPORT_HOURS` | vazio | Horário exibido; vazio = `Segunda a sexta, das 8h às 18h`. |
| `DATA_RETENTION_DAYS_AFTER_CANCEL` | `30` | Dias (1 a 3650) que os dados ficam após o cancelamento; fora da faixa = 30. |

Recorte liberado no piloto: núcleo clínico, exames e vacinação (programas sem
gate), `inventory_pos`, `finance_full` e `multi_unit`
(`OfferScope::RELEASED_FEATURES`). Desligados: `communication`, `hospital` e
`ai_advanced`, até constarem em `OFFER_PREVIEW_FEATURES`.

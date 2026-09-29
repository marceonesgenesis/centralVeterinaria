# T-05 — Sessão Redis, hardening de autenticação, rate limit e revogação

## O que foi feito

- `CentralVet\Redis\RedisConnectionFactory` (`src/app/Core/Redis/RedisConnectionFactory.php`):
  fábrica única de conexões phpredis a partir de `REDIS_HOST/PORT/DATABASE/PASSWORD/TIMEOUT`.
  Falha alto (`RuntimeException`) em vez de degradar silenciosamente — consistente com o
  princípio fail-closed já usado em `TenantContext` (ADR 0002).
- `CentralVet\Session\RedisSessionHandler` (`src/app/Core/Session/RedisSessionHandler.php`):
  implementa `SessionHandlerInterface` nativo do PHP, armazenando cada sessão como uma chave
  `SETEX` (`centralvet:session:{id}`, TTL configurável). `Adianti\Registry\TSession` já aceita
  um handler opcional no construtor (`new TSession($handler)`), então não foi necessário tocar
  no framework (`src/lib/adianti/registry/TSession.php`).
- `CentralVet\Session\SessionHandlerFactory`: escolhe o handler a partir de `SESSION_DRIVER`
  (`redis` ou `files`, default `files` no código — o `.env.example`/compose já habilitam
  `redis` por padrão, conforme decisão de arquitetura registrada no `plan.md`). Ponto único de
  configuração usado nos três bootstraps de sessão (`engine.php`, `index.php`, `download.php`).
- `CentralVet\Session\SessionRegistry`: mapeia `user_id -> session_id` corrente no Redis.
  Substitui, quando `SESSION_DRIVER=redis`, o rastreio anterior via `TAPCache` (memória local
  de um único processo/container) usado por `ApplicationAuthenticationService::checkMultiSession()`.
  Isso corrige um problema real: com múltiplos containers `app`, o TAPCache não detecta login
  concorrente feito em outro container. Com Redis compartilhado, um novo login sobrescreve o
  ponteiro e a sessão antiga é derrubada na requisição seguinte — é o mecanismo de "revogação
  remota" pedido, condicionado à flag já existente `general.concurrent_sessions` (comportamento
  desligado por padrão, sem mudança de default). O fallback por `TAPCache` foi mantido intacto
  para quando `SESSION_DRIVER=files`.
- `CentralVet\Security\LoginRateLimiter`: limitador de tentativas de login por
  `sha256(login|ip)` (nunca guarda login/IP em claro nem senha), janela fixa via `INCR`+`EXPIRE`
  (`LOGIN_RATE_LIMIT_MAX_ATTEMPTS`, `LOGIN_RATE_LIMIT_DECAY_SECONDS`).
- `LoginForm::onLogin` (`src/app/control/admin/LoginForm.php`): checa o rate limit antes de
  validar credenciais; incrementa apenas em falha real de autenticação (não quando o próprio
  bloqueio já disparou); limpa o contador só após `loadSessionVars` (login totalmente concluído,
  inclusive 2FA). Proteção a session fixation reforçada: `TSession::regenerate()` foi trocado
  por `session_regenerate_id(true)` (agora também remove a sessão antiga, não só desvincula).
- `LoginForm::onLogout`: registra o esquecimento no `SessionRegistry` (best-effort) e chama
  `session_regenerate_id(true)` após `TSession::freeSession()`, para que o ID pós-logout também
  seja descartado.
- `ApplicationAuthenticationService::loadSessionVars`: registra a sessão atual no
  `SessionRegistry` (best-effort, não derruba o login se o Redis falhar nesse ponto pontual).

## Decisões e por quê

- Usei o `SessionHandlerInterface` nativo do PHP (phpredis já compilado na imagem, ver
  `docker/php/Dockerfile`) em vez do save-handler nativo `redis` do próprio phpredis
  (`session.save_handler=redis` via ini) para manter o TTL e o prefixo de chave explícitos no
  código (mais fácil de auditar/testar) e para reaproveitar a mesma `RedisConnectionFactory` do
  rate limiter e do `SessionRegistry`.
- Todas as classes novas ficam em `CentralVet\Redis`, `CentralVet\Session` e
  `CentralVet\Security`, como pastas de topo "planas" dentro de `app/Core`, no mesmo padrão já
  usado por `Core/Tenancy` e `Core/Persistence` — evitei forçar a separação
  Application/Domain/Infrastructure/Presolution do `Core/README.md`, que se aplica a módulos de
  negócio, não a estas primitivas de plataforma.
- `SessionRegistry` indexa apenas por `user_id`, sem `tenant_id`, porque a migration de
  multi-tenant ainda não foi aplicada e o login atual não popula `tenantid` na sessão. Documentei
  no próprio arquivo que isso precisa ganhar um namespace de tenant quando o login tenant-aware
  (T-06+) existir.
- Não criei nem apliquei nenhuma migration SQL: T-05 não precisa de nenhuma tabela nova (todo o
  estado efêmero de sessão/rate-limit/revogação vive no Redis, com TTL).
- Não fiz nenhum teste de login real (nem contra o MySQL nem via HTTP), conforme restrição de
  autorização SQL/gravação de dados. A validação foi limitada a `php -l` em cada arquivo tocado,
  `composer dump-autoload` (necessário — o mapeamento PSR-4 `CentralVet\\` já estava declarado no
  `composer.json` desde T-02, mas nunca tinha sido regenerado em `vendor/composer/autoload_psr4.php`;
  sem isso, as classes de T-04 e T-05 não seriam autoloadáveis em runtime) e `docker compose config`
  para validar o compose.
- `.env.example` e `docker-compose.yml` agora documentam/propagam `REDIS_PASSWORD` (opcional,
  vazio por padrão — não inventei credencial), `SESSION_DRIVER` (default `redis`),
  `SESSION_PREFIX`, `SESSION_LIFETIME`, `LOGIN_RATE_LIMIT_MAX_ATTEMPTS` e
  `LOGIN_RATE_LIMIT_DECAY_SECONDS`. O serviço `app` passou a depender de `redis` (e também
  `mysql`) com `condition: service_healthy`, já que sessão agora pode depender do Redis estar de
  pé.

## Pendências / próximos passos sugeridos

- Não implementei uma tela/admin action explícita de "revogar sessão de outro usuário" — isso
  depende de RBAC (T-06, bloqueada). O primitivo (`SessionRegistry`) já está pronto para T-06
  chamar `revoke`/`forget` a partir de uma ação administrativa.
- O rate limiter usa `$_SERVER['REMOTE_ADDR']` diretamente; se a aplicação passar a rodar atrás
  de um proxy que reescreve IP (o Nginx do compose já faz proxy), pode ser necessário considerar
  `X-Forwarded-For` de forma segura (validando proxies confiáveis) — não fiz isso agora para não
  introduzir uma superfície de spoofing sem revisão dedicada.
- Nenhum teste automatizado (unitário/integração) foi criado para essas classes nesta task; a
  suíte de testes é escopo de outra task da Fase 0 (T-10 conforme `plan.md`).

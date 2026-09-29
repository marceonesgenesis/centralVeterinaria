# Notas de execução

## Decisões tomadas
- 2026-09-19 — Usar Docker Compose dedicado; o host já atende outros projetos.
- 2026-09-19 — Reservar `127.0.0.1:8081` para acesso local; banco e cache ficam internos.
- 2026-09-19 — Adiar storage S3 compatível até a escolha entre S3, R2 e MinIO.
- 2026-09-19 — Separar liveness (`/live`) de readiness (`/health`); readiness usa apenas `SELECT 1` e `PING`.
- 2026-09-19 — Proteger restauração com `CONFIRM_RESTORE=RESTORE_CENTRALVET`; sua execução continua dependendo de autorização explícita.
- 2026-09-19 — A infraestrutura base foi inicialmente validada com PHP 8.3.33, Composer 2.8.11, Nginx 1.27.5, MySQL 8.0.43 e Redis 7.4.5; PECL Redis fixado em 6.3.0.
- 2026-09-19 — Executar app/worker como `www-data`, com filesystem read-only, `cap_drop: ALL`, `no-new-privileges` e `/tmp` efêmero.
- 2026-09-19 — Manter infraestrutura na raiz e concentrar todo o código da aplicação em `src/`.
- 2026-09-19 — Usar `/var/www/html/templete8.6` como origem do Adianti Template 8.6; manter o document root em `src/` para compatibilidade com os assets e entrypoints do framework.
- 2026-09-19 — Não importar os bancos SQLite do template; manter apenas os SQLs de referência e parametrizar as conexões Adianti por `DB_*` para o MySQL isolado.
- 2026-09-19 — Após importar o Adianti Template 8.6, atualizar a imagem de PHP 8.3.33 para PHP 8.4, versão mínima exigida pelo bootstrap do template.

## Bloqueios
- Nenhum para a configuração local inicial.
- Domínio, TLS e exposição externa dependem da definição do ambiente de publicação.

## Descobertas
- Host Ubuntu 24.04 com Docker/Compose, PHP 8.3, Node 22 e Nginx disponíveis.
- Disco está com 85% de uso; imagens e volumes devem ser controlados.
- Portas 80, 8080, 9000, 6379 e 3308 já estão em uso por outros serviços.
- O PRD exige MySQL 8; o cliente local é MariaDB e não deve ser usado como substituto.
- A extensão PHP GD deve ser incluída na imagem da aplicação.
- Build validado com PHP 8.3.33 e extensões GD, Intl, PDO MySQL, Redis, ZIP e OPcache carregadas.
- MySQL e Redis não têm portas publicadas; o backend usa rede Docker interna e o HTTP é ligado somente a `127.0.0.1`.
- Backup agora grava primeiro em arquivos temporários, valida conteúdo e gzip, e só então publica o artefato por rename atômico.
- Backup/restore atuais são ferramentas locais: cobrem apenas MySQL lógico, sem Redis, criptografia, storage externo, PITR, retenção ou rollback automático.
- Revisão T-06 aprovada: Compose e sintaxes PHP/Shell válidos; código concentrado em `src/`; mounts e caminhos consistentes; app/worker não root e read-only; versões patch fixas; health checks independentes; portas, redes, volumes, logs, backup e documentação aderentes ao escopo. O primeiro start continua condicionado a `.env` com credenciais fortes e à autorização para inicializar MySQL/Redis.
- Integração T-07: copiados `app/`, `lib/`, `vendor/`, `rest/`, assets, menus, Composer e entrypoints do template. Excluídos `*.db`, conteúdo de output/tmp, Docker da origem e configurações de infraestrutura da origem. `/live` e `/health` foram integrados ao entrypoint Adianti.
- Validação T-07: 1.421 arquivos PHP passaram no lint; `vendor/autoload.php` carregou; bootstrap Adianti carregou em PHP 8.4; Compose validou com `.env.example`; imagem `centralvet-app` foi construída; extensões PHP requeridas e `/live` foram verificados em container efêmero. Nenhum serviço ou banco foi iniciado.
- Correção T-08: `APP_SEED` passou a ser obrigatório no Compose e validado com mínimo de 32 caracteres no bootstrap; removidos endpoints REST demonstrativos e resíduos temporários do Composer; Nginx passou a negar código/metadados internos e execução PHP em dados; `download.php` passou a validar caminhos canônicos dentro de `files/` e a bloquear `files/system`.
- Correção T-08: dependências de runtime são instaladas do `composer.lock`, sem pacotes dev, em estágio isolado; código e `vendor` ficam na imagem imutável, sem bind mount no PHP. `tmp` e `app/output` são `tmpfs` efêmeros `noexec/nosuid/nodev`; `files` usa volume persistente com execução de PHP bloqueada na borda.
- Validação T-08: lint de 1.412 arquivos PHP, Composer/autoload da imagem, `docker compose config`, sintaxe Nginx, build multi-stage, rejeição do placeholder de `APP_SEED`, bootstrap de `/live`, ownership dos diretórios e ausência de REST demo/resíduos temporários aprovados. Nenhum serviço, banco, migration ou seed foi iniciado/executado.
- Revisão final T-08: Nginx permite somente `pt.json`, `en.json` e `es.json` no diretório i18n do DataTables e mantém os demais JSON de `lib/` indisponíveis. `/files/` foi totalmente removido da superfície HTTP; `download.php` é a única via, autenticada, sempre como `attachment`, `application/octet-stream` e `nosniff`.
- Revisão final T-08: `app_files` permanece named volume persistente. Compose/named volumes não expõem flags `noexec/nosuid/nodev` de forma portável; opções de driver local seriam dependentes do host. A mitigação adotada é não montar uploads no Nginx, não executar/servir PHP desse caminho, executar PHP como `www-data` com rootfs read-only, `cap_drop: ALL` e `no-new-privileges`.
- Validação da revisão final: confirmados no filesystem apenas `en.json`, `es.json` e `pt.json` no caminho i18n liberado; Nginx `-t`, Compose, lint dos 1.412 PHP, ausência de `app_files` no serviço Nginx, headers seguros de download e rebuild da imagem foram aprovados. Nenhum serviço da stack ou banco foi iniciado.
- 2026-09-19 — T-10 autorizado: bootstrap executado somente no MySQL 8.0.43 isolado, schema `centralvet`, confirmado vazio antes da escrita. Aplicados apenas os schemas-base permission, communication e log; nenhum `*-update.sql`, migration ou seed adicional foi executado.
- T-10 usa variante local auditável em `var/sql-bootstrap/`, ignorada pelo Git e modo `600`. Ela troca o hash conhecido do admin por bcrypt de senha aleatória guardada somente no `.env` local e remove o usuário demo e suas três associações. Os comentários `---` do template foram normalizados para `-- ` nas cópias MySQL, sem alterar SQL executável ou arquivos-base.
- A primeira tentativa foi rejeitada pelo parser na linha 1 devido ao comentário `---`; consultas confirmaram zero tabelas/índices após a falha. Depois da normalização, os três arquivos foram aplicados uma vez com sucesso na ordem permission → communication → log.
- Validação T-10: MySQL 8.0.43/`centralvet`, 40 tabelas, 87 índices não primários, 39 PKs, 0 views, 0 triggers e 141 seeds; um admin, nenhum login `user`, communication/log vazios, hash do admin compatível com a senha local. `/live`, `/health` e formulário de login responderam 200.
- Compatibilidade observada: há 30 declarações `REFERENCES` nos arquivos, mas MySQL materializou somente 14 FKs explícitas. Faltam 15 referências inline de communication e uma referência inline de permission (`system_users.system_unit_id`).
- T-11 preparou `src/app/database/migrations/20260919_add_missing_adianti_foreign_keys.sql` com exatamente 16 `ALTER TABLE`, constraints `RESTRICT` e o índice adicional `sys_users_unit_idx`. A migration inclui prechecks de órfãos, aviso de DDL não atômico e validação esperada de 30 FKs/88 índices. Ela ainda não havia sido aplicada nessa etapa e não contém rollback destrutivo.
- 2026-09-20 — T-12 autorizado e concluído. Backup lógico anterior à migration: `var/backups/centralvet-20260920T011359Z.sql.gz`, modo `600`, gzip íntegro, dump completo de 40 tabelas, SHA-256 `8fd0d7c6ddd180434998abd87eb852ecf023c7e327ca67c9b94199f54bdc6139`.
- Imediatamente antes da T-12, os 16 prechecks retornaram zero órfãos e o baseline foi confirmado em 40 tabelas, 14 FKs e 87 índices não primários. A migration preparada foi executada uma única vez, sem erro e sem rollback/restauração.
- Pós-check T-12: 40 tabelas, 30 FKs, 88 índices não primários, `sys_users_unit_idx` presente, as 16 novas constraints com regras `ON UPDATE/DELETE RESTRICT` e todos os 16 checks de órfãos ainda em zero. Os 14 FKs anteriores são reportados pelo MySQL como `NO ACTION`, semanticamente restritivos.
- `/live` e `/health` permaneceram saudáveis (`mysql=true`, `redis=true`) e a página de login respondeu HTTP 200. Nenhum login foi efetuado e nenhum segredo foi exibido.
- Execução T-09 autorizada: criado `.env` local ignorado pelo Git, modo `0600`, com senhas e `APP_SEED` aleatórios fortes; nenhum segredo foi exibido ou registrado nas notas.
- A stack isolada foi iniciada com sucesso. A inicialização oficial do MySQL 8.0.43 criou o banco `centralvet`, o usuário da aplicação e seus grants nesse banco; nenhum schema, migration ou seed Adianti foi aplicado.
- Validação T-09: cinco serviços saudáveis; `/live` e `/health` responderam OK; `SELECT 1` autenticado pelo usuário da aplicação e Redis `PING` passaram; MySQL 3306/33060 e Redis 6379 permanecem sem publicação no host; logs recentes não contêm segredos nem padrões críticos.

# Hospedagem compartilhada com MySQL 5.7

As migrations originais continuam destinadas a MySQL 8. Para um banco vazio
MySQL 5.7, prepare uma variante privada a partir dos arquivos de bootstrap
sanitizados e das migrations numeradas, ordenados em um diretório privado:

```sh
python3 scripts/prepare-mysql57.py var/sql-bootstrap/online var/sql-bootstrap/mysql57
python3 scripts/test-prepare-mysql57.py
```

O preparador não conecta ao banco. Gera SQL para o cliente `mysql`, arrays de
comandos JSON para PDO e um manifesto. Substitui `utf8mb4_0900_ai_ci` por
`utf8mb4_unicode_ci`, fixa InnoDB/utf8mb4 nas tabelas de bootstrap e substitui
cada CHECK por dois triggers BEFORE INSERT/UPDATE que usam SIGNAL SQLSTATE
45000. A condição `IS FALSE` mantém a semântica de CHECK: NULL não é uma
violação. Índices, chaves estrangeiras e seeds são preservados. A comparação
de textos usa as regras Unicode disponíveis no MySQL 5.7, que podem diferir
da collation Unicode 9 usada no MySQL 8.

O checksum de cada etapa identifica seu array JSON canônico antes do
preenchimento do próprio checksum no INSERT de `schema_migrations`. Guarde
o manifesto e os artefatos junto ao relatório de implantação.

Em 2026-10-02, foram instalados no banco online `marceo22_centralvet` os três
schemas iniciais do Adianti, a correção de FKs e as migrations 0001–0009:
80 tabelas, 31 validações e 62 triggers. O banco foi confirmado vazio antes
da instalação; o snapshot e os relatórios privados ficam em
`var/sql-bootstrap/mysql57/`. As verificações SELECT são adaptadas para
consultar TRIGGERS quando o MySQL 8 usa `information_schema.check_constraints`.

## Configuração da aplicação

`src/init.php` carrega opcionalmente `app/config/hosting-environment.php`.
Esse arquivo privado deve definir APP_SEED, DB_HOST, DB_PORT, DB_DATABASE,
DB_USERNAME, DB_PASSWORD, APP_ENV, APP_DEBUG, APP_TIMEZONE e APP_PUBLIC_URL
usando `putenv()`.
Não versionar esse arquivo. Bloquear acesso HTTP aos diretórios `app/config`
e `app/database` com `.htaccess` (`Require all denied`).

`APP_PUBLIC_URL` é a base dos links de redefinição de senha; sem ela o reset
não envia e-mail. Use o domínio real, com `https` e o subdiretório se houver:

```php
putenv('APP_PUBLIC_URL=https://<domínio>');
```

O limite de tentativas do reset reaproveita `LOGIN_RATE_LIMIT_*` e o
`RATE_LIMIT_DRIVER` (ver [`environments.md`](./environments.md)). A proteção
CSRF não exige configuração; o cookie `cv_csrf` só depende da sessão.

Defina `DB_STRICT_MODE=true` para que cada conexão da aplicação use o modo
SQL estrito, independentemente da configuração global da hospedagem. Isso
evita truncamentos silenciosos e conversões inválidas de valores/datas.

Para login em um único servidor sem Redis, defina `SESSION_DRIVER=files` e
`RATE_LIMIT_DRIVER=files`. O limitador grava contadores com TTL e `flock()` em
um diretório privado do sistema temporário; falhas de leitura ou gravação
interrompem a operação. Opcionalmente fixe `RATE_LIMIT_DIRECTORY` fora da
raiz pública. O backend padrão continua Redis; nenhum fallback automático
ocorre. Filas, registro distribuído de sessões e tokens da landing continuam
dependendo de Redis e exigem infraestrutura/configuração própria.

O arquivo `src/.htaccess` define `landing.php` como DirectoryIndex do Apache.
Em `/centralvet/`, visitantes anônimos abrem a landing, e o botão Entrar
abre `index.php?class=LoginForm`. Assets, login, endpoint de leads e
redirecionamentos da landing usam caminhos relativos, compatíveis com
instalação na raiz do domínio ou em um subdiretório. Sessões autenticadas e
pedidos com query string seguem para `index.php` no mesmo diretório.

### request_order (cookies fora de $_REQUEST)

Com o `request_order` padrão de algumas hospedagens (vazio, caindo em
`variables_order` = `EGPCS`), os cookies entram em `$_REQUEST`, e o Adianti
copia `$_REQUEST` ao montar links de paginação, ordenação e filtros. Sintoma:
`PHPSESSID=` aparece nas URLs das listas paginadas (ex.:
`index.php?class=SystemUserList&...&PHPSESSID=...`), expondo a sessão em
histórico, logs e cabeçalho `Referer`. Fixe só GET e POST no `.user.ini` da
raiz da aplicação (ou no `php.ini`, pelo painel do provedor):

```ini
request_order = "GP"
```

A imagem Docker já aplica a mesma linha (`docker/php/conf.d/app.ini`). Como
conferir: um script temporário com `<?php echo ini_get('request_order');`
deve imprimir `GP` (apague-o depois; o `.user.ini` pode levar até
`user_ini.cache_ttl`, 300 s por padrão, para valer), e a lista paginada não
deve conter `PHPSESSID` no HTML.

### IP do visitante e limite de envios da landing

O `lead.php` usa só `REMOTE_ADDR` e conta 10 envios por IP por hora. Atrás de
proxy/CDN do provedor (ex.: Cloudflare), `REMOTE_ADDR` vira o IP do proxy e
todos os visitantes dividem o mesmo limite. A correção é no servidor, com
`mod_remoteip` (`RemoteIPHeader` + `RemoteIPTrustedProxy` só com as faixas do
proxy), pedida ao provedor: essas diretivas não valem no `.htaccess`. O PHP
nunca lê `X-Forwarded-For`, porque o cliente falsifica o header.

Como conferir: envie um lead de teste e compare o `consent_ip` gravado com o
IP público de quem enviou; se vier o IP do proxy, o `mod_remoteip` falta.

O `.htaccess` recusa métodos diferentes de `POST` no `lead.php` com 405
(`RewriteRule ^lead\.php$ - [R=405,L]`), só com `mod_rewrite`; sem ele o PHP
responde o mesmo 405.

A landing oferece Português, English e Español nos seletores do cabeçalho
e do carrinho. `landing/translations.js` contém as traduções de textos,
planos, comparativo, FAQ, formulário e mensagens; `landing/i18n.js` aplica
o idioma e guarda a preferência em `localStorage` (`cvp-language`). O padrão
é português. A troca mantém o plano selecionado e os campos preenchidos.
Os preços continuam em BRL e recebem a formatação numérica do idioma.

A ação Criar conta do login abre `landing.php#cart`. A landing reconhece
esse fragmento e abre o carrinho automaticamente: um plano salvo mostra
o formulário de ativação; um carrinho vazio oferece a seleção de planos.

Validação no navegador, com Playwright disponível:

```sh
PLAYWRIGHT_MODULE=/caminho/para/playwright node scripts/test-landing-i18n.cjs https://zionsystem.com.br/centralvet/
```

O teste intercepta o envio de leads no navegador; nenhuma solicitação de
teste é gravada no banco online.

## Execução pela hospedagem

Quando não há acesso SSH nem MySQL remoto, `scripts/mysql57-runner.php` é um
template de executor temporário. Substitua os marcadores de token aleatório,
expiração e diretório privado localmente; publique com nome aleatório e
remova após conferir a instalação. Aceita somente POST com token em header,
confere o banco-alvo, executa somente etapas do manifesto em ordem, rejeita
reaplicações e bloqueia continuidade após falha ou interrupção. Não deixe
o executor acessível após a implantação. Não publique senhas ou SQL por
HTTP; o bundle deve estar sob o diretório protegido `app/database`.

Executar somente no escopo SQL autorizado. Em falha parcial, não tentar
novamente nem fazer rollback destrutivo automaticamente: o DDL MySQL não
é transacional. Para bancos com dados, este procedimento inicial recusa a
instalação; uma atualização exige revisão específica e backup completo.

Para novas migrations, adicionar os artefatos à preparação e revisar a
compatibilidade com MySQL 5.7 antes de executar. Este bootstrap inicial não
é um mecanismo de atualização automática de bancos já existentes.

Os schemas iniciais do Adianti não cadastram automaticamente os controladores
de negócio do Central Vet. O seed de programas/permissões separado está em
`src/app/database/seeds/initial-application-programs.sql`. Ele cadastra os
controladores, libera-os para o grupo administrador e configura a
permissão de desconto por método. Não contém dados clínicos de demonstração.
O total de programas muda a cada tela nova; para saber quantos o seed cadastra,
use `grep -c "INSERT INTO system_program " src/app/database/seeds/initial-application-programs.sql`
em vez de um número fixo. Depois de aplicar, confira por nome as telas mais
recentes (por exemplo, as da Etapa 2) e a liberação para o grupo 1:

```sql
SELECT p.controller, COUNT(gp.id) AS liberado_grupo_1
FROM system_program p
LEFT JOIN system_group_program gp
       ON gp.system_program_id = p.id AND gp.system_group_id = 1
WHERE p.controller IN ('SaleList', 'SaleCancelForm')
GROUP BY p.controller;
```

O resultado esperado são duas linhas, cada uma com `liberado_grupo_1` = 1.

Esse seed foi aplicado no banco online com autorização específica em
2026-10-02, dentro de uma transação, após guardar e validar o snapshot das
seis tabelas afetadas. A verificação pelo modelo SystemUser confirmou os 45
programas liberados e `EncounterAccountForm.onApplyDiscount` autorizado.
As contagens dos registros de negócio foram preservadas. Após novo login,
dez telas abriram sem erros: tutores, pacientes, agenda, fila, produtos,
visão financeira, serviços, leads, sessões de caixa e recebíveis pendentes.
O executor temporário e o bundle remoto foram removidos; os relatórios
privados ficam em `var/sql-bootstrap/mysql57/`.

Em novas instalações, aplicar esse seed depois das migrations 0001–0009,
com autorização específica para as permissões. A aplicação não o executa
automaticamente.

## Migration 0010 (internação)

A `20261005_0010_phase6a_hospitalization.sql` cria as cinco tabelas da
internação e amplia dois CHECKs existentes
(`encounter_account_item_source_type_ck` e `stock_movement_reason_ck`) em
duas instruções: `DROP CHECK` e depois `ADD CONSTRAINT`. No 5.7 esses CHECKs
são triggers `<nome>_bi`/`<nome>_bu`, então o preparador converte cada
`DROP CHECK` em `DROP TRIGGER IF EXISTS` dos dois triggers e só depois emite
os `CREATE TRIGGER` de mesmo nome com a lista nova. A ordem no `.sql` gerado
deve ser drop → create; conferir antes de executar.

Preparo, sempre num diretório temporário privado (nunca em
`var/sql-bootstrap/`):

```bash
d=$(mktemp -d)
cp src/app/database/migrations/20261005_0010_phase6a_hospitalization.sql \
  "$d/14-20261005_0010_phase6a_hospitalization.sql"
cp src/app/database/migrations/20261005_0010_phase6a_hospitalization.verify.sql "$d/"
python3 scripts/prepare-mysql57.py "$d" "$d/out"
grep -n "TRIGGER IF EXISTS\|CREATE TRIGGER \`encounter_account_item\|CREATE TRIGGER \`stock_movement" \
  "$d/out/14-20261005_0010_phase6a_hospitalization.sql"
```

A verificação do 5.7 troca a consulta a `information_schema.check_constraints`
pela de `information_schema.TRIGGERS`, filtrando `EVENT_OBJECT_TABLE` pelo
literal `table_name = '<tabela>'` ou `table_name IN (...)` da própria consulta;
consulta de CHECK sem esse literal faz o preparador falhar.

## Migration 0011 (cirurgia)

A `20261005_0011_phase6b_surgery.sql` cria seis tabelas (`surgery_room`,
`surgery`, `surgery_team`, `surgery_checklist`, `surgery_event` e
`surgery_material`) e amplia os mesmos dois CHECKs da 0010
(`encounter_account_item_source_type_ck` e `stock_movement_reason_ck`) em
`DROP CHECK` e `ADD CONSTRAINT`. Como a 0010 já criou os triggers
`<nome>_bi`/`<nome>_bu` desses CHECKs no 5.7, o preparador emite
`DROP TRIGGER IF EXISTS` dos dois triggers antes de cada `CREATE TRIGGER`;
sem isso o `CREATE TRIGGER` falha por nome duplicado. Os `timestamp(6)` têm
`DEFAULT CURRENT_TIMESTAMP(6)`, e os CHECKs novos não usam `BETWEEN`, `LIKE`
nem funções.

Preparo num diretório temporário privado, com o prefixo `15-` (depois do
`14-` da 0010):

```bash
d=$(mktemp -d)
cp src/app/database/migrations/20261005_0011_phase6b_surgery.sql \
  "$d/15-20261005_0011_phase6b_surgery.sql"
cp src/app/database/migrations/20261005_0011_phase6b_surgery.verify.sql "$d/"
python3 scripts/prepare-mysql57.py "$d" "$d/out"
grep -n "TRIGGER IF EXISTS\|CREATE TRIGGER \`encounter_account_item\|CREATE TRIGGER \`stock_movement" \
  "$d/out/15-20261005_0011_phase6b_surgery.sql"
```

Confira a ordem drop, create nos dois CHECKs e que o verify do 5.7 lista as
6 tabelas. Em seguida aplique a DML de programas descrita em
[`cirurgia.md`](./cirurgia.md), com autorização específica.

## Migration 0012 (comunicação)

A `20261006_0012_phase7a_communication.sql` cria quatro tabelas
(`communication_preference`, `message_template`, `communication_message` e
`appointment_followup`), 18 CHECKs, 4 UNIQUEs e dois índices em tabelas
existentes (`vaccination_tenant_next_dose_idx` e
`receivable_tenant_status_idx`, cada um em seu próprio `ALTER TABLE ... ADD
KEY`). Não amplia CHECKs de tabelas existentes, então o preparador só emite
os triggers `_bi`/`_bu` das tabelas novas. Os CHECKs não usam `BETWEEN`,
`LIKE` nem funções. Rode numa janela de manutenção: a criação dos índices
em `vaccination` e `receivable` percorre as tabelas.

Preparo num diretório temporário privado, com o prefixo `16-` (depois do
`15-` da 0011):

```bash
d=$(mktemp -d)
cp src/app/database/migrations/20261006_0012_phase7a_communication.sql \
  "$d/16-20261006_0012_phase7a_communication.sql"
cp src/app/database/migrations/20261006_0012_phase7a_communication.verify.sql "$d/"
python3 scripts/prepare-mysql57.py "$d" "$d/out"
grep -n "CREATE TRIGGER \`communication_\|CREATE TRIGGER \`message_template\|CREATE TRIGGER \`appointment_followup" \
  "$d/out/16-20261006_0012_phase7a_communication.sql"
```

Confira que o verify do 5.7 lista as 4 tabelas. Em seguida aplique a DML dos
7 programas descrita em [`comunicacao.md`](./comunicacao.md), com
autorização específica.

### Agendador e worker na hospedagem

O e-mail só sai com o worker consumindo a fila (`RedisQueue`, exige Redis
acessível pela hospedagem). Sem processo contínuo na hospedagem, escolha uma
das opções:

- Manter `COMMUNICATION_EMAIL_DRIVER=log` (nada é enviado; o histórico
  registra a referência). Só o cron do agendador é necessário.
- Rodar o worker em modo one-shot por cron e o agendador por cron, a partir
  da raiz de `src/`:

```cron
# Lembretes automáticos de comunicação (de hora em hora)
0 * * * * cd /caminho/da/aplicacao/src && php bin/communication-scheduler.php >> /caminho/dos/logs/communication-scheduler.log 2>&1
# Envio dos e-mails da fila (a cada minuto; drena e encerra em até 50 s)
* * * * * cd /caminho/da/aplicacao/src && php bin/worker.php --once --max-seconds=50 --max-jobs=200 >> /caminho/dos/logs/worker.log 2>&1
```

`--once` processa a fila até esvaziar ou até o primeiro limite
(`--max-seconds`, padrão 50 com `--once`; `--max-jobs`, padrão sem limite;
`0` = sem limite) e sai com 0; sai com 1 se o Redis cair (log
`worker.loop_error` só com a classe) e com 2 em opção inválida. Execuções
sobrepostas não acumulam: uma trava de arquivo (`flock` no diretório
temporário) faz a segunda execução sair com 0 e o log
`worker.once_skipped_locked`; o envio duplicado já é barrado pelo claim
condicional da mensagem. Retentativas em backoff são recuperadas na execução
seguinte. Sem `--once`, o worker continua em loop contínuo (padrão do
container `worker`).

As saídas e os logs trazem só ids, códigos e contadores, sem dado pessoal.
Defina no ambiente do cron as variáveis de banco (`DB_*`), de Redis
(`REDIS_*`), `COMMUNICATION_*` e `SMTP_*` (nunca na linha do crontab com a
senha à vista).

## Migration 0013 (documentos)

A `20261006_0013_phase7b_documents.sql` cria duas tabelas
(`document_template` e `generated_document`), 9 CHECKs, 3 UNIQUEs e índices
nas colunas de FK. Não altera tabelas existentes nem amplia CHECKs, então o
preparador só emite os triggers `_bi`/`_bu` das tabelas novas. Os CHECKs não
usam `BETWEEN`, `LIKE` nem funções.

Preparo num diretório temporário privado, com o prefixo `17-` (depois do
`16-` da 0012):

```bash
d=$(mktemp -d)
cp src/app/database/migrations/20261006_0013_phase7b_documents.sql \
  "$d/17-20261006_0013_phase7b_documents.sql"
cp src/app/database/migrations/20261006_0013_phase7b_documents.verify.sql "$d/"
python3 scripts/prepare-mysql57.py "$d" "$d/out"
grep -n "CREATE TRIGGER \`document_template\|CREATE TRIGGER \`generated_document" \
  "$d/out/17-20261006_0013_phase7b_documents.sql"
```

Confira que o verify do 5.7 lista as 2 tabelas. Em seguida aplique a DML dos
4 programas descrita em [`documentos.md`](./documentos.md), com autorização
específica.

### Pasta de documentos e cron

- Crie a pasta dos PDFs **acima** do `public_html` (nunca dentro dele), com
  permissão 0750 e dono do usuário que roda o PHP, e aponte
  `DOCUMENT_STORAGE_LOCAL_ROOT` para ela (o driver `local` recusa root dentro
  do webroot). Alternativa: `DOCUMENT_STORAGE_DRIVER=s3` com o bucket já criado.

```bash
mkdir -p /caminho/acima/do/public_html/documentos
chmod 0750 /caminho/acima/do/public_html/documentos
```

- O cron do `worker.php --once` já existente (seção da 0012) processa a fila
  `document.generate`. Acrescente o varredor, que republica documentos
  `queued` presos (o tick do worker não existe sem processo contínuo):

```cron
# Varredor de documentos presos em queued (a cada 5 minutos)
*/5 * * * * php <app>/src/bin/document-sweep.php >> /caminho/dos/logs/document-sweep.log 2>&1
```

Substitua `<app>` pelo caminho da aplicação. O ambiente do cron precisa das
variáveis de banco (`DB_*`), de Redis (`REDIS_*`) e `DOCUMENT_*`
(`DOCUMENT_STORAGE_DRIVER`, `DOCUMENT_STORAGE_LOCAL_ROOT`,
`DOCUMENT_SYSTEM_USER_ID`), além de `S3_*` com o driver `s3`; nunca na linha do
crontab com segredo à vista. A saída é um JSON de contadores, sem dado
pessoal; sai com 1 se algum tenant falhou.


## Migration 0014 (IA)

A `20261007_0014_phase8a_ai_gateway.sql` cria três tabelas (`feature_flag`,
`ai_tenant_setting` e `ai_interaction`), 8 CHECKs, 2 chaves de unicidade
(`feature_flag_tenant_key_uq` e a PK de `ai_tenant_setting`) e 9 FKs. Não
altera tabelas existentes nem semeia dados: a IA fica desligada em todo tenant.
São 9 FKs (o cabeçalho da 0014 diz 8).

O preparador só lê arquivos `NN-*.sql`; apontá-lo direto para
`src/app/database/migrations` falha (`No numbered SQL files found`). Copie a
0014 com o prefixo `18-` (depois do `17-` da 0013) para um diretório temporário
privado e prepare dele:

```bash
d=$(mktemp -d)
cp src/app/database/migrations/20261007_0014_phase8a_ai_gateway.sql \
  "$d/18-20261007_0014_phase8a_ai_gateway.sql"
cp src/app/database/migrations/20261007_0014_phase8a_ai_gateway.verify.sql "$d/"
python3 scripts/prepare-mysql57.py "$d" "$d/out"
grep -n "CREATE TRIGGER \`feature_flag\|CREATE TRIGGER \`ai_" \
  "$d/out/18-20261007_0014_phase8a_ai_gateway.sql"
```

Confira que o verify do 5.7 lista as 3 tabelas. Aplique só com backup e
autorização SQL específica; depois, a DML dos programas `AiSettingsForm` e
`AiInteractionList` (grupo 1), que está em
`src/app/database/seeds/initial-application-programs.sql` (bloco "Fase 8A — AI
Gateway", linhas 1011-1034; idempotente, com `NOT EXISTS`).

### Ambiente da IA

- Defina no ambiente da hospedagem (nunca em código nem no crontab) as variáveis
  `AI_PROVIDER=anthropic`, `ANTHROPIC_API_KEY` (a chave real só aqui),
  `ANTHROPIC_BASE_URL`, `AI_MODEL`, `AI_EFFORT`, `AI_MAX_OUTPUT_TOKENS`,
  `AI_TIMEOUT_SECONDS`, `AI_CONNECT_TIMEOUT_SECONDS` e `AI_FALLBACKS`; todas têm
  padrão, só a chave é obrigatória. Não use `AI_PROVIDER=stub` em produção.
- Exige a extensão PHP `ext-curl` e HTTPS de saída (TCP 443) para
  `api.anthropic.com`; hospedagem que bloqueia saída resulta em `ai_timeout`.
- Variáveis, custo e falhas: [`ia-assistente.md`](./ia-assistente.md).


## Migration 0015 (copiloto)

A `20261015_0015_phase8b_copilot.sql` cria 3 tabelas (`ai_conversation`,
`ai_conversation_message` e `mcp_tool_call`) com 6 CHECKs novos e 9 FKs, e
**amplia os 6 CHECKs de `ai_interaction`** (`purpose`, `subject`, `status`,
`failed`, `decided` e `final`), cada um em DROP CHECK seguido de ADD
CONSTRAINT: finalidades `copilot_*`, sujeito `ai_conversation` e status
`answered`. Entre o drop e o create de cada CHECK a tabela fica sem a regra;
aplique em janela curta, com backup. Não semeia dados; o copiloto de gestão só
liga com a flag `ai_copilot_management`.

Copie a 0015 com o prefixo `19-` (depois do `18-` da 0014) para um diretório
temporário privado e prepare dele:

```bash
d=$(mktemp -d)
cp src/app/database/migrations/20261015_0015_phase8b_copilot.sql \
  "$d/19-20261015_0015_phase8b_copilot.sql"
cp src/app/database/migrations/20261015_0015_phase8b_copilot.verify.sql "$d/"
python3 scripts/prepare-mysql57.py "$d" "$d/out"
```

Confira a ordem DROP TRIGGER, CREATE TRIGGER dos 6 CHECKs de `ai_interaction`
no arquivo preparado (cada `<nome>_bi`/`<nome>_bu` sai do drop antes do create):

```bash
grep -n "TRIGGER IF EXISTS .*ai_interaction\|CREATE TRIGGER .*ai_interaction" \
  "$d/out/19-20261015_0015_phase8b_copilot.sql"
```

Esperado: 12 `DROP TRIGGER IF EXISTS` e 12 `CREATE TRIGGER` (um par `_bi`/`_bu`
por CHECK), cada drop antes do create do mesmo nome.

Aplique só com autorização SQL específica. Antes, anote
`SELECT COUNT(*) FROM ai_interaction`; depois, rode o mesmo comando e exija o
**mesmo número** (a migration só amplia CHECKs, não apaga linhas).

Na conferência, **não** use o `.verify.sql` original: ele consulta
`information_schema.check_constraints`, que o 5.7 não tem. Use o verify adaptado
que o preparador grava em `$d/out/manifest.json` (chave `verification`, arquivo
`20261015_0015_phase8b_copilot.verify.sql`), onde a consulta de CHECKs vira
`information_schema.TRIGGERS` (`EVENT_OBJECT_TABLE`); as demais consultas
(tabelas, colunas, FKs) são as mesmas. Depois, a DML dos programas do copiloto
no seed `initial-application-programs.sql`.

### Cron da retenção do copiloto

As conversas com mais de 90 dias sem atividade são apagadas por script, uma vez
por dia, a partir da raiz de `src/` (a saída é um JSON de contadores, sem dado
pessoal; sai com 1 em erro):

```cron
# Retenção de 90 dias das conversas do copiloto (diária, 03:30)
30 3 * * * cd /caminho/da/aplicacao/src && php bin/ai-conversation-retention.php >> /caminho/dos/logs/ai-conversation-retention.log 2>&1
```

Sem a variável `RATE_LIMIT_DRIVER=files`, o limitador de chamadas do copiloto
exige Redis acessível. Detalhes: [`ia-assistente.md`](./ia-assistente.md).

## Migration 0016 (venda e financeiro)

A `20261021_0016_etapa2_sale_finance.sql` liga a venda ao financeiro e torna o
cancelamento rastreável: `receivable.encounter_account_id` passa a aceitar
NULL e ganha a coluna `sale_id` (UNIQUE `receivable_sale_uq`, FK para `sale` e
o CHECK `receivable_origin_ck`: o recebível vem de uma conta de atendimento
**ou** de uma venda); `sale` ganha `cancelled_at`,
`cancelled_by_system_user_id` e `cancellation_reason` (CHECK
`sale_cancellation_ck`); cria `payment_reversal` (estorno de um recebimento,
UNIQUE por `payment_id`) e `submission_key` (token de envio de formulário,
UNIQUE por tenant/escopo/token); e **amplia `stock_movement_reason_ck`** com
`sale_cancellation`, em DROP CHECK seguido de ADD CONSTRAINT. São 5 CHECKs
novos e 1 ampliado (12 triggers no 5.7). Não semeia dados; os `ALTER TABLE` em
`receivable` e `sale` reconstroem as tabelas, então aplique em janela curta,
com backup. Antes, confira que nenhuma venda está `cancelled`
(`SELECT COUNT(*) FROM sale WHERE status = 'cancelled'` deve dar 0), senão o
`sale_cancellation_ck` recusa a migration.

Copie a migration com o prefixo `20-` (depois do `19-` da 0015) para um
diretório temporário privado e prepare dele:

```bash
d=$(mktemp -d)
cp src/app/database/migrations/20261021_0016_etapa2_sale_finance.sql \
  "$d/20-20261021_0016_etapa2_sale_finance.sql"
cp src/app/database/migrations/20261021_0016_etapa2_sale_finance.verify.sql "$d/"
python3 scripts/prepare-mysql57.py "$d" "$d/out"
```

Esperado: `Prepared 1 stages, 6 checks and 12 triggers`. No arquivo preparado,
os `DROP TRIGGER IF EXISTS` de `stock_movement_reason_ck_bi`/`_bu` vêm antes
dos `CREATE TRIGGER` do mesmo nome.

Aplique só com autorização SQL específica. Antes e depois, anote
`SELECT COUNT(*) FROM receivable` e `SELECT COUNT(*) FROM sale` e exija os
**mesmos números**. Na conferência, use o verify adaptado de
`$d/out/manifest.json` (chave `verification`), como na 0015: a consulta de
CHECKs vira `information_schema.TRIGGERS`; as linhas de violação devem dar 0.

## Migration 0017 (cobrança da assinatura)

A `20261021_0017_etapa3_billing.sql` cria as 4 tabelas da contratação:
`billing_order` (pedido feito na landing, com plano, preço do servidor,
responsável, aceite dos termos e progresso do provisionamento),
`subscription` (uma por pedido e no máximo uma por tenant; `stripe_subscription_id`
nulo no Pix), `subscription_invoice` (fatura Stripe no cartão ou fatura local
com `local_key` `pix:<assinatura>:<data>` no Pix; CHECK
`subscription_invoice_ref_ck`) e `billing_event` (evento Stripe recebido, único
por `provider`/`event_id`). São 9 CHECKs novos (18 triggers no 5.7), 9 UNIQUEs e
4 FKs. Não altera tabela existente nem semeia dados; os tenants de hoje ficam
sem assinatura (acesso `legacy`). **Aplique a 0017 antes de subir o código da
Etapa 3**: a guarda de login lê `subscription`. `billing_order` e
`billing_event.payload` guardam dado pessoal (LGPD).

Copie a migration com o prefixo `21-` (depois do `20-` da 0016) para um
diretório temporário privado e prepare dele:

```bash
d=$(mktemp -d)
cp src/app/database/migrations/20261021_0017_etapa3_billing.sql \
  "$d/21-20261021_0017_etapa3_billing.sql"
cp src/app/database/migrations/20261021_0017_etapa3_billing.verify.sql "$d/"
python3 scripts/prepare-mysql57.py "$d" "$d/out"
```

Esperado: `Prepared 1 stages, 9 checks and 18 triggers`.

Aplique só com autorização SQL específica. Antes e depois, anote
`SELECT COUNT(*) FROM tenant` e `SELECT COUNT(*) FROM system_users` e exija os
**mesmos números**. Na conferência, use o verify adaptado de
`$d/out/manifest.json` (chave `verification`), como na 0015: a consulta de
CHECKs vira `information_schema.TRIGGERS`; as linhas de violação devem dar 0 e
as 4 tabelas novas, 0 linhas.

### Reconciliação da cobrança

O `bin/billing-reconcile.php` (Etapa 3) recupera o que o webhook não aplicou:
busca no Stripe os eventos desde o último recebido menos 1 dia (`GET
/v1/events`, paginado; sem eventos, os últimos 3 dias) e aplica os que faltam;
retoma pedidos pagos sem provisionamento, provisionamentos falhos e convites
não enviados; emite a fatura Pix 3 dias antes do fim do período, marca a
assinatura `past_due` quando a fatura Pix vence e gera PaymentIntent novo
quando o Pix expira; e suspende a assinatura cuja tolerância de 7 dias acabou.
Também estorna o pedido pago cujo e-mail do responsável já é login
(`provisioning_failed`/`login_conflict`, a corrida em que o e-mail vira login
entre o checkout e o webhook): no cartão, cancela a Subscription do Stripe
(`GET` e, se ainda ativa, `DELETE /v1/subscriptions/<id>`); nos dois meios,
estorna o pagamento inteiro (`POST /v1/refunds`), cancela a assinatura local
(o Pix não ganha fatura nova) e marca o pedido `refunded`. Cada passo fica
gravado no pedido e a rodada seguinte retoma do passo pendente. As escritas no
Stripe são esse `DELETE`, esse `POST /v1/refunds` e o `POST
/v1/payment_intents` do Pix, todas com `Idempotency-Key`. Rode por cron a cada 15 minutos, a partir da raiz de `src/`:

```cron
# Reconciliação da cobrança (a cada 15 minutos)
*/15 * * * * cd /caminho/da/aplicacao/src && php bin/billing-reconcile.php >> /caminho/dos/logs/billing-reconcile.log 2>&1
```

A saída é uma linha de contadores (`billing-reconcile events_fetched=<n>
events_ingested=<n> orders_fulfilled=<n> orders_failed=<n> orders_refunded=<n>
pix_invoices_issued=<n> pix_invoices_overdue=<n> pix_intents_refreshed=<n>
subscriptions_suspended=<n>`), sem dado pessoal; sai 0, ou 1 em erro de Stripe
ou de banco (`billing-reconcile error=<classe>`, só a classe da exceção). Cada
pedido, fatura ou assinatura roda em transação própria: a falha de um item vai
para o log e não interrompe os outros. Execuções sobrepostas não acumulam (trava
de arquivo `flock` no diretório temporário; a segunda sai 0 com
`billing-reconcile skipped_locked`). O ambiente do cron precisa de `DB_*`,
`STRIPE_*`, `SMTP_*`/`COMMUNICATION_*` e `APP_PUBLIC_URL`, como o webhook (nunca na
linha do crontab).

`orders_refunded` conta os pedidos estornados na rodada. Cada estorno vai ao log
como `[LoginConflictRefund] {"event":"login_conflict_refunded",...}` (pedido,
meio de pagamento e id do estorno) e, com `BILLING_OPERATOR_EMAIL` preenchido
no ambiente do cron, sai um e-mail de alerta ao operador com o pedido, o
plano, o valor, a clínica e o e-mail do responsável (vazio: só o log; a falha
do e-mail não desfaz o estorno). Falha no Stripe sai no log como
`login_conflict_refund_failed` com o passo (`cancel_subscription` ou `refund`,
os dois que chamam o Stripe; a gravação final do estorno é local e não falha
por erro do Stripe) e o pedido continua `provisioning_failed` até a próxima
rodada. Avise o cliente: o e-mail já tinha login, então ele deve entrar com a
conta existente ou comprar com outro e-mail.

Endpoint do webhook no painel do Stripe (Developers → Webhooks → Add
endpoint):

- URL: `https://<domínio>/billing-webhook.php` (POST, autenticado pela
  `Stripe-Signature`; o segredo `whsec_…` do endpoint vai em
  `STRIPE_WEBHOOK_SECRET`, nunca em código nem no crontab).
- Versão da API: `2024-06-20` (a mesma de `StripeConfig::API_VERSION`).
- Eventos: `checkout.session.completed`,
  `checkout.session.async_payment_succeeded`,
  `checkout.session.async_payment_failed`, `checkout.session.expired`,
  `invoice.created`, `invoice.finalized`, `invoice.paid`,
  `invoice.payment_failed`, `invoice.voided`, `invoice.marked_uncollectible`,
  `customer.subscription.updated`, `customer.subscription.deleted`,
  `payment_intent.succeeded` e `charge.refunded`.

## Migration 0018 (estorno do pedido com e-mail já cadastrado)

A `20261026_0018_billing_order_refund.sql` acrescenta a `billing_order` as
colunas `subscription_cancelled_at`, `stripe_refund_id` e `refunded_at` (todas
nulas, depois de `invite_sent_at`) e amplia o CHECK `billing_order_status_ck`
com `refunded` (DROP CHECK seguido de ADD CONSTRAINT, 2 triggers no 5.7). É o
estado terminal do pedido pago cujo e-mail do responsável já é login
(`provisioning_failed`/`login_conflict`): o estorno (cancelar a assinatura do
cartão, registrar o reembolso do Stripe e marcar o pedido `refunded`) grava o
progresso nessas colunas, e o provisionamento deixa de reprocessar esse
pedido. Não cria tabela nem semeia dados; os
pedidos existentes ficam com as 3 colunas nulas. **Aplique a 0018 antes de
subir o código desta onda**: todo INSERT/UPDATE em `billing_order` grava as 3
colunas e, sem elas, falha com `Unknown column`.

Copie a migration com o prefixo `22-` (depois do `21-` da 0017) para um
diretório temporário privado e prepare dele:

```bash
d=$(mktemp -d)
cp src/app/database/migrations/20261026_0018_billing_order_refund.sql \
  "$d/22-20261026_0018_billing_order_refund.sql"
cp src/app/database/migrations/20261026_0018_billing_order_refund.verify.sql "$d/"
python3 scripts/prepare-mysql57.py "$d" "$d/out"
```

Esperado: `Prepared 1 stages, 1 checks and 2 triggers`.

Aplique só com autorização SQL específica. Antes e depois, anote
`SELECT COUNT(*) FROM billing_order` e exija o **mesmo número**. Na
conferência, use o verify adaptado de `$d/out/manifest.json` (chave
`verification`), como na 0015: a consulta de CHECKs vira
`information_schema.TRIGGERS`; as linhas de violação devem dar 0 e as 3 colunas
novas aparecem.

## Migration 0019 (monitoramento operacional)

A `20261026_0019_ops_monitoring.sql` cria 2 tabelas novas e vazias, sem chave
estrangeira nem CHECK (0 triggers no 5.7): `ops_heartbeat` (uma linha por rotina,
com o último sucesso e um detalhe curto: `backup`, `billing-reconcile`,
`billing-event-retention`, `ops-monitor`) e `ops_alert_state` (uma linha por
chave de alerta, com o último envio ao operador e a contagem, para a
deduplicação de 6 h). Não altera tabela existente nem semeia dados, e não guarda
dado pessoal. **Aplique a 0019 antes de agendar** o `ops-monitor`, o backup com
heartbeat e a retenção (ver [`cron.md`](./cron.md)): sem as tabelas, o
`ops-monitor` falha e o `backup_stale` fica de pé. No 5.7 só a collation é
convertida (as colunas de data têm `DEFAULT CURRENT_TIMESTAMP(6)` explícito).

Copie a migration com o prefixo `23-` (depois do `22-` da 0018) para um
diretório temporário privado e prepare dele:

```bash
d=$(mktemp -d)
cp src/app/database/migrations/20261026_0019_ops_monitoring.sql \
  "$d/23-20261026_0019_ops_monitoring.sql"
cp src/app/database/migrations/20261026_0019_ops_monitoring.verify.sql "$d/"
python3 scripts/prepare-mysql57.py "$d" "$d/out"
```

Esperado: `Prepared 1 stages, 0 checks and 0 triggers`.

Aplique só com autorização SQL específica, depois do backup. Antes e depois,
anote `SELECT COUNT(*) FROM tenant` e exija o **mesmo número**; na conferência,
use o verify adaptado de `$d/out/manifest.json` (chave `verification`): as 2
tabelas novas existem e estão vazias. Rollback: restaurar o backup anterior
(ver [`restore-backup.md`](./restore-backup.md)) ou uma migration reversa
numerada; apagar as duas tabelas só perde heartbeat e deduplicação.

## Migrations pendentes e preflight na hospedagem

Sem Docker, a pendência de migrations e a configuração se conferem por PHP CLI,
a partir da raiz de `src/`:

```bash
php bin/migration-status.php
php bin/preflight.php --with-db
```

- `bin/migration-status.php` compara os arquivos de
  `src/app/database/migrations/` com `schema_migrations` e imprime `applied`,
  `pending` e `unknown` por versão e, por último, `migration-status
  database=<nome> applied=<n> pending=<n> unknown=<n>`; sai 0 só com
  `pending=0 unknown=0`, 1 com pendência e 2 em erro. Só lê. As migrations da
  hospedagem entram em `schema_migrations` pelo preparador 5.7 com a versão
  completa (ex.: `20261026_0019_ops_monitoring`).
- `bin/preflight.php --with-db` confere ambiente, PHP, extensões e, pelo `DB_*`
  de runtime, a versão do MySQL (5.7 com `DB_STRICT_MODE=false` dá WARN) e as
  migrations pendentes. Em hospedagem sem nginx, `TRUST_FORWARDED_PROTO=true`
  só é seguro atrás de um proxy que sobrescreve `X-Forwarded-Proto`; o cookie de
  sessão `Secure` depende de a requisição ser HTTPS (ver
  [`environments.md`](./environments.md)). Códigos e checagens:
  [`release.md`](./release.md).

Rode os dois depois de cada atualização do pacote e de cada migration.

## Backup e restauração na hospedagem

### Gerar

`bin/hosting-backup.php` não usa Docker, `gpg` nem `mysqldump`: faz o dump por
PDO (tabelas, dados em lotes de 500 linhas e triggers), empacota `src/files` e a
pasta de documentos (`DOCUMENT_STORAGE_LOCAL_ROOT`) e cifra com `openssl` no
formato `CVBK1` (AES-256-CTR + HMAC-SHA256, chave derivada da frase por PBKDF2).
Agende pelo painel ([`cron.md`](./cron.md)); para rodar à mão:

```bash
cd /caminho/da/aplicacao/src
php bin/hosting-backup.php
```

Variáveis no ambiente do cron: `BACKUP_PASSPHRASE_FILE` (obrigatória; arquivo
fora do `public_html`, permissão 0600, fora do repositório e do backup),
`BACKUP_DIR` (padrão `var/backups` na raiz do projeto, fora do `public_html`),
`BACKUP_RETENTION_DAYS` (padrão 14) e `BACKUP_OFFSITE_DIR` (opcional). Saída e
códigos (0, 2, 3, 4) como em [`restore-backup.md`](./restore-backup.md); o
`Backup created: <caminho>` indica o `centralvet-<data>.tar.gz.cvbk`. O
heartbeat `backup` depende da migration 0019. Baixe os arquivos por SFTP para
fora do provedor com frequência: o disco da hospedagem não é cópia externa.

**Permissões (`--user 1000:33`).** No ambiente Docker (dev ou ensaio), rodar
`bin/hosting-backup.php` por `docker compose run` exige `--user 1000:33`: o
volume de documentos é 0750 de `www-data` (grupo 33), o container não tem
`DAC_OVERRIDE` e `var/drill` é 0700 do usuário 1000 do host; com o usuário
padrão a escrita ou a leitura falha e o script sai com código 2. Exemplo
(ensaio, sem gravar heartbeat no dev):

```bash
docker compose run --rm --no-deps -T --user 1000:33 \
  -v "$PWD/src:/var/www/html/src:ro" -v "$PWD/var/drill:/var/www/html/var/drill" \
  -e BACKUP_DIR=/var/www/html/var/drill/backups-hosting \
  -e BACKUP_PASSPHRASE_FILE=/var/www/html/var/drill/passphrase \
  app php bin/hosting-backup.php --no-heartbeat
```

Na hospedagem compartilhada real não há `--user`: o agendador roda como o
usuário da conta, que precisa **ler** `src/files` e a pasta de documentos e
**escrever** em `BACKUP_DIR` (e em `BACKUP_OFFSITE_DIR`, se houver).

### Restaurar (decifrar com `--decrypt`)

O agendador não restaura sozinho. Com autorização explícita e um backup do
estado atual:

1. Decifre o pacote (a frase vem do mesmo `BACKUP_PASSPHRASE_FILE`):

   ```bash
   cd /caminho/da/aplicacao/src
   php bin/hosting-backup.php --decrypt ../var/backups/centralvet-<data>.tar.gz.cvbk /caminho/privado/restore.tar.gz
   ```

   Sai 5 (e não cria a saída) com frase errada ou arquivo alterado; 2 por
   argumento e 3 por arquivo.
2. Extraia fora do `public_html` e confira o `MANIFEST`
   (`format=centralvet-backup-v1`, `rows <tabela>=<n>` e um `sha256` por parte):

   ```bash
   mkdir /caminho/privado/restore && tar -xzf /caminho/privado/restore.tar.gz -C /caminho/privado/restore
   cat /caminho/privado/restore/MANIFEST
   (cd /caminho/privado/restore && sha256sum db.sql.gz files.tar.gz documents.tar.gz)
   ```
3. Importe o banco com o cliente disponível: `db.sql.gz` traz `DROP TABLE IF
   EXISTS`, `CREATE TABLE`, `INSERT` e os triggers entre `DELIMITER ;;`, sem
   `DEFINER`. Com o cliente `mysql` (SSH ou máquina com acesso remoto):

   ```bash
   gunzip -c /caminho/privado/restore/db.sql.gz | mysql -h <host> -u <usuario> -p <banco>
   ```

   Sem `mysql`, use o importador do painel (phpMyAdmin) com o `.sql`
   descompactado, conferindo que o cliente respeita `DELIMITER` (senão os
   triggers falham: importe-os à parte). O usuário precisa de `DROP`, `CREATE`,
   `INSERT` e `TRIGGER` no banco; use o usuário de migration, nunca o de runtime.
4. Reponha os arquivos: `files.tar.gz` em `src/files` e `documents.tar.gz` na
   pasta `DOCUMENT_STORAGE_LOCAL_ROOT` (0750, dono do usuário do PHP):

   ```bash
   tar -xzf /caminho/privado/restore/files.tar.gz -C /caminho/da/aplicacao/src/files
   tar -xzf /caminho/privado/restore/documents.tar.gz -C <DOCUMENT_STORAGE_LOCAL_ROOT>
   ```

   Confira a raiz interna de cada tar (`tar -tzf ... | head`) antes de extrair,
   para não aninhar uma pasta dentro da outra.
5. Apague `/caminho/privado/restore*` (contêm dado pessoal em claro) e rode
   `php bin/migration-status.php`, `php bin/preflight.php --with-db` e a fumaça
   ([`release.md`](./release.md)). Rode `bin/billing-reconcile.php` uma vez para
   recuperar do Stripe os eventos posteriores ao backup.

### Ensaiar

O ensaio automatizado desse formato roda pela pilha Docker, em um projeto
descartável: `scripts/restore-drill.sh var/drill/backups-hosting/<arquivo>.tar.gz.cvbk`
decifra o pacote, restaura e compara com o `MANIFEST` (medido: 13 s,
`mismatches=0`). Gere o backup do ensaio com `php bin/hosting-backup.php
--no-heartbeat` e `BACKUP_DIR` apontando para `var/drill/backups-hosting`.
Resultados e passo a passo:
[`ensaio-restauracao-rollback.md`](./ensaio-restauracao-rollback.md).

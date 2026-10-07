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
DB_USERNAME, DB_PASSWORD, APP_ENV, APP_DEBUG e APP_TIMEZONE usando `putenv()`.
Não versionar esse arquivo. Bloquear acesso HTTP aos diretórios `app/config`
e `app/database` com `.htaccess` (`Require all denied`).

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
`src/app/database/seeds/initial-application-programs.sql`. Ele cadastra 45
controladores, libera-os somente para o grupo administrador e configura a
permissão de desconto por método. Não contém dados clínicos de demonstração.

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

### Anexos e fotos (driver local)

- Sem S3, use `STORAGE_DRIVER=local` para anexos de atendimento, resultados de
  exame e fotos de pacientes. A raiz vem de `STORAGE_LOCAL_ROOT`; ausente, cai
  em `DOCUMENT_STORAGE_LOCAL_ROOT`. Aponte-a para uma pasta **acima** do
  `public_html` (0750, dono do usuário do PHP); a `StorageFactory` recusa raiz
  dentro do webroot.
- Com `STORAGE_DRIVER` vazio e sem `S3_ENDPOINT`/`S3_BUCKET`, o padrão já é
  `local`.
- Objetos antigos gravados no S3 continuam legíveis só se as `S3_*` forem
  mantidas; sem elas, esses anexos e fotos ficam indisponíveis.
- O fallback das fotos só vale no sentido S3 → local. Fotos gravadas com o
  driver `local` não são procuradas na pasta local se o driver voltar a `s3`;
  antes de voltar, copie a pasta para o bucket ou mantenha `local`.

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


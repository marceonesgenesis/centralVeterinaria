# Leads da landing pública

A landing pública (`/`, visitante anônimo) envia o plano escolhido no carrinho
e os dados de contato por `POST /lead.php`. Cada envio aceito vira uma linha
em `landing_lead` (migration `20261001_0009_landing_lead`). A tabela **não**
tem `tenant_id`: o lead é pré-venda do operador da plataforma, anterior a
qualquer clínica. Nome e preço do plano são gravados a partir do
`LandingCatalog` no momento do pedido (nunca do payload) e não mudam depois.

> Nenhum passo com efeito real (DDL/DML) roda sem autorização SQL específica
> do usuário (`sql-write-approval`). Ver [`migrations.md`](./migrations.md).

## Aplicação

1. Backup testado: `./scripts/backup.sh` (ou `make backup`) e
   `gzip -t var/backups/centralvet-<timestamp>.sql.gz`.
2. Anotar antes, só com `SELECT`, `COUNT(*)` de `tenant`, `patient`, `tutor`,
   `system_program` e `system_group_program`.
3. `sha256sum src/app/database/migrations/20261001_0009_landing_lead.sql` e
   trocar o checksum de zeros do `INSERT INTO schema_migrations` pelo valor
   aprovado (procedimento em [`migrations.md`](./migrations.md)).
4. Aplicar `20261001_0009_landing_lead.sql` em `centralvet` com o usuário de
   migration. DDL MySQL não é transacional: em falha, pare e inspecione o
   `information_schema`, sem reaplicar às cegas.
5. Banco de teste: com aprovação própria, aplicar a mesma migration em
   `centralvet_test` (bancos novos já nascem com ela, porque
   `scripts/test-db/provision.sh` lista a 0009 depois da 0008; ver
   [`tests.md`](./tests.md)).

## Verificação

Rodar `src/app/database/migrations/20261001_0009_landing_lead.verify.sql`
(só `SELECT`) no banco em que a migration foi aplicada. Esperado:

- 1 linha em `schema_migrations` com `version = '20261001_0009_landing_lead'`;
- tabela `landing_lead` InnoDB, `utf8mb4_0900_ai_ci`, com 15 colunas e
  nenhuma `tenant_id`;
- índices `landing_lead_created_idx (created_at)` e
  `landing_lead_plan_idx (plan_id, created_at)`;
- checks `landing_lead_price_ck` e `landing_lead_vets_ck`;
- `COUNT(*)` de `tenant`, `patient`, `tutor` e `system_program` iguais aos
  anotados antes (`system_program` +1 só depois do SQL do programa admin).

## Rollback

Caminho preferido: restaurar o backup anterior à migration
([`restore-backup.md`](./restore-backup.md),
[`migration-rollback.md`](./migration-rollback.md)). Se for preciso desfazer
só a 0009 mantendo o resto, prepare uma migration reversa (não redigida),
com backup e aprovação SQL próprios, que exporte os leads (CSV da tela) e rode
`DROP TABLE landing_lead` e o `DELETE` da linha `20261001_0009_landing_lead`
em `schema_migrations`. Para o programa admin, a reversa remove
`system_group_program.id = 111` e `system_program.id = 109`.

## Programa admin

A tela `LandingLeadList` ("Leads da landing") é registrada por
`.claude/tasks/mar-20261001-2231-landing-publica-carrinho/sql/T-02-programs.sql`
em `centralvet` (banco permission = banco da aplicação), com aprovação SQL:
`system_program.id = 109` e `system_group_program.id = 111` (grupo 1,
"Template - Admin"). O script abre transação e confere `MAX(id)` de
`system_program` (esperado 108) e de `system_group_program` (esperado 110);
valor diferente → `ROLLBACK`, sem `COMMIT`, e reajuste dos ids. Os usuários
do grupo precisam sair e entrar de novo para o menu carregar o programa.

## Operação

- **Limite por IP:** 10 `POST /lead.php` por IP por hora, contados a cada
  POST (inclusive os recusados), com o `LoginRateLimiter` no Redis sob o
  prefixo `centralvet:lead-throttle:`. Excedido → `429`. O IP é o
  `REMOTE_ADDR`; o PHP nunca lê `X-Forwarded-For`. No nginx local
  (`docker/nginx/default.conf`), `set_real_ip_from` confia nas faixas
  RFC1918 (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16) e em 127.0.0.1, com
  `real_ip_header X-Forwarded-For;` e `real_ip_recursive on;`. Risco aceito:
  quem fala com o gateway forja o header, mas a porta só escuta em
  `127.0.0.1`; publicá-la em `0.0.0.0` exige rever `set_real_ip_from`. Para
  conferir o bucket de um IP:
  `redis-cli EXISTS centralvet:lead-throttle:$(printf 'lead|IP' | sha256sum | cut -d' ' -f1)`
  (`1` = há envios na janela). Na hospedagem compartilhada o IP real vem do
  `mod_remoteip` do provedor: ver `shared-hosting-mysql57.md` § IP do
  visitante e limite de envios da landing.
- **Token do formulário:** cada visualização da landing emite um token
  aleatório guardado no Redis (chave = sha256 do token, TTL 2 h), aceito só
  depois de 3 s da emissão e uma única vez. Token ausente, vencido, reusado ou
  Redis fora do ar → `403` e a página pede para recarregar (o lead se perde
  até o Redis voltar). O endpoint também exige `Content-Type:
  application/json`, `Origin`/`Sec-Fetch-Site` do próprio site e honeypot
  `website` vazio.
- **Onde ver os leads:** menu admin → "Leads da landing" (`LandingLeadList`),
  com filtro por plano e período, 20 por página e exportação CSV.
- **Dados pessoais:** nome, e-mail, telefone e IP do consentimento ficam em
  claro (`consent_ip` prova o consentimento LGPD da versão
  `consent_version`). Ainda não há prazo de retenção: o expurgo é manual, com
  aprovação SQL, até a próxima rodada.

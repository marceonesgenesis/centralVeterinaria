# Fumaça da contratação (banco de dev)

Roteiro da fumaça da contratação pela landing, pelo webhook assinado e pelo
reconciliador, no banco de dev `centralvet`, sem Stripe. Veio do roteiro da
Etapa 3 (T-22) e passou a usar os arquivos versionados em
`scripts/smoke/billing/`:

| Arquivo | Para quê |
|---|---|
| `scripts/smoke/billing/webhook-event.php` | Gera um evento do Stripe assinado com o segredo de **teste** e, com `--send`, faz o POST ao `billing-webhook.php` local |
| `scripts/smoke/billing/invite-link.php` | Imprime o link de primeiro acesso de um pedido `ZZ-E3-` provisionado (só `SELECT`) |
| `scripts/smoke/billing/cleanup-zz-e3.sql` | Apaga os dados `ZZ-E3-` e termina com o bloco "6. Prova" (só `SELECT`) |

> **Só com autorização.** A fumaça escreve no banco de dev (`UPDATE`/`INSERT`
> de simulação, pedidos, tenants, usuários) e na chave do limitador no Redis.
> Cada passo marcado **[autorizado]** ou com SQL de escrita exige autorização
> expressa do usuário para o banco `centralvet` (`sql-write-approval`). Nunca
> rode em produção, nunca com segredo ou chave reais do Stripe, e não escreva
> no `.env` da raiz.

A integração no `centralvet_test` (`BillingAcquisitionFlowIntegrationTest`)
já prova os 16 cenários (a..p) com o `FakeStripeClient`; nela as asserções
sobre as chamadas ao Stripe são filtradas pelo path (`/v1/checkout/sessions`,
`/v1/subscriptions`, `/v1/payment_intents`, `/v1/refunds`), não pela posição
na lista de chamadas (o `calls[1]` do cenário (c) foi trocado pelo filtro de
`POST /v1/checkout/sessions` na Etapa 4, T-06). A
fumaça confere o mesmo pelo nginx real, sem Stripe: onde o fluxo dependeria
do Stripe (sessão de checkout, `GET /v1/subscriptions`, PaymentIntent), um
passo SQL marcado **[simula Stripe]** faz esse papel. Pelo mesmo motivo, no
DevTools e nos logs identifique cada requisição pelo path (`/checkout.php`,
`/billing-webhook.php`, `index.php?class=...`), não pela ordem em que aparece.

## 0. Preparação

Rode da raiz do repositório. O gerador roda no host (PHP 8.3+ com o
`src/vendor` instalado); o `invite-link.php` roda no container `app`, que não
tem `scripts/` na imagem, por isso entra pela entrada padrão.

```bash
SMOKE=scripts/smoke/billing
APP=http://localhost:8081
export STRIPE_WEBHOOK_SECRET=whsec_test_etapa4      # segredo de TESTE, igual ao do app abaixo
EVDIR=$(mktemp -d)                                  # saídas do gerador (event_id, payload, assinatura)
# EV <nome> <tipo> <objeto-json> [--id <evt_zz_e3_...>] [--timestamp <unix>]: gera, envia e guarda a saída em $EVDIR/<nome>.out
EV() { local name=$1; shift; php "$SMOKE/webhook-event.php" "$@" --send "$APP/billing-webhook.php" | tee "$EVDIR/$name.out"; }
EVID() { sed -n 's/^event_id=//p' "$EVDIR/$1.out"; }
DEVSQL() { docker compose exec -T mysql sh -ceu 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch --skip-column-names centralvet' -e "$1"; }
RECON() { docker compose exec -T app php bin/billing-reconcile.php; echo "exit=$?"; }
INVITE() { docker compose exec -T app php -- "$1" < "$SMOKE/invite-link.php"; }
```

O gerador sai 2 com `STRIPE_WEBHOOK_SECRET` vazio ou tipo fora da lista
(`checkout.session.completed`, `checkout.session.async_payment_succeeded`,
`checkout.session.async_payment_failed`, `checkout.session.expired`,
`invoice.paid`, `invoice.payment_failed`, `payment_intent.succeeded`,
`charge.refunded`, `customer.subscription.deleted`). Todo `event_id` começa
com `evt_zz_e3_`, o que a limpeza usa.

1. **[autorizado] Migrations e seeds no dev**: confira com
   `scripts/migration-status.sh` (ou o `schema_migrations`) que a 0017 e a
   0018 estão aplicadas; se não, aplique-as (com os `.verify.sql`) pelo
   procedimento de [`migrations.md`](./migrations.md), depois
   `src/app/database/seeds/initial-application-programs.sql` e
   `src/app/database/seeds/plan-groups.sql`. Prova:
   ```sql
   SELECT name, (SELECT COUNT(*) FROM system_group_program gp WHERE gp.system_group_id = g.id) AS programas
   FROM system_group g WHERE name LIKE 'Central Vet - Plano %' ORDER BY id;
   -- esperado: Starter 46, Pro 65, Business 65, Enterprise 87
   SELECT g.name, p.controller FROM system_group g JOIN system_group_program gp ON gp.system_group_id = g.id
   JOIN system_program p ON p.id = gp.system_program_id
   WHERE g.name LIKE 'Central Vet - Plano %' AND g.name <> 'Central Vet - Plano Enterprise' AND p.controller LIKE 'Ai%';
   -- esperado: 0 linhas (IA só no Enterprise)
   ```
2. **[autorizado] Rebuild e subida** com o segredo de teste só no ambiente
   do processo (a variável do shell tem precedência sobre o `.env` na
   interpolação do compose) e a chave secreta do Stripe vazia:
   ```bash
   STRIPE_WEBHOOK_SECRET=whsec_test_etapa4 STRIPE_SECRET_KEY= docker compose up -d --build app worker
   docker compose exec -T app sh -c 'test "$STRIPE_WEBHOOK_SECRET" = whsec_test_etapa4 && test -z "$STRIPE_SECRET_KEY" && echo ok-env'
   docker compose exec -T app sh -c 'echo "driver=$COMMUNICATION_EMAIL_DRIVER"'    # esperado: driver=log
   ```
3. **Linha de base**: rode só o bloco "6. Prova" de
   `scripts/smoke/billing/cleanup-zz-e3.sql` (os `SELECT` do fim do arquivo)
   e confira `0` em todas as linhas. Anote qualquer órfão que já existia.
4. **Limitador de pedidos por IP**: o `POST /checkout.php` aceita até
   `CHECKOUT_RATE_LIMIT_MAX_ATTEMPTS` (padrão 10) tentativas por IP em
   `CHECKOUT_RATE_LIMIT_DECAY_SECONDS` (padrão 3600 s); a seguinte responde
   **429** `{"error":"rate_limited","retry_after":<s>}` com `Retry-After`.
   A seção 2 faz 6 compras, mais as repetições de erro: zere a chave do
   limitador no Redis antes de começar e sempre que aparecer um 429. A chave
   é `centralvet:checkout-throttle:` + SHA-256 de `checkout|<ip>` (o IP que
   o PHP vê, depois do `set_real_ip_from` do nginx):
   ```bash
   docker compose exec -T redis redis-cli --scan --pattern 'centralvet:checkout-throttle:*'
   docker compose exec -T redis sh -c "redis-cli --scan --pattern 'centralvet:checkout-throttle:*' | xargs -r redis-cli del"
   # com REDIS_PASSWORD definido, acrescente -a "$REDIS_PASSWORD" aos dois redis-cli
   ```
   Apague só as chaves `centralvet:checkout-throttle:*`: o mesmo Redis guarda
   sessões, tokens do formulário e o limitador de login.

Nomes usados (todo dado criado começa com `ZZ-E3-` / `zz-e3-`):

| Pedido | Plano | Forma | Clínica | Responsável | E-mail | Sessão simulada | Uso |
|---|---|---|---|---|---|---|---|
| A | Starter | Pix | ZZ-E3- Clínica A | ZZ-E3- Admin A | zz-e3-a@example.test | cs_zz_e3_a | (a) (e) (m) (n) (p), login |
| B | Pro | Pix | ZZ-E3- Clínica B | ZZ-E3- Admin B | zz-e3-b@example.test | cs_zz_e3_b | (m) (o) (p), 403 |
| R | Starter | Pix | ZZ-E3- Clínica R | ZZ-E3- Resp R | zz-e3-r@example.test | cs_zz_e3_r | (b) |
| P | Pro | Pix | ZZ-E3- Clínica P | ZZ-E3- Resp P | zz-e3-p@example.test | cs_zz_e3_p | (c) |
| C | Pro | Cartão | ZZ-E3- Clínica C | ZZ-E3- Admin C | zz-e3-c@example.test | cs_zz_e3_c | (f) (i) (j) (k) (l) |
| H | Starter | Cartão | ZZ-E3- Clínica H | ZZ-E3- Admin H | zz-e3-a@example.test (conflito) | cs_zz_e3_h | (h) |

Fuso: o PHP grava datas no relógio de `APP_TIMEZONE` (America/Fortaleza) e o
cliente `mysql` usa o fuso da sessão do servidor. Por isso os `UPDATE` de
simulação usam folgas de 1 dia (`NOW() - INTERVAL 1 DAY`), não de minutos.

## 1. Regressões

1. Navegador: `$APP/index.php` → login do administrador da plataforma
   (credencial do dev) → entra. Sair.
2. "Esqueci minha senha" com um usuário do dev → mensagem de envio, sem erro 500.
3. Gerar um PDF (recibo de venda ou documento clínico do tenant da
   plataforma) → o PDF abre.
4. `curl -s -o /dev/null -w '%{http_code}\n' "$APP/download.php?file=app/config/application.php"`
   → `404`; o mesmo para `download.php?file=tmp/x.pdf` → `404`.

## 2. Compra pela landing até o 502 (g)

Para cada linha da tabela (A, B, R, P, C, H), no navegador, em `$APP/`:

1. **Recarregue a página** antes de cada compra: cada carregamento emite um
   `LeadFormToken` novo, de uso único.
2. Clicar em "Assinar" no plano da linha → a seção `#assinar` aparece com o
   plano escolhido.
3. Preencher `sub-name`, `sub-email`, `sub-phone` (`(85) 99999-1234`),
   `sub-clinic`, a forma de pagamento (`#sub-methods`), marcar `sub-terms`;
   `sub-consent` desmarcado. Botão `#submit-subscribe`.
   **Tempo mínimo do `LeadFormToken`:** o token só vale a partir de 3 s depois
   do carregamento da página (`LeadFormToken::MIN_AGE_SECONDS`) e por até 2 h.
   Enviar antes disso, ou reenviar o mesmo token, dá **403**
   `{"error":"invalid_token"}`: recarregue, espere 3 s e envie de novo.
4. DevTools → Network, filtro `checkout.php`: `POST /checkout.php` responde
   **502** `{"error":"gateway_unavailable"}`; a tela mostra em pt-BR "Não foi
   possível iniciar o pagamento. Tente de novo em instantes." Um **429**
   `rate_limited` aqui é o limitador por IP: zere a chave no Redis (seção 0,
   passo 4) e repita a linha.
5. Rótulos da seção alinhados à esquerda e botão com 44 px ou mais (snippet
   da seção 6).

Com `STRIPE_SECRET_KEY` vazio, o `CurlStripeClient` recusa a chave antes de
montar a requisição (`StripeApiException`) e isso vira o 502. **Nenhuma
chamada sai para api.stripe.com.**

Prova (g) — os 6 pedidos `pending`, sem sessão e com o preço do servidor:
```sql
SELECT responsible_email, plan_id, payment_method, status, price_cents, stripe_checkout_session_id, terms_version
FROM billing_order WHERE clinic_name LIKE 'ZZ-E3-%' ORDER BY id;
-- esperado: 6 linhas, status=pending, stripe_checkout_session_id NULL,
-- price_cents Starter 5700 / Pro 9700, terms_version termos-uso-2026-10
```

## 3. [simula Stripe] Sessões de checkout abertas

```sql
UPDATE billing_order SET status = 'checkout_open',
  stripe_checkout_session_id = CONCAT('cs_zz_e3_', LOWER(RIGHT(clinic_name, 1))),
  stripe_customer_id = CONCAT('cus_zz_e3_', LOWER(RIGHT(clinic_name, 1)))
WHERE clinic_name LIKE 'ZZ-E3- Clínica _' AND status = 'pending';
SELECT clinic_name, status, stripe_checkout_session_id FROM billing_order WHERE clinic_name LIKE 'ZZ-E3-%' ORDER BY id;
-- esperado: 6 linhas checkout_open, sessões cs_zz_e3_a/b/r/p/c/h
```

O `public_id` dos pedidos de cartão é a marca (`billing_order`) que o
processador exige nos eventos de assinatura e fatura que chegam antes da
assinatura local:
```bash
PUB_C=$(DEVSQL "SELECT public_id FROM billing_order WHERE clinic_name = 'ZZ-E3- Clínica C'")
echo "$PUB_C"
```

## 4. Cenários pelo webhook local assinado

Cada `EV` imprime `event_id=`, `payload=`, `stripe_signature=` e a resposta
`HTTP <status> <corpo>`; a saída fica em `$EVDIR/<nome>.out`. Prova comum:
```sql
SELECT event_id, event_type, status, attempts, last_error FROM billing_event WHERE event_id = '<event_id impresso>';
```

### (a) Pagamento aprovado (Pix de A) → provisioned, 1 tenant, 1 convite
```bash
EV pix-a checkout.session.async_payment_succeeded '{"id":"cs_zz_e3_a","object":"checkout.session","mode":"payment","customer":"cus_zz_e3_a","subscription":null,"payment_intent":"pi_zz_e3_a","payment_status":"paid","amount_total":5700}'
# esperado: HTTP 200 {"received":true,"result":"processed"}
docker compose logs --since 5m app | grep 'communication.email.sandbox'   # 1 linha para zz-e3-a@example.test
```
```sql
SELECT o.status, o.tenant_id, o.invite_sent_at IS NOT NULL AS convite, t.legal_name, s.status AS assinatura, s.plan_id
FROM billing_order o JOIN tenant t ON t.id = o.tenant_id JOIN subscription s ON s.billing_order_id = o.id
WHERE o.responsible_email = 'zz-e3-a@example.test' AND o.clinic_name = 'ZZ-E3- Clínica A';
-- esperado: provisioned, convite=1, ZZ-E3- Clínica A, active, starter
SELECT COUNT(*) FROM tenant WHERE legal_name = 'ZZ-E3- Clínica A';                -- 1
SELECT tu.status, g.name FROM tenant_user tu JOIN system_users u ON u.id = tu.system_user_id
  JOIN system_user_group ug ON ug.system_user_id = u.id JOIN system_group g ON g.id = ug.system_group_id
WHERE u.login = 'zz-e3-a@example.test';                                            -- invited | Central Vet - Plano Starter
```
O driver `log` registra só destinatário e tamanho do corpo, não o link.
Gere o link de primeiro acesso (mesmo formato do convite, só `SELECT`):
```bash
INVITE zz-e3-a@example.test      # imprime order_id=<id> e link=<APP_URL>/index.php?class=SystemPasswordResetForm&...
```
Navegador: abrir o link → `SystemPasswordResetForm` → definir senha → login
como `zz-e3-a@example.test` → o primeiro acesso entra no sistema.
```sql
SELECT tu.status FROM tenant_user tu JOIN system_users u ON u.id = tu.system_user_id WHERE u.login = 'zz-e3-a@example.test';  -- active
```
Repita para B:
```bash
EV pix-b checkout.session.async_payment_succeeded '{"id":"cs_zz_e3_b","object":"checkout.session","mode":"payment","customer":"cus_zz_e3_b","subscription":null,"payment_intent":"pi_zz_e3_b","payment_status":"paid","amount_total":9700}'
INVITE zz-e3-b@example.test
```

### (b) Recusado (R) → expired, 0 tenant
```bash
EV failed-r checkout.session.async_payment_failed '{"id":"cs_zz_e3_r","object":"checkout.session","payment_status":"unpaid"}'   # HTTP 200 processed
```
```sql
SELECT status, tenant_id FROM billing_order WHERE responsible_email = 'zz-e3-r@example.test';   -- expired | NULL
SELECT COUNT(*) FROM tenant WHERE legal_name = 'ZZ-E3- Clínica R';                               -- 0
SELECT COUNT(*) FROM system_users WHERE login = 'zz-e3-r@example.test';                          -- 0
```

### (c) Pendente (P, Pix com checkout aberto, sem evento) → checkout_open, sem acesso
Nenhum envio.
```sql
SELECT status, tenant_id, paid_at FROM billing_order WHERE responsible_email = 'zz-e3-p@example.test';  -- checkout_open | NULL | NULL
SELECT COUNT(*) FROM tenant WHERE legal_name = 'ZZ-E3- Clínica P';                                     -- 0
SELECT COUNT(*) FROM system_users WHERE login = 'zz-e3-p@example.test';                                -- 0
```
Navegador: login com `zz-e3-p@example.test` e qualquer senha → recusado.

### (d) Webhook inválido → 400, 0 billing_event
```sql
SELECT COUNT(*) FROM billing_event;   -- anote N
```
```bash
P_OBJ='{"id":"cs_zz_e3_p","object":"checkout.session","mode":"payment","customer":"cus_zz_e3_p","subscription":null,"payment_intent":"pi_zz_e3_p","payment_status":"paid","amount_total":9700}'
STRIPE_WEBHOOK_SECRET=whsec_test_errado EV bad-secret checkout.session.async_payment_succeeded "$P_OBJ"
# esperado: HTTP 400 {"error":"invalid_signature"}
EV old-ts checkout.session.async_payment_succeeded "$P_OBJ" --timestamp $(( $(date +%s) - 3600 ))   # fora da tolerância de 300 s → 400
sed -n 's/^payload=//p' "$EVDIR/old-ts.out" > "$EVDIR/old-ts.json"
curl -sS -i -X POST "$APP/billing-webhook.php" -H 'Content-Type: application/json' --data-binary @"$EVDIR/old-ts.json"   # sem cabeçalho → 400
curl -sS -o /dev/null -w '%{http_code}\n' "$APP/billing-webhook.php"                                                     # GET → 405
```
```sql
SELECT COUNT(*) FROM billing_event;   -- igual a N
SELECT status FROM billing_order WHERE responsible_email = 'zz-e3-p@example.test';   -- checkout_open
```

### (e) Evento duplicado → duplicate, contagens iguais
```sql
SELECT (SELECT COUNT(*) FROM tenant) t, (SELECT COUNT(*) FROM system_users) u, (SELECT COUNT(*) FROM subscription) s,
       (SELECT COUNT(*) FROM subscription_invoice) i, (SELECT COUNT(*) FROM billing_event) e;   -- anote
```
```bash
EV pix-a-dup checkout.session.async_payment_succeeded '{"id":"cs_zz_e3_a","object":"checkout.session","mode":"payment","customer":"cus_zz_e3_a","subscription":null,"payment_intent":"pi_zz_e3_a","payment_status":"paid","amount_total":5700}' --id "$(EVID pix-a)"
# esperado: HTTP 200 {"received":true,"result":"duplicate"}
docker compose logs --since 2m app | grep -c 'communication.email.sandbox'   # 0 (nenhum convite novo)
```
Repita o `SELECT` de contagens → idêntico.

### (f) Fora de ordem (C, cartão): invoice.paid antes do checkout, depois reentrega → active
```bash
NOW=$(date +%s)
C1_OBJ='{"id":"in_zz_e3_c1","object":"invoice","subscription":"sub_zz_e3_c","status":"paid","amount_due":9700,"amount_paid":9700,"currency":"brl","lines":{"data":[{"period":{"start":'$((NOW-3600))',"end":'$((NOW+2592000))'}}]},"subscription_details":{"metadata":{"billing_order":"'"$PUB_C"'"}}}'
EV inv-c1 invoice.paid "$C1_OBJ"
# esperado: HTTP 500 {"error":"processing_failed"} (assinatura ainda não existe)
```
```sql
SELECT status, last_error FROM billing_event WHERE event_type = 'invoice.paid' AND event_id LIKE 'evt\_zz\_e3\_%' ORDER BY id DESC LIMIT 1;  -- failed
-- [simula Stripe] o GET /v1/subscriptions/sub_zz_e3_c que o processador faria no checkout:
INSERT INTO subscription (billing_order_id, plan_id, status, payment_method, stripe_customer_id, stripe_subscription_id,
  current_period_start, current_period_end, last_event_created)
SELECT id, 'pro', 'active', 'card', 'cus_zz_e3_c', 'sub_zz_e3_c', NOW() - INTERVAL 1 HOUR, NOW() + INTERVAL 30 DAY, UNIX_TIMESTAMP() - 7200
FROM billing_order WHERE clinic_name = 'ZZ-E3- Clínica C' AND status = 'checkout_open';
```
```bash
EV checkout-c checkout.session.completed '{"id":"cs_zz_e3_c","object":"checkout.session","mode":"subscription","customer":"cus_zz_e3_c","subscription":"sub_zz_e3_c","payment_intent":null,"payment_status":"paid","amount_total":9700}'
# 200 processed (provisiona C)
EV inv-c1-retry invoice.paid "$C1_OBJ" --id "$(EVID inv-c1)"      # reentrega do mesmo evento → 200 processed
```
```sql
SELECT o.status, s.status, i.status, i.stripe_invoice_id FROM billing_order o JOIN subscription s ON s.billing_order_id = o.id
JOIN subscription_invoice i ON i.subscription_id = s.id WHERE o.clinic_name = 'ZZ-E3- Clínica C';
-- provisioned | active | paid | in_zz_e3_c1
SELECT status FROM billing_event WHERE event_id = '<EVID inv-c1>';   -- processed
```

### (h) Aprovado com provisionamento interrompido (H) → provisioning_failed; o reconciliador conclui
H usa o e-mail de A (login já existe) para interromper o provisionamento.
```sql
INSERT INTO subscription (billing_order_id, plan_id, status, payment_method, stripe_customer_id, stripe_subscription_id,
  current_period_start, current_period_end, last_event_created)
SELECT id, 'starter', 'active', 'card', 'cus_zz_e3_h', 'sub_zz_e3_h', NOW() - INTERVAL 1 HOUR, NOW() + INTERVAL 30 DAY, UNIX_TIMESTAMP() - 7200
FROM billing_order WHERE clinic_name = 'ZZ-E3- Clínica H' AND status = 'checkout_open';   -- [simula Stripe]
```
```bash
EV checkout-h checkout.session.completed '{"id":"cs_zz_e3_h","object":"checkout.session","mode":"subscription","customer":"cus_zz_e3_h","subscription":"sub_zz_e3_h","payment_intent":null,"payment_status":"paid","amount_total":5700}'
# 200 processed
```
```sql
SELECT status, provisioning_error, tenant_id FROM billing_order WHERE clinic_name = 'ZZ-E3- Clínica H';   -- provisioning_failed | login_conflict | NULL
SELECT COUNT(*) FROM tenant WHERE legal_name = 'ZZ-E3- Clínica H';                                        -- 0
UPDATE billing_order SET responsible_email = 'zz-e3-h@example.test' WHERE clinic_name = 'ZZ-E3- Clínica H';  -- resolve o conflito
```
Resolva o conflito **antes** do reconciliador: desde a T-31 da Etapa 3, ele
cancela e estorna o pedido pago que continua com `login_conflict`.
```bash
RECON
# esperado: exit=1 com "billing-reconcile error=CentralVet\Billing\Stripe\StripeApiException" (o GET /v1/events
# é recusado localmente pela chave vazia, sem requisição a api.stripe.com), mas as fases seguintes rodam:
# fulfillOrders provisiona H.
```
```sql
SELECT status, invite_sent_at IS NOT NULL FROM billing_order WHERE clinic_name = 'ZZ-E3- Clínica H';  -- provisioned | 1
SELECT COUNT(*) FROM tenant WHERE legal_name = 'ZZ-E3- Clínica H';                                    -- 1
```

### (i) Renovação aprovada (C, 2º período) → current_period_end avança
```bash
END=$(DEVSQL "SELECT UNIX_TIMESTAMP(current_period_end) FROM subscription WHERE stripe_subscription_id = 'sub_zz_e3_c'")
EV inv-c2 invoice.paid '{"id":"in_zz_e3_c2","object":"invoice","subscription":"sub_zz_e3_c","status":"paid","amount_due":9700,"amount_paid":9700,"currency":"brl","lines":{"data":[{"period":{"start":'$END',"end":'$((END+2592000))'}}]},"subscription_details":{"metadata":{"billing_order":"'"$PUB_C"'"}}}'
# 200 processed
```
```sql
SELECT status, current_period_end FROM subscription WHERE stripe_subscription_id = 'sub_zz_e3_c';   -- active, ~30 dias depois do anterior
```

### (l) Estorno total (C) → fatura refunded
```bash
EV refund-c2 charge.refunded '{"id":"ch_zz_e3_c2","object":"charge","invoice":"in_zz_e3_c2","payment_intent":null,"amount":9700,"amount_refunded":9700,"refunded":true}'
# 200 processed
```
```sql
SELECT stripe_invoice_id, status FROM subscription_invoice WHERE stripe_invoice_id = 'in_zz_e3_c2';   -- refunded
SELECT status FROM subscription WHERE stripe_subscription_id = 'sub_zz_e3_c';                         -- active (estorno não muda acesso)
```

### (j) Renovação recusada (C) → past_due, tolerância de 7 dias, depois suspended
```bash
NOW=$(date +%s)
EV inv-c3 invoice.payment_failed '{"id":"in_zz_e3_c3","object":"invoice","subscription":"sub_zz_e3_c","status":"open","amount_due":9700,"amount_paid":0,"currency":"brl","lines":{"data":[{"period":{"start":'$NOW',"end":'$((NOW+2592000))'}}]},"subscription_details":{"metadata":{"billing_order":"'"$PUB_C"'"}}}'
# 200 processed
```
```sql
SELECT status, grace_until, TIMESTAMPDIFF(DAY, updated_at, grace_until) AS dias FROM subscription WHERE stripe_subscription_id = 'sub_zz_e3_c';
-- past_due | grace_until ≈ agora + 7 dias | dias = 7 (± fuso)
UPDATE subscription SET grace_until = NOW() - INTERVAL 1 DAY WHERE stripe_subscription_id = 'sub_zz_e3_c';   -- simula os 7 dias
```
```bash
RECON      # exit=1 pelo GET /v1/events; suspendGraceExpired roda mesmo assim
```
```sql
SELECT status FROM subscription WHERE stripe_subscription_id = 'sub_zz_e3_c';   -- suspended
```

### (k) Cancelamento (C) → cancelled
```bash
EV deleted-c customer.subscription.deleted '{"id":"sub_zz_e3_c","object":"subscription","status":"canceled","customer":"cus_zz_e3_c","metadata":{"billing_order":"'"$PUB_C"'"}}'
# 200 processed
```
```sql
SELECT status, cancelled_at IS NOT NULL FROM subscription WHERE stripe_subscription_id = 'sub_zz_e3_c';   -- cancelled | 1
```

### (n) Renovação Pix (A) → o reconciliador emite a fatura; payment_intent.succeeded avança o período
```sql
UPDATE subscription s JOIN billing_order o ON o.id = s.billing_order_id SET s.current_period_end = NOW() + INTERVAL 2 DAY
WHERE o.clinic_name = 'ZZ-E3- Clínica A';   -- simula o fim do período em 2 dias (emissão 3 dias antes)
```
```bash
RECON   # exit=1 (GET /v1/events); issuePixInvoices cria a fatura local; o POST /v1/payment_intents é recusado
        # localmente (chave vazia, sem rede) e a fatura fica sem PaymentIntent
INV_A=$(DEVSQL "SELECT i.id FROM subscription_invoice i JOIN subscription s ON s.id = i.subscription_id JOIN billing_order o ON o.id = s.billing_order_id WHERE o.clinic_name = 'ZZ-E3- Clínica A' AND i.status = 'open' ORDER BY i.id DESC LIMIT 1")
echo "$INV_A"
```
```sql
SELECT i.id, i.local_key, i.status, i.amount_due_cents, i.stripe_payment_intent_id, i.payment_intent_attempts, i.period_end
FROM subscription_invoice i JOIN subscription s ON s.id = i.subscription_id JOIN billing_order o ON o.id = s.billing_order_id
WHERE o.clinic_name = 'ZZ-E3- Clínica A' ORDER BY i.id;
-- 2 linhas: a do 1º mês (paid) e a nova (open, local_key pix:<id>:<data>, 5700, PI NULL, attempts 1)
```
```bash
EV pi-n payment_intent.succeeded '{"id":"pi_zz_e3_n","object":"payment_intent","status":"succeeded","amount":5700,"amount_received":5700,"currency":"brl","metadata":{"subscription_invoice":"'"$INV_A"'"}}'
# 200 processed
```
```sql
SELECT s.status, s.current_period_end, i.status FROM subscription s JOIN billing_order o ON o.id = s.billing_order_id
JOIN subscription_invoice i ON i.subscription_id = s.id WHERE o.clinic_name = 'ZZ-E3- Clínica A' ORDER BY i.id DESC LIMIT 1;
-- active | um mês depois do fim simulado | paid
```

### (o) Renovação Pix sem pagamento (B) → past_due, 7 dias, suspended; pagamento tardio volta a active
```sql
UPDATE subscription s JOIN billing_order o ON o.id = s.billing_order_id SET s.current_period_end = NOW() + INTERVAL 2 DAY
WHERE o.clinic_name = 'ZZ-E3- Clínica B';
```
```bash
RECON      # cria a fatura open de B (sem PI)
INV_B=$(DEVSQL "SELECT i.id FROM subscription_invoice i JOIN subscription s ON s.id = i.subscription_id JOIN billing_order o ON o.id = s.billing_order_id WHERE o.clinic_name = 'ZZ-E3- Clínica B' AND i.status = 'open' ORDER BY i.id DESC LIMIT 1")
```
```sql
UPDATE subscription_invoice i JOIN subscription s ON s.id = i.subscription_id JOIN billing_order o ON o.id = s.billing_order_id
SET i.due_at = NOW() - INTERVAL 1 DAY WHERE o.clinic_name = 'ZZ-E3- Clínica B' AND i.status = 'open';   -- simula o vencimento
```
```bash
RECON      # refreshOpenPix: fatura vencida → markOverdue
```
```sql
SELECT s.status, s.grace_until FROM subscription s JOIN billing_order o ON o.id = s.billing_order_id WHERE o.clinic_name = 'ZZ-E3- Clínica B';
-- past_due | ≈ agora + 7 dias
```
Navegador (B, ainda na tolerância): login `zz-e3-b@example.test` → acesso
normal; `SubscriptionStatusView` mostra "Em atraso"/tolerância e a fatura aberta.
```sql
UPDATE subscription s JOIN billing_order o ON o.id = s.billing_order_id SET s.grace_until = NOW() - INTERVAL 1 DAY WHERE o.clinic_name = 'ZZ-E3- Clínica B';
```
```bash
RECON      # suspendGraceExpired
```
```sql
SELECT s.status FROM subscription s JOIN billing_order o ON o.id = s.billing_order_id WHERE o.clinic_name = 'ZZ-E3- Clínica B';   -- suspended
```
Faça agora o bloco (m) e a parte "B bloqueado"; depois o pagamento tardio:
```bash
EV pi-o payment_intent.succeeded '{"id":"pi_zz_e3_o","object":"payment_intent","status":"succeeded","amount":9700,"amount_received":9700,"currency":"brl","metadata":{"subscription_invoice":"'"$INV_B"'"}}'
# 200 processed
```
```sql
SELECT s.status, s.grace_until, s.current_period_end FROM subscription s JOIN billing_order o ON o.id = s.billing_order_id
WHERE o.clinic_name = 'ZZ-E3- Clínica B';   -- active | NULL | um mês depois do fim simulado
```

### (m) Recurso de outro plano e assinatura suspensa
Com B `suspended` e A `active` (Starter):
- Navegador como A: `$APP/index.php?class=SaleList` → **403**, página HTML
  com "Seu plano não inclui este recurso", link "Assinatura" e "Sair".
  `index.php?class=PatientList` → abre normalmente.
- Navegador como B (sessão nova, ou espere 60 s do cache
  `cv_subscription_access`): `index.php?class=PatientList` → **403** com "Sua
  assinatura está suspensa. Regularize o pagamento para continuar";
  `index.php?class=SubscriptionStatusView` → abre (sempre liberada).
```sql
SELECT o.clinic_name, s.plan_id, s.status FROM subscription s JOIN billing_order o ON o.id = s.billing_order_id
WHERE o.clinic_name IN ('ZZ-E3- Clínica A', 'ZZ-E3- Clínica B');   -- A starter active | B pro suspended
```
DevTools → Network, filtro `index.php`: status 403 no GET do documento.

## 5. Telas da clínica — (p)

Como administrador de A (`zz-e3-a@example.test`):
1. Menu Configurações: itens "Assinatura", "Equipe" e "Unidades da clínica".
2. `SubscriptionStatusView`: plano Starter, estado ativo, período, faturas (a
   do 1º mês e a de (n)).
3. `ClinicUserList` → só os usuários de A. Convidar "ZZ-E3- Usuário A1",
   `zz-e3-a1@example.test`: o combo de grupo mostra **só** "Central Vet -
   Plano Starter"; salvar → convite no log
   (`docker compose logs --since 2m app | grep communication.email.sandbox`).
4. Convidar "ZZ-E3- Usuário A2", `zz-e3-a2@example.test` → "Seu plano permite
   até 2 usuários"; nada criado.
5. Editar A1 (nome "ZZ-E3- Usuário A1 editado") → salvo; desativar A1 →
   status inativo.
6. `ClinicUnitList` → só "ZZ-E3- Clínica A". Nova unidade "ZZ-E3- Unidade A2"
   → "Seu plano permite até 1 unidades". Renomear a própria unidade para
   "ZZ-E3- Clínica A (sede)" → salvo.
7. Fronteira de tenant (ids de B pelo `SELECT` abaixo):
   `index.php?class=ClinicUserForm&method=onEdit&id=<usuário de B>` e
   `index.php?class=ClinicUnitForm&method=onEdit&id=<unidade de B>` →
   recusados, nenhum dado de B na tela.
8. `SystemUserList`, `SystemUserForm`, `SystemUnitForm`, `SystemGroupForm` →
   "Permissão negada".
9. Em cada tela acima, rodar o snippet da seção 6.

```sql
SELECT u.id AS usuario_b, su.id AS unidade_b FROM tenant t JOIN tenant_user tu ON tu.tenant_id = t.id
JOIN system_users u ON u.id = tu.system_user_id JOIN system_unit su ON su.tenant_id = t.id WHERE t.legal_name = 'ZZ-E3- Clínica B';
-- Prova (p):
SELECT u.name, u.login, u.active, tu.status FROM tenant_user tu JOIN system_users u ON u.id = tu.system_user_id
JOIN tenant t ON t.id = tu.tenant_id WHERE t.legal_name LIKE 'ZZ-E3- Clínica A%' ORDER BY u.id;
-- ZZ-E3- Admin A | active ; ZZ-E3- Usuário A1 editado | N | inactive ; nenhuma linha de zz-e3-a2
SELECT COUNT(*) FROM system_users WHERE login = 'zz-e3-a2@example.test';                                   -- 0
SELECT name FROM system_unit WHERE tenant_id = (SELECT id FROM tenant WHERE legal_name = 'ZZ-E3- Clínica B');  -- inalterado
SELECT g.name FROM system_user_group ug JOIN system_group g ON g.id = ug.system_group_id
JOIN system_users u ON u.id = ug.system_user_id WHERE u.login LIKE 'zz-e3-a%';                             -- só Central Vet - Plano Starter
```

## 6. Rótulos à esquerda e alvos de 44 px

DevTools → Console, em cada tela, cortina e formulário da fumaça:
```js
(() => {
  const bad = [];
  document.querySelectorAll('label, .control-label, th, .tformrow label, .col-form-label').forEach(el => {
    const a = getComputedStyle(el).textAlign;
    if (el.offsetParent && !['left', 'start', '-webkit-left'].includes(a)) bad.push(['rótulo', a, el.textContent.trim().slice(0, 40)]);
  });
  document.querySelectorAll('button, .btn, a.btn, input[type=submit], input[type=button]').forEach(el => {
    const r = el.getBoundingClientRect();
    if (el.offsetParent && (r.height < 44 || r.width < 44)) bad.push(['alvo', Math.round(r.width) + 'x' + Math.round(r.height), (el.textContent || el.value || '').trim().slice(0, 40)]);
  });
  return bad.length ? bad : 'ok';
})()
```
Esperado: `"ok"` (anote as exceções com a tela).

## 7. Limpeza ZZ-E3- e prova final

**[autorizado]**
```bash
docker compose exec -T mysql sh -ceu 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch centralvet' < scripts/smoke/billing/cleanup-zz-e3.sql
```
O bloco "6. Prova" do fim do arquivo (depois do `COMMIT`) deve dar `0` em
todas as linhas. Depois:

1. Zere a chave do limitador no Redis (seção 0, passo 4).
2. Suba o `app` de novo sem o segredo de teste: `docker compose up -d app worker`.
3. `rm -rf "$EVDIR"` e `unset STRIPE_WEBHOOK_SECRET`.
4. Registre o resultado nas notas da execução.

## Mapa cenário → onde é provado

| Cenário | Integração (`centralvet_test`) | Fumaça (dev) |
|---|---|---|
| (a) | testScenarioA… | 4 (a) |
| (b) | testScenarioB… | 4 (b) |
| (c) | testScenarioC… (sessão filtrada por `/v1/checkout/sessions`) | 4 (c) |
| (d) | testScenarioD… | 4 (d) |
| (e) | testScenarioE… | 4 (e) |
| (f) | testScenarioF… | 4 (f), com [simula Stripe] no GET da assinatura |
| (g) | testScenarioG… | 2 (502 na landing) |
| (h) | testScenarioH… (só GET ao Stripe depois do webhook) | 4 (h), interrupção por login_conflict |
| (i) | testScenarioI… | 4 (i) |
| (j) | testScenarioJ… | 4 (j), 7 dias simulados por UPDATE |
| (k) | testScenarioK… | 4 (k) |
| (l) | testScenarioL… | 4 (l) |
| (m) | testScenarioM… | 4 (m) no navegador |
| (n) | testScenarioN… | 4 (n), fatura sem PI (sem chave) paga pelo evento |
| (o) | testScenarioO… | 4 (o), vencimento e 7 dias por UPDATE |
| (p) | testScenarioP… | 5 |

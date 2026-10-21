# T-22 — Roteiro da fumaça no navegador (banco de dev `centralvet`)

Quem executa: **só o orquestrador/validador**, depois da autorização do usuário para escrever no banco de dev, aplicar a 0017 e os seeds, fazer o rebuild e criar/excluir os dados `ZZ-E3-`. O Goku (T-22) não rodou nada daqui contra o dev; validou só o HMAC do script (contra `StripeSignatureVerifier`) e a sintaxe da limpeza (no `centralvet_test`, com ROLLBACK, 0 linhas).

A integração no `centralvet_test` (`BillingAcquisitionFlowIntegrationTest`, 16 PASS) já prova os 16 cenários (a..p) com o `FakeStripeClient`. A fumaça confere a mesma coisa pelo nginx real, sem Stripe: onde o fluxo de verdade dependeria do Stripe (sessão de checkout, `GET /v1/subscriptions`, PaymentIntent), um passo SQL marcado **[simula Stripe]** faz esse papel.

## 0. Preparação

Variáveis (rode da raiz `/var/www/html/centralvet`):

```bash
SCR=/tmp/claude-1000/-var-www-html-centralvet/fc92cc27-9f66-4ad7-bfe3-efed43d88248/scratchpad
EV="python3 -I $SCR/t22_webhook_events.py"          # gera; com --send envia ao nginx local
DEVSQL() { docker compose exec -T mysql sh -ceu 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch centralvet' -e "$1"; }
RECON() { docker compose exec -T app php bin/billing-reconcile.php; echo "exit=$?"; }
APP=http://localhost:8081
```

1. **[autorizado] 0017 e seeds no dev**, nesta ordem: `src/app/database/migrations/20261021_0017_etapa3_billing.sql` (e o `.verify.sql`), `src/app/database/seeds/initial-application-programs.sql`, `src/app/database/seeds/plan-groups.sql`. Prova:
   ```sql
   SELECT name, (SELECT COUNT(*) FROM system_group_program gp WHERE gp.system_group_id = g.id) AS programas
   FROM system_group g WHERE name LIKE 'Central Vet - Plano %' ORDER BY id;
   -- esperado: Starter 46, Pro 65, Business 65, Enterprise 87
   SELECT g.name, p.controller FROM system_group g JOIN system_group_program gp ON gp.system_group_id = g.id
   JOIN system_program p ON p.id = gp.system_program_id
   WHERE g.name LIKE 'Central Vet - Plano %' AND g.name <> 'Central Vet - Plano Enterprise' AND p.controller LIKE 'Ai%';
   -- esperado: 0 linhas (IA só no Enterprise; reaplicar o seed inicial não muda as contagens — T-25 Correção pós-onda)
   ```
2. **[autorizado] Rebuild e subida** com o segredo de **teste** do webhook só no ambiente do processo (sem escrever no `.env`; variável do shell tem precedência sobre o `.env` na interpolação do compose) e os demais `STRIPE_*` vazios:
   ```bash
   STRIPE_WEBHOOK_SECRET=whsec_test_etapa3 STRIPE_SECRET_KEY= docker compose up -d --build app worker
   docker compose exec -T app sh -c 'test "$STRIPE_WEBHOOK_SECRET" = whsec_test_etapa3 && test -z "$STRIPE_SECRET_KEY" && echo ok-env'
   docker compose exec -T app sh -c 'echo "driver=$COMMUNICATION_EMAIL_DRIVER"'    # esperado: driver=log
   ```
3. Linha de base (antes de criar qualquer dado): o bloco "6. Prova" de `$SCR/t22-cleanup-zz-e3.sql` deve dar 0 em todas as linhas. Anote se algum órfão já existia antes.

Nomes usados (todo dado criado começa com `ZZ-E3-` / `zz-e3-`):

| Pedido | Plano | Forma | Clínica | Responsável | E-mail | Sessão simulada | Uso |
|---|---|---|---|---|---|---|---|
| A | Starter | Pix | ZZ-E3- Clínica A | ZZ-E3- Admin A | zz-e3-a@example.test | cs_zz_e3_a | (a) (e) (m) (n) (p), login |
| B | Pro | Pix | ZZ-E3- Clínica B | ZZ-E3- Admin B | zz-e3-b@example.test | cs_zz_e3_b | (m) (o) (p), 403 |
| R | Starter | Pix | ZZ-E3- Clínica R | ZZ-E3- Resp R | zz-e3-r@example.test | cs_zz_e3_r | (b) |
| P | Pro | Pix | ZZ-E3- Clínica P | ZZ-E3- Resp P | zz-e3-p@example.test | cs_zz_e3_p | (c) |
| C | Pro | Cartão | ZZ-E3- Clínica C | ZZ-E3- Admin C | zz-e3-c@example.test | cs_zz_e3_c | (f) (i) (j) (k) (l) |
| H | Starter | Cartão | ZZ-E3- Clínica H | ZZ-E3- Admin H | zz-e3-a@example.test (conflito) | cs_zz_e3_h | (h) |

Fuso: o PHP grava datas no relógio de `APP_TIMEZONE` (America/Fortaleza) e o cliente `mysql` usa o fuso da sessão do servidor. Por isso os `UPDATE` de simulação usam folgas de 1 dia (`NOW() - INTERVAL 1 DAY`) e não de minutos.

## 1. Regressões (S5, T-05)

1. Navegador: `$APP/index.php` → login do administrador da plataforma (credencial do dev) → entra. Sair.
2. "Esqueci minha senha" com um usuário do dev → mensagem de envio, sem erro 500 (php-jwt 7).
3. Gerar um PDF (ex.: recibo de venda ou documento clínico do tenant da plataforma) → o PDF abre (dompdf 3.1.6).
4. T-05: `curl -s -o /dev/null -w '%{http_code}\n' "$APP/download.php?file=app/config/application.php"` → `404`; o mesmo para `download.php?file=tmp/x.pdf` → `404`.

## 2. Compra pela landing até o 502 (g)

Para cada linha da tabela (A, B, R, P, C, H), no navegador, em `$APP/`:
1. Clicar em "Assinar" no plano da linha → a seção `#assinar` aparece com o plano escolhido.
2. Preencher `sub-name`, `sub-email`, `sub-phone` (`(85) 99999-1234`), `sub-clinic`, a forma de pagamento (`#sub-methods`), marcar `sub-terms`; `sub-consent` desmarcado. Botão `#submit-subscribe`.
3. DevTools → Network: `POST /checkout.php` responde **502** `{"error":"gateway_unavailable"}`; a tela mostra em pt-BR "Não foi possível iniciar o pagamento. Tente de novo em instantes."
4. Rótulos da seção alinhados à esquerda e botão com 44 px ou mais (snippet da seção 6).

Observação: com `STRIPE_SECRET_KEY` vazio o `CurlStripeClient` ainda tenta `POST https://api.stripe.com/v1/customers` sem credencial (Stripe responde 401, ou falha de rede) e isso vira o 502. Nenhuma conta real é usada.

Prova (g) — os 6 pedidos `pending`, sem sessão e com o preço do servidor:
```sql
SELECT responsible_email, plan_id, payment_method, status, price_cents, stripe_checkout_session_id, terms_version
FROM billing_order WHERE clinic_name LIKE 'ZZ-E3-%' ORDER BY id;
-- esperado: 6 linhas, status=pending, stripe_checkout_session_id NULL,
-- price_cents Starter 5700 / Pro 9700, terms_version assinatura-2026-10
```

## 3. [simula Stripe] Sessões de checkout abertas

O Stripe real abriria a sessão; aqui o pedido passa a `checkout_open` com a sessão simulada:
```sql
UPDATE billing_order SET status = 'checkout_open',
  stripe_checkout_session_id = CONCAT('cs_zz_e3_', LOWER(RIGHT(clinic_name, 1))),
  stripe_customer_id = CONCAT('cus_zz_e3_', LOWER(RIGHT(clinic_name, 1)))
WHERE clinic_name LIKE 'ZZ-E3- Clínica _' AND status = 'pending';
SELECT clinic_name, status, stripe_checkout_session_id FROM billing_order WHERE clinic_name LIKE 'ZZ-E3-%' ORDER BY id;
-- esperado: 6 linhas checkout_open, sessões cs_zz_e3_a/b/r/p/c/h
```

## 4. Cenários pelo webhook local assinado

Cada `$EV ... --send` imprime `event_id`, o `Stripe-Signature` e a resposta `HTTP <status> <corpo>`; o corpo fica em `$SCR/t22-events/`. Prova comum de cada envio:
```sql
SELECT event_id, event_type, status, attempts, last_error FROM billing_event WHERE event_id = '<event_id impresso>';
```

### (a) Pagamento aprovado (Pix de A) → provisioned, 1 tenant, 1 convite
```bash
$EV checkout-async-succeeded --session cs_zz_e3_a --pi pi_zz_e3_a --amount 5700 --send
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
O driver `log` registra só destinatário e tamanho do corpo, não o link. Para a definição de senha, gere um link no mesmo formato do convite (só SELECT):
```bash
docker compose exec -T app php -- zz-e3-a@example.test < $SCR/t22_invite_link.php
```
Navegador: abrir o link → `SystemPasswordResetForm` → definir senha → login como `zz-e3-a@example.test` → primeiro acesso entra no sistema.
```sql
SELECT tu.status FROM tenant_user tu JOIN system_users u ON u.id = tu.system_user_id WHERE u.login = 'zz-e3-a@example.test';  -- active
```
Repita o envio e o primeiro acesso para B: `$EV checkout-async-succeeded --session cs_zz_e3_b --pi pi_zz_e3_b --amount 9700 --send`, link com `zz-e3-b@example.test`.

### (b) Recusado (R) → expired, 0 tenant
```bash
$EV checkout-async-failed --session cs_zz_e3_r --send      # HTTP 200 processed
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
Navegador: login com `zz-e3-p@example.test` e qualquer senha → recusado (usuário inexistente).

### (d) Webhook inválido → 400, 0 billing_event
```sql
SELECT COUNT(*) INTO @ev FROM billing_event; SELECT @ev;   -- anote N
```
```bash
$EV checkout-async-succeeded --session cs_zz_e3_p --pi pi_zz_e3_p --amount 9700 --secret whsec_test_errado --send
# esperado: HTTP 400 {"error":"invalid_signature"}
F=$(ls -t $SCR/t22-events/pix-paid-*.json | head -1)
curl -sS -i -X POST "$APP/billing-webhook.php" -H 'Content-Type: application/json' --data-binary @"$F"   # sem cabeçalho → 400
$EV resend "$F" --timestamp $(( $(date +%s) - 3600 )) --send                                              # fora da tolerância → 400
curl -sS -o /dev/null -w '%{http_code}\n' "$APP/billing-webhook.php"                                     # GET → 405
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
FA=$(grep -l '"cs_zz_e3_a"' $SCR/t22-events/pix-paid-*.json | head -1)
$EV resend "$FA" --send          # HTTP 200 {"received":true,"result":"duplicate"}
docker compose logs --since 2m app | grep -c 'communication.email.sandbox'   # 0 (nenhum convite novo)
```
Repita o SELECT de contagens → idêntico.

### (f) Fora de ordem (C, cartão): invoice.paid antes do checkout, depois reenvio → active
```bash
NOW=$(date +%s)
$EV invoice-paid --invoice in_zz_e3_c1 --subscription sub_zz_e3_c --start $((NOW-3600)) --end $((NOW+2592000)) --amount 9700 --send
# esperado: HTTP 500 {"error":"processing_failed"} (assinatura ainda não existe)
```
```sql
SELECT status, last_error FROM billing_event WHERE event_type = 'invoice.paid' AND event_id LIKE 'evt_zz_e3_%' ORDER BY id DESC LIMIT 1;  -- failed
-- [simula Stripe] o GET /v1/subscriptions/sub_zz_e3_c que o processador faria no checkout:
INSERT INTO subscription (billing_order_id, plan_id, status, payment_method, stripe_customer_id, stripe_subscription_id,
  current_period_start, current_period_end, last_event_created)
SELECT id, 'pro', 'active', 'card', 'cus_zz_e3_c', 'sub_zz_e3_c', NOW() - INTERVAL 1 HOUR, NOW() + INTERVAL 30 DAY, UNIX_TIMESTAMP() - 7200
FROM billing_order WHERE clinic_name = 'ZZ-E3- Clínica C' AND status = 'checkout_open';
```
```bash
$EV checkout-completed --session cs_zz_e3_c --subscription sub_zz_e3_c --send      # 200 processed (provisiona C)
FC=$(ls -t $SCR/t22-events/invoice-paid-*.json | head -1)
$EV resend "$FC" --send                                                             # 200 processed
```
```sql
SELECT o.status, s.status, i.status, i.stripe_invoice_id FROM billing_order o JOIN subscription s ON s.billing_order_id = o.id
JOIN subscription_invoice i ON i.subscription_id = s.id WHERE o.clinic_name = 'ZZ-E3- Clínica C';
-- provisioned | active | paid | in_zz_e3_c1
SELECT status FROM billing_event WHERE event_id = '<event_id do invoice.paid>';   -- processed
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
$EV checkout-completed --session cs_zz_e3_h --subscription sub_zz_e3_h --send     # 200 processed
```
```sql
SELECT status, provisioning_error, tenant_id FROM billing_order WHERE clinic_name = 'ZZ-E3- Clínica H';   -- provisioning_failed | login_conflict | NULL
SELECT COUNT(*) FROM tenant WHERE legal_name = 'ZZ-E3- Clínica H';                                        -- 0
UPDATE billing_order SET responsible_email = 'zz-e3-h@example.test' WHERE clinic_name = 'ZZ-E3- Clínica H';  -- resolve o conflito
```
```bash
RECON
# esperado: exit=1 com "billing-reconcile error=CentralVet\Billing\Stripe\StripeApiException" (o GET /v1/events
# falha sem chave), mas as fases seguintes rodam: fulfillOrders provisiona H. Sem POST ao Stripe nesta fase
# (provado na integração: testScenarioH... só GET depois do webhook).
```
```sql
SELECT status, provisioning_error, invite_sent_at IS NOT NULL FROM billing_order WHERE clinic_name = 'ZZ-E3- Clínica H';  -- provisioned | (anterior) | 1
SELECT COUNT(*) FROM tenant WHERE legal_name = 'ZZ-E3- Clínica H';                                                        -- 1
```

### (i) Renovação aprovada (C, 2º período) → current_period_end avança
```sql
SELECT UNIX_TIMESTAMP(current_period_end) INTO @end FROM subscription WHERE stripe_subscription_id = 'sub_zz_e3_c'; SELECT @end, current_period_end FROM subscription WHERE stripe_subscription_id = 'sub_zz_e3_c';
```
```bash
END=<@end>
$EV invoice-paid --invoice in_zz_e3_c2 --subscription sub_zz_e3_c --start $END --end $((END+2592000)) --amount 9700 --send   # 200 processed
```
```sql
SELECT status, current_period_end FROM subscription WHERE stripe_subscription_id = 'sub_zz_e3_c';   -- active, ~30 dias depois do anterior
```

### (l) Estorno total (C) → fatura refunded
```bash
$EV charge-refunded --invoice in_zz_e3_c2 --amount 9700 --send      # 200 processed
```
```sql
SELECT stripe_invoice_id, status FROM subscription_invoice WHERE stripe_invoice_id = 'in_zz_e3_c2';   -- refunded
SELECT status FROM subscription WHERE stripe_subscription_id = 'sub_zz_e3_c';                         -- active (estorno não muda acesso)
```

### (j) Renovação recusada (C) → past_due, tolerância de 7 dias, depois suspended e blocked_status
```bash
NOW=$(date +%s)
$EV invoice-payment-failed --invoice in_zz_e3_c3 --subscription sub_zz_e3_c --start $NOW --end $((NOW+2592000)) --amount 9700 --send   # 200 processed
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

### (k) Cancelamento (C) → cancelled e blocked_status
```bash
$EV subscription-deleted --subscription sub_zz_e3_c --send      # 200 processed
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
RECON   # exit=1 (GET /v1/events); issuePixInvoices cria a fatura local; o POST /v1/payment_intents falha sem chave
        # e a fatura fica sem PaymentIntent (a próxima rodada tentaria de novo com a mesma Idempotency-Key)
```
```sql
SELECT i.id, i.local_key, i.status, i.amount_due_cents, i.stripe_payment_intent_id, i.payment_intent_attempts, i.period_end
FROM subscription_invoice i JOIN subscription s ON s.id = i.subscription_id JOIN billing_order o ON o.id = s.billing_order_id
WHERE o.clinic_name = 'ZZ-E3- Clínica A' ORDER BY i.id;
-- 2 linhas: a do 1º mês (paid) e a nova (open, local_key pix:<id>:<data>, 5700, PI NULL, attempts 1)
```
```bash
$EV payment-intent-succeeded --pi pi_zz_e3_n --invoice-id <id da fatura open> --amount 5700 --send    # 200 processed
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
Navegador (B, ainda na tolerância): login `zz-e3-b@example.test` → acesso normal; `SubscriptionStatusView` mostra "Em atraso"/tolerância e a fatura aberta.
```sql
UPDATE subscription s JOIN billing_order o ON o.id = s.billing_order_id SET s.grace_until = NOW() - INTERVAL 1 DAY WHERE o.clinic_name = 'ZZ-E3- Clínica B';
```
```bash
RECON      # suspendGraceExpired
```
```sql
SELECT s.status FROM subscription s JOIN billing_order o ON o.id = s.billing_order_id WHERE o.clinic_name = 'ZZ-E3- Clínica B';   -- suspended
```
Faça agora o bloco (m) e a parte "B bloqueado" da seção 5; depois o pagamento tardio:
```bash
$EV payment-intent-succeeded --pi pi_zz_e3_o --invoice-id <id da fatura open de B> --amount 9700 --send   # 200 processed
```
```sql
SELECT s.status, s.grace_until, s.current_period_end FROM subscription s JOIN billing_order o ON o.id = s.billing_order_id
WHERE o.clinic_name = 'ZZ-E3- Clínica B';   -- active | NULL | um mês depois do fim simulado
```

### (m) Recurso de outro plano e assinatura de outro tenant
Com B `suspended` e A `active` (Starter):
- Navegador como A: `$APP/index.php?class=SaleList` → **403**, página HTML com "Seu plano não inclui este recurso", link "Assinatura" e "Sair" (não tela em branco). `index.php?class=PatientList` → abre normalmente (A continua `full`).
- Navegador como B (sessão nova, ou espere 60 s do cache `cv_subscription_access`): `index.php?class=PatientList` → **403** com "Sua assinatura está suspensa. Regularize o pagamento para continuar"; `index.php?class=SubscriptionStatusView` → abre (sempre liberada).
```sql
SELECT o.clinic_name, s.plan_id, s.status FROM subscription s JOIN billing_order o ON o.id = s.billing_order_id
WHERE o.clinic_name IN ('ZZ-E3- Clínica A', 'ZZ-E3- Clínica B');   -- A starter active | B pro suspended
```
DevTools → Network: status 403 no GET do documento `index.php`.

## 5. Telas da clínica (T-19, T-21, T-24, T-25) — (p)

Como administrador de A (`zz-e3-a@example.test`):
1. Menu Configurações: itens "Assinatura", "Equipe" e "Unidades da clínica".
2. `SubscriptionStatusView` (T-21): plano Starter, estado ativo, período, faturas (a do 1º mês e a de (n)) com o link de pagamento quando houver.
3. `ClinicUserList` → lista só os usuários de A (o admin). Convidar "ZZ-E3- Usuário A1", `zz-e3-a1@example.test`: o combo de grupo mostra **só** "Central Vet - Plano Starter"; salvar → convite no log (`docker compose logs --since 2m app | grep communication.email.sandbox`).
4. Convidar "ZZ-E3- Usuário A2", `zz-e3-a2@example.test` → mensagem "Seu plano permite até 2 usuários"; nada criado.
5. Editar A1 (nome "ZZ-E3- Usuário A1 editado") → salvo; desativar A1 → status inativo.
6. `ClinicUnitList` → só a unidade "ZZ-E3- Clínica A". Nova unidade "ZZ-E3- Unidade A2" → "Seu plano permite até 1 unidades" (Starter já está na quota com a unidade provisionada). Renomear a própria unidade para "ZZ-E3- Clínica A (sede)" → salvo.
7. Fronteira de tenant (ids de B pelo SELECT abaixo): `index.php?class=ClinicUserForm&method=onEdit&id=<usuário de B>` e `index.php?class=ClinicUnitForm&method=onEdit&id=<unidade de B>` → recusados (mensagem de recurso de outro tenant), nenhum dado de B na tela.
8. `index.php?class=SystemUserList`, `SystemUserForm`, `SystemUnitForm`, `SystemGroupForm` → "Permissão negada".
9. Em cada tela acima, rodar o snippet da seção 6.

```sql
SELECT u.id AS usuario_b, su.id AS unidade_b FROM tenant t JOIN tenant_user tu ON tu.tenant_id = t.id
JOIN system_users u ON u.id = tu.system_user_id JOIN system_unit su ON su.tenant_id = t.id WHERE t.legal_name = 'ZZ-E3- Clínica B';
-- Prova (p):
SELECT u.name, u.login, u.active, tu.status FROM tenant_user tu JOIN system_users u ON u.id = tu.system_user_id
JOIN tenant t ON t.id = tu.tenant_id WHERE t.legal_name LIKE 'ZZ-E3- Clínica A%' ORDER BY u.id;
-- ZZ-E3- Admin A | active ; ZZ-E3- Usuário A1 editado | N | inactive ; nenhuma linha de zz-e3-a2
SELECT COUNT(*) FROM system_users WHERE login = 'zz-e3-a2@example.test';                                   -- 0
SELECT name FROM system_unit WHERE tenant_id = (SELECT id FROM tenant WHERE legal_name = 'ZZ-E3- Clínica B');  -- inalterado (ZZ-E3- Clínica B)
SELECT g.name FROM system_user_group ug JOIN system_group g ON g.id = ug.system_group_id
JOIN system_users u ON u.id = ug.system_user_id WHERE u.login LIKE 'zz-e3-a%';                             -- só Central Vet - Plano Starter
```

## 6. Rótulos à esquerda e alvos de 44 px

DevTools → Console (ou `evaluate_script`), em cada tela/cortina/form da fumaça:
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

```bash
docker compose exec -T mysql sh -ceu 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch centralvet' < $SCR/t22-cleanup-zz-e3.sql
```
O último SELECT do arquivo (depois do COMMIT) deve dar `0` em todas as linhas. Prova pedida no critério:
```sql
SELECT (SELECT COUNT(*) FROM tenant WHERE legal_name LIKE 'ZZ-E3-%')
     + (SELECT COUNT(*) FROM billing_order WHERE clinic_name LIKE 'ZZ-E3-%' OR responsible_name LIKE 'ZZ-E3-%')
     + (SELECT COUNT(*) FROM system_users WHERE name LIKE 'ZZ-E3-%' OR login LIKE 'zz-e3-%')
     + (SELECT COUNT(*) FROM system_unit WHERE name LIKE 'ZZ-E3-%')
     + (SELECT COUNT(*) FROM billing_event WHERE event_id LIKE 'evt\_zz\_e3\_%') AS zz_e3;   -- 0
```
Depois: subir `app` de novo sem o segredo de teste (`docker compose up -d app worker`), apagar `$SCR/t22-events/` e registrar o resultado em `notes.md`.

## Mapa cenário → onde é provado

| Cenário | Integração (`centralvet_test`) | Fumaça (dev) |
|---|---|---|
| (a) | testScenarioA… | 4 (a) |
| (b) | testScenarioB… | 4 (b) |
| (c) | testScenarioC… | 4 (c) |
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
| (n) | testScenarioN… (PaymentIntent do fake) | 4 (n), fatura sem PI (sem chave) paga pelo evento |
| (o) | testScenarioO… | 4 (o), vencimento e 7 dias por UPDATE |
| (p) | testScenarioP… | 5 |

-- Fumaça da contratação (Etapa 4, T-06): limpeza dos dados ZZ-E3- criados
-- pelo roteiro docs/runbooks/smoke-billing.md. Roda SÓ com autorização do
-- usuário para escrever no banco de dev `centralvet` (nunca em produção):
--
--   docker compose exec -T mysql sh -ceu 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 --batch centralvet' < scripts/smoke/billing/cleanup-zz-e3.sql
--
-- Escopo: só linhas com o marcador da fumaça — tenant.legal_name e
-- billing_order.clinic_name 'ZZ-E3-%', system_users.login 'zz-e3-%',
-- billing_event.event_id 'evt_zz_e3_%' — e as linhas que dependem delas.
-- As tabelas temporárias zz_e3_* guardam os ids marcados; todo DELETE filtra
-- por elas ou pelo marcador. Para só conferir (linha de base), rode apenas o
-- bloco "6. Prova", que é só SELECT.

START TRANSACTION;

-- 1. Ids marcados
CREATE TEMPORARY TABLE zz_e3_order AS SELECT id FROM billing_order WHERE clinic_name LIKE 'ZZ-E3-%';
CREATE TEMPORARY TABLE zz_e3_subscription AS SELECT s.id FROM subscription s JOIN billing_order o ON o.id = s.billing_order_id WHERE o.clinic_name LIKE 'ZZ-E3-%';
CREATE TEMPORARY TABLE zz_e3_tenant AS SELECT id FROM tenant WHERE legal_name LIKE 'ZZ-E3-%';
CREATE TEMPORARY TABLE zz_e3_user AS SELECT id FROM system_users WHERE login LIKE 'zz-e3-%';
CREATE TEMPORARY TABLE zz_e3_unit AS SELECT su.id FROM system_unit su JOIN tenant t ON t.id = su.tenant_id WHERE t.legal_name LIKE 'ZZ-E3-%';

-- 2. Cobrança
DELETE FROM billing_event WHERE LEFT(event_id, 10) = 'evt_zz_e3_';
DELETE FROM subscription_invoice WHERE subscription_id IN (SELECT id FROM zz_e3_subscription);
DELETE FROM subscription WHERE id IN (SELECT id FROM zz_e3_subscription);
DELETE FROM billing_order WHERE id IN (SELECT id FROM zz_e3_order);

-- 3. Tenant: auditoria, vínculos e grupos
DELETE FROM audit_log WHERE tenant_id IN (SELECT id FROM zz_e3_tenant);
DELETE FROM tenant_user WHERE tenant_id IN (SELECT id FROM zz_e3_tenant);
DELETE FROM tenant_group WHERE tenant_id IN (SELECT id FROM zz_e3_tenant);

-- 4. Usuários zz-e3-* (convidados pela fumaça e pelas telas da clínica)
DELETE FROM system_user_old_password WHERE system_user_id IN (SELECT id FROM zz_e3_user);
DELETE FROM system_user_program WHERE system_user_id IN (SELECT id FROM zz_e3_user);
DELETE FROM system_user_group WHERE system_user_id IN (SELECT id FROM zz_e3_user);
DELETE FROM system_user_unit WHERE system_user_id IN (SELECT id FROM zz_e3_user);
DELETE FROM system_user_unit WHERE system_unit_id IN (SELECT id FROM zz_e3_unit);
DELETE FROM system_users WHERE id IN (SELECT id FROM zz_e3_user);

-- 5. Unidades e tenants
DELETE FROM system_unit WHERE id IN (SELECT id FROM zz_e3_unit);
DELETE FROM tenant WHERE id IN (SELECT id FROM zz_e3_tenant);

COMMIT;

DROP TEMPORARY TABLE zz_e3_order, zz_e3_subscription, zz_e3_tenant, zz_e3_user, zz_e3_unit;

-- 6. Prova (só SELECT): 0 em todas as linhas
SELECT 'tenant' AS tabela, COUNT(*) AS zz_e3 FROM tenant WHERE legal_name LIKE 'ZZ-E3-%'
UNION ALL SELECT 'billing_order', COUNT(*) FROM billing_order WHERE clinic_name LIKE 'ZZ-E3-%' OR responsible_name LIKE 'ZZ-E3-%' OR responsible_email LIKE 'zz-e3-%'
UNION ALL SELECT 'subscription', COUNT(*) FROM subscription WHERE stripe_subscription_id LIKE 'sub\_zz\_e3\_%' OR stripe_customer_id LIKE 'cus\_zz\_e3\_%'
UNION ALL SELECT 'subscription_invoice', COUNT(*) FROM subscription_invoice WHERE stripe_invoice_id LIKE 'in\_zz\_e3\_%' OR stripe_payment_intent_id LIKE 'pi\_zz\_e3\_%'
UNION ALL SELECT 'billing_event', COUNT(*) FROM billing_event WHERE event_id LIKE 'evt\_zz\_e3\_%'
UNION ALL SELECT 'system_users', COUNT(*) FROM system_users WHERE name LIKE 'ZZ-E3-%' OR login LIKE 'zz-e3-%'
UNION ALL SELECT 'system_unit', COUNT(*) FROM system_unit WHERE name LIKE 'ZZ-E3-%';

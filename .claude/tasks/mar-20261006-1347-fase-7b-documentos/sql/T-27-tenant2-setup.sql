SET NAMES utf8mb4;
START TRANSACTION;
-- T-27 — Segundo tenant de teste "F7B teste Clínica 2" para o E2E de isolamento cross-tenant
-- da Fase 7B (banco local centralvet).
--
-- NÃO EXECUTAR SEM APROVAÇÃO. Só o orquestrador executa, com aprovação SQL explícita do usuário
-- (skill sql-write-approval) e backup antes:
--   ./scripts/backup.sh && gzip -t var/backups/<arquivo>.sql.gz
-- Execução interativa (para ler os SELECTs antes de decidir o COMMIT):
--   docker compose exec mysql sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" --default-character-set=utf8mb4 centralvet'
--   mysql> source /caminho/T-27-tenant2-setup.sql
-- O COMMIT final está comentado: sem ele, fechar a sessão desfaz tudo. No `source` interativo o
-- cliente NÃO para no erro; procure "ERROR" na saída e, se houver, rode ROLLBACK.
-- Recomendado: ensaio completo terminando em ROLLBACK antes da execução com COMMIT.
--
-- Modelo de tenant (lido em 2026-10-06 só com SELECT/SHOW CREATE TABLE, MySQL 8.0.43):
--   tenant (AUTO_INCREMENT; public_id UUID e slug únicos; status active|suspended|cancelled)
--   tenant_user (AUTO_INCREMENT) — é por aqui que o login resolve o tenant da sessão:
--     ApplicationAuthenticationService::resolveTenantId → SELECT tenant_id FROM tenant_user
--     WHERE system_user_id = ? LIMIT 1 → TSession 'tenantid' → TenantContext.
--   tenant_group / tenant_role (AUTO_INCREMENT) — vínculo do tenant com grupos/papéis Adianti;
--     o runtime não consulta, mas o tenant 1 tem as linhas (espelhado aqui só para os grupos 1, 2).
--   system_unit.tenant_id (NOT NULL, FK). Sem AUTO_INCREMENT: id = MAX(id)+1, como o Adianti faz.
--   system_users / system_user_unit / system_user_group: sem AUTO_INCREMENT e sem tenant_id;
--     id = MAX(id)+1. multiunit=1: o LoginForm lista as unidades de system_user_unit e o
--     setUnit só aceita uma delas; system_users.system_unit_id é a unidade padrão.
--   Não há tabela de plano, assinatura nem configuração por tenant (multi_database=0, então
--   system_unit.connection_name não é usado; preenchido com 'unit_a' como as unidades do tenant 1).
--   Grupos: 1 'Template - Admin' e 2 'Template - Users' dão DocumentList, DocumentTemplateList,
--   PendingCenter, TutorList/Form, PatientList/Form, VaccinationForm (system_group_program).
--
-- Senha: só o hash bcrypt (password_hash PASSWORD_DEFAULT, gerado no container app; o
-- SystemUser::authenticate usa password_verify). A senha temporária está no relatório T-27.
--
-- Idempotência: cada INSERT só grava se a linha natural ainda não existe (slug, nome da unidade
-- no tenant, login), e as variáveis são resolvidas de novo pela chave natural depois de cada
-- INSERT. Rodar duas vezes não duplica nada.

-- =============================================================================================
-- 0. Pré-condições (só leitura). Esperado: tudo vazio/0. Se listar algo, PARE (ROLLBACK).
-- =============================================================================================
SELECT 'tenant_existente' AS t, id, slug, legal_name, status FROM tenant
 WHERE slug = 'f7b-teste-clinica-2' OR legal_name LIKE 'F7B teste%';
SELECT 'unidade_existente' AS t, id, tenant_id, name FROM system_unit WHERE name LIKE 'F7B teste%';
SELECT 'usuario_existente' AS t, id, name, login FROM system_users
 WHERE login = 'f7b.tenant2@example.invalid' OR name = 'F7B teste Admin 2';
SELECT 'grupos' AS t, id, name FROM system_group WHERE id IN (1, 2);  -- esperado: 2 linhas
SELECT COUNT(*) AS tenant_total FROM tenant;                          -- lido: 1
SELECT MAX(id) AS system_unit_max FROM system_unit;                   -- lido: 2
SELECT MAX(id) AS system_users_max FROM system_users;                 -- lido: 1
SELECT MAX(id) AS system_user_unit_max FROM system_user_unit;         -- lido: 2
SELECT MAX(id) AS system_user_group_max FROM system_user_group;       -- lido: 3

-- =============================================================================================
-- 1. Tenant (AUTO_INCREMENT → LAST_INSERT_ID, com fallback pela chave natural)
-- =============================================================================================
INSERT INTO tenant (public_id, slug, legal_name, trade_name, status, timezone)
SELECT UUID(), 'f7b-teste-clinica-2', 'F7B teste Clínica 2', 'F7B teste Clínica 2', 'active', 'America/Fortaleza'
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM tenant WHERE slug = 'f7b-teste-clinica-2');
SET @t2_insert_id = LAST_INSERT_ID();
SET @t2 = (SELECT id FROM tenant WHERE slug = 'f7b-teste-clinica-2' AND legal_name = 'F7B teste Clínica 2');
-- confere: @t2 = @t2_insert_id (na primeira execução) e @t2 > 1
SELECT @t2 AS t2, @t2_insert_id AS last_insert_id, (@t2 IS NOT NULL AND @t2 > 1) AS t2_ok;

-- grupos permitidos do tenant (espelho do tenant 1 para os grupos usados aqui)
INSERT INTO tenant_group (tenant_id, system_group_id)
SELECT @t2, g.id FROM system_group g
 WHERE g.id IN (1, 2) AND @t2 > 1
   AND NOT EXISTS (SELECT 1 FROM tenant_group tg WHERE tg.tenant_id = @t2 AND tg.system_group_id = g.id);

-- =============================================================================================
-- 2. Unidade (sem AUTO_INCREMENT → MAX(id)+1)
-- =============================================================================================
SET @u2_next = (SELECT COALESCE(MAX(id), 0) + 1 FROM system_unit);
INSERT INTO system_unit (id, tenant_id, name, connection_name, custom_code)
SELECT @u2_next, @t2, 'F7B teste Unit 2', 'unit_a', NULL FROM DUAL
 WHERE @t2 > 1
   AND NOT EXISTS (SELECT 1 FROM system_unit WHERE tenant_id = @t2 AND name = 'F7B teste Unit 2');
SET @u2 = (SELECT id FROM system_unit WHERE tenant_id = @t2 AND name = 'F7B teste Unit 2');

-- =============================================================================================
-- 3. Usuário (sem AUTO_INCREMENT → MAX(id)+1), vínculo só com a nova unidade e o novo tenant
-- =============================================================================================
SET @user2_next = (SELECT COALESCE(MAX(id), 0) + 1 FROM system_users);
INSERT INTO system_users (id, name, login, password, email, accepted_term_policy, frontpage_id,
                          system_unit_id, active)
SELECT @user2_next, 'F7B teste Admin 2', 'f7b.tenant2@example.invalid',
       '$2y$12$SM2pI37OC8//MYY6mzl40u3dtjA2XPP7upDwQbMaNyjSyjDN9rccO',
       'f7b.tenant2@example.invalid', 'Y', NULL, @u2, 'Y' FROM DUAL
 WHERE @u2 IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM system_users WHERE login = 'f7b.tenant2@example.invalid');
SET @user2 = (SELECT id FROM system_users WHERE login = 'f7b.tenant2@example.invalid' AND name = 'F7B teste Admin 2');

SET @uu_next = (SELECT COALESCE(MAX(id), 0) + 1 FROM system_user_unit);
INSERT INTO system_user_unit (id, system_user_id, system_unit_id)
SELECT @uu_next, @user2, @u2 FROM DUAL
 WHERE @user2 IS NOT NULL AND @u2 IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM system_user_unit WHERE system_user_id = @user2 AND system_unit_id = @u2);

-- grupos 1 e 2: um INSERT por grupo (id = MAX+1 recalculado a cada um)
SET @ug_next = (SELECT COALESCE(MAX(id), 0) + 1 FROM system_user_group);
INSERT INTO system_user_group (id, system_user_id, system_group_id)
SELECT @ug_next, @user2, 1 FROM DUAL
 WHERE @user2 IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM system_user_group WHERE system_user_id = @user2 AND system_group_id = 1);
SET @ug_next = (SELECT COALESCE(MAX(id), 0) + 1 FROM system_user_group);
INSERT INTO system_user_group (id, system_user_id, system_group_id)
SELECT @ug_next, @user2, 2 FROM DUAL
 WHERE @user2 IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM system_user_group WHERE system_user_id = @user2 AND system_group_id = 2);

-- tenant_user (AUTO_INCREMENT): é o que o login usa para a sessão 'tenantid'
INSERT INTO tenant_user (tenant_id, system_user_id, status)
SELECT @t2, @user2, 'active' FROM DUAL
 WHERE @t2 > 1 AND @user2 IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM tenant_user WHERE system_user_id = @user2);

-- =============================================================================================
-- 4. Conferência (esperado nos comentários)
-- =============================================================================================
SELECT @t2 AS t2, @u2 AS u2, @user2 AS user2;                                    -- 3 não nulos
SELECT id, public_id, slug, legal_name, trade_name, status, timezone FROM tenant WHERE id = @t2;   -- 1 linha
SELECT tenant_id, system_group_id FROM tenant_group WHERE tenant_id = @t2 ORDER BY system_group_id; -- 1, 2
SELECT id, tenant_id, name, connection_name FROM system_unit WHERE id = @u2;     -- tenant_id = @t2
SELECT id, name, login, email, system_unit_id, active, frontpage_id,
       LEFT(password, 4) AS hash_prefix FROM system_users WHERE id = @user2;     -- unidade @u2, Y, $2y$
SELECT system_user_id, system_unit_id FROM system_user_unit WHERE system_user_id = @user2;   -- só @u2
SELECT system_user_id, system_group_id FROM system_user_group WHERE system_user_id = @user2
 ORDER BY system_group_id;                                                        -- 1, 2
SELECT tenant_id, system_user_id, status FROM tenant_user WHERE system_user_id = @user2;     -- @t2, active
-- isolamento do vínculo: o Admin 2 não pode ter unidade nem tenant do tenant 1 (esperado 0 e 0)
SELECT COUNT(*) AS unidades_fora FROM system_user_unit uu JOIN system_unit u ON u.id = uu.system_unit_id
 WHERE uu.system_user_id = @user2 AND u.tenant_id <> @t2;
SELECT COUNT(*) AS tenants_fora FROM tenant_user WHERE system_user_id = @user2 AND tenant_id <> @t2;
-- o tenant 1 continua igual (esperado: 1 tenant 'active' id 1; unidades 1 e 2; admin só no tenant 1)
SELECT id, slug, status FROM tenant ORDER BY id;
SELECT id, tenant_id, name FROM system_unit ORDER BY id;
SELECT tenant_id, system_user_id FROM tenant_user ORDER BY tenant_id, system_user_id;

-- Confira tudo acima. Se bater, descomente e rode o COMMIT; senão, ROLLBACK.
-- ROLLBACK;
-- COMMIT;

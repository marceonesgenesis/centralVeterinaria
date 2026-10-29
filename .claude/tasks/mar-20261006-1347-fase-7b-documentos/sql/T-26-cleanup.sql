SET NAMES utf8mb4;
START TRANSACTION;
-- T-26 — Limpeza dos registros do gate da Onda 8 da Fase 7B (banco centralvet)
--
-- NÃO EXECUTAR SEM APROVAÇÃO. Só o orquestrador executa, com aprovação SQL explícita do
-- usuário (skill sql-write-approval) e backup antes:
--   ./scripts/backup.sh && gzip -t var/backups/<arquivo>.sql.gz
-- Execução interativa (para ler os SELECTs antes de decidir o COMMIT):
--   docker compose exec mysql sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" --default-character-set=utf8mb4 centralvet'
--   mysql> source /caminho/T-26-cleanup.sql
-- O COMMIT final está comentado: sem ele, fechar a sessão desfaz tudo. Atenção: no `source`
-- interativo o cliente mysql NÃO para no erro (FK RESTRICT, CHECK); ele segue para o comando
-- seguinte dentro da mesma transação. Por isso, execute em duas etapas:
--   1) `source` do script inteiro (o COMMIT fica comentado) e procure "ERROR" na saída;
--      com qualquer ERROR, rode ROLLBACK e reveja o predicado; só sem erro confira os SELECTs.
--   2) Só então digite COMMIT; à mão, na mesma sessão (ou ROLLBACK; se algo não bater).
-- Recomendado: ensaio completo terminando em ROLLBACK antes da execução com COMMIT.
--
-- Estado lido em 2026-10-06 16:16 (só SELECT, MySQL 8.0.43), DEPOIS do gate da Onda 8
-- (reviews/T-23.md, T-24.md, T-25.md § Gate onda 8) e depois da limpeza da T-21:
--   tutor 8 (MAX 15448), patient 8 (MAX 13322), generated_document 1 (id 10),
--   document_template 1 (id 3), stored_object 10 (MAX 2141), system_users 2 (MAX 2),
--   system_user_group 4 (MAX 4), system_user_unit 3 (MAX 3), communication_message 0,
--   communication_preference 0, encounter 7, vaccination 0, prescription 3, surgery 0,
--   appointment 15, audit_log MAX(id) 6058, system_access_log 63 (MAX 63).
--   Pré-gate da Onda 8 (= estado final esperado): tutor 7/13876, patient 7/9180,
--   generated_document 0, document_template 0, stored_object 9/2131, system_users 1,
--   system_user_group 3, system_user_unit 2.
--   Criados pelo gate da Onda 8 (alvo deste script, todos conferidos por SELECT):
--     tutor 15448 'F7B teste Tutor' (e-mail f7b.teste@example.invalid, 16:07:35),
--     patient 13322 'F7B teste' (tutor 15448), document_template 3 'F7B teste Atestado',
--     generated_document 10 (medical_certificate, paciente 13322, tutor 15448, template 3,
--     objeto 2141), stored_object 2141 (local,
--     cv/development/tenant/1/objects/documents/10/3b58d651eb50ab49.pdf),
--     system_users 2 'F7B teste sem acesso' (login/e-mail f7b.semacesso@example.invalid),
--     system_user_group 4 (user 2 → grupo 3), system_user_unit 3 (user 2 → unidade 1).
--
-- Dependentes descobertos por information_schema.KEY_COLUMN_USAGE (todas as FKs que apontam
-- para tutor, patient, generated_document, stored_object, document_template e system_users),
-- com contagem por id do gate:
--   generated_document.patient_id/tutor_id/stored_object_id/template_id → só o documento 10;
--   patient.tutor_id → só o paciente 13322; system_user_group/system_user_unit → só 4 e 3.
--   Zero linhas: communication_message, communication_preference, encounter, appointment,
--   vaccination, prescription, surgery*, exam_request, encounter_account, receivable, sale,
--   queue_entry, hospitalization, procedure_execution; e, para o usuário 2,
--   system_user_program, system_user_role, system_user_old_password, tenant_user, stored_object
--   .created_by, generated_document.requested_by (o documento 10 foi pedido pelo usuário 1),
--   document_template.created_by/updated_by e todas as colunas *_system_user_id clínicas.
--   As FKs são RESTRICT/NO ACTION, exceto audit_log.system_user_id (ON DELETE SET NULL).
--
-- Fora de propósito (não apagados):
--   audit_log — trilha append-only, como nas fases anteriores. Hoje 0 linhas com
--     system_user_id = 2 (o usuário sem acesso foi negado antes de auditar), então o SET NULL
--     da FK não altera nada; a seção 1 confere esse 0. As 24 linhas de auditoria do gate
--     (DocumentRequestForm, DocumentList, PendingCenter...) são do usuário 1 e ficam.
--   system_access_log — 2 linhas (ids 62, 63) com login f7b.semacesso@example.invalid; sem FK
--     (referência por texto), log de acesso do framework: ficam.
--   system_access_notification_log, system_change_log, system_request_log, system_sql_log,
--     system_notification, system_message, system_preference — 0 linhas do usuário 2; sem FK.
--
-- Ordem das FKs: generated_document (FK patient, tutor, stored_object, template) →
-- stored_object → document_template → patient → tutor → system_user_group →
-- system_user_unit → system_user_program/role/old_password/tenant_user (0 hoje; apagados por
-- guarda para a FK RESTRICT não interromper) → system_users.
--
-- Predicados: id explícito + prefixo 'F7B teste' / e-mail de teste / vínculo esperado.
-- Nenhum DELETE sem WHERE.
--
-- PARE (ROLLBACK) se: algum "confere" da seção 2 vier 0; algum SELECT "*_fora" listar linhas;
-- "audit_log_user2" não vier 0; ou as linhas afetadas divergirem das contagens "antes".
--
-- Arquivo do volume app_documents (NÃO EXECUTAR aqui; só depois do COMMIT e com confirmação):
--   docker compose exec -T app rm -f -- /var/www/html/var/documents/cv/development/tenant/1/objects/documents/10/3b58d651eb50ab49.pdf
--   docker compose exec -T app rmdir -- /var/www/html/var/documents/cv/development/tenant/1/objects/documents/10
-- Depois do rm: docker compose exec -T app find /var/www/html/var/documents/cv -type f | wc -l
-- deve bater com o número de stored_object local restantes (hoje 1 arquivo e 1 objeto local → 0).

-- Início do gate da Onda 8 (primeiro registro F7B: 16:07:35). Ajuste se o gate começou antes.
SET @gate_start = '2026-10-06 16:00:00';

-- =============================================================================================
-- 1. Contagens antes (só leitura)
-- =============================================================================================
SELECT COUNT(*) AS tutor_total              FROM tutor;               -- 8 (MAX 15448)
SELECT COUNT(*) AS patient_total            FROM patient;             -- 8 (MAX 13322)
SELECT COUNT(*) AS generated_document_total FROM generated_document;  -- 1
SELECT COUNT(*) AS document_template_total  FROM document_template;   -- 1
SELECT COUNT(*) AS stored_object_total      FROM stored_object;       -- 10 (MAX 2141)
SELECT COUNT(*) AS system_users_total       FROM system_users;        -- 2
SELECT COUNT(*) AS system_user_group_total  FROM system_user_group;   -- 4
SELECT COUNT(*) AS system_user_unit_total   FROM system_user_unit;    -- 3
SELECT COUNT(*) AS audit_log_user2          FROM audit_log WHERE system_user_id = 2;  -- 0 (se não, PARE)

-- =============================================================================================
-- 2. Revisão das linhas que serão apagadas (antes). Sem telefone, corpo nem texto.
--    Cada SELECT usa o MESMO predicado do DELETE correspondente da seção 3.
-- =============================================================================================
SELECT 'document' AS t, g.id, g.tenant_id, g.kind, g.patient_id, g.tutor_id, g.status, g.stored_object_id,
       g.template_id, g.created_at
  FROM generated_document g
 WHERE g.id = 10 AND g.patient_id = 13322 AND g.tutor_id = 15448;
SELECT 'object' AS t, o.id, o.storage_provider, o.object_key, o.status, o.created_at
  FROM stored_object o
 WHERE o.id = 2141 AND o.tenant_id = 1
   AND o.object_key = 'cv/development/tenant/1/objects/documents/10/3b58d651eb50ab49.pdf';
SELECT 'template' AS t, m.id, m.kind, m.name, m.status
  FROM document_template m
 WHERE m.id = 3 AND m.name LIKE 'F7B teste%';
SELECT 'patient' AS t, p.id, p.name, p.tutor_id
  FROM patient p
 WHERE p.id = 13322 AND p.name LIKE 'F7B teste%' AND p.tutor_id = 15448;
SELECT 'tutor' AS t, u.id, u.full_name, u.created_at
  FROM tutor u
 WHERE u.id = 15448 AND u.full_name LIKE 'F7B teste%' AND u.email = 'f7b.teste@example.invalid';
SELECT 'user_group' AS t, x.id, x.system_user_id, x.system_group_id
  FROM system_user_group x
 WHERE x.id = 4 AND x.system_user_id = 2 AND x.system_group_id = 3;
SELECT 'user_unit' AS t, x.id, x.system_user_id, x.system_unit_id
  FROM system_user_unit x
 WHERE x.id = 3 AND x.system_user_id = 2 AND x.system_unit_id = 1;
SELECT 'user' AS t, s.id, s.name, s.login, s.active
  FROM system_users s
 WHERE s.id = 2 AND s.login = 'f7b.semacesso@example.invalid'
   AND s.email = 'f7b.semacesso@example.invalid' AND s.name LIKE 'F7B teste%';
-- ^ dependentes do usuário 2 sem linha hoje (todos 0; se não, revise antes de seguir):
SELECT COUNT(*) AS user2_programs    FROM system_user_program      WHERE system_user_id = 2;
SELECT COUNT(*) AS user2_roles       FROM system_user_role         WHERE system_user_id = 2;
SELECT COUNT(*) AS user2_old_pw      FROM system_user_old_password WHERE system_user_id = 2;
SELECT COUNT(*) AS user2_tenant_user FROM tenant_user              WHERE system_user_id = 2;
-- ^ logs sem FK que ficam (informativo):
SELECT 'access_log_fica' AS t, a.id, a.login_time FROM system_access_log a WHERE a.login = 'f7b.semacesso@example.invalid';

-- 2.1 Linhas criadas no gate fora da lista (devem ser vazias; se listarem, PARE)
SELECT 'tutor_fora' AS t, u.id, u.full_name FROM tutor u
 WHERE u.id <> 15448 AND (u.full_name LIKE 'F7B teste%' OR u.email LIKE 'f7b%@example.invalid' OR u.id > 13876);
SELECT 'patient_fora' AS t, p.id, p.name FROM patient p
 WHERE p.id <> 13322 AND (p.name LIKE 'F7B teste%' OR p.tutor_id = 15448 OR p.id > 9180);
SELECT 'documentos_fora' AS t, g.id, g.kind, g.patient_id, g.status FROM generated_document g WHERE g.id <> 10;
SELECT 'template_fora' AS t, m.id, m.name FROM document_template m WHERE m.id <> 3;
SELECT 'objetos_fora' AS t, o.id, o.storage_provider, o.created_at FROM stored_object o
 WHERE o.id <> 2141 AND (o.id > 2131 OR o.created_at >= @gate_start);
SELECT 'users_fora' AS t, s.id, s.login FROM system_users s
 WHERE s.id NOT IN (1, 2) OR (s.id <> 2 AND s.login LIKE 'f7b%');
SELECT 'user_group_fora' AS t, x.id, x.system_user_id FROM system_user_group x WHERE x.system_user_id = 2 AND x.id <> 4;
SELECT 'user_unit_fora'  AS t, x.id, x.system_user_id FROM system_user_unit  x WHERE x.system_user_id = 2 AND x.id <> 3;

-- 2.2 Conferência dos predicados contra os ids do gate (confere = 1 em todas; se 0, PARE)
SELECT 'document' AS t, GROUP_CONCAT(id ORDER BY id) AS ids, GROUP_CONCAT(id ORDER BY id) <=> '10' AS confere
  FROM generated_document WHERE id = 10 AND patient_id = 13322 AND tutor_id = 15448;
SELECT 'object' AS t, GROUP_CONCAT(id ORDER BY id) AS ids, GROUP_CONCAT(id ORDER BY id) <=> '2141' AS confere
  FROM stored_object WHERE id = 2141 AND tenant_id = 1
   AND object_key = 'cv/development/tenant/1/objects/documents/10/3b58d651eb50ab49.pdf';
SELECT 'template' AS t, GROUP_CONCAT(id ORDER BY id) AS ids, GROUP_CONCAT(id ORDER BY id) <=> '3' AS confere
  FROM document_template WHERE id = 3 AND name LIKE 'F7B teste%';
SELECT 'patient' AS t, GROUP_CONCAT(id ORDER BY id) AS ids, GROUP_CONCAT(id ORDER BY id) <=> '13322' AS confere
  FROM patient WHERE id = 13322 AND name LIKE 'F7B teste%' AND tutor_id = 15448;
SELECT 'tutor' AS t, GROUP_CONCAT(id ORDER BY id) AS ids, GROUP_CONCAT(id ORDER BY id) <=> '15448' AS confere
  FROM tutor WHERE id = 15448 AND full_name LIKE 'F7B teste%' AND email = 'f7b.teste@example.invalid';
SELECT 'user_group' AS t, GROUP_CONCAT(id ORDER BY id) AS ids, GROUP_CONCAT(id ORDER BY id) <=> '4' AS confere
  FROM system_user_group WHERE id = 4 AND system_user_id = 2 AND system_group_id = 3;
SELECT 'user_unit' AS t, GROUP_CONCAT(id ORDER BY id) AS ids, GROUP_CONCAT(id ORDER BY id) <=> '3' AS confere
  FROM system_user_unit WHERE id = 3 AND system_user_id = 2 AND system_unit_id = 1;
SELECT 'user' AS t, GROUP_CONCAT(id ORDER BY id) AS ids, GROUP_CONCAT(id ORDER BY id) <=> '2' AS confere
  FROM system_users WHERE id = 2 AND login = 'f7b.semacesso@example.invalid'
   AND email = 'f7b.semacesso@example.invalid' AND name LIKE 'F7B teste%';

-- =============================================================================================
-- 3. Limpeza (ordem das FKs). Toda instrução tem WHERE por id explícito e guarda.
-- =============================================================================================

-- 3.1 Documentos: generated_document → stored_object → document_template
DELETE FROM generated_document WHERE id = 10 AND patient_id = 13322 AND tutor_id = 15448;
DELETE FROM stored_object      WHERE id = 2141 AND tenant_id = 1
   AND object_key = 'cv/development/tenant/1/objects/documents/10/3b58d651eb50ab49.pdf';
DELETE FROM document_template  WHERE id = 3 AND name LIKE 'F7B teste%';

-- 3.2 Paciente → tutor
DELETE FROM patient WHERE id = 13322 AND name LIKE 'F7B teste%' AND tutor_id = 15448;
DELETE FROM tutor   WHERE id = 15448 AND full_name LIKE 'F7B teste%' AND email = 'f7b.teste@example.invalid';

-- 3.3 Usuário sem acesso: vínculos → usuário (0 linhas esperadas nos quatro do meio)
DELETE FROM system_user_group WHERE id = 4 AND system_user_id = 2 AND system_group_id = 3;
DELETE FROM system_user_unit  WHERE id = 3 AND system_user_id = 2 AND system_unit_id = 1;
DELETE FROM system_user_program      WHERE system_user_id = 2
   AND EXISTS (SELECT 1 FROM system_users s WHERE s.id = 2 AND s.login = 'f7b.semacesso@example.invalid');
DELETE FROM system_user_role         WHERE system_user_id = 2
   AND EXISTS (SELECT 1 FROM system_users s WHERE s.id = 2 AND s.login = 'f7b.semacesso@example.invalid');
DELETE FROM system_user_old_password WHERE system_user_id = 2
   AND EXISTS (SELECT 1 FROM system_users s WHERE s.id = 2 AND s.login = 'f7b.semacesso@example.invalid');
DELETE FROM tenant_user              WHERE system_user_id = 2
   AND EXISTS (SELECT 1 FROM system_users s WHERE s.id = 2 AND s.login = 'f7b.semacesso@example.invalid');
DELETE FROM system_users WHERE id = 2 AND login = 'f7b.semacesso@example.invalid'
   AND email = 'f7b.semacesso@example.invalid' AND name LIKE 'F7B teste%';

-- =============================================================================================
-- 4. Conferência (esperado: 0 nas linhas do gate; totais iguais ao pré-gate da Onda 8)
-- =============================================================================================
SELECT COUNT(*) AS document_gate_depois   FROM generated_document WHERE id = 10;
SELECT COUNT(*) AS object_gate_depois     FROM stored_object      WHERE id = 2141;
SELECT COUNT(*) AS template_f7b_depois    FROM document_template  WHERE name LIKE 'F7B teste%';
SELECT COUNT(*) AS patient_f7b_depois     FROM patient            WHERE id = 13322 OR name LIKE 'F7B teste%';
SELECT COUNT(*) AS tutor_f7b_depois       FROM tutor              WHERE full_name LIKE 'F7B teste%' OR email LIKE 'f7b%@example.invalid';
SELECT COUNT(*) AS user_f7b_depois        FROM system_users       WHERE id = 2 OR login LIKE 'f7b%';
SELECT COUNT(*) AS user_group_u2_depois   FROM system_user_group  WHERE system_user_id = 2;
SELECT COUNT(*) AS user_unit_u2_depois    FROM system_user_unit   WHERE system_user_id = 2;
SELECT COUNT(*) AS tutor_total_depois              FROM tutor;               -- 7
SELECT COUNT(*) AS patient_total_depois            FROM patient;             -- 7
SELECT COUNT(*) AS generated_document_total_depois FROM generated_document;  -- 0
SELECT COUNT(*) AS document_template_total_depois  FROM document_template;   -- 0
SELECT COUNT(*) AS stored_object_total_depois      FROM stored_object;       -- 9
SELECT COUNT(*) AS system_users_total_depois       FROM system_users;        -- 1
SELECT COUNT(*) AS system_user_group_total_depois  FROM system_user_group;   -- 3
SELECT COUNT(*) AS system_user_unit_total_depois   FROM system_user_unit;    -- 2
SELECT MAX(id) AS tutor_max_depois         FROM tutor;          -- 13876
SELECT MAX(id) AS patient_max_depois       FROM patient;        -- 9180
SELECT MAX(id) AS stored_object_max_depois FROM stored_object;  -- 2131

-- Confira tudo acima. Se bater, descomente e rode o COMMIT; senão, ROLLBACK.
-- Depois do COMMIT, rode os comandos rm do cabeçalho (com confirmação).
-- ROLLBACK;
-- COMMIT;

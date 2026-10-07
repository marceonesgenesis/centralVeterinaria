SET NAMES utf8mb4;

-- T-04 — Fase 7B (Documentos): 4 programas e 16 concessões
-- (4 a cada um dos grupos 1 'Template - Admin', 2 'Template - Users', 'Clínico – Internação' e
-- 'Clínico – Cirurgia'; o grupo 3 'Application - Programs' não recebe nada — decisão da 7A mantida na 7B).
-- Grupos 1 e 2 por id (fixos do Adianti); grupos da 6A e da 6B pelo nome exato (í e travessão U+2013), nunca id literal.
-- Sem id literal: cada INSERT deriva o id de COALESCE(MAX(id),0)+1 no próprio comando, com
-- WHERE NOT EXISTS (idempotente). Compatível com MySQL 5.7 (sem função de janela, CTE ou variável).
-- Última referência (7A): system_program 133, system_group_program 173.
-- Aplicar só com aprovação SQL explícita, com o cliente em utf8mb4:
--   docker compose exec -T mysql sh -c 'mysql --default-character-set=utf8mb4 -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" centralvet' < sql/T-04-programs.sql
-- Rollback preparado: sql/T-04-programs.rollback.sql. Conferência: sql/T-04-programs.verify.sql.

START TRANSACTION;

SELECT COUNT(*) AS programs_before, MAX(id) AS max_program_id FROM system_program;
SELECT COUNT(*) AS group_programs_before, MAX(id) AS max_group_program_id FROM system_group_program;
SELECT id, name FROM system_group WHERE name IN ('Clínico – Internação', 'Clínico – Cirurgia') ORDER BY id;

-- 1. Programas

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Document List', 'DocumentList'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='DocumentList');

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Document Request Form', 'DocumentRequestForm'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='DocumentRequestForm');

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Document Template List', 'DocumentTemplateList'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='DocumentTemplateList');

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Document Template Form', 'DocumentTemplateForm'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='DocumentTemplateForm');

-- 2a. Concessões ao grupo 1 (Template - Admin)

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='DocumentList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='DocumentRequestForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='DocumentTemplateList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='DocumentTemplateForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

-- 2b. Concessões ao grupo 2 (Template - Users)

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 2, p.id
FROM system_program p WHERE p.controller='DocumentList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=2 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 2, p.id
FROM system_program p WHERE p.controller='DocumentRequestForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=2 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 2, p.id
FROM system_program p WHERE p.controller='DocumentTemplateList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=2 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 2, p.id
FROM system_program p WHERE p.controller='DocumentTemplateForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=2 AND existing_link.system_program_id=p.id);

-- 2c. Concessões ao grupo 'Clínico – Internação' (id lido por nome, nunca literal)

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Internação'
WHERE p.controller='DocumentList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Internação'
WHERE p.controller='DocumentRequestForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Internação'
WHERE p.controller='DocumentTemplateList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Internação'
WHERE p.controller='DocumentTemplateForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

-- 2d. Concessões ao grupo 'Clínico – Cirurgia' (id lido por nome, nunca literal)

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Cirurgia'
WHERE p.controller='DocumentList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Cirurgia'
WHERE p.controller='DocumentRequestForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Cirurgia'
WHERE p.controller='DocumentTemplateList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Cirurgia'
WHERE p.controller='DocumentTemplateForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

-- 3. Conferência (esperado: 4 programas; 4 concessões em cada um dos 4 grupos)
SELECT id, name, controller FROM system_program WHERE controller IN ('DocumentList', 'DocumentRequestForm', 'DocumentTemplateList', 'DocumentTemplateForm') ORDER BY id;
SELECT gp.system_group_id, COUNT(*) AS grants
FROM system_group_program gp JOIN system_program p ON p.id = gp.system_program_id
WHERE p.controller IN ('DocumentList', 'DocumentRequestForm', 'DocumentTemplateList', 'DocumentTemplateForm')
GROUP BY gp.system_group_id ORDER BY gp.system_group_id;
SELECT COUNT(*) AS programs_after FROM system_program;
SELECT COUNT(*) AS group_programs_after FROM system_group_program;

COMMIT;

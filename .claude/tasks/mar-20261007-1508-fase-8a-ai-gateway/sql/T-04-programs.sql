SET NAMES utf8mb4;

-- T-04 — Fase 8A (AI Gateway): 2 programas e 2 concessões, só ao grupo 1 'Template - Admin' (por id).
-- As ações de IA nas telas clínicas autorizam pelos programas já existentes (EncounterView, ExamResultForm): sem DML.
-- Sem id literal: cada INSERT deriva o id de COALESCE(MAX(id),0)+1 no próprio comando, com
-- WHERE NOT EXISTS (idempotente). Compatível com MySQL 5.7 (sem função de janela, CTE ou variável).
-- Aplicar só com aprovação SQL explícita, com o cliente em utf8mb4:
--   docker compose exec -T mysql sh -c 'mysql --default-character-set=utf8mb4 -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" centralvet' < sql/T-04-programs.sql
-- Rollback preparado: sql/T-04-programs.rollback.sql. Conferência: sql/T-04-programs.verify.sql.

START TRANSACTION;

SELECT COUNT(*) AS programs_before, MAX(id) AS max_program_id FROM system_program;
SELECT COUNT(*) AS group_programs_before, MAX(id) AS max_group_program_id FROM system_group_program;

-- 1. Programas

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - AI Settings', 'AiSettingsForm'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='AiSettingsForm');

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - AI Interaction List', 'AiInteractionList'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='AiInteractionList');

-- 2. Concessões ao grupo 1 (Template - Admin)

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='AiSettingsForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='AiInteractionList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

-- 3. Conferência (esperado: 2 programas, 2 concessões no grupo 1; contagens = antes + 2 / + 2)

SELECT p.id, p.name, p.controller FROM system_program p WHERE p.controller IN ('AiSettingsForm', 'AiInteractionList') ORDER BY p.id;
SELECT gp.system_group_id, COUNT(*) AS grants
FROM system_group_program gp JOIN system_program p ON p.id = gp.system_program_id
WHERE p.controller IN ('AiSettingsForm', 'AiInteractionList')
GROUP BY gp.system_group_id ORDER BY gp.system_group_id;
SELECT COUNT(*) AS programs_after FROM system_program;
SELECT COUNT(*) AS group_programs_after FROM system_group_program;

COMMIT;

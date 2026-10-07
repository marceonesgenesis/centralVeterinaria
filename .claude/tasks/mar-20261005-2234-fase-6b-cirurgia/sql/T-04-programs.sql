SET NAMES utf8mb4;

-- T-04 — Fase 6B (Cirurgia): grupo 'Clínico – Cirurgia', 9 programas e 18 concessões
-- (9 ao grupo 1 'Template - Admin' e 9 ao grupo novo; grupos 2, 3 e o grupo 'Internação' da 6A não recebem nada).
-- Estado lido em 2026-10-05 (só SELECT, banco centralvet):
--   system_group:         COUNT(*)=4,   MAX(id)=4   (id int NOT NULL, sem AUTO_INCREMENT)
--                         1 Template - Admin, 2 Template - Users, 3 Application - Programs, 4 grupo clínico da 6A
--   system_program:       COUNT(*)=117, MAX(id)=117
--   system_group_program: COUNT(*)=127, MAX(id)=127
-- Sem id literal: cada INSERT deriva o id de COALESCE(MAX(id),0)+1 no próprio comando, com
-- WHERE NOT EXISTS (idempotente). Compatível com MySQL 5.7 (sem função de janela, CTE ou variável).
-- Aplicar só com aprovação SQL explícita, com o cliente em utf8mb4:
--   docker compose exec -T mysql sh -c 'mysql --default-character-set=utf8mb4 -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" centralvet' < sql/T-04-programs.sql
-- Rollback preparado: sql/T-04-programs.rollback.sql. Conferência: sql/T-04-programs.verify.sql.

START TRANSACTION;

SELECT COUNT(*) AS groups_before, MAX(id) AS max_group_id FROM system_group;
SELECT COUNT(*) AS programs_before, MAX(id) AS max_program_id FROM system_program;
SELECT COUNT(*) AS group_programs_before, MAX(id) AS max_group_program_id FROM system_group_program;

-- 1. Grupo novo
INSERT INTO system_group (id, name)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group current_groups), 'Clínico – Cirurgia'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_group existing_group WHERE existing_group.name='Clínico – Cirurgia');

-- 2. Programas
INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Surgery Room List', 'SurgeryRoomList'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='SurgeryRoomList');

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Surgery Room Form', 'SurgeryRoomForm'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='SurgeryRoomForm');

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Surgery List', 'SurgeryList'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='SurgeryList');

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Surgery Schedule Form', 'SurgeryScheduleForm'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='SurgeryScheduleForm');

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Surgery View', 'SurgeryView'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='SurgeryView');

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Surgery Consent Form', 'SurgeryConsentForm'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='SurgeryConsentForm');

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Surgery Event Form', 'SurgeryEventForm'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='SurgeryEventForm');

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Surgery Checklist Form', 'SurgeryChecklistForm'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='SurgeryChecklistForm');

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Surgery Material Form', 'SurgeryMaterialForm'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='SurgeryMaterialForm');

-- 3a. Concessões ao grupo 1 (Template - Admin)
INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='SurgeryRoomList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='SurgeryRoomForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='SurgeryList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='SurgeryScheduleForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='SurgeryView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='SurgeryConsentForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='SurgeryEventForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='SurgeryChecklistForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='SurgeryMaterialForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

-- 3b. Concessões ao grupo 'Clínico – Cirurgia' (id lido por nome, nunca literal)
INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Cirurgia'
WHERE p.controller='SurgeryRoomList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Cirurgia'
WHERE p.controller='SurgeryRoomForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Cirurgia'
WHERE p.controller='SurgeryList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Cirurgia'
WHERE p.controller='SurgeryScheduleForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Cirurgia'
WHERE p.controller='SurgeryView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Cirurgia'
WHERE p.controller='SurgeryConsentForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Cirurgia'
WHERE p.controller='SurgeryEventForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Cirurgia'
WHERE p.controller='SurgeryChecklistForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Cirurgia'
WHERE p.controller='SurgeryMaterialForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

-- 4. Conferência
SELECT id, name, CHAR_LENGTH(name) AS chars, LENGTH(name) AS bytes FROM system_group WHERE name='Clínico – Cirurgia';
SELECT id, name, controller FROM system_program WHERE controller IN ('SurgeryRoomList', 'SurgeryRoomForm', 'SurgeryList', 'SurgeryScheduleForm', 'SurgeryView', 'SurgeryConsentForm', 'SurgeryEventForm', 'SurgeryChecklistForm', 'SurgeryMaterialForm') ORDER BY id;
SELECT gp.system_group_id, COUNT(*) AS grants
FROM system_group_program gp JOIN system_program p ON p.id = gp.system_program_id
WHERE p.controller IN ('SurgeryRoomList', 'SurgeryRoomForm', 'SurgeryList', 'SurgeryScheduleForm', 'SurgeryView', 'SurgeryConsentForm', 'SurgeryEventForm', 'SurgeryChecklistForm', 'SurgeryMaterialForm')
GROUP BY gp.system_group_id ORDER BY gp.system_group_id;
SELECT COUNT(*) AS groups_after FROM system_group;
SELECT COUNT(*) AS programs_after FROM system_program;
SELECT COUNT(*) AS group_programs_after FROM system_group_program;

COMMIT;

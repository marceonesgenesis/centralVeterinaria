SET NAMES utf8mb4;

-- T-05 — Fase 6A (Internação): grupo 'Clínico – Internação', 8 programas e 16 concessões
-- (8 ao grupo 1 'Template - Admin' e 8 ao grupo novo; grupos 2 e 3 não recebem nada).
-- Estado lido em 2026-10-05 (só SELECT):
--   system_group:         COUNT(*)=3,   MAX(id)=3   (id int NOT NULL, sem AUTO_INCREMENT)
--                         1 Template - Admin, 2 Template - Users, 3 Application - Programs
--   system_program:       COUNT(*)=109, MAX(id)=109
--   system_group_program: COUNT(*)=111, MAX(id)=111
-- Sem id literal: cada INSERT deriva o id de COALESCE(MAX(id),0)+1 no próprio comando, com
-- WHERE NOT EXISTS (idempotente). Compatível com MySQL 5.7 (sem função de janela, CTE ou variável).
-- Aplicar só com aprovação SQL explícita, com o cliente em utf8mb4:
--   docker compose exec -T mysql sh -c 'mysql --default-character-set=utf8mb4 -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" centralvet' < sql/T-05-programs.sql
-- Rollback preparado: sql/T-05-programs.rollback.sql. Conferência: sql/T-05-programs.verify.sql.

START TRANSACTION;

SELECT COUNT(*) AS groups_before, MAX(id) AS max_group_id FROM system_group;
SELECT COUNT(*) AS programs_before, MAX(id) AS max_program_id FROM system_program;
SELECT COUNT(*) AS group_programs_before, MAX(id) AS max_group_program_id FROM system_group_program;

-- 1. Grupo novo
INSERT INTO system_group (id, name)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group current_groups), 'Clínico – Internação'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_group existing_group WHERE existing_group.name='Clínico – Internação');

-- 2. Programas
INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Bed List', 'BedList'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='BedList');

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Bed Form', 'BedForm'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='BedForm');

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Hospitalization Board', 'HospitalizationBoard'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='HospitalizationBoard');

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Hospitalization Admission Form', 'HospitalizationAdmissionForm'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='HospitalizationAdmissionForm');

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Hospitalization View', 'HospitalizationView'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='HospitalizationView');

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Hospitalization Order Form', 'HospitalizationOrderForm'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='HospitalizationOrderForm');

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Hospitalization Administration Form', 'HospitalizationAdministrationForm'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='HospitalizationAdministrationForm');

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Hospitalization Event Form', 'HospitalizationEventForm'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='HospitalizationEventForm');

-- 3a. Concessões ao grupo 1 (Template - Admin)
INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='BedList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='BedForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='HospitalizationBoard'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='HospitalizationAdmissionForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='HospitalizationView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='HospitalizationOrderForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='HospitalizationAdministrationForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='HospitalizationEventForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

-- 3b. Concessões ao grupo 'Clínico – Internação' (id lido por nome, nunca literal)
INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Internação'
WHERE p.controller='BedList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Internação'
WHERE p.controller='BedForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Internação'
WHERE p.controller='HospitalizationBoard'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Internação'
WHERE p.controller='HospitalizationAdmissionForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Internação'
WHERE p.controller='HospitalizationView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Internação'
WHERE p.controller='HospitalizationOrderForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Internação'
WHERE p.controller='HospitalizationAdministrationForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Internação'
WHERE p.controller='HospitalizationEventForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

-- 4. Conferência
SELECT id, name, CHAR_LENGTH(name) AS chars, LENGTH(name) AS bytes FROM system_group WHERE name='Clínico – Internação';
SELECT id, name, controller FROM system_program WHERE controller IN ('BedList', 'BedForm', 'HospitalizationBoard', 'HospitalizationAdmissionForm', 'HospitalizationView', 'HospitalizationOrderForm', 'HospitalizationAdministrationForm', 'HospitalizationEventForm') ORDER BY id;
SELECT gp.system_group_id, COUNT(*) AS grants
FROM system_group_program gp JOIN system_program p ON p.id = gp.system_program_id
WHERE p.controller IN ('BedList', 'BedForm', 'HospitalizationBoard', 'HospitalizationAdmissionForm', 'HospitalizationView', 'HospitalizationOrderForm', 'HospitalizationAdministrationForm', 'HospitalizationEventForm')
GROUP BY gp.system_group_id ORDER BY gp.system_group_id;
SELECT COUNT(*) AS groups_after FROM system_group;
SELECT COUNT(*) AS programs_after FROM system_program;
SELECT COUNT(*) AS group_programs_after FROM system_group_program;

COMMIT;

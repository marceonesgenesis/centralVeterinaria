SET NAMES utf8mb4;

-- T-04 — Rollback PREPARADO (nunca executar sem nova aprovação SQL explícita).
-- O caminho preferido continua sendo restaurar o backup feito no bloqueio entre as Ondas 1 e 2.
-- Todo DELETE tem WHERE por nome de controller ou de grupo. O COMMIT está comentado:
-- confira os SELECTs e só então rode COMMIT (ou ROLLBACK) manualmente.

START TRANSACTION;

SELECT COUNT(*) AS system_group_before FROM system_group;
SELECT COUNT(*) AS system_program_before FROM system_program;
SELECT COUNT(*) AS system_group_program_before FROM system_group_program;
-- Concessões dos 9 programas fora dos grupos 1 e 'Clínico – Cirurgia' (esperado: 0; se houver, pare e avalie)
SELECT gp.id, gp.system_group_id, p.controller
FROM system_group_program gp JOIN system_program p ON p.id = gp.system_program_id
WHERE p.controller IN ('SurgeryRoomList', 'SurgeryRoomForm', 'SurgeryList', 'SurgeryScheduleForm', 'SurgeryView', 'SurgeryConsentForm', 'SurgeryEventForm', 'SurgeryChecklistForm', 'SurgeryMaterialForm')
AND gp.system_group_id <> 1
AND gp.system_group_id NOT IN (SELECT g.id FROM system_group g WHERE g.name='Clínico – Cirurgia');

-- 1. Concessões dos 9 programas nos grupos 1 e 'Clínico – Cirurgia'
DELETE FROM system_group_program
WHERE system_program_id IN (SELECT p.id FROM system_program p WHERE p.controller IN ('SurgeryRoomList', 'SurgeryRoomForm', 'SurgeryList', 'SurgeryScheduleForm', 'SurgeryView', 'SurgeryConsentForm', 'SurgeryEventForm', 'SurgeryChecklistForm', 'SurgeryMaterialForm'))
AND (system_group_id = 1 OR system_group_id IN (SELECT g.id FROM system_group g WHERE g.name='Clínico – Cirurgia'));

-- 2. Usuários do grupo novo
DELETE FROM system_user_group
WHERE system_group_id IN (SELECT g.id FROM system_group g WHERE g.name='Clínico – Cirurgia');

-- 3. Concessões restantes do grupo novo
DELETE FROM system_group_program
WHERE system_group_id IN (SELECT g.id FROM system_group g WHERE g.name='Clínico – Cirurgia');

-- 4. Concessões diretas a usuários e restrições de método dos 9 programas
DELETE FROM system_user_program
WHERE system_program_id IN (SELECT p.id FROM system_program p WHERE p.controller IN ('SurgeryRoomList', 'SurgeryRoomForm', 'SurgeryList', 'SurgeryScheduleForm', 'SurgeryView', 'SurgeryConsentForm', 'SurgeryEventForm', 'SurgeryChecklistForm', 'SurgeryMaterialForm'));

DELETE FROM system_program_method_role
WHERE system_program_id IN (SELECT p.id FROM system_program p WHERE p.controller IN ('SurgeryRoomList', 'SurgeryRoomForm', 'SurgeryList', 'SurgeryScheduleForm', 'SurgeryView', 'SurgeryConsentForm', 'SurgeryEventForm', 'SurgeryChecklistForm', 'SurgeryMaterialForm'));

-- 5. Grupo novo
DELETE FROM system_group WHERE name='Clínico – Cirurgia';

-- 6. Os 9 programas
DELETE FROM system_program WHERE controller IN ('SurgeryRoomList', 'SurgeryRoomForm', 'SurgeryList', 'SurgeryScheduleForm', 'SurgeryView', 'SurgeryConsentForm', 'SurgeryEventForm', 'SurgeryChecklistForm', 'SurgeryMaterialForm');

-- 7. Conferência (esperado: 0, 0, 0 e contagens = antes - 1 / - 9 / - 18)
SELECT COUNT(*) AS group_rows FROM system_group WHERE name='Clínico – Cirurgia';
SELECT COUNT(*) AS programs FROM system_program WHERE controller IN ('SurgeryRoomList', 'SurgeryRoomForm', 'SurgeryList', 'SurgeryScheduleForm', 'SurgeryView', 'SurgeryConsentForm', 'SurgeryEventForm', 'SurgeryChecklistForm', 'SurgeryMaterialForm');
SELECT COUNT(*) AS grants
FROM system_group_program gp JOIN system_program p ON p.id = gp.system_program_id
WHERE p.controller IN ('SurgeryRoomList', 'SurgeryRoomForm', 'SurgeryList', 'SurgeryScheduleForm', 'SurgeryView', 'SurgeryConsentForm', 'SurgeryEventForm', 'SurgeryChecklistForm', 'SurgeryMaterialForm');
SELECT COUNT(*) AS system_group_after FROM system_group;
SELECT COUNT(*) AS system_program_after FROM system_program;
SELECT COUNT(*) AS system_group_program_after FROM system_group_program;

-- COMMIT;

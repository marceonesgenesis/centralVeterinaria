SET NAMES utf8mb4;

-- T-04 — Conferência (só SELECT) do grupo 'Clínico – Cirurgia', dos 9 programas e das concessões.
-- Esperado depois da aplicação: grupo 1 vez com chars=18 e bytes=21, 9 programas,
-- grupo 1 = 9, 'Clínico – Cirurgia' = 9, grupo 4 (Internação, 6A) = 0, grupos 2 e 3 = 0,
-- contagens = antes + 1 / + 9 / + 18 (antes em 2026-10-05: system_group 4, system_program 117, system_group_program 127).

SELECT COUNT(*) AS group_rows, MIN(CHAR_LENGTH(name)) AS chars, MIN(LENGTH(name)) AS bytes
FROM system_group WHERE name='Clínico – Cirurgia';

SELECT COUNT(*) AS programs FROM system_program WHERE controller IN ('SurgeryRoomList', 'SurgeryRoomForm', 'SurgeryList', 'SurgeryScheduleForm', 'SurgeryView', 'SurgeryConsentForm', 'SurgeryEventForm', 'SurgeryChecklistForm', 'SurgeryMaterialForm');

-- Concessões por grupo (1, 2, 3, o grupo clínico da 6A e o grupo novo)
SELECT g.id AS group_id, g.name AS group_name,
       (SELECT COUNT(*) FROM system_group_program gp JOIN system_program p ON p.id = gp.system_program_id
        WHERE gp.system_group_id = g.id AND p.controller IN ('SurgeryRoomList', 'SurgeryRoomForm', 'SurgeryList', 'SurgeryScheduleForm', 'SurgeryView', 'SurgeryConsentForm', 'SurgeryEventForm', 'SurgeryChecklistForm', 'SurgeryMaterialForm')) AS grants
FROM system_group g
WHERE g.id IN (1, 2, 3) OR g.name IN ('Clínico – Internação', 'Clínico – Cirurgia')
ORDER BY g.id;

-- Concessões fora do grupo 1 e do grupo novo (esperado: 0 linhas)
SELECT gp.id, gp.system_group_id, p.controller
FROM system_group_program gp JOIN system_program p ON p.id = gp.system_program_id
WHERE p.controller IN ('SurgeryRoomList', 'SurgeryRoomForm', 'SurgeryList', 'SurgeryScheduleForm', 'SurgeryView', 'SurgeryConsentForm', 'SurgeryEventForm', 'SurgeryChecklistForm', 'SurgeryMaterialForm')
AND gp.system_group_id <> 1
AND gp.system_group_id NOT IN (SELECT g.id FROM system_group g WHERE g.name='Clínico – Cirurgia');

SELECT p.controller, p.name, COUNT(gp.id) AS grants
FROM system_program p LEFT JOIN system_group_program gp ON gp.system_program_id = p.id
WHERE p.controller IN ('SurgeryRoomList', 'SurgeryRoomForm', 'SurgeryList', 'SurgeryScheduleForm', 'SurgeryView', 'SurgeryConsentForm', 'SurgeryEventForm', 'SurgeryChecklistForm', 'SurgeryMaterialForm')
GROUP BY p.id, p.controller, p.name ORDER BY p.id;

SELECT COUNT(*) AS system_program_total FROM system_program;
SELECT COUNT(*) AS system_group_total FROM system_group;
SELECT COUNT(*) AS system_group_program_total FROM system_group_program;

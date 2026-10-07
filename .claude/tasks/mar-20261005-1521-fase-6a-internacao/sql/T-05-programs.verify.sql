SET NAMES utf8mb4;

-- T-05 — Conferência (só SELECT) do grupo 'Clínico – Internação', dos 8 programas e das concessões.
-- Esperado depois da aplicação: grupo 1 vez com chars=20 e bytes=25, 8 programas,
-- grupo 1 = 8, 'Clínico – Internação' = 8, grupos 2 e 3 = 0, contagens = antes + 1 / + 8 / + 16
-- (antes em 2026-10-05: system_group 3, system_program 109, system_group_program 111).

SELECT COUNT(*) AS group_rows, MIN(CHAR_LENGTH(name)) AS chars, MIN(LENGTH(name)) AS bytes
FROM system_group WHERE name='Clínico – Internação';

SELECT COUNT(*) AS programs FROM system_program WHERE controller IN ('BedList', 'BedForm', 'HospitalizationBoard', 'HospitalizationAdmissionForm', 'HospitalizationView', 'HospitalizationOrderForm', 'HospitalizationAdministrationForm', 'HospitalizationEventForm');

SELECT g.id AS group_id, g.name AS group_name,
       (SELECT COUNT(*) FROM system_group_program gp JOIN system_program p ON p.id = gp.system_program_id
        WHERE gp.system_group_id = g.id AND p.controller IN ('BedList', 'BedForm', 'HospitalizationBoard', 'HospitalizationAdmissionForm', 'HospitalizationView', 'HospitalizationOrderForm', 'HospitalizationAdministrationForm', 'HospitalizationEventForm')) AS grants
FROM system_group g
WHERE g.id IN (1, 2, 3) OR g.name='Clínico – Internação'
ORDER BY g.id;

SELECT p.controller, p.name, COUNT(gp.id) AS grants
FROM system_program p LEFT JOIN system_group_program gp ON gp.system_program_id = p.id
WHERE p.controller IN ('BedList', 'BedForm', 'HospitalizationBoard', 'HospitalizationAdmissionForm', 'HospitalizationView', 'HospitalizationOrderForm', 'HospitalizationAdministrationForm', 'HospitalizationEventForm')
GROUP BY p.id, p.controller, p.name ORDER BY p.id;

SELECT COUNT(*) AS system_program_total FROM system_program;
SELECT COUNT(*) AS system_group_total FROM system_group;
SELECT COUNT(*) AS system_group_program_total FROM system_group_program;

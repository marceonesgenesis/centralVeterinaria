SET NAMES utf8mb4;

-- T-04 — Conferência (só SELECT) dos 4 programas da Fase 7B e das concessões.
-- Esperado depois da aplicação: 4 programas; grupo 1 = 4, grupo 2 = 4, 'Clínico – Internação' = 4,
-- 'Clínico – Cirurgia' = 4, grupo 3 = 0; 0 concessões fora desses 4 grupos;
-- contagens = antes + 4 (system_program) / + 16 (system_group_program); system_group inalterado.

SELECT COUNT(*) AS programs FROM system_program WHERE controller IN ('DocumentList', 'DocumentRequestForm', 'DocumentTemplateList', 'DocumentTemplateForm');

SELECT p.controller, p.name FROM system_program p WHERE p.controller IN ('DocumentList', 'DocumentRequestForm', 'DocumentTemplateList', 'DocumentTemplateForm') ORDER BY p.id;

-- Concessões por grupo (uma linha por grupo, com id e nome)
SELECT g.id AS group_id, g.name AS group_name,
       (SELECT COUNT(*) FROM system_group_program gp JOIN system_program p ON p.id = gp.system_program_id
        WHERE gp.system_group_id = g.id AND p.controller IN ('DocumentList', 'DocumentRequestForm', 'DocumentTemplateList', 'DocumentTemplateForm')) AS grants
FROM system_group g
WHERE g.id IN (1, 2, 3) OR g.name IN ('Clínico – Internação', 'Clínico – Cirurgia')
ORDER BY g.id;

-- Concessões fora dos grupos 1, 2, 'Clínico – Internação' e 'Clínico – Cirurgia' (esperado: 0 linhas)
SELECT gp.id, gp.system_group_id, p.controller
FROM system_group_program gp JOIN system_program p ON p.id = gp.system_program_id
WHERE p.controller IN ('DocumentList', 'DocumentRequestForm', 'DocumentTemplateList', 'DocumentTemplateForm')
AND gp.system_group_id NOT IN (1, 2)
AND gp.system_group_id NOT IN (SELECT g.id FROM system_group g WHERE g.name IN ('Clínico – Internação', 'Clínico – Cirurgia'));

SELECT p.controller, COUNT(gp.id) AS grants
FROM system_program p LEFT JOIN system_group_program gp ON gp.system_program_id = p.id
WHERE p.controller IN ('DocumentList', 'DocumentRequestForm', 'DocumentTemplateList', 'DocumentTemplateForm')
GROUP BY p.id, p.controller ORDER BY p.id;

SELECT COUNT(*) AS system_program_total FROM system_program;
SELECT COUNT(*) AS system_group_total FROM system_group;
SELECT COUNT(*) AS system_group_program_total FROM system_group_program;

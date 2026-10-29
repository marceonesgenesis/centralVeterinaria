SET NAMES utf8mb4;

-- T-04 — Conferência (só SELECT) dos 2 programas da Fase 8A e das concessões.
-- Esperado depois da aplicação: 2 programas; grupo 1 = 2; demais grupos = 0 (0 linhas na consulta de concessões
-- fora do grupo 1); contagens = antes + 2 (system_program) / + 2 (system_group_program); system_group inalterado.

SELECT COUNT(*) AS programs FROM system_program WHERE controller IN ('AiSettingsForm', 'AiInteractionList');

SELECT p.id, p.controller, p.name FROM system_program p WHERE p.controller IN ('AiSettingsForm', 'AiInteractionList') ORDER BY p.id;

-- Concessões por grupo (uma linha por grupo existente; esperado: grupo 1 = 2, demais = 0)
SELECT g.id AS group_id, g.name AS group_name,
       (SELECT COUNT(*) FROM system_group_program gp JOIN system_program p ON p.id = gp.system_program_id
        WHERE gp.system_group_id = g.id AND p.controller IN ('AiSettingsForm', 'AiInteractionList')) AS grants
FROM system_group g
ORDER BY g.id;

-- Concessões fora do grupo 1 (esperado: 0 linhas)
SELECT gp.id, gp.system_group_id, p.controller
FROM system_group_program gp JOIN system_program p ON p.id = gp.system_program_id
WHERE p.controller IN ('AiSettingsForm', 'AiInteractionList')
AND gp.system_group_id <> 1;

SELECT p.controller, COUNT(gp.id) AS grants
FROM system_program p LEFT JOIN system_group_program gp ON gp.system_program_id = p.id
WHERE p.controller IN ('AiSettingsForm', 'AiInteractionList')
GROUP BY p.id, p.controller ORDER BY p.id;

SELECT COUNT(*) AS system_program_total FROM system_program;
SELECT COUNT(*) AS system_group_total FROM system_group;
SELECT COUNT(*) AS system_group_program_total FROM system_group_program;

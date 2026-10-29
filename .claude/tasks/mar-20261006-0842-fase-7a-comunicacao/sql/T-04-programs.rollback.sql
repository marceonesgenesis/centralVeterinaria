SET NAMES utf8mb4;

-- T-04 — Rollback PREPARADO (nunca executar sem nova aprovação SQL explícita).
-- O caminho preferido continua sendo restaurar o backup feito no bloqueio entre as Ondas 1 e 2.
-- Todo DELETE tem WHERE por nome de controller. Os grupos não são tocados.
-- O COMMIT está comentado: confira os SELECTs e só então rode COMMIT (ou ROLLBACK) manualmente.

START TRANSACTION;

SELECT COUNT(*) AS system_program_before FROM system_program;
SELECT COUNT(*) AS system_group_program_before FROM system_group_program;
-- Concessões dos 7 programas por grupo (esperado: grupos 1, 2, 'Clínico – Internação' e 'Clínico – Cirurgia' com 7 cada)
SELECT gp.system_group_id, g.name AS group_name, COUNT(*) AS grants
FROM system_group_program gp
JOIN system_program p ON p.id = gp.system_program_id
LEFT JOIN system_group g ON g.id = gp.system_group_id
WHERE p.controller IN ('MessageTemplateList', 'MessageTemplateForm', 'CommunicationMessageList', 'CommunicationMessageView', 'CommunicationComposeForm', 'TutorCommunicationForm', 'PendingCenter')
GROUP BY gp.system_group_id, g.name ORDER BY gp.system_group_id;

-- 1. Concessões dos 7 programas (todos os grupos)
DELETE FROM system_group_program
WHERE system_program_id IN (SELECT p.id FROM system_program p WHERE p.controller IN ('MessageTemplateList', 'MessageTemplateForm', 'CommunicationMessageList', 'CommunicationMessageView', 'CommunicationComposeForm', 'TutorCommunicationForm', 'PendingCenter'));

-- 2. Concessões diretas a usuários e restrições de método dos 7 programas
DELETE FROM system_user_program
WHERE system_program_id IN (SELECT p.id FROM system_program p WHERE p.controller IN ('MessageTemplateList', 'MessageTemplateForm', 'CommunicationMessageList', 'CommunicationMessageView', 'CommunicationComposeForm', 'TutorCommunicationForm', 'PendingCenter'));

DELETE FROM system_program_method_role
WHERE system_program_id IN (SELECT p.id FROM system_program p WHERE p.controller IN ('MessageTemplateList', 'MessageTemplateForm', 'CommunicationMessageList', 'CommunicationMessageView', 'CommunicationComposeForm', 'TutorCommunicationForm', 'PendingCenter'));

-- 3. Os 7 programas
DELETE FROM system_program WHERE controller IN ('MessageTemplateList', 'MessageTemplateForm', 'CommunicationMessageList', 'CommunicationMessageView', 'CommunicationComposeForm', 'TutorCommunicationForm', 'PendingCenter');

-- 4. Conferência (esperado: 0, 0 e contagens = antes - 7 / - 28)
SELECT COUNT(*) AS programs FROM system_program WHERE controller IN ('MessageTemplateList', 'MessageTemplateForm', 'CommunicationMessageList', 'CommunicationMessageView', 'CommunicationComposeForm', 'TutorCommunicationForm', 'PendingCenter');
SELECT COUNT(*) AS grants
FROM system_group_program gp JOIN system_program p ON p.id = gp.system_program_id
WHERE p.controller IN ('MessageTemplateList', 'MessageTemplateForm', 'CommunicationMessageList', 'CommunicationMessageView', 'CommunicationComposeForm', 'TutorCommunicationForm', 'PendingCenter');
SELECT COUNT(*) AS system_program_after FROM system_program;
SELECT COUNT(*) AS system_group_program_after FROM system_group_program;

-- COMMIT;

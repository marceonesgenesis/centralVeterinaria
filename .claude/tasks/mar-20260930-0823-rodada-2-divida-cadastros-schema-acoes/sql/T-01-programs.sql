-- T-01 — Registro de BankAccountList, BankAccountForm e ServiceImportForm, e CvShellController
-- para todos os grupos (banco permission = banco da aplicação, centralvet)
-- Estado lido em 2026-09-30 (só SELECT):
--   system_program:       MAX(id)=105, COUNT(*)=105 (105 = CvShellController)
--   system_group_program: MAX(id)=105, COUNT(*)=105; colunas id (int, sem AUTO_INCREMENT),
--                         system_group_id, system_program_id
--   system_group:         1 Template - Admin, 2 Template - Users, 3 Application - Programs
--   CvShellController (105) hoje só no grupo 1
-- Execução exige aprovação explícita do usuário. Só INSERT e SELECT.
-- Se MAX(id) mudar antes da aplicação, pare e reajuste os ids fixos abaixo.

START TRANSACTION;

SELECT MAX(id) AS max_program_id FROM system_program;
SELECT MAX(id) AS max_group_program_id FROM system_group_program;

INSERT INTO system_program (id, name, controller) VALUES (106, 'Contas bancárias', 'BankAccountList');
INSERT INTO system_program (id, name, controller) VALUES (107, 'Conta bancária', 'BankAccountForm');
INSERT INTO system_program (id, name, controller) VALUES (108, 'Importar serviços', 'ServiceImportForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id) VALUES (106, 1, 106);
INSERT INTO system_group_program (id, system_group_id, system_program_id) VALUES (107, 1, 107);
INSERT INTO system_group_program (id, system_group_id, system_program_id) VALUES (108, 1, 108);

-- CvShellController (105) para cada grupo que ainda não o tem (hoje: grupos 2 e 3 → ids 109, 110)
INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT base.max_id + ROW_NUMBER() OVER (ORDER BY g.id), g.id, 105
FROM system_group g
CROSS JOIN (SELECT MAX(id) AS max_id FROM system_group_program) base
WHERE NOT EXISTS (
    SELECT 1 FROM system_group_program gp
    WHERE gp.system_group_id = g.id AND gp.system_program_id = 105
);

SELECT id, name, controller FROM system_program
WHERE controller IN ('BankAccountList', 'BankAccountForm', 'ServiceImportForm', 'CvShellController')
ORDER BY id;
SELECT id, system_group_id, system_program_id FROM system_group_program
WHERE system_program_id IN (105, 106, 107, 108)
ORDER BY system_program_id, system_group_id;

COMMIT;

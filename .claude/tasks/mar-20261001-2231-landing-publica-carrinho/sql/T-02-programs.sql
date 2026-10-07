-- T-02 — Registro de LandingLeadList ("Leads da landing") no grupo 1 (Template - Admin)
-- (banco permission = banco da aplicação, centralvet)
-- Estado lido em 2026-10-01 (só SELECT):
--   system_program:       MAX(id)=108; colunas id, name, controller
--   system_group_program: MAX(id)=110; colunas id (sem AUTO_INCREMENT), system_group_id,
--                         system_program_id
--   system_group:         1 Template - Admin, 2 Template - Users, 3 Application - Programs
-- Execução exige aprovação explícita do usuário. Só INSERT e SELECT.
-- Guarda: se os dois SELECT MAX(id) abaixo não devolverem 108 e 110, PARE aqui, rode
-- ROLLBACK (nunca COMMIT) e reajuste os ids fixos 109 e 111.

START TRANSACTION;

SELECT MAX(id) AS max_program_id FROM system_program;             -- esperado: 108
SELECT MAX(id) AS max_group_program_id FROM system_group_program; -- esperado: 110

INSERT INTO system_program (id, name, controller) VALUES (109, 'Leads da landing', 'LandingLeadList');

INSERT INTO system_group_program (id, system_group_id, system_program_id) VALUES (111, 1, 109);

-- Conferência (esperado: 1 linha cada)
SELECT id, name, controller FROM system_program WHERE controller = 'LandingLeadList';
SELECT id, system_group_id, system_program_id FROM system_group_program WHERE system_program_id = 109;

COMMIT;

-- T-07 — Registro de FinancialOverview e CvShellController (banco permission = banco da aplicação)
-- Estado lido em 2026-09-29 (só SELECT):
--   system_program:       MAX(id)=103, COUNT(*)=103
--   system_group_program: MAX(id)=103, COUNT(*)=103
--   FinancialEntryList (id 100) → grupo 1 (Template - Admin)
--   Programas clínicos (65+: PatientList, QueueEntryView, EncounterView, PrescriptionForm, ...) → só grupo 1
-- Execução exige aprovação explícita do usuário. Só INSERT; sem DELETE/UPDATE.

START TRANSACTION;

INSERT INTO system_program (id, name, controller) VALUES (104, 'Visão geral financeira', 'FinancialOverview');
INSERT INTO system_program (id, name, controller) VALUES (105, 'Contexto da casca', 'CvShellController');

INSERT INTO system_group_program (id, system_group_id, system_program_id) VALUES (104, 1, 104);
INSERT INTO system_group_program (id, system_group_id, system_program_id) VALUES (105, 1, 105);

COMMIT;

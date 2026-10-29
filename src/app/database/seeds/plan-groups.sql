-- Prepared only; requires separate approval.
-- Etapa 3 (T-11): grupos de permissão por plano. Cada grupo "Central Vet - Plano <Nome>"
-- recebe os programas de initial-application-programs.sql (exceto LandingLeadList) que
-- PlanEntitlements::forPlan(<plano>)->allowsProgram() libera, mais o autoatendimento do
-- próprio usuário: SystemProfileView (perfil), SystemProfileForm (troca de senha) e
-- SystemProfile2FAForm (2FA).
-- Nenhum programa de administração global do sistema (SystemUser*, SystemUnit*,
-- SystemGroup*, SystemProgram*, SystemSQLPanel, SystemDatabaseExplorer, LandingLeadList...):
-- eles não filtram por tenant e levariam ao grupo 1 ou a dados de outro tenant. Gestão de
-- equipe e de unidades por clínica fica para task própria, que acrescenta seus programas.
-- Depende de permission.sql e initial-application-programs.sql já aplicados: um programa
-- ausente em system_program simplesmente não é ligado. Idempotente; não apaga nem altera nada.
-- Os JOINs por system_group.name e system_program.controller supõem nomes únicos (mesmo
-- padrão de initial-application-programs.sql): duplicata geraria o mesmo MAX+1 e erro de PK.
-- Regerar a partir de PlanEntitlements quando o mapa de direitos mudar (PlanGroupsSeedTest confere).

SET NAMES utf8mb4;

START TRANSACTION;

-- Plano Starter (starter): 42 programas.

INSERT INTO system_group (id, name)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group current_groups), 'Central Vet - Plano Starter'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_group existing_group WHERE existing_group.name='Central Vet - Plano Starter');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='AgendaView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='AppointmentForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='CashSessionForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='CashSessionList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='CvShellController'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='DocumentList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='DocumentRequestForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='DocumentTemplateForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='DocumentTemplateList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='EncounterAccountForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='EncounterView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='ExamCatalogForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='ExamCatalogList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='ExamRequestForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='ExamResultForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='GlobalSearchController'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='PatientForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='PatientList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='PaymentForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='PendingCenter'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='PendingExamResultList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='PendingReceivableList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='PrescriptionForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='ProcedureCatalogForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='ProcedureCatalogList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='ProcedureExecutionForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='ProcedureInputForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='QueueEntryView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='ServiceForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='ServiceImportForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='ServiceList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='TutorForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='TutorList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='VaccinationCardView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='VaccinationForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='VaccineCatalogForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='VaccineCatalogList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='VaccineProtocolForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='SubscriptionStatusView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='SystemProfileView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='SystemProfileForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='SystemProfile2FAForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

-- Plano Pro (pro): 61 programas.

INSERT INTO system_group (id, name)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group current_groups), 'Central Vet - Plano Pro'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_group existing_group WHERE existing_group.name='Central Vet - Plano Pro');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='AgendaView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='AppointmentForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='BankAccountForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='BankAccountList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='CashSessionForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='CashSessionList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='CommunicationComposeForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='CommunicationMessageList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='CommunicationMessageView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='CvShellController'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='DocumentList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='DocumentRequestForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='DocumentTemplateForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='DocumentTemplateList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='EncounterAccountForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='EncounterView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='ExamCatalogForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='ExamCatalogList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='ExamRequestForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='ExamResultForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='FinancialEntryForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='FinancialEntryList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='FinancialOverview'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='GlobalSearchController'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='MessageTemplateForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='MessageTemplateList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='PatientForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='PatientList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='PayableForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='PayableList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='PaymentForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='PendingCenter'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='PendingExamResultList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='PendingReceivableList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='PrescriptionForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='ProcedureCatalogForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='ProcedureCatalogList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='ProcedureExecutionForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='ProcedureInputForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='ProductForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='ProductList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='QueueEntryView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='SaleCancelForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='SaleForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='SaleList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='ServiceForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='ServiceImportForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='ServiceList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='StockBatchForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='TutorCommunicationForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='TutorForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='TutorList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='VaccinationCardView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='VaccinationForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='VaccineCatalogForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='VaccineCatalogList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='VaccineProtocolForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='SubscriptionStatusView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='SystemProfileView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='SystemProfileForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='SystemProfile2FAForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

-- Plano Business (business): 61 programas.

INSERT INTO system_group (id, name)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group current_groups), 'Central Vet - Plano Business'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_group existing_group WHERE existing_group.name='Central Vet - Plano Business');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='AgendaView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='AppointmentForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='BankAccountForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='BankAccountList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='CashSessionForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='CashSessionList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='CommunicationComposeForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='CommunicationMessageList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='CommunicationMessageView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='CvShellController'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='DocumentList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='DocumentRequestForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='DocumentTemplateForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='DocumentTemplateList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='EncounterAccountForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='EncounterView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='ExamCatalogForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='ExamCatalogList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='ExamRequestForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='ExamResultForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='FinancialEntryForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='FinancialEntryList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='FinancialOverview'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='GlobalSearchController'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='MessageTemplateForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='MessageTemplateList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='PatientForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='PatientList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='PayableForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='PayableList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='PaymentForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='PendingCenter'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='PendingExamResultList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='PendingReceivableList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='PrescriptionForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='ProcedureCatalogForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='ProcedureCatalogList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='ProcedureExecutionForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='ProcedureInputForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='ProductForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='ProductList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='QueueEntryView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='SaleCancelForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='SaleForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='SaleList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='ServiceForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='ServiceImportForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='ServiceList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='StockBatchForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='TutorCommunicationForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='TutorForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='TutorList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='VaccinationCardView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='VaccinationForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='VaccineCatalogForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='VaccineCatalogList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='VaccineProtocolForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='SubscriptionStatusView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='SystemProfileView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='SystemProfileForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='SystemProfile2FAForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

-- Plano Enterprise (enterprise): 83 programas.

INSERT INTO system_group (id, name)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group current_groups), 'Central Vet - Plano Enterprise'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_group existing_group WHERE existing_group.name='Central Vet - Plano Enterprise');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='AgendaView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='AiConversationList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='AiCopilotPanel'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='AiInteractionList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='AiSettingsForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='AiToolCallList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='AppointmentForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='BankAccountForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='BankAccountList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='BedForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='BedList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='CashSessionForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='CashSessionList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='CommunicationComposeForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='CommunicationMessageList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='CommunicationMessageView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='CvShellController'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='DocumentList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='DocumentRequestForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='DocumentTemplateForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='DocumentTemplateList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='EncounterAccountForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='EncounterView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='ExamCatalogForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='ExamCatalogList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='ExamRequestForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='ExamResultForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='FinancialEntryForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='FinancialEntryList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='FinancialOverview'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='GlobalSearchController'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='HospitalizationAdministrationForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='HospitalizationAdmissionForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='HospitalizationBoard'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='HospitalizationEventForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='HospitalizationOrderForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='HospitalizationView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='MessageTemplateForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='MessageTemplateList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='PatientForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='PatientList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='PayableForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='PayableList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='PaymentForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='PendingCenter'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='PendingExamResultList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='PendingReceivableList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='PrescriptionForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='ProcedureCatalogForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='ProcedureCatalogList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='ProcedureExecutionForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='ProcedureInputForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='ProductForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='ProductList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='QueueEntryView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='SaleCancelForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='SaleForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='SaleList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='ServiceForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='ServiceImportForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='ServiceList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='StockBatchForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='SurgeryChecklistForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='SurgeryConsentForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='SurgeryEventForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='SurgeryList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='SurgeryMaterialForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='SurgeryRoomForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='SurgeryRoomList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='SurgeryScheduleForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='SurgeryView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='TutorCommunicationForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='TutorForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='TutorList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='VaccinationCardView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='VaccinationForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='VaccineCatalogForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='VaccineCatalogList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='VaccineProtocolForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='SubscriptionStatusView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='SystemProfileView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='SystemProfileForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='SystemProfile2FAForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

-- Etapa 3 (T-25): equipe (ClinicUserList/ClinicUserForm) e unidades (ClinicUnitList/
-- ClinicUnitForm) por clínica nos 4 grupos de plano; com elas, Starter 46, Pro 65,
-- Business 65 e Enterprise 87 programas. O limite de unidades vem de
-- PlanEntitlements::maxUnits() no ClinicUnitService.

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='ClinicUserList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='ClinicUserForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='ClinicUnitList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Starter'
WHERE p.controller='ClinicUnitForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='ClinicUserList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='ClinicUserForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='ClinicUnitList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Pro'
WHERE p.controller='ClinicUnitForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='ClinicUserList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='ClinicUserForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='ClinicUnitList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Business'
WHERE p.controller='ClinicUnitForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='ClinicUserList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='ClinicUserForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='ClinicUnitList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Central Vet - Plano Enterprise'
WHERE p.controller='ClinicUnitForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

COMMIT;

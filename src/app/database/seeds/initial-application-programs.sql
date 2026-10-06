-- Prepared only: initial Central Vet programs for the administrator group (id=1).

-- Requires separate approval; no business records are created or changed.

SET NAMES utf8mb4;

START TRANSACTION;

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Agenda View', 'AgendaView'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='AgendaView');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='AgendaView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Appointment Form', 'AppointmentForm'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='AppointmentForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='AppointmentForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Bank Account Form', 'BankAccountForm'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='BankAccountForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='BankAccountForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Bank Account List', 'BankAccountList'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='BankAccountList');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='BankAccountList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Cash Session Form', 'CashSessionForm'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='CashSessionForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='CashSessionForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Cash Session List', 'CashSessionList'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='CashSessionList');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='CashSessionList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Cv Shell Controller', 'CvShellController'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='CvShellController');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='CvShellController'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Encounter Account Form', 'EncounterAccountForm'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='EncounterAccountForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='EncounterAccountForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Encounter View', 'EncounterView'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='EncounterView');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='EncounterView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Exam Catalog Form', 'ExamCatalogForm'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='ExamCatalogForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='ExamCatalogForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Exam Catalog List', 'ExamCatalogList'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='ExamCatalogList');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='ExamCatalogList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Exam Request Form', 'ExamRequestForm'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='ExamRequestForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='ExamRequestForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Exam Result Form', 'ExamResultForm'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='ExamResultForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='ExamResultForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Financial Entry Form', 'FinancialEntryForm'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='FinancialEntryForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='FinancialEntryForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Financial Entry List', 'FinancialEntryList'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='FinancialEntryList');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='FinancialEntryList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Financial Overview', 'FinancialOverview'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='FinancialOverview');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='FinancialOverview'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Global Search Controller', 'GlobalSearchController'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='GlobalSearchController');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='GlobalSearchController'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Patient Form', 'PatientForm'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='PatientForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='PatientForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Patient List', 'PatientList'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='PatientList');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='PatientList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Payable Form', 'PayableForm'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='PayableForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='PayableForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Payable List', 'PayableList'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='PayableList');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='PayableList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Payment Form', 'PaymentForm'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='PaymentForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='PaymentForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Pending Exam Result List', 'PendingExamResultList'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='PendingExamResultList');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='PendingExamResultList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Pending Receivable List', 'PendingReceivableList'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='PendingReceivableList');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='PendingReceivableList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Prescription Form', 'PrescriptionForm'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='PrescriptionForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='PrescriptionForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Procedure Catalog Form', 'ProcedureCatalogForm'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='ProcedureCatalogForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='ProcedureCatalogForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Procedure Catalog List', 'ProcedureCatalogList'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='ProcedureCatalogList');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='ProcedureCatalogList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Procedure Execution Form', 'ProcedureExecutionForm'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='ProcedureExecutionForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='ProcedureExecutionForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Procedure Input Form', 'ProcedureInputForm'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='ProcedureInputForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='ProcedureInputForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Product Form', 'ProductForm'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='ProductForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='ProductForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Product List', 'ProductList'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='ProductList');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='ProductList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Queue Entry View', 'QueueEntryView'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='QueueEntryView');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='QueueEntryView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Sale Form', 'SaleForm'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='SaleForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='SaleForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Service Form', 'ServiceForm'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='ServiceForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='ServiceForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Service Import Form', 'ServiceImportForm'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='ServiceImportForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='ServiceImportForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Service List', 'ServiceList'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='ServiceList');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='ServiceList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Stock Batch Form', 'StockBatchForm'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='StockBatchForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='StockBatchForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Tutor Form', 'TutorForm'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='TutorForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='TutorForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Tutor List', 'TutorList'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='TutorList');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='TutorList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Vaccination Card View', 'VaccinationCardView'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='VaccinationCardView');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='VaccinationCardView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Vaccination Form', 'VaccinationForm'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='VaccinationForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='VaccinationForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Vaccine Catalog Form', 'VaccineCatalogForm'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='VaccineCatalogForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='VaccineCatalogForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Vaccine Catalog List', 'VaccineCatalogList'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='VaccineCatalogList');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='VaccineCatalogList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Vaccine Protocol Form', 'VaccineProtocolForm'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='VaccineProtocolForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='VaccineProtocolForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Landing Lead List', 'LandingLeadList'
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE controller='LandingLeadList');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='LandingLeadList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_role (id,name,custom_code) SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_role current_roles), 'Financial - Discount authorization', 'financial_discount' WHERE NOT EXISTS (SELECT 1 FROM system_role WHERE custom_code='financial_discount');

INSERT INTO tenant_role (tenant_id,system_role_id) SELECT 1,r.id FROM system_role r WHERE r.custom_code='financial_discount' AND NOT EXISTS (SELECT 1 FROM tenant_role tr WHERE tr.tenant_id=1 AND tr.system_role_id=r.id);

INSERT INTO system_program_method_role (id,system_program_id,system_role_id,method_name) SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program_method_role current_restrictions),p.id,r.id,'onApplyDiscount' FROM system_program p JOIN system_role r ON r.custom_code='financial_discount' WHERE p.controller='EncounterAccountForm' AND NOT EXISTS (SELECT 1 FROM system_program_method_role pmr WHERE pmr.system_program_id=p.id AND pmr.method_name='onApplyDiscount' AND pmr.system_role_id=r.id);

INSERT INTO system_user_role (id,system_user_id,system_role_id) SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_user_role current_user_roles),u.id,r.id FROM system_users u JOIN system_role r ON r.custom_code='financial_discount' WHERE u.login='admin' AND NOT EXISTS (SELECT 1 FROM system_user_role ur WHERE ur.system_user_id=u.id AND ur.system_role_id=r.id);

-- Fase 6A — Internação: grupo clínico e 8 programas, concedidos ao grupo 1 e ao grupo novo.

INSERT INTO system_group (id, name)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group current_groups), 'Clínico – Internação'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_group existing_group WHERE existing_group.name='Clínico – Internação');

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Bed List', 'BedList'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='BedList');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='BedList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Internação'
WHERE p.controller='BedList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Bed Form', 'BedForm'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='BedForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='BedForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Internação'
WHERE p.controller='BedForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Hospitalization Board', 'HospitalizationBoard'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='HospitalizationBoard');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='HospitalizationBoard'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Internação'
WHERE p.controller='HospitalizationBoard'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Hospitalization Admission Form', 'HospitalizationAdmissionForm'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='HospitalizationAdmissionForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='HospitalizationAdmissionForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Internação'
WHERE p.controller='HospitalizationAdmissionForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Hospitalization View', 'HospitalizationView'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='HospitalizationView');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='HospitalizationView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Internação'
WHERE p.controller='HospitalizationView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Hospitalization Order Form', 'HospitalizationOrderForm'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='HospitalizationOrderForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='HospitalizationOrderForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Internação'
WHERE p.controller='HospitalizationOrderForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Hospitalization Administration Form', 'HospitalizationAdministrationForm'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='HospitalizationAdministrationForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='HospitalizationAdministrationForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Internação'
WHERE p.controller='HospitalizationAdministrationForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Hospitalization Event Form', 'HospitalizationEventForm'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='HospitalizationEventForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='HospitalizationEventForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Internação'
WHERE p.controller='HospitalizationEventForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

-- Fase 6B — Cirurgia: grupo clínico e 9 programas, concedidos ao grupo 1 e ao grupo novo.

INSERT INTO system_group (id, name)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group current_groups), 'Clínico – Cirurgia'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_group existing_group WHERE existing_group.name='Clínico – Cirurgia');

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Surgery Room List', 'SurgeryRoomList'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='SurgeryRoomList');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='SurgeryRoomList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Cirurgia'
WHERE p.controller='SurgeryRoomList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Surgery Room Form', 'SurgeryRoomForm'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='SurgeryRoomForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='SurgeryRoomForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Cirurgia'
WHERE p.controller='SurgeryRoomForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Surgery List', 'SurgeryList'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='SurgeryList');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='SurgeryList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Cirurgia'
WHERE p.controller='SurgeryList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Surgery Schedule Form', 'SurgeryScheduleForm'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='SurgeryScheduleForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='SurgeryScheduleForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Cirurgia'
WHERE p.controller='SurgeryScheduleForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Surgery View', 'SurgeryView'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='SurgeryView');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='SurgeryView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Cirurgia'
WHERE p.controller='SurgeryView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Surgery Consent Form', 'SurgeryConsentForm'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='SurgeryConsentForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='SurgeryConsentForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Cirurgia'
WHERE p.controller='SurgeryConsentForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Surgery Event Form', 'SurgeryEventForm'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='SurgeryEventForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='SurgeryEventForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Cirurgia'
WHERE p.controller='SurgeryEventForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Surgery Checklist Form', 'SurgeryChecklistForm'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='SurgeryChecklistForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='SurgeryChecklistForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Cirurgia'
WHERE p.controller='SurgeryChecklistForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Surgery Material Form', 'SurgeryMaterialForm'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='SurgeryMaterialForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='SurgeryMaterialForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Cirurgia'
WHERE p.controller='SurgeryMaterialForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

-- Fase 7A — Comunicação e Central de Pendências: 7 programas, concedidos aos grupos 1, 2,
-- 'Clínico – Internação' e 'Clínico – Cirurgia' (o grupo 3 não recebe nada).

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Message Template List', 'MessageTemplateList'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='MessageTemplateList');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='MessageTemplateList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 2, p.id
FROM system_program p WHERE p.controller='MessageTemplateList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=2 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Internação'
WHERE p.controller='MessageTemplateList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Cirurgia'
WHERE p.controller='MessageTemplateList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Message Template Form', 'MessageTemplateForm'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='MessageTemplateForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='MessageTemplateForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 2, p.id
FROM system_program p WHERE p.controller='MessageTemplateForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=2 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Internação'
WHERE p.controller='MessageTemplateForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Cirurgia'
WHERE p.controller='MessageTemplateForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Communication Message List', 'CommunicationMessageList'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='CommunicationMessageList');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='CommunicationMessageList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 2, p.id
FROM system_program p WHERE p.controller='CommunicationMessageList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=2 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Internação'
WHERE p.controller='CommunicationMessageList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Cirurgia'
WHERE p.controller='CommunicationMessageList'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Communication Message View', 'CommunicationMessageView'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='CommunicationMessageView');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='CommunicationMessageView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 2, p.id
FROM system_program p WHERE p.controller='CommunicationMessageView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=2 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Internação'
WHERE p.controller='CommunicationMessageView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Cirurgia'
WHERE p.controller='CommunicationMessageView'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Communication Compose Form', 'CommunicationComposeForm'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='CommunicationComposeForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='CommunicationComposeForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 2, p.id
FROM system_program p WHERE p.controller='CommunicationComposeForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=2 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Internação'
WHERE p.controller='CommunicationComposeForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Cirurgia'
WHERE p.controller='CommunicationComposeForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Tutor Communication Form', 'TutorCommunicationForm'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='TutorCommunicationForm');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='TutorCommunicationForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 2, p.id
FROM system_program p WHERE p.controller='TutorCommunicationForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=2 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Internação'
WHERE p.controller='TutorCommunicationForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Cirurgia'
WHERE p.controller='TutorCommunicationForm'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs), 'Central Vet - Pending Center', 'PendingCenter'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program existing_program WHERE existing_program.controller='PendingCenter');

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='PendingCenter'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=1 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 2, p.id
FROM system_program p WHERE p.controller='PendingCenter'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=2 AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Internação'
WHERE p.controller='PendingCenter'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), g.id, p.id
FROM system_program p JOIN system_group g ON g.name='Clínico – Cirurgia'
WHERE p.controller='PendingCenter'
AND NOT EXISTS (SELECT 1 FROM system_group_program existing_link WHERE existing_link.system_group_id=g.id AND existing_link.system_program_id=p.id);

COMMIT;

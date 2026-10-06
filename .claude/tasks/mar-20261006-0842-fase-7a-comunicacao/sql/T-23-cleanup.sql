-- T-23 — Limpeza dos registros de gate "F7A teste" da Fase 7A (banco centralvet)
--
-- NÃO EXECUTAR SEM APROVAÇÃO. Só o orquestrador executa, depois do gate final da Onda 6, com
-- aprovação SQL explícita do usuário (skill sql-write-approval) e backup antes:
--   ./scripts/backup.sh && gzip -t var/backups/<arquivo>.sql.gz
-- Execução interativa (para ler os SELECTs antes de decidir o COMMIT):
--   docker compose exec mysql sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" --default-character-set=utf8mb4 centralvet'
--   mysql> source /caminho/T-23-cleanup.sql
-- O usuário precisa de CREATE TEMPORARY TABLES (as tabelas tmp_f7a_* vivem só na sessão).
-- O COMMIT final está comentado: sem ele, fechar a sessão desfaz tudo. Qualquer erro (FK
-- RESTRICT, CHECK) interrompe o script; rode ROLLBACK e reveja o predicado.
-- Recomendado: ensaio completo terminando em ROLLBACK antes da execução com COMMIT.
--
-- Estado lido em 2026-10-06 10:26 (só SELECT, MySQL 8.0.43), ANTES do gate da Onda 6:
--   tutor 7 (MAX(id) 13876), patient 7, appointment 15 (MAX(id) 831), encounter 7 (MAX(id) 4304),
--   encounter_account 4, receivable 3, payment 3, vaccination 0, queue_entry 4,
--   system_program 133 (126 da 6B + 7 da T-04), audit_log MAX(id) 5739.
--   communication_message 0, communication_preference 0, message_template 0, appointment_followup 0.
--   tutor/patient/message_template 'F7A teste%': 0; tutor com e-mail f7a.teste@...: 0.
--
-- Estado lido em 2026-10-06 10:51 (só SELECT), DEPOIS dos gates (roteiro A, revalidação 1, roteiro B;
-- mensagens e templates relidos depois do complemento da rodada 2 e da onda 7):
--   tutor 9 (MAX 15446), patient 9 (MAX 13320), appointment 20 (MAX 838), encounter 8 (MAX 11104),
--   communication_message 13 (MAX 42; 30 e 36 do complemento da rodada 2, 42 da onda 7),
--   communication_preference 3, message_template 7 (MAX 7; 7 da onda 7),
--   appointment_followup 3; encounter_account 4, receivable 3, payment 3, vaccination 0,
--   queue_entry 4, system_program 133 (inalterados); audit_log MAX(id) 5856.
--   Ids criados nos gates (todos alvo deste script):
--     tutor                    15445 'F7A teste Tutor', 15446 'F7A teste Tutor 2'
--     patient                  13319 'F7A teste Pet' (tutor 15445), 13320 'F7A teste Pet 2' (tutor 15446)
--     message_template         1-7 ('F7A teste confirmação/retorno/vacina/cobrança/whatsapp/unidade/xss')
--     communication_preference 1, 2 (tutor 15445), 3 (tutor 15446)
--     communication_message    1-7, 17, 18, 19, 30, 36, 42 (8-16, 20-29, 31-35 e 37-41 são lacunas
--                              do auto_increment; 19, 30, 36 e 42 têm patient_id NULL e entram pelo
--                              tutor 15445; 42 usa o template 7)
--     appointment              834 (paciente 13319), 835 (13320), 836, 837, 838 (retornos de 11104)
--     encounter                11104 (paciente 13319, agendamento 834, in_progress)
--     appointment_followup     1, 2, 3 (encounter 11104 → appointment 836, 837, 838)
--   Dependentes procurados (FKs de information_schema.KEY_COLUMN_USAGE + colunas *_id sem FK) e
--   vazios para esses ids: encounter_account, encounter_account_item, receivable, payment,
--   vaccination, exam_request, exam_result, prescription, prescription_item, procedure_execution,
--   sale, hospitalization, surgery (incl. followup_appointment_id), queue_entry, financial_entry,
--   stock_movement e stored_object (nada criado depois de @gate_start), system_change_log,
--   system_sql_log e system_notification (0 linhas). Não há triggers no schema.
--   Mensagens dos gates para tutores fora do prefixo: 0.
--   Resultado esperado: tutor 7, patient 7, appointment 15, encounter 7, communication_message 0,
--   communication_preference 0, message_template 0, appointment_followup 0 (MAX(id) de tutor,
--   patient, appointment e encounter volta a 13876, 9180, 831 e 4304; o AUTO_INCREMENT não volta).
--
-- Guardas por id: além do prefixo, tutor/patient/appointment/encounter só entram se o id for
-- maior que o MAX(id) pré-gate (13876, 9180, 831, 4304); a seção 2.5 confere as listas derivadas
-- contra os ids explícitos acima (coluna confere = 1 em todas; se alguma vier 0, PARE).
--
-- audit_log: os registros gerados pelos gates (ids 5740-5856, 113 linhas: communication_preference
-- 14, message_template 34, communication_message 37, pending_center 22, appointment 5,
-- encounter 1) ficam de propósito: são a trilha de auditoria append-only, não têm FK para as
-- linhas apagadas e o plano (T-23) deixa audit_log fora. O DELETE das linhas abaixo gera novos
-- registros só se feito pela aplicação; por SQL direto, não.
--
-- Como os ids são derivados (todos a partir do prefixo 'F7A teste'):
--   tutores      = tutor.full_name LIKE 'F7A teste%' ("F7A teste Tutor", "F7A teste Tutor 2")
--   pacientes    = patient.name LIKE 'F7A teste%' ou paciente de tutor F7A
--   templates    = message_template.name LIKE 'F7A teste%'
--   mensagens    = communication_message de tutor F7A, de paciente F7A ou com template F7A
--   preferências = communication_preference de tutor F7A
--   atendimentos = encounter de paciente F7A
--   agendamentos = appointment de paciente F7A (inclui o retorno agendado pelo atendimento)
--   follow-ups   = appointment_followup de agendamento ou atendimento F7A
--   contas       = encounter_account de atendimento, paciente ou tutor F7A
--   recebíveis   = receivable de conta F7A ou de tutor F7A
--   vacinações   = vaccination de atendimento ou paciente F7A
--
-- Mensagens criadas pelo agendador do gate para tutores FORA do prefixo (o tick roda em todos os
-- tenants ativos) NÃO são apagadas: aparecem na lista "mensagens_fora" (seção 2.4) para decisão
-- à parte. A entrega pelo driver `log` não tem efeito externo.
--
-- Pagamentos: payment → financial_entry (reference_type 'payment') e sessão de caixa. O roteiro
-- do gate não registra pagamento; se a lista "payment" (2.4) não vier vazia, PARE: o DELETE do
-- recebível falha pela FK payment_receivable_fk e o caixa precisa de tratamento à parte.
--
-- Ordem das FKs: communication_message → communication_preference → message_template →
-- appointment_followup → receivable → encounter_account_item → encounter_account → vaccination
-- → exam_result → exam_request → prescription_item → prescription → encounter → queue_entry →
-- appointment → patient → tutor. procedure_execution, sale, hospitalization e surgery de
-- atendimento F7A não são esperados (o gate não os cria): a FK RESTRICT interrompe o script se
-- existirem; ROLLBACK e reveja.
--
-- PARE (ROLLBACK) se: algum SELECT "antes" listar linha que não seja de teste; a lista de
-- payment não vier vazia; ou as linhas afetadas divergirem das contagens "antes".

SET NAMES utf8mb4;
-- Início do gate da Onda 6 (estado acima lido antes dele). Ajuste se o gate começou antes.
SET @gate_start = '2026-10-06 10:26:00';

-- =============================================================================================
-- 1. Contagens antes (só leitura)
-- =============================================================================================
SELECT COUNT(*) AS tutor_total                    FROM tutor;                     -- pré-gate: 7
SELECT COUNT(*) AS patient_total                  FROM patient;                   -- pré-gate: 7
SELECT COUNT(*) AS appointment_total              FROM appointment;               -- pré-gate: 15
SELECT COUNT(*) AS encounter_total                FROM encounter;                 -- pré-gate: 7
SELECT COUNT(*) AS encounter_account_total        FROM encounter_account;         -- pré-gate: 4
SELECT COUNT(*) AS receivable_total               FROM receivable;                -- pré-gate: 3
SELECT COUNT(*) AS payment_total                  FROM payment;                   -- pré-gate: 3 (não muda)
SELECT COUNT(*) AS vaccination_total              FROM vaccination;               -- pré-gate: 0
SELECT COUNT(*) AS queue_entry_total              FROM queue_entry;               -- pré-gate: 4
SELECT COUNT(*) AS system_program_total           FROM system_program;            -- pré-gate: 133 (não muda)
SELECT COUNT(*) AS communication_message_total    FROM communication_message;     -- pré-gate: 0
SELECT COUNT(*) AS communication_preference_total FROM communication_preference;  -- pré-gate: 0
SELECT COUNT(*) AS message_template_total         FROM message_template;          -- pré-gate: 0
SELECT COUNT(*) AS appointment_followup_total     FROM appointment_followup;      -- pré-gate: 0
SELECT COUNT(*) AS tutor_f7a    FROM tutor            WHERE full_name LIKE 'F7A teste%';
SELECT COUNT(*) AS patient_f7a  FROM patient          WHERE name LIKE 'F7A teste%';
SELECT COUNT(*) AS template_f7a FROM message_template WHERE name LIKE 'F7A teste%';

-- =============================================================================================
-- 2. Ids derivados do prefixo (tabelas temporárias da sessão; cada uma é lida uma vez por
--    comando, por causa do erro 1137 "Can't reopen table" do MySQL)
-- =============================================================================================
DROP TEMPORARY TABLE IF EXISTS tmp_f7a_tutor, tmp_f7a_patient, tmp_f7a_template, tmp_f7a_message,
    tmp_f7a_encounter, tmp_f7a_appointment, tmp_f7a_followup, tmp_f7a_account, tmp_f7a_receivable,
    tmp_f7a_vaccination, tmp_f7a_exam_request, tmp_f7a_prescription;

CREATE TEMPORARY TABLE tmp_f7a_tutor        (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f7a_patient      (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f7a_template     (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f7a_message      (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f7a_encounter    (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f7a_appointment  (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f7a_followup     (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f7a_account      (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f7a_receivable   (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f7a_vaccination  (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f7a_exam_request (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f7a_prescription (id BIGINT UNSIGNED PRIMARY KEY);

-- 2.1 Cadastros com o prefixo
INSERT INTO tmp_f7a_tutor    SELECT id FROM tutor            WHERE full_name LIKE 'F7A teste%' AND id > 13876;
INSERT INTO tmp_f7a_template SELECT id FROM message_template WHERE name LIKE 'F7A teste%';
INSERT IGNORE INTO tmp_f7a_patient SELECT id FROM patient WHERE name LIKE 'F7A teste%' AND id > 9180;
INSERT IGNORE INTO tmp_f7a_patient SELECT id FROM patient WHERE tutor_id IN (SELECT id FROM tmp_f7a_tutor) AND id > 9180;

-- 2.2 Comunicação
INSERT IGNORE INTO tmp_f7a_message SELECT id FROM communication_message WHERE tutor_id    IN (SELECT id FROM tmp_f7a_tutor);
INSERT IGNORE INTO tmp_f7a_message SELECT id FROM communication_message WHERE patient_id  IN (SELECT id FROM tmp_f7a_patient);
INSERT IGNORE INTO tmp_f7a_message SELECT id FROM communication_message WHERE template_id IN (SELECT id FROM tmp_f7a_template);

-- 2.3 Clínica e financeiro dos pacientes/tutores F7A
INSERT INTO tmp_f7a_encounter   SELECT id FROM encounter   WHERE patient_id IN (SELECT id FROM tmp_f7a_patient) AND id > 4304;
INSERT INTO tmp_f7a_appointment SELECT id FROM appointment WHERE patient_id IN (SELECT id FROM tmp_f7a_patient) AND id > 831;
INSERT IGNORE INTO tmp_f7a_followup SELECT id FROM appointment_followup WHERE appointment_id IN (SELECT id FROM tmp_f7a_appointment);
INSERT IGNORE INTO tmp_f7a_followup SELECT id FROM appointment_followup WHERE encounter_id   IN (SELECT id FROM tmp_f7a_encounter);
INSERT IGNORE INTO tmp_f7a_account SELECT id FROM encounter_account WHERE encounter_id IN (SELECT id FROM tmp_f7a_encounter);
INSERT IGNORE INTO tmp_f7a_account SELECT id FROM encounter_account WHERE patient_id   IN (SELECT id FROM tmp_f7a_patient);
INSERT IGNORE INTO tmp_f7a_account SELECT id FROM encounter_account WHERE tutor_id     IN (SELECT id FROM tmp_f7a_tutor);
INSERT IGNORE INTO tmp_f7a_receivable SELECT id FROM receivable WHERE encounter_account_id IN (SELECT id FROM tmp_f7a_account);
INSERT IGNORE INTO tmp_f7a_receivable SELECT id FROM receivable WHERE tutor_id             IN (SELECT id FROM tmp_f7a_tutor);
INSERT IGNORE INTO tmp_f7a_vaccination SELECT id FROM vaccination WHERE encounter_id IN (SELECT id FROM tmp_f7a_encounter);
INSERT IGNORE INTO tmp_f7a_vaccination SELECT id FROM vaccination WHERE patient_id   IN (SELECT id FROM tmp_f7a_patient);
INSERT INTO tmp_f7a_exam_request SELECT id FROM exam_request WHERE encounter_id IN (SELECT id FROM tmp_f7a_encounter);
INSERT INTO tmp_f7a_prescription SELECT id FROM prescription WHERE encounter_id IN (SELECT id FROM tmp_f7a_encounter);

-- 2.4 Revisão das linhas que serão apagadas (antes). Sem e-mail, telefone nem corpo de mensagem.
SELECT 'tutor' AS t, u.id, u.full_name, u.created_at FROM tutor u WHERE u.id IN (SELECT id FROM tmp_f7a_tutor);
-- ^ tutores com o e-mail de teste e nome fora do prefixo (deve ser vazio; se listar, PARE):
SELECT 'tutor_email_fora' AS t, u.id, u.full_name FROM tutor u
 WHERE u.email = 'f7a.teste@example.invalid' AND u.full_name NOT LIKE 'F7A teste%';
SELECT 'patient' AS t, p.id, p.name, p.tutor_id FROM patient p WHERE p.id IN (SELECT id FROM tmp_f7a_patient);
-- ^ PARE se algum paciente sem o prefixo não for de teste.
SELECT 'template' AS t, m.id, m.name, m.purpose, m.channel, m.status FROM message_template m WHERE m.id IN (SELECT id FROM tmp_f7a_template);
SELECT 'message' AS t, c.id, c.tutor_id, c.patient_id, c.template_id, c.purpose, c.channel, c.origin, c.status, c.source_type, c.source_id, c.created_at
  FROM communication_message c WHERE c.id IN (SELECT id FROM tmp_f7a_message);
-- ^ mensagens criadas no gate fora do prefixo (ficam; decisão à parte):
SELECT 'mensagens_fora' AS t, c.id, c.tutor_id, c.purpose, c.channel, c.origin, c.status, c.created_at
  FROM communication_message c WHERE c.id NOT IN (SELECT id FROM tmp_f7a_message) AND c.created_at >= @gate_start;
SELECT 'preference' AS t, p.id, p.tutor_id, p.channel, p.status, p.consent_source FROM communication_preference p
 WHERE p.tutor_id IN (SELECT id FROM tmp_f7a_tutor);
SELECT 'followup' AS t, f.id, f.appointment_id, f.encounter_id FROM appointment_followup f WHERE f.id IN (SELECT id FROM tmp_f7a_followup);
SELECT 'appointment' AS t, a.id, a.patient_id, a.scheduled_at, a.status FROM appointment a WHERE a.id IN (SELECT id FROM tmp_f7a_appointment);
SELECT 'encounter' AS t, e.id, e.patient_id, e.appointment_id, e.status FROM encounter e WHERE e.id IN (SELECT id FROM tmp_f7a_encounter);
SELECT 'account' AS t, a.id, a.encounter_id, a.status, a.total_cents FROM encounter_account a WHERE a.id IN (SELECT id FROM tmp_f7a_account);
SELECT 'receivable' AS t, r.id, r.encounter_account_id, r.tutor_id, r.status, r.total_cents, r.paid_cents FROM receivable r WHERE r.id IN (SELECT id FROM tmp_f7a_receivable);
-- ^ pagamentos dos recebíveis F7A (deve ser vazio; se listar, PARE):
SELECT 'payment' AS t, p.id, p.receivable_id, p.amount_cents FROM payment p WHERE p.receivable_id IN (SELECT id FROM tmp_f7a_receivable);
SELECT 'vaccination' AS t, v.id, v.patient_id, v.encounter_id, v.next_dose_at FROM vaccination v WHERE v.id IN (SELECT id FROM tmp_f7a_vaccination);
SELECT COUNT(*) AS exam_requests FROM tmp_f7a_exam_request;
SELECT COUNT(*) AS prescriptions FROM tmp_f7a_prescription;
-- ^ dependentes não esperados (devem ser 0; a FK interrompe o DELETE do atendimento):
SELECT COUNT(*) AS procedure_executions FROM procedure_execution WHERE encounter_id IN (SELECT id FROM tmp_f7a_encounter);
SELECT COUNT(*) AS sales                FROM sale                WHERE patient_id   IN (SELECT id FROM tmp_f7a_patient);
SELECT COUNT(*) AS hospitalizations     FROM hospitalization     WHERE patient_id   IN (SELECT id FROM tmp_f7a_patient);
SELECT COUNT(*) AS surgeries            FROM surgery             WHERE patient_id   IN (SELECT id FROM tmp_f7a_patient);
SELECT COUNT(*) AS surgery_followups    FROM surgery             WHERE followup_appointment_id IN (SELECT id FROM tmp_f7a_appointment);
SELECT COUNT(*) AS account_items        FROM encounter_account_item WHERE account_id IN (SELECT id FROM tmp_f7a_account);

-- 2.5 Conferência das listas derivadas contra os ids explícitos do gate (confere = 1 em todas)
SELECT 'tutor' AS t, GROUP_CONCAT(id ORDER BY id) AS ids, GROUP_CONCAT(id ORDER BY id) = '15445,15446' AS confere FROM tmp_f7a_tutor;
SELECT 'patient' AS t, GROUP_CONCAT(id ORDER BY id) AS ids, GROUP_CONCAT(id ORDER BY id) = '13319,13320' AS confere FROM tmp_f7a_patient;
SELECT 'template' AS t, GROUP_CONCAT(id ORDER BY id) AS ids, GROUP_CONCAT(id ORDER BY id) = '1,2,3,4,5,6,7' AS confere FROM tmp_f7a_template;
SELECT 'message' AS t, GROUP_CONCAT(id ORDER BY id) AS ids, GROUP_CONCAT(id ORDER BY id) = '1,2,3,4,5,6,7,17,18,19,30,36,42' AS confere FROM tmp_f7a_message;
SELECT 'appointment' AS t, GROUP_CONCAT(id ORDER BY id) AS ids, GROUP_CONCAT(id ORDER BY id) = '834,835,836,837,838' AS confere FROM tmp_f7a_appointment;
SELECT 'encounter' AS t, GROUP_CONCAT(id ORDER BY id) AS ids, GROUP_CONCAT(id ORDER BY id) = '11104' AS confere FROM tmp_f7a_encounter;
SELECT 'followup' AS t, GROUP_CONCAT(id ORDER BY id) AS ids, GROUP_CONCAT(id ORDER BY id) = '1,2,3' AS confere FROM tmp_f7a_followup;
SELECT 'preference' AS t, GROUP_CONCAT(id ORDER BY id) AS ids, GROUP_CONCAT(id ORDER BY id) = '1,2,3' AS confere
  FROM communication_preference WHERE tutor_id IN (SELECT id FROM tmp_f7a_tutor);
-- ^ vazias esperadas (confere = 1 = lista vazia): conta, recebível, vacinação, exame, prescrição
SELECT (SELECT COUNT(*) FROM tmp_f7a_account) = 0     AS account_vazio;
SELECT (SELECT COUNT(*) FROM tmp_f7a_receivable) = 0  AS receivable_vazio;
SELECT (SELECT COUNT(*) FROM tmp_f7a_vaccination) = 0 AS vaccination_vazio;

-- =============================================================================================
-- 3. Limpeza (ordem das FKs)
-- =============================================================================================
START TRANSACTION;

-- 3.1 Comunicação: mensagem → preferência → template → vínculo de retorno
DELETE FROM communication_message    WHERE id IN (SELECT id FROM tmp_f7a_message);
DELETE FROM communication_preference WHERE tutor_id IN (SELECT id FROM tmp_f7a_tutor);
DELETE FROM message_template         WHERE id IN (SELECT id FROM tmp_f7a_template) AND name LIKE 'F7A teste%';
DELETE FROM appointment_followup     WHERE id IN (SELECT id FROM tmp_f7a_followup);

-- 3.2 Financeiro: recebível → itens → conta (payment não é apagado: FK interrompe se existir)
DELETE FROM receivable             WHERE id IN (SELECT id FROM tmp_f7a_receivable);
DELETE FROM encounter_account_item WHERE account_id IN (SELECT id FROM tmp_f7a_account);
DELETE FROM encounter_account      WHERE id IN (SELECT id FROM tmp_f7a_account);

-- 3.3 Clínica do atendimento: vacinação, exames e prescrições
DELETE FROM vaccination       WHERE id IN (SELECT id FROM tmp_f7a_vaccination);
DELETE FROM exam_result       WHERE exam_request_id IN (SELECT id FROM tmp_f7a_exam_request);
DELETE FROM exam_request      WHERE id IN (SELECT id FROM tmp_f7a_exam_request);
DELETE FROM prescription_item WHERE prescription_id IN (SELECT id FROM tmp_f7a_prescription);
DELETE FROM prescription      WHERE id IN (SELECT id FROM tmp_f7a_prescription);

-- 3.4 Atendimento (antes do agendamento: FK encounter.appointment_id → appointment)
DELETE FROM encounter WHERE id IN (SELECT id FROM tmp_f7a_encounter) AND id > 4304;

-- 3.5 Fila e agendamentos (inclui o retorno)
DELETE FROM queue_entry WHERE patient_id     IN (SELECT id FROM tmp_f7a_patient);
DELETE FROM queue_entry WHERE appointment_id IN (SELECT id FROM tmp_f7a_appointment);
DELETE FROM appointment WHERE id IN (SELECT id FROM tmp_f7a_appointment) AND id > 831;

-- 3.6 Pacientes e tutores
DELETE FROM patient WHERE id IN (SELECT id FROM tmp_f7a_patient) AND id > 9180;
DELETE FROM tutor   WHERE id IN (SELECT id FROM tmp_f7a_tutor) AND id > 13876 AND full_name LIKE 'F7A teste%';

-- =============================================================================================
-- 4. Conferência (esperado: 0 em todas as linhas F7A)
-- =============================================================================================
SELECT COUNT(*) AS tutor_f7a_depois       FROM tutor                    WHERE full_name LIKE 'F7A teste%';
SELECT COUNT(*) AS patient_f7a_depois     FROM patient                  WHERE id IN (SELECT id FROM tmp_f7a_patient);
SELECT COUNT(*) AS template_f7a_depois    FROM message_template         WHERE name LIKE 'F7A teste%';
SELECT COUNT(*) AS message_f7a_depois     FROM communication_message    WHERE id IN (SELECT id FROM tmp_f7a_message);
SELECT COUNT(*) AS preference_f7a_depois  FROM communication_preference WHERE tutor_id IN (SELECT id FROM tmp_f7a_tutor);
SELECT COUNT(*) AS followup_f7a_depois    FROM appointment_followup     WHERE id IN (SELECT id FROM tmp_f7a_followup);
SELECT COUNT(*) AS appointment_f7a_depois FROM appointment              WHERE id IN (SELECT id FROM tmp_f7a_appointment);
SELECT COUNT(*) AS encounter_f7a_depois   FROM encounter                WHERE id IN (SELECT id FROM tmp_f7a_encounter);
SELECT COUNT(*) AS account_f7a_depois     FROM encounter_account        WHERE id IN (SELECT id FROM tmp_f7a_account);
SELECT COUNT(*) AS receivable_f7a_depois  FROM receivable               WHERE id IN (SELECT id FROM tmp_f7a_receivable);
SELECT COUNT(*) AS vaccination_f7a_depois FROM vaccination              WHERE id IN (SELECT id FROM tmp_f7a_vaccination);
-- Totais por patient_id dos pacientes F7A (todas 0):
SELECT COUNT(*) AS appointment_por_patient  FROM appointment           WHERE patient_id IN (SELECT id FROM tmp_f7a_patient);
SELECT COUNT(*) AS encounter_por_patient    FROM encounter             WHERE patient_id IN (SELECT id FROM tmp_f7a_patient);
SELECT COUNT(*) AS account_por_patient      FROM encounter_account     WHERE patient_id IN (SELECT id FROM tmp_f7a_patient);
SELECT COUNT(*) AS vaccination_por_patient  FROM vaccination           WHERE patient_id IN (SELECT id FROM tmp_f7a_patient);
SELECT COUNT(*) AS queue_por_patient        FROM queue_entry           WHERE patient_id IN (SELECT id FROM tmp_f7a_patient);
SELECT COUNT(*) AS message_por_patient      FROM communication_message WHERE patient_id IN (SELECT id FROM tmp_f7a_patient);
SELECT COUNT(*) AS exam_por_patient         FROM exam_request          WHERE patient_id IN (SELECT id FROM tmp_f7a_patient);
SELECT COUNT(*) AS prescription_por_patient FROM prescription          WHERE patient_id IN (SELECT id FROM tmp_f7a_patient);
SELECT COUNT(*) AS tutor_total_depois                    FROM tutor;                     -- pré-gate 7
SELECT COUNT(*) AS patient_total_depois                  FROM patient;                   -- pré-gate 7
SELECT COUNT(*) AS appointment_total_depois              FROM appointment;               -- pré-gate 15
SELECT COUNT(*) AS encounter_total_depois                FROM encounter;                 -- pré-gate 7
SELECT COUNT(*) AS encounter_account_total_depois        FROM encounter_account;         -- pré-gate 4
SELECT COUNT(*) AS receivable_total_depois               FROM receivable;                -- pré-gate 3
SELECT COUNT(*) AS payment_total_depois                  FROM payment;                   -- pré-gate 3
SELECT COUNT(*) AS vaccination_total_depois              FROM vaccination;               -- pré-gate 0
SELECT COUNT(*) AS queue_entry_total_depois              FROM queue_entry;               -- pré-gate 4
SELECT COUNT(*) AS system_program_total_depois           FROM system_program;            -- igual ao antes (133)
SELECT COUNT(*) AS communication_message_total_depois    FROM communication_message;     -- pré-gate 0 (mensagens_fora: 0)
SELECT COUNT(*) AS communication_preference_total_depois FROM communication_preference;  -- pré-gate 0
SELECT COUNT(*) AS message_template_total_depois         FROM message_template;          -- pré-gate 0
SELECT COUNT(*) AS appointment_followup_total_depois     FROM appointment_followup;      -- pré-gate 0
SELECT MAX(id) AS tutor_max_depois       FROM tutor;        -- pré-gate 13876
SELECT MAX(id) AS patient_max_depois     FROM patient;      -- pré-gate 9180
SELECT MAX(id) AS appointment_max_depois FROM appointment;  -- pré-gate 831
SELECT MAX(id) AS encounter_max_depois   FROM encounter;    -- pré-gate 4304

-- Confira tudo acima. Se bater, descomente e rode o COMMIT; senão, ROLLBACK.
-- ROLLBACK;
-- COMMIT;

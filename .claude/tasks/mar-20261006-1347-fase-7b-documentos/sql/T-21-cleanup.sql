SET NAMES utf8mb4;
START TRANSACTION;
-- T-21 — Limpeza dos registros de gate "F7B teste" da Fase 7B (banco centralvet)
--
-- NÃO EXECUTAR SEM APROVAÇÃO. Só o orquestrador executa, depois dos gates da Onda 6, com
-- aprovação SQL explícita do usuário (skill sql-write-approval) e backup antes:
--   ./scripts/backup.sh && gzip -t var/backups/<arquivo>.sql.gz
-- Execução interativa (para ler os SELECTs antes de decidir o COMMIT):
--   docker compose exec mysql sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" --default-character-set=utf8mb4 centralvet'
--   mysql> source /caminho/T-21-cleanup.sql
-- O usuário precisa de CREATE TEMPORARY TABLES (as tabelas tmp_f7b_* vivem só na sessão).
-- O COMMIT final está comentado: sem ele, fechar a sessão desfaz tudo. Qualquer erro (FK
-- RESTRICT, CHECK) interrompe o script; rode ROLLBACK e reveja o predicado.
-- Recomendado: ensaio completo terminando em ROLLBACK antes da execução com COMMIT.
--
-- Estado lido em 2026-10-06 14:49 (só SELECT, MySQL 8.0.43), DEPOIS do gate da Onda 4 e ANTES
-- dos gates da Onda 6 (roteiros A e B):
--   tutor 8 (MAX 15447), patient 8 (MAX 13321), encounter 8 (MAX 11105), appointment 15 (MAX 831),
--   vaccination 1 (MAX 2983), prescription 3 (MAX 1677), prescription_item 5 (MAX 2516),
--   surgery 0, stored_object 10 (MAX 2132), generated_document 1, document_template 0,
--   communication_message 0, communication_preference 0, encounter_account 4 (MAX 46),
--   queue_entry 4 (MAX 322), stock_movement 1 (reference_type NULL), audit_log MAX(id) 5927.
--   Pré-gate da Onda 4 (notes.md): generated_document 0, tutor 7/13876, patient 7/9180,
--   vaccination 0, stored_object 9/2131; encounter 7/4304 (depois da limpeza da 7A).
--   Já criados pelo gate da Onda 4 (alvo deste script):
--     tutor 15447 'F7B teste Tutor' (o e-mail de teste é f7b@example.invalid, não f7b.teste@...),
--     patient 13321 'F7B teste Pet', encounter 11105 (sem agendamento), vaccination 2983,
--     generated_document 1 (vaccination_card v1 ready), stored_object 2132
--     (object_key cv/development/tenant/1/objects/documents/1/5ce0f90572413f32.pdf).
--   Criados pelo roteiro A da Onda 6 (reviews/T-21.md § Gate (roteiro A)):
--     generated_document 2..6, stored_object 2133..2137, prescription 6124 (+ item 9108), surgery 4
--     (sala 2, encounter 11105), surgery_room 2 'F7B teste Sala' (code F7BT1), document_template 1,
--     communication_preference 8 (tutor 15447), communication_message 53 (document_ready do doc 3).
--   Criados pelo roteiro B (reviews/T-21.md § Gate (roteiro B) e complemento):
--     generated_document 7, 8 e stored_object 2138, 2139; nenhuma mensagem nem preferência nova.
--   Criados pelo complemento RF4 (reviews/T-21.md § Gate (complemento RF4)): document_template 2
--     (nome com prefixo 'F7B teste' e HTML de teste), generated_document 9 (usa o template 2),
--     stored_object 2140.
--
-- Guardas por id: além do prefixo, as linhas só entram se o id for maior que o MAX(id) pré-gate
-- da Onda 4 (tutor 13876, patient 9180, encounter 4304, appointment 831, stored_object 2131,
-- prescription 1677). generated_document, document_template, surgery e vaccination estavam
-- vazias antes da Onda 4 (vaccination 0 pré-gate).
--
-- audit_log fica de propósito (trilha append-only, sem FK para as linhas apagadas).
--
-- Como os ids são derivados (todos a partir do prefixo 'F7B teste'):
--   tutores      = tutor.full_name LIKE 'F7B teste%'
--   pacientes    = patient.name LIKE 'F7B teste%' ou paciente de tutor F7B
--   documentos   = generated_document de paciente ou tutor F7B
--   objetos      = stored_object apontado por documento F7B ou com object_key
--                  cv/%/tenant/<t>/objects/documents/<id do documento F7B>/% (objeto órfão)
--   templates    = document_template.name LIKE 'F7B teste%'
--   mensagens    = communication_message de tutor/paciente F7B ou com dedupe_key
--                  'document_ready:document:<id do documento F7B>:%'
--   preferências = communication_preference de tutor F7B
--   atendimentos = encounter de paciente F7B
--   agendamentos = appointment de paciente F7B
--   cirurgias    = surgery de paciente ou atendimento F7B (+ checklist/evento/material/equipe)
--   prescrições  = prescription de paciente ou atendimento F7B (+ itens)
--   vacinações   = vaccination de paciente ou atendimento F7B
--   contas       = encounter_account de atendimento, paciente ou tutor F7B (+ itens, recebíveis)
--
-- Ordem das FKs (a do plano): communication_message → communication_preference →
-- generated_document → stored_object → document_template → registros clínicos
-- (surgery_* → surgery → prescription_item → prescription → vaccination → exam_result →
-- exam_request → receivable → encounter_account_item → encounter_account → appointment_followup
-- → encounter → queue_entry → appointment → patient → tutor).
--
-- PARE (ROLLBACK) se: algum SELECT "antes" listar linha que não seja de teste; a lista de payment,
-- stock_movement, financial_entry, procedure_execution, sale ou hospitalization não vier vazia
-- (efeito em estoque/caixa precisa de tratamento à parte); "document_ready_fora" ou
-- "documentos_fora" listarem linhas; ou as linhas afetadas divergirem das contagens "antes".
--
-- Arquivos do volume app_documents (NÃO EXECUTAR aqui; só depois do COMMIT e com confirmação):
-- a seção 2.6 gera os comandos a partir de stored_object.object_key. Conhecido hoje:
--   docker compose exec -T app rm -f -- /var/www/html/var/documents/cv/development/tenant/1/objects/documents/1/5ce0f90572413f32.pdf
--   (stored_object 2133..2137 = documentos 2..6:)
--   docker compose exec -T app rm -f -- /var/www/html/var/documents/cv/development/tenant/1/objects/documents/2/a53f9cf1acc71077.pdf
--   docker compose exec -T app rm -f -- /var/www/html/var/documents/cv/development/tenant/1/objects/documents/3/9e9dcc957d80b516.pdf
--   docker compose exec -T app rm -f -- /var/www/html/var/documents/cv/development/tenant/1/objects/documents/4/6d879fe68e5148c3.pdf
--   docker compose exec -T app rm -f -- /var/www/html/var/documents/cv/development/tenant/1/objects/documents/5/7964861c6c73c40a.pdf
--   docker compose exec -T app rm -f -- /var/www/html/var/documents/cv/development/tenant/1/objects/documents/6/2dfca22f5c5ae841.pdf
--   (stored_object 2138, 2139 = documentos 7, 8, roteiro B:)
--   docker compose exec -T app rm -f -- /var/www/html/var/documents/cv/development/tenant/1/objects/documents/7/cffae9f0f4d1b35a.pdf
--   docker compose exec -T app rm -f -- /var/www/html/var/documents/cv/development/tenant/1/objects/documents/8/68f8e88c8349b74d.pdf
--   (stored_object 2140 = documento 9, complemento RF4:)
--   docker compose exec -T app rm -f -- /var/www/html/var/documents/cv/development/tenant/1/objects/documents/9/799791ae7c047f09.pdf
-- Depois do rm: docker compose exec -T app find /var/www/html/var/documents/cv -type f | wc -l
-- deve bater com o número de stored_object local restantes.

-- Início do gate da Onda 4 (primeiro registro F7B: 14:37:59). Ajuste se o gate começou antes.
SET @gate_start = '2026-10-06 14:30:00';

-- =============================================================================================
-- 1. Contagens antes (só leitura)
-- =============================================================================================
SELECT COUNT(*) AS tutor_total                    FROM tutor;                     -- pré-gate O4: 7 (MAX 13876)
SELECT COUNT(*) AS patient_total                  FROM patient;                   -- pré-gate O4: 7 (MAX 9180)
SELECT COUNT(*) AS encounter_total                FROM encounter;                 -- pré-gate O4: 7 (MAX 4304)
SELECT COUNT(*) AS appointment_total              FROM appointment;               -- 15 (MAX 831)
SELECT COUNT(*) AS vaccination_total              FROM vaccination;               -- pré-gate O4: 0
SELECT COUNT(*) AS prescription_total             FROM prescription;              -- 3 (MAX 1677)
SELECT COUNT(*) AS prescription_item_total        FROM prescription_item;         -- 5 (MAX 2516)
SELECT COUNT(*) AS surgery_total                  FROM surgery;                   -- 0
SELECT COUNT(*) AS surgery_room_total             FROM surgery_room;              -- 0
SELECT COUNT(*) AS stored_object_total            FROM stored_object;             -- pré-gate O4: 9 (MAX 2131)
SELECT COUNT(*) AS generated_document_total       FROM generated_document;        -- pré-gate O4: 0
SELECT COUNT(*) AS document_template_total        FROM document_template;         -- 0
SELECT COUNT(*) AS communication_message_total    FROM communication_message;     -- 0
SELECT COUNT(*) AS communication_preference_total FROM communication_preference;  -- 0
SELECT COUNT(*) AS encounter_account_total        FROM encounter_account;         -- 4 (MAX 46)
SELECT COUNT(*) AS queue_entry_total              FROM queue_entry;               -- 4 (MAX 322)
SELECT COUNT(*) AS stock_movement_total           FROM stock_movement;            -- 1 (não muda)
SELECT COUNT(*) AS payment_total                  FROM payment;                   -- 3 (não muda)
SELECT COUNT(*) AS tutor_f7b    FROM tutor             WHERE full_name LIKE 'F7B teste%';
SELECT COUNT(*) AS patient_f7b  FROM patient           WHERE name LIKE 'F7B teste%';
SELECT COUNT(*) AS template_f7b FROM document_template WHERE name LIKE 'F7B teste%';

-- =============================================================================================
-- 2. Ids derivados do prefixo (tabelas temporárias da sessão; cada uma é lida uma vez por
--    comando, por causa do erro 1137 "Can't reopen table" do MySQL)
-- =============================================================================================
DROP TEMPORARY TABLE IF EXISTS tmp_f7b_tutor, tmp_f7b_patient, tmp_f7b_document, tmp_f7b_object,
    tmp_f7b_template, tmp_f7b_message, tmp_f7b_encounter, tmp_f7b_appointment, tmp_f7b_surgery,
    tmp_f7b_prescription, tmp_f7b_vaccination, tmp_f7b_exam_request, tmp_f7b_account,
    tmp_f7b_receivable, tmp_f7b_followup, tmp_f7b_room;

CREATE TEMPORARY TABLE tmp_f7b_tutor        (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f7b_patient      (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f7b_document     (id BIGINT UNSIGNED PRIMARY KEY, tenant_id BIGINT UNSIGNED NOT NULL);
CREATE TEMPORARY TABLE tmp_f7b_object       (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f7b_template     (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f7b_message      (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f7b_encounter    (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f7b_appointment  (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f7b_surgery      (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f7b_prescription (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f7b_vaccination  (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f7b_exam_request (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f7b_account      (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f7b_receivable   (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f7b_followup     (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f7b_room         (id BIGINT UNSIGNED PRIMARY KEY);

-- 2.1 Cadastros com o prefixo
INSERT INTO tmp_f7b_tutor SELECT id FROM tutor WHERE full_name LIKE 'F7B teste%' AND id > 13876;
INSERT IGNORE INTO tmp_f7b_patient SELECT id FROM patient WHERE name LIKE 'F7B teste%' AND id > 9180;
INSERT IGNORE INTO tmp_f7b_patient SELECT id FROM patient WHERE tutor_id IN (SELECT id FROM tmp_f7b_tutor) AND id > 9180;
INSERT INTO tmp_f7b_template SELECT id FROM document_template WHERE name LIKE 'F7B teste%';
-- sala criada no roteiro A para agendar a cirurgia (surgery_room vazia antes do gate da Onda 4)
INSERT INTO tmp_f7b_room SELECT id FROM surgery_room WHERE name LIKE 'F7B teste%' AND code LIKE 'F7BT%';

-- 2.2 Documentos e objetos
INSERT IGNORE INTO tmp_f7b_document SELECT id, tenant_id FROM generated_document WHERE patient_id IN (SELECT id FROM tmp_f7b_patient);
INSERT IGNORE INTO tmp_f7b_document SELECT id, tenant_id FROM generated_document WHERE tutor_id   IN (SELECT id FROM tmp_f7b_tutor);
INSERT IGNORE INTO tmp_f7b_object SELECT stored_object_id FROM generated_document
 WHERE id IN (SELECT id FROM tmp_f7b_document) AND stored_object_id IS NOT NULL AND stored_object_id > 2131;
-- objetos órfãos (put feito, transação final desfeita) pela chave do documento
INSERT IGNORE INTO tmp_f7b_object SELECT o.id FROM stored_object o JOIN tmp_f7b_document d ON o.tenant_id = d.tenant_id
 WHERE o.id > 2131 AND o.object_key LIKE CONCAT('cv/%/tenant/', d.tenant_id, '/objects/documents/', d.id, '/%');

-- 2.3 Comunicação
INSERT IGNORE INTO tmp_f7b_message SELECT id FROM communication_message WHERE tutor_id   IN (SELECT id FROM tmp_f7b_tutor);
INSERT IGNORE INTO tmp_f7b_message SELECT id FROM communication_message WHERE patient_id IN (SELECT id FROM tmp_f7b_patient);
INSERT IGNORE INTO tmp_f7b_message SELECT c.id FROM communication_message c JOIN tmp_f7b_document d
    ON c.dedupe_key LIKE CONCAT('document_ready:document:', d.id, ':%') AND c.tenant_id = d.tenant_id
 WHERE c.purpose = 'document_ready';

-- 2.4 Clínica e financeiro dos pacientes/tutores F7B
INSERT INTO tmp_f7b_encounter   SELECT id FROM encounter   WHERE patient_id IN (SELECT id FROM tmp_f7b_patient) AND id > 4304;
INSERT INTO tmp_f7b_appointment SELECT id FROM appointment WHERE patient_id IN (SELECT id FROM tmp_f7b_patient) AND id > 831;
INSERT IGNORE INTO tmp_f7b_surgery SELECT id FROM surgery WHERE patient_id   IN (SELECT id FROM tmp_f7b_patient);
INSERT IGNORE INTO tmp_f7b_surgery SELECT id FROM surgery WHERE encounter_id IN (SELECT id FROM tmp_f7b_encounter);
INSERT IGNORE INTO tmp_f7b_prescription SELECT id FROM prescription WHERE patient_id   IN (SELECT id FROM tmp_f7b_patient) AND id > 1677;
INSERT IGNORE INTO tmp_f7b_prescription SELECT id FROM prescription WHERE encounter_id IN (SELECT id FROM tmp_f7b_encounter) AND id > 1677;
INSERT IGNORE INTO tmp_f7b_vaccination SELECT id FROM vaccination WHERE patient_id   IN (SELECT id FROM tmp_f7b_patient);
INSERT IGNORE INTO tmp_f7b_vaccination SELECT id FROM vaccination WHERE encounter_id IN (SELECT id FROM tmp_f7b_encounter);
INSERT IGNORE INTO tmp_f7b_exam_request SELECT id FROM exam_request WHERE patient_id   IN (SELECT id FROM tmp_f7b_patient);
INSERT IGNORE INTO tmp_f7b_exam_request SELECT id FROM exam_request WHERE encounter_id IN (SELECT id FROM tmp_f7b_encounter);
INSERT IGNORE INTO tmp_f7b_account SELECT id FROM encounter_account WHERE encounter_id IN (SELECT id FROM tmp_f7b_encounter);
INSERT IGNORE INTO tmp_f7b_account SELECT id FROM encounter_account WHERE patient_id   IN (SELECT id FROM tmp_f7b_patient);
INSERT IGNORE INTO tmp_f7b_account SELECT id FROM encounter_account WHERE tutor_id     IN (SELECT id FROM tmp_f7b_tutor);
INSERT IGNORE INTO tmp_f7b_receivable SELECT id FROM receivable WHERE encounter_account_id IN (SELECT id FROM tmp_f7b_account);
INSERT IGNORE INTO tmp_f7b_receivable SELECT id FROM receivable WHERE tutor_id             IN (SELECT id FROM tmp_f7b_tutor);
INSERT IGNORE INTO tmp_f7b_followup SELECT id FROM appointment_followup WHERE appointment_id IN (SELECT id FROM tmp_f7b_appointment);
INSERT IGNORE INTO tmp_f7b_followup SELECT id FROM appointment_followup WHERE encounter_id   IN (SELECT id FROM tmp_f7b_encounter);

-- 2.5 Revisão das linhas que serão apagadas (antes). Sem e-mail, telefone, corpo nem texto.
SELECT 'tutor' AS t, u.id, u.full_name, u.created_at FROM tutor u WHERE u.id IN (SELECT id FROM tmp_f7b_tutor);
-- ^ tutores com e-mail de teste F7B e nome fora do prefixo (deve ser vazio; se listar, PARE):
SELECT 'tutor_email_fora' AS t, u.id, u.full_name FROM tutor u
 WHERE u.email LIKE 'f7b%@example.invalid' AND u.full_name NOT LIKE 'F7B teste%';
SELECT 'patient' AS t, p.id, p.name, p.tutor_id FROM patient p WHERE p.id IN (SELECT id FROM tmp_f7b_patient);
SELECT 'document' AS t, g.id, g.tenant_id, g.kind, g.source_type, g.source_id, g.patient_id, g.version, g.status,
       g.stored_object_id, g.template_id, g.notify_tutor, g.created_at
  FROM generated_document g WHERE g.id IN (SELECT id FROM tmp_f7b_document);
-- ^ documentos criados nos gates fora do prefixo (deve ser vazio; se listar, PARE):
SELECT 'documentos_fora' AS t, g.id, g.kind, g.patient_id, g.status, g.created_at FROM generated_document g
 WHERE g.id NOT IN (SELECT id FROM tmp_f7b_document) AND g.created_at >= @gate_start;
SELECT 'object' AS t, o.id, o.storage_provider, o.object_key, o.original_name, o.status, o.created_at
  FROM stored_object o WHERE o.id IN (SELECT id FROM tmp_f7b_object);
-- ^ objetos criados nos gates fora da lista (anexos de outra tela? decidir à parte; não apagados):
SELECT 'objetos_fora' AS t, o.id, o.storage_provider, o.original_name, o.created_at FROM stored_object o
 WHERE o.id NOT IN (SELECT id FROM tmp_f7b_object) AND o.created_at >= @gate_start;
SELECT 'template' AS t, m.id, m.kind, m.name, m.status FROM document_template m WHERE m.id IN (SELECT id FROM tmp_f7b_template);
-- ^ documentos de outros pacientes que usam template F7B (deve ser vazio; a FK interrompe o DELETE):
SELECT 'template_em_uso_fora' AS t, g.id, g.patient_id FROM generated_document g
 WHERE g.template_id IN (SELECT id FROM tmp_f7b_template) AND g.id NOT IN (SELECT id FROM tmp_f7b_document);
SELECT 'message' AS t, c.id, c.tutor_id, c.patient_id, c.purpose, c.channel, c.legal_basis, c.origin, c.status, c.dedupe_key, c.created_at
  FROM communication_message c WHERE c.id IN (SELECT id FROM tmp_f7b_message);
-- ^ avisos document_ready criados no gate fora da lista (deve ser vazio; se listar, PARE):
SELECT 'document_ready_fora' AS t, c.id, c.tutor_id, c.channel, c.status, c.created_at FROM communication_message c
 WHERE c.id NOT IN (SELECT id FROM tmp_f7b_message) AND c.purpose = 'document_ready';
-- ^ outras mensagens criadas no gate fora da lista (agendador; ficam, decisão à parte):
SELECT 'mensagens_fora' AS t, c.id, c.tutor_id, c.purpose, c.channel, c.status, c.created_at FROM communication_message c
 WHERE c.id NOT IN (SELECT id FROM tmp_f7b_message) AND c.created_at >= @gate_start;
SELECT 'preference' AS t, p.id, p.tutor_id, p.channel, p.status FROM communication_preference p
 WHERE p.tutor_id IN (SELECT id FROM tmp_f7b_tutor);
SELECT 'surgery' AS t, s.id, s.patient_id, s.encounter_id, s.status, s.followup_appointment_id, s.consent_recorded_at
  FROM surgery s WHERE s.id IN (SELECT id FROM tmp_f7b_surgery);
SELECT 'room' AS t, s.id, s.code, s.name, s.status FROM surgery_room s WHERE s.id IN (SELECT id FROM tmp_f7b_room);
-- ^ cirurgias fora do prefixo usando sala F7B (deve ser vazio; a FK surgery.room_id interrompe o DELETE):
SELECT 'sala_em_uso_fora' AS t, s.id, s.patient_id FROM surgery s
 WHERE s.room_id IN (SELECT id FROM tmp_f7b_room) AND s.id NOT IN (SELECT id FROM tmp_f7b_surgery);
SELECT 'prescription' AS t, r.id, r.patient_id, r.encounter_id FROM prescription r WHERE r.id IN (SELECT id FROM tmp_f7b_prescription);
SELECT 'vaccination' AS t, v.id, v.patient_id, v.encounter_id FROM vaccination v WHERE v.id IN (SELECT id FROM tmp_f7b_vaccination);
SELECT 'encounter' AS t, e.id, e.patient_id, e.appointment_id, e.status FROM encounter e WHERE e.id IN (SELECT id FROM tmp_f7b_encounter);
SELECT 'appointment' AS t, a.id, a.patient_id, a.scheduled_at, a.status FROM appointment a WHERE a.id IN (SELECT id FROM tmp_f7b_appointment);
SELECT 'account' AS t, a.id, a.encounter_id, a.status, a.total_cents FROM encounter_account a WHERE a.id IN (SELECT id FROM tmp_f7b_account);
SELECT 'receivable' AS t, r.id, r.encounter_account_id, r.status, r.total_cents, r.paid_cents FROM receivable r WHERE r.id IN (SELECT id FROM tmp_f7b_receivable);
SELECT COUNT(*) AS exam_requests FROM tmp_f7b_exam_request;
SELECT COUNT(*) AS followups     FROM tmp_f7b_followup;
-- ^ dependentes com efeito em estoque/caixa ou não esperados (todos 0; se não, PARE):
SELECT COUNT(*) AS payments             FROM payment             WHERE receivable_id IN (SELECT id FROM tmp_f7b_receivable);
SELECT COUNT(*) AS surgery_materials    FROM surgery_material    WHERE surgery_id    IN (SELECT id FROM tmp_f7b_surgery);
SELECT COUNT(*) AS stock_movements      FROM stock_movement      WHERE created_at >= @gate_start;
SELECT COUNT(*) AS financial_entries    FROM financial_entry     WHERE created_at >= @gate_start;
SELECT COUNT(*) AS procedure_executions FROM procedure_execution WHERE patient_id    IN (SELECT id FROM tmp_f7b_patient);
SELECT COUNT(*) AS sales                FROM sale                WHERE patient_id    IN (SELECT id FROM tmp_f7b_patient);
SELECT COUNT(*) AS hospitalizations     FROM hospitalization     WHERE patient_id    IN (SELECT id FROM tmp_f7b_patient);

-- 2.6 Comandos de remoção dos arquivos do volume (gerados aqui, executados só depois do COMMIT,
--     com confirmação; NÃO executar nesta sessão)
SELECT CONCAT('docker compose exec -T app rm -f -- /var/www/html/var/documents/', o.object_key) AS rm_cmd
  FROM stored_object o WHERE o.id IN (SELECT id FROM tmp_f7b_object) AND o.storage_provider = 'local';

-- 2.7 Conferência das listas derivadas contra os ids do gate (confere = 1 em todas; se alguma
--     vier 0, PARE). Onda 4 + roteiros A, B e complemento RF4 da Onda 6 (conferidos por SELECT em 2026-10-06).
SELECT 'tutor'   AS t, GROUP_CONCAT(id ORDER BY id) AS ids, GROUP_CONCAT(id ORDER BY id) = '15447' AS confere FROM tmp_f7b_tutor;
SELECT 'patient' AS t, GROUP_CONCAT(id ORDER BY id) AS ids, GROUP_CONCAT(id ORDER BY id) = '13321' AS confere FROM tmp_f7b_patient;
SELECT 'document' AS t, GROUP_CONCAT(id ORDER BY id) AS ids, GROUP_CONCAT(id ORDER BY id) = '1,2,3,4,5,6,7,8,9' AS confere FROM tmp_f7b_document;
SELECT 'object'  AS t, GROUP_CONCAT(id ORDER BY id) AS ids, GROUP_CONCAT(id ORDER BY id) = '2132,2133,2134,2135,2136,2137,2138,2139,2140' AS confere FROM tmp_f7b_object;
SELECT 'template' AS t, GROUP_CONCAT(id ORDER BY id) AS ids, GROUP_CONCAT(id ORDER BY id) = '1,2' AS confere FROM tmp_f7b_template;
SELECT 'message' AS t, GROUP_CONCAT(id ORDER BY id) AS ids, GROUP_CONCAT(id ORDER BY id) = '53' AS confere FROM tmp_f7b_message;
SELECT 'preference' AS t, GROUP_CONCAT(id ORDER BY id) AS ids, GROUP_CONCAT(id ORDER BY id) = '8' AS confere
  FROM communication_preference WHERE tutor_id IN (SELECT id FROM tmp_f7b_tutor);
SELECT 'encounter' AS t, GROUP_CONCAT(id ORDER BY id) AS ids, GROUP_CONCAT(id ORDER BY id) = '11105' AS confere FROM tmp_f7b_encounter;
SELECT 'vaccination' AS t, GROUP_CONCAT(id ORDER BY id) AS ids, GROUP_CONCAT(id ORDER BY id) = '2983' AS confere FROM tmp_f7b_vaccination;
SELECT 'surgery' AS t, GROUP_CONCAT(id ORDER BY id) AS ids, GROUP_CONCAT(id ORDER BY id) = '4' AS confere FROM tmp_f7b_surgery;
SELECT 'room'    AS t, GROUP_CONCAT(id ORDER BY id) AS ids, GROUP_CONCAT(id ORDER BY id) = '2' AS confere FROM tmp_f7b_room;
SELECT 'prescription' AS t, GROUP_CONCAT(id ORDER BY id) AS ids, GROUP_CONCAT(id ORDER BY id) = '6124' AS confere FROM tmp_f7b_prescription;
SELECT 'prescription_item' AS t, GROUP_CONCAT(id ORDER BY id) AS ids, GROUP_CONCAT(id ORDER BY id) = '9108' AS confere
  FROM prescription_item WHERE prescription_id IN (SELECT id FROM tmp_f7b_prescription);
-- ^ vazias esperadas (1 = lista vazia): agendamento, conta, recebível, exame, retorno
SELECT (SELECT COUNT(*) FROM tmp_f7b_appointment) = 0  AS appointment_vazio;
SELECT (SELECT COUNT(*) FROM tmp_f7b_account) = 0      AS account_vazio;
SELECT (SELECT COUNT(*) FROM tmp_f7b_receivable) = 0   AS receivable_vazio;
SELECT (SELECT COUNT(*) FROM tmp_f7b_exam_request) = 0 AS exam_request_vazio;
SELECT (SELECT COUNT(*) FROM tmp_f7b_followup) = 0     AS followup_vazio;

-- =============================================================================================
-- 3. Limpeza (ordem das FKs). Toda instrução tem WHERE por lista derivada e guarda.
-- =============================================================================================

-- 3.1 Comunicação: aviso document_ready e demais mensagens F7B → preferências do tutor F7B
DELETE FROM communication_message    WHERE id IN (SELECT id FROM tmp_f7b_message);
DELETE FROM communication_preference WHERE tutor_id IN (SELECT id FROM tmp_f7b_tutor);

-- 3.2 Documentos: generated_document (FK stored_object_id, template_id) → stored_object → template
DELETE FROM generated_document WHERE id IN (SELECT id FROM tmp_f7b_document);
DELETE FROM stored_object      WHERE id IN (SELECT id FROM tmp_f7b_object) AND id > 2131;
DELETE FROM document_template  WHERE id IN (SELECT id FROM tmp_f7b_template) AND name LIKE 'F7B teste%';

-- 3.3 Cirurgia (filhas → cirurgia; antes do atendimento e do agendamento de retorno)
DELETE FROM surgery_checklist WHERE surgery_id IN (SELECT id FROM tmp_f7b_surgery);
DELETE FROM surgery_event     WHERE surgery_id IN (SELECT id FROM tmp_f7b_surgery);
DELETE FROM surgery_material  WHERE surgery_id IN (SELECT id FROM tmp_f7b_surgery);
DELETE FROM surgery_team      WHERE surgery_id IN (SELECT id FROM tmp_f7b_surgery);
DELETE FROM surgery           WHERE id IN (SELECT id FROM tmp_f7b_surgery);
-- sala F7B (FK surgery.room_id → surgery_room: só depois das cirurgias)
DELETE FROM surgery_room      WHERE id IN (SELECT id FROM tmp_f7b_room) AND name LIKE 'F7B teste%';

-- 3.4 Receita, vacinação e exames
DELETE FROM prescription_item WHERE prescription_id IN (SELECT id FROM tmp_f7b_prescription);
DELETE FROM prescription      WHERE id IN (SELECT id FROM tmp_f7b_prescription) AND id > 1677;
DELETE FROM vaccination       WHERE id IN (SELECT id FROM tmp_f7b_vaccination);
DELETE FROM exam_result       WHERE exam_request_id IN (SELECT id FROM tmp_f7b_exam_request);
DELETE FROM exam_request      WHERE id IN (SELECT id FROM tmp_f7b_exam_request);

-- 3.5 Financeiro do atendimento (payment não é apagado: a FK interrompe se existir)
DELETE FROM receivable             WHERE id IN (SELECT id FROM tmp_f7b_receivable);
DELETE FROM encounter_account_item WHERE account_id IN (SELECT id FROM tmp_f7b_account);
DELETE FROM encounter_account      WHERE id IN (SELECT id FROM tmp_f7b_account);

-- 3.6 Atendimento, fila e agendamentos
DELETE FROM appointment_followup WHERE id IN (SELECT id FROM tmp_f7b_followup);
DELETE FROM encounter            WHERE id IN (SELECT id FROM tmp_f7b_encounter) AND id > 4304;
DELETE FROM queue_entry          WHERE patient_id     IN (SELECT id FROM tmp_f7b_patient);
DELETE FROM queue_entry          WHERE appointment_id IN (SELECT id FROM tmp_f7b_appointment);
DELETE FROM appointment          WHERE id IN (SELECT id FROM tmp_f7b_appointment) AND id > 831;

-- 3.7 Pacientes e tutores
DELETE FROM patient WHERE id IN (SELECT id FROM tmp_f7b_patient) AND id > 9180;
DELETE FROM tutor   WHERE id IN (SELECT id FROM tmp_f7b_tutor) AND id > 13876 AND full_name LIKE 'F7B teste%';

-- =============================================================================================
-- 4. Conferência (esperado: 0 em todas as linhas F7B; totais iguais ao pré-gate da Onda 4)
-- =============================================================================================
SELECT COUNT(*) AS tutor_f7b_depois        FROM tutor                    WHERE full_name LIKE 'F7B teste%';
SELECT COUNT(*) AS patient_f7b_depois      FROM patient                  WHERE id IN (SELECT id FROM tmp_f7b_patient);
SELECT COUNT(*) AS document_f7b_depois     FROM generated_document       WHERE id IN (SELECT id FROM tmp_f7b_document);
SELECT COUNT(*) AS object_f7b_depois       FROM stored_object            WHERE id IN (SELECT id FROM tmp_f7b_object);
SELECT COUNT(*) AS template_f7b_depois     FROM document_template        WHERE name LIKE 'F7B teste%';
SELECT COUNT(*) AS message_f7b_depois      FROM communication_message    WHERE id IN (SELECT id FROM tmp_f7b_message);
SELECT COUNT(*) AS preference_f7b_depois   FROM communication_preference WHERE tutor_id IN (SELECT id FROM tmp_f7b_tutor);
SELECT COUNT(*) AS surgery_f7b_depois      FROM surgery                  WHERE id IN (SELECT id FROM tmp_f7b_surgery);
SELECT COUNT(*) AS room_f7b_depois         FROM surgery_room             WHERE id IN (SELECT id FROM tmp_f7b_room);
SELECT COUNT(*) AS prescription_f7b_depois FROM prescription             WHERE id IN (SELECT id FROM tmp_f7b_prescription);
SELECT COUNT(*) AS vaccination_f7b_depois  FROM vaccination              WHERE id IN (SELECT id FROM tmp_f7b_vaccination);
SELECT COUNT(*) AS encounter_f7b_depois    FROM encounter                WHERE id IN (SELECT id FROM tmp_f7b_encounter);
SELECT COUNT(*) AS appointment_f7b_depois  FROM appointment              WHERE id IN (SELECT id FROM tmp_f7b_appointment);
SELECT COUNT(*) AS tutor_total_depois                 FROM tutor;                 -- pré-gate O4: 7
SELECT COUNT(*) AS patient_total_depois               FROM patient;               -- pré-gate O4: 7
SELECT COUNT(*) AS encounter_total_depois             FROM encounter;             -- pré-gate O4: 7
SELECT COUNT(*) AS appointment_total_depois           FROM appointment;           -- 15
SELECT COUNT(*) AS vaccination_total_depois           FROM vaccination;           -- pré-gate O4: 0
SELECT COUNT(*) AS prescription_total_depois          FROM prescription;          -- 3
SELECT COUNT(*) AS surgery_total_depois               FROM surgery;               -- 0
SELECT COUNT(*) AS surgery_room_total_depois          FROM surgery_room;          -- 0
SELECT COUNT(*) AS prescription_item_total_depois     FROM prescription_item;     -- 5 (MAX 2516)
SELECT COUNT(*) AS stored_object_total_depois         FROM stored_object;         -- pré-gate O4: 9 (+ objetos_fora)
SELECT COUNT(*) AS generated_document_total_depois    FROM generated_document;    -- 0
SELECT COUNT(*) AS document_template_total_depois     FROM document_template;     -- 0
SELECT COUNT(*) AS communication_message_total_depois FROM communication_message; -- 0 (+ mensagens_fora)
SELECT COUNT(*) AS communication_preference_total_depois FROM communication_preference; -- 0
SELECT COUNT(*) AS payment_total_depois               FROM payment;               -- 3
SELECT MAX(id) AS tutor_max_depois         FROM tutor;          -- 13876
SELECT MAX(id) AS patient_max_depois       FROM patient;        -- 9180
SELECT MAX(id) AS stored_object_max_depois FROM stored_object;  -- 2131 (se objetos_fora vazio)

-- Confira tudo acima. Se bater, descomente e rode o COMMIT; senão, ROLLBACK.
-- Depois do COMMIT, rode os comandos rm da seção 2.6 (com confirmação).
-- ROLLBACK;
-- COMMIT;

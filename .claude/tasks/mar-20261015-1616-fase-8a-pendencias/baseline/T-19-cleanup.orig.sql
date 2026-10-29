SET NAMES utf8mb4;
START TRANSACTION;
-- T-19 — Limpeza dos registros de gate "F8A teste" da Fase 8A (banco centralvet, tenant 1)
--
-- NÃO EXECUTAR SEM APROVAÇÃO. Só o orquestrador executa, depois dos gates/verificações manuais,
-- com aprovação SQL explícita do usuário (skill sql-write-approval) e backup antes:
--   ./scripts/backup.sh && gzip -t var/backups/<arquivo>.sql.gz
-- Execução interativa (para ler os SELECTs antes de decidir o COMMIT):
--   docker compose exec mysql sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" --default-character-set=utf8mb4 centralvet'
--   mysql> source /caminho/T-19-cleanup.sql
-- O usuário precisa de CREATE TEMPORARY TABLES (as tabelas tmp_f8a_* vivem só na sessão).
-- O COMMIT final está comentado: sem ele, fechar a sessão desfaz tudo. No `source` interativo o
-- cliente mysql NÃO para no erro (FK RESTRICT, CHECK); segue para o comando seguinte na mesma
-- transação. Execute em duas etapas:
--   1) `source` do script inteiro e procure "ERROR" na saída; com qualquer ERROR, ROLLBACK;
--      sem erro, confira os SELECTs de conferência (colunas `confere`/`*_vazio` = 1).
--   2) Só então digite COMMIT; à mão, na mesma sessão (ou ROLLBACK; se algo não bater).
-- Recomendado: ensaio completo terminando em ROLLBACK antes da execução com COMMIT.
--
-- Estado lido em 2026-10-07 16:48 (só SELECT, MySQL 8.0.43), ANTES dos gates da Onda 6
-- (= estado pré-gate da Onda 4 anotado em notes.md § Decisões):
--   tutor 7 (MAX 13876), patient 7 (MAX 9180), encounter 7 (MAX 4304), exam_request 3 (MAX 312),
--   exam_result 3 (MAX 3), appointment 15 (MAX 831), queue_entry 4 (MAX 322),
--   prescription 3 (MAX 1677), prescription_item 5 (MAX 2516), encounter_account 4 (MAX 46),
--   receivable 3 (MAX 42), sale 2 (MAX 1715), stored_object 9 (MAX 2131), vaccination 0,
--   appointment_followup 0, surgery 0, hospitalization 0, procedure_execution 0,
--   generated_document 0, communication_message 0, communication_preference 0,
--   ai_interaction 0, feature_flag 0, ai_tenant_setting 0, audit_log 1155 (MAX 6090).
--   Nenhum paciente/tutor "F8A teste" existia.
-- Estado de feature_flag/ai_tenant_setting antes do gate (notes.md § Bloqueios + leitura acima):
--   nenhuma linha em nenhum tenant → a limpeza remove a flag `ai_assistant` e o setting do tenant 1.
--   Se o usuário decidir manter a IA ligada em dev, comente os dois DELETEs da seção 5.
-- audit_log fica (sem FK; registro de auditoria). O smoke de CLI (T-19-gateway-smoke.php) não grava
-- no banco; ai_interaction de qualquer chamada real feita pela tela entra pelos sujeitos F8A abaixo.
-- Se os ids/contagens de conferência não baterem (gate gerou mais registros), revise os literais
-- da seção 4 antes do COMMIT.

SET @tenant = 1;
SET @gate_start = '2026-10-07 16:45:00';

-- 1) Contagens ANTES -------------------------------------------------------------------------
SELECT COUNT(*) AS tutor_total             FROM tutor;              -- pré-gate: 7 (MAX 13876)
SELECT COUNT(*) AS patient_total           FROM patient;            -- pré-gate: 7 (MAX 9180)
SELECT COUNT(*) AS encounter_total         FROM encounter;          -- pré-gate: 7 (MAX 4304)
SELECT COUNT(*) AS exam_request_total      FROM exam_request;       -- pré-gate: 3 (MAX 312)
SELECT COUNT(*) AS exam_result_total       FROM exam_result;        -- pré-gate: 3 (MAX 3)
SELECT COUNT(*) AS appointment_total       FROM appointment;        -- pré-gate: 15 (MAX 831)
SELECT COUNT(*) AS queue_entry_total       FROM queue_entry;        -- pré-gate: 4 (MAX 322)
SELECT COUNT(*) AS prescription_total      FROM prescription;       -- pré-gate: 3 (MAX 1677)
SELECT COUNT(*) AS prescription_item_total FROM prescription_item;  -- pré-gate: 5 (MAX 2516)
SELECT COUNT(*) AS encounter_account_total FROM encounter_account;  -- pré-gate: 4 (MAX 46)
SELECT COUNT(*) AS ai_interaction_total    FROM ai_interaction;     -- pré-gate: 0
SELECT COUNT(*) AS feature_flag_total      FROM feature_flag;       -- pré-gate: 0
SELECT COUNT(*) AS ai_tenant_setting_total FROM ai_tenant_setting;  -- pré-gate: 0
SELECT COUNT(*) AS audit_log_total         FROM audit_log;          -- pré-gate: 1155 (só cresce)
SELECT COUNT(*) AS tutor_f8a   FROM tutor   WHERE tenant_id = @tenant AND full_name LIKE 'F8A teste%';
SELECT COUNT(*) AS patient_f8a FROM patient WHERE tenant_id = @tenant AND name      LIKE 'F8A teste%';

-- 2) Ids F8A em tabelas temporárias -------------------------------------------------------------
DROP TEMPORARY TABLE IF EXISTS tmp_f8a_tutor, tmp_f8a_patient, tmp_f8a_encounter, tmp_f8a_appointment,
    tmp_f8a_queue, tmp_f8a_exam_request, tmp_f8a_exam_result, tmp_f8a_prescription, tmp_f8a_ai;

CREATE TEMPORARY TABLE tmp_f8a_tutor        (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f8a_patient      (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f8a_encounter    (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f8a_appointment  (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f8a_queue        (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f8a_exam_request (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f8a_exam_result  (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f8a_prescription (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f8a_ai           (id BIGINT UNSIGNED PRIMARY KEY);

INSERT INTO tmp_f8a_tutor SELECT id FROM tutor
 WHERE tenant_id = @tenant AND full_name LIKE 'F8A teste%' AND id > 13876;
INSERT IGNORE INTO tmp_f8a_patient SELECT id FROM patient
 WHERE tenant_id = @tenant AND name LIKE 'F8A teste%' AND id > 9180;
INSERT IGNORE INTO tmp_f8a_patient SELECT id FROM patient
 WHERE tenant_id = @tenant AND tutor_id IN (SELECT id FROM tmp_f8a_tutor) AND id > 9180;

INSERT INTO tmp_f8a_encounter SELECT id FROM encounter
 WHERE tenant_id = @tenant AND patient_id IN (SELECT id FROM tmp_f8a_patient) AND id > 4304;
INSERT INTO tmp_f8a_appointment SELECT id FROM appointment
 WHERE tenant_id = @tenant AND patient_id IN (SELECT id FROM tmp_f8a_patient) AND id > 831;
INSERT INTO tmp_f8a_queue SELECT id FROM queue_entry
 WHERE tenant_id = @tenant AND patient_id IN (SELECT id FROM tmp_f8a_patient) AND id > 322;
INSERT IGNORE INTO tmp_f8a_exam_request SELECT id FROM exam_request
 WHERE tenant_id = @tenant AND patient_id IN (SELECT id FROM tmp_f8a_patient) AND id > 312;
INSERT IGNORE INTO tmp_f8a_exam_request SELECT id FROM exam_request
 WHERE tenant_id = @tenant AND encounter_id IN (SELECT id FROM tmp_f8a_encounter) AND id > 312;
INSERT INTO tmp_f8a_exam_result SELECT id FROM exam_result
 WHERE tenant_id = @tenant AND exam_request_id IN (SELECT id FROM tmp_f8a_exam_request) AND id > 3;
INSERT IGNORE INTO tmp_f8a_prescription SELECT id FROM prescription
 WHERE tenant_id = @tenant AND patient_id IN (SELECT id FROM tmp_f8a_patient) AND id > 1677;
INSERT IGNORE INTO tmp_f8a_prescription SELECT id FROM prescription
 WHERE tenant_id = @tenant AND encounter_id IN (SELECT id FROM tmp_f8a_encounter) AND id > 1677;

INSERT IGNORE INTO tmp_f8a_ai SELECT id FROM ai_interaction
 WHERE tenant_id = @tenant AND subject_type = 'encounter'   AND subject_id IN (SELECT id FROM tmp_f8a_encounter);
INSERT IGNORE INTO tmp_f8a_ai SELECT id FROM ai_interaction
 WHERE tenant_id = @tenant AND subject_type = 'exam_result' AND subject_id IN (SELECT id FROM tmp_f8a_exam_result);

-- 3) Conferência (só leitura; nenhum texto clínico, sugestão ou PII nas colunas) ------------------
SELECT 'tutor' AS t, u.id, u.created_at FROM tutor u WHERE u.id IN (SELECT id FROM tmp_f8a_tutor);
SELECT 'tutor_email_fora' AS t, u.id FROM tutor u
 WHERE u.email LIKE 'f8a%@example.invalid' AND u.id NOT IN (SELECT id FROM tmp_f8a_tutor);
SELECT 'patient' AS t, p.id, p.tutor_id, p.created_at FROM patient p WHERE p.id IN (SELECT id FROM tmp_f8a_patient);
SELECT 'encounter' AS t, e.id, e.patient_id, e.appointment_id, e.status FROM encounter e
 WHERE e.id IN (SELECT id FROM tmp_f8a_encounter);
SELECT 'appointment' AS t, a.id, a.patient_id, a.status FROM appointment a WHERE a.id IN (SELECT id FROM tmp_f8a_appointment);
SELECT 'queue_entry' AS t, q.id, q.patient_id, q.appointment_id FROM queue_entry q WHERE q.id IN (SELECT id FROM tmp_f8a_queue);
SELECT 'exam_request' AS t, r.id, r.patient_id, r.encounter_id, r.status FROM exam_request r
 WHERE r.id IN (SELECT id FROM tmp_f8a_exam_request);
SELECT 'exam_result' AS t, x.id, x.exam_request_id, x.stored_object_key IS NOT NULL AS tem_anexo FROM exam_result x
 WHERE x.id IN (SELECT id FROM tmp_f8a_exam_result);
SELECT 'prescription' AS t, r.id, r.patient_id, r.encounter_id FROM prescription r WHERE r.id IN (SELECT id FROM tmp_f8a_prescription);
SELECT 'ai_interaction' AS t, i.id, i.purpose, i.subject_type, i.subject_id, i.provider, i.model, i.status,
       i.input_tokens, i.output_tokens, i.cost_micro_usd, i.error_code, i.created_at
  FROM ai_interaction i WHERE i.id IN (SELECT id FROM tmp_f8a_ai);
SELECT 'ai_fora' AS t, i.id, i.tenant_id, i.purpose, i.subject_type, i.subject_id, i.provider, i.status, i.created_at
  FROM ai_interaction i WHERE i.id NOT IN (SELECT id FROM tmp_f8a_ai);          -- esperado: vazio
SELECT 'encounters_fora' AS t, e.id, e.patient_id FROM encounter e
 WHERE e.id > 4304 AND e.id NOT IN (SELECT id FROM tmp_f8a_encounter);           -- esperado: vazio
SELECT 'exam_results_fora' AS t, x.id, x.exam_request_id FROM exam_result x
 WHERE x.id > 3 AND x.id NOT IN (SELECT id FROM tmp_f8a_exam_result);             -- esperado: vazio
SELECT 'feature_flag' AS t, f.id, f.tenant_id, f.flag_key, f.enabled, f.updated_at FROM feature_flag f;
SELECT 'ai_tenant_setting' AS t, s.tenant_id, s.terms_version, s.monthly_token_quota, s.updated_at FROM ai_tenant_setting s;

-- Dependentes que o gate da 8A não deve criar; qualquer valor > 0 = ROLLBACK e revisar o predicado.
SELECT (SELECT COUNT(*) FROM encounter_account    WHERE encounter_id IN (SELECT id FROM tmp_f8a_encounter)
                                                     OR patient_id   IN (SELECT id FROM tmp_f8a_patient)
                                                     OR tutor_id     IN (SELECT id FROM tmp_f8a_tutor))  = 0 AS account_vazio;
SELECT (SELECT COUNT(*) FROM vaccination          WHERE patient_id   IN (SELECT id FROM tmp_f8a_patient)) = 0 AS vaccination_vazio;
SELECT (SELECT COUNT(*) FROM appointment_followup WHERE encounter_id IN (SELECT id FROM tmp_f8a_encounter)
                                                     OR appointment_id IN (SELECT id FROM tmp_f8a_appointment)) = 0 AS followup_vazio;
SELECT (SELECT COUNT(*) FROM surgery              WHERE patient_id   IN (SELECT id FROM tmp_f8a_patient)) = 0 AS surgery_vazio;
SELECT (SELECT COUNT(*) FROM hospitalization      WHERE patient_id   IN (SELECT id FROM tmp_f8a_patient)) = 0 AS hospitalization_vazio;
SELECT (SELECT COUNT(*) FROM procedure_execution  WHERE patient_id   IN (SELECT id FROM tmp_f8a_patient)) = 0 AS procedure_vazio;
SELECT (SELECT COUNT(*) FROM sale                 WHERE patient_id   IN (SELECT id FROM tmp_f8a_patient)
                                                     OR tutor_id     IN (SELECT id FROM tmp_f8a_tutor))  = 0 AS sale_vazio;
SELECT (SELECT COUNT(*) FROM generated_document   WHERE patient_id   IN (SELECT id FROM tmp_f8a_patient)
                                                     OR tutor_id     IN (SELECT id FROM tmp_f8a_tutor))  = 0 AS document_vazio;
SELECT (SELECT COUNT(*) FROM communication_message WHERE patient_id  IN (SELECT id FROM tmp_f8a_patient)
                                                     OR tutor_id     IN (SELECT id FROM tmp_f8a_tutor))  = 0 AS message_vazio;
SELECT (SELECT COUNT(*) FROM communication_preference WHERE tutor_id IN (SELECT id FROM tmp_f8a_tutor)) = 0 AS preference_vazio;
SELECT (SELECT COUNT(*) FROM receivable           WHERE tutor_id     IN (SELECT id FROM tmp_f8a_tutor))  = 0 AS receivable_vazio;

-- Anexos de resultado de exame (se houver): apagar o objeto no storage à parte, depois do COMMIT.
SELECT 'anexo_exame' AS t, x.id, x.stored_object_key FROM exam_result x
 WHERE x.id IN (SELECT id FROM tmp_f8a_exam_result) AND x.stored_object_key IS NOT NULL;

-- 4) Conferência dos ids esperados: preencher os literais com os ids do gate antes do COMMIT.
--    (Sem gate pela tela nesta execução, o esperado é tudo vazio: '' em cada GROUP_CONCAT.)
SELECT 'tutor'        AS t, IFNULL(GROUP_CONCAT(id ORDER BY id), '') AS ids FROM tmp_f8a_tutor;
SELECT 'patient'      AS t, IFNULL(GROUP_CONCAT(id ORDER BY id), '') AS ids FROM tmp_f8a_patient;
SELECT 'encounter'    AS t, IFNULL(GROUP_CONCAT(id ORDER BY id), '') AS ids FROM tmp_f8a_encounter;
SELECT 'appointment'  AS t, IFNULL(GROUP_CONCAT(id ORDER BY id), '') AS ids FROM tmp_f8a_appointment;
SELECT 'queue_entry'  AS t, IFNULL(GROUP_CONCAT(id ORDER BY id), '') AS ids FROM tmp_f8a_queue;
SELECT 'exam_request' AS t, IFNULL(GROUP_CONCAT(id ORDER BY id), '') AS ids FROM tmp_f8a_exam_request;
SELECT 'exam_result'  AS t, IFNULL(GROUP_CONCAT(id ORDER BY id), '') AS ids FROM tmp_f8a_exam_result;
SELECT 'prescription' AS t, IFNULL(GROUP_CONCAT(id ORDER BY id), '') AS ids FROM tmp_f8a_prescription;
SELECT 'ai'           AS t, IFNULL(GROUP_CONCAT(id ORDER BY id), '') AS ids FROM tmp_f8a_ai;

-- 5) Remoção na ordem das FKs (todas com tenant 1 e ids de tmp_f8a_*) ---------------------------
DELETE FROM ai_interaction
 WHERE tenant_id = @tenant AND id IN (SELECT id FROM tmp_f8a_ai);
DELETE FROM exam_result
 WHERE tenant_id = @tenant AND id IN (SELECT id FROM tmp_f8a_exam_result);
DELETE FROM exam_request
 WHERE tenant_id = @tenant AND id IN (SELECT id FROM tmp_f8a_exam_request);
DELETE FROM prescription_item
 WHERE tenant_id = @tenant AND prescription_id IN (SELECT id FROM tmp_f8a_prescription);
DELETE FROM prescription
 WHERE tenant_id = @tenant AND id IN (SELECT id FROM tmp_f8a_prescription);
DELETE FROM encounter
 WHERE tenant_id = @tenant AND id IN (SELECT id FROM tmp_f8a_encounter);
DELETE FROM queue_entry
 WHERE tenant_id = @tenant AND id IN (SELECT id FROM tmp_f8a_queue);
DELETE FROM appointment
 WHERE tenant_id = @tenant AND id IN (SELECT id FROM tmp_f8a_appointment);
DELETE FROM patient
 WHERE tenant_id = @tenant AND id IN (SELECT id FROM tmp_f8a_patient);
DELETE FROM tutor
 WHERE tenant_id = @tenant AND id IN (SELECT id FROM tmp_f8a_tutor);

-- Configuração de IA do tenant 1 volta ao estado pré-gate (nenhuma linha).
DELETE FROM feature_flag
 WHERE tenant_id = @tenant AND flag_key = 'ai_assistant';
DELETE FROM ai_tenant_setting
 WHERE tenant_id = @tenant;

-- 6) Contagens DEPOIS (esperado: igual ao pré-gate do cabeçalho; audit_log só cresce) --------------
SELECT COUNT(*) = 7    AS tutor_ok             FROM tutor;
SELECT COUNT(*) = 7    AS patient_ok           FROM patient;
SELECT COUNT(*) = 7    AS encounter_ok         FROM encounter;
SELECT COUNT(*) = 3    AS exam_request_ok      FROM exam_request;
SELECT COUNT(*) = 3    AS exam_result_ok       FROM exam_result;
SELECT COUNT(*) = 15   AS appointment_ok       FROM appointment;
SELECT COUNT(*) = 4    AS queue_entry_ok       FROM queue_entry;
SELECT COUNT(*) = 3    AS prescription_ok      FROM prescription;
SELECT COUNT(*) = 5    AS prescription_item_ok FROM prescription_item;
SELECT COUNT(*) = 0    AS ai_interaction_ok    FROM ai_interaction;
SELECT COUNT(*) = 0    AS feature_flag_ok      FROM feature_flag;
SELECT COUNT(*) = 0    AS ai_tenant_setting_ok FROM ai_tenant_setting;
SELECT COUNT(*) >= 1155 AS audit_log_ok        FROM audit_log;
SELECT COUNT(*) AS patient_f8a_restante FROM patient WHERE name LIKE 'F8A teste%';
SELECT COUNT(*) AS tutor_f8a_restante   FROM tutor   WHERE full_name LIKE 'F8A teste%';

-- COMMIT;

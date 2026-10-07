-- T-21 — Limpeza dos registros de gate "F6B teste" da Fase 6B (banco centralvet)
--
-- NÃO EXECUTAR SEM APROVAÇÃO. Só o orquestrador executa, depois do gate final da Onda 6, com
-- aprovação SQL explícita do usuário (skill sql-write-approval) e backup antes:
--   ./scripts/backup.sh && gzip -t var/backups/<arquivo>.sql.gz
-- Execução interativa (para ler os SELECTs antes de decidir o COMMIT):
--   docker compose exec mysql sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" --default-character-set=utf8mb4 centralvet'
--   mysql> source /caminho/T-21-cleanup.sql
-- O usuário precisa de CREATE TEMPORARY TABLES (as tabelas tmp_f6b_* vivem só na sessão).
-- O COMMIT final está comentado: sem ele, fechar a sessão desfaz tudo. Qualquer erro (FK
-- RESTRICT, CHECK) interrompe o script; rode ROLLBACK e reveja o predicado.
-- Recomendado: ensaio completo terminando em ROLLBACK antes da execução com COMMIT (na 6A o
-- ensaio pegou a violação de encounter_account_total_ck).
--
-- Estado lido em 2026-10-06 07:47 (só SELECT, MySQL 8.0.43), ANTES do gate da Onda 6:
--   surgery_room, surgery, surgery_team, surgery_checklist, surgery_event, surgery_material: 0.
--   encounter 7 (373, 556, 1547, 1708, 2189, 3408, 4304 — de fases anteriores, NÃO apagados),
--   encounter_account 4 (1, 2, 40 closed; 46 open, encounter 1708), encounter_account_item 6,
--   stock_movement 1 (id 1, lote 729), stock_batch 1, appointment 15, hospitalization 0, bed 0,
--   queue_entry 4, system_program 126, audit_log MAX(id) 5431.
--   patient/product/stock_batch/bed 'F6B teste%': 0.
--   Atualize este bloco com o estado lido depois do gate (ids reais) antes de executar.
--
-- audit_log: os registros gerados pelos gates (id > 5431: surgery, surgery_room,
-- encounter_account, stock_batch, hospitalization, appointment...) ficam de propósito: são a
-- trilha de auditoria append-only e não têm FK para as linhas apagadas.
--
-- Como os ids são derivados (todos a partir do prefixo 'F6B teste'):
--   salas        = surgery_room.code/name LIKE 'F6B teste%'
--   pacientes    = patient.name LIKE 'F6B teste%'; atendimentos = encounter dos pacientes F6B
--   produtos     = product.name LIKE 'F6B teste%'
--   cirurgias    = na sala F6B, de atendimento/paciente F6B, com notes_text ou
--                  cancellation_reason_text 'F6B teste%', ou com material de produto F6B
--   equipe/checklist/eventos/materiais = das cirurgias acima
--   retornos     = appointment de surgery.followup_appointment_id das cirurgias F6B
--   leitos       = bed.code/name LIKE 'F6B teste%' (pós-operatório, 'F6B teste L1')
--   internações  = no leito F6B, transferidas de/para leito F6B, de atendimento F6B, com
--                  prescrição 'F6B teste%', ou do atendimento de cirurgia F6B criadas depois de
--                  @gate_start
--   itens da conta = surgery_procedure com source_id da cirurgia F6B, surgery_material com
--                  source_id do material F6B, hospitalization_stay/_administration da internação F6B
--   movimentos   = surgery_consumption com reference 'surgery' das cirurgias F6B,
--                  hospitalization_consumption das internações F6B, todos os movimentos dos
--                  lotes F6B e dos produtos F6B
--   lotes        = stock_batch.lot LIKE 'F6B teste%' ou de produto F6B
--
-- Ajuste de saldo (documentado): a baixa da conclusão (movimento out surgery_consumption, FEFO,
-- pode tocar vários lotes) e a da alta pós-operatória voltam ao lote de origem com UPDATE
-- stock_batch.quantity + soma das saídas, só para lotes que NÃO são apagados. Lote F6B é apagado
-- inteiro com seus movimentos. Entradas manuais feitas pelo gate em lotes fora do prefixo NÃO são
-- cobertas: o gate deve usar só produto/lote 'F6B teste'.
--
-- Contas: as que perdem itens têm subtotal/total recalculados por tabela derivada (soma dos
-- itens restantes; total = subtotal - desconto, mesma regra do EncounterAccountService; lição do
-- encounter_account_total_ck da 6A: nenhuma atribuição lê coluna atualizada no mesmo comando).
-- Contas criadas no gate (tmp_f6b_account_new: created_at >= @gate_start, open, só itens F6B,
-- sem receivable e com audit_log só de SurgeryView::onComplete, HospitalizationView::onDischarge
-- ou EncounterAccountForm::onLoad) são apagadas. Conta criada no gate que ficar com itens de
-- fora (ex.: syncAutomaticItems do onLoad) é recalculada e mantida.
--
-- Ordem: o task pedia "appointment de retorno → surgery", mas surgery.followup_appointment_id
-- tem FK para appointment, então a cirurgia é apagada antes do retorno (3.7 → 3.8).
--
-- PARE (ROLLBACK) se: alguma conta afetada estiver 'closed' (há recebível com o total antigo);
-- algum SELECT "antes" listar linha que não seja de teste; a lista "cirurgias fora do prefixo"
-- mostrar cirurgia criada no gate; ou as linhas afetadas divergirem das contagens "antes".
-- Tutores 'F6B teste' (se o gate criar) ficam fora do escopo desta limpeza.

SET NAMES utf8mb4;
-- Início do gate da Onda 6 (estado acima lido antes dele). Ajuste se o gate começou antes.
SET @gate_start = '2026-10-06 07:47:00';

-- =============================================================================================
-- 1. Contagens antes (só leitura)
-- =============================================================================================
SELECT COUNT(*) AS encounter_total              FROM encounter;               -- 2026-10-06 07:47 (pré-gate): 7
SELECT COUNT(*) AS encounter_account_total      FROM encounter_account;       -- pré-gate: 4
SELECT COUNT(*) AS encounter_account_item_total FROM encounter_account_item;  -- pré-gate: 6
SELECT COUNT(*) AS stock_movement_total         FROM stock_movement;          -- pré-gate: 1
SELECT COUNT(*) AS appointment_total            FROM appointment;             -- pré-gate: 15
SELECT COUNT(*) AS hospitalization_total        FROM hospitalization;         -- pré-gate: 0
SELECT COUNT(*) AS system_program_total         FROM system_program;          -- pré-gate: 126 (não muda)
SELECT COUNT(*) AS surgery_total                FROM surgery;                 -- pré-gate: 0
SELECT COUNT(*) AS room_f6b    FROM surgery_room WHERE code LIKE 'F6B teste%' OR name LIKE 'F6B teste%';
SELECT COUNT(*) AS bed_f6b     FROM bed          WHERE code LIKE 'F6B teste%' OR name LIKE 'F6B teste%';
SELECT COUNT(*) AS patient_f6b FROM patient      WHERE name LIKE 'F6B teste%';
SELECT COUNT(*) AS product_f6b FROM product      WHERE name LIKE 'F6B teste%';
SELECT COUNT(*) AS batch_f6b   FROM stock_batch  WHERE lot LIKE 'F6B teste%';

-- =============================================================================================
-- 2. Ids derivados do prefixo (tabelas temporárias da sessão; cada uma é lida uma vez por
--    comando, por causa do erro 1137 "Can't reopen table" do MySQL)
-- =============================================================================================
DROP TEMPORARY TABLE IF EXISTS tmp_f6b_room, tmp_f6b_patient, tmp_f6b_encounter, tmp_f6b_product,
    tmp_f6b_surgery, tmp_f6b_surgery_enc, tmp_f6b_material, tmp_f6b_followup, tmp_f6b_bed,
    tmp_f6b_hosp, tmp_f6b_order, tmp_f6b_adm, tmp_f6b_item, tmp_f6b_account, tmp_f6b_account_new,
    tmp_f6b_mov, tmp_f6b_batch;

CREATE TEMPORARY TABLE tmp_f6b_room        (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f6b_patient     (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f6b_encounter   (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f6b_product     (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f6b_surgery     (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f6b_surgery_enc (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f6b_material    (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f6b_followup    (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f6b_bed         (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f6b_hosp        (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f6b_order       (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f6b_adm         (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f6b_item        (id BIGINT UNSIGNED PRIMARY KEY, account_id BIGINT UNSIGNED NOT NULL);
CREATE TEMPORARY TABLE tmp_f6b_account     (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f6b_account_new (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f6b_mov         (id BIGINT UNSIGNED PRIMARY KEY, stock_batch_id BIGINT UNSIGNED NOT NULL,
                                            movement_type VARCHAR(20) NOT NULL, quantity INT UNSIGNED NOT NULL);
CREATE TEMPORARY TABLE tmp_f6b_batch       (id BIGINT UNSIGNED PRIMARY KEY);

-- 2.1 Cadastros com o prefixo
INSERT INTO tmp_f6b_room      SELECT id FROM surgery_room WHERE code LIKE 'F6B teste%' OR name LIKE 'F6B teste%';
INSERT INTO tmp_f6b_patient   SELECT id FROM patient      WHERE name LIKE 'F6B teste%';
INSERT INTO tmp_f6b_encounter SELECT id FROM encounter    WHERE patient_id IN (SELECT id FROM tmp_f6b_patient);
INSERT INTO tmp_f6b_product   SELECT id FROM product      WHERE name LIKE 'F6B teste%';
INSERT INTO tmp_f6b_bed       SELECT id FROM bed          WHERE code LIKE 'F6B teste%' OR name LIKE 'F6B teste%';

-- 2.2 Cirurgias e filhos
INSERT IGNORE INTO tmp_f6b_surgery SELECT id FROM surgery WHERE room_id      IN (SELECT id FROM tmp_f6b_room);
INSERT IGNORE INTO tmp_f6b_surgery SELECT id FROM surgery WHERE encounter_id IN (SELECT id FROM tmp_f6b_encounter);
INSERT IGNORE INTO tmp_f6b_surgery SELECT id FROM surgery WHERE patient_id   IN (SELECT id FROM tmp_f6b_patient);
INSERT IGNORE INTO tmp_f6b_surgery SELECT id FROM surgery
 WHERE notes_text LIKE 'F6B teste%' OR cancellation_reason_text LIKE 'F6B teste%';
INSERT IGNORE INTO tmp_f6b_surgery SELECT surgery_id FROM surgery_material WHERE product_id IN (SELECT id FROM tmp_f6b_product);

INSERT INTO tmp_f6b_surgery_enc SELECT DISTINCT encounter_id FROM surgery WHERE id IN (SELECT id FROM tmp_f6b_surgery);
INSERT INTO tmp_f6b_material    SELECT id FROM surgery_material WHERE surgery_id IN (SELECT id FROM tmp_f6b_surgery);
INSERT INTO tmp_f6b_followup    SELECT followup_appointment_id FROM surgery
 WHERE id IN (SELECT id FROM tmp_f6b_surgery) AND followup_appointment_id IS NOT NULL;

-- 2.3 Internação pós-operatória (modelo da 6A)
INSERT IGNORE INTO tmp_f6b_hosp SELECT id FROM hospitalization WHERE bed_id IN (SELECT id FROM tmp_f6b_bed);
INSERT IGNORE INTO tmp_f6b_hosp SELECT hospitalization_id FROM hospitalization_event WHERE from_bed_id IN (SELECT id FROM tmp_f6b_bed);
INSERT IGNORE INTO tmp_f6b_hosp SELECT hospitalization_id FROM hospitalization_event WHERE to_bed_id   IN (SELECT id FROM tmp_f6b_bed);
INSERT IGNORE INTO tmp_f6b_hosp SELECT id FROM hospitalization WHERE encounter_id IN (SELECT id FROM tmp_f6b_encounter);
INSERT IGNORE INTO tmp_f6b_hosp SELECT hospitalization_id FROM hospitalization_order WHERE description_text LIKE 'F6B teste%';
INSERT IGNORE INTO tmp_f6b_hosp SELECT id FROM hospitalization
 WHERE encounter_id IN (SELECT id FROM tmp_f6b_surgery_enc) AND created_at >= @gate_start;

INSERT INTO tmp_f6b_order SELECT id FROM hospitalization_order          WHERE hospitalization_id IN (SELECT id FROM tmp_f6b_hosp);
INSERT INTO tmp_f6b_adm   SELECT id FROM hospitalization_administration WHERE hospitalization_id IN (SELECT id FROM tmp_f6b_hosp);

-- 2.4 Itens e contas
INSERT IGNORE INTO tmp_f6b_item SELECT id, account_id FROM encounter_account_item
 WHERE source_type = 'surgery_procedure' AND source_id IN (SELECT id FROM tmp_f6b_surgery);
INSERT IGNORE INTO tmp_f6b_item SELECT id, account_id FROM encounter_account_item
 WHERE source_type = 'surgery_material' AND source_id IN (SELECT id FROM tmp_f6b_material);
INSERT IGNORE INTO tmp_f6b_item SELECT id, account_id FROM encounter_account_item
 WHERE source_type = 'hospitalization_stay' AND source_id IN (SELECT id FROM tmp_f6b_hosp);
INSERT IGNORE INTO tmp_f6b_item SELECT id, account_id FROM encounter_account_item
 WHERE source_type = 'hospitalization_administration' AND source_id IN (SELECT id FROM tmp_f6b_adm);
INSERT INTO tmp_f6b_account SELECT DISTINCT account_id FROM tmp_f6b_item;
-- Contas criadas no gate (openOrGet da conclusão/alta/tela da conta): só itens F6B, sem
-- receivable, audit_log só das ações do gate.
INSERT INTO tmp_f6b_account_new
SELECT a.id FROM encounter_account a
 WHERE a.id IN (SELECT id FROM tmp_f6b_account)
   AND a.status = 'open'
   AND a.created_at >= @gate_start
   AND NOT EXISTS (SELECT 1 FROM encounter_account_item i
                    WHERE i.account_id = a.id AND i.id NOT IN (SELECT id FROM tmp_f6b_item))
   AND NOT EXISTS (SELECT 1 FROM receivable r WHERE r.encounter_account_id = a.id)
   AND NOT EXISTS (SELECT 1 FROM audit_log l
                    WHERE l.entity_type = 'encounter_account' AND l.entity_id = CAST(a.id AS CHAR)
                      AND l.action NOT IN ('SurgeryView::onComplete', 'HospitalizationView::onDischarge',
                                           'EncounterAccountForm::onLoad'));

-- 2.5 Lotes e movimentos
INSERT IGNORE INTO tmp_f6b_batch SELECT id FROM stock_batch WHERE lot LIKE 'F6B teste%';
INSERT IGNORE INTO tmp_f6b_batch SELECT id FROM stock_batch WHERE product_id IN (SELECT id FROM tmp_f6b_product);

INSERT IGNORE INTO tmp_f6b_mov SELECT id, stock_batch_id, movement_type, quantity FROM stock_movement
 WHERE reason = 'surgery_consumption' AND reference_type = 'surgery'
   AND reference_id IN (SELECT id FROM tmp_f6b_surgery);
INSERT IGNORE INTO tmp_f6b_mov SELECT id, stock_batch_id, movement_type, quantity FROM stock_movement
 WHERE reason = 'hospitalization_consumption' AND reference_type = 'hospitalization'
   AND reference_id IN (SELECT id FROM tmp_f6b_hosp);
INSERT IGNORE INTO tmp_f6b_mov SELECT id, stock_batch_id, movement_type, quantity FROM stock_movement
 WHERE stock_batch_id IN (SELECT id FROM tmp_f6b_batch);
INSERT IGNORE INTO tmp_f6b_mov SELECT id, stock_batch_id, movement_type, quantity FROM stock_movement
 WHERE product_id IN (SELECT id FROM tmp_f6b_product);

-- 2.6 Revisão das linhas que serão apagadas (antes)
SELECT 'room' AS t, r.id, r.code, r.name, r.system_unit_id FROM surgery_room r WHERE r.id IN (SELECT id FROM tmp_f6b_room);
SELECT 'surgery' AS t, s.id, s.encounter_id, s.patient_id, s.room_id, s.status, s.followup_appointment_id, s.created_at
  FROM surgery s WHERE s.id IN (SELECT id FROM tmp_f6b_surgery);
-- ^ cirurgias fora do prefixo (deve ser vazio; se listar cirurgia do gate, PARE e ajuste o predicado):
SELECT 'surgery_fora' AS t, s.id, s.room_id, s.status, s.created_at FROM surgery s WHERE s.id NOT IN (SELECT id FROM tmp_f6b_surgery);
SELECT COUNT(*) AS team      FROM surgery_team      WHERE surgery_id IN (SELECT id FROM tmp_f6b_surgery);
SELECT COUNT(*) AS checklist FROM surgery_checklist WHERE surgery_id IN (SELECT id FROM tmp_f6b_surgery);
SELECT COUNT(*) AS events    FROM surgery_event     WHERE surgery_id IN (SELECT id FROM tmp_f6b_surgery);
SELECT 'material' AS t, m.id, m.surgery_id, m.product_id, m.quantity FROM surgery_material m WHERE m.id IN (SELECT id FROM tmp_f6b_material);
SELECT 'followup' AS t, a.id, a.patient_id, a.scheduled_at, a.status FROM appointment a WHERE a.id IN (SELECT id FROM tmp_f6b_followup);
SELECT 'bed' AS t, b.id, b.code FROM bed b WHERE b.id IN (SELECT id FROM tmp_f6b_bed);
SELECT 'hospitalization' AS t, h.id, h.encounter_id, h.patient_id, h.bed_id, h.status FROM hospitalization h WHERE h.id IN (SELECT id FROM tmp_f6b_hosp);
SELECT COUNT(*) AS hosp_orders FROM tmp_f6b_order;
SELECT COUNT(*) AS hosp_adms   FROM tmp_f6b_adm;
SELECT 'item' AS t, i.id, i.account_id, i.source_type, i.source_id, i.amount_cents FROM encounter_account_item i WHERE i.id IN (SELECT id FROM tmp_f6b_item);
SELECT 'account' AS t, a.id, a.encounter_id, a.status, a.subtotal_cents, a.discount_cents, a.total_cents, a.created_at
  FROM encounter_account a WHERE a.id IN (SELECT id FROM tmp_f6b_account);
-- ^ PARE se alguma conta estiver 'closed'.
SELECT 'account_new' AS t, a.id, a.encounter_id, a.created_at FROM encounter_account a WHERE a.id IN (SELECT id FROM tmp_f6b_account_new);
SELECT 'movement' AS t, m.id, m.stock_batch_id, m.movement_type, m.quantity FROM tmp_f6b_mov m;
SELECT 'batch' AS t, s.id, s.product_id, s.lot, s.quantity FROM stock_batch s WHERE s.id IN (SELECT id FROM tmp_f6b_batch);
SELECT 'patient' AS t, p.id, p.name FROM patient p WHERE p.id IN (SELECT id FROM tmp_f6b_patient);
SELECT 'encounter' AS t, e.id, e.patient_id FROM encounter e WHERE e.id IN (SELECT id FROM tmp_f6b_encounter);
SELECT 'product' AS t, p.id, p.name FROM product p WHERE p.id IN (SELECT id FROM tmp_f6b_product);

-- =============================================================================================
-- 3. Limpeza (ordem das FKs)
-- =============================================================================================
START TRANSACTION;

-- 3.1 Ajuste de saldo: devolve as saídas da conclusão/alta aos lotes que permanecem.
UPDATE stock_batch b
  JOIN (SELECT stock_batch_id, SUM(quantity) AS qty
          FROM tmp_f6b_mov
         WHERE movement_type = 'out'
         GROUP BY stock_batch_id) m ON m.stock_batch_id = b.id
   SET b.quantity = b.quantity + m.qty
 WHERE b.id NOT IN (SELECT id FROM tmp_f6b_batch);

-- 3.2 Filhos da cirurgia: checklist → evento → material → equipe
DELETE FROM surgery_checklist WHERE surgery_id IN (SELECT id FROM tmp_f6b_surgery);
DELETE FROM surgery_event     WHERE surgery_id IN (SELECT id FROM tmp_f6b_surgery);
DELETE FROM surgery_material  WHERE id IN (SELECT id FROM tmp_f6b_material);
DELETE FROM surgery_team      WHERE surgery_id IN (SELECT id FROM tmp_f6b_surgery);

-- 3.3 Internação pós-operatória: administração → prescrição → evento
DELETE FROM hospitalization_administration WHERE id IN (SELECT id FROM tmp_f6b_adm);
DELETE FROM hospitalization_order          WHERE id IN (SELECT id FROM tmp_f6b_order);
DELETE FROM hospitalization_event          WHERE hospitalization_id IN (SELECT id FROM tmp_f6b_hosp);

-- 3.4 Itens surgery_*/hospitalization_* da conta e recálculo das contas afetadas
DELETE FROM encounter_account_item
 WHERE source_type IN ('surgery_procedure', 'surgery_material', 'hospitalization_stay', 'hospitalization_administration')
   AND id IN (SELECT id FROM tmp_f6b_item);
-- Os dois valores vêm da mesma tabela derivada (soma dos itens restantes) e do desconto, que
-- não muda: nenhuma atribuição lê outra coluna atualizada no mesmo comando.
-- encounter_account_discount_ck: se a soma restante ficar menor que o desconto, o UPDATE falha:
-- ROLLBACK e trate a conta à parte.
UPDATE encounter_account a
  LEFT JOIN (SELECT i.account_id, SUM(i.amount_cents) AS items_cents
               FROM encounter_account_item i
              GROUP BY i.account_id) s ON s.account_id = a.id
   SET a.subtotal_cents = COALESCE(s.items_cents, 0),
       a.total_cents    = COALESCE(s.items_cents, 0) - a.discount_cents
 WHERE a.status = 'open'
   AND a.id IN (SELECT id FROM tmp_f6b_account)
   AND a.id NOT IN (SELECT id FROM tmp_f6b_account_new);
-- Contas criadas no gate, já sem itens: o atendimento volta a não ter conta.
DELETE FROM encounter_account
 WHERE status = 'open'
   AND NOT EXISTS (SELECT 1 FROM encounter_account_item i WHERE i.account_id = encounter_account.id)
   AND id IN (SELECT id FROM tmp_f6b_account_new);

-- 3.5 Movimentos de estoque: consumo da cirurgia/internação, lotes F6B e produtos F6B
DELETE FROM stock_movement WHERE id IN (SELECT id FROM tmp_f6b_mov);

-- 3.6 Internação e leito (bed.current_hospitalization_id não tem FK)
DELETE FROM hospitalization WHERE id IN (SELECT id FROM tmp_f6b_hosp);
DELETE FROM bed             WHERE id IN (SELECT id FROM tmp_f6b_bed) AND (code LIKE 'F6B teste%' OR name LIKE 'F6B teste%');

-- 3.7 Cirurgia (antes do retorno: FK surgery.followup_appointment_id → appointment)
DELETE FROM surgery WHERE id IN (SELECT id FROM tmp_f6b_surgery);

-- 3.8 Retorno agendado pela cirurgia (fila primeiro; encounter com appointment_id do retorno
--     faz o FK parar o script: ROLLBACK e reveja)
DELETE FROM queue_entry WHERE appointment_id IN (SELECT id FROM tmp_f6b_followup);
DELETE FROM appointment WHERE id IN (SELECT id FROM tmp_f6b_followup);

-- 3.9 Salas F6B
DELETE FROM surgery_room WHERE id IN (SELECT id FROM tmp_f6b_room) AND (code LIKE 'F6B teste%' OR name LIKE 'F6B teste%');

-- 3.10 Lotes F6B
DELETE FROM stock_batch WHERE id IN (SELECT id FROM tmp_f6b_batch);

-- 3.11 Atendimentos, pacientes e produtos 'F6B teste' criados no gate.
--      FK RESTRICT de exam_request/prescription/procedure_execution/sale/vaccination/receivable
--      interrompe o script se o gate tiver criado dependentes fora desta lista: ROLLBACK e reveja.
DELETE FROM encounter_account_item
 WHERE account_id IN (SELECT id FROM encounter_account WHERE encounter_id IN (SELECT id FROM tmp_f6b_encounter));
DELETE FROM encounter_account WHERE encounter_id IN (SELECT id FROM tmp_f6b_encounter);
DELETE FROM encounter         WHERE id IN (SELECT id FROM tmp_f6b_encounter);
DELETE FROM queue_entry       WHERE patient_id IN (SELECT id FROM tmp_f6b_patient);
DELETE FROM appointment       WHERE patient_id IN (SELECT id FROM tmp_f6b_patient);
DELETE FROM patient           WHERE id IN (SELECT id FROM tmp_f6b_patient) AND name LIKE 'F6B teste%';
DELETE FROM product           WHERE id IN (SELECT id FROM tmp_f6b_product) AND name LIKE 'F6B teste%';

-- =============================================================================================
-- 4. Conferência (esperado: 0 em todas as linhas F6B)
-- =============================================================================================
SELECT COUNT(*) AS room_f6b_depois      FROM surgery_room WHERE code LIKE 'F6B teste%' OR name LIKE 'F6B teste%';
SELECT COUNT(*) AS surgery_f6b_depois   FROM surgery WHERE id IN (SELECT id FROM tmp_f6b_surgery);
SELECT COUNT(*) AS team_f6b_depois      FROM surgery_team      WHERE surgery_id IN (SELECT id FROM tmp_f6b_surgery);
SELECT COUNT(*) AS checklist_f6b_depois FROM surgery_checklist WHERE surgery_id IN (SELECT id FROM tmp_f6b_surgery);
SELECT COUNT(*) AS event_f6b_depois     FROM surgery_event     WHERE surgery_id IN (SELECT id FROM tmp_f6b_surgery);
SELECT COUNT(*) AS material_f6b_depois  FROM surgery_material  WHERE id IN (SELECT id FROM tmp_f6b_material);
SELECT COUNT(*) AS followup_f6b_depois  FROM appointment WHERE id IN (SELECT id FROM tmp_f6b_followup);
SELECT COUNT(*) AS bed_f6b_depois       FROM bed WHERE code LIKE 'F6B teste%' OR name LIKE 'F6B teste%';
SELECT COUNT(*) AS hosp_f6b_depois      FROM hospitalization WHERE id IN (SELECT id FROM tmp_f6b_hosp);
SELECT COUNT(*) AS item_f6b_depois      FROM encounter_account_item WHERE id IN (SELECT id FROM tmp_f6b_item);
SELECT COUNT(*) AS mov_f6b_depois       FROM stock_movement WHERE id IN (SELECT id FROM tmp_f6b_mov);
SELECT COUNT(*) AS batch_f6b_depois     FROM stock_batch WHERE lot LIKE 'F6B teste%';
SELECT COUNT(*) AS patient_f6b_depois   FROM patient WHERE name LIKE 'F6B teste%';
SELECT COUNT(*) AS product_f6b_depois   FROM product WHERE name LIKE 'F6B teste%';
SELECT a.id, a.status, a.subtotal_cents, a.discount_cents, a.total_cents,
       (SELECT COALESCE(SUM(i.amount_cents), 0) FROM encounter_account_item i WHERE i.account_id = a.id) AS soma_itens
  FROM encounter_account a WHERE a.id IN (SELECT id FROM tmp_f6b_account);   -- subtotal = soma_itens; total = subtotal - desconto
SELECT COUNT(*) AS account_new_depois FROM encounter_account WHERE id IN (SELECT id FROM tmp_f6b_account_new); -- 0
SELECT 'batch_saldo' AS t, b.id, b.lot, b.quantity FROM stock_batch b
 WHERE b.id IN (SELECT stock_batch_id FROM tmp_f6b_mov);                     -- lotes que permanecem, saldo devolvido
SELECT COUNT(*) AS encounter_total_depois              FROM encounter;               -- pré-gate 7
SELECT COUNT(*) AS encounter_account_total_depois      FROM encounter_account;       -- pré-gate 4
SELECT COUNT(*) AS encounter_account_item_total_depois FROM encounter_account_item;  -- pré-gate 6
SELECT COUNT(*) AS stock_movement_total_depois         FROM stock_movement;          -- pré-gate 1
SELECT COUNT(*) AS appointment_total_depois            FROM appointment;             -- pré-gate 15
SELECT COUNT(*) AS hospitalization_total_depois        FROM hospitalization;         -- pré-gate 0
SELECT COUNT(*) AS surgery_total_depois                FROM surgery;                 -- pré-gate 0
SELECT COUNT(*) AS system_program_total_depois         FROM system_program;          -- igual ao antes (126)

-- Confira tudo acima. Se bater, descomente e rode o COMMIT; senão, ROLLBACK.
-- ROLLBACK;
-- COMMIT;

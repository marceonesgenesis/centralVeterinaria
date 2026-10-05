-- T-20 — Limpeza dos registros de gate "F6 teste" da Fase 6A (banco centralvet)
--
-- NÃO EXECUTAR SEM APROVAÇÃO. Só o orquestrador executa, depois do gate final da Onda 6, com
-- aprovação SQL explícita do usuário (skill sql-write-approval) e backup antes:
--   ./scripts/backup.sh && gzip -t var/backups/<arquivo>.sql.gz
-- Execução interativa (para ler os SELECTs antes de decidir o COMMIT):
--   docker compose exec mysql sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" --default-character-set=utf8mb4 centralvet'
--   mysql> source /caminho/T-20-cleanup.sql
-- O usuário precisa de CREATE TEMPORARY TABLES (as tabelas tmp_f6_* vivem só na sessão).
-- O COMMIT final está comentado: sem ele, fechar a sessão desfaz tudo. Qualquer erro (FK
-- RESTRICT, CHECK) interrompe o script; rode ROLLBACK e reveja o predicado.
--
-- Estado lido em 2026-10-05 (só SELECT, MySQL 8.0.43), antes do gate da Onda 6:
--   bed 1..3 ('F6 teste L1'..'L3'); bed 1 ocupado pela internação 2.
--   hospitalization 1 (encounter 1708, discharged, leito 2) e 2 (encounter 2189, admitted, leito 1);
--     paciente 1452 'F10 varredura Pet T21' e os atendimentos 1708/2189 são de fases anteriores
--     e NÃO são apagados.
--   hospitalization_order 1..3; hospitalization_administration 1..9; hospitalization_event 1..6.
--   encounter_account_item 7 (hospitalization_stay, source 1, 10000) e 8
--     (hospitalization_administration, source 1, 1000) na conta 46 (open, subtotal 18000,
--     desconto 500, total 17500).
--   stock_movement 2 (in 10, purchase_entry, lote 11186) e 3 (out 1, hospitalization_consumption,
--     reference hospitalization 1); stock_batch 11186 lot 'F6 teste lote' (produto 8545
--     'R2 Prod (teste)', de fase anterior, NÃO é apagado), quantity 9.
--   patient/product com nome 'F6 teste%': 0 (o gate da Onda 6 pode criar; o script cobre).
--   Totais: encounter 7, encounter_account_item 8, stock_movement 3, system_program 117.
--
-- Como os ids são derivados (todos a partir do prefixo 'F6 teste'):
--   leitos      = bed.code/name LIKE 'F6 teste%'
--   pacientes   = patient.name LIKE 'F6 teste%'; atendimentos = encounter dos pacientes F6
--   produtos    = product.name LIKE 'F6 teste%'
--   internações = no leito F6, transferidas de/para leito F6, de atendimento F6, ou com
--                 prescrição 'F6 teste%'
--   prescrições/administrações/eventos = das internações acima
--   itens da conta = hospitalization_stay com source_id da internação F6 e
--                    hospitalization_administration com source_id da administração F6
--   movimentos  = hospitalization_consumption com reference das internações F6, todos os
--                 movimentos dos lotes F6 e dos produtos F6
--   lotes       = stock_batch.lot LIKE 'F6 teste%' ou de produto F6
--
-- Ajuste de saldo (documentado): a baixa da alta (movimento out hospitalization_consumption)
-- volta ao lote de origem com UPDATE stock_batch.quantity + soma das saídas, só para lotes que
-- NÃO são apagados (lote fora do prefixo). Lote 'F6 teste ...' é apagado inteiro com seus
-- movimentos, então não precisa de ajuste. As contas que perdem itens têm subtotal/total
-- recalculados pela soma dos itens restantes (mesma regra do EncounterAccountService:
-- total = subtotal - desconto; CHECK discount_cents <= subtotal_cents).
-- PARE (ROLLBACK) se: alguma conta afetada estiver 'closed' (há recebível com o total antigo);
-- algum SELECT "antes" listar linha que não seja de teste; ou as linhas afetadas divergirem
-- das contagens "antes".
-- Tutores 'F6 teste' (se o gate criar) ficam fora do escopo desta limpeza.

SET NAMES utf8mb4;

-- =============================================================================================
-- 1. Contagens antes (só leitura)
-- =============================================================================================
SELECT COUNT(*) AS encounter_total              FROM encounter;               -- 2026-10-05: 7
SELECT COUNT(*) AS encounter_account_item_total FROM encounter_account_item;  -- 2026-10-05: 8
SELECT COUNT(*) AS stock_movement_total         FROM stock_movement;          -- 2026-10-05: 3
SELECT COUNT(*) AS system_program_total         FROM system_program;          -- 2026-10-05: 117 (não muda)
SELECT COUNT(*) AS bed_f6     FROM bed     WHERE code LIKE 'F6 teste%' OR name LIKE 'F6 teste%'; -- 3
SELECT COUNT(*) AS patient_f6 FROM patient WHERE name LIKE 'F6 teste%';                         -- 0
SELECT COUNT(*) AS product_f6 FROM product WHERE name LIKE 'F6 teste%';                         -- 0
SELECT COUNT(*) AS batch_f6   FROM stock_batch WHERE lot LIKE 'F6 teste%';                      -- 1
SELECT COUNT(*) AS order_f6   FROM hospitalization_order WHERE description_text LIKE 'F6 teste%'; -- 3

-- =============================================================================================
-- 2. Ids derivados do prefixo (tabelas temporárias da sessão; cada uma é lida uma vez por
--    comando, por causa do erro 1137 "Can't reopen table" do MySQL)
-- =============================================================================================
DROP TEMPORARY TABLE IF EXISTS tmp_f6_bed, tmp_f6_patient, tmp_f6_encounter, tmp_f6_product,
    tmp_f6_hosp, tmp_f6_order, tmp_f6_adm, tmp_f6_item, tmp_f6_account, tmp_f6_mov, tmp_f6_batch;

CREATE TEMPORARY TABLE tmp_f6_bed       (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f6_patient   (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f6_encounter (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f6_product   (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f6_hosp      (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f6_order     (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f6_adm       (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f6_item      (id BIGINT UNSIGNED PRIMARY KEY, account_id BIGINT UNSIGNED NOT NULL);
CREATE TEMPORARY TABLE tmp_f6_account   (id BIGINT UNSIGNED PRIMARY KEY);
CREATE TEMPORARY TABLE tmp_f6_mov       (id BIGINT UNSIGNED PRIMARY KEY, stock_batch_id BIGINT UNSIGNED NOT NULL,
                                         movement_type VARCHAR(20) NOT NULL, quantity INT UNSIGNED NOT NULL);
CREATE TEMPORARY TABLE tmp_f6_batch     (id BIGINT UNSIGNED PRIMARY KEY);

INSERT INTO tmp_f6_bed       SELECT id FROM bed     WHERE code LIKE 'F6 teste%' OR name LIKE 'F6 teste%';
INSERT INTO tmp_f6_patient   SELECT id FROM patient WHERE name LIKE 'F6 teste%';
INSERT INTO tmp_f6_encounter SELECT id FROM encounter WHERE patient_id IN (SELECT id FROM tmp_f6_patient);
INSERT INTO tmp_f6_product   SELECT id FROM product WHERE name LIKE 'F6 teste%';

INSERT IGNORE INTO tmp_f6_hosp SELECT id FROM hospitalization WHERE bed_id IN (SELECT id FROM tmp_f6_bed);
INSERT IGNORE INTO tmp_f6_hosp SELECT hospitalization_id FROM hospitalization_event WHERE from_bed_id IN (SELECT id FROM tmp_f6_bed);
INSERT IGNORE INTO tmp_f6_hosp SELECT hospitalization_id FROM hospitalization_event WHERE to_bed_id IN (SELECT id FROM tmp_f6_bed);
INSERT IGNORE INTO tmp_f6_hosp SELECT id FROM hospitalization WHERE encounter_id IN (SELECT id FROM tmp_f6_encounter);
INSERT IGNORE INTO tmp_f6_hosp SELECT hospitalization_id FROM hospitalization_order WHERE description_text LIKE 'F6 teste%';

INSERT INTO tmp_f6_order SELECT id FROM hospitalization_order          WHERE hospitalization_id IN (SELECT id FROM tmp_f6_hosp);
INSERT INTO tmp_f6_adm   SELECT id FROM hospitalization_administration WHERE hospitalization_id IN (SELECT id FROM tmp_f6_hosp);

INSERT IGNORE INTO tmp_f6_item SELECT id, account_id FROM encounter_account_item
 WHERE source_type = 'hospitalization_stay' AND source_id IN (SELECT id FROM tmp_f6_hosp);
INSERT IGNORE INTO tmp_f6_item SELECT id, account_id FROM encounter_account_item
 WHERE source_type = 'hospitalization_administration' AND source_id IN (SELECT id FROM tmp_f6_adm);
INSERT INTO tmp_f6_account SELECT DISTINCT account_id FROM tmp_f6_item;

INSERT IGNORE INTO tmp_f6_batch SELECT id FROM stock_batch WHERE lot LIKE 'F6 teste%';
INSERT IGNORE INTO tmp_f6_batch SELECT id FROM stock_batch WHERE product_id IN (SELECT id FROM tmp_f6_product);

INSERT IGNORE INTO tmp_f6_mov SELECT id, stock_batch_id, movement_type, quantity FROM stock_movement
 WHERE reason = 'hospitalization_consumption' AND reference_type = 'hospitalization'
   AND reference_id IN (SELECT id FROM tmp_f6_hosp);
INSERT IGNORE INTO tmp_f6_mov SELECT id, stock_batch_id, movement_type, quantity FROM stock_movement
 WHERE stock_batch_id IN (SELECT id FROM tmp_f6_batch);
INSERT IGNORE INTO tmp_f6_mov SELECT id, stock_batch_id, movement_type, quantity FROM stock_movement
 WHERE product_id IN (SELECT id FROM tmp_f6_product);

-- Revisão das linhas que serão apagadas (antes). Em 2026-10-05: beds 1,2,3; hosp 1,2;
-- orders 1,2,3; adms 1..9; itens 7,8 (conta 46, open); movimentos 2,3; lote 11186.
SELECT 'bed' AS t, b.id, b.code AS info FROM bed b WHERE b.id IN (SELECT id FROM tmp_f6_bed);
SELECT 'hospitalization' AS t, h.id, h.encounter_id, h.patient_id, h.bed_id, h.status FROM hospitalization h WHERE h.id IN (SELECT id FROM tmp_f6_hosp);
SELECT 'order' AS t, o.id, o.hospitalization_id, o.description_text FROM hospitalization_order o WHERE o.id IN (SELECT id FROM tmp_f6_order);
SELECT COUNT(*) AS administrations FROM tmp_f6_adm;
SELECT COUNT(*) AS events FROM hospitalization_event WHERE hospitalization_id IN (SELECT id FROM tmp_f6_hosp);
SELECT 'item' AS t, i.id, i.account_id, i.source_type, i.source_id, i.amount_cents FROM encounter_account_item i WHERE i.id IN (SELECT id FROM tmp_f6_item);
SELECT 'account' AS t, a.id, a.status, a.subtotal_cents, a.discount_cents, a.total_cents FROM encounter_account a WHERE a.id IN (SELECT id FROM tmp_f6_account);
-- ^ PARE se alguma conta estiver 'closed'.
SELECT 'movement' AS t, m.id, m.stock_batch_id, m.movement_type, m.quantity FROM tmp_f6_mov m;
SELECT 'batch' AS t, s.id, s.product_id, s.lot, s.quantity FROM stock_batch s WHERE s.id IN (SELECT id FROM tmp_f6_batch);
SELECT 'patient' AS t, p.id, p.name FROM patient p WHERE p.id IN (SELECT id FROM tmp_f6_patient);
SELECT 'encounter' AS t, e.id, e.patient_id FROM encounter e WHERE e.id IN (SELECT id FROM tmp_f6_encounter);
SELECT 'product' AS t, p.id, p.name FROM product p WHERE p.id IN (SELECT id FROM tmp_f6_product);

-- =============================================================================================
-- 3. Limpeza (ordem das FKs)
-- =============================================================================================
START TRANSACTION;

-- 3.1 Ajuste de saldo: devolve as saídas da internação aos lotes que permanecem.
--     Em 2026-10-05: 0 linhas (a única saída, movimento 3, é do lote 'F6 teste lote', apagado).
UPDATE stock_batch b
  JOIN (SELECT stock_batch_id, SUM(quantity) AS qty
          FROM tmp_f6_mov
         WHERE movement_type = 'out'
         GROUP BY stock_batch_id) m ON m.stock_batch_id = b.id
   SET b.quantity = b.quantity + m.qty
 WHERE b.id NOT IN (SELECT id FROM tmp_f6_batch);

-- 3.2 Internação: administração → prescrição → evento
DELETE FROM hospitalization_administration WHERE id IN (SELECT id FROM tmp_f6_adm);                      -- 9
DELETE FROM hospitalization_order          WHERE id IN (SELECT id FROM tmp_f6_order);                    -- 3
DELETE FROM hospitalization_event          WHERE hospitalization_id IN (SELECT id FROM tmp_f6_hosp);     -- 6

-- 3.3 Itens hospitalization_* da conta e recálculo das contas afetadas
DELETE FROM encounter_account_item
 WHERE source_type IN ('hospitalization_stay', 'hospitalization_administration')
   AND id IN (SELECT id FROM tmp_f6_item);                                                                -- 2
UPDATE encounter_account a
   SET a.subtotal_cents = (SELECT COALESCE(SUM(i.amount_cents), 0) FROM encounter_account_item i WHERE i.account_id = a.id),
       a.total_cents    = a.subtotal_cents - a.discount_cents
 WHERE a.status = 'open'
   AND a.id IN (SELECT id FROM tmp_f6_account);                       -- 1 (conta 46: 7000 / 500 / 6500)

-- 3.4 Movimentos de estoque: consumo da internação, lotes F6 e produtos F6
DELETE FROM stock_movement WHERE id IN (SELECT id FROM tmp_f6_mov);                                       -- 2

-- 3.5 Internação e leito (bed.current_hospitalization_id não tem FK)
DELETE FROM hospitalization WHERE id IN (SELECT id FROM tmp_f6_hosp);                                     -- 2
DELETE FROM bed             WHERE id IN (SELECT id FROM tmp_f6_bed) AND (code LIKE 'F6 teste%' OR name LIKE 'F6 teste%'); -- 3

-- 3.6 Lotes F6
DELETE FROM stock_batch WHERE id IN (SELECT id FROM tmp_f6_batch);                                        -- 1

-- 3.7 Atendimentos, pacientes e produtos 'F6 teste' criados no gate (0 em 2026-10-05).
--     FK RESTRICT de exam_request/prescription/procedure_execution/sale/vaccination/receivable
--     interrompe o script se o gate tiver criado dependentes fora desta lista: ROLLBACK e reveja.
DELETE FROM encounter_account_item
 WHERE account_id IN (SELECT id FROM encounter_account WHERE encounter_id IN (SELECT id FROM tmp_f6_encounter));
DELETE FROM encounter_account WHERE encounter_id IN (SELECT id FROM tmp_f6_encounter);
DELETE FROM encounter         WHERE id IN (SELECT id FROM tmp_f6_encounter);
DELETE FROM queue_entry       WHERE patient_id IN (SELECT id FROM tmp_f6_patient);
DELETE FROM appointment       WHERE patient_id IN (SELECT id FROM tmp_f6_patient);
DELETE FROM patient           WHERE id IN (SELECT id FROM tmp_f6_patient) AND name LIKE 'F6 teste%';
DELETE FROM product           WHERE id IN (SELECT id FROM tmp_f6_product) AND name LIKE 'F6 teste%';

-- =============================================================================================
-- 4. Conferência (esperado: 0 em todas as linhas F6)
-- =============================================================================================
SELECT COUNT(*) AS bed_f6_depois     FROM bed     WHERE code LIKE 'F6 teste%' OR name LIKE 'F6 teste%';
SELECT COUNT(*) AS hosp_f6_depois    FROM hospitalization WHERE id IN (SELECT id FROM tmp_f6_hosp);
SELECT COUNT(*) AS order_f6_depois   FROM hospitalization_order WHERE description_text LIKE 'F6 teste%';
SELECT COUNT(*) AS adm_f6_depois     FROM hospitalization_administration WHERE id IN (SELECT id FROM tmp_f6_adm);
SELECT COUNT(*) AS event_f6_depois   FROM hospitalization_event WHERE hospitalization_id IN (SELECT id FROM tmp_f6_hosp);
SELECT COUNT(*) AS item_f6_depois    FROM encounter_account_item WHERE id IN (SELECT id FROM tmp_f6_item);
SELECT COUNT(*) AS mov_f6_depois     FROM stock_movement WHERE id IN (SELECT id FROM tmp_f6_mov);
SELECT COUNT(*) AS batch_f6_depois   FROM stock_batch WHERE lot LIKE 'F6 teste%';
SELECT COUNT(*) AS patient_f6_depois FROM patient WHERE name LIKE 'F6 teste%';
SELECT COUNT(*) AS product_f6_depois FROM product WHERE name LIKE 'F6 teste%';
SELECT a.id, a.status, a.subtotal_cents, a.discount_cents, a.total_cents,
       (SELECT COALESCE(SUM(i.amount_cents), 0) FROM encounter_account_item i WHERE i.account_id = a.id) AS soma_itens
  FROM encounter_account a WHERE a.id IN (SELECT id FROM tmp_f6_account);   -- subtotal = soma_itens
SELECT COUNT(*) AS encounter_total_depois              FROM encounter;               -- antes - atendimentos F6
SELECT COUNT(*) AS encounter_account_item_total_depois FROM encounter_account_item;  -- antes - itens apagados
SELECT COUNT(*) AS stock_movement_total_depois         FROM stock_movement;          -- antes - movimentos apagados
SELECT COUNT(*) AS system_program_total_depois         FROM system_program;          -- igual ao antes

-- Confira tudo acima. Se bater, descomente e rode o COMMIT; senão, ROLLBACK.
-- COMMIT;
-- ROLLBACK;

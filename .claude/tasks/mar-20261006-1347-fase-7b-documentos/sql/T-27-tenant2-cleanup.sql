SET NAMES utf8mb4;
START TRANSACTION;
-- T-27 — Limpeza do segundo tenant de teste "F7B teste Clínica 2" e de TUDO o que o E2E de
-- isolamento cross-tenant criar sob ele (banco local centralvet).
--
-- NÃO EXECUTAR SEM APROVAÇÃO. Só o orquestrador executa, depois do E2E, com aprovação SQL
-- explícita do usuário (skill sql-write-approval) e backup antes:
--   ./scripts/backup.sh && gzip -t var/backups/<arquivo>.sql.gz
-- Execução interativa:
--   docker compose exec mysql sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" --default-character-set=utf8mb4 centralvet'
--   mysql> source /caminho/T-27-tenant2-cleanup.sql
-- O COMMIT final está comentado. No `source` interativo o cliente NÃO para no erro (FK RESTRICT);
-- execute em duas etapas: 1) `source` do script e procure "ERROR" (havendo, ROLLBACK e reveja);
-- 2) só sem erro e com os SELECTs batendo, digite COMMIT; à mão na mesma sessão.
-- Recomendado: ensaio completo terminando em ROLLBACK antes da execução com COMMIT.
--
-- Predicados: o tenant é resolvido pela chave natural (slug 'f7b-teste-clinica-2' + legal_name
-- 'F7B teste%'); a unidade por (tenant_id, nome 'F7B teste Unit 2'); o usuário por login
-- 'f7b.tenant2@example.invalid' + nome 'F7B teste%'. Todo DELETE filtra por tenant_id = @t2 (que
-- é 100% de teste: o tenant inteiro foi criado pela T-27) ou pelo id do usuário/unidade de teste,
-- e exige @t2 > 1 (o tenant 1 nunca entra). Se @t2 vier NULL, nenhum DELETE casa linha.
--
-- audit_log fica FORA (trilha append-only). Consequência pelo schema:
--   audit_log.tenant_id → tenant RESTRICT e audit_log.system_unit_id → system_unit RESTRICT;
--   audit_log.system_user_id → system_users ON DELETE SET NULL (apagar o usuário ALTERARIA o
--   audit_log). Por isso, na seção 3.9, tenant, unidade e usuário só são apagados se o audit_log
--   não os referenciar; senão ficam neutralizados (tenant 'cancelled', usuário inativo, sem
--   grupos/unidades/tenant_user) e o resíduo é listado em 4. Apagar o audit_log do tenant 2 é
--   decisão à parte, com nova autorização (não está neste script).
--   Logs Adianti sem FK (system_access_log, system_change_log, system_request_log,
--   system_sql_log) também ficam; a seção 1 só conta as linhas.
--
-- Ordem das FKs (filhas → mães), todas por tenant_id = @t2:
--   comunicação → documentos (generated_document → stored_object → document_template) →
--   financeiro (payment → receivable → encounter_account_item → encounter_account; sale_item →
--   sale) → internação → cirurgia (filhas → surgery) → procedure_execution → receita →
--   vacinação → exames → appointment_followup → queue_entry → encounter → appointment →
--   patient → tutor → estoque/catálogos → templates/serviços → caixa/contas/leitos/salas →
--   vínculos do usuário → system_users → system_unit → tenant_group/role/user → tenant.
--
-- Arquivos (NÃO EXECUTAR aqui; só depois do COMMIT e com confirmação). A seção 2 gera os rm por
-- object_key; o prefixo do tenant no volume app_documents é:
--   /var/www/html/var/documents/cv/development/tenant/<@t2>/
--   docker compose exec -T app sh -c 'ls -R /var/www/html/var/documents/cv/development/tenant/<@t2>/'
--   docker compose exec -T app rm -rf -- /var/www/html/var/documents/cv/development/tenant/<@t2>/
--   (troque <@t2> pelo número da seção 1; NUNCA rode com <@t2> vazio nem com 1)
-- Objetos S3/MinIO (anexos/foto de paciente, storage_provider <> 'local') ficam no bucket com
-- prefixo cv/development/tenant/<@t2>/; a seção 2 lista as chaves para remoção à parte.
-- Depois do rm: docker compose exec -T app find /var/www/html/var/documents/cv -type f | wc -l
-- deve bater com o número de stored_object local restantes.

SET @t2    = (SELECT id FROM tenant WHERE slug = 'f7b-teste-clinica-2' AND legal_name LIKE 'F7B teste%' AND id > 1);
SET @u2    = (SELECT id FROM system_unit WHERE tenant_id = @t2 AND name = 'F7B teste Unit 2');
SET @user2 = (SELECT id FROM system_users WHERE login = 'f7b.tenant2@example.invalid' AND name LIKE 'F7B teste%');

-- =============================================================================================
-- 1. Identificação e contagens antes (só leitura)
-- =============================================================================================
SELECT @t2 AS t2, @u2 AS u2, @user2 AS user2, (@t2 IS NOT NULL AND @t2 > 1) AS t2_ok;  -- t2_ok = 1, senão PARE
SELECT id, slug, legal_name, status, created_at FROM tenant WHERE id = @t2;
-- ^ unidades do tenant 2 além da de teste (deve ser vazio; se listar, PARE e reveja)
SELECT 'unidade_extra' AS t, id, name FROM system_unit WHERE tenant_id = @t2 AND id <> @u2;
-- ^ usuários do tenant 2 além do Admin 2 (criados no E2E? decidir à parte; deve ser vazio)
SELECT 'usuario_extra' AS t, tu.system_user_id, u.login FROM tenant_user tu JOIN system_users u ON u.id = tu.system_user_id
 WHERE tu.tenant_id = @t2 AND tu.system_user_id <> @user2;
-- ^ o Admin 2 ligado a outro tenant ou unidade de outro tenant (deve ser vazio; se listar, PARE)
SELECT 'admin2_fora' AS t, tenant_id FROM tenant_user WHERE system_user_id = @user2 AND tenant_id <> @t2;
SELECT 'admin2_unidade_fora' AS t, uu.system_unit_id FROM system_user_unit uu JOIN system_unit s ON s.id = uu.system_unit_id
 WHERE uu.system_user_id = @user2 AND s.tenant_id <> @t2;
-- ^ linhas de OUTRO tenant que referenciam o Admin 2 ou a Unit 2 (todos 0; se não, PARE: o FK
--   RESTRICT interromperia o DELETE do usuário/unidade e indicaria vazamento entre tenants)
SELECT COUNT(*) AS objetos_outro_tenant_admin2   FROM stored_object      WHERE created_by = @user2 AND tenant_id <> @t2;
SELECT COUNT(*) AS documentos_outro_tenant_admin2 FROM generated_document WHERE requested_by_system_user_id = @user2 AND tenant_id <> @t2;
SELECT COUNT(*) AS encounters_outro_tenant_admin2 FROM encounter          WHERE professional_system_user_id = @user2 AND tenant_id <> @t2;
SELECT COUNT(*) AS objetos_outro_tenant_unit2    FROM stored_object      WHERE system_unit_id = @u2 AND tenant_id <> @t2;
SELECT COUNT(*) AS documentos_outro_tenant_unit2 FROM generated_document WHERE system_unit_id = @u2 AND tenant_id <> @t2;
-- contagens do tenant 2 (o que será apagado)
SELECT 'tutor' AS t, COUNT(*) AS n FROM tutor WHERE tenant_id = @t2
UNION ALL SELECT 'patient', COUNT(*) FROM patient WHERE tenant_id = @t2
UNION ALL SELECT 'generated_document', COUNT(*) FROM generated_document WHERE tenant_id = @t2
UNION ALL SELECT 'stored_object', COUNT(*) FROM stored_object WHERE tenant_id = @t2
UNION ALL SELECT 'document_template', COUNT(*) FROM document_template WHERE tenant_id = @t2
UNION ALL SELECT 'communication_message', COUNT(*) FROM communication_message WHERE tenant_id = @t2
UNION ALL SELECT 'communication_preference', COUNT(*) FROM communication_preference WHERE tenant_id = @t2
UNION ALL SELECT 'encounter', COUNT(*) FROM encounter WHERE tenant_id = @t2
UNION ALL SELECT 'appointment', COUNT(*) FROM appointment WHERE tenant_id = @t2
UNION ALL SELECT 'vaccination', COUNT(*) FROM vaccination WHERE tenant_id = @t2
UNION ALL SELECT 'vaccine_catalog_item', COUNT(*) FROM vaccine_catalog_item WHERE tenant_id = @t2
UNION ALL SELECT 'prescription', COUNT(*) FROM prescription WHERE tenant_id = @t2
UNION ALL SELECT 'surgery', COUNT(*) FROM surgery WHERE tenant_id = @t2
UNION ALL SELECT 'product', COUNT(*) FROM product WHERE tenant_id = @t2
UNION ALL SELECT 'payment', COUNT(*) FROM payment WHERE tenant_id = @t2
UNION ALL SELECT 'stock_movement', COUNT(*) FROM stock_movement WHERE tenant_id = @t2
UNION ALL SELECT 'audit_log (fica)', COUNT(*) FROM audit_log WHERE tenant_id = @t2;
-- totais globais antes (para comparar com 4.)
SELECT 'tenant' AS t, COUNT(*) AS n FROM tenant
UNION ALL SELECT 'system_unit', COUNT(*) FROM system_unit
UNION ALL SELECT 'system_users', COUNT(*) FROM system_users
UNION ALL SELECT 'tutor', COUNT(*) FROM tutor
UNION ALL SELECT 'patient', COUNT(*) FROM patient
UNION ALL SELECT 'generated_document', COUNT(*) FROM generated_document
UNION ALL SELECT 'stored_object', COUNT(*) FROM stored_object
UNION ALL SELECT 'audit_log', COUNT(*) FROM audit_log;
-- logs Adianti do login de teste (ficam; só contagem)
SELECT COUNT(*) AS access_log_admin2 FROM system_access_log WHERE login = 'f7b.tenant2@example.invalid';
-- revisão dos cadastros (sem e-mail, telefone nem texto)
SELECT 'tutor' AS t, id, full_name, created_at FROM tutor WHERE tenant_id = @t2;
SELECT 'patient' AS t, id, name, tutor_id FROM patient WHERE tenant_id = @t2;
SELECT 'document' AS t, id, kind, source_type, source_id, patient_id, version, status, stored_object_id, created_at
  FROM generated_document WHERE tenant_id = @t2;
-- ^ cadastros do tenant 2 fora do prefixo 'F7B teste' (o E2E deve usar o prefixo; se listar,
--   confira que são do E2E antes de seguir — todo o tenant 2 é de teste)
SELECT 'tutor_fora_prefixo' AS t, id FROM tutor WHERE tenant_id = @t2 AND full_name NOT LIKE 'F7B teste%';
SELECT 'patient_fora_prefixo' AS t, id FROM patient WHERE tenant_id = @t2 AND name NOT LIKE 'F7B teste%';

-- =============================================================================================
-- 2. Arquivos (gerar aqui; executar só depois do COMMIT, com confirmação)
-- =============================================================================================
SELECT CONCAT('docker compose exec -T app rm -f -- /var/www/html/var/documents/', o.object_key) AS rm_cmd
  FROM stored_object o WHERE o.tenant_id = @t2 AND o.storage_provider = 'local' AND @t2 > 1;
SELECT CONCAT('docker compose exec -T app rm -rf -- /var/www/html/var/documents/cv/development/tenant/', @t2, '/') AS rm_prefixo
  FROM DUAL WHERE @t2 > 1;
SELECT 'objeto_s3' AS t, o.id, o.storage_provider, o.bucket, o.object_key
  FROM stored_object o WHERE o.tenant_id = @t2 AND o.storage_provider <> 'local';

-- =============================================================================================
-- 3. Limpeza (ordem das FKs). Todo DELETE tem WHERE por tenant_id = @t2 AND @t2 > 1, ou pelo id
--    do usuário/unidade de teste.
-- =============================================================================================

-- 3.1 Comunicação
DELETE FROM communication_message    WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM communication_preference WHERE tenant_id = @t2 AND @t2 > 1;

-- 3.2 Documentos: generated_document → stored_object → document_template
DELETE FROM generated_document WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM stored_object      WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM document_template  WHERE tenant_id = @t2 AND @t2 > 1;

-- 3.3 Financeiro e vendas
DELETE FROM payment                WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM receivable             WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM encounter_account_item WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM encounter_account      WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM sale_item              WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM sale                   WHERE tenant_id = @t2 AND @t2 > 1;

-- 3.4 Internação
DELETE FROM hospitalization_administration WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM hospitalization_event          WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM hospitalization_order          WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM hospitalization                WHERE tenant_id = @t2 AND @t2 > 1;

-- 3.5 Cirurgia, procedimentos, receita, vacinação e exames
DELETE FROM surgery_checklist   WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM surgery_event       WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM surgery_material    WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM surgery_team        WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM surgery             WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM procedure_execution WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM prescription_item   WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM prescription        WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM vaccination         WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM exam_result         WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM exam_request        WHERE tenant_id = @t2 AND @t2 > 1;

-- 3.6 Atendimento, fila, agendamento, pacientes e tutores
DELETE FROM appointment_followup WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM queue_entry          WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM encounter            WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM appointment          WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM patient              WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM tutor                WHERE tenant_id = @t2 AND @t2 > 1;

-- 3.7 Estoque e catálogos
DELETE FROM stock_movement               WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM stock_batch                  WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM procedure_catalog_item_input WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM procedure_catalog_item       WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM product                      WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM vaccine_protocol             WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM vaccine_catalog_item         WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM exam_catalog_item            WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM prescription_template_item   WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM prescription_template        WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM message_template             WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM service                      WHERE tenant_id = @t2 AND @t2 > 1;

-- 3.8 Caixa, contas, leitos e salas
DELETE FROM financial_entry WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM payable         WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM cash_session    WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM bank_account    WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM bed             WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM surgery_room    WHERE tenant_id = @t2 AND @t2 > 1;

-- 3.9 Usuário, unidade e tenant (audit_log fica: só apaga o que ele não referencia)
DELETE FROM system_user_group        WHERE system_user_id = @user2;
DELETE FROM system_user_unit         WHERE system_user_id = @user2;
DELETE FROM system_user_role         WHERE system_user_id = @user2;
DELETE FROM system_user_program      WHERE system_user_id = @user2;
DELETE FROM system_user_old_password WHERE system_user_id = @user2;
DELETE FROM tenant_user              WHERE tenant_id = @t2 AND system_user_id = @user2 AND @t2 > 1;
-- usuário: só sem referência no audit_log (o FK SET NULL alteraria a trilha)
DELETE FROM system_users
 WHERE id = @user2 AND login = 'f7b.tenant2@example.invalid' AND name LIKE 'F7B teste%'
   AND NOT EXISTS (SELECT 1 FROM audit_log a WHERE a.system_user_id = @user2);
-- se ficou: neutralizado (sem login possível)
UPDATE system_users SET active = 'N'
 WHERE id = @user2 AND login = 'f7b.tenant2@example.invalid' AND name LIKE 'F7B teste%';
-- unidade: só sem audit_log e sem usuário apontando para ela
DELETE FROM system_unit
 WHERE id = @u2 AND tenant_id = @t2 AND @t2 > 1 AND name = 'F7B teste Unit 2'
   AND NOT EXISTS (SELECT 1 FROM audit_log a WHERE a.system_unit_id = @u2)
   AND NOT EXISTS (SELECT 1 FROM system_users s WHERE s.system_unit_id = @u2);
DELETE FROM tenant_group WHERE tenant_id = @t2 AND @t2 > 1;
DELETE FROM tenant_role  WHERE tenant_id = @t2 AND @t2 > 1;
-- tenant: só sem audit_log, unidade nem membro restante
DELETE FROM tenant
 WHERE id = @t2 AND @t2 > 1 AND slug = 'f7b-teste-clinica-2' AND legal_name LIKE 'F7B teste%'
   AND NOT EXISTS (SELECT 1 FROM audit_log a   WHERE a.tenant_id = @t2)
   AND NOT EXISTS (SELECT 1 FROM system_unit s WHERE s.tenant_id = @t2)
   AND NOT EXISTS (SELECT 1 FROM tenant_user u WHERE u.tenant_id = @t2);
-- se ficou: cancelado
UPDATE tenant SET status = 'cancelled'
 WHERE id = @t2 AND @t2 > 1 AND slug = 'f7b-teste-clinica-2' AND legal_name LIKE 'F7B teste%';

-- =============================================================================================
-- 4. Conferência
-- =============================================================================================
-- dados do tenant 2 (esperado 0 em todas, exceto audit_log)
SELECT 'tutor' AS t, COUNT(*) AS n FROM tutor WHERE tenant_id = @t2
UNION ALL SELECT 'patient', COUNT(*) FROM patient WHERE tenant_id = @t2
UNION ALL SELECT 'generated_document', COUNT(*) FROM generated_document WHERE tenant_id = @t2
UNION ALL SELECT 'stored_object', COUNT(*) FROM stored_object WHERE tenant_id = @t2
UNION ALL SELECT 'document_template', COUNT(*) FROM document_template WHERE tenant_id = @t2
UNION ALL SELECT 'communication_message', COUNT(*) FROM communication_message WHERE tenant_id = @t2
UNION ALL SELECT 'encounter', COUNT(*) FROM encounter WHERE tenant_id = @t2
UNION ALL SELECT 'vaccination', COUNT(*) FROM vaccination WHERE tenant_id = @t2
UNION ALL SELECT 'vaccine_catalog_item', COUNT(*) FROM vaccine_catalog_item WHERE tenant_id = @t2
UNION ALL SELECT 'prescription', COUNT(*) FROM prescription WHERE tenant_id = @t2
UNION ALL SELECT 'product', COUNT(*) FROM product WHERE tenant_id = @t2
UNION ALL SELECT 'tenant_group', COUNT(*) FROM tenant_group WHERE tenant_id = @t2
UNION ALL SELECT 'tenant_user', COUNT(*) FROM tenant_user WHERE tenant_id = @t2
UNION ALL SELECT 'system_user_unit', COUNT(*) FROM system_user_unit WHERE system_user_id = @user2
UNION ALL SELECT 'system_user_group', COUNT(*) FROM system_user_group WHERE system_user_id = @user2
UNION ALL SELECT 'audit_log (fica)', COUNT(*) FROM audit_log WHERE tenant_id = @t2;
-- resíduo esperado: vazio se o audit_log não tinha linhas do tenant 2; senão tenant 'cancelled',
-- Unit 2 e Admin 2 (active = 'N') ficam por causa do FK RESTRICT do audit_log
SELECT 'tenant_residuo'  AS t, id, slug, status FROM tenant       WHERE id = @t2;
SELECT 'unidade_residuo' AS t, id, name         FROM system_unit  WHERE id = @u2;
SELECT 'usuario_residuo' AS t, id, login, active FROM system_users WHERE id = @user2;
-- tenant 1 intacto
SELECT id, slug, status FROM tenant ORDER BY id;
SELECT id, tenant_id, name FROM system_unit ORDER BY id;
SELECT 'tenant' AS t, COUNT(*) AS n FROM tenant
UNION ALL SELECT 'system_unit', COUNT(*) FROM system_unit
UNION ALL SELECT 'system_users', COUNT(*) FROM system_users
UNION ALL SELECT 'tutor', COUNT(*) FROM tutor
UNION ALL SELECT 'patient', COUNT(*) FROM patient
UNION ALL SELECT 'generated_document', COUNT(*) FROM generated_document
UNION ALL SELECT 'stored_object', COUNT(*) FROM stored_object
UNION ALL SELECT 'audit_log', COUNT(*) FROM audit_log;   -- audit_log igual ao "antes"

-- Confira tudo acima. Se bater, descomente e rode o COMMIT; senão, ROLLBACK.
-- Depois do COMMIT, rode os comandos da seção 2 (com confirmação).
-- ROLLBACK;
-- COMMIT;

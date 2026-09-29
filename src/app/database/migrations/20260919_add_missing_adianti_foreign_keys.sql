-- Central Vet Pro / Adianti 8.6
-- Migration preparada em 2026-09-19; NÃO aplicada automaticamente.
--
-- Escopo exato:
--   * 15 foreign keys originalmente declaradas inline em communication.sql;
--   * 1 foreign key inline de system_users.system_unit_id em permission.sql;
--   * 1 índice auxiliar, sys_users_unit_idx, criado no mesmo ALTER de system_users.
--
-- Prechecks obrigatórios antes de solicitar/executar esta migration:
--   1. Confirmar MySQL 8.0.43 e DATABASE() = 'centralvet'.
--   2. Confirmar baseline de 40 tabelas, 87 índices não primários e 14 FKs.
--   3. Executar a consulta de órfãos abaixo e exigir violations = 0 em todas as linhas.
--   4. Obter backup/snapshot verificável e autorização SQL específica para os 16 ALTERs.
--
-- SELECT 'fk_users_unit' AS check_name, COUNT(*) AS violations
--   FROM system_users c LEFT JOIN system_unit p ON p.id = c.system_unit_id
--  WHERE c.system_unit_id IS NOT NULL AND p.id IS NULL
-- UNION ALL SELECT 'fk_message_tag_message', COUNT(*) FROM system_message_tag c LEFT JOIN system_message p ON p.id=c.system_message_id WHERE c.system_message_id IS NOT NULL AND p.id IS NULL
-- UNION ALL SELECT 'fk_folder_parent', COUNT(*) FROM system_folder c LEFT JOIN system_folder p ON p.id=c.system_folder_parent_id WHERE c.system_folder_parent_id IS NOT NULL AND p.id IS NULL
-- UNION ALL SELECT 'fk_folder_user_folder', COUNT(*) FROM system_folder_user c LEFT JOIN system_folder p ON p.id=c.system_folder_id WHERE c.system_folder_id IS NOT NULL AND p.id IS NULL
-- UNION ALL SELECT 'fk_folder_group_folder', COUNT(*) FROM system_folder_group c LEFT JOIN system_folder p ON p.id=c.system_folder_id WHERE c.system_folder_id IS NOT NULL AND p.id IS NULL
-- UNION ALL SELECT 'fk_document_folder', COUNT(*) FROM system_document c LEFT JOIN system_folder p ON p.id=c.system_folder_id WHERE c.system_folder_id IS NOT NULL AND p.id IS NULL
-- UNION ALL SELECT 'fk_document_user_document', COUNT(*) FROM system_document_user c LEFT JOIN system_document p ON p.id=c.document_id WHERE c.document_id IS NOT NULL AND p.id IS NULL
-- UNION ALL SELECT 'fk_document_group_document', COUNT(*) FROM system_document_group c LEFT JOIN system_document p ON p.id=c.document_id WHERE c.document_id IS NOT NULL AND p.id IS NULL
-- UNION ALL SELECT 'fk_document_bookmark_document', COUNT(*) FROM system_document_bookmark c LEFT JOIN system_document p ON p.id=c.system_document_id WHERE c.system_document_id IS NOT NULL AND p.id IS NULL
-- UNION ALL SELECT 'fk_folder_bookmark_folder', COUNT(*) FROM system_folder_bookmark c LEFT JOIN system_folder p ON p.id=c.system_folder_id WHERE c.system_folder_id IS NOT NULL AND p.id IS NULL
-- UNION ALL SELECT 'fk_post_share_group_post', COUNT(*) FROM system_post_share_group c LEFT JOIN system_post p ON p.id=c.system_post_id WHERE c.system_post_id IS NOT NULL AND p.id IS NULL
-- UNION ALL SELECT 'fk_post_tag_post', COUNT(*) FROM system_post_tag c LEFT JOIN system_post p ON p.id=c.system_post_id WHERE c.system_post_id IS NOT NULL AND p.id IS NULL
-- UNION ALL SELECT 'fk_post_comment_post', COUNT(*) FROM system_post_comment c LEFT JOIN system_post p ON p.id=c.system_post_id WHERE c.system_post_id IS NOT NULL AND p.id IS NULL
-- UNION ALL SELECT 'fk_post_like_post', COUNT(*) FROM system_post_like c LEFT JOIN system_post p ON p.id=c.system_post_id WHERE c.system_post_id IS NOT NULL AND p.id IS NULL
-- UNION ALL SELECT 'fk_wiki_tag_page', COUNT(*) FROM system_wiki_tag c LEFT JOIN system_wiki_page p ON p.id=c.system_wiki_page_id WHERE c.system_wiki_page_id IS NOT NULL AND p.id IS NULL
-- UNION ALL SELECT 'fk_wiki_share_group_page', COUNT(*) FROM system_wiki_share_group c LEFT JOIN system_wiki_page p ON p.id=c.system_wiki_page_id WHERE c.system_wiki_page_id IS NOT NULL AND p.id IS NULL;
--
-- IMPORTANTE: DDL no MySQL possui commits implícitos; esta migration não é atômica.
-- Em erro, interromper e inspecionar o estado. Não há DROP/rollback destrutivo automático.
-- Validação esperada após aplicação autorizada: 30 FKs e 88 índices não primários.

ALTER TABLE system_users
    ADD INDEX sys_users_unit_idx (system_unit_id),
    ADD CONSTRAINT fk_users_unit
        FOREIGN KEY (system_unit_id) REFERENCES system_unit (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE system_message_tag
    ADD CONSTRAINT fk_message_tag_message
        FOREIGN KEY (system_message_id) REFERENCES system_message (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE system_folder
    ADD CONSTRAINT fk_folder_parent
        FOREIGN KEY (system_folder_parent_id) REFERENCES system_folder (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE system_folder_user
    ADD CONSTRAINT fk_folder_user_folder
        FOREIGN KEY (system_folder_id) REFERENCES system_folder (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE system_folder_group
    ADD CONSTRAINT fk_folder_group_folder
        FOREIGN KEY (system_folder_id) REFERENCES system_folder (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE system_document
    ADD CONSTRAINT fk_document_folder
        FOREIGN KEY (system_folder_id) REFERENCES system_folder (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE system_document_user
    ADD CONSTRAINT fk_document_user_document
        FOREIGN KEY (document_id) REFERENCES system_document (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE system_document_group
    ADD CONSTRAINT fk_document_group_document
        FOREIGN KEY (document_id) REFERENCES system_document (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE system_document_bookmark
    ADD CONSTRAINT fk_document_bookmark_document
        FOREIGN KEY (system_document_id) REFERENCES system_document (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE system_folder_bookmark
    ADD CONSTRAINT fk_folder_bookmark_folder
        FOREIGN KEY (system_folder_id) REFERENCES system_folder (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE system_post_share_group
    ADD CONSTRAINT fk_post_share_group_post
        FOREIGN KEY (system_post_id) REFERENCES system_post (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE system_post_tag
    ADD CONSTRAINT fk_post_tag_post
        FOREIGN KEY (system_post_id) REFERENCES system_post (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE system_post_comment
    ADD CONSTRAINT fk_post_comment_post
        FOREIGN KEY (system_post_id) REFERENCES system_post (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE system_post_like
    ADD CONSTRAINT fk_post_like_post
        FOREIGN KEY (system_post_id) REFERENCES system_post (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE system_wiki_tag
    ADD CONSTRAINT fk_wiki_tag_page
        FOREIGN KEY (system_wiki_page_id) REFERENCES system_wiki_page (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT;

ALTER TABLE system_wiki_share_group
    ADD CONSTRAINT fk_wiki_share_group_page
        FOREIGN KEY (system_wiki_page_id) REFERENCES system_wiki_page (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT;

-- Central Vet Pro - Foundation / multi-tenancy
-- Migration: 20260920_0001_foundation_multitenancy
-- Status: PREPARED ONLY. Do not execute without a fresh backup and explicit SQL approval.
-- Target: MySQL 8.0.x, database centralvet, Adianti 8.6 baseline already installed.
--
-- Effects:
--   * creates schema_migrations, tenant, tenant_user, tenant_group, tenant_role,
--     audit_log and stored_object;
--   * adds system_unit.tenant_id;
--   * creates one compatibility tenant (id=1), links all legacy users/groups/
--     roles/units to it, and then makes system_unit.tenant_id NOT NULL;
--   * creates 18 secondary indexes, 14 foreign keys and 4 unique constraints.
--
-- MySQL DDL commits implicitly. On failure, stop and inspect; do not rerun blindly.
-- The literal migration checksum below MUST be replaced with the approved artifact's
-- SHA-256 by the migration executor before this file is applied.

CREATE TABLE schema_migrations (
    version varchar(100) NOT NULL,
    checksum char(64) NOT NULL,
    applied_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    execution_ms int unsigned NULL,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE tenant (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    public_id char(36) NOT NULL,
    slug varchar(120) NOT NULL,
    legal_name varchar(255) NOT NULL,
    trade_name varchar(255) NULL,
    status varchar(20) NOT NULL DEFAULT 'active',
    timezone varchar(64) NOT NULL DEFAULT 'America/Fortaleza',
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY tenant_public_id_uq (public_id),
    UNIQUE KEY tenant_slug_uq (slug),
    CONSTRAINT tenant_status_ck CHECK (status IN ('active', 'suspended', 'cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Compatibility tenant for the existing Adianti seed. Rename it through the
-- future tenant administration service after the foundation is active.
INSERT INTO tenant (id, public_id, slug, legal_name, trade_name, status)
VALUES (1, '00000000-0000-4000-8000-000000000001', 'centralvet-inicial',
        'Central Vet Pro - Tenant inicial', 'Central Vet', 'active');

ALTER TABLE system_unit
    ADD COLUMN tenant_id bigint unsigned NULL AFTER id,
    ADD INDEX system_unit_tenant_idx (tenant_id),
    ADD CONSTRAINT system_unit_tenant_fk
        FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT;

UPDATE system_unit SET tenant_id = 1 WHERE tenant_id IS NULL;

ALTER TABLE system_unit
    MODIFY COLUMN tenant_id bigint unsigned NOT NULL;

CREATE TABLE tenant_user (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    system_user_id int NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'active',
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY tenant_user_membership_uq (tenant_id, system_user_id),
    KEY tenant_user_user_idx (system_user_id),
    CONSTRAINT tenant_user_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT tenant_user_user_fk FOREIGN KEY (system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT tenant_user_status_ck CHECK (status IN ('invited', 'active', 'inactive'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE tenant_group (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    system_group_id int NOT NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY tenant_group_membership_uq (tenant_id, system_group_id),
    KEY tenant_group_group_idx (system_group_id),
    CONSTRAINT tenant_group_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT tenant_group_group_fk FOREIGN KEY (system_group_id) REFERENCES system_group (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE tenant_role (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    system_role_id int NOT NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY tenant_role_membership_uq (tenant_id, system_role_id),
    KEY tenant_role_role_idx (system_role_id),
    CONSTRAINT tenant_role_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT tenant_role_role_fk FOREIGN KEY (system_role_id) REFERENCES system_role (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO tenant_user (tenant_id, system_user_id)
SELECT 1, id FROM system_users;

INSERT INTO tenant_group (tenant_id, system_group_id)
SELECT 1, id FROM system_group;

INSERT INTO tenant_role (tenant_id, system_role_id)
SELECT 1, id FROM system_role;

CREATE TABLE audit_log (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    system_unit_id int NULL,
    system_user_id int NULL,
    correlation_id varchar(64) NOT NULL,
    action varchar(100) NOT NULL,
    entity_type varchar(190) NOT NULL,
    entity_id varchar(190) NULL,
    before_data json NULL,
    after_data json NULL,
    metadata json NULL,
    ip_address varchar(45) NULL,
    user_agent varchar(512) NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY audit_log_tenant_created_idx (tenant_id, created_at),
    KEY audit_log_tenant_entity_idx (tenant_id, entity_type, entity_id),
    KEY audit_log_user_created_idx (system_user_id, created_at),
    KEY audit_log_correlation_idx (correlation_id),
    CONSTRAINT audit_log_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT audit_log_unit_fk FOREIGN KEY (system_unit_id) REFERENCES system_unit (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT audit_log_user_fk FOREIGN KEY (system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE stored_object (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    public_id char(36) NOT NULL,
    tenant_id bigint unsigned NOT NULL,
    system_unit_id int NULL,
    storage_provider varchar(32) NOT NULL,
    bucket varchar(255) NOT NULL,
    object_key varchar(1024) NOT NULL,
    version_id varchar(255) NULL,
    original_name varchar(512) NOT NULL,
    content_type varchar(190) NOT NULL,
    size_bytes bigint unsigned NOT NULL,
    sha256 char(64) NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'pending',
    created_by int NOT NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    deleted_at timestamp(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY stored_object_public_id_uq (public_id),
    KEY stored_object_tenant_created_idx (tenant_id, created_at),
    KEY stored_object_tenant_status_idx (tenant_id, status),
    KEY stored_object_unit_idx (system_unit_id),
    KEY stored_object_creator_idx (created_by),
    KEY stored_object_locator_idx (tenant_id, bucket, object_key(255)),
    CONSTRAINT stored_object_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT stored_object_unit_fk FOREIGN KEY (system_unit_id) REFERENCES system_unit (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT stored_object_creator_fk FOREIGN KEY (created_by) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT stored_object_status_ck CHECK (status IN ('pending', 'available', 'quarantined', 'deleted'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO schema_migrations (version, checksum)
VALUES ('20260920_0001_foundation_multitenancy',
        '1701cd1f1efec000bf5021ab5370b4d482c81784a325d456a1d1dbd2f3c746c9');

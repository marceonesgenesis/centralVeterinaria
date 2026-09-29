-- Central Vet Pro - Phase 4 procedure, stock and sale (PDV)
-- Migration: 20260924_0005_phase4_procedure_stock_sale
-- Status: PREPARED ONLY. Do not execute without a fresh backup and explicit SQL approval.
-- Target: MySQL 8.0.x, database centralvet, after 20260922_0004_phase3_prescription_exam_vaccine
-- has been applied (tenant, system_unit, system_users, tutor, patient, encounter and
-- schema_migrations already exist).
--
-- Effects:
--   * creates product (tenant-scoped catalog of sellable/consumable items, with unit of
--     measure, unit cost and a minimum stock threshold used by the application, not a
--     DB constraint);
--   * creates stock_batch (a physical lot of a product received at a system_unit, with
--     optional lot code/expiry date and a quantity on hand), with a composite index on
--     (system_unit_id, product_id, expiry_date) so the application can consume batches
--     in expiry order (oldest expiry first) without a table scan;
--   * creates stock_movement (an immutable ledger row for every stock in/out/adjustment,
--     tied to a batch, with a typed reason and an optional generic reference to the
--     document that caused it — procedure execution or sale — plus the professional who
--     triggered it);
--   * creates procedure_catalog_item (tenant-scoped catalog of billable procedures, with
--     price, optional duration and preparation instructions) and
--     procedure_catalog_item_input (the bill-of-materials of a catalog item: which
--     products it consumes and how many units per execution);
--   * creates procedure_execution (an actual execution of a catalog item for a patient
--     inside an encounter, by a professional — the record that drives stock consumption
--     for the procedure's declared inputs);
--   * creates sale (a PDV transaction header for a tutor, optionally tied to a patient
--     and/or encounter, with a status and total) and sale_item (its line items, each
--     either a product or a procedure sold, with a generic item_reference_id that is
--     NOT a database foreign key — see Risk below);
--   * adds FKs from stock_batch/stock_movement/procedure_execution/sale to system_unit(id)
--     or system_users(id) as applicable; from stock_batch/stock_movement/
--     procedure_catalog_item_input to product(id); from stock_movement to
--     stock_batch(id); from procedure_catalog_item_input/procedure_execution to
--     procedure_catalog_item(id); from procedure_execution/sale to encounter(id) and
--     patient(id); from sale to tutor(id); from sale_item to sale(id);
--   * creates 8 tables, 6 CHECK constraints (stock_movement.movement_type,
--     stock_movement.reason, procedure_catalog_item_input.quantity_per_execution,
--     sale.status, sale_item.item_type, sale_item.quantity), 3 UNIQUE constraints and
--     26 foreign keys.
--
-- No data is seeded by this migration: all 8 tables start empty for every existing
-- tenant.
--
-- Risk: stock_movement is an append-only ledger; the application, not this schema,
-- keeps stock_batch.quantity consistent with the sum of its movements — no trigger
-- enforces that invariant. sale_item.item_reference_id intentionally has NO database
-- foreign key: it points to either product(id) or product/procedure(id) depending on
-- item_type, and MySQL cannot express a polymorphic FK; referential integrity for that
-- column is the Application service's responsibility (documented as a simplification in
-- notes.md, same pattern already accepted for reference_type/reference_id on
-- stock_movement). No new PII is introduced: sale references tutor/patient/encounter,
-- which already carry whatever PII they carry.
--
-- MySQL DDL commits implicitly. On failure, stop and inspect; do not rerun blindly.
-- The literal migration checksum below MUST be replaced with the approved artifact's
-- SHA-256 by the migration executor before this file is applied.

CREATE TABLE product (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    name varchar(190) NOT NULL,
    category varchar(190) NULL,
    unit_of_measure varchar(20) NOT NULL,
    unit_cost_cents int unsigned NOT NULL,
    minimum_stock_quantity int unsigned NOT NULL DEFAULT 0,
    active tinyint(1) NOT NULL DEFAULT 1,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY product_tenant_name_uq (tenant_id, name),
    KEY product_tenant_idx (tenant_id),
    KEY product_tenant_active_idx (tenant_id, active),
    CONSTRAINT product_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE stock_batch (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    system_unit_id int NOT NULL,
    product_id bigint unsigned NOT NULL,
    lot varchar(60) NULL,
    expiry_date date NULL,
    quantity int unsigned NOT NULL DEFAULT 0,
    received_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY stock_batch_tenant_idx (tenant_id),
    KEY stock_batch_unit_product_expiry_idx (system_unit_id, product_id, expiry_date),
    KEY stock_batch_product_idx (product_id),
    CONSTRAINT stock_batch_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT stock_batch_unit_fk FOREIGN KEY (system_unit_id) REFERENCES system_unit (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT stock_batch_product_fk FOREIGN KEY (product_id) REFERENCES product (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE stock_movement (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    system_unit_id int NOT NULL,
    product_id bigint unsigned NOT NULL,
    stock_batch_id bigint unsigned NOT NULL,
    movement_type varchar(20) NOT NULL,
    quantity int unsigned NOT NULL,
    reason varchar(40) NOT NULL,
    reference_type varchar(40) NULL,
    reference_id bigint unsigned NULL,
    professional_system_user_id int NOT NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY stock_movement_tenant_idx (tenant_id),
    KEY stock_movement_product_idx (product_id),
    KEY stock_movement_batch_idx (stock_batch_id),
    KEY stock_movement_unit_idx (system_unit_id),
    KEY stock_movement_professional_idx (professional_system_user_id),
    KEY stock_movement_reference_idx (reference_type, reference_id),
    CONSTRAINT stock_movement_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT stock_movement_unit_fk FOREIGN KEY (system_unit_id) REFERENCES system_unit (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT stock_movement_product_fk FOREIGN KEY (product_id) REFERENCES product (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT stock_movement_batch_fk FOREIGN KEY (stock_batch_id) REFERENCES stock_batch (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT stock_movement_professional_fk FOREIGN KEY (professional_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT stock_movement_type_ck CHECK (movement_type IN ('in', 'out', 'adjustment')),
    CONSTRAINT stock_movement_reason_ck CHECK (reason IN ('purchase_entry', 'procedure_consumption', 'sale_consumption', 'manual_adjustment'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE procedure_catalog_item (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    name varchar(190) NOT NULL,
    price_cents int unsigned NOT NULL,
    duration_minutes int unsigned NULL,
    preparation_text text NULL,
    active tinyint(1) NOT NULL DEFAULT 1,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY procedure_catalog_item_tenant_name_uq (tenant_id, name),
    KEY procedure_catalog_item_tenant_idx (tenant_id),
    KEY procedure_catalog_item_tenant_active_idx (tenant_id, active),
    CONSTRAINT procedure_catalog_item_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE procedure_catalog_item_input (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    procedure_catalog_item_id bigint unsigned NOT NULL,
    product_id bigint unsigned NOT NULL,
    quantity_per_execution int unsigned NOT NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY procedure_catalog_item_input_item_product_uq (procedure_catalog_item_id, product_id),
    KEY procedure_catalog_item_input_tenant_idx (tenant_id),
    KEY procedure_catalog_item_input_product_idx (product_id),
    CONSTRAINT procedure_catalog_item_input_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT procedure_catalog_item_input_item_fk FOREIGN KEY (procedure_catalog_item_id) REFERENCES procedure_catalog_item (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT procedure_catalog_item_input_product_fk FOREIGN KEY (product_id) REFERENCES product (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT procedure_catalog_item_input_qty_ck CHECK (quantity_per_execution >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE procedure_execution (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    encounter_id bigint unsigned NOT NULL,
    patient_id bigint unsigned NOT NULL,
    procedure_catalog_item_id bigint unsigned NOT NULL,
    professional_system_user_id int NOT NULL,
    notes_text text NULL,
    executed_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY procedure_execution_tenant_idx (tenant_id),
    KEY procedure_execution_encounter_idx (encounter_id),
    KEY procedure_execution_patient_idx (patient_id),
    KEY procedure_execution_item_idx (procedure_catalog_item_id),
    KEY procedure_execution_professional_idx (professional_system_user_id),
    CONSTRAINT procedure_execution_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT procedure_execution_encounter_fk FOREIGN KEY (encounter_id) REFERENCES encounter (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT procedure_execution_patient_fk FOREIGN KEY (patient_id) REFERENCES patient (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT procedure_execution_item_fk FOREIGN KEY (procedure_catalog_item_id) REFERENCES procedure_catalog_item (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT procedure_execution_professional_fk FOREIGN KEY (professional_system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE sale (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    system_unit_id int NOT NULL,
    tutor_id bigint unsigned NOT NULL,
    patient_id bigint unsigned NULL,
    encounter_id bigint unsigned NULL,
    system_user_id int NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'completed',
    total_amount_cents int unsigned NOT NULL,
    sold_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY sale_tenant_idx (tenant_id),
    KEY sale_tenant_unit_sold_at_idx (tenant_id, system_unit_id, sold_at),
    KEY sale_tutor_idx (tutor_id),
    KEY sale_patient_idx (patient_id),
    KEY sale_encounter_idx (encounter_id),
    KEY sale_system_user_idx (system_user_id),
    CONSTRAINT sale_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT sale_unit_fk FOREIGN KEY (system_unit_id) REFERENCES system_unit (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT sale_tutor_fk FOREIGN KEY (tutor_id) REFERENCES tutor (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT sale_patient_fk FOREIGN KEY (patient_id) REFERENCES patient (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT sale_encounter_fk FOREIGN KEY (encounter_id) REFERENCES encounter (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT sale_system_user_fk FOREIGN KEY (system_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT sale_status_ck CHECK (status IN ('completed', 'cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE sale_item (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    tenant_id bigint unsigned NOT NULL,
    sale_id bigint unsigned NOT NULL,
    item_type varchar(20) NOT NULL,
    item_reference_id bigint unsigned NOT NULL,
    description_text varchar(255) NOT NULL,
    unit_price_cents int unsigned NOT NULL,
    quantity int unsigned NOT NULL,
    total_cents int unsigned NOT NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY sale_item_tenant_idx (tenant_id),
    KEY sale_item_sale_idx (sale_id),
    KEY sale_item_type_reference_idx (item_type, item_reference_id),
    CONSTRAINT sale_item_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT sale_item_sale_fk FOREIGN KEY (sale_id) REFERENCES sale (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT sale_item_type_ck CHECK (item_type IN ('product', 'procedure')),
    CONSTRAINT sale_item_quantity_ck CHECK (quantity >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO schema_migrations (version, checksum)
VALUES ('20260924_0005_phase4_procedure_stock_sale',
        'b2edc346035709275175b147c3e7829cc7d8bfc54d11b626c791f64c97f0f8cd');

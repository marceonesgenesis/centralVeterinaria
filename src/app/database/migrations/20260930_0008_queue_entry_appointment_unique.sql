-- Central Vet Pro - Round 2: one queue entry per appointment
-- Migration: 20260930_0008_queue_entry_appointment_unique
-- Status: PREPARED ONLY. Do not execute without a fresh backup and explicit SQL approval.
-- Target: MySQL 8.0.x, database centralvet, after 20260930_0007_rodada2_cadastros_financeiro
-- has been applied (queue_entry, appointment and schema_migrations already exist).
--
-- Effects (in this order):
--   * queue_entry: non-destructive dedupe. For every non-NULL appointment_id with more than
--     one entry, the entry with the lowest id (the first check-in) keeps the link and the
--     others get appointment_id = NULL (they become walk-in entries). No row is removed;
--   * queue_entry: adds UNIQUE KEY queue_entry_appointment_uq (appointment_id). MySQL
--     accepts multiple NULL values in a unique key, so walk-in entries are not affected.
--     The existing index queue_entry_appointment_idx and the foreign key
--     queue_entry_appointment_fk are kept.
--
-- Data: the only DML is the dedupe UPDATE and the schema_migrations audit row. COUNT(*) of
-- queue_entry must be the same before and after; COUNT(*) with appointment_id IS NULL grows
-- by the number of unlinked entries (checked by the .verify.sql). Before applying, record
-- in notes.md (Bloqueios) the rows of every duplicated group and COUNT(*) of queue_entry.
--
-- Risk: ALTER TABLE on queue_entry (small table; brief metadata lock). The UNIQUE key
-- cannot fail on existing data because the dedupe runs first. After this migration a
-- concurrent second check-in of the same appointment fails with a duplicate-key error
-- instead of creating a second entry (translated by the application in T-41).
--
-- Rollback: preferred path is restoring the pre-migration backup (make backup, see
-- docs/runbooks/restore-backup.md and docs/runbooks/migration-rollback.md). If data
-- written after the migration must be kept, prepare a reverse migration 0009 (not drafted
-- now) with its own backup and explicit SQL approval that runs
--   ALTER TABLE queue_entry DROP INDEX queue_entry_appointment_uq;
-- and an UPDATE restoring the appointment_id values recorded in notes.md (Bloqueios).
--
-- MySQL DDL commits implicitly. On failure, stop and inspect information_schema; do not
-- rerun blindly.
-- The literal migration checksum below MUST be replaced with the approved artifact's
-- SHA-256 by the migration executor before this file is applied.

UPDATE queue_entry q
JOIN (
    SELECT appointment_id, MIN(id) AS keep_id
    FROM queue_entry
    WHERE appointment_id IS NOT NULL
    GROUP BY appointment_id
    HAVING COUNT(*) > 1
) d ON q.appointment_id = d.appointment_id AND q.id <> d.keep_id
SET q.appointment_id = NULL;

ALTER TABLE queue_entry
    ADD UNIQUE KEY queue_entry_appointment_uq (appointment_id);

INSERT INTO schema_migrations (version, checksum)
VALUES ('20260930_0008_queue_entry_appointment_unique',
        '0000000000000000000000000000000000000000000000000000000000000000' /* 64-char PLACEHOLDER: replace before applying */);

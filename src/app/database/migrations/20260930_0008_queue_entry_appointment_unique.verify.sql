-- READ-ONLY verification for 20260930_0008_queue_entry_appointment_unique.
-- Safe to run only after the migration itself has been explicitly authorized.
-- Only SELECT statements. Compare the counts below with the ones recorded in notes.md
-- (Bloqueios) before the migration.

SELECT version, checksum, applied_at
FROM schema_migrations
WHERE version = '20260930_0008_queue_entry_appointment_unique';

-- New unique key (expected: 1 row, non_unique = 0, column appointment_id)
SELECT index_name, non_unique, seq_in_index, column_name, nullable
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'queue_entry'
  AND index_name = 'queue_entry_appointment_uq'
  AND non_unique = 0;

-- Kept index and foreign key (expected: 1 row each)
SELECT index_name, non_unique
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'queue_entry'
  AND index_name = 'queue_entry_appointment_idx';

SELECT constraint_name, referenced_table_name
FROM information_schema.referential_constraints
WHERE constraint_schema = DATABASE()
  AND table_name = 'queue_entry'
  AND constraint_name = 'queue_entry_appointment_fk';

-- Duplicated appointment groups left (expected: 0)
SELECT COUNT(*) AS duplicated_groups
FROM (
    SELECT appointment_id
    FROM queue_entry
    WHERE appointment_id IS NOT NULL
    GROUP BY appointment_id
    HAVING COUNT(*) > 1
) x;

-- Row count (expected: equal to the count recorded before the migration)
SELECT COUNT(*) AS queue_entry_rows FROM queue_entry;

-- Walk-in entries (expected: previous count + number of unlinked entries)
SELECT COUNT(*) AS queue_entry_without_appointment FROM queue_entry WHERE appointment_id IS NULL;

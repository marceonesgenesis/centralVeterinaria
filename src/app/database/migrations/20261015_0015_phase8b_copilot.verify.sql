-- READ-ONLY verification for 20261015_0015_phase8b_copilot.
-- Safe to run only after the migration itself has been explicitly authorized.
-- Record SELECT COUNT(*) FROM ai_interaction before applying; the count below must match.

SELECT version, checksum, applied_at
FROM schema_migrations
WHERE version = '20261015_0015_phase8b_copilot';

-- Tables (expected: 3 rows)
SELECT table_name, table_rows
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN ('ai_conversation', 'ai_conversation_message', 'mcp_tool_call')
ORDER BY table_name;

SELECT table_name, column_name, is_nullable, column_type, column_default, extra
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name IN ('ai_conversation', 'ai_conversation_message', 'mcp_tool_call')
ORDER BY table_name, ordinal_position;

-- Foreign keys of the new tables (expected: 9 rows; only
-- ai_conversation_message_conversation_fk with delete rule CASCADE, the
-- others RESTRICT; mcp_tool_call has no FK to ai_conversation)
SELECT table_name, constraint_name, referenced_table_name, delete_rule
FROM information_schema.referential_constraints
WHERE constraint_schema = DATABASE()
  AND table_name IN ('ai_conversation', 'ai_conversation_message', 'mcp_tool_call')
ORDER BY table_name, constraint_name;

-- Checks of the new tables (expected: 6 rows)
SELECT tc.table_name, tc.constraint_name, cc.check_clause
FROM information_schema.table_constraints tc
JOIN information_schema.check_constraints cc
  ON cc.constraint_schema = tc.constraint_schema
 AND cc.constraint_name = tc.constraint_name
WHERE tc.table_schema = DATABASE()
  AND tc.table_name IN ('ai_conversation', 'ai_conversation_message', 'mcp_tool_call')
  AND tc.constraint_type = 'CHECK'
ORDER BY tc.table_name, tc.constraint_name;

-- Widened checks of ai_interaction (expected: 6 rows; purpose with copilot_clinical and
-- copilot_management, subject with ai_conversation, status, failed, decided and final
-- with answered)
SELECT tc.table_name, tc.constraint_name, cc.check_clause
FROM information_schema.table_constraints tc
JOIN information_schema.check_constraints cc
  ON cc.constraint_schema = tc.constraint_schema
 AND cc.constraint_name = tc.constraint_name
WHERE tc.table_schema = DATABASE()
  AND tc.table_name IN ('ai_interaction')
  AND tc.constraint_type = 'CHECK'
ORDER BY tc.table_name, tc.constraint_name;

-- Indexes of the new tables (expected: ai_conversation_owner_idx (tenant_id,
-- system_user_id, updated_at), ai_conversation_updated_idx (updated_at),
-- ai_conversation_message_conversation_idx (conversation_id, id) and the 4 indexes of
-- mcp_tool_call on (tenant_id, created_at), (system_user_id, created_at),
-- (tool_name, created_at) and (correlation_id))
SELECT table_name, index_name, seq_in_index, column_name
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name IN ('ai_conversation', 'ai_conversation_message', 'mcp_tool_call')
  AND index_name IN (
      'ai_conversation_owner_idx', 'ai_conversation_updated_idx',
      'ai_conversation_message_conversation_idx',
      'mcp_tool_call_tenant_created_idx', 'mcp_tool_call_user_created_idx',
      'mcp_tool_call_tool_created_idx', 'mcp_tool_call_correlation_idx'
  )
ORDER BY table_name, index_name, seq_in_index;

-- Row counts (expected: ai_interaction equal to the count recorded before the migration;
-- the 3 new tables empty)
SELECT COUNT(*) AS ai_interaction_rows FROM ai_interaction;
SELECT (SELECT COUNT(*) FROM ai_conversation) AS ai_conversation_rows,
       (SELECT COUNT(*) FROM ai_conversation_message) AS ai_conversation_message_rows,
       (SELECT COUNT(*) FROM mcp_tool_call) AS mcp_tool_call_rows;

/*
===============================================================================
ASCII Quest II - Migration 003 Verification
===============================================================================

READ-ONLY. Run after migration 003 has completed and review every result.
===============================================================================
*/

USE ascii_quest;

SELECT migration_id, applied_at
FROM schema_migrations
WHERE migration_id = '003_combat_foundation';

SELECT
    TABLE_NAME,
    ENGINE,
    TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('combat_encounters', 'combat_actions', 'combat_events')
ORDER BY TABLE_NAME;

SELECT
    TABLE_NAME,
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLLATION_NAME,
    EXTRA,
    ORDINAL_POSITION
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('characters', 'combat_encounters', 'combat_actions', 'combat_events')
  AND (
      TABLE_NAME <> 'characters'
      OR COLUMN_NAME IN ('id', 'current_hp', 'current_mana', 'life_state', 'died_at')
  )
ORDER BY TABLE_NAME, ORDINAL_POSITION;

SELECT
    TABLE_NAME,
    INDEX_NAME,
    NON_UNIQUE,
    SEQ_IN_INDEX,
    COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('combat_encounters', 'combat_actions', 'combat_events')
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

SELECT
    TABLE_NAME,
    COLUMN_NAME,
    CONSTRAINT_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('combat_encounters', 'combat_actions', 'combat_events')
  AND REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION;

SELECT
    tc.TABLE_NAME,
    tc.CONSTRAINT_NAME,
    tc.CONSTRAINT_TYPE,
    cc.CHECK_CLAUSE
FROM information_schema.TABLE_CONSTRAINTS AS tc
LEFT JOIN information_schema.CHECK_CONSTRAINTS AS cc
       ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
      AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
WHERE tc.CONSTRAINT_SCHEMA = DATABASE()
  AND tc.TABLE_NAME IN ('characters', 'combat_encounters', 'combat_actions', 'combat_events')
  AND tc.CONSTRAINT_TYPE = 'CHECK'
ORDER BY tc.TABLE_NAME, tc.CONSTRAINT_NAME;

SELECT
    life_state,
    COUNT(*) AS champion_count,
    SUM(current_hp) AS total_current_hp,
    SUM(current_mana) AS total_current_mana
FROM characters
GROUP BY life_state
ORDER BY life_state;

SELECT COUNT(*) AS encounter_count
FROM combat_encounters;

SELECT COUNT(*) AS action_count
FROM combat_actions;

SELECT COUNT(*) AS event_count
FROM combat_events;

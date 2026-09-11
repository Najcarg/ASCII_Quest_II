/*
===============================================================================
ASCII Quest II - Migration 004 Verification
===============================================================================

READ-ONLY. Inspect after migration 004 has completed.
===============================================================================
*/

USE ascii_quest;

SELECT migration_id, applied_at
FROM schema_migrations
WHERE migration_id = '004_combat_enemy_ai_initialization';

SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    ORDINAL_POSITION
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'combat_encounters'
  AND COLUMN_NAME = 'enemy_ai_initialized_timeline_ms';

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
  AND tc.TABLE_NAME = 'combat_encounters'
  AND tc.CONSTRAINT_NAME = 'chk_combat_encounters_enemy_ai_initialized';

SELECT
    COUNT(*) AS encounter_count,
    COALESCE(SUM(CASE
        WHEN enemy_ai_initialized_timeline_ms IS NULL THEN 1
        ELSE 0
    END), 0) AS marker_null_count,
    COALESCE(SUM(CASE
        WHEN enemy_ai_initialized_timeline_ms = 0 THEN 1
        ELSE 0
    END), 0) AS marker_zero_count,
    COALESCE(SUM(CASE
        WHEN enemy_ai_initialized_timeline_ms > 0 THEN 1
        ELSE 0
    END), 0) AS marker_positive_count
FROM combat_encounters;

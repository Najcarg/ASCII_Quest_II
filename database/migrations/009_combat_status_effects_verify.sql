/* READ-ONLY Migration 009 verification. Inspect every result. */
USE ascii_quest;
SELECT migration_id, applied_at FROM schema_migrations
WHERE migration_id IN ('008_combat_elemental_snapshots', '009_combat_status_effects');
SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'combat_status_effects';
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, GENERATION_EXPRESSION
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND
 (TABLE_NAME = 'combat_status_effects' OR
  (TABLE_NAME = 'combat_actions' AND COLUMN_NAME IN
   ('snapshot_poison_status_damage', 'snapshot_bleed_status_damage', 'snapshot_burn_status_damage',
    'snapshot_freeze_status_damage', 'snapshot_shock_status_damage')))
ORDER BY TABLE_NAME, ORDINAL_POSITION;
SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'combat_status_effects'
ORDER BY INDEX_NAME, SEQ_IN_INDEX;
SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'combat_status_effects';
SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME, UPDATE_RULE, DELETE_RULE
FROM information_schema.REFERENTIAL_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'combat_status_effects';
SELECT CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'combat_status_effects';

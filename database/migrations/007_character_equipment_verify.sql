/* READ-ONLY verification for Migration 007. */
USE ascii_quest;

SELECT migration_id, applied_at FROM schema_migrations
WHERE migration_id = '007_character_equipment';

SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'character_equipment';

SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND (TABLE_NAME = 'character_equipment'
       OR (TABLE_NAME = 'combat_actions' AND COLUMN_NAME = 'snapshot_weapon_item_id'))
ORDER BY TABLE_NAME, ORDINAL_POSITION;

SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, COLUMN_NAME, SEQ_IN_INDEX
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND (TABLE_NAME = 'character_equipment'
       OR (TABLE_NAME = 'combat_actions' AND COLUMN_NAME = 'snapshot_weapon_item_id'))
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

SELECT TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE
FROM information_schema.TABLE_CONSTRAINTS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('character_equipment', 'combat_actions')
ORDER BY TABLE_NAME, CONSTRAINT_NAME;

SELECT equipment_slot, COUNT(*) AS equipped_count
FROM character_equipment
GROUP BY equipment_slot
ORDER BY equipment_slot;

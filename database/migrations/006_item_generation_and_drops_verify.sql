/* READ-ONLY verification for Migration 006. */
USE ascii_quest;

SELECT migration_id, applied_at FROM schema_migrations
WHERE migration_id = '006_item_generation_and_drops';

SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('item_affix_definitions', 'item_affix_tiers', 'item_affix_category_rules', 'item_definition_affix_families', 'character_item_affixes', 'combat_item_drops')
ORDER BY TABLE_NAME;

SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND (TABLE_NAME IN ('item_affix_definitions', 'item_affix_tiers', 'item_affix_category_rules', 'item_definition_affix_families', 'character_item_affixes', 'combat_item_drops')
       OR (TABLE_NAME = 'combat_encounters' AND COLUMN_NAME IN ('loot_source_level', 'item_drops_generated_at')))
ORDER BY TABLE_NAME, ORDINAL_POSITION;

SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, COLUMN_NAME, SEQ_IN_INDEX
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('item_affix_definitions', 'item_affix_tiers', 'item_affix_category_rules', 'item_definition_affix_families', 'character_item_affixes', 'combat_item_drops')
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

SELECT position, modifier_type, COUNT(*) AS definition_count
FROM item_affix_definitions GROUP BY position, modifier_type ORDER BY position, modifier_type;

SELECT outcome, COUNT(*) AS drop_count
FROM combat_item_drops GROUP BY outcome ORDER BY outcome;

SELECT COUNT(*) AS character_item_affixes_count FROM character_item_affixes;

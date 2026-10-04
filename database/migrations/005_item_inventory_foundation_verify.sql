/*
===============================================================================
ASCII Quest II - Migration 005 Verification
===============================================================================

READ-ONLY. Inspect after migration 005 has completed.
===============================================================================
*/

USE ascii_quest;

SELECT migration_id, applied_at
FROM schema_migrations
WHERE migration_id = '005_item_inventory_foundation';

SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('item_definitions', 'character_items', 'item_mutation_requests')
ORDER BY TABLE_NAME;

SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('item_definitions', 'character_items', 'item_mutation_requests')
ORDER BY TABLE_NAME, ORDINAL_POSITION;

SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, COLUMN_NAME, SEQ_IN_INDEX
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('item_definitions', 'character_items', 'item_mutation_requests')
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

SELECT
    COUNT(*) AS starter_definition_count
FROM item_definitions
WHERE definition_key IN ('basic_sword', 'basic_wand', 'basic_dagger', 'basic_mace');

SELECT
    COUNT(*) AS character_item_count,
    COALESCE(SUM(character_id IS NULL), 0) AS unclaimed_item_count,
    COALESCE(SUM(character_id IS NOT NULL), 0) AS claimed_item_count
FROM character_items;

SELECT COUNT(*) AS mutation_request_count
FROM item_mutation_requests;

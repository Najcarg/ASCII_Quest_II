/* Read-only. Zero rows = verified. Does not grant items or mutate any row.
   Immediately after authorized migration existing rows are NULL (no backfill).
   Later legitimate retirements are allowed; this verifier does NOT require all NULL.
   Historical immutability/no backfill is established by SQL review, not inferred
   from a current-state query without a pre-migration baseline. */
USE ascii_quest;
SELECT 'missing prerequisite 010' AS violation
 WHERE (SELECT COUNT(*) FROM schema_migrations WHERE migration_id = '010_combat_item_affix_completion') <> 1
UNION ALL
SELECT 'missing migration 011'
 WHERE (SELECT COUNT(*) FROM schema_migrations WHERE migration_id = '011_test_item_provenance') <> 1
UNION ALL
SELECT 'unexpected source CHECK count'
 WHERE (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'character_items'
           AND LOWER(CHECK_CLAUSE) LIKE '%source_type%') <> 1
UNION ALL
SELECT 'unexpected source CHECK shape'
 WHERE (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'character_items'
           AND CONSTRAINT_NAME = 'chk_character_items_source_type'
           AND BINARY REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(CHECK_CLAUSE, '`', ''), ' ', ''), CHAR(9), ''), CHAR(10), ''), CHAR(13), ''), 'IN', 'in') IN
               ('source_typein(''starter'',''combat_drop'',''maintenance_test'')', '(source_typein(''starter'',''combat_drop'',''maintenance_test''))')) <> 1
UNION ALL
SELECT 'unexpected retired_at schema'
 WHERE (SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'character_items'
           AND COLUMN_NAME = 'retired_at' AND COLUMN_TYPE = 'datetime(6)'
           AND DATETIME_PRECISION = 6 AND IS_NULLABLE = 'YES' AND EXTRA = ''
           AND (COLUMN_DEFAULT IS NULL OR UPPER(COLUMN_DEFAULT) = 'NULL')) <> 1
UNION ALL
SELECT 'unexpected existing FK fk_combat_actions_snapshot_weapon_item'
 WHERE (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND UNIQUE_CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME = 'combat_actions' AND CONSTRAINT_NAME = 'fk_combat_actions_snapshot_weapon_item'
           AND REFERENCED_TABLE_NAME = 'character_items' AND DELETE_RULE = 'RESTRICT'
           AND (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE k WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.TABLE_NAME = 'combat_actions' AND k.CONSTRAINT_NAME = 'fk_combat_actions_snapshot_weapon_item' AND k.REFERENCED_TABLE_NAME = 'character_items' AND k.REFERENCED_TABLE_SCHEMA = DATABASE() AND ((k.ORDINAL_POSITION = 1 AND k.COLUMN_NAME = 'snapshot_weapon_item_id' AND k.REFERENCED_COLUMN_NAME = 'id'))) = 1) <> 1
UNION ALL
SELECT 'unexpected existing FK fk_item_mutation_requests_item'
 WHERE (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND UNIQUE_CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME = 'item_mutation_requests' AND CONSTRAINT_NAME = 'fk_item_mutation_requests_item'
           AND REFERENCED_TABLE_NAME = 'character_items' AND DELETE_RULE = 'RESTRICT'
           AND (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE k WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.TABLE_NAME = 'item_mutation_requests' AND k.CONSTRAINT_NAME = 'fk_item_mutation_requests_item' AND k.REFERENCED_TABLE_NAME = 'character_items' AND k.REFERENCED_TABLE_SCHEMA = DATABASE() AND ((k.ORDINAL_POSITION = 1 AND k.COLUMN_NAME = 'item_id' AND k.REFERENCED_COLUMN_NAME = 'id'))) = 1) <> 1
UNION ALL
SELECT 'unexpected existing FK fk_combat_item_drops_item'
 WHERE (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND UNIQUE_CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME = 'combat_item_drops' AND CONSTRAINT_NAME = 'fk_combat_item_drops_item'
           AND REFERENCED_TABLE_NAME = 'character_items' AND DELETE_RULE = 'RESTRICT'
           AND (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE k WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.TABLE_NAME = 'combat_item_drops' AND k.CONSTRAINT_NAME = 'fk_combat_item_drops_item' AND k.REFERENCED_TABLE_NAME = 'character_items' AND k.REFERENCED_TABLE_SCHEMA = DATABASE() AND ((k.ORDINAL_POSITION = 1 AND k.COLUMN_NAME = 'item_id' AND k.REFERENCED_COLUMN_NAME = 'id'))) = 1) <> 1
UNION ALL
SELECT 'unexpected existing FK fk_character_equipment_owned_item'
 WHERE (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND UNIQUE_CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME = 'character_equipment' AND CONSTRAINT_NAME = 'fk_character_equipment_owned_item'
           AND REFERENCED_TABLE_NAME = 'character_items' AND DELETE_RULE = 'RESTRICT'
           AND (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE k WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.TABLE_NAME = 'character_equipment' AND k.CONSTRAINT_NAME = 'fk_character_equipment_owned_item' AND k.REFERENCED_TABLE_NAME = 'character_items' AND k.REFERENCED_TABLE_SCHEMA = DATABASE() AND ((k.ORDINAL_POSITION = 1 AND k.COLUMN_NAME = 'item_id' AND k.REFERENCED_COLUMN_NAME = 'id') OR (k.ORDINAL_POSITION = 2 AND k.COLUMN_NAME = 'character_id' AND k.REFERENCED_COLUMN_NAME = 'character_id'))) = 2) <> 1
UNION ALL
SELECT 'unexpected existing FK fk_character_item_affixes_item'
 WHERE (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND UNIQUE_CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME = 'character_item_affixes' AND CONSTRAINT_NAME = 'fk_character_item_affixes_item'
           AND REFERENCED_TABLE_NAME = 'character_items' AND DELETE_RULE = 'CASCADE'
           AND (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE k WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.TABLE_NAME = 'character_item_affixes' AND k.CONSTRAINT_NAME = 'fk_character_item_affixes_item' AND k.REFERENCED_TABLE_NAME = 'character_items' AND k.REFERENCED_TABLE_SCHEMA = DATABASE() AND ((k.ORDINAL_POSITION = 1 AND k.COLUMN_NAME = 'item_id' AND k.REFERENCED_COLUMN_NAME = 'id'))) = 1) <> 1;

/*
===============================================================================
ASCII Quest II - Migration 001 Verification
===============================================================================
READ-ONLY. This file does not modify the database.
Run only after 001_character_stat_system.sql completes successfully.
===============================================================================
*/

USE ascii_quest;

/* 1. Migration record: expect exactly one row. */
SELECT migration_id, applied_at
FROM schema_migrations
WHERE migration_id = '001_character_stat_system';

/* 2. Approved class starting bonuses. */
SELECT
    id,
    class_key,
    class_name,
    start_strength_bonus,
    start_dexterity_bonus,
    start_vitality_bonus,
    start_energy_bonus,
    start_fate_bonus
FROM character_classes
WHERE class_key IN ('warrior', 'mage', 'rogue', 'cleric')
ORDER BY id;

/* Expected:
   warrior : STR +5, DEX +0, VIT +5, ENE +0, FATE +0
   mage    : STR +0, DEX +0, VIT +0, ENE +5, FATE +5
   rogue   : STR +0, DEX +5, VIT +5, ENE +0, FATE +0
   cleric  : STR +0, DEX +0, VIT +5, ENE +3, FATE +2
*/

/* 3. Character stat/resource columns.
      Expect these eight rows only from the targeted list:
      stat_points, strength, dexterity, vitality, energy, fate,
      current_hp, current_mana.
      max_hp, max_mana, attack and defense must not appear.
*/
SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    ORDINAL_POSITION
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'characters'
  AND COLUMN_NAME IN (
      'stat_points',
      'strength',
      'dexterity',
      'vitality',
      'energy',
      'fate',
      'current_hp',
      'current_mana',
      'max_hp',
      'max_mana',
      'attack',
      'defense'
  )
ORDER BY ORDINAL_POSITION;

/* 4. Class stat columns.
      Expect ONLY the five start_*_bonus rows from this targeted list.
      None of the legacy base/growth fields should appear.
*/
SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    ORDINAL_POSITION
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'character_classes'
  AND COLUMN_NAME IN (
      'start_strength_bonus',
      'start_dexterity_bonus',
      'start_vitality_bonus',
      'start_energy_bonus',
      'start_fate_bonus',
      'base_hp',
      'base_mana',
      'base_attack',
      'base_defense',
      'base_crit_damage',
      'base_crit_chance',
      'base_attack_count',
      'base_dodge',
      'base_heal_per_step',
      'base_life_on_hit',
      'base_mana_per_min',
      'base_mana_on_hit',
      'base_bonus_xp_on_kill',
      'base_gold_find',
      'hp_per_level',
      'mana_per_level',
      'attack_per_level',
      'defense_per_level',
      'dodge_per_level'
  )
ORDER BY ORDINAL_POSITION;

/* 5. Development reset counts: both must be 0. */
SELECT COUNT(*) AS remaining_characters
FROM characters;

SELECT COUNT(*) AS remaining_character_overrides
FROM character_map_overrides;

/* 6. Preserve the four existing class identities and their stable IDs. */
SELECT id, class_key, class_name, glyph, ascii_fallback
FROM character_classes
WHERE id IN (1, 2, 3, 4)
ORDER BY id;

/* 7. Confirm the important existing indexes still exist. */
SELECT
    TABLE_NAME,
    INDEX_NAME,
    NON_UNIQUE,
    SEQ_IN_INDEX,
    COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('characters', 'character_classes', 'character_map_overrides')
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

/* 8. Confirm the important foreign keys still exist. */
SELECT
    TABLE_NAME,
    COLUMN_NAME,
    CONSTRAINT_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('characters', 'character_classes', 'character_map_overrides')
  AND REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY TABLE_NAME, CONSTRAINT_NAME;

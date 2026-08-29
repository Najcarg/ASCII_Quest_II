/*
===============================================================================
ASCII Quest II - Migration 001: Character Stat Foundation
===============================================================================

WARNING
-------
This is a DESTRUCTIVE DEVELOPMENT migration.

It intentionally deletes:
  - every row from character_map_overrides that belongs to a Champion
  - every row from characters

It preserves:
  - users
  - game_maps
  - tile_types
  - the existing character_classes rows / IDs / class keys / descriptions
  - character and class indexes / foreign keys that do not reference removed columns

Required backup before execution:
  ascii_quest_before_001_2026-08-28.sql

Live schema inspected before this migration was written:
  MariaDB 11.8.6
  characters: 4 rows
  character_map_overrides: 10 rows
  classes: warrior, mage, rogue, cleric
===============================================================================
*/

USE ascii_quest;

/* --------------------------------------------------------------------------
   Migration history table
   -------------------------------------------------------------------------- */
CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_id VARCHAR(100) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

DROP PROCEDURE IF EXISTS run_001_character_stat_system;

DELIMITER $$

CREATE PROCEDURE run_001_character_stat_system()
BEGIN
    DECLARE v_count INT DEFAULT 0;

    /* ----------------------------------------------------------------------
       Guard: do not run the same migration twice.
       ---------------------------------------------------------------------- */
    SELECT COUNT(*)
      INTO v_count
      FROM schema_migrations
     WHERE migration_id = '001_character_stat_system';

    IF v_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration 001_character_stat_system is already applied';
    END IF;

    /* ----------------------------------------------------------------------
       Preflight: confirm the live tables this migration was written against.
       These checks happen before any Champion data is deleted.
       ---------------------------------------------------------------------- */
    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME IN ('characters', 'character_classes', 'character_map_overrides');

    IF v_count <> 3 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration 001 preflight failed: expected characters, character_classes and character_map_overrides';
    END IF;

    /* Confirm every legacy character column that will be removed exists. */
    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'characters'
       AND COLUMN_NAME IN ('max_hp', 'max_mana', 'attack', 'defense');

    IF v_count <> 4 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration 001 preflight failed: characters legacy column set differs from inspected schema';
    END IF;

    /* Confirm every legacy class-stat column that will be removed exists. */
    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'character_classes'
       AND COLUMN_NAME IN (
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
       );

    IF v_count <> 19 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration 001 preflight failed: character_classes legacy column set differs from inspected schema';
    END IF;

    /* Confirm the five new main-stat columns are not already present. */
    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'characters'
       AND COLUMN_NAME IN ('stat_points', 'strength', 'dexterity', 'vitality', 'energy', 'fate');

    IF v_count <> 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration 001 preflight failed: one or more new character stat columns already exist';
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'character_classes'
       AND COLUMN_NAME IN (
            'start_strength_bonus',
            'start_dexterity_bonus',
            'start_vitality_bonus',
            'start_energy_bonus',
            'start_fate_bonus'
       );

    IF v_count <> 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration 001 preflight failed: one or more new class bonus columns already exist';
    END IF;

    /* Confirm the four approved class identities are present exactly once. */
    SELECT COUNT(*)
      INTO v_count
      FROM character_classes
     WHERE class_key IN ('warrior', 'mage', 'rogue', 'cleric');

    IF v_count <> 4 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration 001 preflight failed: expected warrior, mage, rogue and cleric class rows';
    END IF;

    /* ----------------------------------------------------------------------
       Development reset.

       character_map_overrides.character_id references characters.id, so
       dependent per-character map state is removed before Champions.
       ---------------------------------------------------------------------- */
    DELETE cmo
      FROM character_map_overrides AS cmo
      INNER JOIN characters AS c
              ON c.id = cmo.character_id;

    DELETE FROM characters;

    /* ----------------------------------------------------------------------
       Replace class combat/growth data with starting MAIN-STAT bonuses.

       Universal base main stats (5 each) live in PHP configuration.
       These database values are class-specific additions only.
       ---------------------------------------------------------------------- */
    ALTER TABLE character_classes
        ADD COLUMN start_strength_bonus INT UNSIGNED NOT NULL DEFAULT 0 AFTER description,
        ADD COLUMN start_dexterity_bonus INT UNSIGNED NOT NULL DEFAULT 0 AFTER start_strength_bonus,
        ADD COLUMN start_vitality_bonus INT UNSIGNED NOT NULL DEFAULT 0 AFTER start_dexterity_bonus,
        ADD COLUMN start_energy_bonus INT UNSIGNED NOT NULL DEFAULT 0 AFTER start_vitality_bonus,
        ADD COLUMN start_fate_bonus INT UNSIGNED NOT NULL DEFAULT 0 AFTER start_energy_bonus;

    UPDATE character_classes
       SET start_strength_bonus = CASE class_key
               WHEN 'warrior' THEN 5
               ELSE 0
           END,
           start_dexterity_bonus = CASE class_key
               WHEN 'rogue' THEN 5
               ELSE 0
           END,
           start_vitality_bonus = CASE class_key
               WHEN 'warrior' THEN 5
               WHEN 'rogue' THEN 5
               WHEN 'cleric' THEN 5
               ELSE 0
           END,
           start_energy_bonus = CASE class_key
               WHEN 'mage' THEN 5
               WHEN 'cleric' THEN 3
               ELSE 0
           END,
           start_fate_bonus = CASE class_key
               WHEN 'mage' THEN 5
               WHEN 'cleric' THEN 2
               ELSE 0
           END
     WHERE class_key IN ('warrior', 'mage', 'rogue', 'cleric');

    /* ----------------------------------------------------------------------
       Store only permanent Champion main stats and unspent points.
       Maximum Life/Mana and combat values are derived by CharacterStats.php.
       ---------------------------------------------------------------------- */
    ALTER TABLE characters
        ADD COLUMN stat_points INT UNSIGNED NOT NULL DEFAULT 0 AFTER experience,
        ADD COLUMN strength INT UNSIGNED NOT NULL DEFAULT 5 AFTER stat_points,
        ADD COLUMN dexterity INT UNSIGNED NOT NULL DEFAULT 5 AFTER strength,
        ADD COLUMN vitality INT UNSIGNED NOT NULL DEFAULT 5 AFTER dexterity,
        ADD COLUMN energy INT UNSIGNED NOT NULL DEFAULT 5 AFTER vitality,
        ADD COLUMN fate INT UNSIGNED NOT NULL DEFAULT 5 AFTER energy;

    ALTER TABLE characters
        DROP COLUMN max_hp,
        DROP COLUMN max_mana,
        DROP COLUMN attack,
        DROP COLUMN defense;

    ALTER TABLE character_classes
        DROP COLUMN base_hp,
        DROP COLUMN base_mana,
        DROP COLUMN base_attack,
        DROP COLUMN base_defense,
        DROP COLUMN base_crit_damage,
        DROP COLUMN base_crit_chance,
        DROP COLUMN base_attack_count,
        DROP COLUMN base_dodge,
        DROP COLUMN base_heal_per_step,
        DROP COLUMN base_life_on_hit,
        DROP COLUMN base_mana_per_min,
        DROP COLUMN base_mana_on_hit,
        DROP COLUMN base_bonus_xp_on_kill,
        DROP COLUMN base_gold_find,
        DROP COLUMN hp_per_level,
        DROP COLUMN mana_per_level,
        DROP COLUMN attack_per_level,
        DROP COLUMN defense_per_level,
        DROP COLUMN dodge_per_level;

    /* ----------------------------------------------------------------------
       Record successful completion only after every required schema change.
       ---------------------------------------------------------------------- */
    INSERT INTO schema_migrations (migration_id)
    VALUES ('001_character_stat_system');
END$$

DELIMITER ;

CALL run_001_character_stat_system();
DROP PROCEDURE run_001_character_stat_system;

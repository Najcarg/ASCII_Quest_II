/*
===============================================================================
ASCII Quest II - Migration 006: Item Generation and Physical Drops
===============================================================================

Adds normalized affix definitions, immutable item affix rolls, and durable
combat reward-slot outcomes. Existing encounters remain legacy NULL rows and
receive no retroactive physical drops.

This migration is intentionally NOT applied by the development agent.
===============================================================================
*/

USE ascii_quest;

DROP PROCEDURE IF EXISTS run_006_item_generation_and_drops;

DELIMITER $$

CREATE PROCEDURE run_006_item_generation_and_drops()
BEGIN
    DECLARE v_count INT DEFAULT 0;

    SELECT COUNT(*) INTO v_count FROM schema_migrations
     WHERE migration_id = '005_item_inventory_foundation';
    IF v_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 006 requires migration 005';
    END IF;

    SELECT COUNT(*) INTO v_count FROM schema_migrations
     WHERE migration_id = '006_item_generation_and_drops';
    IF v_count > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 006_item_generation_and_drops is already applied';
    END IF;

    SELECT COUNT(*) INTO v_count FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME IN ('item_affix_definitions', 'item_affix_tiers', 'item_affix_category_rules', 'item_definition_affix_families', 'character_item_affixes', 'combat_item_drops');
    IF v_count <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 006 preflight failed: a target table exists without its migration record';
    END IF;

    SELECT COUNT(*) INTO v_count FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND ((TABLE_NAME = 'combat_encounters' AND COLUMN_NAME IN ('loot_source_level', 'item_drops_generated_at'))
         OR (TABLE_NAME = 'item_definitions' AND COLUMN_NAME = 'loot_weight'));
    IF v_count <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 006 preflight failed: an encounter column exists without its migration record';
    END IF;

    ALTER TABLE item_definitions
        ADD COLUMN loot_weight INT UNSIGNED NOT NULL DEFAULT 0 AFTER is_active,
        ADD CONSTRAINT chk_item_definitions_loot_weight CHECK (loot_weight <= 1000000);

    ALTER TABLE combat_encounters
        ADD COLUMN loot_source_level INT UNSIGNED NULL AFTER reward_experience,
        ADD COLUMN item_drops_generated_at DATETIME(6) NULL AFTER rewards_issued_at,
        ADD CONSTRAINT chk_combat_encounters_loot_source_level CHECK (loot_source_level IS NULL OR loot_source_level > 0);

    CREATE TABLE item_affix_definitions (
        affix_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        family_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        position VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        display_fragment VARCHAR(96) NOT NULL,
        modifier_type VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        modifier_operation VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        minimum_item_level INT UNSIGNED NOT NULL,
        selection_weight INT UNSIGNED NOT NULL DEFAULT 100,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (affix_key),
        KEY idx_item_affix_definitions_generation (family_key, position, is_active, minimum_item_level),
        KEY idx_item_affix_definitions_modifier (modifier_type),
        CONSTRAINT chk_item_affix_definitions_position CHECK (position IN ('prefix', 'suffix', 'special')),
        CONSTRAINT chk_item_affix_definitions_operation CHECK (modifier_operation IN ('flat', 'additive_percent')),
        CONSTRAINT chk_item_affix_definitions_level CHECK (minimum_item_level > 0),
        CONSTRAINT chk_item_affix_definitions_weight CHECK (selection_weight > 0),
        CONSTRAINT chk_item_affix_definitions_active CHECK (is_active IN (0, 1))
    ) ENGINE=InnoDB;

    CREATE TABLE item_affix_tiers (
        affix_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        tier SMALLINT UNSIGNED NOT NULL,
        minimum_item_level INT UNSIGNED NOT NULL,
        minimum_value INT NOT NULL,
        maximum_value INT NOT NULL,
        PRIMARY KEY (affix_key, tier),
        KEY idx_item_affix_tiers_level (minimum_item_level),
        CONSTRAINT fk_item_affix_tiers_definition FOREIGN KEY (affix_key)
            REFERENCES item_affix_definitions (affix_key) ON DELETE CASCADE,
        CONSTRAINT chk_item_affix_tiers_tier CHECK (tier > 0),
        CONSTRAINT chk_item_affix_tiers_level CHECK (minimum_item_level > 0),
        CONSTRAINT chk_item_affix_tiers_range CHECK (minimum_value <= maximum_value)
    ) ENGINE=InnoDB;

    CREATE TABLE item_affix_category_rules (
        affix_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        category VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        PRIMARY KEY (affix_key, category),
        CONSTRAINT fk_item_affix_category_rules_definition FOREIGN KEY (affix_key)
            REFERENCES item_affix_definitions (affix_key) ON DELETE CASCADE,
        CONSTRAINT chk_item_affix_category_rules_category CHECK (category IN ('weapon', 'armour', 'off_hand', 'jewellery'))
    ) ENGINE=InnoDB;

    CREATE TABLE item_definition_affix_families (
        definition_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        family_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        PRIMARY KEY (definition_key, family_key),
        KEY idx_item_definition_affix_families_family (family_key),
        CONSTRAINT fk_item_definition_affix_families_definition FOREIGN KEY (definition_key)
            REFERENCES item_definitions (definition_key) ON DELETE CASCADE
    ) ENGINE=InnoDB;

    CREATE TABLE character_item_affixes (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        item_id INT UNSIGNED NOT NULL,
        position VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        affix_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        tier SMALLINT UNSIGNED NOT NULL,
        family_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        display_fragment VARCHAR(96) NOT NULL,
        modifier_type VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        modifier_operation VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        rolled_value INT NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_character_item_affixes_definition (item_id, affix_key),
        UNIQUE KEY uq_character_item_affixes_position (item_id, position),
        UNIQUE KEY uq_character_item_affixes_family (item_id, family_key),
        KEY idx_character_item_affixes_tier (affix_key, tier),
        CONSTRAINT fk_character_item_affixes_item FOREIGN KEY (item_id)
            REFERENCES character_items (id) ON DELETE CASCADE,
        CONSTRAINT fk_character_item_affixes_tier FOREIGN KEY (affix_key, tier)
            REFERENCES item_affix_tiers (affix_key, tier) ON DELETE RESTRICT,
        CONSTRAINT chk_character_item_affixes_position CHECK (position IN ('prefix', 'suffix', 'special')),
        CONSTRAINT chk_character_item_affixes_operation CHECK (modifier_operation IN ('flat', 'additive_percent'))
    ) ENGINE=InnoDB;

    CREATE TABLE combat_item_drops (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        encounter_id INT UNSIGNED NOT NULL,
        reward_slot SMALLINT UNSIGNED NOT NULL,
        outcome VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        item_id INT UNSIGNED NULL,
        base_chance_bp SMALLINT UNSIGNED NOT NULL,
        item_find_bonus_bp INT UNSIGNED NOT NULL DEFAULT 0,
        effective_chance_bp SMALLINT UNSIGNED NOT NULL,
        chance_roll_bp SMALLINT UNSIGNED NOT NULL,
        claimed_character_id INT UNSIGNED NULL,
        generated_at DATETIME(6) NOT NULL,
        claimed_at DATETIME(6) NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_combat_item_drops_slot (encounter_id, reward_slot),
        UNIQUE KEY uq_combat_item_drops_item (item_id),
        KEY idx_combat_item_drops_claim (encounter_id, outcome),
        CONSTRAINT fk_combat_item_drops_encounter FOREIGN KEY (encounter_id)
            REFERENCES combat_encounters (id) ON DELETE CASCADE,
        CONSTRAINT fk_combat_item_drops_item FOREIGN KEY (item_id)
            REFERENCES character_items (id) ON DELETE RESTRICT,
        CONSTRAINT fk_combat_item_drops_claimed_character FOREIGN KEY (claimed_character_id)
            REFERENCES characters (id) ON DELETE CASCADE,
        CONSTRAINT chk_combat_item_drops_slot CHECK (reward_slot > 0),
        CONSTRAINT chk_combat_item_drops_outcome CHECK (outcome IN ('none', 'unclaimed', 'claimed')),
        CONSTRAINT chk_combat_item_drops_base_chance CHECK (base_chance_bp <= 10000),
        CONSTRAINT chk_combat_item_drops_effective_chance CHECK (effective_chance_bp <= 10000),
        CONSTRAINT chk_combat_item_drops_roll CHECK (chance_roll_bp BETWEEN 1 AND 10000),
        CONSTRAINT chk_combat_item_drops_item_state CHECK (
            (outcome = 'none' AND item_id IS NULL AND claimed_character_id IS NULL AND claimed_at IS NULL)
            OR (outcome = 'unclaimed' AND item_id IS NOT NULL AND claimed_character_id IS NULL AND claimed_at IS NULL)
            OR (outcome = 'claimed' AND item_id IS NOT NULL AND claimed_character_id IS NOT NULL AND claimed_at IS NOT NULL)
        )
    ) ENGINE=InnoDB;

    INSERT INTO item_definitions (
        definition_key, display_name, category, subtype, equipment_slot,
        damage_type, base_damage_min, base_damage_max, base_toughness,
        minimum_item_level, maximum_rarity, glyph, loot_weight
    ) VALUES
        ('worn_axe', 'Axe', 'weapon', 'axe', 'weapon', 'physical', 5, 8, 0, 1, 'rare', '/', 100),
        ('oak_staff', 'Staff', 'weapon', 'staff', 'weapon', 'fire', 4, 7, 0, 1, 'rare', '/', 100),
        ('hide_helm', 'Hide Helm', 'armour', 'helm', 'helm', NULL, 0, 0, 3, 1, 'rare', '^', 100),
        ('hide_chest', 'Hide Chest', 'armour', 'chest', 'chest', NULL, 0, 0, 6, 1, 'rare', 'H', 100),
        ('wooden_shield', 'Wooden Shield', 'off_hand', 'shield', 'off-hand', NULL, 0, 0, 4, 1, 'rare', 'O', 100),
        ('copper_ring', 'Copper Ring', 'jewellery', 'ring', 'ring', NULL, 0, 0, 0, 1, 'rare', 'o', 100);

    INSERT INTO item_affix_definitions
        (affix_key, family_key, position, display_fragment, modifier_type, modifier_operation, minimum_item_level)
    VALUES
        ('hunter', 'damage_flat', 'prefix', 'Hunter', 'flat_damage', 'flat', 1),
        ('brutal', 'damage_percent', 'prefix', 'Brutal', 'damage_percent_bp', 'additive_percent', 1),
        ('stout', 'maximum_life', 'prefix', 'Stout', 'maximum_life', 'flat', 1),
        ('focused', 'maximum_mana', 'prefix', 'Focused', 'maximum_mana', 'flat', 1),
        ('strong', 'strength', 'prefix', 'Strong', 'strength', 'flat', 1),
        ('swift', 'dexterity', 'prefix', 'Swift', 'dexterity', 'flat', 1),
        ('vital', 'vitality', 'prefix', 'Vital', 'vitality', 'flat', 1),
        ('energetic', 'energy', 'prefix', 'Energetic', 'energy', 'flat', 1),
        ('fated', 'fate', 'prefix', 'Fated', 'fate', 'flat', 1),
        ('armoured', 'toughness', 'prefix', 'Armoured', 'toughness', 'flat', 1),
        ('of_health', 'maximum_life_suffix', 'suffix', 'of Health', 'maximum_life', 'flat', 1),
        ('of_mana', 'maximum_mana_suffix', 'suffix', 'of Mana', 'maximum_mana', 'flat', 1),
        ('of_flame_warding', 'fire_resistance', 'suffix', 'of Flame Warding', 'fire_resistance', 'flat', 1),
        ('of_frost_warding', 'cold_resistance', 'suffix', 'of Frost Warding', 'cold_resistance', 'flat', 1),
        ('of_haste', 'attack_rate', 'suffix', 'of Haste', 'attack_rate_bp', 'additive_percent', 1),
        ('of_channeling', 'cast_rate', 'suffix', 'of Channeling', 'cast_rate_bp', 'additive_percent', 1);

    INSERT INTO item_affix_tiers (affix_key, tier, minimum_item_level, minimum_value, maximum_value)
    SELECT affix_key, 1, minimum_item_level,
           CASE WHEN modifier_operation = 'additive_percent' THEN 100 ELSE 1 END,
           CASE WHEN modifier_operation = 'additive_percent' THEN 300 ELSE 3 END
      FROM item_affix_definitions;
    INSERT INTO item_affix_tiers (affix_key, tier, minimum_item_level, minimum_value, maximum_value)
    SELECT affix_key, 2, 10,
           CASE WHEN modifier_operation = 'additive_percent' THEN 301 ELSE 4 END,
           CASE WHEN modifier_operation = 'additive_percent' THEN 600 ELSE 7 END
      FROM item_affix_definitions;

    INSERT INTO item_affix_category_rules (affix_key, category)
    SELECT affix_key, category
      FROM item_affix_definitions
      CROSS JOIN (SELECT 'weapon' AS category UNION ALL SELECT 'armour' UNION ALL SELECT 'off_hand' UNION ALL SELECT 'jewellery') AS categories
     WHERE modifier_type NOT IN ('flat_damage', 'damage_percent_bp', 'attack_rate_bp', 'cast_rate_bp')
        OR category = 'weapon';

    INSERT INTO item_definition_affix_families (definition_key, family_key)
    SELECT DISTINCT definitions.definition_key, affixes.family_key
      FROM item_definitions AS definitions
      INNER JOIN item_affix_category_rules AS rules ON rules.category = definitions.category
      INNER JOIN item_affix_definitions AS affixes ON affixes.affix_key = rules.affix_key
     WHERE definitions.loot_weight > 0;

    INSERT INTO schema_migrations (migration_id)
    VALUES ('006_item_generation_and_drops');
END$$

DELIMITER ;

CALL run_006_item_generation_and_drops();
DROP PROCEDURE run_006_item_generation_and_drops;

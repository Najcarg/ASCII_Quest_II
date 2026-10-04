/*
===============================================================================
ASCII Quest II - Migration 005: Item Inventory Foundation
===============================================================================

Adds the immutable item catalogue, persistent Champion item instances, and
durable item mutation receipts. Catalogue rows are seeded; item instances are
not granted by this migration.

This migration is intentionally NOT applied by the development agent.
===============================================================================
*/

USE ascii_quest;

DROP PROCEDURE IF EXISTS run_005_item_inventory_foundation;

DELIMITER $$

CREATE PROCEDURE run_005_item_inventory_foundation()
BEGIN
    DECLARE v_count INT DEFAULT 0;

    SELECT COUNT(*) INTO v_count
      FROM schema_migrations
     WHERE migration_id = '004_combat_enemy_ai_initialization';

    IF v_count <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration 005 requires migration 004';
    END IF;

    SELECT COUNT(*) INTO v_count
      FROM schema_migrations
     WHERE migration_id = '005_item_inventory_foundation';

    IF v_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration 005_item_inventory_foundation is already applied';
    END IF;

    SELECT COUNT(*) INTO v_count
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'characters'
       AND ENGINE = 'InnoDB';

    IF v_count <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration 005 preflight failed: characters must exist and use InnoDB';
    END IF;

    SELECT COUNT(*) INTO v_count
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'characters'
       AND COLUMN_NAME = 'id'
       AND DATA_TYPE = 'int'
       AND COLUMN_TYPE REGEXP '^int(\\([0-9]+\\))? unsigned$'
       AND IS_NULLABLE = 'NO';

    IF v_count <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration 005 preflight failed: characters.id must be INT UNSIGNED NOT NULL';
    END IF;

    SELECT COUNT(*) INTO v_count
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME IN ('item_definitions', 'character_items', 'item_mutation_requests');

    IF v_count <> 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration 005 preflight failed: an item table exists without its migration record';
    END IF;

    CREATE TABLE item_definitions (
        definition_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        display_name VARCHAR(96) NOT NULL,
        category VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        subtype VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        equipment_slot VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        damage_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
        base_damage_min INT UNSIGNED NOT NULL DEFAULT 0,
        base_damage_max INT UNSIGNED NOT NULL DEFAULT 0,
        base_toughness INT UNSIGNED NOT NULL DEFAULT 0,
        base_attack_rate_modifier_bp SMALLINT NOT NULL DEFAULT 0,
        base_cast_rate_modifier_bp SMALLINT NOT NULL DEFAULT 0,
        base_block_rate_modifier_bp SMALLINT NOT NULL DEFAULT 0,
        minimum_item_level INT UNSIGNED NOT NULL,
        maximum_rarity VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        glyph VARCHAR(8) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
        PRIMARY KEY (definition_key),
        KEY idx_item_definitions_catalogue (category, equipment_slot, is_active),
        KEY idx_item_definitions_minimum_level (minimum_item_level),
        CONSTRAINT chk_item_definitions_category
            CHECK (category IN ('weapon', 'armour', 'off_hand', 'jewellery')),
        CONSTRAINT chk_item_definitions_slot
            CHECK (equipment_slot IN ('helm', 'gloves', 'chest', 'ring', 'weapon', 'off-hand', 'amulet', 'belt', 'charm', 'boots')),
        CONSTRAINT chk_item_definitions_level CHECK (minimum_item_level > 0),
        CONSTRAINT chk_item_definitions_rarity
            CHECK (maximum_rarity IN ('normal', 'magic', 'rare')),
        CONSTRAINT chk_item_definitions_damage
            CHECK (base_damage_min <= base_damage_max),
        CONSTRAINT chk_item_definitions_active CHECK (is_active IN (0, 1))
    ) ENGINE=InnoDB;

    CREATE TABLE character_items (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        character_id INT UNSIGNED NULL,
        definition_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        item_level INT UNSIGNED NOT NULL,
        rarity VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        display_name VARCHAR(160) NOT NULL,
        snapshot_category VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        snapshot_subtype VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        snapshot_equipment_slot VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        snapshot_damage_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
        snapshot_damage_min INT UNSIGNED NOT NULL DEFAULT 0,
        snapshot_damage_max INT UNSIGNED NOT NULL DEFAULT 0,
        snapshot_toughness INT UNSIGNED NOT NULL DEFAULT 0,
        snapshot_attack_rate_modifier_bp SMALLINT NOT NULL DEFAULT 0,
        snapshot_cast_rate_modifier_bp SMALLINT NOT NULL DEFAULT 0,
        snapshot_block_rate_modifier_bp SMALLINT NOT NULL DEFAULT 0,
        glyph VARCHAR(8) NOT NULL,
        source_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        source_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        generated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        claimed_at DATETIME(6) NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_character_items_id_owner (id, character_id),
        UNIQUE KEY uq_character_items_source (character_id, source_type, source_key),
        KEY idx_character_items_inventory (character_id, claimed_at, id),
        KEY idx_character_items_definition_level (definition_key, item_level),
        CONSTRAINT fk_character_items_character
            FOREIGN KEY (character_id) REFERENCES characters (id) ON DELETE CASCADE,
        CONSTRAINT fk_character_items_definition
            FOREIGN KEY (definition_key) REFERENCES item_definitions (definition_key) ON DELETE RESTRICT,
        CONSTRAINT chk_character_items_level CHECK (item_level > 0),
        CONSTRAINT chk_character_items_rarity
            CHECK (rarity IN ('normal', 'magic', 'rare')),
        CONSTRAINT chk_character_items_source_type
            CHECK (source_type IN ('starter', 'combat_drop')),
        CONSTRAINT chk_character_items_damage
            CHECK (snapshot_damage_min <= snapshot_damage_max),
        CONSTRAINT chk_character_items_claim
            CHECK (
                (character_id IS NULL AND claimed_at IS NULL)
                OR (character_id IS NOT NULL AND claimed_at IS NOT NULL)
            )
    ) ENGINE=InnoDB;

    CREATE TABLE item_mutation_requests (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        character_id INT UNSIGNED NOT NULL,
        request_token CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        command_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        request_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        item_id INT UNSIGNED NULL,
        source_slot VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
        target_slot VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
        result_code VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NULL,
        result_payload JSON NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        completed_at DATETIME(6) NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_item_mutation_requests_token (character_id, request_token),
        KEY idx_item_mutation_requests_item (item_id),
        KEY idx_item_mutation_requests_completed (completed_at),
        CONSTRAINT fk_item_mutation_requests_character
            FOREIGN KEY (character_id) REFERENCES characters (id) ON DELETE CASCADE,
        CONSTRAINT fk_item_mutation_requests_item
            FOREIGN KEY (item_id) REFERENCES character_items (id) ON DELETE RESTRICT,
        CONSTRAINT chk_item_mutation_requests_command
            CHECK (command_type IN ('claim', 'equip', 'unequip', 'swap')),
        CONSTRAINT chk_item_mutation_requests_completion
            CHECK (
                (result_code IS NULL AND completed_at IS NULL)
                OR (result_code IS NOT NULL AND completed_at IS NOT NULL)
            )
    ) ENGINE=InnoDB;

    INSERT INTO item_definitions (
        definition_key, display_name, category, subtype, equipment_slot,
        damage_type, base_damage_min, base_damage_max, minimum_item_level,
        maximum_rarity, glyph
    ) VALUES
        ('basic_sword', 'Basic Sword', 'weapon', 'sword', 'weapon', 'physical', 4, 7, 1, 'normal', '/'),
        ('basic_wand', 'Basic Wand', 'weapon', 'wand', 'weapon', 'fire', 3, 6, 1, 'normal', '!'),
        ('basic_dagger', 'Basic Dagger', 'weapon', 'dagger', 'weapon', 'physical', 3, 5, 1, 'normal', '/'),
        ('basic_mace', 'Basic Mace', 'weapon', 'mace', 'weapon', 'physical', 5, 7, 1, 'normal', 'T');

    INSERT INTO schema_migrations (migration_id)
    VALUES ('005_item_inventory_foundation');
END$$

DELIMITER ;

CALL run_005_item_inventory_foundation();
DROP PROCEDURE run_005_item_inventory_foundation;

/*
===============================================================================
ASCII Quest II - Migration 007: Character Equipment
===============================================================================

Adds the authoritative Champion equipment relation and an immutable weapon
item reference for combat action snapshots. This migration creates no items,
equipment rows, or resource changes for existing Champions.

This migration is intentionally NOT applied by the development agent.
===============================================================================
*/

USE ascii_quest;

DROP PROCEDURE IF EXISTS run_007_character_equipment;

DELIMITER $$

CREATE PROCEDURE run_007_character_equipment()
BEGIN
    DECLARE v_count INT DEFAULT 0;

    SELECT COUNT(*) INTO v_count FROM schema_migrations
     WHERE migration_id = '006_item_generation_and_drops';
    IF v_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 007 requires migration 006';
    END IF;

    SELECT COUNT(*) INTO v_count FROM schema_migrations
     WHERE migration_id = '007_character_equipment';
    IF v_count > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 007_character_equipment is already applied';
    END IF;

    SELECT COUNT(*) INTO v_count FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'character_equipment';
    IF v_count <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 007 preflight failed: character_equipment exists without its migration record';
    END IF;

    SELECT COUNT(*) INTO v_count FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'combat_actions'
       AND COLUMN_NAME = 'snapshot_weapon_item_id';
    IF v_count <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 007 preflight failed: snapshot weapon column exists without its migration record';
    END IF;

    SELECT COUNT(*) INTO v_count FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND ((TABLE_NAME = 'characters' AND COLUMN_NAME = 'id'
             AND DATA_TYPE = 'int' AND COLUMN_TYPE REGEXP '^int(\\([0-9]+\\))? unsigned$' AND IS_NULLABLE = 'NO')
         OR (TABLE_NAME = 'character_items' AND COLUMN_NAME IN ('id', 'character_id')
             AND DATA_TYPE = 'int' AND COLUMN_TYPE REGEXP '^int(\\([0-9]+\\))? unsigned$')
         OR (TABLE_NAME = 'combat_actions' AND COLUMN_NAME = 'id'
             AND DATA_TYPE = 'int' AND COLUMN_TYPE REGEXP '^int(\\([0-9]+\\))? unsigned$' AND IS_NULLABLE = 'NO'));
    IF v_count <> 4 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 007 preflight failed: parent key shapes do not match';
    END IF;

    CREATE TABLE character_equipment (
        character_id INT UNSIGNED NOT NULL,
        equipment_slot VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        item_id INT UNSIGNED NOT NULL,
        equipped_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
        PRIMARY KEY (character_id, equipment_slot),
        UNIQUE KEY uq_character_equipment_item (item_id),
        KEY idx_character_equipment_owner_item (item_id, character_id),
        CONSTRAINT fk_character_equipment_character
            FOREIGN KEY (character_id) REFERENCES characters (id) ON DELETE CASCADE,
        CONSTRAINT fk_character_equipment_owned_item
            FOREIGN KEY (item_id, character_id)
            REFERENCES character_items (id, character_id) ON DELETE RESTRICT,
        CONSTRAINT chk_character_equipment_slot
            CHECK (equipment_slot IN ('helm', 'gloves', 'chest', 'ring', 'weapon', 'off-hand', 'amulet', 'belt', 'charm', 'boots'))
    ) ENGINE=InnoDB;

    ALTER TABLE combat_actions
        ADD COLUMN snapshot_weapon_item_id INT UNSIGNED NULL AFTER snapshot_damage_type,
        ADD KEY idx_combat_actions_snapshot_weapon_item (snapshot_weapon_item_id),
        ADD CONSTRAINT fk_combat_actions_snapshot_weapon_item
            FOREIGN KEY (snapshot_weapon_item_id) REFERENCES character_items (id) ON DELETE RESTRICT;

    INSERT INTO schema_migrations (migration_id)
    VALUES ('007_character_equipment');
END$$

DELIMITER ;

CALL run_007_character_equipment();
DROP PROCEDURE run_007_character_equipment;

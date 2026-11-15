/*
ASCII Quest II - Migration 008: Direct elemental combat snapshots
Prerequisite: 007_character_equipment. NOT APPLIED by the development agent.
Legacy rows retain SQL NULL; runtime interprets pending NULL bonuses as zero.
No historical backfill, equipment changes, or gameplay data mutation.
*/
USE ascii_quest;

DROP PROCEDURE IF EXISTS run_008_combat_elemental_snapshots;
DELIMITER $$

CREATE PROCEDURE run_008_combat_elemental_snapshots()
BEGIN
    DECLARE v_count INT DEFAULT 0;

    SELECT COUNT(*) INTO v_count FROM schema_migrations
     WHERE migration_id = '007_character_equipment';
    IF v_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 008 requires migration 007';
    END IF;

    SELECT COUNT(*) INTO v_count FROM schema_migrations
     WHERE migration_id = '008_combat_elemental_snapshots';
    IF v_count > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 008_combat_elemental_snapshots is already applied';
    END IF;

    SELECT COUNT(*) INTO v_count FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'combat_actions'
       AND COLUMN_NAME IN ('snapshot_fire_damage', 'snapshot_lightning_damage',
                           'snapshot_cold_damage', 'snapshot_poison_damage');
    IF v_count <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 008 preflight failed: elemental columns exist without migration record';
    END IF;

    SELECT COUNT(*) INTO v_count FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'combat_actions'
       AND COLUMN_NAME = 'snapshot_base_damage' AND DATA_TYPE = 'int'
       AND COLUMN_TYPE REGEXP '^int(\\([0-9]+\\))? unsigned$' AND IS_NULLABLE = 'YES';
    IF v_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 008 preflight failed: combat action base damage shape does not match';
    END IF;

    ALTER TABLE combat_actions
        ADD COLUMN snapshot_fire_damage INT UNSIGNED NULL AFTER snapshot_base_damage,
        ADD COLUMN snapshot_lightning_damage INT UNSIGNED NULL AFTER snapshot_fire_damage,
        ADD COLUMN snapshot_cold_damage INT UNSIGNED NULL AFTER snapshot_lightning_damage,
        ADD COLUMN snapshot_poison_damage INT UNSIGNED NULL AFTER snapshot_cold_damage;

    INSERT INTO schema_migrations (migration_id)
    VALUES ('008_combat_elemental_snapshots');
END$$

DELIMITER ;
CALL run_008_combat_elemental_snapshots();
DROP PROCEDURE run_008_combat_elemental_snapshots;

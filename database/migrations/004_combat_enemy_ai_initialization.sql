/*
===============================================================================
ASCII Quest II - Migration 004: Combat Enemy AI Initialization
===============================================================================

Adds one durable compatibility marker for Task 6 enemy AI initialization.
Existing encounter rows remain NULL and no combat state is otherwise changed.

This migration is intentionally NOT applied by the development agent.
===============================================================================
*/

USE ascii_quest;

DROP PROCEDURE IF EXISTS run_004_combat_enemy_ai_initialization;

DELIMITER $$

CREATE PROCEDURE run_004_combat_enemy_ai_initialization()
BEGIN
    DECLARE v_count INT DEFAULT 0;

    SELECT COUNT(*)
      INTO v_count
      FROM schema_migrations
     WHERE migration_id = '003_combat_foundation';

    IF v_count <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration 004 requires migration 003';
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM schema_migrations
     WHERE migration_id = '004_combat_enemy_ai_initialization';

    IF v_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration 004_combat_enemy_ai_initialization is already applied';
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'combat_encounters'
       AND ENGINE = 'InnoDB';

    IF v_count <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration 004 preflight failed: combat_encounters must exist and use InnoDB';
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'combat_encounters'
       AND COLUMN_NAME = 'timeline_elapsed_ms'
       AND DATA_TYPE = 'bigint'
       AND COLUMN_TYPE REGEXP '^bigint(\\([0-9]+\\))? unsigned$'
       AND IS_NULLABLE = 'NO';

    IF v_count <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration 004 preflight failed: timeline_elapsed_ms must be BIGINT UNSIGNED NOT NULL';
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'combat_encounters'
       AND COLUMN_NAME = 'enemy_ai_initialized_timeline_ms';

    IF v_count <> 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration 004 preflight failed: enemy AI marker exists without its migration record';
    END IF;

    ALTER TABLE combat_encounters
        ADD COLUMN enemy_ai_initialized_timeline_ms BIGINT UNSIGNED NULL,
        ADD CONSTRAINT chk_combat_encounters_enemy_ai_initialized
            CHECK (
                enemy_ai_initialized_timeline_ms IS NULL
                OR enemy_ai_initialized_timeline_ms <= timeline_elapsed_ms
            );

    INSERT INTO schema_migrations (migration_id)
    VALUES ('004_combat_enemy_ai_initialization');
END$$

DELIMITER ;

CALL run_004_combat_enemy_ai_initialization();
DROP PROCEDURE run_004_combat_enemy_ai_initialization;

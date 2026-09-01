/*
===============================================================================
ASCII Quest II - Migration 002: Champion Warp Unlocks
===============================================================================

Stores only permanent Champion-to-Warp-ID unlocks. Warp names, maps,
coordinates and costs remain authoritative in version-controlled map JSON.

This migration is intentionally NOT applied by the development agent.
===============================================================================
*/

USE ascii_quest;

CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_id VARCHAR(100) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

DROP PROCEDURE IF EXISTS run_002_character_warps;

DELIMITER $$

CREATE PROCEDURE run_002_character_warps()
BEGIN
    DECLARE v_count INT DEFAULT 0;

    SELECT COUNT(*)
      INTO v_count
      FROM schema_migrations
     WHERE migration_id = '002_character_warps';

    IF v_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration 002_character_warps is already applied';
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'characters';

    IF v_count <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration 002 preflight failed: characters table is missing';
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'character_warps';

    IF v_count <> 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration 002 preflight failed: character_warps already exists without a migration record';
    END IF;

    CREATE TABLE character_warps (
        character_id INT UNSIGNED NOT NULL,
        warp_id VARCHAR(64) NOT NULL,
        unlocked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (character_id, warp_id),
        CONSTRAINT fk_character_warps_character
            FOREIGN KEY (character_id)
            REFERENCES characters (id)
            ON DELETE CASCADE
    ) ENGINE=InnoDB;

    INSERT INTO schema_migrations (migration_id)
    VALUES ('002_character_warps');
END$$

DELIMITER ;

CALL run_002_character_warps();
DROP PROCEDURE run_002_character_warps;

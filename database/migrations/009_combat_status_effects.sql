/* Migration 009: durable enemy status effects. Prerequisite 008.
   NOT APPLIED. Legacy snapshots remain NULL; no history backfill.
   DDL is not transactional in MariaDB: preflight rejects partial installs. */
USE ascii_quest;
DROP PROCEDURE IF EXISTS run_009_combat_status_effects;
DELIMITER $$
CREATE PROCEDURE run_009_combat_status_effects()
BEGIN
    DECLARE v_count INT DEFAULT 0;
    SELECT COUNT(*) INTO v_count FROM schema_migrations
     WHERE migration_id = '008_combat_elemental_snapshots';
    IF v_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 009 requires migration 008';
    END IF;
    SELECT COUNT(*) INTO v_count FROM schema_migrations
     WHERE migration_id = '009_combat_status_effects';
    IF v_count > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 009_combat_status_effects is already applied';
    END IF;
    SELECT COUNT(*) INTO v_count FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'combat_status_effects';
    IF v_count <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 009 partial install: status table exists';
    END IF;
    SELECT COUNT(*) INTO v_count FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'combat_actions'
       AND COLUMN_NAME IN ('snapshot_poison_status_damage', 'snapshot_bleed_status_damage',
          'snapshot_burn_status_damage', 'snapshot_freeze_status_damage', 'snapshot_shock_status_damage');
    IF v_count <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 009 partial install: status snapshots exist';
    END IF;
    SELECT COUNT(*) INTO v_count FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('combat_actions', 'combat_encounters') AND ENGINE = 'InnoDB';
    IF v_count <> 2 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 009 requires InnoDB combat tables';
    END IF;
    SELECT COUNT(*) INTO v_count FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('combat_actions', 'combat_encounters')
       AND COLUMN_NAME = 'id' AND DATA_TYPE = 'int'
       AND COLUMN_TYPE REGEXP '^int(\\([0-9]+\\))? unsigned$' AND IS_NULLABLE = 'NO' AND COLUMN_KEY = 'PRI';
    IF v_count <> 2 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 009 combat primary key shape mismatch';
    END IF;
    SELECT COUNT(*) INTO v_count FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'combat_actions'
       AND COLUMN_NAME IN ('snapshot_fire_damage', 'snapshot_lightning_damage', 'snapshot_cold_damage', 'snapshot_poison_damage')
       AND DATA_TYPE = 'int' AND COLUMN_TYPE REGEXP '^int(\\([0-9]+\\))? unsigned$' AND IS_NULLABLE = 'YES';
    IF v_count <> 4 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 009 prerequisite 008 column shape mismatch';
    END IF;

    ALTER TABLE combat_actions
        ADD COLUMN snapshot_poison_status_damage INT UNSIGNED NULL,
        ADD COLUMN snapshot_bleed_status_damage INT UNSIGNED NULL,
        ADD COLUMN snapshot_burn_status_damage INT UNSIGNED NULL,
        ADD COLUMN snapshot_freeze_status_damage INT UNSIGNED NULL,
        ADD COLUMN snapshot_shock_status_damage INT UNSIGNED NULL;

    CREATE TABLE combat_status_effects (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        encounter_id INT UNSIGNED NOT NULL,
        source_action_id INT UNSIGNED NOT NULL,
        target_actor VARCHAR(16) NOT NULL,
        status_key VARCHAR(16) NOT NULL,
        potency INT UNSIGNED NOT NULL,
        applied_timeline_ms BIGINT UNSIGNED NOT NULL,
        next_tick_timeline_ms BIGINT UNSIGNED NULL,
        expires_timeline_ms BIGINT UNSIGNED NOT NULL,
        state VARCHAR(16) NOT NULL,
        active_slot TINYINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN state = 'active' THEN 1 ELSE NULL END) STORED,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
        UNIQUE KEY uq_status_source (source_action_id, target_actor, status_key),
        UNIQUE KEY uq_status_active (encounter_id, target_actor, status_key, active_slot),
        KEY idx_status_schedule (encounter_id, state, next_tick_timeline_ms, expires_timeline_ms),
        CONSTRAINT fk_status_encounter FOREIGN KEY (encounter_id) REFERENCES combat_encounters (id),
        CONSTRAINT fk_status_action FOREIGN KEY (source_action_id) REFERENCES combat_actions (id),
        CONSTRAINT chk_status_target CHECK (target_actor = 'enemy'),
        CONSTRAINT chk_status_key CHECK (status_key IN ('poison', 'bleed', 'burn', 'freeze', 'shock')),
        CONSTRAINT chk_status_state CHECK (state IN ('active', 'expired')),
        CONSTRAINT chk_status_duration CHECK (expires_timeline_ms = applied_timeline_ms + 4000),
        CONSTRAINT chk_status_tick CHECK (
            (status_key IN ('freeze', 'shock') AND next_tick_timeline_ms IS NULL) OR
            (status_key IN ('poison', 'bleed', 'burn') AND
                (next_tick_timeline_ms IS NULL OR
                 next_tick_timeline_ms BETWEEN applied_timeline_ms + 1000 AND expires_timeline_ms)))
    ) ENGINE=InnoDB;

    INSERT INTO schema_migrations (migration_id) VALUES ('009_combat_status_effects');
END$$
DELIMITER ;
CALL run_009_combat_status_effects();
DROP PROCEDURE run_009_combat_status_effects;

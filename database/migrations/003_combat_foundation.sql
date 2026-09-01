/*
===============================================================================
ASCII Quest II - Migration 003: Combat Foundation
===============================================================================

Adds durable combat aggregates and Champion life state. This migration is
additive: it does not create encounters or change Champion HP, Mana, map state,
Gold, or experience.

This migration is intentionally NOT applied by the development agent.
===============================================================================
*/

USE ascii_quest;

CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_id VARCHAR(100) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

DROP PROCEDURE IF EXISTS run_003_combat_foundation;

DELIMITER $$

CREATE PROCEDURE run_003_combat_foundation()
BEGIN
    DECLARE v_count INT DEFAULT 0;

    SELECT COUNT(*)
      INTO v_count
      FROM schema_migrations
     WHERE migration_id = '003_combat_foundation';

    IF v_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration 003_combat_foundation is already applied';
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM schema_migrations
     WHERE migration_id IN ('001_character_stat_system', '002_character_warps');

    IF v_count <> 2 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration 003 requires migrations 001 and 002';
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'characters';

    IF v_count <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration 003 preflight failed: characters table is missing';
    END IF;

    /* DATA_TYPE rejects BIGINT and other integer families. COLUMN_TYPE accepts
       either modern no-display-width INT UNSIGNED or legacy INT(n) UNSIGNED. */
    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'characters'
       AND COLUMN_NAME = 'id'
       AND DATA_TYPE = 'int'
       AND COLUMN_TYPE REGEXP '^int(\\([0-9]+\\))? unsigned$';

    IF v_count <> 1 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration 003 preflight failed: characters.id must be INT UNSIGNED';
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME IN ('combat_encounters', 'combat_actions', 'combat_events');

    IF v_count <> 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration 003 preflight failed: a combat table exists without its migration record';
    END IF;

    SELECT COUNT(*)
      INTO v_count
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'characters'
       AND COLUMN_NAME IN ('life_state', 'died_at');

    IF v_count <> 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration 003 preflight failed: Champion lifecycle columns exist without its migration record';
    END IF;

    ALTER TABLE characters
        ADD COLUMN life_state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'alive',
        ADD COLUMN died_at DATETIME(6) NULL,
        ADD CONSTRAINT chk_characters_life_state
            CHECK (life_state IN ('alive', 'dead')),
        ADD CONSTRAINT chk_characters_death_timestamp
            CHECK (
                (life_state = 'alive' AND died_at IS NULL)
                OR (life_state = 'dead' AND died_at IS NOT NULL)
            );

    CREATE TABLE combat_encounters (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        character_id INT UNSIGNED NOT NULL,
        enemy_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        active_slot TINYINT UNSIGNED NULL,
        enemy_max_hp INT UNSIGNED NOT NULL,
        enemy_current_hp INT UNSIGNED NOT NULL,
        timeline_elapsed_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
        last_synchronized_at DATETIME(6) NOT NULL,
        turn_number INT UNSIGNED NOT NULL DEFAULT 1,
        turn_started_timeline_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
        next_enemy_decision_timeline_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
        player_actions_remaining SMALLINT UNSIGNED NOT NULL,
        enemy_actions_remaining SMALLINT UNSIGNED NOT NULL,
        potion_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
        potion_charge_allowance SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        potion_charges_remaining SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        reward_gold INT UNSIGNED NOT NULL DEFAULT 0,
        reward_experience INT UNSIGNED NOT NULL DEFAULT 0,
        rewards_issued_at DATETIME(6) NULL,
        death_processed_at DATETIME(6) NULL,
        killer_enemy_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
        completed_at DATETIME(6) NULL,
        version INT UNSIGNED NOT NULL DEFAULT 1,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
        PRIMARY KEY (id),
        UNIQUE KEY uq_combat_encounters_active (character_id, active_slot),
        KEY ix_combat_encounters_character_status (character_id, status),
        CONSTRAINT fk_combat_encounters_character
            FOREIGN KEY (character_id)
            REFERENCES characters (id)
            ON DELETE CASCADE,
        CONSTRAINT chk_combat_encounters_status
            CHECK (status IN ('active', 'victory_loot', 'defeated', 'closed')),
        CONSTRAINT chk_combat_encounters_active_slot
            CHECK (
                (status IN ('active', 'victory_loot') AND active_slot = 1)
                OR (status IN ('defeated', 'closed') AND active_slot IS NULL)
            ),
        CONSTRAINT chk_combat_encounters_enemy_max_hp
            CHECK (enemy_max_hp > 0),
        CONSTRAINT chk_combat_encounters_enemy_current_hp
            CHECK (enemy_current_hp <= enemy_max_hp),
        CONSTRAINT chk_combat_encounters_turn
            CHECK (turn_number > 0),
        CONSTRAINT chk_combat_encounters_timeline
            CHECK (
                turn_started_timeline_ms <= timeline_elapsed_ms
                AND next_enemy_decision_timeline_ms >= turn_started_timeline_ms
            ),
        CONSTRAINT chk_combat_encounters_potion_charges
            CHECK (potion_charges_remaining <= potion_charge_allowance),
        CONSTRAINT chk_combat_encounters_potion_definition
            CHECK (
                (potion_key IS NULL AND potion_charge_allowance = 0 AND potion_charges_remaining = 0)
                OR potion_key IS NOT NULL
            ),
        CONSTRAINT chk_combat_encounters_version
            CHECK (version > 0)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    CREATE TABLE combat_actions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        encounter_id INT UNSIGNED NOT NULL,
        parent_action_id INT UNSIGNED NULL,
        actor VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        action_kind VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        definition_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        request_token CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
        active_slot TINYINT UNSIGNED NULL,
        state VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        started_timeline_ms BIGINT UNSIGNED NULL,
        resolves_timeline_ms BIGINT UNSIGNED NULL,
        cooldown_ready_timeline_ms BIGINT UNSIGNED NULL,
        completed_timeline_ms BIGINT UNSIGNED NULL,
        snapshot_weapon_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
        snapshot_damage_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
        snapshot_base_damage INT UNSIGNED NULL,
        snapshot_accuracy DECIMAL(7,3) NULL,
        snapshot_critical_chance DECIMAL(7,3) NULL,
        snapshot_critical_damage INT NULL,
        block_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
        block_expires_timeline_ms BIGINT UNSIGNED NULL,
        block_attempted_timeline_ms BIGINT UNSIGNED NULL,
        block_prompt_x DECIMAL(6,3) NULL,
        block_prompt_y DECIMAL(6,3) NULL,
        resolved_damage INT UNSIGNED NULL,
        prevented_damage INT UNSIGNED NULL,
        healing_applied INT UNSIGNED NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
        PRIMARY KEY (id),
        UNIQUE KEY uq_combat_actions_request (encounter_id, request_token),
        UNIQUE KEY uq_combat_actions_actor_active (encounter_id, actor, active_slot),
        UNIQUE KEY uq_combat_actions_block_token (block_token),
        KEY ix_combat_actions_parent (parent_action_id),
        KEY ix_combat_actions_due (encounter_id, state, resolves_timeline_ms),
        CONSTRAINT fk_combat_actions_encounter
            FOREIGN KEY (encounter_id)
            REFERENCES combat_encounters (id)
            ON DELETE CASCADE,
        CONSTRAINT fk_combat_actions_parent
            FOREIGN KEY (parent_action_id)
            REFERENCES combat_actions (id)
            ON DELETE SET NULL,
        CONSTRAINT chk_combat_actions_actor
            CHECK (actor IN ('player', 'enemy', 'system')),
        CONSTRAINT chk_combat_actions_kind
            CHECK (action_kind IN ('attack', 'weapon', 'skill', 'block', 'potion')),
        CONSTRAINT chk_combat_actions_state
            CHECK (state IN ('pending', 'resolved', 'cancelled', 'rejected')),
        CONSTRAINT chk_combat_actions_active_slot
            CHECK (
                (state = 'pending' AND active_slot = 1)
                OR (state IN ('resolved', 'cancelled', 'rejected') AND active_slot IS NULL)
            ),
        CONSTRAINT chk_combat_actions_timeline
            CHECK (
                (resolves_timeline_ms IS NULL OR started_timeline_ms IS NULL OR resolves_timeline_ms >= started_timeline_ms)
                AND (cooldown_ready_timeline_ms IS NULL OR started_timeline_ms IS NULL OR cooldown_ready_timeline_ms >= started_timeline_ms)
                AND (completed_timeline_ms IS NULL OR started_timeline_ms IS NULL OR completed_timeline_ms >= started_timeline_ms)
                AND (block_expires_timeline_ms IS NULL OR started_timeline_ms IS NULL OR block_expires_timeline_ms >= started_timeline_ms)
                AND (block_attempted_timeline_ms IS NULL OR block_expires_timeline_ms IS NULL OR block_attempted_timeline_ms <= block_expires_timeline_ms)
            ),
        CONSTRAINT chk_combat_actions_snapshot_rates
            CHECK (
                (snapshot_accuracy IS NULL OR snapshot_accuracy BETWEEN 0.000 AND 100.000)
                AND (snapshot_critical_chance IS NULL OR snapshot_critical_chance BETWEEN 0.000 AND 100.000)
            ),
        CONSTRAINT chk_combat_actions_block_prompt_x
            CHECK (block_prompt_x BETWEEN 0.000 AND 1.000),
        CONSTRAINT chk_combat_actions_block_prompt_y
            CHECK (block_prompt_y BETWEEN 0.000 AND 1.000)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    CREATE TABLE combat_events (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        encounter_id INT UNSIGNED NOT NULL,
        sequence_number INT UNSIGNED NOT NULL,
        event_type VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        message VARCHAR(500) NOT NULL,
        emphasis VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NULL,
        created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        PRIMARY KEY (id),
        UNIQUE KEY uq_combat_events_sequence (encounter_id, sequence_number),
        CONSTRAINT fk_combat_events_encounter
            FOREIGN KEY (encounter_id)
            REFERENCES combat_encounters (id)
            ON DELETE CASCADE,
        CONSTRAINT chk_combat_events_sequence
            CHECK (sequence_number > 0),
        CONSTRAINT chk_combat_events_message
            CHECK (CHAR_LENGTH(message) > 0)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    INSERT INTO schema_migrations (migration_id)
    VALUES ('003_combat_foundation');
END$$

DELIMITER ;

CALL run_003_combat_foundation();
DROP PROCEDURE run_003_combat_foundation;

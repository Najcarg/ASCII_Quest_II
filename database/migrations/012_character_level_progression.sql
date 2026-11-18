/* Task34A. Migration012 NOT APPLIED during development.
   Future skill migration is 013. Quiesce runtime and take a validated backup.
   MariaDB DDL implicitly commits: column installation and data/marker transaction
   are NOT one atomic unit. Partial install fails closed; inspect/restore manually.
   Reconciles ONLY alive level caches. Never changes XP, dead history, stats,
   resources, gold, items, existing action snapshots or historical events. */
USE ascii_quest;
DROP PROCEDURE IF EXISTS run_012_character_level_progression;
DELIMITER $$
CREATE PROCEDURE run_012_character_level_progression()
BEGIN
    DECLARE v_count INT DEFAULT 0;
    DECLARE v_lock INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        IF v_lock = 1 THEN
            DO RELEASE_LOCK('ascii_quest.migration012');
        END IF;
        RESIGNAL;
    END;
    SELECT GET_LOCK('ascii_quest.migration012', 0) INTO v_lock;
    IF v_lock IS NULL OR v_lock <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 012 lock unavailable';
    END IF;
    IF @@session.check_constraint_checks <> 1 OR @@session.foreign_key_checks <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 012 requires constraint enforcement';
    END IF;
    SELECT COUNT(*) INTO v_count FROM schema_migrations
     WHERE migration_id = '011_test_item_provenance';
    IF v_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 012 requires migration 011';
    END IF;
    SELECT COUNT(*) INTO v_count FROM schema_migrations
     WHERE migration_id = '012_character_level_progression';
    IF v_count <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 012 already applied';
    END IF;
    SELECT COUNT(*) INTO v_count FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'combat_encounters'
       AND COLUMN_NAME = 'reward_progression';
    IF v_count <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 012 partial snapshot install';
    END IF;
    SELECT COUNT(*) INTO v_count FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('characters', 'combat_encounters', 'schema_migrations')
       AND ENGINE = 'InnoDB';
    IF v_count <> 3 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 012 requires InnoDB parents and marker';
    END IF;
    SELECT COUNT(*) INTO v_count FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'characters'
       AND COLUMN_NAME IN ('level', 'experience') AND IS_NULLABLE = 'NO'
       AND DATA_TYPE IN ('tinyint', 'smallint', 'mediumint', 'int', 'bigint');
    IF v_count <> 2 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 012 unexpected level or XP types';
    END IF;
    SELECT COUNT(*) INTO v_count FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'characters'
       AND COLUMN_NAME = 'experience' AND DATA_TYPE IN ('int', 'bigint');
    IF v_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 012 XP capacity too small';
    END IF;
    SELECT COUNT(*) INTO v_count FROM characters
     WHERE experience IS NULL OR experience < 0 OR experience > 9223372036854775807
        OR level IS NULL OR level < 1 OR life_state IS NULL OR life_state NOT IN ('alive', 'dead')
        OR (life_state = 'alive' AND (current_hp <= 0 OR died_at IS NOT NULL));
    IF v_count <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 012 unexpected Champion lifecycle or XP';
    END IF;
    ALTER TABLE combat_encounters
        ADD COLUMN reward_progression LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
        ADD CONSTRAINT chk_combat_reward_progression_json
            CHECK (reward_progression IS NULL OR JSON_VALID(reward_progression));

    START TRANSACTION;
    -- Exact immutable curve v1: cumulative totalXP = 100 * (level - 1)^2.
    UPDATE characters
       SET level = CASE
            WHEN experience >= 240100 THEN 50
            WHEN experience >= 230400 THEN 49
            WHEN experience >= 220900 THEN 48
            WHEN experience >= 211600 THEN 47
            WHEN experience >= 202500 THEN 46
            WHEN experience >= 193600 THEN 45
            WHEN experience >= 184900 THEN 44
            WHEN experience >= 176400 THEN 43
            WHEN experience >= 168100 THEN 42
            WHEN experience >= 160000 THEN 41
            WHEN experience >= 152100 THEN 40
            WHEN experience >= 144400 THEN 39
            WHEN experience >= 136900 THEN 38
            WHEN experience >= 129600 THEN 37
            WHEN experience >= 122500 THEN 36
            WHEN experience >= 115600 THEN 35
            WHEN experience >= 108900 THEN 34
            WHEN experience >= 102400 THEN 33
            WHEN experience >= 96100 THEN 32
            WHEN experience >= 90000 THEN 31
            WHEN experience >= 84100 THEN 30
            WHEN experience >= 78400 THEN 29
            WHEN experience >= 72900 THEN 28
            WHEN experience >= 67600 THEN 27
            WHEN experience >= 62500 THEN 26
            WHEN experience >= 57600 THEN 25
            WHEN experience >= 52900 THEN 24
            WHEN experience >= 48400 THEN 23
            WHEN experience >= 44100 THEN 22
            WHEN experience >= 40000 THEN 21
            WHEN experience >= 36100 THEN 20
            WHEN experience >= 32400 THEN 19
            WHEN experience >= 28900 THEN 18
            WHEN experience >= 25600 THEN 17
            WHEN experience >= 22500 THEN 16
            WHEN experience >= 19600 THEN 15
            WHEN experience >= 16900 THEN 14
            WHEN experience >= 14400 THEN 13
            WHEN experience >= 12100 THEN 12
            WHEN experience >= 10000 THEN 11
            WHEN experience >= 8100 THEN 10
            WHEN experience >= 6400 THEN 9
            WHEN experience >= 4900 THEN 8
            WHEN experience >= 3600 THEN 7
            WHEN experience >= 2500 THEN 6
            WHEN experience >= 1600 THEN 5
            WHEN experience >= 900 THEN 4
            WHEN experience >= 400 THEN 3
            WHEN experience >= 100 THEN 2
            ELSE 1
        END
     WHERE life_state = 'alive'
       AND CASE WHEN level BETWEEN 1 AND 50 THEN
            experience < 100 * (level - 1) * (level - 1)
            OR (level < 50 AND experience >= 100 * level * level)
           ELSE 1 END;
    INSERT INTO schema_migrations (migration_id) VALUES ('012_character_level_progression');
    COMMIT;
    DO RELEASE_LOCK('ascii_quest.migration012');
END$$
DELIMITER ;
CALL run_012_character_level_progression();
DROP PROCEDURE run_012_character_level_progression;

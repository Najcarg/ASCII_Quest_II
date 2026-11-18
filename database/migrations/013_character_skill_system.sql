/* Task34B. Migration013 NOT APPLIED during development anywhere.
   Single authoritative catalogue seed. No Champion unlock/loadout/point grants.
   No UPDATE of historical rows. Quiesced maintenance + validated rollback backup
   required when separately authorized. MariaDB DDL implicitly commits; partial
   install fails closed and requires manual recovery, never blind rerun. */
USE ascii_quest;
DROP PROCEDURE IF EXISTS run_013_character_skill_system;
DELIMITER $$
CREATE PROCEDURE run_013_character_skill_system()
BEGIN
    DECLARE v_count INT DEFAULT 0;
    DECLARE v_lock INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF v_lock = 1 THEN DO RELEASE_LOCK('ascii_quest.migration013'); END IF;
        RESIGNAL;
    END;
    SELECT GET_LOCK('ascii_quest.migration013', 0) INTO v_lock;
    IF v_lock IS NULL OR v_lock <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration013 lock unavailable';
    END IF;
    IF @@session.check_constraint_checks <> 1 OR @@session.foreign_key_checks <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration013 requires constraint enforcement';
    END IF;
    SELECT COUNT(*) INTO v_count FROM schema_migrations WHERE migration_id = '012_character_level_progression';
    IF v_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration013 requires012'; END IF;
    SELECT COUNT(*) INTO v_count FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'combat_encounters' AND COLUMN_NAME = 'reward_progression' AND DATA_TYPE = 'longtext' AND IS_NULLABLE = 'YES';
    IF v_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration013 prerequisite012 snapshot shape missing'; END IF;
    SELECT COUNT(*) INTO v_count FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'combat_encounters' AND CONSTRAINT_NAME = 'chk_combat_reward_progression_json';
    IF v_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration013 prerequisite012 CHECK missing'; END IF;
    SELECT COUNT(*) INTO v_count FROM schema_migrations WHERE LEFT(migration_id,4) = '013_';
    IF v_count <> 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration013 already applied'; END IF;
    SELECT COUNT(*) INTO v_count FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME IN ('skill_definitions','skill_prerequisites','character_skills','character_skill_loadout','skill_mutation_requests');
    IF v_count <> 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration013 partial tables'; END IF;
    SELECT COUNT(*) INTO v_count FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'combat_actions' AND COLUMN_NAME = 'snapshot_skill_definition';
    IF v_count <> 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration013 partial snapshot'; END IF;
    SELECT COUNT(*) INTO v_count FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME IN ('characters','character_classes','combat_actions','schema_migrations') AND ENGINE = 'InnoDB';
    IF v_count <> 4 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration013 requires InnoDB parents'; END IF;
    SELECT COUNT(*) INTO v_count FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME IN ('characters','character_classes') AND COLUMN_NAME = 'id' AND IS_NULLABLE = 'NO'
      AND DATA_TYPE = 'int' AND COLUMN_TYPE REGEXP '^int(\\([0-9]+\\))? unsigned$';
    IF v_count <> 2 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration013 unexpected parent IDs'; END IF;
    SELECT COUNT(*) INTO v_count FROM character_classes WHERE BINARY class_key IN ('warrior','mage','rogue','cleric');
    IF v_count <> 4 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration013 canonical classes missing'; END IF;

    CREATE TABLE skill_definitions (
        skill_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        class_id INT UNSIGNED NOT NULL,
        tree_position VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        display_name VARCHAR(64) NOT NULL,
        description VARCHAR(255) NOT NULL,
        skill_type VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        minimum_level TINYINT UNSIGNED NOT NULL,
        damage_type VARCHAR(12) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        base_damage SMALLINT UNSIGNED NOT NULL,
        duration_ms SMALLINT UNSIGNED NOT NULL,
        cooldown_ms INT UNSIGNED NOT NULL,
        status_key VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NULL,
        status_power SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        is_active TINYINT UNSIGNED NOT NULL DEFAULT 1,
        PRIMARY KEY (skill_key),
        UNIQUE KEY uq_skill_class_position (class_id, tree_position),
        UNIQUE KEY uq_skill_class (skill_key, class_id),
        UNIQUE KEY uq_skill_type (skill_key, skill_type),
        CONSTRAINT fk_skill_class FOREIGN KEY (class_id) REFERENCES character_classes(id) ON DELETE RESTRICT,
        CONSTRAINT chk_skill_position CHECK (tree_position IN ('root','branch_1','branch_2','ultimate')),
        CONSTRAINT chk_skill_type CHECK ((tree_position = 'ultimate' AND skill_type = 'ultimate') OR (tree_position <> 'ultimate' AND skill_type = 'normal')),
        CONSTRAINT chk_skill_level CHECK (minimum_level BETWEEN 2 AND 50),
        CONSTRAINT chk_skill_damage CHECK (damage_type IN ('physical','fire','cold','lightning','poison') AND base_damage BETWEEN 1 AND 10000),
        CONSTRAINT chk_skill_timing CHECK (duration_ms BETWEEN 100 AND 10000 AND cooldown_ms BETWEEN 100 AND 600000),
        CONSTRAINT chk_skill_status CHECK ((status_key IS NULL AND status_power = 0) OR (status_key IS NOT NULL AND status_key IN ('poison','bleed','burn','freeze','shock') AND status_power BETWEEN 1 AND 100)),
        CONSTRAINT chk_skill_active CHECK (is_active IN (0,1))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE skill_prerequisites (
        skill_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        prerequisite_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        class_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (skill_key, prerequisite_key),
        CONSTRAINT fk_skill_prereq_child FOREIGN KEY (skill_key, class_id) REFERENCES skill_definitions(skill_key, class_id) ON DELETE RESTRICT,
        CONSTRAINT fk_skill_prereq_parent FOREIGN KEY (prerequisite_key, class_id) REFERENCES skill_definitions(skill_key, class_id) ON DELETE RESTRICT,
        CONSTRAINT chk_skill_prereq_self CHECK (skill_key <> prerequisite_key)
    ) ENGINE=InnoDB;
    CREATE TABLE character_skills (
        character_id INT UNSIGNED NOT NULL,
        skill_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        unlocked_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        PRIMARY KEY (character_id, skill_key),
        CONSTRAINT fk_character_skills_owner FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE,
        CONSTRAINT fk_character_skills_definition FOREIGN KEY (skill_key) REFERENCES skill_definitions(skill_key) ON DELETE RESTRICT
    ) ENGINE=InnoDB;
    CREATE TABLE character_skill_loadout (
        character_id INT UNSIGNED NOT NULL,
        slot VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        skill_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        skill_type VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
        PRIMARY KEY (character_id, slot),
        UNIQUE KEY uq_character_skill_assignment (character_id, skill_key),
        CONSTRAINT fk_skill_loadout_unlocked FOREIGN KEY (character_id, skill_key) REFERENCES character_skills(character_id, skill_key) ON DELETE CASCADE,
        CONSTRAINT fk_skill_loadout_type FOREIGN KEY (skill_key, skill_type) REFERENCES skill_definitions(skill_key, skill_type) ON DELETE RESTRICT,
        CONSTRAINT chk_skill_loadout_slot CHECK ((slot IN ('skill_1','skill_2','skill_3') AND skill_type = 'normal') OR (slot = 'ultimate' AND skill_type = 'ultimate'))
    ) ENGINE=InnoDB;
    CREATE TABLE skill_mutation_requests (
        character_id INT UNSIGNED NOT NULL,
        request_token CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        command_type VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        request_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        result_payload LONGTEXT NOT NULL,
        completed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        PRIMARY KEY (character_id, request_token),
        CONSTRAINT fk_skill_request_owner FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE,
        CONSTRAINT chk_skill_request_command CHECK (command_type IN ('unlock','assign','unassign')),
        CONSTRAINT chk_skill_request_payload CHECK (JSON_VALID(result_payload))
    ) ENGINE=InnoDB;
    ALTER TABLE combat_actions ADD COLUMN snapshot_skill_definition LONGTEXT NULL,
        ADD CONSTRAINT chk_combat_skill_snapshot CHECK (snapshot_skill_definition IS NULL OR JSON_VALID(snapshot_skill_definition));

    INSERT INTO skill_definitions (skill_key,class_id,tree_position,display_name,description,skill_type,minimum_level,damage_type,base_damage,duration_ms,cooldown_ms,status_key,status_power,is_active) SELECT 'warrior_power_strike',id,'root','Power Strike','A measured physical strike.','normal',2,'physical',24,1200,3000,NULL,0,1 FROM character_classes WHERE class_key='warrior';
    INSERT INTO skill_definitions (skill_key,class_id,tree_position,display_name,description,skill_type,minimum_level,damage_type,base_damage,duration_ms,cooldown_ms,status_key,status_power,is_active) SELECT 'warrior_cleave',id,'branch_1','Cleave','A heavy single-target cleave.','normal',3,'physical',30,1600,5000,NULL,0,1 FROM character_classes WHERE class_key='warrior';
    INSERT INTO skill_definitions (skill_key,class_id,tree_position,display_name,description,skill_type,minimum_level,damage_type,base_damage,duration_ms,cooldown_ms,status_key,status_power,is_active) SELECT 'warrior_crushing_blow',id,'branch_2','Crushing Blow','Crush one foe with a deliberate blow.','normal',3,'physical',28,1400,4000,NULL,0,1 FROM character_classes WHERE class_key='warrior';
    INSERT INTO skill_definitions (skill_key,class_id,tree_position,display_name,description,skill_type,minimum_level,damage_type,base_damage,duration_ms,cooldown_ms,status_key,status_power,is_active) SELECT 'warrior_berserker_strike',id,'ultimate','Berserker Strike','An ultimate single-target physical strike.','ultimate',5,'physical',52,2000,10000,NULL,0,1 FROM character_classes WHERE class_key='warrior';
    INSERT INTO skill_definitions (skill_key,class_id,tree_position,display_name,description,skill_type,minimum_level,damage_type,base_damage,duration_ms,cooldown_ms,status_key,status_power,is_active) SELECT 'mage_flame_strike',id,'root','Flame Strike','Strike with fire; intrinsic Burn may follow.','normal',2,'fire',24,1500,5000,'burn',6,1 FROM character_classes WHERE class_key='mage';
    INSERT INTO skill_definitions (skill_key,class_id,tree_position,display_name,description,skill_type,minimum_level,damage_type,base_damage,duration_ms,cooldown_ms,status_key,status_power,is_active) SELECT 'mage_frost_bolt',id,'branch_1','Frost Bolt','A cold bolt with intrinsic Freeze chance.','normal',3,'cold',20,1400,4500,'freeze',15,1 FROM character_classes WHERE class_key='mage';
    INSERT INTO skill_definitions (skill_key,class_id,tree_position,display_name,description,skill_type,minimum_level,damage_type,base_damage,duration_ms,cooldown_ms,status_key,status_power,is_active) SELECT 'mage_lightning_bolt',id,'branch_2','Lightning Bolt','Lightning with intrinsic Shock chance.','normal',3,'lightning',26,1400,4500,'shock',12,1 FROM character_classes WHERE class_key='mage';
    INSERT INTO skill_definitions (skill_key,class_id,tree_position,display_name,description,skill_type,minimum_level,damage_type,base_damage,duration_ms,cooldown_ms,status_key,status_power,is_active) SELECT 'mage_arcane_inferno',id,'ultimate','Arcane Inferno','An ultimate fire strike with intrinsic Burn chance.','ultimate',5,'fire',50,2200,11000,'burn',10,1 FROM character_classes WHERE class_key='mage';
    INSERT INTO skill_definitions (skill_key,class_id,tree_position,display_name,description,skill_type,minimum_level,damage_type,base_damage,duration_ms,cooldown_ms,status_key,status_power,is_active) SELECT 'rogue_quick_strike',id,'root','Quick Strike','A fast single-target physical strike.','normal',2,'physical',18,800,2500,NULL,0,1 FROM character_classes WHERE class_key='rogue';
    INSERT INTO skill_definitions (skill_key,class_id,tree_position,display_name,description,skill_type,minimum_level,damage_type,base_damage,duration_ms,cooldown_ms,status_key,status_power,is_active) SELECT 'rogue_venom_strike',id,'branch_1','Venom Strike','A physical strike with intrinsic Poison chance.','normal',3,'physical',20,1000,4000,'poison',8,1 FROM character_classes WHERE class_key='rogue';
    INSERT INTO skill_definitions (skill_key,class_id,tree_position,display_name,description,skill_type,minimum_level,damage_type,base_damage,duration_ms,cooldown_ms,status_key,status_power,is_active) SELECT 'rogue_bleeding_strike',id,'branch_2','Bleeding Strike','A physical strike with intrinsic Bleed chance.','normal',3,'physical',22,1100,4000,'bleed',8,1 FROM character_classes WHERE class_key='rogue';
    INSERT INTO skill_definitions (skill_key,class_id,tree_position,display_name,description,skill_type,minimum_level,damage_type,base_damage,duration_ms,cooldown_ms,status_key,status_power,is_active) SELECT 'rogue_execution',id,'ultimate','Execution','An ultimate single-target physical strike.','ultimate',5,'physical',48,1600,10000,NULL,0,1 FROM character_classes WHERE class_key='rogue';
    INSERT INTO skill_definitions (skill_key,class_id,tree_position,display_name,description,skill_type,minimum_level,damage_type,base_damage,duration_ms,cooldown_ms,status_key,status_power,is_active) SELECT 'cleric_holy_strike',id,'root','Holy Strike','A focused physical strike; no healing effect.','normal',2,'physical',22,1300,3500,NULL,0,1 FROM character_classes WHERE class_key='cleric';
    INSERT INTO skill_definitions (skill_key,class_id,tree_position,display_name,description,skill_type,minimum_level,damage_type,base_damage,duration_ms,cooldown_ms,status_key,status_power,is_active) SELECT 'cleric_smite',id,'branch_1','Smite','A lightning strike with intrinsic Shock chance.','normal',3,'lightning',28,1500,5000,'shock',10,1 FROM character_classes WHERE class_key='cleric';
    INSERT INTO skill_definitions (skill_key,class_id,tree_position,display_name,description,skill_type,minimum_level,damage_type,base_damage,duration_ms,cooldown_ms,status_key,status_power,is_active) SELECT 'cleric_judgement',id,'branch_2','Judgement','A fire strike with intrinsic Burn chance.','normal',3,'fire',30,1600,5000,'burn',8,1 FROM character_classes WHERE class_key='cleric';
    INSERT INTO skill_definitions (skill_key,class_id,tree_position,display_name,description,skill_type,minimum_level,damage_type,base_damage,duration_ms,cooldown_ms,status_key,status_power,is_active) SELECT 'cleric_divine_wrath',id,'ultimate','Divine Wrath','An ultimate lightning strike with intrinsic Shock chance.','ultimate',5,'lightning',50,2000,11000,'shock',15,1 FROM character_classes WHERE class_key='cleric';
    INSERT INTO skill_prerequisites (skill_key,prerequisite_key,class_id) SELECT 'warrior_cleave','warrior_power_strike',id FROM character_classes WHERE class_key='warrior';
    INSERT INTO skill_prerequisites (skill_key,prerequisite_key,class_id) SELECT 'warrior_crushing_blow','warrior_power_strike',id FROM character_classes WHERE class_key='warrior';
    INSERT INTO skill_prerequisites (skill_key,prerequisite_key,class_id) SELECT 'warrior_berserker_strike','warrior_cleave',id FROM character_classes WHERE class_key='warrior';
    INSERT INTO skill_prerequisites (skill_key,prerequisite_key,class_id) SELECT 'mage_frost_bolt','mage_flame_strike',id FROM character_classes WHERE class_key='mage';
    INSERT INTO skill_prerequisites (skill_key,prerequisite_key,class_id) SELECT 'mage_lightning_bolt','mage_flame_strike',id FROM character_classes WHERE class_key='mage';
    INSERT INTO skill_prerequisites (skill_key,prerequisite_key,class_id) SELECT 'mage_arcane_inferno','mage_frost_bolt',id FROM character_classes WHERE class_key='mage';
    INSERT INTO skill_prerequisites (skill_key,prerequisite_key,class_id) SELECT 'rogue_venom_strike','rogue_quick_strike',id FROM character_classes WHERE class_key='rogue';
    INSERT INTO skill_prerequisites (skill_key,prerequisite_key,class_id) SELECT 'rogue_bleeding_strike','rogue_quick_strike',id FROM character_classes WHERE class_key='rogue';
    INSERT INTO skill_prerequisites (skill_key,prerequisite_key,class_id) SELECT 'rogue_execution','rogue_venom_strike',id FROM character_classes WHERE class_key='rogue';
    INSERT INTO skill_prerequisites (skill_key,prerequisite_key,class_id) SELECT 'cleric_smite','cleric_holy_strike',id FROM character_classes WHERE class_key='cleric';
    INSERT INTO skill_prerequisites (skill_key,prerequisite_key,class_id) SELECT 'cleric_judgement','cleric_holy_strike',id FROM character_classes WHERE class_key='cleric';
    INSERT INTO skill_prerequisites (skill_key,prerequisite_key,class_id) SELECT 'cleric_divine_wrath','cleric_smite',id FROM character_classes WHERE class_key='cleric';
    SELECT COUNT(*) INTO v_count FROM skill_definitions;
    IF v_count <> 16 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration013 catalogue seed count mismatch'; END IF;
    SELECT COUNT(*) INTO v_count FROM skill_prerequisites;
    IF v_count <> 12 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration013 prerequisite seed count mismatch'; END IF;
    INSERT INTO schema_migrations (migration_id) VALUES ('013_character_skill_system');
    DO RELEASE_LOCK('ascii_quest.migration013');
END$$
DELIMITER ;
CALL run_013_character_skill_system();
DROP PROCEDURE run_013_character_skill_system;

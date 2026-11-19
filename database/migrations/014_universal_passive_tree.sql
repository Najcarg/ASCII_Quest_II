/* Task35: NOT APPLIED during development. Requires separately authorized,
   quiesced migration and rollback backup. No XP/level/history/allocation grants.
   DDL implicitly commits: partial installs fail closed; never blindly rerun. */
USE ascii_quest;
DROP PROCEDURE IF EXISTS run_014_universal_passive_tree;
DELIMITER $$
CREATE PROCEDURE run_014_universal_passive_tree()
BEGIN
    DECLARE v_count INT DEFAULT 0;
    DECLARE v_lock INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF v_lock = 1 THEN DO RELEASE_LOCK('ascii_quest.migration014'); END IF;
        RESIGNAL;
    END;
    SELECT GET_LOCK('ascii_quest.migration014',0) INTO v_lock;
    IF v_lock IS NULL OR v_lock <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Migration014 lock unavailable'; END IF;
    IF @@session.check_constraint_checks <> 1 OR @@session.foreign_key_checks <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Migration014 requires constraint enforcement';
    END IF;
    SELECT COUNT(*) INTO v_count FROM schema_migrations WHERE migration_id='013_character_skill_system';
    IF v_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Migration014 requires013'; END IF;
    SELECT COUNT(*) INTO v_count FROM schema_migrations WHERE LEFT(migration_id,4)='014_';
    IF v_count <> 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Migration014 already applied'; END IF;
    SELECT COUNT(*) INTO v_count FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME IN ('passive_definitions','character_passives','passive_mutation_requests');
    IF v_count <> 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Migration014 partial tables'; END IF;
    SELECT COUNT(*) INTO v_count FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='combat_actions' AND COLUMN_NAME='snapshot_elemental_status_chance';
    IF v_count <> 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Migration014 partial snapshot'; END IF;
    SELECT COUNT(*) INTO v_count FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME IN ('characters','combat_actions','character_skills','skill_mutation_requests','schema_migrations') AND ENGINE='InnoDB';
    IF v_count <> 5 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Migration014 requires InnoDB prerequisites'; END IF;
    SELECT COUNT(*) INTO v_count FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='characters' AND COLUMN_NAME='id' AND IS_NULLABLE='NO'
        AND DATA_TYPE='int' AND COLUMN_TYPE REGEXP '^int(\\([0-9]+\\))? unsigned$';
    IF v_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Migration014 unexpected Champion parent ID'; END IF;
    SELECT COUNT(*) INTO v_count FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='combat_actions' AND COLUMN_NAME='snapshot_skill_definition' AND DATA_TYPE='longtext' AND IS_NULLABLE='YES';
    IF v_count <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Migration014 prerequisite013 snapshot missing'; END IF;

    CREATE TABLE passive_definitions (
        passive_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        `row_number` TINYINT UNSIGNED NOT NULL,
        lane_key CHAR(1) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        display_name VARCHAR(64) NOT NULL,
        description VARCHAR(255) NOT NULL,
        effect_json LONGTEXT NOT NULL,
        is_major TINYINT UNSIGNED NOT NULL DEFAULT 0,
        is_active TINYINT UNSIGNED NOT NULL DEFAULT 1,
        PRIMARY KEY (passive_key),
        UNIQUE KEY uq_passive_row_lane (`row_number`,lane_key),
        UNIQUE KEY uq_passive_key_row (passive_key,`row_number`),
        CONSTRAINT chk_passive_row CHECK (`row_number` BETWEEN 1 AND 20),
        CONSTRAINT chk_passive_lane CHECK (lane_key IN ('a','b','c','d')),
        CONSTRAINT chk_passive_major CHECK ((`row_number` = 20 AND is_major = 1) OR (`row_number` < 20 AND is_major = 0)),
        CONSTRAINT chk_passive_active CHECK (is_active IN (0,1)),
        CONSTRAINT chk_passive_effect CHECK (JSON_VALID(effect_json))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE character_passives (
        character_id INT UNSIGNED NOT NULL,
        passive_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        `row_number` TINYINT UNSIGNED NOT NULL,
        pass_number TINYINT UNSIGNED NOT NULL,
        unlocked_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        PRIMARY KEY (character_id,passive_key),
        UNIQUE KEY uq_character_passive_row_pass (character_id,`row_number`,pass_number),
        CONSTRAINT fk_passive_owner FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE,
        CONSTRAINT fk_passive_definition FOREIGN KEY (passive_key,`row_number`) REFERENCES passive_definitions(passive_key,`row_number`) ON DELETE RESTRICT,
        CONSTRAINT chk_passive_pass CHECK (pass_number IN (1,2))
    ) ENGINE=InnoDB;
    CREATE TABLE passive_mutation_requests (
        character_id INT UNSIGNED NOT NULL,
        request_token CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        request_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        result_payload LONGTEXT NOT NULL,
        completed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
        PRIMARY KEY (character_id,request_token),
        CONSTRAINT fk_passive_request_owner FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE,
        CONSTRAINT chk_passive_request_payload CHECK (JSON_VALID(result_payload))
    ) ENGINE=InnoDB;
    ALTER TABLE combat_actions ADD COLUMN snapshot_elemental_status_chance TINYINT UNSIGNED NULL,
        ADD CONSTRAINT chk_combat_elemental_status_chance CHECK (snapshot_elemental_status_chance IS NULL OR snapshot_elemental_status_chance BETWEEN 0 AND 100);

    /* Canonical effects: flat integers except _bp (100 basis points = 1%).
       Elemental Resistance deliberately excludes Poison. */
    INSERT INTO passive_definitions (passive_key,`row_number`,lane_key,display_name,description,effect_json,is_major,is_active) VALUES
    ('passive_r01_a_strength',1,'a','Strength +3','Strength +3','{"strength":3}',0,1),
    ('passive_r01_b_energy',1,'b','Energy +3','Energy +3','{"energy":3}',0,1),
    ('passive_r01_c_dexterity',1,'c','Dexterity +3','Dexterity +3','{"dexterity":3}',0,1),
    ('passive_r01_d_vitality',1,'d','Vitality +3','Vitality +3','{"vitality":3}',0,1),
    ('passive_r02_a_maximum_life',2,'a','Maximum Life +25','Maximum Life +25','{"maximum_life":25}',0,1),
    ('passive_r02_b_maximum_mana',2,'b','Maximum Mana +20','Maximum Mana +20','{"maximum_mana":20}',0,1),
    ('passive_r02_c_accuracy',2,'c','Accuracy +5','Accuracy +5','{"accuracy":5}',0,1),
    ('passive_r02_d_maximum_life',2,'d','Maximum Life +40','Maximum Life +40','{"maximum_life":40}',0,1),
    ('passive_r03_a_physical_damage',3,'a','Physical Damage +5','Physical Damage +5','{"physical_damage":5}',0,1),
    ('passive_r03_b_fire_damage',3,'b','Fire Damage +5','Fire Damage +5','{"fire_damage":5}',0,1),
    ('passive_r03_c_critical_chance',3,'c','Critical Chance +4%','Critical Chance +4%','{"critical_chance":4}',0,1),
    ('passive_r03_d_healing_power',3,'d','Healing Power +5%','Healing Power +5%','{"healing_power_percent":5}',0,1),
    ('passive_r04_a_block_rate',4,'a','Block Rate +10%','Block Rate +10%','{"block_rate_bp":1000}',0,1),
    ('passive_r04_b_cold_damage',4,'b','Cold Damage +5','Cold Damage +5','{"cold_damage":5}',0,1),
    ('passive_r04_c_critical_damage',4,'c','Critical Damage +10%','Critical Damage +10%','{"critical_damage":10}',0,1),
    ('passive_r04_d_life_on_hit',4,'d','Life on Hit +3','Life on Hit +3','{"life_on_hit":3}',0,1),
    ('passive_r05_a_toughness',5,'a','Toughness +4','Toughness +4','{"toughness":4}',0,1),
    ('passive_r05_b_lightning_damage',5,'b','Lightning Damage +5','Lightning Damage +5','{"lightning_damage":5}',0,1),
    ('passive_r05_c_dodging',5,'c','Dodging +4%','Dodging +4%','{"dodging":4}',0,1),
    ('passive_r05_d_elemental_resistance',5,'d','Elemental Resistance +4%','Fire, Cold and Lightning Resistance +4%','{"fire_resistance":4,"cold_resistance":4,"lightning_resistance":4}',0,1),
    ('passive_r06_a_physical_damage',6,'a','Physical Damage +10','Physical Damage +10','{"physical_damage":10}',0,1),
    ('passive_r06_b_fire_resistance',6,'b','Fire Resistance +5%','Fire Resistance +5%','{"fire_resistance":5}',0,1),
    ('passive_r06_c_poison_damage',6,'c','Poison Damage +5','Poison Damage +5','{"poison_damage":5}',0,1),
    ('passive_r06_d_healing_power',6,'d','Healing Power +10%','Healing Power +10%','{"healing_power_percent":10}',0,1),
    ('passive_r07_a_maximum_life',7,'a','Maximum Life +50','Maximum Life +50','{"maximum_life":50}',0,1),
    ('passive_r07_b_cold_resistance',7,'b','Cold Resistance +5%','Cold Resistance +5%','{"cold_resistance":5}',0,1),
    ('passive_r07_c_bleed_damage',7,'c','Bleed Damage +5','Bleed Damage +5','{"bleed_damage":5}',0,1),
    ('passive_r07_d_block_rate',7,'d','Block Rate +15%','Block Rate +15%','{"block_rate_bp":1500}',0,1),
    ('passive_r08_a_strength',8,'a','Strength +5','Strength +5','{"strength":5}',0,1),
    ('passive_r08_b_lightning_resistance',8,'b','Lightning Resistance +5%','Lightning Resistance +5%','{"lightning_resistance":5}',0,1),
    ('passive_r08_c_accuracy',8,'c','Accuracy +8','Accuracy +8','{"accuracy":8}',0,1),
    ('passive_r08_d_vitality',8,'d','Vitality +5','Vitality +5','{"vitality":5}',0,1),
    ('passive_r09_a_life_on_hit',9,'a','Life on Hit +5','Life on Hit +5','{"life_on_hit":5}',0,1),
    ('passive_r09_b_maximum_mana',9,'b','Maximum Mana +35','Maximum Mana +35','{"maximum_mana":35}',0,1),
    ('passive_r09_c_critical_chance',9,'c','Critical Chance +6%','Critical Chance +6%','{"critical_chance":6}',0,1),
    ('passive_r09_d_maximum_life',9,'d','Maximum Life +60','Maximum Life +60','{"maximum_life":60}',0,1),
    ('passive_r10_a_physical_damage',10,'a','Physical Damage +10','Physical Damage +10','{"physical_damage":10}',0,1),
    ('passive_r10_b_fire_damage',10,'b','Fire Damage +10','Fire Damage +10','{"fire_damage":10}',0,1),
    ('passive_r10_c_critical_damage',10,'c','Critical Damage +15%','Critical Damage +15%','{"critical_damage":15}',0,1),
    ('passive_r10_d_healing_power',10,'d','Healing Power +10%','Healing Power +10%','{"healing_power_percent":10}',0,1),
    ('passive_r11_a_toughness',11,'a','Toughness +6','Toughness +6','{"toughness":6}',0,1),
    ('passive_r11_b_cold_damage',11,'b','Cold Damage +10','Cold Damage +10','{"cold_damage":10}',0,1),
    ('passive_r11_c_poison_damage',11,'c','Poison Damage +10','Poison Damage +10','{"poison_damage":10}',0,1),
    ('passive_r11_d_life_on_hit',11,'d','Life on Hit +5','Life on Hit +5','{"life_on_hit":5}',0,1),
    ('passive_r12_a_block_rate',12,'a','Block Rate +15%','Block Rate +15%','{"block_rate_bp":1500}',0,1),
    ('passive_r12_b_lightning_damage',12,'b','Lightning Damage +10','Lightning Damage +10','{"lightning_damage":10}',0,1),
    ('passive_r12_c_bleed_damage',12,'c','Bleed Damage +10','Bleed Damage +10','{"bleed_damage":10}',0,1),
    ('passive_r12_d_elemental_resistance',12,'d','Elemental Resistance +6%','Fire, Cold and Lightning Resistance +6%','{"fire_resistance":6,"cold_resistance":6,"lightning_resistance":6}',0,1),
    ('passive_r13_a_strength',13,'a','Strength +7','Strength +7','{"strength":7}',0,1),
    ('passive_r13_b_energy',13,'b','Energy +7','Energy +7','{"energy":7}',0,1),
    ('passive_r13_c_dexterity',13,'c','Dexterity +7','Dexterity +7','{"dexterity":7}',0,1),
    ('passive_r13_d_vitality',13,'d','Vitality +7','Vitality +7','{"vitality":7}',0,1),
    ('passive_r14_a_maximum_life',14,'a','Maximum Life +75','Maximum Life +75','{"maximum_life":75}',0,1),
    ('passive_r14_b_maximum_mana',14,'b','Maximum Mana +50','Maximum Mana +50','{"maximum_mana":50}',0,1),
    ('passive_r14_c_accuracy',14,'c','Accuracy +10','Accuracy +10','{"accuracy":10}',0,1),
    ('passive_r14_d_healing_power',14,'d','Healing Power +15%','Healing Power +15%','{"healing_power_percent":15}',0,1),
    ('passive_r15_a_physical_damage',15,'a','Physical Damage +20','Physical Damage +20','{"physical_damage":20}',0,1),
    ('passive_r15_b_fire_damage',15,'b','Fire Damage +20','Fire Damage +20','{"fire_damage":20}',0,1),
    ('passive_r15_c_critical_chance',15,'c','Critical Chance +8%','Critical Chance +8%','{"critical_chance":8}',0,1),
    ('passive_r15_d_life_on_hit',15,'d','Life on Hit +8','Life on Hit +8','{"life_on_hit":8}',0,1),
    ('passive_r16_a_toughness',16,'a','Toughness +8','Toughness +8','{"toughness":8}',0,1),
    ('passive_r16_b_cold_damage',16,'b','Cold Damage +20','Cold Damage +20','{"cold_damage":20}',0,1),
    ('passive_r16_c_critical_damage',16,'c','Critical Damage +20%','Critical Damage +20%','{"critical_damage":20}',0,1),
    ('passive_r16_d_maximum_life',16,'d','Maximum Life +100','Maximum Life +100','{"maximum_life":100}',0,1),
    ('passive_r17_a_block_rate',17,'a','Block Rate +20%','Block Rate +20%','{"block_rate_bp":2000}',0,1),
    ('passive_r17_b_lightning_damage',17,'b','Lightning Damage +20','Lightning Damage +20','{"lightning_damage":20}',0,1),
    ('passive_r17_c_poison_damage',17,'c','Poison Damage +20','Poison Damage +20','{"poison_damage":20}',0,1),
    ('passive_r17_d_healing_power',17,'d','Healing Power +20%','Healing Power +20%','{"healing_power_percent":20}',0,1),
    ('passive_r18_a_life_on_hit',18,'a','Life on Hit +10','Life on Hit +10','{"life_on_hit":10}',0,1),
    ('passive_r18_b_action_rate',18,'b','Cast / Action Rate +10%','Skill action execution rate +10%; no cooldown reduction or extra turn actions','{"cast_rate_bp":1000}',0,1),
    ('passive_r18_c_bleed_damage',18,'c','Bleed Damage +20','Bleed Damage +20','{"bleed_damage":20}',0,1),
    ('passive_r18_d_elemental_resistance',18,'d','Elemental Resistance +10%','Fire, Cold and Lightning Resistance +10%','{"fire_resistance":10,"cold_resistance":10,"lightning_resistance":10}',0,1),
    ('passive_r19_a_physical_damage',19,'a','Physical Damage +20','Physical Damage +20','{"physical_damage":20}',0,1),
    ('passive_r19_b_elemental_status_chance',19,'b','Elemental Status Chance +10%','Burn, Freeze and Shock chance +10 percentage points; requires an existing status source','{"elemental_status_chance_percent":10}',0,1),
    ('passive_r19_c_critical_damage',19,'c','Critical Damage +25%','Critical Damage +25%','{"critical_damage":25}',0,1),
    ('passive_r19_d_maximum_life',19,'d','Maximum Life +125','Maximum Life +125','{"maximum_life":125}',0,1),
    ('passive_r20_a_brutality',20,'a','Brutality','Physical Damage +30','{"physical_damage":30}',1,1),
    ('passive_r20_b_elemental_mastery',20,'b','Elemental Mastery','Fire Damage +20; Cold Damage +20; Lightning Damage +20','{"fire_damage":20,"cold_damage":20,"lightning_damage":20}',1,1),
    ('passive_r20_c_assassin',20,'c','Assassin','Critical Damage +40%','{"critical_damage":40}',1,1),
    ('passive_r20_d_divine_vitality',20,'d','Divine Vitality','Maximum Life +200; Healing Power +25%','{"maximum_life":200,"healing_power_percent":25}',1,1);

    INSERT INTO schema_migrations (migration_id) VALUES ('014_universal_passive_tree');
    DO RELEASE_LOCK('ascii_quest.migration014');
END$$
DELIMITER ;
CALL run_014_universal_passive_tree();
DROP PROCEDURE run_014_universal_passive_tree;

/* Task32. Migration011 NOT APPLIED during development.
   Run only under separately authorized, quiesced maintenance with a schema backup.
   MariaDB ALTER TABLE implicitly commits: DDL and the migration marker CANNOT be
   one rollback-able transaction. One ALTER replaces the CHECK and adds retired_at.
   A crash between ALTER and marker is a partial install: rerun FAILS CLOSED;
   inspect schema/backup manually, never auto-repair or rewrite historical rows.
   Existing rows receive NULL via the nullable column; NO backfill UPDATE. */
USE ascii_quest;
DROP PROCEDURE IF EXISTS run_011_test_item_provenance;
DELIMITER $$
CREATE PROCEDURE run_011_test_item_provenance()
BEGIN
    DECLARE v_count INT DEFAULT 0;
    DECLARE v_lock INT DEFAULT 0;
    DECLARE v_clause LONGTEXT;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF v_lock = 1 THEN
            DO RELEASE_LOCK('ascii_quest.migration011');
        END IF;
        RESIGNAL;
    END;

    SELECT GET_LOCK('ascii_quest.migration011', 0) INTO v_lock;
    IF v_lock IS NULL OR v_lock <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 011 lock unavailable';
    END IF;
    IF @@session.check_constraint_checks <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 011 requires CHECK enforcement';
    END IF;
    SELECT COUNT(*) INTO v_count FROM schema_migrations
     WHERE migration_id = '010_combat_item_affix_completion';
    IF v_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 011 requires migration 010';
    END IF;
    SELECT COUNT(*) INTO v_count FROM schema_migrations
     WHERE migration_id = '011_test_item_provenance';
    IF v_count <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 011 already applied';
    END IF;
    SELECT COUNT(*) INTO v_count FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'character_items'
       AND COLUMN_NAME = 'retired_at';
    IF v_count <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 011 partial retirement column install';
    END IF;
    SELECT COUNT(*) INTO v_count FROM information_schema.CHECK_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'character_items'
       AND LOWER(CHECK_CLAUSE) LIKE '%source_type%';
    IF v_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 011 missing or unexpected source checks';
    END IF;
    SELECT COUNT(*), MAX(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(CHECK_CLAUSE, '`', ''), ' ', ''), CHAR(9), ''), CHAR(10), ''), CHAR(13), ''), 'IN', 'in')) INTO v_count, v_clause
      FROM information_schema.CHECK_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'character_items'
       AND CONSTRAINT_NAME = 'chk_character_items_source_type';
    IF v_count <> 1 OR v_clause IS NULL OR BINARY v_clause NOT IN
       ('source_typein(''starter'',''combat_drop'')', '(source_typein(''starter'',''combat_drop''))') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 011 partial install or unexpected CHECK shape';
    END IF;
    SELECT COUNT(*) INTO v_count FROM character_items
     WHERE source_type NOT IN ('starter', 'combat_drop');
    IF v_count <> 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 011 unexpected historical provenance';
    END IF;

    SELECT COUNT(*) INTO v_count FROM information_schema.REFERENTIAL_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND UNIQUE_CONSTRAINT_SCHEMA = DATABASE()
       AND REFERENCED_TABLE_NAME = 'character_items'
       AND ((TABLE_NAME = 'combat_actions' AND CONSTRAINT_NAME = 'fk_combat_actions_snapshot_weapon_item' AND DELETE_RULE = 'RESTRICT'
             AND (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE k WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.TABLE_NAME = 'combat_actions' AND k.CONSTRAINT_NAME = 'fk_combat_actions_snapshot_weapon_item' AND k.REFERENCED_TABLE_NAME = 'character_items' AND k.REFERENCED_TABLE_SCHEMA = DATABASE() AND ((k.ORDINAL_POSITION = 1 AND k.COLUMN_NAME = 'snapshot_weapon_item_id' AND k.REFERENCED_COLUMN_NAME = 'id'))) = 1) OR
            (TABLE_NAME = 'item_mutation_requests' AND CONSTRAINT_NAME = 'fk_item_mutation_requests_item' AND DELETE_RULE = 'RESTRICT'
             AND (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE k WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.TABLE_NAME = 'item_mutation_requests' AND k.CONSTRAINT_NAME = 'fk_item_mutation_requests_item' AND k.REFERENCED_TABLE_NAME = 'character_items' AND k.REFERENCED_TABLE_SCHEMA = DATABASE() AND ((k.ORDINAL_POSITION = 1 AND k.COLUMN_NAME = 'item_id' AND k.REFERENCED_COLUMN_NAME = 'id'))) = 1) OR
            (TABLE_NAME = 'combat_item_drops' AND CONSTRAINT_NAME = 'fk_combat_item_drops_item' AND DELETE_RULE = 'RESTRICT'
             AND (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE k WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.TABLE_NAME = 'combat_item_drops' AND k.CONSTRAINT_NAME = 'fk_combat_item_drops_item' AND k.REFERENCED_TABLE_NAME = 'character_items' AND k.REFERENCED_TABLE_SCHEMA = DATABASE() AND ((k.ORDINAL_POSITION = 1 AND k.COLUMN_NAME = 'item_id' AND k.REFERENCED_COLUMN_NAME = 'id'))) = 1) OR
            (TABLE_NAME = 'character_equipment' AND CONSTRAINT_NAME = 'fk_character_equipment_owned_item' AND DELETE_RULE = 'RESTRICT'
             AND (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE k WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.TABLE_NAME = 'character_equipment' AND k.CONSTRAINT_NAME = 'fk_character_equipment_owned_item' AND k.REFERENCED_TABLE_NAME = 'character_items' AND k.REFERENCED_TABLE_SCHEMA = DATABASE() AND ((k.ORDINAL_POSITION = 1 AND k.COLUMN_NAME = 'item_id' AND k.REFERENCED_COLUMN_NAME = 'id') OR (k.ORDINAL_POSITION = 2 AND k.COLUMN_NAME = 'character_id' AND k.REFERENCED_COLUMN_NAME = 'character_id'))) = 2) OR
            (TABLE_NAME = 'character_item_affixes' AND CONSTRAINT_NAME = 'fk_character_item_affixes_item' AND DELETE_RULE = 'CASCADE'
             AND (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE k WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.TABLE_NAME = 'character_item_affixes' AND k.CONSTRAINT_NAME = 'fk_character_item_affixes_item' AND k.REFERENCED_TABLE_NAME = 'character_items' AND k.REFERENCED_TABLE_SCHEMA = DATABASE() AND ((k.ORDINAL_POSITION = 1 AND k.COLUMN_NAME = 'item_id' AND k.REFERENCED_COLUMN_NAME = 'id'))) = 1));
    IF v_count <> 5 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 011 unexpected item history/equipment FKs';
    END IF;

    ALTER TABLE character_items
        DROP CONSTRAINT chk_character_items_source_type,
        ADD CONSTRAINT chk_character_items_source_type
            CHECK (source_type IN ('starter', 'combat_drop', 'maintenance_test')),
        ADD COLUMN retired_at DATETIME(6) NULL;

    -- Post-DDL check before the separate durable marker. No claim of DDL rollback.
    SELECT MAX(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(CHECK_CLAUSE, '`', ''), ' ', ''), CHAR(9), ''), CHAR(10), ''), CHAR(13), ''), 'IN', 'in')) INTO v_clause
      FROM information_schema.CHECK_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'character_items'
       AND CONSTRAINT_NAME = 'chk_character_items_source_type';
    IF v_clause IS NULL OR BINARY v_clause NOT IN
       ('source_typein(''starter'',''combat_drop'',''maintenance_test'')', '(source_typein(''starter'',''combat_drop'',''maintenance_test''))') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 011 post-DDL CHECK mismatch; manual recovery required';
    END IF;
    SELECT COUNT(*) INTO v_count FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'character_items'
       AND COLUMN_NAME = 'retired_at' AND COLUMN_TYPE = 'datetime(6)'
       AND DATETIME_PRECISION = 6 AND IS_NULLABLE = 'YES' AND EXTRA = ''
       AND (COLUMN_DEFAULT IS NULL OR UPPER(COLUMN_DEFAULT) = 'NULL');
    IF v_count <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration 011 post-DDL retirement mismatch; manual recovery required';
    END IF;
    INSERT INTO schema_migrations (migration_id) VALUES ('011_test_item_provenance');
    DO RELEASE_LOCK('ascii_quest.migration011');
END$$
DELIMITER ;
CALL run_011_test_item_provenance();
DROP PROCEDURE run_011_test_item_provenance;

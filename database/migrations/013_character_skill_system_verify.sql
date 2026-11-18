/* MariaDB may omit redundant AND-group parentheses in CHECK_CLAUSE. Exact
   equivalent flattened spellings below retain AND-before-OR precedence; do not
   remove arbitrary parentheses or accept weaker predicates.
   READ ONLY. Zero violation rows = verified; Migration013 NOT APPLIED in development.
   Historical immutability requires before/after snapshots; this verifier cannot
   infer that no backfill occurred from today's data. Run prerequisite012 verifier
   too: accounting below uses its persisted authoritative level cache. */
USE ascii_quest;
SELECT 'missing prerequisite012' AS violation WHERE (SELECT COUNT(*) FROM schema_migrations WHERE migration_id='012_character_level_progression') <> 1
UNION ALL
SELECT 'missing or unexpected013 marker' AS violation WHERE (SELECT COUNT(*) FROM schema_migrations WHERE LEFT(migration_id,4)='013_') <> 1 OR (SELECT COUNT(*) FROM schema_migrations WHERE migration_id='013_character_skill_system') <> 1
UNION ALL
SELECT 'skill table shape' AS violation WHERE (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('skill_definitions','skill_prerequisites','character_skills','character_skill_loadout','skill_mutation_requests') AND ENGINE='InnoDB') <> 5
UNION ALL
SELECT 'snapshot column shape' AS violation WHERE (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='combat_actions' AND COLUMN_NAME='snapshot_skill_definition' AND DATA_TYPE='longtext' AND IS_NULLABLE='YES' AND EXTRA='' AND (COLUMN_DEFAULT IS NULL OR UPPER(COLUMN_DEFAULT)='NULL')) <> 1
UNION ALL
SELECT 'FK fk_skill_class' AS violation WHERE (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='skill_definitions' AND CONSTRAINT_NAME='fk_skill_class' AND REFERENCED_TABLE_NAME='character_classes' AND DELETE_RULE='RESTRICT' AND UNIQUE_CONSTRAINT_SCHEMA=DATABASE()) <> 1 OR (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='skill_definitions' AND CONSTRAINT_NAME='fk_skill_class' AND REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='character_classes' AND ((ORDINAL_POSITION=1 AND COLUMN_NAME='class_id' AND REFERENCED_COLUMN_NAME='id'))) <> 1
UNION ALL
SELECT 'FK fk_skill_prereq_child' AS violation WHERE (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='skill_prerequisites' AND CONSTRAINT_NAME='fk_skill_prereq_child' AND REFERENCED_TABLE_NAME='skill_definitions' AND DELETE_RULE='RESTRICT' AND UNIQUE_CONSTRAINT_SCHEMA=DATABASE()) <> 1 OR (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='skill_prerequisites' AND CONSTRAINT_NAME='fk_skill_prereq_child' AND REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='skill_definitions' AND ((ORDINAL_POSITION=1 AND COLUMN_NAME='skill_key' AND REFERENCED_COLUMN_NAME='skill_key') OR (ORDINAL_POSITION=2 AND COLUMN_NAME='class_id' AND REFERENCED_COLUMN_NAME='class_id'))) <> 2
UNION ALL
SELECT 'FK fk_skill_prereq_parent' AS violation WHERE (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='skill_prerequisites' AND CONSTRAINT_NAME='fk_skill_prereq_parent' AND REFERENCED_TABLE_NAME='skill_definitions' AND DELETE_RULE='RESTRICT' AND UNIQUE_CONSTRAINT_SCHEMA=DATABASE()) <> 1 OR (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='skill_prerequisites' AND CONSTRAINT_NAME='fk_skill_prereq_parent' AND REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='skill_definitions' AND ((ORDINAL_POSITION=1 AND COLUMN_NAME='prerequisite_key' AND REFERENCED_COLUMN_NAME='skill_key') OR (ORDINAL_POSITION=2 AND COLUMN_NAME='class_id' AND REFERENCED_COLUMN_NAME='class_id'))) <> 2
UNION ALL
SELECT 'FK fk_character_skills_owner' AS violation WHERE (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='character_skills' AND CONSTRAINT_NAME='fk_character_skills_owner' AND REFERENCED_TABLE_NAME='characters' AND DELETE_RULE='CASCADE' AND UNIQUE_CONSTRAINT_SCHEMA=DATABASE()) <> 1 OR (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='character_skills' AND CONSTRAINT_NAME='fk_character_skills_owner' AND REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='characters' AND ((ORDINAL_POSITION=1 AND COLUMN_NAME='character_id' AND REFERENCED_COLUMN_NAME='id'))) <> 1
UNION ALL
SELECT 'FK fk_character_skills_definition' AS violation WHERE (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='character_skills' AND CONSTRAINT_NAME='fk_character_skills_definition' AND REFERENCED_TABLE_NAME='skill_definitions' AND DELETE_RULE='RESTRICT' AND UNIQUE_CONSTRAINT_SCHEMA=DATABASE()) <> 1 OR (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='character_skills' AND CONSTRAINT_NAME='fk_character_skills_definition' AND REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='skill_definitions' AND ((ORDINAL_POSITION=1 AND COLUMN_NAME='skill_key' AND REFERENCED_COLUMN_NAME='skill_key'))) <> 1
UNION ALL
SELECT 'FK fk_skill_loadout_unlocked' AS violation WHERE (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='character_skill_loadout' AND CONSTRAINT_NAME='fk_skill_loadout_unlocked' AND REFERENCED_TABLE_NAME='character_skills' AND DELETE_RULE='CASCADE' AND UNIQUE_CONSTRAINT_SCHEMA=DATABASE()) <> 1 OR (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='character_skill_loadout' AND CONSTRAINT_NAME='fk_skill_loadout_unlocked' AND REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='character_skills' AND ((ORDINAL_POSITION=1 AND COLUMN_NAME='character_id' AND REFERENCED_COLUMN_NAME='character_id') OR (ORDINAL_POSITION=2 AND COLUMN_NAME='skill_key' AND REFERENCED_COLUMN_NAME='skill_key'))) <> 2
UNION ALL
SELECT 'FK fk_skill_loadout_type' AS violation WHERE (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='character_skill_loadout' AND CONSTRAINT_NAME='fk_skill_loadout_type' AND REFERENCED_TABLE_NAME='skill_definitions' AND DELETE_RULE='RESTRICT' AND UNIQUE_CONSTRAINT_SCHEMA=DATABASE()) <> 1 OR (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='character_skill_loadout' AND CONSTRAINT_NAME='fk_skill_loadout_type' AND REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='skill_definitions' AND ((ORDINAL_POSITION=1 AND COLUMN_NAME='skill_key' AND REFERENCED_COLUMN_NAME='skill_key') OR (ORDINAL_POSITION=2 AND COLUMN_NAME='skill_type' AND REFERENCED_COLUMN_NAME='skill_type'))) <> 2
UNION ALL
SELECT 'FK fk_skill_request_owner' AS violation WHERE (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='skill_mutation_requests' AND CONSTRAINT_NAME='fk_skill_request_owner' AND REFERENCED_TABLE_NAME='characters' AND DELETE_RULE='CASCADE' AND UNIQUE_CONSTRAINT_SCHEMA=DATABASE()) <> 1 OR (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='skill_mutation_requests' AND CONSTRAINT_NAME='fk_skill_request_owner' AND REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='characters' AND ((ORDINAL_POSITION=1 AND COLUMN_NAME='character_id' AND REFERENCED_COLUMN_NAME='id'))) <> 1
UNION ALL
SELECT 'unique skill_definitions.PRIMARY' AS violation WHERE (SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='skill_definitions' AND INDEX_NAME='PRIMARY' AND NON_UNIQUE=0) <> 'skill_key' OR (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='skill_definitions' AND INDEX_NAME='PRIMARY' AND NON_UNIQUE=0) <> 1
UNION ALL
SELECT 'unique skill_definitions.uq_skill_class_position' AS violation WHERE (SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='skill_definitions' AND INDEX_NAME='uq_skill_class_position' AND NON_UNIQUE=0) <> 'class_id,tree_position' OR (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='skill_definitions' AND INDEX_NAME='uq_skill_class_position' AND NON_UNIQUE=0) <> 2
UNION ALL
SELECT 'unique skill_definitions.uq_skill_class' AS violation WHERE (SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='skill_definitions' AND INDEX_NAME='uq_skill_class' AND NON_UNIQUE=0) <> 'skill_key,class_id' OR (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='skill_definitions' AND INDEX_NAME='uq_skill_class' AND NON_UNIQUE=0) <> 2
UNION ALL
SELECT 'unique skill_definitions.uq_skill_type' AS violation WHERE (SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='skill_definitions' AND INDEX_NAME='uq_skill_type' AND NON_UNIQUE=0) <> 'skill_key,skill_type' OR (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='skill_definitions' AND INDEX_NAME='uq_skill_type' AND NON_UNIQUE=0) <> 2
UNION ALL
SELECT 'unique skill_prerequisites.PRIMARY' AS violation WHERE (SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='skill_prerequisites' AND INDEX_NAME='PRIMARY' AND NON_UNIQUE=0) <> 'skill_key,prerequisite_key' OR (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='skill_prerequisites' AND INDEX_NAME='PRIMARY' AND NON_UNIQUE=0) <> 2
UNION ALL
SELECT 'unique character_skills.PRIMARY' AS violation WHERE (SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='character_skills' AND INDEX_NAME='PRIMARY' AND NON_UNIQUE=0) <> 'character_id,skill_key' OR (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='character_skills' AND INDEX_NAME='PRIMARY' AND NON_UNIQUE=0) <> 2
UNION ALL
SELECT 'unique character_skill_loadout.PRIMARY' AS violation WHERE (SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='character_skill_loadout' AND INDEX_NAME='PRIMARY' AND NON_UNIQUE=0) <> 'character_id,slot' OR (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='character_skill_loadout' AND INDEX_NAME='PRIMARY' AND NON_UNIQUE=0) <> 2
UNION ALL
SELECT 'unique character_skill_loadout.uq_character_skill_assignment' AS violation WHERE (SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='character_skill_loadout' AND INDEX_NAME='uq_character_skill_assignment' AND NON_UNIQUE=0) <> 'character_id,skill_key' OR (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='character_skill_loadout' AND INDEX_NAME='uq_character_skill_assignment' AND NON_UNIQUE=0) <> 2
UNION ALL
SELECT 'unique skill_mutation_requests.PRIMARY' AS violation WHERE (SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='skill_mutation_requests' AND INDEX_NAME='PRIMARY' AND NON_UNIQUE=0) <> 'character_id,request_token' OR (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='skill_mutation_requests' AND INDEX_NAME='PRIMARY' AND NON_UNIQUE=0) <> 2
UNION ALL
SELECT 'CHECK chk_skill_position' AS violation WHERE (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='skill_definitions' AND CONSTRAINT_NAME='chk_skill_position' AND LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(CHECK_CLAUSE,'`',''),' ',''),CHAR(9),''),CHAR(10),''),CHAR(13),'')) IN ('tree_positionin(''root'',''branch_1'',''branch_2'',''ultimate'')','(tree_positionin(''root'',''branch_1'',''branch_2'',''ultimate''))')) <> 1
UNION ALL
SELECT 'CHECK chk_skill_type' AS violation WHERE (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='skill_definitions' AND CONSTRAINT_NAME='chk_skill_type' AND LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(CHECK_CLAUSE,'`',''),' ',''),CHAR(9),''),CHAR(10),''),CHAR(13),'')) IN ('(tree_position=''ultimate''andskill_type=''ultimate'')or(tree_position<>''ultimate''andskill_type=''normal'')','((tree_position=''ultimate''andskill_type=''ultimate'')or(tree_position<>''ultimate''andskill_type=''normal''))','tree_position=''ultimate''andskill_type=''ultimate''ortree_position<>''ultimate''andskill_type=''normal''')) <> 1
UNION ALL
SELECT 'CHECK chk_skill_level' AS violation WHERE (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='skill_definitions' AND CONSTRAINT_NAME='chk_skill_level' AND LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(CHECK_CLAUSE,'`',''),' ',''),CHAR(9),''),CHAR(10),''),CHAR(13),'')) IN ('minimum_levelbetween2and50','(minimum_levelbetween2and50)')) <> 1
UNION ALL
SELECT 'CHECK chk_skill_damage' AS violation WHERE (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='skill_definitions' AND CONSTRAINT_NAME='chk_skill_damage' AND LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(CHECK_CLAUSE,'`',''),' ',''),CHAR(9),''),CHAR(10),''),CHAR(13),'')) IN ('damage_typein(''physical'',''fire'',''cold'',''lightning'',''poison'')andbase_damagebetween1and10000','(damage_typein(''physical'',''fire'',''cold'',''lightning'',''poison'')andbase_damagebetween1and10000)')) <> 1
UNION ALL
SELECT 'CHECK chk_skill_timing' AS violation WHERE (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='skill_definitions' AND CONSTRAINT_NAME='chk_skill_timing' AND LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(CHECK_CLAUSE,'`',''),' ',''),CHAR(9),''),CHAR(10),''),CHAR(13),'')) IN ('duration_msbetween100and10000andcooldown_msbetween100and600000','(duration_msbetween100and10000andcooldown_msbetween100and600000)')) <> 1
UNION ALL
SELECT 'CHECK chk_skill_status' AS violation WHERE (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='skill_definitions' AND CONSTRAINT_NAME='chk_skill_status' AND LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(CHECK_CLAUSE,'`',''),' ',''),CHAR(9),''),CHAR(10),''),CHAR(13),'')) IN ('(status_keyisnullandstatus_power=0)or(status_keyisnotnullandstatus_keyin(''poison'',''bleed'',''burn'',''freeze'',''shock'')andstatus_powerbetween1and100)','((status_keyisnullandstatus_power=0)or(status_keyisnotnullandstatus_keyin(''poison'',''bleed'',''burn'',''freeze'',''shock'')andstatus_powerbetween1and100))','status_keyisnullandstatus_power=0orstatus_keyisnotnullandstatus_keyin(''poison'',''bleed'',''burn'',''freeze'',''shock'')andstatus_powerbetween1and100')) <> 1
UNION ALL
SELECT 'CHECK chk_skill_active' AS violation WHERE (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='skill_definitions' AND CONSTRAINT_NAME='chk_skill_active' AND LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(CHECK_CLAUSE,'`',''),' ',''),CHAR(9),''),CHAR(10),''),CHAR(13),'')) IN ('is_activein(0,1)','(is_activein(0,1))')) <> 1
UNION ALL
SELECT 'CHECK chk_skill_prereq_self' AS violation WHERE (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='skill_prerequisites' AND CONSTRAINT_NAME='chk_skill_prereq_self' AND LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(CHECK_CLAUSE,'`',''),' ',''),CHAR(9),''),CHAR(10),''),CHAR(13),'')) IN ('skill_key<>prerequisite_key','(skill_key<>prerequisite_key)')) <> 1
UNION ALL
SELECT 'CHECK chk_skill_loadout_slot' AS violation WHERE (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='character_skill_loadout' AND CONSTRAINT_NAME='chk_skill_loadout_slot' AND LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(CHECK_CLAUSE,'`',''),' ',''),CHAR(9),''),CHAR(10),''),CHAR(13),'')) IN ('(slotin(''skill_1'',''skill_2'',''skill_3'')andskill_type=''normal'')or(slot=''ultimate''andskill_type=''ultimate'')','((slotin(''skill_1'',''skill_2'',''skill_3'')andskill_type=''normal'')or(slot=''ultimate''andskill_type=''ultimate''))','slotin(''skill_1'',''skill_2'',''skill_3'')andskill_type=''normal''orslot=''ultimate''andskill_type=''ultimate''')) <> 1
UNION ALL
SELECT 'CHECK chk_skill_request_command' AS violation WHERE (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='skill_mutation_requests' AND CONSTRAINT_NAME='chk_skill_request_command' AND LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(CHECK_CLAUSE,'`',''),' ',''),CHAR(9),''),CHAR(10),''),CHAR(13),'')) IN ('command_typein(''unlock'',''assign'',''unassign'')','(command_typein(''unlock'',''assign'',''unassign''))')) <> 1
UNION ALL
SELECT 'CHECK chk_skill_request_payload' AS violation WHERE (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='skill_mutation_requests' AND CONSTRAINT_NAME='chk_skill_request_payload' AND LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(CHECK_CLAUSE,'`',''),' ',''),CHAR(9),''),CHAR(10),''),CHAR(13),'')) IN ('json_valid(result_payload)','(json_valid(result_payload))')) <> 1
UNION ALL
SELECT 'CHECK combat snapshot' AS violation WHERE (SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='combat_actions' AND CONSTRAINT_NAME='chk_combat_skill_snapshot' AND LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(CHECK_CLAUSE,'`',''),' ',''),CHAR(9),''),CHAR(10),''),CHAR(13),'')) IN ('snapshot_skill_definitionisnullorjson_valid(snapshot_skill_definition)','(snapshot_skill_definitionisnullorjson_valid(snapshot_skill_definition))')) <> 1;

SELECT 'catalogue count' AS violation WHERE (SELECT COUNT(*) FROM skill_definitions) <> 16;
SELECT cc.class_key, 'class tree shape' AS violation FROM character_classes cc LEFT JOIN skill_definitions sd ON sd.class_id=cc.id
 WHERE cc.class_key IN ('warrior','mage','rogue','cleric') GROUP BY cc.class_key
 HAVING COUNT(sd.skill_key) <> 4 OR SUM(sd.skill_type='normal') <> 3 OR SUM(sd.skill_type='ultimate') <> 1 OR COUNT(DISTINCT sd.tree_position) <> 4;
SELECT sd.skill_key, 'invalid canonical class' AS violation FROM skill_definitions sd JOIN character_classes cc ON cc.id=sd.class_id
 WHERE cc.class_key NOT IN ('warrior','mage','rogue','cleric') OR LEFT(sd.skill_key,LENGTH(cc.class_key)+1) <> CONCAT(cc.class_key,'_');
SELECT 'prerequisite count' AS violation WHERE (SELECT COUNT(*) FROM skill_prerequisites) <> 12;
SELECT sd.skill_key, 'prerequisite graph shape' AS violation FROM skill_definitions sd LEFT JOIN skill_prerequisites sp ON sp.skill_key=sd.skill_key
 GROUP BY sd.skill_key,sd.tree_position HAVING COUNT(sp.prerequisite_key) <> IF(sd.tree_position='root',0,1);
SELECT sp.skill_key, 'prerequisite graph edge' AS violation FROM skill_prerequisites sp JOIN skill_definitions child ON child.skill_key=sp.skill_key JOIN skill_definitions parent ON parent.skill_key=sp.prerequisite_key
 WHERE child.class_id<>parent.class_id OR sp.class_id<>child.class_id OR NOT ((child.tree_position IN ('branch_1','branch_2') AND parent.tree_position='root') OR (child.tree_position='ultimate' AND parent.tree_position='branch_1'));
SELECT c.id, 'skill accounting or class mismatch' AS violation FROM characters c JOIN character_skills cs ON cs.character_id=c.id JOIN skill_definitions sd ON sd.skill_key=cs.skill_key
 GROUP BY c.id,c.level,c.class_id HAVING COUNT(*) > GREATEST(0,c.level-1) OR SUM(sd.class_id<>c.class_id)>0;
SELECT l.character_id,l.slot,'invalid assignment' AS violation FROM character_skill_loadout l JOIN characters c ON c.id=l.character_id JOIN skill_definitions sd ON sd.skill_key=l.skill_key LEFT JOIN character_skills cs ON cs.character_id=l.character_id AND cs.skill_key=l.skill_key
 WHERE cs.skill_key IS NULL OR c.class_id<>sd.class_id OR l.skill_type<>sd.skill_type OR NOT ((l.slot='ultimate' AND sd.skill_type='ultimate') OR (l.slot IN ('skill_1','skill_2','skill_3') AND sd.skill_type='normal'));
SELECT character_id,skill_key,'duplicate assignment' AS violation FROM character_skill_loadout GROUP BY character_id,skill_key HAVING COUNT(*)>1;
SELECT id,'invalid frozen skill JSON' AS violation FROM combat_actions WHERE snapshot_skill_definition IS NOT NULL AND (JSON_VALID(snapshot_skill_definition)<>1 OR actor<>'player' OR action_kind<>'skill');
SELECT cs.character_id,cs.skill_key,'invalid learned prerequisites or level' AS violation
 FROM character_skills cs JOIN characters c ON c.id=cs.character_id JOIN skill_definitions sd ON sd.skill_key=cs.skill_key
 WHERE c.level<sd.minimum_level OR EXISTS (SELECT 1 FROM skill_prerequisites p WHERE p.skill_key=cs.skill_key AND NOT EXISTS (SELECT 1 FROM character_skills learned WHERE learned.character_id=cs.character_id AND learned.skill_key=p.prerequisite_key));
-- Frozen metadata is validated against itself and stored numeric snapshots,
-- never against today's mutable definitions or loadout.
SELECT id,'invalid frozen skill semantics' AS violation FROM combat_actions
 WHERE snapshot_skill_definition IS NOT NULL AND CASE WHEN JSON_VALID(snapshot_skill_definition)=1 THEN
 JSON_CONTAINS_PATH(snapshot_skill_definition,'all','$.version','$.action.key','$.action.name','$.action.description','$.action.kind','$.action.skill_type','$.action.class_key','$.action.slot','$.action.damage_type','$.action.prototype_damage','$.action.duration_seconds','$.action.cooldown_seconds','$.action.intrinsic_status')<>1
 OR JSON_TYPE(JSON_EXTRACT(snapshot_skill_definition,'$.version'))<>'INTEGER'
 OR JSON_TYPE(JSON_EXTRACT(snapshot_skill_definition,'$.action'))<>'OBJECT'
 OR JSON_TYPE(JSON_EXTRACT(snapshot_skill_definition,'$.action.name'))<>'STRING'
 OR TRIM(JSON_UNQUOTE(JSON_EXTRACT(snapshot_skill_definition,'$.action.name')))=''
 OR JSON_TYPE(JSON_EXTRACT(snapshot_skill_definition,'$.action.description'))<>'STRING'
 OR JSON_EXTRACT(snapshot_skill_definition,'$.version')<>1
 OR JSON_UNQUOTE(JSON_EXTRACT(snapshot_skill_definition,'$.action.kind'))<>'skill'
 OR JSON_UNQUOTE(JSON_EXTRACT(snapshot_skill_definition,'$.action.key'))<>definition_key
 OR snapshot_damage_type IS NULL OR snapshot_base_damage IS NULL
 OR snapshot_base_damage NOT BETWEEN 1 AND 10000
 OR snapshot_damage_type NOT IN ('physical','fire','cold','lightning','poison')
 OR JSON_UNQUOTE(JSON_EXTRACT(snapshot_skill_definition,'$.action.damage_type'))<>snapshot_damage_type
 OR JSON_TYPE(JSON_EXTRACT(snapshot_skill_definition,'$.action.prototype_damage'))<>'INTEGER'
 OR CAST(JSON_UNQUOTE(JSON_EXTRACT(snapshot_skill_definition,'$.action.prototype_damage')) AS DECIMAL(20,0))<>snapshot_base_damage
 OR JSON_UNQUOTE(JSON_EXTRACT(snapshot_skill_definition,'$.action.class_key')) NOT IN ('warrior','mage','rogue','cleric')
 OR LEFT(definition_key,LENGTH(JSON_UNQUOTE(JSON_EXTRACT(snapshot_skill_definition,'$.action.class_key')))+1)<>CONCAT(JSON_UNQUOTE(JSON_EXTRACT(snapshot_skill_definition,'$.action.class_key')),'_')
 OR definition_key NOT REGEXP '^[a-z][a-z0-9_]{1,63}$'
 OR JSON_TYPE(JSON_EXTRACT(snapshot_skill_definition,'$.action.duration_seconds')) NOT IN ('INTEGER','DOUBLE')
 OR CAST(JSON_UNQUOTE(JSON_EXTRACT(snapshot_skill_definition,'$.action.duration_seconds')) AS DECIMAL(20,5)) NOT BETWEEN 0.1 AND 10
 OR JSON_TYPE(JSON_EXTRACT(snapshot_skill_definition,'$.action.cooldown_seconds')) NOT IN ('INTEGER','DOUBLE')
 OR CAST(JSON_UNQUOTE(JSON_EXTRACT(snapshot_skill_definition,'$.action.cooldown_seconds')) AS DECIMAL(20,5)) NOT BETWEEN 0.1 AND 600
 OR NOT ((JSON_UNQUOTE(JSON_EXTRACT(snapshot_skill_definition,'$.action.slot'))='ultimate' AND JSON_UNQUOTE(JSON_EXTRACT(snapshot_skill_definition,'$.action.skill_type'))='ultimate') OR (JSON_UNQUOTE(JSON_EXTRACT(snapshot_skill_definition,'$.action.slot')) IN ('skill_1','skill_2','skill_3') AND JSON_UNQUOTE(JSON_EXTRACT(snapshot_skill_definition,'$.action.skill_type'))='normal'))
 ELSE 1 END;
SELECT id,'invalid intrinsic frozen status' AS violation FROM combat_actions
 WHERE snapshot_skill_definition IS NOT NULL AND CASE WHEN JSON_VALID(snapshot_skill_definition)=1 THEN
 JSON_TYPE(JSON_EXTRACT(snapshot_skill_definition,'$.action.intrinsic_status'))<>'NULL' AND (
 JSON_CONTAINS_PATH(snapshot_skill_definition,'all','$.action.intrinsic_status.key','$.action.intrinsic_status.power','$.action.intrinsic_status.duration_ms','$.action.intrinsic_status.chance_percent')<>1
 OR JSON_TYPE(JSON_EXTRACT(snapshot_skill_definition,'$.action.intrinsic_status'))<>'OBJECT'
 OR JSON_TYPE(JSON_EXTRACT(snapshot_skill_definition,'$.action.intrinsic_status.power'))<>'INTEGER'
 OR JSON_TYPE(JSON_EXTRACT(snapshot_skill_definition,'$.action.intrinsic_status.duration_ms'))<>'INTEGER'
 OR JSON_TYPE(JSON_EXTRACT(snapshot_skill_definition,'$.action.intrinsic_status.chance_percent')) NOT IN ('INTEGER','DOUBLE')
 OR JSON_UNQUOTE(JSON_EXTRACT(snapshot_skill_definition,'$.action.intrinsic_status.key')) NOT IN ('poison','bleed','burn','freeze','shock')
 OR CAST(JSON_UNQUOTE(JSON_EXTRACT(snapshot_skill_definition,'$.action.intrinsic_status.power')) AS DECIMAL(20,0)) NOT BETWEEN 1 AND 100
 OR JSON_EXTRACT(snapshot_skill_definition,'$.action.intrinsic_status.duration_ms')<>4000
 OR CAST(JSON_UNQUOTE(JSON_EXTRACT(snapshot_skill_definition,'$.action.intrinsic_status.chance_percent')) AS DECIMAL(10,5)) NOT BETWEEN 0 AND 100)
 ELSE 1 END;
SELECT 'class FK column type mismatch' AS violation WHERE
 (SELECT COUNT(*) FROM information_schema.COLUMNS n JOIN information_schema.COLUMNS p
   ON p.TABLE_SCHEMA=n.TABLE_SCHEMA AND p.TABLE_NAME='character_classes' AND p.COLUMN_NAME='id'
  WHERE n.TABLE_SCHEMA=DATABASE() AND n.TABLE_NAME IN ('skill_definitions','skill_prerequisites') AND n.COLUMN_NAME='class_id'
    AND n.COLUMN_TYPE=p.COLUMN_TYPE AND n.IS_NULLABLE='NO')<>2;

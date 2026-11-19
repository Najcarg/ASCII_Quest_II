/* READ ONLY. Zero violation rows = verified. Migration014 NOT APPLIED during
   development. Run only after separately authorized application. Before/after
   snapshots are required to attest no XP/history/item/skill rewriting.
   CHECK normalization removes whitespace/backticks ONLY, accepts exact equivalent
   outer/group-parenthesized or MariaDB11.8.6 flattened AND-before-OR forms. */
USE ascii_quest;
SELECT 'missing prerequisite013' AS violation WHERE (SELECT COUNT(*) FROM schema_migrations WHERE migration_id='013_character_skill_system')<>1
UNION ALL SELECT 'missing or unexpected014 marker' WHERE (SELECT COUNT(*) FROM schema_migrations WHERE LEFT(migration_id,4)='014_')<>1 OR (SELECT COUNT(*) FROM schema_migrations WHERE migration_id='014_universal_passive_tree')<>1
UNION ALL SELECT 'constraint enforcement disabled' WHERE @@session.check_constraint_checks<>1 OR @@session.foreign_key_checks<>1
UNION ALL SELECT 'InnoDB passive tables' WHERE (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('passive_definitions','character_passives','passive_mutation_requests') AND ENGINE='InnoDB')<>3
UNION ALL SELECT 'catalogue count' WHERE (SELECT COUNT(*) FROM passive_definitions)<>80;

WITH expected AS (
 SELECT 'passive_definitions' AS tbl,'passive_key' AS col,'varchar(64)' AS typ,'NO' AS nullable,'ascii_bin' AS coll
 UNION ALL SELECT 'passive_definitions','row_number','tinyint unsigned','NO',NULL
 UNION ALL SELECT 'passive_definitions','lane_key','char(1)','NO','ascii_bin'
 UNION ALL SELECT 'passive_definitions','display_name','varchar(64)','NO',NULL
 UNION ALL SELECT 'passive_definitions','description','varchar(255)','NO',NULL
 UNION ALL SELECT 'passive_definitions','effect_json','longtext','NO',NULL
 UNION ALL SELECT 'passive_definitions','is_major','tinyint unsigned','NO',NULL
 UNION ALL SELECT 'passive_definitions','is_active','tinyint unsigned','NO',NULL
 UNION ALL SELECT 'character_passives','character_id','int unsigned','NO',NULL
 UNION ALL SELECT 'character_passives','passive_key','varchar(64)','NO','ascii_bin'
 UNION ALL SELECT 'character_passives','row_number','tinyint unsigned','NO',NULL
 UNION ALL SELECT 'character_passives','pass_number','tinyint unsigned','NO',NULL
 UNION ALL SELECT 'character_passives','unlocked_at','datetime(6)','NO',NULL
 UNION ALL SELECT 'passive_mutation_requests','character_id','int unsigned','NO',NULL
 UNION ALL SELECT 'passive_mutation_requests','request_token','char(36)','NO','ascii_bin'
 UNION ALL SELECT 'passive_mutation_requests','request_fingerprint','char(64)','NO','ascii_bin'
 UNION ALL SELECT 'passive_mutation_requests','result_payload','longtext','NO',NULL
 UNION ALL SELECT 'passive_mutation_requests','completed_at','datetime(6)','NO',NULL
 UNION ALL SELECT 'combat_actions','snapshot_elemental_status_chance','tinyint unsigned','YES',NULL
)
SELECT CONCAT(e.tbl,'.',e.col,' column shape') AS violation FROM expected e LEFT JOIN information_schema.COLUMNS c
 ON c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME=e.tbl AND c.COLUMN_NAME=e.col
 WHERE c.COLUMN_NAME IS NULL OR c.IS_NULLABLE<>e.nullable
 OR (CASE WHEN c.DATA_TYPE IN ('int','tinyint') THEN REGEXP_REPLACE(c.COLUMN_TYPE,'\\([0-9]+\\)','') ELSE c.COLUMN_TYPE END)<>e.typ
 OR (e.coll IS NOT NULL AND NOT(c.COLLATION_NAME<=>e.coll)) OR c.EXTRA LIKE '%GENERATED%';
SELECT TABLE_NAME,'unexpected columns' AS violation FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
 AND TABLE_NAME IN ('passive_definitions','character_passives','passive_mutation_requests') GROUP BY TABLE_NAME
 HAVING COUNT(*)<>CASE TABLE_NAME WHEN 'passive_definitions' THEN 8 WHEN 'character_passives' THEN 5 ELSE 5 END;

WITH expected AS (
 SELECT 'passive_definitions' AS tbl,'PRIMARY' AS idx,'passive_key' AS cols
 UNION ALL SELECT 'passive_definitions','uq_passive_row_lane','row_number,lane_key'
 UNION ALL SELECT 'passive_definitions','uq_passive_key_row','passive_key,row_number'
 UNION ALL SELECT 'character_passives','PRIMARY','character_id,passive_key'
 UNION ALL SELECT 'character_passives','uq_character_passive_row_pass','character_id,row_number,pass_number'
 UNION ALL SELECT 'passive_mutation_requests','PRIMARY','character_id,request_token'
)
SELECT CONCAT(e.tbl,'.',e.idx,' unique shape') AS violation FROM expected e LEFT JOIN information_schema.STATISTICS s
 ON s.TABLE_SCHEMA=DATABASE() AND s.TABLE_NAME=e.tbl AND s.INDEX_NAME=e.idx
 GROUP BY e.tbl,e.idx,e.cols HAVING NOT(GROUP_CONCAT(s.COLUMN_NAME ORDER BY s.SEQ_IN_INDEX)<=>e.cols)
 OR MIN(s.NON_UNIQUE)<>0 OR MAX(s.NON_UNIQUE)<>0 OR SUM(s.SUB_PART IS NOT NULL)>0;

WITH expected AS (
 SELECT 'character_passives' AS tbl,'fk_passive_owner' AS fk,'characters' AS parent,'CASCADE' AS del,'character_id:id' AS cols
 UNION ALL SELECT 'character_passives','fk_passive_definition','passive_definitions','RESTRICT','passive_key:passive_key,row_number:row_number'
 UNION ALL SELECT 'passive_mutation_requests','fk_passive_request_owner','characters','CASCADE','character_id:id'
)
SELECT CONCAT(e.fk,' FK shape') AS violation FROM expected e LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS r
 ON r.CONSTRAINT_SCHEMA=DATABASE() AND r.TABLE_NAME=e.tbl AND r.CONSTRAINT_NAME=e.fk
 LEFT JOIN information_schema.KEY_COLUMN_USAGE k ON k.CONSTRAINT_SCHEMA=DATABASE() AND k.TABLE_NAME=e.tbl AND k.CONSTRAINT_NAME=e.fk
 GROUP BY e.fk,e.parent,e.del,e.cols HAVING NOT(MAX(r.REFERENCED_TABLE_NAME)<=>e.parent) OR NOT(MAX(r.DELETE_RULE)<=>e.del)
 OR NOT(MAX(r.UPDATE_RULE)<=>'RESTRICT') OR NOT(MIN(k.REFERENCED_TABLE_SCHEMA)<=>DATABASE())
 OR NOT(GROUP_CONCAT(CONCAT(k.COLUMN_NAME,':',k.REFERENCED_COLUMN_NAME) ORDER BY k.ORDINAL_POSITION)<=>e.cols);

WITH expected AS (
 SELECT 'passive_definitions' AS tbl,'chk_passive_row' AS ck,'row_numberbetween1and20' AS expr
 UNION ALL SELECT 'passive_definitions','chk_passive_lane','lane_keyin(''a'',''b'',''c'',''d'')'
 UNION ALL SELECT 'passive_definitions','chk_passive_major','row_number=20andis_major=1orrow_number<20andis_major=0'
 UNION ALL SELECT 'passive_definitions','chk_passive_active','is_activein(0,1)'
 UNION ALL SELECT 'passive_definitions','chk_passive_effect','json_valid(effect_json)'
 UNION ALL SELECT 'character_passives','chk_passive_pass','pass_numberin(1,2)'
 UNION ALL SELECT 'passive_mutation_requests','chk_passive_request_payload','json_valid(result_payload)'
 UNION ALL SELECT 'combat_actions','chk_combat_elemental_status_chance','snapshot_elemental_status_chanceisnullorsnapshot_elemental_status_chancebetween0and100'
), actual AS (
 SELECT TABLE_NAME,CONSTRAINT_NAME,LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(CHECK_CLAUSE,'`',''),' ',''),CHAR(9),''),CHAR(10),''),CHAR(13),'')) AS expr
 FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE()
)
SELECT CONCAT(e.ck,' CHECK shape') AS violation FROM expected e LEFT JOIN actual a ON a.TABLE_NAME=e.tbl AND a.CONSTRAINT_NAME=e.ck
 WHERE a.expr IS NULL OR (a.expr<>e.expr AND a.expr<>CONCAT('(',e.expr,')')
 AND NOT(e.ck='chk_passive_major' AND a.expr IN ('(row_number=20andis_major=1)or(row_number<20andis_major=0)','((row_number=20andis_major=1)or(row_number<20andis_major=0))')));

/* Exact canonical catalogue fingerprints per row, under1024 bytes per row so
   even the minimum GROUP_CONCAT limit works. Values generated from seed literals,
   not a second gameplay catalogue. Byte differences deliberately fail closed. */
WITH expected AS (
 SELECT 1 AS `row_number`, '56afef9d74f2b7b325187d38e20961becafe1bea5e02e68f4d4806ede6f269de' AS digest
 UNION ALL SELECT 2,'b20ee48bd8388bc41cc117e3aa0157889b514747ba5b5483f652d7632bc8eeee'
 UNION ALL SELECT 3,'9f4a13bbf617da8b3a225be513ae8c6f264b80f0e4c6b374d729d3f9eff96ad1'
 UNION ALL SELECT 4,'b33917bf9d92a0efe5b402d4f954c6a5573fe14f710064f08bf70fc66e436dad'
 UNION ALL SELECT 5,'88bd882b6b80a9658bfd0be116a878cb0d1edb5a89e6962dd6bb06e206812cd7'
 UNION ALL SELECT 6,'ec61b66476c1bc0e59b5b7870afc684c2a0c61f824a67471558ebc8af6912a8a'
 UNION ALL SELECT 7,'3fa987aa6a05faa2c8d2d159ff73e657fa0895c1a65678ba60e6e504f63f11ae'
 UNION ALL SELECT 8,'b0643db7ea8531a68430b474b1007b0ed4544cc3c49de45238ddaf6037cb9134'
 UNION ALL SELECT 9,'ad5df4faa3143ffa241cdb771708946bb65c80d4a39395dcc3aee2a64c032fb3'
 UNION ALL SELECT 10,'e628cd92cb2a41fc438691784ff29e3a99b15166adff1ebaf8dc1caef3c1d5ca'
 UNION ALL SELECT 11,'50f2be5253b809ff4af223214218d9bc5b2379349a9e5240e4961ed4657fa9be'
 UNION ALL SELECT 12,'c17bb1f2c9a588b4f24c8d3ee8ae5a144cb38664b0138bd28ec74b789bcf794b'
 UNION ALL SELECT 13,'951b93ed66c6c9bad7ff51a9b7363d253a5f6a1df55b8d650114d45061863273'
 UNION ALL SELECT 14,'299d7a86135c9486161ade8d772a88de01aa9c719048875344cf8be5daaf909d'
 UNION ALL SELECT 15,'d466b79c00b338ba54666f42323b14977b5a3ea468096ef0c8f943f42aade55b'
 UNION ALL SELECT 16,'69c48a2818ce29ef6a2f14a0ac08be7197bb92576a8f17ebbbde738612374b47'
 UNION ALL SELECT 17,'9815077718499d9872e85bf361c0e38b7b52c063def82752e77ddb46b6f92a2e'
 UNION ALL SELECT 18,'fe276288125de2e3300901d785b7bc0e5cb48f88addebee473f964a15b00e6fa'
 UNION ALL SELECT 19,'cbc755569b5f4a0b64fdaf769277d9d29b3e57d620fc873c25d95a8d09825b35'
 UNION ALL SELECT 20,'c49979e50eaeea234444b72b3ec9ccfa97b903a8fa2dc62d40af3164f98b0452'
), actual AS (
 SELECT `row_number`,COUNT(*) AS nodes,COUNT(DISTINCT lane_key) AS lanes,
 SHA2(GROUP_CONCAT(CONCAT_WS('|',passive_key,`row_number`,lane_key,display_name,description,effect_json,is_major,is_active) ORDER BY lane_key SEPARATOR '\n'),256) AS digest
 FROM passive_definitions GROUP BY `row_number`
)
SELECT e.`row_number`,'catalogue drift' AS violation FROM expected e LEFT JOIN actual a ON a.`row_number`=e.`row_number`
 WHERE a.`row_number` IS NULL OR a.nodes<>4 OR a.lanes<>4 OR BINARY a.digest<>BINARY e.digest;

SELECT cp.character_id,'invalid allocation' AS violation FROM character_passives cp
 LEFT JOIN passive_definitions pd ON pd.passive_key=cp.passive_key AND pd.`row_number`=cp.`row_number`
 LEFT JOIN characters c ON c.id=cp.character_id
 WHERE pd.passive_key IS NULL OR c.id IS NULL OR cp.pass_number NOT IN (1,2);
SELECT character_id,`row_number`,'overallocated row' AS violation FROM character_passives GROUP BY character_id,`row_number` HAVING COUNT(*)>2;
SELECT character_id,'nonsequential history' AS violation FROM character_passives GROUP BY character_id
 HAVING MIN((pass_number-1)*20+`row_number`)<>1 OR MAX((pass_number-1)*20+`row_number`)<>COUNT(*) OR COUNT(DISTINCT (pass_number-1)*20+`row_number`)<>COUNT(*);
SELECT cp.character_id,'overspent points' AS violation FROM character_passives cp JOIN characters c ON c.id=cp.character_id
 GROUP BY cp.character_id,c.level,c.experience,c.life_state HAVING COUNT(*)>LEAST(40,GREATEST(0,(CASE WHEN c.life_state='dead' THEN c.level ELSE LEAST(100,FLOOR(SQRT(c.experience/100))+1) END)-50));
SELECT r.character_id,'invalid receipt' AS violation FROM passive_mutation_requests r LEFT JOIN characters c ON c.id=r.character_id
 WHERE c.id IS NULL OR r.request_token NOT REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
 OR r.request_fingerprint NOT REGEXP '^[0-9a-f]{64}$' OR JSON_VALID(r.result_payload)<>1;
SELECT id,'invalid frozen elemental chance' AS violation FROM combat_actions WHERE snapshot_elemental_status_chance NOT BETWEEN 0 AND 100;

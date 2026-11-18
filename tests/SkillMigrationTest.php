<?php
declare(strict_types=1);
return [
    'Task34B migration013 requires012 absent013 enforced constraints parent shapes and no partial install' => function (): void {
        $sql=file_get_contents(__DIR__.'/../database/migrations/013_character_skill_system.sql');
        foreach (["migration_id = '012_character_level_progression'","LEFT(migration_id,4) = '013_'",'v_count <> 0','snapshot_skill_definition','check_constraint_checks','foreign_key_checks','GET_LOCK','RELEASE_LOCK','RESIGNAL',"ENGINE = 'InnoDB'","COLUMN_NAME = 'id'","COLUMN_TYPE REGEXP",'canonical classes missing'] as $fragment) {
            assertSameValue(true,str_contains($sql,$fragment),'Preflight '.$fragment);
        }
        assertSameValue(5,substr_count($sql,'CREATE TABLE '),'Only five normalized relations.');
        assertSameValue(1,substr_count($sql,'ALTER TABLE '),'Only nullable snapshot DDL on history.');
        assertSameValue(16,substr_count($sql,'INSERT INTO skill_definitions'),'Sixteen authoritative seeds.');
        assertSameValue(12,substr_count($sql,'INSERT INTO skill_prerequisites'),'Twelve edges.');
        assertSameValue(1,substr_count($sql,'INSERT INTO schema_migrations'),'Marker once after seed.');
    },
    'Task34B migration deliberate FK unique slot type NULL semantics and audit retention' => function (): void {
        $sql=file_get_contents(__DIR__.'/../database/migrations/013_character_skill_system.sql');
        foreach (['PRIMARY KEY (character_id, skill_key)','PRIMARY KEY (character_id, slot)','UNIQUE KEY uq_character_skill_assignment (character_id, skill_key)',
            'REFERENCES character_skills(character_id, skill_key) ON DELETE CASCADE','REFERENCES skill_definitions(skill_key, skill_type) ON DELETE RESTRICT',
            'REFERENCES skill_definitions(skill_key, class_id) ON DELETE RESTRICT',"slot IN ('skill_1','skill_2','skill_3') AND skill_type = 'normal'","slot = 'ultimate' AND skill_type = 'ultimate'",
            'status_key IS NOT NULL','snapshot_skill_definition LONGTEXT NULL','snapshot_skill_definition IS NULL OR JSON_VALID(snapshot_skill_definition)',
            'PRIMARY KEY (character_id, request_token)','JSON_VALID(result_payload)'] as $part) {
            assertSameValue(true,str_contains($sql,$part),'Constraint '.$part);
        }
        assertSameValue(false,str_contains($sql,'ADD COLUMN skill_points'),'No mutable points balance.');
        assertSameValue(true,str_contains($sql,'v_class_id_type NOT REGEXP'),'Class type whitelist before dynamic DDL.');
        assertSameValue(2,substr_count($sql,"class_id ',v_class_id_type,'"),'Both new class FK columns copy the actual parent type.');
        assertSameValue(2,substr_count($sql,'DEALLOCATE PREPARE skill_class_stmt'),'No persistent prepared DDL handle.');
        preg_match_all("/SET @skill_class_ddl = CONCAT\('(.+?)class_id ',v_class_id_type,'(.+?)'\);/s",$sql,$templates,PREG_SET_ORDER);
        assertSameValue(2,count($templates),'Two reconstructible whitelisted DDL templates.');
        foreach (['tinyint unsigned','smallint','int(11) unsigned','bigint unsigned'] as $type) {
            foreach ($templates as $template) {
                $ddl=str_replace("''","'",$template[1].'class_id '.$type.$template[2]);
                assertSameValue(true,str_contains($ddl,'class_id '.$type.' NOT NULL'),'Parent width/signedness preserved.');
                assertSameValue(true,str_contains($ddl,'ENGINE=InnoDB'),'Engine preserved.');
            }
        }
    },
    'Task34B migration never grants allocations rewrites history or changes earlier migrations' => function (): void {
        $sql=file_get_contents(__DIR__.'/../database/migrations/013_character_skill_system.sql');
        $sql=preg_replace('~/\*.*?\*/|--[^\n]*~s','',$sql);
        assertSameValue(0,preg_match('/(?:^|;)\s*(UPDATE|DELETE|TRUNCATE|REPLACE)\b/i',$sql),'No data backfill/cleanup.');
        foreach (['characters','character_skills','character_skill_loadout','skill_mutation_requests','combat_actions','combat_events','character_items','character_equipment'] as $table) {
            assertSameValue(0,preg_match('/INSERT\s+INTO\s+'.preg_quote($table,'/').'\b/i',$sql),'No grants/backfill '.$table);
        }
    },
    'Task34B verifier is read-only and verifies catalogue graph FK unique CHECK and data invariants' => function (): void {
        $sql=file_get_contents(__DIR__.'/../database/migrations/013_character_skill_system_verify.sql');
        $clean=preg_replace('~/\*.*?\*/|--[^\n]*~s','',$sql);
        assertSameValue(0,preg_match('/\b(UPDATE|INSERT|DELETE|ALTER|CREATE|DROP|CALL|TRUNCATE|GRANT|SET)\b/i',$clean),'Read-only verifier.');
        assertSameValue(0,preg_match('/\bREPLACE\b(?!\s*\()/i',$clean),'No REPLACE statement.');
        foreach (['REFERENTIAL_CONSTRAINTS','KEY_COLUMN_USAGE','STATISTICS','NON_UNIQUE=0','CHECK_CONSTRAINTS','IS_NULLABLE',
            'catalogue count','prerequisite graph','duplicate assignment','invalid assignment','skill accounting','invalid frozen skill JSON'] as $part) {
            assertSameValue(true,str_contains($sql,$part),'Verifier '.$part);
        }
    },
    'Task34B repository serializes Character before account and safe atomic movement SQL' => function (): void {
        $repo=file_get_contents(__DIR__.'/../ascii-quest/lib/SkillRepository.php');
        assertSameValue(true,str_contains($repo,'FOR UPDATE'),'Owned row lock.');
        assertSameValue(true,str_contains($repo,'c.id = :character_id AND c.user_id = :user_id'),'Owned query.');
        assertSameValue(true,str_contains($repo,"ce.status IN ('active','victory_loot')"),'Both account unresolved statuses.');
        assertSameValue(true,str_contains($repo,'slot = :slot OR skill_key = :skill_key'),'Move source/target once.');
        assertSameValue(true,str_contains($repo,'DELETE FROM character_skill_loadout WHERE character_id = :character_id'),'Deletes scoped to Champion.');
        assertSameValue(false,str_contains($repo,'UPDATE characters'),'No XP/stat/currency writes.');
        assertSameValue(true,str_contains($repo,'if (!$lock)'),'No consistent class read before account mutex.');
        assertSameValue(true,str_contains($repo,'WHERE id = :encounter_id'),'Encounter lock by scalar ID, not JOIN other Champion.');
        $factory=file_get_contents(__DIR__.'/../ascii-quest/lib/CombatBootstrap.php');
        $production=substr($factory,strpos($factory,'public static function service(PDO'),strpos($factory,'public static function serviceForRepository')-strpos($factory,'public static function service(PDO'));
        assertSameValue(true,str_contains($production,'SkillBootstrap::service($pdo)'),'Production always DB authority.');
        assertSameValue(true,str_contains($production,'$itemDropService, $skillService)'),'No production prototype fallback.');
        foreach (glob(__DIR__.'/../ascii-quest/*.php') as $route) {
            assertSameValue(false,str_contains(file_get_contents($route),'serviceForRepository('),'No real route uses the prototype fixture factory.');
        }
        assertSameValue(true,str_contains(file_get_contents(__DIR__.'/../ascii-quest/move_character.php'),'serviceForPdoRepository($pdo, $combatRepository)'),'Movement entry uses DB catalogue/equipment and its owned aggregate.');
    },
];

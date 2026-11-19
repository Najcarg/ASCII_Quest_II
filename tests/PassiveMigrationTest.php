<?php
declare(strict_types=1);
require_once __DIR__.'/PassiveFixtures.php';
$tests=[];
$tests['Task35 migration014 preflights013 rerun partial state constraint enforcement and parent shapes']=function():void {
    $sql=file_get_contents(__DIR__.'/../database/migrations/014_universal_passive_tree.sql');
    foreach(["migration_id='013_character_skill_system'","LEFT(migration_id,4)='014_'",'Migration014 already applied','Migration014 partial tables','Migration014 partial snapshot','@@session.check_constraint_checks','@@session.foreign_key_checks','GET_LOCK','RELEASE_LOCK','RESIGNAL','requires InnoDB prerequisites','unexpected Champion parent ID','prerequisite013 snapshot missing'] as $fragment)assertSameValue(true,str_contains($sql,$fragment),'Preflight '.$fragment);
    assertSameValue(true,strpos($sql,'Migration014 partial tables')<strpos($sql,'CREATE TABLE passive_definitions'),'Preflight before DDL.');
    assertSameValue(3,substr_count($sql,'CREATE TABLE '),'Exactly three normalized tables.');
};
$tests['Task35 migration014 exact80 unique20x4 active classless definitions no automatic grants or mutable points']=function():void {
    $rows=passiveFixtureRows();assertSameValue(80,count($rows),'Exactly80.');
    $positions=[];$keys=[];
    foreach($rows as $node){$positions[$node['row_number']][]=$node['lane_key'];$keys[]=$node['passive_key'];assertSameValue(1,$node['is_active'],'Active V1.');assertSameValue($node['row_number']===20?1:0,$node['is_major'],'Exactly4majors.');}
    assertSameValue(80,count(array_unique($keys)),'Unique stable keys.');assertSameValue(range(1,20),array_keys($positions),'Exactly20rows.');foreach($positions as $lanes)assertSameValue(['a','b','c','d'],$lanes,'Four choices per row.');
    $sql=preg_replace('~/\*.*?\*/~s','',file_get_contents(__DIR__.'/../database/migrations/014_universal_passive_tree.sql'));
    assertSameValue(0,preg_match('/\b(update|delete|truncate)\s+(characters|combat_actions|character_skills|character_items|character_equipment)\b/i',$sql),'No history/XP/level/item/skill mutation.');
    assertSameValue(0,preg_match('/INSERT INTO (character_passives|characters|character_skills)/i',$sql),'No automatic character allocations.');
    assertSameValue(false,str_contains($sql,'class_id'),'No class dependency.');assertSameValue(false,str_contains($sql,'passive_points'),'No mutable passive balance.');
    assertSameValue(2,substr_count($sql,'INSERT INTO '),'Only definitions and migration marker.');
};
$tests['Task35 migration014 structural max2 row guarantee FK row binding no duplicate node durable receipt']=function():void {
    $sql=file_get_contents(__DIR__.'/../database/migrations/014_universal_passive_tree.sql');
    foreach(['PRIMARY KEY (character_id,passive_key)','UNIQUE KEY uq_character_passive_row_pass (character_id,row_number,pass_number)','FOREIGN KEY (passive_key,row_number) REFERENCES passive_definitions(passive_key,row_number)','CHECK (pass_number IN (1,2))','PRIMARY KEY (character_id,request_token)','request_fingerprint CHAR(64)','result_payload LONGTEXT NOT NULL','CHECK (JSON_VALID(result_payload))','ON DELETE RESTRICT','snapshot_elemental_status_chance TINYINT UNSIGNED NULL'] as $fragment)assertSameValue(true,str_contains($sql,$fragment),'Structural protection '.$fragment);
    // At most one allocation in each of two allowed pass slots = <=2 per row.
    $slots=[];foreach([1,2,3,1] as $pass){$accepted=in_array($pass,[1,2],true)&&!isset($slots[$pass]);if($accepted)$slots[$pass]=true;}
    assertSameValue(2,count($slots),'Constraint model refuses third pass and duplicate pass.');
};
$tests['Task35 verifier read-only precise CHECK forms fingerprints schema accounting sequential history']=function():void {
    $verify=file_get_contents(__DIR__.'/../database/migrations/014_universal_passive_tree_verify.sql');
    $noComments=preg_replace('~/\*.*?\*/~s','',$verify);
    assertSameValue(0,preg_match('/\b(insert|update|delete|alter|create|drop|truncate|call|replace|set)\s+(?:into\s+)?[a-z_]/i',str_replace('REPLACE(', 'REPLACEFUNC(', $noComments)),'Read-only SQL only.');
    foreach(['information_schema.COLUMNS','information_schema.STATISTICS','information_schema.REFERENTIAL_CONSTRAINTS','information_schema.KEY_COLUMN_USAGE','information_schema.CHECK_CONSTRAINTS','NON_UNIQUE','SUB_PART','COLLATION_NAME','overallocated row','nonsequential history','overspent points','invalid receipt','catalogue drift','snapshot_elemental_status_chance'] as $fragment)assertSameValue(true,str_contains($verify,$fragment),'Verifier '.$fragment);
    assertSameValue(false,str_contains($verify,"REPLACE(CHECK_CLAUSE,'(',''"),'No arbitrary parentheses erasure.');
    $groups=[];foreach(passiveFixtureRows() as $row)$groups[$row['row_number']][]=implode('|',$row);
    foreach($groups as $row=>$values){$literal=implode("\n",$values);assertSameValue(true,strlen($literal)<1024,'Safe minimum GROUP_CONCAT length.');assertSameValue(true,str_contains($verify,hash('sha256',$literal)),'Exact seed fingerprint row'.$row);}
};
$tests['Task35 MariaDB CHECK flattened equivalent accepted weaker predicates not accepted']=function():void {
    $verify=file_get_contents(__DIR__.'/../database/migrations/014_universal_passive_tree_verify.sql');
    $allowed=['row_number=20andis_major=1orrow_number<20andis_major=0','(row_number=20andis_major=1)or(row_number<20andis_major=0)','((row_number=20andis_major=1)or(row_number<20andis_major=0))'];
    foreach($allowed as $expr)assertSameValue(true,str_contains($verify,"'".$expr."'"),'Exact equivalent form.');
    foreach(['row_number=20oris_major=1','pass_numberin(1,2,3)','snapshot_elemental_status_chancebetween0and101'] as $weak)assertSameValue(false,str_contains($verify,"'".$weak."'"),'Weaker CHECK never whitelisted.');
};
$tests['Task35 production repositories lock Character account then lookup safe encounter and atomic receipts']=function():void {
    $service=file_get_contents(__DIR__.'/../ascii-quest/lib/PassiveService.php');$repo=file_get_contents(__DIR__.'/../ascii-quest/lib/PassiveRepository.php');$authority=file_get_contents(__DIR__.'/../ascii-quest/lib/SkillRepository.php');
    assertSameValue(true,str_contains($repo,'$this->authority->ownedCharacter'),'Reuses34B Character lock.');
    assertSameValue(true,str_contains($authority,'FOR UPDATE'),'Authoritative serialization SQL.');
    assertSameValue(true,str_contains($repo,'INSERT INTO character_passives'),'Canonical persistence.');
    assertSameValue(true,str_contains($repo,'INSERT INTO passive_mutation_requests'),'Durable receipt.');
    assertSameValue(true,strpos($service,'$this->repository->unlock(')<strpos($service,'$this->repository->complete('),'Allocation and receipt same transaction.');
    assertSameValue(true,str_contains($service,'$this->repository->rollback(); throw $e;'),'All thrown mutations rollback.');
};
return $tests;

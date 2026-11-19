<?php
declare(strict_types=1);
require_once __DIR__.'/PassiveFixtures.php';
require_once __DIR__.'/../ascii-quest/lib/CharacterStats.php';
$tests=[];
foreach ([1=>0,49=>0,50=>0,51=>1,60=>10,70=>20,80=>30,90=>40,91=>40,99=>40,100=>40] as $level=>$expected) {
    $tests["Task35 points at level$level"] = function () use($level,$expected):void {
        $points=PassiveService::points(passiveFixtureCharacter($level),0);
        assertSameValue(['earned'=>$expected,'spent'=>0,'available'=>$expected],$points,'Derived formula.');
        assertSameValue(max(0,$expected-40),PassiveService::points(passiveFixtureCharacter($level),40)['available'],'Never negative.');
    };
}
$tests['Task35 authoritative XP not forged cached level and strict malformed progression']=function():void {
    $character=passiveFixtureCharacter(51);$character['level']=90;
    assertSameValue(1,PassiveService::points($character,0)['earned'],'XP-derived alive level.');
    foreach ([null,0,-1,101,1.5,true,'bad'] as $bad) { $character['level']=$bad;passiveReject(fn()=>PassiveService::points($character,0),InvalidArgumentException::class); }
    foreach ([-1,41] as $spent) { passiveReject(fn()=>PassiveService::points(passiveFixtureCharacter(),$spent),InvalidArgumentException::class); }
};
foreach (range(1,40) as $step) {
    $tests["Task35 sequential choice $step supports switching lane and projects next row/pass"] = function() use($step):void {
        [$repo,$service]=passiveFixture();
        $repo->allocations=passivePrefix($step-1);
        $before=$service->state(7,42);$row=($step-1)%20+1;$pass=intdiv($step-1,20)+1;
        $lane=$pass===1?($row%2?'a':'d'):($row%2?'b':'c');
        $node=passiveNode($row,$lane);
        assertSameValue($row,$before['progress']['next_row'],'Next row.');
        assertSameValue($pass,$before['progress']['current_pass'],'Current pass.');
        $available=array_filter(array_merge(...array_column($before['rows'],'nodes')),static fn($n)=>$n['state']==='available');
        assertSameValue($pass===1?4:3,count($available),'All unused choices in current row, not lane-specific.');
        $service->unlock(7,42,$node['key'],passiveToken($step));
        $after=$service->state(7,42);
        assertSameValue($step,$after['points']['spent'],'One point only.');
        assertSameValue($step===40?null:$step%20+1,$after['progress']['next_row'],'Next row or complete.');
        assertSameValue($step===40?null:intdiv($step,20)+1,$after['progress']['current_pass'],'Pass transition.');
        assertSameValue(intdiv($step,20),$after['progress']['completed_passes'],'Completed passes.');
        $selected=array_values(array_filter($after['rows'][$row-1]['nodes'],fn($n)=>$n['key']===$node['key']))[0];
        assertSameValue($pass,$selected['unlocked_pass'],'Historical pass marker.');
    };
}
$tests['Task35 row gates reject future previous duplicate second during pass1 and third after pass2']=function():void {
    [$repo,$service]=passiveFixture();
    passiveReject(fn()=>$service->unlock(7,42,passiveNode(2)['key'],passiveToken(1)));
    $service->unlock(7,42,passiveNode(1)['key'],passiveToken(2));
    passiveReject(fn()=>$service->unlock(7,42,passiveNode(1,'b')['key'],passiveToken(3)));
    passiveReject(fn()=>$service->unlock(7,42,passiveNode(1)['key'],passiveToken(4)));
    $repo->allocations=passivePrefix(20);
    passiveReject(fn()=>$service->unlock(7,42,passiveNode(2,'b')['key'],passiveToken(5)));
    $service->unlock(7,42,passiveNode(1,'b')['key'],passiveToken(6));
    passiveReject(fn()=>$service->unlock(7,42,passiveNode(1,'c')['key'],passiveToken(7)));
    $repo->allocations=passivePrefix(40);
    passiveReject(fn()=>$service->unlock(7,42,passiveNode(20,'d')['key'],passiveToken(8)));
    assertSameValue(true,$service->state(7,42)['progress']['complete'],'Exactly two passes.');
};
$tests['Task35 points never permit free grants and history validation fails closed']=function():void {
    [$repo,$service]=passiveFixture(50);
    passiveReject(fn()=>$service->unlock(7,42,passiveNode(1)['key'],passiveToken(1)));
    foreach ([array_reverse(passivePrefix(2)),passivePrefix(40)] as $valid) { $service->progress($valid); }
    $bad=passivePrefix(2);array_shift($bad);passiveReject(fn()=>$service->progress($bad),UnexpectedValueException::class);
    $bad=passivePrefix(1);$bad[]=$bad[0];passiveReject(fn()=>$service->progress($bad),UnexpectedValueException::class);
    $bad=passivePrefix(1);$bad[0]['pass_number']=3;passiveReject(fn()=>$service->progress($bad),UnexpectedValueException::class);
    $bad=passivePrefix(1);$bad[0]['row_number']=2;passiveReject(fn()=>$service->progress($bad),UnexpectedValueException::class);
};
foreach (['warrior'=>[[1,'b'],[2,'b']],'mage'=>[[1,'a'],[3,'a']],'rogue'=>[[3,'d']],'cleric'=>[[3,'c']]] as $class=>$choices) {
    $tests["Task35 universal $class cross-archetype access"]=function()use($class,$choices):void {
        foreach ($choices as [$row,$lane]) {
            [$repo,$service]=passiveFixture(90,$class);$repo->allocations=passivePrefix($row-1);
            $node=passiveNode($row,$lane);$service->unlock(7,42,$node['key'],passiveToken($row));
            assertSameValue($node['key'],$repo->allocations[$row-1]['passive_key'],'No class filtering.');
        }
    };
}
$tests['Task35 ownership lifecycle combat and victory_loot no allocation or success receipt']=function():void {
    [$repo,$service]=passiveFixture();$key=passiveNode(1)['key'];
    foreach ([[8,42],[7,43],[0,42]] as [$user,$id]) { passiveReject(fn()=>$service->unlock($user,$id,$key,passiveToken(1)),OutOfBoundsException::class); }
    foreach (['active','victory_loot'] as $status) {
        $repo->combat=['id'=>91,'status'=>$status];
        assertSameValue('combat_locked',$service->state(7,42)['rows'][0]['nodes'][0]['reason'],'Same gate in projection.');
        passiveReject(fn()=>$service->unlock(7,42,$key,passiveToken(2)));
    }
    $repo->combat=null;$repo->character['life_state']='dead';
    passiveReject(fn()=>$service->unlock(7,42,$key,passiveToken(3)));
    assertSameValue([], $repo->receipts,'No failures have success receipts.');
    $repo->allocations=passivePrefix(1);$history=$service->state(7,42);
    assertSameValue('unlocked',$history['rows'][0]['nodes'][0]['state'],'Dead history retained.');
    [$new,$fresh]=passiveFixture(1);assertSameValue(0,$fresh->state(7,42)['points']['spent'],'New Champion fresh.');
};
$tests['Task35 durable receipt replay collision rollback lost response and serialization order']=function():void {
    [$repo,$service]=passiveFixture(51);$key=passiveNode(1)['key'];$token=passiveToken(1);
    $result=$service->unlock(7,42,$key,$token);
    assertSameValue(['begin','character_lock','account_lock','encounter','commit'],$repo->events,'Champion then account then encounter lock.');
    $repo->character['life_state']='dead';$repo->combat=['status'=>'active'];
    assertSameValue($result,$service->unlock(7,42,$key,$token),'Immutable replay after death/combat.');
    passiveReject(fn()=>$service->unlock(7,42,passiveNode(1,'b')['key'],$token));
    assertSameValue(1,count($repo->allocations),'No duplicate replay allocation.');
    [$repo,$service]=passiveFixture(51);$repo->failComplete=true;
    passiveReject(fn()=>$service->unlock(7,42,$key,$token),RuntimeException::class);
    assertSameValue([],$repo->allocations,'Failed receipt rolls back allocation.');assertSameValue([],$repo->receipts,'No partial success.');
    $repo->failComplete=false;$service->unlock(7,42,$key,$token);
    passiveReject(fn()=>$service->unlock(7,42,passiveNode(1,'b')['key'],passiveToken(2)));
    assertSameValue(1,count($repo->allocations),'Serialized second request cannot overspend or take same row/pass.');
};
$tests['Task35 inactive missing bad keys tokens and corrupt catalogue fail closed']=function():void {
    [$repo,$service]=passiveFixture();
    foreach (['bad','warrior_strength','passive_r99_a_missing'] as $key) { passiveReject(fn()=>$service->unlock(7,42,$key,passiveToken(1)),str_starts_with($key,'passive_')?DomainException::class:InvalidArgumentException::class); }
    passiveReject(fn()=>$service->unlock(7,42,passiveNode(1)['key'],'bad'),InvalidArgumentException::class);
    foreach (['missing','duplicate','effect','major'] as $type) {
        $rows=passiveFixtureRows();
        if($type==='missing')array_pop($rows);if($type==='duplicate')$rows[1]=$rows[0];if($type==='effect')$rows[0]['effect_json']='{"class_only":10}';if($type==='major')$rows[0]['is_major']=1;
        passiveReject(fn()=>new PassiveCatalogue($rows),UnexpectedValueException::class);
    }
    $rows=passiveFixtureRows();$rows[0]['is_active']=0;$catalogue=new PassiveCatalogue($rows);
    $state=(new PassiveService(new stdClass(),$catalogue))->project(passiveFixtureCharacter(),[]);
    assertSameValue('inactive',$state['rows'][0]['nodes'][0]['reason'],'Inactive unavailable.');
};
$tests['Task35 Level100 flag reserves Ascendancy only and skill points stop at49']=function():void {
    [$repo,$service]=passiveFixture(100);assertSameValue(true,$service->state(7,42)['ascendancy_unlocked'],'Future flag.');
    assertSameValue(49,SkillService::points($repo->character,0)['earned'],'Active tree does not gain51–100 points.');
    $repo->character=passiveFixtureCharacter(99);assertSameValue(false,$service->state(7,42)['ascendancy_unlocked'],'Not before100.');
};
$tests['Task35 progression100 preserves old thresholds all51–100 boundaries legacy receipts and dead history']=function():void {
    $table=require __DIR__.'/../ascii-quest/config/character_progression.php';
    foreach(range(1,100) as $level) {
        assertSameValue(100*($level-1)**2,$table[$level],'Unchanged curve.');
        assertSameValue($level,CharacterProgression::levelForTotalXp($table[$level]),'Exact threshold.');
        if($level>1)assertSameValue($level-1,CharacterProgression::levelForTotalXp($table[$level]-1),'Below threshold.');
        assertSameValue($level,CharacterProgression::levelForTotalXp($table[$level]+1),'Above threshold.');
    }
    $legacy=['xp_gained'=>40,'total_xp_before'=>999999,'total_xp_after'=>1000039,'old_level'=>50,'new_level'=>50];
    assertSameValue($legacy,CharacterProgression::rewardSnapshot(json_encode($legacy)),'Immutable cap50 receipt.');
    $legacy['new_level']=99;passiveReject(fn()=>CharacterProgression::rewardSnapshot(json_encode($legacy)),UnexpectedValueException::class);
    $dead=passiveFixtureCharacter(4);$dead['life_state']='dead';$dead['experience']=999999;
    assertSameValue(4,CharacterProgression::project($dead)['level'],'Never reconcile dead historical levels.');
};
return $tests;

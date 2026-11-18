<?php
declare(strict_types=1);
require_once __DIR__ . '/SkillFixtures.php';

$tests=[];
foreach (SkillCatalogue::CLASSES as $class) {
    $tests["Task34B $class unlocks its four-node graph and derives current-class state"] = function () use ($class): void {
        [$repo,$service]=task34bService(new FakeSkillRepository($class));
        $state=$service->state(7,42);
        assertSameValue(4,count($state['skills']),'Four nodes.');
        assertSameValue(['available','locked','locked','locked'],array_column($state['skills'],'state'),'Initial graph states.');
        assertSameValue(['earned'=>4,'spent'=>0,'available'=>4],$state['points'],'Initial derived points.');
        $keys=task34bLearnClass($repo,$service);
        $state=$service->state(7,42);
        assertSameValue(['unlocked','unlocked','unlocked','unlocked'],array_column($state['skills'],'state'),'All unlocked.');
        assertSameValue(['earned'=>4,'spent'=>4,'available'=>0],$state['points'],'Cost1 all.');
        assertSameValue($keys,array_column($state['skills'],'key'),'Current class only.');
        assertSameValue(['character','account','encounter'],array_slice($repo->locks,0,3),'Existing lock order.');
    };
}
$tests += [
    'Task34B first consistent class read follows account mutex before combat lookup' => function (): void {
        [$r,$s]=task34bService();$r->resolveClassAfterMutex=true;
        $s->mutate(7,42,'unlock','warrior_power_strike',null,task34bToken());
        assertSameValue(['character','account','class','encounter'],array_slice($r->locks,0,4),'No stale pre-mutex repeatable-read snapshot.');
        $r->locks=[];$r->battle=['id'=>92,'status'=>'active'];
        assertSameValue(true,$s->state(7,42)['loadout_locked'],'Account fighter remains authoritative.');
        assertSameValue(['character','account','class','encounter'],array_slice($r->locks,0,4),'GET same lock/snapshot order.');
    },
    'Task34B unlock rejects wrong user missing champion class dead inactive level prerequisites points' => function (): void {
        [$r,$s]=task34bService();
        foreach ([[8,42,'warrior_power_strike'],[7,999,'warrior_power_strike'],[7,42,'mage_flame_strike'],[7,42,'warrior_berserker_strike'],[7,42,'warrior_cleave'],[7,42,'unknown_skill']] as [$user,$id,$key]) {
            task34aAssertRejected(fn()=>$s->mutate($user,$id,'unlock',$key,null,task34bToken()),$user===8||$id===999?OutOfBoundsException::class:DomainException::class);
        }
        $r->characters[42]['life_state']='dead';
        task34aAssertRejected(fn()=>$s->mutate(7,42,'unlock','warrior_power_strike',null,task34bToken()),DomainException::class);
        $r->characters[42]['life_state']='alive'; $r->definitions[0]['is_active']=0;
        task34aAssertRejected(fn()=>$s->mutate(7,42,'unlock','warrior_power_strike',null,task34bToken()),DomainException::class);
        $r->definitions[0]['is_active']=1; $r->characters[42]['level']=1; $r->characters[42]['experience']=0;
        task34aAssertRejected(fn()=>$s->mutate(7,42,'unlock','warrior_power_strike',null,task34bToken()),DomainException::class);
        $r->characters[42]['level']=2; $r->characters[42]['experience']=100;
        $r->learned[42]=['mage_flame_strike']; // Existing corrupt audit still consumes points, never refunds.
        assertSameValue('no_points',$s->state(7,42)['skills'][0]['reason'],'No currency.');
        task34aAssertRejected(fn()=>$s->mutate(7,42,'unlock','warrior_power_strike',null,task34bToken()),DomainException::class);
        assertSameValue(['mage_flame_strike'],$r->learned[42],'No overspend.');
    },
    'Task34B root level2 branch level3 ultimate level5 and normal progression accounting' => function (): void {
        [$r,$s]=task34bService(new FakeSkillRepository('warrior',2));
        $s->mutate(7,42,'unlock','warrior_power_strike',null,task34bToken(1));
        assertSameValue(0,$s->state(7,42)['points']['available'],'Spent first point.');
        task34aAssertRejected(fn()=>$s->mutate(7,42,'unlock','warrior_cleave',null,task34bToken(2)),DomainException::class);
        $r->characters[42]['level']=3;$r->characters[42]['experience']=400;
        $s->mutate(7,42,'unlock','warrior_cleave',null,task34bToken(2));
        assertSameValue(0,$s->state(7,42)['points']['available'],'Two levels two points.');
        task34aAssertRejected(fn()=>$s->mutate(7,42,'unlock','warrior_berserker_strike',null,task34bToken(3)),DomainException::class);
        $r->characters[42]['level']=5;$r->characters[42]['experience']=1600;
        $s->mutate(7,42,'unlock','warrior_berserker_strike',null,task34bToken(3));
        assertSameValue(1,$s->state(7,42)['points']['available'],'One point remains for other branch.');
    },
    'Task34B unlock same-token replay collision different-token learned and rollback' => function (): void {
        [$r,$s]=task34bService();
        $result=$s->mutate(7,42,'unlock','warrior_power_strike',null,task34bToken());
        assertSameValue($result,$s->mutate(7,42,'unlock','warrior_power_strike',null,task34bToken()),'Semantic replay.');
        task34aAssertRejected(fn()=>$s->mutate(7,42,'unlock','warrior_cleave',null,task34bToken()),DomainException::class);
        task34aAssertRejected(fn()=>$s->mutate(7,42,'unlock','warrior_power_strike',null,task34bToken(2)),DomainException::class);
        $before=[$r->learned,$r->receipts];$r->failReceipt=true;
        task34aAssertRejected(fn()=>$s->mutate(7,42,'unlock','warrior_cleave',null,task34bToken(3)),RuntimeException::class);
        assertSameValue($before,[$r->learned,$r->receipts],'Receipt failure rolls back allocation.');
        $r->failReceipt=false;$r->failInsert=true;
        task34aAssertRejected(fn()=>$s->mutate(7,42,'unlock','warrior_cleave',null,task34bToken(3)),RuntimeException::class);
        assertSameValue($before,[$r->learned,$r->receipts],'Allocation failure atomic.');
    },
    'Task34B two service requests cannot spend the same last derived point' => function (): void {
        [$r,$first]=task34bService(new FakeSkillRepository('warrior',3));
        $first->mutate(7,42,'unlock','warrior_power_strike',null,task34bToken());
        $second=new SkillService($r,$r->catalogue());
        $first->mutate(7,42,'unlock','warrior_cleave',null,task34bToken(2));
        task34aAssertRejected(fn()=>$second->mutate(7,42,'unlock','warrior_crushing_blow',null,task34bToken(3)),DomainException::class);
        assertSameValue(2,count($r->learned[42]),'No overspend across service instances.');
        assertSameValue(0,$second->state(7,42)['points']['available'],'Accounting persisted.');
        $r->begin();
        task34aAssertRejected(fn()=>$r->begin(),RuntimeException::class);
        $r->rollback();
    },
    'Task34B assignment moves replaces persists all slots and unassigns without duplicates' => function (): void {
        [$r,$s]=task34bService();$keys=task34bLearnClass($r,$s);
        foreach (SkillCatalogue::SLOTS as $i=>$slot) { $s->mutate(7,42,'assign',$keys[$i],$slot,task34bToken(200+$i)); }
        assertSameValue($keys,array_column($s->state(7,42)['loadout'],'key'),'All slots assigned.');
        $move=$s->mutate(7,42,'assign',$keys[0],'skill_2',task34bToken(210));
        assertSameValue('skill_1',$move['source_slot'],'Old slot moved.');
        assertSameValue($keys[1],$move['displaced_skill_key'],'Target displaced.');
        $state=(new SkillService($r,$r->catalogue()))->state(7,42);
        assertSameValue(null,$state['loadout']['skill_1']['key'],'Source emptied.');
        assertSameValue($keys[0],$state['loadout']['skill_2']['key'],'Move persisted.');
        assertSameValue(3,count($r->assignments[42]),'One assignment per skill.');
        $s->mutate(7,42,'unassign',null,'skill_2',task34bToken(211));
        assertSameValue(null,$s->state(7,42)['loadout']['skill_2']['key'],'Unassigned.');
        assertSameValue(4,$s->state(7,42)['points']['spent'],'Loadout never refunds.');
    },
    'Task34B loadout rejects locked crossclass type unknown slot dead wrong owner inactive' => function (): void {
        [$r,$s]=task34bService();
        task34aAssertRejected(fn()=>$s->mutate(7,42,'assign','warrior_power_strike','skill_1',task34bToken()),DomainException::class);
        $keys=task34bLearnClass($r,$s);
        foreach ([[$keys[0],'ultimate'],[$keys[3],'skill_1'],['mage_flame_strike','skill_1']] as [$key,$slot]) {
            task34aAssertRejected(fn()=>$s->mutate(7,42,'assign',$key,$slot,task34bToken()),DomainException::class);
        }
        task34aAssertRejected(fn()=>$s->mutate(7,42,'assign',$keys[0],'skill1',task34bToken()),InvalidArgumentException::class);
        task34aAssertRejected(fn()=>$s->mutate(8,42,'assign',$keys[0],'skill_1',task34bToken()),OutOfBoundsException::class);
        $r->definitions[0]['is_active']=0;
        task34aAssertRejected(fn()=>$s->mutate(7,42,'assign',$keys[0],'skill_1',task34bToken()),DomainException::class);
        $r->definitions[0]['is_active']=1;$r->characters[42]['life_state']='dead';
        task34aAssertRejected(fn()=>$s->mutate(7,42,'assign',$keys[0],'skill_1',task34bToken()),DomainException::class);
        task34aAssertRejected(fn()=>$s->mutate(7,42,'unassign',null,'skill_1',task34bToken()),DomainException::class);
    },
    'Task34B active and victory-loot block assignment and unassignment but allocation cannot change pending loadout' => function (): void {
        foreach (['active','victory_loot'] as $status) {
            [$r,$s]=task34bService();$keys=task34bLearnClass($r,$s);
            $r->battle=['id'=>91,'status'=>$status];
            task34aAssertRejected(fn()=>$s->mutate(7,42,'assign',$keys[0],'skill_1',task34bToken()),DomainException::class);
            task34aAssertRejected(fn()=>$s->mutate(7,42,'unassign',null,'skill_1',task34bToken()),DomainException::class);
            assertSameValue('combat_locked',$s->state(7,42)['loadout_reason'],'Public reason.');
        }
        [$r,$s]=task34bService();$r->battle=['id'=>91,'status'=>'active'];
        $s->mutate(7,42,'unlock','warrior_power_strike',null,task34bToken());
        assertSameValue([],$r->assignments,'Allocation alone never changes loadout.');
    },
    'Task34B replacement failure receipt failure collision and replay preserve atomic semantics' => function (): void {
        [$r,$s]=task34bService();$keys=task34bLearnClass($r,$s);
        $result=$s->mutate(7,42,'assign',$keys[0],'skill_1',task34bToken(201));
        $before=[$r->assignments,$r->receipts];$r->failAssignment=true;
        task34aAssertRejected(fn()=>$s->mutate(7,42,'assign',$keys[1],'skill_1',task34bToken(202)),RuntimeException::class);
        assertSameValue($before,[$r->assignments,$r->receipts],'Delete-then-insert rolled back.');
        $r->failAssignment=false;$r->failReceipt=true;
        task34aAssertRejected(fn()=>$s->mutate(7,42,'unassign',null,'skill_1',task34bToken(202)),RuntimeException::class);
        assertSameValue($before,[$r->assignments,$r->receipts],'Unassign receipt rollback.');
        $r->failReceipt=false;
        $s->mutate(7,42,'assign',$keys[1],'skill_1',task34bToken(202));
        assertSameValue($result,$s->mutate(7,42,'assign',$keys[0],'skill_1',task34bToken(201)),'Old result replay without reapplying move.');
        assertSameValue($keys[1],$s->state(7,42)['loadout']['skill_1']['key'],'Current loadout not overwritten by replay.');
        task34aAssertRejected(fn()=>$s->mutate(7,42,'assign',$keys[0],'skill_2',task34bToken(201)),DomainException::class);
    },
    'Task34B dead audit retained fresh Champion independent and completed replay is not new mutation' => function (): void {
        [$r,$s]=task34bService();$keys=task34bLearnClass($r,$s);
        $result=$s->mutate(7,42,'assign',$keys[0],'skill_1',task34bToken(201));
        $r->characters[42]['life_state']='dead';$r->characters[42]['current_hp']=0;
        $before=[$r->learned,$r->assignments,$r->receipts];
        assertSameValue($result,$s->mutate(7,42,'assign',$keys[0],'skill_1',task34bToken(201)),'Retained audit replay.');
        assertSameValue($keys[0],$s->state(7,42)['loadout']['skill_1']['key'],'Dead loadout retained.');
        assertSameValue($before,[$r->learned,$r->assignments,$r->receipts],'Death read/replay never mutates audit.');
        $fresh=$s->state(7,43);
        assertSameValue(['earned'=>0,'spent'=>0,'available'=>0],$fresh['points'],'Fresh level1 independent.');
        assertSameValue([null,null,null,null],array_column($fresh['loadout'],'key'),'Fresh loadout empty.');
    },
];
return $tests;

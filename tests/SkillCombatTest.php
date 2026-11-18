<?php
declare(strict_types=1);
require_once __DIR__ . '/SkillFixtures.php';

function task34bCombat(string $class='warrior',bool $assigned=true,array $random=[1,21,100,1]): array
{
    $pdo=new FakeCombatPdo();
    $pdo->characters[42]['class_key']=$class;$pdo->characters[42]['level']=5;$pdo->characters[42]['experience']=1600;
    $repo=new FakeSkillRepository($class);$repo->characters=&$pdo->characters;
    $skills=new SkillService($repo,$repo->catalogue());
    $keys=task34bLearnClass($repo,$skills);
    if ($assigned) { foreach (SkillCatalogue::SLOTS as $i=>$slot) { $skills->mutate(7,42,'assign',$keys[$i],$slot,task34bToken(200+$i)); } }
    $pdo->encounters[91]=task4Encounter(['timeline_elapsed_ms'=>0,'next_enemy_decision_timeline_ms'=>100000,'enemy_ai_initialized_timeline_ms'=>0,'player_actions_remaining'=>5,'enemy_actions_remaining'=>0,'enemy_current_hp'=>1000,'enemy_max_hp'=>1000]);
    $repo->battle=['id'=>91,'status'=>'active'];
    $clock=new Task4MutableCombatClock(new DateTimeImmutable('2026-09-01 12:00:00.000000',new DateTimeZone('UTC')));
    $combat=CombatBootstrap::serviceForRepository(new CombatRepository($pdo),$clock,null,new Task6SequenceRandomSource($random),null,$skills);
    return [$pdo,$clock,$combat,$repo,$skills,$keys];
}

$tests=[];
foreach (SkillCatalogue::CLASSES as $class) {
    $tests["Task34B $class all four assigned combat slots execute canonical snapshots with intrinsic statuses only"] = function () use ($class): void {
        foreach (SkillCatalogue::SLOTS as $i=>$slot) {
            [$pdo,$clock,$combat,$repo,$skills,$keys]=task34bCombat($class);
            $state=$combat->state(7,42);
            assertSameValue(SkillCatalogue::SLOTS,array_column($state['player_skills'],'slot'),'All slots projected.');
            assertSameValue($keys,array_column($state['player_skills'],'key'),'Assigned identities.');
            $definition=$skills->catalogue()->skill($keys[$i]);
            assertSameValue($definition['display_name'],$state['player_skills'][$i]['name'],'DB name.');
            $combat->startPlayerAction(7,42,$keys[$i],task34bToken(300+$i),$slot);
            $actions=array_values(array_filter($pdo->actions,static fn($a)=>$a['actor']==='player'&&$a['action_kind']==='skill'));
            assertSameValue(1,count($actions),'Single action.');$action=$actions[0];
            $frozen=SkillCatalogue::decodeSnapshot($action['snapshot_skill_definition']);
            assertSameValue($slot,$frozen['slot'],'Frozen assignment.');
            assertSameValue($definition['base_damage'],$action['snapshot_base_damage'],'Fixed direct base.');
            assertSameValue($definition['duration_ms'],$action['resolves_timeline_ms'],'Base duration in prototype fixture.');
            assertSameValue($definition['cooldown_ms'],$action['cooldown_ready_timeline_ms'],'Key cooldown.');
            foreach (SkillCatalogue::STATUS_KEYS as $key) { assertSameValue(0,$action['snapshot_'.$key.'_status_damage'],'No equipment status addition.'); }
            $clock->set((new DateTimeImmutable('2026-09-01 12:00:00.000000',new DateTimeZone('UTC')))->modify('+'.$definition['duration_ms'].' milliseconds'));
            $resolved=$combat->state(7,42);
            assertSameValue('resolved',$pdo->actions[$action['id']]['state'],'Action resolved.');
            assertSameValue(1000-$definition['base_damage'],$pdo->encounters[91]['enemy_current_hp'],'Direct damage unchanged.');
            $statuses=array_values($pdo->statuses);
            if ($definition['status_key']===null) { assertSameValue([],$statuses,'No intrinsic status.'); }
            else {
                assertSameValue(1,count($statuses),'Intrinsic status entered existing engine.');
                assertSameValue($definition['status_key'],$statuses[0]['status_key'],'Supported status.');
                assertSameValue($definition['status_power'],$statuses[0]['potency'],'Intrinsic potency, not equipment.');
                assertSameValue(4000,$statuses[0]['expires_timeline_ms']-$statuses[0]['applied_timeline_ms'],'Frozen duration.');
            }
            assertSameValue(4,$resolved['turn']['player_actions_remaining'],'One Action consumed.');
        }
    };
}
$tests += [
    'Task34B fresh movement encounter initial projection uses persisted four-slot loadout' => function (): void {
        [$pdo,$clock,,,$skills,$keys]=task34bCombat('rogue');$pdo->encounters=[];
        $repository=new CombatRepository($pdo);
        $service=CombatBootstrap::serviceForRepository($repository,$clock,null,new Task6SequenceRandomSource([]),null,$skills);
        $repository->beginTransaction();$character=$repository->lockOwnedCharacter(7,42);$repository->lockOwnedAccountActiveEncounter(7,42);
        $state=$service->startOrResumeForLockedMovement(7,$character,CombatDefinitionRegistry::fromDefaultConfig()->encounters()[0]);
        $repository->commit();
        assertSameValue($keys,array_column($state['player_skills'],'key'),'Initial movement response cannot advertise a free prototype skill.');
        assertSameValue(4,count($state['skill_state']['skills']),'Shared tree state on initial combat entry.');
    },
    'Task34B empty persisted loadout exposes four unavailable slots and rejects free prototype' => function (): void {
        [$pdo,,$combat,,,$keys]=task34bCombat('mage',false);
        $state=$combat->state(7,42);
        assertSameValue([null,null,null,null],array_column($state['player_skills'],'key'),'No free skill1.');
        assertSameValue(['empty_slot','empty_slot','empty_slot','empty_slot'],array_column($state['player_skills'],'disabled_reason'),'Empty reasons.');
        foreach ([['prototype_flame_strike','skill_1'],[$keys[0],'skill_1'],[$keys[0],null],['warrior_power_strike','skill_1']] as [$key,$slot]) {
            task34aAssertRejected(fn()=>$combat->startPlayerAction(7,42,$key,task34bToken(300),$slot),DomainException::class);
        }
        assertSameValue([],array_values(array_filter($pdo->actions,static fn($a)=>$a['actor']==='player')),'Crafted actions not persisted.');
    },
    'Task34B assigned action still validates slot learned class active level and cooldown' => function (): void {
        [$pdo,$clock,$combat,$repo,$skills,$keys]=task34bCombat('warrior');
        task34aAssertRejected(fn()=>$combat->startPlayerAction(7,42,$keys[0],task34bToken(300),'skill_2'),DomainException::class);
        task34aAssertRejected(fn()=>$combat->startPlayerAction(7,42,$keys[0],task34bToken(300),null),DomainException::class);
        $saved=$repo->learned[42];$repo->learned[42]=[];
        task34aAssertRejected(fn()=>$combat->startPlayerAction(7,42,$keys[0],task34bToken(300),'skill_1'),DomainException::class);
        $repo->learned[42]=$saved;
        $combat->startPlayerAction(7,42,$keys[0],task34bToken(300),'skill_1');
        $clock->set(new DateTimeImmutable('2026-09-01 12:00:02.000000',new DateTimeZone('UTC')));
        $state=$combat->state(7,42);
        assertSameValue('cooldown',$state['player_skills'][0]['disabled_reason'],'Cooldown projected.');
        task34aAssertRejected(fn()=>$combat->startPlayerAction(7,42,$keys[0],task34bToken(301),'skill_1'),DomainException::class);
        task34aAssertRejected(fn()=>$combat->startPlayerAction(7,42,$keys[0],task34bToken(300),'skill_2'),DomainException::class);
    },
    'Task34B pending skill survives current loadout removal catalogue changes and replay after retirement' => function (): void {
        [$pdo,$clock,$combat,$repo,$skills,$keys]=task34bCombat('mage');
        $combat->startPlayerAction(7,42,$keys[1],task34bToken(300),'skill_2');
        $before=$pdo->actions;
        $repo->assignments[42]=[];$repo->learned[42]=[];
        foreach ($repo->definitions as &$row) { if ($row['skill_key']===$keys[1]) { $row['base_damage']=999;$row['status_power']=99;$row['display_name']='Retuned';$row['is_active']=0; } } unset($row);
        $fresh=new SkillService($repo,$repo->catalogue());
        $clock->set(new DateTimeImmutable('2026-09-01 12:00:02.000000',new DateTimeZone('UTC')));
        $restarted=CombatBootstrap::serviceForRepository(new CombatRepository($pdo),$clock,null,new Task6SequenceRandomSource([1,21,100,1]),null,$fresh);
        $state=$restarted->state(7,42);
        assertSameValue(980,$pdo->encounters[91]['enemy_current_hp'],'Frozen Frost Bolt20, not today999.');
        assertSameValue(15,array_values($pdo->statuses)[0]['potency'],'Frozen Freeze15, not today99.');
        $action=array_values($before)[0];
        assertSameValue($action['snapshot_skill_definition'],$pdo->actions[$action['id']]['snapshot_skill_definition'],'Immutable metadata unchanged.');
        $events=$pdo->events;
        $restarted->startPlayerAction(7,42,$keys[1],task34bToken(300),'skill_2');
        assertSameValue($events,$pdo->events,'Token replay ignores today allocation, does not resolve twice.');
        assertSameValue([null,null,null,null],array_column($state['player_skills'],'key'),'Current display can be empty without changing pending history.');
        assertSameValue('Frost Bolt',$state['player_actions'][0]['name'],'Frozen history name, not Retuned.');
    },
    'Task34B intrinsic proc chance is frozen rather than recalculated at resolution' => function (): void {
        [$pdo,$clock,$combat,,,$keys]=task34bCombat('mage');
        $combat->startPlayerAction(7,42,$keys[1],task34bToken(300),'skill_2');
        $id=array_key_first($pdo->actions);$frozen=SkillCatalogue::decodeSnapshot($pdo->actions[$id]['snapshot_skill_definition']);
        assertFloatValue(75.0,(float)$frozen['intrinsic_status']['chance_percent'],'Shared Task30 chance captured at start.');
        // Simulate a valid historical frozen zero-chance rule. Today's function
        // would return75; pending resolution must consume the stored percent.
        $frozen['intrinsic_status']['chance_percent']=0;
        $pdo->actions[$id]['snapshot_skill_definition']=SkillCatalogue::encodeSnapshot($frozen);
        $clock->set(new DateTimeImmutable('2026-09-01 12:00:02.000000',new DateTimeZone('UTC')));
        $combat->state(7,42);
        assertSameValue([],$pdo->statuses,'No current-rule proc replaces frozen chance.');
        assertSameValue(980,$pdo->encounters[91]['enemy_current_hp'],'Direct damage still frozen.');
    },
    'Task34B production authority rejects inactive crossclass busy allowance and time-invalid canonical requests' => function (): void {
        [$pdo,$clock,$combat,$repo,$skills,$keys]=task34bCombat();
        $repo->definitions[0]['is_active']=0;
        $inactive=new SkillService($repo,$repo->catalogue());
        $strict=CombatBootstrap::serviceForRepository(new CombatRepository($pdo),$clock,null,new Task6SequenceRandomSource([]),null,$inactive);
        task34aAssertRejected(fn()=>$strict->startPlayerAction(7,42,$keys[0],task34bToken(300),'skill_1'),DomainException::class);
        $repo->definitions[0]['is_active']=1;$pdo->characters[42]['class_key']='mage';
        task34aAssertRejected(fn()=>$combat->startPlayerAction(7,42,$keys[0],task34bToken(300),'skill_1'),DomainException::class);
        $pdo->characters[42]['class_key']='warrior';
        $pdo->encounters[91]['player_actions_remaining']=0;
        task34aAssertRejected(fn()=>$combat->startPlayerAction(7,42,$keys[0],task34bToken(300),'skill_1'),DomainException::class);
        $pdo->encounters[91]['player_actions_remaining']=5;$pdo->encounters[91]['timeline_elapsed_ms']=9500;
        task34aAssertRejected(fn()=>$combat->startPlayerAction(7,42,$keys[0],task34bToken(300),'skill_1'),DomainException::class);
        $pdo->encounters[91]['timeline_elapsed_ms']=0;
        $combat->startPlayerAction(7,42,$keys[0],task34bToken(300),'skill_1');
        task34aAssertRejected(fn()=>$combat->startPlayerAction(7,42,$keys[1],task34bToken(301),'skill_2'),DomainException::class);
    },
    'Task34B canonical killing skill preserves34A victory rewards and idempotent progression' => function (): void {
        [$pdo,$clock,$combat,,,$keys]=task34bCombat();$pdo->encounters[91]['enemy_current_hp']=1;
        $combat->startPlayerAction(7,42,$keys[0],task34bToken(300),'skill_1');
        $clock->set(new DateTimeImmutable('2026-09-01 12:00:02.000000',new DateTimeZone('UTC')));
        $state=$combat->state(7,42);
        assertSameValue('victory_loot',$state['status'],'Victory.');
        assertSameValue(1640,$pdo->characters[42]['experience'],'Exactly40 XP.');assertSameValue(5,$pdo->characters[42]['level'],'34A curve.');assertSameValue(25,$pdo->characters[42]['gold'],'Gold unchanged.');
        assertSameValue(4,$state['skill_state']['points']['earned'],'Authoritative skill projection reflects34A level.');
        $saved=[$pdo->characters,$pdo->events,$pdo->actions];
        $replay=$combat->startPlayerAction(7,42,$keys[0],task34bToken(300),'skill_1');
        assertSameValue($saved,[$pdo->characters,$pdo->events,$pdo->actions],'No duplicate rewards or points.');
        assertSameValue($state['loot_phase']['rewards'],$replay['loot_phase']['rewards'],'Immutable34A receipt.');
    },
    'Task34B real DB-authority adapter preserves unresolved legacy Flame Strike and denies fresh prototype requests' => function (): void {
        [$pdo,$clock,$combat,$repo]=task34bCombat('mage',false);
        $snapshot=(new PrototypeCombatEquipmentProvider(CombatDefinitionRegistry::fromDefaultConfig()))->offensiveSnapshot($pdo->characters[42],'prototype_flame_strike');
        $pdo->actions[60]=array_replace(task6PendingAction(),$snapshot,['id'=>60,'encounter_id'=>91,'action_kind'=>'skill','definition_key'=>'prototype_flame_strike','request_token'=>task34bToken(300),'started_timeline_ms'=>0,'resolves_timeline_ms'=>1500,'cooldown_ready_timeline_ms'=>5000,'snapshot_skill_definition'=>null]);
        $clock->set(new DateTimeImmutable('2026-09-01 12:00:02.000000',new DateTimeZone('UTC')));
        $state=$combat->state(7,42);
        assertSameValue(976,$pdo->encounters[91]['enemy_current_hp'],'Legacy fire24 unchanged.');
        assertSameValue('prototype_burning',$state['active_effects'][0]['key'],'Original cosmetic effect identity.');
        assertSameValue([],$pdo->statuses,'Legacy cosmetic Burning is NOT new damaging Burn.');
        $old=[$pdo->actions,$pdo->events];$combat->startPlayerAction(7,42,'prototype_flame_strike',task34bToken(300));
        assertSameValue($old,[$pdo->actions,$pdo->events],'Legacy token replay retained.');
        task34aAssertRejected(fn()=>$combat->startPlayerAction(7,42,'prototype_flame_strike',task34bToken(301)),DomainException::class);
    },
    'Task34B persistent equipment cannot amplify skill damage elements or intrinsic status and cast rate stays shared' => function (): void {
        [$rows,$edges]=task34bCatalogueRows();$catalogue=new SkillCatalogue($rows,$edges);
        $defs=CombatDefinitionRegistry::withSkillCatalogue($catalogue);
        $equipment=new class {
            public function lockEquippedItems(int $id): array {
                $affixes=[];
                foreach (['strength','fire_damage','lightning_damage','cold_damage','poison_damage','bleed_damage','burn_damage','freeze_damage','shock_damage','life_on_hit'] as $key) {
                    $affixes[]=['modifier_type'=>$key,'modifier_operation'=>'flat','rolled_value'=>999];
                }
                $affixes[]=['modifier_type'=>'cast_rate_bp','modifier_operation'=>'additive_percent','rolled_value'=>10000];
                return [['id'=>1,'definition_key'=>'basic_sword','snapshot_equipment_slot'=>'weapon','snapshot_damage_min'=>999,'snapshot_damage_max'=>999,'snapshot_damage_type'=>'physical','affixes'=>$affixes]];
            }
        };
        $provider=new PersistentCombatEquipmentProvider($equipment,$defs,new EquipmentStatAggregator());
        $character=(new FakeCombatPdo())->characters[42];
        foreach ($catalogue->all() as $row) {
            $snapshot=$provider->offensiveSnapshot($character,$row['skill_key']);
            assertSameValue($row['base_damage'],$snapshot['snapshot_base_damage'],'No Strength/item amplification.');
            foreach (['fire','lightning','cold','poison'] as $type) { assertSameValue(0,$snapshot['snapshot_'.$type.'_damage'],'No elemental item addition.'); }
            foreach (SkillCatalogue::STATUS_KEYS as $key) { assertSameValue(0,$snapshot['snapshot_'.$key.'_status_damage'],'No equipment status addition.'); }
            assertSameValue(null,$snapshot['snapshot_weapon_item_id'],'No weapon identity.');
            assertSameValue((int)round($row['duration_ms']/2),$provider->effectiveDurationMs($character,$row['skill_key'],$row['duration_ms']),'Shared cast-rate duration.');
        }
    },
    'Task34B valid Block reaction and replay remain unchanged with all four persisted skill slots' => function (): void {
        [$pdo,,$combat,$repo,,$keys]=task34bCombat();
        $pdo->encounters[91]['timeline_elapsed_ms']=500;$pdo->encounters[91]['player_actions_remaining']=0;
        $pdo->actions[71]=task7EnemyAction();
        $allocations=[$repo->learned,$repo->assignments];
        $state=$combat->attemptBlock(7,42,71,str_repeat('b',64),task34bToken(300));
        assertSameValue($keys,array_column($state['player_skills'],'key'),'Skill slots survive reaction projection.');
        assertSameValue(0,$state['turn']['player_actions_remaining'],'Block consumes no Action.');
        assertSameValue(1,count(array_filter($pdo->actions,static fn($a)=>$a['action_kind']==='block')),'One Block receipt.');
        $saved=[$pdo->characters,$pdo->actions,$pdo->events];
        $combat->attemptBlock(7,42,71,str_repeat('b',64),task34bToken(300));
        assertSameValue($saved,[$pdo->characters,$pdo->actions,$pdo->events],'Block replay remains once only.');
        assertSameValue($allocations,[$repo->learned,$repo->assignments],'No skill allocation mutation by Block.');
    },
    'Task34B weapon potion block paths remain existing engine with database skill authority' => function (): void {
        [$pdo,,$combat]=task34bCombat();
        $state=$combat->startPlayerAction(7,42,'prototype_weapon_attack',task34bToken(300));
        assertSameValue(4,$state['turn']['player_actions_remaining'],'Weapon normal Action.');
        $pdo->encounters[91]['potion_charges_remaining']=1;$pdo->characters[42]['current_hp']=100;
        $state=$combat->usePotion(7,42,task34bToken(301));
        assertSameValue(0,$state['potion']['charges_remaining'],'Existing potion.');
        task34aAssertRejected(fn()=>$combat->attemptBlock(7,42,999,str_repeat('b',64),task34bToken(302)),DomainException::class);
    },
];
return $tests;

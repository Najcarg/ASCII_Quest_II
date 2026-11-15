<?php
declare(strict_types=1);

function task29Service(FakeCombatPdo $pdo, Task4MutableCombatClock $clock, CombatEquipmentProvider $provider, CombatRandomSource $random, ?array $config = null): CombatService
{
    $repo = new CombatRepository($pdo);
    $definitions = $config === null ? CombatDefinitionRegistry::fromDefaultConfig() : new CombatDefinitionRegistry($config);
    $turn = new CombatTurnEngine($definitions->turnDurationSeconds());
    $resolver = new CombatActionResolver($repo, $definitions, $provider, new PrototypeEnemyDefenseResolver($random),
        new PrototypeBlockResolver(new PrototypeChampionDamageResolver(), $random, $definitions->playerReaction('basic_block')), $random);
    $sync = new CombatSynchronizer($clock, $turn, $definitions->maxDisconnectedCatchupSeconds(), null, $repo, $definitions, new CaveBrutePolicy($turn), $resolver, $random);
    return new CombatService($repo, $definitions, $clock, $sync, $provider);
}

// Test already-applied durable effects at small potencies, which cannot proc on
// integer 1..100 draws under the existing p/100 chance formula. No SQL is run.
function task29Seed(FakeCombatPdo $pdo, string $key, int $potency, int $now = 3000, int $source = 70): void
{
    $pdo->actions[$source] = ['id'=>$source,'encounter_id'=>91,'state'=>'resolved','actor'=>'player','action_kind'=>'weapon',
        'definition_key'=>'prototype_weapon_attack','started_timeline_ms'=>$now-1000,'resolves_timeline_ms'=>$now,
        'cooldown_ready_timeline_ms'=>$now, 'completed_timeline_ms'=>$now];
    $repo = new CombatRepository($pdo); $repo->beginTransaction();
    try {
        $repo->lockOwnedCharacter(7,42); $repo->lockActiveEncounter(42);
        $repo->applyStatusEffect(91,$source,$key,$potency,$now);
        $repo->commit();
    } catch (Throwable $error) { $repo->rollBack(); throw $error; }
}

$tests = [
    'Task29 poison and burn resist independently bleed ignores resistance and DoTs ignore Shock' => function (): void {
        foreach (['poison','burn','bleed'] as $key) {
            foreach ([0,50,100] as $resistance) {
                [$pdo,$clock,,$provider] = task29Fixture();
                task29Seed($pdo,$key,7);
                task29Seed($pdo,'shock',50,3000,71);
                $config = task6CombatConfig();
                $config['prototype_balance']['enemies']['cave_brute']['server_only']['resistances'] = array_fill_keys(['fire','lightning','cold','poison'],$resistance);
                $random = new Task6SequenceRandomSource([]);
                $service = task29Service($pdo,$clock,$provider,$random,$config);
                task29At($clock,4); $state = $service->state(7,42);
                $tick = $key === 'bleed' ? 7 : (int) round(7*(1-$resistance/100));
                assertSameValue([100000-4*$tick,4,0,[],145],[$state['enemy']['current_hp'],count(task29TickEvents($pdo)),$random->integerCalls,task29Active($pdo),$pdo->characters[42]['current_hp']],'Resistance with project rounding, exactly four including zero-damage ticks.');
                assertSameValue(array_fill(0,4,ucfirst($key).' deals '.$tick.' damage.'),array_column(task29TickEvents($pdo),'message'),'Concise persisted tick events.');
            }
        }
    },
    'Task29 large catchup is chronological ticks before tied actions and existing action id order' => function (): void {
        [$pdo,$clock,,$provider] = task29Fixture();
        task29Seed($pdo,'bleed',7);
        $pdo->actions[1] = task6PendingAction(['id'=>1,'encounter_id'=>91,'started_timeline_ms'=>3000,'resolves_timeline_ms'=>4000,'snapshot_base_damage'=>5]);
        $pdo->actions[2] = task6EnemyAction('smash',['id'=>2,'encounter_id'=>91,'started_timeline_ms'=>3000,'resolves_timeline_ms'=>4000]);
        $random = new Task6SequenceRandomSource([1,100,100,100]);
        $service = task29Service($pdo,$clock,$provider,$random);
        task29At($clock,5); $state = $service->state(7,42);
        assertSameValue(['status_tick_bleed','player_attack_hit','enemy_attack_hit','status_tick_bleed','status_tick_bleed','status_tick_bleed'],array_column(array_values($pdo->events),'event_type'),'Interleave no action-first whole-window batching.');
        assertSameValue([99967,4,'resolved','resolved',8000],[$state['enemy']['current_hp'],$random->integerCalls,$pdo->actions[1]['state'],$pdo->actions[2]['state'],$state['timeline']['elapsed_ms']],'All due work once.');
        $before = [$pdo->events,$pdo->statuses,$pdo->actions,$pdo->characters];
        task29Service($pdo,$clock,$provider,$random)->state(7,42);
        assertSameValue($before,[$pdo->events,$pdo->statuses,$pdo->actions,$pdo->characters],'Fresh service durable replay.');
    },
    'Task29 Freeze20 slows newly started enemy actions twenty percent while cooldown stays base' => function (): void {
        foreach ([[20,2400],[50,3000],[200,3000]] as [$potency,$duration]) {
            [$pdo,$clock,,$provider] = task29Fixture();
            task29Seed($pdo,'freeze',$potency);
            $pdo->encounters[91]['next_enemy_decision_timeline_ms'] = 3000;
            $random = new Task6SequenceRandomSource([100,100]);
            $service = task29Service($pdo,$clock,$provider,$random);
            $state = $service->state(7,42);
            $enemy = $pdo->actions[1];
            assertSameValue(['fire_slam',3000,3000+$duration,9000],[$enemy['definition_key'],$enemy['started_timeline_ms'],$enemy['resolves_timeline_ms'],$enemy['cooldown_ready_timeline_ms']],'Freeze clamps50 and leaves base cooldown.');
            $before = [$pdo->actions,$pdo->statuses];
            $fresh = task29Service($pdo,$clock,$provider,$random); $fresh->state(7,42);
            assertSameValue([$before,2],[[$pdo->actions,$pdo->statuses],$random->integerCalls],'Refresh stable pending duration and prompt draws.');
            assertSameValue([['key'=>'freeze','potency'=>$potency,'remaining_ms'=>4000]],$state['enemy']['status_effects'],'Server-owned status projection.');
        }
    },
    'Task29 Freeze does not alter pending actions expires before starts and fit checks use slowed duration' => function (): void {
        [$pdo,$clock,,$provider] = task29Fixture();
        $pdo->actions[3] = task6EnemyAction('fire_slam',['id'=>3,'encounter_id'=>91,'started_timeline_ms'=>2000,'resolves_timeline_ms'=>4000,'cooldown_ready_timeline_ms'=>11000]);
        task29Seed($pdo,'freeze',20);
        $pdo->encounters[91]['next_enemy_decision_timeline_ms'] = 4000;
        $random = new Task6SequenceRandomSource([100,100,100]);
        $service = task29Service($pdo,$clock,$provider,$random);
        $pending = $pdo->actions[3];
        $service->state(7,42);
        assertSameValue($pending,$pdo->actions[3],'Pending never resized.');
        task29At($clock,1); $service->state(7,42);
        assertSameValue([4000,5800],[$pdo->actions[1]['started_timeline_ms'],$pdo->actions[1]['resolves_timeline_ms']],'Future Smash at4seconds has1.8second duration.');
        // A frozen action that would cross Turn end must wait, not throw on start.
        [$pdo,$clock,,$provider] = task29Fixture();
        task29Seed($pdo,'freeze',50,6500);
        $pdo->encounters[91]['timeline_elapsed_ms'] = 6500;
        $pdo->encounters[91]['next_enemy_decision_timeline_ms'] = 8000;
        task29At($clock,1.5);
        $random = new Task6SequenceRandomSource([]);
        $service = task29Service($pdo,$clock,$provider,$random);
        $service->state(7,42);
        assertSameValue(1,count($pdo->actions),'No frozen action fits at8seconds, although base Smash would fit.');
        // Already expired Freeze cannot slow actions started at its expiry.
        [$pdo,$clock,,$provider] = task29Fixture(); task29Seed($pdo,'freeze',20);
        $pdo->encounters[91]['next_enemy_decision_timeline_ms'] = 7000;
        $random = new Task6SequenceRandomSource([100,100]);
        $service = task29Service($pdo,$clock,$provider,$random);
        task29At($clock,4); $service->state(7,42);
        assertSameValue([7000,9000,[]],[$pdo->actions[1]['started_timeline_ms'],$pdo->actions[1]['resolves_timeline_ms'],task29Active($pdo)],'Expiry exclusive for amplification/slow at same cursor.');
    },
    'Task29 Freeze and Shock refresh replace new potency duration without rewriting pending actions' => function (): void {
        foreach (['freeze','shock'] as $key) {
            [$pdo,$clock,,$provider] = task29Fixture();
            task29Seed($pdo,$key,50);
            task29Seed($pdo,$key,20,3500,71);
            $active = task29Active($pdo);
            assertSameValue([2,1,20,3500,null,7500],[count($pdo->statuses),count($active),$active[0]['potency'],$active[0]['applied_timeline_ms'],$active[0]['next_tick_timeline_ms'],$active[0]['expires_timeline_ms']],'No additive potency or tick scheduling for CC.');
            $random = new Task6SequenceRandomSource([]); $service = task29Service($pdo,$clock,$provider,$random);
            task29At($clock,4); $state = $service->state(7,42);
            assertSameValue([['key'=>$key,'potency'=>20,'remaining_ms'=>500]],$state['enemy']['status_effects'],'Refreshed duration, not old expiry.');
            task29At($clock,4.5); $state = task29Service($pdo,$clock,$provider,$random)->state(7,42);
            assertSameValue([[],[],100000],[$state['enemy']['status_effects'],task29TickEvents($pdo),$state['enemy']['current_hp']],'Expiry no damage/events.');
        }
    },
    'Task29 Shock20 amplifies resisted sum then one Block then one critical with caps' => function (): void {
        foreach ([[20,[1,100,100],18],[20,[1,20,100],9],[20,[1,20,1],18],[50,[1,100,100],23],[200,[1,100,100],23]] as [$potency,$rolls,$expected]) {
            [$pdo,$clock,,$provider] = task29Fixture();
            task29Seed($pdo,'shock',$potency);
            $pdo->actions[1] = task6PendingAction(array_replace(['id'=>1,'encounter_id'=>91,'resolves_timeline_ms'=>4000],task28Offense(['snapshot_base_damage'=>10,'snapshot_fire_damage'=>10,'snapshot_critical_chance'=>50,'snapshot_critical_damage'=>100])));
            $config = task6CombatConfig(); $config['prototype_balance']['enemies']['cave_brute']['server_only']['resistances']['fire'] = 50;
            $random = new Task6SequenceRandomSource($rolls); $service = task29Service($pdo,$clock,$provider,$random,$config);
            task29At($clock,1); $service->state(7,42);
            assertSameValue([$expected,3],[$pdo->actions[1]['resolved_damage'],$random->integerCalls],'Post resistance sum15 before Block/Crit, clamp50.');
            $blocked = $rolls[1] === 20;
            $sum = (int) round(15*(1+min(50,$potency)/100));
            assertSameValue(5+($blocked ? (int) round($sum*0.5) : 0),$pdo->actions[1]['prevented_damage'],'Prevention remains actual resistance plus actual Block, not reduced by amplification.');
            assertSameValue([],task29TickEvents($pdo),'Shock never ticks.');
        }
    },
    'Task29 newly applied Shock does not amplify its attack expiry excludes later direct and refresh has new potency' => function (): void {
        [$pdo,$clock,,$provider,$random,$service] = task29Resolve(['shock'=>2000],[1,100,100,20]);
        assertSameValue(5,$pdo->actions[1]['resolved_damage'],'Own attack not amplified.');
        foreach ([[20,4000,7000,6],[20,3000,7000,5]] as [$potency,$applied,$resolve,$expected]) {
            [$pdo,$clock,,$provider] = task29Fixture(); task29Seed($pdo,'shock',$potency,$applied);
            $pdo->actions[1] = task6PendingAction(['id'=>1,'encounter_id'=>91,'started_timeline_ms'=>6000,'resolves_timeline_ms'=>$resolve,'snapshot_base_damage'=>5]);
            $random = new Task6SequenceRandomSource([1,100,100]); $service = task29Service($pdo,$clock,$provider,$random);
            task29At($clock,4); $service->state(7,42);
            assertSameValue($expected,$pdo->actions[1]['resolved_damage'],'Existing active only; expiry exact.');
        }
        [$pdo,$clock,,$provider] = task29Fixture(); task29Seed($pdo,'shock',50); task29Seed($pdo,'shock',20,3500,71);
        $pdo->actions[1] = task6PendingAction(['id'=>1,'encounter_id'=>91,'resolves_timeline_ms'=>4000,'snapshot_base_damage'=>10]);
        $random = new Task6SequenceRandomSource([1,100,100]); $service = task29Service($pdo,$clock,$provider,$random);
        task29At($clock,1); $service->state(7,42);
        assertSameValue(12,$pdo->actions[1]['resolved_damage'],'New potency20 not old50 nor summed70.');
    },
    'Task29 direct poison remains alongside poison status and no per-component status rolls' => function (): void {
        [$pdo,$clock,,,$random,$service] = task29Resolve(['poison'=>100],[1,100,100,1]);
        assertSameValue([105,100,4],[$pdo->actions[1]['resolved_damage'],task29Active($pdo)[0]['potency'],$random->integerCalls],'Task28 poison direct preserved and independent durable poison.');
        task29At($clock,2); $service->state(7,42);
        assertSameValue([99795,1,4],[$pdo->encounters[91]['enemy_current_hp'],count(task29TickEvents($pdo)),$random->integerCalls],'Direct plus one poison tick.');
    },
    'Task29 status application event failure and synchronization failure roll back every mutation' => function (): void {
        foreach (['application','synchronization'] as $failure) {
            [$pdo,$clock,,$provider,$random,$service] = task29Fixture(['bleed'=>2000],[1,100,100,20,1,100,100,20]);
            $service->startPlayerAction(7,42,'prototype_weapon_attack',equipmentToken(2900));
            $saved = [$pdo->characters,$pdo->encounters,$pdo->actions,$pdo->events,$pdo->statuses];
            $pdo->failEventInsert = $failure === 'application'; $pdo->failEventType = 'status_applied_bleed';
            $pdo->failSynchronizationUpdate = $failure === 'synchronization';
            task29At($clock,1); $threw = false;
            try { $service->state(7,42); } catch (RuntimeException) { $threw = true; }
            assertSameValue([true,$saved],[$threw,[$pdo->characters,$pdo->encounters,$pdo->actions,$pdo->events,$pdo->statuses]],'Atomic action HP events and schedule rollback.');
            $pdo->failEventInsert = $pdo->failSynchronizationUpdate = false;
            $service->state(7,42);
            assertSameValue([1,2,'resolved'],[count($pdo->statuses),count($pdo->events),$pdo->actions[1]['state']],'Retry commits one application only.');
        }
    },
    'Task29 status repository enforces aggregate locks source ownership and durable active uniqueness' => function (): void {
        [$pdo] = task29Fixture(); $repo = new CombatRepository($pdo);
        assertCombatRepositoryRejected(fn () => $repo->applyStatusEffect(91,70,'bleed',7,3000),'No unlocked mutation.');
        $repo->beginTransaction(); $repo->lockOwnedCharacter(7,42); $repo->lockActiveEncounter(42);
        task6AssertRejected(fn () => $repo->applyStatusEffect(91,999,'bleed',7,3000),'No foreign source.');
        $repo->rollBack();
        task29Seed($pdo,'bleed',7); task29Seed($pdo,'bleed',8,3500,71);
        $repo->beginTransaction(); $repo->lockOwnedCharacter(7,42); $repo->lockActiveEncounter(42);
        $before = $pdo->statuses;
        assertSameValue(false,$repo->applyStatusEffect(91,70,'bleed',7,3000),'Old expired source cannot refresh again.');
        assertSameValue($before,$pdo->statuses,'Replay cannot retire new active source.');
        $repo->commit();
    },
];
foreach (CombatStatusEffects::KEYS as $key) {
    $tests["Task29 {$key} durable replay no rerolls and resolved or cancelled cannot resolve"] = function () use ($key): void {
        [$pdo,$clock,,$provider,$random,$service] = task29Resolve([$key=>2000],[1,100,100,20]);
        $before = [$pdo->statuses,$pdo->events,$pdo->actions,$pdo->characters];
        $fresh = task29Service($pdo,$clock,$provider,$random);
        $fresh->state(7,42); $fresh->startPlayerAction(7,42,'prototype_weapon_attack',equipmentToken(2900));
        assertSameValue([$before,4],[[$pdo->statuses,$pdo->events,$pdo->actions,$pdo->characters],$random->integerCalls],'Two-tab durable replay no random.');
        $repo = new CombatRepository($pdo); $repo->beginTransaction();
        $character = $repo->lockOwnedCharacter(7,42); $encounter = $repo->lockActiveEncounter(42);
        $resolver = new CombatActionResolver($repo,CombatDefinitionRegistry::fromDefaultConfig(),$provider,new PrototypeEnemyDefenseResolver($random),new Task7RecordingBlockResolver(),$random);
        foreach (['resolved','cancelled'] as $state) {
            $action = $pdo->actions[1]; $action['state'] = $state;
            task6AssertRejected(fn () => $resolver->resolvePending($encounter,$character,$action),'No historical action resolution.');
        }
        assertSameValue(4,$random->integerCalls,'No historical rerolls.');
        $repo->rollBack();
    };
}
return $tests;

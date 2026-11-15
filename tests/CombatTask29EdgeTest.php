<?php
declare(strict_types=1);

return [
    'Task29 all-five immutable application uses action-start values despite current equipment changes' => function (): void {
        [$pdo,$clock,$reader,,$random,$service] = task29Fixture(array_fill_keys(CombatStatusEffects::KEYS,2000),[1,100,100,20,20,20,20,20]);
        $service->startPlayerAction(7,42,'prototype_weapon_attack',equipmentToken(2910));
        $snapshot = array_intersect_key($pdo->actions[1],task29Snapshots());
        $reader->items = [task28Ring(['poison_damage'=>9000,'bleed_damage'=>9000,'burn_damage'=>9000,'freeze_damage'=>9000,'shock_damage'=>9000])];
        task29At($clock,1); $service->state(7,42);
        assertSameValue([array_fill(0,5,2000),$snapshot,8],[array_column(task29Active($pdo),'potency'),array_intersect_key($pdo->actions[1],task29Snapshots()),$random->integerCalls],'Current gear cannot supply chances or potency.');
    },
    'Task29 validates each status snapshot rejects missing negative fractional and nonnumeric while allowing PDO integer strings' => function (): void {
        foreach (CombatStatusEffects::KEYS as $key) {
            foreach ([-1,1.5,'bad','1.5','missing','2000'] as $value) {
                [$pdo,$clock,,,,$service] = task29Fixture([], [1,100,100,20]);
                $service->startPlayerAction(7,42,'prototype_weapon_attack',equipmentToken(2911));
                $column = 'snapshot_'.$key.'_status_damage';
                if ($value === 'missing') { unset($pdo->actions[1][$column]); }
                else { $pdo->actions[1][$column] = $value; }
                $before = [$pdo->actions,$pdo->events,$pdo->statuses,$pdo->encounters,$pdo->characters];
                task29At($clock,1);
                if ($value === '2000') {
                    $service->state(7,42);
                    assertSameValue([2000,'2000'],[task29Active($pdo)[0]['potency'],$pdo->actions[1][$column]],'PDO integer strings accepted without rewriting snapshots.');
                } else {
                    task6AssertRejected(fn () => $service->state(7,42),'Malformed snapshot rejected.');
                    assertSameValue($before,[$pdo->actions,$pdo->events,$pdo->statuses,$pdo->encounters,$pdo->characters],'Action resolution and HP roll back.');
                }
            }
        }
    },
    'Task29 nonoffensive commands and skills persist zero status snapshots even if equipment has all statuses' => function (): void {
        [$pdo,$clock,,,,$service] = task29Fixture(array_fill_keys(CombatStatusEffects::KEYS,2000),[1,100,100]);
        $service->startPlayerAction(7,42,'prototype_flame_strike',equipmentToken(2912));
        assertSameValue(task29Snapshots(),array_intersect_key($pdo->actions[1],task29Snapshots()),'Skill explicit zeros.');
        // Defensive exclusion: even malformed nonzero skill snapshots cannot proc.
        foreach (task29Snapshots(array_fill_keys(CombatStatusEffects::KEYS,9000)) as $column=>$value) { $pdo->actions[1][$column] = $value; }
        task29At($clock,1.5); $service->state(7,42);
        assertSameValue([],task29Active($pdo),'Skills never read equipment status potency.');
        $repo = new CombatRepository($pdo); $repo->beginTransaction();
        $repo->lockOwnedCharacter(7,42); $repo->lockActiveEncounter(42);
        $potion = $repo->createResolvedPotionCommand(91,'prototype_health_potion',equipmentToken(2913),4500,5);
        $pdo->actions[71] = task6EnemyAction('smash',['id'=>71,'encounter_id'=>91]);
        $repo->lockEnemyActionForBlock(91,71);
        $block = $repo->createResolvedBlockCommand(91,71,'basic_block',equipmentToken(2914),4500);
        assertSameValue([task29Snapshots(),task29Snapshots()],[array_intersect_key($potion,task29Snapshots()),array_intersect_key($block,task29Snapshots())],'New player commands explicitly neutral.');
        $repo->rollBack();
    },
    'Task29 refresh at a due tick preserves old tied tick then resets to one future schedule' => function (): void {
        [$pdo,$clock,,$provider] = task29Fixture(); task29Seed($pdo,'bleed',7);
        $pdo->actions[1] = task6PendingAction(array_replace(['id'=>1,'encounter_id'=>91,'resolves_timeline_ms'=>4000,'snapshot_base_damage'=>5],task29Snapshots(['bleed'=>100])));
        $random = new Task6SequenceRandomSource([1,100,100,1]); $service = task29Service($pdo,$clock,$provider,$random);
        task29At($clock,1); $service->state(7,42);
        assertSameValue(['status_tick_bleed','player_attack_hit','status_applied_bleed'],array_column(array_values($pdo->events),'event_type'),'Tie tick before refreshed application.');
        assertSameValue([2,1,100,5000,8000],[count($pdo->statuses),count(task29Active($pdo)),task29Active($pdo)[0]['potency'],task29Active($pdo)[0]['next_tick_timeline_ms'],task29Active($pdo)[0]['expires_timeline_ms']],'Only new potency and schedule remain active.');
        task29At($clock,5); $service->state(7,42);
        assertSameValue([99588,5,[]],[$pdo->encounters[91]['enemy_current_hp'],count(task29TickEvents($pdo)),task29Active($pdo)],'One old tick plus exactly four new ticks.');
    },
    'Task29 Freeze refresh uses lower replacement potency for future actions while pending stays stable' => function (): void {
        [$pdo,$clock,,$provider] = task29Fixture(); task29Seed($pdo,'freeze',50);
        $pdo->actions[3] = task6EnemyAction('fire_slam',['id'=>3,'encounter_id'=>91,'started_timeline_ms'=>2000,'resolves_timeline_ms'=>4000,'cooldown_ready_timeline_ms'=>11000]);
        task29Seed($pdo,'freeze',20,3500,71);
        $before = $pdo->actions[3];
        $pdo->encounters[91]['next_enemy_decision_timeline_ms'] = 4000;
        $random = new Task6SequenceRandomSource([100,100,100]); $service = task29Service($pdo,$clock,$provider,$random);
        $service->state(7,42);
        assertSameValue($before,$pdo->actions[3],'Refresh never edits pending action.');
        task29At($clock,1); $service->state(7,42);
        assertSameValue([4000,5800,7000],[$pdo->actions[1]['started_timeline_ms'],$pdo->actions[1]['resolves_timeline_ms'],$pdo->actions[1]['cooldown_ready_timeline_ms']],'Replacement20 means1800ms Smash, not2250ms or2550ms.');
    },
    'Task29 partial catchup fresh services advance exactly one durable tick each without clock rounding drift' => function (): void {
        [$pdo,$clock,,$provider] = task29Fixture(); task29Seed($pdo,'bleed',7,3250);
        $random = new Task6SequenceRandomSource([]);
        foreach ([[1.249,0],[1.250,1],[2.249,1],[2.250,2],[3.250,3],[4.250,4]] as [$seconds,$count]) {
            task29At($clock,$seconds); $state = task29Service($pdo,$clock,$provider,$random)->state(7,42);
            assertSameValue([100000-7*$count,$count],[$state['enemy']['current_hp'],count(task29TickEvents($pdo))],'Fractional timeline cursor and durable schedule.');
        }
        assertSameValue([],task29Active($pdo),'Expires exactly applied+4000 after fourth tick.');
    },
    'Task29 same-cursor lethal ticks terminate before later statuses and terminal encounters do no work' => function (): void {
        [$pdo,$clock,,$provider] = task29Fixture(); task29Seed($pdo,'bleed',100); task29Seed($pdo,'burn',100,3000,71);
        $pdo->encounters[91]['enemy_current_hp'] = 50;
        $random = new Task6SequenceRandomSource([]); $service = task29Service($pdo,$clock,$provider,$random);
        task29At($clock,1); $state = $service->state(7,42);
        assertSameValue(['victory_loot',['status_tick_bleed','victory_rewards']],[$state['status'],array_column(array_values($pdo->events),'event_type')],'Status id tie order, terminal immediately.');
        $saved = [$pdo->statuses,$pdo->events,$pdo->characters,$pdo->actions];
        task29At($clock,5); task29Service($pdo,$clock,$provider,$random)->state(7,42);
        assertSameValue($saved,[$pdo->statuses,$pdo->events,$pdo->characters,$pdo->actions],'Terminal later refresh never processes retained schedules.');
    },
    'Task29 tick schedule HP and prior events roll back on late catchup persistence failure' => function (): void {
        [$pdo,$clock,,$provider] = task29Fixture(); task29Seed($pdo,'bleed',7);
        $random = new Task6SequenceRandomSource([]); $service = task29Service($pdo,$clock,$provider,$random);
        $before = [$pdo->statuses,$pdo->events,$pdo->characters,$pdo->encounters,$pdo->actions];
        $pdo->failSynchronizationUpdate = true; task29At($clock,4); $threw = false;
        try { $service->state(7,42); } catch (RuntimeException) { $threw = true; }
        assertSameValue([true,$before],[$threw,[$pdo->statuses,$pdo->events,$pdo->characters,$pdo->encounters,$pdo->actions]],'Four processed ticks all roll back on aggregate persistence failure.');
        $pdo->failSynchronizationUpdate = false; task29Service($pdo,$clock,$provider,$random)->state(7,42);
        assertSameValue([99972,4,[]],[$pdo->encounters[91]['enemy_current_hp'],count(task29TickEvents($pdo)),task29Active($pdo)],'Fresh retry commits four once.');
    },
];

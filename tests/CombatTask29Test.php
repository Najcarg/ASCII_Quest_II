<?php
declare(strict_types=1);

function task29Snapshots(array $values = []): array
{
    $result = [];
    foreach (['poison', 'bleed', 'burn', 'freeze', 'shock'] as $key) {
        $result['snapshot_' . $key . '_status_damage'] = $values[$key] ?? 0;
    }
    return $result;
}

function task29Fixture(array $values = [], array $rolls = [], array $character = []): array
{
    $fixture = task25Fixture([task28Ring(array_combine(array_map(static fn ($key) => $key . '_damage', array_keys($values)), array_values($values)))], $rolls, $character);
    $fixture[0]->encounters[91]['enemy_current_hp'] = 100000;
    $fixture[0]->encounters[91]['enemy_max_hp'] = 100000;
    return $fixture;
}

function task29At(Task4MutableCombatClock $clock, float $seconds): void
{
    $base = new DateTimeImmutable('2026-09-01 12:00:00.000000', new DateTimeZone('UTC'));
    $clock->set($base->modify('+' . (int) round($seconds * 1000000) . ' microseconds'));
}

function task29Active(FakeCombatPdo $pdo): array
{
    return array_values(array_filter($pdo->statuses, static fn ($row) => $row['state'] === 'active'));
}

function task29Resolve(array $values, array $rolls, string $action = 'prototype_weapon_attack', array $character = []): array
{
    $fixture = task29Fixture($values, $rolls, $character);
    [$pdo, $clock, , , , $service] = $fixture;
    $service->startPlayerAction(7, 42, $action, equipmentToken(2900));
    task26Advance($clock, $action === 'prototype_weapon_attack' ? 1 : 1.5);
    $service->state(7, 42);
    return $fixture;
}

function task29Encounters(FakeCombatPdo $pdo): array
{
    return array_map(static function ($row) { unset($row['version']); return $row; }, $pdo->encounters);
}

function task29TickEvents(FakeCombatPdo $pdo): array
{
    return array_values(array_filter($pdo->events, static fn ($row) => str_starts_with($row['event_type'], 'status_tick_')));
}

$tests = [
    'Task29 migration safety and exact five nullable snapshots with read-only inspection' => function (): void {
        $sql = equipmentMigrationSql('009_combat_status_effects.sql');
        foreach (["migration_id = '008_combat_elemental_snapshots'", "migration_id = '009_combat_status_effects'", 'if v_count <> 1 then', 'if v_count > 0 then', 'if v_count <> 0 then', 'information_schema.tables', 'information_schema.columns', 'signal sqlstate'] as $fragment) {
            assertEquipmentSqlContains($sql, $fragment, 'Preflight.');
        }
        preg_match_all('/add column (snapshot_\w+) int unsigned null/', $sql, $matches);
        assertSameValue(array_keys(task29Snapshots()), $matches[1], 'Exact five fields.');
        assertSameValue(1, substr_count($sql, 'insert into'), 'Only migration record.');
        assertSameValue(0, preg_match('/\b(update|delete|truncate)\s+(combat_actions|characters|character_items|item_definitions)\b/', $sql), 'No backfill or unrelated changes.');
        assertSameValue(true, strpos($sql, 'information_schema.tables') < strpos($sql, 'alter table'), 'Partial table preflight before mutation.');
        $verify = equipmentMigrationSql('009_combat_status_effects_verify.sql');
        foreach (array_keys(task29Snapshots()) as $column) { assertEquipmentSqlContains($verify, $column, 'Inspect snapshot.'); }
        foreach (['column_type', 'is_nullable', 'column_default', 'statistics', 'referential_constraints', 'check_constraints', '009_combat_status_effects'] as $fragment) { assertEquipmentSqlContains($verify, $fragment, 'Read-only schema verification.'); }
        assertSameValue(0, preg_match('/\b(insert|update|delete|alter|create|drop|truncate|call)\b/', $verify), 'Read only.');
    },
    'Task29 durable table shapes FK CHECK schedule indexes and two distinct uniqueness rules' => function (): void {
        $sql = equipmentMigrationSql('009_combat_status_effects.sql');
        foreach (['create table combat_status_effects', 'id int unsigned not null auto_increment', 'encounter_id int unsigned not null', 'source_action_id int unsigned not null', 'target_actor varchar(16) not null', 'status_key varchar(16) not null', 'potency int unsigned not null', 'applied_timeline_ms bigint unsigned not null', 'next_tick_timeline_ms bigint unsigned null', 'expires_timeline_ms bigint unsigned not null', 'state varchar(16) not null', 'datetime(6)', 'foreign key', 'references combat_encounters', 'references combat_actions', "check (target_actor = 'enemy')", "check (status_key in ('poison', 'bleed', 'burn', 'freeze', 'shock'))", "check (state in ('active', 'expired'))", 'unique key uq_status_source (source_action_id, target_actor, status_key)', 'unique key uq_status_active (encounter_id, target_actor, status_key, active_slot)', 'key idx_status_schedule', 'generated always'] as $fragment) {
            assertEquipmentSqlContains($sql, $fragment, 'Durable schema.');
        }
    },
    'Task29 snapshot authority aggregates multiple items skills zero and immutable pending legacy NULL' => function (): void {
        [$pdo, $clock, $reader, $provider, $random, $service] = task29Fixture(array_fill_keys(['poison', 'bleed', 'burn', 'freeze', 'shock'], 100), [1,100,100,100,100,100,100,100]);
        $reader->items[] = task28Ring(['poison_damage' => 20, 'bleed_damage' => 30, 'burn_damage' => 40, 'freeze_damage' => 50, 'shock_damage' => 60], 99);
        $expected = task29Snapshots(['poison'=>120,'bleed'=>130,'burn'=>140,'freeze'=>150,'shock'=>160]);
        assertSameValue($expected, array_intersect_key($provider->offensiveSnapshot($pdo->characters[42], 'prototype_weapon_attack'), $expected), 'Authoritative utility.');
        assertSameValue(task29Snapshots(), array_intersect_key($provider->offensiveSnapshot($pdo->characters[42], 'prototype_flame_strike'), $expected), 'Skill zeros.');
        $service->startPlayerAction(7,42,'prototype_weapon_attack',equipmentToken(2901));
        assertSameValue($expected, array_intersect_key($pdo->actions[1], $expected), 'Stored snapshot.');
        $reader->items = [];
        task26Advance($clock); $service->state(7,42);
        assertSameValue([$expected,8], [array_intersect_key($pdo->actions[1], $expected),$random->integerCalls], 'Immutable and five independent draws.');
        [$pdo,$clock,,,$random,$service] = task29Fixture(['burn'=>9000], [1,100,100]);
        $service->startPlayerAction(7,42,'prototype_weapon_attack',equipmentToken(2902));
        foreach (task29Snapshots() as $column => $_) { $pdo->actions[1][$column] = null; }
        task26Advance($clock); $service->state(7,42);
        assertSameValue([[],3,array_fill_keys(array_keys(task29Snapshots()),null)], [task29Active($pdo),$random->integerCalls,array_intersect_key($pdo->actions[1],task29Snapshots())], 'NULL zero no current substitution or backfill.');
    },
    'Task29 all new commands bind explicit zeros and resolution never updates snapshots' => function (): void {
        [$pdo,$clock,,,,$service] = task29Fixture([], [1,100,100]);
        $service->startPlayerAction(7,42,'prototype_weapon_attack',equipmentToken(2903));
        assertSameValue(task29Snapshots(),array_intersect_key($pdo->actions[1],task29Snapshots()),'Explicit unarmed zeros.');
        task26Advance($clock); $service->state(7,42);
        foreach ($pdo->preparedSql as $sql) {
            if (str_starts_with($sql,'INSERT INTO combat_actions')) {
                foreach (array_keys(task29Snapshots()) as $column) { assertSameValue(2,substr_count($sql,$column),'Bound exactly once.'); }
            }
            if (str_starts_with($sql,'UPDATE combat_actions')) { assertSameValue(false,str_contains($sql,'snapshot_'),'Immutable.'); }
        }
    },
    'Task29 independent mixed status rolls and replay through fresh service' => function (): void {
        [$pdo,$clock,,$provider,$random,$service] = task29Resolve(array_fill_keys(['poison','bleed','burn','freeze','shock'],2000),[1,100,100,20,21,20,21,20]);
        assertSameValue(['poison','burn','shock'],array_column(task29Active($pdo),'status_key'),'Independent chance, not highest/shared draw.');
        $saved = [$pdo->statuses,$pdo->actions,$pdo->events,task29Encounters($pdo),$pdo->characters];
        $fresh = CombatBootstrap::serviceForRepository(new CombatRepository($pdo),$clock,$provider,$random);
        $state = $fresh->state(7,42);
        $fresh->startPlayerAction(7,42,'prototype_weapon_attack',equipmentToken(2900));
        assertSameValue([$saved,8],[[$pdo->statuses,$pdo->actions,$pdo->events,task29Encounters($pdo),$pdo->characters],$random->integerCalls],'Fresh service / second tab replay identical.');
        assertSameValue([['key'=>'poison','potency'=>2000,'remaining_ms'=>4000],['key'=>'burn','potency'=>2000,'remaining_ms'=>4000],['key'=>'shock','potency'=>2000,'remaining_ms'=>4000]],$state['enemy']['status_effects'],'Allowlist only.');
    },
    'Task29 landed zero damage applies unscaled status after Life on Hit' => function (): void {
        [$pdo,$clock,$reader,,,$service] = task29Fixture(['bleed'=>2000], [1,20,1,20], ['strength'=>0]);
        $reader->items[] = task28Ring(['life_on_hit'=>5,'critical_chance'=>100,'critical_damage'=>100],99);
        $service->startPlayerAction(7,42,'prototype_weapon_attack',equipmentToken(2900));
        task26Advance($clock); $service->state(7,42);
        assertSameValue([0,150,2000],[$pdo->actions[1]['resolved_damage'],$pdo->characters[42]['current_hp'],task29Active($pdo)[0]['potency']],'Zero contact status unaffected by crit or Block.');
        assertSameValue(['player_attack_critical_block','player_life_on_hit','status_applied_bleed'],array_column(array_values($pdo->events),'event_type'),'Direct then LoH then application.');
    },
    'Task29 DoT four exact ticks no Block crit LoH or rolls and expiry at inclusive fourth tick' => function (): void {
        foreach (['poison','bleed','burn'] as $key) {
            [$pdo,$clock,,$provider,$random,$service] = task29Resolve([$key=>100],[1,100,100,1]);
            $pdo->encounters[91]['enemy_current_hp'] = 1000; $pdo->encounters[91]['enemy_max_hp'] = 1000;
            $hp = $pdo->characters[42]['current_hp'];
            task29At($clock,5); $state = $service->state(7,42);
            assertSameValue([600,4,4,[],$hp],[$state['enemy']['current_hp'],count(task29TickEvents($pdo)),$random->integerCalls,task29Active($pdo),$pdo->characters[42]['current_hp']],'Exactly +1/+2/+3/+4 seconds and no secondary effects.');
            $saved = [$pdo->statuses,$pdo->events,task29Encounters($pdo)];
            CombatBootstrap::serviceForRepository(new CombatRepository($pdo),$clock,$provider,$random)->state(7,42);
            assertSameValue($saved,[$pdo->statuses,$pdo->events,task29Encounters($pdo)],'Durable schedule replay.');
        }
    },
    'Task29 refresh expires old identity replaces potency resets tick schedule and is replay safe' => function (): void {
        [$pdo,$clock,,$provider,$random,$service] = task29Resolve(['bleed'=>200],[1,100,100,1]);
        $repo = new CombatRepository($pdo); $repo->beginTransaction();
        $repo->lockOwnedCharacter(7,42); $repo->lockActiveEncounter(42);
        $action = $pdo->actions[1]; $action['id'] = 99; $pdo->actions[99] = $action;
        $repo->applyStatusEffect(91,99,'bleed',7,4500);
        $repo->applyStatusEffect(91,99,'bleed',7,4500);
        $repo->commit();
        assertSameValue([2,1,7,4500,5500,8500],[count($pdo->statuses),count(task29Active($pdo)),task29Active($pdo)[0]['potency'],task29Active($pdo)[0]['applied_timeline_ms'],task29Active($pdo)[0]['next_tick_timeline_ms'],task29Active($pdo)[0]['expires_timeline_ms']],'New row preserves source identity and one active instance.');
        $pdo->encounters[91]['enemy_current_hp'] = 100; $pdo->encounters[91]['enemy_max_hp'] = 100;
        task29At($clock,5.5); $service->state(7,42);
        assertSameValue([72,4,[]],[$pdo->encounters[91]['enemy_current_hp'],count(task29TickEvents($pdo)),task29Active($pdo)],'Old tick schedule never duplicates.');
    },
    'Task29 DoT kill wins before tied enemy action cancels action rewards exactly once' => function (): void {
        [$pdo,$clock,,$provider,$random,$service] = task29Resolve(['bleed'=>100],[1,100,100,1]);
        $pdo->encounters[91]['enemy_current_hp'] = 73;
        $pdo->actions[90] = task6EnemyAction('smash',['id'=>90,'encounter_id'=>91,'resolves_timeline_ms'=>5000]);
        task29At($clock,4); $state = $service->state(7,42);
        assertSameValue(['victory_loot',1,'cancelled',145],[$state['status'],count(task29TickEvents($pdo)),$pdo->actions[90]['state'],$pdo->characters[42]['current_hp']],'Tick before action, terminal stops remaining ticks.');
        $saved = [$pdo->characters,$pdo->events,$pdo->statuses,task29Encounters($pdo)];
        CombatBootstrap::serviceForRepository(new CombatRepository($pdo),$clock,$provider,$random)->state(7,42);
        assertSameValue($saved,[$pdo->characters,$pdo->events,$pdo->statuses,task29Encounters($pdo)],'Victory/rewards once.');
    },
    'Task29 tick transaction rollback restores HP schedule events and terminal rewards' => function (): void {
        foreach (['status_tick_bleed','victory_rewards'] as $failure) {
            [$pdo,$clock,,,,$service] = task29Resolve(['bleed'=>100],[1,100,100,1]);
            $pdo->encounters[91]['enemy_current_hp'] = 73;
            $before = [$pdo->characters,$pdo->encounters,$pdo->actions,$pdo->events,$pdo->statuses];
            $pdo->failEventType = $failure; $pdo->failEventInsert = true;
            task29At($clock,2);
            $threw = false;
            try { $service->state(7,42); } catch (RuntimeException) { $threw = true; }
            assertSameValue(true, $threw, 'Injected event failure rolls back tick.');
            assertSameValue($before,[$pdo->characters,$pdo->encounters,$pdo->actions,$pdo->events,$pdo->statuses],'All durable state rollback.');
            $pdo->failEventType = null; $pdo->failEventInsert = false; $service->state(7,42);
            assertSameValue(['victory_loot',1],[$pdo->encounters[91]['status'],count(task29TickEvents($pdo))],'Retry exactly once.');
        }
    },
];
foreach (['poison','bleed','burn','freeze','shock'] as $key) {
    $tests["Task29 {$key} zero skips chance and inclusive boundary independent roll"] = function () use ($key): void {
        foreach ([[0,[],false,3],[2000,[20],true,4],[2000,[21],false,4],[9001,[90],true,4],[9001,[91],false,4],[1,[1],false,4]] as [$potency,$rolls,$active,$calls]) {
            [$pdo,,,,$random] = task29Resolve([$key=>$potency],array_merge([1,100,100],$rolls));
            assertSameValue([$active,$calls],[count(task29Active($pdo))===1,$random->integerCalls],'Own chance = min(90,p/100), integer draw <= fractional chance.');
        }
    };
    $tests["Task29 {$key} HIT CRITICAL BLOCK contact once MISS and skills never roll"] = function () use ($key): void {
        foreach ([[[1,100,100,20],'prototype_weapon_attack',true,4],[[1,20,100,20],'prototype_weapon_attack',true,4],[[1,100,1,20],'prototype_weapon_attack',true,4],[[66],'prototype_weapon_attack',false,1],[[1,100,100],'prototype_flame_strike',false,3]] as [$rolls,$action,$active,$calls]) {
            [$pdo,,,,$random] = task29Resolve([$key=>2000],$rolls,$action);
            assertSameValue([$active,$calls],[count(task29Active($pdo))===1,$random->integerCalls],'One roll per status only on normal contact.');
        }
    };
}
return $tests;

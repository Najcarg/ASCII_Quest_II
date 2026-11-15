<?php
declare(strict_types=1);

function task28Bonus(array $values = []): array
{
    $result = [];
    foreach (['fire', 'lightning', 'cold', 'poison'] as $type) {
        $result['snapshot_' . $type . '_damage'] = $values[$type] ?? 0;
    }
    return $result;
}

function task28Offense(array $changes = []): array
{
    return array_replace(task25Offense(), task28Bonus(), ['snapshot_damage_type' => 'physical', 'snapshot_base_damage' => 10], $changes);
}

function task28Resolve(array $action, array $resistances = [], array $rolls = [1, 100, 100]): array
{
    $enemy = task6CaveBruteDefinition();
    $enemy['server_only']['resistances'] = array_replace(array_fill_keys(['fire', 'lightning', 'cold', 'poison'], 0), $resistances);
    $random = new Task6SequenceRandomSource($rolls);
    return [(new PrototypeEnemyDefenseResolver($random))->resolve($action, $enemy), $random->integerCalls];
}

function task28Ring(array $values, int $id = 98): array
{
    $affixes = [];
    foreach ($values as $type => $value) {
        $affixes[] = ['modifier_type' => $type, 'modifier_operation' => 'flat', 'rolled_value' => $value];
    }
    return equipmentItem($id, 'ring', $affixes) + ['equipment_slot' => 'ring'];
}

function task28StoredBonus(array $action): array
{
    return array_intersect_key($action, task28Bonus());
}

return [
    'Task28 migration requires 007 and rejects recorded reruns and partial columns before DDL' => function (): void {
        $sql = equipmentMigrationSql('008_combat_elemental_snapshots.sql');
        foreach (["migration_id = '007_character_equipment'", "migration_id = '008_combat_elemental_snapshots'", 'if v_count <> 1 then', 'if v_count > 0 then', 'if v_count <> 0 then', "table_name = 'combat_actions'", 'signal sqlstate', 'information_schema.columns'] as $fragment) {
            assertEquipmentSqlContains($sql, $fragment, 'Migration preflight.');
        }
        assertSameValue(true, strpos($sql, 'if v_count <> 0 then') < strpos($sql, 'alter table'), 'Partial detection before schema mutation.');
        foreach (array_keys(task28Bonus()) as $column) {
            assertEquipmentSqlContains($sql, "'$column'", 'Each partially installed column is detected.');
        }
    },
    'Task28 migration adds exactly four nullable unsigned integer columns and only one migration record' => function (): void {
        $sql = equipmentMigrationSql('008_combat_elemental_snapshots.sql');
        preg_match_all('/add column (snapshot_\w+) int unsigned null/', $sql, $matches);
        assertSameValue(array_keys(task28Bonus()), $matches[1], 'Exact four columns and SQL shapes.');
        assertSameValue(4, substr_count($sql, 'add column'), 'No unrelated columns.');
        assertSameValue(1, substr_count($sql, 'alter table'), 'Single table ALTER.');
        assertSameValue(1, substr_count($sql, 'insert into'), 'Only migration record.');
        assertSameValue(1, substr_count($sql, "values ('008_combat_elemental_snapshots')"), 'Record once.');
        assertSameValue(0, preg_match('/\b(update|delete|truncate|create table|add key|add constraint)\b/', $sql), 'No gameplay or unrelated mutation.');
    },
    'Task28 separate verification SQL is read only and inspects all columns and migration' => function (): void {
        $sql = equipmentMigrationSql('008_combat_elemental_snapshots_verify.sql');
        assertEquipmentSqlContains($sql, "migration_id = '008_combat_elemental_snapshots'", 'Record check.');
        foreach (array_keys(task28Bonus()) as $column) { assertEquipmentSqlContains($sql, $column, 'Column inspection.'); }
        foreach (['column_type', 'is_nullable', 'column_default'] as $column) { assertEquipmentSqlContains($sql, $column, 'Shape inspection.'); }
        assertSameValue(0, preg_match('/\b(insert|update|delete|alter|create|drop|truncate|call)\b/', $sql), 'Read only.');
    },
    'Task28 Cave Brute has explicit zero resistances and registry rejects missing or out of range entries' => function (): void {
        $config = task6CombatConfig();
        assertSameValue(array_fill_keys(['fire', 'lightning', 'cold', 'poison'], 0), $config['prototype_balance']['enemies']['cave_brute']['server_only']['resistances'] ?? null, 'Named server resistance block.');
        foreach (['fire', 'lightning', 'cold', 'poison'] as $type) {
            foreach ([-1, 101, null] as $value) {
                $bad = $config;
                $bad['prototype_balance']['enemies']['cave_brute']['server_only']['resistances'][$type] = $value;
                $rejected = false;
                try { new CombatDefinitionRegistry($bad); } catch (RuntimeException) { $rejected = true; }
                assertSameValue(true, $rejected, 'Invalid resistance rejected.');
            }
        }
    },
    'Task28 physical and each bonus type sum after independent resistance with project rounding' => function (): void {
        foreach ([
            [[], [], 10], [['fire' => 5], [], 15], [['fire' => 10], ['fire' => 50], 15],
            [['fire' => 10, 'lightning' => 8, 'cold' => 6, 'poison' => 4], [], 38],
            [['fire' => 10, 'lightning' => 8, 'cold' => 6, 'poison' => 4], ['fire' => 25, 'lightning' => 50, 'cold' => 100, 'poison' => 75], 23],
            [['fire' => 5], ['fire' => 100], 10],
        ] as [$bonus, $resistances, $total]) {
            [$result, $calls] = task28Resolve(task28Offense(task28Bonus($bonus)), $resistances);
            assertSameValue(['HIT', $total, 3], [$result['outcome'], $result['applied_damage'], $calls], 'One contact, not per-component rolls.');
        }
    },
    'Task28 native elemental obeys resistance and same-type native plus bonus combines before rounding' => function (): void {
        foreach ([['fire', 10, 0, 25, 8], ['fire', 1, 1, 50, 1], ['lightning', 10, 2, 50, 6], ['cold', 10, 0, 100, 0], ['poison', 10, 0, 0, 10]] as [$type, $base, $bonus, $resistance, $expected]) {
            [$result] = task28Resolve(task28Offense(['snapshot_damage_type' => $type, 'snapshot_base_damage' => $base, 'snapshot_' . $type . '_damage' => $bonus]), [$type => $resistance]);
            assertSameValue(['HIT', $expected], [$result['outcome'], $result['applied_damage']], 'Native component and same-type bonus combine.');
        }
    },
    'Task28 fully resisted all-elemental zero remains HIT and MISS skips elemental Block and critical processing' => function (): void {
        [$zero, $calls] = task28Resolve(task28Offense(['snapshot_damage_type' => 'fire', 'snapshot_fire_damage' => 5]), ['fire' => 100]);
        assertSameValue(['HIT', 0, 3], [$zero['outcome'], $zero['applied_damage'], $calls], 'No minimum-one rule.');
        [$miss, $calls] = task28Resolve(task28Offense(['snapshot_fire_damage' => 999, 'snapshot_critical_chance' => 100]), [], [66]);
        assertSameValue(['MISS', 0, false, 1], [$miss['outcome'], $miss['applied_damage'], $miss['blocked'], $calls], 'No further random draws on miss.');
    },
    'Task28 one Block on resisted sum then one critical multiplying remaining total' => function (): void {
        foreach ([[[1, 20, 100], 7, 'BLOCK'], [[1, 100, 1], 30, 'CRITICAL'], [[1, 20, 1], 14, 'CRITICAL']] as [$rolls, $expected, $outcome]) {
            [$result, $calls] = task28Resolve(task28Offense(['snapshot_fire_damage' => 10, 'snapshot_critical_chance' => 50, 'snapshot_critical_damage' => 100]), ['fire' => 50], $rolls);
            assertSameValue([$expected, $outcome, 3], [$result['applied_damage'], $result['outcome'], $calls], 'Resisted sum15 Block rounds8 leaves7 critical doubles once.');
            assertSameValue([20, $rolls[1] === 20 ? 13 : 5], [$result['incoming_damage'], $result['prevented_damage']], 'Precritical prevention includes resistance and then Block, never critical scaling.');
        }
    },
    'Task28 persistent provider snapshots all four authoritative aggregated utility values and explicit zeros for skills' => function (): void {
        [$pdo, , $reader, $provider] = task25Fixture([], []);
        assertSameValue(task28Bonus(), task28StoredBonus($provider->offensiveSnapshot($pdo->characters[42], 'prototype_weapon_attack')), 'Explicit unarmed zeros.');
        $ring = task28Ring(['fire_damage' => 2, 'lightning_damage' => 4, 'cold_damage' => 6, 'poison_damage' => 8]);
        $ring['affixes'][] = ['modifier_type' => 'fire_damage', 'modifier_operation' => 'flat', 'rolled_value' => '3'];
        $reader->items = [$ring, task28Ring(['fire_damage' => 7, 'cold_damage' => -9], 99)];
        $snapshot = $provider->offensiveSnapshot($pdo->characters[42], 'prototype_weapon_attack');
        assertSameValue(task28Bonus(['fire' => 12, 'lightning' => 4, 'poison' => 8]), task28StoredBonus($snapshot), 'Multiple items and affixes aggregate; utility clamps negative totals.');
        assertSameValue([5, 'physical'], [$snapshot['snapshot_base_damage'], $snapshot['snapshot_damage_type']], 'No mixing into native base.');
        $skill = $provider->offensiveSnapshot($pdo->characters[42], 'prototype_flame_strike');
        assertSameValue([24, 'fire', task28Bonus()], [$skill['snapshot_base_damage'], $skill['snapshot_damage_type'], task28StoredBonus($skill)], 'Skill native preserved, all bonuses zero.');
    },
    'Task28 native fire weapon gets no Strength and persisted elemental snapshots survive equipment fixture changes and replay' => function (): void {
        $wand = task25Mace();
        $wand['snapshot_damage_min'] = 10; $wand['snapshot_damage_max'] = 10; $wand['snapshot_damage_type'] = 'fire';
        [$pdo, $clock, $reader, $provider, $random, $service] = task25Fixture([$wand, task28Ring(['fire_damage' => 5, 'lightning_damage' => 2, 'cold_damage' => 3, 'poison_damage' => 4])], [1, 100, 100], ['strength' => 99]);
        $token = equipmentToken(2800);
        $service->startPlayerAction(7, 42, 'prototype_weapon_attack', $token);
        $before = $pdo->actions[1];
        assertSameValue([10, 'fire', task28Bonus(['fire' => 5, 'lightning' => 2, 'cold' => 3, 'poison' => 4])], [$before['snapshot_base_damage'], $before['snapshot_damage_type'], task28StoredBonus($before)], 'Stored separate native and all four bonus values.');
        $reader->items = [task28Ring(['fire_damage' => 999])];
        task26Advance($clock);
        $state = $service->state(7, 42);
        assertSameValue([24, 49, task28StoredBonus($before)], [$pdo->actions[1]['resolved_damage'], $state['enemy']['current_hp'], task28StoredBonus($pdo->actions[1])], 'Immutable stored offense, not current equipment.');
        $saved = [$pdo->characters, $pdo->actions, $pdo->events];
        $service->state(7, 42);
        $service->startPlayerAction(7, 42, 'prototype_weapon_attack', $token);
        assertSameValue([$saved, 3], [[$pdo->characters, $pdo->actions, $pdo->events], $random->integerCalls], 'Replay no mutation or rerolls.');
    },
    'Task28 legacy pending NULL bonuses stay NULL and use zero never current equipment or historical backfill' => function (): void {
        [$pdo, $clock, , , , $service] = task25Fixture([task28Ring(['fire_damage' => 999])], [1, 100, 100]);
        $service->startPlayerAction(7, 42, 'prototype_weapon_attack', equipmentToken(2801));
        foreach (array_keys(task28Bonus()) as $key) { $pdo->actions[1][$key] = null; }
        task26Advance($clock);
        $service->state(7, 42);
        assertSameValue([5, array_fill_keys(array_keys(task28Bonus()), null)], [$pdo->actions[1]['resolved_damage'], task28StoredBonus($pdo->actions[1])], 'NULL is zero but remains NULL.');
        $saved = [$pdo->characters, $pdo->actions, $pdo->events];
        $service->state(7, 42);
        assertSameValue($saved, [$pdo->characters, $pdo->actions, $pdo->events], 'Resolved history untouched.');
    },
    'Task28 multi-element weapon heals Life on Hit exactly once and excluded skill adds no bonuses or healing' => function (): void {
        foreach (['prototype_weapon_attack', 'prototype_flame_strike'] as $key) {
            [$pdo, $clock, , , $random, $service] = task25Fixture([task28Ring(['fire_damage' => 5, 'lightning_damage' => 2, 'cold_damage' => 3, 'poison_damage' => 4, 'life_on_hit' => 5])], [1, 100, 100]);
            $service->startPlayerAction(7, 42, $key, equipmentToken(2802));
            $before = task28StoredBonus($pdo->actions[1]);
            assertSameValue($key === 'prototype_flame_strike' ? task28Bonus() : task28Bonus(['fire' => 5, 'lightning' => 2, 'cold' => 3, 'poison' => 4]), $before, 'All four explicit non-NULL values persisted.');
            task26Advance($clock, $key === 'prototype_flame_strike' ? 1.5 : 1);
            $service->state(7, 42);
            assertSameValue($key === 'prototype_flame_strike' ? [24, 145, 0, 3] : [19, 150, 1, 3], [$pdo->actions[1]['resolved_damage'], $pdo->characters[42]['current_hp'], count(task26HealingEvents($pdo)), $random->integerCalls], 'One normal contact, no per-type heal trigger.');
            assertSameValue($before, task28StoredBonus($pdo->actions[1]), 'Resolution preserves all four snapshots.');
        }
    },
    'Task28 MISS returns before even validating elemental snapshots or resistance configuration' => function (): void {
        $random = new Task6SequenceRandomSource([66]);
        $result = (new PrototypeEnemyDefenseResolver($random))->resolve(task28Offense(['snapshot_fire_damage' => -999]), []);
        assertSameValue(['MISS', 0, 1], [$result['outcome'], $result['applied_damage'], $random->integerCalls], 'Hit check short circuits elemental processing, Block, and critical.');
    },
    'Task28 malformed or nonpending NULL bonuses are rejected and SQL numeric strings are accepted' => function (): void {
        foreach (['fire', 'lightning', 'cold', 'poison'] as $type) {
            foreach ([-1, 1.5, 'no', null] as $value) {
                task6AssertRejected(fn (): array => task28Resolve(task28Offense(['snapshot_' . $type . '_damage' => $value])), 'Only nonnegative integer snapshots or legacy pending NULL allowed.');
            }
            [$result] = task28Resolve(task28Offense(['snapshot_' . $type . '_damage' => '5']));
            assertSameValue(15, $result['applied_damage'], 'PDO integer string accepted without affix reconstruction.');
        }
        $missing = task28Offense(); unset($missing['snapshot_fire_damage']);
        task6AssertRejected(fn (): array => task28Resolve($missing), 'Missing is not legacy SQL NULL.');
        foreach (['resolved', 'cancelled'] as $state) {
            task6AssertRejected(fn (): array => task28Resolve(task28Offense(['state' => $state, 'snapshot_fire_damage' => null])), 'Compatibility never extends to historical rows.');
        }
    },
    'Task28 SQL action INSERT binds all four columns and resolution UPDATE never rewrites snapshots' => function (): void {
        [$pdo, $clock, , , , $service] = task25Fixture([], [1, 100, 100]);
        $service->startPlayerAction(7, 42, 'prototype_weapon_attack', equipmentToken(2804));
        task26Advance($clock); $service->state(7, 42);
        $insert = array_values(array_filter($pdo->preparedSql, static fn (string $sql): bool => str_starts_with($sql, 'INSERT INTO combat_actions')))[0];
        foreach (array_keys(task28Bonus()) as $column) {
            assertSameValue(2, substr_count($insert, $column), 'Each column and matching named bind are present exactly once.');
        }
        foreach ($pdo->preparedSql as $sql) {
            if (preg_match('/^UPDATE combat_actions/i', $sql) === 1) {
                assertSameValue(false, str_contains($sql, 'snapshot_'), 'Only lifecycle/results mutate at resolution.');
            }
        }
    },
    'Task28 Flame Strike native Fire obeys resistance but equipped elemental damage remains excluded' => function (): void {
        [$result, $calls] = task28Resolve(task28Offense(['action_kind' => 'skill', 'snapshot_damage_type' => 'fire', 'snapshot_base_damage' => 24, 'snapshot_fire_damage' => 999]), ['fire' => 25]);
        assertSameValue(['HIT', 18, 3], [$result['outcome'], $result['applied_damage'], $calls], 'Native skill damage only; defensive exclusion even for a malformed nonzero skill bonus.');
    },
    'Task28 new nonoffensive player commands also store explicit zero elemental bonuses' => function (): void {
        [$pdo] = task25Fixture([], []);
        $pdo->actions[71] = task6EnemyAction('smash', ['id' => 71, 'encounter_id' => 91]);
        $repository = new CombatRepository($pdo);
        $repository->beginTransaction();
        try {
            $repository->lockOwnedCharacter(7, 42);
            $repository->lockActiveEncounter(42);
            $potion = $repository->createResolvedPotionCommand(91, 'prototype_health_potion', equipmentToken(2805), 3000, 5);
            $repository->lockEnemyActionForBlock(91, 71);
            $block = $repository->createResolvedBlockCommand(91, 71, 'basic_block', equipmentToken(2806), 3000);
            assertSameValue([task28Bonus(), task28Bonus()], [task28StoredBonus($potion), task28StoredBonus($block)], 'New player actions use explicit zeros, not legacy NULL.');
        } finally { $repository->rollBack(); }
    },
    'Task28 fully resisted zero HIT still heals once by explicit contact policy' => function (): void {
        [$pdo, $clock, $reader, $provider] = task25Fixture([task26Ring()], []);
        $config = task6CombatConfig();
        $config['prototype_balance']['enemies']['cave_brute']['server_only']['resistances'] = array_fill_keys(['fire', 'lightning', 'cold', 'poison'], 100);
        $definitions = new CombatDefinitionRegistry($config);
        $wand = task25Mace(); $wand['snapshot_damage_type'] = 'fire';
        $reader->items[] = $wand;
        $random = new Task6SequenceRandomSource([1, 100, 100]);
        $repository = new CombatRepository($pdo);
        $turn = new CombatTurnEngine($definitions->turnDurationSeconds());
        $resolver = new CombatActionResolver($repository, $definitions, $provider, new PrototypeEnemyDefenseResolver($random), new Task7RecordingBlockResolver());
        $sync = new CombatSynchronizer($clock, $turn, $definitions->maxDisconnectedCatchupSeconds(), null, $repository, $definitions, new CaveBrutePolicy($turn), $resolver, $random);
        $service = new CombatService($repository, $definitions, $clock, $sync, $provider);
        $service->startPlayerAction(7, 42, 'prototype_weapon_attack', equipmentToken(2803));
        task26Advance($clock);
        $state = $service->state(7, 42);
        assertSameValue(['HIT', 0, 150, 1], [$state['battle_events'][0]['outcome'], $pdo->actions[1]['resolved_damage'], $pdo->characters[42]['current_hp'], count(task26HealingEvents($pdo))], 'Zero HIT remains contact.');
    },
];

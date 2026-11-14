<?php
declare(strict_types=1);

function task25Offense(array $changes = []): array
{
    return array_replace(['snapshot_base_damage' => 11, 'snapshot_accuracy' => 15.0,
        'snapshot_critical_chance' => 0.0, 'snapshot_critical_damage' => 20], $changes);
}

function task25Resolve(array $offense, array $rolls): array
{
    $random = new Task6SequenceRandomSource($rolls);
    $result = (new PrototypeEnemyDefenseResolver($random))->resolve($offense, task6CaveBruteDefinition());
    return [$result, $random->integerCalls];
}

function task25Fixture(array $items, array $rolls, array $character = []): array
{
    $pdo = new FakeCombatPdo();
    $pdo->characters[42] = array_replace($pdo->characters[42], ['strength' => 5], $character);
    $pdo->encounters[91] = task4Encounter([
        'next_enemy_decision_timeline_ms' => 9000,
        'enemy_ai_initialized_timeline_ms' => 0,
        'player_actions_remaining' => 1,
    ]);
    $clock = new Task4MutableCombatClock(new DateTimeImmutable('2026-09-01 12:00:00.000000', new DateTimeZone('UTC')));
    $reader = new FakeCombatEquipmentReader($items);
    $provider = new PersistentCombatEquipmentProvider($reader, CombatDefinitionRegistry::fromDefaultConfig(), new EquipmentStatAggregator());
    $random = new Task6SequenceRandomSource($rolls);
    $service = CombatBootstrap::serviceForRepository(new CombatRepository($pdo), $clock, $provider, $random);
    return [$pdo, $clock, $reader, $provider, $random, $service];
}

function task25Mace(): array
{
    return equipmentItem(77, 'weapon') + ['equipment_slot' => 'weapon', 'definition_key' => 'basic_mace',
        'snapshot_damage_min' => 5, 'snapshot_damage_max' => 7, 'snapshot_damage_type' => 'physical'];
}

function task25Ring(): array
{
    $affixes = [];
    foreach (['accuracy' => 30, 'critical_chance' => 30, 'critical_damage' => 80, 'dodging' => 20, 'fire_resistance' => 8,
        'life_on_hit' => 999, 'burn_damage' => 999, 'fire_damage' => 999] as $type => $value) {
        $affixes[] = ['modifier_type' => $type, 'modifier_operation' => 'flat', 'rolled_value' => $value];
    }
    return equipmentItem(90, 'ring', $affixes) + ['equipment_slot' => 'ring'];
}

function task25Snapshot(array $action): array
{
    return array_intersect_key($action, array_flip(['snapshot_weapon_item_id', 'snapshot_weapon_key',
        'snapshot_base_damage', 'snapshot_damage_type', 'snapshot_accuracy', 'snapshot_critical_chance', 'snapshot_critical_damage']));
}

return [
    'Task25 Accuracy 15 gives 65 inclusive hit and 66 miss without Block or crit rolls' => function (): void {
        [$hit, $calls] = task25Resolve(task25Offense(), [65, 100, 100]);
        assertSameValue(['HIT', 11, 3], [$hit['outcome'] ?? null, $hit['applied_damage'], $calls], '65 hits.');
        [$miss, $calls] = task25Resolve(task25Offense(['snapshot_critical_chance' => 100]), [66]);
        assertSameValue(['MISS', 0, false, 1], [$miss['outcome'] ?? null, $miss['applied_damage'], $miss['blocked'], $calls], 'Miss skips Block and critical.');
    },
    'Task25 Accuracy clamps to 5 and 95 not guaranteed hit or miss' => function (): void {
        foreach ([[-100, 5, 'HIT'], [-100, 6, 'MISS'], [100, 95, 'HIT'], [100, 96, 'MISS'], [0, 50, 'HIT'], [1, 51, 'HIT']] as [$accuracy, $roll, $expected]) {
            [$damage] = task25Resolve(task25Offense(['snapshot_accuracy' => $accuracy]), $expected === 'HIT' ? [$roll, 100, 100] : [$roll]);
            assertSameValue($expected, $damage['outcome'] ?? null, 'Clamped inclusive hit threshold.');
        }
    },
    'Task25 Critical clamps chances and uses bonus percent with nearest rounding' => function (): void {
        foreach ([[0, 1, 'HIT', 11], [1, 1, 'CRITICAL', 13], [1, 2, 'HIT', 11], [65, 65, 'CRITICAL', 13], [65, 66, 'HIT', 11], [100, 100, 'CRITICAL', 13], [150, 100, 'CRITICAL', 13], [-5, 1, 'HIT', 11]] as [$chance, $roll, $outcome, $applied]) {
            [$damage, $calls] = task25Resolve(task25Offense(['snapshot_critical_chance' => $chance]), [1, 100, $roll]);
            assertSameValue([$outcome, $applied, 3], [$damage['outcome'] ?? null, $damage['applied_damage'], $calls], 'One critical roll on hit.');
        }
    },
    'Task25 Enemy Block precedes critical scaling exactly once and zero HIT stays explicit' => function (): void {
        [$damage] = task25Resolve(task25Offense(['snapshot_critical_chance' => 100]), [1, 20, 100]);
        assertSameValue([true, 6, 6, 'CRITICAL'], [$damage['blocked'], $damage['prevented_damage'], $damage['applied_damage'], $damage['outcome'] ?? null], '11 minus rounded Block 6 gives 5, then times 1.20 gives 6.');
        [$zero] = task25Resolve(task25Offense(['snapshot_base_damage' => 0]), [1, 100, 100]);
        assertSameValue(['HIT', false, 0], [$zero['outcome'] ?? null, $zero['blocked'], $zero['applied_damage']], 'Zero damage is not inferred as miss.');
    },
    'Task25 Dodge boundaries precede Block and passive defense with no double prevention' => function (): void {
        foreach ([[0, 1, false], [1, 1, true], [12, 12, true], [12, 13, false], [100, 75, true], [100, 76, false]] as [$dodge, $roll, $dodged]) {
            $random = new Task6SequenceRandomSource($dodged ? [$roll] : [$roll, 20]);
            $resolver = new PrototypeBlockResolver(new PrototypeChampionDamageResolver(), $random, CombatDefinitionRegistry::fromDefaultConfig()->playerReaction('basic_block'));
            // A dodge must not even validate passive defense: it never reaches that resolver.
            $defense = $dodged ? ['dodging' => $dodge] : ['dodging' => $dodge, 'block_rate' => 1.0, 'toughness' => 20, 'resistances' => ['fire' => 50]];
            $result = $resolver->resolve(task6EnemyAction('smash'), $defense, true);
            assertSameValue($dodged ? ['DODGE', 0, 18, false, 1] : ['BLOCK', 8, 10, true, 2], [$result['outcome'] ?? null, $result['applied_damage'], $result['prevented_damage'], $result['blocked'], $random->integerCalls], 'Dodge then existing 15 passive damage / half Block rounded to 8.');
        }
    },
    'Task25 Failed dodge preserves fire resistance and unattempted Block consumes no Block roll' => function (): void {
        $random = new Task6SequenceRandomSource([100]);
        $resolver = new PrototypeBlockResolver(new PrototypeChampionDamageResolver(), $random, CombatDefinitionRegistry::fromDefaultConfig()->playerReaction('basic_block'));
        $result = $resolver->resolve(task6EnemyAction('fire_slam'), ['dodging' => 12, 'resistances' => ['fire' => 50]], false);
        assertSameValue(['HIT', 12, 12, 1], [$result['outcome'] ?? null, $result['applied_damage'], $result['prevented_damage'], $random->integerCalls], 'Resistance after failed dodge.');
    },
    'Task25 Persisted outcomes and replay preserve allowance cooldown and explicit zero HIT' => function (): void {
        foreach ([
            ['HIT', [65, 100, 100], 5, 5, false],
            ['MISS', [66], 5, 0, false],
            ['CRITICAL', [1, 100, 15], 5, 6, false],
            ['BLOCK', [1, 20, 100], 5, 2, true],
            ['HIT', [1, 100, 100], 0, 0, false],
            ['CRITICAL', [1, 20, 15], 5, 2, true],
        ] as [$expected, $rolls, $strength, $applied, $blocked]) {
            [$pdo, $clock, , $provider, $random, $service] = task25Fixture([], $rolls, ['strength' => $strength]);
            $token = equipmentToken(2500);
            $service->startPlayerAction(7, 42, 'prototype_weapon_attack', $token);
            $before = task25Snapshot($pdo->actions[1]);
            assertSameValue([3000, 4000, 5500, 0], [$pdo->actions[1]['started_timeline_ms'], $pdo->actions[1]['resolves_timeline_ms'], $pdo->actions[1]['cooldown_ready_timeline_ms'], $pdo->encounters[91]['player_actions_remaining']], 'Normal action and cooldown consumption for every result.');
            $clock->set(new DateTimeImmutable('2026-09-01 12:00:01.000000', new DateTimeZone('UTC')));
            $state = $service->state(7, 42);
            assertSameValue([$expected, $blocked], [$state['battle_events'][0]['outcome'] ?? null, $state['battle_events'][0]['blocked'] ?? null], 'Outcome is persisted and server projected, not inferred.');
            assertSameValue($applied, $pdo->actions[1]['resolved_damage'], 'Stored result.');
            assertSameValue(73 - $applied, $state['enemy']['current_hp'], 'Enemy HP once.');
            assertSameValue($before, task25Snapshot($pdo->actions[1]), 'Resolution never overwrites offense.');
            assertSameValue(145, $state['champion']['current_hp'], 'No Life on Hit.');
            $service->state(7, 42);
            $replay = CombatBootstrap::serviceForRepository(new CombatRepository($pdo), $clock, $provider, new Task6SequenceRandomSource([]))
                ->startPlayerAction(7, 42, 'prototype_weapon_attack', $token);
            assertSameValue($state['battle_events'], $replay['battle_events'], 'Replay has identical durable outcome and text.');
            assertSameValue(count($rolls), $random->integerCalls, 'Exactly the deliberate sequence, no replay rolls.');
            assertSameValue(1, count($pdo->events), 'One event per action, even on replay.');
            assertSameValue(0, $pdo->encounters[91]['player_actions_remaining'], 'No replay allowance refund.');
        }
    },
    'Task25 Equipment changes cannot alter pending hit critical damage type or base damage' => function (): void {
        foreach ([[[65, 100, 15], 'CRITICAL', 13], [[66], 'MISS', 0], [[65, 100, 16], 'HIT', 11]] as [$rolls, $outcome, $applied]) {
            [$pdo, $clock, $reader, $provider, , $service] = task25Fixture([task25Mace()], $rolls);
            $service->startPlayerAction(7, 42, 'prototype_weapon_attack', equipmentToken(2501));
            $before = task25Snapshot($pdo->actions[1]);
            assertSameValue([11, 'physical', 15.0, 15.0, 20], [$before['snapshot_base_damage'], $before['snapshot_damage_type'], $before['snapshot_accuracy'], $before['snapshot_critical_chance'], $before['snapshot_critical_damage']], 'Strength 5 mace offense.');
            $wand = task25Mace();
            $wand['snapshot_damage_min'] = 100;
            $wand['snapshot_damage_max'] = 100;
            $wand['snapshot_damage_type'] = 'fire';
            $wand['definition_key'] = 'basic_wand';
            $reader->items = [$wand, task25Ring()];
            $pdo->characters[42]['strength'] = 99;
            $pdo->characters[42]['dexterity'] = 99;
            $pdo->characters[42]['fate'] = 99;
            $future = $provider->offensiveSnapshot($pdo->characters[42], 'prototype_weapon_attack');
            assertSameValue([100, 'fire', 90.0, 90.0], [$future['snapshot_base_damage'], $future['snapshot_damage_type'], $future['snapshot_accuracy'], $future['snapshot_critical_chance']], 'Only future snapshots change.');
            $clock->set(new DateTimeImmutable('2026-09-01 12:00:01.000000', new DateTimeZone('UTC')));
            $state = $service->state(7, 42);
            assertSameValue([$outcome, $applied], [$state['battle_events'][0]['outcome'], $pdo->actions[1]['resolved_damage']], 'All offensive resolution uses original snapshot.');
            assertSameValue($before, task25Snapshot($pdo->actions[1]), 'Every stored offense field is unchanged.');
        }
    },
    'Task25 Real equip and unequip alter future snapshots only including skill offense' => function (): void {
        $character = combatEquipmentCharacter();
        $store = new FakeEquipmentStore($character, [task25Mace(), task25Ring()]);
        $equipment = equipmentService($store);
        $provider = new PersistentCombatEquipmentProvider($store, CombatDefinitionRegistry::fromDefaultConfig(), new EquipmentStatAggregator());
        foreach (['prototype_weapon_attack', 'prototype_flame_strike'] as $key) {
            $before = $provider->offensiveSnapshot($character, $key);
            $equipment->equip(7, 42, 90, 'ring', equipmentToken($key === 'prototype_weapon_attack' ? 2502 : 2504));
            $boosted = $provider->offensiveSnapshot($character, $key);
            assertSameValue([45.0, 45.0, 100], [$boosted['snapshot_accuracy'], $boosted['snapshot_critical_chance'], $boosted['snapshot_critical_damage']], 'Equipment offense from shared CharacterStats.');
            assertSameValue([15.0, 15.0, 20], [$before['snapshot_accuracy'], $before['snapshot_critical_chance'], $before['snapshot_critical_damage']], 'Old immutable snapshot remains.');
            $equipment->unequip(7, 42, 90, equipmentToken($key === 'prototype_weapon_attack' ? 2503 : 2505));
            assertSameValue($before, $provider->offensiveSnapshot($character, $key), 'Unequip affects next snapshot.');
            [$boostedResult] = task25Resolve($boosted, [90, 100, 45]);
            assertSameValue(['CRITICAL', $key === 'prototype_weapon_attack' ? 10 : 48], [$boostedResult['outcome'], $boostedResult['applied_damage']], 'Boosted snapshot resolves after unequip, including skill base damage without weapon scaling.');
        }
    },
    'Task25 Enemy resolution uses newly equipped dodge or defense before existing Block' => function (): void {
        foreach ([[32, null, 'DODGE', 0, 18], [33, null, 'HIT', 16, 2], [33, 500, 'BLOCK', 8, 10]] as [$roll, $attempted, $outcome, $applied, $prevented]) {
            [$pdo, $clock, $reader, , $random, $service] = task25Fixture([], $attempted === null || $outcome === 'DODGE' ? [$roll] : [$roll, 20]);
            $pdo->actions[71] = task6EnemyAction('smash', ['id' => 71, 'encounter_id' => 91, 'resolves_timeline_ms' => 4000, 'block_attempted_timeline_ms' => $attempted]);
            $armour = task25Ring();
            $armour['snapshot_toughness'] = 4;
            $reader->items = [$armour];
            $clock->set(new DateTimeImmutable('2026-09-01 12:00:01.000000', new DateTimeZone('UTC')));
            $state = $service->state(7, 42);
            assertSameValue([$outcome, $applied, $prevented], [$state['battle_events'][0]['outcome'], $pdo->actions[71]['resolved_damage'], $pdo->actions[71]['prevented_damage']], 'Current gear, then dodge, then normal defense/Block.');
            assertSameValue(145 - $applied, $pdo->characters[42]['current_hp'], 'HP persisted exactly once.');
            $service->state(7, 42);
            assertSameValue($outcome === 'BLOCK' ? 2 : 1, $random->integerCalls, 'No reroll after resolution.');
        }
    },
    'Task25 Skills snapshot immutable chances at start and legacy null offense never reads current stats' => function (): void {
        [$pdo, $clock, $reader, , $random, $service] = task25Fixture([task25Ring()], [90, 100, 45]);
        $service->startPlayerAction(7, 42, 'prototype_flame_strike', equipmentToken(2506));
        $before = task25Snapshot($pdo->actions[1]);
        assertSameValue([24, 'fire', 45.0, 45.0, 100, null], [$before['snapshot_base_damage'], $before['snapshot_damage_type'], $before['snapshot_accuracy'], $before['snapshot_critical_chance'], $before['snapshot_critical_damage'], $before['snapshot_weapon_item_id']], 'Skill snapshots stats but retains its own offense path.');
        $reader->items = [];
        $clock->set(new DateTimeImmutable('2026-09-01 12:00:01.500000', new DateTimeZone('UTC')));
        $state = $service->state(7, 42);
        assertSameValue(['CRITICAL', 48, 3], [$state['battle_events'][0]['outcome'], $pdo->actions[1]['resolved_damage'], $random->integerCalls], 'Stored skill chances and bonus survive unequip.');
        assertSameValue($before, task25Snapshot($pdo->actions[1]), 'Immutable skill offense.');
        [$legacy] = task25Resolve(['state' => 'pending', 'snapshot_base_damage' => 24, 'snapshot_accuracy' => null, 'snapshot_critical_chance' => null, 'snapshot_critical_damage' => null], [50, 100, 1]);
        assertSameValue(['HIT', 24], [$legacy['outcome'], $legacy['applied_damage']], 'Pre-Task25 null offense uses neutral zero, not current equipment.');
    },
    'Task25 Outcome event failure atomically rolls back action damage and retries without partial results' => function (): void {
        [$pdo, $clock, , , , $service] = task25Fixture([], [1, 100, 100, 1, 100, 100]);
        $service->startPlayerAction(7, 42, 'prototype_weapon_attack', equipmentToken(2507));
        $before = [$pdo->characters, $pdo->encounters, $pdo->actions, $pdo->events];
        $pdo->failEventInsert = true;
        $clock->set(new DateTimeImmutable('2026-09-01 12:00:01.000000', new DateTimeZone('UTC')));
        try {
            $service->state(7, 42);
            throw new RuntimeException('Expected outcome event insert failure.');
        } catch (RuntimeException $e) {
            assertSameValue('Injected combat event insert failure.', $e->getMessage(), 'Persistence failure is not swallowed.');
        }
        assertSameValue($before, [$pdo->characters, $pdo->encounters, $pdo->actions, $pdo->events], 'Entire result is transactional.');
        $pdo->failEventInsert = false;
        $state = $service->state(7, 42);
        assertSameValue(['HIT', 68, 1], [$state['battle_events'][0]['outcome'], $state['enemy']['current_hp'], count($pdo->events)], 'One durable result on retry.');
    },
    'Task25 Approved legacy NULL fallback is confined to pending snapshots and does not fill missing data' => function (): void {
        foreach (['resolved', 'cancelled'] as $state) {
            task6AssertRejected(
                fn (): array => task25Resolve(task25Offense(['state' => $state, 'snapshot_accuracy' => null]), []),
                'Historical NULL offense must not invoke pending compatibility.',
            );
        }
        $missing = task25Offense();
        unset($missing['snapshot_accuracy']);
        task6AssertRejected(fn (): array => task25Resolve($missing, []), 'Missing data is not a legacy SQL NULL.');
    },
    'Task25 Legacy pending NULL offense uses neutral zero without equipment reads or historical rewrites' => function (): void {
        foreach ([[50, 'HIT', 24, [50, 100, 1]], [51, 'MISS', 0, [51]]] as [$hitRoll, $outcome, $applied, $rolls]) {
            [$pdo, $clock, , $provider, $random, $service] = task25Fixture([task25Ring()], $rolls, ['dexterity' => 99, 'fate' => 99]);
            $pdo->actions[101] = task10SkillAction([
                'state' => 'pending', 'active_slot' => 1, 'completed_timeline_ms' => null,
                'started_timeline_ms' => 3000, 'resolves_timeline_ms' => 4000,
            ]);
            $snapshot = task25Snapshot($pdo->actions[101]);
            $clock->set(new DateTimeImmutable('2026-09-01 12:00:01.000000', new DateTimeZone('UTC')));
            $state = $service->state(7, 42);
            assertSameValue([$outcome, $applied], [$state['battle_events'][0]['outcome'], $pdo->actions[101]['resolved_damage']], 'Legacy NULL accuracy is 0: 50 hits, 51 misses despite current high Accuracy.');
            assertSameValue($snapshot, task25Snapshot($pdo->actions[101]), 'Persisted NULL values stay NULL; no backfill.');
            assertSameValue(73 - $applied, $pdo->encounters[91]['enemy_current_hp'], 'No crit from NULL chance even on roll 1 and high current Fate.');
            assertSameValue(count($rolls), $random->integerCalls, 'Legacy miss skips Block/crit; hit rolls each once.');
            $before = [$pdo->actions, $pdo->events, $pdo->characters];
            CombatBootstrap::serviceForRepository(new CombatRepository($pdo), $clock, $provider, new Task6SequenceRandomSource([]))->state(7, 42);
            assertSameValue($before, [$pdo->actions, $pdo->events, $pdo->characters], 'Resolved legacy history never rerolls or rewrites.');
        }
        [$noBonus] = task25Resolve(task25Offense(['state' => 'pending', 'snapshot_critical_chance' => 100, 'snapshot_critical_damage' => null]), [1, 100, 100]);
        assertSameValue(['CRITICAL', 11], [$noBonus['outcome'], $noBonus['applied_damage']], 'Legacy NULL critical damage gives no bonus, not current equipment damage.');
    },
];

<?php
declare(strict_types=1);

function task27Item(int $id, int $basisPoints): array
{
    return equipmentItem($id, 'ring', [[
        'modifier_type' => 'block_rate_bp', 'modifier_operation' => 'additive_percent',
        'rolled_value' => $basisPoints,
    ]]) + ['equipment_slot' => 'ring'];
}

function task27Resolve(array $defense, array $rolls, bool $attempted = true, ?array $definition = null, string $attack = 'smash'): array
{
    $random = new Task6SequenceRandomSource($rolls);
    $resolver = new PrototypeBlockResolver(new PrototypeChampionDamageResolver(), $random,
        $definition ?? CombatDefinitionRegistry::fromDefaultConfig()->playerReaction('basic_block'));
    return [$resolver->resolve(task6EnemyAction($attack), $defense, $attempted), $random->integerCalls];
}

function task27Defense(array $items = []): array
{
    return persistentEquipmentProvider($items)->currentDefense(combatEquipmentCharacter());
}

$tests = [];
foreach ([
    ['base', 0, 20], ['factor 1.25', 2500, 25], ['factor 1.50', 5000, 30],
    ['factor 2', 10000, 40], ['cap', 90000, 75], ['negative floor', -20000, 2],
    ['fractional 25.5', 2750, 25], ['fractional 25.998', 2999, 25],
] as [$label, $bp, $threshold]) {
    $tests['Task27 ' . $label . ' inclusive chance boundary'] = function () use ($bp, $threshold): void {
        foreach ([$threshold => 'BLOCK', $threshold + 1 => 'HIT'] as $roll => $expected) {
            [$result, $calls] = task27Resolve(task27Defense([task27Item(90, $bp)]), [100, $roll]);
            assertSameValue([$expected, $expected === 'BLOCK', 2], [$result['outcome'], $result['blocked'], $calls], 'Integer roll compared directly with authoritative float chance.');
        }
    };
}

return $tests + [
    'Task27 defense projection carries shared factor in both providers' => function (): void {
        $character = combatEquipmentCharacter();
        $prototype = new PrototypeCombatEquipmentProvider(CombatDefinitionRegistry::fromDefaultConfig());
        assertFloatValue(1.0, $prototype->currentDefense($character)['block_rate'] ?? -1, 'Prototype base factor.');
        assertFloatValue(1.25, task27Defense([task27Item(90, 2500)])['block_rate'] ?? -1, 'Persistent equipment factor.');
        assertFloatValue(0.10, task27Defense([task27Item(90, -20000)])['block_rate'] ?? -1, 'Shared CharacterStats floor remains 0.10.');
    },
    'Task27 aggregation sums snapshot and multiple affixes once without unrelated changes' => function (): void {
        $one = task27Item(90, 1500);
        $one['snapshot_block_rate_modifier_bp'] = '1000';
        $two = task27Item(91, 2500);
        $two['affixes'][0]['rolled_value'] = '2500';
        $aggregator = new EquipmentStatAggregator();
        assertSameValue(2500, $aggregator->aggregate([$one])['block_rate_bp'], 'Single item snapshot plus affix.');
        $modifiers = $aggregator->aggregate([$one, $two]);
        assertSameValue(5000, $modifiers['block_rate_bp'], 'Multiple items add in bp, not multiplied.');
        assertSameValue($modifiers, $aggregator->aggregate([$two, $one]), 'Order independent.');
        $base = CharacterStats::calculate(combatEquipmentCharacter());
        $boosted = CharacterStats::calculate(combatEquipmentCharacter(), $modifiers);
        assertFloatValue(1.5, $boosted['rates']['block_rate'], 'Shared additive factor.');
        $boosted['rates']['block_rate'] = $base['rates']['block_rate'];
        assertSameValue($base, $boosted, 'All unrelated stats including LoH attack/cast Dodge resistances and damage unchanged.');
    },
    'Task27 no active attempt never rolls or passively Blocks even at capped factor' => function (): void {
        [$result, $calls] = task27Resolve(task27Defense([task27Item(90, 90000)]), [100], false);
        assertSameValue(['HIT', false, 1], [$result['outcome'], $result['blocked'], $calls], 'Only Dodge roll, not Block.');
    },
    'Task27 successful Dodge skips Block roll and defense calculation' => function (): void {
        [$result, $calls] = task27Resolve(['dodging' => 12.0], [12]);
        assertSameValue(['DODGE', false, 0, 18, 1], [$result['outcome'], $result['blocked'], $result['applied_damage'], $result['prevented_damage'], $calls], 'No Block factor or mitigation needed after Dodge.');
    },
    'Task27 failed Dodge uses modified Block chance then unchanged physical reduction' => function (): void {
        $defense = task27Defense([task27Item(90, 2500)]);
        $defense['toughness'] = 20;
        [$result, $calls] = task27Resolve($defense, [13, 25]);
        assertSameValue(['BLOCK', 18, 8, 10, 2], [$result['outcome'], $result['incoming_damage'], $result['applied_damage'], $result['prevented_damage'], $calls], '18 becomes 15 passive then 8 with 50% Block.');
    },
    'Task27 Block Rate changes neither configured base chance nor reduction' => function (): void {
        $definition = CombatDefinitionRegistry::fromDefaultConfig()->playerReaction('basic_block');
        $definition['server_only']['prototype_chance_percent'] = 30;
        $definition['server_only']['prototype_reduction_percent'] = 25;
        $defense = task27Defense([task27Item(90, 2500)]);
        $defense['toughness'] = 0;
        foreach ([[37, 'BLOCK', 14], [38, 'HIT', 18]] as [$roll, $outcome, $damage]) {
            [$result] = task27Resolve($defense, [100, $roll], true, $definition);
            assertSameValue([$outcome, $damage], [$result['outcome'], $result['applied_damage']], 'Configured 30 * 1.25 = 37.5, no rounding; configured reduction 25%.');
        }
    },
    'Task27 fire resistance and minimum applied damage stay unchanged' => function (): void {
        $defense = task27Defense([task27Item(90, 10000)]);
        $defense['resistances']['fire'] = 50.0;
        [$result] = task27Resolve($defense, [100, 40], true, null, 'fire_slam');
        assertSameValue(['BLOCK', 6, 18], [$result['outcome'], $result['applied_damage'], $result['prevented_damage']], '24 -> 12 fire resistance -> 6 Block.');
        $defense['resistances']['fire'] = 100.0;
        [$result] = task27Resolve($defense, [100, 40], true, null, 'fire_slam');
        assertSameValue(1, $result['applied_damage'], 'Existing minimum one damage retained.');
    },
    'Task27 attempted Block rejects missing or nonfinite factors instead of inventing stats' => function (): void {
        foreach ([null, INF, NAN, 'invalid'] as $factor) {
            $defense = task27Defense();
            $defense['block_rate'] = $factor;
            task6AssertRejected(fn (): array => task27Resolve($defense, [100, 20]), 'Invalid current factor must not silently use base chance.');
        }
    },
    'Task27 zero factor chance lower clamp cannot Block on roll one' => function (): void {
        // Defensive resolver clamp is independent of the stricter shared CharacterStats floor.
        $defense = task27Defense();
        $defense['block_rate'] = -1.0;
        [$result, $calls] = task27Resolve($defense, [100, 1]);
        assertSameValue(['HIT', 2], [$result['outcome'], $calls], 'Negative product clamps to zero, no guaranteed success.');
    },
    'Task27 pending defense uses current rate and resolved history refresh never rerolls' => function (): void {
        foreach ([[25, 'BLOCK', 8], [26, 'HIT', 16]] as [$roll, $outcome, $damage]) {
            [$pdo, $clock, $reader, $provider, $random, $service] = task25Fixture([], [100, $roll]);
            $pdo->actions[71] = task6EnemyAction('smash', ['id' => 71, 'encounter_id' => 91, 'resolves_timeline_ms' => 4000, 'block_attempted_timeline_ms' => 500]);
            // Injected reader proves resolution timing; real mid-combat equipment changes remain forbidden.
            $reader->items = [task27Item(90, 2500)];
            $clock->set(new DateTimeImmutable('2026-09-01 12:00:01.000000', new DateTimeZone('UTC')));
            $state = $service->state(7, 42);
            assertSameValue([$outcome, $damage, 145 - $damage], [$state['battle_events'][0]['outcome'], $pdo->actions[71]['resolved_damage'], $pdo->characters[42]['current_hp']], 'Resolution-time factor, unchanged defense/damage, HP applied once.');
            assertSameValue(2, $random->integerCalls, 'Dodge then one modified Block roll.');
            $before = [$pdo->actions, $pdo->events, $pdo->characters];
            $reader->items = [task27Item(90, -20000)];
            $service->state(7, 42);
            $emptyRandom = new Task6SequenceRandomSource([]);
            CombatBootstrap::serviceForRepository(new CombatRepository($pdo), $clock, $provider, $emptyRandom)->state(7, 42);
            assertSameValue($before, [$pdo->actions, $pdo->events, $pdo->characters], 'Refresh and repeated sync preserve durable damage/outcome after factor changes.');
            assertSameValue(0, $emptyRandom->integerCalls, 'Resolved history never rerolls.');
        }
    },
    'Task27 accepted Block intent replay preserves prompt timing allowance and one resolution' => function (): void {
        [$pdo, $clock, , $provider, $random, $service] = task25Fixture([task27Item(90, 5000)], [100, 30]);
        $pdo->actions[71] = task7EnemyAction(['resolves_timeline_ms' => 4000, 'block_expires_timeline_ms' => 4000]);
        $before = $pdo->actions[71];
        $token = equipmentToken(2700);
        $service->attemptBlock(7, 42, 71, str_repeat('b', 64), $token);
        $service->attemptBlock(7, 42, 71, str_repeat('b', 64), $token);
        assertSameValue(0, $random->integerCalls, 'Intent acceptance and replay do not roll.');
        assertSameValue(1, $pdo->encounters[91]['player_actions_remaining'], 'Zero Action cost unchanged.');
        $after = $pdo->actions[71];
        $after['block_attempted_timeline_ms'] = null;
        assertSameValue($before, $after, 'Only attempted timestamp changes; prompt duration cooldown damage untouched.');
        $clock->set(new DateTimeImmutable('2026-09-01 12:00:01.000000', new DateTimeZone('UTC')));
        $state = $service->state(7, 42);
        assertSameValue('BLOCK', $state['battle_events'][0]['outcome'], 'Accepted active intent uses 30% chance.');
        $resolved = [$pdo->characters, $pdo->actions, $pdo->events];
        $service->attemptBlock(7, 42, 71, str_repeat('b', 64), $token);
        CombatBootstrap::serviceForRepository(new CombatRepository($pdo), $clock, $provider, new Task6SequenceRandomSource([]))
            ->attemptBlock(7, 42, 71, str_repeat('b', 64), $token);
        assertSameValue($resolved, [$pdo->characters, $pdo->actions, $pdo->events], 'Resolved action replay is stable across service instances.');
        assertSameValue(2, $random->integerCalls, 'Exactly one Dodge and one Block roll total.');
    },
    'Task27 invalid intent cannot activate Block despite high factor' => function (): void {
        [$pdo, $clock, , , $random, $service] = task25Fixture([task27Item(90, 90000)], [100]);
        $pdo->actions[71] = task7EnemyAction(['resolves_timeline_ms' => 4000, 'block_expires_timeline_ms' => 4000]);
        task7AssertDomainRejected(fn (): array => $service->attemptBlock(7, 42, 71, str_repeat('c', 64), equipmentToken(2701)), 'Wrong prompt token.');
        assertSameValue(null, $pdo->actions[71]['block_attempted_timeline_ms'], 'Rejected intent leaves no attempt.');
        $clock->set(new DateTimeImmutable('2026-09-01 12:00:01.000000', new DateTimeZone('UTC')));
        $state = $service->state(7, 42);
        assertSameValue(['HIT', 1], [$state['battle_events'][0]['outcome'], $random->integerCalls], 'No automatic Block and no Block roll.');
    },
];

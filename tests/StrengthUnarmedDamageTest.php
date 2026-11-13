<?php
declare(strict_types=1);

return [
    'Strength is melee damage with no hidden base even at zero' => function (): void {
        $character = combatEquipmentCharacter();
        foreach ([0, 5, 6] as $strength) {
            $character['strength'] = $strength;
            $stats = CharacterStats::calculate($character);
            assertSameValue($strength, $stats['combat']['melee_damage'], 'One damage per Strength.');
            assertSameValue(['min' => $strength, 'max' => $strength, 'type' => 'physical'], $stats['combat']['attack_damage'], 'Unarmed range.');
            assertSameValue("{$strength} Physical", $stats['combat']['attack_damage_display'], 'Single-value physical display.');
        }
    },
    'Unarmed global flat and percent modifiers match HUD and combat including rounding' => function (): void {
        foreach ([[2, 0, 7], [0, 5000, 8], [2, 5000, 11], [2, -20000, 0]] as [$flat, $percent, $expected]) {
            $ring = equipmentItem(90, 'ring', [
                ['modifier_type' => 'flat_damage', 'modifier_operation' => 'flat', 'rolled_value' => $flat],
                ['modifier_type' => 'damage_percent_bp', 'modifier_operation' => 'additive_percent', 'rolled_value' => $percent],
            ]) + ['equipment_slot' => 'ring_1'];
            $provider = persistentEquipmentProvider([$ring]);
            $stats = $provider->characterStats(combatEquipmentCharacter());
            $snapshot = $provider->offensiveSnapshot(combatEquipmentCharacter(), 'prototype_weapon_attack');
            assertSameValue("{$expected} Physical", $stats['combat']['attack_damage_display'], 'Unarmed modifiers in HUD.');
            assertSameValue($expected, $snapshot['snapshot_base_damage'], 'Unarmed modifiers in combat.');
            assertSameValue(null, $snapshot['snapshot_weapon_item_id'], 'Other gear is not a weapon identity.');
            assertSameValue(null, $snapshot['snapshot_weapon_key'], 'No synthetic definition identity.');
            assertFloatValue(15.0, $snapshot['snapshot_accuracy'], 'Unarmed Accuracy preserved.');
            assertFloatValue(15.0, $snapshot['snapshot_critical_chance'], 'Unarmed Critical Chance preserved.');
            assertSameValue(20, $snapshot['snapshot_critical_damage'], 'Unarmed Critical Damage preserved.');
        }
        $ring = equipmentItem(90, 'ring', [
            ['modifier_type' => 'attack_rate_bp', 'modifier_operation' => 'additive_percent', 'rolled_value' => 2500],
            ['modifier_type' => 'cast_rate_bp', 'modifier_operation' => 'additive_percent', 'rolled_value' => 1000],
        ]) + ['equipment_slot' => 'ring_1'];
        $provider = persistentEquipmentProvider([$ring]);
        assertSameValue(800, $provider->effectiveDurationMs(combatEquipmentCharacter(), 'prototype_weapon_attack', 1000), 'Unarmed uses Attack Rate.');
        assertSameValue(909, $provider->effectiveDurationMs(combatEquipmentCharacter(), 'prototype_flame_strike', 1000), 'Cast Rate unaffected by unarmed.');
    },
    'Basic Mace equip and unequip switch between real weapon range and unarmed damage' => function (): void {
        $weapon = equipmentItem(77, 'weapon') + ['definition_key' => 'basic_mace', 'snapshot_damage_min' => 5, 'snapshot_damage_max' => 7, 'snapshot_damage_type' => 'physical'];
        $character = combatEquipmentCharacter();
        $store = new FakeEquipmentStore($character, [$weapon]);
        $service = equipmentService($store);
        $provider = new PersistentCombatEquipmentProvider($store, CombatDefinitionRegistry::fromDefaultConfig(), new EquipmentStatAggregator());
        assertSameValue('5 Physical', $provider->characterStats($character)['combat']['attack_damage_display'], 'Before equip.');
        $service->equip(7, 42, 77, 'weapon', equipmentToken(2401));
        assertSameValue('10–12 Physical', $provider->characterStats($character)['combat']['attack_damage_display'], 'After equip.');
        assertSameValue(11, $provider->offensiveSnapshot($character, 'prototype_weapon_attack')['snapshot_base_damage'], 'Mace midpoint.');
        $service->unequip(7, 42, 77, equipmentToken(2402));
        assertSameValue('5 Physical', $provider->characterStats($character)['combat']['attack_damage_display'], 'After unequip.');
        assertSameValue(5, $provider->offensiveSnapshot($character, 'prototype_weapon_attack')['snapshot_base_damage'], 'Unarmed combat restored.');
    },
    'Sword endpoints add Strength only for physical damage and preserve other stats' => function (): void {
        $character = combatEquipmentCharacter();
        $modifiers = ['accuracy' => 3, 'critical_chance' => 4, 'critical_damage' => 5, 'attack_rate_bp' => 2500, 'cast_rate_bp' => 1000, 'fire_damage' => 9, 'poison_damage' => 200, 'toughness' => 2, 'fire_resistance' => 3];
        $unarmed = CharacterStats::calculate($character, $modifiers);
        foreach (['physical' => [13, 17], 'fire' => [8, 12]] as $type => [$min, $max]) {
            $weapon = ['snapshot_damage_min' => 8, 'snapshot_damage_max' => 12, 'snapshot_damage_type' => $type];
            $stats = CharacterStats::calculate($character, $modifiers, $weapon);
            assertSameValue(['min' => $min, 'max' => $max, 'type' => $type], $stats['combat']['attack_damage'], 'Strength physical-only.');
            $modified = CharacterStats::calculate($character, $modifiers + ['flat_damage' => 2, 'damage_percent_bp' => 1000], $weapon);
            assertSameValue($type === 'physical' ? ['min' => 17, 'max' => 21, 'type' => 'physical'] : ['min' => 11, 'max' => 15, 'type' => 'fire'], $modified['combat']['attack_damage'], 'Flat and percent preserved for both damage types.');
            unset($stats['combat']['attack_damage'], $stats['combat']['attack_damage_display']);
            $baseline = $unarmed;
            unset($baseline['combat']['attack_damage'], $baseline['combat']['attack_damage_display']);
            assertSameValue($baseline, $stats, 'Weapon presence changes no other stat.');
            $character['strength'] = 6;
            $stronger = CharacterStats::calculate($character, $modifiers, $weapon);
            assertSameValue($type === 'physical' ? $min + 1 : $min, $stronger['combat']['attack_damage']['min'], 'Strength updates only physical endpoints.');
            $character['strength'] = 5;
        }
        assertFloatValue(18.0, $unarmed['combat']['accuracy'], 'Accuracy preserved.');
        assertFloatValue(19.0, $unarmed['combat']['critical_chance'], 'Critical chance preserved.');
        assertSameValue(25, $unarmed['combat']['critical_damage'], 'Critical damage preserved.');
        assertFloatValue(1.25, $unarmed['rates']['attack_rate'], 'Attack Rate preserved.');
        assertFloatValue(1.1, $unarmed['rates']['cast_rate'], 'Cast Rate preserved.');
        assertSameValue(9, $unarmed['utility']['fire_damage'], 'Elemental damage preserved.');
        assertSameValue(200, $unarmed['utility']['poison_damage'], 'Status damage preserved.');
        assertFloatValue(2.0, $unarmed['utility']['status_effect_chance'], 'Status chance preserved.');
        assertSameValue(14, $unarmed['combat']['toughness'], 'Toughness preserved.');
        assertFloatValue(8.0, $unarmed['resistances']['fire'], 'Resistance preserved.');
    },
    'Zero attack damage resolves normally but negative damage remains invalid' => function (): void {
        $resolver = new PrototypeEnemyDefenseResolver(new Task6SequenceRandomSource([1]));
        assertSameValue(['incoming_damage' => 0, 'prevented_damage' => 0, 'applied_damage' => 0, 'blocked' => true], $resolver->resolve(['snapshot_base_damage' => 0], task6CaveBruteDefinition()), 'Zero damage remains zero when blocked.');
        task6AssertRejected(fn (): array => $resolver->resolve(['snapshot_base_damage' => -1], task6CaveBruteDefinition()), 'Negative damage.');
    },
    'Unarmed action starts persists resolves and replays through existing normal attack architecture' => function (): void {
        foreach ([[5, 0, 5], [0, 0, 0], [5, -10000, 0]] as [$strength, $percent, $expectedDamage]) {
            $pdo = new FakeCombatPdo();
            $pdo->characters[42]['strength'] = $strength;
            $pdo->encounters[91] = task4Encounter(['player_actions_remaining' => 1, 'next_enemy_decision_timeline_ms' => 9000, 'enemy_ai_initialized_timeline_ms' => 0]);
            $clock = new Task4MutableCombatClock(new DateTimeImmutable('2026-09-01 12:00:00.000000', new DateTimeZone('UTC')));
            $random = new Task6SequenceRandomSource([100]);
            $ring = equipmentItem(90, 'ring', [
                ['modifier_type' => 'damage_percent_bp', 'modifier_operation' => 'additive_percent', 'rolled_value' => $percent],
            ]) + ['equipment_slot' => 'ring_1'];
            $service = CombatBootstrap::serviceForRepository(new CombatRepository($pdo), $clock, persistentEquipmentProvider([$ring]), $random);
            $token = equipmentToken(2450 + $strength);
            $state = $service->startPlayerAction(7, 42, 'prototype_weapon_attack', $token);
            assertSameValue('pending', $state['player_actions'][0]['state'], 'Normal attack starts unarmed.');
            $action = $pdo->actions[1];
            assertSameValue('weapon', $action['action_kind'], 'Existing action kind is retained.');
            assertSameValue(null, $action['snapshot_weapon_item_id'], 'No fake item persisted.');
            assertSameValue(null, $action['snapshot_weapon_key'], 'No fake weapon key persisted.');
            assertSameValue('physical', $action['snapshot_damage_type'], 'Physical snapshot.');
            assertSameValue($expectedDamage, $action['snapshot_base_damage'], 'No prototype damage.');
            assertSameValue([3000, 4000, 5500], [$action['started_timeline_ms'], $action['resolves_timeline_ms'], $action['cooldown_ready_timeline_ms']], 'Existing duration and cooldown.');
            assertSameValue(0, $pdo->encounters[91]['player_actions_remaining'], 'One allowance consumed.');
            $service->startPlayerAction(7, 42, 'prototype_weapon_attack', $token);
            assertSameValue(1, count($pdo->actions), 'Replay is idempotent.');
            $pdo->characters[42]['strength'] = 99;
            $clock->set(new DateTimeImmutable('2026-09-01 12:00:01.000000', new DateTimeZone('UTC')));
            $service->state(7, 42);
            assertSameValue('resolved', $pdo->actions[1]['state'], 'Unarmed action resolves.');
            assertSameValue($expectedDamage, $pdo->actions[1]['resolved_damage'], 'Resolution uses immutable unarmed snapshot.');
            assertSameValue(73 - $expectedDamage, $pdo->encounters[91]['enemy_current_hp'], 'Damage applies normally.');
            $service->state(7, 42);
            assertSameValue(73 - $expectedDamage, $pdo->encounters[91]['enemy_current_hp'], 'No repeated damage.');
        }
    },
];

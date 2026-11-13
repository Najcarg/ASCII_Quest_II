<?php
declare(strict_types=1);

return [
    'Effective damage preserves Basic Mace range and physical melee contribution' => function (): void {
        $weapon = ['snapshot_damage_min' => 5, 'snapshot_damage_max' => 7, 'snapshot_damage_type' => 'physical'];
        assertSameValue(['min' => 8, 'max' => 10, 'type' => 'physical'], EffectiveWeaponDamage::calculate($weapon, 3, 0, 0), 'Illustrative mace range.');
        assertSameValue(['min' => 10, 'max' => 12, 'type' => 'physical'], EffectiveWeaponDamage::calculate($weapon, 3, 2, 0), 'Flat damage adds to both endpoints.');
        assertSameValue(['min' => 12, 'max' => 15, 'type' => 'physical'], EffectiveWeaponDamage::calculate($weapon, 3, 0, 5000), 'Percent includes melee on both endpoints.');
        assertSameValue(['min' => 15, 'max' => 18, 'type' => 'physical'], EffectiveWeaponDamage::calculate($weapon, 3, 2, 5000), 'Flat before percent on both endpoints.');
        assertSameValue(['min' => 8, 'max' => 8, 'type' => 'physical'], EffectiveWeaponDamage::calculate(null, 3, 2, 5000), 'Explicit unarmed uses melee and global modifiers.');
    },
    'Basic Mace display and combat increase with Champion Strength' => function (): void {
        $weapon = equipmentItem(77, 'weapon') + ['equipment_slot' => 'weapon', 'definition_key' => 'basic_mace', 'snapshot_damage_min' => 5, 'snapshot_damage_max' => 7, 'snapshot_damage_type' => 'physical'];
        $provider = persistentEquipmentProvider([$weapon]);
        $character = combatEquipmentCharacter();
        assertSameValue(['min' => 10, 'max' => 12, 'type' => 'physical'], $provider->characterStats($character)['combat']['attack_damage'], 'Mace plus configured contribution.');
        assertSameValue(11, $provider->offensiveSnapshot($character, 'prototype_weapon_attack')['snapshot_base_damage'], 'Mace combat midpoint.');
        $character['strength']++;
        assertSameValue('11–13 Physical', $provider->characterStats($character)['combat']['attack_damage_display'], 'Strength updates displayed attack.');
        assertSameValue(12, $provider->offensiveSnapshot($character, 'prototype_weapon_attack')['snapshot_base_damage'], 'Strength updates actual attack.');
    },
    'Effective damage keeps elemental weapons independent of physical melee' => function (): void {
        $weapon = ['snapshot_damage_min' => 5, 'snapshot_damage_max' => 7, 'snapshot_damage_type' => 'fire'];
        assertSameValue(['min' => 7, 'max' => 9, 'type' => 'fire'], EffectiveWeaponDamage::calculate($weapon, 30, 2, 0), 'Elemental base and flat preserved.');
    },
    'Effective damage clamps percent and rejects invalid ranges' => function (): void {
        $weapon = ['snapshot_damage_min' => '5', 'snapshot_damage_max' => '7', 'snapshot_damage_type' => 'physical'];
        assertSameValue(['min' => 0, 'max' => 0, 'type' => 'physical'], EffectiveWeaponDamage::calculate($weapon, 3, 0, -20000), 'Damage cannot be negative.');
        $weapon['snapshot_damage_min'] = 8;
        try { EffectiveWeaponDamage::calculate($weapon, 3, 0, 0); } catch (DomainException) { return; }
        throw new RuntimeException('Invalid range must fail closed.');
    },
    'Equipment stats equip and unequip share combat damage authority' => function (): void {
        $weapon = equipmentItem(77, 'weapon', [
            ['modifier_type' => 'strength', 'modifier_operation' => 'flat', 'rolled_value' => 1],
            ['modifier_type' => 'flat_damage', 'modifier_operation' => 'flat', 'rolled_value' => 2],
            ['modifier_type' => 'damage_percent_bp', 'modifier_operation' => 'additive_percent', 'rolled_value' => 1000],
        ]) + ['equipment_slot' => 'weapon', 'definition_key' => 'basic_mace', 'snapshot_damage_min' => 5, 'snapshot_damage_max' => 7, 'snapshot_damage_type' => 'physical'];
        $character = combatEquipmentCharacter();
        $reader = new FakeEquipmentStore($character, [$weapon]);
        $service = equipmentService($reader);
        $provider = new PersistentCombatEquipmentProvider($reader, CombatDefinitionRegistry::fromDefaultConfig(), new EquipmentStatAggregator());
        assertSameValue(['min' => 5, 'max' => 5, 'type' => 'physical'], $provider->characterStats($character)['combat']['attack_damage'], 'Initially unarmed.');
        $service->equip(7, 42, 77, 'weapon', equipmentToken(2301));
        $stats = $provider->characterStats($character);
        assertSameValue(6, $stats['combat']['melee_damage'], 'Equipped Strength contributes one per point.');
        assertSameValue(['min' => 14, 'max' => 17, 'type' => 'physical'], $stats['combat']['attack_damage'], 'Equip updates both endpoints.');
        assertSameValue('14–17 Physical', $stats['combat']['attack_damage_display'], 'Server formats equipped range.');
        $snapshot = $provider->offensiveSnapshot($character, 'prototype_weapon_attack');
        assertSameValue(16, $snapshot['snapshot_base_damage'], 'Combat uses midpoint of authoritative endpoints.');
        assertFloatValue((float) $stats['combat']['accuracy'], $snapshot['snapshot_accuracy'], 'Accuracy unchanged.');
        assertFloatValue((float) $stats['combat']['critical_chance'], $snapshot['snapshot_critical_chance'], 'Critical chance unchanged.');
        assertSameValue($stats['combat']['critical_damage'], $snapshot['snapshot_critical_damage'], 'Critical damage unchanged.');
        assertSameValue(1000, $provider->effectiveDurationMs($character, 'prototype_weapon_attack', 1000), 'Attack rate unchanged.');
        $service->unequip(7, 42, 77, equipmentToken(2302));
        assertSameValue(['min' => 5, 'max' => 5, 'type' => 'physical'], $provider->characterStats($character)['combat']['attack_damage'], 'Unequip restores unarmed range.');
        assertSameValue('5 Physical', $provider->characterStats($character)['combat']['attack_damage_display'], 'Server formats unequipped state.');
    },
];

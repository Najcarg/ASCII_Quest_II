<?php
declare(strict_types=1);

foreach (['CombatEquipmentProvider.php', 'CombatDefinitionRegistry.php', 'CharacterStats.php', 'EquipmentStatAggregator.php', 'PersistentCombatEquipmentProvider.php'] as $library) {
    $path = __DIR__ . '/../ascii-quest/lib/' . $library;
    if (is_file($path)) { require_once $path; }
}

final class FakeCombatEquipmentReader
{
    public function __construct(public array $items) {}
    public function lockEquippedItems(int $characterId): array { return $this->items; }
}

function persistentEquipmentProvider(array $items): object
{
    if (!class_exists('PersistentCombatEquipmentProvider')) {
        throw new RuntimeException('PersistentCombatEquipmentProvider must exist.');
    }
    return new PersistentCombatEquipmentProvider(
        new FakeCombatEquipmentReader($items),
        CombatDefinitionRegistry::fromDefaultConfig(),
        new EquipmentStatAggregator(),
    );
}

function combatEquipmentCharacter(): array
{
    return equipmentCharacter(['id' => 42]);
}

return [
    'Persistent combat provider snapshots equipped weapon identity and active offense' => function (): void {
        $weapon = equipmentItem(77, 'weapon', [
            ['modifier_type' => 'flat_damage', 'modifier_operation' => 'flat', 'rolled_value' => 2],
            ['modifier_type' => 'damage_percent_bp', 'modifier_operation' => 'additive_percent', 'rolled_value' => 1000],
            ['modifier_type' => 'critical_chance', 'modifier_operation' => 'flat', 'rolled_value' => 3],
        ]);
        $weapon += [
            'definition_key' => 'worn_axe', 'snapshot_damage_type' => 'physical',
            'snapshot_damage_min' => 8, 'snapshot_damage_max' => 12,
            'equipment_slot' => 'weapon',
        ];
        $snapshot = persistentEquipmentProvider([$weapon])->offensiveSnapshot(combatEquipmentCharacter(), 'prototype_weapon_attack');
        assertSameValue(77, $snapshot['snapshot_weapon_item_id'], 'Weapon item identity.');
        assertSameValue('worn_axe', $snapshot['snapshot_weapon_key'], 'Weapon definition identity.');
        assertSameValue('physical', $snapshot['snapshot_damage_type'], 'Damage type.');
        assertSameValue(13, $snapshot['snapshot_base_damage'], 'Base flat and percentage damage are resolved once.');
        assertFloatValue(18.0, $snapshot['snapshot_critical_chance'], 'Equipped critical value is snapshotted for future use only.');
    },

    'Persistent combat provider rejects weapon actions without a real equipped weapon' => function (): void {
        try {
            persistentEquipmentProvider([])->offensiveSnapshot(combatEquipmentCharacter(), 'prototype_weapon_attack');
        } catch (DomainException) {
            return;
        }
        throw new RuntimeException('Missing real weapon must not fall back to prototype damage.');
    },

    'Persistent combat provider applies attack and cast rate to action duration snapshots' => function (): void {
        $item = equipmentItem(1, 'weapon', [
            ['modifier_type' => 'attack_rate_bp', 'modifier_operation' => 'additive_percent', 'rolled_value' => 2500],
            ['modifier_type' => 'cast_rate_bp', 'modifier_operation' => 'additive_percent', 'rolled_value' => 1000],
        ]);
        $provider = persistentEquipmentProvider([$item]);
        assertSameValue(800, $provider->effectiveDurationMs(combatEquipmentCharacter(), 'prototype_weapon_attack', 1000), 'Weapon duration.');
        assertSameValue(909, $provider->effectiveDurationMs(combatEquipmentCharacter(), 'prototype_flame_strike', 1000), 'Skill duration.');
    },

    'Persistent combat defense reads current equipment while deferred mechanics stay inactive' => function (): void {
        $armour = equipmentItem(3, 'chest', [
            ['modifier_type' => 'fire_resistance', 'modifier_operation' => 'flat', 'rolled_value' => 8],
            ['modifier_type' => 'dodging', 'modifier_operation' => 'flat', 'rolled_value' => 20],
            ['modifier_type' => 'block_rate_bp', 'modifier_operation' => 'additive_percent', 'rolled_value' => 9000],
            ['modifier_type' => 'life_on_hit', 'modifier_operation' => 'flat', 'rolled_value' => 99],
        ]);
        $armour['snapshot_toughness'] = 4;
        $reader = new FakeCombatEquipmentReader([$armour]);
        $provider = new PersistentCombatEquipmentProvider($reader, CombatDefinitionRegistry::fromDefaultConfig(), new EquipmentStatAggregator());
        $defense = $provider->currentDefense(combatEquipmentCharacter());
        assertSameValue(16, $defense['toughness'], 'Current equipped toughness.');
        assertFloatValue(13.0, $defense['resistances']['fire'], 'Current equipped resistance.');
        assertFloatValue(32.0, $defense['dodging'], 'Dodge is projected but existing resolver remains authoritative.');
        assertSameValue(false, array_key_exists('block_rate', $defense), 'Block Rate does not activate a final formula.');
        assertSameValue(false, array_key_exists('life_on_hit', $defense), 'Life on hit does not activate.');

        $reader->items[0]['snapshot_toughness'] = 9;
        assertSameValue(21, $provider->currentDefense(combatEquipmentCharacter())['toughness'], 'Defense is read again at resolution time.');
    },

    'Combat action persistence accepts nullable immutable weapon item snapshots' => function (): void {
        $source = file_get_contents(__DIR__ . '/../ascii-quest/lib/CombatRepository.php');
        if (!is_string($source)
            || !str_contains($source, 'snapshot_weapon_item_id')
            || !str_contains($source, ':snapshot_weapon_item_id')) {
            throw new RuntimeException('CombatRepository must persist snapshot_weapon_item_id.');
        }
    },
];

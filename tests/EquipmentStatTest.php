<?php
declare(strict_types=1);

require_once __DIR__ . '/../ascii-quest/lib/CharacterStats.php';
$equipmentAggregatorPath = __DIR__ . '/../ascii-quest/lib/EquipmentStatAggregator.php';
if (is_file($equipmentAggregatorPath)) {
    require_once $equipmentAggregatorPath;
}

function baseEquipmentCharacter(): array
{
    return ['strength' => 5, 'dexterity' => 5, 'vitality' => 5, 'energy' => 5, 'fate' => 5];
}

return [
    'Equipment aggregation combines base snapshots and canonical affixes' => function (): void {
        $aggregator = new EquipmentStatAggregator();
        $modifiers = $aggregator->aggregate([
            [
                'id' => 9,
                'snapshot_toughness' => 4,
                'snapshot_attack_rate_modifier_bp' => 250,
                'snapshot_cast_rate_modifier_bp' => 0,
                'snapshot_block_rate_modifier_bp' => 100,
                'affixes' => [
                    ['modifier_type' => 'strength', 'modifier_operation' => 'flat', 'rolled_value' => 2],
                    ['modifier_type' => 'maximum_life', 'modifier_operation' => 'flat', 'rolled_value' => 7],
                    ['modifier_type' => 'damage_percent_bp', 'modifier_operation' => 'additive_percent', 'rolled_value' => 300],
                ],
            ],
            [
                'id' => 3,
                'snapshot_toughness' => 2,
                'snapshot_attack_rate_modifier_bp' => 500,
                'snapshot_cast_rate_modifier_bp' => 200,
                'snapshot_block_rate_modifier_bp' => 0,
                'affixes' => [
                    ['modifier_type' => 'strength', 'modifier_operation' => 'flat', 'rolled_value' => 1],
                    ['modifier_type' => 'maximum_life', 'modifier_operation' => 'flat', 'rolled_value' => 5],
                ],
            ],
        ]);

        assertSameValue(3, $modifiers['strength'], 'Strength stacks independently of input order.');
        assertSameValue(12, $modifiers['maximum_life'], 'Maximum Life stacks.');
        assertSameValue(6, $modifiers['toughness'], 'Snapshot toughness stacks.');
        assertSameValue(750, $modifiers['attack_rate_bp'], 'Base attack rate stacks in basis points.');
        assertSameValue(200, $modifiers['cast_rate_bp'], 'Base cast rate stacks in basis points.');
        assertSameValue(100, $modifiers['block_rate_bp'], 'Base block rate stacks but stays passive.');
        assertSameValue(300, $modifiers['damage_percent_bp'], 'Percentage modifiers stay in basis points.');
        assertSameValue($modifiers, $aggregator->aggregate(array_reverse([
            [
                'id' => 9, 'snapshot_toughness' => 4,
                'snapshot_attack_rate_modifier_bp' => 250, 'snapshot_cast_rate_modifier_bp' => 0,
                'snapshot_block_rate_modifier_bp' => 100,
                'affixes' => [
                    ['modifier_type' => 'strength', 'modifier_operation' => 'flat', 'rolled_value' => 2],
                    ['modifier_type' => 'maximum_life', 'modifier_operation' => 'flat', 'rolled_value' => 7],
                    ['modifier_type' => 'damage_percent_bp', 'modifier_operation' => 'additive_percent', 'rolled_value' => 300],
                ],
            ],
            [
                'id' => 3, 'snapshot_toughness' => 2,
                'snapshot_attack_rate_modifier_bp' => 500, 'snapshot_cast_rate_modifier_bp' => 200,
                'snapshot_block_rate_modifier_bp' => 0,
                'affixes' => [
                    ['modifier_type' => 'strength', 'modifier_operation' => 'flat', 'rolled_value' => 1],
                    ['modifier_type' => 'maximum_life', 'modifier_operation' => 'flat', 'rolled_value' => 5],
                ],
            ],
        ])), 'Aggregation must not depend on repository row order.');
    },

    'CharacterStats applies active equipment modifiers once through its authority' => function (): void {
        $stats = CharacterStats::calculate(baseEquipmentCharacter(), [
            'strength' => 2,
            'dexterity' => 1,
            'vitality' => 3,
            'energy' => 4,
            'fate' => 5,
            'maximum_life' => 9,
            'maximum_mana' => 11,
            'toughness' => 6,
            'fire_resistance' => 7,
            'lightning_resistance' => 8,
            'poison_resistance' => 9,
            'cold_resistance' => 10,
            'attack_rate_bp' => 1250,
            'cast_rate_bp' => 500,
            'block_rate_bp' => 250,
            'critical_chance' => 4,
            'critical_damage' => 3,
            'dodging' => 2,
            'life_on_hit' => 12,
            'flat_damage' => 0,
            'damage_percent_bp' => 0,
        ]);

        assertSameValue(7, $stats['main']['strength'], 'Equipped Strength.');
        assertSameValue(189, $stats['resources']['max_life'], 'Vitality and direct Life.');
        assertSameValue(246, $stats['resources']['max_mana'], 'Energy and direct Mana.');
        assertSameValue(22, $stats['combat']['toughness'], 'Strength and direct Toughness.');
        assertFloatValue(14.0, $stats['resistances']['fire'], 'Strength and direct resistance.');
        assertFloatValue(1.125, $stats['rates']['attack_rate'], 'Attack factor.');
        assertFloatValue(1.05, $stats['rates']['cast_rate'], 'Cast factor.');
        assertFloatValue(1.025, $stats['rates']['block_rate'], 'Passive block factor projection.');
        assertSameValue(12, $stats['utility']['life_on_hit'], 'Deferred modifier is aggregated but not resolved.');
    },

    'Equipment aggregation rejects unknown modifier vocabulary' => function (): void {
        try {
            (new EquipmentStatAggregator())->aggregate([[
                'id' => 1,
                'snapshot_toughness' => 0,
                'snapshot_attack_rate_modifier_bp' => 0,
                'snapshot_cast_rate_modifier_bp' => 0,
                'snapshot_block_rate_modifier_bp' => 0,
                'affixes' => [[
                    'modifier_type' => 'free_invincibility',
                    'modifier_operation' => 'flat',
                    'rolled_value' => 1,
                ]],
            ]]);
        } catch (UnexpectedValueException) {
            return;
        }
        throw new RuntimeException('Unknown modifier keys must be rejected.');
    },

    'Empty equipment modifiers preserve the legacy CharacterStats result exactly' => function (): void {
        $character = baseEquipmentCharacter();
        assertSameValue(CharacterStats::calculate($character), CharacterStats::calculate($character, []), 'Legacy result.');
    },
];

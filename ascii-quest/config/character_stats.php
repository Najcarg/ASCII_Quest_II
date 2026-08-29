<?php
declare(strict_types=1);

return [
    'main_stat_base' => 5,

    'base' => [
        'life' => 100,
        'mana' => 100,
        'melee_damage' => 5,
        'toughness' => 2,
        'dodging' => 2.0,
        'accuracy' => 5.0,
        'critical_damage' => 5,
        'spell_power' => 5,
        'critical_chance' => 5.0,
        'fire_resistance' => 0.0,
        'lightning_resistance' => 0.0,
        'poison_resistance' => 0.0,
        'cold_resistance' => 0.0,
        'loot_chance' => 1.0,
        'gold_find' => 1.0,
        'action' => 1,
        'attack_rate' => 1.00,
        'cast_rate' => 1.00,
        'block_rate' => 1.00,
    ],

    'per_point' => [
        'strength' => [
            'melee_damage' => 5,
            'toughness' => 2,
            'fire_resistance' => 1.0,
        ],
        'dexterity' => [
            'dodging' => 2.0,
            'accuracy' => 2.0,
            'critical_damage' => 3,
            'lightning_resistance' => 1.0,
        ],
        'vitality' => [
            'life' => 10,
            'poison_resistance' => 1.0,
        ],
        'energy' => [
            'mana' => 15,
            'spell_power' => 5,
            'cold_resistance' => 1.0,
        ],
        'fate' => [
            'critical_chance' => 2.0,
            'loot_chance' => 1.0,
            'gold_find' => 1.0,
        ],
    ],

    'caps' => [
        'fire_resistance' => 90.0,
        'lightning_resistance' => 90.0,
        'poison_resistance' => 90.0,
        'cold_resistance' => 90.0,
        'dodging' => 90.0,
        'accuracy' => 90.0,
        'critical_chance' => 90.0,
        'loot_chance' => 90.0,
        'status_effect_chance' => 90.0,
    ],

    'status_effect' => [
        'divisor' => 100.0,
    ],
];

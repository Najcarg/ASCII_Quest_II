<?php
declare(strict_types=1);

/*
 * Combat Milestone 1 foundation-only prototype values.
 *
 * These values exist to prove the combat contracts. They are not final
 * balance values or permanent combat formulas.
 */
return [
    'foundation_only' => true,
    'turn_duration_seconds' => 10.0,
    'max_disconnected_catchup_seconds' => 5.0,

    'prototype_encounters' => [
        'deep_cave_01_cave_brute' => [
            'id' => 'deep_cave_01_cave_brute',
            'map_key' => 'deep_cave_01',
            'map_file' => 'deep_cave.json',
            'enemy_key' => 'cave_brute',
            'x' => 20,
            'y' => 12,
            'glyph' => 'B',
            'stationary' => true,
            'fighting_range' => 1,
            'range_shape' => 'orthogonal',
        ],
    ],

    'prototype_balance' => [
        'player_actions' => [
            'prototype_weapon_attack' => [
                'key' => 'prototype_weapon_attack',
                'name' => 'Weapon Attack',
                'kind' => 'weapon',
                'damage_type' => 'physical',
                'duration_seconds' => 1.0,
                'cooldown_seconds' => 2.5,
                'prototype_damage' => 20,
            ],
            'prototype_flame_strike' => [
                'key' => 'prototype_flame_strike',
                'name' => 'Flame Strike',
                'kind' => 'skill',
                'damage_type' => 'fire',
                'duration_seconds' => 1.5,
                'cooldown_seconds' => 5.0,
                'prototype_damage' => 24,
                'effect' => [
                    'key' => 'prototype_burning',
                    'duration_seconds' => 4.0,
                ],
            ],
        ],

        'potions' => [
            'prototype_health_potion' => [
                'key' => 'prototype_health_potion',
                'name' => 'Health Potion',
                'charges' => 1,
                'prototype_healing' => 50,
            ],
        ],

        'enemies' => [
            'cave_brute' => [
                'key' => 'cave_brute',
                'name' => 'Cave Brute',
                'glyph' => 'B',
                'maximum_hp' => 120,
                'action' => 2,
                'actions' => [
                    'smash' => [
                        'key' => 'smash',
                        'name' => 'Smash',
                        'kind' => 'attack',
                        'damage_type' => 'physical',
                        'duration_seconds' => 1.5,
                        'server_only' => [
                            'cooldown_seconds' => 3.0,
                            'prototype_damage' => 18,
                        ],
                    ],
                    'fire_slam' => [
                        'key' => 'fire_slam',
                        'name' => 'Fire Slam',
                        'kind' => 'skill',
                        'damage_type' => 'fire',
                        'duration_seconds' => 2.0,
                        'server_only' => [
                            'cooldown_seconds' => 6.0,
                            'prototype_damage' => 24,
                        ],
                    ],
                ],
                'block' => [
                    'key' => 'basic_block',
                    'name' => 'Basic Block',
                    'server_only' => [
                        'prototype_chance_percent' => 20,
                        'prototype_reduction_percent' => 50,
                    ],
                ],
                'server_only' => [
                    'prototype_gold_reward' => 25,
                    'prototype_raw_exp_reward' => 40,
                ],
            ],
        ],
    ],
];

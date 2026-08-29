<?php
declare(strict_types=1);

require_once __DIR__ . '/../ascii-quest/lib/CharacterStats.php';

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ' Expected ' . var_export($expected, true) .
            ', got ' . var_export($actual, true)
        );
    }
}

function assertFloatValue(float $expected, float $actual, string $message): void
{
    if (abs($expected - $actual) > 0.00001) {
        throw new RuntimeException("{$message} Expected {$expected}, got {$actual}");
    }
}

function calculateCharacter(array $main): array
{
    return CharacterStats::calculate($main);
}

return [
    'Warrior starting stats' => function (): void {
        $stats = calculateCharacter([
            'strength' => 10,
            'dexterity' => 5,
            'vitality' => 10,
            'energy' => 5,
            'fate' => 5,
        ]);

        assertSameValue(200, $stats['resources']['max_life'], 'Warrior Life.');
        assertSameValue(175, $stats['resources']['max_mana'], 'Warrior Mana.');
        assertSameValue(55, $stats['combat']['melee_damage'], 'Warrior Melee Damage.');
        assertSameValue(22, $stats['combat']['toughness'], 'Warrior Toughness.');
        assertSameValue(30, $stats['combat']['spell_power'], 'Warrior Spell Power.');
        assertFloatValue(12.0, $stats['combat']['dodging'], 'Warrior Dodging.');
        assertFloatValue(15.0, $stats['combat']['accuracy'], 'Warrior Accuracy.');
        assertSameValue(20, $stats['combat']['critical_damage'], 'Warrior Critical Damage.');
        assertFloatValue(15.0, $stats['combat']['critical_chance'], 'Warrior Critical Chance.');
        assertFloatValue(10.0, $stats['resistances']['fire'], 'Warrior Fire Resistance.');
        assertFloatValue(5.0, $stats['resistances']['lightning'], 'Warrior Lightning Resistance.');
        assertFloatValue(10.0, $stats['resistances']['poison'], 'Warrior Poison Resistance.');
        assertFloatValue(5.0, $stats['resistances']['cold'], 'Warrior Cold Resistance.');
        assertFloatValue(6.0, $stats['fortune']['loot_chance'], 'Warrior Loot Chance.');
        assertFloatValue(6.0, $stats['fortune']['gold_find'], 'Warrior Gold Find.');
    },

    'Mage starting stats' => function (): void {
        $stats = calculateCharacter([
            'strength' => 5,
            'dexterity' => 5,
            'vitality' => 5,
            'energy' => 10,
            'fate' => 10,
        ]);

        assertSameValue(150, $stats['resources']['max_life'], 'Mage Life.');
        assertSameValue(250, $stats['resources']['max_mana'], 'Mage Mana.');
        assertSameValue(30, $stats['combat']['melee_damage'], 'Mage Melee Damage.');
        assertSameValue(12, $stats['combat']['toughness'], 'Mage Toughness.');
        assertSameValue(55, $stats['combat']['spell_power'], 'Mage Spell Power.');
        assertFloatValue(25.0, $stats['combat']['critical_chance'], 'Mage Critical Chance.');
        assertFloatValue(11.0, $stats['fortune']['loot_chance'], 'Mage Loot Chance.');
        assertFloatValue(11.0, $stats['fortune']['gold_find'], 'Mage Gold Find.');
    },

    'Rogue starting stats' => function (): void {
        $stats = calculateCharacter([
            'strength' => 5,
            'dexterity' => 10,
            'vitality' => 10,
            'energy' => 5,
            'fate' => 5,
        ]);

        assertSameValue(200, $stats['resources']['max_life'], 'Rogue Life.');
        assertSameValue(175, $stats['resources']['max_mana'], 'Rogue Mana.');
        assertFloatValue(22.0, $stats['combat']['dodging'], 'Rogue Dodging.');
        assertFloatValue(25.0, $stats['combat']['accuracy'], 'Rogue Accuracy.');
        assertSameValue(35, $stats['combat']['critical_damage'], 'Rogue Critical Damage.');
        assertFloatValue(10.0, $stats['resistances']['lightning'], 'Rogue Lightning Resistance.');
    },

    'Cleric starting stats' => function (): void {
        $stats = calculateCharacter([
            'strength' => 5,
            'dexterity' => 5,
            'vitality' => 10,
            'energy' => 8,
            'fate' => 7,
        ]);

        assertSameValue(200, $stats['resources']['max_life'], 'Cleric Life.');
        assertSameValue(220, $stats['resources']['max_mana'], 'Cleric Mana.');
        assertSameValue(45, $stats['combat']['spell_power'], 'Cleric Spell Power.');
        assertFloatValue(19.0, $stats['combat']['critical_chance'], 'Cleric Critical Chance.');
        assertFloatValue(8.0, $stats['fortune']['loot_chance'], 'Cleric Loot Chance.');
        assertFloatValue(8.0, $stats['fortune']['gold_find'], 'Cleric Gold Find.');
    },

    '90 percent caps' => function (): void {
        $stats = calculateCharacter([
            'strength' => 200,
            'dexterity' => 200,
            'vitality' => 200,
            'energy' => 200,
            'fate' => 200,
        ]);

        assertFloatValue(90.0, $stats['resistances']['fire'], 'Fire cap.');
        assertFloatValue(90.0, $stats['resistances']['lightning'], 'Lightning cap.');
        assertFloatValue(90.0, $stats['resistances']['poison'], 'Poison cap.');
        assertFloatValue(90.0, $stats['resistances']['cold'], 'Cold cap.');
        assertFloatValue(90.0, $stats['combat']['dodging'], 'Dodging cap.');
        assertFloatValue(90.0, $stats['combat']['accuracy'], 'Accuracy cap.');
        assertFloatValue(90.0, $stats['combat']['critical_chance'], 'Critical Chance cap.');
        assertFloatValue(90.0, $stats['fortune']['loot_chance'], 'Loot Chance cap.');
    },

    'Gold Find remains uncapped' => function (): void {
        $stats = calculateCharacter([
            'strength' => 5,
            'dexterity' => 5,
            'vitality' => 5,
            'energy' => 5,
            'fate' => 200,
        ]);

        assertFloatValue(201.0, $stats['fortune']['gold_find'], 'Gold Find.');
    },

    'Action and rates start at base values' => function (): void {
        $stats = calculateCharacter([
            'strength' => 5,
            'dexterity' => 5,
            'vitality' => 5,
            'energy' => 5,
            'fate' => 5,
        ]);

        assertSameValue(1, $stats['rates']['action'], 'Action.');
        assertFloatValue(1.00, $stats['rates']['attack_rate'], 'Attack Rate.');
        assertFloatValue(1.00, $stats['rates']['cast_rate'], 'Cast Rate.');
        assertFloatValue(1.00, $stats['rates']['block_rate'], 'Block Rate.');
    },

    'Utility values start at zero' => function (): void {
        $stats = calculateCharacter([
            'strength' => 5,
            'dexterity' => 5,
            'vitality' => 5,
            'energy' => 5,
            'fate' => 5,
        ]);

        foreach ([
            'life_regeneration', 'mana_regeneration', 'life_on_hit', 'mana_on_hit',
            'life_per_kill', 'mana_per_kill', 'fire_damage', 'lightning_damage',
            'cold_damage', 'poison_damage', 'bleed_damage', 'burn_damage',
            'freeze_damage', 'shock_damage'
        ] as $key) {
            assertSameValue(0, $stats['utility'][$key], "Utility {$key}.");
        }

        assertFloatValue(0.0, $stats['utility']['status_effect_chance'], 'Initial status chance.');
    },

    'Status effect chance uses highest status damage' => function (): void {
        $chance = CharacterStats::statusEffectChance([
            'poison' => 50,
            'bleed' => 300,
            'burn' => 800,
            'freeze' => 0,
            'shock' => 200,
        ]);

        assertFloatValue(8.0, $chance, 'Status chance from highest damage.');
    },

    'Status effect chance is capped at 90 percent' => function (): void {
        $chance = CharacterStats::statusEffectChance([
            'poison' => 0,
            'bleed' => 0,
            'burn' => 12000,
            'freeze' => 0,
            'shock' => 0,
        ]);

        assertFloatValue(90.0, $chance, 'Status chance cap.');
    },

    'Negative main stat is rejected' => function (): void {
        try {
            calculateCharacter([
                'strength' => -1,
                'dexterity' => 5,
                'vitality' => 5,
                'energy' => 5,
                'fate' => 5,
            ]);
        } catch (InvalidArgumentException) {
            return;
        }

        throw new RuntimeException('Negative Strength should throw InvalidArgumentException.');
    },

    'Invalid main stat is rejected' => function (): void {
        try {
            calculateCharacter([
                'strength' => 5,
                'dexterity' => 5,
                'vitality' => 5,
                'energy' => 5,
                'fate' => 'abc',
            ]);
        } catch (InvalidArgumentException) {
            return;
        }

        throw new RuntimeException('Non-numeric Fate should throw InvalidArgumentException.');
    },

    'All class starting bonuses' => function (): void {
        $cases = [
            'Warrior' => [[5, 0, 5, 0, 0], [10, 5, 10, 5, 5]],
            'Mage' => [[0, 0, 0, 5, 5], [5, 5, 5, 10, 10]],
            'Rogue' => [[0, 5, 5, 0, 0], [5, 10, 10, 5, 5]],
            'Cleric' => [[0, 0, 5, 3, 2], [5, 5, 10, 8, 7]],
        ];

        foreach ($cases as $className => [$bonuses, $expected]) {
            [$str, $dex, $vit, $ene, $fate] = $bonuses;

            $actual = CharacterStats::startingMainStats([
                'start_strength_bonus' => $str,
                'start_dexterity_bonus' => $dex,
                'start_vitality_bonus' => $vit,
                'start_energy_bonus' => $ene,
                'start_fate_bonus' => $fate,
            ]);

            assertSameValue([
                'strength' => $expected[0],
                'dexterity' => $expected[1],
                'vitality' => $expected[2],
                'energy' => $expected[3],
                'fate' => $expected[4],
            ], $actual, "{$className} main stats.");
        }
    },

    'Class bonuses produce Warrior main stats' => function (): void {
        $main = CharacterStats::startingMainStats([
            'start_strength_bonus' => 5,
            'start_dexterity_bonus' => 0,
            'start_vitality_bonus' => 5,
            'start_energy_bonus' => 0,
            'start_fate_bonus' => 0,
        ]);

        assertSameValue([
            'strength' => 10,
            'dexterity' => 5,
            'vitality' => 10,
            'energy' => 5,
            'fate' => 5,
        ], $main, 'Warrior main stats.');
    },
];

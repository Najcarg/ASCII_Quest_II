<?php
declare(strict_types=1);

final class CharacterStats
{
    private static ?array $config = null;

    public static function startingMainStats(array $class): array
    {
        $base = (int) self::config()['main_stat_base'];

        return [
            'strength' => $base + self::readNonNegativeInt($class, 'start_strength_bonus'),
            'dexterity' => $base + self::readNonNegativeInt($class, 'start_dexterity_bonus'),
            'vitality' => $base + self::readNonNegativeInt($class, 'start_vitality_bonus'),
            'energy' => $base + self::readNonNegativeInt($class, 'start_energy_bonus'),
            'fate' => $base + self::readNonNegativeInt($class, 'start_fate_bonus'),
        ];
    }

    public static function calculate(array $character, array $equipmentModifiers = []): array
    {
        $config = self::config();
        $base = $config['base'];

        $modifier = static function (string $key) use ($equipmentModifiers): int {
            $value = $equipmentModifiers[$key] ?? 0;
            if (!is_int($value)) {
                throw new InvalidArgumentException("Invalid equipment modifier: {$key}");
            }
            return $value;
        };

        $strength = max(0, self::readNonNegativeInt($character, 'strength') + $modifier('strength'));
        $dexterity = max(0, self::readNonNegativeInt($character, 'dexterity') + $modifier('dexterity'));
        $vitality = max(0, self::readNonNegativeInt($character, 'vitality') + $modifier('vitality'));
        $energy = max(0, self::readNonNegativeInt($character, 'energy') + $modifier('energy'));
        $fate = max(0, self::readNonNegativeInt($character, 'fate') + $modifier('fate'));

        $maxLife = max(0, $base['life'] + ($vitality * $config['per_point']['vitality']['life']) + $modifier('maximum_life'));
        $maxMana = max(0, $base['mana'] + ($energy * $config['per_point']['energy']['mana']) + $modifier('maximum_mana'));

        $meleeDamage = $base['melee_damage'] + ($strength * $config['per_point']['strength']['melee_damage']);
        $toughness = max(0, $base['toughness'] + ($strength * $config['per_point']['strength']['toughness']) + $modifier('toughness'));
        $dodging = self::cap('dodging', max(0.0, $base['dodging'] + ($dexterity * $config['per_point']['dexterity']['dodging']) + $modifier('dodging')));
        $accuracy = self::cap('accuracy', max(0.0, $base['accuracy'] + ($dexterity * $config['per_point']['dexterity']['accuracy']) + $modifier('accuracy')));
        $criticalDamage = max(0, $base['critical_damage'] + ($dexterity * $config['per_point']['dexterity']['critical_damage']) + $modifier('critical_damage'));
        $criticalChance = self::cap('critical_chance', max(0.0, $base['critical_chance'] + ($fate * $config['per_point']['fate']['critical_chance']) + $modifier('critical_chance')));
        $spellPower = $base['spell_power'] + ($energy * $config['per_point']['energy']['spell_power']);

        $fireResistance = self::cap('fire_resistance', max(0.0, $base['fire_resistance'] + ($strength * $config['per_point']['strength']['fire_resistance']) + $modifier('fire_resistance')));
        $lightningResistance = self::cap('lightning_resistance', max(0.0, $base['lightning_resistance'] + ($dexterity * $config['per_point']['dexterity']['lightning_resistance']) + $modifier('lightning_resistance')));
        $poisonResistance = self::cap('poison_resistance', max(0.0, $base['poison_resistance'] + ($vitality * $config['per_point']['vitality']['poison_resistance']) + $modifier('poison_resistance')));
        $coldResistance = self::cap('cold_resistance', max(0.0, $base['cold_resistance'] + ($energy * $config['per_point']['energy']['cold_resistance']) + $modifier('cold_resistance')));

        $lootChance = self::cap('loot_chance', $base['loot_chance'] + ($fate * $config['per_point']['fate']['loot_chance']));
        $goldFind = $base['gold_find'] + ($fate * $config['per_point']['fate']['gold_find']);

        $utility = [
            'life_regeneration' => 0,
            'mana_regeneration' => 0,
            'life_on_hit' => max(0, $modifier('life_on_hit')),
            'mana_on_hit' => 0,
            'life_per_kill' => 0,
            'mana_per_kill' => 0,
            'fire_damage' => max(0, $modifier('fire_damage')),
            'lightning_damage' => max(0, $modifier('lightning_damage')),
            'cold_damage' => max(0, $modifier('cold_damage')),
            'poison_damage' => max(0, $modifier('poison_damage')),
            'bleed_damage' => max(0, $modifier('bleed_damage')),
            'burn_damage' => max(0, $modifier('burn_damage')),
            'freeze_damage' => max(0, $modifier('freeze_damage')),
            'shock_damage' => max(0, $modifier('shock_damage')),
        ];

        $utility['status_effect_chance'] = self::statusEffectChance([
            'poison' => $utility['poison_damage'],
            'bleed' => $utility['bleed_damage'],
            'burn' => $utility['burn_damage'],
            'freeze' => $utility['freeze_damage'],
            'shock' => $utility['shock_damage'],
        ]);

        return [
            'main' => [
                'strength' => $strength,
                'dexterity' => $dexterity,
                'vitality' => $vitality,
                'energy' => $energy,
                'fate' => $fate,
            ],
            'resources' => [
                'max_life' => $maxLife,
                'max_mana' => $maxMana,
            ],
            'combat' => [
                'melee_damage' => $meleeDamage,
                'toughness' => $toughness,
                'dodging' => $dodging,
                'accuracy' => $accuracy,
                'critical_damage' => $criticalDamage,
                'critical_chance' => $criticalChance,
                'spell_power' => $spellPower,
            ],
            'resistances' => [
                'fire' => $fireResistance,
                'lightning' => $lightningResistance,
                'poison' => $poisonResistance,
                'cold' => $coldResistance,
            ],
            'rates' => [
                'action' => (int) $base['action'],
                'attack_rate' => (float) $base['attack_rate'] * max(0.10, 1 + ($modifier('attack_rate_bp') / 10000)),
                'cast_rate' => (float) $base['cast_rate'] * max(0.10, 1 + ($modifier('cast_rate_bp') / 10000)),
                'block_rate' => (float) $base['block_rate'] * max(0.10, 1 + ($modifier('block_rate_bp') / 10000)),
            ],
            'fortune' => [
                'loot_chance' => $lootChance,
                'gold_find' => $goldFind,
            ],
            'utility' => $utility,
        ];
    }

    public static function statusEffectChance(array $statusDamage): float
    {
        foreach (['poison', 'bleed', 'burn', 'freeze', 'shock'] as $key) {
            if (!array_key_exists($key, $statusDamage) || !is_numeric($statusDamage[$key])) {
                throw new InvalidArgumentException("Invalid status damage value: {$key}");
            }

            if ((float) $statusDamage[$key] < 0) {
                throw new InvalidArgumentException("Status damage cannot be negative: {$key}");
            }
        }

        $highest = max(array_map('floatval', [
            $statusDamage['poison'],
            $statusDamage['bleed'],
            $statusDamage['burn'],
            $statusDamage['freeze'],
            $statusDamage['shock'],
        ]));

        $chance = $highest / (float) self::config()['status_effect']['divisor'];
        return self::cap('status_effect_chance', $chance);
    }

    private static function config(): array
    {
        if (self::$config === null) {
            self::$config = require __DIR__ . '/../config/character_stats.php';
        }

        return self::$config;
    }

    private static function readNonNegativeInt(array $data, string $key): int
    {
        if (!array_key_exists($key, $data)) {
            throw new InvalidArgumentException("Missing integer value: {$key}");
        }

        $value = $data[$key];

        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $integer = (int) $value;
        } else {
            throw new InvalidArgumentException("Invalid integer value: {$key}");
        }

        if ($integer < 0) {
            throw new InvalidArgumentException("Integer value cannot be negative: {$key}");
        }

        return $integer;
    }

    private static function cap(string $key, float $value): float
    {
        $cap = (float) self::config()['caps'][$key];
        return min($cap, $value);
    }
}

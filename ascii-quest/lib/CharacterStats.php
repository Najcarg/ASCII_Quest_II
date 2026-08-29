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

    public static function calculate(array $character): array
    {
        $config = self::config();
        $base = $config['base'];

        $strength = self::readNonNegativeInt($character, 'strength');
        $dexterity = self::readNonNegativeInt($character, 'dexterity');
        $vitality = self::readNonNegativeInt($character, 'vitality');
        $energy = self::readNonNegativeInt($character, 'energy');
        $fate = self::readNonNegativeInt($character, 'fate');

        $maxLife = $base['life'] + ($vitality * $config['per_point']['vitality']['life']);
        $maxMana = $base['mana'] + ($energy * $config['per_point']['energy']['mana']);

        $meleeDamage = $base['melee_damage'] + ($strength * $config['per_point']['strength']['melee_damage']);
        $toughness = $base['toughness'] + ($strength * $config['per_point']['strength']['toughness']);
        $dodging = self::cap('dodging', $base['dodging'] + ($dexterity * $config['per_point']['dexterity']['dodging']));
        $accuracy = self::cap('accuracy', $base['accuracy'] + ($dexterity * $config['per_point']['dexterity']['accuracy']));
        $criticalDamage = $base['critical_damage'] + ($dexterity * $config['per_point']['dexterity']['critical_damage']);
        $criticalChance = self::cap('critical_chance', $base['critical_chance'] + ($fate * $config['per_point']['fate']['critical_chance']));
        $spellPower = $base['spell_power'] + ($energy * $config['per_point']['energy']['spell_power']);

        $fireResistance = self::cap('fire_resistance', $base['fire_resistance'] + ($strength * $config['per_point']['strength']['fire_resistance']));
        $lightningResistance = self::cap('lightning_resistance', $base['lightning_resistance'] + ($dexterity * $config['per_point']['dexterity']['lightning_resistance']));
        $poisonResistance = self::cap('poison_resistance', $base['poison_resistance'] + ($vitality * $config['per_point']['vitality']['poison_resistance']));
        $coldResistance = self::cap('cold_resistance', $base['cold_resistance'] + ($energy * $config['per_point']['energy']['cold_resistance']));

        $lootChance = self::cap('loot_chance', $base['loot_chance'] + ($fate * $config['per_point']['fate']['loot_chance']));
        $goldFind = $base['gold_find'] + ($fate * $config['per_point']['fate']['gold_find']);

        $utility = [
            'life_regeneration' => 0,
            'mana_regeneration' => 0,
            'life_on_hit' => 0,
            'mana_on_hit' => 0,
            'life_per_kill' => 0,
            'mana_per_kill' => 0,
            'fire_damage' => 0,
            'lightning_damage' => 0,
            'cold_damage' => 0,
            'poison_damage' => 0,
            'bleed_damage' => 0,
            'burn_damage' => 0,
            'freeze_damage' => 0,
            'shock_damage' => 0,
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
                'attack_rate' => (float) $base['attack_rate'],
                'cast_rate' => (float) $base['cast_rate'],
                'block_rate' => (float) $base['block_rate'],
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

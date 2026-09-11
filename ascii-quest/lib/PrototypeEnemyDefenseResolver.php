<?php
declare(strict_types=1);

require_once __DIR__ . '/CombatRandomSource.php';
require_once __DIR__ . '/EnemyDefenseResolver.php';

final class PrototypeEnemyDefenseResolver implements EnemyDefenseResolver
{
    public function __construct(private CombatRandomSource $random)
    {
    }

    public function resolve(array $playerAction, array $enemyDefinition): array
    {
        $incomingDamage = self::requiredPositiveInteger(
            $playerAction,
            'snapshot_base_damage',
            'Player action base damage',
        );
        $block = $enemyDefinition['block']['server_only'] ?? null;
        if (!is_array($block)) {
            throw new InvalidArgumentException('Enemy Block configuration is required.');
        }

        $chancePercent = self::requiredPercentage(
            $block,
            'prototype_chance_percent',
            'Enemy Block chance',
        );
        $reductionPercent = self::requiredPercentage(
            $block,
            'prototype_reduction_percent',
            'Enemy Block reduction',
        );
        $blocked = $this->random->integer(1, 100) <= $chancePercent;
        $preventedDamage = $blocked
            ? (int) round($incomingDamage * $reductionPercent / 100)
            : 0;

        return [
            'incoming_damage' => $incomingDamage,
            'prevented_damage' => $preventedDamage,
            'applied_damage' => max(0, $incomingDamage - $preventedDamage),
            'blocked' => $blocked,
        ];
    }

    private static function requiredPositiveInteger(
        array $values,
        string $key,
        string $label,
    ): int {
        $value = self::integerValue($values[$key] ?? null, $label);
        if ($value <= 0) {
            throw new InvalidArgumentException("{$label} must be positive.");
        }

        return $value;
    }

    private static function requiredPercentage(array $values, string $key, string $label): int
    {
        $value = self::integerValue($values[$key] ?? null, $label);
        if ($value < 0 || $value > 100) {
            throw new InvalidArgumentException("{$label} must be between 0 and 100.");
        }

        return $value;
    }

    private static function integerValue(mixed $value, string $label): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            return (int) $value;
        }

        throw new InvalidArgumentException("{$label} must be an integer.");
    }
}

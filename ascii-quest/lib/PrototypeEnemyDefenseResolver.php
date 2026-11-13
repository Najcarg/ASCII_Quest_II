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
        $incomingDamage = self::requiredNonNegativeInteger(
            $playerAction,
            'snapshot_base_damage',
            'Player action base damage',
        );
        $hitChance = max(5, min(95, 50 + self::snapshotNumber($playerAction, 'snapshot_accuracy')));
        if ($this->random->integer(1, 100) > $hitChance) {
            return [
                'incoming_damage' => $incomingDamage,
                'prevented_damage' => 0,
                'applied_damage' => 0,
                'blocked' => false,
                'outcome' => 'MISS',
            ];
        }

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

        // Preserve enemy Block on normal damage, then scale the remainder once.
        $normalDamage = max(0, $incomingDamage - $preventedDamage);
        $criticalChance = max(0, min(100, self::snapshotNumber($playerAction, 'snapshot_critical_chance')));
        $critical = $this->random->integer(1, 100) <= $criticalChance;
        $appliedDamage = $critical
            ? max(0, (int) round($normalDamage * (1 + self::snapshotNumber($playerAction, 'snapshot_critical_damage') / 100)))
            : $normalDamage;

        return [
            'incoming_damage' => $incomingDamage,
            'prevented_damage' => $preventedDamage,
            'applied_damage' => $appliedDamage,
            'blocked' => $blocked,
            'outcome' => $critical ? 'CRITICAL' : ($blocked ? 'BLOCK' : 'HIT'),
        ];
    }

    private static function snapshotNumber(array $action, string $key): float
    {
        if (!array_key_exists($key, $action)) {
            throw new InvalidArgumentException("Player action {$key} snapshot is required.");
        }
        $value = $action[$key];
        if ($value === null) {
            // Approved compatibility for legacy pending SQL NULL snapshots only.
            // Never backfill them or substitute current Champion/equipment stats.
            if (($action['state'] ?? null) !== 'pending') {
                throw new InvalidArgumentException('Legacy NULL offense requires a pending action.');
            }
            return 0.0;
        }
        if (!is_numeric($value) || !is_finite((float) $value)) {
            throw new InvalidArgumentException("Player action {$key} must be finite numeric data.");
        }
        return (float) $value;
    }

    private static function requiredNonNegativeInteger(
        array $values,
        string $key,
        string $label,
    ): int {
        $value = self::integerValue($values[$key] ?? null, $label);
        if ($value < 0) {
            throw new InvalidArgumentException("{$label} cannot be negative.");
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

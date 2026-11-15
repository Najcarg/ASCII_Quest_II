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

        // Build from immutable action data only, after a successful hit roll.
        // Same-type native and bonus damage combine BEFORE resistance rounding.
        $components = $this->components($playerAction, $incomingDamage);
        $incomingDamage = array_sum($components);
        $resistedComponents = $components;
        $resistances = $enemyDefinition['server_only']['resistances'] ?? null;
        if (!is_array($resistances)) {
            throw new InvalidArgumentException('Enemy elemental resistances are required.');
        }
        foreach (['fire', 'lightning', 'cold', 'poison'] as $type) {
            $resistance = self::requiredPercentage($resistances, $type, 'Enemy elemental resistance');
            $resistedComponents[$type] = max(0, (int) round($components[$type] * (1 - $resistance / 100)));
        }
        $resistedDamage = array_sum($resistedComponents);
        $resistancePrevention = $incomingDamage - $resistedDamage;
        // Existing Shock only: new applications occur after direct damage and LoH.
        $shock = max(0, min(50, (int) ($enemyDefinition['server_only']['active_shock_potency'] ?? 0)));
        $resistedDamage = max(0, (int) round($resistedDamage * (1 + $shock / 100)));

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
        $blockedDamage = $blocked
            ? (int) round($resistedDamage * $reductionPercent / 100)
            : 0;

        // ONE Block on the resisted sum, then ONE critical on the remainder.
        $normalDamage = max(0, $resistedDamage - $blockedDamage);
        // Amplification is not negative prevention. Retain actual resistance
        // and Block mitigation, independent of Shock and critical bonus damage.
        $preventedDamage = $resistancePrevention + $blockedDamage;
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

    /** @return array{physical: int, fire: int, lightning: int, cold: int, poison: int} */
    private function components(array $action, int $nativeDamage): array
    {
        $components = array_fill_keys(['physical', 'fire', 'lightning', 'cold', 'poison'], 0);
        $type = $action['snapshot_damage_type'] ?? null;
        if (!is_string($type) || !array_key_exists($type, $components)) {
            throw new InvalidArgumentException('Player action native damage type is unsupported.');
        }
        $components[$type] = $nativeDamage;
        foreach (['fire', 'lightning', 'cold', 'poison'] as $element) {
            $key = 'snapshot_' . $element . '_damage';
            if (!array_key_exists($key, $action)) {
                throw new InvalidArgumentException('Player elemental snapshot is required.');
            }
            if ($action[$key] === null && ($action['state'] ?? null) === 'pending') {
                // Legacy pending SQL NULL only: neutral zero, never current equipment.
                continue;
            }
            $bonus = self::requiredNonNegativeInteger($action, $key, 'Player elemental snapshot');
            if (($action['action_kind'] ?? null) !== 'skill') {
                $components[$element] += $bonus;
            }
        }
        return $components;
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

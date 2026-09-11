<?php
declare(strict_types=1);

require_once __DIR__ . '/ChampionDamageResolver.php';

final class PrototypeChampionDamageResolver implements ChampionDamageResolver
{
    public function resolve(array $enemyAction, array $currentDefense): array
    {
        $incomingDamage = self::requiredPositiveInteger(
            $enemyAction,
            'snapshot_base_damage',
            'Enemy action base damage',
        );
        $damageType = $enemyAction['snapshot_damage_type'] ?? null;
        if (!is_string($damageType) || !in_array($damageType, ['physical', 'fire'], true)) {
            throw new InvalidArgumentException('Enemy action damage type is unsupported.');
        }

        $reductionFraction = match ($damageType) {
            'physical' => $this->physicalReduction($currentDefense),
            'fire' => $this->fireReduction($currentDefense),
        };
        $appliedDamage = max(
            1,
            (int) round($incomingDamage * (1 - $reductionFraction)),
        );

        return [
            'incoming_damage' => $incomingDamage,
            'prevented_damage' => $incomingDamage - $appliedDamage,
            'applied_damage' => $appliedDamage,
        ];
    }

    private function physicalReduction(array $currentDefense): float
    {
        $toughness = self::requiredNonNegativeNumber(
            $currentDefense['toughness'] ?? null,
            'Champion Toughness',
        );

        return $toughness / ($toughness + 100);
    }

    private function fireReduction(array $currentDefense): float
    {
        $resistances = $currentDefense['resistances'] ?? null;
        if (!is_array($resistances)) {
            throw new InvalidArgumentException('Champion resistances are required.');
        }
        $fireResistance = self::requiredNonNegativeNumber(
            $resistances['fire'] ?? null,
            'Champion Fire Resistance',
        );
        if ($fireResistance > 100) {
            throw new InvalidArgumentException('Champion Fire Resistance cannot exceed 100.');
        }

        return $fireResistance / 100;
    }

    private static function requiredPositiveInteger(
        array $values,
        string $key,
        string $label,
    ): int {
        $value = $values[$key] ?? null;
        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value <= 0) {
            throw new InvalidArgumentException("{$label} must be a positive integer.");
        }

        return $value;
    }

    private static function requiredNonNegativeNumber(mixed $value, string $label): float
    {
        if (!is_int($value) && !is_float($value) && !is_numeric($value)) {
            throw new InvalidArgumentException("{$label} must be numeric.");
        }
        $number = (float) $value;
        if (!is_finite($number) || $number < 0) {
            throw new InvalidArgumentException("{$label} cannot be negative.");
        }

        return $number;
    }
}

<?php
declare(strict_types=1);

/** Shared equipment attack range; combat may resolve its midpoint, never a separate formula. */
final class EffectiveWeaponDamage
{
    public static function equippedWeapon(array $items): ?array
    {
        foreach ($items as $item) {
            if (($item['equipment_slot'] ?? null) === 'weapon') {
                if (($item['snapshot_equipment_slot'] ?? null) !== 'weapon') {
                    throw new DomainException('Equipped weapon snapshot is invalid.');
                }
                return $item;
            }
        }
        return null;
    }

    public static function calculate(?array $weapon, int $meleeDamage, int $flatDamage, int $percentBp): array
    {
        // A missing real weapon is explicitly unarmed, not a synthetic item snapshot.
        $minimum = $weapon === null ? 0 : self::damage($weapon, 'snapshot_damage_min');
        $maximum = $weapon === null ? 0 : self::damage($weapon, 'snapshot_damage_max');
        $type = $weapon === null ? 'physical' : ($weapon['snapshot_damage_type'] ?? null);
        if ($minimum > $maximum || !is_string($type) || $type === '') {
            throw new DomainException('Equipped weapon damage is invalid.');
        }
        $contribution = $type === 'physical' ? $meleeDamage : 0;
        $factor = 1 + (max(-10000, $percentBp) / 10000);
        $apply = static fn (int $damage): int => max(0, (int) round(($damage + $contribution + $flatDamage) * $factor));
        return ['min' => $apply($minimum), 'max' => $apply($maximum), 'type' => $type];
    }

    public static function display(array $range): string
    {
        $damage = $range['min'] === $range['max'] ? (string) $range['min'] : $range['min'] . '–' . $range['max'];
        return $damage . ' ' . ucfirst($range['type']);
    }

    private static function damage(array $weapon, string $key): int
    {
        $value = $weapon[$key] ?? null;
        if (is_string($value) && ctype_digit($value)) { $value = (int) $value; }
        if (!is_int($value) || $value < 0) { throw new DomainException('Invalid weapon damage.'); }
        return $value;
    }
}

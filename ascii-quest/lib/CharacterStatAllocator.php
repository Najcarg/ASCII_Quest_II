<?php
declare(strict_types=1);

final class CharacterStatAllocator
{
    private const ALLOWED_STATS = [
        'strength',
        'dexterity',
        'vitality',
        'energy',
        'fate',
    ];

    public static function allocate(
        array $character,
        int $authenticatedUserId,
        string $stat,
    ): array {
        if (!in_array($stat, self::ALLOWED_STATS, true)) {
            throw new InvalidArgumentException('Invalid stat selection.');
        }

        $ownerId = self::readInteger($character, 'user_id');
        if ($ownerId !== $authenticatedUserId) {
            throw new OutOfBoundsException('Champion not found.');
        }

        $statPoints = self::readInteger($character, 'stat_points');
        if ($statPoints <= 0) {
            throw new DomainException('No stat points are available.');
        }

        $currentStat = self::readInteger($character, $stat);
        if ($currentStat < 0) {
            throw new DomainException('Champion stat cannot be negative.');
        }

        $allocated = $character;
        $allocated[$stat] = $currentStat + 1;
        $allocated['stat_points'] = $statPoints - 1;

        return $allocated;
    }

    private static function readInteger(array $data, string $key): int
    {
        if (!array_key_exists($key, $data)) {
            throw new InvalidArgumentException("Missing integer value: {$key}");
        }

        $value = $data[$key];
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new InvalidArgumentException("Invalid integer value: {$key}");
    }
}

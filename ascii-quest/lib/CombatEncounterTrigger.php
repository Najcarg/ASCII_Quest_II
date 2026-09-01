<?php
declare(strict_types=1);

final class CombatEncounterTrigger
{
    public static function isInOrthogonalRange(
        int $championX,
        int $championY,
        int $enemyX,
        int $enemyY,
        int $range = 1,
    ): bool {
        if ($range <= 0) {
            throw new InvalidArgumentException('Combat fighting range must be positive.');
        }

        return abs($championX - $enemyX) + abs($championY - $enemyY) === $range;
    }

    public static function isDirectContact(
        int $requestedX,
        int $requestedY,
        int $enemyX,
        int $enemyY,
    ): bool {
        return $requestedX === $enemyX && $requestedY === $enemyY;
    }
}

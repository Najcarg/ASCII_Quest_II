<?php
declare(strict_types=1);

require_once __DIR__ . '/CombatRandomSource.php';

final class SystemCombatRandomSource implements CombatRandomSource
{
    public function token(int $bytes = 32): string
    {
        if ($bytes <= 0) {
            throw new InvalidArgumentException('Random token length must be positive.');
        }

        return bin2hex(random_bytes($bytes));
    }

    public function integer(int $minimum, int $maximum): int
    {
        if ($minimum > $maximum) {
            throw new InvalidArgumentException('Random integer bounds are invalid.');
        }

        return random_int($minimum, $maximum);
    }
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/ItemRandomSource.php';

final class SystemItemRandomSource implements ItemRandomSource
{
    public function integer(int $minimum, int $maximum): int
    {
        return random_int($minimum, $maximum);
    }
}

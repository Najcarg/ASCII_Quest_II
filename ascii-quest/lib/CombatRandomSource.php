<?php
declare(strict_types=1);

interface CombatRandomSource
{
    public function token(int $bytes = 32): string;

    public function integer(int $minimum, int $maximum): int;
}

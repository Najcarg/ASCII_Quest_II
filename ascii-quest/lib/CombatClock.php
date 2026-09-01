<?php
declare(strict_types=1);

interface CombatClock
{
    public function now(): DateTimeImmutable;
}

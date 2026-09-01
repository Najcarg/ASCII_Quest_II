<?php
declare(strict_types=1);

require_once __DIR__ . '/CombatClock.php';

final class SystemCombatClock implements CombatClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}

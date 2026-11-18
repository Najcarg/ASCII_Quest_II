<?php
declare(strict_types=1);

/** Existing Task29/30 status vocabulary and fixed duration; no new status engine. */
final class CombatStatusRules
{
    public const KEYS = ['poison','bleed','burn','freeze','shock'];
    public const DURATION_MS = 4000;
}

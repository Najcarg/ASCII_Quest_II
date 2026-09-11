<?php
declare(strict_types=1);

interface ChampionDamageResolver
{
    public function resolve(array $enemyAction, array $currentDefense): array;
}

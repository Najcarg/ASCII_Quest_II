<?php
declare(strict_types=1);

interface EnemyDefenseResolver
{
    public function resolve(array $playerAction, array $enemyDefinition): array;
}

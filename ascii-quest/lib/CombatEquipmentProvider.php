<?php
declare(strict_types=1);

interface CombatEquipmentProvider
{
    public function offensiveSnapshot(array $lockedCharacter, string $attackKey): array;

    public function currentDefense(array $lockedCharacter): array;
}

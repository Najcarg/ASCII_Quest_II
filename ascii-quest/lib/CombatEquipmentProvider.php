<?php
declare(strict_types=1);

interface CombatEquipmentProvider
{
    public function offensiveSnapshot(array $lockedCharacter, string $attackKey): array;

    /** Current CharacterStats defense, including the rates.block_rate factor as block_rate. */
    public function currentDefense(array $lockedCharacter): array;
}

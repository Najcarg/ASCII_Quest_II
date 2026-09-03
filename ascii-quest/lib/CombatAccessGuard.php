<?php
declare(strict_types=1);

final class CombatAccessGuard
{
    public const MOVE = 'move';
    public const INTERACT = 'interact';
    public const MAP_SYNC = 'map_sync';
    public const WARP_UNLOCK = 'warp_unlock';
    public const WARP_TRAVEL = 'warp_travel';
    public const STAT_ALLOCATE = 'stat_allocate';
    public const SELECT_CHARACTER = 'select_character';
    public const DELETE_CHARACTER = 'delete_character';
    public const CREATE_CHARACTER = 'create_character';
    public const COMBAT_ENTRY = 'combat_entry';
    public const GAME_LOAD = 'game_load';

    private const EXPLORATION_OPERATIONS = [
        self::MOVE,
        self::INTERACT,
        self::MAP_SYNC,
        self::WARP_UNLOCK,
        self::WARP_TRAVEL,
        self::COMBAT_ENTRY,
    ];

    public function __construct(private object $repository)
    {
    }

    private bool $atomicActive = false;

    public function assertAllowed(string $operation, int $userId, int $characterId): array
    {
        if ($operation === self::CREATE_CHARACTER) {
            return [
                'allowed' => true,
                'character' => null,
                'active_encounter' => null,
                'resume_combat' => false,
            ];
        }

        $character = $this->repository->findOwnedCharacter($userId, $characterId);
        if ($character === null) {
            throw new OutOfBoundsException('Champion not found.');
        }
        if (($character['life_state'] ?? null) !== 'alive') {
            throw new DomainException('This Champion is unavailable.');
        }

        $accountEncounter = $this->repository->findOwnedActiveEncounterForUser($userId);

        return $this->decision($operation, $userId, $character, $accountEncounter);
    }

    public function beginAtomic(string $operation, int $userId, int $characterId): array
    {
        if ($operation === self::CREATE_CHARACTER) {
            throw new InvalidArgumentException('Character creation does not use the combat transaction.');
        }
        if ($this->atomicActive) {
            throw new LogicException('Combat access transaction is already active.');
        }

        $this->repository->beginTransaction();
        $this->atomicActive = true;

        try {
            $character = $this->repository->lockOwnedCharacter($userId, $characterId);
            if ($character === null) {
                throw new OutOfBoundsException('Champion not found.');
            }
            $accountEncounter = $this->repository->lockOwnedAccountActiveEncounter(
                $userId,
                $characterId,
            );

            return $this->decision($operation, $userId, $character, $accountEncounter);
        } catch (Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
    }

    public function commit(): void
    {
        if (!$this->atomicActive) {
            throw new LogicException('No combat access transaction is active.');
        }
        $this->repository->commit();
        $this->atomicActive = false;
    }

    public function rollBack(): void
    {
        if (!$this->atomicActive) {
            return;
        }
        $this->repository->rollBack();
        $this->atomicActive = false;
    }

    public function isAtomicActive(): bool
    {
        return $this->atomicActive;
    }

    public function assertLockedAllowed(
        string $operation,
        int $userId,
        array $lockedCharacter,
        ?array $lockedEncounter,
    ): array {
        return $this->decision($operation, $userId, $lockedCharacter, $lockedEncounter);
    }

    public function accountCombatState(int $userId): ?array
    {
        return $this->repository->findOwnedActiveEncounterForUser($userId);
    }

    private function decision(
        string $operation,
        int $userId,
        array $character,
        ?array $accountEncounter,
    ): array {
        $characterId = (int) ($character['id'] ?? 0);
        if ((int) ($character['user_id'] ?? 0) !== $userId) {
            throw new OutOfBoundsException('Champion not found.');
        }
        if (($character['life_state'] ?? null) !== 'alive') {
            throw new DomainException('This Champion is unavailable.');
        }

        $isFighter = $accountEncounter !== null &&
            (int) $accountEncounter['character_id'] === $characterId;

        if (in_array($operation, self::EXPLORATION_OPERATIONS, true)) {
            if ($accountEncounter !== null) {
                throw new DomainException($isFighter
                    ? 'Combat is active. Resume the battle.'
                    : 'Another Champion is already in combat.');
            }
        } elseif ($operation === self::STAT_ALLOCATE) {
            if ($accountEncounter !== null) {
                throw new DomainException('Stat allocation is unavailable during combat.');
            }
        } elseif ($operation === self::SELECT_CHARACTER) {
            if ($accountEncounter !== null && !$isFighter) {
                throw new DomainException('Another Champion is already in combat.');
            }
        } elseif ($operation === self::DELETE_CHARACTER) {
            if ($isFighter) {
                throw new DomainException('A fighting Champion cannot be deleted.');
            }
        } elseif ($operation === self::GAME_LOAD) {
            if ($accountEncounter !== null && !$isFighter) {
                throw new DomainException('Another Champion is already in combat.');
            }
        } else {
            throw new InvalidArgumentException('Unknown combat access operation.');
        }

        return [
            'allowed' => true,
            'character' => $character,
            'active_encounter' => $accountEncounter,
            'resume_combat' => $isFighter,
        ];
    }
}

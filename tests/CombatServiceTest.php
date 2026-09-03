<?php
declare(strict_types=1);

require_once __DIR__ . '/../ascii-quest/lib/CombatDefinitionRegistry.php';
require_once __DIR__ . '/../ascii-quest/lib/CombatClock.php';

$combatServicePath = __DIR__ . '/../ascii-quest/lib/CombatService.php';
if (is_file($combatServicePath)) {
    require_once $combatServicePath;
}

final class Task3FixedCombatClock implements CombatClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-01 12:00:00.000000', new DateTimeZone('UTC'));
    }
}

final class Task3MovementRepository
{
    public array $characters;
    public array $encounters = [];
    public array $actions = [];
    public array $events = [];
    public array $lockOrder = [];
    public bool $failEncounterCreation = false;

    private ?array $snapshot = null;
    private ?int $lockedCharacterId = null;
    private bool $encounterLockChecked = false;
    private ?array $lockedEncounter = null;
    private int $nextEncounterId = 1;

    public function __construct()
    {
        $this->characters = [
            42 => [
                'id' => 42,
                'user_id' => 7,
                'current_map_id' => 2,
                'current_map_key' => 'deep_cave_01',
                'current_map_file' => 'deep_cave.json',
                'pos_x' => 18,
                'pos_y' => 12,
                'current_hp' => 145,
                'current_mana' => 80,
                'life_state' => 'alive',
                'strength' => 10,
                'dexterity' => 5,
                'vitality' => 10,
                'energy' => 5,
                'fate' => 5,
            ],
            84 => [
                'id' => 84,
                'user_id' => 8,
                'current_map_id' => 2,
                'current_map_key' => 'deep_cave_01',
                'current_map_file' => 'deep_cave.json',
                'pos_x' => 18,
                'pos_y' => 12,
                'current_hp' => 100,
                'current_mana' => 60,
                'life_state' => 'alive',
                'strength' => 10,
                'dexterity' => 5,
                'vitality' => 10,
                'energy' => 5,
                'fate' => 5,
            ],
        ];
    }

    public function beginTransaction(): void
    {
        $this->snapshot = [$this->characters, $this->encounters, $this->nextEncounterId];
        $this->lockedCharacterId = null;
        $this->encounterLockChecked = false;
        $this->lockedEncounter = null;
    }

    public function commit(): void
    {
        $this->snapshot = null;
        $this->lockedCharacterId = null;
        $this->encounterLockChecked = false;
        $this->lockedEncounter = null;
    }

    public function rollBack(): void
    {
        if ($this->snapshot !== null) {
            [$this->characters, $this->encounters, $this->nextEncounterId] = $this->snapshot;
        }
        $this->commit();
    }

    public function lockOwnedCharacter(int $userId, int $characterId): ?array
    {
        $this->lockOrder[] = 'champion';
        $character = $this->characters[$characterId] ?? null;
        if ($character === null || $character['user_id'] !== $userId) {
            return null;
        }
        $this->lockedCharacterId = $characterId;

        return $character;
    }

    public function lockActiveEncounter(int $characterId): ?array
    {
        if ($this->lockedCharacterId !== $characterId) {
            throw new LogicException('Champion must be locked first.');
        }
        $this->lockOrder[] = 'encounter';
        $this->encounterLockChecked = true;
        $this->lockedEncounter = $this->activeForCharacter($characterId);

        return $this->lockedEncounter;
    }

    public function lockedActiveEncounter(int $characterId): ?array
    {
        if ($this->lockedCharacterId !== $characterId || !$this->encounterLockChecked) {
            throw new LogicException('Movement participant requires Champion and encounter locks.');
        }

        return $this->lockedEncounter;
    }

    public function updateLockedCharacterPosition(
        int $userId,
        int $characterId,
        int $mapId,
        int $currentX,
        int $currentY,
        int $newX,
        int $newY,
    ): bool {
        if ($this->lockedCharacterId !== $characterId) {
            throw new LogicException('Champion must be locked first.');
        }
        $character = $this->characters[$characterId] ?? null;
        if (
            $character === null ||
            $character['user_id'] !== $userId ||
            $character['current_map_id'] !== $mapId ||
            $character['pos_x'] !== $currentX ||
            $character['pos_y'] !== $currentY
        ) {
            return false;
        }
        $this->characters[$characterId]['pos_x'] = $newX;
        $this->characters[$characterId]['pos_y'] = $newY;

        return true;
    }

    public function createEncounter(int $characterId, array $encounter): array
    {
        if ($this->failEncounterCreation) {
            throw new RuntimeException('Encounter insert failed.');
        }
        $existing = $this->activeForCharacter($characterId);
        if ($existing !== null) {
            return $existing;
        }
        $id = $this->nextEncounterId++;
        $this->encounters[$id] = ['id' => $id, 'character_id' => $characterId] + $encounter;
        $this->lockedEncounter = $this->encounters[$id];

        return $this->encounters[$id];
    }

    public function findOwnedCharacter(int $userId, int $characterId): ?array
    {
        $character = $this->characters[$characterId] ?? null;

        return $character !== null && $character['user_id'] === $userId ? $character : null;
    }

    public function findActiveEncounter(int $characterId): ?array
    {
        return $this->activeForCharacter($characterId);
    }

    public function actionsForEncounter(int $encounterId): array
    {
        return array_values(array_filter(
            $this->actions,
            static fn (array $action): bool => $action['encounter_id'] === $encounterId,
        ));
    }

    public function eventsForEncounter(int $encounterId): array
    {
        return array_values(array_filter(
            $this->events,
            static fn (array $event): bool => $event['encounter_id'] === $encounterId,
        ));
    }

    private function activeForCharacter(int $characterId): ?array
    {
        foreach ($this->encounters as $encounter) {
            if ($encounter['character_id'] === $characterId && $encounter['active_slot'] === 1) {
                return $encounter;
            }
        }

        return null;
    }
}

function task3CombatService(?Task3MovementRepository $repository = null): array
{
    if (!class_exists('CombatService')) {
        throw new RuntimeException('CombatService must exist.');
    }
    $repository ??= new Task3MovementRepository();
    $definitions = new CombatDefinitionRegistry(
        require __DIR__ . '/../ascii-quest/config/combat.php',
    );

    return [
        new CombatService($repository, $definitions, new Task3FixedCombatClock()),
        $repository,
        $definitions->encounter('deep_cave_01_cave_brute'),
    ];
}

function assertTask3CombatRejected(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (LogicException | DomainException | OutOfBoundsException | RuntimeException) {
        return;
    }

    throw new RuntimeException($message . ' Expected rejection.');
}

return [
    'Movement decision persists an orthogonal floor destination and starts combat' => function (): void {
        [$service, $repository, $definition] = task3CombatService();
        $character = $repository->characters[42];
        $decision = $service->movementDecision($character, 19, 12, true, $definition);

        assertSameValue([19, 12], [$decision['final_x'], $decision['final_y']], 'Final floor position.');
        assertSameValue(true, $decision['start_combat'], 'Orthogonal range entry.');
        assertSameValue(false, $decision['direct_contact'], 'Normal floor movement.');
    },

    'Diagonal final position does not start combat' => function (): void {
        [$service, $repository, $definition] = task3CombatService();
        $character = array_replace($repository->characters[42], ['pos_x' => 18, 'pos_y' => 11]);
        $decision = $service->movementDecision($character, 19, 11, true, $definition);

        assertSameValue([19, 11], [$decision['final_x'], $decision['final_y']], 'Diagonal floor position.');
        assertSameValue(false, $decision['start_combat'], 'Diagonal position.');
    },

    'Direct enemy contact retains the Champion coordinate and starts without overlap' => function (): void {
        [$service, $repository, $definition] = task3CombatService();
        $character = array_replace($repository->characters[42], ['pos_x' => 19, 'pos_y' => 12]);
        $decision = $service->movementDecision($character, 20, 12, false, $definition);

        assertSameValue([19, 12], [$decision['final_x'], $decision['final_y']], 'Champion stays put.');
        assertSameValue([20, 12], [$definition['x'], $definition['y']], 'Enemy stays configured.');
        assertSameValue(true, $decision['start_combat'], 'Direct contact starts combat.');
        assertSameValue(true, $decision['direct_contact'], 'Direct contact marker.');
        assertSameValue(false, $decision['final_x'] === $definition['x'] && $decision['final_y'] === $definition['y'], 'No coordinate overlap.');
    },

    'Movement and encounter creation commit and roll back atomically' => function (): void {
        [$service, $repository, $definition] = task3CombatService();
        $repository->beginTransaction();
        $character = $repository->lockOwnedCharacter(7, 42);
        $repository->lockActiveEncounter(42);
        $repository->updateLockedCharacterPosition(7, 42, 2, 18, 12, 19, 12);
        $service->startOrResumeForLockedMovement(7, $character, $definition);
        $repository->rollBack();

        assertSameValue([18, 12], [$repository->characters[42]['pos_x'], $repository->characters[42]['pos_y']], 'Rolled-back position.');
        assertSameValue([], $repository->encounters, 'Rolled-back encounter.');

        $repository->beginTransaction();
        $character = $repository->lockOwnedCharacter(7, 42);
        $repository->lockActiveEncounter(42);
        $repository->updateLockedCharacterPosition(7, 42, 2, 18, 12, 19, 12);
        $state = $service->startOrResumeForLockedMovement(7, $character, $definition);
        $repository->commit();

        assertSameValue([19, 12], [$repository->characters[42]['pos_x'], $repository->characters[42]['pos_y']], 'Committed position.');
        assertSameValue(1, count($repository->encounters), 'Committed encounter.');
        assertSameValue(1, $state['encounter_id'], 'Created encounter identity.');
        assertSameValue(145, $state['champion']['current_hp'], 'Combat entry preserves HP.');
        assertSameValue(80, $state['champion']['current_mana'], 'Combat entry preserves Mana.');
        assertSameValue(['champion', 'encounter', 'champion', 'encounter'], $repository->lockOrder, 'Champion-first lock order.');
    },

    'Encounter creation failure rolls the movement position back' => function (): void {
        [$service, $repository, $definition] = task3CombatService();
        $repository->failEncounterCreation = true;
        $repository->beginTransaction();

        try {
            $character = $repository->lockOwnedCharacter(7, 42);
            $repository->lockActiveEncounter(42);
            $repository->updateLockedCharacterPosition(7, 42, 2, 18, 12, 19, 12);
            $service->startOrResumeForLockedMovement(7, $character, $definition);
            throw new RuntimeException('Expected encounter insert failure.');
        } catch (RuntimeException) {
            $repository->rollBack();
        }

        assertSameValue([18, 12], [$repository->characters[42]['pos_x'], $repository->characters[42]['pos_y']], 'Failed encounter restores position.');
        assertSameValue([], $repository->encounters, 'Failed encounter leaves no row.');
    },

    'Retry and two-tab start resume one unchanged encounter' => function (): void {
        [$service, $repository, $definition] = task3CombatService();
        $repository->beginTransaction();
        $character = $repository->lockOwnedCharacter(7, 42);
        $repository->lockActiveEncounter(42);
        $first = $service->startOrResumeForLockedMovement(7, $character, $definition);
        $repository->commit();

        $repository->encounters[1]['enemy_current_hp'] = 73;
        $repository->encounters[1]['potion_charges_remaining'] = 0;
        $repository->actions[] = [
            'id' => 9,
            'encounter_id' => 1,
            'actor' => 'player',
            'action_kind' => 'weapon',
            'definition_key' => 'prototype_weapon_attack',
            'state' => 'pending',
            'started_timeline_ms' => 100,
            'resolves_timeline_ms' => 1100,
            'cooldown_ready_timeline_ms' => 2600,
            'snapshot_base_damage' => 999,
        ];

        $repository->beginTransaction();
        $character = $repository->lockOwnedCharacter(7, 42);
        $repository->lockActiveEncounter(42);
        $retry = $service->startOrResumeForLockedMovement(7, $character, $definition);
        $repository->commit();
        $refresh = $service->state(7, 42);

        assertSameValue($first['encounter_id'], $retry['encounter_id'], 'Retry encounter identity.');
        assertSameValue($first['encounter_id'], $refresh['encounter_id'], 'Refresh encounter identity.');
        assertSameValue(1, count($repository->encounters), 'One active encounter.');
        assertSameValue(73, $refresh['enemy']['current_hp'], 'Stored enemy HP.');
        assertSameValue(0, $refresh['potion']['charges_remaining'], 'Stored potion state.');
        assertSameValue('pending', $refresh['player_actions'][0]['state'], 'Stored action state.');
        assertSameValue(false, array_key_exists('snapshot_base_damage', $refresh['player_actions'][0]), 'Offensive snapshot is hidden.');
        assertSameValue(false, array_key_exists('next_enemy_decision_timeline_ms', $refresh), 'AI timing is hidden.');
        assertSameValue(false, array_key_exists('cooldowns', $refresh['enemy']), 'Enemy cooldowns are hidden.');
    },

    'Movement start rejects dead and wrong-owner Champions' => function (): void {
        [$service, $repository, $definition] = task3CombatService();
        $repository->beginTransaction();
        $dead = $repository->lockOwnedCharacter(7, 42);
        $dead['life_state'] = 'dead';
        $repository->lockActiveEncounter(42);
        assertTask3CombatRejected(
            fn (): array => $service->startOrResumeForLockedMovement(7, $dead, $definition),
            'Dead Champion.',
        );
        $repository->rollBack();

        $repository->beginTransaction();
        $otherOwner = $repository->lockOwnedCharacter(8, 84);
        $repository->lockActiveEncounter(84);
        assertTask3CombatRejected(
            fn (): array => $service->startOrResumeForLockedMovement(7, $otherOwner, $definition),
            'Other user Champion.',
        );
        $repository->rollBack();
    },
];

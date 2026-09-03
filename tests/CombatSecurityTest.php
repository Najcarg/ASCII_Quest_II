<?php
declare(strict_types=1);

$combatAccessGuardPath = __DIR__ . '/../ascii-quest/lib/CombatAccessGuard.php';
$combatBootstrapPath = __DIR__ . '/../ascii-quest/lib/CombatBootstrap.php';
foreach ([$combatAccessGuardPath, $combatBootstrapPath] as $path) {
    if (is_file($path)) {
        require_once $path;
    }
}

final class Task3GuardRepository
{
    public array $characters = [
        42 => ['id' => 42, 'user_id' => 7, 'life_state' => 'alive'],
        43 => ['id' => 43, 'user_id' => 7, 'life_state' => 'alive'],
        84 => ['id' => 84, 'user_id' => 8, 'life_state' => 'alive'],
    ];
    public array $encounters = [];
    public int $writes = 0;
    public array $lockOrder = [];
    public bool $transactionActive = false;
    public bool $injectEncounterBeforeAccountLock = false;

    public function findOwnedCharacter(int $userId, int $characterId): ?array
    {
        $character = $this->characters[$characterId] ?? null;

        return $character !== null && $character['user_id'] === $userId ? $character : null;
    }

    public function findActiveEncounter(int $characterId): ?array
    {
        foreach ($this->encounters as $encounter) {
            if ($encounter['character_id'] === $characterId && $encounter['active_slot'] === 1) {
                return $encounter;
            }
        }

        return null;
    }

    public function findOwnedActiveEncounterForUser(int $userId): ?array
    {
        foreach ($this->encounters as $encounter) {
            $character = $this->characters[$encounter['character_id']] ?? null;
            if ($character !== null && $character['user_id'] === $userId && $encounter['active_slot'] === 1) {
                return $encounter;
            }
        }

        return null;
    }

    public function beginTransaction(): void
    {
        if ($this->transactionActive) {
            throw new LogicException('Nested guard transaction.');
        }
        $this->transactionActive = true;
        $this->lockOrder = [];
    }

    public function lockOwnedCharacter(int $userId, int $characterId): ?array
    {
        $this->lockOrder[] = 'champion';

        return $this->findOwnedCharacter($userId, $characterId);
    }

    public function lockOwnedAccountActiveEncounter(int $userId, int $characterId): ?array
    {
        if (!$this->transactionActive || ($this->lockOrder[0] ?? null) !== 'champion') {
            throw new LogicException('Champion must be locked first.');
        }
        $this->lockOrder[] = 'account';
        $this->lockOrder[] = 'encounter';
        if ($this->injectEncounterBeforeAccountLock) {
            $this->encounters[] = [
                'id' => 99,
                'character_id' => 42,
                'status' => 'active',
                'active_slot' => 1,
            ];
            $this->injectEncounterBeforeAccountLock = false;
        }

        return $this->findOwnedActiveEncounterForUser($userId);
    }

    public function commit(): void
    {
        if (!$this->transactionActive) {
            throw new LogicException('No guard transaction.');
        }
        $this->transactionActive = false;
    }

    public function rollBack(): void
    {
        $this->transactionActive = false;
    }
}

function task3CombatGuard(?Task3GuardRepository $repository = null): array
{
    if (!class_exists('CombatAccessGuard')) {
        throw new RuntimeException('CombatAccessGuard must exist.');
    }
    $repository ??= new Task3GuardRepository();

    return [new CombatAccessGuard($repository), $repository];
}

function assertTask3GuardRejected(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (DomainException | OutOfBoundsException | RuntimeException) {
        return;
    }

    throw new RuntimeException($message . ' Expected rejection.');
}

return [
    'Shared guard blocks every exploration mutation during unresolved combat' => function (): void {
        [$guard, $repository] = task3CombatGuard();
        $repository->encounters[] = [
            'id' => 10,
            'character_id' => 42,
            'status' => 'active',
            'active_slot' => 1,
        ];

        foreach ([
            CombatAccessGuard::MOVE,
            CombatAccessGuard::INTERACT,
            CombatAccessGuard::MAP_SYNC,
            CombatAccessGuard::WARP_UNLOCK,
            CombatAccessGuard::WARP_TRAVEL,
            CombatAccessGuard::STAT_ALLOCATE,
            CombatAccessGuard::COMBAT_ENTRY,
        ] as $operation) {
            assertTask3GuardRejected(
                fn (): array => $guard->assertAllowed($operation, 7, 42),
                $operation,
            );
        }

        assertSameValue(0, $repository->writes, 'Guard decisions never mutate combat.');
    },

    'Shared guard allows ordinary exploration with no encounter' => function (): void {
        [$guard] = task3CombatGuard();

        foreach ([
            CombatAccessGuard::MOVE,
            CombatAccessGuard::INTERACT,
            CombatAccessGuard::MAP_SYNC,
            CombatAccessGuard::WARP_UNLOCK,
            CombatAccessGuard::WARP_TRAVEL,
            CombatAccessGuard::STAT_ALLOCATE,
            CombatAccessGuard::COMBAT_ENTRY,
        ] as $operation) {
            $decision = $guard->assertAllowed($operation, 7, 42);
            assertSameValue(42, $decision['character']['id'], $operation . ' Champion.');
            assertSameValue(false, $decision['resume_combat'], $operation . ' exploration mode.');
        }
    },

    'A stale second-Champion session cannot load or mutate exploration during account combat' => function (): void {
        [$guard, $repository] = task3CombatGuard();
        $repository->encounters[] = [
            'id' => 10,
            'character_id' => 42,
            'status' => 'active',
            'active_slot' => 1,
        ];

        foreach ([
            CombatAccessGuard::MOVE,
            CombatAccessGuard::INTERACT,
            CombatAccessGuard::MAP_SYNC,
            CombatAccessGuard::WARP_UNLOCK,
            CombatAccessGuard::WARP_TRAVEL,
            CombatAccessGuard::STAT_ALLOCATE,
            CombatAccessGuard::COMBAT_ENTRY,
            CombatAccessGuard::GAME_LOAD,
        ] as $operation) {
            assertTask3GuardRejected(
                fn (): array => $guard->assertAllowed($operation, 7, 43),
                $operation . ' stale second Champion.',
            );
        }
    },

    'Atomic guard locks Champion then account encounter and holds through mutation' => function (): void {
        [$guard, $repository] = task3CombatGuard();

        $decision = $guard->beginAtomic(
            CombatAccessGuard::INTERACT,
            7,
            43,
        );

        assertSameValue(43, $decision['character']['id'], 'Locked requested Champion.');
        assertSameValue(true, $repository->transactionActive, 'Guard transaction remains active.');
        assertSameValue(['champion', 'account', 'encounter'], $repository->lockOrder, 'Atomic guard lock order.');
        $repository->writes++;
        $guard->commit();
        assertSameValue(false, $repository->transactionActive, 'Mutation and guard commit together.');
    },

    'Atomic guard rechecks an encounter that commits while the request waits' => function (): void {
        [$guard, $repository] = task3CombatGuard();
        $repository->injectEncounterBeforeAccountLock = true;

        assertTask3GuardRejected(
            fn (): array => $guard->beginAtomic(
                CombatAccessGuard::DELETE_CHARACTER,
                7,
                42,
            ),
            'Concurrent combat before deletion.',
        );

        assertSameValue(false, $repository->transactionActive, 'Rejected atomic guard rolls back.');
        assertSameValue(['champion', 'account', 'encounter'], $repository->lockOrder, 'Interleaved lock order.');
        assertSameValue(0, $repository->writes, 'No mutation after the recheck rejects.');
    },

    'Champion selection resumes the fighter and rejects every other Enter Dungeon' => function (): void {
        [$guard, $repository] = task3CombatGuard();
        $repository->encounters[] = [
            'id' => 10,
            'character_id' => 42,
            'status' => 'active',
            'active_slot' => 1,
        ];

        $resume = $guard->assertAllowed(CombatAccessGuard::SELECT_CHARACTER, 7, 42);
        assertSameValue(true, $resume['resume_combat'], 'Fighter resumes battle.');
        assertSameValue(10, $resume['active_encounter']['id'], 'Encounter remains unchanged.');
        assertTask3GuardRejected(
            fn (): array => $guard->assertAllowed(CombatAccessGuard::SELECT_CHARACTER, 7, 43),
            'Other Champion selection.',
        );
        assertTask3GuardRejected(
            fn (): array => $guard->assertAllowed(CombatAccessGuard::SELECT_CHARACTER, 7, 84),
            'Other user Champion selection.',
        );
    },

    'Fighting Champion deletion rejects and ordinary deletion returns after closure' => function (): void {
        [$guard, $repository] = task3CombatGuard();
        $repository->encounters[] = [
            'id' => 10,
            'character_id' => 42,
            'status' => 'active',
            'active_slot' => 1,
        ];

        assertTask3GuardRejected(
            fn (): array => $guard->assertAllowed(CombatAccessGuard::DELETE_CHARACTER, 7, 42),
            'Fighter deletion.',
        );
        $repository->encounters[0]['active_slot'] = null;
        $repository->encounters[0]['status'] = 'closed';

        $decision = $guard->assertAllowed(CombatAccessGuard::DELETE_CHARACTER, 7, 42);
        assertSameValue(false, $decision['resume_combat'], 'Closed encounter unlocks deletion.');
    },

    'Character creation remains allowed and does not inspect or mutate combat' => function (): void {
        [$guard, $repository] = task3CombatGuard();
        $repository->encounters[] = [
            'id' => 10,
            'character_id' => 42,
            'status' => 'active',
            'active_slot' => 1,
            'enemy_current_hp' => 73,
        ];
        $before = $repository->encounters;

        $decision = $guard->assertAllowed(CombatAccessGuard::CREATE_CHARACTER, 7, 0);

        assertSameValue(true, $decision['allowed'], 'Creation stays available.');
        assertSameValue($before, $repository->encounters, 'Creation does not touch the fight.');
        assertSameValue(0, $repository->writes, 'Creation guard performs no write.');
    },

    'Dead Champions and post-start movement reject server-side' => function (): void {
        [$guard, $repository] = task3CombatGuard();
        $repository->characters[42]['life_state'] = 'dead';
        assertTask3GuardRejected(
            fn (): array => $guard->assertAllowed(CombatAccessGuard::MOVE, 7, 42),
            'Dead movement.',
        );

        $repository->characters[42]['life_state'] = 'alive';
        $repository->encounters[] = [
            'id' => 10,
            'character_id' => 42,
            'status' => 'active',
            'active_slot' => 1,
        ];
        assertTask3GuardRejected(
            fn (): array => $guard->assertAllowed(CombatAccessGuard::MOVE, 7, 42),
            'Post-start movement.',
        );
    },

    'Game load never mutates expired overrides after its read-only combat decision' => function (): void {
        $game = file_get_contents(__DIR__ . '/../ascii-quest/game.php');
        $sync = file_get_contents(__DIR__ . '/../ascii-quest/sync_map_state.php');
        if ($game === false || $sync === false) {
            throw new RuntimeException('Game and map-sync routes must be readable.');
        }

        assertSameValue(
            false,
            str_contains($game, 'DELETE FROM character_map_overrides'),
            'Game load performs zero cleanup writes for a fighter or stale Champion after any interleaving.',
        );
        assertSameValue(
            true,
            str_contains($game, 'expires_at > NOW()'),
            'Game rendering excludes expired overrides without deleting them.',
        );
        assertSameValue(
            true,
            str_contains($sync, '->beginAtomic(') &&
                str_contains($sync, 'DELETE FROM character_map_overrides'),
            'Atomic map sync remains the sole expired-override cleanup route.',
        );
    },

    'State-changing route contracts delegate to the shared server guard' => function (): void {
        $routes = [
            'move_character.php' => 'MOVE',
            'interact.php' => 'INTERACT',
            'sync_map_state.php' => 'MAP_SYNC',
            'unlock_warp.php' => 'WARP_UNLOCK',
            'travel_warp.php' => 'WARP_TRAVEL',
            'allocate_stat.php' => 'STAT_ALLOCATE',
            'select_character.php' => 'SELECT_CHARACTER',
            'delete_character.php' => 'DELETE_CHARACTER',
        ];

        foreach ($routes as $file => $operation) {
            $source = file_get_contents(__DIR__ . '/../ascii-quest/' . $file);
            if ($source === false) {
                throw new RuntimeException('Unable to read route ' . $file);
            }
            assertSameValue(
                true,
                str_contains($source, 'CombatAccessGuard::' . $operation),
                $file . ' shared guard operation.',
            );
            if ($file !== 'move_character.php') {
                assertSameValue(
                    true,
                    str_contains($source, '->beginAtomic('),
                    $file . ' atomic guard boundary.',
                );
            }
        }

        $movement = file_get_contents(__DIR__ . '/../ascii-quest/move_character.php');
        assertSameValue(
            true,
            is_string($movement) && str_contains($movement, 'lockOwnedAccountActiveEncounter'),
            'Movement account encounter lock.',
        );
    },

    'Character selection POST and form both require CSRF' => function (): void {
        $endpoint = file_get_contents(__DIR__ . '/../ascii-quest/select_character.php');
        $markup = file_get_contents(__DIR__ . '/../ascii-quest/character_select.php');
        if ($endpoint === false || $markup === false) {
            throw new RuntimeException('Character selection files must be readable.');
        }

        assertSameValue(true, str_contains($endpoint, 'hash_equals'), 'Selection endpoint CSRF comparison.');
        assertSameValue(true, str_contains($markup, 'name="csrf_token"'), 'Selection form CSRF field.');
    },

    'Configured Cave Brute validates against the authoritative map and metadata' => function (): void {
        if (!class_exists('CombatBootstrap')) {
            throw new RuntimeException('CombatBootstrap must exist.');
        }
        require_once __DIR__ . '/../ascii-quest/map_loader.php';
        $map = loadMapFromFile('deep_cave.json');
        $encounter = CombatBootstrap::validatedEncounterForMap('deep_cave.json', $map);

        assertSameValue('deep_cave_01', $map['map_key'], 'Authoritative map key.');
        assertSameValue([20, 12, 'B'], [$encounter['x'], $encounter['y'], $encounter['glyph']], 'Validated enemy overlay.');
        assertSameValue('.', $map['layout'][12][20], 'Underlying JSON remains floor.');

        $invalid = $map;
        $invalid['objects'][] = ['type' => 'chest', 'x' => 20, 'y' => 12, 'glyph' => 'O'];
        assertTask3GuardRejected(
            fn (): array => CombatBootstrap::validatedEncounterForMap('deep_cave.json', $invalid),
            'Enemy metadata overlap.',
        );
    },
];

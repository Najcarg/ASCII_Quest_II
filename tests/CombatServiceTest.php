<?php
declare(strict_types=1);

require_once __DIR__ . '/../ascii-quest/lib/CombatDefinitionRegistry.php';
require_once __DIR__ . '/../ascii-quest/lib/CombatClock.php';
require_once __DIR__ . '/../ascii-quest/lib/CombatTurnEngine.php';

$combatSynchronizerPath = __DIR__ . '/../ascii-quest/lib/CombatSynchronizer.php';
if (is_file($combatSynchronizerPath)) {
    require_once $combatSynchronizerPath;
}

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

final class Task4MutableCombatClock implements CombatClock
{
    public function __construct(private DateTimeImmutable $current)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->current;
    }

    public function set(DateTimeImmutable $current): void
    {
        $this->current = $current;
    }
}

final class Task4SequenceCombatClock implements CombatClock
{
    public int $observations = 0;

    /** @param list<DateTimeImmutable> $observedTimes */
    public function __construct(private array $observedTimes)
    {
    }

    public function now(): DateTimeImmutable
    {
        $observation = $this->observedTimes[$this->observations] ?? null;
        if (!$observation instanceof DateTimeImmutable) {
            throw new RuntimeException('Combat clock was observed too many times.');
        }
        $this->observations++;

        return $observation;
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

    public function lockOwnedAccountActiveEncounter(int $userId, int $characterId): ?array
    {
        if ($this->lockedCharacterId !== $characterId) {
            throw new LogicException('Champion must be locked first.');
        }
        $this->lockOrder[] = 'account';
        $this->lockOrder[] = 'encounter';
        $this->encounterLockChecked = true;
        $this->lockedEncounter = null;
        foreach ($this->encounters as $encounter) {
            $owner = $this->characters[$encounter['character_id']]['user_id'] ?? null;
            if ($owner === $userId && $encounter['active_slot'] === 1) {
                $this->lockedEncounter = $encounter;
                break;
            }
        }

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

    public function updateLockedEncounterSynchronization(
        int $encounterId,
        array $encounter,
        int $expectedVersion,
    ): bool {
        if (!isset($this->encounters[$encounterId])) {
            return false;
        }
        if ((int) $this->encounters[$encounterId]['version'] !== $expectedVersion) {
            return false;
        }
        foreach ([
            'timeline_elapsed_ms',
            'last_synchronized_at',
            'turn_number',
            'turn_started_timeline_ms',
            'next_enemy_decision_timeline_ms',
            'player_actions_remaining',
            'enemy_actions_remaining',
        ] as $key) {
            $this->encounters[$encounterId][$key] = $encounter[$key];
        }
        $this->encounters[$encounterId]['version']++;

        return true;
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

function task4SynchronizerFixture(
    string $now = '2026-09-01 12:00:00.000000',
    float $catchupSeconds = 5.0,
    float $turnDurationSeconds = 10.0,
    ?Closure $dueEventProcessor = null,
): array {
    if (!class_exists('CombatSynchronizer')) {
        throw new RuntimeException('CombatSynchronizer must exist.');
    }

    $clock = new Task4MutableCombatClock(
        new DateTimeImmutable($now, new DateTimeZone('UTC')),
    );

    return [
        new CombatSynchronizer(
            $clock,
            new CombatTurnEngine($turnDurationSeconds),
            $catchupSeconds,
            $dueEventProcessor,
        ),
        $clock,
    ];
}

function task4Encounter(array $overrides = []): array
{
    return array_replace([
        'id' => 91,
        'character_id' => 42,
        'enemy_key' => 'cave_brute',
        'status' => 'active',
        'active_slot' => 1,
        'enemy_max_hp' => 120,
        'enemy_current_hp' => 73,
        'timeline_elapsed_ms' => 3000,
        'last_synchronized_at' => '2026-09-01 12:00:00.000000',
        'turn_number' => 1,
        'turn_started_timeline_ms' => 0,
        'next_enemy_decision_timeline_ms' => 5000,
        'player_actions_remaining' => 0,
        'enemy_actions_remaining' => 1,
        'potion_key' => 'prototype_health_potion',
        'potion_charge_allowance' => 1,
        'potion_charges_remaining' => 0,
        'reward_gold' => 25,
        'reward_experience' => 40,
        'rewards_issued_at' => null,
        'death_processed_at' => null,
        'killer_enemy_key' => null,
        'completed_at' => null,
        'version' => 4,
    ], $overrides);
}

function task4RepositoryService(
    FakeCombatPdo $pdo,
    Task4MutableCombatClock $clock,
): CombatService {
    return new CombatService(
        new CombatRepository($pdo),
        new CombatDefinitionRegistry(require __DIR__ . '/../ascii-quest/config/combat.php'),
        $clock,
    );
}

return [
    'Authoritative synchronization applies zero short and capped wall-clock gaps exactly' => function (): void {
        foreach ([
            ['2026-09-01 12:00:00.000000', 3000],
            ['2026-09-01 12:00:02.000000', 5000],
            ['2026-09-01 12:00:05.000000', 8000],
            ['2026-09-01 12:00:30.000000', 8000],
            ['2026-09-01 18:00:00.000000', 8000],
        ] as [$now, $expectedTimeline]) {
            [$synchronizer] = task4SynchronizerFixture($now);

            $result = $synchronizer->synchronize(task4Encounter(), 1, 2);

            assertSameValue($expectedTimeline, $result['timeline_elapsed_ms'], $now . ' logical timeline.');
            assertSameValue($now, $result['last_synchronized_at'], $now . ' actual wall anchor.');
        }
    },

    'A capped disconnect is discarded rather than replayed by an immediate poll' => function (): void {
        [$synchronizer, $clock] = task4SynchronizerFixture('2026-09-01 12:00:30.000000');

        $first = $synchronizer->synchronize(task4Encounter(), 1, 2);
        $second = $synchronizer->synchronize($first, 1, 2);

        assertSameValue(8000, $first['timeline_elapsed_ms'], 'First poll applies only five seconds.');
        assertSameValue('2026-09-01 12:00:30.000000', $first['last_synchronized_at'], 'First poll anchors actual now.');
        assertSameValue(8000, $second['timeline_elapsed_ms'], 'Immediate poll applies no discarded time.');

        $clock->set(new DateTimeImmutable('2026-09-01 12:00:31.000000', new DateTimeZone('UTC')));
        $third = $synchronizer->synchronize($second, 1, 2);
        assertSameValue(9000, $third['timeline_elapsed_ms'], 'Only newly connected time advances.');
    },

    'Synchronization uses its configured cap and never regresses logical time' => function (): void {
        [$custom] = task4SynchronizerFixture('2026-09-01 12:00:30.000000', 2.0);
        $capped = $custom->synchronize(task4Encounter(), 1, 2);
        assertSameValue(5000, $capped['timeline_elapsed_ms'], 'Injected two-second cap.');

        [$regressed] = task4SynchronizerFixture('2026-09-01 11:59:55.000000');
        $result = $regressed->synchronize(task4Encounter(), 1, 2);
        assertSameValue(3000, $result['timeline_elapsed_ms'], 'Logical time remains monotonic.');
        assertSameValue('2026-09-01 11:59:55.000000', $result['last_synchronized_at'], 'Regression safely re-anchors.');
    },

    'Terminal reward and death history remain unchanged when combat cannot progress' => function (): void {
        [$synchronizer] = task4SynchronizerFixture('2026-09-01 12:00:30.000000');
        foreach ([
            task4Encounter([
                'status' => 'victory_loot',
                'rewards_issued_at' => '2026-09-01 12:00:01.000000',
                'completed_at' => '2026-09-01 12:00:02.000000',
            ]),
            task4Encounter([
                'status' => 'defeated',
                'active_slot' => null,
                'death_processed_at' => '2026-09-01 12:00:01.500000',
                'killer_enemy_key' => 'cave_brute',
                'completed_at' => '2026-09-01 12:00:01.500000',
            ]),
        ] as $terminal) {
            $result = $synchronizer->synchronize($terminal, 1, 2);

            $expected = $terminal;
            $expected['last_synchronized_at'] = '2026-09-01 12:00:30.000000';
            assertSameValue($expected, $result, $terminal['status'] . ' state preservation.');
        }
    },

    'Synchronization crosses a Turn boundary and resets rather than carries Actions' => function (): void {
        [$synchronizer] = task4SynchronizerFixture('2026-09-01 12:00:03.000000');
        $encounter = task4Encounter([
            'timeline_elapsed_ms' => 8000,
            'last_synchronized_at' => '2026-09-01 12:00:00.000000',
            'player_actions_remaining' => 0,
            'enemy_actions_remaining' => 1,
            'next_enemy_decision_timeline_ms' => 5000,
        ]);

        $result = $synchronizer->synchronize($encounter, 1, 2);

        assertSameValue(11000, $result['timeline_elapsed_ms'], 'Logical target.');
        assertSameValue(2, $result['turn_number'], 'One crossed boundary.');
        assertSameValue(10000, $result['turn_started_timeline_ms'], 'New Turn start.');
        assertSameValue(1, $result['player_actions_remaining'], 'Spent player Actions reset, not accumulated.');
        assertSameValue(2, $result['enemy_actions_remaining'], 'Unused enemy Actions reset, not accumulated.');
        assertSameValue(10000, $result['next_enemy_decision_timeline_ms'], 'Unused enemy cursor remains schema-valid after rollover.');
    },

    'Synchronization deterministically crosses multiple short Turns within one capped interval' => function (): void {
        [$synchronizer] = task4SynchronizerFixture(
            '2026-09-01 12:00:05.000000',
            5.0,
            2.0,
        );
        $encounter = task4Encounter([
            'timeline_elapsed_ms' => 1500,
            'last_synchronized_at' => '2026-09-01 12:00:00.000000',
            'turn_started_timeline_ms' => 0,
            'next_enemy_decision_timeline_ms' => 0,
        ]);

        $result = $synchronizer->synchronize($encounter, 3, 2);

        assertSameValue(6500, $result['timeline_elapsed_ms'], 'Capped target crosses three boundaries.');
        assertSameValue(4, $result['turn_number'], 'Final Turn identity.');
        assertSameValue(6000, $result['turn_started_timeline_ms'], 'Final Turn start.');
        assertSameValue(3, $result['player_actions_remaining'], 'Latest player allowance only.');
        assertSameValue(2, $result['enemy_actions_remaining'], 'Latest enemy allowance only.');
        assertSameValue(6000, $result['next_enemy_decision_timeline_ms'], 'Enemy cursor floors to final Turn start.');
    },

    'Due action at the exact Turn boundary is observed before allowance reset' => function (): void {
        $dueAction = [
            'id' => 501,
            'actor' => 'player',
            'state' => 'pending',
            'resolves_timeline_ms' => 10000,
        ];
        $observed = [];
        $processor = static function (array $encounter, int $throughTimelineMs) use (
            $dueAction,
            &$observed,
        ): array {
            if ($dueAction['resolves_timeline_ms'] <= $throughTimelineMs) {
                $observed[] = [
                    'action_id' => $dueAction['id'],
                    'through_timeline_ms' => $throughTimelineMs,
                    'turn_number' => $encounter['turn_number'],
                    'player_actions_remaining' => $encounter['player_actions_remaining'],
                ];
            }

            return $encounter;
        };
        [$synchronizer] = task4SynchronizerFixture(
            '2026-09-01 12:00:02.000000',
            5.0,
            10.0,
            $processor,
        );
        $encounter = task4Encounter([
            'timeline_elapsed_ms' => 8000,
            'last_synchronized_at' => '2026-09-01 12:00:00.000000',
            'player_actions_remaining' => 0,
        ]);

        $result = $synchronizer->synchronize($encounter, 1, 2);

        assertSameValue([[
            'action_id' => 501,
            'through_timeline_ms' => 10000,
            'turn_number' => 1,
            'player_actions_remaining' => 0,
        ]], $observed, 'Boundary due processing sees the old Turn allowance state.');
        assertSameValue(2, $result['turn_number'], 'Rollover occurs after due processing.');
        assertSameValue(1, $result['player_actions_remaining'], 'New allowance follows exact-boundary processing.');
    },

    'Chronological processor state is retained while the encounter remains active' => function (): void {
        $processor = static function (array $encounter, int $throughTimelineMs): array {
            $encounter['enemy_actions_remaining'] = 0;

            return $encounter;
        };
        [$synchronizer] = task4SynchronizerFixture(
            '2026-09-01 12:00:05.000000',
            5.0,
            10.0,
            $processor,
        );

        $result = $synchronizer->synchronize(task4Encounter(), 1, 2);

        assertSameValue(0, $result['enemy_actions_remaining'], 'Future processor allowance mutation is not overwritten.');
    },

    'Chronological processor terminal state stops before same-time rollover or later target time' => function (): void {
        $processor = static function (array $encounter, int $throughTimelineMs): array {
            if ($throughTimelineMs >= 9000) {
                $encounter['status'] = 'victory_loot';
                $encounter['timeline_elapsed_ms'] = 9000;
            }

            return $encounter;
        };
        [$synchronizer] = task4SynchronizerFixture(
            '2026-09-01 12:00:05.000000',
            5.0,
            10.0,
            $processor,
        );
        $encounter = task4Encounter([
            'timeline_elapsed_ms' => 8000,
            'player_actions_remaining' => 0,
        ]);

        $result = $synchronizer->synchronize($encounter, 1, 2);

        assertSameValue('victory_loot', $result['status'], 'Future terminal transition retained.');
        assertSameValue(9000, $result['timeline_elapsed_ms'], 'Combat stops at terminal due-event position.');
        assertSameValue(1, $result['turn_number'], 'Terminal resolution precedes and suppresses rollover.');
        assertSameValue(0, $result['player_actions_remaining'], 'Terminal processing does not grant a new allowance.');
        assertSameValue('2026-09-01 12:00:05.000000', $result['last_synchronized_at'], 'Wall anchor still uses actual server now.');
    },

    'Chronological processor cannot return a regressed or beyond-segment timeline' => function (): void {
        foreach ([2999, 8001] as $invalidTimeline) {
            $processor = static function (array $encounter, int $throughTimelineMs) use (
                $invalidTimeline,
            ): array {
                $encounter['timeline_elapsed_ms'] = $invalidTimeline;

                return $encounter;
            };
            [$synchronizer] = task4SynchronizerFixture(
                '2026-09-01 12:00:05.000000',
                5.0,
                10.0,
                $processor,
            );

            assertTask3CombatRejected(
                fn (): array => $synchronizer->synchronize(task4Encounter(), 1, 2),
                'Invalid processor timeline ' . $invalidTimeline . '.',
            );
        }
    },

    'Exhausted Actions remain exhausted while synchronization stays inside one Turn' => function (): void {
        [$synchronizer] = task4SynchronizerFixture('2026-09-01 12:00:05.000000');

        $result = $synchronizer->synchronize(task4Encounter(), 1, 2);

        assertSameValue(8000, $result['timeline_elapsed_ms'], 'Same-Turn target.');
        assertSameValue(1, $result['turn_number'], 'Turn does not roll early.');
        assertSameValue(0, $result['player_actions_remaining'], 'Player remains exhausted.');
        assertSameValue(1, $result['enemy_actions_remaining'], 'Enemy spent state is preserved.');
    },

    'Combat state synchronizes through the real repository transaction without resetting persisted state' => function (): void {
        $pdo = new FakeCombatPdo();
        $pdo->encounters[91] = task4Encounter();
        $pdo->actions[501] = [
            'id' => 501,
            'encounter_id' => 91,
            'parent_action_id' => null,
            'actor' => 'player',
            'action_kind' => 'weapon',
            'definition_key' => 'prototype_weapon_attack',
            'request_token' => '11111111-1111-4111-8111-111111111111',
            'active_slot' => 1,
            'state' => 'pending',
            'started_timeline_ms' => 2500,
            'resolves_timeline_ms' => 9000,
            'cooldown_ready_timeline_ms' => 11000,
            'completed_timeline_ms' => null,
            'snapshot_weapon_key' => 'private_weapon',
            'snapshot_base_damage' => 999,
            'block_token' => 'private-block-token',
            'block_expires_timeline_ms' => 8500,
            'block_attempted_timeline_ms' => 7000,
        ];
        $pdo->events[701] = [
            'id' => 701,
            'encounter_id' => 91,
            'sequence_number' => 1,
            'event_type' => 'encounter_started',
            'message' => 'The Cave Brute engages.',
            'emphasis' => 'warning',
            'created_at' => '2026-09-01 12:00:00.000000',
        ];
        $characterBefore = $pdo->characters[42];
        $actionBefore = $pdo->actions[501];
        $eventBefore = $pdo->events[701];
        $preservedEncounter = array_intersect_key(
            $pdo->encounters[91],
            array_flip([
                'id', 'character_id', 'enemy_current_hp', 'potion_charge_allowance',
                'potion_charges_remaining', 'reward_gold', 'reward_experience',
                'rewards_issued_at', 'death_processed_at', 'killer_enemy_key',
                'completed_at', 'status', 'active_slot',
            ]),
        );
        $clock = new Task4MutableCombatClock(
            new DateTimeImmutable('2026-09-01 12:00:30.000000', new DateTimeZone('UTC')),
        );

        $state = task4RepositoryService($pdo, $clock)->state(7, 42);

        assertSameValue(8000, $pdo->encounters[91]['timeline_elapsed_ms'], 'Capped timeline persisted.');
        assertSameValue('2026-09-01 12:00:30.000000', $pdo->encounters[91]['last_synchronized_at'], 'Actual anchor persisted.');
        assertSameValue(5, $pdo->encounters[91]['version'], 'Synchronization version persisted once.');
        assertSameValue(['champion', 'account', 'encounter'], $pdo->lockOrder, 'Authoritative lock order.');
        assertSameValue($characterBefore, $pdo->characters[42], 'Champion HP Mana and all fields preserved.');
        assertSameValue($actionBefore, $pdo->actions[501], 'Active action cooldown Block and snapshot state preserved.');
        assertSameValue($eventBefore, $pdo->events[701], 'Battle Info history preserved.');
        assertSameValue(
            $preservedEncounter,
            array_intersect_key($pdo->encounters[91], $preservedEncounter),
            'Enemy HP potion reward death lifecycle and encounter identity preserved.',
        );
        assertSameValue(91, $state['encounter_id'], 'Existing encounter is projected.');
        assertSameValue(145, $state['champion']['current_hp'], 'No free HP healing.');
        assertSameValue(80, $state['champion']['current_mana'], 'No free Mana healing.');
        assertSameValue(73, $state['enemy']['current_hp'], 'No enemy HP reset.');
        assertSameValue('pending', $state['player_actions'][0]['state'], 'Persisted action is projected unchanged.');
        assertSameValue('The Cave Brute engages.', $state['battle_events'][0]['message'], 'Persisted event is projected.');
        assertSameValue('2026-09-01T12:00:30+00:00', $state['server_observed_at'], 'Public server observation uses injected clock.');
        assertSameValue(false, array_key_exists('snapshot_base_damage', $state['player_actions'][0]), 'Snapshot remains private.');
        assertSameValue(false, array_key_exists('next_enemy_decision_timeline_ms', $state), 'Enemy decision cursor remains private.');
        assertSameValue(false, array_key_exists('cooldowns', $state['enemy']), 'Enemy cooldowns remain private.');
    },

    'Public server observation is the persisted synchronization anchor without a second clock read' => function (): void {
        $pdo = new FakeCombatPdo();
        $pdo->encounters[91] = task4Encounter();
        $clock = new Task4SequenceCombatClock([
            new DateTimeImmutable('2026-09-01 12:00:02.000000', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-09-01 12:00:09.000000', new DateTimeZone('UTC')),
        ]);
        $service = new CombatService(
            new CombatRepository($pdo),
            new CombatDefinitionRegistry(require __DIR__ . '/../ascii-quest/config/combat.php'),
            $clock,
        );

        $state = $service->state(7, 42);

        assertSameValue(
            '2026-09-01 12:00:02.000000',
            $pdo->encounters[91]['last_synchronized_at'],
            'Synchronization persists the first authoritative observation.',
        );
        assertSameValue(
            '2026-09-01T12:00:02+00:00',
            $state['server_observed_at'],
            'Public observation exactly represents the persisted synchronization anchor.',
        );
        assertSameValue(1, $clock->observations, 'Projection does not observe the clock a second time.');
    },

    'Refresh browser reopen and login resume the same encounter without replaying skipped time' => function (): void {
        $pdo = new FakeCombatPdo();
        $pdo->encounters[91] = task4Encounter();
        $clock = new Task4MutableCombatClock(
            new DateTimeImmutable('2026-09-01 12:00:30.000000', new DateTimeZone('UTC')),
        );

        $first = task4RepositoryService($pdo, $clock)->state(7, 42);
        $second = task4RepositoryService($pdo, $clock)->state(7, 42);

        assertSameValue([91, 91], [$first['encounter_id'], $second['encounter_id']], 'Durable encounter identity.');
        assertSameValue(1, count($pdo->encounters), 'State requests never recreate combat.');
        assertSameValue([], $pdo->actions, 'Synchronization never synthesizes a player attack.');
        assertSameValue(8000, $first['timeline']['elapsed_ms'], 'First reconnect applies cap.');
        assertSameValue(8000, $second['timeline']['elapsed_ms'], 'Immediate login/reopen applies zero.');
        assertSameValue('2026-09-01 12:00:30.000000', $pdo->encounters[91]['last_synchronized_at'], 'Discarded time remains discarded.');
    },

    'Crossed Turn state is persisted atomically by the real repository' => function (): void {
        $pdo = new FakeCombatPdo();
        $pdo->encounters[91] = task4Encounter([
            'timeline_elapsed_ms' => 8000,
            'player_actions_remaining' => 0,
            'enemy_actions_remaining' => 1,
        ]);
        $clock = new Task4MutableCombatClock(
            new DateTimeImmutable('2026-09-01 12:00:03.000000', new DateTimeZone('UTC')),
        );

        task4RepositoryService($pdo, $clock)->state(7, 42);

        assertSameValue(11000, $pdo->encounters[91]['timeline_elapsed_ms'], 'Timeline persisted.');
        assertSameValue(2, $pdo->encounters[91]['turn_number'], 'Turn number persisted.');
        assertSameValue(10000, $pdo->encounters[91]['turn_started_timeline_ms'], 'Turn start persisted.');
        assertSameValue(1, $pdo->encounters[91]['player_actions_remaining'], 'Player allowance persisted.');
        assertSameValue(2, $pdo->encounters[91]['enemy_actions_remaining'], 'Enemy allowance persisted.');
        assertSameValue(10000, $pdo->encounters[91]['next_enemy_decision_timeline_ms'], 'Schema-compatible enemy cursor persisted.');
    },

    'Projection failure and rejected Champion requests cannot partially advance combat' => function (): void {
        $pdo = new FakeCombatPdo();
        $pdo->encounters[91] = task4Encounter();
        $pdo->failActionsRead = true;
        $before = $pdo->encounters[91];
        $clock = new Task4MutableCombatClock(
            new DateTimeImmutable('2026-09-01 12:00:30.000000', new DateTimeZone('UTC')),
        );

        assertTask3CombatRejected(
            fn (): array => task4RepositoryService($pdo, $clock)->state(7, 42),
            'Projection failure.',
        );
        assertSameValue($before, $pdo->encounters[91], 'Projection failure rolls synchronization back.');

        $pdo->failActionsRead = false;
        $pdo->encounters[91] = task4Encounter();
        $before = $pdo->encounters[91];
        assertTask3CombatRejected(
            fn (): array => task4RepositoryService($pdo, $clock)->state(7, 43),
            'Another selected Champion.',
        );
        assertSameValue($before, $pdo->encounters[91], 'Wrong selected Champion cannot advance fighter timeline.');

        $pdo->characters[42]['life_state'] = 'dead';
        assertTask3CombatRejected(
            fn (): array => task4RepositoryService($pdo, $clock)->state(7, 42),
            'Dead Champion.',
        );
        assertSameValue($before, $pdo->encounters[91], 'Dead rejection cannot advance encounter.');
    },

    'Rejected synchronization save rolls the entire authoritative transaction back' => function (): void {
        $pdo = new FakeCombatPdo();
        $pdo->encounters[91] = task4Encounter();
        $pdo->failSynchronizationUpdate = true;
        $before = $pdo->encounters[91];
        $clock = new Task4MutableCombatClock(
            new DateTimeImmutable('2026-09-01 12:00:30.000000', new DateTimeZone('UTC')),
        );

        assertTask3CombatRejected(
            fn (): array => task4RepositoryService($pdo, $clock)->state(7, 42),
            'Rejected optimistic synchronization save.',
        );

        assertSameValue($before, $pdo->encounters[91], 'Rejected save persists no timeline or anchor.');
        assertSameValue(false, $pdo->inTransaction(), 'Rejected save closes the transaction by rollback.');
        assertSameValue(['champion', 'account', 'encounter'], $pdo->lockOrder, 'Rejected save retains approved lock order.');
    },

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

<?php
declare(strict_types=1);

$combatRepositoryPath = __DIR__ . '/../ascii-quest/lib/CombatRepository.php';
if (is_file($combatRepositoryPath)) {
    require_once $combatRepositoryPath;
}
$combatAccessGuardPath = __DIR__ . '/../ascii-quest/lib/CombatAccessGuard.php';
if (is_file($combatAccessGuardPath)) {
    require_once $combatAccessGuardPath;
}

final class FakeCombatPdo extends PDO
{
    public array $users = [];
    public array $characters = [];
    public array $encounters = [];
    public array $actions = [];
    public array $events = [];
    public array $preparedSql = [];
    public array $lockOrder = [];
    public bool $duplicateEncounterOnNextInsert = false;

    private bool $transactionActive = false;
    private ?array $snapshot = null;
    private int $nextEncounterId = 1;
    private int $nextActionId = 1;
    private int $nextEventId = 1;
    private string $lastInsertIdValue = '0';

    public function __construct()
    {
        $this->users = [7 => ['id' => 7], 8 => ['id' => 8]];
        $this->characters = [
            42 => [
                'id' => 42,
                'user_id' => 7,
                'current_map_id' => 2,
                'pos_x' => 19,
                'pos_y' => 12,
                'current_hp' => 145,
                'current_mana' => 80,
                'life_state' => 'alive',
            ],
            43 => [
                'id' => 43,
                'user_id' => 7,
                'current_map_id' => 2,
                'pos_x' => 18,
                'pos_y' => 12,
                'current_hp' => 110,
                'current_mana' => 70,
                'life_state' => 'alive',
            ],
            84 => [
                'id' => 84,
                'user_id' => 8,
                'current_map_id' => 2,
                'pos_x' => 18,
                'pos_y' => 12,
                'current_hp' => 100,
                'current_mana' => 60,
                'life_state' => 'alive',
            ],
        ];
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->preparedSql[] = $query;

        return new FakeCombatPdoStatement($this, $query);
    }

    public function beginTransaction(): bool
    {
        if ($this->transactionActive) {
            throw new PDOException('Transaction already active.');
        }

        $this->snapshot = [
            $this->encounters,
            $this->actions,
            $this->events,
            $this->nextEncounterId,
            $this->nextActionId,
            $this->nextEventId,
        ];
        $this->transactionActive = true;

        return true;
    }

    public function commit(): bool
    {
        if (!$this->transactionActive) {
            throw new PDOException('No active transaction.');
        }

        $this->snapshot = null;
        $this->transactionActive = false;

        return true;
    }

    public function rollBack(): bool
    {
        if (!$this->transactionActive || $this->snapshot === null) {
            throw new PDOException('No active transaction.');
        }

        [
            $this->encounters,
            $this->actions,
            $this->events,
            $this->nextEncounterId,
            $this->nextActionId,
            $this->nextEventId,
        ] = $this->snapshot;
        $this->snapshot = null;
        $this->transactionActive = false;

        return true;
    }

    public function inTransaction(): bool
    {
        return $this->transactionActive;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->lastInsertIdValue;
    }

    public function executeStatement(string $sql, array $params): array
    {
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($sql)) ?? $sql);

        if (str_starts_with($normalized, 'select') && str_contains($normalized, 'from users')) {
            if (str_contains($normalized, 'for update')) {
                $this->lockOrder[] = 'account';
            }
            $user = $this->users[(int) $params['user_id']] ?? null;

            return [
                'rows' => $user !== null ? [$user] : [],
                'row_count' => $user !== null ? 1 : 0,
            ];
        }

        if (str_starts_with($normalized, 'select') && str_contains($normalized, 'from characters')) {
            if (str_contains($normalized, 'for update')) {
                $this->lockOrder[] = 'champion';
            }
            $character = $this->characters[(int) $params['character_id']] ?? null;
            $rows = $character !== null && $character['user_id'] === (int) $params['user_id']
                ? [$character]
                : [];

            return ['rows' => $rows, 'row_count' => count($rows)];
        }

        if (
            str_starts_with($normalized, 'select ce.id') &&
            str_contains($normalized, 'from combat_encounters ce')
        ) {
            $ownedCharacterIds = array_map(
                static fn (array $character): int => (int) $character['id'],
                array_filter(
                    $this->characters,
                    static fn (array $character): bool =>
                        (int) $character['user_id'] === (int) $params['user_id'],
                ),
            );
            $rows = array_values(array_filter(
                $this->encounters,
                static fn (array $row): bool =>
                    in_array((int) $row['character_id'], $ownedCharacterIds, true) &&
                    $row['active_slot'] === 1,
            ));
            usort($rows, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
            $rows = array_map(
                static fn (array $row): array => ['id' => $row['id']],
                array_slice($rows, 0, 1),
            );

            return ['rows' => $rows, 'row_count' => count($rows)];
        }

        if (
            str_starts_with($normalized, 'select') &&
            str_contains($normalized, 'from combat_encounters') &&
            array_key_exists('encounter_id', $params)
        ) {
            if (str_contains($normalized, 'for update')) {
                $this->lockOrder[] = 'encounter';
            }
            $encounter = $this->encounters[(int) $params['encounter_id']] ?? null;
            $rows = $encounter !== null && $encounter['active_slot'] === 1
                ? [$encounter]
                : [];

            return ['rows' => $rows, 'row_count' => count($rows)];
        }

        if (str_starts_with($normalized, 'select') && str_contains($normalized, 'from combat_encounters')) {
            if (str_contains($normalized, 'for update')) {
                $this->lockOrder[] = 'encounter';
            }
            $rows = array_values(array_filter(
                $this->encounters,
                static fn (array $row): bool =>
                    $row['character_id'] === (int) $params['character_id']
                    && $row['active_slot'] === 1,
            ));

            return ['rows' => array_slice($rows, 0, 1), 'row_count' => count($rows) > 0 ? 1 : 0];
        }

        if (str_starts_with($normalized, 'insert into combat_encounters')) {
            if ($this->duplicateEncounterOnNextInsert) {
                $this->duplicateEncounterOnNextInsert = false;
                $id = $this->nextEncounterId++;
                $this->encounters[$id] = array_merge($params, ['id' => $id]);
                throw new PDOException('Duplicate active encounter.', 23000);
            }

            $id = $this->nextEncounterId++;
            $this->encounters[$id] = array_merge($params, ['id' => $id]);
            $this->lastInsertIdValue = (string) $id;

            return ['rows' => [], 'row_count' => 1];
        }

        if (str_starts_with($normalized, 'update combat_encounters')) {
            $id = (int) $params['encounter_id'];
            $encounter = $this->encounters[$id] ?? null;
            if ($encounter === null || $encounter['version'] !== (int) $params['expected_version']) {
                return ['rows' => [], 'row_count' => 0];
            }

            $this->encounters[$id]['timeline_elapsed_ms'] = (int) $params['timeline_elapsed_ms'];
            $this->encounters[$id]['last_synchronized_at'] = $params['last_synchronized_at'];
            $this->encounters[$id]['version']++;

            return ['rows' => [], 'row_count' => 1];
        }

        if (str_starts_with($normalized, 'select') && str_contains($normalized, 'from combat_actions')) {
            if (str_contains($normalized, 'for update')) {
                $this->lockOrder[] = 'action';
            }
            $rows = array_values(array_filter(
                $this->actions,
                static fn (array $row): bool =>
                    $row['encounter_id'] === (int) $params['encounter_id']
                    && $row['request_token'] === $params['request_token'],
            ));

            return ['rows' => array_slice($rows, 0, 1), 'row_count' => count($rows) > 0 ? 1 : 0];
        }

        if (str_starts_with($normalized, 'insert into combat_actions')) {
            foreach ($this->actions as $existing) {
                if (
                    $params['request_token'] !== null
                    && $existing['encounter_id'] === (int) $params['encounter_id']
                    && $existing['request_token'] === $params['request_token']
                ) {
                    throw new PDOException('Duplicate request token.', 23000);
                }
            }

            $id = $this->nextActionId++;
            $this->actions[$id] = array_merge($params, ['id' => $id]);
            $this->lastInsertIdValue = (string) $id;

            return ['rows' => [], 'row_count' => 1];
        }

        if (str_starts_with($normalized, 'select sequence_number') && str_contains($normalized, 'from combat_events')) {
            $this->lockOrder[] = 'event';
            $rows = array_values(array_filter(
                $this->events,
                static fn (array $row): bool => $row['encounter_id'] === (int) $params['encounter_id'],
            ));
            usort($rows, static fn (array $a, array $b): int => $b['sequence_number'] <=> $a['sequence_number']);

            return ['rows' => array_slice($rows, 0, 1), 'row_count' => count($rows) > 0 ? 1 : 0];
        }

        if (str_starts_with($normalized, 'insert into combat_events')) {
            $id = $this->nextEventId++;
            $this->events[$id] = array_merge($params, ['id' => $id]);
            $this->lastInsertIdValue = (string) $id;

            return ['rows' => [], 'row_count' => 1];
        }

        if (str_starts_with($normalized, 'select') && str_contains($normalized, 'from combat_events')) {
            $rows = array_values(array_filter(
                $this->events,
                static fn (array $row): bool => $row['encounter_id'] === (int) $params['encounter_id'],
            ));
            usort($rows, static fn (array $a, array $b): int => $a['sequence_number'] <=> $b['sequence_number']);

            return ['rows' => $rows, 'row_count' => count($rows)];
        }

        throw new RuntimeException('Unexpected prepared SQL: ' . $normalized);
    }
}

final class FakeCombatPdoStatement extends PDOStatement
{
    private array $rows = [];
    private int $rowCountValue = 0;

    public function __construct(
        private FakeCombatPdo $pdo,
        private string $sql,
    ) {
    }

    public function execute(?array $params = null): bool
    {
        $result = $this->pdo->executeStatement($this->sql, $params ?? []);
        $this->rows = $result['rows'];
        $this->rowCountValue = $result['row_count'];

        return true;
    }

    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0,
    ): mixed {
        return array_shift($this->rows) ?? false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = $this->rows;
        $this->rows = [];

        return $rows;
    }

    public function rowCount(): int
    {
        return $this->rowCountValue;
    }
}

function combatRepositoryFixture(): array
{
    if (!class_exists('CombatRepository')) {
        throw new RuntimeException('CombatRepository must exist.');
    }

    $pdo = new FakeCombatPdo();

    return [new CombatRepository($pdo), $pdo];
}

function combatEncounterFixture(array $overrides = []): array
{
    return array_replace([
        'enemy_key' => 'cave_brute',
        'status' => 'active',
        'active_slot' => 1,
        'enemy_max_hp' => 160,
        'enemy_current_hp' => 160,
        'timeline_elapsed_ms' => 0,
        'last_synchronized_at' => '2026-08-31 12:00:00.000000',
        'turn_number' => 1,
        'turn_started_timeline_ms' => 0,
        'next_enemy_decision_timeline_ms' => 0,
        'player_actions_remaining' => 1,
        'enemy_actions_remaining' => 2,
        'potion_key' => 'foundation_health_potion',
        'potion_charge_allowance' => 1,
        'potion_charges_remaining' => 1,
        'reward_gold' => 12,
        'reward_experience' => 20,
        'version' => 1,
    ], $overrides);
}

function combatActionFixture(string $requestToken): array
{
    return [
        'actor' => 'player',
        'action_kind' => 'weapon',
        'definition_key' => 'prototype_weapon_attack',
        'request_token' => $requestToken,
        'active_slot' => 1,
        'state' => 'pending',
        'started_timeline_ms' => 100,
        'resolves_timeline_ms' => 1100,
        'cooldown_ready_timeline_ms' => 2100,
    ];
}

function assertCombatRepositoryRejected(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (LogicException) {
        return;
    }

    throw new RuntimeException($message . ' Expected LogicException.');
}

function seedActiveCombat(FakeCombatPdo $pdo): void
{
    $pdo->encounters[10] = array_merge(
        combatEncounterFixture(),
        ['id' => 10, 'character_id' => 42],
    );
}

return [
    'Combat repository owned lookup never exposes another user Champion' => function (): void {
        [$repository, $pdo] = combatRepositoryFixture();

        assertSameValue(42, $repository->findOwnedCharacter(7, 42)['id'] ?? null, 'Owned Champion.');
        assertSameValue(null, $repository->findOwnedCharacter(7, 84), 'Another user Champion.');
        if (str_contains(strtolower(implode("\n", $pdo->preparedSql)), 'for update')) {
            throw new RuntimeException('Read-only ownership lookup must not acquire a row lock.');
        }
    },

    'Existing encounter mutation locks Champion then encounter' => function (): void {
        [$repository, $pdo] = combatRepositoryFixture();
        seedActiveCombat($pdo);

        $repository->beginTransaction();
        $repository->lockOwnedCharacter(7, 42);
        $encounter = $repository->lockActiveEncounter(42);
        $updated = $repository->updateEncounterSynchronization(
            (int) $encounter['id'],
            2500,
            '2026-08-31 12:00:02.500000',
            1,
        );
        $repository->commit();

        assertSameValue(['champion', 'encounter'], $pdo->lockOrder, 'Existing encounter lock order.');
        assertSameValue(true, $updated, 'Synchronization update.');
        assertSameValue(2500, $pdo->encounters[10]['timeline_elapsed_ms'], 'Logical timeline update.');
        assertSameValue(2, $pdo->encounters[10]['version'], 'Optimistic version increment.');
    },

    'Movement-triggered creation locks Champion then empty active encounter slot' => function (): void {
        [$repository, $pdo] = combatRepositoryFixture();

        $repository->beginTransaction();
        $repository->lockOwnedCharacter(7, 42);
        assertSameValue(null, $repository->lockActiveEncounter(42), 'No existing encounter.');
        $created = $repository->createEncounter(42, combatEncounterFixture());
        $repository->commit();

        assertSameValue(['champion', 'encounter'], $pdo->lockOrder, 'Creation lock order.');
        assertSameValue(1, $created['id'], 'Created encounter identity.');
        assertSameValue(42, $pdo->encounters[1]['character_id'], 'Created encounter Champion.');
    },

    'Active encounter uniqueness returns the database-guarded competing row' => function (): void {
        [$repository, $pdo] = combatRepositoryFixture();
        $pdo->duplicateEncounterOnNextInsert = true;

        $repository->beginTransaction();
        $repository->lockOwnedCharacter(7, 42);
        $repository->lockActiveEncounter(42);
        $encounter = $repository->createEncounter(42, combatEncounterFixture());
        $repository->commit();

        assertSameValue(1, count($pdo->encounters), 'Exactly one active encounter.');
        assertSameValue(1, $encounter['id'], 'Competing encounter is returned.');
        assertSameValue(['champion', 'encounter', 'encounter'], $pdo->lockOrder, 'Duplicate recovery lock order.');
    },

    'Request-token replay returns one persisted action' => function (): void {
        [$repository, $pdo] = combatRepositoryFixture();
        seedActiveCombat($pdo);

        $repository->beginTransaction();
        $repository->lockOwnedCharacter(7, 42);
        $repository->lockActiveEncounter(42);
        $first = $repository->createAction(10, combatActionFixture('11111111-1111-4111-8111-111111111111'));
        $replay = $repository->createAction(10, combatActionFixture('11111111-1111-4111-8111-111111111111'));
        $repository->commit();

        assertSameValue($first['id'], $replay['id'], 'Replay action identity.');
        assertSameValue(1, count($pdo->actions), 'One persisted action.');
        assertSameValue(['champion', 'encounter', 'action', 'action'], $pdo->lockOrder, 'Action lock order.');
    },

    'Battle Info events append immutable ordered sequences' => function (): void {
        [$repository, $pdo] = combatRepositoryFixture();
        seedActiveCombat($pdo);

        $repository->beginTransaction();
        $repository->lockOwnedCharacter(7, 42);
        $repository->lockActiveEncounter(42);
        $first = $repository->appendEvent(10, 'encounter_started', 'The Cave Brute engages.', 'warning');
        $second = $repository->appendEvent(10, 'player_action', 'You begin an attack.', null);
        $repository->commit();

        $events = $repository->eventsForEncounter(10);
        assertSameValue([1, 2], array_column($events, 'sequence_number'), 'Ordered event sequences.');
        assertSameValue(1, $first['sequence_number'], 'First event sequence.');
        assertSameValue(2, $second['sequence_number'], 'Second event sequence.');
        assertSameValue(['champion', 'encounter', 'event', 'event'], $pdo->lockOrder, 'Event lock order.');
    },

    'Combat repository commit and rollback preserve atomic event state' => function (): void {
        [$repository, $pdo] = combatRepositoryFixture();
        seedActiveCombat($pdo);

        $repository->beginTransaction();
        $repository->lockOwnedCharacter(7, 42);
        $repository->lockActiveEncounter(42);
        $repository->appendEvent(10, 'temporary', 'This will roll back.', null);
        $repository->rollBack();
        assertSameValue([], $repository->eventsForEncounter(10), 'Rolled-back event.');

        $repository->beginTransaction();
        $repository->lockOwnedCharacter(7, 42);
        $repository->lockActiveEncounter(42);
        $repository->appendEvent(10, 'committed', 'This remains.', null);
        $repository->commit();
        assertSameValue(['committed'], array_column($repository->eventsForEncounter(10), 'event_type'), 'Committed event.');
    },

    'Combat repository rejects lock-order violations and mutation outside transactions' => function (): void {
        [$repository] = combatRepositoryFixture();

        assertCombatRepositoryRejected(
            fn (): ?array => $repository->lockOwnedCharacter(7, 42),
            'Champion lock outside transaction.',
        );
        assertCombatRepositoryRejected(
            fn (): ?array => $repository->lockActiveEncounter(42),
            'Encounter lock before Champion.',
        );
        assertCombatRepositoryRejected(
            fn (): array => $repository->createEncounter(42, combatEncounterFixture()),
            'Encounter creation outside transaction.',
        );
        assertCombatRepositoryRejected(
            fn (): array => $repository->createAction(10, combatActionFixture('22222222-2222-4222-8222-222222222222')),
            'Action creation outside transaction.',
        );
        assertCombatRepositoryRejected(
            fn (): array => $repository->appendEvent(10, 'invalid', 'No transaction.', null),
            'Event append outside transaction.',
        );

        $repository->beginTransaction();
        assertCombatRepositoryRejected(
            fn (): ?array => $repository->lockActiveEncounter(42),
            'Encounter lock before Champion in transaction.',
        );
        $repository->rollBack();
    },

    'Real combat repository locks Champion account mutex then another Champion encounter' => function (): void {
        [$repository, $pdo] = combatRepositoryFixture();
        seedActiveCombat($pdo);
        $beforeCharacters = $pdo->characters;
        $beforeEncounters = $pdo->encounters;

        $repository->beginTransaction();
        $character = $repository->lockOwnedCharacter(7, 43);
        $encounter = $repository->lockOwnedAccountActiveEncounter(7, 43);

        assertSameValue(['champion', 'account', 'encounter'], $pdo->lockOrder, 'Account combat lock order.');
        assertSameValue(42, $encounter['character_id'] ?? null, 'Another owned Champion encounter.');

        $guard = new CombatAccessGuard($repository);
        $rejected = false;
        try {
            $guard->assertLockedAllowed(
                CombatAccessGuard::MOVE,
                7,
                $character,
                $encounter,
            );
        } catch (DomainException) {
            $rejected = true;
        }
        assertSameValue(true, $rejected, 'Other Champion exploration rejection.');
        assertSameValue($beforeCharacters, $pdo->characters, 'No Champion mutation after rejection.');
        assertSameValue($beforeEncounters, $pdo->encounters, 'No encounter mutation after rejection.');
        $repository->rollBack();

        $repository->beginTransaction();
        assertCombatRepositoryRejected(
            fn (): ?array => $repository->lockOwnedAccountActiveEncounter(7, 43),
            'Account mutex before Champion lock.',
        );
        $repository->rollBack();
    },
];

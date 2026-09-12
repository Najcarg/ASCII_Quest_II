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
    public array $writeOrder = [];
    public array $resolutionObservations = [];
    public bool $duplicateEncounterOnNextInsert = false;
    public bool $failActionsRead = false;
    public bool $failSynchronizationUpdate = false;
    public ?int $failSynchronizationUpdateOnAttempt = null;
    public int $synchronizationUpdateAttempts = 0;
    public bool $failActionInsert = false;
    public bool $failActionResolution = false;
    public bool $failCharacterHpUpdate = false;

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
                'strength' => 10,
                'dexterity' => 5,
                'vitality' => 10,
                'energy' => 5,
                'fate' => 5,
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
            $this->characters,
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
            $this->characters,
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

        if (str_starts_with($normalized, 'update characters set current_hp')) {
            if ($normalized !== 'update characters set current_hp = :new_current_hp where id = :character_id and user_id = :user_id and current_hp = :expected_current_hp') {
                throw new RuntimeException('Unexpected Champion HP update SQL.');
            }
            $id = (int) $params['character_id'];
            if ($this->failCharacterHpUpdate) {
                return ['rows' => [], 'row_count' => 0];
            }
            $character = $this->characters[$id] ?? null;
            if (
                $character === null ||
                (int) $character['user_id'] !== (int) $params['user_id'] ||
                (int) $character['current_hp'] !== (int) $params['expected_current_hp']
            ) {
                return ['rows' => [], 'row_count' => 0];
            }

            $this->characters[$id]['current_hp'] = (int) $params['new_current_hp'];
            $this->writeOrder[] = ['kind' => 'character'];

            return ['rows' => [], 'row_count' => 1];
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
            if (
                $encounter !== null &&
                !array_key_exists('enemy_ai_initialized_timeline_ms', $encounter)
            ) {
                $encounter['enemy_ai_initialized_timeline_ms'] = null;
            }
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
            $rows = array_map(static function (array $row): array {
                if (!array_key_exists('enemy_ai_initialized_timeline_ms', $row)) {
                    $row['enemy_ai_initialized_timeline_ms'] = null;
                }

                return $row;
            }, $rows);

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
            $setClause = trim((string) preg_replace(
                '/^update combat_encounters set (.*?) where .*$/',
                '$1',
                $normalized,
            ));
            $legacySet = 'timeline_elapsed_ms = :timeline_elapsed_ms, last_synchronized_at = :last_synchronized_at, version = version + 1';
            $synchronizationSet = 'timeline_elapsed_ms = :timeline_elapsed_ms, last_synchronized_at = :last_synchronized_at, turn_number = :turn_number, turn_started_timeline_ms = :turn_started_timeline_ms, player_actions_remaining = :player_actions_remaining, enemy_actions_remaining = :enemy_actions_remaining, enemy_current_hp = :enemy_current_hp, next_enemy_decision_timeline_ms = :next_enemy_decision_timeline_ms, enemy_ai_initialized_timeline_ms = :enemy_ai_initialized_timeline_ms, version = version + 1';
            if (!in_array($setClause, [$legacySet, $synchronizationSet], true)) {
                throw new RuntimeException('Unexpected combat encounter synchronization SET clause.');
            }
            $this->synchronizationUpdateAttempts++;
            $this->writeOrder[] = [
                'kind' => 'encounter',
                'expected_version' => (int) $params['expected_version'],
                'player_actions_remaining' => (int) ($params['player_actions_remaining'] ?? -1),
            ];
            $id = (int) $params['encounter_id'];
            $encounter = $this->encounters[$id] ?? null;
            if (
                $this->failSynchronizationUpdate ||
                $this->failSynchronizationUpdateOnAttempt === $this->synchronizationUpdateAttempts ||
                $encounter === null ||
                $encounter['version'] !== (int) $params['expected_version']
            ) {
                return ['rows' => [], 'row_count' => 0];
            }

            $this->encounters[$id]['timeline_elapsed_ms'] = (int) $params['timeline_elapsed_ms'];
            $this->encounters[$id]['last_synchronized_at'] = $params['last_synchronized_at'];
            foreach ([
                'turn_number',
                'turn_started_timeline_ms',
                'next_enemy_decision_timeline_ms',
                'player_actions_remaining',
                'enemy_actions_remaining',
                'enemy_current_hp',
            ] as $key) {
                if (array_key_exists($key, $params)) {
                    $this->encounters[$id][$key] = (int) $params[$key];
                }
            }
            if (array_key_exists('enemy_ai_initialized_timeline_ms', $params)) {
                if (
                    array_key_exists('enemy_ai_initialized_timeline_ms', $this->encounters[$id]) ||
                    $params['enemy_ai_initialized_timeline_ms'] !== null
                ) {
                    $this->encounters[$id]['enemy_ai_initialized_timeline_ms'] =
                        $params['enemy_ai_initialized_timeline_ms'] === null
                            ? null
                            : (int) $params['enemy_ai_initialized_timeline_ms'];
                }
            }
            $this->encounters[$id]['version']++;

            return ['rows' => [], 'row_count' => 1];
        }

        if (str_starts_with($normalized, 'select') && str_contains($normalized, 'from combat_actions')) {
            if ($this->failActionsRead && !str_contains($normalized, 'for update')) {
                throw new RuntimeException('Injected action projection failure.');
            }
            if (str_contains($normalized, 'for update')) {
                $this->lockOrder[] = 'action';
            }
            $rows = array_values(array_filter(
                $this->actions,
                static fn (array $row): bool =>
                    $row['encounter_id'] === (int) $params['encounter_id'] &&
                    (!str_contains($normalized, "and state = 'pending'") ||
                        $row['state'] === 'pending') &&
                    (!array_key_exists('request_token', $params) ||
                        $row['request_token'] === $params['request_token']),
            ));

            if (array_key_exists('request_token', $params)) {
                $rows = array_slice($rows, 0, 1);
            } elseif (str_contains($normalized, 'order by resolves_timeline_ms asc, id asc')) {
                usort($rows, static function (array $left, array $right): int {
                    $position = (int) $left['resolves_timeline_ms'] <=>
                        (int) $right['resolves_timeline_ms'];

                    return $position !== 0 ? $position : (int) $left['id'] <=> (int) $right['id'];
                });
            } elseif (str_contains($normalized, 'order by id asc')) {
                usort($rows, static fn (array $left, array $right): int =>
                    (int) $left['id'] <=> (int) $right['id']);
            }

            return ['rows' => $rows, 'row_count' => count($rows)];
        }

        if (str_starts_with($normalized, 'insert into combat_actions')) {
            if ($this->failActionInsert) {
                throw new RuntimeException('Injected action insert failure.');
            }
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
            $this->writeOrder[] = ['kind' => 'action'];
            $this->lastInsertIdValue = (string) $id;

            return ['rows' => [], 'row_count' => 1];
        }

        if (str_starts_with($normalized, 'update combat_actions')) {
            $setClause = trim((string) preg_replace('/^update combat_actions set (.*?) where .*$/', '$1', $normalized));
            $lifecycleSet = "state = 'resolved', active_slot = null, completed_timeline_ms = :completed_timeline_ms";
            $damageSet = "state = 'resolved', active_slot = null, completed_timeline_ms = :completed_timeline_ms, resolved_damage = :resolved_damage, prevented_damage = :prevented_damage";
            if (!in_array($setClause, [$lifecycleSet, $damageSet], true)) {
                throw new RuntimeException('Unexpected combat action resolution SET clause.');
            }
            $id = (int) $params['action_id'];
            if ($this->failActionResolution) {
                return ['rows' => [], 'row_count' => 0];
            }
            $action = $this->actions[$id] ?? null;
            if (
                $action === null ||
                $action['encounter_id'] !== (int) $params['encounter_id'] ||
                $action['state'] !== 'pending'
            ) {
                return ['rows' => [], 'row_count' => 0];
            }

            $this->actions[$id]['state'] = 'resolved';
            $this->actions[$id]['active_slot'] = null;
            $this->actions[$id]['completed_timeline_ms'] = (int) $params['completed_timeline_ms'];
            if ($setClause === $damageSet) {
                $this->actions[$id]['resolved_damage'] = (int) $params['resolved_damage'];
                $this->actions[$id]['prevented_damage'] = (int) $params['prevented_damage'];
            }
            $this->resolutionObservations[] = [
                'turn_number' => (int) $this->encounters[(int) $params['encounter_id']]['turn_number'],
                'player_actions_remaining' => (int) $this->encounters[(int) $params['encounter_id']]['player_actions_remaining'],
            ];

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
        'enemy_ai_initialized_timeline_ms' => 0,
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
        'snapshot_weapon_key' => 'prototype_weapon_attack',
        'snapshot_damage_type' => 'physical',
        'snapshot_base_damage' => 20,
        'snapshot_accuracy' => 15.0,
        'snapshot_critical_chance' => 15.0,
        'snapshot_critical_damage' => 20,
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

    'Weapon action snapshots persist and due resolution clears only the active slot' => function (): void {
        [$repository, $pdo] = combatRepositoryFixture();
        seedActiveCombat($pdo);
        $token = '33333333-3333-4333-8333-333333333333';

        $repository->beginTransaction();
        $repository->lockOwnedCharacter(7, 42);
        $repository->lockActiveEncounter(42);
        $created = $repository->createAction(10, combatActionFixture($token));
        $locked = $repository->lockActionsForEncounter(10);
        $resolved = $repository->resolveLockedAction(10, (int) $created['id'], 1100);
        $repository->commit();

        assertSameValue(20, $created['snapshot_base_damage'] ?? null, 'Server snapshot is persisted.');
        assertSameValue($token, $locked[0]['request_token'] ?? null, 'Relevant action rows are locked after the encounter.');
        assertSameValue(true, $resolved, 'Pending action resolves once.');
        assertSameValue('resolved', $pdo->actions[1]['state'], 'Resolved lifecycle state.');
        assertSameValue(null, $pdo->actions[1]['active_slot'], 'Resolved action releases its active slot.');
        assertSameValue(1100, $pdo->actions[1]['completed_timeline_ms'], 'Exact logical completion position.');
        assertSameValue(2100, $pdo->actions[1]['cooldown_ready_timeline_ms'], 'Cooldown position is immutable.');
        assertSameValue(20, $pdo->actions[1]['snapshot_base_damage'], 'Offensive snapshot is immutable.');
        assertSameValue(['champion', 'encounter', 'action', 'action'], $pdo->lockOrder, 'Action locks follow the encounter.');

        $resolutionSql = '';
        foreach ($pdo->preparedSql as $sql) {
            if (str_starts_with(strtolower(trim($sql)), 'update combat_actions')) {
                $resolutionSql = strtolower((string) preg_replace('/\s+/', ' ', trim($sql)));
            }
        }
        assertSameValue(true, preg_match(
            "~^update combat_actions set state = 'resolved', active_slot = null, completed_timeline_ms = :completed_timeline_ms where id = :action_id and encounter_id = :encounter_id and state = 'pending'$~",
            $resolutionSql,
        ) === 1, 'Resolution SQL changes only lifecycle fields with pending scoped predicates.');
        $setClause = (string) preg_replace('/^update combat_actions set (.*?) where .*$/', '$1', $resolutionSql);
        assertSameValue(false, str_contains($setClause, 'cooldown'), 'Resolution SQL does not rewrite cooldown state.');
        assertSameValue(false, str_contains($setClause, 'snapshot'), 'Resolution SQL does not rewrite snapshots.');
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

    'Combat repository persists the enemy AI marker and complete synchronization state' => function (): void {
        [$repository, $pdo] = combatRepositoryFixture();

        $repository->beginTransaction();
        $repository->lockOwnedCharacter(7, 42);
        $repository->lockActiveEncounter(42);
        $created = $repository->createEncounter(42, combatEncounterFixture());
        $updatedState = array_replace($created, [
            'timeline_elapsed_ms' => 5000,
            'last_synchronized_at' => '2026-09-11 12:00:05.000000',
            'turn_number' => 1,
            'turn_started_timeline_ms' => 0,
            'next_enemy_decision_timeline_ms' => 5000,
            'enemy_ai_initialized_timeline_ms' => 0,
            'player_actions_remaining' => 0,
            'enemy_actions_remaining' => 1,
            'enemy_current_hp' => 111,
        ]);
        $updated = $repository->updateLockedEncounterSynchronization(
            (int) $created['id'],
            $updatedState,
            1,
        );
        $repository->commit();

        assertSameValue(0, $created['enemy_ai_initialized_timeline_ms'] ?? null, 'New encounter marker.');
        assertSameValue(true, $updated, 'Complete synchronization update.');
        assertSameValue(111, $pdo->encounters[1]['enemy_current_hp'] ?? null, 'Enemy HP persists.');
        assertSameValue(0, $pdo->encounters[1]['enemy_ai_initialized_timeline_ms'] ?? null, 'AI marker persists.');
        assertSameValue(5000, $pdo->encounters[1]['next_enemy_decision_timeline_ms'], 'Enemy decision schedule persists.');

        $insertSql = '';
        $updateSql = '';
        foreach ($pdo->preparedSql as $preparedSql) {
            $normalized = strtolower((string) preg_replace('/\s+/', ' ', trim($preparedSql)));
            if (str_starts_with($normalized, 'insert into combat_encounters')) {
                $insertSql = $normalized;
            }
            if (str_starts_with($normalized, 'update combat_encounters')) {
                $updateSql = $normalized;
            }
        }
        assertSameValue(true, str_contains(
            $insertSql,
            'enemy_ai_initialized_timeline_ms',
        ), 'Encounter INSERT includes the initialization marker.');
        assertSameValue(true, str_contains(
            $updateSql,
            'enemy_current_hp = :enemy_current_hp',
        ), 'Synchronization SQL updates enemy HP.');
        assertSameValue(true, str_contains(
            $updateSql,
            'enemy_ai_initialized_timeline_ms = :enemy_ai_initialized_timeline_ms',
        ), 'Synchronization SQL updates the initialization marker.');
        assertSameValue(true, str_contains(
            $updateSql,
            'next_enemy_decision_timeline_ms = :next_enemy_decision_timeline_ms',
        ), 'Synchronization SQL updates the decision schedule.');
        assertSameValue(true, str_contains(
            $updateSql,
            'where id = :encounter_id and version = :expected_version',
        ), 'Synchronization retains optimistic versioning.');
    },

    'Pending action lock is chronological while all-history lock retains resolved cooldown rows' => function (): void {
        [$repository, $pdo] = combatRepositoryFixture();
        seedActiveCombat($pdo);
        $pdo->actions = [
            8 => ['id' => 8, 'encounter_id' => 10, 'actor' => 'enemy', 'state' => 'pending', 'resolves_timeline_ms' => 4500],
            3 => ['id' => 3, 'encounter_id' => 10, 'actor' => 'enemy', 'state' => 'resolved', 'resolves_timeline_ms' => 2000, 'cooldown_ready_timeline_ms' => 6000],
            5 => ['id' => 5, 'encounter_id' => 10, 'actor' => 'player', 'state' => 'pending', 'resolves_timeline_ms' => 3000],
            2 => ['id' => 2, 'encounter_id' => 10, 'actor' => 'enemy', 'state' => 'pending', 'resolves_timeline_ms' => 3000],
        ];

        $repository->beginTransaction();
        $repository->lockOwnedCharacter(7, 42);
        $repository->lockActiveEncounter(42);
        $all = $repository->lockActionsForEncounter(10);
        $pending = $repository->lockPendingActionsForEncounter(10);
        $repository->commit();

        assertSameValue([2, 3, 5, 8], array_column($all, 'id'), 'All history remains ID ordered.');
        assertSameValue(['pending', 'resolved', 'pending', 'pending'], array_column($all, 'state'), 'Resolved cooldown history remains available.');
        assertSameValue([2, 5, 8], array_column($pending, 'id'), 'Pending actions use resolve position then ID order.');
        assertSameValue(['champion', 'encounter', 'action', 'action'], $pdo->lockOrder, 'Action history locks follow the encounter.');

        $pendingSql = strtolower((string) preg_replace('/\s+/', ' ', $pdo->preparedSql[array_key_last($pdo->preparedSql)]));
        assertSameValue(
            'select * from combat_actions where encounter_id = :encounter_id and state = \'pending\' order by resolves_timeline_ms asc, id asc for update',
            trim($pendingSql),
            'Pending query is scoped, chronological, and locked.',
        );
    },

    'Single request-token lock cannot authorize resolution of another action' => function (): void {
        [$repository, $pdo] = combatRepositoryFixture();
        seedActiveCombat($pdo);
        $tokenA = '66666666-6666-4666-8666-666666666666';
        $tokenB = '77777777-7777-4777-8777-777777777777';
        $pdo->actions[4] = ['id' => 4, 'encounter_id' => 10] + combatActionFixture($tokenA);
        $pdo->actions[5] = array_replace(
            ['id' => 5, 'encounter_id' => 10] + combatActionFixture($tokenB),
            ['actor' => 'enemy', 'definition_key' => 'smash'],
        );

        $repository->beginTransaction();
        $repository->lockOwnedCharacter(7, 42);
        $repository->lockActiveEncounter(42);
        $lockedA = $repository->lockActionByRequestToken(10, $tokenA);
        assertSameValue(4, $lockedA['id'] ?? null, 'Only action A is locked by token.');
        assertCombatRepositoryRejected(
            fn (): bool => $repository->resolveLockedActionWithDamage(10, 5, 1100, 10, 8),
            'A single-row token lock cannot authorize action B resolution.',
        );

        $repository->lockPendingActionsForEncounter(10);
        $resolvedB = $repository->resolveLockedActionWithDamage(10, 5, 1100, 10, 8);
        $repository->commit();

        assertSameValue(true, $resolvedB, 'Broad pending-action lock authorizes B resolution.');
        assertSameValue('pending', $pdo->actions[4]['state'], 'Action A remains pending.');
        assertSameValue('resolved', $pdo->actions[5]['state'], 'Action B resolves after broad lock.');
    },

    'Damage resolution persists exact results once without changing snapshot or cooldown' => function (): void {
        [$repository, $pdo] = combatRepositoryFixture();
        seedActiveCombat($pdo);
        $pdo->actions[4] = ['id' => 4, 'encounter_id' => 10] + combatActionFixture(
            '44444444-4444-4444-8444-444444444444',
        );
        $beforeSnapshot = [
            'snapshot_base_damage' => $pdo->actions[4]['snapshot_base_damage'],
            'snapshot_accuracy' => $pdo->actions[4]['snapshot_accuracy'],
            'cooldown_ready_timeline_ms' => $pdo->actions[4]['cooldown_ready_timeline_ms'],
        ];

        $repository->beginTransaction();
        $repository->lockOwnedCharacter(7, 42);
        $repository->lockActiveEncounter(42);
        $repository->appendEvent(10, 'lock_probe', 'Event locks are not action locks.', null);
        assertCombatRepositoryRejected(
            fn (): bool => $repository->resolveLockedActionWithDamage(10, 4, 1100, 10, 10),
            'An event-row lock cannot authorize action resolution.',
        );
        $repository->lockPendingActionsForEncounter(10);
        $first = $repository->resolveLockedActionWithDamage(10, 4, 1100, 10, 10);
        $second = $repository->resolveLockedActionWithDamage(10, 4, 1100, 10, 10);
        $repository->commit();

        assertSameValue(true, $first, 'Pending action resolves.');
        assertSameValue(false, $second, 'Resolved action cannot resolve twice.');
        assertSameValue('resolved', $pdo->actions[4]['state'], 'Resolved lifecycle.');
        assertSameValue(null, $pdo->actions[4]['active_slot'], 'Active slot clears.');
        assertSameValue(1100, $pdo->actions[4]['completed_timeline_ms'], 'Exact completion position.');
        assertSameValue(10, $pdo->actions[4]['resolved_damage'], 'Resolved damage persists.');
        assertSameValue(10, $pdo->actions[4]['prevented_damage'], 'Prevented damage persists.');
        assertSameValue($beforeSnapshot, [
            'snapshot_base_damage' => $pdo->actions[4]['snapshot_base_damage'],
            'snapshot_accuracy' => $pdo->actions[4]['snapshot_accuracy'],
            'cooldown_ready_timeline_ms' => $pdo->actions[4]['cooldown_ready_timeline_ms'],
        ], 'Resolution preserves snapshot and cooldown.');

        $resolutionSql = '';
        foreach ($pdo->preparedSql as $preparedSql) {
            $normalized = strtolower((string) preg_replace('/\s+/', ' ', trim($preparedSql)));
            if (str_starts_with($normalized, 'update combat_actions')) {
                $resolutionSql = $normalized;
            }
        }
        assertSameValue(
            "update combat_actions set state = 'resolved', active_slot = null, completed_timeline_ms = :completed_timeline_ms, resolved_damage = :resolved_damage, prevented_damage = :prevented_damage where id = :action_id and encounter_id = :encounter_id and state = 'pending'",
            $resolutionSql,
            'Damage resolution updates only results and lifecycle for one pending encounter action.',
        );
    },

    'Champion current HP update is locked nonnegative and compare-and-swap guarded' => function (): void {
        [$repository, $pdo] = combatRepositoryFixture();

        $repository->beginTransaction();
        $repository->lockOwnedCharacter(7, 42);
        $toZero = $repository->updateLockedCharacterCurrentHp(7, 42, 145, 0);
        $stale = $repository->updateLockedCharacterCurrentHp(7, 42, 145, 10);

        $negativeRejected = false;
        $sqlCount = count($pdo->preparedSql);
        try {
            $repository->updateLockedCharacterCurrentHp(7, 42, 0, -1);
        } catch (InvalidArgumentException) {
            $negativeRejected = true;
        }
        $repository->commit();

        assertSameValue(true, $toZero, 'Zero HP is valid.');
        assertSameValue(false, $stale, 'Stale expected HP is rejected.');
        assertSameValue(true, $negativeRejected, 'Negative HP is rejected.');
        assertSameValue($sqlCount, count($pdo->preparedSql), 'Negative HP is rejected before SQL.');
        assertSameValue(0, $pdo->characters[42]['current_hp'], 'Successful compare-and-swap persists zero.');
    },

    'Combat repository rollback restores Task 6 aggregate action and schedule state' => function (): void {
        [$repository, $pdo] = combatRepositoryFixture();
        seedActiveCombat($pdo);
        $pdo->encounters[10]['enemy_ai_initialized_timeline_ms'] = null;
        $pdo->actions[4] = ['id' => 4, 'encounter_id' => 10] + combatActionFixture(
            '55555555-5555-4555-8555-555555555555',
        );
        $before = [
            'characters' => $pdo->characters,
            'encounters' => $pdo->encounters,
            'actions' => $pdo->actions,
            'events' => $pdo->events,
        ];

        $repository->beginTransaction();
        $repository->lockOwnedCharacter(7, 42);
        $encounter = $repository->lockActiveEncounter(42);
        $repository->lockPendingActionsForEncounter(10);
        assertSameValue(true, $repository->resolveLockedActionWithDamage(10, 4, 1100, 10, 10), 'Action write.');
        assertSameValue(true, $repository->updateLockedCharacterCurrentHp(7, 42, 145, 130), 'Champion HP write.');
        $firstUpdate = array_replace($encounter, [
            'timeline_elapsed_ms' => 2000,
            'last_synchronized_at' => '2026-09-11 12:00:02.000000',
            'turn_number' => 1,
            'turn_started_timeline_ms' => 0,
            'next_enemy_decision_timeline_ms' => 3000,
            'enemy_ai_initialized_timeline_ms' => 0,
            'player_actions_remaining' => 0,
            'enemy_actions_remaining' => 1,
            'enemy_current_hp' => 150,
        ]);
        assertSameValue(true, $repository->updateLockedEncounterSynchronization(10, $firstUpdate, 1), 'First encounter write.');
        $pdo->failSynchronizationUpdateOnAttempt = 2;
        $failed = $repository->updateLockedEncounterSynchronization(
            10,
            array_replace($firstUpdate, ['timeline_elapsed_ms' => 2500]),
            2,
        );
        assertSameValue(false, $failed, 'Injected later write fails.');
        $repository->rollBack();

        assertSameValue($before['characters'], $pdo->characters, 'Champion HP rollback.');
        assertSameValue($before['encounters'], $pdo->encounters, 'Marker HP schedule allowances and version rollback.');
        assertSameValue($before['actions'], $pdo->actions, 'Action lifecycle and results rollback.');
        assertSameValue($before['events'], $pdo->events, 'Event history rollback.');
        assertSameValue(['champion', 'encounter', 'action'], $pdo->lockOrder, 'Focused lock order remains Champion Encounter Action.');
    },

    'Repository write failures leave locked action and Champion rows unchanged' => function (): void {
        [$repository, $pdo] = combatRepositoryFixture();
        seedActiveCombat($pdo);
        $pdo->actions[4] = ['id' => 4, 'encounter_id' => 10] + combatActionFixture(
            '89898989-8989-4989-8989-898989898989',
        );
        $actionBefore = $pdo->actions[4];
        $characterBefore = $pdo->characters[42];
        $pdo->failActionResolution = true;
        $pdo->failCharacterHpUpdate = true;

        $repository->beginTransaction();
        $repository->lockOwnedCharacter(7, 42);
        $repository->lockActiveEncounter(42);
        $repository->lockPendingActionsForEncounter(10);

        assertSameValue(
            false,
            $repository->resolveLockedActionWithDamage(10, 4, 1100, 20, 0),
            'Injected action-result write failure is observable.',
        );
        assertSameValue(
            false,
            $repository->updateLockedCharacterCurrentHp(7, 42, 145, 125),
            'Injected Champion HP compare-and-swap failure is observable.',
        );
        $repository->rollBack();

        assertSameValue($actionBefore, $pdo->actions[4], 'Failed result write changes no action fields.');
        assertSameValue($characterBefore, $pdo->characters[42], 'Failed HP write changes no Champion fields.');
    },
];

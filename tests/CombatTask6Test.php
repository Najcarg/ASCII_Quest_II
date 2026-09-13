<?php
declare(strict_types=1);

require_once __DIR__ . '/../ascii-quest/lib/CombatRandomSource.php';
require_once __DIR__ . '/../ascii-quest/lib/CombatTurnEngine.php';

$caveBrutePolicyPath = __DIR__ . '/../ascii-quest/lib/CaveBrutePolicy.php';
if (is_file($caveBrutePolicyPath)) {
    require_once $caveBrutePolicyPath;
}
$enemyDefenseResolverPath = __DIR__ . '/../ascii-quest/lib/EnemyDefenseResolver.php';
if (is_file($enemyDefenseResolverPath)) {
    require_once $enemyDefenseResolverPath;
}
$prototypeEnemyDefenseResolverPath =
    __DIR__ . '/../ascii-quest/lib/PrototypeEnemyDefenseResolver.php';
if (is_file($prototypeEnemyDefenseResolverPath)) {
    require_once $prototypeEnemyDefenseResolverPath;
}
$championDamageResolverPath = __DIR__ . '/../ascii-quest/lib/ChampionDamageResolver.php';
if (is_file($championDamageResolverPath)) {
    require_once $championDamageResolverPath;
}
$prototypeChampionDamageResolverPath =
    __DIR__ . '/../ascii-quest/lib/PrototypeChampionDamageResolver.php';
if (is_file($prototypeChampionDamageResolverPath)) {
    require_once $prototypeChampionDamageResolverPath;
}
$prototypeBlockResolverPath = __DIR__ . '/../ascii-quest/lib/PrototypeBlockResolver.php';
if (is_file($prototypeBlockResolverPath)) {
    require_once $prototypeBlockResolverPath;
}
$combatActionResolverPath = __DIR__ . '/../ascii-quest/lib/CombatActionResolver.php';
if (is_file($combatActionResolverPath)) {
    require_once $combatActionResolverPath;
}

final class Task6SequenceRandomSource implements CombatRandomSource
{
    public int $integerCalls = 0;

    /** @var list<int> */
    private array $integers;

    /** @param list<int> $integers */
    public function __construct(array $integers)
    {
        $this->integers = $integers;
    }

    public function token(int $bytes = 32): string
    {
        return str_repeat('a', $bytes * 2);
    }

    public function integer(int $minimum, int $maximum): int
    {
        $this->integerCalls++;
        if ($this->integers === []) {
            throw new RuntimeException('No deterministic integer remains.');
        }

        $value = array_shift($this->integers);
        if ($value < $minimum || $value > $maximum) {
            throw new RuntimeException('Deterministic integer is outside the requested range.');
        }

        return $value;
    }
}

function task6CombatConfig(): array
{
    return require __DIR__ . '/../ascii-quest/config/combat.php';
}

function task6CaveBruteDefinition(): array
{
    return task6CombatConfig()['prototype_balance']['enemies']['cave_brute'];
}

function task6TurnState(int $timelineMs = 0, int $enemyActions = 2): array
{
    $state = (new CombatTurnEngine(10.0))->synchronizeTurn([], 0, 1, 2);
    $state['enemy_actions_remaining'] = $enemyActions;

    return (new CombatTurnEngine(10.0))->synchronizeTurn($state, $timelineMs, 1, 2);
}

function task6Policy(): object
{
    if (!class_exists('CaveBrutePolicy')) {
        throw new RuntimeException('CaveBrutePolicy must exist.');
    }

    return new CaveBrutePolicy(new CombatTurnEngine(10.0));
}

function task6EnemyDefenseResolver(Task6SequenceRandomSource $random): object
{
    if (!class_exists('PrototypeEnemyDefenseResolver')) {
        throw new RuntimeException('PrototypeEnemyDefenseResolver must exist.');
    }

    return new PrototypeEnemyDefenseResolver($random);
}

function task6ChampionDamageResolver(): object
{
    if (!class_exists('PrototypeChampionDamageResolver')) {
        throw new RuntimeException('PrototypeChampionDamageResolver must exist.');
    }

    return new PrototypeChampionDamageResolver();
}

function task6ActionResolver(
    CombatRepository $repository,
    Task5MutableEquipmentProvider $equipment,
    Task6SequenceRandomSource $random,
): object {
    if (!class_exists('CombatActionResolver')) {
        throw new RuntimeException('CombatActionResolver must exist.');
    }

    return new CombatActionResolver(
        $repository,
        CombatDefinitionRegistry::fromDefaultConfig(),
        $equipment,
        task6EnemyDefenseResolver($random),
        new PrototypeBlockResolver(
            task6ChampionDamageResolver(),
            $random,
            CombatDefinitionRegistry::fromDefaultConfig()->playerReaction('basic_block'),
        ),
    );
}

function task6PendingAction(array $overrides = []): array
{
    return array_replace(
        ['id' => 4, 'encounter_id' => 10] + combatActionFixture(
            '12121212-1212-4212-8212-121212121212',
        ),
        $overrides,
    );
}

function task6EnemyAction(string $definitionKey, array $overrides = []): array
{
    $definition = task6CaveBruteDefinition()['actions'][$definitionKey];

    return array_replace([
        'id' => 4,
        'encounter_id' => 10,
        'actor' => 'enemy',
        'action_kind' => $definition['kind'],
        'definition_key' => $definitionKey,
        'request_token' => null,
        'active_slot' => 1,
        'state' => 'pending',
        'started_timeline_ms' => 100,
        'resolves_timeline_ms' => 100 + (int) round($definition['duration_seconds'] * 1000),
        'cooldown_ready_timeline_ms' => 100 + (int) round(
            $definition['server_only']['cooldown_seconds'] * 1000,
        ),
        'snapshot_weapon_key' => null,
        'snapshot_damage_type' => $definition['damage_type'],
        'snapshot_base_damage' => $definition['server_only']['prototype_damage'],
        'snapshot_accuracy' => null,
        'snapshot_critical_chance' => null,
        'snapshot_critical_damage' => null,
        'completed_timeline_ms' => null,
        'resolved_damage' => null,
        'prevented_damage' => null,
    ], $overrides);
}

function task6AssertRejected(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (InvalidArgumentException | DomainException) {
        return;
    }

    throw new RuntimeException($message . ' Expected rejection.');
}

function task6SynchronizeCursor(
    array $encounterOverrides = [],
    array $actions = [],
    array $characterOverrides = [],
    string $serverNow = '2026-09-01 12:00:00.000000',
): array {
    $pdo = new FakeCombatPdo();
    $pdo->encounters[91] = task4Encounter(array_replace([
        'timeline_elapsed_ms' => 0,
        'last_synchronized_at' => '2026-09-01 12:00:00.000000',
        'turn_started_timeline_ms' => 0,
        'next_enemy_decision_timeline_ms' => 0,
        'enemy_ai_initialized_timeline_ms' => 0,
        'player_actions_remaining' => 1,
        'enemy_actions_remaining' => 2,
    ], $encounterOverrides));
    $pdo->characters[42] = array_replace($pdo->characters[42], $characterOverrides);
    foreach ($actions as $action) {
        $pdo->actions[(int) $action['id']] = $action;
    }
    $clock = new Task4MutableCombatClock(
        new DateTimeImmutable($serverNow, new DateTimeZone('UTC')),
    );

    return [task5RepositoryService($pdo, $clock)->state(7, 42), $pdo];
}

function task6ChronologicalService(
    FakeCombatPdo $pdo,
    Task4MutableCombatClock $clock,
    ?Task5MutableEquipmentProvider $equipment = null,
    ?Task6SequenceRandomSource $random = null,
): CombatService {
    $repository = new CombatRepository($pdo);
    $definitions = CombatDefinitionRegistry::fromDefaultConfig();
    $equipment ??= new Task5MutableEquipmentProvider();
    $random ??= new Task6SequenceRandomSource([]);
    $turnEngine = new CombatTurnEngine($definitions->turnDurationSeconds());
    $resolver = task6ActionResolver($repository, $equipment, $random);
    $synchronizer = new CombatSynchronizer(
        $clock,
        $turnEngine,
        $definitions->maxDisconnectedCatchupSeconds(),
        null,
        $repository,
        $definitions,
        new CaveBrutePolicy($turnEngine),
        $resolver,
    );

    return new CombatService(
        $repository,
        $definitions,
        $clock,
        $synchronizer,
        $equipment,
    );
}

return [
    'Cave Brute policy prefers Fire Slam and falls back to Smash during cooldown' => function (): void {
        $policy = task6Policy();
        $enemy = task6CaveBruteDefinition();
        $encounter = ['status' => 'active', 'enemy_current_hp' => 120];
        $turn = task6TurnState(2000);

        assertSameValue(
            ['decision' => 'start', 'definition_key' => 'fire_slam'],
            $policy->decide($encounter, 145, $enemy, [], $turn, 2000),
            'Ready skill is preferred.',
        );

        $history = [[
            'actor' => 'enemy',
            'definition_key' => 'fire_slam',
            'state' => 'resolved',
            'resolves_timeline_ms' => 2000,
            'cooldown_ready_timeline_ms' => 6000,
        ]];
        assertSameValue(
            ['decision' => 'start', 'definition_key' => 'smash'],
            $policy->decide($encounter, 145, $enemy, $history, $turn, 2000),
            'Resolved Fire Slam remains cooling from stored history.',
        );
        assertSameValue(
            ['decision' => 'start', 'definition_key' => 'fire_slam'],
            $policy->decide($encounter, 145, $enemy, $history, task6TurnState(6000), 6000),
            'Fire Slam is preferred again at its stored ready position.',
        );
    },

    'Cave Brute policy schedules waits for allowance busy state and Turn time' => function (): void {
        $policy = task6Policy();
        $enemy = task6CaveBruteDefinition();
        $encounter = ['status' => 'active', 'enemy_current_hp' => 120];

        assertSameValue(
            ['decision' => 'wait', 'next_timeline_ms' => 10000],
            $policy->decide($encounter, 145, $enemy, [], task6TurnState(3000, 0), 3000),
            'Exhausted allowance waits for Turn boundary.',
        );

        $pending = [[
            'actor' => 'enemy',
            'definition_key' => 'fire_slam',
            'state' => 'pending',
            'resolves_timeline_ms' => 5500,
            'cooldown_ready_timeline_ms' => 9500,
        ]];
        assertSameValue(
            ['decision' => 'wait', 'next_timeline_ms' => 5500],
            $policy->decide($encounter, 145, $enemy, $pending, task6TurnState(4000), 4000),
            'Pending enemy action waits for its resolve position.',
        );
        assertSameValue(
            ['decision' => 'wait', 'next_timeline_ms' => 10000],
            $policy->decide($encounter, 145, $enemy, [], task6TurnState(9000), 9000),
            'Insufficient time for both actions waits for Turn boundary.',
        );
    },

    'Cave Brute policy stops inactive or zero HP combatants' => function (): void {
        $policy = task6Policy();
        $enemy = task6CaveBruteDefinition();
        $turn = task6TurnState();

        foreach ([
            [['status' => 'victory_loot', 'enemy_current_hp' => 120], 145, 'Inactive encounter.'],
            [['status' => 'active', 'enemy_current_hp' => 0], 145, 'Enemy at zero HP.'],
            [['status' => 'active', 'enemy_current_hp' => 120], 0, 'Champion at zero HP.'],
        ] as [$encounter, $championHp, $label]) {
            assertSameValue(
                ['decision' => 'stop'],
                $policy->decide($encounter, $championHp, $enemy, [], $turn, 0),
                $label,
            );
        }
    },

    'Pending player action does not make Cave Brute busy' => function (): void {
        $history = [[
            'actor' => 'player',
            'definition_key' => 'prototype_weapon_attack',
            'state' => 'pending',
            'resolves_timeline_ms' => 2000,
            'cooldown_ready_timeline_ms' => 2500,
        ]];

        assertSameValue(
            ['decision' => 'start', 'definition_key' => 'fire_slam'],
            task6Policy()->decide(
                ['status' => 'active', 'enemy_current_hp' => 120],
                145,
                task6CaveBruteDefinition(),
                $history,
                task6TurnState(),
                0,
            ),
            'Player and enemy may act concurrently.',
        );
    },

    'Cave Brute Block succeeds on roll 20 with one authoritative roll' => function (): void {
        $random = new Task6SequenceRandomSource([20]);
        $result = task6EnemyDefenseResolver($random)->resolve(
            [
                'snapshot_base_damage' => 20,
                'snapshot_accuracy' => 5.0,
                'snapshot_critical_chance' => 99.0,
                'snapshot_critical_damage' => 999,
            ],
            task6CaveBruteDefinition(),
        );

        assertSameValue([
            'incoming_damage' => 20,
            'prevented_damage' => 10,
            'applied_damage' => 10,
            'blocked' => true,
        ], $result, 'Configured 20 percent Block succeeds at boundary roll 20.');
        assertSameValue(1, $random->integerCalls, 'One Block roll per player hit.');
    },

    'Cave Brute Block fails on roll 21 and ignores Accuracy and Critical fields' => function (): void {
        $firstRandom = new Task6SequenceRandomSource([21]);
        $first = task6EnemyDefenseResolver($firstRandom)->resolve(
            [
                'snapshot_base_damage' => 20,
                'snapshot_accuracy' => 0.0,
                'snapshot_critical_chance' => 0.0,
                'snapshot_critical_damage' => 0,
            ],
            task6CaveBruteDefinition(),
        );
        $secondRandom = new Task6SequenceRandomSource([21]);
        $second = task6EnemyDefenseResolver($secondRandom)->resolve(
            [
                'snapshot_base_damage' => 20,
                'snapshot_accuracy' => 100.0,
                'snapshot_critical_chance' => 100.0,
                'snapshot_critical_damage' => 1000,
            ],
            task6CaveBruteDefinition(),
        );

        assertSameValue([
            'incoming_damage' => 20,
            'prevented_damage' => 0,
            'applied_damage' => 20,
            'blocked' => false,
        ], $first, 'Roll 21 fails configured 20 percent Block.');
        assertSameValue($first, $second, 'Accuracy and Critical snapshots are deliberately unused.');
        assertSameValue(1, $firstRandom->integerCalls, 'First hit rolls once.');
        assertSameValue(1, $secondRandom->integerCalls, 'Second hit rolls once.');
    },

    'Champion physical damage uses current Toughness and ignores Dodging' => function (): void {
        $resolver = task6ChampionDamageResolver();
        $action = ['snapshot_base_damage' => 18, 'snapshot_damage_type' => 'physical'];
        $first = $resolver->resolve($action, [
            'toughness' => 20,
            'dodging' => 0.0,
            'resistances' => ['fire' => 25.0],
        ]);
        $second = $resolver->resolve($action, [
            'toughness' => 20,
            'dodging' => 100.0,
            'resistances' => ['fire' => 25.0],
        ]);

        assertSameValue([
            'incoming_damage' => 18,
            'prevented_damage' => 3,
            'applied_damage' => 15,
        ], $first, 'Physical damage uses the provisional Toughness formula.');
        assertSameValue($first, $second, 'Dodging is deliberately unused.');
    },

    'Champion Fire damage uses current Fire Resistance' => function (): void {
        $result = task6ChampionDamageResolver()->resolve(
            ['snapshot_base_damage' => 24, 'snapshot_damage_type' => 'fire'],
            [
                'toughness' => 999,
                'dodging' => 99.0,
                'resistances' => ['fire' => 25.0],
            ],
        );

        assertSameValue([
            'incoming_damage' => 24,
            'prevented_damage' => 6,
            'applied_damage' => 18,
        ], $result, 'Fire damage uses current Fire Resistance only.');
    },

    'Champion damage applies at least one for a positive valid attack' => function (): void {
        $resolver = task6ChampionDamageResolver();

        assertSameValue(1, $resolver->resolve(
            ['snapshot_base_damage' => 1, 'snapshot_damage_type' => 'physical'],
            ['toughness' => 1000000, 'resistances' => ['fire' => 0.0]],
        )['applied_damage'], 'Physical minimum damage.');
        assertSameValue(1, $resolver->resolve(
            ['snapshot_base_damage' => 1, 'snapshot_damage_type' => 'fire'],
            ['toughness' => 0, 'resistances' => ['fire' => 100.0]],
        )['applied_damage'], 'Fire minimum damage.');
    },

    'Champion damage rejects invalid stored enemy action data' => function (): void {
        $resolver = task6ChampionDamageResolver();
        $defense = ['toughness' => 20, 'resistances' => ['fire' => 25.0]];

        foreach ([
            [['snapshot_damage_type' => 'physical'], 'Missing damage.'],
            [['snapshot_base_damage' => 0, 'snapshot_damage_type' => 'physical'], 'Zero damage.'],
            [['snapshot_base_damage' => -1, 'snapshot_damage_type' => 'physical'], 'Negative damage.'],
            [['snapshot_base_damage' => 18, 'snapshot_damage_type' => 'poison'], 'Unsupported type.'],
        ] as [$action, $label]) {
            task6AssertRejected(
                fn (): array => $resolver->resolve($action, $defense),
                $label,
            );
        }
    },

    'Cave Brute action persistence uses authoritative server-only definition values' => function (): void {
        [$repository, $pdo] = combatRepositoryFixture();
        seedActiveCombat($pdo);
        $definition = task6CaveBruteDefinition()['actions']['fire_slam'];
        $durationMs = (int) round($definition['duration_seconds'] * 1000);
        $cooldownMs = (int) round($definition['server_only']['cooldown_seconds'] * 1000);

        $repository->beginTransaction();
        $repository->lockOwnedCharacter(7, 42);
        $repository->lockActiveEncounter(42);
        $created = $repository->createAction(10, [
            'actor' => 'enemy',
            'action_kind' => (string) $definition['kind'],
            'definition_key' => 'fire_slam',
            'request_token' => null,
            'active_slot' => 1,
            'state' => 'pending',
            'started_timeline_ms' => 3000,
            'resolves_timeline_ms' => 3000 + $durationMs,
            'cooldown_ready_timeline_ms' => 3000 + $cooldownMs,
            'snapshot_weapon_key' => null,
            'snapshot_damage_type' => (string) $definition['damage_type'],
            'snapshot_base_damage' => (int) $definition['server_only']['prototype_damage'],
            'snapshot_accuracy' => null,
            'snapshot_critical_chance' => null,
            'snapshot_critical_damage' => null,
        ]);
        $repository->commit();

        assertSameValue('enemy', $created['actor'], 'Server-created actor.');
        assertSameValue('skill', $created['action_kind'], 'Authoritative action kind.');
        assertSameValue('fire_slam', $created['definition_key'], 'Authoritative definition key.');
        assertSameValue(null, $created['request_token'], 'Enemy action has no browser request token.');
        assertSameValue([3000, 5000, 9000], [
            $created['started_timeline_ms'],
            $created['resolves_timeline_ms'],
            $created['cooldown_ready_timeline_ms'],
        ], 'Timing comes from server-owned duration and cooldown configuration.');
        assertSameValue('fire', $created['snapshot_damage_type'], 'Stored damage type.');
        assertSameValue(24, $created['snapshot_base_damage'], 'Stored server-only prototype damage.');
        assertSameValue([null, null, null, null], [
            $created['snapshot_weapon_key'],
            $created['snapshot_accuracy'],
            $created['snapshot_critical_chance'],
            $created['snapshot_critical_damage'],
        ], 'Player-only snapshots remain null.');
    },

    'Pending player weapon resolves stored damage and automatic Block exactly once' => function (): void {
        [$repository, $pdo] = combatRepositoryFixture();
        seedActiveCombat($pdo);
        $pdo->encounters[10]['enemy_current_hp'] = 120;
        $pdo->actions[4] = task6PendingAction([
            'resolves_timeline_ms' => 1100,
            'cooldown_ready_timeline_ms' => 2600,
            'snapshot_base_damage' => 20,
            'snapshot_accuracy' => 0.0,
            'snapshot_critical_chance' => 100.0,
            'snapshot_critical_damage' => 999,
        ]);
        $snapshotBefore = array_intersect_key($pdo->actions[4], array_flip([
            'snapshot_weapon_key',
            'snapshot_damage_type',
            'snapshot_base_damage',
            'snapshot_accuracy',
            'snapshot_critical_chance',
            'snapshot_critical_damage',
            'cooldown_ready_timeline_ms',
        ]));
        $equipment = new Task5MutableEquipmentProvider();
        $random = new Task6SequenceRandomSource([20]);
        $resolver = task6ActionResolver($repository, $equipment, $random);

        $repository->beginTransaction();
        $character = $repository->lockOwnedCharacter(7, 42);
        $encounter = $repository->lockActiveEncounter(42);
        $action = $repository->lockPendingActionsForEncounter(10)[0];
        $result = $resolver->resolvePending($encounter, $character, $action);
        $repository->commit();

        assertSameValue(110, $result['encounter']['enemy_current_hp'], 'Blocked stored damage reduces current aggregate enemy HP once.');
        assertSameValue($character, $result['character'], 'Player resolution does not change Champion resources.');
        assertSameValue(1, $random->integerCalls, 'Exactly one authoritative Block roll.');
        assertSameValue('resolved', $pdo->actions[4]['state'], 'Player action resolves.');
        assertSameValue(null, $pdo->actions[4]['active_slot'], 'Player action releases its active slot.');
        assertSameValue(1100, $pdo->actions[4]['completed_timeline_ms'], 'Completion uses the stored resolve position.');
        assertSameValue(10, $pdo->actions[4]['resolved_damage'], 'Applied damage is persisted.');
        assertSameValue(10, $pdo->actions[4]['prevented_damage'], 'Prevented damage is persisted.');
        assertSameValue($snapshotBefore, array_intersect_key($pdo->actions[4], $snapshotBefore), 'Snapshot and cooldown remain immutable.');
        assertSameValue(120, $pdo->encounters[10]['enemy_current_hp'], 'Encounter HP persistence remains the later synchronization boundary.');

        $repository->beginTransaction();
        $repository->lockOwnedCharacter(7, 42);
        $repository->lockActiveEncounter(42);
        $resolvedAction = $repository->lockActionsForEncounter(10)[0];
        task6AssertRejected(
            fn (): array => $resolver->resolvePending(
                $result['encounter'],
                $result['character'],
                $resolvedAction,
            ),
            'An already-resolved player action.',
        );
        $repository->rollBack();

        assertSameValue(1, $random->integerCalls, 'A resolved action never rolls Block again.');
        assertSameValue(110, $result['encounter']['enemy_current_hp'], 'A resolved action cannot damage again.');
        assertSameValue(10, $pdo->actions[4]['resolved_damage'], 'Resolved result is not rewritten.');
    },

    'Historical resolved player action is never reprocessed for damage' => function (): void {
        [$repository, $pdo] = combatRepositoryFixture();
        seedActiveCombat($pdo);
        $pdo->encounters[10]['enemy_current_hp'] = 87;
        $pdo->actions[4] = task6PendingAction([
            'state' => 'resolved',
            'active_slot' => null,
            'completed_timeline_ms' => 1100,
            'resolved_damage' => null,
            'prevented_damage' => null,
        ]);
        $random = new Task6SequenceRandomSource([]);
        $resolver = task6ActionResolver(
            $repository,
            new Task5MutableEquipmentProvider(),
            $random,
        );

        $repository->beginTransaction();
        $character = $repository->lockOwnedCharacter(7, 42);
        $encounter = $repository->lockActiveEncounter(42);
        assertSameValue([], $repository->lockPendingActionsForEncounter(10), 'Historical action is excluded from pending chronology.');
        $historical = $repository->lockActionsForEncounter(10)[0];
        task6AssertRejected(
            fn (): array => $resolver->resolvePending($encounter, $character, $historical),
            'Historical resolved player action.',
        );
        $repository->rollBack();

        assertSameValue(0, $random->integerCalls, 'Historical action causes no Block roll.');
        assertSameValue(87, $pdo->encounters[10]['enemy_current_hp'], 'Historical action manufactures no enemy damage.');
        assertSameValue(null, $pdo->actions[4]['resolved_damage'], 'Historical result remains untouched.');
    },

    'Smash resolution reads current Toughness and persists Champion HP once' => function (): void {
        [$repository, $pdo] = combatRepositoryFixture();
        seedActiveCombat($pdo);
        $pdo->actions[4] = task6EnemyAction('smash');
        $equipment = new Task5MutableEquipmentProvider();
        $equipment->defense = [
            'toughness' => 0,
            'dodging' => 100.0,
            'resistances' => ['fire' => 0.0, 'lightning' => 0.0, 'poison' => 0.0, 'cold' => 0.0],
        ];
        $resolver = task6ActionResolver(
            $repository,
            $equipment,
            new Task6SequenceRandomSource([]),
        );
        $equipment->defense['toughness'] = 20;

        $repository->beginTransaction();
        $character = $repository->lockOwnedCharacter(7, 42);
        $encounter = $repository->lockActiveEncounter(42);
        $action = $repository->lockPendingActionsForEncounter(10)[0];
        $result = $resolver->resolvePending($encounter, $character, $action);
        $repository->commit();

        assertSameValue(1, $equipment->defensiveReads, 'Current defense is read at hit resolution.');
        assertSameValue(130, $result['character']['current_hp'], 'Latest Toughness reduces Smash to 15 damage.');
        assertSameValue(130, $pdo->characters[42]['current_hp'], 'Champion HP compare-and-swap persists the result.');
        assertSameValue(15, $pdo->actions[4]['resolved_damage'], 'Smash applied damage persists.');
        assertSameValue(3, $pdo->actions[4]['prevented_damage'], 'Smash prevented damage persists.');
        assertSameValue('alive', $pdo->characters[42]['life_state'], 'Task 4 does not process permanent death.');
        assertSameValue('active', $result['encounter']['status'], 'Task 4 does not process terminal encounter status.');
    },

    'Fire Slam resolution uses current Fire Resistance and clamps Champion HP to zero' => function (): void {
        [$repository, $pdo] = combatRepositoryFixture();
        seedActiveCombat($pdo);
        $pdo->characters[42]['current_hp'] = 10;
        $pdo->actions[4] = task6EnemyAction('fire_slam');
        $snapshotBefore = array_intersect_key($pdo->actions[4], array_flip([
            'snapshot_damage_type',
            'snapshot_base_damage',
            'cooldown_ready_timeline_ms',
        ]));
        $equipment = new Task5MutableEquipmentProvider();
        $equipment->defense = [
            'toughness' => 999,
            'dodging' => 100.0,
            'resistances' => ['fire' => 25.0, 'lightning' => 0.0, 'poison' => 0.0, 'cold' => 0.0],
        ];
        $resolver = task6ActionResolver(
            $repository,
            $equipment,
            new Task6SequenceRandomSource([]),
        );

        $repository->beginTransaction();
        $character = $repository->lockOwnedCharacter(7, 42);
        $encounter = $repository->lockActiveEncounter(42);
        $action = $repository->lockPendingActionsForEncounter(10)[0];
        $result = $resolver->resolvePending($encounter, $character, $action);
        $repository->commit();

        assertSameValue(0, $result['character']['current_hp'], 'Incoming damage clamps Champion HP to zero.');
        assertSameValue(0, $pdo->characters[42]['current_hp'], 'Zero Champion HP is persisted.');
        assertSameValue(18, $pdo->actions[4]['resolved_damage'], 'Current Fire Resistance determines applied damage.');
        assertSameValue(6, $pdo->actions[4]['prevented_damage'], 'Fire prevention result persists.');
        assertSameValue($snapshotBefore, array_intersect_key($pdo->actions[4], $snapshotBefore), 'Enemy snapshot and cooldown remain immutable.');
        assertSameValue('alive', $pdo->characters[42]['life_state'], 'HP zero does not set life_state in Task 4.');
        assertSameValue(false, array_key_exists('died_at', $pdo->characters[42]), 'Task 4 does not set died_at.');
        assertSameValue('active', $result['encounter']['status'], 'HP zero does not create victory or defeated state.');
    },

    'Combat action resolver rejects unsupported actors actions and definition mismatches' => function (): void {
        [$repository] = combatRepositoryFixture();
        $equipment = new Task5MutableEquipmentProvider();
        $random = new Task6SequenceRandomSource([]);
        $resolver = task6ActionResolver($repository, $equipment, $random);
        $encounter = ['id' => 10, 'enemy_key' => 'cave_brute', 'enemy_current_hp' => 120, 'status' => 'active'];
        $character = ['id' => 42, 'user_id' => 7, 'current_hp' => 145];

        foreach ([
            [task6PendingAction(['actor' => 'spectator']), 'Unsupported actor.'],
            [task6PendingAction(['action_kind' => 'skill']), 'Player non-weapon action.'],
            [task6EnemyAction('smash', ['definition_key' => 'unknown']), 'Unknown enemy action.'],
            [task6EnemyAction('smash', ['action_kind' => 'skill']), 'Enemy definition mismatch.'],
            [task6PendingAction(['state' => 'resolved', 'active_slot' => null]), 'Non-pending action.'],
        ] as [$action, $label]) {
            task6AssertRejected(
                fn (): array => $resolver->resolvePending($encounter, $character, $action),
                $label,
            );
        }

        assertSameValue(0, $random->integerCalls, 'Rejected shapes cannot reach Block resolution.');
        assertSameValue(0, $equipment->defensiveReads, 'Rejected shapes cannot read current defense.');
    },

    'Cave Brute cursor decision preserves enemy sequencing and player concurrency' => function (): void {
        $enemyPending = task6EnemyAction('fire_slam', [
            'id' => 20,
            'encounter_id' => 91,
            'started_timeline_ms' => 0,
            'resolves_timeline_ms' => 2000,
            'cooldown_ready_timeline_ms' => 6000,
        ]);
        [, $busyPdo] = task6SynchronizeCursor([], [$enemyPending]);

        assertSameValue(1, count($busyPdo->actions), 'A pending enemy action prevents overlap.');
        assertSameValue(2, $busyPdo->encounters[91]['enemy_actions_remaining'], 'Waiting consumes no enemy Action.');
        assertSameValue(2000, $busyPdo->encounters[91]['next_enemy_decision_timeline_ms'], 'Busy enemy waits for its exact resolution.');

        $playerPending = task6PendingAction([
            'id' => 21,
            'encounter_id' => 91,
            'started_timeline_ms' => 0,
            'resolves_timeline_ms' => 1000,
            'cooldown_ready_timeline_ms' => 2500,
        ]);
        [, $concurrentPdo] = task6SynchronizeCursor([], [$playerPending]);
        $enemyActions = array_values(array_filter(
            $concurrentPdo->actions,
            static fn (array $action): bool => $action['actor'] === 'enemy',
        ));

        assertSameValue(1, count($enemyActions), 'A pending player action permits one concurrent enemy action.');
        assertSameValue('fire_slam', $enemyActions[0]['definition_key'], 'Concurrent enemy action remains skill-first.');
        assertSameValue(1, $concurrentPdo->encounters[91]['enemy_actions_remaining'], 'Concurrent enemy start consumes one allowance.');
    },

    'Cave Brute cursor decision uses resolved cooldown history for skill-first selection' => function (): void {
        $resolvedFireSlam = task6EnemyAction('fire_slam', [
            'id' => 30,
            'encounter_id' => 91,
            'state' => 'resolved',
            'active_slot' => null,
            'started_timeline_ms' => 0,
            'resolves_timeline_ms' => 2000,
            'cooldown_ready_timeline_ms' => 6000,
            'completed_timeline_ms' => 2000,
        ]);

        [, $coolingPdo] = task6SynchronizeCursor([
            'timeline_elapsed_ms' => 2000,
            'last_synchronized_at' => '2026-09-01 12:00:00.000000',
            'next_enemy_decision_timeline_ms' => 2000,
        ], [$resolvedFireSlam]);
        $coolingStarts = array_values(array_filter(
            $coolingPdo->actions,
            static fn (array $action): bool => $action['state'] === 'pending',
        ));
        assertSameValue('smash', $coolingStarts[0]['definition_key'] ?? null, 'Resolved Fire Slam remains cooling before its stored ready position.');
        assertSameValue([2000, 3500, 5000], [
            $coolingStarts[0]['started_timeline_ms'],
            $coolingStarts[0]['resolves_timeline_ms'],
            $coolingStarts[0]['cooldown_ready_timeline_ms'],
        ], 'Smash timing starts at the authoritative cursor.');

        [, $readyPdo] = task6SynchronizeCursor([
            'timeline_elapsed_ms' => 6000,
            'last_synchronized_at' => '2026-09-01 12:00:00.000000',
            'next_enemy_decision_timeline_ms' => 6000,
        ], [$resolvedFireSlam]);
        $readyStarts = array_values(array_filter(
            $readyPdo->actions,
            static fn (array $action): bool => $action['state'] === 'pending',
        ));
        assertSameValue('fire_slam', $readyStarts[0]['definition_key'] ?? null, 'Fire Slam is preferred at its stored cooldown-ready position.');
    },

    'Cave Brute cursor decision persists strictly future wait positions' => function (): void {
        $cases = [
            'exhausted allowance' => [
                ['timeline_elapsed_ms' => 3000, 'next_enemy_decision_timeline_ms' => 3000, 'enemy_actions_remaining' => 0],
                [],
                10000,
            ],
            'insufficient Turn time' => [
                ['timeline_elapsed_ms' => 9000, 'next_enemy_decision_timeline_ms' => 9000],
                [],
                10000,
            ],
            'earliest cooldown' => [
                ['timeline_elapsed_ms' => 3000, 'next_enemy_decision_timeline_ms' => 3000],
                [
                    task6EnemyAction('fire_slam', [
                        'id' => 40,
                        'encounter_id' => 91,
                        'state' => 'resolved',
                        'active_slot' => null,
                        'cooldown_ready_timeline_ms' => 6000,
                        'completed_timeline_ms' => 2000,
                    ]),
                    task6EnemyAction('smash', [
                        'id' => 41,
                        'encounter_id' => 91,
                        'state' => 'resolved',
                        'active_slot' => null,
                        'cooldown_ready_timeline_ms' => 5000,
                        'completed_timeline_ms' => 1600,
                    ]),
                ],
                5000,
            ],
        ];

        foreach ($cases as $label => [$encounter, $actions, $expectedNext]) {
            [, $pdo] = task6SynchronizeCursor($encounter, $actions);

            assertSameValue(count($actions), count($pdo->actions), $label . ' creates no action.');
            assertSameValue($expectedNext, $pdo->encounters[91]['next_enemy_decision_timeline_ms'], $label . ' stores the future decision position.');
            assertSameValue($encounter['enemy_actions_remaining'] ?? 2, $pdo->encounters[91]['enemy_actions_remaining'], $label . ' consumes no Action.');
            if ($expectedNext <= (int) $pdo->encounters[91]['timeline_elapsed_ms']) {
                throw new RuntimeException($label . ' did not schedule a strictly future decision.');
            }
        }
    },

    'Cave Brute stop decision is invocation-local and cannot loop at the cursor' => function (): void {
        $cases = [
            'enemy zero HP' => [
                ['enemy_current_hp' => 0],
                [],
                'active',
                1000,
            ],
            'Champion zero HP' => [
                [],
                ['current_hp' => 0],
                'active',
                1000,
            ],
            'inactive encounter' => [
                ['status' => 'victory_loot'],
                [],
                'victory_loot',
                0,
            ],
        ];

        foreach ($cases as $label => [$encounter, $character, $expectedStatus, $expectedTimeline]) {
            [, $pdo] = task6SynchronizeCursor(
                array_replace($encounter, [
                    'last_synchronized_at' => '2026-09-01 12:00:00.000000',
                    'next_enemy_decision_timeline_ms' => 0,
                ]),
                [],
                $character,
                '2026-09-01 12:00:01.000000',
            );

            assertSameValue([], $pdo->actions, $label . ' starts no action.');
            assertSameValue(2, $pdo->encounters[91]['enemy_actions_remaining'], $label . ' consumes no Action.');
            assertSameValue(0, $pdo->encounters[91]['next_enemy_decision_timeline_ms'], $label . ' writes no sentinel.');
            assertSameValue($expectedTimeline, $pdo->encounters[91]['timeline_elapsed_ms'], $label . ' completes the invocation safely.');
            assertSameValue($expectedStatus, $pdo->encounters[91]['status'], $label . ' invents no terminal transition.');
            $expectedActionLocks = $expectedStatus === 'active' ? 2 : 1;
            assertSameValue($expectedActionLocks, count(array_filter(
                $pdo->lockOrder,
                static fn (string $lock): bool => $lock === 'action',
            )), $label . ' performs one policy-history lock plus only the existing due-event lock when advancing.');
        }
    },

    'Task 5 leaves legacy null enemy AI marker untouched' => function (): void {
        [, $pdo] = task6SynchronizeCursor([
            'enemy_ai_initialized_timeline_ms' => null,
            'next_enemy_decision_timeline_ms' => 0,
        ]);

        assertSameValue([], $pdo->actions, 'Legacy encounter starts no historical AI action.');
        assertSameValue(null, $pdo->encounters[91]['enemy_ai_initialized_timeline_ms'], 'Task 5 does not initialize the legacy marker.');
        assertSameValue(0, count(array_filter(
            $pdo->lockOrder,
            static fn (string $lock): bool => $lock === 'action',
        )), 'Legacy marker bypasses the Task 5 cursor operation.');
    },

    'Task 6 synchronization returns both current authoritative aggregates' => function (): void {
        $pdo = new FakeCombatPdo();
        $repository = new CombatRepository($pdo);
        $definitions = CombatDefinitionRegistry::fromDefaultConfig();
        $clock = new Task4MutableCombatClock(new DateTimeImmutable(
            '2026-09-01 12:00:00.000000',
            new DateTimeZone('UTC'),
        ));
        $turnEngine = new CombatTurnEngine($definitions->turnDurationSeconds());
        $synchronizer = new CombatSynchronizer(
            $clock,
            $turnEngine,
            $definitions->maxDisconnectedCatchupSeconds(),
            null,
            $repository,
            $definitions,
            new CaveBrutePolicy($turnEngine),
            task6ActionResolver(
                $repository,
                new Task5MutableEquipmentProvider(),
                new Task6SequenceRandomSource([]),
            ),
        );
        $pdo->encounters[91] = task4Encounter([
            'timeline_elapsed_ms' => 0,
            'last_synchronized_at' => '2026-09-01 12:00:00.000000',
            'turn_started_timeline_ms' => 0,
            'next_enemy_decision_timeline_ms' => 5000,
            'enemy_ai_initialized_timeline_ms' => 0,
        ]);

        $repository->beginTransaction();
        $character = $repository->lockOwnedCharacter(7, 42);
        $encounter = $repository->lockActiveEncounter(42);
        $result = $synchronizer->synchronize($encounter, $character, 1, 2);
        $repository->rollBack();

        assertSameValue(91, $result['encounter']['id'], 'Current Encounter aggregate is returned.');
        assertSameValue(42, $result['character']['id'], 'Current locked Champion aggregate is returned.');
        assertSameValue(145, $result['character']['current_hp'], 'Synchronization does not heal or recreate Champion HP.');
    },

    'Legacy Cave Brute AI anchors once at 55603 without historical replay' => function (): void {
        $pdo = new FakeCombatPdo();
        $pdo->encounters[91] = task4Encounter([
            'timeline_elapsed_ms' => 55603,
            'last_synchronized_at' => '2026-09-01 12:00:00.000000',
            'turn_number' => 6,
            'turn_started_timeline_ms' => 50000,
            'next_enemy_decision_timeline_ms' => 0,
            'enemy_ai_initialized_timeline_ms' => null,
            'player_actions_remaining' => 1,
            'enemy_actions_remaining' => 2,
        ]);
        $clock = new Task4MutableCombatClock(new DateTimeImmutable(
            '2026-09-01 12:00:30.000000',
            new DateTimeZone('UTC'),
        ));
        $service = task6ChronologicalService($pdo, $clock);

        $service->state(7, 42);
        $firstActions = array_values($pdo->actions);

        assertSameValue(55603, $pdo->encounters[91]['enemy_ai_initialized_timeline_ms'], 'Legacy marker captures persisted starting timeline.');
        assertSameValue(55603, $firstActions[0]['started_timeline_ms'] ?? null, 'First legacy enemy decision occurs at its anchor.');
        foreach ($firstActions as $action) {
            if ((int) $action['started_timeline_ms'] < 55603) {
                throw new RuntimeException('Legacy AI replayed an action before its compatibility anchor.');
            }
        }
        assertSameValue(60603, $pdo->encounters[91]['timeline_elapsed_ms'], 'Only the capped five-second window is processed.');
        assertSameValue('2026-09-01 12:00:30.000000', $pdo->encounters[91]['last_synchronized_at'], 'Actual wall clock becomes the new anchor.');

        $actionCount = count($pdo->actions);
        $service->state(7, 42);
        assertSameValue(55603, $pdo->encounters[91]['enemy_ai_initialized_timeline_ms'], 'Immediate refresh preserves the original marker.');
        assertSameValue(60603, $pdo->encounters[91]['timeline_elapsed_ms'], 'Immediate refresh cannot replay discarded wall time.');
        assertSameValue($actionCount, count($pdo->actions), 'Immediate refresh does not recreate historical enemy actions.');
    },

    'Task 6 resolves actions before same-position Turn reset and enemy decision' => function (): void {
        $pdo = new FakeCombatPdo();
        $pdo->encounters[91] = task4Encounter([
            'timeline_elapsed_ms' => 9000,
            'last_synchronized_at' => '2026-09-01 12:00:00.000000',
            'turn_started_timeline_ms' => 0,
            'turn_number' => 1,
            'next_enemy_decision_timeline_ms' => 10000,
            'enemy_ai_initialized_timeline_ms' => 0,
            'player_actions_remaining' => 0,
            'enemy_actions_remaining' => 0,
            'enemy_current_hp' => 120,
        ]);
        $pdo->actions[50] = task6PendingAction([
            'id' => 50,
            'encounter_id' => 91,
            'started_timeline_ms' => 9000,
            'resolves_timeline_ms' => 10000,
            'cooldown_ready_timeline_ms' => 11500,
        ]);
        $clock = new Task4MutableCombatClock(new DateTimeImmutable(
            '2026-09-01 12:00:01.000000',
            new DateTimeZone('UTC'),
        ));
        $random = new Task6SequenceRandomSource([21]);

        task6ChronologicalService($pdo, $clock, null, $random)->state(7, 42);

        assertSameValue('resolved', $pdo->actions[50]['state'], 'Boundary player action resolves.');
        assertSameValue(10000, $pdo->actions[50]['completed_timeline_ms'], 'Resolution retains the exact boundary position.');
        assertSameValue(100, $pdo->encounters[91]['enemy_current_hp'], 'Boundary damage is applied exactly once.');
        assertSameValue(1, $random->integerCalls, 'Boundary hit performs exactly one Cave Brute Block roll.');
        assertSameValue(1, $pdo->resolutionObservations[0]['turn_number'], 'Action persistence observes the old Turn before reset.');
        assertSameValue(2, $pdo->encounters[91]['turn_number'], 'Turn resets after resolution.');
        $enemyStarts = array_values(array_filter(
            $pdo->actions,
            static fn (array $action): bool => $action['actor'] === 'enemy',
        ));
        assertSameValue(10000, $enemyStarts[0]['started_timeline_ms'] ?? null, 'Enemy decision uses the reset allowance at the same cursor.');
        assertSameValue(1, $pdo->encounters[91]['enemy_actions_remaining'], 'Enemy consumes one newly reset Action.');
    },

    'Task 6 processes interleaved actions decisions and Turn boundary chronologically' => function (): void {
        $pdo = new FakeCombatPdo();
        $pdo->encounters[91] = task4Encounter([
            'timeline_elapsed_ms' => 8000,
            'last_synchronized_at' => '2026-09-01 12:00:00.000000',
            'turn_started_timeline_ms' => 0,
            'turn_number' => 1,
            'next_enemy_decision_timeline_ms' => 8000,
            'enemy_ai_initialized_timeline_ms' => 0,
            'player_actions_remaining' => 0,
            'enemy_actions_remaining' => 1,
            'enemy_current_hp' => 120,
        ]);
        $pdo->actions[60] = task6PendingAction([
            'id' => 60,
            'encounter_id' => 91,
            'started_timeline_ms' => 8000,
            'resolves_timeline_ms' => 9000,
            'cooldown_ready_timeline_ms' => 10500,
        ]);
        $pdo->actions[61] = task6EnemyAction('fire_slam', [
            'id' => 61,
            'encounter_id' => 91,
            'started_timeline_ms' => 8000,
            'resolves_timeline_ms' => 10000,
            'cooldown_ready_timeline_ms' => 14000,
        ]);
        $clock = new Task4MutableCombatClock(new DateTimeImmutable(
            '2026-09-01 12:00:05.000000',
            new DateTimeZone('UTC'),
        ));
        $random = new Task6SequenceRandomSource([21]);

        $state = task6ChronologicalService($pdo, $clock, null, $random)->state(7, 42);

        $resolvedPositions = [];
        foreach ($pdo->actions as $action) {
            if (($action['state'] ?? null) === 'resolved') {
                $resolvedPositions[] = (int) $action['completed_timeline_ms'];
            }
        }
        sort($resolvedPositions);
        assertSameValue([9000, 10000, 11500], $resolvedPositions, 'Player, Fire Slam, then Smash resolve at their chronological positions.');
        assertSameValue(100, $pdo->encounters[91]['enemy_current_hp'], 'Player hit damages Cave Brute once.');
        assertSameValue(105, $pdo->characters[42]['current_hp'], 'Fire Slam and Smash use current defense and damage Champion once each.');
        assertSameValue(105, $state['champion']['current_hp'], 'CombatService projects the updated Champion aggregate rather than the stale locked row.');
        assertSameValue(2, $pdo->encounters[91]['turn_number'], 'The intervening Turn boundary is processed.');
        assertSameValue(13000, $pdo->encounters[91]['timeline_elapsed_ms'], 'All due work through the capped target is processed.');
        $started = array_column(array_values($pdo->actions), 'started_timeline_ms');
        assertSameValue([8000, 8000, 10000, 13000], $started, 'Enemy decisions occur only at their chronological cursors.');
    },

    'Connected polls and one capped reconnect produce equivalent logical combat state' => function (): void {
        $makePdo = static function (): FakeCombatPdo {
            $pdo = new FakeCombatPdo();
            $pdo->encounters[91] = task4Encounter([
                'timeline_elapsed_ms' => 0,
                'last_synchronized_at' => '2026-09-01 12:00:00.000000',
                'turn_started_timeline_ms' => 0,
                'next_enemy_decision_timeline_ms' => 0,
                'enemy_ai_initialized_timeline_ms' => 0,
                'player_actions_remaining' => 1,
                'enemy_actions_remaining' => 2,
                'version' => 1,
            ]);

            return $pdo;
        };
        $connectedPdo = $makePdo();
        $reconnectPdo = $makePdo();
        $connectedClock = new Task4MutableCombatClock(new DateTimeImmutable('2026-09-01 12:00:00.000000', new DateTimeZone('UTC')));
        $reconnectClock = new Task4MutableCombatClock(new DateTimeImmutable('2026-09-01 12:00:05.000000', new DateTimeZone('UTC')));
        $connected = task6ChronologicalService($connectedPdo, $connectedClock);
        $reconnect = task6ChronologicalService($reconnectPdo, $reconnectClock);

        $connected->state(7, 42);
        $nativeAction = array_values($connectedPdo->actions)[0] ?? null;
        assertSameValue('fire_slam', $nativeAction['definition_key'] ?? null, 'Native initialized encounter remains skill-first at zero gap.');
        assertSameValue(0, $nativeAction['started_timeline_ms'] ?? null, 'Native initialized encounter processes its due decision at timeline zero.');
        for ($second = 1; $second <= 5; $second++) {
            $connectedClock->set(new DateTimeImmutable(
                sprintf('2026-09-01 12:00:0%d.000000', $second),
                new DateTimeZone('UTC'),
            ));
            $connected->state(7, 42);
        }
        $reconnect->state(7, 42);

        foreach ([
            'timeline_elapsed_ms',
            'turn_number',
            'turn_started_timeline_ms',
            'next_enemy_decision_timeline_ms',
            'player_actions_remaining',
            'enemy_actions_remaining',
            'enemy_current_hp',
            'enemy_ai_initialized_timeline_ms',
        ] as $field) {
            assertSameValue($connectedPdo->encounters[91][$field], $reconnectPdo->encounters[91][$field], 'Equivalent Encounter field ' . $field . '.');
        }
        assertSameValue($connectedPdo->characters[42]['current_hp'], $reconnectPdo->characters[42]['current_hp'], 'Equivalent Champion HP.');
        assertSameValue(array_values($connectedPdo->actions), array_values($reconnectPdo->actions), 'Equivalent actions and results.');
    },

    'Task 6 caps long disconnect once and invocation-local stop still advances other work' => function (): void {
        $pdo = new FakeCombatPdo();
        $pdo->encounters[91] = task4Encounter([
            'timeline_elapsed_ms' => 0,
            'last_synchronized_at' => '2026-09-01 12:00:00.000000',
            'turn_started_timeline_ms' => 0,
            'next_enemy_decision_timeline_ms' => 0,
            'enemy_ai_initialized_timeline_ms' => 0,
            'enemy_current_hp' => 0,
            'player_actions_remaining' => 1,
            'enemy_actions_remaining' => 2,
        ]);
        $pdo->actions[70] = task6EnemyAction('smash', [
            'id' => 70,
            'encounter_id' => 91,
            'started_timeline_ms' => 0,
            'resolves_timeline_ms' => 1500,
            'cooldown_ready_timeline_ms' => 3000,
        ]);
        $pdo->actions[71] = task6EnemyAction('fire_slam', [
            'id' => 71,
            'encounter_id' => 91,
            'started_timeline_ms' => 5000,
            'resolves_timeline_ms' => 7000,
            'cooldown_ready_timeline_ms' => 11000,
        ]);
        $clock = new Task4MutableCombatClock(new DateTimeImmutable(
            '2026-09-01 12:00:30.000000',
            new DateTimeZone('UTC'),
        ));
        $service = task6ChronologicalService($pdo, $clock);

        $service->state(7, 42);

        assertSameValue(5000, $pdo->encounters[91]['timeline_elapsed_ms'], 'Thirty-second gap applies only five seconds.');
        assertSameValue('2026-09-01 12:00:30.000000', $pdo->encounters[91]['last_synchronized_at'], 'Long gap reanchors to actual server time.');
        assertSameValue('resolved', $pdo->actions[70]['state'], 'Already-started action resolves despite local AI stop.');
        assertSameValue('pending', $pdo->actions[71]['state'], 'Action beyond capped target is not processed.');
        assertSameValue(129, $pdo->characters[42]['current_hp'], 'Only the due Smash damages Champion.');
        assertSameValue(2, count($pdo->actions), 'Stop creates no new enemy action.');
        assertSameValue('active', $pdo->encounters[91]['status'], 'Task 6 invents no terminal lifecycle state.');

        $service->state(7, 42);
        assertSameValue(5000, $pdo->encounters[91]['timeline_elapsed_ms'], 'Immediate request replays no discarded gap.');
        assertSameValue(2, count($pdo->actions), 'Later stop reevaluation remains safe.');
    },

    'Player command rejects after synchronization reduces Cave Brute HP to zero' => function (): void {
        $pdo = new FakeCombatPdo();
        $pdo->encounters[91] = task4Encounter([
            'timeline_elapsed_ms' => 0,
            'last_synchronized_at' => '2026-09-01 12:00:00.000000',
            'turn_started_timeline_ms' => 0,
            'next_enemy_decision_timeline_ms' => 5000,
            'enemy_ai_initialized_timeline_ms' => 0,
            'enemy_current_hp' => 20,
            'player_actions_remaining' => 1,
            'enemy_actions_remaining' => 2,
        ]);
        $pdo->actions[50] = task6PendingAction([
            'id' => 50,
            'encounter_id' => 91,
            'started_timeline_ms' => 0,
            'resolves_timeline_ms' => 1000,
            'cooldown_ready_timeline_ms' => 1000,
        ]);
        $clock = new Task4MutableCombatClock(new DateTimeImmutable(
            '2026-09-01 12:00:01.000000',
            new DateTimeZone('UTC'),
        ));
        $service = task6ChronologicalService(
            $pdo,
            $clock,
            null,
            new Task6SequenceRandomSource([21]),
        );

        assertTask3CombatRejected(
            fn (): array => $service->startPlayerAction(
                7,
                42,
                'prototype_weapon_attack',
                '13131313-1313-4313-8313-131313131313',
            ),
            'A fresh player command against a zero-HP Cave Brute.',
        );

        assertSameValue(0, $pdo->encounters[91]['enemy_current_hp'], 'Authoritative synchronized damage remains committed.');
        assertSameValue('resolved', $pdo->actions[50]['state'], 'The due action resolves exactly once.');
        assertSameValue(1, count($pdo->actions), 'No new player action is inserted.');
        assertSameValue(1, $pdo->encounters[91]['player_actions_remaining'], 'Rejected command consumes no Action.');
        assertSameValue('active', $pdo->encounters[91]['status'], 'Task 7 creates no victory state.');
        assertSameValue('alive', $pdo->characters[42]['life_state'], 'Task 7 creates no death lifecycle state.');
    },

    'Zero-HP Champion cannot start player or Cave Brute actions' => function (): void {
        $pdo = new FakeCombatPdo();
        $pdo->characters[42]['current_hp'] = 0;
        $pdo->encounters[91] = task4Encounter([
            'timeline_elapsed_ms' => 0,
            'last_synchronized_at' => '2026-09-01 12:00:00.000000',
            'turn_started_timeline_ms' => 0,
            'next_enemy_decision_timeline_ms' => 0,
            'enemy_ai_initialized_timeline_ms' => 0,
            'player_actions_remaining' => 1,
            'enemy_actions_remaining' => 2,
        ]);
        $clock = new Task4MutableCombatClock(new DateTimeImmutable(
            '2026-09-01 12:00:00.000000',
            new DateTimeZone('UTC'),
        ));
        $service = task6ChronologicalService($pdo, $clock);

        assertTask3CombatRejected(
            fn (): array => $service->startPlayerAction(
                7,
                42,
                'prototype_weapon_attack',
                '14141414-1414-4414-8414-141414141414',
            ),
            'A zero-HP Champion player command.',
        );

        assertSameValue([], $pdo->actions, 'Neither actor starts an action.');
        assertSameValue(1, $pdo->encounters[91]['player_actions_remaining'], 'Player allowance is unchanged.');
        assertSameValue(2, $pdo->encounters[91]['enemy_actions_remaining'], 'Enemy allowance is unchanged.');
        assertSameValue('active', $pdo->encounters[91]['status'], 'Task 7 creates no defeated state.');
        assertSameValue('alive', $pdo->characters[42]['life_state'], 'Task 7 does not process permanent death.');
        assertSameValue(null, $pdo->characters[42]['died_at'] ?? null, 'Task 7 does not set died_at.');
    },

    'Repeated same-time synchronization never reapplies Cave Brute Block or player damage' => function (): void {
        foreach ([20 => 110, 21 => 100] as $roll => $expectedEnemyHp) {
            $pdo = new FakeCombatPdo();
            $pdo->encounters[91] = task4Encounter([
                'timeline_elapsed_ms' => 0,
                'last_synchronized_at' => '2026-09-01 12:00:00.000000',
                'turn_started_timeline_ms' => 0,
                'next_enemy_decision_timeline_ms' => 5000,
                'enemy_ai_initialized_timeline_ms' => 0,
                'enemy_current_hp' => 120,
                'player_actions_remaining' => 0,
                'enemy_actions_remaining' => 2,
            ]);
            $pdo->actions[50] = task6PendingAction([
                'id' => 50,
                'encounter_id' => 91,
                'started_timeline_ms' => 0,
                'resolves_timeline_ms' => 1000,
                'cooldown_ready_timeline_ms' => 2500,
            ]);
            $clock = new Task4MutableCombatClock(new DateTimeImmutable(
                '2026-09-01 12:00:01.000000',
                new DateTimeZone('UTC'),
            ));
            $random = new Task6SequenceRandomSource([$roll]);
            $service = task6ChronologicalService($pdo, $clock, null, $random);

            $service->state(7, 42);
            $afterFirst = $pdo->actions;
            $allowances = [
                $pdo->encounters[91]['player_actions_remaining'],
                $pdo->encounters[91]['enemy_actions_remaining'],
            ];
            $service->state(7, 42);

            assertSameValue(1, $random->integerCalls, 'Roll ' . $roll . ' performs one Block roll across repeated synchronization.');
            assertSameValue($expectedEnemyHp, $pdo->encounters[91]['enemy_current_hp'], 'Roll ' . $roll . ' decrements enemy HP once.');
            assertSameValue($afterFirst, $pdo->actions, 'Roll ' . $roll . ' neither recompletes nor creates an action.');
            assertSameValue($allowances, [
                $pdo->encounters[91]['player_actions_remaining'],
                $pdo->encounters[91]['enemy_actions_remaining'],
            ], 'Roll ' . $roll . ' consumes no second Action allowance.');
        }
    },

    'Repeated same-time synchronization never reapplies Cave Brute damage or historical player results' => function (): void {
        $pdo = new FakeCombatPdo();
        $pdo->encounters[91] = task4Encounter([
            'timeline_elapsed_ms' => 0,
            'last_synchronized_at' => '2026-09-01 12:00:00.000000',
            'turn_started_timeline_ms' => 0,
            'next_enemy_decision_timeline_ms' => 5000,
            'enemy_ai_initialized_timeline_ms' => 0,
            'player_actions_remaining' => 1,
            'enemy_actions_remaining' => 1,
            'enemy_current_hp' => 87,
        ]);
        $pdo->actions[50] = task6EnemyAction('smash', [
            'id' => 50,
            'encounter_id' => 91,
            'started_timeline_ms' => 0,
            'resolves_timeline_ms' => 1500,
            'cooldown_ready_timeline_ms' => 3000,
        ]);
        $pdo->actions[51] = task6PendingAction([
            'id' => 51,
            'encounter_id' => 91,
            'state' => 'resolved',
            'active_slot' => null,
            'started_timeline_ms' => 0,
            'resolves_timeline_ms' => 900,
            'cooldown_ready_timeline_ms' => 2500,
            'completed_timeline_ms' => 900,
            'resolved_damage' => null,
            'prevented_damage' => null,
        ]);
        $clock = new Task4MutableCombatClock(new DateTimeImmutable(
            '2026-09-01 12:00:01.500000',
            new DateTimeZone('UTC'),
        ));
        $random = new Task6SequenceRandomSource([]);
        $service = task6ChronologicalService($pdo, $clock, null, $random);

        $service->state(7, 42);
        $firstChampionHp = $pdo->characters[42]['current_hp'];
        $firstActions = $pdo->actions;
        $service->state(7, 42);

        assertSameValue(129, $firstChampionHp, 'Cave Brute Smash damages the Champion once.');
        assertSameValue($firstChampionHp, $pdo->characters[42]['current_hp'], 'Repeated synchronization causes no second Champion HP decrement.');
        assertSameValue($firstActions, $pdo->actions, 'Enemy completion and action schedule are not duplicated.');
        assertSameValue(0, $random->integerCalls, 'Historical resolved player action causes no Block roll.');
        assertSameValue(87, $pdo->encounters[91]['enemy_current_hp'], 'Historical resolved player action manufactures no damage.');
        assertSameValue(null, $pdo->actions[51]['resolved_damage'], 'Historical result damage remains null.');
        assertSameValue(null, $pdo->actions[51]['prevented_damage'], 'Historical prevented damage remains null.');
    },

    'Mixed five-second window preserves exact action cooldown and Turn chronology' => function (): void {
        $pdo = new FakeCombatPdo();
        $pdo->encounters[91] = task4Encounter([
            'timeline_elapsed_ms' => 8500,
            'last_synchronized_at' => '2026-09-01 12:00:00.000000',
            'turn_number' => 1,
            'turn_started_timeline_ms' => 0,
            'next_enemy_decision_timeline_ms' => 8500,
            'enemy_ai_initialized_timeline_ms' => 0,
            'player_actions_remaining' => 0,
            'enemy_actions_remaining' => 1,
            'enemy_current_hp' => 120,
        ]);
        $pdo->actions[80] = task6PendingAction([
            'id' => 80,
            'encounter_id' => 91,
            'started_timeline_ms' => 8500,
            'resolves_timeline_ms' => 9500,
            'cooldown_ready_timeline_ms' => 11000,
        ]);
        $pdo->actions[81] = task6EnemyAction('fire_slam', [
            'id' => 81,
            'encounter_id' => 91,
            'state' => 'resolved',
            'active_slot' => null,
            'started_timeline_ms' => 4500,
            'resolves_timeline_ms' => 6500,
            'cooldown_ready_timeline_ms' => 10500,
            'completed_timeline_ms' => 6500,
            'resolved_damage' => 22,
            'prevented_damage' => 2,
        ]);
        $clock = new Task4MutableCombatClock(new DateTimeImmutable(
            '2026-09-01 12:00:05.000000',
            new DateTimeZone('UTC'),
        ));
        $random = new Task6SequenceRandomSource([21]);

        task6ChronologicalService($pdo, $clock, null, $random)->state(7, 42);

        $enemyActions = array_values(array_filter(
            $pdo->actions,
            static fn (array $action): bool =>
                $action['actor'] === 'enemy' && (int) $action['started_timeline_ms'] >= 8500,
        ));
        usort($enemyActions, static fn (array $left, array $right): int =>
            (int) $left['started_timeline_ms'] <=> (int) $right['started_timeline_ms']);
        assertSameValue([
            ['smash', 8500, 10000, 'resolved'],
            ['fire_slam', 10500, 12500, 'resolved'],
            ['smash', 12500, 14000, 'pending'],
        ], array_map(static fn (array $action): array => [
            $action['definition_key'],
            (int) $action['started_timeline_ms'],
            (int) $action['resolves_timeline_ms'],
            $action['state'],
        ], $enemyActions), 'Smash fallback, Fire Slam readiness, and later Smash are chronological and sequential.');
        assertSameValue('resolved', $pdo->actions[80]['state'], 'Player action resolves while the enemy acts independently.');
        assertSameValue(10000, $enemyActions[0]['completed_timeline_ms'], 'Smash resolves exactly at the Turn boundary.');
        assertSameValue(1, $pdo->resolutionObservations[1]['turn_number'], 'Same-position Smash resolution occurs before Turn reset.');
        assertSameValue(2, $pdo->encounters[91]['turn_number'], 'Turn allowance resets exactly once.');
        assertSameValue(0, $pdo->encounters[91]['enemy_actions_remaining'], 'Unused old allowance is lost and the reset allowance is consumed without carry.');
        assertSameValue(13500, $pdo->encounters[91]['timeline_elapsed_ms'], 'Only the permitted five-second logical window is processed.');
        assertSameValue('pending', $enemyActions[2]['state'], 'The action resolving beyond the capped target remains pending.');
        assertSameValue(1, $random->integerCalls, 'The one player resolution performs one automatic Block roll.');
    },
];

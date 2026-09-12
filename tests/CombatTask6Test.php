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
        task6ChampionDamageResolver(),
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
];

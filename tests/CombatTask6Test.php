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
];

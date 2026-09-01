<?php
declare(strict_types=1);

$combatDefinitionPath = __DIR__ . '/../ascii-quest/lib/CombatDefinitionRegistry.php';
$combatClockPath = __DIR__ . '/../ascii-quest/lib/CombatClock.php';
$systemCombatClockPath = __DIR__ . '/../ascii-quest/lib/SystemCombatClock.php';
$combatRandomSourcePath = __DIR__ . '/../ascii-quest/lib/CombatRandomSource.php';
$systemCombatRandomSourcePath = __DIR__ . '/../ascii-quest/lib/SystemCombatRandomSource.php';
$combatEncounterTriggerPath = __DIR__ . '/../ascii-quest/lib/CombatEncounterTrigger.php';

foreach ([
    $combatDefinitionPath,
    $combatClockPath,
    $systemCombatClockPath,
    $combatRandomSourcePath,
    $systemCombatRandomSourcePath,
    $combatEncounterTriggerPath,
] as $combatClassPath) {
    if (is_file($combatClassPath)) {
        require_once $combatClassPath;
    }
}

function combatTestConfig(): array
{
    $path = __DIR__ . '/../ascii-quest/config/combat.php';
    if (!is_file($path)) {
        throw new RuntimeException('Combat configuration must exist.');
    }

    $config = require $path;
    if (!is_array($config)) {
        throw new RuntimeException('Combat configuration must return an array.');
    }

    return $config;
}

function combatTestRegistry(?array $config = null): object
{
    if (!class_exists('CombatDefinitionRegistry')) {
        throw new RuntimeException('CombatDefinitionRegistry must exist.');
    }

    return new CombatDefinitionRegistry($config ?? combatTestConfig());
}

function assertCombatDefinitionRejected(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (InvalidArgumentException | DomainException | RuntimeException) {
        return;
    }

    throw new RuntimeException($message . ' Expected rejection.');
}

function assertCanonicalCombatIdentifier(string $identifier, string $message): void
{
    if (preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/D', $identifier) !== 1) {
        throw new RuntimeException($message . ' Got ' . var_export($identifier, true));
    }
}

return [
    'Combat timing comes from one validated configuration snapshot' => function (): void {
        $config = combatTestConfig();
        $registry = combatTestRegistry($config);

        assertFloatValue(10.0, $registry->turnDurationSeconds(), 'Turn duration.');
        assertFloatValue(
            5.0,
            $registry->maxDisconnectedCatchupSeconds(),
            'Disconnected catch-up cap.',
        );

        $config['turn_duration_seconds'] = 99.0;
        $config['max_disconnected_catchup_seconds'] = 99.0;

        assertFloatValue(
            10.0,
            $registry->turnDurationSeconds(),
            'Registry must retain its validated turn-duration snapshot.',
        );
        assertFloatValue(
            5.0,
            $registry->maxDisconnectedCatchupSeconds(),
            'Registry must retain its validated catch-up snapshot.',
        );
    },

    'Turn duration supports deterministic injected configurations' => function (): void {
        foreach ([8.0, 12.0, 15.0] as $duration) {
            $config = combatTestConfig();
            $config['turn_duration_seconds'] = $duration;

            assertFloatValue(
                $duration,
                combatTestRegistry($config)->turnDurationSeconds(),
                "Injected {$duration}-second turn duration.",
            );
        }
    },

    'Cave Brute exposes its approved foundation actions and Block' => function (): void {
        $enemy = combatTestRegistry()->enemy('cave_brute');

        assertSameValue('cave_brute', $enemy['key'], 'Enemy identifier.');
        assertSameValue('Cave Brute', $enemy['name'], 'Enemy name.');
        assertSameValue(2, $enemy['action'], 'Enemy Action allowance.');
        assertSameValue('smash', $enemy['actions']['smash']['key'], 'Smash identifier.');
        assertSameValue('Smash', $enemy['actions']['smash']['name'], 'Smash name.');
        assertSameValue(
            'fire_slam',
            $enemy['actions']['fire_slam']['key'],
            'Fire Slam identifier.',
        );
        assertSameValue(
            'Fire Slam',
            $enemy['actions']['fire_slam']['name'],
            'Fire Slam name.',
        );
        assertSameValue('basic_block', $enemy['block']['key'], 'Enemy Block identifier.');
    },

    'Cave Brute rejects an extra third action' => function (): void {
        $config = combatTestConfig();
        $config['prototype_balance']['enemies']['cave_brute']['actions']['headbutt'] = [
            'key' => 'headbutt',
            'name' => 'Headbutt',
            'kind' => 'attack',
            'damage_type' => 'physical',
            'duration_seconds' => 1.0,
            'server_only' => [
                'cooldown_seconds' => 4.0,
                'prototype_damage' => 12,
            ],
        ];

        assertCombatDefinitionRejected(
            fn (): object => combatTestRegistry($config),
            'Extra Cave Brute action.',
        );
    },

    'Cave Brute rejects a missing approved action' => function (): void {
        $config = combatTestConfig();
        unset(
            $config['prototype_balance']['enemies']['cave_brute']
                ['actions']['fire_slam'],
        );

        assertCombatDefinitionRejected(
            fn (): object => combatTestRegistry($config),
            'Missing Cave Brute Fire Slam.',
        );
    },

    'Cave Brute rejects Smash misclassified as a skill' => function (): void {
        $config = combatTestConfig();
        $config['prototype_balance']['enemies']['cave_brute']
            ['actions']['smash']['kind'] = 'skill';

        assertCombatDefinitionRejected(
            fn (): object => combatTestRegistry($config),
            'Misclassified Cave Brute Smash.',
        );
    },

    'Cave Brute rejects Fire Slam misclassified as an attack' => function (): void {
        $config = combatTestConfig();
        $config['prototype_balance']['enemies']['cave_brute']
            ['actions']['fire_slam']['kind'] = 'attack';

        assertCombatDefinitionRejected(
            fn (): object => combatTestRegistry($config),
            'Misclassified Cave Brute Fire Slam.',
        );
    },

    'Prototype encounter has one stable stationary orthogonal definition' => function (): void {
        $encounters = combatTestRegistry()->encounters();

        assertSameValue(1, count($encounters), 'Prototype encounter count.');
        $encounter = $encounters[0];

        assertSameValue(
            'deep_cave_01_cave_brute',
            $encounter['id'],
            'Stable encounter identifier.',
        );
        assertSameValue('deep_cave_01', $encounter['map_key'], 'Encounter map key.');
        assertSameValue('deep_cave.json', $encounter['map_file'], 'Encounter map file.');
        assertSameValue('cave_brute', $encounter['enemy_key'], 'Encounter enemy key.');
        assertSameValue(true, is_int($encounter['x']), 'Encounter X must be an integer.');
        assertSameValue(true, is_int($encounter['y']), 'Encounter Y must be an integer.');
        assertSameValue('B', $encounter['glyph'], 'Encounter glyph.');
        assertSameValue(true, $encounter['stationary'], 'Encounter must be stationary.');
        assertSameValue(1, $encounter['fighting_range'], 'Encounter fighting range.');
        assertSameValue('orthogonal', $encounter['range_shape'], 'Encounter range shape.');
    },

    'Combat identifiers and action timing are canonical and positive' => function (): void {
        $registry = combatTestRegistry();
        $enemy = $registry->enemy('cave_brute');

        assertCanonicalCombatIdentifier($enemy['key'], 'Enemy identifier must be canonical.');
        foreach ($enemy['actions'] as $actionKey => $action) {
            assertCanonicalCombatIdentifier($actionKey, 'Enemy action key must be canonical.');
            assertSameValue($actionKey, $action['key'], 'Enemy action key must match its index.');
            assertSameValue(
                true,
                $action['duration_seconds'] > 0,
                "Enemy {$actionKey} duration must be positive.",
            );
            assertSameValue(
                true,
                $action['server_only']['cooldown_seconds'] > 0,
                "Enemy {$actionKey} cooldown must be positive.",
            );
        }

        $playerAction = $registry->playerAction('prototype_weapon_attack');
        assertSameValue(
            'prototype_weapon_attack',
            $playerAction['key'],
            'Player action identifier.',
        );
        assertSameValue(true, $playerAction['duration_seconds'] > 0, 'Player duration.');
        assertSameValue(true, $playerAction['cooldown_seconds'] > 0, 'Player cooldown.');

        $potion = $registry->potion('prototype_health_potion');
        assertSameValue('prototype_health_potion', $potion['key'], 'Potion identifier.');
        assertSameValue(true, $potion['charges'] > 0, 'Potion charges.');
    },

    'Enemy cooldown values remain explicitly server-only' => function (): void {
        $enemy = combatTestRegistry()->enemy('cave_brute');

        foreach ($enemy['actions'] as $actionKey => $action) {
            assertSameValue(
                false,
                array_key_exists('cooldown_seconds', $action),
                "Enemy {$actionKey} must not expose a client-safe cooldown field.",
            );
            assertSameValue(
                true,
                isset($action['server_only']['cooldown_seconds']),
                "Enemy {$actionKey} must retain its server-only cooldown.",
            );
        }
    },

    'Combat definitions reject invalid timing and non-canonical identifiers' => function (): void {
        $invalidDuration = combatTestConfig();
        $invalidDuration['turn_duration_seconds'] = 0.0;
        assertCombatDefinitionRejected(
            fn (): object => combatTestRegistry($invalidDuration),
            'Zero turn duration.',
        );

        $invalidIdentifier = combatTestConfig();
        $invalidIdentifier['prototype_balance']['enemies']['cave_brute']['key'] = 'Cave Brute';
        assertCombatDefinitionRejected(
            fn (): object => combatTestRegistry($invalidIdentifier),
            'Non-canonical enemy identifier.',
        );

        $invalidEffect = combatTestConfig();
        $invalidEffect['prototype_balance']['player_actions']
            ['prototype_flame_strike']['effect']['duration_seconds'] = 0.0;
        assertCombatDefinitionRejected(
            fn (): object => combatTestRegistry($invalidEffect),
            'Zero prototype effect duration.',
        );
    },

    'Encounter trigger accepts only orthogonal Manhattan range' => function (): void {
        if (!class_exists('CombatEncounterTrigger')) {
            throw new RuntimeException('CombatEncounterTrigger must exist.');
        }

        foreach ([[10, 9], [10, 11], [9, 10], [11, 10]] as [$x, $y]) {
            assertSameValue(
                true,
                CombatEncounterTrigger::isInOrthogonalRange($x, $y, 10, 10),
                "Orthogonal position {$x},{$y}.",
            );
        }

        foreach ([[9, 9], [11, 11], [10, 10], [10, 12], [7, 10]] as [$x, $y]) {
            assertSameValue(
                false,
                CombatEncounterTrigger::isInOrthogonalRange($x, $y, 10, 10),
                "Non-range position {$x},{$y}.",
            );
        }
    },

    'Encounter trigger recognizes requested direct contact' => function (): void {
        if (!class_exists('CombatEncounterTrigger')) {
            throw new RuntimeException('CombatEncounterTrigger must exist.');
        }

        assertSameValue(
            true,
            CombatEncounterTrigger::isDirectContact(10, 10, 10, 10),
            'Requested enemy coordinate is direct contact.',
        );
        assertSameValue(
            false,
            CombatEncounterTrigger::isDirectContact(10, 9, 10, 10),
            'Adjacent coordinate is not direct contact.',
        );
    },

    'System combat clock and random source satisfy server boundaries' => function (): void {
        if (!class_exists('SystemCombatClock')) {
            throw new RuntimeException('SystemCombatClock must exist.');
        }
        if (!class_exists('SystemCombatRandomSource')) {
            throw new RuntimeException('SystemCombatRandomSource must exist.');
        }

        $now = (new SystemCombatClock())->now();
        assertSameValue('UTC', $now->getTimezone()->getName(), 'Combat wall clock timezone.');

        $random = new SystemCombatRandomSource();
        $token = $random->token(8);
        assertSameValue(16, strlen($token), 'Eight random bytes must be hex encoded.');
        assertSameValue(1, preg_match('/\A[0-9a-f]{16}\z/D', $token), 'Random token format.');
        $integer = $random->integer(4, 7);
        assertSameValue(true, $integer >= 4 && $integer <= 7, 'Random integer bounds.');
    },
];

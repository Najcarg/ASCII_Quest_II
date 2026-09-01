<?php
declare(strict_types=1);

$combatTurnEnginePath = __DIR__ . '/../ascii-quest/lib/CombatTurnEngine.php';
if (is_file($combatTurnEnginePath)) {
    require_once $combatTurnEnginePath;
}

function combatTestTurnEngine(float $durationSeconds = 10.0): object
{
    if (!class_exists('CombatTurnEngine')) {
        throw new RuntimeException('CombatTurnEngine must exist.');
    }

    return new CombatTurnEngine($durationSeconds);
}

function assertCombatTurnRejected(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (InvalidArgumentException | DomainException | RuntimeException) {
        return;
    }

    throw new RuntimeException($message . ' Expected rejection.');
}

return [
    'Turn one starts at the logical timeline with configured duration and allowances' => function (): void {
        foreach ([8.0, 12.0, 15.0] as $durationSeconds) {
            $state = combatTestTurnEngine($durationSeconds)->synchronizeTurn(
                [],
                1250,
                1,
                2,
            );

            assertSameValue(1, $state['turn_number'], 'Initial turn number.');
            assertSameValue(1250, $state['turn_started_timeline_ms'], 'Initial turn start.');
            assertSameValue(
                1250 + (int) ($durationSeconds * 1000),
                $state['turn_ends_timeline_ms'],
                'Configured turn end.',
            );
            assertSameValue(1, $state['player_actions_remaining'], 'Player allowance.');
            assertSameValue(2, $state['enemy_actions_remaining'], 'Enemy allowance.');
        }
    },

    'Crossed turn boundaries reset allowances without adding carry-over' => function (): void {
        $engine = combatTestTurnEngine();
        $state = $engine->synchronizeTurn([], 1000, 1, 2);
        $state['player_actions_remaining'] = 0;
        $state['enemy_actions_remaining'] = 1;

        $state = $engine->synchronizeTurn($state, 21500, 1, 2);

        assertSameValue(3, $state['turn_number'], 'Two crossed boundaries reach turn three.');
        assertSameValue(21000, $state['turn_started_timeline_ms'], 'Latest turn start.');
        assertSameValue(31000, $state['turn_ends_timeline_ms'], 'Latest turn end.');
        assertSameValue(1, $state['player_actions_remaining'], 'Player allowance resets.');
        assertSameValue(2, $state['enemy_actions_remaining'], 'Enemy allowance resets.');
    },

    'Unused Actions are lost at the next boundary' => function (): void {
        $engine = combatTestTurnEngine();
        $state = $engine->synchronizeTurn([], 0, 3, 2);
        $state['player_actions_remaining'] = 2;
        $state['enemy_actions_remaining'] = 1;

        $state = $engine->synchronizeTurn($state, 10000, 3, 2);

        assertSameValue(2, $state['turn_number'], 'Boundary starts turn two.');
        assertSameValue(3, $state['player_actions_remaining'], 'Player unused Actions do not carry.');
        assertSameValue(2, $state['enemy_actions_remaining'], 'Enemy unused Actions do not carry.');
    },

    'Action consumption cannot make an allowance negative' => function (): void {
        $engine = combatTestTurnEngine();
        $state = $engine->synchronizeTurn([], 0, 1, 2);
        $state = $engine->consumeAction($state, 'player', 0, 1000);

        assertSameValue(0, $state['player_actions_remaining'], 'One player Action consumed.');
        assertCombatTurnRejected(
            fn (): array => $engine->consumeAction($state, 'player', 1000, 1000),
            'Exhausted player allowance.',
        );
        assertSameValue(0, $state['player_actions_remaining'], 'Rejected consumption stays at zero.');
    },

    'Action start rejects insufficient time remaining in the turn' => function (): void {
        $engine = combatTestTurnEngine();
        $state = $engine->synchronizeTurn([], 0, 2, 2);

        assertSameValue(
            true,
            $engine->canStartAction($state, 'player', 8500, 1500),
            'An action may resolve exactly at the turn boundary.',
        );
        assertSameValue(
            false,
            $engine->canStartAction($state, 'player', 8501, 1500),
            'An action cannot resolve after the turn boundary.',
        );
        assertCombatTurnRejected(
            fn (): array => $engine->consumeAction($state, 'player', 8501, 1500),
            'Insufficient turn time.',
        );
    },

    'A busy actor rejects another sequential action' => function (): void {
        $engine = combatTestTurnEngine();
        $state = $engine->synchronizeTurn([], 0, 2, 2);
        $state = $engine->consumeAction($state, 'player', 1000, 2000);

        assertSameValue(
            false,
            $engine->canStartAction($state, 'player', 1500, 1000),
            'Player remains busy before its own action resolves.',
        );
        assertSameValue(
            true,
            $engine->canStartAction($state, 'player', 3000, 1000),
            'Player is free at its own resolve position.',
        );
    },

    'Player and enemy can each execute one action concurrently' => function (): void {
        $engine = combatTestTurnEngine();
        $state = $engine->synchronizeTurn([], 0, 2, 2);
        $state = $engine->consumeAction($state, 'player', 1000, 2000);

        assertSameValue(
            true,
            $engine->canStartAction($state, 'enemy', 1000, 1500),
            'Player activity must not mark the enemy busy.',
        );

        $state = $engine->consumeAction($state, 'enemy', 1000, 1500);

        assertSameValue(1, $state['player_actions_remaining'], 'Player Action consumed.');
        assertSameValue(1, $state['enemy_actions_remaining'], 'Enemy Action consumed.');
        assertSameValue(3000, $state['player_busy_until_timeline_ms'], 'Player resolve position.');
        assertSameValue(2500, $state['enemy_busy_until_timeline_ms'], 'Enemy resolve position.');
    },

    'Turn engine rejects invalid actors durations and logical timeline regression' => function (): void {
        $engine = combatTestTurnEngine();
        $state = $engine->synchronizeTurn([], 1000, 1, 2);

        assertCombatTurnRejected(
            fn (): bool => $engine->canStartAction($state, 'browser', 1000, 1000),
            'Unknown actor.',
        );
        assertCombatTurnRejected(
            fn (): bool => $engine->canStartAction($state, 'player', 1000, 0),
            'Zero duration.',
        );
        assertCombatTurnRejected(
            fn (): array => $engine->synchronizeTurn($state, 999, 1, 2),
            'Logical timeline regression.',
        );
    },
];

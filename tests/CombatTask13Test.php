<?php
declare(strict_types=1);

function task13DeathFixture(): array
{
    $pdo = new FakeCombatPdo();
    $pdo->characters[42]['current_hp'] = 18;
    $pdo->characters[42]['current_mana'] = 80;
    $pdo->encounters[91] = task4Encounter([
        'timeline_elapsed_ms' => 0,
        'last_synchronized_at' => '2026-09-01 12:00:00.000000',
        'turn_started_timeline_ms' => 0,
        'next_enemy_decision_timeline_ms' => 5000,
        'enemy_ai_initialized_timeline_ms' => 0,
        'player_actions_remaining' => 1,
        'enemy_actions_remaining' => 1,
        'version' => 4,
    ]);
    $pdo->actions[60] = task6EnemyAction('smash', [
        'id' => 60,
        'encounter_id' => 91,
        'started_timeline_ms' => 0,
        'resolves_timeline_ms' => 1500,
        'cooldown_ready_timeline_ms' => 3000,
    ]);
    $pdo->actions[61] = task6PendingAction([
        'id' => 61,
        'encounter_id' => 91,
        'started_timeline_ms' => 1000,
        'resolves_timeline_ms' => 1500,
        'cooldown_ready_timeline_ms' => 4000,
    ]);
    $clock = new Task4MutableCombatClock(new DateTimeImmutable(
        '2026-09-01 12:00:03.000000',
        new DateTimeZone('UTC'),
    ));
    $equipment = new Task5MutableEquipmentProvider();
    $equipment->defense = [
        'toughness' => 0,
        'dodging' => 0.0,
        'resistances' => [
            'fire' => 0.0,
            'lightning' => 0.0,
            'poison' => 0.0,
            'cold' => 0.0,
        ],
    ];

    return [
        $pdo,
        $clock,
        task6ChronologicalService(
            $pdo,
            $clock,
            $equipment,
            new Task6SequenceRandomSource([100]),
        ),
    ];
}

return [
    'Task 13 lethal enemy damage atomically persists permanent death' => function (): void {
        [$pdo, , $service] = task13DeathFixture();

        $state = $service->state(7, 42);

        assertSameValue(0, $pdo->characters[42]['current_hp'] ?? null, 'Champion HP is exactly zero.');
        assertSameValue('dead', $pdo->characters[42]['life_state'] ?? null, 'Champion life state is permanent.');
        assertSameValue('defeated', $pdo->encounters[91]['status'] ?? null, 'Encounter is terminal defeat.');
        assertSameValue(null, $pdo->encounters[91]['active_slot'] ?? null, 'Defeat releases the active slot.');
        assertSameValue('cave_brute', $pdo->encounters[91]['killer_enemy_key'] ?? null, 'Killer comes from the encounter.');
        assertSameValue(
            $pdo->characters[42]['died_at'] ?? null,
            $pdo->encounters[91]['death_processed_at'] ?? null,
            'One authoritative death timestamp is persisted.',
        );
        assertSameValue('defeated', $state['status'] ?? null, 'Defeat is projected safely.');
        assertSameValue(0, $state['champion']['current_hp'] ?? null, 'Projected HP is zero.');
        assertSameValue(1500, $state['timeline']['elapsed_ms'] ?? null, 'Chronology stops at the lethal hit.');
        assertSameValue('resolved', $pdo->actions[60]['state'] ?? null, 'Killing action resolves once.');
        assertSameValue('cancelled', $pdo->actions[61]['state'] ?? null, 'Later player offense is cancelled.');
        assertSameValue(0, $pdo->characters[42]['gold'] ?? null, 'Defeat awards no victory Gold.');
        assertSameValue(0, $pdo->characters[42]['experience'] ?? null, 'Defeat awards no victory EXP.');
    },

    'Task 13 refresh reads terminal defeat without processing death twice' => function (): void {
        [$pdo, $clock, $service] = task13DeathFixture();
        $first = $service->state(7, 42);
        $diedAt = $pdo->characters[42]['died_at'];
        $killer = $pdo->encounters[91]['killer_enemy_key'];
        $deathProcessedAt = $pdo->encounters[91]['death_processed_at'];
        $events = $pdo->events;
        $actions = $pdo->actions;

        $clock->set(new DateTimeImmutable('2026-09-01 12:01:00.000000', new DateTimeZone('UTC')));
        $freshService = task6ChronologicalService(
            $pdo,
            $clock,
            null,
            new Task6SequenceRandomSource([]),
        );
        $second = $freshService->state(7, 42);

        assertSameValue($first, $second, 'Refresh returns the identical terminal projection.');
        assertSameValue($diedAt, $pdo->characters[42]['died_at'], 'died_at is immutable.');
        assertSameValue($killer, $pdo->encounters[91]['killer_enemy_key'], 'Killer identity is immutable.');
        assertSameValue($deathProcessedAt, $pdo->encounters[91]['death_processed_at'], 'Death guard is immutable.');
        assertSameValue($events, $pdo->events, 'Refresh adds no duplicate death event.');
        assertSameValue($actions, $pdo->actions, 'Refresh starts or resolves no further action.');
        assertSameValue(0, $pdo->characters[42]['current_hp'], 'Refresh never heals the Champion.');
        assertSameValue(80, $pdo->characters[42]['current_mana'], 'Refresh never restores Mana.');
    },

    'Task 13 durable death result is a stable internal Slayer candidate only' => function (): void {
        [$pdo, , $service] = task13DeathFixture();
        $service->state(7, 42);
        $repository = new CombatRepository($pdo);

        $first = $repository->findDeathResultForOwnedCharacter(7, 42);
        $second = $repository->findDeathResultForOwnedCharacter(7, 42);

        assertSameValue(true, $first instanceof CombatDeathResult, 'Typed internal death result.');
        assertSameValue($first->toArray(), $second?->toArray(), 'Repeated reads use the same durable values.');
        assertSameValue([
            'champion_id' => 42,
            'encounter_id' => 91,
            'killer_enemy_key' => 'cave_brute',
            'died_at' => $pdo->characters[42]['died_at'],
        ], $first->toArray(), 'Candidate contains only authoritative stable identity and death context.');
        foreach (['qualified', 'title', 'bounty', 'counter', 'reward'] as $forbidden) {
            assertSameValue(false, array_key_exists($forbidden, $first->toArray()), $forbidden . ' is not implemented.');
        }
    },

    'Task 13 terminal commands reject without mutating defeated history' => function (): void {
        [$pdo, , $service] = task13DeathFixture();
        $service->state(7, 42);
        $before = [
            'character' => $pdo->characters[42],
            'encounter' => $pdo->encounters[91],
            'actions' => $pdo->actions,
            'events' => $pdo->events,
        ];

        foreach ([
            fn (): array => $service->startPlayerAction(
                7,
                42,
                'prototype_weapon_attack',
                '13131313-1313-4313-8313-131313131301',
            ),
            fn (): array => $service->usePotion(
                7,
                42,
                '13131313-1313-4313-8313-131313131302',
            ),
            fn (): array => $service->attemptBlock(
                7,
                42,
                60,
                str_repeat('a', 64),
                '13131313-1313-4313-8313-131313131303',
            ),
        ] as $command) {
            task12AssertRejected($command, 'Terminal combat command.');
        }

        assertSameValue($before['character'], $pdo->characters[42], 'Commands cannot mutate dead Champion state.');
        assertSameValue($before['encounter'], $pdo->encounters[91], 'Commands cannot advance terminal encounter state.');
        assertSameValue($before['actions'], $pdo->actions, 'Commands cannot create or rewrite actions.');
        assertSameValue($before['events'], $pdo->events, 'Commands cannot append terminal events.');
    },

    'Task 13 death event failure rolls back and retry processes once' => function (): void {
        [$pdo, , $service] = task13DeathFixture();
        $beforeCharacter = $pdo->characters[42];
        $beforeEncounter = $pdo->encounters[91];
        $beforeActions = $pdo->actions;
        $pdo->failEventInsert = true;

        try {
            $service->state(7, 42);
            throw new RuntimeException('Injected death-event failure was not raised.');
        } catch (RuntimeException $exception) {
            assertSameValue('Injected combat event insert failure.', $exception->getMessage(), 'Expected event failure.');
        }
        assertSameValue($beforeCharacter, $pdo->characters[42], 'Failure rolls back Champion death.');
        assertSameValue($beforeEncounter, $pdo->encounters[91], 'Failure rolls back encounter defeat.');
        assertSameValue($beforeActions, $pdo->actions, 'Failure rolls back action resolution.');
        assertSameValue([], $pdo->events, 'Failure leaves no partial death event.');

        $pdo->failEventInsert = false;
        $service->state(7, 42);
        assertSameValue('dead', $pdo->characters[42]['life_state'], 'Retry processes death.');
        assertSameValue(1, count(array_filter(
            $pdo->events,
            static fn (array $event): bool => ($event['event_type'] ?? null) === 'champion_defeated',
        )), 'Retry appends one death event.');
    },

    'Task 13 permanent-history deletion guard preserves Champion and killer context' => function (): void {
        [$pdo, , $service] = task13DeathFixture();
        $service->state(7, 42);
        $character = $pdo->characters[42];
        $encounter = $pdo->encounters[91];
        $guard = new CombatAccessGuard(new CombatRepository($pdo));

        task12AssertRejected(
            fn (): array => $guard->beginAtomic(CombatAccessGuard::DELETE_CHARACTER, 7, 42),
            'Dead Champion deletion.',
        );

        assertSameValue($character, $pdo->characters[42], 'Dead Champion row remains.');
        assertSameValue($encounter, $pdo->encounters[91], 'Defeated killer history remains.');
        assertSameValue(false, $pdo->inTransaction(), 'Rejected deletion closes its transaction.');
    },

    'Task 13 defeated projection exposes no death guard or Slayer internals' => function (): void {
        [$pdo, , $service] = task13DeathFixture();
        $state = $service->state(7, 42);
        $keys = task7RecursiveStateKeys($state);

        foreach (['death_processed_at', 'killer_enemy_key', 'died_at', 'slayer', 'bounty', 'title'] as $forbidden) {
            assertSameValue(false, in_array($forbidden, $keys, true), $forbidden . ' remains server-only.');
        }
        assertSameValue(false, $state['player_attack']['available'] ?? true, 'Offense is unavailable.');
        assertSameValue(null, $state['reaction_prompt'] ?? null, 'Block prompt is unavailable.');
    },
];

<?php
declare(strict_types=1);

function task34aVictoryFixture(int $xp = 80, int $reward = 40): array
{
    $pdo = new FakeCombatPdo();
    $pdo->characters[42]['level'] = CharacterProgression::levelForTotalXp($xp);
    $pdo->characters[42]['experience'] = $xp;
    $pdo->characters[42]['stat_points'] = 7;
    $pdo->encounters[91] = task4Encounter([
        'timeline_elapsed_ms' => 0,
        'player_actions_remaining' => 2,
        'next_enemy_decision_timeline_ms' => 10000,
        'enemy_ai_initialized_timeline_ms' => 0,
        'enemy_current_hp' => 1,
        'reward_experience' => $reward,
    ]);
    $clock = new Task4MutableCombatClock(new DateTimeImmutable('2026-09-01 12:00:00.000000', new DateTimeZone('UTC')));
    $service = task6ChronologicalService($pdo, $clock, null, new Task6SequenceRandomSource([1, 21, 100]));
    return [$pdo, $clock, $service];
}

return [
    'Task34A victory persists XP level receipt and gold once across service replay' => function (): void {
        foreach ([[80, 40, 2], [0, 1700, 5], [240090, 40, 50], [999999, 40, 100]] as [$xp, $reward, $level]) {
            [$pdo, $clock, $service] = task34aVictoryFixture($xp, $reward);
            $token = '34000000-0000-4000-8000-000000000001';
            $before = $pdo->characters[42];
            $service->startPlayerAction(7, 42, 'prototype_weapon_attack', $token);
            $clock->set(new DateTimeImmutable('2026-09-01 12:00:02.000000', new DateTimeZone('UTC')));
            $state = $service->state(7, 42);
            assertSameValue('victory_loot', $state['status'], 'Won.');
            assertSameValue($xp + $reward, $pdo->characters[42]['experience'], 'XP persisted once.');
            assertSameValue($level, $pdo->characters[42]['level'], 'Level persisted.');
            assertSameValue(25, $pdo->characters[42]['gold'], 'Gold unchanged.');
            foreach (['stat_points', 'strength', 'dexterity', 'vitality', 'energy', 'fate', 'current_hp', 'current_mana'] as $key) {
                assertSameValue($before[$key], $pdo->characters[42][$key], "No automatic {$key} award.");
            }
            $receipt = $state['loot_phase']['rewards']['progression'];
            assertSameValue($xp, $receipt['total_xp_before'], 'Snapshot before.');
            assertSameValue($xp + $reward, $receipt['total_xp_after'], 'Snapshot after.');
            assertSameValue($level, $state['champion']['progression']['level'], 'Authoritative projection.');
            $saved = [$pdo->characters, $pdo->events, $pdo->actions];
            $replay = task6ChronologicalService($pdo, $clock, null, new Task6SequenceRandomSource([]))->startPlayerAction(7, 42, 'prototype_weapon_attack', $token);
            assertSameValue($receipt, $replay['loot_phase']['rewards']['progression'], 'Stable replay receipt.');
            assertSameValue($state['champion']['progression'], $replay['champion']['progression'], 'Stable final progression.');
            assertSameValue($saved, [$pdo->characters, $pdo->events, $pdo->actions], 'No repeat XP/level/gold/events.');
            $levelEvents = array_values(array_filter($pdo->events, static fn (array $e): bool => $e['event_type'] === 'level_up'));
            assertSameValue($receipt['old_level'] < $level ? 1 : 0, count($levelEvents), 'One aggregate level-up event.');
            if ($levelEvents !== []) {
                assertSameValue('Level Up! ' . $receipt['old_level'] . ' → ' . $level . '.', $levelEvents[0]['message'], 'Multi-level feedback.');
            }
        }
    },
    'Task34A failed level-up event rolls back rewards level receipt and retries once' => function (): void {
        [$pdo, $clock, $service] = task34aVictoryFixture();
        $service->startPlayerAction(7, 42, 'prototype_weapon_attack', '34000000-0000-4000-8000-000000000002');
        $clock->set(new DateTimeImmutable('2026-09-01 12:00:02.000000', new DateTimeZone('UTC')));
        $before = [$pdo->characters, $pdo->encounters, $pdo->actions, $pdo->events];
        $pdo->failEventInsert = true;
        $pdo->failEventType = 'level_up';
        task34aAssertRejected(fn () => $service->state(7, 42), RuntimeException::class);
        assertSameValue($before, [$pdo->characters, $pdo->encounters, $pdo->actions, $pdo->events], 'Atomic rollback.');
        $pdo->failEventInsert = false;
        $pdo->failEventType = null;
        $state = task6ChronologicalService($pdo, $clock, null, new Task6SequenceRandomSource([1, 21, 100]))->state(7, 42);
        assertSameValue(120, $state['champion']['progression']['total_xp'], 'Retry XP once.');
        assertSameValue(2, $pdo->characters[42]['level'], 'Retry level once.');
    },
    'Task34A wrong owner and defeat cannot change progression' => function (): void {
        [$pdo, , $service] = task34aVictoryFixture();
        $before = $pdo->characters;
        task34aAssertRejected(fn () => $service->state(8, 42), OutOfBoundsException::class);
        assertSameValue($before, $pdo->characters, 'No foreign reward.');
        [$deadPdo, , $deadService] = task13DeathFixture();
        $deadPdo->characters[42]['experience'] = 1700;
        $deadPdo->characters[42]['level'] = 1;
        $deadService->state(7, 42);
        assertSameValue(1700, $deadPdo->characters[42]['experience'], 'Death retains XP.');
        assertSameValue(1, $deadPdo->characters[42]['level'], 'Death retains stored historical level.');
        $state = $deadService->state(7, 42);
        assertSameValue(1, $state['champion']['progression']['level'], 'Historical projection.');
        assertSameValue('historical', $state['champion']['progression']['status'], 'Historical label.');
    },
    'Task34A reward storage failure rolls back and two service instances cannot duplicate progression' => function (): void {
        [$pdo, $clock, $service] = task34aVictoryFixture();
        $service->startPlayerAction(7, 42, 'prototype_weapon_attack', '34000000-0000-4000-8000-000000000005');
        $clock->set(new DateTimeImmutable('2026-09-01 12:00:02.000000', new DateTimeZone('UTC')));
        $before = [$pdo->characters, $pdo->encounters, $pdo->actions, $pdo->events];
        $pdo->failRewardUpdate = true;
        task34aAssertRejected(fn () => $service->state(7, 42), PDOException::class);
        assertSameValue($before, [$pdo->characters, $pdo->encounters, $pdo->actions, $pdo->events], 'DB storage errors never lose XP or commit a partial marker.');
        $pdo->failRewardUpdate = false;
        $one = task6ChronologicalService($pdo, $clock, null, new Task6SequenceRandomSource([1, 21, 100]))->state(7, 42);
        $two = task6ChronologicalService($pdo, $clock, null, new Task6SequenceRandomSource([]))->state(7, 42);
        assertSameValue($one['champion']['progression'], $two['champion']['progression'], 'Two services one final progression.');
        assertSameValue($one['loot_phase']['rewards'], $two['loot_phase']['rewards'], 'Stable durable reward receipt.');
        assertSameValue(120, $pdo->characters[42]['experience'], 'Exactly one XP reward.');
        assertSameValue(2, $pdo->characters[42]['level'], 'Exactly one persisted level update.');
    },
    'Task34A malformed clients cannot set XP level or requirements' => function (): void {
        foreach (['experience' => 99999, 'level' => 50, 'next_level_total_xp' => 1] as $key => $value) {
            $request = ['csrf_token' => 'task34a-csrf', 'action_key' => 'prototype_weapon_attack', 'request_token' => '34000000-0000-4000-8000-000000000003', $key => $value];
            [$status, , $call] = task5RunCombatActionEndpoint( ['user_id' => 7, 'character_id' => 42, 'csrf_token' => 'task34a-csrf'], 'POST', json_encode($request));
            assertSameValue(400, $status, 'Extra progression field rejected.');
            assertSameValue(false, $call !== null, 'No DB access.');
        }
    },
    'Task34A legacy victory history has no fabricated progression receipt' => function (): void {
        [$pdo, , $service] = task34aVictoryFixture();
        $pdo->encounters[91]['status'] = 'victory_loot';
        $pdo->encounters[91]['rewards_issued_at'] = '2026-09-01 12:00:00.000000';
        assertSameValue(null, $service->state(7, 42)['loot_phase']['rewards']['progression'], 'No backfill.');
    },
];

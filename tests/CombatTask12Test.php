<?php
declare(strict_types=1);

function task12VictoryFixture(): array
{
    $pdo = new FakeCombatPdo();
    $pdo->characters[42]['gold'] = 100;
    $pdo->characters[42]['experience'] = 80;
    $pdo->characters[42]['level'] = 7;
    $pdo->encounters[91] = task4Encounter([
        'timeline_elapsed_ms' => 2000,
        'last_synchronized_at' => '2026-09-01 12:00:00.000000',
        'turn_started_timeline_ms' => 0,
        'next_enemy_decision_timeline_ms' => 3000,
        'enemy_ai_initialized_timeline_ms' => 0,
        'player_actions_remaining' => 0,
        'enemy_actions_remaining' => 1,
        'enemy_current_hp' => 20,
        'version' => 4,
    ]);
    $pdo->actions[60] = task6PendingAction([
        'id' => 60,
        'encounter_id' => 91,
        'started_timeline_ms' => 1500,
        'resolves_timeline_ms' => 2500,
        'cooldown_ready_timeline_ms' => 4000,
        'snapshot_base_damage' => 20,
    ]);
    $pdo->actions[61] = task6EnemyAction('smash', [
        'id' => 61,
        'encounter_id' => 91,
        'started_timeline_ms' => 1500,
        'resolves_timeline_ms' => 3000,
        'cooldown_ready_timeline_ms' => 4500,
    ]);
    $clock = new Task4MutableCombatClock(new DateTimeImmutable(
        '2026-09-01 12:00:01.000000',
        new DateTimeZone('UTC'),
    ));
    $random = new Task6SequenceRandomSource([21, 100]);

    return [$pdo, $clock, task6ChronologicalService($pdo, $clock, null, $random)];
}

function task12AssertRejected(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (DomainException | OutOfBoundsException) {
        return;
    }

    throw new RuntimeException($message . ' Expected rejection.');
}

function task12RunCloseEndpoint(
    array $session,
    string $method = 'POST',
    string $rawBody = '{}',
): array {
    $endpoint = __DIR__ . '/../ascii-quest/combat_close.php';
    if (!is_file($endpoint)) {
        return [0, null, null, 'combat_close.php must exist.'];
    }

    $fixtureDirectory = sys_get_temp_dir() . '/ascii-quest-task12-' . bin2hex(random_bytes(8));
    $libraryDirectory = $fixtureDirectory . '/lib';
    $sessionDirectory = $fixtureDirectory . '/sessions';
    if (!mkdir($libraryDirectory, 0700, true) || !mkdir($sessionDirectory, 0700, true)) {
        throw new RuntimeException('Unable to create combat close endpoint fixture.');
    }
    $endpointCopy = $fixtureDirectory . '/combat_close.php';
    $callFile = $fixtureDirectory . '/service-call.json';
    $runner = $fixtureDirectory . '/run.php';
    copy($endpoint, $endpointCopy);
    file_put_contents($fixtureDirectory . '/db.php', <<<'PHP'
<?php
declare(strict_types=1);
function getDb(): PDO { return new class extends PDO { public function __construct() {} }; }
PHP);
    file_put_contents($libraryDirectory . '/CombatBootstrap.php', <<<'PHP'
<?php
declare(strict_types=1);
final class Task12EndpointService
{
    public function closeVictory(int $userId, int $characterId, string $requestToken): array
    {
        file_put_contents((string) getenv('ASCII_QUEST_TASK12_CALL_FILE'), json_encode([
            'user_id' => $userId,
            'character_id' => $characterId,
            'request_token' => $requestToken,
        ]));
        if ($userId !== 7 || $characterId !== 42) {
            throw new OutOfBoundsException('Private ownership detail.');
        }
        return ['closed' => true];
    }
}
final class CombatBootstrap
{
    public static function service(PDO $pdo): Task12EndpointService
    {
        return new Task12EndpointService();
    }
}
PHP);

    $sessionId = 'task12' . bin2hex(random_bytes(8));
    $runnerSource = '<?php' . "\n" .
        'ini_set(\'session.save_path\', ' . var_export($sessionDirectory, true) . ');' . "\n" .
        'session_id(' . var_export($sessionId, true) . ');' . "\n" .
        'session_start();' . "\n" .
        '$_SESSION = ' . var_export($session, true) . ';' . "\n" .
        'session_write_close();' . "\n" .
        '$_SERVER[\'REQUEST_METHOD\'] = ' . var_export($method, true) . ';' . "\n" .
        'register_shutdown_function(static function (): void { echo "\\n__TASK12_STATUS__" . http_response_code(); });' . "\n" .
        'require ' . var_export($endpointCopy, true) . ';' . "\n";
    file_put_contents($runner, $runnerSource);
    $process = proc_open(
        [PHP_BINARY, $runner],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        array_replace(getenv(), ['ASCII_QUEST_TASK12_CALL_FILE' => $callFile]),
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to execute combat close endpoint fixture.');
    }
    fwrite($pipes[0], $rawBody);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $status = 0;
    $body = $stdout;
    if (preg_match('/\n__TASK12_STATUS__(\d+)\z/', $stdout, $matches) === 1) {
        $status = (int) $matches[1];
        $body = substr($stdout, 0, -strlen($matches[0]));
    }
    $payload = json_decode($body, true);
    $call = is_file($callFile) ? json_decode((string) file_get_contents($callFile), true) : null;
    foreach (glob($sessionDirectory . '/*') ?: [] as $sessionFile) {
        unlink($sessionFile);
    }
    foreach ([$runner, $callFile, $endpointCopy, $libraryDirectory . '/CombatBootstrap.php', $fixtureDirectory . '/db.php'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($sessionDirectory);
    rmdir($libraryDirectory);
    rmdir($fixtureDirectory);

    return [$status, $payload, $call, $stderr];
}

return [
    'Task 12 synchronization recognizes stored zero HP before any due action' => function (): void {
        $pdo = new FakeCombatPdo();
        $pdo->characters[42]['gold'] = 10;
        $pdo->characters[42]['experience'] = 20;
        $pdo->encounters[91] = task4Encounter([
            'timeline_elapsed_ms' => 2000,
            'last_synchronized_at' => '2026-09-01 12:00:00.000000',
            'next_enemy_decision_timeline_ms' => 2000,
            'enemy_ai_initialized_timeline_ms' => 0,
            'enemy_current_hp' => 0,
        ]);
        $pdo->actions[62] = task6EnemyAction('smash', [
            'id' => 62,
            'encounter_id' => 91,
            'started_timeline_ms' => 500,
            'resolves_timeline_ms' => 2000,
            'cooldown_ready_timeline_ms' => 3500,
        ]);
        $clock = new Task4MutableCombatClock(new DateTimeImmutable(
            '2026-09-01 12:00:01.000000',
            new DateTimeZone('UTC'),
        ));

        $state = task6ChronologicalService($pdo, $clock)->state(7, 42);

        assertSameValue('victory_loot', $state['status'] ?? null, 'Stored lethal state transitions immediately.');
        assertSameValue(145, $pdo->characters[42]['current_hp'], 'Due enemy damage is never applied after zero enemy HP.');
        assertSameValue('cancelled', $pdo->actions[62]['state'] ?? null, 'Already-due offense is cancelled.');
        assertSameValue(2000, $pdo->encounters[91]['timeline_elapsed_ms'], 'Terminal cursor does not advance.');
    },

    'Task 12 lethal resolution is terminal before later due combat work' => function (): void {
        [$pdo, , $service] = task12VictoryFixture();
        $state = $service->state(7, 42);

        assertSameValue('victory_loot', $state['status'] ?? null, 'Projected terminal status.');
        assertSameValue('victory_loot', $pdo->encounters[91]['status'] ?? null, 'Durable terminal status.');
        assertSameValue(0, $pdo->encounters[91]['enemy_current_hp'] ?? null, 'Enemy HP is exactly zero.');
        assertSameValue(2500, $pdo->encounters[91]['timeline_elapsed_ms'] ?? null, 'Gameplay timeline stops at lethal resolution.');
        assertSameValue(145, $pdo->characters[42]['current_hp'] ?? null, 'Later enemy damage is not applied.');
        assertSameValue('resolved', $pdo->actions[60]['state'] ?? null, 'Lethal player action resolves once.');
        assertSameValue('cancelled', $pdo->actions[61]['state'] ?? null, 'Outstanding enemy offense is cancelled.');
        assertSameValue(null, $pdo->actions[61]['active_slot'] ?? null, 'Cancelled offense releases its active slot.');

        task12AssertRejected(
            fn (): array => $service->startPlayerAction(
                7,
                42,
                'prototype_weapon_attack',
                '12121212-1212-4212-8212-121212121212',
            ),
            'A player action cannot begin after victory.',
        );
    },

    'Task 12 configured Gold and raw EXP issue exactly once with one event' => function (): void {
        [$pdo, $clock, $service] = task12VictoryFixture();
        $first = $service->state(7, 42);
        $issuedAt = $pdo->encounters[91]['rewards_issued_at'] ?? null;

        assertSameValue(125, $pdo->characters[42]['gold'] ?? null, 'Configured Gold reward.');
        assertSameValue(120, $pdo->characters[42]['experience'] ?? null, 'Configured raw EXP reward.');
        assertSameValue(7, $pdo->characters[42]['level'] ?? null, 'No level formula is invoked.');
        assertSameValue(true, is_string($issuedAt) && $issuedAt !== '', 'Durable reward guard timestamp.');
        $victoryEvents = array_values(array_filter(
            $pdo->events,
            static fn (array $event): bool => ($event['event_type'] ?? null) === 'victory_rewards',
        ));
        assertSameValue(1, count($victoryEvents), 'One server-authored reward event.');
        assertSameValue(true, str_contains($victoryEvents[0]['message'] ?? '', '25 Gold'), 'Event uses configured Gold.');
        assertSameValue(true, str_contains($victoryEvents[0]['message'] ?? '', '40 EXP'), 'Event uses configured EXP.');
        assertSameValue(true, str_contains(
            $first['battle_events'][0]['message'] ?? '',
            '25 Gold and 40 EXP',
        ), 'Victory reward event is visible through the Battle Info projection.');

        $clock->set(new DateTimeImmutable('2026-09-01 12:00:05.000000', new DateTimeZone('UTC')));
        $second = $service->state(7, 42);
        assertSameValue(125, $pdo->characters[42]['gold'] ?? null, 'Refresh cannot duplicate Gold.');
        assertSameValue(120, $pdo->characters[42]['experience'] ?? null, 'Refresh cannot duplicate EXP.');
        assertSameValue($issuedAt, $pdo->encounters[91]['rewards_issued_at'] ?? null, 'Reward guard remains stable.');
        assertSameValue(1, count(array_filter(
            $pdo->events,
            static fn (array $event): bool => ($event['event_type'] ?? null) === 'victory_rewards',
        )), 'Refresh cannot duplicate the event.');
        assertSameValue($first['loot_phase'] ?? null, $second['loot_phase'] ?? null, 'Loot phase survives refresh.');
    },

    'Task 12 victory reward event failure rolls back the entire transition and retries once' => function (): void {
        [$pdo, , $service] = task12VictoryFixture();
        $beforeCharacter = $pdo->characters[42];
        $beforeEncounter = $pdo->encounters[91];
        $beforeActions = $pdo->actions;
        $pdo->failEventInsert = true;
        try {
            $service->state(7, 42);
            throw new RuntimeException('Injected reward-event failure was not raised.');
        } catch (RuntimeException $exception) {
            assertSameValue('Injected combat event insert failure.', $exception->getMessage(), 'Expected event failure.');
        }
        assertSameValue($beforeCharacter, $pdo->characters[42], 'Reward failure rolls back Champion values.');
        assertSameValue($beforeEncounter, $pdo->encounters[91], 'Reward failure rolls back encounter lifecycle.');
        assertSameValue($beforeActions, $pdo->actions, 'Reward failure rolls back action resolution and cancellation.');
        assertSameValue([], $pdo->events, 'Reward failure leaves no event.');

        $pdo->failEventInsert = false;
        $state = $service->state(7, 42);
        assertSameValue('victory_loot', $state['status'] ?? null, 'Retry reaches victory.');
        assertSameValue(125, $pdo->characters[42]['gold'], 'Retry issues Gold once.');
        assertSameValue(120, $pdo->characters[42]['experience'], 'Retry issues EXP once.');
        assertSameValue(1, count($pdo->events), 'Retry appends one event.');
    },

    'Task 12 loot projection separates rewards from empty physical drops' => function (): void {
        [$pdo, , $service] = task12VictoryFixture();
        $state = $service->state(7, 42);

        assertSameValue([
            'rewards' => ['gold' => 25, 'experience' => 40],
            'physical_drops' => [],
        ], $state['loot_phase'] ?? null, 'Presentation-safe victory loot contract.');
        assertSameValue(false, array_key_exists('rewards_issued_at', $state), 'Reward guard remains private.');
        assertSameValue(false, in_array(['gold' => 25], $state['loot_phase']['physical_drops'] ?? [], true), 'Gold is not physical loot.');
        assertSameValue(false, in_array(['experience' => 40], $state['loot_phase']['physical_drops'] ?? [], true), 'EXP is not physical loot.');
        assertSameValue(1, $pdo->encounters[91]['active_slot'] ?? null, 'Victory loot retains the active encounter slot.');
    },

    'Task 12 owned victory loot closes once without healing or reissuing rewards' => function (): void {
        [$pdo, , $service] = task12VictoryFixture();
        $service->state(7, 42);
        $before = $pdo->characters[42];

        $result = $service->closeVictory(
            7,
            42,
            '34343434-3434-4434-8434-343434343434',
        );

        assertSameValue(['closed' => true], $result, 'Close response.');
        assertSameValue('closed', $pdo->encounters[91]['status'] ?? null, 'Closed lifecycle status.');
        assertSameValue(null, $pdo->encounters[91]['active_slot'] ?? null, 'Active encounter slot releases.');
        assertSameValue(true, is_string($pdo->encounters[91]['completed_at'] ?? null), 'Completion timestamp persists.');
        assertSameValue(null, (new CombatRepository($pdo))->findActiveEncounter(42), 'Exploration has no active encounter lock.');
        assertSameValue($before['current_hp'], $pdo->characters[42]['current_hp'], 'Close does not heal HP.');
        assertSameValue($before['current_mana'], $pdo->characters[42]['current_mana'], 'Close does not restore Mana.');
        assertSameValue($before['gold'], $pdo->characters[42]['gold'], 'Close does not reissue Gold.');
        assertSameValue($before['experience'], $pdo->characters[42]['experience'], 'Close does not reissue EXP.');

        task12AssertRejected(
            fn (): array => $service->closeVictory(
                7,
                42,
                '34343434-3434-4434-8434-343434343434',
            ),
            'A replay cannot close or reward another encounter.',
        );
        assertSameValue('closed', $pdo->encounters[91]['status'] ?? null, 'Replay leaves one closed encounter.');
        assertSameValue($before['gold'], $pdo->characters[42]['gold'], 'Replay leaves one reward set.');
    },

    'Task 12 close rejects active combat and wrong ownership' => function (): void {
        $pdo = new FakeCombatPdo();
        $pdo->characters[42]['gold'] = 100;
        $pdo->characters[42]['experience'] = 80;
        $pdo->encounters[91] = task4Encounter(['enemy_ai_initialized_timeline_ms' => 0]);
        $clock = new Task4MutableCombatClock(new DateTimeImmutable(
            '2026-09-01 12:00:00.000000',
            new DateTimeZone('UTC'),
        ));
        $service = task6ChronologicalService($pdo, $clock);

        task12AssertRejected(
            fn (): array => $service->closeVictory(
                7,
                42,
                '56565656-5656-4656-8656-565656565656',
            ),
            'Active combat cannot close.',
        );
        task12AssertRejected(
            fn (): array => $service->closeVictory(
                8,
                42,
                '78787878-7878-4878-8878-787878787878',
            ),
            'Wrong owner cannot close.',
        );
        assertSameValue('active', $pdo->encounters[91]['status'] ?? null, 'Rejected close preserves encounter.');
        assertSameValue(100, $pdo->characters[42]['gold'] ?? null, 'Rejected close grants no rewards.');
    },

    'Task 12 two service instances produce one closed encounter and one reward set' => function (): void {
        [$pdo, $clock, $firstTab] = task12VictoryFixture();
        $secondTab = task6ChronologicalService($pdo, $clock, null, new Task6SequenceRandomSource([]));
        $firstTab->state(7, 42);

        assertSameValue(['closed' => true], $firstTab->closeVictory(
            7,
            42,
            'cdcdcdcd-cdcd-4dcd-8dcd-cdcdcdcdcdcd',
        ), 'First tab closes.');
        task12AssertRejected(
            fn (): array => $secondTab->closeVictory(
                7,
                42,
                'efefefef-efef-4fef-8fef-efefefefefef',
            ),
            'Second tab cannot repeat the close.',
        );
        assertSameValue('closed', $pdo->encounters[91]['status'], 'One final closed state.');
        assertSameValue(125, $pdo->characters[42]['gold'], 'One Gold reward set.');
        assertSameValue(120, $pdo->characters[42]['experience'], 'One EXP reward set.');
        assertSameValue(1, count($pdo->events), 'One victory event.');
    },

    'Task 12 close endpoint requires session POST JSON and valid CSRF' => function (): void {
        $validBody = (string) json_encode([
            'csrf_token' => 'session-token',
            'request_token' => '90909090-9090-4090-8090-909090909090',
        ]);
        foreach ([
            [[], 'POST', $validBody, 401],
            [['user_id' => 7, 'character_id' => 42, 'csrf_token' => 'session-token'], 'GET', $validBody, 405],
            [['user_id' => 7, 'character_id' => 42, 'csrf_token' => 'session-token'], 'POST', '{', 400],
            [['user_id' => 7, 'character_id' => 42, 'csrf_token' => 'session-token'], 'POST', (string) json_encode([
                'request_token' => '90909090-9090-4090-8090-909090909090',
            ]), 400],
            [['user_id' => 7, 'character_id' => 42, 'csrf_token' => 'session-token'], 'POST', (string) json_encode([
                'csrf_token' => 'wrong-token',
                'request_token' => '90909090-9090-4090-8090-909090909090',
            ]), 403],
        ] as [$session, $method, $body, $expectedStatus]) {
            [$status, $payload, $call] = task12RunCloseEndpoint($session, $method, $body);
            assertSameValue($expectedStatus, $status, 'Close endpoint security status.');
            assertSameValue(false, $payload['success'] ?? null, 'Close endpoint safe failure.');
            assertSameValue(null, $call, 'Rejected close never reaches service authority.');
        }

        [$status, $payload, $call] = task12RunCloseEndpoint(
            ['user_id' => 7, 'character_id' => 99, 'csrf_token' => 'session-token'],
            'POST',
            $validBody,
        );
        assertSameValue(404, $status, 'Wrong-owner session is rejected by service ownership enforcement.');
        assertSameValue(false, $payload['success'] ?? null, 'Wrong-owner response remains safe.');
        assertSameValue(7, $call['user_id'] ?? null, 'Endpoint uses the session user for ownership.');
        assertSameValue(99, $call['character_id'] ?? null, 'Endpoint uses the session Champion for ownership.');
    },

    'Task 12 close endpoint accepts only CSRF and canonical request token from session owner' => function (): void {
        $session = ['user_id' => 7, 'character_id' => 42, 'csrf_token' => 'session-token'];
        $base = [
            'csrf_token' => 'session-token',
            'request_token' => 'abababab-abab-4bab-8bab-abababababab',
        ];
        foreach (['reward_gold', 'experience', 'enemy_hp', 'current_hp', 'status', 'loot', 'timeline_ms', 'character_id'] as $field) {
            [$status, , $call] = task12RunCloseEndpoint(
                $session,
                'POST',
                (string) json_encode($base + [$field => 999]),
            );
            assertSameValue(400, $status, $field . ' is outside strict allowlist.');
            assertSameValue(null, $call, $field . ' never reaches the service.');
        }
        [$invalidStatus, , $invalidCall] = task12RunCloseEndpoint(
            $session,
            'POST',
            (string) json_encode(array_replace($base, ['request_token' => 'not-a-uuid'])),
        );
        assertSameValue(422, $invalidStatus, 'Invalid UUID status.');
        assertSameValue(null, $invalidCall, 'Invalid UUID never reaches service.');

        [$status, $payload, $call] = task12RunCloseEndpoint($session, 'POST', (string) json_encode($base));
        assertSameValue(200, $status, 'Valid close status.');
        assertSameValue(true, $payload['closed'] ?? null, 'Valid close response.');
        assertSameValue(7, $call['user_id'] ?? null, 'Session user is authoritative.');
        assertSameValue(42, $call['character_id'] ?? null, 'Session Champion is authoritative.');
        assertSameValue($base['request_token'], $call['request_token'] ?? null, 'Canonical request token reaches service.');
    },
];

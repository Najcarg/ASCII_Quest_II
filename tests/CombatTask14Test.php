<?php
declare(strict_types=1);

function task14RunEarlyRoute(
    string $route,
    array $session,
    string $method,
    array $post,
): array {
    $source = __DIR__ . '/../ascii-quest/' . $route;
    if (!is_file($source)) {
        throw new RuntimeException('Missing route fixture: ' . $route);
    }

    $directory = sys_get_temp_dir() . '/ascii-quest-task14-' . bin2hex(random_bytes(8));
    $libraryDirectory = $directory . '/lib';
    $sessionDirectory = $directory . '/sessions';
    if (!mkdir($libraryDirectory, 0700, true) || !mkdir($sessionDirectory, 0700, true)) {
        throw new RuntimeException('Unable to create Task 14 route fixture.');
    }

    $endpoint = $directory . '/' . $route;
    $runner = $directory . '/run.php';
    $databaseCall = $directory . '/database-call';
    copy($source, $endpoint);
    file_put_contents($directory . '/db.php', <<<'PHP'
<?php
declare(strict_types=1);
function getDb(): object
{
    file_put_contents((string) getenv('ASCII_QUEST_TASK14_DATABASE_CALL'), 'called');
    throw new RuntimeException('Database must not be reached by a rejected request.');
}
PHP);
    foreach (['map_loader.php'] as $dependency) {
        file_put_contents($directory . '/' . $dependency, "<?php\ndeclare(strict_types=1);\n");
    }
    foreach (['CharacterStats.php', 'WarpBootstrap.php', 'CombatBootstrap.php', 'EquipmentBootstrap.php'] as $dependency) {
        file_put_contents($libraryDirectory . '/' . $dependency, "<?php\ndeclare(strict_types=1);\n");
    }

    $sessionId = 'task14' . bin2hex(random_bytes(8));
    $runnerSource = '<?php' . "\n" .
        'ini_set(\'session.save_path\', ' . var_export($sessionDirectory, true) . ');' . "\n" .
        'session_id(' . var_export($sessionId, true) . ');' . "\n" .
        'session_start();' . "\n" .
        '$_SESSION = ' . var_export($session, true) . ';' . "\n" .
        'session_write_close();' . "\n" .
        '$_SERVER[\'REQUEST_METHOD\'] = ' . var_export($method, true) . ';' . "\n" .
        '$_POST = ' . var_export($post, true) . ';' . "\n" .
        'register_shutdown_function(static function (): void { echo "\\n__TASK14_STATUS__" . http_response_code(); });' . "\n" .
        'require ' . var_export($endpoint, true) . ';' . "\n";
    file_put_contents($runner, $runnerSource);

    $process = proc_open(
        [PHP_BINARY, $runner],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        array_replace(getenv(), ['ASCII_QUEST_TASK14_DATABASE_CALL' => $databaseCall]),
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to execute Task 14 route fixture.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $status = 0;
    $body = $stdout;
    if (preg_match('/\n__TASK14_STATUS__(\d+)\z/', $stdout, $matches) === 1) {
        $status = (int) $matches[1];
        $body = substr($stdout, 0, -strlen($matches[0]));
    }
    $databaseReached = is_file($databaseCall);

    foreach (glob($sessionDirectory . '/*') ?: [] as $sessionFile) {
        unlink($sessionFile);
    }
    foreach ([
        $runner,
        $databaseCall,
        $endpoint,
        $directory . '/db.php',
        $directory . '/map_loader.php',
        $libraryDirectory . '/CharacterStats.php',
        $libraryDirectory . '/WarpBootstrap.php',
        $libraryDirectory . '/CombatBootstrap.php',
        $libraryDirectory . '/EquipmentBootstrap.php',
    ] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($sessionDirectory);
    rmdir($libraryDirectory);
    rmdir($directory);

    return [$exitCode, $status, $body, $stderr, $databaseReached];
}

return [
    'Task 14 exploration mutation routes reject session and CSRF failures before database work' => function (): void {
        $session = ['user_id' => 7, 'character_id' => 42, 'csrf_token' => 'session-token'];
        foreach ([
            'move_character.php',
            'interact.php',
            'sync_map_state.php',
            'create_character.php',
            'select_character.php',
            'delete_character.php',
        ] as $route) {
            foreach ([
                ['session' => [], 'post' => ['csrf_token' => 'session-token']],
                ['session' => $session, 'post' => []],
                ['session' => $session, 'post' => ['csrf_token' => 'wrong-token']],
            ] as $case) {
                [$exitCode, , , $stderr, $databaseReached] = task14RunEarlyRoute(
                    $route,
                    $case['session'],
                    'POST',
                    $case['post'],
                );
                assertSameValue(0, $exitCode, $route . ' rejected request exits safely. ' . $stderr);
                assertSameValue(false, $databaseReached, $route . ' rejects before database authority.');
            }
        }
    },

    'Task 14 malformed delete CSRF is rejected without a TypeError or database access' => function (): void {
        foreach ([
            'move_character.php',
            'interact.php',
            'sync_map_state.php',
            'create_character.php',
            'select_character.php',
            'delete_character.php',
        ] as $route) {
            [$exitCode, , , $stderr, $databaseReached] = task14RunEarlyRoute(
                $route,
                ['user_id' => 7, 'character_id' => 42, 'csrf_token' => 'session-token'],
                'POST',
                ['csrf_token' => ['malformed']],
            );

            assertSameValue(0, $exitCode, $route . ' malformed CSRF exits through controlled rejection. ' . $stderr);
            assertSameValue(false, str_contains($stderr, 'TypeError'), $route . ' malformed CSRF raises no TypeError.');
            assertSameValue(false, $databaseReached, $route . ' malformed CSRF reaches no database mutation path.');
        }
    },

    'Task 14 unresolved terminal and ordinary lifecycle route matrix stays server authoritative' => function (): void {
        $blockedOperations = [
            CombatAccessGuard::MOVE,
            CombatAccessGuard::INTERACT,
            CombatAccessGuard::MAP_SYNC,
            CombatAccessGuard::WARP_UNLOCK,
            CombatAccessGuard::WARP_TRAVEL,
            CombatAccessGuard::STAT_ALLOCATE,
            CombatAccessGuard::COMBAT_ENTRY,
        ];
        foreach (['active', 'victory_loot'] as $status) {
            [$guard, $repository] = task3CombatGuard();
            $repository->encounters[] = [
                'id' => 10,
                'character_id' => 42,
                'status' => $status,
                'active_slot' => 1,
            ];
            foreach ($blockedOperations as $operation) {
                assertTask3GuardRejected(
                    fn (): array => $guard->assertAllowed($operation, 7, 42),
                    $status . ' ' . $operation,
                );
            }
            assertSameValue(true, $guard->assertAllowed(
                CombatAccessGuard::SELECT_CHARACTER,
                7,
                42,
            )['resume_combat'], $status . ' fighter remains resumable.');
            assertTask3GuardRejected(
                fn (): array => $guard->assertAllowed(CombatAccessGuard::SELECT_CHARACTER, 7, 43),
                $status . ' blocks another Champion.',
            );
            assertSameValue(true, $guard->assertAllowed(
                CombatAccessGuard::CREATE_CHARACTER,
                7,
                0,
            )['allowed'], $status . ' keeps creation available.');
            assertSameValue(0, $repository->writes, $status . ' matrix performs no mutation.');
        }

        [$closedGuard, $closedRepository] = task3CombatGuard();
        $closedRepository->encounters[] = [
            'id' => 10,
            'character_id' => 42,
            'status' => 'closed',
            'active_slot' => null,
        ];
        foreach ($blockedOperations as $operation) {
            assertSameValue(true, $closedGuard->assertAllowed($operation, 7, 42)['allowed'], 'Closed ' . $operation . '.');
        }
        assertSameValue(true, $closedGuard->assertAllowed(
            CombatAccessGuard::DELETE_CHARACTER,
            7,
            42,
        )['allowed'], 'Closed living Champion is deletable.');

        [$deadGuard, $deadRepository] = task3CombatGuard();
        $deadRepository->characters[42]['life_state'] = 'dead';
        foreach (array_merge($blockedOperations, [
            CombatAccessGuard::SELECT_CHARACTER,
            CombatAccessGuard::DELETE_CHARACTER,
        ]) as $operation) {
            assertTask3GuardRejected(
                fn (): array => $deadGuard->assertAllowed($operation, 7, 42),
                'Defeated/dead ' . $operation,
            );
        }
    },

    'Task 14 two tabs replay one skill request without duplicate action or allowance spend' => function (): void {
        $pdo = new FakeCombatPdo();
        $pdo->encounters[91] = task4Encounter([
            'enemy_current_hp' => 120,
            'timeline_elapsed_ms' => 1000,
            'last_synchronized_at' => '2026-09-01 12:00:00.000000',
            'turn_started_timeline_ms' => 0,
            'next_enemy_decision_timeline_ms' => 10000,
            'enemy_ai_initialized_timeline_ms' => 0,
            'player_actions_remaining' => 2,
            'enemy_actions_remaining' => 2,
        ]);
        $clock = new Task4MutableCombatClock(new DateTimeImmutable(
            '2026-09-01 12:00:00.000000',
            new DateTimeZone('UTC'),
        ));
        $first = task6ChronologicalService($pdo, $clock, null, new Task6SequenceRandomSource([21]));
        $second = task6ChronologicalService($pdo, $clock, null, new Task6SequenceRandomSource([]));
        $token = '14141414-1414-4414-8414-141414141414';

        $firstState = $first->startPlayerAction(7, 42, 'prototype_flame_strike', $token);
        $secondState = $second->startPlayerAction(7, 42, 'prototype_flame_strike', $token);
        $skills = array_values(array_filter(
            $pdo->actions,
            static fn (array $action): bool => ($action['definition_key'] ?? null) === 'prototype_flame_strike',
        ));

        assertSameValue(1, count($skills), 'One durable skill action.');
        assertSameValue(1, $pdo->encounters[91]['player_actions_remaining'], 'One Action is spent.');
        assertSameValue($firstState['player_actions'], $secondState['player_actions'], 'Both tabs observe the same action.');
    },

    'Task 14 two service instances replay weapon Potion and Block commands exactly once' => function (): void {
        $weaponPdo = new FakeCombatPdo();
        $weaponPdo->encounters[91] = task4Encounter([
            'timeline_elapsed_ms' => 9000,
            'player_actions_remaining' => 1,
        ]);
        $weaponClock = new Task4MutableCombatClock(new DateTimeImmutable(
            '2026-09-01 12:00:00.000000',
            new DateTimeZone('UTC'),
        ));
        $weaponToken = '14214214-1421-4421-8421-142142142142';
        task5RepositoryService($weaponPdo, $weaponClock)->startPlayerAction(
            7,
            42,
            'prototype_weapon_attack',
            $weaponToken,
        );
        task5RepositoryService($weaponPdo, $weaponClock)->startPlayerAction(
            7,
            42,
            'prototype_weapon_attack',
            $weaponToken,
        );
        assertSameValue(1, count($weaponPdo->actions), 'Two weapon tabs persist one action.');
        assertSameValue(0, $weaponPdo->encounters[91]['player_actions_remaining'], 'Two weapon tabs spend one Action.');

        $potionPdo = new FakeCombatPdo();
        task8Encounter($potionPdo);
        $potionPdo->characters[42]['current_hp'] = 100;
        $potionToken = '14314314-1431-4431-8431-143143143143';
        task8UsePotion(task8Service($potionPdo), $potionToken);
        $potionAfterFirst = [
            $potionPdo->characters[42]['current_hp'],
            $potionPdo->encounters[91]['potion_charges_remaining'],
            $potionPdo->actions,
            $potionPdo->events,
        ];
        task8UsePotion(task8Service($potionPdo), $potionToken);
        assertSameValue($potionAfterFirst, [
            $potionPdo->characters[42]['current_hp'],
            $potionPdo->encounters[91]['potion_charges_remaining'],
            $potionPdo->actions,
            $potionPdo->events,
        ], 'Two Potion tabs heal, consume, persist, and log once.');

        $blockPdo = new FakeCombatPdo();
        $blockPdo->encounters[91] = task4Encounter([
            'timeline_elapsed_ms' => 500,
            'last_synchronized_at' => '2026-09-01 12:00:00.000000',
            'next_enemy_decision_timeline_ms' => 2000,
            'enemy_ai_initialized_timeline_ms' => 0,
            'player_actions_remaining' => 0,
        ]);
        $blockPdo->actions[71] = task7EnemyAction();
        $blockClock = new Task4MutableCombatClock(new DateTimeImmutable(
            '2026-09-01 12:00:00.000000',
            new DateTimeZone('UTC'),
        ));
        $blockToken = '14414414-1441-4441-8441-144144144144';
        foreach ([new Task7SequenceRandomSource(), new Task7SequenceRandomSource()] as $random) {
            CombatBootstrap::serviceForRepository(
                new CombatRepository($blockPdo),
                $blockClock,
                new Task5MutableEquipmentProvider(),
                $random,
            )->attemptBlock(7, 42, 71, str_repeat('b', 64), $blockToken);
        }
        $blockCommands = array_filter(
            $blockPdo->actions,
            static fn (array $action): bool => ($action['action_kind'] ?? null) === 'block',
        );
        assertSameValue(1, count($blockCommands), 'Two Block tabs persist one resolution.');
        assertSameValue(500, $blockPdo->actions[71]['block_attempted_timeline_ms'] ?? null, 'Two Block tabs retain one attempt timeline.');
        assertSameValue(0, $blockPdo->encounters[91]['player_actions_remaining'], 'Block consumes no Action.');
    },

    'Task 14 accepted weapon and skill tokens replay current victory without duplicate effects' => function (): void {
        foreach ([
            'prototype_weapon_attack' => '14514514-1451-4451-8451-145145145145',
            'prototype_flame_strike' => '14614614-1461-4461-8461-146146146146',
        ] as $actionKey => $requestToken) {
            $pdo = new FakeCombatPdo();
            $pdo->characters[42]['gold'] = 100;
            $pdo->characters[42]['experience'] = 80;
            $pdo->encounters[91] = task4Encounter([
                'timeline_elapsed_ms' => 0,
                'last_synchronized_at' => '2026-09-01 12:00:00.000000',
                'turn_started_timeline_ms' => 0,
                'next_enemy_decision_timeline_ms' => 10000,
                'enemy_ai_initialized_timeline_ms' => 0,
                'player_actions_remaining' => 2,
                'enemy_actions_remaining' => 1,
                'enemy_current_hp' => 1,
                'version' => 4,
            ]);
            $clock = new Task4MutableCombatClock(new DateTimeImmutable(
                '2026-09-01 12:00:00.000000',
                new DateTimeZone('UTC'),
            ));
            $service = task6ChronologicalService(
                $pdo,
                $clock,
                null,
                new Task6SequenceRandomSource([21]),
            );

            $service->startPlayerAction(7, 42, $actionKey, $requestToken);
            $clock->set(new DateTimeImmutable(
                '2026-09-01 12:00:02.000000',
                new DateTimeZone('UTC'),
            ));
            $victory = $service->state(7, 42);
            $beforeReplay = [
                'character' => $pdo->characters[42],
                'actions' => $pdo->actions,
                'events' => $pdo->events,
                'rewards_issued_at' => $pdo->encounters[91]['rewards_issued_at'],
                'player_actions_remaining' => $pdo->encounters[91]['player_actions_remaining'],
            ];

            $replay = task6ChronologicalService(
                $pdo,
                $clock,
                null,
                new Task6SequenceRandomSource([]),
            )->startPlayerAction(7, 42, $actionKey, $requestToken);

            assertSameValue('victory_loot', $victory['status'] ?? null, $actionKey . ' reaches victory.');
            assertSameValue('victory_loot', $replay['status'] ?? null, $actionKey . ' replay returns current victory.');
            assertSameValue(1, count(array_filter(
                $pdo->actions,
                static fn (array $action): bool => ($action['request_token'] ?? null) === $requestToken,
            )), $actionKey . ' keeps one accepted command row.');
            assertSameValue(1, $pdo->encounters[91]['player_actions_remaining'], $actionKey . ' spends one Action.');
            assertSameValue(true, ($pdo->actions[1]['resolved_damage'] ?? null) > 0, $actionKey . ' resolves damage once.');
            assertSameValue($beforeReplay['character'], $pdo->characters[42], $actionKey . ' replay does not repeat rewards.');
            assertSameValue($beforeReplay['actions'], $pdo->actions, $actionKey . ' replay does not rewrite actions.');
            assertSameValue($beforeReplay['events'], $pdo->events, $actionKey . ' replay does not append events.');
            assertSameValue($beforeReplay['rewards_issued_at'], $pdo->encounters[91]['rewards_issued_at'], $actionKey . ' reward guard is unchanged.');
            assertSameValue(1, count(array_filter(
                $pdo->events,
                static fn (array $event): bool => ($event['event_type'] ?? null) === 'victory_rewards',
            )), $actionKey . ' has one reward event.');

            $otherAction = $actionKey === 'prototype_weapon_attack'
                ? 'prototype_flame_strike'
                : 'prototype_weapon_attack';
            task12AssertRejected(
                fn (): array => task6ChronologicalService(
                    $pdo,
                    $clock,
                    null,
                    new Task6SequenceRandomSource([]),
                )->startPlayerAction(7, 42, $otherAction, $requestToken),
                $actionKey . ' terminal token collision.',
            );
        }
    },

    'Task 14 accepted action token replays after later permanent defeat' => function (): void {
        $pdo = new FakeCombatPdo();
        $pdo->characters[42]['current_hp'] = 1;
        $pdo->encounters[91] = task4Encounter([
            'timeline_elapsed_ms' => 0,
            'last_synchronized_at' => '2026-09-01 12:00:00.000000',
            'turn_started_timeline_ms' => 0,
            'next_enemy_decision_timeline_ms' => 10000,
            'enemy_ai_initialized_timeline_ms' => 0,
            'player_actions_remaining' => 1,
            'enemy_actions_remaining' => 1,
            'version' => 4,
        ]);
        $pdo->actions[60] = task6EnemyAction('smash', [
            'id' => 60,
            'encounter_id' => 91,
            'started_timeline_ms' => 0,
            'resolves_timeline_ms' => 500,
            'cooldown_ready_timeline_ms' => 3000,
        ]);
        $clock = new Task4MutableCombatClock(new DateTimeImmutable(
            '2026-09-01 12:00:00.000000',
            new DateTimeZone('UTC'),
        ));
        $token = '14714714-1471-4471-8471-147147147147';
        $service = task6ChronologicalService($pdo, $clock);
        $service->startPlayerAction(7, 42, 'prototype_weapon_attack', $token);
        $clock->set(new DateTimeImmutable(
            '2026-09-01 12:00:02.000000',
            new DateTimeZone('UTC'),
        ));
        $service->state(7, 42);
        $beforeReplay = [
            'character' => $pdo->characters[42],
            'actions' => $pdo->actions,
            'events' => $pdo->events,
            'death_processed_at' => $pdo->encounters[91]['death_processed_at'],
        ];

        $replay = task6ChronologicalService($pdo, $clock)->startPlayerAction(
            7,
            42,
            'prototype_weapon_attack',
            $token,
        );

        assertSameValue('defeated', $replay['status'] ?? null, 'Accepted action replay returns current defeat.');
        assertSameValue($beforeReplay['character'], $pdo->characters[42], 'Defeat replay does not alter dead Champion state.');
        assertSameValue($beforeReplay['actions'], $pdo->actions, 'Defeat replay does not recreate or resolve actions.');
        assertSameValue($beforeReplay['events'], $pdo->events, 'Defeat replay does not append death events.');
        assertSameValue($beforeReplay['death_processed_at'], $pdo->encounters[91]['death_processed_at'], 'Death guard is unchanged.');
    },

    'Task 14 accepted Potion token replays terminal state without healing or consumption' => function (): void {
        $pdo = new FakeCombatPdo();
        task8Encounter($pdo, [
            'next_enemy_decision_timeline_ms' => 10000,
            'enemy_actions_remaining' => 0,
        ]);
        $pdo->characters[42]['current_hp'] = 100;
        $clock = task8Clock();
        $token = '14814814-1481-4481-8481-148148148148';
        task8UsePotion(task8Service($pdo, $clock), $token);
        $pdo->encounters[91]['enemy_current_hp'] = 0;
        $terminal = task6ChronologicalService($pdo, $clock)->state(7, 42);
        $beforeReplay = [
            'hp' => $pdo->characters[42]['current_hp'],
            'charges' => $pdo->encounters[91]['potion_charges_remaining'],
            'actions' => $pdo->actions,
            'events' => $pdo->events,
            'rewards_issued_at' => $pdo->encounters[91]['rewards_issued_at'],
        ];

        $replay = task8UsePotion(task8Service($pdo, $clock), $token);

        assertSameValue('victory_loot', $terminal['status'] ?? null, 'Potion encounter becomes terminal.');
        assertSameValue('victory_loot', $replay['status'] ?? null, 'Potion replay returns terminal projection.');
        assertSameValue($beforeReplay, [
            'hp' => $pdo->characters[42]['current_hp'],
            'charges' => $pdo->encounters[91]['potion_charges_remaining'],
            'actions' => $pdo->actions,
            'events' => $pdo->events,
            'rewards_issued_at' => $pdo->encounters[91]['rewards_issued_at'],
        ], 'Potion terminal replay does not heal consume write or reward again.');
        assertSameValue(1, count(task8PotionActions($pdo)), 'Potion terminal replay keeps one command.');

        task12AssertRejected(
            fn (): array => task6ChronologicalService($pdo, $clock)->startPlayerAction(
                7,
                42,
                'prototype_weapon_attack',
                $token,
            ),
            'Terminal Potion token used as weapon.',
        );
    },

    'Task 14 accepted Block token replays terminal state without another attempt' => function (): void {
        $pdo = new FakeCombatPdo();
        $pdo->encounters[91] = task4Encounter([
            'timeline_elapsed_ms' => 500,
            'last_synchronized_at' => '2026-09-01 12:00:00.000000',
            'next_enemy_decision_timeline_ms' => 10000,
            'enemy_ai_initialized_timeline_ms' => 0,
            'player_actions_remaining' => 0,
        ]);
        $pdo->actions[71] = task7EnemyAction();
        $clock = new Task4MutableCombatClock(new DateTimeImmutable(
            '2026-09-01 12:00:00.000000',
            new DateTimeZone('UTC'),
        ));
        $token = '14914914-1491-4491-8491-149149149149';
        $service = task6ChronologicalService(
            $pdo,
            $clock,
            null,
            new Task6SequenceRandomSource([]),
        );
        $service->attemptBlock(7, 42, 71, str_repeat('b', 64), $token);
        $pdo->encounters[91]['enemy_current_hp'] = 0;
        $terminal = $service->state(7, 42);
        $beforeReplay = [
            'actions' => $pdo->actions,
            'events' => $pdo->events,
            'attempted_at' => $pdo->actions[71]['block_attempted_timeline_ms'],
        ];

        $replay = task6ChronologicalService($pdo, $clock)->attemptBlock(
            7,
            42,
            71,
            str_repeat('b', 64),
            $token,
        );

        assertSameValue('victory_loot', $terminal['status'] ?? null, 'Block encounter becomes terminal.');
        assertSameValue('victory_loot', $replay['status'] ?? null, 'Block replay returns terminal projection.');
        assertSameValue($beforeReplay['actions'], $pdo->actions, 'Block terminal replay creates no command or attempt.');
        assertSameValue($beforeReplay['events'], $pdo->events, 'Block terminal replay appends no event.');
        assertSameValue($beforeReplay['attempted_at'], $pdo->actions[71]['block_attempted_timeline_ms'], 'Block attempt timeline is unchanged.');
        assertSameValue(1, count(array_filter(
            $pdo->actions,
            static fn (array $action): bool => ($action['action_kind'] ?? null) === 'block',
        )), 'Block terminal replay keeps one Block command.');

        task12AssertRejected(
            fn (): array => task6ChronologicalService($pdo, $clock)->attemptBlock(
                7,
                42,
                72,
                str_repeat('b', 64),
                $token,
            ),
            'Terminal Block token used for another parent action.',
        );
    },

    'Task 14 navigation guards preserve persisted resources and encounter state' => function (): void {
        foreach (['active', 'victory_loot'] as $status) {
            [$guard, $repository] = task3CombatGuard();
            $repository->characters[42]['current_hp'] = 73;
            $repository->characters[42]['current_mana'] = 41;
            $repository->encounters[] = [
                'id' => 10,
                'character_id' => 42,
                'status' => $status,
                'active_slot' => 1,
                'enemy_current_hp' => $status === 'active' ? 81 : 0,
            ];
            $beforeCharacter = $repository->characters[42];
            $beforeEncounter = $repository->encounters[0];

            $load = $guard->assertAllowed(CombatAccessGuard::GAME_LOAD, 7, 42);
            $select = $guard->assertAllowed(CombatAccessGuard::SELECT_CHARACTER, 7, 42);

            assertSameValue(true, $load['resume_combat'], $status . ' game load resumes.');
            assertSameValue(true, $select['resume_combat'], $status . ' selection resumes.');
            assertSameValue($beforeCharacter, $repository->characters[42], $status . ' navigation preserves HP and Mana.');
            assertSameValue($beforeEncounter, $repository->encounters[0], $status . ' navigation preserves encounter.');
            assertSameValue(0, $repository->writes, $status . ' navigation performs no write.');
        }
    },
];

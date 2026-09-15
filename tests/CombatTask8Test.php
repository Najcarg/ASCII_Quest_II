<?php
declare(strict_types=1);

function task8Encounter(FakeCombatPdo $pdo, array $overrides = []): void
{
    $pdo->encounters[91] = task4Encounter(array_replace([
        'timeline_elapsed_ms' => 3000,
        'last_synchronized_at' => '2026-09-01 12:00:00.000000',
        'next_enemy_decision_timeline_ms' => 5000,
        'enemy_ai_initialized_timeline_ms' => 0,
        'potion_key' => 'prototype_health_potion',
        'potion_charge_allowance' => 1,
        'potion_charges_remaining' => 1,
        'version' => 4,
    ], $overrides));
}

function task8Clock(string $now = '2026-09-01 12:00:00.000000'): Task4MutableCombatClock
{
    return new Task4MutableCombatClock(
        new DateTimeImmutable($now, new DateTimeZone('UTC')),
    );
}

function task8Service(
    FakeCombatPdo $pdo,
    ?Task4MutableCombatClock $clock = null,
    ?CombatDefinitionRegistry $definitions = null,
): CombatService {
    $definitions ??= CombatDefinitionRegistry::fromDefaultConfig();

    return new CombatService(
        new CombatRepository($pdo),
        $definitions,
        $clock ?? task8Clock(),
    );
}

function task8UsePotion(
    CombatService $service,
    string $requestToken = '88888888-8888-4888-8888-888888888888',
    int $userId = 7,
    int $characterId = 42,
): array {
    if (!method_exists($service, 'usePotion')) {
        throw new RuntimeException('CombatService::usePotion() is missing.');
    }

    return $service->usePotion($userId, $characterId, $requestToken);
}

function task8AssertRejected(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (Throwable) {
        return;
    }

    throw new RuntimeException($message . ' Expected rejection.');
}

function task8PotionActions(FakeCombatPdo $pdo): array
{
    return array_values(array_filter(
        $pdo->actions,
        static fn (array $action): bool =>
            ($action['actor'] ?? null) === 'player' &&
            ($action['action_kind'] ?? null) === 'potion',
    ));
}

function task8PendingWeapon(int $id = 61): array
{
    return array_replace(
        combatActionFixture('61616161-6161-4161-8161-616161616161'),
        [
            'id' => $id,
            'encounter_id' => 91,
            'actor' => 'player',
            'action_kind' => 'weapon',
            'definition_key' => 'prototype_weapon_attack',
            'state' => 'pending',
            'active_slot' => 1,
            'started_timeline_ms' => 2500,
            'resolves_timeline_ms' => 4000,
            'cooldown_ready_timeline_ms' => 5000,
            'completed_timeline_ms' => null,
        ],
    );
}

function task8RunPotionEndpoint(
    array $session,
    string $method,
    string $rawBody,
    ?string $serviceFailure = null,
): array {
    $endpoint = __DIR__ . '/../ascii-quest/combat_potion.php';
    if (!is_file($endpoint)) {
        throw new RuntimeException('combat_potion.php is missing.');
    }

    $directory = sys_get_temp_dir() . '/ascii-quest-task8-' . bin2hex(random_bytes(8));
    $libraryDirectory = $directory . '/lib';
    $sessionDirectory = $directory . '/sessions';
    if (!mkdir($libraryDirectory, 0700, true) || !mkdir($sessionDirectory, 0700, true)) {
        throw new RuntimeException('Unable to create Potion endpoint fixture.');
    }

    $endpointCopy = $directory . '/combat_potion.php';
    $callFile = $directory . '/service-call.json';
    $runner = $directory . '/run.php';
    copy($endpoint, $endpointCopy);
    file_put_contents($directory . '/db.php', <<<'PHP'
<?php
declare(strict_types=1);
function getDb(): PDO
{
    return new class extends PDO { public function __construct() {} };
}
PHP);
    file_put_contents($libraryDirectory . '/CombatBootstrap.php', <<<'PHP'
<?php
declare(strict_types=1);
final class Task8EndpointService
{
    public function usePotion(int $userId, int $characterId, string $requestToken): array
    {
        file_put_contents((string) getenv('ASCII_QUEST_TASK8_CALL_FILE'), json_encode([
            'user_id' => $userId,
            'character_id' => $characterId,
            'request_token' => $requestToken,
        ]));
        if ($userId !== 7 || $characterId !== 42) {
            throw new OutOfBoundsException('Private Potion ownership detail.');
        }
        $failure = getenv('ASCII_QUEST_TASK8_FAILURE');
        if ($failure === 'domain') {
            throw new DomainException('Private Potion availability detail.');
        }
        if ($failure === 'runtime') {
            throw new RuntimeException('Private Potion database detail.');
        }

        return ['encounter_id' => 91, 'potion' => ['charges_remaining' => 0]];
    }
}
final class CombatBootstrap
{
    public static function service(PDO $pdo): Task8EndpointService
    {
        return new Task8EndpointService();
    }
}
PHP);

    $sessionId = 'task8' . bin2hex(random_bytes(8));
    $runnerSource = '<?php' . "\n" .
        'ini_set(\'session.save_path\', ' . var_export($sessionDirectory, true) . ');' . "\n" .
        'session_id(' . var_export($sessionId, true) . ');' . "\n" .
        'session_start();' . "\n" .
        '$_SESSION = ' . var_export($session, true) . ';' . "\n" .
        'session_write_close();' . "\n" .
        '$_SERVER[\'REQUEST_METHOD\'] = ' . var_export($method, true) . ';' . "\n" .
        'register_shutdown_function(static function (): void {' . "\n" .
        '    echo "\\n__TASK8_STATUS__" . http_response_code();' . "\n" .
        '});' . "\n" .
        'require ' . var_export($endpointCopy, true) . ';' . "\n";
    file_put_contents($runner, $runnerSource);

    $process = proc_open(
        [PHP_BINARY, $runner],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        array_replace(getenv(), [
            'ASCII_QUEST_TASK8_CALL_FILE' => $callFile,
            'ASCII_QUEST_TASK8_FAILURE' => $serviceFailure ?? '',
        ]),
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to execute Potion endpoint fixture.');
    }
    fwrite($pipes[0], $rawBody);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $status = 0;
    $body = $stdout;
    if (preg_match('/\n__TASK8_STATUS__(\d+)\z/', $stdout, $matches) === 1) {
        $status = (int) $matches[1];
        $body = substr($stdout, 0, -strlen($matches[0]));
    }
    $payload = json_decode($body, true);
    $call = is_file($callFile)
        ? json_decode((string) file_get_contents($callFile), true)
        : null;

    foreach (glob($sessionDirectory . '/*') ?: [] as $sessionFile) {
        unlink($sessionFile);
    }
    foreach ([$runner, $callFile, $endpointCopy, $libraryDirectory . '/CombatBootstrap.php', $directory . '/db.php'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($sessionDirectory);
    rmdir($libraryDirectory);
    rmdir($directory);

    return [$status, $payload, $call, $exitCode, $body, $stderr];
}

return [
    'Task 8 schema and current projection already preserve safe snapshotted potion state' => function (): void {
        $migration = file_get_contents(__DIR__ . '/../database/migrations/003_combat_foundation.sql');
        if (!is_string($migration)) {
            throw new RuntimeException('Migration 003 could not be read.');
        }
        foreach ([
            'potion_key VARCHAR(64)',
            'potion_charge_allowance SMALLINT UNSIGNED',
            'potion_charges_remaining SMALLINT UNSIGNED',
            'healing_applied INT UNSIGNED NULL',
            "action_kind IN ('attack', 'weapon', 'skill', 'block', 'potion')",
            'UNIQUE KEY uq_combat_actions_request (encounter_id, request_token)',
        ] as $required) {
            assertSameValue(true, str_contains($migration, $required), $required . ' schema support.');
        }

        $pdo = new FakeCombatPdo();
        task8Encounter($pdo);
        $projector = new CombatStateProjector(
            new CombatRepository($pdo),
            CombatDefinitionRegistry::fromDefaultConfig(),
        );
        $first = $projector->project($pdo->characters[42], $pdo->encounters[91]);
        assertSameValue([
            'key',
            'charge_allowance',
            'charges_remaining',
        ], array_keys($first['potion']), 'Potion projection keeps its exact safe allowlist.');
        assertSameValue([
            'key' => 'prototype_health_potion',
            'charge_allowance' => 1,
            'charges_remaining' => 1,
        ], $first['potion'], 'Encounter snapshot is projected authoritatively.');

        $pdo->encounters[91]['potion_charges_remaining'] = 0;
        $second = $projector->project($pdo->characters[42], $pdo->encounters[91]);
        assertSameValue(0, $second['potion']['charges_remaining'], 'Spent charges stay spent on refresh.');
        $serialized = json_encode($second['potion']);
        foreach (['prototype_healing', 'healing_applied', 'request_token', 'current_hp', 'maximum_life'] as $hidden) {
            assertSameValue(false, str_contains((string) $serialized, $hidden), $hidden . ' remains private.');
        }
    },

    'Potion repository persists one locked charge and one resolved command' => function (): void {
        if (!method_exists(CombatRepository::class, 'consumeLockedEncounterPotionCharge')) {
            throw new RuntimeException('CombatRepository::consumeLockedEncounterPotionCharge() is missing.');
        }
        if (!method_exists(CombatRepository::class, 'createResolvedPotionCommand')) {
            throw new RuntimeException('CombatRepository::createResolvedPotionCommand() is missing.');
        }

        $pdo = new FakeCombatPdo();
        task8Encounter($pdo);
        $repository = new CombatRepository($pdo);
        $repository->beginTransaction();
        $repository->lockOwnedCharacter(7, 42);
        $repository->lockOwnedAccountActiveEncounter(7, 42);
        assertSameValue(true, $repository->consumeLockedEncounterPotionCharge(91, 1, 4), 'One charge CAS succeeds.');
        assertSameValue(0, $pdo->encounters[91]['potion_charges_remaining'], 'One charge is consumed.');
        assertSameValue(1, $pdo->encounters[91]['potion_charge_allowance'], 'Allowance is immutable.');
        assertSameValue(false, $repository->consumeLockedEncounterPotionCharge(91, 1, 4), 'Stale CAS rejects.');
        task8AssertRejected(
            fn (): bool => $repository->consumeLockedEncounterPotionCharge(91, 0, 5),
            'Zero remaining charge cannot be consumed.',
        );

        $token = '81818181-8181-4181-8181-818181818181';
        $command = $repository->createResolvedPotionCommand(
            91,
            'prototype_health_potion',
            $token,
            3000,
            37,
        );
        assertSameValue([
            'actor' => 'player',
            'action_kind' => 'potion',
            'definition_key' => 'prototype_health_potion',
            'request_token' => $token,
            'active_slot' => null,
            'state' => 'resolved',
            'started_timeline_ms' => 3000,
            'resolves_timeline_ms' => 3000,
            'cooldown_ready_timeline_ms' => null,
            'completed_timeline_ms' => 3000,
            'healing_applied' => 37,
        ], array_intersect_key($command, array_flip([
            'actor', 'action_kind', 'definition_key', 'request_token', 'active_slot',
            'state', 'started_timeline_ms', 'resolves_timeline_ms',
            'completed_timeline_ms', 'cooldown_ready_timeline_ms', 'healing_applied',
        ])), 'Resolved potion command uses the approved schema shape.');
        foreach ([
            'snapshot_weapon_key', 'snapshot_damage_type', 'snapshot_base_damage',
            'snapshot_accuracy', 'snapshot_critical_chance',
            'snapshot_critical_damage', 'block_token',
            'block_expires_timeline_ms', 'block_attempted_timeline_ms',
            'block_prompt_x', 'block_prompt_y', 'resolved_damage',
            'prevented_damage',
        ] as $emptyField) {
            assertSameValue(null, $command[$emptyField] ?? null, $emptyField . ' is unused by Potion.');
        }
        $replayed = $repository->lockActionByRequestToken(91, $token);
        ksort($command);
        if (is_array($replayed)) {
            ksort($replayed);
        }
        assertSameValue($command, $replayed, 'Request replay finds the same command.');
        $repository->rollBack();
    },

    'Valid Potion use is instant zero Action and preserves Mana and pending weapon state' => function (): void {
        $pdo = new FakeCombatPdo();
        task8Encounter($pdo, ['player_actions_remaining' => 0]);
        $pdo->characters[42]['current_hp'] = 100;
        $pdo->actions[61] = task8PendingWeapon();
        $state = task8UsePotion(task8Service($pdo));

        assertSameValue(150, $pdo->characters[42]['current_hp'], 'Configured 50 HP is applied.');
        assertSameValue(80, $pdo->characters[42]['current_mana'], 'Mana is unchanged.');
        assertSameValue(0, $pdo->encounters[91]['potion_charges_remaining'], 'One charge is consumed.');
        assertSameValue(1, $pdo->encounters[91]['potion_charge_allowance'], 'Allowance remains snapshotted.');
        assertSameValue(0, $pdo->encounters[91]['player_actions_remaining'], 'Potion consumes zero Actions.');
        assertSameValue('pending', $pdo->actions[61]['state'], 'Pending weapon remains pending.');
        assertSameValue(1, $pdo->actions[61]['active_slot'], 'Pending weapon keeps its active slot.');

        $commands = task8PotionActions($pdo);
        assertSameValue(1, count($commands), 'One resolved Potion command is created.');
        assertSameValue(null, $commands[0]['active_slot'], 'Potion never occupies an active slot.');
        assertSameValue('resolved', $commands[0]['state'], 'Potion resolves instantly.');
        assertSameValue([3000, 3000, 3000], [
            $commands[0]['started_timeline_ms'],
            $commands[0]['resolves_timeline_ms'],
            $commands[0]['completed_timeline_ms'],
        ], 'Potion uses one authoritative logical position.');
        assertSameValue(50, $commands[0]['healing_applied'], 'Actual healing is persisted.');
        assertSameValue('prototype_health_potion', $commands[0]['definition_key'], 'Encounter potion definition is persisted.');
        assertSameValue('88888888-8888-4888-8888-888888888888', $commands[0]['request_token'], 'Potion command owns the client UUID.');
        assertSameValue(1, count($pdo->events), 'One server Battle Info event is appended.');
        assertSameValue('potion_used', $pdo->events[1]['event_type'], 'Potion event type is server-authored.');
        assertSameValue('You recover 50 HP with Health Potion.', $pdo->events[1]['message'], 'Potion event reports actual healing.');
        assertSameValue(0, $state['potion']['charges_remaining'], 'Projected remaining charge is authoritative.');
    },

    'Potion healing uses CharacterStats Maximum Life and full HP rejects without side effects' => function (): void {
        $partial = new FakeCombatPdo();
        task8Encounter($partial);
        $partial->characters[42]['current_hp'] = 180;
        task8UsePotion(task8Service($partial), '82828282-8282-4282-8282-828282828282');
        assertSameValue(200, $partial->characters[42]['current_hp'], 'Partial heal caps at CharacterStats Maximum Life.');
        assertSameValue(20, task8PotionActions($partial)[0]['healing_applied'], 'Actual partial heal is persisted.');

        $derived = new FakeCombatPdo();
        task8Encounter($derived);
        $derived->characters[42]['vitality'] = 11;
        $derived->characters[42]['current_hp'] = 180;
        task8UsePotion(task8Service($derived), '83838383-8383-4383-8383-838383838383');
        assertSameValue(210, $derived->characters[42]['current_hp'], 'Changed Vitality uses CharacterStats Maximum Life.');
        assertSameValue(30, task8PotionActions($derived)[0]['healing_applied'], 'CharacterStats headroom controls actual heal.');

        $full = new FakeCombatPdo();
        task8Encounter($full, [
            'timeline_elapsed_ms' => 3000,
            'next_enemy_decision_timeline_ms' => 10000,
            'enemy_actions_remaining' => 0,
        ]);
        $full->characters[42]['current_hp'] = 200;
        $beforeMana = $full->characters[42]['current_mana'];
        task8AssertRejected(
            fn (): array => task8UsePotion(
                task8Service($full, task8Clock('2026-09-01 12:00:01.000000')),
                '84848484-8484-4484-8484-848484848484',
            ),
            'Full HP Potion use rejects.',
        );
        assertSameValue(4000, $full->encounters[91]['timeline_elapsed_ms'], 'Legitimate synchronization remains committed on domain rejection.');
        assertSameValue(1, $full->encounters[91]['potion_charges_remaining'], 'Full HP spends no charge.');
        assertSameValue([], task8PotionActions($full), 'Full HP creates no command.');
        assertSameValue([], $full->events, 'Full HP creates no event.');
        assertSameValue($beforeMana, $full->characters[42]['current_mana'], 'Full HP rejection leaves Mana unchanged.');
    },

    'Potion UUID replay and configuration reload cannot replenish or duplicate use' => function (): void {
        $pdo = new FakeCombatPdo();
        task8Encounter($pdo);
        $pdo->characters[42]['current_hp'] = 100;
        $token = '85858585-8585-4585-8585-858585858585';
        $service = task8Service($pdo);
        task8UsePotion($service, $token);
        $afterFirst = [
            'hp' => $pdo->characters[42]['current_hp'],
            'mana' => $pdo->characters[42]['current_mana'],
            'charges' => $pdo->encounters[91]['potion_charges_remaining'],
            'allowance' => $pdo->encounters[91]['potion_charge_allowance'],
            'player_actions' => $pdo->encounters[91]['player_actions_remaining'],
            $pdo->actions,
            $pdo->events,
        ];
        task8UsePotion($service, $token);
        assertSameValue($afterFirst, [
            'hp' => $pdo->characters[42]['current_hp'],
            'mana' => $pdo->characters[42]['current_mana'],
            'charges' => $pdo->encounters[91]['potion_charges_remaining'],
            'allowance' => $pdo->encounters[91]['potion_charge_allowance'],
            'player_actions' => $pdo->encounters[91]['player_actions_remaining'],
            $pdo->actions,
            $pdo->events,
        ], 'Exact replay heals decrements inserts and logs only once.');

        $config = require __DIR__ . '/../ascii-quest/config/combat.php';
        $config['prototype_balance']['potions']['prototype_health_potion']['charges'] = 9;
        $config['prototype_balance']['potions']['prototype_health_potion']['prototype_healing'] = 99;
        $reprovisioned = task8Service(
            $pdo,
            task8Clock(),
            new CombatDefinitionRegistry($config),
        );
        $state = $reprovisioned->state(7, 42);
        assertSameValue(1, $state['potion']['charge_allowance'], 'Config reload cannot change snapshotted allowance.');
        assertSameValue(0, $state['potion']['charges_remaining'], 'Config reload cannot restore spent charges.');
        task8AssertRejected(
            fn (): array => task8UsePotion(
                $reprovisioned,
                '86868686-8686-4686-8686-868686868686',
            ),
            'Different UUID rejects after charge exhaustion.',
        );
        assertSameValue($afterFirst['hp'], $pdo->characters[42]['current_hp'], 'Zero-charge rejection does not heal.');
        assertSameValue($afterFirst['mana'], $pdo->characters[42]['current_mana'], 'Zero-charge rejection does not change Mana.');
        assertSameValue($afterFirst['player_actions'], $pdo->encounters[91]['player_actions_remaining'], 'Zero-charge rejection does not consume Action.');
        assertSameValue(1, count(task8PotionActions($pdo)), 'Zero-charge rejection creates no command.');
        assertSameValue(1, count($pdo->events), 'Zero-charge rejection creates no event.');
    },

    'Potion validates post-synchronization HP and cannot run outside eligible active combat' => function (): void {
        $pdo = new FakeCombatPdo();
        task8Encounter($pdo, [
            'timeline_elapsed_ms' => 1000,
            'last_synchronized_at' => '2026-09-01 12:00:00.000000',
            'next_enemy_decision_timeline_ms' => 10000,
            'enemy_actions_remaining' => 0,
        ]);
        $pdo->characters[42]['current_hp'] = 180;
        $pdo->actions[71] = task7EnemyAction([
            'encounter_id' => 91,
            'started_timeline_ms' => 0,
            'resolves_timeline_ms' => 2000,
            'block_expires_timeline_ms' => 2000,
            'block_attempted_timeline_ms' => null,
        ]);
        $service = CombatBootstrap::serviceForRepository(
            new CombatRepository($pdo),
            task8Clock('2026-09-01 12:00:01.000000'),
            new Task5MutableEquipmentProvider(),
            new Task7SequenceRandomSource(),
        );
        task8UsePotion($service, '87878787-8787-4787-8787-878787878787');
        assertSameValue(200, $pdo->characters[42]['current_hp'], 'Potion caps the post-hit HP at Maximum Life.');
        assertSameValue(44, task8PotionActions($pdo)[0]['healing_applied'], 'Heal uses post-synchronization HP rather than stale 180 HP.');

        $outside = new FakeCombatPdo();
        task8AssertRejected(
            fn (): array => task8UsePotion(task8Service($outside)),
            'Potion requires an active encounter.',
        );

        $zero = new FakeCombatPdo();
        task8Encounter($zero);
        $zero->characters[42]['current_hp'] = 0;
        task8AssertRejected(
            fn (): array => task8UsePotion(task8Service($zero)),
            'Zero-HP Champion cannot be resurrected by Potion.',
        );
        assertSameValue(0, $zero->characters[42]['current_hp'], 'Zero HP remains zero.');
        assertSameValue(1, $zero->encounters[91]['potion_charges_remaining'], 'Resurrection attempt spends no charge.');

        $dead = new FakeCombatPdo();
        task8Encounter($dead);
        $dead->characters[42]['life_state'] = 'dead';
        task8AssertRejected(
            fn (): array => task8UsePotion(task8Service($dead)),
            'Dead Champion remains unavailable through existing guard.',
        );
        assertSameValue('dead', $dead->characters[42]['life_state'], 'Potion does not alter life state.');
    },

    'Potion transaction rolls back HP charge command and event failures completely' => function (): void {
        if (!method_exists(CombatService::class, 'usePotion')) {
            throw new RuntimeException('CombatService::usePotion() is missing; rollback paths cannot be exercised.');
        }

        foreach ([
            'hp' => 'failCharacterHpUpdate',
            'charge' => 'failPotionChargeUpdate',
            'command' => 'failActionInsert',
            'event' => 'failEventInsert',
            'projection' => 'failActionsRead',
        ] as $label => $flag) {
            $pdo = new FakeCombatPdo();
            task8Encounter($pdo);
            $pdo->characters[42]['current_hp'] = 100;
            $before = [
                $pdo->characters,
                $pdo->encounters,
                $pdo->actions,
                $pdo->events,
            ];
            $pdo->{$flag} = true;
            task8AssertRejected(
                fn (): array => task8UsePotion(
                    task8Service($pdo),
                    '89898989-8989-4989-8989-898989898989',
                ),
                $label . ' persistence failure rejects.',
            );
            assertSameValue($before, [
                $pdo->characters,
                $pdo->encounters,
                $pdo->actions,
                $pdo->events,
            ], $label . ' failure leaves no partial potion transaction.');
        }
    },

    'Combat Potion endpoint enforces POST session CSRF UUID and exact intent keys' => function (): void {
        $session = ['user_id' => 7, 'character_id' => 42, 'csrf_token' => 'session-token'];
        $valid = [
            'csrf_token' => 'session-token',
            'request_token' => '90909090-9090-4090-8090-909090909090',
        ];
        foreach ([
            'method' => [$session, 'GET', json_encode($valid), 405],
            'session' => [[], 'POST', json_encode($valid), 401],
            'malformed' => [$session, 'POST', '{', 400],
            'non-object' => [$session, 'POST', '[]', 400],
            'missing' => [$session, 'POST', json_encode(['csrf_token' => 'session-token']), 400],
            'extra' => [$session, 'POST', json_encode($valid + ['potion_key' => 'prototype_health_potion']), 400],
            'csrf' => [$session, 'POST', json_encode(array_replace($valid, ['csrf_token' => 'wrong'])), 403],
            'uuid' => [$session, 'POST', json_encode(array_replace($valid, ['request_token' => 'not-a-uuid'])), 422],
        ] as $label => [$caseSession, $method, $body, $expected]) {
            [$status, $payload, $call] = task8RunPotionEndpoint($caseSession, $method, (string) $body);
            assertSameValue($expected, $status, $label . ' status.');
            assertSameValue(false, $payload['success'] ?? null, $label . ' safe response.');
            assertSameValue(null, $call, $label . ' never reaches service.');
        }

        [$status, , $call] = task8RunPotionEndpoint($session, 'POST', (string) json_encode($valid));
        assertSameValue(200, $status, 'Valid Potion intent status.');
        assertSameValue([
            'user_id' => 7,
            'character_id' => 42,
            'request_token' => '90909090-9090-4090-8090-909090909090',
        ], $call, 'Endpoint derives Champion identity from session and passes only UUID intent.');
    },

    'Combat Potion endpoint rejects authoritative values and hides internal failures' => function (): void {
        $session = ['user_id' => 7, 'character_id' => 42, 'csrf_token' => 'session-token'];
        $base = [
            'csrf_token' => 'session-token',
            'request_token' => '91919191-9191-4191-8191-919191919191',
        ];
        foreach ([
            'user_id', 'character_id', 'potion_key', 'current_hp', 'new_hp',
            'maximum_life', 'healing', 'healing_applied', 'prototype_healing',
            'charges', 'charge_allowance', 'charges_remaining', 'current_mana',
            'mana', 'player_actions_remaining', 'timeline_elapsed_ms',
            'completed_timeline_ms', 'cooldown_ready_timeline_ms', 'outcome',
        ] as $extra) {
            [$status, , $call] = task8RunPotionEndpoint(
                $session,
                'POST',
                (string) json_encode($base + [$extra => 999]),
            );
            assertSameValue(400, $status, $extra . ' extra field status.');
            assertSameValue(null, $call, $extra . ' never reaches service.');
        }

        [$domainStatus, , , , $domainBody] = task8RunPotionEndpoint(
            $session,
            'POST',
            (string) json_encode($base),
            'domain',
        );
        assertSameValue(422, $domainStatus, 'Domain error status.');
        assertSameValue(false, str_contains($domainBody, 'Private Potion availability detail.'), 'Domain detail stays private.');

        [$runtimeStatus, , , , $runtimeBody] = task8RunPotionEndpoint(
            $session,
            'POST',
            (string) json_encode($base),
            'runtime',
        );
        assertSameValue(500, $runtimeStatus, 'Internal error status.');
        assertSameValue(false, str_contains($runtimeBody, 'Private Potion database detail.'), 'Internal detail stays private.');
    },
];

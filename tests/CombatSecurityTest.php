<?php
declare(strict_types=1);

$combatAccessGuardPath = __DIR__ . '/../ascii-quest/lib/CombatAccessGuard.php';
$combatBootstrapPath = __DIR__ . '/../ascii-quest/lib/CombatBootstrap.php';
foreach ([$combatAccessGuardPath, $combatBootstrapPath] as $path) {
    if (is_file($path)) {
        require_once $path;
    }
}

final class Task3GuardRepository
{
    public array $characters = [
        42 => ['id' => 42, 'user_id' => 7, 'life_state' => 'alive'],
        43 => ['id' => 43, 'user_id' => 7, 'life_state' => 'alive'],
        84 => ['id' => 84, 'user_id' => 8, 'life_state' => 'alive'],
    ];
    public array $encounters = [];
    public int $writes = 0;
    public array $lockOrder = [];
    public bool $transactionActive = false;
    public bool $injectEncounterBeforeAccountLock = false;

    public function findOwnedCharacter(int $userId, int $characterId): ?array
    {
        $character = $this->characters[$characterId] ?? null;

        return $character !== null && $character['user_id'] === $userId ? $character : null;
    }

    public function findActiveEncounter(int $characterId): ?array
    {
        foreach ($this->encounters as $encounter) {
            if ($encounter['character_id'] === $characterId && $encounter['active_slot'] === 1) {
                return $encounter;
            }
        }

        return null;
    }

    public function findOwnedActiveEncounterForUser(int $userId): ?array
    {
        foreach ($this->encounters as $encounter) {
            $character = $this->characters[$encounter['character_id']] ?? null;
            if ($character !== null && $character['user_id'] === $userId && $encounter['active_slot'] === 1) {
                return $encounter;
            }
        }

        return null;
    }

    public function beginTransaction(): void
    {
        if ($this->transactionActive) {
            throw new LogicException('Nested guard transaction.');
        }
        $this->transactionActive = true;
        $this->lockOrder = [];
    }

    public function lockOwnedCharacter(int $userId, int $characterId): ?array
    {
        $this->lockOrder[] = 'champion';

        return $this->findOwnedCharacter($userId, $characterId);
    }

    public function lockOwnedAccountActiveEncounter(int $userId, int $characterId): ?array
    {
        if (!$this->transactionActive || ($this->lockOrder[0] ?? null) !== 'champion') {
            throw new LogicException('Champion must be locked first.');
        }
        $this->lockOrder[] = 'account';
        $this->lockOrder[] = 'encounter';
        if ($this->injectEncounterBeforeAccountLock) {
            $this->encounters[] = [
                'id' => 99,
                'character_id' => 42,
                'status' => 'active',
                'active_slot' => 1,
            ];
            $this->injectEncounterBeforeAccountLock = false;
        }

        return $this->findOwnedActiveEncounterForUser($userId);
    }

    public function commit(): void
    {
        if (!$this->transactionActive) {
            throw new LogicException('No guard transaction.');
        }
        $this->transactionActive = false;
    }

    public function rollBack(): void
    {
        $this->transactionActive = false;
    }
}

function task3CombatGuard(?Task3GuardRepository $repository = null): array
{
    if (!class_exists('CombatAccessGuard')) {
        throw new RuntimeException('CombatAccessGuard must exist.');
    }
    $repository ??= new Task3GuardRepository();

    return [new CombatAccessGuard($repository), $repository];
}

function assertTask3GuardRejected(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (DomainException | OutOfBoundsException | RuntimeException) {
        return;
    }

    throw new RuntimeException($message . ' Expected rejection.');
}

function task4RunCombatStateEndpoint(
    array $session,
    string $method = 'GET',
    array $get = [],
    array $post = [],
    bool $failBootstrapInitialization = false,
    ?string $serviceDomainExceptionMessage = null,
): array {
    $endpoint = __DIR__ . '/../ascii-quest/combat_state.php';
    if (!is_file($endpoint)) {
        throw new RuntimeException('combat_state.php must exist.');
    }

    $fixtureDirectory = sys_get_temp_dir() . '/ascii-quest-task4-' . bin2hex(random_bytes(8));
    $libraryDirectory = $fixtureDirectory . '/lib';
    $sessionDirectory = $fixtureDirectory . '/sessions';
    if (!mkdir($libraryDirectory, 0700, true) || !mkdir($sessionDirectory, 0700, true)) {
        throw new RuntimeException('Unable to create combat endpoint fixture.');
    }

    $endpointCopy = $fixtureDirectory . '/combat_state.php';
    $callFile = $fixtureDirectory . '/service-call.json';
    $runner = $fixtureDirectory . '/run.php';
    copy($endpoint, $endpointCopy);
    file_put_contents($fixtureDirectory . '/db.php', <<<'PHP'
<?php
declare(strict_types=1);

function getDb(): PDO
{
    return new class extends PDO {
        public function __construct()
        {
        }
    };
}
PHP);
    file_put_contents($libraryDirectory . '/CombatBootstrap.php', <<<'PHP'
<?php
declare(strict_types=1);

final class Task4EndpointService
{
    public function state(int $userId, int $characterId): array
    {
        $allowed = $userId === 7 && $characterId === 42;
        file_put_contents((string) getenv('ASCII_QUEST_TASK4_CALL_FILE'), json_encode([
            'user_id' => $userId,
            'character_id' => $characterId,
            'advanced' => $allowed,
            'exploration_mutations' => 0,
        ]));
        if (!$allowed) {
            throw new OutOfBoundsException('Champion not found.');
        }
        $domainMessage = getenv('ASCII_QUEST_TASK4_DOMAIN_MESSAGE');
        if (is_string($domainMessage) && $domainMessage !== '') {
            throw new DomainException($domainMessage);
        }

        return [
            'encounter_id' => 91,
            'status' => 'active',
            'server_observed_at' => '2026-09-01T12:00:30+00:00',
            'timeline' => ['elapsed_ms' => 8000],
            'version' => 5,
            'turn' => [
                'number' => 1,
                'started_timeline_ms' => 0,
                'player_actions_remaining' => 0,
                'enemy_actions_remaining' => 1,
            ],
            'champion' => ['id' => 42, 'current_hp' => 145, 'current_mana' => 80],
            'enemy' => [
                'key' => 'cave_brute',
                'name' => 'Cave Brute',
                'glyph' => 'B',
                'current_hp' => 73,
                'maximum_hp' => 120,
            ],
            'player_actions' => [],
            'active_effects' => [],
            'reaction_prompt' => null,
            'potion' => [
                'key' => 'prototype_health_potion',
                'charge_allowance' => 1,
                'charges_remaining' => 0,
            ],
            'battle_events' => [],
            'loot_phase' => null,
        ];
    }
}

final class CombatBootstrap
{
    public static function service(PDO $pdo): Task4EndpointService
    {
        return new Task4EndpointService();
    }
}
PHP);
    if ($failBootstrapInitialization) {
        file_put_contents($libraryDirectory . '/CombatBootstrap.php', <<<'PHP'
<?php
declare(strict_types=1);

throw new RuntimeException('private bootstrap initialization detail');
PHP);
    }

    $sessionId = 'task4' . bin2hex(random_bytes(8));
    $runnerSource = '<?php' . "\n" .
        'ini_set(\'session.save_path\', ' . var_export($sessionDirectory, true) . ');' . "\n" .
        'session_id(' . var_export($sessionId, true) . ');' . "\n" .
        'session_start();' . "\n" .
        '$_SESSION = ' . var_export($session, true) . ';' . "\n" .
        'session_write_close();' . "\n" .
        '$_SERVER[\'REQUEST_METHOD\'] = ' . var_export($method, true) . ';' . "\n" .
        '$_GET = ' . var_export($get, true) . ';' . "\n" .
        '$_POST = ' . var_export($post, true) . ';' . "\n" .
        'register_shutdown_function(static function (): void {' . "\n" .
        '    echo "\\n__TASK4_STATUS__" . http_response_code();' . "\n" .
        '});' . "\n" .
        'require ' . var_export($endpointCopy, true) . ';' . "\n";
    file_put_contents($runner, $runnerSource);

    $process = proc_open(
        [PHP_BINARY, $runner],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        null,
        array_replace(getenv(), [
            'ASCII_QUEST_TASK4_CALL_FILE' => $callFile,
            'ASCII_QUEST_TASK4_DOMAIN_MESSAGE' => $serviceDomainExceptionMessage ?? '',
        ]),
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to execute combat endpoint fixture.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $status = 0;
    $body = $stdout;
    if (preg_match('/\n__TASK4_STATUS__(\d+)\z/', $stdout, $matches) === 1) {
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
    foreach ([$runner, $callFile, $endpointCopy, $libraryDirectory . '/CombatBootstrap.php', $fixtureDirectory . '/db.php'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($sessionDirectory);
    rmdir($libraryDirectory);
    rmdir($fixtureDirectory);

    return [$status, $payload, $call, $exitCode, $body, $stderr];
}

return [
    'Combat state endpoint requires an authenticated selected Champion session' => function (): void {
        [$status, $payload, $call] = task4RunCombatStateEndpoint([]);

        assertSameValue(401, $status, 'Unauthenticated status.');
        assertSameValue(false, $payload['success'] ?? null, 'Unauthenticated response.');
        assertSameValue(null, $call, 'Unauthenticated request never reaches state synchronization.');

        [$status, $payload, $call] = task4RunCombatStateEndpoint(['user_id' => 7]);
        assertSameValue(401, $status, 'Missing selected Champion status.');
        assertSameValue(false, $payload['success'] ?? null, 'Missing selected Champion response.');
        assertSameValue(null, $call, 'Missing selection never reaches state synchronization.');
    },

    'Combat state endpoint accepts GET only' => function (): void {
        [$status, $payload, $call] = task4RunCombatStateEndpoint(
            ['user_id' => 7, 'character_id' => 42],
            'POST',
        );

        assertSameValue(405, $status, 'Wrong-method status.');
        assertSameValue(false, $payload['success'] ?? null, 'Wrong-method response.');
        assertSameValue(null, $call, 'Wrong method never reaches state synchronization.');
    },

    'Combat state endpoint trusts only session identity and server synchronization time' => function (): void {
        [$status, $payload, $call] = task4RunCombatStateEndpoint(
            ['user_id' => 7, 'character_id' => 42],
            'GET',
            ['character_id' => '84', 'server_now' => '2099-01-01T00:00:00Z'],
            ['character_id' => '84', 'timestamp' => '2099-01-01T00:00:00Z'],
        );

        assertSameValue(200, $status, 'Owned state status.');
        assertSameValue(7, $call['user_id'] ?? null, 'Session user identity.');
        assertSameValue(42, $call['character_id'] ?? null, 'Session Champion identity.');
        assertSameValue(true, $call['advanced'] ?? null, 'Owned state invokes synchronization.');
        assertSameValue(0, $call['exploration_mutations'] ?? null, 'State request does not mutate exploration.');
        assertSameValue(42, $payload['champion']['id'] ?? null, 'Selected Champion projection.');
        assertSameValue(8000, $payload['timeline']['elapsed_ms'] ?? null, 'Server-produced timeline.');
        assertSameValue('2026-09-01T12:00:30+00:00', $payload['server_observed_at'] ?? null, 'Approved public server time name.');
        assertSameValue(false, array_key_exists('server_now', $payload), 'No alternate public server time field.');
        assertSameValue(false, array_key_exists('next_enemy_decision_timeline_ms', $payload), 'Enemy decision cursor hidden.');
        assertSameValue(false, array_key_exists('cooldowns', $payload['enemy']), 'Enemy cooldowns hidden.');
    },

    'Another users Champion cannot be read or synchronized by combat state endpoint' => function (): void {
        [$status, $payload, $call] = task4RunCombatStateEndpoint([
            'user_id' => 7,
            'character_id' => 84,
        ]);

        assertSameValue(404, $status, 'Wrong-owner status.');
        assertSameValue(false, $payload['success'] ?? null, 'Wrong-owner response.');
        assertSameValue(false, $call['advanced'] ?? null, 'Failed ownership cannot advance encounter time.');
        assertSameValue(84, $call['character_id'] ?? null, 'Only the selected identity was attempted.');
        assertSameValue(false, array_key_exists('timeline', $payload), 'Failed ownership exposes no state.');
    },

    'Combat state dependency initialization failure returns only safe JSON' => function (): void {
        [$status, $payload, $call, $exitCode, $body] = task4RunCombatStateEndpoint(
            ['user_id' => 7, 'character_id' => 42],
            'GET',
            [],
            [],
            true,
        );

        assertSameValue(0, $exitCode, 'Initialization failure is handled by the endpoint.');
        assertSameValue(500, $status, 'Initialization failure HTTP status.');
        assertSameValue(false, $payload['success'] ?? null, 'Initialization failure response.');
        assertSameValue(
            'Unable to synchronize combat. Please try again.',
            $payload['message'] ?? null,
            'Generic initialization failure message.',
        );
        assertSameValue(false, str_contains($body, 'private bootstrap'), 'Private exception detail is not exposed.');
        assertSameValue(null, $call, 'Failed initialization never invokes state synchronization.');
    },

    'Combat state endpoint logs but never exposes internal domain failures' => function (): void {
        $privateMessage = 'Stored combat Turn is behind private logical timeline state.';
        [$status, $payload, $call, $exitCode, $body, $stderr] = task4RunCombatStateEndpoint(
            ['user_id' => 7, 'character_id' => 42],
            'GET',
            [],
            [],
            false,
            $privateMessage,
        );

        assertSameValue(0, $exitCode, 'Domain failure is handled by the endpoint.');
        assertSameValue(422, $status, 'Domain failure HTTP status.');
        assertSameValue(false, $payload['success'] ?? null, 'Domain failure response.');
        assertSameValue(
            'Combat state is unavailable.',
            $payload['message'] ?? null,
            'Domain failure uses a generic public message.',
        );
        assertSameValue(false, str_contains($body, $privateMessage), 'Private domain detail is not exposed.');
        assertSameValue(true, str_contains($stderr, $privateMessage), 'Private domain detail is logged server-side.');
        assertSameValue(true, $call['advanced'] ?? null, 'Owned state reached authoritative synchronization.');
    },

    'Shared guard blocks every exploration mutation during unresolved combat' => function (): void {
        [$guard, $repository] = task3CombatGuard();
        $repository->encounters[] = [
            'id' => 10,
            'character_id' => 42,
            'status' => 'active',
            'active_slot' => 1,
        ];

        foreach ([
            CombatAccessGuard::MOVE,
            CombatAccessGuard::INTERACT,
            CombatAccessGuard::MAP_SYNC,
            CombatAccessGuard::WARP_UNLOCK,
            CombatAccessGuard::WARP_TRAVEL,
            CombatAccessGuard::STAT_ALLOCATE,
            CombatAccessGuard::COMBAT_ENTRY,
        ] as $operation) {
            assertTask3GuardRejected(
                fn (): array => $guard->assertAllowed($operation, 7, 42),
                $operation,
            );
        }

        assertSameValue(0, $repository->writes, 'Guard decisions never mutate combat.');
    },

    'Shared guard allows ordinary exploration with no encounter' => function (): void {
        [$guard] = task3CombatGuard();

        foreach ([
            CombatAccessGuard::MOVE,
            CombatAccessGuard::INTERACT,
            CombatAccessGuard::MAP_SYNC,
            CombatAccessGuard::WARP_UNLOCK,
            CombatAccessGuard::WARP_TRAVEL,
            CombatAccessGuard::STAT_ALLOCATE,
            CombatAccessGuard::COMBAT_ENTRY,
        ] as $operation) {
            $decision = $guard->assertAllowed($operation, 7, 42);
            assertSameValue(42, $decision['character']['id'], $operation . ' Champion.');
            assertSameValue(false, $decision['resume_combat'], $operation . ' exploration mode.');
        }
    },

    'A stale second-Champion session cannot load or mutate exploration during account combat' => function (): void {
        [$guard, $repository] = task3CombatGuard();
        $repository->encounters[] = [
            'id' => 10,
            'character_id' => 42,
            'status' => 'active',
            'active_slot' => 1,
        ];

        foreach ([
            CombatAccessGuard::MOVE,
            CombatAccessGuard::INTERACT,
            CombatAccessGuard::MAP_SYNC,
            CombatAccessGuard::WARP_UNLOCK,
            CombatAccessGuard::WARP_TRAVEL,
            CombatAccessGuard::STAT_ALLOCATE,
            CombatAccessGuard::COMBAT_ENTRY,
            CombatAccessGuard::GAME_LOAD,
        ] as $operation) {
            assertTask3GuardRejected(
                fn (): array => $guard->assertAllowed($operation, 7, 43),
                $operation . ' stale second Champion.',
            );
        }
    },

    'Atomic guard locks Champion then account encounter and holds through mutation' => function (): void {
        [$guard, $repository] = task3CombatGuard();

        $decision = $guard->beginAtomic(
            CombatAccessGuard::INTERACT,
            7,
            43,
        );

        assertSameValue(43, $decision['character']['id'], 'Locked requested Champion.');
        assertSameValue(true, $repository->transactionActive, 'Guard transaction remains active.');
        assertSameValue(['champion', 'account', 'encounter'], $repository->lockOrder, 'Atomic guard lock order.');
        $repository->writes++;
        $guard->commit();
        assertSameValue(false, $repository->transactionActive, 'Mutation and guard commit together.');
    },

    'Atomic guard rechecks an encounter that commits while the request waits' => function (): void {
        [$guard, $repository] = task3CombatGuard();
        $repository->injectEncounterBeforeAccountLock = true;

        assertTask3GuardRejected(
            fn (): array => $guard->beginAtomic(
                CombatAccessGuard::DELETE_CHARACTER,
                7,
                42,
            ),
            'Concurrent combat before deletion.',
        );

        assertSameValue(false, $repository->transactionActive, 'Rejected atomic guard rolls back.');
        assertSameValue(['champion', 'account', 'encounter'], $repository->lockOrder, 'Interleaved lock order.');
        assertSameValue(0, $repository->writes, 'No mutation after the recheck rejects.');
    },

    'Champion selection resumes the fighter and rejects every other Enter Dungeon' => function (): void {
        [$guard, $repository] = task3CombatGuard();
        $repository->encounters[] = [
            'id' => 10,
            'character_id' => 42,
            'status' => 'active',
            'active_slot' => 1,
        ];

        $resume = $guard->assertAllowed(CombatAccessGuard::SELECT_CHARACTER, 7, 42);
        assertSameValue(true, $resume['resume_combat'], 'Fighter resumes battle.');
        assertSameValue(10, $resume['active_encounter']['id'], 'Encounter remains unchanged.');
        assertTask3GuardRejected(
            fn (): array => $guard->assertAllowed(CombatAccessGuard::SELECT_CHARACTER, 7, 43),
            'Other Champion selection.',
        );
        assertTask3GuardRejected(
            fn (): array => $guard->assertAllowed(CombatAccessGuard::SELECT_CHARACTER, 7, 84),
            'Other user Champion selection.',
        );
    },

    'Fighting Champion deletion rejects and ordinary deletion returns after closure' => function (): void {
        [$guard, $repository] = task3CombatGuard();
        $repository->encounters[] = [
            'id' => 10,
            'character_id' => 42,
            'status' => 'active',
            'active_slot' => 1,
        ];

        assertTask3GuardRejected(
            fn (): array => $guard->assertAllowed(CombatAccessGuard::DELETE_CHARACTER, 7, 42),
            'Fighter deletion.',
        );
        $repository->encounters[0]['active_slot'] = null;
        $repository->encounters[0]['status'] = 'closed';

        $decision = $guard->assertAllowed(CombatAccessGuard::DELETE_CHARACTER, 7, 42);
        assertSameValue(false, $decision['resume_combat'], 'Closed encounter unlocks deletion.');
    },

    'Character creation remains allowed and does not inspect or mutate combat' => function (): void {
        [$guard, $repository] = task3CombatGuard();
        $repository->encounters[] = [
            'id' => 10,
            'character_id' => 42,
            'status' => 'active',
            'active_slot' => 1,
            'enemy_current_hp' => 73,
        ];
        $before = $repository->encounters;

        $decision = $guard->assertAllowed(CombatAccessGuard::CREATE_CHARACTER, 7, 0);

        assertSameValue(true, $decision['allowed'], 'Creation stays available.');
        assertSameValue($before, $repository->encounters, 'Creation does not touch the fight.');
        assertSameValue(0, $repository->writes, 'Creation guard performs no write.');
    },

    'Dead Champions and post-start movement reject server-side' => function (): void {
        [$guard, $repository] = task3CombatGuard();
        $repository->characters[42]['life_state'] = 'dead';
        assertTask3GuardRejected(
            fn (): array => $guard->assertAllowed(CombatAccessGuard::MOVE, 7, 42),
            'Dead movement.',
        );

        $repository->characters[42]['life_state'] = 'alive';
        $repository->encounters[] = [
            'id' => 10,
            'character_id' => 42,
            'status' => 'active',
            'active_slot' => 1,
        ];
        assertTask3GuardRejected(
            fn (): array => $guard->assertAllowed(CombatAccessGuard::MOVE, 7, 42),
            'Post-start movement.',
        );
    },

    'Game load never mutates expired overrides after its read-only combat decision' => function (): void {
        $game = file_get_contents(__DIR__ . '/../ascii-quest/game.php');
        $sync = file_get_contents(__DIR__ . '/../ascii-quest/sync_map_state.php');
        if ($game === false || $sync === false) {
            throw new RuntimeException('Game and map-sync routes must be readable.');
        }

        assertSameValue(
            false,
            str_contains($game, 'DELETE FROM character_map_overrides'),
            'Game load performs zero cleanup writes for a fighter or stale Champion after any interleaving.',
        );
        assertSameValue(
            true,
            str_contains($game, 'expires_at > NOW()'),
            'Game rendering excludes expired overrides without deleting them.',
        );
        assertSameValue(
            true,
            str_contains($sync, '->beginAtomic(') &&
                str_contains($sync, 'DELETE FROM character_map_overrides'),
            'Atomic map sync remains the sole expired-override cleanup route.',
        );
    },

    'State-changing route contracts delegate to the shared server guard' => function (): void {
        $routes = [
            'move_character.php' => 'MOVE',
            'interact.php' => 'INTERACT',
            'sync_map_state.php' => 'MAP_SYNC',
            'unlock_warp.php' => 'WARP_UNLOCK',
            'travel_warp.php' => 'WARP_TRAVEL',
            'allocate_stat.php' => 'STAT_ALLOCATE',
            'select_character.php' => 'SELECT_CHARACTER',
            'delete_character.php' => 'DELETE_CHARACTER',
        ];

        foreach ($routes as $file => $operation) {
            $source = file_get_contents(__DIR__ . '/../ascii-quest/' . $file);
            if ($source === false) {
                throw new RuntimeException('Unable to read route ' . $file);
            }
            assertSameValue(
                true,
                str_contains($source, 'CombatAccessGuard::' . $operation),
                $file . ' shared guard operation.',
            );
            if ($file !== 'move_character.php') {
                assertSameValue(
                    true,
                    str_contains($source, '->beginAtomic('),
                    $file . ' atomic guard boundary.',
                );
            }
        }

        $movement = file_get_contents(__DIR__ . '/../ascii-quest/move_character.php');
        assertSameValue(
            true,
            is_string($movement) && str_contains($movement, 'lockOwnedAccountActiveEncounter'),
            'Movement account encounter lock.',
        );
    },

    'Character selection POST and form both require CSRF' => function (): void {
        $endpoint = file_get_contents(__DIR__ . '/../ascii-quest/select_character.php');
        $markup = file_get_contents(__DIR__ . '/../ascii-quest/character_select.php');
        if ($endpoint === false || $markup === false) {
            throw new RuntimeException('Character selection files must be readable.');
        }

        assertSameValue(true, str_contains($endpoint, 'hash_equals'), 'Selection endpoint CSRF comparison.');
        assertSameValue(true, str_contains($markup, 'name="csrf_token"'), 'Selection form CSRF field.');
    },

    'Configured Cave Brute validates against the authoritative map and metadata' => function (): void {
        if (!class_exists('CombatBootstrap')) {
            throw new RuntimeException('CombatBootstrap must exist.');
        }
        require_once __DIR__ . '/../ascii-quest/map_loader.php';
        $map = loadMapFromFile('deep_cave.json');
        $encounter = CombatBootstrap::validatedEncounterForMap('deep_cave.json', $map);

        assertSameValue('deep_cave_01', $map['map_key'], 'Authoritative map key.');
        assertSameValue([20, 12, 'B'], [$encounter['x'], $encounter['y'], $encounter['glyph']], 'Validated enemy overlay.');
        assertSameValue('.', $map['layout'][12][20], 'Underlying JSON remains floor.');

        $invalid = $map;
        $invalid['objects'][] = ['type' => 'chest', 'x' => 20, 'y' => 12, 'glyph' => 'O'];
        assertTask3GuardRejected(
            fn (): array => CombatBootstrap::validatedEncounterForMap('deep_cave.json', $invalid),
            'Enemy metadata overlap.',
        );
    },
];

<?php
declare(strict_types=1);

$blockResolverPath = __DIR__ . '/../ascii-quest/lib/BlockResolver.php';
if (is_file($blockResolverPath)) {
    require_once $blockResolverPath;
}
$prototypeBlockResolverPath = __DIR__ . '/../ascii-quest/lib/PrototypeBlockResolver.php';
if (is_file($prototypeBlockResolverPath)) {
    require_once $prototypeBlockResolverPath;
}

final class Task7SequenceRandomSource implements CombatRandomSource
{
    public int $tokenCalls = 0;
    public int $integerCalls = 0;

    /** @param list<string> $tokens @param list<int> $integers */
    public function __construct(
        private array $tokens = [],
        private array $integers = [],
    ) {
    }

    public function token(int $bytes = 32): string
    {
        $this->tokenCalls++;
        if ($this->tokens === []) {
            throw new RuntimeException('No deterministic Block token remains.');
        }

        return (string) array_shift($this->tokens);
    }

    public function integer(int $minimum, int $maximum): int
    {
        $this->integerCalls++;
        if ($this->integers === []) {
            throw new RuntimeException('No deterministic Block coordinate remains.');
        }
        $value = (int) array_shift($this->integers);
        if ($value < $minimum || $value > $maximum) {
            throw new RuntimeException('Deterministic Block coordinate is outside requested bounds.');
        }

        return $value;
    }
}

if (interface_exists('BlockResolver')) {
    final class Task7RecordingBlockResolver implements BlockResolver
    {
        public int $calls = 0;
        public array $attempted = [];
        public array $defenses = [];

        public function resolve(
            array $incomingAction,
            array $currentDefense,
            bool $attempted,
        ): array {
            $this->calls++;
            $this->attempted[] = $attempted;
            $this->defenses[] = $currentDefense;

            return [
                'blocked' => $attempted,
                'incoming_damage' => (int) $incomingAction['snapshot_base_damage'],
                'prevented_damage' => $attempted ? 8 : 0,
                'applied_damage' => $attempted ? 10 : 18,
            ];
        }
    }
}

function task7AssertDomainRejected(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (InvalidArgumentException | DomainException | OutOfBoundsException) {
        return;
    }

    throw new RuntimeException($message . ' Expected rejection.');
}

function task7EnemyAction(array $overrides = []): array
{
    return array_replace(task6EnemyAction('fire_slam', [
        'id' => 71,
        'encounter_id' => 91,
        'started_timeline_ms' => 0,
        'resolves_timeline_ms' => 2000,
        'cooldown_ready_timeline_ms' => 6000,
        'block_token' => str_repeat('b', 64),
        'block_expires_timeline_ms' => 2000,
        'block_attempted_timeline_ms' => null,
        'block_prompt_x' => 0.250,
        'block_prompt_y' => 0.750,
    ]), $overrides);
}

function task7Project(FakeCombatPdo $pdo, array $encounterOverrides = []): array
{
    $encounter = task4Encounter(array_replace([
        'timeline_elapsed_ms' => 500,
        'next_enemy_decision_timeline_ms' => 2000,
        'enemy_ai_initialized_timeline_ms' => 0,
    ], $encounterOverrides));
    $pdo->encounters[91] = $encounter;

    return (new CombatStateProjector(
        new CombatRepository($pdo),
        CombatDefinitionRegistry::fromDefaultConfig(),
    ))->project($pdo->characters[42], $encounter);
}

function task7RunBlockEndpoint(
    array $session,
    string $method,
    string $rawBody,
    ?string $serviceFailure = null,
): array {
    $endpoint = __DIR__ . '/../ascii-quest/combat_block.php';
    if (!is_file($endpoint)) {
        throw new RuntimeException('combat_block.php is missing.');
    }

    $directory = sys_get_temp_dir() . '/ascii-quest-task7-' . bin2hex(random_bytes(8));
    $libraryDirectory = $directory . '/lib';
    $sessionDirectory = $directory . '/sessions';
    if (!mkdir($libraryDirectory, 0700, true) || !mkdir($sessionDirectory, 0700, true)) {
        throw new RuntimeException('Unable to create Block endpoint fixture.');
    }

    $endpointCopy = $directory . '/combat_block.php';
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
final class Task7EndpointService
{
    public function attemptBlock(
        int $userId,
        int $characterId,
        int $enemyActionId,
        string $blockToken,
        string $requestToken,
    ): array {
        file_put_contents((string) getenv('ASCII_QUEST_TASK7_CALL_FILE'), json_encode([
            'user_id' => $userId,
            'character_id' => $characterId,
            'enemy_action_id' => $enemyActionId,
            'block_token' => $blockToken,
            'request_token' => $requestToken,
        ]));
        if ($userId !== 7 || $characterId !== 42) {
            throw new OutOfBoundsException('Private ownership detail.');
        }
        $failure = getenv('ASCII_QUEST_TASK7_FAILURE');
        if ($failure === 'domain') {
            throw new DomainException('Private Block timing detail.');
        }
        if ($failure === 'runtime') {
            throw new RuntimeException('Private database detail.');
        }

        return ['encounter_id' => 91, 'reaction_prompt' => null];
    }
}
final class CombatBootstrap
{
    public static function service(PDO $pdo): Task7EndpointService
    {
        return new Task7EndpointService();
    }
}
PHP);

    $sessionId = 'task7' . bin2hex(random_bytes(8));
    $runnerSource = '<?php' . "\n" .
        'ini_set(\'session.save_path\', ' . var_export($sessionDirectory, true) . ');' . "\n" .
        'session_id(' . var_export($sessionId, true) . ');' . "\n" .
        'session_start();' . "\n" .
        '$_SESSION = ' . var_export($session, true) . ';' . "\n" .
        'session_write_close();' . "\n" .
        '$_SERVER[\'REQUEST_METHOD\'] = ' . var_export($method, true) . ';' . "\n" .
        'register_shutdown_function(static function (): void {' . "\n" .
        '    echo "\\n__TASK7_STATUS__" . http_response_code();' . "\n" .
        '});' . "\n" .
        'require ' . var_export($endpointCopy, true) . ';' . "\n";
    file_put_contents($runner, $runnerSource);

    $process = proc_open(
        [PHP_BINARY, $runner],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        array_replace(getenv(), [
            'ASCII_QUEST_TASK7_CALL_FILE' => $callFile,
            'ASCII_QUEST_TASK7_FAILURE' => $serviceFailure ?? '',
        ]),
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to execute Block endpoint fixture.');
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
    if (preg_match('/\n__TASK7_STATUS__(\d+)\z/', $stdout, $matches) === 1) {
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
    'Task 7 Block definition and pure resolver contract are centralized' => function (): void {
        $config = require __DIR__ . '/../ascii-quest/config/combat.php';
        $enemyBlock = $config['prototype_balance']['enemies']['cave_brute']['block'] ?? null;
        $definition = $config['prototype_balance']['player_reactions']['basic_block'] ?? null;
        assertSameValue(true, is_array($enemyBlock), 'Cave Brute defensive Block definition is required.');
        assertSameValue(true, is_array($definition), 'Player reaction Block definition is required.');
        assertSameValue(true, interface_exists('BlockResolver'), 'BlockResolver interface is required.');
        assertSameValue(true, class_exists('PrototypeBlockResolver'), 'PrototypeBlockResolver is required.');
        assertSameValue(20, $enemyBlock['server_only']['prototype_chance_percent'] ?? null, 'Enemy defensive Block chance remains centralized.');
        assertSameValue(50, $enemyBlock['server_only']['prototype_reduction_percent'] ?? null, 'Enemy defensive Block reduction remains centralized.');
        assertSameValue(20, $definition['server_only']['prototype_chance_percent'] ?? null, 'Player reaction Block chance is centralized separately.');
        assertSameValue(50, $definition['server_only']['prototype_reduction_percent'] ?? null, 'Player reaction Block reduction is centralized separately.');
        assertSameValue($definition, CombatDefinitionRegistry::fromDefaultConfig()->playerReaction('basic_block'), 'Registry exposes the player reaction definition independently.');

        $bounds = $definition['prompt_safe_bounds'] ?? [];
        foreach (['x_min_thousandths', 'x_max_thousandths', 'y_min_thousandths', 'y_max_thousandths'] as $key) {
            assertSameValue(true, is_int($bounds[$key] ?? null), $key . ' is centralized.');
            assertSameValue(true, $bounds[$key] >= 0 && $bounds[$key] <= 1000, $key . ' is normalized.');
        }
        assertSameValue([100, 900, 100, 900], [
            $bounds['x_min_thousandths'],
            $bounds['x_max_thousandths'],
            $bounds['y_min_thousandths'],
            $bounds['y_max_thousandths'],
        ], 'Foundation popup bounds are centralized.');
        assertSameValue(true, $bounds['x_min_thousandths'] < $bounds['x_max_thousandths'], 'X bounds are ordered.');
        assertSameValue(true, $bounds['y_min_thousandths'] < $bounds['y_max_thousandths'], 'Y bounds are ordered.');

        $missedRandom = new Task7SequenceRandomSource();
        $successRandom = new Task7SequenceRandomSource([], [20]);
        $failedRandom = new Task7SequenceRandomSource([], [21]);
        $missedResolver = new PrototypeBlockResolver(
            new PrototypeChampionDamageResolver(),
            $missedRandom,
            $definition,
        );
        $successResolver = new PrototypeBlockResolver(
            new PrototypeChampionDamageResolver(),
            $successRandom,
            $definition,
        );
        $failedResolver = new PrototypeBlockResolver(
            new PrototypeChampionDamageResolver(),
            $failedRandom,
            $definition,
        );
        $action = task7EnemyAction();
        $defense = ['toughness' => 0, 'resistances' => ['fire' => 0.0]];
        $missed = $missedResolver->resolve($action, $defense, false);
        $successful = $successResolver->resolve($action, $defense, true);
        $failed = $failedResolver->resolve($action, $defense, true);
        assertSameValue(false, $missed['blocked'], 'Missed prompt has no active Block.');
        assertSameValue(0, $missedRandom->integerCalls, 'Missed prompt performs no unnecessary Block roll.');
        assertSameValue(true, $successful['blocked'], 'Accepted roll 20 succeeds provisionally.');
        assertSameValue(12, $successful['applied_damage'], 'Successful Block applies the provisional 50 percent reduction.');
        assertSameValue(1, $successRandom->integerCalls, 'Accepted successful Block rolls exactly once.');
        assertSameValue(false, $failed['blocked'], 'Accepted roll 21 fails provisionally.');
        assertSameValue($missed['applied_damage'], $failed['applied_damage'], 'Failed Block applies no active reduction.');
        assertSameValue(1, $failedRandom->integerCalls, 'Accepted failed Block rolls exactly once.');
        assertSameValue($missed['incoming_damage'], $successful['incoming_damage'], 'Block does not rewrite incoming offense.');
    },

    'Player reaction Block and Cave Brute defensive Block configurations are independent' => function (): void {
        $config = require __DIR__ . '/../ascii-quest/config/combat.php';

        $enemyChanged = $config;
        $enemyChanged['prototype_balance']['enemies']['cave_brute']['block']['server_only'] = [
            'prototype_chance_percent' => 1,
            'prototype_reduction_percent' => 1,
        ];
        $playerDefinition = (new CombatDefinitionRegistry($enemyChanged))
            ->playerReaction('basic_block');
        $playerRandom = new Task7SequenceRandomSource([], [20]);
        $playerResult = (new PrototypeBlockResolver(
            new PrototypeChampionDamageResolver(),
            $playerRandom,
            $playerDefinition,
        ))->resolve(
            task7EnemyAction(),
            ['toughness' => 0, 'resistances' => ['fire' => 0.0]],
            true,
        );
        assertSameValue(true, $playerResult['blocked'], 'Changing enemy Block does not change player reaction chance.');
        assertSameValue(12, $playerResult['applied_damage'], 'Changing enemy Block does not change player reaction reduction.');

        $playerChanged = $config;
        $playerChanged['prototype_balance']['player_reactions']['basic_block']['server_only'] = [
            'prototype_chance_percent' => 1,
            'prototype_reduction_percent' => 1,
        ];
        $enemyDefinition = (new CombatDefinitionRegistry($playerChanged))->enemy('cave_brute');
        $enemyRandom = new Task7SequenceRandomSource([], [20]);
        $enemyResult = (new PrototypeEnemyDefenseResolver($enemyRandom))->resolve(
            ['snapshot_base_damage' => 20],
            $enemyDefinition,
        );
        assertSameValue(true, $enemyResult['blocked'], 'Changing player reaction Block does not change enemy Block chance.');
        assertSameValue(10, $enemyResult['applied_damage'], 'Changing player reaction Block does not change enemy Block reduction.');

        $invalidPlayer = $config;
        $invalidPlayer['prototype_balance']['player_reactions']['basic_block']
            ['server_only']['prototype_chance_percent'] = 101;
        assertCombatDefinitionRejected(
            fn (): object => new CombatDefinitionRegistry($invalidPlayer),
            'Invalid player reaction Block chance must reject independently.',
        );

        $invalidEnemy = $config;
        $invalidEnemy['prototype_balance']['enemies']['cave_brute']['block']
            ['server_only']['prototype_chance_percent'] = 101;
        assertCombatDefinitionRejected(
            fn (): object => new CombatDefinitionRegistry($invalidEnemy),
            'Invalid enemy defensive Block chance must reject independently.',
        );
    },

    'Enemy start creates one durable bounded Block prompt without refresh rerolls' => function (): void {
        $pdo = new FakeCombatPdo();
        $pdo->encounters[91] = task4Encounter([
            'timeline_elapsed_ms' => 0,
            'last_synchronized_at' => '2026-09-01 12:00:00.000000',
            'turn_started_timeline_ms' => 0,
            'next_enemy_decision_timeline_ms' => 0,
            'enemy_ai_initialized_timeline_ms' => 0,
            'enemy_actions_remaining' => 2,
        ]);
        $clock = new Task4MutableCombatClock(new DateTimeImmutable(
            '2026-09-01 12:00:00.000000',
            new DateTimeZone('UTC'),
        ));
        $random = new Task7SequenceRandomSource([str_repeat('c', 64)], [250, 750]);
        $service = CombatBootstrap::serviceForRepository(
            new CombatRepository($pdo),
            $clock,
            new Task5MutableEquipmentProvider(),
            $random,
        );

        $first = $service->state(7, 42);
        $action = array_values($pdo->actions)[0] ?? [];
        assertSameValue(str_repeat('c', 64), $action['block_token'] ?? null, 'Server Block token is persisted on enemy action.');
        assertSameValue($action['resolves_timeline_ms'], $action['block_expires_timeline_ms'] ?? null, 'Prompt cannot outlive hit resolution.');
        $definition = CombatDefinitionRegistry::fromDefaultConfig()
            ->playerReaction('basic_block');
        $bounds = $definition['prompt_safe_bounds'];
        assertSameValue(true, $action['block_prompt_x'] >= $bounds['x_min_thousandths'] / 1000, 'Prompt X is above safe minimum.');
        assertSameValue(true, $action['block_prompt_x'] <= $bounds['x_max_thousandths'] / 1000, 'Prompt X is below safe maximum.');
        assertSameValue(true, $action['block_prompt_y'] >= $bounds['y_min_thousandths'] / 1000, 'Prompt Y is above safe minimum.');
        assertSameValue(true, $action['block_prompt_y'] <= $bounds['y_max_thousandths'] / 1000, 'Prompt Y is below safe maximum.');

        $second = $service->state(7, 42);
        assertSameValue($first['reaction_prompt'], $second['reaction_prompt'], 'Refresh returns the identical prompt.');
        assertSameValue(1, $random->tokenCalls, 'Refresh never rerolls the Block token.');
        assertSameValue(2, $random->integerCalls, 'Refresh never rerolls popup coordinates.');
    },

    'Reaction projection is eligible-only stable allowlisted and recursively private' => function (): void {
        $pdo = new FakeCombatPdo();
        $pdo->actions[71] = task7EnemyAction([
            'cooldown_ready_timeline_ms' => 6000,
            'snapshot_base_damage' => 24,
            'block_roll' => 99,
            'raw_defense' => ['toughness' => 999],
            'future_policy_choice' => 'private',
        ]);
        $state = task7Project($pdo);
        $prompt = $state['reaction_prompt'] ?? null;
        assertSameValue([
            'enemy_action_id',
            'definition_key',
            'name',
            'damage_type',
            'resolves_timeline_ms',
            'expires_timeline_ms',
            'block_token',
            'x',
            'y',
        ], array_keys($prompt ?? []), 'Reaction prompt has exactly the approved fields.');
        assertSameValue(str_repeat('b', 64), $prompt['block_token'] ?? null, 'Single-use Block token is visible.');

        $keys = task7RecursiveStateKeys(['reaction_prompt' => $prompt]);
        foreach ([
            'cooldown_ready_timeline_ms', 'snapshot_base_damage', 'block_roll',
            'raw_defense', 'future_policy_choice', 'next_enemy_decision_timeline_ms',
            'request_token', 'prototype_chance_percent',
            'prototype_reduction_percent', 'block_success', 'block_result',
            'blocked', 'random_roll',
        ] as $forbidden) {
            assertSameValue(false, in_array($forbidden, $keys, true), $forbidden . ' remains private.');
        }

        foreach ([
            ['state' => 'resolved', 'active_slot' => null],
            ['block_attempted_timeline_ms' => 400],
            ['block_expires_timeline_ms' => 500],
            ['actor' => 'player'],
        ] as $ineligible) {
            $candidate = new FakeCombatPdo();
            $candidate->actions[71] = task7EnemyAction($ineligible);
            assertSameValue(null, task7Project($candidate, ['timeline_elapsed_ms' => 500])['reaction_prompt'], 'Ineligible action has no prompt.');
        }
    },

    'Timely Block intent is one-attempt idempotent and costs zero Actions' => function (): void {
        assertSameValue(true, method_exists(CombatService::class, 'attemptBlock'), 'CombatService::attemptBlock is required.');

        $pdo = new FakeCombatPdo();
        $pdo->encounters[91] = task4Encounter([
            'timeline_elapsed_ms' => 500,
            'last_synchronized_at' => '2026-09-01 12:00:00.000000',
            'next_enemy_decision_timeline_ms' => 2000,
            'enemy_ai_initialized_timeline_ms' => 0,
            'player_actions_remaining' => 0,
        ]);
        $pdo->actions[70] = task6PendingAction([
            'id' => 70,
            'encounter_id' => 91,
            'started_timeline_ms' => 0,
            'resolves_timeline_ms' => 1500,
        ]);
        $pdo->actions[71] = task7EnemyAction();
        $clock = new Task4MutableCombatClock(new DateTimeImmutable(
            '2026-09-01 12:00:00.000000',
            new DateTimeZone('UTC'),
        ));
        $service = CombatBootstrap::serviceForRepository(
            new CombatRepository($pdo),
            $clock,
            new Task5MutableEquipmentProvider(),
            new Task7SequenceRandomSource(),
        );
        $request = '77777777-7777-4777-8777-777777777777';

        $service->attemptBlock(7, 42, 71, str_repeat('b', 64), $request);
        assertSameValue(500, $pdo->actions[71]['block_attempted_timeline_ms'] ?? null, 'Attempt uses authoritative timeline.');
        assertSameValue(null, $pdo->actions[71]['request_token'], 'Incoming enemy action retains its null request token.');
        assertSameValue(str_repeat('b', 64), $pdo->actions[71]['block_token'], 'Prompt remains on the incoming enemy action.');
        assertSameValue(0, $pdo->encounters[91]['player_actions_remaining'], 'Block works at zero Actions and consumes none.');
        assertSameValue('pending', $pdo->actions[70]['state'], 'Player action remains pending.');
        $blockCommands = array_values(array_filter(
            $pdo->actions,
            static fn (array $action): bool => ($action['action_kind'] ?? null) === 'block',
        ));
        assertSameValue(1, count($blockCommands), 'Accepted attempt creates one Block command.');
        assertSameValue([
            'player',
            'block',
            'basic_block',
            71,
            $request,
            'resolved',
            null,
            500,
            500,
            500,
        ], [
            $blockCommands[0]['actor'] ?? null,
            $blockCommands[0]['action_kind'] ?? null,
            $blockCommands[0]['definition_key'] ?? null,
            $blockCommands[0]['parent_action_id'] ?? null,
            $blockCommands[0]['request_token'] ?? null,
            $blockCommands[0]['state'] ?? null,
            $blockCommands[0]['active_slot'] ?? null,
            $blockCommands[0]['started_timeline_ms'] ?? null,
            $blockCommands[0]['resolves_timeline_ms'] ?? null,
            $blockCommands[0]['completed_timeline_ms'] ?? null,
        ], 'Block command is a resolved zero-slot child at the accepted timeline.');
        assertSameValue([null, null, null], [
            $blockCommands[0]['block_token'] ?? null,
            $blockCommands[0]['block_prompt_x'] ?? null,
            $blockCommands[0]['block_prompt_y'] ?? null,
        ], 'Prompt data stays off the child Block command.');

        $before = $pdo->actions;
        $service->attemptBlock(7, 42, 71, str_repeat('b', 64), $request);
        assertSameValue($before, $pdo->actions, 'Exact request replay returns the same persisted command behavior.');
        task7AssertDomainRejected(
            fn (): array => $service->attemptBlock(
                7,
                42,
                71,
                str_repeat('b', 64),
                '78787878-7878-4787-8787-787878787878',
            ),
            'A distinct second attempt',
        );
        assertSameValue(1, count(array_filter(
            $pdo->actions,
            static fn (array $action): bool => ($action['action_kind'] ?? null) === 'block',
        )), 'A distinct second request cannot create another Block child.');
    },

    'Wrong expired cross-owner and competing Block attempts cannot mutate state' => function (): void {
        assertSameValue(true, method_exists(CombatRepository::class, 'lockEnemyActionForBlock'), 'Block action lock is required.');
        assertSameValue(true, method_exists(CombatRepository::class, 'createResolvedBlockCommand'), 'Resolved Block child insert is required.');
        assertSameValue(true, method_exists(CombatRepository::class, 'markLockedEnemyActionBlockAttempted'), 'Atomic parent Block marker write is required.');

        $pdo = new FakeCombatPdo();
        $pdo->encounters[91] = task4Encounter(['timeline_elapsed_ms' => 500]);
        $pdo->actions[71] = task7EnemyAction();
        $repository = new CombatRepository($pdo);
        $repository->beginTransaction();
        $repository->lockOwnedCharacter(7, 42);
        $repository->lockOwnedAccountActiveEncounter(7, 42);
        $repository->lockEnemyActionForBlock(91, 71);
        $before = $pdo->actions[71];
        assertSameValue(false, $repository->markLockedEnemyActionBlockAttempted(
            91,
            71,
            str_repeat('x', 64),
            500,
        ), 'Wrong Block token is rejected by authoritative compare-and-set.');
        $attemptSql = strtolower((string) preg_replace(
            '/\s+/',
            ' ',
            $pdo->preparedSql[array_key_last($pdo->preparedSql)],
        ));
        assertSameValue(true, str_contains(
            $attemptSql,
            ':expiry_timeline_ms < block_expires_timeline_ms',
        ), 'Native PDO uses a distinct expiry-comparison placeholder.');
        assertSameValue($before, $pdo->actions[71], 'Rejected request leaves attempt fields untouched.');
        assertSameValue(0, count(array_filter(
            $pdo->actions,
            static fn (array $action): bool => ($action['action_kind'] ?? null) === 'block',
        )), 'Wrong token creates no Block command.');
        $repository->rollBack();

        $clock = new Task4MutableCombatClock(new DateTimeImmutable(
            '2026-09-01 12:00:02.000000',
            new DateTimeZone('UTC'),
        ));
        $service = CombatBootstrap::serviceForRepository(
            new CombatRepository($pdo),
            $clock,
            new Task5MutableEquipmentProvider(),
            new Task7SequenceRandomSource([str_repeat('d', 64)], [300, 700]),
        );
        task7AssertDomainRejected(
            fn (): array => $service->attemptBlock(
                7,
                42,
                71,
                str_repeat('b', 64),
                '80808080-8080-4808-8080-808080808080',
            ),
            'Attempt at expiry',
        );
        task7AssertDomainRejected(
            fn (): array => $service->attemptBlock(
                8,
                84,
                71,
                str_repeat('b', 64),
                '81818181-8181-4818-8181-818181818181',
            ),
            'Cross-owner attempt',
        );
        assertSameValue(null, $pdo->actions[71]['block_attempted_timeline_ms'], 'Rejected attempts do not mark Block attempted.');
        assertSameValue(null, $pdo->actions[71]['request_token'], 'Rejected attempts never repurpose enemy request token.');
        assertSameValue(0, count(array_filter(
            $pdo->actions,
            static fn (array $action): bool => ($action['action_kind'] ?? null) === 'block',
        )), 'Expired and cross-owner attempts create no Block command.');
    },

    'Enemy hit receives persisted attempt and current defense exactly once' => function (): void {
        assertSameValue(true, interface_exists('BlockResolver'), 'BlockResolver is required for enemy hit resolution.');
        assertSameValue(true, class_exists('Task7RecordingBlockResolver'), 'Recording Block resolver fixture is available.');

        foreach ([false, true] as $attempted) {
            $pdo = new FakeCombatPdo();
            $pdo->encounters[91] = task4Encounter([
                'timeline_elapsed_ms' => 0,
                'last_synchronized_at' => '2026-09-01 12:00:00.000000',
                'next_enemy_decision_timeline_ms' => 5000,
                'enemy_ai_initialized_timeline_ms' => 0,
            ]);
            $pdo->actions[71] = task7EnemyAction([
                'block_attempted_timeline_ms' => $attempted ? 500 : null,
            ]);
            $repository = new CombatRepository($pdo);
            $definitions = CombatDefinitionRegistry::fromDefaultConfig();
            $equipment = new Task5MutableEquipmentProvider();
            $equipment->defense['toughness'] = 77;
            $block = new Task7RecordingBlockResolver();
            $actionResolver = new CombatActionResolver(
                $repository,
                $definitions,
                $equipment,
                new PrototypeEnemyDefenseResolver(new Task6SequenceRandomSource([])),
                $block,
            );
            $turnEngine = new CombatTurnEngine($definitions->turnDurationSeconds());
            $clock = new Task4MutableCombatClock(new DateTimeImmutable(
                '2026-09-01 12:00:02.000000',
                new DateTimeZone('UTC'),
            ));
            $service = new CombatService(
                $repository,
                $definitions,
                $clock,
                new CombatSynchronizer(
                    $clock,
                    $turnEngine,
                    $definitions->maxDisconnectedCatchupSeconds(),
                    null,
                    $repository,
                    $definitions,
                    new CaveBrutePolicy($turnEngine),
                    $actionResolver,
                ),
                $equipment,
            );

            $service->state(7, 42);
            assertSameValue([$attempted], $block->attempted, 'Persisted attempt flag reaches Block resolver.');
            assertSameValue(77, $block->defenses[0]['toughness'] ?? null, 'Current hit-time defense reaches Block resolver.');
            assertSameValue(1, $block->calls, 'Incoming action invokes Block resolver once.');
            $hp = $pdo->characters[42]['current_hp'];
            $result = [$pdo->actions[71]['resolved_damage'], $pdo->actions[71]['prevented_damage']];
            $service->state(7, 42);
            assertSameValue(1, $block->calls, 'Repeated synchronization does not re-resolve Block.');
            assertSameValue($hp, $pdo->characters[42]['current_hp'], 'Repeated synchronization does not reapply damage.');
            assertSameValue($result, [$pdo->actions[71]['resolved_damage'], $pdo->actions[71]['prevented_damage']], 'Resolved result remains immutable.');
        }
    },

    'Player reaction Block applies passive Champion defense exactly once' => function (): void {
        foreach ([
            'missed' => [null, [], 18, 6, 0],
            'failed' => [500, [21], 18, 6, 1],
            'successful' => [500, [20], 9, 15, 1],
        ] as $label => [$attemptedAt, $rolls, $expectedDamage, $expectedPrevented, $expectedRolls]) {
            $pdo = new FakeCombatPdo();
            $pdo->encounters[91] = task4Encounter([
                'timeline_elapsed_ms' => 0,
                'last_synchronized_at' => '2026-09-01 12:00:00.000000',
                'next_enemy_decision_timeline_ms' => 5000,
                'enemy_ai_initialized_timeline_ms' => 0,
            ]);
            $pdo->actions[71] = task7EnemyAction([
                'block_attempted_timeline_ms' => $attemptedAt,
            ]);
            $repository = new CombatRepository($pdo);
            $definitions = CombatDefinitionRegistry::fromDefaultConfig();
            $equipment = new Task5MutableEquipmentProvider();
            $equipment->defense['resistances']['fire'] = 25.0;
            $random = new Task7SequenceRandomSource([], $rolls);
            $block = new PrototypeBlockResolver(
                new PrototypeChampionDamageResolver(),
                $random,
                $definitions->playerReaction('basic_block'),
            );
            $actionResolver = new CombatActionResolver(
                $repository,
                $definitions,
                $equipment,
                new PrototypeEnemyDefenseResolver(new Task6SequenceRandomSource([])),
                $block,
            );
            $turnEngine = new CombatTurnEngine($definitions->turnDurationSeconds());
            $clock = new Task4MutableCombatClock(new DateTimeImmutable(
                '2026-09-01 12:00:02.000000',
                new DateTimeZone('UTC'),
            ));
            $service = new CombatService(
                $repository,
                $definitions,
                $clock,
                new CombatSynchronizer(
                    $clock,
                    $turnEngine,
                    $definitions->maxDisconnectedCatchupSeconds(),
                    null,
                    $repository,
                    $definitions,
                    new CaveBrutePolicy($turnEngine),
                    $actionResolver,
                ),
                $equipment,
            );

            $service->state(7, 42);

            assertSameValue($expectedDamage, $pdo->actions[71]['resolved_damage'], $label . ' Block final damage.');
            assertSameValue($expectedPrevented, $pdo->actions[71]['prevented_damage'], $label . ' Block total prevented damage.');
            assertSameValue($expectedRolls, $random->integerCalls, $label . ' Block random-call count.');
            assertSameValue(1, $equipment->defensiveReads, $label . ' Block reads current defense once.');

            $resolvedHp = $pdo->characters[42]['current_hp'];
            $service->state(7, 42);
            assertSameValue($expectedRolls, $random->integerCalls, $label . ' repeated synchronization adds no Block roll.');
            assertSameValue($resolvedHp, $pdo->characters[42]['current_hp'], $label . ' repeated synchronization adds no damage.');
            assertSameValue(1, $equipment->defensiveReads, $label . ' repeated synchronization does not reread defense.');
        }
    },

    'Combat Block endpoint enforces POST session CSRF UUID and strict intent allowlist' => function (): void {
        $session = ['user_id' => 7, 'character_id' => 42, 'csrf_token' => 'session-token'];
        $valid = [
            'csrf_token' => 'session-token',
            'enemy_action_id' => 71,
            'block_token' => str_repeat('b', 64),
            'request_token' => '82828282-8282-4828-8282-828282828282',
        ];
        foreach ([
            'session' => [[], 'POST', json_encode($valid), 401],
            'method' => [$session, 'GET', json_encode($valid), 405],
            'json' => [$session, 'POST', '{', 400],
            'csrf' => [$session, 'POST', json_encode(array_replace($valid, ['csrf_token' => 'wrong'])), 403],
            'uuid' => [$session, 'POST', json_encode(array_replace($valid, ['request_token' => 'not-a-uuid'])), 422],
            'token' => [$session, 'POST', json_encode(array_replace($valid, ['block_token' => 'wrong'])), 422],
        ] as $label => [$caseSession, $method, $body, $expected]) {
            [$status, $payload, $call] = task7RunBlockEndpoint($caseSession, $method, (string) $body);
            assertSameValue($expected, $status, $label . ' status.');
            assertSameValue(false, $payload['success'] ?? null, $label . ' safe response.');
            assertSameValue(null, $call, $label . ' never reaches service.');
        }

        [$status, , $call] = task7RunBlockEndpoint($session, 'POST', (string) json_encode($valid));
        assertSameValue(200, $status, 'Valid Block intent status.');
        assertSameValue([
            'user_id' => 7,
            'character_id' => 42,
            'enemy_action_id' => 71,
            'block_token' => str_repeat('b', 64),
            'request_token' => '82828282-8282-4828-8282-828282828282',
        ], $call, 'Endpoint uses session identity and exact intent fields.');
    },

    'Combat Block endpoint rejects every client-authored authoritative value and hides errors' => function (): void {
        $session = ['user_id' => 7, 'character_id' => 42, 'csrf_token' => 'session-token'];
        $base = [
            'csrf_token' => 'session-token',
            'enemy_action_id' => 71,
            'block_token' => str_repeat('b', 64),
            'request_token' => '83838383-8383-4838-8383-838383838383',
        ];
        foreach ([
            'user_id', 'character_id', 'x', 'y', 'damage', 'prevented_damage',
            'outcome', 'block_chance', 'block_roll', 'expires_timeline_ms',
            'attempted_timeline_ms', 'timestamp', 'defense', 'current_hp',
            'resolves_timeline_ms', 'cooldown_ready_timeline_ms',
            'player_actions_remaining',
        ] as $extra) {
            [$status, , $call] = task7RunBlockEndpoint(
                $session,
                'POST',
                (string) json_encode($base + [$extra => 999]),
            );
            assertSameValue(400, $status, $extra . ' extra field status.');
            assertSameValue(null, $call, $extra . ' never reaches service.');
        }

        [$domainStatus, $domainPayload, , , $domainBody] = task7RunBlockEndpoint(
            $session,
            'POST',
            (string) json_encode($base),
            'domain',
        );
        assertSameValue(422, $domainStatus, 'Domain error status.');
        assertSameValue(false, str_contains($domainBody, 'Private Block timing detail.'), 'Domain detail stays private.');
        assertSameValue(true, is_string($domainPayload['message'] ?? null), 'Domain response remains safe JSON.');

        [$runtimeStatus, , , , $runtimeBody] = task7RunBlockEndpoint(
            $session,
            'POST',
            (string) json_encode($base),
            'runtime',
        );
        assertSameValue(500, $runtimeStatus, 'Internal error status.');
        assertSameValue(false, str_contains($runtimeBody, 'Private database detail.'), 'Internal detail stays private.');
    },
];

<?php
declare(strict_types=1);

function runInventoryEndpoint(array $session, string $method = 'GET', array $query = []): array
{
    $endpoint = __DIR__ . '/../ascii-quest/inventory_state.php';
    if (!is_file($endpoint)) {
        throw new RuntimeException('inventory_state.php must exist.');
    }

    $directory = sys_get_temp_dir() . '/ascii-quest-item-endpoint-' . bin2hex(random_bytes(8));
    $library = $directory . '/lib';
    $sessions = $directory . '/sessions';
    if (!mkdir($library, 0700, true) || !mkdir($sessions, 0700, true)) {
        throw new RuntimeException('Unable to create inventory endpoint fixture.');
    }
    $endpointCopy = $directory . '/inventory_state.php';
    $callFile = $directory . '/call.json';
    $runner = $directory . '/run.php';
    copy($endpoint, $endpointCopy);
    file_put_contents($directory . '/db.php', <<<'PHP'
<?php
function getDb(): PDO { return new class extends PDO { public function __construct() {} }; }
PHP);
    file_put_contents($library . '/ItemBootstrap.php', <<<'PHP'
<?php
final class InventoryEndpointFixtureService
{
    public function state(int $userId, int $characterId, int $page): array
    {
        file_put_contents((string) getenv('ASCII_QUEST_ITEM_CALL'), json_encode(compact('userId', 'characterId', 'page')));
        if ($userId !== 7 || $characterId !== 42) {
            throw new OutOfBoundsException('private ownership detail');
        }
        return [
            'items' => [[
                'id' => 5, 'display_name' => 'Basic Sword', 'rarity' => 'normal',
                'item_level' => 1, 'definition_key' => 'basic_sword',
                'base_type' => 'sword', 'category' => 'weapon',
                'equipment_slot' => 'weapon', 'glyph' => '/', 'base_stats' => [],
                'equipped' => false, 'equipped_slot' => null,
            ]],
            'pagination' => ['page' => $page, 'per_page' => 25, 'total_items' => 1, 'total_pages' => 1, 'has_previous' => false, 'has_next' => false],
            'equipment_locked' => false,
            'mutation_disabled_reason' => null,
        ];
    }
}
final class ItemBootstrap
{
    public static function service(PDO $pdo): InventoryEndpointFixtureService
    {
        return new InventoryEndpointFixtureService();
    }
}
PHP);

    $sessionId = 'item' . bin2hex(random_bytes(8));
    $source = '<?php' . "\n"
        . 'ini_set(\'session.save_path\', ' . var_export($sessions, true) . ');' . "\n"
        . 'session_id(' . var_export($sessionId, true) . '); session_start();' . "\n"
        . '$_SESSION = ' . var_export($session, true) . '; session_write_close();' . "\n"
        . '$_SERVER[\'REQUEST_METHOD\'] = ' . var_export($method, true) . ';' . "\n"
        . '$_GET = ' . var_export($query, true) . ';' . "\n"
        . 'register_shutdown_function(static function (): void { echo "\n__ITEM_STATUS__" . http_response_code(); });' . "\n"
        . 'require ' . var_export($endpointCopy, true) . ';' . "\n";
    file_put_contents($runner, $source);

    $process = proc_open([PHP_BINARY, $runner], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_replace(getenv(), ['ASCII_QUEST_ITEM_CALL' => $callFile]));
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to execute inventory endpoint fixture.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $status = 0;
    $body = $stdout;
    if (preg_match('/\n__ITEM_STATUS__(\d+)\z/', $stdout, $matches) === 1) {
        $status = (int) $matches[1];
        $body = substr($stdout, 0, -strlen($matches[0]));
    }
    $payload = json_decode($body, true);
    $call = is_file($callFile) ? json_decode((string) file_get_contents($callFile), true) : null;

    foreach (glob($sessions . '/*') ?: [] as $file) { unlink($file); }
    foreach ([$runner, $callFile, $endpointCopy, $library . '/ItemBootstrap.php', $directory . '/db.php'] as $file) {
        if (is_file($file)) { unlink($file); }
    }
    rmdir($sessions); rmdir($library); rmdir($directory);

    return [$status, $payload, $call, $exitCode, $body, $stderr];
}

return [
    'Inventory endpoint requires an authenticated selected Champion session' => function (): void {
        foreach ([[], ['user_id' => 7]] as $session) {
            [$status, $payload, $call] = runInventoryEndpoint($session);
            assertSameValue(401, $status, 'Missing session status.');
            assertSameValue(false, $payload['success'] ?? null, 'Missing session payload.');
            assertSameValue(null, $call, 'No database service before session rejection.');
        }
    },

    'Inventory endpoint accepts GET only and exact query keys' => function (): void {
        [$status, , $call] = runInventoryEndpoint(['user_id' => 7, 'character_id' => 42], 'POST');
        assertSameValue(405, $status, 'Wrong method status.');
        assertSameValue(null, $call, 'Wrong method rejected early.');

        [$status, , $call] = runInventoryEndpoint(['user_id' => 7, 'character_id' => 42], 'GET', ['page' => '1', 'character_id' => '99']);
        assertSameValue(422, $status, 'Unknown query key status.');
        assertSameValue(null, $call, 'Unknown query key rejected early.');
    },

    'Inventory endpoint requires a strict positive bounded decimal page' => function (): void {
        foreach ([['page' => []], ['page' => ''], ['page' => '0'], ['page' => '-1'], ['page' => '01'], ['page' => '1.0'], ['page' => '999999999999999999999999']] as $query) {
            [$status, $payload, $call] = runInventoryEndpoint(['user_id' => 7, 'character_id' => 42], 'GET', $query);
            assertSameValue(422, $status, 'Invalid page status for ' . json_encode($query));
            assertSameValue(false, $payload['success'] ?? null, 'Invalid page payload.');
            assertSameValue(null, $call, 'Invalid page rejected before service.');
        }
    },

    'Inventory endpoint passes only session authority and sanitized page state' => function (): void {
        [$status, $payload, $call] = runInventoryEndpoint(['user_id' => 7, 'character_id' => 42], 'GET', ['page' => '1']);
        assertSameValue(200, $status, 'Owned inventory status.');
        assertSameValue(['userId' => 7, 'characterId' => 42, 'page' => 1], $call, 'Session identities and validated page.');
        assertSameValue('Basic Sword', $payload['items'][0]['display_name'] ?? null, 'Sanitized service projection returned.');
        assertSameValue(false, array_key_exists('character_id', $payload['items'][0]), 'Owner identity stays hidden.');
    },

    'Inventory endpoint blocks cross-owner selection with a generic response' => function (): void {
        [$status, $payload, $call, , $body] = runInventoryEndpoint(['user_id' => 7, 'character_id' => 99]);
        assertSameValue(404, $status, 'Cross-owner status.');
        assertSameValue(['userId' => 7, 'characterId' => 99, 'page' => 1], $call, 'Only selected session identity attempted.');
        assertSameValue(false, $payload['success'] ?? null, 'Cross-owner response.');
        assertSameValue(false, str_contains($body, 'private ownership detail'), 'Internal ownership detail hidden.');
    },

    'Inventory endpoint declares JSON and no-store response headers' => function (): void {
        $source = file_get_contents(__DIR__ . '/../ascii-quest/inventory_state.php');
        if ($source === false) { throw new RuntimeException('Inventory endpoint must be readable.'); }
        foreach ([
            "Content-Type: application/json; charset=utf-8",
            'Cache-Control: no-store, no-cache, must-revalidate',
            'Pragma: no-cache',
        ] as $header) {
            assertSameValue(true, str_contains($source, $header), 'Required response header ' . $header . '.');
        }
    },
];

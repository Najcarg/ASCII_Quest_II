<?php
declare(strict_types=1);

function runItemClaimEndpoint(array $session, string $method, string $body): array
{
    $endpoint = __DIR__ . '/../ascii-quest/item_claim.php';
    $directory = sys_get_temp_dir() . '/ascii-quest-claim-' . bin2hex(random_bytes(8));
    $library = $directory . '/lib';
    $sessions = $directory . '/sessions';
    mkdir($library, 0700, true);
    mkdir($sessions, 0700, true);
    copy($endpoint, $directory . '/item_claim.php');
    file_put_contents($directory . '/db.php', '<?php function getDb(): PDO { return new class extends PDO { public function __construct() {} }; }');
    file_put_contents($library . '/ItemBootstrap.php', <<<'PHP'
<?php
final class ClaimFixtureService {
    public function claim(int $userId, int $characterId, int $dropId, string $requestToken): array {
        file_put_contents((string) getenv('ASCII_QUEST_CLAIM_CALL'), json_encode(compact('userId', 'characterId', 'dropId', 'requestToken')));
        if ($dropId === 99) { throw new DomainException('private foreign drop'); }
        return ['drop' => ['id' => $dropId, 'claim_state' => 'claimed', 'item' => ['id' => 8, 'display_name' => 'Hunter Axe']]];
    }
}
final class ItemBootstrap { public static function service(PDO $pdo): ClaimFixtureService { return new ClaimFixtureService(); } }
PHP);
    $callFile = $directory . '/call.json';
    $runner = $directory . '/run.php';
    $sessionId = 'claim' . bin2hex(random_bytes(8));
    $source = '<?php' . "\n"
        . 'ini_set(\'session.save_path\', ' . var_export($sessions, true) . ');' . "\n"
        . 'session_id(' . var_export($sessionId, true) . '); session_start();' . "\n"
        . '$_SESSION = ' . var_export($session, true) . '; session_write_close();' . "\n"
        . '$_SERVER[\'REQUEST_METHOD\'] = ' . var_export($method, true) . ';' . "\n"
        . 'register_shutdown_function(static function (): void { echo "\n__CLAIM_STATUS__" . http_response_code(); });' . "\n"
        . 'require ' . var_export($directory . '/item_claim.php', true) . ';' . "\n";
    file_put_contents($runner, $source);
    $process = proc_open([PHP_BINARY, $runner], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_replace(getenv(), ['ASCII_QUEST_CLAIM_CALL' => $callFile]));
    fwrite($pipes[0], $body); fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    proc_close($process);
    preg_match('/\n__CLAIM_STATUS__(\d+)\z/', $stdout, $matches);
    $status = (int) ($matches[1] ?? 0);
    $json = $matches ? substr($stdout, 0, -strlen($matches[0])) : $stdout;
    $payload = json_decode($json, true);
    $call = is_file($callFile) ? json_decode((string) file_get_contents($callFile), true) : null;
    foreach (glob($sessions . '/*') ?: [] as $file) { unlink($file); }
    foreach ([$callFile, $runner, $library . '/ItemBootstrap.php', $directory . '/db.php', $directory . '/item_claim.php'] as $file) { if (is_file($file)) { unlink($file); } }
    rmdir($sessions); rmdir($library); rmdir($directory);
    return [$status, $payload, $call, $stderr];
}

function validClaimBody(array $overrides = []): string
{
    return json_encode(array_replace([
        'csrf_token' => 'csrf', 'drop_id' => 7,
        'request_token' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    ], $overrides), JSON_THROW_ON_ERROR);
}

return [
    'Item claim endpoint requires session POST and exact CSRF intent keys' => function (): void {
        [$status, , $call] = runItemClaimEndpoint([], 'POST', validClaimBody());
        assertSameValue(401, $status, 'Session.'); assertSameValue(null, $call, 'No service.');
        [$status, , $call] = runItemClaimEndpoint(['user_id' => 7, 'character_id' => 42, 'csrf_token' => 'csrf'], 'GET', validClaimBody());
        assertSameValue(405, $status, 'Method.'); assertSameValue(null, $call, 'No service.');
        [$status, , $call] = runItemClaimEndpoint(['user_id' => 7, 'character_id' => 42, 'csrf_token' => 'csrf'], 'POST', validClaimBody(['stats' => ['damage' => 999]]));
        assertSameValue(400, $status, 'Strict allowlist.'); assertSameValue(null, $call, 'No authoritative client fields.');
        [$status, , $call] = runItemClaimEndpoint(['user_id' => 7, 'character_id' => 42, 'csrf_token' => 'different'], 'POST', validClaimBody());
        assertSameValue(403, $status, 'CSRF.'); assertSameValue(null, $call, 'No service.');
    },

    'Item claim endpoint validates integer drop and UUIDv4 before service' => function (): void {
        foreach ([['drop_id' => '7'], ['drop_id' => 0], ['request_token' => 'not-a-uuid']] as $override) {
            [$status, , $call] = runItemClaimEndpoint(['user_id' => 7, 'character_id' => 42, 'csrf_token' => 'csrf'], 'POST', validClaimBody($override));
            assertSameValue(422, $status, 'Malformed intent.'); assertSameValue(null, $call, 'Rejected early.');
        }
    },

    'Item claim endpoint passes only selected authority and hides foreign details' => function (): void {
        [$status, $payload, $call] = runItemClaimEndpoint(['user_id' => 7, 'character_id' => 42, 'csrf_token' => 'csrf'], 'POST', validClaimBody());
        assertSameValue(200, $status, 'Claim.');
        assertSameValue(['userId' => 7, 'characterId' => 42, 'dropId' => 7, 'requestToken' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'], $call, 'Server authority.');
        assertSameValue('claimed', $payload['drop']['claim_state'] ?? null, 'Sanitized result.');
        [$status, $payload, , $stderr] = runItemClaimEndpoint(['user_id' => 7, 'character_id' => 42, 'csrf_token' => 'csrf'], 'POST', validClaimBody(['drop_id' => 99]));
        assertSameValue(422, $status, 'Foreign claim.');
        assertSameValue('Item drop unavailable.', $payload['message'] ?? null, 'Generic response.');
        assertSameValue(false, str_contains(json_encode($payload), 'private foreign drop'), 'No ownership leak.');
    },
];

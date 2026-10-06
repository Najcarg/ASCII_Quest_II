<?php
declare(strict_types=1);

function runEquipmentEndpoint(string $name, array $session, string $method, string $body): array
{
    $directory = sys_get_temp_dir() . '/ascii-quest-equipment-endpoint-' . bin2hex(random_bytes(8));
    $library = $directory . '/lib'; $sessions = $directory . '/sessions';
    mkdir($library, 0700, true); mkdir($sessions, 0700, true);
    copy(__DIR__ . '/../ascii-quest/' . $name . '.php', $directory . '/' . $name . '.php');
    file_put_contents($directory . '/db.php', '<?php function getDb(): PDO { return new class extends PDO { public function __construct() {} }; }');
    file_put_contents($library . '/EquipmentBootstrap.php', <<<'PHP'
<?php
final class EquipmentEndpointFixtureService {
    public function equip(int $userId, int $characterId, int $itemId, string $slot, string $requestToken): array { return $this->record('equip', compact('userId', 'characterId', 'itemId', 'slot', 'requestToken')); }
    public function unequip(int $userId, int $characterId, int $itemId, string $requestToken): array { return $this->record('unequip', compact('userId', 'characterId', 'itemId', 'requestToken')); }
    private function record(string $command, array $values): array {
        file_put_contents((string) getenv('ASCII_QUEST_EQUIPMENT_CALL'), json_encode([$command, $values]));
        if ($values['itemId'] === 99) { throw new DomainException('private foreign item'); }
        return ['result' => $command === 'equip' ? 'equipped' : 'unequipped'];
    }
}
final class EquipmentBootstrap {
    public static function service(PDO $pdo): EquipmentEndpointFixtureService { return new EquipmentEndpointFixtureService(); }
    public static function publicCharacterState(PDO $pdo, int $userId, int $characterId): array {
        return ['current_hp' => 120, 'current_mana' => 80, 'stats' => ['resources' => ['max_life' => 170, 'max_mana' => 190]]];
    }
}
PHP);
    file_put_contents($library . '/ItemBootstrap.php', <<<'PHP'
<?php
final class EquipmentInventoryFixture { public function state(int $userId, int $characterId, int $page): array { return ['items' => [], 'equipment' => [], 'pagination' => ['page' => 1], 'equipment_locked' => false, 'mutation_disabled_reason' => null]; } }
final class ItemBootstrap { public static function service(PDO $pdo): EquipmentInventoryFixture { return new EquipmentInventoryFixture(); } }
PHP);
    $callFile = $directory . '/call.json'; $runner = $directory . '/run.php';
    $sessionId = 'equip' . bin2hex(random_bytes(8));
    $source = '<?php' . "\n" . 'ini_set(\'session.save_path\', ' . var_export($sessions, true) . ');' . "\n"
        . 'session_id(' . var_export($sessionId, true) . '); session_start(); $_SESSION = ' . var_export($session, true) . '; session_write_close();' . "\n"
        . '$_SERVER[\'REQUEST_METHOD\'] = ' . var_export($method, true) . ';' . "\n"
        . 'register_shutdown_function(static function (): void { echo "\n__EQUIPMENT_STATUS__" . http_response_code(); });' . "\n"
        . 'require ' . var_export($directory . '/' . $name . '.php', true) . ';';
    file_put_contents($runner, $source);
    $process = proc_open([PHP_BINARY, $runner], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_replace(getenv(), ['ASCII_QUEST_EQUIPMENT_CALL' => $callFile]));
    fwrite($pipes[0], $body); fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]); proc_close($process);
    preg_match('/\n__EQUIPMENT_STATUS__(\d+)\z/', $stdout, $matches);
    $json = $matches ? substr($stdout, 0, -strlen($matches[0])) : $stdout;
    $result = [(int) ($matches[1] ?? 0), json_decode($json, true), is_file($callFile) ? json_decode((string) file_get_contents($callFile), true) : null, $stderr];
    foreach (glob($sessions . '/*') ?: [] as $file) { unlink($file); }
    foreach ([$callFile, $runner, $library . '/EquipmentBootstrap.php', $library . '/ItemBootstrap.php', $directory . '/db.php', $directory . '/' . $name . '.php'] as $file) { if (is_file($file)) unlink($file); }
    rmdir($sessions); rmdir($library); rmdir($directory);
    return $result;
}

function equipmentEndpointBody(string $name, array $overrides = []): string
{
    $body = ['csrf_token' => 'csrf', 'item_id' => 7, 'request_token' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'];
    if ($name === 'equip_item') { $body['slot'] = 'weapon'; }
    return json_encode(array_replace($body, $overrides), JSON_THROW_ON_ERROR);
}

return [
    'Equipment endpoints require session POST CSRF and exact intent keys' => function (): void {
        foreach (['equip_item', 'unequip_item'] as $name) {
            [$status, , $call] = runEquipmentEndpoint($name, [], 'POST', equipmentEndpointBody($name));
            assertSameValue(401, $status, $name . ' session.'); assertSameValue(null, $call, 'No service.');
            [$status, , $call] = runEquipmentEndpoint($name, ['user_id' => 7, 'character_id' => 42, 'csrf_token' => 'csrf'], 'GET', equipmentEndpointBody($name));
            assertSameValue(405, $status, $name . ' method.'); assertSameValue(null, $call, 'No service.');
            [$status, , $call] = runEquipmentEndpoint($name, ['user_id' => 7, 'character_id' => 42, 'csrf_token' => 'csrf'], 'POST', equipmentEndpointBody($name, ['damage' => 999]));
            assertSameValue(400, $status, $name . ' exact keys.'); assertSameValue(null, $call, 'No client authority.');
        }
    },

    'Equipment endpoints validate item slot and UUID before service' => function (): void {
        $session = ['user_id' => 7, 'character_id' => 42, 'csrf_token' => 'csrf'];
        foreach ([['item_id' => '7'], ['item_id' => 0], ['request_token' => 'bad'], ['slot' => 'second-ring']] as $override) {
            [$status, , $call] = runEquipmentEndpoint('equip_item', $session, 'POST', equipmentEndpointBody('equip_item', $override));
            assertSameValue(422, $status, 'Malformed equip intent.'); assertSameValue(null, $call, 'Rejected early.');
        }
    },

    'Equipment endpoints pass only session authority and return refreshed safe state' => function (): void {
        $session = ['user_id' => 7, 'character_id' => 42, 'csrf_token' => 'csrf'];
        [$status, $payload, $call] = runEquipmentEndpoint('equip_item', $session, 'POST', equipmentEndpointBody('equip_item'));
        assertSameValue(200, $status, 'Equip success.');
        assertSameValue(['equip', ['userId' => 7, 'characterId' => 42, 'itemId' => 7, 'slot' => 'weapon', 'requestToken' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']], $call, 'Equip authority.');
        assertSameValue([], $payload['state']['items'] ?? null, 'Refreshed state.');
        assertSameValue(170, $payload['champion']['stats']['resources']['max_life'] ?? null, 'Refreshed equipment-aware stats.');
        [$status, $payload, $call] = runEquipmentEndpoint('unequip_item', $session, 'POST', equipmentEndpointBody('unequip_item'));
        assertSameValue(200, $status, 'Unequip success.'); assertSameValue('unequip', $call[0] ?? null, 'Unequip authority.');
        [$status, $payload] = runEquipmentEndpoint('equip_item', $session, 'POST', equipmentEndpointBody('equip_item', ['item_id' => 99]));
        assertSameValue(422, $status, 'Foreign item.'); assertSameValue('Equipment unavailable.', $payload['message'] ?? null, 'Generic failure.');
    },
];

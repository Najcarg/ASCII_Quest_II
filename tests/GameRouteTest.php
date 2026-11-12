<?php
declare(strict_types=1);

function task22RemoveGameRouteFixture(string $directory): void
{
    foreach (array_reverse(glob($directory . '/{*,.*}', GLOB_BRACE) ?: []) as $path) {
        if (basename($path) === '.' || basename($path) === '..') {
            continue;
        }
        if (is_dir($path)) {
            task22RemoveGameRouteFixture($path);
            continue;
        }
        unlink($path);
    }
    rmdir($directory);
}

function task22RunAuthenticatedGameRoute(): array
{
    $directory = sys_get_temp_dir() . '/ascii-quest-game-route-' . bin2hex(random_bytes(8));
    $libraryDirectory = $directory . '/lib';
    $sessionDirectory = $directory . '/sessions';
    $markerFile = $directory . '/equipment-stats.txt';
    mkdir($libraryDirectory, 0700, true);
    mkdir($sessionDirectory, 0700, true);

    try {
        copy(__DIR__ . '/../ascii-quest/game.php', $directory . '/game.php');
        file_put_contents($directory . '/db.php', <<<'PHP'
<?php
declare(strict_types=1);

final class Task22GameStatement extends PDOStatement
{
    public function __construct() {}
    public function execute(?array $params = null): bool { return true; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return ['id' => 73];
    }
}

final class Task22GamePdo extends PDO
{
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new Task22GameStatement(); }
}

function getDb(): PDO { return new Task22GamePdo(); }
PHP);
        file_put_contents($directory . '/map_loader.php', "<?php\ndeclare(strict_types=1);\n");
        file_put_contents($libraryDirectory . '/CharacterStats.php', "<?php\ndeclare(strict_types=1);\nfinal class CharacterStats {}\n");
        file_put_contents($libraryDirectory . '/WarpBootstrap.php', "<?php\ndeclare(strict_types=1);\nfinal class WarpBootstrap {}\n");
        file_put_contents($libraryDirectory . '/ItemBootstrap.php', "<?php\ndeclare(strict_types=1);\nfinal class ItemBootstrap {}\n");
        file_put_contents($libraryDirectory . '/CombatBootstrap.php', <<<'PHP'
<?php
declare(strict_types=1);

final class CombatAccessGuard
{
    public const GAME_LOAD = 'game_load';
}

final class CombatBootstrap
{
    public static function guard(PDO $pdo): object
    {
        return new class {
            public function assertAllowed(string $operation, int $userId, int $characterId): void
            {
                throw new DomainException('Task 22 route fixture completed.');
            }
        };
    }
}
PHP);
        file_put_contents($libraryDirectory . '/EquipmentBootstrap.php', <<<'PHP'
<?php
declare(strict_types=1);

final class EquipmentBootstrap
{
    public static function stats(PDO $pdo, array $character): array
    {
        file_put_contents((string) getenv('ASCII_QUEST_GAME_STATS_MARKER'), (string) $character['id']);
        return [];
    }
}
PHP);

        $sessionId = 'task22' . bin2hex(random_bytes(8));
        $runner = $directory . '/run.php';
        $runnerSource = '<?php' . "\n"
            . 'ini_set(\'session.save_path\', ' . var_export($sessionDirectory, true) . ');' . "\n"
            . 'session_id(' . var_export($sessionId, true) . ');' . "\n"
            . 'session_start();' . "\n"
            . '$_SESSION = [\'user_id\' => 17, \'character_id\' => 73, \'csrf_token\' => \'task22-csrf\'];' . "\n"
            . 'session_write_close();' . "\n"
            . '$_SERVER[\'REQUEST_METHOD\'] = \'GET\';' . "\n"
            . 'require ' . var_export($directory . '/game.php', true) . ';' . "\n";
        file_put_contents($runner, $runnerSource);

        $process = proc_open(
            [PHP_BINARY, $runner],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            array_replace(getenv(), ['ASCII_QUEST_GAME_STATS_MARKER' => $markerFile]),
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to execute authenticated game route fixture.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        return [
            'status' => $status,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'stats_character_id' => is_file($markerFile) ? trim((string) file_get_contents($markerFile)) : null,
        ];
    } finally {
        task22RemoveGameRouteFixture($directory);
    }
}

return [
    'Authenticated game route loads EquipmentBootstrap before equipment-aware stats initialization' => function (): void {
        $result = task22RunAuthenticatedGameRoute();
        if ($result['status'] !== 0) {
            throw new RuntimeException(
                'Authenticated game route exited with status ' . $result['status'] . ': ' . trim($result['stderr']),
            );
        }
        if ($result['stats_character_id'] !== '73') {
            throw new RuntimeException('Equipment-aware stats initialization did not receive the selected Champion.');
        }
    },
];

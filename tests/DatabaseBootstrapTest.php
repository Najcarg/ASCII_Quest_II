<?php
declare(strict_types=1);

/** Each case gets a fresh PHP process: global getDb() cannot be unloaded. */
function dbBootstrapProcess(string $program, array $arguments = []): array
{
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-d', 'log_errors=1', $program, ...$arguments],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/');
    if (!is_resource($process)) { throw new RuntimeException('Fixture process unavailable.'); }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
}

function dbBootstrapFixture(callable $test): void
{
    $root = sys_get_temp_dir() . '/aq-bootstrap-' . bin2hex(random_bytes(8));
    mkdir($root, 0700); mkdir($root . '/ascii-quest'); mkdir($root . '/scripts'); mkdir($root . '/scripts/lib');
    $helper = __DIR__ . '/../scripts/lib/DatabaseBootstrap.php';
    if (is_file($helper)) { copy($helper, $root . '/scripts/lib/DatabaseBootstrap.php'); }
    $runner = <<<'PHP'
<?php
if (!is_file(__DIR__ . '/scripts/lib/DatabaseBootstrap.php')) { echo 'helper_missing'; exit(1); }
require __DIR__ . '/scripts/lib/DatabaseBootstrap.php';
try {
    // Private, in-process test seam; no environment, argv or public source override exists.
    $method = new ReflectionMethod(DatabaseBootstrap::class, 'connectFromSources');
    $pdo = $method->invoke(null, __DIR__ . '/ascii-quest/db.php', __DIR__ . '/approved.php');
    echo 'PDO_OK:' . get_class($pdo);
} catch (Throwable $e) {
    echo 'SAFE_ERROR:' . $e->getMessage() . ($e->getPrevious() === null ? ':no_previous' : ':previous');
}
PHP;
    file_put_contents($root . '/runner.php', $runner);
    try { $test($root); }
    finally {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($root);
    }
}

function dbBootstrapValid(string $class): string
{
    return '<?php echo "fixture-private-marker"; class ' . $class . ' extends PDO { public function __construct() {} } function getDb(): PDO { echo "fixture-private-marker"; return new ' . $class . '; }';
}

function dbBootstrapSafeFailure(string $root): void
{
    assertSameValue([0, 'SAFE_ERROR:Maintenance database unavailable.:no_previous', ''], dbBootstrapProcess($root . '/runner.php'), 'Only sanitized failure, no source/path/trace/warning.');
}

return [
    'Task32.1 local bootstrap wins over approved fallback independent of cwd' => function (): void {
        dbBootstrapFixture(function ($root): void {
            file_put_contents($root . '/ascii-quest/db.php', dbBootstrapValid('LocalFixturePdo'));
            file_put_contents($root . '/approved.php', '<?php throw new RuntimeException("fixture-private-marker");');
            assertSameValue([0, 'PDO_OK:LocalFixturePdo', ''], dbBootstrapProcess($root . '/runner.php'), 'Local selected; echo discarded.');
        });
    },
    'Task32.1 missing local uses approved bootstrap independent of cwd' => function (): void {
        dbBootstrapFixture(function ($root): void {
            file_put_contents($root . '/approved.php', dbBootstrapValid('ApprovedFixturePdo'));
            assertSameValue([0, 'PDO_OK:ApprovedFixturePdo', ''], dbBootstrapProcess($root . '/runner.php'), 'Approved source selected.');
        });
    },
    'Task32.1 neither bootstrap exists fails safely' => function (): void {
        dbBootstrapFixture(fn ($root) => dbBootstrapSafeFailure($root));
    },
    'Task32.1 broken local never falls through and malformed content is suppressed' => function (): void {
        foreach ([
            'fixture-private-marker outside PHP',
            '<?php echo "fixture-private-marker"; function getDb( {',
            '<?php echo "fixture-private-marker"; function getDb() { return "fixture-private-marker"; }',
            '<?php echo "fixture-private-marker"; throw new RuntimeException("fixture-private-marker");',
            '<?php function getDb() { echo "fixture-private-marker"; trigger_error("fixture-private-marker", E_USER_WARNING); }',
            '<?php function getDb($required) {}',
            '<?php require __DIR__ . "/unavailable-protected-config.php";',
        ] as $source) {
            dbBootstrapFixture(function ($root) use ($source): void {
                file_put_contents($root . '/ascii-quest/db.php', $source);
                file_put_contents($root . '/approved.php', dbBootstrapValid('ForbiddenFallbackPdo'));
                dbBootstrapSafeFailure($root);
            });
        }
    },
    'Task32.1 directory and dangling local source fail closed' => function (): void {
        foreach (['directory', 'symlink'] as $type) {
            dbBootstrapFixture(function ($root) use ($type): void {
                if ($type === 'directory') { mkdir($root . '/ascii-quest/db.php'); }
                else { symlink($root . '/absent.php', $root . '/ascii-quest/db.php'); }
                file_put_contents($root . '/approved.php', dbBootstrapValid('ForbiddenFallbackPdo'));
                dbBootstrapSafeFailure($root);
            });
        }
    },
    'Task32.1 unreadable local file or parent cannot silently select production' => function (): void {
        foreach (['file', 'parent'] as $target) {
            dbBootstrapFixture(function ($root) use ($target): void {
                file_put_contents($root . '/ascii-quest/db.php', dbBootstrapValid('InaccessibleFixturePdo'));
                file_put_contents($root . '/approved.php', dbBootstrapValid('ForbiddenFallbackPdo'));
                $path = $root . '/ascii-quest' . ($target === 'file' ? '/db.php' : '');
                chmod($path, 0000);
                try { dbBootstrapSafeFailure($root); }
                finally { chmod($path, $target === 'file' ? 0600 : 0700); }
            });
        }
    },
    'Task32.1 preexisting global getDb cannot satisfy a different bootstrap' => function (): void {
        dbBootstrapFixture(function ($root): void {
            $runner = file_get_contents($root . '/runner.php');
            file_put_contents($root . '/runner.php', str_replace('<?php', '<?php function getDb() { throw new RuntimeException("fixture-private-marker"); }', $runner));
            file_put_contents($root . '/ascii-quest/db.php', '<?php echo "fixture-private-marker";');
            dbBootstrapSafeFailure($root);
        });
    },
    'Task32.1 failed load cannot reuse polluted getDb on another attempt' => function (): void {
        dbBootstrapFixture(function ($root): void {
            file_put_contents($root . '/ascii-quest/db.php', '<?php function getDb(): PDO { return new class extends PDO { public function __construct() {} }; } throw new RuntimeException("fixture-private-marker");');
            $runner = file_get_contents($root . '/runner.php');
            $runner .= "\n" . 'try { $method->invoke(null, __DIR__ . "/ascii-quest/db.php", __DIR__ . "/approved.php"); echo "BAD_RETRY"; } catch (Throwable $e) { echo ":retry_rejected"; }';
            file_put_contents($root . '/runner.php', $runner);
            assertSameValue([0, 'SAFE_ERROR:Maintenance database unavailable.:no_previous:retry_rejected', ''], dbBootstrapProcess($root . '/runner.php'), 'No poisoned function reuse.');
        });
    },
    'Task32.1 CLI rejects arguments before loading and sanitizes valid-argument bootstrap failure' => function (): void {
        dbBootstrapFixture(function ($root): void {
            copy(__DIR__ . '/../scripts/grant_test_equipment.php', $root . '/scripts/grant_test_equipment.php');
            foreach (['TestEquipmentGrantArguments', 'TestEquipmentGrantRepository', 'TestEquipmentGrantService'] as $file) {
                copy(__DIR__ . '/../scripts/lib/' . $file . '.php', $root . '/scripts/lib/' . $file . '.php');
            }
            file_put_contents($root . '/ascii-quest/db.php', '<?php file_put_contents(__DIR__ . "/loaded", "yes"); echo "fixture-private-marker"; throw new RuntimeException("fixture-private-marker");');
            $script = $root . '/scripts/grant_test_equipment.php';
            foreach ([[], ['--user-id=7', '--character-id=42', '--source=/tmp/no'], ['--user-id=7', '--character-id=42', '--unequip']] as $arguments) {
                [$code, $out, $err] = dbBootstrapProcess($script, $arguments);
                assertSameValue([2, '', true, false], [$code, $out, str_starts_with($err, 'Usage:'), file_exists($root . '/ascii-quest/loaded')], 'Arguments before any DB include.');
            }
            assertSameValue([1, '', "Champion 42: failed (maintenance_failed)\n"], dbBootstrapProcess($script, ['--user-id=7', '--character-id=42']), 'Normal invocation safe failure.');
            assertSameValue(true, file_exists($root . '/ascii-quest/loaded'), 'Valid arguments reached bootstrap.');
        });
    },
    'Task32.1 normal CLI preview with fixture PDO emits only intended report' => function (): void {
        dbBootstrapFixture(function ($root): void {
            copy(__DIR__ . '/../scripts/grant_test_equipment.php', $root . '/scripts/grant_test_equipment.php');
            foreach (['TestEquipmentGrantArguments', 'TestEquipmentGrantRepository', 'TestEquipmentGrantService'] as $file) {
                symlink(__DIR__ . '/../scripts/lib/' . $file . '.php', $root . '/scripts/lib/' . $file . '.php');
            }
            $source = '<?php echo "fixture-private-marker";';
            foreach (['CharacterStatsTest', 'EquipmentServiceTest', 'InventoryServiceTest', 'Task32Fixtures'] as $file) {
                $source .= 'require_once ' . var_export(__DIR__ . '/' . $file . '.php', true) . ';';
            }
            $source .= 'function getDb(): PDO { echo "fixture-private-marker"; return new Task32Pdo; }';
            file_put_contents($root . '/ascii-quest/db.php', $source);
            [$code, $out, $err] = dbBootstrapProcess($root . '/scripts/grant_test_equipment.php', ['--user-id=7', '--character-id=42']);
            assertSameValue([0, '', true, false, false, false], [$code, $err, str_starts_with($out, "TEST equipment maintenance (inventory only)\n"), str_contains($out, 'fixture-private-marker'), str_contains($out, $root), str_contains($out, 'PDO')], 'No bootstrap content/path/PDO details in a successful CLI run.');
            $report = json_decode(explode("\n", $out, 2)[1], true, 512, JSON_THROW_ON_ERROR);
            assertSameValue(['preview', 0, 14], [$report['result'], $report['created'], $report['would_create']], 'Real service, fixture PDO, no apply.');
        });
    },
    'Task32.1 unreadable protected dependency is sanitized without falling through' => function (): void {
        dbBootstrapFixture(function ($root): void {
            mkdir($root . '/protected', 0700);
            file_put_contents($root . '/protected/config.php', '<?php echo "fixture-private-marker";');
            file_put_contents($root . '/ascii-quest/db.php', '<?php require __DIR__ . "/../protected/config.php";');
            file_put_contents($root . '/approved.php', dbBootstrapValid('ForbiddenFallbackPdo'));
            chmod($root . '/protected', 0000);
            try { dbBootstrapSafeFailure($root); }
            finally { chmod($root . '/protected', 0700); }
        });
    },
    'Task32.1 getDb exceptions and PDO diagnostic output are sanitized' => function (): void {
        foreach ([
            '<?php function getDb(): PDO { echo "fixture-private-marker"; throw new PDOException("fixture-private-marker"); }',
            '<?php class NoisyFixturePdo extends PDO { public function __construct() {} } function getDb(): PDO { var_dump("fixture-private-marker"); return new NoisyFixturePdo; }',
        ] as $index => $source) {
            dbBootstrapFixture(function ($root) use ($index, $source): void {
                file_put_contents($root . '/ascii-quest/db.php', $source);
                if ($index === 0) { dbBootstrapSafeFailure($root); }
                else { assertSameValue([0, 'PDO_OK:NoisyFixturePdo', ''], dbBootstrapProcess($root . '/runner.php'), 'No diagnostic output.'); }
            });
        }
    },
    'Task32.1 successful PDO is cached and output/error state restored' => function (): void {
        dbBootstrapFixture(function ($root): void {
            file_put_contents($root . '/ascii-quest/db.php', dbBootstrapValid('CachedFixturePdo'));
            $runner = file_get_contents($root . '/runner.php');
            $runner = str_replace('try {', '$before = [ini_get("display_errors"), ini_get("log_errors"), ob_get_level()]; $handler = static function () {}; set_error_handler($handler); try {', $runner);
            $runner .= "\n" . '$again = DatabaseBootstrap::connect(); $restored = set_error_handler(static function () {}); restore_error_handler(); echo $again === $pdo && $restored === $handler && $before === [ini_get("display_errors"), ini_get("log_errors"), ob_get_level()] ? ":state_ok" : ":BAD_STATE";';
            file_put_contents($root . '/runner.php', $runner);
            assertSameValue([0, 'PDO_OK:CachedFixturePdo:state_ok', ''], dbBootstrapProcess($root . '/runner.php'), 'One load, state restored.');
        });
    },
    'Task32.1 real non-CLI requests fail before loading any bootstrap' => function (): void {
        dbBootstrapFixture(function ($root): void {
            copy(__DIR__ . '/../scripts/grant_test_equipment.php', $root . '/scripts/grant_test_equipment.php');
            file_put_contents($root . '/ascii-quest/db.php', '<?php file_put_contents(__DIR__ . "/loaded", "yes"); echo "fixture-private-marker";');
            $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
            if ($socket === false) { throw new RuntimeException('Fixture port unavailable.'); }
            $address = stream_socket_get_name($socket, false); fclose($socket);
            $server = proc_open([PHP_BINARY, '-S', $address, '-t', $root], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, '/');
            if (!is_resource($server)) { throw new RuntimeException('Fixture web server unavailable.'); }
            try {
                $client = false;
                for ($i = 0; $i < 100; $i++) {
                    $client = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
                    if ($client !== false) { fclose($client); break; }
                    usleep(20000);
                }
                if ($client === false) { throw new RuntimeException('Fixture web server did not start.'); }
                foreach (['/scripts/grant_test_equipment.php', '/scripts/lib/DatabaseBootstrap.php'] as $path) {
                    $client = stream_socket_client('tcp://' . $address, $errno, $error, 2);
                    if ($client === false) { throw new RuntimeException('Fixture request unavailable.'); }
                    stream_set_timeout($client, 2);
                    fwrite($client, "GET $path HTTP/1.0\r\nHost: localhost\r\n\r\n");
                    $response = stream_get_contents($client); fclose($client);
                    assertSameValue([true, '', false], [str_starts_with($response, 'HTTP/1.0 404'), explode("\r\n\r\n", $response, 2)[1] ?? null, file_exists($root . '/ascii-quest/loaded')], '404 and no body/include.');
                }
            } finally { proc_terminate($server); proc_close($server); }
        });
    },
    'Task32.1 production API fixes exact sources and has no browser maintenance route' => function (): void {
        $path = __DIR__ . '/../scripts/lib/DatabaseBootstrap.php';
        assertSameValue(true, is_file($path), 'Helper exists.');
        $source = file_get_contents($path);
        assertSameValue(true, str_contains($source, "'/var/www/html/ascii-quest/db.php'"), 'Only approved production source.');
        assertSameValue(true, strpos($source, "PHP_SAPI !== 'cli'") < strpos($source, 'final class'), 'Guard before API/loading.');
        assertSameValue(false, (bool) preg_match('/getenv|\$_(?:ENV|GET|POST|REQUEST|SERVER)/', $source), 'No external source override.');
        foreach (glob(__DIR__ . '/../ascii-quest/*.php') as $web) {
            assertSameValue(false, str_contains(file_get_contents($web), 'DatabaseBootstrap'), 'No browser registration.');
        }
    },
];

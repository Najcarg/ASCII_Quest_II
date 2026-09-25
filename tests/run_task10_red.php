<?php
declare(strict_types=1);

foreach ([
    'CharacterStatsTest.php',
    'CharacterStatAllocatorTest.php',
    'WarpTest.php',
    'CombatDefinitionTest.php',
    'CombatTurnEngineTest.php',
    'CombatMigrationTest.php',
    'CombatMigration004Test.php',
    'CombatRepositoryTest.php',
    'CombatServiceTest.php',
    'CombatSecurityTest.php',
    'CombatTask6Test.php',
    'CombatTask7Test.php',
    'CombatTask8Test.php',
    'CombatTask9ATest.php',
] as $fixtureFile) {
    require __DIR__ . '/' . $fixtureFile;
}

$tests = require __DIR__ . '/CombatTask10Test.php';
$passed = 0;
$failed = 0;

echo "ASCII Quest Task 10 RED Tests\n\n";

foreach ($tests as $name => $test) {
    try {
        $test();
        echo "[PASS] {$name}\n";
        $passed++;
    } catch (Throwable $exception) {
        echo "[FAIL] {$name}: {$exception->getMessage()}\n";
        $failed++;
    }
}

echo "\n{$passed} passed\n{$failed} failed\n";
exit($failed === 0 ? 0 : 1);

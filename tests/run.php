<?php
declare(strict_types=1);

$tests = array_merge(
    require __DIR__ . '/CharacterStatsTest.php',
    require __DIR__ . '/CharacterStatAllocatorTest.php',
    require __DIR__ . '/WarpTest.php',
    require __DIR__ . '/CombatDefinitionTest.php',
    require __DIR__ . '/CombatTurnEngineTest.php',
    require __DIR__ . '/CombatMigrationTest.php',
    require __DIR__ . '/CombatRepositoryTest.php',
);

$passed = 0;
$failed = 0;

echo "ASCII Quest Tests\n\n";

foreach ($tests as $name => $test) {
    try {
        $test();
        echo "[PASS] {$name}\n";
        $passed++;
    } catch (Throwable $e) {
        echo "[FAIL] {$name}: {$e->getMessage()}\n";
        $failed++;
    }
}

echo "\n{$passed} passed\n{$failed} failed\n";
exit($failed === 0 ? 0 : 1);

<?php
declare(strict_types=1);

// Load existing shared fixtures without running their unrelated test cases.
foreach ([
    'ItemGeneratorTest.php', 'CharacterStatsTest.php', 'EquipmentServiceTest.php', 'EquipmentCombatIntegrationTest.php',
    'CombatRepositoryTest.php', 'CombatServiceTest.php', 'CombatTask6Test.php',
    'CombatTask28Test.php', 'CombatTask7Test.php', 'CombatTask25Test.php', 'CombatTask26Test.php', 'EquipmentMigrationTest.php',
    'CombatTask29Test.php', 'CombatTask29BehaviorTest.php', 'CombatTask29EdgeTest.php',
] as $file) {
    require __DIR__ . '/' . $file;
}
$tests = require __DIR__ . '/CombatTask31Test.php';
$passed = 0;
$failed = 0;
echo "ASCII Quest Task 31 Tests\n\n";
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

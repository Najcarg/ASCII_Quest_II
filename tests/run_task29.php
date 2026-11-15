<?php
declare(strict_types=1);

// Load existing shared fixtures without running their unrelated test cases.
foreach ([
    'CharacterStatsTest.php', 'EquipmentServiceTest.php', 'EquipmentCombatIntegrationTest.php',
    'CombatRepositoryTest.php', 'CombatServiceTest.php', 'CombatTask6Test.php',
    'CombatTask28Test.php', 'CombatTask7Test.php', 'CombatTask25Test.php', 'CombatTask26Test.php', 'EquipmentMigrationTest.php',
] as $file) {
    require __DIR__ . '/' . $file;
}
$tests = array_merge(require __DIR__ . '/CombatTask29Test.php', require __DIR__ . '/CombatTask29BehaviorTest.php', require __DIR__ . '/CombatTask29EdgeTest.php');
$passed = 0;
$failed = 0;
echo "ASCII Quest Task 29 Tests\n\n";
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

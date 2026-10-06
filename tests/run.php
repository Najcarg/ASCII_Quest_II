<?php
declare(strict_types=1);

$tests = array_merge(
    require __DIR__ . '/CharacterStatsTest.php',
    require __DIR__ . '/CharacterStatAllocatorTest.php',
    require __DIR__ . '/WarpTest.php',
    require __DIR__ . '/CombatDefinitionTest.php',
    require __DIR__ . '/CombatTurnEngineTest.php',
    require __DIR__ . '/CombatMigrationTest.php',
    require __DIR__ . '/CombatMigration004Test.php',
    require __DIR__ . '/ItemInventoryMigrationTest.php',
    require __DIR__ . '/ItemDropMigrationTest.php',
    require __DIR__ . '/EquipmentMigrationTest.php',
    require __DIR__ . '/EquipmentStatTest.php',
    require __DIR__ . '/EquipmentServiceTest.php',
    require __DIR__ . '/StarterEquipmentTest.php',
    require __DIR__ . '/EquipmentCombatIntegrationTest.php',
    require __DIR__ . '/EquipmentEndpointTest.php',
    require __DIR__ . '/ItemGeneratorTest.php',
    require __DIR__ . '/ItemDropServiceTest.php',
    require __DIR__ . '/InventoryServiceTest.php',
    require __DIR__ . '/ItemEndpointSecurityTest.php',
    require __DIR__ . '/ItemClaimEndpointTest.php',
    require __DIR__ . '/CombatRepositoryTest.php',
    require __DIR__ . '/CombatServiceTest.php',
    require __DIR__ . '/CombatSecurityTest.php',
    require __DIR__ . '/CombatTask6Test.php',
    require __DIR__ . '/CombatTask7Test.php',
    require __DIR__ . '/CombatTask8Test.php',
    require __DIR__ . '/CombatTask9ATest.php',
    require __DIR__ . '/CombatTask10Test.php',
    require __DIR__ . '/CombatTask12Test.php',
    require __DIR__ . '/CombatTask13Test.php',
    require __DIR__ . '/CombatTask14Test.php',
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

<?php
declare(strict_types=1);
foreach (['ItemGeneratorTest.php', 'CharacterStatsTest.php', 'EquipmentServiceTest.php', 'StarterEquipmentTest.php', 'EquipmentCombatIntegrationTest.php', 'InventoryServiceTest.php',
    'CombatRepositoryTest.php', 'CombatServiceTest.php', 'CombatTask6Test.php', 'CombatTask28Test.php', 'CombatTask7Test.php',
    'CombatTask25Test.php', 'CombatTask26Test.php', 'EquipmentMigrationTest.php', 'CombatTask29Test.php', 'CombatTask29BehaviorTest.php', 'CombatTask29EdgeTest.php'] as $file) {
    require_once __DIR__ . '/' . $file;
}
$tests = require __DIR__ . '/TestEquipmentGrantTest.php';
$passed = $failed = 0;
foreach ($tests as $name => $test) {
    try { $test(); echo "[PASS] $name\n"; $passed++; }
    catch (Throwable $e) { echo "[FAIL] $name: {$e->getMessage()}\n"; $failed++; }
}
echo "\n$passed passed\n$failed failed\n";
exit($failed === 0 ? 0 : 1);

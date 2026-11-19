<?php
declare(strict_types=1);
// Load existing fixture definitions, without executing their test closures.
foreach (['CharacterStatsTest.php','CombatRepositoryTest.php','CombatServiceTest.php','CombatTask6Test.php','CombatTask7Test.php','CombatSecurityTest.php','EquipmentServiceTest.php','EquipmentCombatIntegrationTest.php','CombatTask25Test.php','CombatTask26Test.php','CombatTask28Test.php','CombatTask29Test.php','CombatTask8Test.php','SkillCombatTest.php'] as $file) { require __DIR__.'/'.$file; }
$tests=[];
foreach (['PassiveSystemTest.php','PassiveStatTest.php','PassiveMigrationTest.php','PassiveRepositoryTest.php','PassiveCombatTest.php','PassiveEndpointTest.php'] as $file) { $tests=array_merge($tests,require __DIR__.'/'.$file); }
$passed=$failed=0;
foreach ($tests as $name=>$test) {
    try { $test();echo "[PASS] $name\n";$passed++; }
    catch (Throwable $e) { echo "[FAIL] $name: {$e->getMessage()}\n";$failed++; }
}
echo "\n$passed passed\n$failed failed\n";
exit($failed===0?0:1);

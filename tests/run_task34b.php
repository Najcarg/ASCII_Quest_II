<?php
declare(strict_types=1);
foreach (['CharacterStatsTest.php','CombatRepositoryTest.php','CombatServiceTest.php','CombatTask6Test.php','CombatTask7Test.php','CombatSecurityTest.php','CharacterProgressionTest.php'] as $file) { require __DIR__ . '/' . $file; }
$tests = [];
foreach (['SkillSystemTest.php','SkillMutationTest.php','SkillCombatTest.php','SkillMigrationTest.php','SkillEndpointTest.php'] as $file) {
    if (is_file(__DIR__ . '/' . $file)) { $tests = array_merge($tests, require __DIR__ . '/' . $file); }
}
$passed = $failed = 0;
foreach ($tests as $name => $test) {
    try { $test(); echo "[PASS] $name\n"; $passed++; }
    catch (Throwable $e) { echo "[FAIL] $name: {$e->getMessage()}\n"; $failed++; }
}
echo "\n$passed passed\n$failed failed\n";
exit($failed === 0 ? 0 : 1);

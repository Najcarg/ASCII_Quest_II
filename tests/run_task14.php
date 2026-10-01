<?php
declare(strict_types=1);

$files = [
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
    'CombatTask10Test.php',
    'CombatTask12Test.php',
    'CombatTask13Test.php',
    'CombatTask14Test.php',
];
$allTests = [];
foreach ($files as $file) {
    $allTests = array_merge($allTests, require __DIR__ . '/' . $file);
}

$selectedNames = [
    'Task 14 exploration mutation routes reject session and CSRF failures before database work',
    'Task 14 malformed delete CSRF is rejected without a TypeError or database access',
    'Task 14 unresolved terminal and ordinary lifecycle route matrix stays server authoritative',
    'Task 14 two tabs replay one skill request without duplicate action or allowance spend',
    'Task 14 two service instances replay weapon Potion and Block commands exactly once',
    'Task 14 accepted weapon and skill tokens replay current victory without duplicate effects',
    'Task 14 accepted action token replays after later permanent defeat',
    'Task 14 accepted Potion token replays terminal state without healing or consumption',
    'Task 14 accepted Block token replays terminal state without another attempt',
    'Task 14 navigation guards preserve persisted resources and encounter state',
    'Retry and two-tab start resume one unchanged encounter',
    'Accepted request tokens replay immutable snapshots while later actions use new equipment',
    'Timely Block intent is one-attempt idempotent and costs zero Actions',
    'Potion UUID replay and configuration reload cannot replenish or duplicate use',
    'Task 12 configured Gold and raw EXP issue exactly once with one event',
    'Task 12 two service instances produce one closed encounter and one reward set',
    'Task 13 refresh reads terminal defeat without processing death twice',
    'Task 13 death event failure rolls back and retry processes once',
    'Authoritative synchronization applies zero short and capped wall-clock gaps exactly',
    'A capped disconnect is discarded rather than replayed by an immediate poll',
    'Combat state synchronizes through the real repository transaction without resetting persisted state',
    'Refresh browser reopen and login resume the same encounter without replaying skipped time',
    'Terminal reward and death history remain unchanged when combat cannot progress',
    'Task 12 terminal victory supersedes Task 6 catch-up work at stored zero enemy HP',
    'Task 13 selection and main menu retain dead and unresolved lifecycle states',
    'Enemy active action projection is allowlisted and recursively hides combat internals',
    'Task 13 defeated projection exposes no death guard or Slayer internals',
    'Real combat repository locks Champion account mutex then another Champion encounter',
    'Atomic guard locks Champion then account encounter and holds through mutation',
    'Combat action endpoint requires session POST valid JSON and CSRF',
    'Combat action endpoint accepts only strict weapon intent fields and canonical UUIDs',
    'Combat Block endpoint enforces POST session CSRF UUID and strict intent allowlist',
    'Combat Potion endpoint enforces POST session CSRF UUID and exact intent keys',
    'Task 12 close endpoint accepts only CSRF from session owner',
];

$passed = 0;
$failed = 0;
echo "ASCII Quest Task 14 Tests\n\n";
foreach ($selectedNames as $name) {
    $test = $allTests[$name] ?? null;
    if (!is_callable($test)) {
        echo "[FAIL] {$name}: focused test is missing\n";
        $failed++;
        continue;
    }
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

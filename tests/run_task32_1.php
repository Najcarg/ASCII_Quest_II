<?php
declare(strict_types=1);
require_once __DIR__ . '/CharacterStatsTest.php';
$tests = require __DIR__ . '/DatabaseBootstrapTest.php';
$passed = $failed = 0;
foreach ($tests as $name => $test) {
    try { $test(); echo "[PASS] $name\n"; $passed++; }
    catch (Throwable $e) { echo "[FAIL] $name: {$e->getMessage()}\n"; $failed++; }
}
echo "\n$passed passed\n$failed failed\n";
exit($failed === 0 ? 0 : 1);

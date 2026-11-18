<?php
declare(strict_types=1);

// CLI-only synthetic repositories. Never requires db.php or contacts a database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
foreach (['CharacterStatsTest.php', 'CombatRepositoryTest.php', 'CombatServiceTest.php', 'CombatTask6Test.php', 'CombatTask7Test.php', 'CombatTask13Test.php', 'CharacterProgressionTest.php', 'CharacterProgressionCombatTest.php'] as $file) {
    require __DIR__ . '/../' . $file;
}
$states = [];
foreach ([0, 99, 100, 120, 399, 400, 240100, 999999] as $xp) {
    [, , $service] = task34aVictoryFixture($xp);
    $states['xp_' . $xp] = $service->state(7, 42);
}
foreach ([['single', 80, 40], ['multi', 0, 1700], ['cap', 240090, 40]] as [$name, $xp, $reward]) {
    [, $clock, $service] = task34aVictoryFixture($xp, $reward);
    $service->startPlayerAction(7, 42, 'prototype_weapon_attack', '34000000-0000-4000-8000-000000000004');
    $clock->set(new DateTimeImmutable('2026-09-01 12:00:02.000000', new DateTimeZone('UTC')));
    $states[$name] = $service->state(7, 42);
}
echo json_encode($states, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

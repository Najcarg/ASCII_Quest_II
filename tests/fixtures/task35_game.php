<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../PassiveFixtures.php';
$level=51;$spent=0;
foreach ($argv as $arg) {
    if (str_starts_with($arg,'--level=')) { $level=(int)substr($arg,8); }
    if (str_starts_with($arg,'--spent=')) { $spent=(int)substr($arg,8); }
}
[$passiveRepo,$passiveService]=passiveFixture($level);
$passiveRepo->allocations=passivePrefix($spent);
$task35State=$passiveService->state(7,42);
require __DIR__.'/task34b_game.php';

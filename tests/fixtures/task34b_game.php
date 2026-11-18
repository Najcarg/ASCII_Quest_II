<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
foreach (['CharacterStatsTest.php','CombatRepositoryTest.php','CombatServiceTest.php','CombatTask6Test.php','CombatTask7Test.php','CharacterProgressionTest.php','SkillCombatTest.php'] as $file) { require __DIR__.'/../'.$file; }
$class='warrior';$level=1;
foreach ($argv as $arg) {
    if (str_starts_with($arg,'--class=')) { $class=substr($arg,8); }
    if (str_starts_with($arg,'--level=')) { $level=(int)substr($arg,8); }
}
if (in_array('--combat',$argv,true)) {
    [,,$combat,,,$keys]=task34bCombat($class,in_array('--assigned',$argv,true));
    $task34bCombatState=$combat->state(7,42);
    $task34bState=$task34bCombatState['skill_state'];
    $task34bExperience=1600;
} else {
    [$repo,$service]=task34bService(new FakeSkillRepository($class,$level));
    if (in_array('--learn-root',$argv,true)) {
        $rootKey=$service->catalogue()->forClass($class)[0]['skill_key'];
        $service->mutate(7,42,'unlock',$rootKey,null,task34bToken(1));
    }
    if (in_array('--assigned',$argv,true)) {
        $keys=task34bLearnClass($repo,$service);
        foreach (SkillCatalogue::SLOTS as $i=>$slot) { $service->mutate(7,42,'assign',$keys[$i],$slot,task34bToken(200+$i)); }
    }
    $task34bState=$service->state(7,42);
    $task34bExperience=100*($level-1)**2;
}
require __DIR__.'/task33_game.php';

<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../PassiveFixtures.php';
$states=[];
foreach ([[50,0],[51,0],[55,0],[55,5],[70,20],[71,20],[90,0],[90,40],[100,40]] as [$level,$spent]) {
    [$repo,$service]=passiveFixture($level);$repo->allocations=passivePrefix($spent);
    $states['level'.$level.'_spent'.$spent]=$service->state(7,42);
}
[$repo,$service]=passiveFixture(90);$operations=[];
for($i=0;$i<40;$i++) {
    $node=passiveNode($i%20+1,$i<20?'a':'b');
    $result=$service->unlock(7,42,$node['key'],passiveToken($i+1));
    $operations[]=['key'=>$node['key'],'result'=>$result,'state'=>$service->state(7,42)];
}
echo json_encode(['states'=>$states,'operations'=>$operations],JSON_THROW_ON_ERROR);

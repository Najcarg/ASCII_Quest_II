<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../SkillFixtures.php';
$class=$argv[1]??'warrior';
$states=[];
foreach ([1,2,3,5,50] as $level) {
    [$r,$s]=task34bService(new FakeSkillRepository($class,$level));
    $states['level'.$level]=$s->state(7,42);
}
[$r,$s]=task34bService(new FakeSkillRepository($class,3));
$root=$s->catalogue()->forClass($class)[0]['skill_key'];
$s->mutate(7,42,'unlock',$root,null,task34bToken(1));
$states['level3_root']=$s->state(7,42);
$branch=$s->catalogue()->forClass($class)[1]['skill_key'];
$s->mutate(7,42,'unlock',$branch,null,task34bToken(2));
$states['level3_two']=$s->state(7,42);
[$r,$s]=task34bService(new FakeSkillRepository($class,5));
$keys=array_column($s->catalogue()->forClass($class),'skill_key');
$operations=[];
foreach ($keys as $i=>$key) {
    $result=$s->mutate(7,42,'unlock',$key,null,task34bToken(10+$i));
    $operations[]=['command'=>'unlock','key'=>$key,'slot'=>null,'result'=>$result,'state'=>$s->state(7,42)];
}
foreach ([[$keys[0],'skill_1'],[$keys[1],'skill_2'],[$keys[2],'skill_3'],[$keys[3],'ultimate'],[$keys[0],'skill_2']] as $i=>[$key,$slot]) {
    $result=$s->mutate(7,42,'assign',$key,$slot,task34bToken(20+$i));
    $operations[]=['command'=>'assign','key'=>$key,'slot'=>$slot,'result'=>$result,'state'=>$s->state(7,42)];
}
$result=$s->mutate(7,42,'unassign',null,'skill_2',task34bToken(30));
$operations[]=['command'=>'unassign','key'=>null,'slot'=>'skill_2','result'=>$result,'state'=>$s->state(7,42)];
$r->battle=['id'=>91,'status'=>'active'];$states['active']=$s->state(7,42);
$r->battle=['id'=>91,'status'=>'victory_loot'];$states['victory_loot']=$s->state(7,42);
echo json_encode(['states'=>$states,'keys'=>$keys,'operations'=>$operations],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);

<?php
declare(strict_types=1);

function task34bEndpoint(string $route,array $session,string $method,string $body='{}',array $get=[],?string $failure=null): array
{
    $dir=sys_get_temp_dir().'/ascii-quest-skill-endpoint-'.bin2hex(random_bytes(6));
    mkdir($dir.'/lib',0700,true);mkdir($dir.'/sessions',0700);
    copy(__DIR__.'/../ascii-quest/'.$route,$dir.'/'.$route);
    copy(__DIR__.'/../ascii-quest/lib/SkillEndpoint.php',$dir.'/lib/SkillEndpoint.php');
    file_put_contents($dir.'/db.php','<?php function getDb(): object { file_put_contents(__DIR__."/db-called","yes"); return new stdClass(); }');
    file_put_contents($dir.'/lib/SkillBootstrap.php', <<<'PHP'
<?php
final class SkillBootstrap {
    public static function service(object $db): object {
        return new class {
            public function mutate($user,$character,$command,$key,$slot,$token): array {
                if (getenv('SKILL_ENDPOINT_FAILURE')==='domain') throw new DomainException('Private database details must not escape.');
                if (getenv('SKILL_ENDPOINT_FAILURE')==='runtime') throw new RuntimeException('Private SQL credentials must not escape.');
                file_put_contents(dirname(__DIR__).'/intent.json',json_encode(compact('user','character','command','key','slot','token')));
                return ['result'=>'fixture'];
            }
            public function state($user,$character): array { return ['champion'=>['id'=>$character],'points'=>['earned'=>0,'spent'=>0,'available'=>0]]; }
        };
    }
}
PHP);
    $runner='<?php ini_set("session.save_path",'.var_export($dir.'/sessions',true).');session_id("skill'.bin2hex(random_bytes(8)).'");session_start();$_SESSION='.var_export($session,true).';session_write_close();$_SERVER["REQUEST_METHOD"]='.var_export($method,true).';$_GET='.var_export($get,true).';register_shutdown_function(function(){echo "\n__STATUS__".http_response_code();});require '.var_export($dir.'/'.$route,true).';';
    file_put_contents($dir.'/run.php',$runner);
    $process=proc_open([PHP_BINARY,$dir.'/run.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_replace(getenv(),['SKILL_ENDPOINT_FAILURE'=>$failure??'']));
    fwrite($pipes[0],$body);fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);
    preg_match('/\n__STATUS__(\d+)\z/',$stdout,$m);$status=(int)($m[1]??0);$payload=json_decode(preg_replace('/\n__STATUS__\d+\z/','',$stdout),true);
    $called=is_file($dir.'/db-called');$intent=is_file($dir.'/intent.json')?json_decode(file_get_contents($dir.'/intent.json'),true):null;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $path) { $path->isDir()?rmdir($path->getPathname()):unlink($path->getPathname()); }rmdir($dir);
    return [$status,$payload,$called,$intent,$stderr,$exit];
}
$tests=[];
foreach (['unlock_skill.php'=>'unlock','assign_skill.php'=>'assign','unassign_skill.php'=>'unassign'] as $route=>$command) {
    $tests["Task34B $route rejects unauth method CSRF forged progression and malformed intents before DB"] = function () use ($route,$command): void {
        $session=['user_id'=>7,'character_id'=>42,'csrf_token'=>'csrf'];
        $valid=['csrf_token'=>'csrf','request_token'=>task34bToken()];
        if ($command!=='unassign') $valid['skill_key']='warrior_power_strike';
        if ($command!=='unlock') $valid['slot']='skill_1';
        $cases=[[[], 'POST',json_encode($valid),401],[$session,'GET','{}',405],[$session,'POST','{bad',400],[$session,'POST','[]',400]];
        $bad=$valid;$bad['csrf_token']='bad';$cases[]=[$session,'POST',json_encode($bad),403];
        foreach (['character_id','user_id','points','level','experience','cost'] as $key) { $bad=$valid;$bad[$key]=99;$cases[]=[$session,'POST',json_encode($bad),400]; }
        $bad=$valid;$bad['request_token']='bad';$cases[]=[$session,'POST',json_encode($bad),422];
        if ($command!=='unlock') { $bad=$valid;$bad['slot']='weapon';$cases[]=[$session,'POST',json_encode($bad),422]; }
        foreach ($cases as [$auth,$method,$body,$expected]) {
            [$code,,$called]=task34bEndpoint($route,$auth,$method,$body);
            assertSameValue($expected,$code,'Sanitized status.');assertSameValue(false,$called,'Rejected before DB.');
        }
        [$code,$state,$called,$intent]=task34bEndpoint($route,$session,'POST',json_encode($valid));
        assertSameValue(200,$code,'Valid request.');assertSameValue(true,$called,'Reached authority.');
        assertSameValue(7,$intent['user'],'Session user.');assertSameValue(42,$intent['character'],'Session selected Champion.');
        assertSameValue($command,$intent['command'],'Exact command.');assertSameValue(42,$state['state']['champion']['id'],'Projected selected Champion.');
        foreach (['domain'=>422,'runtime'=>500] as $failure=>$expected) {
            [$code,$payload,,,$stderr]=task34bEndpoint($route,$session,'POST',json_encode($valid),[],$failure);
            assertSameValue($expected,$code,'Fail closed.');assertSameValue(false,str_contains(json_encode($payload),'Private'),'No internal message.');
            assertSameValue(false,str_contains($stderr,'Private'),'No credential/SQL detail in logs.');
        }
    };
}
$tests['Task34B skill state is authenticated GET selected owned only with no caller Champion IDs']=function():void {
    $session=['user_id'=>7,'character_id'=>42,'csrf_token'=>'csrf'];
    foreach ([[[],'GET',[],401],[$session,'POST',[],405],[$session,'GET',['character_id'=>99],400]] as [$auth,$method,$get,$expected]) {
        [$code,,$called]=task34bEndpoint('skill_state.php',$auth,$method,'{}',$get);
        assertSameValue($expected,$code,'Reject invalid state intent.');assertSameValue(false,$called,'No DB.');
    }
    [$code,$state]=task34bEndpoint('skill_state.php',$session,'GET');
    assertSameValue(200,$code,'GET succeeds.');assertSameValue(42,$state['champion']['id'],'Session-selected only.');
};
return $tests;

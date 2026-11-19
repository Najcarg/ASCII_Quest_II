<?php
declare(strict_types=1);
function passiveEndpointFixture(string $route,array $session,string $method,string $body='{}',array $get=[],string $type='application/json',string $failure=''): array
{
    $dir=sys_get_temp_dir().'/ascii-passive-endpoint-'.bin2hex(random_bytes(6));mkdir($dir.'/lib',0700,true);mkdir($dir.'/sessions',0700);
    copy(__DIR__.'/../ascii-quest/'.$route,$dir.'/'.$route);copy(__DIR__.'/../ascii-quest/lib/PassiveEndpoint.php',$dir.'/lib/PassiveEndpoint.php');
    file_put_contents($dir.'/db.php','<?php function getDb(): object {file_put_contents(__DIR__."/db-called","yes");return new stdClass();}');
    file_put_contents($dir.'/lib/PassiveBootstrap.php', <<<'PHP'
<?php
final class PassiveBootstrap {
 public static function champion(object $db,int $user,int $id): array {return ['current_hp'=>100,'current_mana'=>50,'stats'=>['resources'=>['max_life'=>150,'max_mana'=>100]]];}
 public static function service(object $db): object {return new class {
  public function unlock($user,$id,$key,$token): array {
   if(getenv('PASSIVE_FAILURE')==='domain')throw new DomainException('Private details must not escape');
   if(getenv('PASSIVE_FAILURE')==='runtime')throw new RuntimeException('Private SQL password must not escape');
   file_put_contents(dirname(__DIR__).'/intent.json',json_encode(compact('user','id','key','token')));return ['result'=>'unlocked'];
  }
  public function state($user,$id): array {return ['champion'=>['id'=>$id],'points'=>['earned'=>1,'spent'=>1,'available'=>0]];}
 };}
}
PHP);
    $runner='<?php ini_set("session.save_path",'.var_export($dir.'/sessions',true).');session_id("passive'.bin2hex(random_bytes(6)).'");session_start();$_SESSION='.var_export($session,true).';session_write_close();$_SERVER["REQUEST_METHOD"]='.var_export($method,true).';$_SERVER["CONTENT_TYPE"]='.var_export($type,true).';$_GET='.var_export($get,true).';register_shutdown_function(function(){echo "\n__STATUS__".http_response_code();});require '.var_export($dir.'/'.$route,true).';';
    file_put_contents($dir.'/run.php',$runner);
    $process=proc_open([PHP_BINARY,$dir.'/run.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_replace(getenv(),['PASSIVE_FAILURE'=>$failure]));
    fwrite($pipes[0],$body);fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($process);
    preg_match('/\n__STATUS__(\d+)\z/',$stdout,$match);$status=(int)($match[1]??0);$payload=json_decode(preg_replace('/\n__STATUS__\d+\z/','',$stdout),true);
    $called=is_file($dir.'/db-called');$intent=is_file($dir.'/intent.json')?json_decode(file_get_contents($dir.'/intent.json'),true):null;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $file)$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());rmdir($dir);
    return [$status,$payload,$called,$intent,$stderr];
}
$tests=[];
$tests['Task35 unlock endpoint JSON auth CSRF strict whitelist intent secure session identity']=function():void {
    $session=['user_id'=>7,'character_id'=>42,'csrf_token'=>'csrf'];$valid=['csrf_token'=>'csrf','request_token'=>passiveToken(1),'passive_key'=>'passive_r01_a_strength'];
    $cases=[[[],'POST',json_encode($valid),401],[$session,'GET','{}',405],[$session,'POST','{bad',400],[$session,'POST','[]',400]];
    foreach(['user_id','character_id','earned','spent','available','level','experience','pass_number','row','cost','points'] as $key){$bad=$valid;$bad[$key]=99;$cases[]=[$session,'POST',json_encode($bad),400];}
    foreach(['request_token'=>'bad','passive_key'=>'warrior_strength','csrf_token'=>'wrong'] as $key=>$value){$bad=$valid;$bad[$key]=$value;$cases[]=[$session,'POST',json_encode($bad),$key==='csrf_token'?403:422];}
    foreach($cases as [$auth,$method,$body,$expected]){[$status,,$called]=passiveEndpointFixture('unlock_passive.php',$auth,$method,$body);assertSameValue($expected,$status,'Sanitized rejection.');assertSameValue(false,$called,'Rejected before DB.');}
    foreach(['text/plain','application/jsonwrong'] as $type){[$status,,$called]=passiveEndpointFixture('unlock_passive.php',$session,'POST',json_encode($valid),[],$type);assertSameValue(415,$status,'Strict content type.');assertSameValue(false,$called,'No DB.');}
    [$status,$state,$called,$intent]=passiveEndpointFixture('unlock_passive.php',$session,'POST',json_encode($valid));
    assertSameValue(200,$status,'Valid request.');assertSameValue(42,$intent['id'],'Selected session Champion.');assertSameValue(7,$intent['user'],'Session user.');assertSameValue(42,$state['state']['champion']['id'],'Refreshed projection.');
};
$tests['Task35 passive unlock rejects empty missing and nonstring session CSRF before DB']=function():void {
    $valid=['csrf_token'=>'','request_token'=>passiveToken(1),'passive_key'=>'passive_r01_a_strength'];
    foreach (['',null,0,false,[]] as $token) {
        $session=['user_id'=>7,'character_id'=>42,'csrf_token'=>$token];
        [$status,,$called]=passiveEndpointFixture('unlock_passive.php',$session,'POST',json_encode($valid));
        assertSameValue(403,$status,'Session must contain a nonempty string CSRF token.');
        assertSameValue(false,$called,'Invalid CSRF rejected before DB.');
    }
    [$status,,$called]=passiveEndpointFixture('unlock_passive.php',['user_id'=>7,'character_id'=>42],'POST',json_encode($valid));
    assertSameValue(403,$status,'Missing session CSRF token rejected.');
    assertSameValue(false,$called,'Missing CSRF rejected before DB.');
};
$tests['Task35 passive state selected Champion authenticated GET only and errors sanitized']=function():void {
    $session=['user_id'=>7,'character_id'=>42,'csrf_token'=>'csrf'];
    foreach([[[],'GET',[],401],[$session,'POST',[],405],[$session,'GET',['character_id'=>99],400]] as [$auth,$method,$get,$expected]){[$status,,$called]=passiveEndpointFixture('passive_state.php',$auth,$method,'{}',$get);assertSameValue($expected,$status,'GET authority.');assertSameValue(false,$called,'No DB on rejection.');}
    [$status,$state]=passiveEndpointFixture('passive_state.php',$session,'GET');assertSameValue(200,$status,'GET selected.');assertSameValue(42,$state['champion']['id'],'No caller identity.');
    $valid=['csrf_token'=>'csrf','request_token'=>passiveToken(1),'passive_key'=>'passive_r01_a_strength'];
    foreach(['domain'=>422,'runtime'=>500] as $failure=>$expected){[$status,$payload,,,$stderr]=passiveEndpointFixture('unlock_passive.php',$session,'POST',json_encode($valid),[],'application/json',$failure);assertSameValue($expected,$status,'Fail closed.');assertSameValue(false,str_contains(json_encode($payload).$stderr,'Private'),'No SQL/credential details.');}
};
return $tests;

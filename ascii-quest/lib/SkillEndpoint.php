<?php
declare(strict_types=1);

function skillJson(array $payload,int $status): never
{
    http_response_code($status); echo json_encode($payload,JSON_UNESCAPED_UNICODE); exit;
}

function runSkillEndpoint(string $command): never
{
    session_start();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    $user = filter_var($_SESSION['user_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $character = filter_var($_SESSION['character_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if ($user===false || $character===false) { skillJson(['success'=>false,'message'=>'Your skill session has expired.'],401); }
    $method=$command==='state'?'GET':'POST';
    if (($_SERVER['REQUEST_METHOD']??'')!==$method) { header('Allow: '.$method); skillJson(['success'=>false,'message'=>'Invalid skill request.'],405); }
    $key=$slot=$token=null;
    if ($command==='state') {
        if ($_GET!==[]) { skillJson(['success'=>false,'message'=>'Invalid skill request.'],400); }
    } else {
        try {
            $raw=(string)file_get_contents('php://input');
            if ($raw==='' && PHP_SAPI==='cli') { $raw=(string)file_get_contents('php://stdin'); }
            if (strlen($raw)>8192) { skillJson(['success'=>false,'message'=>'Invalid skill request.'],400); }
            $request=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        } catch (JsonException) { skillJson(['success'=>false,'message'=>'Invalid skill request.'],400); }
        $allowed=['csrf_token','request_token'];
        if ($command!=='unassign') { $allowed[]='skill_key'; }
        if ($command!=='unlock') { $allowed[]='slot'; }
        $keys=is_array($request)?array_keys($request):[]; sort($keys);sort($allowed);
        if ($keys!==$allowed) { skillJson(['success'=>false,'message'=>'Invalid skill request.'],400); }
        if (!is_string($_SESSION['csrf_token']??null) || !is_string($request['csrf_token']??null)
            || !hash_equals($_SESSION['csrf_token'],$request['csrf_token'])) { skillJson(['success'=>false,'message'=>'Invalid skill request.'],403); }
        $key=$request['skill_key']??null; $slot=$request['slot']??null; $token=$request['request_token']??null;
        if (!is_string($token) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',$token)!==1
            || ($command!=='unassign' && (!is_string($key) || preg_match('/\A[a-z][a-z0-9_]{1,63}\z/D',$key)!==1))
            || ($command!=='unlock' && (!is_string($slot) || !in_array($slot,['skill_1','skill_2','skill_3','ultimate'],true)))) {
            skillJson(['success'=>false,'message'=>'Invalid skill intent.'],422);
        }
    }
    try {
        require_once __DIR__ . '/../db.php';
        require_once __DIR__ . '/SkillBootstrap.php';
        $service=SkillBootstrap::service(getDb());
        if ($command==='state') { skillJson($service->state($user,$character),200); }
        $result=$service->mutate($user,$character,$command,$key,$slot,$token);
        skillJson(['result'=>$result,'state'=>$service->state($user,$character)],200);
    } catch (OutOfBoundsException) { skillJson(['success'=>false,'message'=>'Champion unavailable.'],404); }
    catch (DomainException|InvalidArgumentException) { skillJson(['success'=>false,'message'=>'Skill change unavailable. Refresh your skill state.'],422); }
    catch (Throwable $e) {
        // No request/body/token/SQL/credential details are sent or logged.
        error_log('Skill request failed ('.get_class($e).').');
        skillJson(['success'=>false,'message'=>'Unable to load or change skills. Please try again.'],500);
    }
}

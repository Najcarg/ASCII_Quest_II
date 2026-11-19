<?php
declare(strict_types=1);
function passiveJson(array $payload,int $status): never
{
    http_response_code($status); echo json_encode($payload,JSON_UNESCAPED_UNICODE); exit;
}
function runPassiveEndpoint(string $command): never
{
    session_start();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate'); header('Pragma: no-cache');
    $user=filter_var($_SESSION['user_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $id=filter_var($_SESSION['character_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if ($user===false || $id===false) { passiveJson(['success'=>false,'message'=>'Your passive session has expired.'],401); }
    $method=$command==='state'?'GET':'POST';
    if (($_SERVER['REQUEST_METHOD']??'')!==$method) { header('Allow: '.$method); passiveJson(['success'=>false,'message'=>'Invalid passive request.'],405); }
    if ($_GET!==[]) { passiveJson(['success'=>false,'message'=>'Invalid passive request.'],400); }
    if ($command!=='state') {
        if (strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json') { passiveJson(['success'=>false,'message'=>'JSON required.'],415); }
        try {
            $raw=(string)file_get_contents('php://input');
            if ($raw==='' && PHP_SAPI==='cli') { $raw=(string)file_get_contents('php://stdin'); }
            if (strlen($raw)>8192) { passiveJson(['success'=>false,'message'=>'Invalid passive request.'],400); }
            $request=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        } catch (JsonException) { passiveJson(['success'=>false,'message'=>'Invalid passive request.'],400); }
        $keys=is_array($request)?array_keys($request):[]; sort($keys);
        if ($keys!==['csrf_token','passive_key','request_token']) { passiveJson(['success'=>false,'message'=>'Invalid passive request.'],400); }
        if (!is_string($_SESSION['csrf_token']??null) || $_SESSION['csrf_token']===''
            || !is_string($request['csrf_token']) || $request['csrf_token']===''
            || !hash_equals($_SESSION['csrf_token'],$request['csrf_token'])) { passiveJson(['success'=>false,'message'=>'Invalid passive request.'],403); }
        if (!is_string($request['passive_key']) || strlen($request['passive_key'])>64 || preg_match('/\Apassive_r[0-9]{2}_[a-d]_[a-z_]+\z/D',$request['passive_key'])!==1
            || !is_string($request['request_token']) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D',$request['request_token'])!==1) { passiveJson(['success'=>false,'message'=>'Invalid passive intent.'],422); }
    }
    try {
        require_once __DIR__.'/../db.php'; require_once __DIR__.'/PassiveBootstrap.php';
        $pdo=getDb(); $service=PassiveBootstrap::service($pdo);
        if ($command==='state') { passiveJson($service->state($user,$id),200); }
        $result=$service->unlock($user,$id,$request['passive_key'],$request['request_token']);
        passiveJson(['result'=>$result,'state'=>$service->state($user,$id),'champion'=>PassiveBootstrap::champion($pdo,$user,$id)],200);
    } catch (OutOfBoundsException) { passiveJson(['success'=>false,'message'=>'Champion unavailable.'],404); }
    catch (DomainException|InvalidArgumentException) { passiveJson(['success'=>false,'message'=>'Passive change unavailable. Refresh your passive state.'],422); }
    catch (Throwable $e) {
        error_log('Passive request failed ('.get_class($e).').');
        passiveJson(['success'=>false,'message'=>'Unable to load or change passives. Please try again.'],500);
    }
}

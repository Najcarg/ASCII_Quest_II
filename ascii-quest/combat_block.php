<?php
declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

function sendCombatBlockJson(array $payload, int $status): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit();
}

if (!isset($_SESSION['user_id'], $_SESSION['character_id'])) {
    sendCombatBlockJson([
        'success' => false,
        'message' => 'Your combat session has expired.',
    ], 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    sendCombatBlockJson([
        'success' => false,
        'message' => 'Invalid combat Block request.',
    ], 405);
}

try {
    $rawRequest = (string) file_get_contents('php://input');
    if ($rawRequest === '' && PHP_SAPI === 'cli') {
        $rawRequest = (string) file_get_contents('php://stdin');
    }
    $request = json_decode($rawRequest, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    sendCombatBlockJson([
        'success' => false,
        'message' => 'Invalid combat Block request.',
    ], 400);
}

$requestKeys = is_array($request) ? array_keys($request) : [];
$allowedKeys = [
    'csrf_token',
    'enemy_action_id',
    'block_token',
    'request_token',
];
sort($requestKeys);
sort($allowedKeys);
if (!is_array($request) || $requestKeys !== $allowedKeys) {
    sendCombatBlockJson([
        'success' => false,
        'message' => 'Invalid combat Block request.',
    ], 400);
}
if (
    !isset($_SESSION['csrf_token']) ||
    !is_string($request['csrf_token']) ||
    !hash_equals((string) $_SESSION['csrf_token'], $request['csrf_token'])
) {
    sendCombatBlockJson([
        'success' => false,
        'message' => 'Invalid combat Block request.',
    ], 403);
}

$enemyActionId = $request['enemy_action_id'];
$blockToken = $request['block_token'];
$requestToken = $request['request_token'];
if (
    !is_int($enemyActionId) ||
    $enemyActionId <= 0 ||
    !is_string($blockToken) ||
    preg_match('/\A[0-9a-f]{64}\z/D', $blockToken) !== 1 ||
    !is_string($requestToken) ||
    preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $requestToken) !== 1
) {
    sendCombatBlockJson([
        'success' => false,
        'message' => 'Invalid combat Block intent.',
    ], 422);
}

try {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/lib/CombatBootstrap.php';

    $state = CombatBootstrap::service(getDb())->attemptBlock(
        (int) $_SESSION['user_id'],
        (int) $_SESSION['character_id'],
        $enemyActionId,
        $blockToken,
        $requestToken,
    );
    sendCombatBlockJson($state, 200);
} catch (OutOfBoundsException) {
    sendCombatBlockJson([
        'success' => false,
        'message' => 'Champion unavailable.',
    ], 404);
} catch (DomainException $exception) {
    error_log('Combat Block domain failure: ' . $exception->getMessage());
    sendCombatBlockJson([
        'success' => false,
        'message' => 'Combat Block is unavailable.',
    ], 422);
} catch (Throwable $exception) {
    error_log('Combat Block failed: ' . $exception->getMessage());
    sendCombatBlockJson([
        'success' => false,
        'message' => 'Unable to process combat Block. Please try again.',
    ], 500);
}

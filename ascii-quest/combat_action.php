<?php
declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

function sendCombatActionJson(array $payload, int $status): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit();
}

if (!isset($_SESSION['user_id'], $_SESSION['character_id'])) {
    sendCombatActionJson(['success' => false, 'message' => 'Your combat session has expired.'], 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    sendCombatActionJson(['success' => false, 'message' => 'Invalid combat action request.'], 405);
}

try {
    $rawRequest = (string) file_get_contents('php://input');
    if ($rawRequest === '' && PHP_SAPI === 'cli') {
        $rawRequest = (string) file_get_contents('php://stdin');
    }
    $request = json_decode($rawRequest, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    sendCombatActionJson(['success' => false, 'message' => 'Invalid combat action request.'], 400);
}

$requestKeys = is_array($request) ? array_keys($request) : [];
$allowedKeys = ['csrf_token', 'action_key', 'request_token'];
sort($requestKeys);
sort($allowedKeys);
if (!is_array($request) || $requestKeys !== $allowedKeys) {
    sendCombatActionJson(['success' => false, 'message' => 'Invalid combat action request.'], 400);
}
if (!isset($_SESSION['csrf_token']) || !is_string($request['csrf_token']) ||
    !hash_equals((string) $_SESSION['csrf_token'], $request['csrf_token'])) {
    sendCombatActionJson(['success' => false, 'message' => 'Invalid combat action request.'], 403);
}

$actionKey = $request['action_key'];
$requestToken = $request['request_token'];
if (
    !is_string($actionKey) ||
    preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/D', $actionKey) !== 1 ||
    !is_string($requestToken) ||
    preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $requestToken) !== 1
) {
    sendCombatActionJson(['success' => false, 'message' => 'Invalid combat action intent.'], 422);
}

try {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/lib/CombatBootstrap.php';

    $state = CombatBootstrap::service(getDb())->startPlayerAction(
        (int) $_SESSION['user_id'],
        (int) $_SESSION['character_id'],
        $actionKey,
        $requestToken,
    );
    sendCombatActionJson($state, 200);
} catch (OutOfBoundsException) {
    sendCombatActionJson(['success' => false, 'message' => 'Champion unavailable.'], 404);
} catch (DomainException $exception) {
    error_log('Combat action domain failure: ' . $exception->getMessage());
    sendCombatActionJson(['success' => false, 'message' => 'Combat action is unavailable.'], 422);
} catch (Throwable $exception) {
    error_log('Combat action failed: ' . $exception->getMessage());
    sendCombatActionJson(['success' => false, 'message' => 'Unable to process combat action. Please try again.'], 500);
}

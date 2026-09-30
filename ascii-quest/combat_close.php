<?php
declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

function sendCombatCloseJson(array $payload, int $status): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit();
}

if (!isset($_SESSION['user_id'], $_SESSION['character_id'])) {
    sendCombatCloseJson(['success' => false, 'message' => 'Your combat session has expired.'], 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    sendCombatCloseJson(['success' => false, 'message' => 'Invalid combat close request.'], 405);
}

try {
    $rawRequest = (string) file_get_contents('php://input');
    if ($rawRequest === '' && PHP_SAPI === 'cli') {
        $rawRequest = (string) file_get_contents('php://stdin');
    }
    $request = json_decode($rawRequest, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    sendCombatCloseJson(['success' => false, 'message' => 'Invalid combat close request.'], 400);
}

$requestKeys = is_array($request) ? array_keys($request) : [];
$allowedKeys = ['csrf_token', 'request_token'];
sort($requestKeys);
sort($allowedKeys);
if (!is_array($request) || $requestKeys !== $allowedKeys) {
    sendCombatCloseJson(['success' => false, 'message' => 'Invalid combat close request.'], 400);
}
if (
    !isset($_SESSION['csrf_token']) ||
    !is_string($request['csrf_token']) ||
    !hash_equals((string) $_SESSION['csrf_token'], $request['csrf_token'])
) {
    sendCombatCloseJson(['success' => false, 'message' => 'Invalid combat close request.'], 403);
}

$requestToken = $request['request_token'];
if (
    !is_string($requestToken) ||
    preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $requestToken) !== 1
) {
    sendCombatCloseJson(['success' => false, 'message' => 'Invalid combat close intent.'], 422);
}

try {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/lib/CombatBootstrap.php';
    $result = CombatBootstrap::service(getDb())->closeVictory(
        (int) $_SESSION['user_id'],
        (int) $_SESSION['character_id'],
        $requestToken,
    );
    sendCombatCloseJson($result, 200);
} catch (OutOfBoundsException) {
    sendCombatCloseJson(['success' => false, 'message' => 'Champion unavailable.'], 404);
} catch (DomainException $exception) {
    error_log('Combat close domain failure: ' . $exception->getMessage());
    sendCombatCloseJson(['success' => false, 'message' => 'Combat victory cannot be closed.'], 422);
} catch (Throwable $exception) {
    error_log('Combat close failed: ' . $exception->getMessage());
    sendCombatCloseJson(['success' => false, 'message' => 'Unable to close combat. Please try again.'], 500);
}

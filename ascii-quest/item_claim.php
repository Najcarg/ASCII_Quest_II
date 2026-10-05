<?php
declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

function sendItemClaimJson(array $payload, int $status): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit();
}

if (!isset($_SESSION['user_id'], $_SESSION['character_id'])) {
    sendItemClaimJson(['success' => false, 'message' => 'Your item session has expired.'], 401);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    sendItemClaimJson(['success' => false, 'message' => 'Invalid item claim request.'], 405);
}

try {
    $rawRequest = (string) file_get_contents('php://input');
    if ($rawRequest === '' && PHP_SAPI === 'cli') {
        $rawRequest = (string) file_get_contents('php://stdin');
    }
    $request = json_decode($rawRequest, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    sendItemClaimJson(['success' => false, 'message' => 'Invalid item claim request.'], 400);
}

$requestKeys = is_array($request) ? array_keys($request) : [];
$allowedKeys = ['csrf_token', 'drop_id', 'request_token'];
sort($requestKeys);
sort($allowedKeys);
if (!is_array($request) || $requestKeys !== $allowedKeys) {
    sendItemClaimJson(['success' => false, 'message' => 'Invalid item claim request.'], 400);
}
if (!isset($_SESSION['csrf_token'])
    || !is_string($request['csrf_token'])
    || !hash_equals((string) $_SESSION['csrf_token'], $request['csrf_token'])) {
    sendItemClaimJson(['success' => false, 'message' => 'Invalid item claim request.'], 403);
}
$dropId = $request['drop_id'];
$requestToken = $request['request_token'];
if (!is_int($dropId) || $dropId < 1
    || !is_string($requestToken)
    || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $requestToken) !== 1) {
    sendItemClaimJson(['success' => false, 'message' => 'Invalid item claim intent.'], 422);
}

try {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/lib/ItemBootstrap.php';
    $result = ItemBootstrap::service(getDb())->claim(
        (int) $_SESSION['user_id'],
        (int) $_SESSION['character_id'],
        $dropId,
        $requestToken,
    );
    sendItemClaimJson($result, 200);
} catch (OutOfBoundsException|DomainException $exception) {
    error_log('Item claim unavailable: ' . $exception->getMessage());
    sendItemClaimJson(['success' => false, 'message' => 'Item drop unavailable.'], 422);
} catch (Throwable $exception) {
    error_log('Item claim failed: ' . $exception->getMessage());
    sendItemClaimJson(['success' => false, 'message' => 'Unable to claim item. Please try again.'], 500);
}
